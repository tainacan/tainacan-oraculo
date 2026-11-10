<?php
/**
 * Template do Dashboard Administrativo
 *
 * @package Oraculo_Tainacan
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template file; all variables are local to this included template scope.

// Usar a instância do OraculoPage se disponível
$stats           = array();
$indexing_status = array();

if ( class_exists( '\Oraculo_Tainacan\Admin\OraculoPage' ) ) {
	$admin = \Oraculo_Tainacan\Admin\OraculoPage::get_instance();
	$stats = $admin->get_dashboard_stats();
} else {
	// Fallback para dados básicos
	global $wpdb;
	$vectors_table = $wpdb->prefix . 'oraculo_vectors';
	$stats         = array(
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned table ($vectors_table built from $wpdb->prefix + literal oraculo_vectors); fallback count when OraculoPage unavailable.
		'total_indexed'     => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$vectors_table}" ),
		'searches_month'    => 0,
		'satisfaction_rate' => 0,
		'tokens_month'      => 0,
	);
}

// Obter status de indexação
$indexing_status = \Oraculo_Tainacan\get_collections_indexing_status();

// Obter dados de buscas dos últimos 30 dias para o gráfico
global $wpdb;
$logs_table       = $wpdb->prefix . 'oraculo_search_logs';
$searches_30_days = array();

// Verificar se a tabela existe
// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned table existence check via SHOW TABLES.
$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $logs_table ) ) === $logs_table;

if ( $table_exists ) {
	// Buscar contagem de buscas por dia nos últimos 30 dias
    // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- $logs_table is plugin-owned (oraculo_search_logs, built from $wpdb->prefix + literal); table identifier cannot be parameterized.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- timeline chart data, per-request.
	$results = $wpdb->get_results(
		"SELECT DATE(created_at) as date, COUNT(*) as count
         FROM {$logs_table}
         WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
         GROUP BY DATE(created_at)
         ORDER BY date ASC",
		ARRAY_A
	);
    // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter

	// Criar array indexado por data
	$searches_by_date = array();
	foreach ( $results as $row ) {
		$searches_by_date[ $row['date'] ] = (int) $row['count'];
	}

	// Preencher os últimos 30 dias (mesmo que zerados)
	for ( $i = 29; $i >= 0; $i-- ) {
		$date               = gmdate( 'Y-m-d', strtotime( "-{$i} days" ) );
		$searches_30_days[] = array(
			'date'  => wp_date( 'd/m', strtotime( $date ) ),
			'count' => $searches_by_date[ $date ] ?? 0,
		);
	}
}

$options      = \Oraculo_Tainacan\Oraculo_Tainacan::get_options();
$factory      = new \Oraculo_Tainacan\AI\AIProviderFactory();
$has_provider = $factory->has_any_configured();

// URL base para navegação
$base_url = admin_url( 'admin.php?page=oraculo_tainacan_page' );
?>

<div class="oraculo-dashboard-content">
	<?php if ( ! $has_provider ) : ?>
	<div class="notice notice-warning oraculo-notice">
		<p>
			<strong><?php esc_html_e( 'Configuração necessária!', 'oraculo-tainacan' ); ?></strong>
			<?php esc_html_e( 'Configure uma API Key para começar a usar o Oráculo.', 'oraculo-tainacan' ); ?>
			<a href="<?php echo esc_url( $base_url . '&tab=settings' ); ?>">
				<?php esc_html_e( 'Ir para Configurações', 'oraculo-tainacan' ); ?>
			</a>
		</p>
	</div>
	<?php endif; ?>

	<!-- Cards de Estatísticas -->
	<div class="oraculo-stats-grid">
		<div class="oraculo-stat-card">
			<div class="oraculo-stat-icon">📚</div>
			<div class="oraculo-stat-content">
				<span class="oraculo-stat-value"><?php echo esc_html( number_format_i18n( $stats['total_indexed'] ) ); ?></span>
				<span class="oraculo-stat-label"><?php esc_html_e( 'Itens Indexados', 'oraculo-tainacan' ); ?></span>
			</div>
		</div>

		<div class="oraculo-stat-card">
			<div class="oraculo-stat-icon">🔍</div>
			<div class="oraculo-stat-content">
				<span class="oraculo-stat-value"><?php echo esc_html( number_format_i18n( $stats['searches_month'] ) ); ?></span>
				<span class="oraculo-stat-label"><?php esc_html_e( 'Buscas este Mês', 'oraculo-tainacan' ); ?></span>
			</div>
		</div>

		<div class="oraculo-stat-card">
			<div class="oraculo-stat-icon">😊</div>
			<div class="oraculo-stat-content">
				<span class="oraculo-stat-value"><?php echo esc_html( $stats['satisfaction_rate'] ); ?>%</span>
				<span class="oraculo-stat-label"><?php esc_html_e( 'Taxa de Satisfação', 'oraculo-tainacan' ); ?></span>
			</div>
		</div>

		<div class="oraculo-stat-card">
			<div class="oraculo-stat-icon">🎯</div>
			<div class="oraculo-stat-content">
				<span class="oraculo-stat-value"><?php echo esc_html( number_format_i18n( $stats['tokens_month'] ) ); ?></span>
				<span class="oraculo-stat-label"><?php esc_html_e( 'Tokens Utilizados', 'oraculo-tainacan' ); ?></span>
			</div>
		</div>
	</div>

	<div class="oraculo-dashboard-grid">
		<!-- Status das Coleções -->
		<div class="oraculo-card">
			<div class="oraculo-card-header">
				<h2><?php esc_html_e( 'Status de Indexação', 'oraculo-tainacan' ); ?></h2>
				<a href="<?php echo esc_url( $base_url . '&tab=indexing' ); ?>" class="button">
					<?php esc_html_e( 'Gerenciar', 'oraculo-tainacan' ); ?>
				</a>
			</div>
			<div class="oraculo-card-body">
				<?php if ( empty( $indexing_status ) ) : ?>
					<p class="oraculo-empty-state">
						<?php esc_html_e( 'Nenhuma coleção encontrada no Tainacan.', 'oraculo-tainacan' ); ?>
					</p>
				<?php else : ?>
					<table class="oraculo-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Coleção', 'oraculo-tainacan' ); ?></th>
								<th><?php esc_html_e( 'Itens', 'oraculo-tainacan' ); ?></th>
								<th><?php esc_html_e( 'Indexados', 'oraculo-tainacan' ); ?></th>
								<th><?php esc_html_e( 'Status', 'oraculo-tainacan' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( array_slice( $indexing_status, 0, 5 ) as $collection ) : ?>
							<tr>
								<td><?php echo esc_html( $collection['collection_name'] ); ?></td>
								<td><?php echo esc_html( number_format_i18n( $collection['total_items'] ) ); ?></td>
								<td>
									<div class="oraculo-progress-bar">
										<div class="oraculo-progress" style="width: <?php echo esc_attr( $collection['percentage'] ); ?>%"></div>
										<span class="oraculo-progress-text"><?php echo esc_html( $collection['percentage'] ); ?>%</span>
									</div>
								</td>
								<td>
									<span class="oraculo-status oraculo-status-<?php echo esc_attr( $collection['job_status'] ); ?>">
										<?php echo esc_html( ucfirst( $collection['job_status'] ) ); ?>
									</span>
								</td>
							</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>
		</div>

		<!-- Gráfico de Buscas -->
		<div class="oraculo-card">
			<div class="oraculo-card-header">
				<h2><?php esc_html_e( 'Buscas nos Últimos 30 Dias', 'oraculo-tainacan' ); ?></h2>
			</div>
			<div class="oraculo-card-body">
				<canvas id="oraculo-searches-chart" height="200"
						data-searches="<?php echo esc_attr( wp_json_encode( $searches_30_days ) ); ?>"
						data-label="<?php esc_attr_e( 'Buscas', 'oraculo-tainacan' ); ?>"
						data-tooltip-suffix="<?php esc_attr_e( 'buscas', 'oraculo-tainacan' ); ?>"></canvas>
			</div>
		</div>

		<!-- Atalhos Rápidos -->
		<div class="oraculo-card oraculo-card-small">
			<div class="oraculo-card-header">
				<h2><?php esc_html_e( 'Ações Rápidas', 'oraculo-tainacan' ); ?></h2>
			</div>
			<div class="oraculo-card-body">
				<div class="oraculo-quick-actions">
					<a href="<?php echo esc_url( $base_url . '&tab=settings' ); ?>" class="oraculo-action-button">
						<span class="dashicons dashicons-admin-generic"></span>
						<?php esc_html_e( 'Configurações', 'oraculo-tainacan' ); ?>
					</a>

					<a href="<?php echo esc_url( $base_url . '&tab=indexing' ); ?>" class="oraculo-action-button">
						<span class="dashicons dashicons-update"></span>
						<?php esc_html_e( 'Indexar Coleções', 'oraculo-tainacan' ); ?>
					</a>

					<a href="<?php echo esc_url( $base_url . '&tab=analytics' ); ?>" class="oraculo-action-button">
						<span class="dashicons dashicons-chart-area"></span>
						<?php esc_html_e( 'Ver Analytics', 'oraculo-tainacan' ); ?>
					</a>

					<button type="button" class="oraculo-action-button" id="oraculo-test-search">
						<span class="dashicons dashicons-search"></span>
						<?php esc_html_e( 'Testar Busca', 'oraculo-tainacan' ); ?>
					</button>
				</div>
			</div>
		</div>

		<!-- Configuração Atual -->
		<div class="oraculo-card oraculo-card-small">
			<div class="oraculo-card-header">
				<h2><?php esc_html_e( 'Configuração Atual', 'oraculo-tainacan' ); ?></h2>
			</div>
			<div class="oraculo-card-body">
				<ul class="oraculo-config-list">
					<li>
						<span class="oraculo-config-label"><?php esc_html_e( 'Provedor de IA:', 'oraculo-tainacan' ); ?></span>
						<span class="oraculo-config-value">
							<?php
							$providers = array(
								'openai'         => 'OpenAI (ChatGPT)',
								'gemini'         => 'Google Gemini',
								'deepseek'       => 'DeepSeek',
								'ollama'         => 'Ollama (Local)',
								'groq'           => 'Groq',
								'claude'         => 'Claude (Anthropic)',
								'generic_openai' => 'OpenAI Compatível (Genérico)',
							);
							echo esc_html( $providers[ $options['ai_provider'] ?? 'openai' ] ?? 'Não configurado' );
							?>
						</span>
					</li>
					<li>
						<span class="oraculo-config-label"><?php esc_html_e( 'Modelo:', 'oraculo-tainacan' ); ?></span>
						<span class="oraculo-config-value">
							<?php echo esc_html( $options[ $options['ai_provider'] . '_model' ] ?? 'Padrão' ); ?>
						</span>
					</li>
					<li>
						<span class="oraculo-config-label"><?php esc_html_e( 'Chat Ativo:', 'oraculo-tainacan' ); ?></span>
						<span class="oraculo-config-value">
							<?php echo ! empty( $options['enable_chat'] ) ? '✅' : '❌'; ?>
						</span>
					</li>
					<li>
						<span class="oraculo-config-label"><?php esc_html_e( 'Analytics:', 'oraculo-tainacan' ); ?></span>
						<span class="oraculo-config-value">
							<?php echo ! empty( $options['enable_analytics'] ) ? '✅' : '❌'; ?>
						</span>
					</li>
				</ul>
			</div>
		</div>
	</div>

	<!-- Modal de Teste de Busca -->
	<div id="oraculo-test-modal" class="oraculo-modal-overlay" style="display:none;">
		<div class="oraculo-modal">
			<div class="oraculo-modal-header">
				<h3><?php esc_html_e( 'Testar Busca', 'oraculo-tainacan' ); ?></h3>
				<button type="button" class="oraculo-modal-close">&times;</button>
			</div>
			<div class="oraculo-modal-body">
				<div class="oraculo-form-group">
					<label for="oraculo-test-query"><?php esc_html_e( 'Digite sua pergunta:', 'oraculo-tainacan' ); ?></label>
					<input type="text" id="oraculo-test-query" placeholder="<?php esc_attr_e( 'Ex: Quais documentos tratam de...', 'oraculo-tainacan' ); ?>">
				</div>
				<button type="button" id="oraculo-run-test" class="oraculo-btn oraculo-btn-primary">
					<?php esc_html_e( 'Buscar', 'oraculo-tainacan' ); ?>
				</button>
				<div id="oraculo-test-results" class="oraculo-test-results"></div>
			</div>
		</div>
	</div>
</div>


<?php // phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound ?>
