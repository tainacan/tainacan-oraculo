<?php
/**
 * Página do Oráculo integrada ao Tainacan 1.0+
 *
 * Segue a nova arquitetura de páginas do Tainacan
 * usando a classe abstrata \Tainacan\Pages
 *
 * @package Oraculo_Tainacan
 */

namespace Tainacan;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Verificar se classe base existe
if (!class_exists('\Tainacan\Pages')) {
    return;
}

/**
 * Página principal do Oráculo no menu do Tainacan
 */
class Oraculo_Page extends \Tainacan\Pages {

    /**
     * Instância singleton
     * @var Oraculo_Page|null
     */
    private static $instance = null;

    /**
     * Obtém instância única
     * @return Oraculo_Page
     */
    public static function get_instance(): Oraculo_Page {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Construtor - chama o construtor pai
     */
    protected function __construct() {
        parent::__construct();
    }

    /**
     * Slug da página
     * @return string
     */
    protected function get_page_slug(): string {
        return 'oraculo_tainacan_page';
    }

    /**
     * Adiciona item ao menu do Tainacan
     */
    public function add_admin_menu() {
        $page_suffix = add_submenu_page(
            $this->tainacan_root_menu_slug,
            __('Oráculo IA', 'oraculo_tainacan'),
            '<span class="icon">' . $this->get_oraculo_icon() . '</span>' .
            '<span class="menu-text">' . __('Oráculo IA', 'oraculo_tainacan') . '</span>',
            'read',
            $this->get_page_slug(),
            array($this, 'render_page'),
            3
        );

        if ($page_suffix) {
            add_action('load-' . $page_suffix, array($this, 'load_page'));
        }
    }

    /**
     * Renderiza o conteúdo da página
     */
    public function render_page_content() {
        // Determinar qual sub-página exibir baseado no parâmetro tab
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- tab is a read-only navigation parameter with no side effects; value is whitelisted below.
        $tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'dashboard';
        $allowed_tabs = [ 'dashboard', 'indexing', 'settings', 'analytics', 'debug' ];
        if ( ! in_array( $tab, $allowed_tabs, true ) ) {
            $tab = 'dashboard';
        }

        // Wrapper seguindo padrão Tainacan (como o Extrator IA)
        echo '<div class="wrap tainacan-page-container-content oraculo-admin">';

        // Renderizar navegação por abas
        $this->render_tabs_navigation($tab);

        switch ($tab) {
            case 'indexing':
                $this->render_indexing_content();
                break;
            case 'settings':
                $this->render_settings_content();
                break;
            case 'analytics':
                $this->render_analytics_content();
                break;
            case 'debug':
                $options = get_option('oraculo_tainacan_options', []);
                if (!empty($options['debug_mode'])) {
                    $this->render_debug_content();
                } else {
                    $this->render_dashboard_content();
                }
                break;
            default:
                $this->render_dashboard_content();
                break;
        }

        echo '</div>'; // Fecha .oraculo-admin
    }

    /**
     * Carrega assets da página
     */
    public function load_page() {
        parent::load_page();
        add_action('admin_enqueue_scripts', array($this, 'enqueue_page_assets'));
    }

    /**
     * Enqueue CSS específico
     */
    public function admin_enqueue_css() {
        // CSS Admin
        if (defined('ORACULO_TAINACAN_URL') && defined('ORACULO_TAINACAN_VERSION')) {
            wp_enqueue_style(
                'oraculo-admin-page',
                ORACULO_TAINACAN_URL . 'assets/css/admin-page.css',
                [],
                ORACULO_TAINACAN_VERSION
            );
        }
    }

    /**
     * Enqueue JS específico
     */
    public function admin_enqueue_js() {
        // Chart.js para gráficos (bundled locally to avoid external CDN)
        wp_enqueue_script(
            'chart-js',
            plugins_url( 'assets/vendor/chart.umd.min.js', ORACULO_TAINACAN_FILE ),
            [],
            '4.4.1',
            true
        );

        if (defined('ORACULO_TAINACAN_URL') && defined('ORACULO_TAINACAN_VERSION')) {
            // JS Admin
            wp_enqueue_script(
                'oraculo-admin-page',
                ORACULO_TAINACAN_URL . 'assets/js/admin-page.js',
                ['jquery', 'chart-js', 'wp-util'],
                ORACULO_TAINACAN_VERSION,
                true
            );
        }
    }

    /**
     * Enfileira assets específicos
     */
    public function enqueue_page_assets() {
        if (!defined('ORACULO_TAINACAN_URL') || !defined('ORACULO_TAINACAN_VERSION')) {
            return;
        }

        // Dados para JS
        if (class_exists('\Oraculo_Tainacan\AI\AIProviderFactory')) {
            $factory = new \Oraculo_Tainacan\AI\AIProviderFactory();

            wp_localize_script('oraculo-admin-page', 'OraculoAdminPage', [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'restUrl' => rest_url('oraculo/v1/'),
                'nonce' => wp_create_nonce('oraculo_admin'),
                'restNonce' => wp_create_nonce('wp_rest'),
                'providers' => $factory->get_available_providers(),
                'currentProvider' => $factory->get_current_provider(),
                'collections' => function_exists('\Oraculo_Tainacan\get_tainacan_collections')
                    ? \Oraculo_Tainacan\get_tainacan_collections()
                    : [],
                'options' => $this->get_safe_options(),
                'strings' => $this->get_js_strings(),
            ]);
        }
    }

    /**
     * Obtém ícone SVG para o menu
     * @return string
     */
    private function get_oraculo_icon(): string {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="20" height="20" style="vertical-align: middle;">
            <circle cx="12" cy="12" r="10" fill="none" stroke="currentColor" stroke-width="1.5"/>
            <circle cx="12" cy="12" r="4" fill="currentColor"/>
            <path d="M12 2v4M12 18v4M2 12h4M18 12h4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
        </svg>';
    }

    /**
     * Renderiza o dashboard
     */
    private function render_dashboard_content() {
        if ( defined( 'ORACULO_TAINACAN_PATH' ) ) {
            \Oraculo_Tainacan\oraculo_tainacan_render_template( 'admin/dashboard.php' );
        } else {
            echo '<div class="wrap"><h1>' . esc_html__('Oráculo IA - Dashboard', 'oraculo_tainacan') . '</h1>';
            echo '<p>' . esc_html__('Página em construção.', 'oraculo_tainacan') . '</p></div>';
        }
    }

    /**
     * Renderiza a página de indexação
     */
    private function render_indexing_content() {
        if ( defined( 'ORACULO_TAINACAN_PATH' ) ) {
            \Oraculo_Tainacan\oraculo_tainacan_render_template( 'admin/indexing.php' );
        } else {
            echo '<div class="wrap"><h1>' . esc_html__('Indexação', 'oraculo_tainacan') . '</h1></div>';
        }
    }

    /**
     * Renderiza a página de configurações
     */
    private function render_settings_content() {
        if ( defined( 'ORACULO_TAINACAN_PATH' ) ) {
            \Oraculo_Tainacan\oraculo_tainacan_render_template( 'admin/settings.php' );
        } else {
            echo '<div class="wrap"><h1>' . esc_html__('Configurações', 'oraculo_tainacan') . '</h1></div>';
        }
    }

    /**
     * Renderiza a página de analytics
     */
    private function render_analytics_content() {
        if ( defined( 'ORACULO_TAINACAN_PATH' ) ) {
            \Oraculo_Tainacan\oraculo_tainacan_render_template( 'admin/analytics.php' );
        } else {
            echo '<div class="wrap"><h1>' . esc_html__('Analytics', 'oraculo_tainacan') . '</h1></div>';
        }
    }

    /**
     * Renderiza a página de debug
     */
    private function render_debug_content() {
        if ( defined( 'ORACULO_TAINACAN_PATH' ) ) {
            \Oraculo_Tainacan\oraculo_tainacan_render_template( 'admin/debug.php' );
        } else {
            echo '<div class="wrap"><h1>' . esc_html__('Debug', 'oraculo_tainacan') . '</h1></div>';
        }
    }

    /**
     * Renderiza navegação por abas
     * @param string $current_tab
     */
    private function render_tabs_navigation(string $current_tab) {
        $base_url = admin_url('admin.php?page=' . $this->get_page_slug());
        $options = get_option('oraculo_tainacan_options', []);
        $version = defined('ORACULO_TAINACAN_VERSION') ? ORACULO_TAINACAN_VERSION : '2.0.0';

        $tabs = [
            'dashboard' => [
                'label' => __('Dashboard', 'oraculo_tainacan'),
                'icon' => 'dashicons-dashboard'
            ],
            'indexing' => [
                'label' => __('Indexação', 'oraculo_tainacan'),
                'icon' => 'dashicons-database'
            ],
            'settings' => [
                'label' => __('Configurações', 'oraculo_tainacan'),
                'icon' => 'dashicons-admin-settings'
            ],
            'analytics' => [
                'label' => __('Analytics', 'oraculo_tainacan'),
                'icon' => 'dashicons-chart-area'
            ],
        ];

        // Adicionar aba de debug se habilitado
        if (!empty($options['debug_mode'])) {
            $tabs['debug'] = [
                'label' => __('Debug', 'oraculo_tainacan'),
                'icon' => 'dashicons-code-standards'
            ];
        }

        // Header estilo Tainacan nativo
        echo '<div class="tainacan-fixed-subheader">';
        echo '<h1 class="tainacan-page-title">';
        echo '<svg class="oraculo-title-icon" viewBox="0 0 24 24" width="32" height="32" fill="currentColor">';
        echo '<circle cx="12" cy="12" r="10" fill="none" stroke="currentColor" stroke-width="1.5"/>';
        echo '<circle cx="12" cy="12" r="4" fill="currentColor"/>';
        echo '<path d="M12 2v4M12 18v4M2 12h4M18 12h4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>';
        echo '</svg>';
        echo esc_html__('Oráculo IA', 'oraculo_tainacan');
        echo '<span class="oraculo-version">v' . esc_html($version) . '</span>';
        echo '</h1>';
        echo '</div>';

        // Navegação por abas estilo Tainacan
        echo '<nav class="oraculo-tabs-nav">';
        foreach ($tabs as $tab_id => $tab_data) {
            $url = $tab_id === 'dashboard' ? $base_url : $base_url . '&tab=' . $tab_id;
            $active = $current_tab === $tab_id ? ' active' : '';
            echo '<a href="' . esc_url($url) . '" class="oraculo-nav-tab' . esc_attr($active) . '">';
            echo '<span class="dashicons ' . esc_attr($tab_data['icon']) . '"></span>';
            echo '<span class="tab-label">' . esc_html($tab_data['label']) . '</span>';
            echo '</a>';
        }
        echo '</nav>';
    }

    /**
     * Obtém opções seguras para JS (sem API keys)
     * @return array
     */
    private function get_safe_options(): array {
        $options = get_option('oraculo_tainacan_options', []);

        // Remover chaves sensíveis
        $sensitive_keys = [
            'openai_api_key', 'gemini_api_key', 'deepseek_api_key',
            'groq_api_key', 'claude_api_key', 'generic_openai_api_key'
        ];

        foreach ($sensitive_keys as $key) {
            if (isset($options[$key])) {
                $options[$key] = !empty($options[$key]) ? '••••••••' : '';
            }
        }

        return $options;
    }

    /**
     * Obtém strings para JS
     * @return array
     */
    private function get_js_strings(): array {
        return [
            'confirmDelete' => __('Tem certeza que deseja excluir?', 'oraculo_tainacan'),
            'confirmReindex' => __('Tem certeza que deseja reindexar esta coleção? Isso pode levar alguns minutos.', 'oraculo_tainacan'),
            'indexing' => __('Indexando...', 'oraculo_tainacan'),
            'processing' => __('Processando...', 'oraculo_tainacan'),
            'completed' => __('Concluído!', 'oraculo_tainacan'),
            'error' => __('Erro:', 'oraculo_tainacan'),
            'testing' => __('Testando conexão...', 'oraculo_tainacan'),
            'success' => __('Sucesso!', 'oraculo_tainacan'),
            'saved' => __('Configurações salvas!', 'oraculo_tainacan'),
            'items' => __('itens', 'oraculo_tainacan'),
            'of' => __('de', 'oraculo_tainacan'),
            'cancel' => __('Cancelar', 'oraculo_tainacan'),
            'save' => __('Salvar', 'oraculo_tainacan'),
        ];
    }

    /**
     * Obtém estatísticas rápidas para o dashboard
     * @return array
     */
    public function get_dashboard_stats(): array {
        global $wpdb;

        $vectors_table = $wpdb->prefix . 'oraculo_vectors';
        $logs_table = $wpdb->prefix . 'oraculo_search_logs';

        // Verificar se as tabelas existem
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned table existence check via SHOW TABLES.
        $vectors_exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $vectors_table ) ) === $vectors_table;
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned table existence check via SHOW TABLES.
        $logs_exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $logs_table ) ) === $logs_table;

        if (!$vectors_exists) {
            return [
                'total_indexed' => 0,
                'collections_indexed' => 0,
                'searches_today' => 0,
                'searches_month' => 0,
                'satisfaction_rate' => 0,
                'tokens_month' => 0,
            ];
        }

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- $vectors_table and $logs_table are plugin-owned tables built from $wpdb->prefix + literal; table identifiers cannot be parameterized.

        // Total de itens indexados
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned table; dashboard summary counts change frequently.
        $total_indexed = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$vectors_table}");

        // Coleções indexadas
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned table; dashboard summary counts change frequently.
        $collections_indexed = (int) $wpdb->get_var("SELECT COUNT(DISTINCT collection_id) FROM {$vectors_table}");

        if (!$logs_exists) {
            return [
                'total_indexed' => $total_indexed,
                'collections_indexed' => $collections_indexed,
                'searches_today' => 0,
                'searches_month' => 0,
                'satisfaction_rate' => 0,
                'tokens_month' => 0,
            ];
        }

        // Buscas hoje
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned table; dashboard summary counts change frequently.
        $searches_today = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$logs_table} WHERE DATE(created_at) = %s",
            current_time('Y-m-d')
        ));

        // Buscas este mês
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned table; dashboard summary counts change frequently.
        $searches_month = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$logs_table} WHERE MONTH(created_at) = %d AND YEAR(created_at) = %d",
            current_time('n'),
            current_time('Y')
        ));

        // Taxa de feedback positivo
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned table; dashboard summary counts change frequently.
        $positive_feedback = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$logs_table} WHERE feedback = 'positive'"
        );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned table; dashboard summary counts change frequently.
        $total_feedback = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$logs_table} WHERE feedback IS NOT NULL"
        );
        $satisfaction_rate = $total_feedback > 0
            ? round(($positive_feedback / $total_feedback) * 100, 1)
            : 0;

        // Tokens usados este mês
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned table; dashboard summary counts change frequently.
        $tokens_month = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT SUM(tokens_used) FROM {$logs_table} WHERE MONTH(created_at) = %d AND YEAR(created_at) = %d",
            current_time('n'),
            current_time('Y')
        ));

        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter

        return [
            'total_indexed' => $total_indexed,
            'collections_indexed' => $collections_indexed,
            'searches_today' => $searches_today,
            'searches_month' => $searches_month,
            'satisfaction_rate' => $satisfaction_rate,
            'tokens_month' => $tokens_month,
        ];
    }
}
