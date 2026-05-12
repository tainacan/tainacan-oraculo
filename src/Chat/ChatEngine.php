<?php
/**
 * Motor de Chat com Contexto
 *
 * @package Oraculo_Tainacan
 */

namespace Oraculo_Tainacan\Chat;

use Oraculo_Tainacan\AI\AIProviderFactory;
use Oraculo_Tainacan\Search\SearchEngine;
use WP_Error;

/**
 * Motor de chat conversacional com RAG
 */
class ChatEngine {

    /**
     * Factory de provedores de IA
     * @var AIProviderFactory
     */
    private AIProviderFactory $ai_factory;

    /**
     * Motor de busca
     * @var SearchEngine
     */
    private SearchEngine $search_engine;

    /**
     * Opções do plugin
     * @var array
     */
    private array $options;

    /**
     * Nome das tabelas
     * @var string
     */
    private string $conversations_table;
    private string $messages_table;

    /**
     * Construtor
     */
    public function __construct() {
        global $wpdb;

        $this->ai_factory = new AIProviderFactory();
        $this->search_engine = new SearchEngine();
        $this->options = \Oraculo_Tainacan\Oraculo_Tainacan::get_options();
        $this->conversations_table = $wpdb->prefix . 'oraculo_conversations';
        $this->messages_table = $wpdb->prefix . 'oraculo_messages';
    }

    /**
     * Processa mensagem de chat
     *
     * @param string $message Mensagem do usuário
     * @param string $session_id ID da sessão (opcional, será criado se não existir)
     * @param array $collection_ids IDs das coleções para busca
     * @param array $options Opções adicionais
     * @return array|WP_Error
     */
    public function chat(string $message, string $session_id = '', array $collection_ids = [], array $options = []) {
        $start_time = microtime(true);

        // Validar mensagem
        $message = trim($message);
        if (empty($message)) {
            return new WP_Error('empty_message', __('A mensagem não pode estar vazia.', 'oraculo_tainacan'));
        }

        // Obter ou criar conversa
        if (empty($session_id)) {
            $session_id = \Oraculo_Tainacan\generate_session_id();
        }

        $conversation = $this->get_or_create_conversation($session_id, $collection_ids);

        if (is_wp_error($conversation)) {
            return $conversation;
        }

        try {
            // Salvar mensagem do usuário
            $this->save_message($conversation['id'], 'user', $message);

            // Obter histórico recente
            $history = $this->get_conversation_history($conversation['id'], 10);

            // Buscar contexto relevante no acervo
            $context = '';
            $sources = [];
            $rag_strategy = $this->get_rag_strategy();

            if ($rag_strategy !== 'provider' && $this->should_search_context($message, $history)) {
                $search_results = $this->search_engine->semantic_search($message, $collection_ids, 5);

                if (!is_wp_error($search_results) && !empty($search_results)) {
                    $context = $this->format_context($search_results);
                    $sources = $search_results;
                }
            }

            // Construir prompt
            $system_prompt = $this->get_chat_system_prompt();
            $chat_prompt = $this->build_chat_prompt($message, $context, $history);

            // Gerar resposta
            $chat_provider = $this->ai_factory->create_from_options();

            $ai_response = $chat_provider->generate_response($chat_prompt, $system_prompt, [
                'max_tokens' => $options['max_tokens'] ?? $this->options['max_tokens'] ?? 2000,
                'temperature' => $options['temperature'] ?? $this->options['temperature'] ?? 0.7,
            ]);

            if (is_wp_error($ai_response)) {
                return $ai_response;
            }

            // Salvar resposta
            $message_id = $this->save_message(
                $conversation['id'],
                'assistant',
                $ai_response['response'],
                $ai_response['usage']['total_tokens'] ?? 0,
                $ai_response['model'],
                $sources
            );

            // Atualizar conversa
            $this->update_conversation($conversation['id']);

            $response_time = round((microtime(true) - $start_time) * 1000);

            return [
                'session_id' => $session_id,
                'message_id' => $message_id,
                'response' => $ai_response['response'],
                'sources' => $this->format_sources_for_response($sources),
                'usage' => $ai_response['usage'],
                'model' => $ai_response['model'],
                'rag_strategy' => $rag_strategy,
                'cost' => $ai_response['cost'] ?? 0,
                'response_time_ms' => $response_time,
            ];

        } catch (\Exception $e) {
            \Oraculo_Tainacan\debug_log('Chat error: ' . $e->getMessage(), null, 'error');
            return new WP_Error('chat_error', $e->getMessage());
        }
    }

    /**
     * Obtém ou cria conversa
     *
     * @param string $session_id
     * @param array $collection_ids
     * @return array|WP_Error
     */
    private function get_or_create_conversation(string $session_id, array $collection_ids) {
        global $wpdb;

        // Buscar conversa existente
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- $this->conversations_table is plugin-owned table built from $wpdb->prefix + literal; table identifier cannot be parameterized.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- session lookup per request; caching per-request only.
        $conversation = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->conversations_table} WHERE session_id = %s AND status = 'active'",
            $session_id
        ), ARRAY_A);
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter

        if ($conversation) {
            return $conversation;
        }

        // Criar nova conversa
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom plugin table; no WP core API available.
        $result = $wpdb->insert(
            $this->conversations_table,
            [
                'session_id' => $session_id,
                'user_id' => get_current_user_id(),
                'collection_ids' => wp_json_encode($collection_ids),
                'status' => 'active',
                'created_at' => current_time('mysql'),
            ],
            ['%s', '%d', '%s', '%s', '%s']
        );

        if ($result === false) {
            return new WP_Error('db_error', __('Falha ao criar conversa.', 'oraculo_tainacan'));
        }

        return [
            'id' => $wpdb->insert_id,
            'session_id' => $session_id,
            'user_id' => get_current_user_id(),
            'collection_ids' => $collection_ids,
        ];
    }

    /**
     * Salva mensagem
     *
     * @param int $conversation_id
     * @param string $role
     * @param string $content
     * @param int $tokens
     * @param string $model
     * @param array $sources
     * @return int|false ID da mensagem ou false
     */
    private function save_message(
        int $conversation_id,
        string $role,
        string $content,
        int $tokens = 0,
        string $model = '',
        array $sources = []
    ) {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom plugin table; no WP core API available.
        $result = $wpdb->insert(
            $this->messages_table,
            [
                'conversation_id' => $conversation_id,
                'role' => $role,
                'content' => $content,
                'tokens_used' => $tokens,
                'model_used' => $model,
                'sources_json' => !empty($sources) ? wp_json_encode($sources) : null,
                'created_at' => current_time('mysql'),
            ],
            ['%d', '%s', '%s', '%d', '%s', '%s', '%s']
        );
        \Oraculo_Tainacan\oraculo_tainacan_flush_cache();

        return $result !== false ? $wpdb->insert_id : false;
    }

    /**
     * Obtém histórico da conversa
     *
     * @param int $conversation_id
     * @param int $limit
     * @return array
     */
    private function get_conversation_history(int $conversation_id, int $limit = 10): array {
        global $wpdb;

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- $this->messages_table is plugin-owned table built from $wpdb->prefix + literal; table identifier cannot be parameterized.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- history fetched per-request for active chat.
        $messages = $wpdb->get_results($wpdb->prepare(
            "SELECT role, content FROM {$this->messages_table}
             WHERE conversation_id = %d
             ORDER BY id DESC
             LIMIT %d",
            $conversation_id,
            $limit
        ), ARRAY_A);
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter

        // Inverter para ordem cronológica
        return array_reverse($messages);
    }

    /**
     * Atualiza timestamp da conversa
     *
     * @param int $conversation_id
     */
    private function update_conversation(int $conversation_id): void {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table (oraculo_conversations); UPDATE write operation; caching N/A.
        $wpdb->update(
            $this->conversations_table,
            ['updated_at' => current_time('mysql')],
            ['id' => $conversation_id],
            ['%s'],
            ['%d']
        );
        \Oraculo_Tainacan\oraculo_tainacan_flush_cache();
    }

    /**
     * Verifica se deve buscar contexto para esta mensagem
     *
     * @param string $message
     * @param array $history
     * @return bool
     */
    private function should_search_context(string $message, array $history): bool {
        // Sempre buscar se não há histórico
        if (empty($history)) {
            return true;
        }

        // Verificar se é uma pergunta de follow-up simples
        $simple_followups = ['sim', 'não', 'ok', 'certo', 'entendi', 'obrigado', 'obrigada'];
        if (in_array(strtolower(trim($message)), $simple_followups)) {
            return false;
        }

        // Buscar se a mensagem é longa ou contém palavras-chave de busca
        $search_keywords = ['mostre', 'encontre', 'busque', 'procure', 'quero', 'preciso', 'sobre', 'relacionado'];
        foreach ($search_keywords as $keyword) {
            if (stripos($message, $keyword) !== false) {
                return true;
            }
        }

        // Buscar se a mensagem tem mais de 3 palavras significativas
        $words = preg_split('/\s+/', $message);
        $significant_words = array_filter($words, fn($w) => strlen($w) > 3);

        return count($significant_words) > 3;
    }

    /**
     * Obtém estratégia de RAG ativa
     *
     * @return string
     */
    private function get_rag_strategy(): string {
        $strategy = sanitize_text_field((string) ($this->options['rag_strategy'] ?? 'local'));
        $allowed = ['local', 'provider', 'hybrid'];
        return in_array($strategy, $allowed, true) ? $strategy : 'local';
    }

    /**
     * Formata contexto para o prompt
     *
     * @param array $items
     * @return string
     */
    private function format_context(array $items): string {
        if (empty($items)) {
            return '';
        }

        $parts = [];
        foreach ($items as $index => $item) {
            $num = $index + 1;
            $snippet = \Oraculo_Tainacan\truncate_text($item['snippet'] ?? '', 300);

            $parts[] = sprintf(
                "[%d] %s\nURL: %s",
                $num,
                $item['title'] . ': ' . $snippet,
                $item['url'] ?? ''
            );
        }

        return implode("\n\n", $parts);
    }

    /**
     * Obtém prompt do sistema para chat
     *
     * @return string
     */
    private function get_chat_system_prompt(): string {
        $base_prompt = $this->options['system_prompt'] ?? '';

        $chat_additions = __('

Você está em uma conversa contínua. Lembre-se do contexto das mensagens anteriores.
Seja conversacional e amigável, mas mantenha a precisão das informações.
Quando citar itens do acervo, inclua os links quando disponíveis.', 'oraculo_tainacan');

        return $base_prompt . $chat_additions;
    }

    /**
     * Constrói prompt de chat
     *
     * @param string $message
     * @param string $context
     * @param array $history
     * @return string
     */
    private function build_chat_prompt(string $message, string $context, array $history): string {
        $parts = [];

        // Histórico
        if (!empty($history)) {
            $parts[] = "HISTÓRICO DA CONVERSA:";
            foreach ($history as $msg) {
                $role = $msg['role'] === 'user' ? 'Usuário' : 'Assistente';
                $parts[] = "{$role}: " . \Oraculo_Tainacan\truncate_text($msg['content'], 500);
            }
            $parts[] = "";
        }

        // Contexto do acervo
        if (!empty($context)) {
            $parts[] = "INFORMAÇÕES RELEVANTES DO ACERVO:";
            $parts[] = $context;
            $parts[] = "";
        }

        // Mensagem atual
        $parts[] = "MENSAGEM DO USUÁRIO: " . $message;
        $parts[] = "";
        $parts[] = "Responda de forma útil e conversacional:";

        return implode("\n", $parts);
    }

    /**
     * Formata fontes para resposta
     *
     * @param array $sources
     * @return array
     */
    private function format_sources_for_response(array $sources): array {
        return array_map(function($source) {
            return [
                'id' => $source['id'] ?? null,
                'title' => $source['title'] ?? '',
                'url' => $source['url'] ?? '',
                'collection_name' => $source['collection_name'] ?? '',
            ];
        }, $sources);
    }

    /**
     * Obtém conversas do usuário
     *
     * @param int $user_id
     * @param int $limit
     * @return array
     */
    public function get_user_conversations(int $user_id, int $limit = 20): array {
        global $wpdb;

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- $this->conversations_table and $this->messages_table are plugin-owned tables built from $wpdb->prefix + literal; table identifiers cannot be parameterized.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- user conversations list; not cached (changes frequently).
        return $wpdb->get_results($wpdb->prepare(
            "SELECT c.*, COUNT(m.id) as message_count
             FROM {$this->conversations_table} c
             LEFT JOIN {$this->messages_table} m ON c.id = m.conversation_id
             WHERE c.user_id = %d
             GROUP BY c.id
             ORDER BY c.updated_at DESC
             LIMIT %d",
            $user_id,
            $limit
        ), ARRAY_A);
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
    }

    /**
     * Obtém mensagens de uma conversa
     *
     * @param int $conversation_id
     * @param int $limit
     * @return array
     */
    public function get_messages(int $conversation_id, int $limit = 50): array {
        global $wpdb;

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- $this->messages_table is plugin-owned table built from $wpdb->prefix + literal; table identifier cannot be parameterized.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- messages change frequently during active conversation.
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->messages_table}
             WHERE conversation_id = %d
             ORDER BY created_at ASC
             LIMIT %d",
            $conversation_id,
            $limit
        ), ARRAY_A);
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
    }

    /**
     * Encerra conversa
     *
     * @param string $session_id
     * @return bool
     */
    public function end_conversation(string $session_id): bool {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table (oraculo_conversations); UPDATE write operation; caching N/A.
        $result = $wpdb->update(
            $this->conversations_table,
            ['status' => 'ended', 'updated_at' => current_time('mysql')],
            ['session_id' => $session_id],
            ['%s', '%s'],
            ['%s']
        );
        \Oraculo_Tainacan\oraculo_tainacan_flush_cache();

        return $result !== false;
    }

    /**
     * Registra feedback em uma mensagem
     *
     * @param int $message_id
     * @param string $feedback
     * @return bool
     */
    public function record_feedback(int $message_id, string $feedback): bool {
        global $wpdb;

        $valid_feedback = ['positive', 'negative'];
        if (!in_array($feedback, $valid_feedback)) {
            return false;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table (oraculo_messages); UPDATE write operation; caching N/A.
        $result = $wpdb->update(
            $this->messages_table,
            ['feedback' => $feedback],
            ['id' => $message_id],
            ['%s'],
            ['%d']
        );
        \Oraculo_Tainacan\oraculo_tainacan_flush_cache();

        return $result !== false;
    }

    /**
     * Gera título para conversa baseado no conteúdo
     *
     * @param int $conversation_id
     * @return string
     */
    public function generate_conversation_title(int $conversation_id): string {
        global $wpdb;

        // Obter primeira mensagem do usuário
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- $this->messages_table is plugin-owned table built from $wpdb->prefix + literal; table identifier cannot be parameterized.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- first message lookup for title generation.
        $first_message = $wpdb->get_var($wpdb->prepare(
            "SELECT content FROM {$this->messages_table}
             WHERE conversation_id = %d AND role = 'user'
             ORDER BY id ASC LIMIT 1",
            $conversation_id
        ));
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter

        if (!$first_message) {
            return __('Nova conversa', 'oraculo_tainacan');
        }

        // Truncar para título
        $title = \Oraculo_Tainacan\truncate_text($first_message, 50);

        // Atualizar título na conversa
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table (oraculo_conversations); UPDATE write operation; caching N/A.
        $wpdb->update(
            $this->conversations_table,
            ['title' => $title],
            ['id' => $conversation_id],
            ['%s'],
            ['%d']
        );
        \Oraculo_Tainacan\oraculo_tainacan_flush_cache();

        return $title;
    }

    /**
     * Limpa conversas antigas
     *
     * @param int $days_old
     * @return int Número de conversas removidas
     */
    public function cleanup_old_conversations(int $days_old = 30): int {
        global $wpdb;

        // Obter IDs de conversas antigas
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- $this->conversations_table and $this->messages_table are plugin-owned tables built from $wpdb->prefix + literal; table identifiers cannot be parameterized.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- cleanup operation; returns IDs for subsequent DELETE.
        $old_conversations = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$this->conversations_table}
             WHERE updated_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
            $days_old
        ));

        if (empty($old_conversations)) {
            return 0;
        }

        $ids_placeholder = implode(',', array_fill(0, count($old_conversations), '%d'));

        // Remover mensagens — $ids_placeholder contains only %d placeholders built from array_fill; count matches $old_conversations spread.
        // phpcs:disable WordPress.DB.PreparedSQL.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- $ids_placeholder constructed via array_fill('%d'); count matches args spread; bulk DELETE for oraculo_messages; caching N/A for writes.
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->messages_table} WHERE conversation_id IN ($ids_placeholder)",
            $old_conversations
        ));
        // phpcs:enable WordPress.DB.PreparedSQL.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

        // Remover conversas
        // phpcs:disable WordPress.DB.PreparedSQL.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- $ids_placeholder constructed via array_fill('%d'); count matches args spread; bulk DELETE for oraculo_conversations; caching N/A for writes.
        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->conversations_table} WHERE id IN ($ids_placeholder)",
            $old_conversations
        ));
        // phpcs:enable WordPress.DB.PreparedSQL.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
        \Oraculo_Tainacan\oraculo_tainacan_flush_cache();

        return $deleted;
    }
}
