<?php
/**
 * Motor de busca RAG (Retrieval-Augmented Generation)
 *
 * @package Oraculo_Tainacan
 */

namespace Oraculo_Tainacan\Search;

use Oraculo_Tainacan\AI\AIProviderFactory;
use Oraculo_Tainacan\Vector\VectorStore;
use WP_Error;

/**
 * Motor de busca semântica com RAG
 */
class SearchEngine {

    /**
     * Factory de provedores de IA
     * @var AIProviderFactory
     */
    private AIProviderFactory $ai_factory;

    /**
     * Store de vetores
     * @var VectorStore
     */
    private VectorStore $vector_store;

    /**
     * Opções do plugin
     * @var array
     */
    private array $options;

    /**
     * Construtor
     */
    public function __construct() {
        $this->ai_factory = new AIProviderFactory();
        $this->vector_store = new VectorStore();
        $this->options = \Oraculo_Tainacan\Oraculo_Tainacan::get_options();
    }

    /**
     * Realiza busca semântica
     *
     * @param string $query Pergunta do usuário
     * @param array $collection_ids IDs das coleções (vazio = todas)
     * @param array $options Opções adicionais
     * @return array|WP_Error
     */
    public function search(string $query, array $collection_ids = [], array $options = []) {
        $start_time = microtime(true);
        $rag_strategy = $this->get_rag_strategy();

        // Validar query
        $query = trim($query);
        if (empty($query)) {
            return new WP_Error('empty_query', __('A pergunta não pode estar vazia.', 'oraculo_tainacan'));
        }

        // Verificar cache
        $cache_key = $this->get_cache_key($query, $collection_ids);
        $cached = get_transient($cache_key);
        if ($cached !== false && empty($options['no_cache'])) {
            $cached['from_cache'] = true;
            return $cached;
        }

        try {
            $similar_items = [];
            $max_results = $options['max_results'] ?? $this->options['max_results'] ?? 10;
            $threshold = $options['similarity_threshold'] ?? $this->options['similarity_threshold'] ?? 0.3;
            $provider_fallback = false;

            if ($rag_strategy !== 'provider') {
                $embedding_provider = $this->ai_factory->create_for_embeddings();
                $embedding_result = $embedding_provider->generate_embedding($query);

                if (is_wp_error($embedding_result)) {
                    if ($rag_strategy === 'hybrid') {
                        $provider_fallback = true;
                    } else {
                        return $embedding_result;
                    }
                } else {
                    $query_embedding = $embedding_result['embedding'];
                    $similar_items = $this->vector_store->search(
                        $query_embedding,
                        $collection_ids,
                        $max_results * 2,
                        $threshold
                    );

                    if (is_wp_error($similar_items)) {
                        if ($rag_strategy === 'hybrid') {
                            $provider_fallback = true;
                            $similar_items = [];
                        } else {
                            return $similar_items;
                        }
                    }
                }
            }

            if (empty($similar_items) && $rag_strategy === 'local') {
                return $this->build_no_results_response($query);
            }

            $context = '';
            if (!empty($similar_items)) {
                $context = $this->prepare_context($similar_items, $max_results);
            }

            // 3. Gerar resposta com o LLM
            $chat_provider = $this->ai_factory->create_from_options();

            $system_prompt = $this->get_system_prompt();
            $search_prompt = $this->build_search_prompt($query, $context);

            $ai_response = $chat_provider->generate_response($search_prompt, $system_prompt, [
                'max_tokens' => $options['max_tokens'] ?? $this->options['max_tokens'] ?? 2000,
                'temperature' => $options['temperature'] ?? $this->options['temperature'] ?? 0.7,
            ]);

            if (is_wp_error($ai_response)) {
                return $ai_response;
            }

            // 5. Formatar resposta final
            $response_time = round((microtime(true) - $start_time) * 1000);

            $result = [
                'query' => $query,
                'response' => $ai_response['response'],
                'items' => $this->format_items($similar_items, $query, $max_results),
                'total_results' => count($similar_items),
                'usage' => $ai_response['usage'],
                'model' => $ai_response['model'],
                'cost' => $ai_response['cost'] ?? 0,
                'rag_strategy' => $provider_fallback ? 'provider' : $rag_strategy,
                'response_time_ms' => $response_time,
                'search_id' => wp_generate_uuid4(),
                'from_cache' => false,
            ];

            // Salvar no cache
            $cache_duration = $this->options['cache_duration'] ?? 3600;
            set_transient($cache_key, $result, $cache_duration);

            // Registrar log
            $this->log_search($result, $collection_ids);

            return $result;

        } catch (\Exception $e) {
            \Oraculo_Tainacan\debug_log('Search error: ' . $e->getMessage(), null, 'error');
            return new WP_Error('search_error', $e->getMessage());
        }
    }

    /**
     * Busca apenas por similaridade (sem LLM)
     *
     * @param string $query
     * @param array $collection_ids
     * @param int $limit
     * @return array|WP_Error
     */
    public function semantic_search(string $query, array $collection_ids = [], int $limit = 10) {
        try {
            $embedding_provider = $this->ai_factory->create_for_embeddings();
            $embedding_result = $embedding_provider->generate_embedding($query);

            if (is_wp_error($embedding_result)) {
                return $embedding_result;
            }

            $similar_items = $this->vector_store->search(
                $embedding_result['embedding'],
                $collection_ids,
                $limit,
                $this->options['similarity_threshold'] ?? 0.3
            );

            if (is_wp_error($similar_items)) {
                return $similar_items;
            }

            return $this->format_items($similar_items, $query, $limit);

        } catch (\Exception $e) {
            return new WP_Error('search_error', $e->getMessage());
        }
    }

    /**
     * Busca híbrida (semântica + keyword)
     *
     * @param string $query
     * @param array $collection_ids
     * @param int $limit
     * @return array|WP_Error
     */
    public function hybrid_search(string $query, array $collection_ids = [], int $limit = 10) {
        // Busca semântica
        $semantic_results = $this->semantic_search($query, $collection_ids, $limit);

        if (is_wp_error($semantic_results)) {
            return $semantic_results;
        }

        // Busca por keywords
        $keyword_results = $this->vector_store->keyword_search($query, $collection_ids, $limit);

        if (is_wp_error($keyword_results)) {
            $keyword_results = [];
        }

        // Combinar resultados (Reciprocal Rank Fusion)
        $combined = $this->reciprocal_rank_fusion($semantic_results, $keyword_results);

        return array_slice($combined, 0, $limit);
    }

    /**
     * Reciprocal Rank Fusion para combinar resultados
     *
     * @param array $list1
     * @param array $list2
     * @param int $k Constante de suavização
     * @return array
     */
    private function reciprocal_rank_fusion(array $list1, array $list2, int $k = 60): array {
        $scores = [];

        foreach ($list1 as $rank => $item) {
            $id = $item['id'];
            $scores[$id] = ($scores[$id] ?? 0) + 1 / ($k + $rank + 1);
            if (!isset($scores[$id . '_data'])) {
                $scores[$id . '_data'] = $item;
            }
        }

        foreach ($list2 as $rank => $item) {
            $id = $item['id'];
            $scores[$id] = ($scores[$id] ?? 0) + 1 / ($k + $rank + 1);
            if (!isset($scores[$id . '_data'])) {
                $scores[$id . '_data'] = $item;
            }
        }

        // Ordenar por score
        $results = [];
        foreach ($scores as $key => $value) {
            if (strpos($key, '_data') !== false) continue;
            $results[] = array_merge($scores[$key . '_data'], ['rrf_score' => $value]);
        }

        usort($results, fn($a, $b) => $b['rrf_score'] <=> $a['rrf_score']);

        return $results;
    }

    /**
     * Prepara contexto para o LLM
     *
     * @param array $items
     * @param int $max_items
     * @return string
     */
    private function prepare_context(array $items, int $max_items): string {
        $context_parts = [];

        $items = array_slice($items, 0, $max_items);

        foreach ($items as $index => $item) {
            $num = $index + 1;
            $text = \Oraculo_Tainacan\truncate_text($item['content_text'], 500);

            $context_parts[] = sprintf(
                "[%d] %s\nURL: %s\nSimilaridade: %.0f%%",
                $num,
                $text,
                $item['item_url'] ?? '',
                ($item['similarity'] ?? 0) * 100
            );
        }

        return implode("\n\n", $context_parts);
    }

    /**
     * Obtém prompt do sistema
     *
     * @return string
     */
    private function get_system_prompt(): string {
        return $this->options['system_prompt'] ?? $this->get_default_system_prompt();
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
     * Prompt do sistema padrão
     *
     * @return string
     */
    private function get_default_system_prompt(): string {
        return __('Você é um assistente especializado em ajudar usuários a encontrar informações no acervo digital.
Suas respostas devem ser:
- Precisas e baseadas apenas nas informações fornecidas do acervo
- Claras e em português brasileiro
- Úteis, indicando sempre os itens relevantes encontrados
- Honestas quando não houver informação suficiente para responder

Quando citar itens do acervo, sempre mencione o título e forneça o link quando disponível.
Se a pergunta não puder ser respondida com as informações disponíveis, informe educadamente e sugira reformular a pergunta.', 'oraculo_tainacan');
    }

    /**
     * Constrói prompt de busca
     *
     * @param string $query
     * @param string $context
     * @return string
     */
    private function build_search_prompt(string $query, string $context): string {
        $template = $this->options['search_prompt'] ?? $this->get_default_search_prompt();

        return str_replace(
            ['{query}', '{context}'],
            [$query, $context],
            $template
        );
    }

    /**
     * Prompt de busca padrão
     *
     * @return string
     */
    private function get_default_search_prompt(): string {
        return __('Com base nos itens do acervo listados abaixo, responda à pergunta do usuário de forma concisa e informativa.

ITENS DO ACERVO:
{context}

PERGUNTA: {query}

Forneça uma resposta clara, mencionando os itens mais relevantes encontrados. Se houver links, inclua-os na resposta.', 'oraculo_tainacan');
    }

    /**
     * Formata itens para resposta
     *
     * @param array $items
     * @param string $query
     * @param int $limit
     * @return array
     */
    private function format_items(array $items, string $query, int $limit): array {
        $formatted = [];

        foreach (array_slice($items, 0, $limit) as $item) {
            $formatted[] = [
                'id' => $item['item_id'],
                'title' => $item['item_title'] ?? '',
                'snippet' => \Oraculo_Tainacan\generate_snippet($item['content_text'], $query),
                'url' => $item['item_url'] ?? '',
                'collection_id' => $item['collection_id'],
                'collection_name' => $item['collection_name'] ?? '',
                'similarity' => round(($item['similarity'] ?? 0) * 100, 1),
                'metadata' => json_decode($item['metadata_json'] ?? '{}', true),
            ];
        }

        return $formatted;
    }

    /**
     * Resposta quando não há resultados
     *
     * @param string $query
     * @return array
     */
    private function build_no_results_response(string $query): array {
        return [
            'query' => $query,
            'response' => __('Não encontrei itens no acervo que correspondam à sua busca. Tente reformular sua pergunta ou usar termos diferentes.', 'oraculo_tainacan'),
            'items' => [],
            'total_results' => 0,
            'usage' => [],
            'model' => '',
            'cost' => 0,
            'response_time_ms' => 0,
            'search_id' => wp_generate_uuid4(),
            'from_cache' => false,
        ];
    }

    /**
     * Gera chave de cache
     *
     * @param string $query
     * @param array $collection_ids
     * @return string
     */
    private function get_cache_key(string $query, array $collection_ids): string {
        $data = [
            'query' => strtolower(trim($query)),
            'collections' => $collection_ids,
            'provider' => $this->options['ai_provider'] ?? 'openai',
        ];

        return 'oraculo_search_' . md5(wp_json_encode($data));
    }

    /**
     * Registra log de busca
     *
     * @param array $result
     * @param array $collection_ids
     */
    private function log_search(array $result, array $collection_ids): void {
        if (empty($this->options['enable_analytics'])) {
            return;
        }

        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom plugin table; no WP core API available for search logging.
        $wpdb->insert(
            $wpdb->prefix . 'oraculo_search_logs',
            [
                'query_text' => $result['query'],
                'query_hash' => md5(strtolower($result['query'])),
                'user_id' => get_current_user_id(),
                'session_id' => $result['search_id'],
                'collection_ids' => wp_json_encode($collection_ids),
                'results_count' => $result['total_results'],
                'response_time_ms' => $result['response_time_ms'],
                'tokens_used' => $result['usage']['total_tokens'] ?? 0,
                'model_used' => $result['model'],
                'ip_address' => $this->get_client_ip(),
                'user_agent' => sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) ),
                'created_at' => current_time('mysql'),
            ],
            ['%s', '%s', '%d', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s']
        );
    }

    /**
     * Obtém IP do cliente
     *
     * @return string
     */
    private function get_client_ip(): string {
        $headers = [
            'HTTP_CF_CONNECTING_IP', // Cloudflare
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'REMOTE_ADDR',
        ];

        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- IP address is used only internally for logging; not output or used in SQL.
                $ips = explode(',', wp_unslash( $_SERVER[$header] ));
                return trim($ips[0]);
            }
        }

        return '';
    }

    /**
     * Registra feedback de busca
     *
     * @param string $search_id
     * @param string $feedback 'positive' ou 'negative'
     * @return bool
     */
    public function record_feedback(string $search_id, string $feedback): bool {
        global $wpdb;

        $valid_feedback = ['positive', 'negative'];
        if (!in_array($feedback, $valid_feedback)) {
            return false;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; no WP core API available.
        $result = $wpdb->update(
            $wpdb->prefix . 'oraculo_search_logs',
            ['feedback' => $feedback],
            ['session_id' => $search_id],
            ['%s'],
            ['%s']
        );
        \Oraculo_Tainacan\oraculo_tainacan_flush_cache();

        return $result !== false;
    }

    /**
     * Obtém sugestões de busca baseadas no histórico
     *
     * @param int $limit
     * @return array
     */
    public function get_popular_searches(int $limit = 5): array {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; popular searches are analytics data; not critical to cache.
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT query_text, COUNT(*) as count
             FROM {$wpdb->prefix}oraculo_search_logs
             WHERE created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)
             GROUP BY query_hash
             ORDER BY count DESC
             LIMIT %d",
            $limit
        ), ARRAY_A);

        return array_column($results, 'query_text');
    }

    /**
     * Limpa cache de buscas
     *
     * @return int Número de entradas removidas
     */
    public function clear_cache(): int {
        global $wpdb;

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Deletes only plugin's own transient keys from options table; no WP API for bulk transient deletion by prefix.
        $count = $wpdb->query(
            "DELETE FROM {$wpdb->options}
             WHERE option_name LIKE '_transient_oraculo_search_%'
             OR option_name LIKE '_transient_timeout_oraculo_search_%'"
        );

        return $count;
    }
}
