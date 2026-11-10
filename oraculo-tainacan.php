<?php
/**
 * Plugin Name: Oráculo Tainacan
 * Plugin URI: https://github.com/tainacan/oraculo-tainacan
 * Description: Sistema avançado de busca em linguagem natural com IA para acervos Tainacan. Integra RAG (Retrieval-Augmented Generation) com múltiplos provedores de IA.
 * Version: 2.14.0
 * Author: Tainacan Community
 * Author URI: https://tainacan.org
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain: oraculo-tainacan
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * Requires Plugins: tainacan
 *
 * @package Oraculo_Tainacan
 */

declare( strict_types=1 );

namespace Oraculo_Tainacan;

// Impedir acesso direto
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Constantes do plugin
define( 'ORACULO_TAINACAN_VERSION', '2.14.0' );
define( 'ORACULO_TAINACAN_FILE', __FILE__ );
define( 'ORACULO_TAINACAN_PATH', plugin_dir_path( __FILE__ ) );
define( 'ORACULO_TAINACAN_URL', plugin_dir_url( __FILE__ ) );
define( 'ORACULO_TAINACAN_BASENAME', plugin_basename( __FILE__ ) );
define( 'ORACULO_TAINACAN_MIN_PHP', '8.0' );
define( 'ORACULO_TAINACAN_MIN_WP', '6.0' );

/**
 * Classe principal do plugin Oráculo Tainacan
 *
 * @since 2.0.0
 */
final class Oraculo_Tainacan {

	/**
	 * Instância única (Singleton)
	 *
	 * @var Oraculo_Tainacan|null
	 */
	private static ?Oraculo_Tainacan $instance = null;

	/**
	 * Container de serviços
	 *
	 * @var array
	 */
	private array $services = array();

	/**
	 * Obtém instância única
	 *
	 * @return Oraculo_Tainacan
	 */
	public static function get_instance(): Oraculo_Tainacan {
		if ( null === self::$instance ) {
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
		if ( version_compare( PHP_VERSION, ORACULO_TAINACAN_MIN_PHP, '<' ) ) {
			add_action(
				'admin_notices',
				function () {
					$message = sprintf(
					/* translators: 1: minimum required PHP version, 2: current PHP version */
						__( 'Oráculo Tainacan requer PHP %1$s ou superior. Você está usando PHP %2$s.', 'oraculo-tainacan' ),
						ORACULO_TAINACAN_MIN_PHP,
						PHP_VERSION
					);
					echo '<div class="notice notice-error"><p>' . esc_html( $message ) . '</p></div>';
				}
			);
			return;
		}

		// Verificar versão WordPress
		global $wp_version;
		if ( version_compare( $wp_version, ORACULO_TAINACAN_MIN_WP, '<' ) ) {
			add_action(
				'admin_notices',
				function () {
					global $wp_version;
					$message = sprintf(
					/* translators: 1: minimum required WordPress version, 2: current WordPress version */
						__( 'Oráculo Tainacan requer WordPress %1$s ou superior. Você está usando WordPress %2$s.', 'oraculo-tainacan' ),
						ORACULO_TAINACAN_MIN_WP,
						$wp_version
					);
					echo '<div class="notice notice-error"><p>' . esc_html( $message ) . '</p></div>';
				}
			);
			return;
		}
	}

	/**
	 * Carrega dependências
	 */
	private function load_dependencies(): void {
		// Autoloader
		spl_autoload_register( array( $this, 'autoload' ) );

		// Carregar arquivos de funções auxiliares
		require_once ORACULO_TAINACAN_PATH . 'src/helpers.php';
	}

	/**
	 * Autoloader de classes
	 *
	 * @param string $class
	 */
	public function autoload( string $class ): void {
		$prefix   = 'Oraculo_Tainacan\\';
		$base_dir = ORACULO_TAINACAN_PATH . 'src/';

		$len = strlen( $prefix );
		if ( strncmp( $prefix, $class, $len ) !== 0 ) {
			return;
		}

		$relative_class = substr( $class, $len );
		$file           = $base_dir . str_replace( '\\', '/', $relative_class ) . '.php';

		if ( file_exists( $file ) ) {
			require $file;
		}
	}

	/**
	 * Inicializa hooks
	 */
	private function init_hooks(): void {
		// Hooks de ativação/desativação
		register_activation_hook( ORACULO_TAINACAN_FILE, array( $this, 'activate' ) );
		register_deactivation_hook( ORACULO_TAINACAN_FILE, array( $this, 'deactivate' ) );

		// Inicialização
		// load_plugin_textdomain() removed: WP >= 4.6 auto-loads translations for WP.org-hosted plugins.
		add_action( 'plugins_loaded', array( $this, 'init_tainacan_page' ), 20 );
		add_action( 'plugins_loaded', array( $this, 'init' ), 25 );

		// Admin (os assets da página vivem em Admin\OraculoPage, via Tainacan Pages API)
		add_action( 'admin_init', array( $this, 'register_settings' ) );

		// Diagnóstico (uso intenso) e modo rápido: arquivo do mu-plugin em dia
		// depois de atualizar o plugin ou trocar o modo de busca.
		Admin\Diagnostics::register();
		add_action( 'admin_init', array( Performance\FastRest::class, 'maintain' ) );

		// Frontend
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );

		// REST API
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

		// AJAX (somente admin — a superfície pública de busca/chat/feedback vive no REST oraculo/v1)
		add_action( 'wp_ajax_oraculo_index_collection', array( $this, 'ajax_index_collection' ) );
		add_action( 'wp_ajax_oraculo_get_indexing_status', array( $this, 'ajax_get_indexing_status' ) );
		add_action( 'wp_ajax_oraculo_test_connection', array( $this, 'ajax_test_connection' ) );
		add_action( 'wp_ajax_oraculo_list_models', array( $this, 'ajax_list_models' ) );
		add_action( 'wp_ajax_oraculo_clear_vectors', array( $this, 'ajax_clear_vectors' ) );
		add_action( 'wp_ajax_oraculo_clear_all_vectors', array( $this, 'ajax_clear_all_vectors' ) );
		add_action( 'wp_ajax_oraculo_optimize_db', array( $this, 'ajax_optimize_db' ) );
		add_action( 'wp_ajax_oraculo_save_indexing_settings', array( $this, 'ajax_save_indexing_settings' ) );
		add_action( 'wp_ajax_oraculo_save_settings', array( $this, 'ajax_save_settings' ) );
		add_action( 'wp_ajax_oraculo_clear_cache', array( $this, 'ajax_clear_cache' ) );
		add_action( 'wp_ajax_oraculo_process_queue', array( $this, 'ajax_process_queue' ) );

		// Renovação do nonce wp_rest para o front (logado e anônimo). Não muta
		// estado: só devolve o nonce da sessão que o cookie identifica.
		add_action( 'wp_ajax_oraculo_rest_nonce', array( $this, 'ajax_rest_nonce' ) );
		add_action( 'wp_ajax_nopriv_oraculo_rest_nonce', array( $this, 'ajax_rest_nonce' ) );

		// Cron para indexação em background
		add_action( 'oraculo_process_indexing_batch', array( $this, 'process_indexing_batch' ) );

		// Cron de retenção: sem ele as tabelas de logs/conversas crescem sem limite (DoS de armazenamento)
		add_action( 'oraculo_cleanup_old_data', array( $this, 'cleanup_old_data' ) );

		// Preenche as assinaturas binárias dos vetores gravados antes da 2.7.0.
		add_action( Vector\VectorStore::CRON_BACKFILL, array( Vector\VectorStore::class, 'run_backfill_cron' ) );

		// MariaDB 11.7+: cria e preenche a coluna VECTOR (busca no banco).
		add_action( Vector\NativeVector::CRON_HOOK, array( Vector\NativeVector::class, 'run_cron' ) );

		// Tainacan hooks (a integração principal é feita via Tainacan Pages API)

		// Shortcodes
		add_shortcode( 'oraculo_search', array( $this, 'render_search_shortcode' ) );
		add_shortcode( 'oraculo_chat', array( $this, 'render_chat_shortcode' ) );

		// Filtros do plugin
		add_filter( 'plugin_action_links_' . ORACULO_TAINACAN_BASENAME, array( $this, 'add_action_links' ) );

		// WP-CLI
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'oraculo', CLI\Commands::class );
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
		if ( ! wp_next_scheduled( 'oraculo_cleanup_old_data' ) ) {
			wp_schedule_event( time(), 'daily', 'oraculo_cleanup_old_data' );
		}

		// Worker da fila de indexação automática. O schedule custom só existe
		// depois do filtro cron_schedules, por isso o registro explícito aqui;
		// AutoIndexer::ensure_scheduled() cobre upgrades, onde activate() não roda.
		// phpcs:ignore WordPress.WP.CronInterval.ChangeDetected -- Same callback registered by AutoIndexer::register(); re-added here because activation runs before the plugin's own filter is attached. Interval rationale documented at the callback.
		add_filter( 'cron_schedules', array( new Indexing\AutoIndexer(), 'register_cron_schedule' ) );
		if ( ! wp_next_scheduled( Indexing\AutoIndexer::CRON_HOOK ) ) {
			wp_schedule_event( time(), Indexing\AutoIndexer::CRON_SCHEDULE, Indexing\AutoIndexer::CRON_HOOK );
		}
		if ( ! wp_next_scheduled( Indexing\AutoIndexer::CRON_RECONCILE ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', Indexing\AutoIndexer::CRON_RECONCILE );
		}

		Vector\ChunkStore::maybe_create_table();

		// Retoma o preenchimento das assinaturas interrompido por uma desativação.
		Vector\VectorStore::schedule_backfill();

		// Flush rewrite rules
		flush_rewrite_rules();

		// Registrar versão
		update_option( 'oraculo_tainacan_version', ORACULO_TAINACAN_VERSION );
	}

	/**
	 * Desativação do plugin
	 */
	public function deactivate(): void {
		// Limpar cron jobs
		wp_clear_scheduled_hook( 'oraculo_process_indexing_batch' );
		wp_clear_scheduled_hook( 'oraculo_cleanup_old_data' );
		wp_clear_scheduled_hook( Indexing\AutoIndexer::CRON_HOOK );
		wp_clear_scheduled_hook( Indexing\AutoIndexer::CRON_HOOK, array( 'kick' ) );
		wp_clear_scheduled_hook( Indexing\AutoIndexer::CRON_RECONCILE );
		wp_clear_scheduled_hook( Vector\VectorStore::CRON_BACKFILL );
		wp_clear_scheduled_hook( Vector\NativeVector::CRON_HOOK );

		// Sem o Oráculo, o mu-plugin do modo rápido não tem o que enxugar. A
		// escolha fica guardada: reativar recoloca o arquivo (FastRest::maintain).
		Performance\FastRest::disable( true );

		// Sem isto, um lock deixado por um worker interrompido bloquearia a fila
		// por LOCK_TIMEOUT depois da reativação.
		delete_option( 'oraculo_index_queue_lock' );

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
		$sql_vectors   = "CREATE TABLE IF NOT EXISTS {$table_vectors} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            item_id BIGINT(20) UNSIGNED NOT NULL,
            collection_id BIGINT(20) UNSIGNED NOT NULL,
            collection_name VARCHAR(255) DEFAULT '',
            embedding_data LONGTEXT NOT NULL,
            embedding_bits VARBINARY(1024) DEFAULT NULL,
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
		$sql_conversations   = "CREATE TABLE IF NOT EXISTS {$table_conversations} (
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
		$sql_messages   = "CREATE TABLE IF NOT EXISTS {$table_messages} (
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
		$sql_logs   = "CREATE TABLE IF NOT EXISTS {$table_logs} (
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
		$sql_indexing   = "CREATE TABLE IF NOT EXISTS {$table_indexing} (
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
		$sql_prompts   = "CREATE TABLE IF NOT EXISTS {$table_prompts} (
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
		$sql_memory   = "CREATE TABLE IF NOT EXISTS {$table_memory} (
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
		$sql_facts   = "CREATE TABLE IF NOT EXISTS {$table_facts} (
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
		$sql_webhooks   = "CREATE TABLE IF NOT EXISTS {$table_webhooks} (
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

		dbDelta( $sql_vectors );
		dbDelta( $sql_conversations );
		dbDelta( $sql_messages );
		dbDelta( $sql_logs );
		dbDelta( $sql_indexing );
		dbDelta( $sql_prompts );
		dbDelta( $sql_memory );
		dbDelta( $sql_facts );
		dbDelta( $sql_webhooks );
	}

	/**
	 * Define opções padrão
	 */
	private function set_default_options(): void {
		$defaults = array(
			'ai_provider'                    => 'openai',
			'openai_api_key'                 => '',
			'openai_model'                   => 'gpt-4.1-mini',
			'openai_embedding_model'         => 'text-embedding-3-small',
			'gemini_api_key'                 => '',
			'gemini_model'                   => 'gemini-2.5-flash',
			'claude_api_key'                 => '',
			'claude_model'                   => 'claude-sonnet-5',
			'groq_api_key'                   => '',
			'groq_model'                     => 'llama-3.3-70b-versatile',
			'deepseek_api_key'               => '',
			'deepseek_model'                 => 'deepseek-chat',
			'ollama_url'                     => 'http://localhost:11434',
			'ollama_model'                   => 'llama3.2',
			'ollama_embedding_model'         => 'nomic-embed-text',
			'generic_openai_api_base_url'    => '',
			'generic_openai_api_key'         => '',
			'generic_openai_model'           => '',
			'generic_openai_embedding_model' => '',
			'rag_strategy'                   => 'local',
			'max_tokens'                     => 2000,
			'temperature'                    => 0.7,
			'similarity_threshold'           => 0.3,
			'max_results'                    => 10,
			'batch_size'                     => 25,
			'request_timeout'                => 120,
			'cache_duration'                 => 3600,
			'rate_limit_global'              => 300,
			'rate_limit_search'              => 10,
			'rate_limit_chat'                => 20,
			'rate_limit_allowlist'           => '',
			'enable_chat'                    => true,
			'enable_search'                  => true,
			'enable_analytics'               => true,
			'enable_feedback'                => true,
			'debug_mode'                     => false,
			'default_collections'            => array(),
			'system_prompt'                  => $this->get_default_system_prompt(),
			'search_prompt'                  => $this->get_default_search_prompt(),
			'chat_prompt'                    => $this->get_default_chat_prompt(),
			'welcome_message'                => __( 'Oi! Eu sou a BIA, a bibliotecária de IA deste acervo. Posso ajudar a encontrar obras, autores e assuntos — é só perguntar. 📚', 'oraculo-tainacan' ),
			'suggested_questions'            => array(
				__( 'Quais são os itens mais recentes do acervo?', 'oraculo-tainacan' ),
				__( 'Mostre documentos sobre [tema]', 'oraculo-tainacan' ),
				__( 'Quais coleções estão disponíveis?', 'oraculo-tainacan' ),
			),
			'index_fields'                   => array( 'title', 'description' ),
			// Busca visual via AI API do IBRAM (CLIP + pgvector). URL vazia = desligado.
			'search_backend'                 => 'local',
			'clip_api_url'                   => '',
			'clip_api_model'                 => 'ViT-L-14',
			'clip_api_timeout'               => 60,
			'appearance'                     => array(
				'primary_color'   => '#1f2f56',
				'accent_color'    => '#b5e0e3',
				'chat_position'   => 'bottom-right',
				'show_sources'    => true,
				'show_similarity' => false,
			),
			'theme_integration'              => Frontend\ThemeIntegration::get_default_settings(),
		);

		$existing = get_option( 'oraculo_tainacan_options', array() );
		$merged   = wp_parse_args( $existing, $defaults );
		update_option( 'oraculo_tainacan_options', $merged );
	}

	/**
	 * Prompt do sistema padrão
	 */
	private function get_default_system_prompt(): string {
		return __(
			'Você é um assistente especializado em ajudar usuários a encontrar informações no acervo digital.
Suas respostas devem ser:
- Precisas e baseadas apenas nas informações fornecidas do acervo
- Claras e em português brasileiro
- Úteis, indicando sempre os itens relevantes encontrados
- Honestas quando não houver informação suficiente para responder

Quando citar itens do acervo, sempre mencione o título e forneça o link quando disponível.
Se a pergunta não puder ser respondida com as informações disponíveis, informe educadamente e sugira reformular a pergunta.',
			'oraculo-tainacan'
		);
	}

	/**
	 * Prompt de busca padrão
	 */
	private function get_default_search_prompt(): string {
		return __(
			'Com base nos itens do acervo listados abaixo, responda à pergunta do usuário de forma concisa e informativa.

ITENS DO ACERVO:
{context}

PERGUNTA: {query}

Forneça uma resposta clara, mencionando os itens mais relevantes encontrados.',
			'oraculo-tainacan'
		);
	}

	/**
	 * Prompt de chat padrão
	 */
	private function get_default_chat_prompt(): string {
		return __(
			'Você está em uma conversa com um usuário que busca informações no acervo digital.

HISTÓRICO DA CONVERSA:
{history}

CONTEXTO DO ACERVO:
{context}

MENSAGEM DO USUÁRIO: {message}

Responda de forma natural e conversacional, sempre baseando-se nas informações do acervo quando relevante.',
			'oraculo-tainacan'
		);
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
		// Verificar se a classe base do Tainacan existe (classe carregada via autoloader do plugin)
		if ( class_exists( '\Tainacan\Pages' ) && trait_exists( '\Tainacan\Traits\Singleton_Instance' ) ) {
			Admin\OraculoPage::get_instance();
		}
	}

	/**
	 * Inicialização principal
	 */
	public function init(): void {
		// Upgrade de esquema: activate() não roda quando os arquivos são
		// substituídos no deploy, e o CREATE TABLE IF NOT EXISTS não altera
		// tabela existente.
		Vector\VectorStore::maybe_upgrade_schema();
		Vector\ChunkStore::maybe_create_table();
		Vector\NativeVector::maybe_schedule();

		// Inicializar serviços (admin page é inicializada via Tainacan Pages API)
		$this->services['api']       = new API\RestController();
		$this->services['search']    = new Search\SearchEngine();
		$this->services['chat']      = new Chat\ChatEngine();
		$this->services['indexing']  = new Indexing\IndexingManager();
		$this->services['analytics'] = new Analytics\AnalyticsManager();

		// Indexação automática: mantém o índice vetorial em dia conforme o
		// acervo muda. Só registra hooks aqui — nada de chamada de IA no save.
		$this->services['auto_indexer'] = new Indexing\AutoIndexer();
		$this->services['auto_indexer']->register();

		// Aba "Busca com IA" nas listagens de itens do Tainacan (injeção client-side).
		$this->services['theme_integration'] = new Frontend\ThemeIntegration();
	}

	/**
	 * Registra configurações
	 */
	public function register_settings(): void {
		register_setting(
			'oraculo_tainacan',
			'oraculo_tainacan_options',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_options' ),
			)
		);
	}

	/**
	 * Sanitiza opções
	 *
	 * Delega para SettingsSanitizer, o mesmo ponto usado pelo endpoint REST
	 * /settings: allowlist por chave, limites numéricos e preservação das API
	 * keys mascaradas. Antes havia duas implementações divergentes (esta e a
	 * do REST), o que fazia a mesma opção ser aceita ou descartada conforme o
	 * caminho de gravação.
	 *
	 * @param array $options Opções brutas vindas do formulário.
	 * @return array
	 */
	public function sanitize_options( array $options ): array {
		return Admin\SettingsSanitizer::sanitize( $options );
	}

	/**
	 * Enfileira assets do frontend
	 */
	public function enqueue_frontend_assets(): void {
		$options = get_option( 'oraculo_tainacan_options', array() );

		// Cliente REST compartilhado (renova o nonce wp_rest vencido).
		$rest_handle = register_rest_client_script();

		// Registrar tudo; enfileirar só onde é usado (chat flutuante global
		// quando habilitado; shortcodes enfileiram no render).
		wp_register_style(
			'oraculo-frontend',
			ORACULO_TAINACAN_URL . 'assets/css/frontend.css',
			array(),
			ORACULO_TAINACAN_VERSION
		);
		wp_register_style(
			'oraculo-chat',
			ORACULO_TAINACAN_URL . 'assets/css/chat-widget.css',
			array(),
			ORACULO_TAINACAN_VERSION
		);
		wp_register_script(
			'oraculo-frontend',
			ORACULO_TAINACAN_URL . 'assets/js/frontend.js',
			array( 'jquery', $rest_handle ),
			ORACULO_TAINACAN_VERSION,
			true
		);
		wp_register_script(
			'oraculo-chat',
			ORACULO_TAINACAN_URL . 'assets/js/chat-widget.js',
			array( 'jquery', 'oraculo-frontend', $rest_handle ),
			ORACULO_TAINACAN_VERSION,
			true
		);

		// Página atual usa algum shortcode do plugin?
		$has_shortcode = false;
		if ( is_singular() ) {
			$post          = get_post();
			$has_shortcode = $post && (
				has_shortcode( $post->post_content, 'oraculo_search' ) ||
				has_shortcode( $post->post_content, 'oraculo_chat' )
			);
		}

		if ( ! empty( $options['enable_chat'] ) || $has_shortcode ) {
			wp_enqueue_style( 'oraculo-frontend' );
			wp_enqueue_script( 'oraculo-frontend' );
		}

		// Chat flutuante: global quando habilitado
		if ( ! empty( $options['enable_chat'] ) ) {
			wp_enqueue_style( 'oraculo-chat' );
			wp_enqueue_script( 'oraculo-chat' );
		}

		// JS do widget de busca (registrado aqui; enfileirado apenas no render do shortcode)
		wp_register_script(
			'oraculo-search-page',
			ORACULO_TAINACAN_URL . 'assets/js/search-page.js',
			array( $rest_handle ),
			ORACULO_TAINACAN_VERSION,
			true
		);
		wp_localize_script(
			'oraculo-search-page',
			'OraculoSearchPage',
			array(
				'searchUrl'   => rest_url( 'oraculo/v1/search' ),
				'feedbackUrl' => rest_url( 'oraculo/v1/feedback' ),
				'i18n'        => array(
					'assistant'            => __( 'Assistente Oráculo', 'oraculo-tainacan' ),
					'respondedIn'          => __( 'Respondido em', 'oraculo-tainacan' ),
					'sources'              => __( 'Fontes consultadas no acervo', 'oraculo-tainacan' ),
					'helpful'              => __( 'Esta resposta foi útil?', 'oraculo-tainacan' ),
					'yes'                  => __( 'Sim', 'oraculo-tainacan' ),
					'no'                   => __( 'Não', 'oraculo-tainacan' ),
					'thanks'               => __( 'Obrigado pelo feedback!', 'oraculo-tainacan' ),
					'unknownError'         => __( 'Erro desconhecido', 'oraculo-tainacan' ),
					'cantProcess'          => __( 'Não foi possível processar sua busca', 'oraculo-tainacan' ),
					'noResultsTitle'       => __( 'Nenhum resultado encontrado', 'oraculo-tainacan' ),
					'noResultsText'        => __( 'Não encontramos informações relacionadas à sua pergunta. Tente reformular usando outras palavras ou seja mais específico.', 'oraculo-tainacan' ),
					'connectionErrorTitle' => __( 'Erro de conexão', 'oraculo-tainacan' ),
					'connectionErrorBody'  => __( 'Ocorreu um erro ao processar sua busca. Por favor, verifique sua conexão e tente novamente.', 'oraculo-tainacan' ),
				),
			)
		);

		// JS do chat embutido (registrado aqui; enfileirado apenas no render do shortcode)
		wp_register_script(
			'oraculo-chat-embedded',
			ORACULO_TAINACAN_URL . 'assets/js/chat-embedded.js',
			array( $rest_handle ),
			ORACULO_TAINACAN_VERSION,
			true
		);
		wp_localize_script(
			'oraculo-chat-embedded',
			'OraculoChatEmbedded',
			array(
				'chatUrl' => rest_url( 'oraculo/v1/chat' ),
				'i18n'    => array(
					'error'           => __( 'Ocorreu um erro. Tente novamente.', 'oraculo-tainacan' ),
					'connectionError' => __( 'Erro de conexão. Tente novamente.', 'oraculo-tainacan' ),
					'sources'         => __( 'Fontes:', 'oraculo-tainacan' ),
				),
			)
		);

		// CSS customizado baseado nas opções
		$appearance = $options['appearance'] ?? array();
		$custom_css = ':root {
            --oraculo-primary: ' . esc_attr( $appearance['primary_color'] ?? '#1f2f56' ) . ';
            --oraculo-accent: ' . esc_attr( $appearance['accent_color'] ?? '#b5e0e3' ) . ';
        }';
		wp_add_inline_style( 'oraculo-frontend', $custom_css );

		$chat_scope = ! empty( $options['enable_chat'] ) && isset( $this->services['theme_integration'] )
			? $this->services['theme_integration']->get_search_scope()
			: array(
				'collections' => array(),
				'scope_label' => '',
			);

		// Localizar script
		wp_localize_script(
			'oraculo-frontend',
			'OraculoFrontend',
			array(
				'ajaxUrl'            => admin_url( 'admin-ajax.php' ),
				'restUrl'            => rest_url( 'oraculo/v1/' ),
				'nonce'              => wp_create_nonce( 'oraculo_frontend' ),
				'enableChat'         => ! empty( $options['enable_chat'] ),
				// Mesmo escopo da aba "Busca com IA": a BIA consulta a coleção da página.
				'collections'        => $chat_scope['collections'],
				'collectionName'     => $chat_scope['scope_label'],
				'enableSearch'       => ! empty( $options['enable_search'] ),
				'chatPosition'       => $appearance['chat_position'] ?? 'bottom-right',
				'assistantName'      => __( 'BIA', 'oraculo-tainacan' ),
				'assistantRole'      => __( 'Bibliotecária de IA', 'oraculo-tainacan' ),
				'welcomeMessage'     => $options['welcome_message'] ?? '',
				'suggestedQuestions' => $options['suggested_questions'] ?? array(),
				'strings'            => array(
					'placeholder'     => __( 'Pergunte à BIA sobre o acervo…', 'oraculo-tainacan' ),
					'send'            => __( 'Enviar', 'oraculo-tainacan' ),
					'searching'       => __( 'Buscando...', 'oraculo-tainacan' ),
					'thinking'        => __( 'Pensando...', 'oraculo-tainacan' ),
					'typing'          => __( 'BIA está pesquisando no acervo…', 'oraculo-tainacan' ),
					'error'           => __( 'Ocorreu um erro. Tente novamente.', 'oraculo-tainacan' ),
					'noResults'       => __( 'Nenhum resultado encontrado.', 'oraculo-tainacan' ),
					'helpful'         => __( 'Esta resposta foi útil?', 'oraculo-tainacan' ),
					'yes'             => __( 'Sim', 'oraculo-tainacan' ),
					'no'              => __( 'Não', 'oraculo-tainacan' ),
					'sources'         => __( 'Fontes do acervo', 'oraculo-tainacan' ),
					'online'          => __( 'online', 'oraculo-tainacan' ),
					'newConversation' => __( 'Nova conversa', 'oraculo-tainacan' ),
					'openChat'        => __( 'Conversar com a BIA', 'oraculo-tainacan' ),
					'closeChat'       => __( 'Fechar conversa', 'oraculo-tainacan' ),
					'defaultWelcome'  => __( 'Oi! Eu sou a BIA, a bibliotecária de IA deste acervo. Posso ajudar a encontrar obras, autores e assuntos — é só perguntar. 📚', 'oraculo-tainacan' ),
				),
			)
		);
	}

	/**
	 * Registra rotas REST
	 */
	public function register_rest_routes(): void {
		if ( isset( $this->services['api'] ) ) {
			$this->services['api']->register_routes();
		}
	}

	/**
	 * Handler AJAX para iniciar indexação (síncrona)
	 */
	public function ajax_index_collection(): void {
		try {
			check_ajax_referer( 'oraculo_admin', 'nonce' );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( array( 'message' => __( 'Permissão negada.', 'oraculo-tainacan' ) ) );
				return;
			}

			// Limites da indexação síncrona, só depois do nonce e da permissão.
			// Memória pela API do WordPress (WP_MAX_MEMORY_LIMIT, 256M por padrão).
			wp_raise_memory_limit( 'admin' );
			if ( function_exists( 'set_time_limit' ) ) {
				// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- Synchronous admin indexing batch; WordPress has no API for the execution time limit, and the call is skipped where the host disables it.
				@set_time_limit( 300 );
			}

			$collection_id = absint( $_POST['collection_id'] ?? 0 );
			$force         = ! empty( $_POST['force'] );

			if ( empty( $collection_id ) ) {
				wp_send_json_error( array( 'message' => __( 'ID da coleção inválido.', 'oraculo-tainacan' ) ) );
				return;
			}

			if ( ! isset( $this->services['indexing'] ) ) {
				wp_send_json_error( array( 'message' => __( 'Serviço de indexação não inicializado.', 'oraculo-tainacan' ) ) );
				return;
			}

			// Executar indexação síncrona
			$result = $this->services['indexing']->start_indexing( $collection_id, $force );

			if ( is_wp_error( $result ) ) {
				wp_send_json_error( array( 'message' => $result->get_error_message() ) );
				return;
			}

			wp_send_json_success( $result );
		} catch ( \Throwable $e ) {
			wp_send_json_error(
				array(
					'message' => $e->getMessage(),
					'file'    => $e->getFile(),
					'line'    => $e->getLine(),
				)
			);
		}
	}

	/**
	 * Handler AJAX para status de indexação
	 */
	public function ajax_get_indexing_status(): void {
		check_ajax_referer( 'oraculo_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permissão negada.', 'oraculo-tainacan' ) ) );
		}

		$collection_id = absint( $_POST['collection_id'] ?? 0 );

		try {
			if ( ! isset( $this->services['indexing'] ) ) {
				wp_send_json_error( array( 'message' => __( 'Serviço de indexação não inicializado.', 'oraculo-tainacan' ) ) );
				return;
			}
			$status = $this->services['indexing']->get_status( $collection_id );
			wp_send_json_success( $status );
		} catch ( \Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * Handler AJAX para testar conexão
	 */
	public function ajax_test_connection(): void {
		check_ajax_referer( 'oraculo_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permissão negada.', 'oraculo-tainacan' ) ) );
		}

		$provider = sanitize_text_field( wp_unslash( $_POST['provider'] ?? 'openai' ) );

		try {
			$factory     = new AI\AIProviderFactory();
			$ai_provider = $factory->create( $provider );
			$result      = $ai_provider->test_connection();

			// O envelope precisa refletir o resultado do teste: mandar
			// wp_send_json_success com ['success' => false] fazia o JS exibir
			// "✅" na frente de uma mensagem de falha de autenticação.
			if ( ! empty( $result['success'] ) ) {
				wp_send_json_success( $result );
			} else {
				wp_send_json_error( $result );
			}
		} catch ( \Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * Handler AJAX: consulta o endpoint de modelos do provedor com a chave
	 * digitada (ou a já salva) e devolve o que a conta realmente libera
	 *
	 * Funciona antes de salvar: o operador digita uma chave nova, clica em
	 * "Buscar modelos" e vê o catálogo daquela conta sem precisar submeter o
	 * formulário primeiro. Campo vazio ou com a máscara "••••••••" usa a
	 * chave já configurada para o provedor.
	 */
	public function ajax_list_models(): void {
		check_ajax_referer( 'oraculo_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permissão negada.', 'oraculo-tainacan' ) ) );
		}

		$provider_id = sanitize_text_field( wp_unslash( $_POST['provider'] ?? '' ) );
		$submitted   = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '';
		$ollama_url  = isset( $_POST['ollama_url'] ) ? esc_url_raw( wp_unslash( $_POST['ollama_url'] ) ) : '';

		if ( '' === $provider_id ) {
			wp_send_json_error( array( 'message' => __( 'Provedor inválido.', 'oraculo-tainacan' ) ) );
		}

		$current = self::get_options();
		$is_mask = '' === $submitted || (bool) preg_match( '/^[*\x{2022}\x{25CF}]+$/u', $submitted );
		$config  = array();

		if ( in_array( $provider_id, array( 'ollama', 'clip' ), true ) ) {
			// Provedores por URL (sem chave): o campo relevante é o endereço do
			// servidor. O JS envia a URL digitada no painel via ollama_url.
			$stored_url         = 'clip' === $provider_id
				? (string) ( $current['clip_api_url'] ?? '' )
				: (string) ( $current['ollama_url'] ?? '' );
			$config['base_url'] = '' !== $ollama_url ? $ollama_url : $stored_url;
		} else {
			// Placeholder/vazio -> usa o valor já salvo (get_api_key() descriptografa
			// sozinho se estiver no formato 'enc:...'); valor digitado vai como
			// texto puro mesmo, pois ainda não foi persistido nem criptografado.
			$config['api_key'] = $is_mask ? (string) ( $current[ $provider_id . '_api_key' ] ?? '' ) : $submitted;

			// Compatível com OpenAI: URL e chave (opcional) juntas; a URL
			// digitada no painel também chega via ollama_url.
			if ( 'generic_openai' === $provider_id ) {
				$config['api_base_url'] = '' !== $ollama_url ? $ollama_url : (string) ( $current['generic_openai_api_base_url'] ?? '' );
			}
		}

		try {
			$factory     = new AI\AIProviderFactory();
			$ai_provider = $factory->create( $provider_id, $config );
			$models      = $ai_provider->list_remote_models();

			if ( is_wp_error( $models ) ) {
				wp_send_json_error( array( 'message' => $models->get_error_message() ) );
			}

			wp_send_json_success(
				array(
					'models' => $models,
					'count'  => count( $models ),
				)
			);
		} catch ( \Throwable $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * Handler AJAX para limpar vetores de uma coleção
	 */
	public function ajax_clear_vectors(): void {
		check_ajax_referer( 'oraculo_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permissão negada.', 'oraculo-tainacan' ) ) );
		}

		$collection_id = absint( $_POST['collection_id'] ?? 0 );

		if ( empty( $collection_id ) ) {
			wp_send_json_error( array( 'message' => __( 'ID da coleção inválido.', 'oraculo-tainacan' ) ) );
		}

		try {
			$vector_store = new Vector\VectorStore();
			$deleted      = $vector_store->delete_collection( $collection_id );
			wp_send_json_success(
				array(
					/* translators: %d: number of vectors deleted */
					'message' => sprintf( __( '%d vetores removidos.', 'oraculo-tainacan' ), $deleted ),
					'deleted' => $deleted,
				)
			);
		} catch ( \Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * Handler AJAX para limpar todos os vetores
	 */
	public function ajax_clear_all_vectors(): void {
		check_ajax_referer( 'oraculo_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permissão negada.', 'oraculo-tainacan' ) ) );
		}

		try {
			( new Vector\VectorStore() )->truncate_all();
			wp_send_json_success(
				array(
					'message' => __( 'Todos os vetores foram removidos.', 'oraculo-tainacan' ),
				)
			);
		} catch ( \Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * Handler AJAX para otimizar banco de dados
	 */
	public function ajax_optimize_db(): void {
		check_ajax_referer( 'oraculo_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permissão negada.', 'oraculo-tainacan' ) ) );
		}

		try {
			global $wpdb;
			$tables = array(
				$wpdb->prefix . 'oraculo_vectors',
				$wpdb->prefix . 'oraculo_search_logs',
				$wpdb->prefix . 'oraculo_indexing_jobs',
			);

			foreach ( $tables as $table ) {
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- $table is plugin-owned (built from $wpdb->prefix + literal); OPTIMIZE TABLE has no WP API equivalent; write operation, caching N/A.
				$wpdb->query( "OPTIMIZE TABLE {$table}" );
			}

			wp_send_json_success(
				array(
					'message' => __( 'Banco de dados otimizado com sucesso.', 'oraculo-tainacan' ),
				)
			);
		} catch ( \Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * Handler AJAX para salvar configurações de indexação
	 */
	public function ajax_save_indexing_settings(): void {
		check_ajax_referer( 'oraculo_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permissão negada.', 'oraculo-tainacan' ) ) );
		}

		try {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- parse_str target; each key in $settings is sanitized individually below (absint, sanitize_text_field, !empty).
			parse_str( wp_unslash( $_POST['settings'] ?? '' ), $settings );
			// Allowlist of expected keys; each leaf sanitized individually below.
			$allowed_settings_keys = array( 'batch_size', 'embedding_provider', 'auto_index', 'index_title', 'index_description', 'index_metadata', 'index_document' );
			$settings              = array_intersect_key( $settings, array_flip( $allowed_settings_keys ) );

			$batch_size = max( 1, min( 100, absint( $settings['batch_size'] ?? 10 ) ) );

			// Checkboxes → index_fields no array principal de opções, que é o
			// que IndexingManager efetivamente lê. As opções soltas
			// oraculo_index_* eram gravadas aqui e não eram lidas por nada.
			$index_fields = array();
			foreach ( array( 'title', 'description', 'metadata', 'document' ) as $field ) {
				if ( ! empty( $settings[ 'index_' . $field ] ) ) {
					$index_fields[] = $field;
				}
			}

			self::update_option( 'batch_size', $batch_size );
			self::update_option( 'index_fields', $index_fields );

			// Opções standalone consumidas por AutoIndexer e AIProviderFactory.
			update_option( 'oraculo_batch_size', $batch_size );
			update_option( 'oraculo_embedding_provider', sanitize_text_field( $settings['embedding_provider'] ?? 'openai' ) );
			update_option( 'oraculo_auto_index', ! empty( $settings['auto_index'] ) );

			wp_send_json_success(
				array(
					'message' => __( 'Configurações salvas com sucesso.', 'oraculo-tainacan' ),
				)
			);
		} catch ( \Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * Handler AJAX para salvar configurações gerais
	 */
	public function ajax_save_settings(): void {
		check_ajax_referer( 'oraculo_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permissão negada.', 'oraculo-tainacan' ) ) );
			return;
		}

		try {
			// Coletar todas as opções do formulário
			$options = array();

			// Provedor de IA
			if ( isset( $_POST['oraculo_tainacan_options'] ) ) {
                // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize_options() below walks the array and sanitizes every leaf.
				$options = wp_unslash( $_POST['oraculo_tainacan_options'] );
			}

			// Sanitizar opções
			$sanitized = $this->sanitize_options( $options );

			// Salvar
			update_option( 'oraculo_tainacan_options', $sanitized );

			// Limpar cache de provedores para forçar recriação com novas configurações
			\Oraculo_Tainacan\AI\AIProviderFactory::clear_cache();

			wp_send_json_success(
				array(
					'message' => __( 'Configurações salvas com sucesso.', 'oraculo-tainacan' ),
				)
			);
		} catch ( \Throwable $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * Handler AJAX para limpar cache
	 */
	public function ajax_clear_cache(): void {
		check_ajax_referer( 'oraculo_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permissão negada.', 'oraculo-tainacan' ) ) );
			return;
		}

		try {
			// Limpar transients do plugin
			$this->clear_all_transients();

			wp_send_json_success(
				array(
					'message' => __( 'Cache limpo com sucesso.', 'oraculo-tainacan' ),
				)
			);
		} catch ( \Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * Devolve um nonce `wp_rest` válido para a sessão atual.
	 *
	 * O nonce embutido na página vence quando a sessão muda depois do
	 * carregamento (login/logout em outra aba, sessão expirada). O cliente
	 * REST do front (assets/js/rest-client.js) chama esta ação ao receber
	 * rest_cookie_invalid_nonce/oraculo_invalid_nonce e repete o pedido.
	 * Sem verificação de nonce aqui: a ação não muta estado, e o mesmo nonce
	 * já é entregue a qualquer visitante no HTML das páginas públicas.
	 */
	public function ajax_rest_nonce(): void {
		nocache_headers();
		wp_send_json_success( array( 'nonce' => wp_create_nonce( 'wp_rest' ) ) );
	}

	/**
	 * Handler AJAX do botão "Processar fila agora" (tela de indexação)
	 */
	public function ajax_process_queue(): void {
		check_ajax_referer( 'oraculo_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permissão negada.', 'oraculo-tainacan' ) ) );
		}

		$auto_indexer = $this->services['auto_indexer'] ?? new Indexing\AutoIndexer();
		$summary      = $auto_indexer->process_queue();

		if ( ! empty( $summary['locked'] ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'A fila já está sendo processada em segundo plano. Tente novamente em instantes.', 'oraculo-tainacan' ),
				)
			);
		}

		wp_send_json_success( $summary );
	}

	/**
	 * Processa batch de indexação (cron legado)
	 *
	 * O hook oraculo_process_indexing_batch nunca chegou a ser agendado e
	 * chamava IndexingManager::process_next_batch(), método inexistente — se
	 * algum agendamento residual disparasse, era fatal. Agora delega ao worker
	 * da fila, que é o mecanismo real de indexação em background.
	 */
	public function process_indexing_batch(): void {
		if ( isset( $this->services['auto_indexer'] ) ) {
			$this->services['auto_indexer']->process_queue();
		}
	}

	/**
	 * Cron diário de retenção de dados (agendado na ativação).
	 *
	 * Remove logs de busca, conversas/mensagens antigas e memória expirada.
	 * Sem isso, tráfego de bots infla as tabelas indefinidamente.
	 */
	public function cleanup_old_data(): void {
		global $wpdb;

		/**
		 * Filtra a retenção (em dias) dos logs de busca.
		 *
		 * @param int $days Padrão 90.
		 */
		$logs_days = max( 1, (int) apply_filters( 'oraculo_tainacan_retention_logs_days', 90 ) );

		/**
		 * Filtra a retenção (em dias) de conversas inativas e suas mensagens.
		 *
		 * @param int $days Padrão 30.
		 */
		$conversations_days = max( 1, (int) apply_filters( 'oraculo_tainacan_retention_conversations_days', 30 ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin's own tables ($wpdb->prefix + literal); retention DELETEs on cron path; caching N/A; intervals bound via %d placeholders.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}oraculo_search_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
				$logs_days
			)
		);

		$wpdb->query(
			$wpdb->prepare(
				"DELETE m FROM {$wpdb->prefix}oraculo_messages m
				 INNER JOIN {$wpdb->prefix}oraculo_conversations c ON m.conversation_id = c.id
				 WHERE c.updated_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
				$conversations_days
			)
		);

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}oraculo_conversations WHERE updated_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
				$conversations_days
			)
		);

		$wpdb->query(
			"DELETE FROM {$wpdb->prefix}oraculo_memory WHERE expires_at IS NOT NULL AND expires_at < NOW()"
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
	}


	/**
	 * Renderiza shortcode de busca
	 */
	public function render_search_shortcode( array $atts ): string {
		$atts = shortcode_atts(
			array(
				'placeholder'      => __( 'O que você está procurando?', 'oraculo-tainacan' ),
				'button_text'      => __( 'Buscar', 'oraculo-tainacan' ),
				'collections'      => '',
				'show_filters'     => 'true',
				'results_per_page' => 10,
			),
			$atts,
			'oraculo_search'
		);

		// Assets só nas páginas que usam o shortcode (fallback p/ widgets: enqueue no render).
		wp_enqueue_style( 'oraculo-frontend' );
		wp_enqueue_script( 'oraculo-frontend' );
		wp_enqueue_script( 'oraculo-search-page' );

		ob_start();
		oraculo_tainacan_render_template( 'search-widget.php', array( 'atts' => $atts ) );
		return ob_get_clean();
	}

	/**
	 * Renderiza shortcode de chat
	 */
	public function render_chat_shortcode( array $atts ): string {
		$atts = shortcode_atts(
			array(
				'collections'      => '',
				'title'            => __( 'Assistente do Acervo', 'oraculo-tainacan' ),
				'height'           => '500px',
				'show_suggestions' => 'true',
			),
			$atts,
			'oraculo_chat'
		);

		// Assets só nas páginas que usam o shortcode (fallback p/ widgets: enqueue no render).
		wp_enqueue_style( 'oraculo-frontend' );
		wp_enqueue_script( 'oraculo-frontend' );
		wp_enqueue_script( 'oraculo-chat-embedded' );

		ob_start();
		oraculo_tainacan_render_template( 'chat-widget.php', array( 'atts' => $atts ) );
		return ob_get_clean();
	}

	/**
	 * Adiciona links de ação
	 */
	public function add_action_links( array $links ): array {
		$settings_link = '<a href="' . admin_url( 'admin.php?page=oraculo-settings' ) . '">' .
						__( 'Configurações', 'oraculo-tainacan' ) . '</a>';
		array_unshift( $links, $settings_link );
		return $links;
	}

	/**
	 * Obtém serviço
	 */
	public function get_service( string $name ) {
		return $this->services[ $name ] ?? null;
	}

	/**
	 * Obtém opções do plugin
	 */
	public static function get_options(): array {
		return get_option( 'oraculo_tainacan_options', array() );
	}

	/**
	 * Atualiza opção específica
	 */
	public static function update_option( string $key, $value ): bool {
		$options         = self::get_options();
		$options[ $key ] = $value;
		return update_option( 'oraculo_tainacan_options', $options );
	}
}

// Função helper global
function oraculo_tainacan(): Oraculo_Tainacan {
	return Oraculo_Tainacan::get_instance();
}

// Inicializar plugin
oraculo_tainacan();
