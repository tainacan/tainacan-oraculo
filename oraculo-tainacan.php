<?php
/**
 * Plugin Name: Oráculo Tainacan
 * Plugin URI: https://github.com/tainacan/oraculo-tainacan
 * Description: Sistema avançado de busca em linguagem natural com IA para acervos Tainacan. Integra RAG (Retrieval-Augmented Generation) com múltiplos provedores de IA.
 * Version: 2.0.3
 * Author: Tainacan Community
 * Author URI: https://tainacan.org
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain: oraculo_tainacan
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.0
 *
 * @package Oraculo_Tainacan
 */

namespace Oraculo_Tainacan;

// Impedir acesso direto
if (!defined('ABSPATH')) {
    exit;
}

// Constantes do plugin
define('ORACULO_TAINACAN_VERSION', '2.0.3');
define('ORACULO_TAINACAN_FILE', __FILE__);
define('ORACULO_TAINACAN_PATH', plugin_dir_path(__FILE__));
define('ORACULO_TAINACAN_URL', plugin_dir_url(__FILE__));
define('ORACULO_TAINACAN_BASENAME', plugin_basename(__FILE__));
define('ORACULO_TAINACAN_MIN_PHP', '8.0');
define('ORACULO_TAINACAN_MIN_WP', '6.0');

/**
 * Classe principal do plugin Oráculo Tainacan
 *
 * @since 2.0.0
 */
final class Oraculo_Tainacan {

    /**
     * Instância única (Singleton)
     * @var Oraculo_Tainacan|null
     */
    private static ?Oraculo_Tainacan $instance = null;

    /**
     * Container de serviços
     * @var array
     */
    private array $services = [];

    /**
     * Obtém instância única
     * @return Oraculo_Tainacan
     */
    public static function get_instance(): Oraculo_Tainacan {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Construtor privado
     */
    private function __construct() {
        $this->check_requirements();
        $this->load_dependencies();
        $this->init_hooks();
    }

    /**
     * Verifica requisitos mínimos
     */
    private function check_requirements(): void {
        // Verificar versão PHP
        if (version_compare(PHP_VERSION, ORACULO_TAINACAN_MIN_PHP, '<')) {
            add_action('admin_notices', function() {
                $message = sprintf(
                    /* translators: 1: minimum required PHP version, 2: current PHP version */
                    __('Oráculo Tainacan requer PHP %1$s ou superior. Você está usando PHP %2$s.', 'oraculo_tainacan'),
                    ORACULO_TAINACAN_MIN_PHP,
                    PHP_VERSION
                );
                echo '<div class="notice notice-error"><p>' . esc_html($message) . '</p></div>';
            });
            return;
        }

        // Verificar versão WordPress
        global $wp_version;
        if (version_compare($wp_version, ORACULO_TAINACAN_MIN_WP, '<')) {
            add_action('admin_notices', function() {
                global $wp_version;
                $message = sprintf(
                    /* translators: 1: minimum required WordPress version, 2: current WordPress version */
                    __('Oráculo Tainacan requer WordPress %1$s ou superior. Você está usando WordPress %2$s.', 'oraculo_tainacan'),
                    ORACULO_TAINACAN_MIN_WP,
                    $wp_version
                );
                echo '<div class="notice notice-error"><p>' . esc_html($message) . '</p></div>';
            });
            return;
        }
    }

    /**
     * Carrega dependências
     */
    private function load_dependencies(): void {
        // Autoloader
        spl_autoload_register([$this, 'autoload']);

        // Carregar arquivos de funções auxiliares
        require_once ORACULO_TAINACAN_PATH . 'src/helpers.php';
    }

    /**
     * Autoloader de classes
     * @param string $class
     */
    public function autoload(string $class): void {
        $prefix = 'Oraculo_Tainacan\\';
        $base_dir = ORACULO_TAINACAN_PATH . 'src/';

        $len = strlen($prefix);
        if (strncmp($prefix, $class, $len) !== 0) {
            return;
        }

        $relative_class = substr($class, $len);
        $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

        if (file_exists($file)) {
            require $file;
        }
    }

    /**
     * Inicializa hooks
     */
    private function init_hooks(): void {
        // Hooks de ativação/desativação
        register_activation_hook(ORACULO_TAINACAN_FILE, [$this, 'activate']);
        register_deactivation_hook(ORACULO_TAINACAN_FILE, [$this, 'deactivate']);

        // Inicialização
        // load_plugin_textdomain() removed: WP >= 4.6 auto-loads translations for WP.org-hosted plugins.
        add_action('plugins_loaded', [$this, 'init_tainacan_page'], 20);
        add_action('plugins_loaded', [$this, 'init'], 25);

        // Admin
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);

        // Frontend
        add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_assets']);

        // REST API
        add_action('rest_api_init', [$this, 'register_rest_routes']);

        // AJAX
        add_action('wp_ajax_oraculo_search', [$this, 'ajax_search']);
        add_action('wp_ajax_nopriv_oraculo_search', [$this, 'ajax_search']);
        add_action('wp_ajax_oraculo_chat', [$this, 'ajax_chat']);
        add_action('wp_ajax_nopriv_oraculo_chat', [$this, 'ajax_chat']);
        add_action('wp_ajax_oraculo_index_collection', [$this, 'ajax_index_collection']);
        add_action('wp_ajax_oraculo_get_indexing_status', [$this, 'ajax_get_indexing_status']);
        add_action('wp_ajax_oraculo_feedback', [$this, 'ajax_feedback']);
        add_action('wp_ajax_oraculo_test_connection', [$this, 'ajax_test_connection']);
        add_action('wp_ajax_oraculo_clear_vectors', [$this, 'ajax_clear_vectors']);
        add_action('wp_ajax_oraculo_clear_all_vectors', [$this, 'ajax_clear_all_vectors']);
        add_action('wp_ajax_oraculo_optimize_db', [$this, 'ajax_optimize_db']);
        add_action('wp_ajax_oraculo_save_indexing_settings', [$this, 'ajax_save_indexing_settings']);
        add_action('wp_ajax_oraculo_save_settings', [$this, 'ajax_save_settings']);
        add_action('wp_ajax_oraculo_clear_cache', [$this, 'ajax_clear_cache']);

        // Cron para indexação em background
        add_action('oraculo_process_indexing_batch', [$this, 'process_indexing_batch']);

        // Tainacan hooks (a integração principal é feita via Tainacan Pages API)

        // Shortcodes
        add_shortcode('oraculo_search', [$this, 'render_search_shortcode']);
        add_shortcode('oraculo_chat', [$this, 'render_chat_shortcode']);

        // Filtros do plugin
        add_filter('plugin_action_links_' . ORACULO_TAINACAN_BASENAME, [$this, 'add_action_links']);

        // WP-CLI
        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::add_command('oraculo', CLI\Commands::class);
        }
    }

    /**
     * Ativação do plugin
     */
    public function activate(): void {
        // Criar tabelas
        $this->create_tables();

        // Opções padrão
        $this->set_default_options();

        // Agendar cron
        if (!wp_next_scheduled('oraculo_cleanup_old_data')) {
            wp_schedule_event(time(), 'daily', 'oraculo_cleanup_old_data');
        }

        // Flush rewrite rules
        flush_rewrite_rules();

        // Registrar versão
        update_option('oraculo_tainacan_version', ORACULO_TAINACAN_VERSION);
    }

    /**
     * Desativação do plugin
     */
    public function deactivate(): void {
        // Limpar cron jobs
        wp_clear_scheduled_hook('oraculo_process_indexing_batch');
        wp_clear_scheduled_hook('oraculo_cleanup_old_data');

        // Limpar transients
        $this->clear_all_transients();

        // Flush rewrite rules
        flush_rewrite_rules();
    }

    /**
     * Cria tabelas do banco de dados
     */
    private function create_tables(): void {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        // Tabela de vetores (embeddings)
        $table_vectors = $wpdb->prefix . 'oraculo_vectors';
        $sql_vectors = "CREATE TABLE IF NOT EXISTS {$table_vectors} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            item_id BIGINT(20) UNSIGNED NOT NULL,
            collection_id BIGINT(20) UNSIGNED NOT NULL,
            collection_name VARCHAR(255) DEFAULT '',
            embedding_data LONGTEXT NOT NULL,
            content_text LONGTEXT NOT NULL,
            content_hash VARCHAR(64) NOT NULL,
            item_url VARCHAR(500) DEFAULT '',
            item_title VARCHAR(500) DEFAULT '',
            metadata_json LONGTEXT DEFAULT NULL,
            embedding_model VARCHAR(100) DEFAULT 'text-embedding-ada-002',
            token_count INT(11) DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY idx_item_collection (item_id, collection_id),
            KEY idx_collection (collection_id),
            KEY idx_content_hash (content_hash),
            KEY idx_created (created_at)
        ) {$charset_collate};";

        // Tabela de conversas (chat)
        $table_conversations = $wpdb->prefix . 'oraculo_conversations';
        $sql_conversations = "CREATE TABLE IF NOT EXISTS {$table_conversations} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            session_id VARCHAR(64) NOT NULL,
            user_id BIGINT(20) UNSIGNED DEFAULT 0,
            collection_ids TEXT DEFAULT NULL,
            title VARCHAR(255) DEFAULT '',
            status VARCHAR(20) DEFAULT 'active',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_session (session_id),
            KEY idx_user (user_id),
            KEY idx_status (status)
        ) {$charset_collate};";

        // Tabela de mensagens
        $table_messages = $wpdb->prefix . 'oraculo_messages';
        $sql_messages = "CREATE TABLE IF NOT EXISTS {$table_messages} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            conversation_id BIGINT(20) UNSIGNED NOT NULL,
            role VARCHAR(20) NOT NULL DEFAULT 'user',
            content LONGTEXT NOT NULL,
            tokens_used INT(11) DEFAULT 0,
            model_used VARCHAR(100) DEFAULT '',
            sources_json LONGTEXT DEFAULT NULL,
            feedback VARCHAR(20) DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_conversation (conversation_id),
            KEY idx_role (role),
            KEY idx_created (created_at)
        ) {$charset_collate};";

        // Tabela de logs de busca/analytics
        $table_logs = $wpdb->prefix . 'oraculo_search_logs';
        $sql_logs = "CREATE TABLE IF NOT EXISTS {$table_logs} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            query_text TEXT NOT NULL,
            query_hash VARCHAR(64) NOT NULL,
            user_id BIGINT(20) UNSIGNED DEFAULT 0,
            session_id VARCHAR(64) DEFAULT '',
            collection_ids TEXT DEFAULT NULL,
            results_count INT(11) DEFAULT 0,
            response_time_ms INT(11) DEFAULT 0,
            tokens_used INT(11) DEFAULT 0,
            model_used VARCHAR(100) DEFAULT '',
            feedback VARCHAR(20) DEFAULT NULL,
            ip_address VARCHAR(45) DEFAULT '',
            user_agent TEXT DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_query_hash (query_hash),
            KEY idx_user (user_id),
            KEY idx_session (session_id),
            KEY idx_created (created_at),
            KEY idx_feedback (feedback)
        ) {$charset_collate};";

        // Tabela de estado de indexação
        $table_indexing = $wpdb->prefix . 'oraculo_indexing_jobs';
        $sql_indexing = "CREATE TABLE IF NOT EXISTS {$table_indexing} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            collection_id BIGINT(20) UNSIGNED NOT NULL,
            status VARCHAR(20) DEFAULT 'pending',
            total_items INT(11) DEFAULT 0,
            processed_items INT(11) DEFAULT 0,
            failed_items INT(11) DEFAULT 0,
            current_page INT(11) DEFAULT 1,
            items_per_page INT(11) DEFAULT 25,
            error_log LONGTEXT DEFAULT NULL,
            started_at DATETIME DEFAULT NULL,
            completed_at DATETIME DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY idx_collection (collection_id),
            KEY idx_status (status)
        ) {$charset_collate};";

        // Tabela de prompts customizados por coleção
        $table_prompts = $wpdb->prefix . 'oraculo_collection_prompts';
        $sql_prompts = "CREATE TABLE IF NOT EXISTS {$table_prompts} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            collection_id BIGINT(20) UNSIGNED NOT NULL,
            system_prompt LONGTEXT DEFAULT NULL,
            search_prompt LONGTEXT DEFAULT NULL,
            chat_prompt LONGTEXT DEFAULT NULL,
            welcome_message TEXT DEFAULT NULL,
            suggested_questions TEXT DEFAULT NULL,
            is_active TINYINT(1) DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY idx_collection (collection_id)
        ) {$charset_collate};";

        // Tabela de memória de conversação
        $table_memory = $wpdb->prefix . 'oraculo_memory';
        $sql_memory = "CREATE TABLE IF NOT EXISTS {$table_memory} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            session_id VARCHAR(64) NOT NULL,
            user_id BIGINT UNSIGNED DEFAULT 0,
            memory_type ENUM('summary', 'context', 'preference') NOT NULL,
            content TEXT NOT NULL,
            message_range VARCHAR(50) DEFAULT NULL,
            importance_score FLOAT DEFAULT 0.5,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_session (session_id),
            KEY idx_user (user_id),
            KEY idx_type (memory_type)
        ) {$charset_collate};";

        // Tabela de fatos extraídos
        $table_facts = $wpdb->prefix . 'oraculo_facts';
        $sql_facts = "CREATE TABLE IF NOT EXISTS {$table_facts} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            session_id VARCHAR(64) NOT NULL,
            user_id BIGINT UNSIGNED DEFAULT 0,
            fact_type VARCHAR(50) NOT NULL,
            fact_key VARCHAR(100) NOT NULL,
            fact_value TEXT NOT NULL,
            confidence FLOAT DEFAULT 1.0,
            source_message_id BIGINT UNSIGNED DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_fact (session_id, fact_key),
            KEY idx_user (user_id),
            KEY idx_type (fact_type)
        ) {$charset_collate};";

        // Tabela de webhooks
        $table_webhooks = $wpdb->prefix . 'oraculo_webhooks';
        $sql_webhooks = "CREATE TABLE IF NOT EXISTS {$table_webhooks} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(100) NOT NULL,
            url VARCHAR(500) NOT NULL,
            events TEXT NOT NULL,
            secret VARCHAR(100) DEFAULT NULL,
            active TINYINT(1) DEFAULT 1,
            headers TEXT DEFAULT NULL,
            retry_count INT DEFAULT 3,
            last_triggered DATETIME DEFAULT NULL,
            last_status INT DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta($sql_vectors);
        dbDelta($sql_conversations);
        dbDelta($sql_messages);
        dbDelta($sql_logs);
        dbDelta($sql_indexing);
        dbDelta($sql_prompts);
        dbDelta($sql_memory);
        dbDelta($sql_facts);
        dbDelta($sql_webhooks);
    }

    /**
     * Define opções padrão
     */
    private function set_default_options(): void {
        $defaults = [
            'ai_provider' => 'openai',
            'openai_api_key' => '',
            'openai_model' => 'gpt-4o-mini',
            'openai_embedding_model' => 'text-embedding-ada-002',
            'gemini_api_key' => '',
            'gemini_model' => 'gemini-1.5-pro',
            'deepseek_api_key' => '',
            'deepseek_model' => 'deepseek-chat',
            'ollama_url' => 'http://localhost:11434',
            'ollama_model' => 'llama3.2',
            'ollama_embedding_model' => 'nomic-embed-text',
            'generic_openai_api_base_url' => '',
            'generic_openai_api_key' => '',
            'generic_openai_model' => '',
            'generic_openai_embedding_model' => '',
            'rag_strategy' => 'local',
            'max_tokens' => 2000,
            'temperature' => 0.7,
            'similarity_threshold' => 0.3,
            'max_results' => 10,
            'batch_size' => 25,
            'request_timeout' => 120,
            'cache_duration' => 3600,
            'enable_chat' => true,
            'enable_search' => true,
            'enable_analytics' => true,
            'enable_feedback' => true,
            'debug_mode' => false,
            'default_collections' => [],
            'system_prompt' => $this->get_default_system_prompt(),
            'search_prompt' => $this->get_default_search_prompt(),
            'chat_prompt' => $this->get_default_chat_prompt(),
            'welcome_message' => __('Olá! Sou o assistente do acervo. Como posso ajudá-lo a encontrar informações?', 'oraculo_tainacan'),
            'suggested_questions' => [
                __('Quais são os itens mais recentes do acervo?', 'oraculo_tainacan'),
                __('Mostre documentos sobre [tema]', 'oraculo_tainacan'),
                __('Quais coleções estão disponíveis?', 'oraculo_tainacan'),
            ],
            'index_fields' => ['title', 'description'],
            'appearance' => [
                'primary_color' => '#1f2f56',
                'accent_color' => '#b5e0e3',
                'chat_position' => 'bottom-right',
                'show_sources' => true,
                'show_similarity' => false,
            ],
        ];

        $existing = get_option('oraculo_tainacan_options', []);
        $merged = wp_parse_args($existing, $defaults);
        update_option('oraculo_tainacan_options', $merged);
    }

    /**
     * Prompt do sistema padrão
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
     * Prompt de busca padrão
     */
    private function get_default_search_prompt(): string {
        return __('Com base nos itens do acervo listados abaixo, responda à pergunta do usuário de forma concisa e informativa.

ITENS DO ACERVO:
{context}

PERGUNTA: {query}

Forneça uma resposta clara, mencionando os itens mais relevantes encontrados.', 'oraculo_tainacan');
    }

    /**
     * Prompt de chat padrão
     */
    private function get_default_chat_prompt(): string {
        return __('Você está em uma conversa com um usuário que busca informações no acervo digital.

HISTÓRICO DA CONVERSA:
{history}

CONTEXTO DO ACERVO:
{context}

MENSAGEM DO USUÁRIO: {message}

Responda de forma natural e conversacional, sempre baseando-se nas informações do acervo quando relevante.', 'oraculo_tainacan');
    }

    /**
     * Limpa todos os transients do plugin
     */
    private function clear_all_transients(): void {
        global $wpdb;
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Bulk transient DELETE by prefix; no WP API equivalent; write operation, caching N/A.
        $wpdb->query(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_oraculo_%' OR option_name LIKE '_transient_timeout_oraculo_%'"
        );
        oraculo_tainacan_flush_cache();
    }

    /**
     * Inicializa página do Tainacan usando a API de Pages
     */
    public function init_tainacan_page(): void {
        // Verificar se a classe base do Tainacan existe
        if (class_exists('\Tainacan\Pages')) {
            require_once ORACULO_TAINACAN_PATH . 'src/Admin/OraculoPage.php';
            \Tainacan\Oraculo_Page::get_instance();
        }
    }

    /**
     * Inicialização principal
     */
    public function init(): void {
        // Inicializar serviços (admin page é inicializada via Tainacan Pages API)
        $this->services['api'] = new API\RestController();
        $this->services['search'] = new Search\SearchEngine();
        $this->services['chat'] = new Chat\ChatEngine();
        $this->services['indexing'] = new Indexing\IndexingManager();
        $this->services['analytics'] = new Analytics\AnalyticsManager();
    }

    /**
     * Registra configurações
     */
    public function register_settings(): void {
        register_setting('oraculo_tainacan', 'oraculo_tainacan_options', [
            'type' => 'array',
            'sanitize_callback' => [$this, 'sanitize_options'],
        ]);
    }

    /**
     * Sanitiza opções
     */
    public function sanitize_options(array $options): array {
        // Obter opções existentes para preservar API keys
        $existing_options = get_option('oraculo_tainacan_options', []);

        // Sanitização de API keys - preservar se for placeholder
        $api_keys = ['openai_api_key', 'gemini_api_key', 'deepseek_api_key', 'anthropic_api_key', 'groq_api_key', 'claude_api_key', 'generic_openai_api_key'];
        foreach ($api_keys as $key) {
            if (isset($options[$key])) {
                $value = sanitize_text_field($options[$key]);
                // Se o valor for placeholder (••••••••) ou vazio, preservar o valor existente
                if ($value === '••••••••' || $value === '' || strpos($value, '•') !== false) {
                    if (!empty($existing_options[$key])) {
                        $options[$key] = $existing_options[$key];
                    } else {
                        unset($options[$key]);
                    }
                } else {
                    $options[$key] = $value;
                }
            }
        }

        // Sanitização de números
        if (isset($options['max_tokens'])) {
            $options['max_tokens'] = absint($options['max_tokens']);
        }
        if (isset($options['temperature'])) {
            $options['temperature'] = floatval($options['temperature']);
            $options['temperature'] = max(0, min(2, $options['temperature']));
        }
        if (isset($options['similarity_threshold'])) {
            $options['similarity_threshold'] = floatval($options['similarity_threshold']);
            $options['similarity_threshold'] = max(0, min(1, $options['similarity_threshold']));
        }
        if (isset($options['generic_openai_api_base_url'])) {
            $options['generic_openai_api_base_url'] = esc_url_raw($options['generic_openai_api_base_url']);
        }
        if (isset($options['generic_openai_model'])) {
            $options['generic_openai_model'] = sanitize_text_field($options['generic_openai_model']);
        }
        if (isset($options['generic_openai_embedding_model'])) {
            $options['generic_openai_embedding_model'] = sanitize_text_field($options['generic_openai_embedding_model']);
        }
        if (isset($options['rag_strategy'])) {
            $allowed_strategies = ['local', 'provider', 'hybrid'];
            $rag_strategy = sanitize_text_field($options['rag_strategy']);
            $options['rag_strategy'] = in_array($rag_strategy, $allowed_strategies, true) ? $rag_strategy : 'local';
        }

        return $options;
    }

    /**
     * Enfileira assets do admin
     */
    public function enqueue_admin_assets(string $hook): void {
        // Verificar se estamos em uma página do plugin
        if (strpos($hook, 'oraculo') === false && strpos($hook, 'tainacan') === false) {
            return;
        }

        // CSS Admin
        wp_enqueue_style(
            'oraculo-admin',
            ORACULO_TAINACAN_URL . 'assets/css/admin.css',
            [],
            ORACULO_TAINACAN_VERSION
        );

        // JS Admin
        wp_enqueue_script(
            'oraculo-admin',
            ORACULO_TAINACAN_URL . 'assets/js/admin.js',
            ['jquery', 'wp-util'],
            ORACULO_TAINACAN_VERSION,
            true
        );

        // Chart.js para analytics (bundled locally to avoid external CDN)
        wp_enqueue_script(
            'chart-js',
            ORACULO_TAINACAN_URL . 'assets/vendor/chart.umd.min.js',
            [],
            '4.4.1',
            true
        );

        // Localizar script
        wp_localize_script('oraculo-admin', 'OraculoAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'restUrl' => rest_url('oraculo/v1/'),
            'nonce' => wp_create_nonce('oraculo_admin'),
            'restNonce' => wp_create_nonce('wp_rest'),
            'strings' => [
                'confirmDelete' => __('Tem certeza que deseja excluir?', 'oraculo_tainacan'),
                'indexing' => __('Indexando...', 'oraculo_tainacan'),
                'completed' => __('Concluído!', 'oraculo_tainacan'),
                'error' => __('Erro:', 'oraculo_tainacan'),
                'testing' => __('Testando conexão...', 'oraculo_tainacan'),
                'success' => __('Sucesso!', 'oraculo_tainacan'),
            ],
        ]);
    }

    /**
     * Enfileira assets do frontend
     */
    public function enqueue_frontend_assets(): void {
        $options = get_option('oraculo_tainacan_options', []);

        // CSS Frontend
        wp_enqueue_style(
            'oraculo-frontend',
            ORACULO_TAINACAN_URL . 'assets/css/frontend.css',
            [],
            ORACULO_TAINACAN_VERSION
        );

        // CSS do Chat Widget
        if (!empty($options['enable_chat'])) {
            wp_enqueue_style(
                'oraculo-chat',
                ORACULO_TAINACAN_URL . 'assets/css/chat-widget.css',
                [],
                ORACULO_TAINACAN_VERSION
            );
        }

        // JS Frontend
        wp_enqueue_script(
            'oraculo-frontend',
            ORACULO_TAINACAN_URL . 'assets/js/frontend.js',
            ['jquery'],
            ORACULO_TAINACAN_VERSION,
            true
        );

        // JS do Chat Widget
        if (!empty($options['enable_chat'])) {
            wp_enqueue_script(
                'oraculo-chat',
                ORACULO_TAINACAN_URL . 'assets/js/chat-widget.js',
                ['jquery', 'oraculo-frontend'],
                ORACULO_TAINACAN_VERSION,
                true
            );
        }

        // CSS customizado baseado nas opções
        $appearance = $options['appearance'] ?? [];
        $custom_css = ':root {
            --oraculo-primary: ' . esc_attr($appearance['primary_color'] ?? '#1f2f56') . ';
            --oraculo-accent: ' . esc_attr($appearance['accent_color'] ?? '#b5e0e3') . ';
        }';
        wp_add_inline_style('oraculo-frontend', $custom_css);

        // Localizar script
        wp_localize_script('oraculo-frontend', 'OraculoFrontend', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'restUrl' => rest_url('oraculo/v1/'),
            'nonce' => wp_create_nonce('oraculo_frontend'),
            'restNonce' => wp_create_nonce('wp_rest'),
            'enableChat' => !empty($options['enable_chat']),
            'enableSearch' => !empty($options['enable_search']),
            'chatPosition' => $appearance['chat_position'] ?? 'bottom-right',
            'welcomeMessage' => $options['welcome_message'] ?? '',
            'suggestedQuestions' => $options['suggested_questions'] ?? [],
            'strings' => [
                'placeholder' => __('Digite sua pergunta...', 'oraculo_tainacan'),
                'send' => __('Enviar', 'oraculo_tainacan'),
                'searching' => __('Buscando...', 'oraculo_tainacan'),
                'thinking' => __('Pensando...', 'oraculo_tainacan'),
                'error' => __('Ocorreu um erro. Tente novamente.', 'oraculo_tainacan'),
                'noResults' => __('Nenhum resultado encontrado.', 'oraculo_tainacan'),
                'helpful' => __('Esta resposta foi útil?', 'oraculo_tainacan'),
                'yes' => __('Sim', 'oraculo_tainacan'),
                'no' => __('Não', 'oraculo_tainacan'),
                'sources' => __('Fontes', 'oraculo_tainacan'),
            ],
        ]);
    }

    /**
     * Registra rotas REST
     */
    public function register_rest_routes(): void {
        if (isset($this->services['api'])) {
            $this->services['api']->register_routes();
        }
    }

    /**
     * Handler AJAX de busca
     */
    public function ajax_search(): void {
        check_ajax_referer('oraculo_frontend', 'nonce');

        $query = sanitize_text_field( wp_unslash( $_POST['query'] ?? '' ) );
        $collections = array_map('absint', (array)($_POST['collections'] ?? []));

        if (empty($query)) {
            wp_send_json_error(['message' => __('Pergunta não pode estar vazia.', 'oraculo_tainacan')]);
        }

        try {
            $result = $this->services['search']->search($query, $collections);
            wp_send_json_success($result);
        } catch (\Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * Handler AJAX de chat
     */
    public function ajax_chat(): void {
        check_ajax_referer('oraculo_frontend', 'nonce');

        $message = sanitize_text_field( wp_unslash( $_POST['message'] ?? '' ) );
        $session_id = sanitize_text_field( wp_unslash( $_POST['session_id'] ?? '' ) );
        $collections = array_map('absint', (array)($_POST['collections'] ?? []));

        if (empty($message)) {
            wp_send_json_error(['message' => __('Mensagem não pode estar vazia.', 'oraculo_tainacan')]);
        }

        try {
            $result = $this->services['chat']->chat($message, $session_id, $collections);
            wp_send_json_success($result);
        } catch (\Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * Handler AJAX para iniciar indexação (síncrona)
     */
    public function ajax_index_collection(): void {
        try {
            // Aumentar limites para indexação síncrona
            if ( function_exists( 'set_time_limit' ) ) {
                // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- Long-running indexing batch; conditional and harmless if disabled.
                @set_time_limit( 300 );
            }
            if ( function_exists( 'ini_set' ) ) {
                // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- Memory limit increase for large indexing jobs; conditional and harmless if disabled.
                @ini_set( 'memory_limit', '512M' );
            }

            check_ajax_referer('oraculo_admin', 'nonce');

            if (!current_user_can('manage_options')) {
                wp_send_json_error(['message' => __('Permissão negada.', 'oraculo_tainacan')]);
                return;
            }

            $collection_id = absint($_POST['collection_id'] ?? 0);
            $force = !empty($_POST['force']);

            if (empty($collection_id)) {
                wp_send_json_error(['message' => __('ID da coleção inválido.', 'oraculo_tainacan')]);
                return;
            }

            if (!isset($this->services['indexing'])) {
                wp_send_json_error(['message' => __('Serviço de indexação não inicializado.', 'oraculo_tainacan')]);
                return;
            }

            // Executar indexação síncrona
            $result = $this->services['indexing']->start_indexing($collection_id, $force);

            if (is_wp_error($result)) {
                wp_send_json_error(['message' => $result->get_error_message()]);
                return;
            }

            wp_send_json_success($result);
        } catch (\Throwable $e) {
            wp_send_json_error([
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
        }
    }

    /**
     * Handler AJAX para status de indexação
     */
    public function ajax_get_indexing_status(): void {
        check_ajax_referer('oraculo_admin', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permissão negada.', 'oraculo_tainacan')]);
        }

        $collection_id = absint($_POST['collection_id'] ?? 0);

        try {
            if (!isset($this->services['indexing'])) {
                wp_send_json_error(['message' => __('Serviço de indexação não inicializado.', 'oraculo_tainacan')]);
                return;
            }
            $status = $this->services['indexing']->get_status($collection_id);
            wp_send_json_success($status);
        } catch (\Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * Handler AJAX para feedback
     */
    public function ajax_feedback(): void {
        check_ajax_referer('oraculo_frontend', 'nonce');

        $search_id = sanitize_text_field( wp_unslash( $_POST['search_id'] ?? '' ) );
        $feedback = sanitize_text_field( wp_unslash( $_POST['feedback'] ?? '' ) );
        $message_id = absint($_POST['message_id'] ?? 0);

        try {
            $this->services['analytics']->record_feedback($search_id, $feedback, $message_id);
            wp_send_json_success(['message' => __('Obrigado pelo feedback!', 'oraculo_tainacan')]);
        } catch (\Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * Handler AJAX para testar conexão
     */
    public function ajax_test_connection(): void {
        check_ajax_referer('oraculo_admin', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permissão negada.', 'oraculo_tainacan')]);
        }

        $provider = sanitize_text_field( wp_unslash( $_POST['provider'] ?? 'openai' ) );

        try {
            $factory = new AI\AIProviderFactory();
            $ai_provider = $factory->create($provider);
            $result = $ai_provider->test_connection();
            wp_send_json_success($result);
        } catch (\Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * Handler AJAX para limpar vetores de uma coleção
     */
    public function ajax_clear_vectors(): void {
        check_ajax_referer('oraculo_admin', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permissão negada.', 'oraculo_tainacan')]);
        }

        $collection_id = absint($_POST['collection_id'] ?? 0);

        if (empty($collection_id)) {
            wp_send_json_error(['message' => __('ID da coleção inválido.', 'oraculo_tainacan')]);
        }

        try {
            $vector_store = new Vector\VectorStore();
            $deleted = $vector_store->delete_collection($collection_id);
            wp_send_json_success([
                /* translators: %d: number of vectors deleted */
                'message' => sprintf(__('%d vetores removidos.', 'oraculo_tainacan'), $deleted),
                'deleted' => $deleted
            ]);
        } catch (\Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * Handler AJAX para limpar todos os vetores
     */
    public function ajax_clear_all_vectors(): void {
        check_ajax_referer('oraculo_admin', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permissão negada.', 'oraculo_tainacan')]);
        }

        try {
            global $wpdb;
            $table = $wpdb->prefix . 'oraculo_vectors';
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $table is plugin-owned (oraculo_vectors, built from $wpdb->prefix + literal); TRUNCATE TABLE cannot use $wpdb->prepare(); write operation, caching N/A.
            $deleted = $wpdb->query("TRUNCATE TABLE {$table}");
            wp_send_json_success([
                'message' => __('Todos os vetores foram removidos.', 'oraculo_tainacan')
            ]);
        } catch (\Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * Handler AJAX para otimizar banco de dados
     */
    public function ajax_optimize_db(): void {
        check_ajax_referer('oraculo_admin', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permissão negada.', 'oraculo_tainacan')]);
        }

        try {
            global $wpdb;
            $tables = [
                $wpdb->prefix . 'oraculo_vectors',
                $wpdb->prefix . 'oraculo_search_logs',
                $wpdb->prefix . 'oraculo_indexing_jobs'
            ];

            foreach ($tables as $table) {
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- $table is plugin-owned (built from $wpdb->prefix + literal); OPTIMIZE TABLE has no WP API equivalent; write operation, caching N/A.
                $wpdb->query("OPTIMIZE TABLE {$table}");
            }

            wp_send_json_success([
                'message' => __('Banco de dados otimizado com sucesso.', 'oraculo_tainacan')
            ]);
        } catch (\Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * Handler AJAX para salvar configurações de indexação
     */
    public function ajax_save_indexing_settings(): void {
        check_ajax_referer('oraculo_admin', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permissão negada.', 'oraculo_tainacan')]);
        }

        try {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- parse_str target; each key in $settings is sanitized individually below (absint, sanitize_text_field, !empty).
            parse_str( wp_unslash( $_POST['settings'] ?? '' ), $settings );
            // Allowlist of expected keys; each leaf sanitized individually below.
            $allowed_settings_keys = [ 'batch_size', 'embedding_provider', 'auto_index', 'index_title', 'index_description', 'index_metadata', 'index_document' ];
            $settings = array_intersect_key( $settings, array_flip( $allowed_settings_keys ) );

            // Salvar configurações
            update_option('oraculo_batch_size', absint($settings['batch_size'] ?? 10));
            update_option('oraculo_embedding_provider', sanitize_text_field($settings['embedding_provider'] ?? 'openai'));
            update_option('oraculo_auto_index', !empty($settings['auto_index']));
            update_option('oraculo_index_title', !empty($settings['index_title']));
            update_option('oraculo_index_description', !empty($settings['index_description']));
            update_option('oraculo_index_metadata', !empty($settings['index_metadata']));
            update_option('oraculo_index_document', !empty($settings['index_document']));

            wp_send_json_success([
                'message' => __('Configurações salvas com sucesso.', 'oraculo_tainacan')
            ]);
        } catch (\Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * Handler AJAX para salvar configurações gerais
     */
    public function ajax_save_settings(): void {
        check_ajax_referer('oraculo_admin', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permissão negada.', 'oraculo_tainacan')]);
            return;
        }

        try {
            // Coletar todas as opções do formulário
            $options = [];

            // Provedor de IA
            if ( isset( $_POST['oraculo_tainacan_options'] ) ) {
                // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize_options() below walks the array and sanitizes every leaf.
                $options = wp_unslash( $_POST['oraculo_tainacan_options'] );
            }

            // Sanitizar opções
            $sanitized = $this->sanitize_options($options);

            // Salvar
            update_option('oraculo_tainacan_options', $sanitized);

            // Limpar cache de provedores para forçar recriação com novas configurações
            \Oraculo_Tainacan\AI\AIProviderFactory::clear_cache();

            wp_send_json_success([
                'message' => __('Configurações salvas com sucesso.', 'oraculo_tainacan')
            ]);
        } catch (\Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * Handler AJAX para limpar cache
     */
    public function ajax_clear_cache(): void {
        check_ajax_referer('oraculo_admin', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permissão negada.', 'oraculo_tainacan')]);
            return;
        }

        try {
            // Limpar transients do plugin
            $this->clear_all_transients();

            wp_send_json_success([
                'message' => __('Cache limpo com sucesso.', 'oraculo_tainacan')
            ]);
        } catch (\Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * Processa batch de indexação (cron)
     */
    public function process_indexing_batch(): void {
        if (isset($this->services['indexing'])) {
            $this->services['indexing']->process_next_batch();
        }
    }


    /**
     * Renderiza shortcode de busca
     */
    public function render_search_shortcode(array $atts): string {
        $atts = shortcode_atts([
            'placeholder' => __('O que você está procurando?', 'oraculo_tainacan'),
            'button_text' => __('Buscar', 'oraculo_tainacan'),
            'collections' => '',
            'show_filters' => 'true',
            'results_per_page' => 10,
        ], $atts, 'oraculo_search');

        ob_start();
        oraculo_tainacan_render_template( 'search-widget.php', [ 'atts' => $atts ] );
        return ob_get_clean();
    }

    /**
     * Renderiza shortcode de chat
     */
    public function render_chat_shortcode(array $atts): string {
        $atts = shortcode_atts([
            'collections' => '',
            'title' => __('Assistente do Acervo', 'oraculo_tainacan'),
            'height' => '500px',
            'show_suggestions' => 'true',
        ], $atts, 'oraculo_chat');

        ob_start();
        oraculo_tainacan_render_template( 'chat-widget.php', [ 'atts' => $atts ] );
        return ob_get_clean();
    }

    /**
     * Adiciona links de ação
     */
    public function add_action_links(array $links): array {
        $settings_link = '<a href="' . admin_url('admin.php?page=oraculo-settings') . '">' .
                        __('Configurações', 'oraculo_tainacan') . '</a>';
        array_unshift($links, $settings_link);
        return $links;
    }

    /**
     * Obtém serviço
     */
    public function get_service(string $name) {
        return $this->services[$name] ?? null;
    }

    /**
     * Obtém opções do plugin
     */
    public static function get_options(): array {
        return get_option('oraculo_tainacan_options', []);
    }

    /**
     * Atualiza opção específica
     */
    public static function update_option(string $key, $value): bool {
        $options = self::get_options();
        $options[$key] = $value;
        return update_option('oraculo_tainacan_options', $options);
    }
}

// Função helper global
function oraculo_tainacan(): Oraculo_Tainacan {
    return Oraculo_Tainacan::get_instance();
}

// Inicializar plugin
oraculo_tainacan();
