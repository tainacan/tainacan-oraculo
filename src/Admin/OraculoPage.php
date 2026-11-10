<?php
/**
 * Página do Oráculo integrada ao Tainacan 1.0+
 *
 * Segue a arquitetura de páginas do Tainacan usando a classe
 * abstrata \Tainacan\Pages + trait Singleton_Instance.
 *
 * @package Oraculo_Tainacan
 */

declare( strict_types=1 );

namespace Oraculo_Tainacan\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Página principal do Oráculo no menu do Tainacan
 */
class OraculoPage extends \Tainacan\Pages {

	use \Tainacan\Traits\Singleton_Instance;

	/**
	 * Inicialização (chamada pelo trait Singleton_Instance)
	 */
	public function init() {
		parent::init();
	}

	/**
	 * Slug da página
	 *
	 * @return string
	 */
	protected function get_page_slug(): string {
		return 'oraculo_tainacan_page';
	}

	/**
	 * Adiciona item ao menu "Mais" (other links) do Tainacan
	 */
	public function add_admin_menu() {
		$page_suffix = add_submenu_page(
			$this->tainacan_other_links_slug,
			__( 'Oráculo IA', 'oraculo-tainacan' ),
			'<span class="icon">' . $this->get_oraculo_icon() . '</span>' .
			'<span class="menu-text">' . __( 'Oráculo IA', 'oraculo-tainacan' ) . '</span>',
			'manage_options',
			$this->get_page_slug(),
			array( $this, 'render_page' ),
			3
		);

		if ( $page_suffix ) {
			add_action( 'load-' . $page_suffix, array( $this, 'load_page' ) );
		}
	}

	/**
	 * Renderiza o conteúdo da página
	 */
	public function render_page_content() {
		// Determinar qual sub-página exibir baseado no parâmetro tab
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- tab is a read-only navigation parameter with no side effects; value is whitelisted below.
		$tab          = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'dashboard';
		$allowed_tabs = array( 'dashboard', 'indexing', 'settings', 'diagnostics', 'analytics', 'debug' );
		if ( ! in_array( $tab, $allowed_tabs, true ) ) {
			$tab = 'dashboard';
		}

		// Wrapper seguindo padrão Tainacan (como o Extrator IA)
		echo '<div class="wrap tainacan-page-container-content oraculo-admin">';

		// Renderizar navegação por abas
		$this->render_tabs_navigation( $tab );

		switch ( $tab ) {
			case 'indexing':
				$this->render_indexing_content();
				break;
			case 'settings':
				$this->render_settings_content();
				break;
			case 'diagnostics':
				\Oraculo_Tainacan\oraculo_tainacan_render_template( 'admin/diagnostics.php' );
				break;
			case 'analytics':
				$this->render_analytics_content();
				break;
			case 'debug':
				$options = get_option( 'oraculo_tainacan_options', array() );
				if ( ! empty( $options['debug_mode'] ) ) {
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
	}

	/**
	 * Enqueue CSS específico
	 */
	public function admin_enqueue_css() {
		// CSS Admin
		if ( defined( 'ORACULO_TAINACAN_URL' ) && defined( 'ORACULO_TAINACAN_VERSION' ) ) {
			wp_enqueue_style(
				'oraculo-admin-page',
				ORACULO_TAINACAN_URL . 'assets/css/admin-page.css',
				array(),
				ORACULO_TAINACAN_VERSION
			);
		}
	}

	/**
	 * Aba ativa da página (whitelist compartilhada com render_page_content).
	 *
	 * @return string
	 */
	private function get_current_tab(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- tab is a read-only navigation parameter with no side effects; value is whitelisted below.
		$tab          = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'dashboard';
		$allowed_tabs = array( 'dashboard', 'indexing', 'settings', 'diagnostics', 'analytics', 'debug' );

		return in_array( $tab, $allowed_tabs, true ) ? $tab : 'dashboard';
	}

	/**
	 * Enqueue JS específico
	 */
	public function admin_enqueue_js() {
		// Chart.js para gráficos (bundled locally to avoid external CDN)
		wp_enqueue_script(
			'chart-js',
			plugins_url( 'assets/vendor/chart.umd.min.js', ORACULO_TAINACAN_FILE ),
			array(),
			'4.4.1',
			true
		);

		if ( defined( 'ORACULO_TAINACAN_URL' ) && defined( 'ORACULO_TAINACAN_VERSION' ) ) {
			// JS Admin (base: tabs, tooltips, notices, modal)
			wp_enqueue_script(
				'oraculo-admin-page',
				ORACULO_TAINACAN_URL . 'assets/js/admin-page.js',
				array( 'jquery', 'chart-js', 'wp-util' ),
				ORACULO_TAINACAN_VERSION,
				true
			);

			// Dados/i18n para os scripts da página (localize após o registro do handle)
			$factory = new \Oraculo_Tainacan\AI\AIProviderFactory();
			wp_localize_script(
				'oraculo-admin-page',
				'OraculoAdminPage',
				array(
					'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
					'restUrl'         => rest_url( 'oraculo/v1/' ),
					'nonce'           => wp_create_nonce( 'oraculo_admin' ),
					'restNonce'       => wp_create_nonce( 'wp_rest' ),
					'providers'       => $factory->get_available_providers(),
					'currentProvider' => $factory->get_current_provider(),
					'collections'     => function_exists( '\Oraculo_Tainacan\get_tainacan_collections' )
						? \Oraculo_Tainacan\get_tainacan_collections()
						: array(),
					'options'         => $this->get_safe_options(),
					'strings'         => $this->get_js_strings(),
				)
			);

			// JS por aba (extraídos dos antigos inline <script> dos templates)
			$tab      = $this->get_current_tab();
			$tab_deps = array(
				'dashboard'   => array( 'chart-js' ),
				'analytics'   => array( 'jquery', 'oraculo-admin-page', 'chart-js' ),
				'settings'    => array( 'jquery', 'oraculo-admin-page' ),
				'indexing'    => array( 'jquery', 'oraculo-admin-page' ),
				'diagnostics' => array( 'oraculo-admin-page' ),
				'debug'       => array( 'jquery', 'oraculo-admin-page' ),
			);

			wp_enqueue_script(
				'oraculo-admin-tab-' . $tab,
				ORACULO_TAINACAN_URL . 'assets/js/admin/' . $tab . '.js',
				$tab_deps[ $tab ],
				ORACULO_TAINACAN_VERSION,
				true
			);
		}
	}

	/**
	 * Obtém ícone SVG para o menu
	 *
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
			echo '<div class="wrap"><h1>' . esc_html__( 'Oráculo IA - Dashboard', 'oraculo-tainacan' ) . '</h1>';
			echo '<p>' . esc_html__( 'Página em construção.', 'oraculo-tainacan' ) . '</p></div>';
		}
	}

	/**
	 * Renderiza a página de indexação
	 */
	private function render_indexing_content() {
		if ( defined( 'ORACULO_TAINACAN_PATH' ) ) {
			\Oraculo_Tainacan\oraculo_tainacan_render_template( 'admin/indexing.php' );
		} else {
			echo '<div class="wrap"><h1>' . esc_html__( 'Indexação', 'oraculo-tainacan' ) . '</h1></div>';
		}
	}

	/**
	 * Renderiza a página de configurações
	 */
	private function render_settings_content() {
		if ( defined( 'ORACULO_TAINACAN_PATH' ) ) {
			\Oraculo_Tainacan\oraculo_tainacan_render_template( 'admin/settings.php' );
		} else {
			echo '<div class="wrap"><h1>' . esc_html__( 'Configurações', 'oraculo-tainacan' ) . '</h1></div>';
		}
	}

	/**
	 * Renderiza a página de analytics
	 */
	private function render_analytics_content() {
		if ( defined( 'ORACULO_TAINACAN_PATH' ) ) {
			\Oraculo_Tainacan\oraculo_tainacan_render_template( 'admin/analytics.php' );
		} else {
			echo '<div class="wrap"><h1>' . esc_html__( 'Analytics', 'oraculo-tainacan' ) . '</h1></div>';
		}
	}

	/**
	 * Renderiza a página de debug
	 */
	private function render_debug_content() {
		if ( defined( 'ORACULO_TAINACAN_PATH' ) ) {
			\Oraculo_Tainacan\oraculo_tainacan_render_template( 'admin/debug.php' );
		} else {
			echo '<div class="wrap"><h1>' . esc_html__( 'Debug', 'oraculo-tainacan' ) . '</h1></div>';
		}
	}

	/**
	 * Renderiza navegação por abas
	 *
	 * @param string $current_tab
	 */
	private function render_tabs_navigation( string $current_tab ) {
		$base_url = admin_url( 'admin.php?page=' . $this->get_page_slug() );
		$options  = get_option( 'oraculo_tainacan_options', array() );
		$version  = defined( 'ORACULO_TAINACAN_VERSION' ) ? ORACULO_TAINACAN_VERSION : '2.0.0';

		$tabs = array(
			'dashboard'   => array(
				'label' => __( 'Dashboard', 'oraculo-tainacan' ),
				'icon'  => 'dashicons-dashboard',
			),
			'indexing'    => array(
				'label' => __( 'Indexação', 'oraculo-tainacan' ),
				'icon'  => 'dashicons-database',
			),
			'settings'    => array(
				'label' => __( 'Configurações', 'oraculo-tainacan' ),
				'icon'  => 'dashicons-admin-settings',
			),
			'diagnostics' => array(
				'label' => __( 'Diagnóstico', 'oraculo-tainacan' ),
				'icon'  => 'dashicons-performance',
			),
			'analytics'   => array(
				'label' => __( 'Analytics', 'oraculo-tainacan' ),
				'icon'  => 'dashicons-chart-area',
			),
		);

		// Adicionar aba de debug se habilitado
		if ( ! empty( $options['debug_mode'] ) ) {
			$tabs['debug'] = array(
				'label' => __( 'Debug', 'oraculo-tainacan' ),
				'icon'  => 'dashicons-code-standards',
			);
		}

		// Header estilo Tainacan nativo
		echo '<div class="tainacan-fixed-subheader">';
		echo '<h1 class="tainacan-page-title">';
		echo '<svg class="oraculo-title-icon" viewBox="0 0 24 24" width="32" height="32" fill="currentColor">';
		echo '<circle cx="12" cy="12" r="10" fill="none" stroke="currentColor" stroke-width="1.5"/>';
		echo '<circle cx="12" cy="12" r="4" fill="currentColor"/>';
		echo '<path d="M12 2v4M12 18v4M2 12h4M18 12h4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>';
		echo '</svg>';
		echo esc_html__( 'Oráculo IA', 'oraculo-tainacan' );
		echo '<span class="oraculo-version">v' . esc_html( $version ) . '</span>';
		echo '</h1>';
		echo '</div>';

		// Navegação por abas estilo Tainacan
		echo '<nav class="oraculo-tabs-nav">';
		foreach ( $tabs as $tab_id => $tab_data ) {
			$url    = $tab_id === 'dashboard' ? $base_url : $base_url . '&tab=' . $tab_id;
			$active = $current_tab === $tab_id ? ' active' : '';
			echo '<a href="' . esc_url( $url ) . '" class="oraculo-nav-tab' . esc_attr( $active ) . '">';
			echo '<span class="dashicons ' . esc_attr( $tab_data['icon'] ) . '"></span>';
			echo '<span class="tab-label">' . esc_html( $tab_data['label'] ) . '</span>';
			echo '</a>';
		}
		echo '</nav>';
	}

	/**
	 * Obtém opções seguras para JS (sem API keys)
	 *
	 * @return array
	 */
	private function get_safe_options(): array {
		$options = get_option( 'oraculo_tainacan_options', array() );

		// Remover chaves sensíveis
		$sensitive_keys = array(
			'openai_api_key',
			'gemini_api_key',
			'deepseek_api_key',
			'groq_api_key',
			'claude_api_key',
			'generic_openai_api_key',
		);

		foreach ( $sensitive_keys as $key ) {
			if ( isset( $options[ $key ] ) ) {
				$options[ $key ] = ! empty( $options[ $key ] ) ? '••••••••' : '';
			}
		}

		return $options;
	}

	/**
	 * Obtém strings para JS
	 *
	 * @return array
	 */
	private function get_js_strings(): array {
		return array(
			// Aba Diagnóstico (assets/js/admin/diagnostics.js).
			'diagResult'             => __( 'Resultado do teste', 'oraculo-tainacan' ),
			'diagBootstrap'          => __( 'Carga do WordPress nesta rota', 'oraculo-tainacan' ),
			'diagPluginsLoaded'      => __( 'plugin(s) carregado(s)', 'oraculo-tainacan' ),
			'diagFast'               => __( 'Modo rápido em ação', 'oraculo-tainacan' ),
			'diagPool'               => __( 'Processos PHP próprios', 'oraculo-tainacan' ),
			'diagYes'                => __( 'sim', 'oraculo-tainacan' ),
			'diagNo'                 => __( 'não', 'oraculo-tainacan' ),
			'diagSearchTest'         => __( 'Busca de teste', 'oraculo-tainacan' ),
			'diagError'              => __( 'erro', 'oraculo-tainacan' ),
			'diagEmbedding'          => __( 'Entender a pergunta (embedding, no provedor)', 'oraculo-tainacan' ),
			'diagVectors'            => __( 'Comparar com o acervo (vetores)', 'oraculo-tainacan' ),
			'diagItems'              => __( 'itens', 'oraculo-tainacan' ),
			'diagLlm'                => __( 'Resposta curta da IA', 'oraculo-tainacan' ),
			'diagLegend'             => __( 'Verde: dentro do que o teste de carga mostrou como bom. Amarelo: funciona, mas pesa quando muita gente usa ao mesmo tempo.', 'oraculo-tainacan' ),
			'diagStatusOk'           => __( 'Em ordem', 'oraculo-tainacan' ),
			'diagStatusWarn'         => __( 'Pode melhorar', 'oraculo-tainacan' ),
			'diagPoolOn'             => __( 'ligado', 'oraculo-tainacan' ),
			'diagPoolOnText'         => __( 'O teste passou pelo grupo de processos do Oráculo.', 'oraculo-tainacan' ),
			'diagPoolOff'            => __( 'não encontrado', 'oraculo-tainacan' ),
			'diagPoolOffText'        => __( 'O teste não passou por um grupo próprio (a receita do manual define a variável ORACULO_PHP_POOL nele).', 'oraculo-tainacan' ),
			'diagFastNoEffect'       => __( 'ligado, sem efeito', 'oraculo-tainacan' ),
			'diagFastNoEffectText'   => __( 'O arquivo está instalado, mas este pedido carregou todos os plugins. Algum cache de página ou proxy pode estar mudando o endereço da rota.', 'oraculo-tainacan' ),
			'diagTesting'            => __( 'Testando… (alguns segundos)', 'oraculo-tainacan' ),
			'diagFailed'             => __( 'O teste falhou:', 'oraculo-tainacan' ),
			'confirmDelete'          => __( 'Tem certeza que deseja excluir?', 'oraculo-tainacan' ),
			'confirmReindex'         => __( 'Tem certeza que deseja reindexar esta coleção? Isso pode levar alguns minutos.', 'oraculo-tainacan' ),
			'indexing'               => __( 'Indexando...', 'oraculo-tainacan' ),
			'processing'             => __( 'Processando', 'oraculo-tainacan' ),
			'processing2'            => __( 'Processando...', 'oraculo-tainacan' ),
			'completed'              => __( 'Concluído', 'oraculo-tainacan' ),
			'error'                  => __( 'Erro', 'oraculo-tainacan' ),
			'testing'                => __( 'Testando...', 'oraculo-tainacan' ),
			'test'                   => __( 'Testar', 'oraculo-tainacan' ),
			'testConnection'         => __( 'Testar Conexão', 'oraculo-tainacan' ),
			'connectionOk'           => __( 'Conexão OK!', 'oraculo-tainacan' ),
			'connectionFailed'       => __( 'Erro na conexão', 'oraculo-tainacan' ),
			'fetchingModels'         => __( 'Buscando modelos...', 'oraculo-tainacan' ),
			'fetchModelsError'       => __( 'Não foi possível buscar os modelos.', 'oraculo-tainacan' ),
			/* translators: %d: number of models found; substituted client-side via JS String.replace(), not sprintf(). */
			'modelsFound'            => __( '%d modelo(s) encontrado(s) nesta conta.', 'oraculo-tainacan' ),
			'connError'              => __( 'Erro de conexão', 'oraculo-tainacan' ),
			'success'                => __( 'Sucesso!', 'oraculo-tainacan' ),
			'saved'                  => __( 'Configurações salvas com sucesso!', 'oraculo-tainacan' ),
			'saveError'              => __( 'Erro ao salvar', 'oraculo-tainacan' ),
			'saving'                 => __( 'Salvando...', 'oraculo-tainacan' ),
			'settingsSaved'          => __( 'Configurações salvas!', 'oraculo-tainacan' ),
			'items'                  => __( 'itens', 'oraculo-tainacan' ),
			'of'                     => __( 'de', 'oraculo-tainacan' ),
			'cancel'                 => __( 'Cancelar', 'oraculo-tainacan' ),
			'save'                   => __( 'Salvar', 'oraculo-tainacan' ),
			'clearing'               => __( 'Limpando...', 'oraculo-tainacan' ),
			'clear'                  => __( 'Limpar', 'oraculo-tainacan' ),
			'clearCache'             => __( 'Limpar Cache', 'oraculo-tainacan' ),
			'cacheCleared'           => __( 'Cache limpo com sucesso!', 'oraculo-tainacan' ),
			'cacheClearedShort'      => __( 'Cache limpo!', 'oraculo-tainacan' ),
			'cacheError'             => __( 'Erro ao limpar cache', 'oraculo-tainacan' ),
			'clearVectors'           => __( 'Limpar Vetores', 'oraculo-tainacan' ),
			'vectorsCleared'         => __( 'Vetores limpos com sucesso!', 'oraculo-tainacan' ),
			'vectorsError'           => __( 'Erro ao limpar vetores', 'oraculo-tainacan' ),
			'vectorsRemoved'         => __( 'Vetores da coleção removidos.', 'oraculo-tainacan' ),
			'allVectorsRemoved'      => __( 'Todos os vetores foram removidos.', 'oraculo-tainacan' ),
			'confirmClearVectors'    => __( 'Tem certeza? Isso removerá todos os vetores indexados.', 'oraculo-tainacan' ),
			'confirmClearCollection' => __( 'Tem certeza que deseja limpar os vetores desta coleção?', 'oraculo-tainacan' ),
			'confirmIndexAll'        => __( 'Indexar todas as coleções? Isso pode demorar.', 'oraculo-tainacan' ),
			'confirmClearAll'        => __( 'ATENÇÃO: Isso removerá TODOS os vetores. Continuar?', 'oraculo-tainacan' ),
			'queueProcessing'        => __( 'Processando fila de indexação...', 'oraculo-tainacan' ),
			'queueDone'              => __( 'Fila processada', 'oraculo-tainacan' ),
			'queueIndexed'           => __( 'indexado(s)', 'oraculo-tainacan' ),
			'queueFailed'            => __( 'falha(s)', 'oraculo-tainacan' ),
			'queueRemaining'         => __( 'restante(s)', 'oraculo-tainacan' ),
			'clipSent'               => __( 'imagem(ns) enviada(s)', 'oraculo-tainacan' ),
			'startingIndex'          => __( 'Iniciando indexação da coleção', 'oraculo-tainacan' ),
			'syncWait'               => __( 'Aguarde, este processo é síncrono e pode levar alguns segundos...', 'oraculo-tainacan' ),
			'indexDone'              => __( 'Indexação concluída!', 'oraculo-tainacan' ),
			'indexingAll'            => __( 'Iniciando indexação de todas as coleções...', 'oraculo-tainacan' ),
			'itemsFailed'            => __( 'itens falharam na indexação.', 'oraculo-tainacan' ),
			'index'                  => __( 'Indexar', 'oraculo-tainacan' ),
			'reindex'                => __( 'Reindexar', 'oraculo-tainacan' ),
			'notIndexed'             => __( 'Não indexado', 'oraculo-tainacan' ),
			'unknownError'           => __( 'Erro desconhecido', 'oraculo-tainacan' ),
			'optimizing'             => __( 'Otimizando...', 'oraculo-tainacan' ),
			'optimizeDb'             => __( 'Otimizar Banco de Dados', 'oraculo-tainacan' ),
			'optimizeTables'         => __( 'Otimizar Tabelas', 'oraculo-tainacan' ),
			'dbOptimized'            => __( 'Banco de dados otimizado com sucesso!', 'oraculo-tainacan' ),
			'tablesOptimized'        => __( 'Tabelas otimizadas!', 'oraculo-tainacan' ),
			'tablesRepaired'         => __( 'Tabelas reparadas com sucesso!', 'oraculo-tainacan' ),
			'confirmReset1'          => __( 'ATENÇÃO: Isso removerá TODOS os dados do plugin. Continuar?', 'oraculo-tainacan' ),
			'confirmReset2'          => __( 'Esta ação NÃO pode ser desfeita. Tem certeza absoluta?', 'oraculo-tainacan' ),
			'resetDone'              => __( 'Plugin resetado com sucesso!', 'oraculo-tainacan' ),
		);
	}

	/**
	 * Obtém estatísticas rápidas para o dashboard
	 *
	 * @return array
	 */
	public function get_dashboard_stats(): array {
		global $wpdb;

		$vectors_table = $wpdb->prefix . 'oraculo_vectors';
		$logs_table    = $wpdb->prefix . 'oraculo_search_logs';

		// Verificar se as tabelas existem
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned table existence check via SHOW TABLES.
		$vectors_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $vectors_table ) ) === $vectors_table;
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned table existence check via SHOW TABLES.
		$logs_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $logs_table ) ) === $logs_table;

		if ( ! $vectors_exists ) {
			return array(
				'total_indexed'       => 0,
				'collections_indexed' => 0,
				'searches_today'      => 0,
				'searches_month'      => 0,
				'satisfaction_rate'   => 0,
				'tokens_month'        => 0,
			);
		}

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- $vectors_table and $logs_table are plugin-owned tables built from $wpdb->prefix + literal; table identifiers cannot be parameterized.

		// Total de itens indexados
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned table; dashboard summary counts change frequently.
		$total_indexed = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$vectors_table}" );

		// Coleções indexadas
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned table; dashboard summary counts change frequently.
		$collections_indexed = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT collection_id) FROM {$vectors_table}" );

		if ( ! $logs_exists ) {
			return array(
				'total_indexed'       => $total_indexed,
				'collections_indexed' => $collections_indexed,
				'searches_today'      => 0,
				'searches_month'      => 0,
				'satisfaction_rate'   => 0,
				'tokens_month'        => 0,
			);
		}

		// Buscas hoje
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned table; dashboard summary counts change frequently.
		$searches_today = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$logs_table} WHERE DATE(created_at) = %s",
				current_time( 'Y-m-d' )
			)
		);

		// Buscas este mês
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned table; dashboard summary counts change frequently.
		$searches_month = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$logs_table} WHERE MONTH(created_at) = %d AND YEAR(created_at) = %d",
				current_time( 'n' ),
				current_time( 'Y' )
			)
		);

		// Taxa de feedback positivo
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned table; dashboard summary counts change frequently.
		$positive_feedback = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$logs_table} WHERE feedback = 'positive'"
		);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned table; dashboard summary counts change frequently.
		$total_feedback    = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$logs_table} WHERE feedback IS NOT NULL"
		);
		$satisfaction_rate = $total_feedback > 0
			? round( ( $positive_feedback / $total_feedback ) * 100, 1 )
			: 0;

		// Tokens usados este mês
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned table; dashboard summary counts change frequently.
		$tokens_month = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT SUM(tokens_used) FROM {$logs_table} WHERE MONTH(created_at) = %d AND YEAR(created_at) = %d",
				current_time( 'n' ),
				current_time( 'Y' )
			)
		);

        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter

		return array(
			'total_indexed'       => $total_indexed,
			'collections_indexed' => $collections_indexed,
			'searches_today'      => $searches_today,
			'searches_month'      => $searches_month,
			'satisfaction_rate'   => $satisfaction_rate,
			'tokens_month'        => $tokens_month,
		);
	}
}
