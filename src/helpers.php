<?php
/**
 * Funções auxiliares do plugin Oráculo Tainacan
 *
 * @package Oraculo_Tainacan
 */

namespace Oraculo_Tainacan;

// Impedir acesso direto
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Obtém opções do plugin
 *
 * @param string|null $key Chave específica ou null para todas
 * @param mixed $default Valor padrão
 * @return mixed
 */
function get_option_value(?string $key = null, $default = null) {
    $options = get_option('oraculo_tainacan_options', []);

    if ($key === null) {
        return $options;
    }

    return $options[$key] ?? $default;
}

/**
 * Verifica se o modo debug está ativo
 *
 * @return bool
 */
function is_debug_mode(): bool {
    return (bool) get_option_value('debug_mode', false);
}

/**
 * Detecta ambiente de execução do WordPress
 *
 * @return string
 */
function detect_environment(): string {
    if (function_exists('wp_get_environment_type')) {
        return sanitize_text_field(wp_get_environment_type());
    }

    return 'production';
}

/**
 * Loga mensagem se debug estiver ativo
 *
 * @param string $message
 * @param mixed $data
 * @param string $level
 */
function debug_log(string $message, $data = null, string $level = 'info'): void {
    if (!is_debug_mode()) {
        return;
    }

    $log_entry = sprintf(
        '[Oráculo Tainacan] [%s] %s',
        strtoupper($level),
        $message
    );

    if ($data !== null) {
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r -- Debug log gated by WP_DEBUG.
        $log_entry .= ' | Data: ' . print_r($data, true);
    }

    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug log gated by WP_DEBUG.
        error_log($log_entry);
    }
}

/**
 * Calcula similaridade de cosseno entre dois vetores
 *
 * @param array $vector_a
 * @param array $vector_b
 * @return float
 */
function cosine_similarity(array $vector_a, array $vector_b): float {
    if (count($vector_a) !== count($vector_b)) {
        return 0.0;
    }

    $dot_product = 0.0;
    $magnitude_a = 0.0;
    $magnitude_b = 0.0;

    for ($i = 0, $len = count($vector_a); $i < $len; $i++) {
        $dot_product += $vector_a[$i] * $vector_b[$i];
        $magnitude_a += $vector_a[$i] * $vector_a[$i];
        $magnitude_b += $vector_b[$i] * $vector_b[$i];
    }

    $magnitude_a = sqrt($magnitude_a);
    $magnitude_b = sqrt($magnitude_b);

    if ($magnitude_a == 0 || $magnitude_b == 0) {
        return 0.0;
    }

    return $dot_product / ($magnitude_a * $magnitude_b);
}

/**
 * Gera hash único para conteúdo
 *
 * @param string $content
 * @return string
 */
function generate_content_hash(string $content): string {
    return hash('sha256', $content);
}

/**
 * Gera ID de sessão único
 *
 * @return string
 */
function generate_session_id(): string {
    return wp_generate_uuid4();
}

/**
 * Trunca texto mantendo palavras completas
 *
 * @param string $text
 * @param int $max_length
 * @param string $suffix
 * @return string
 */
function truncate_text(string $text, int $max_length = 500, string $suffix = '...'): string {
    if (mb_strlen($text) <= $max_length) {
        return $text;
    }

    $truncated = mb_substr($text, 0, $max_length);
    $last_space = mb_strrpos($truncated, ' ');

    if ($last_space !== false) {
        $truncated = mb_substr($truncated, 0, $last_space);
    }

    return $truncated . $suffix;
}

/**
 * Destaca termos de busca no texto
 *
 * @param string $text
 * @param string $query
 * @param string $tag
 * @return string
 */
function highlight_terms(string $text, string $query, string $tag = 'mark'): string {
    $terms = preg_split('/\s+/', $query);
    $terms = array_filter($terms, fn($term) => mb_strlen($term) > 2);

    if (empty($terms)) {
        return $text;
    }

    foreach ($terms as $term) {
        $pattern = '/(' . preg_quote($term, '/') . ')/iu';
        $text = preg_replace($pattern, "<{$tag}>$1</{$tag}>", $text);
    }

    return $text;
}

/**
 * Limpa e normaliza texto para indexação
 *
 * @param string $text
 * @return string
 */
function normalize_text(string $text): string {
    // Remove HTML
    $text = wp_strip_all_tags($text);

    // Converte entidades HTML
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    // Remove múltiplos espaços em branco
    $text = preg_replace('/\s+/', ' ', $text);

    // Remove caracteres de controle
    $text = preg_replace('/[\x00-\x1F\x7F]/', '', $text);

    // Trim
    $text = trim($text);

    return $text;
}

/**
 * Formata tamanho de arquivo
 *
 * @param int $bytes
 * @param int $decimals
 * @return string
 */
function format_bytes(int $bytes, int $decimals = 2): string {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];

    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);

    $bytes /= pow(1024, $pow);

    return round($bytes, $decimals) . ' ' . $units[$pow];
}

/**
 * Formata duração em milissegundos
 *
 * @param int $milliseconds
 * @return string
 */
function format_duration(int $milliseconds): string {
    if ($milliseconds < 1000) {
        return $milliseconds . 'ms';
    }

    $seconds = $milliseconds / 1000;

    if ($seconds < 60) {
        return round($seconds, 2) . 's';
    }

    $minutes = floor($seconds / 60);
    $remaining_seconds = $seconds % 60;

    return sprintf('%dm %ds', $minutes, $remaining_seconds);
}

/**
 * Obtém coleções do Tainacan
 *
 * @param bool $only_published
 * @return array
 */
function get_tainacan_collections(bool $only_published = true): array {
    if (!class_exists('\Tainacan\Repositories\Collections')) {
        return [];
    }

    $repository = \Tainacan\Repositories\Collections::get_instance();

    $args = [];
    if ($only_published) {
        $args['status'] = 'publish';
    }

    $collections = $repository->fetch($args, 'OBJECT');

    if (!is_array($collections)) {
        return [];
    }

    $result = [];
    foreach ($collections as $collection) {
        // Contar itens da coleção usando wp_count_posts
        $items_count = 0;
        $collection_id = $collection->get_id();

        if ($collection_id) {
            $post_type = $collection->get_db_identifier();
            if ($post_type) {
                $counts = wp_count_posts($post_type);
                $items_count = isset($counts->publish) ? (int) $counts->publish : 0;
            }
        }

        $result[] = [
            'id' => $collection_id,
            'name' => $collection->get_name(),
            'description' => $collection->get_description(),
            'url' => $collection->get_url(),
            'items_count' => $items_count,
        ];
    }

    return $result;
}

/**
 * Obtém item do Tainacan por ID
 *
 * @param int $item_id
 * @return array|null
 */
function get_tainacan_item(int $item_id): ?array {
    if (!class_exists('\Tainacan\Repositories\Items')) {
        return null;
    }

    $repository = \Tainacan\Repositories\Items::get_instance();
    $item = $repository->fetch($item_id);

    if (!$item || !$item->get_id()) {
        return null;
    }

    return format_tainacan_item($item);
}

/**
 * Formata item do Tainacan para array
 *
 * @param \Tainacan\Entities\Item $item
 * @return array
 */
function format_tainacan_item(\Tainacan\Entities\Item $item): array {
    $metadata = [];
    $item_metadata = $item->get_metadata();

    foreach ($item_metadata as $meta) {
        $metadatum = $meta->get_metadatum();
        $value = $meta->get_value_as_string();

        if (!empty($value)) {
            $metadata[$metadatum->get_slug()] = [
                'name' => $metadatum->get_name(),
                'value' => $value,
            ];
        }
    }

    return [
        'id' => $item->get_id(),
        'title' => $item->get_title(),
        'description' => $item->get_description(),
        'url' => get_permalink($item->get_id()),
        'collection_id' => $item->get_collection_id(),
        'collection_name' => $item->get_collection()->get_name(),
        'thumbnail' => get_the_post_thumbnail_url($item->get_id(), 'medium'),
        'metadata' => $metadata,
        'created_at' => $item->get_creation_date(),
        'modified_at' => get_the_modified_date('Y-m-d H:i:s', $item->get_id()),
    ];
}

/**
 * Formata item para texto de indexação
 *
 * @param array $item
 * @param array $fields Campos para incluir
 * @return string
 */
function format_item_for_indexing(array $item, array $fields = ['title', 'description']): string {
    $parts = [];

    // Título
    if (in_array('title', $fields) && !empty($item['title'])) {
        $parts[] = 'TÍTULO: ' . $item['title'];
    }

    // Descrição
    if (in_array('description', $fields) && !empty($item['description'])) {
        $description = normalize_text($item['description']);
        $parts[] = 'DESCRIÇÃO: ' . $description;
    }

    // Metadados
    if (in_array('metadata', $fields) && !empty($item['metadata'])) {
        $meta_parts = [];
        foreach ($item['metadata'] as $slug => $meta) {
            if (!empty($meta['value'])) {
                $meta_parts[] = $meta['name'] . ': ' . $meta['value'];
            }
        }
        if (!empty($meta_parts)) {
            $parts[] = 'METADADOS: ' . implode(' | ', $meta_parts);
        }
    }

    // URL
    if (!empty($item['url'])) {
        $parts[] = 'URL: ' . $item['url'];
    }

    return implode("\n", $parts);
}

/**
 * Conta tokens aproximados em um texto
 *
 * @param string $text
 * @return int
 */
function estimate_tokens(string $text): int {
    // Estimativa: ~4 caracteres por token em média para português
    return (int) ceil(mb_strlen($text) / 4);
}

/**
 * Divide texto em chunks para processamento
 *
 * @param string $text
 * @param int $max_tokens
 * @param int $overlap
 * @return array
 */
function chunk_text(string $text, int $max_tokens = 1000, int $overlap = 100): array {
    $max_chars = $max_tokens * 4; // Aproximação
    $overlap_chars = $overlap * 4;

    if (mb_strlen($text) <= $max_chars) {
        return [$text];
    }

    $chunks = [];
    $start = 0;

    while ($start < mb_strlen($text)) {
        $chunk = mb_substr($text, $start, $max_chars);

        // Tenta quebrar em fim de frase
        if ($start + $max_chars < mb_strlen($text)) {
            $last_period = max(
                mb_strrpos($chunk, '.'),
                mb_strrpos($chunk, '!'),
                mb_strrpos($chunk, '?')
            );

            if ($last_period !== false && $last_period > $max_chars * 0.5) {
                $chunk = mb_substr($chunk, 0, $last_period + 1);
            }
        }

        $chunks[] = trim($chunk);
        $start += mb_strlen($chunk) - $overlap_chars;
    }

    return $chunks;
}

/**
 * Verifica se está em ambiente de desenvolvimento
 *
 * @return bool
 */
function is_development(): bool {
    return defined('WP_DEBUG') && WP_DEBUG;
}

/**
 * Verifica se está em ambiente de produção
 *
 * @return bool
 */
function is_production(): bool {
    return !is_development();
}

/**
 * Verifica se está em servidor XAMPP
 *
 * @return bool
 */
function is_xampp(): bool {
    $server_software = sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ?? '' ) );
    return stripos($server_software, 'apache') !== false &&
           (stripos(ABSPATH, 'xampp') !== false || stripos(ABSPATH, 'htdocs') !== false);
}

/**
 * Verifica se está em Hostinger
 *
 * @return bool
 */
function is_hostinger(): bool {
    $server_name = sanitize_text_field( wp_unslash( $_SERVER['SERVER_NAME'] ?? '' ) );
    return stripos($server_name, 'hostinger') !== false ||
           stripos(ABSPATH, 'hostinger') !== false;
}

/**
 * Obtém limites de memória e tempo baseados no ambiente
 *
 * @return array
 */
function get_environment_limits(): array {
    $memory_limit = wp_convert_hr_to_bytes(ini_get('memory_limit'));
    $time_limit = (int) ini_get('max_execution_time');

    // Ajustes por ambiente
    if (is_xampp()) {
        return [
            'memory_limit' => min($memory_limit, 256 * 1024 * 1024), // 256MB
            'time_limit' => min($time_limit ?: 30, 60),
            'batch_size' => 10,
        ];
    }

    if (is_hostinger()) {
        return [
            'memory_limit' => min($memory_limit, 512 * 1024 * 1024), // 512MB
            'time_limit' => min($time_limit ?: 30, 120),
            'batch_size' => 25,
        ];
    }

    // Servidor dedicado
    return [
        'memory_limit' => $memory_limit,
        'time_limit' => $time_limit ?: 300,
        'batch_size' => 50,
    ];
}

/**
 * Verifica uso de memória atual
 *
 * @return array
 */
function get_memory_usage(): array {
    $current = memory_get_usage(true);
    $peak = memory_get_peak_usage(true);
    $limit = wp_convert_hr_to_bytes(ini_get('memory_limit'));

    return [
        'current' => $current,
        'peak' => $peak,
        'limit' => $limit,
        'current_formatted' => format_bytes($current),
        'peak_formatted' => format_bytes($peak),
        'limit_formatted' => format_bytes($limit),
        'percentage' => round(($current / $limit) * 100, 2),
    ];
}

/**
 * Verifica se há memória suficiente para continuar
 *
 * @param int $threshold_percent
 * @return bool
 */
function has_sufficient_memory(int $threshold_percent = 85): bool {
    $usage = get_memory_usage();
    return $usage['percentage'] < $threshold_percent;
}

/**
 * Sanitiza opções de aparência
 *
 * @param array $appearance
 * @return array
 */
function sanitize_appearance(array $appearance): array {
    return [
        'primary_color' => sanitize_hex_color($appearance['primary_color'] ?? '#1f2f56') ?: '#1f2f56',
        'accent_color' => sanitize_hex_color($appearance['accent_color'] ?? '#b5e0e3') ?: '#b5e0e3',
        'chat_position' => in_array($appearance['chat_position'] ?? '', ['bottom-right', 'bottom-left', 'top-right', 'top-left'])
            ? $appearance['chat_position']
            : 'bottom-right',
        'show_sources' => (bool) ($appearance['show_sources'] ?? true),
        'show_similarity' => (bool) ($appearance['show_similarity'] ?? false),
    ];
}

/**
 * Gera snippet de texto ao redor de termos encontrados
 *
 * @param string $text
 * @param string $query
 * @param int $context_length
 * @return string
 */
function generate_snippet(string $text, string $query, int $context_length = 150): string {
    $text = normalize_text($text);
    $terms = preg_split('/\s+/', strtolower($query));
    $terms = array_filter($terms, fn($t) => mb_strlen($t) > 2);

    if (empty($terms)) {
        return truncate_text($text, $context_length * 2);
    }

    // Encontra a primeira ocorrência de qualquer termo
    $first_pos = mb_strlen($text);
    foreach ($terms as $term) {
        $pos = mb_stripos($text, $term);
        if ($pos !== false && $pos < $first_pos) {
            $first_pos = $pos;
        }
    }

    // Calcula início e fim do snippet
    $start = max(0, $first_pos - $context_length);
    $end = min(mb_strlen($text), $first_pos + $context_length * 2);

    // Ajusta para não cortar palavras
    if ($start > 0) {
        $space_pos = mb_strpos($text, ' ', $start);
        if ($space_pos !== false) {
            $start = $space_pos + 1;
        }
    }

    $snippet = mb_substr($text, $start, $end - $start);

    // Adiciona reticências se necessário
    $prefix = $start > 0 ? '...' : '';
    $suffix = $end < mb_strlen($text) ? '...' : '';

    // Destaca termos
    $snippet = highlight_terms($snippet, $query);

    return $prefix . $snippet . $suffix;
}

/**
 * Valida API key (formato básico)
 *
 * @param string $key
 * @param string $provider
 * @return bool
 */
function validate_api_key(string $key, string $provider = 'openai'): bool {
    if (empty($key)) {
        return false;
    }

    switch ($provider) {
        case 'openai':
            return preg_match('/^sk-[a-zA-Z0-9]{32,}$/', $key);
        case 'gemini':
            return preg_match('/^[a-zA-Z0-9_-]{39}$/', $key);
        case 'deepseek':
            return preg_match('/^sk-[a-zA-Z0-9]{32,}$/', $key);
        default:
            return strlen($key) >= 10;
    }
}

/**
 * Criptografa valor sensível
 *
 * @param string $value
 * @return string
 */
function encrypt_value(string $value): string {
    if (empty($value)) {
        return '';
    }

    $key = wp_salt('auth');
    $iv = substr(hash('sha256', wp_salt('secure_auth')), 0, 16);

    $encrypted = openssl_encrypt($value, 'AES-256-CBC', $key, 0, $iv);

    return base64_encode($encrypted);
}

/**
 * Descriptografa valor
 *
 * @param string $encrypted
 * @return string
 */
function decrypt_value(string $encrypted): string {
    if (empty($encrypted)) {
        return '';
    }

    $key = wp_salt('auth');
    $iv = substr(hash('sha256', wp_salt('secure_auth')), 0, 16);

    $decrypted = openssl_decrypt(base64_decode($encrypted), 'AES-256-CBC', $key, 0, $iv);

    return $decrypted ?: '';
}

/**
 * Flushes the plugin object-cache group to keep cached reads coherent after writes.
 *
 * Uses wp_cache_flush_group() when available (WP 6.1+); falls back to a no-op
 * because the group TTL will expire naturally.
 */
function oraculo_tainacan_flush_cache(): void {
    if ( function_exists( 'wp_cache_flush_group' ) ) {
        wp_cache_flush_group( 'oraculo_tainacan' );
    }
    // On older WP versions the group expires on its own TTL; no global flush needed.
}

/**
 * Renders a plugin template file in a local scope, preventing global variable pollution.
 *
 * Variables passed in $vars are extracted into the local scope of this function,
 * which is then inherited by the included template. No template variable leaks
 * into the global scope.
 *
 * @param string $template Relative path under templates/ (e.g. 'admin/dashboard.php').
 * @param array  $vars     Optional associative array of variables to expose inside the template.
 */
function oraculo_tainacan_render_template( string $template, array $vars = [] ): void {
    $template_path = ORACULO_TAINACAN_PATH . 'templates/' . ltrim( $template, '/' );
    if ( ! file_exists( $template_path ) ) {
        return;
    }
    // phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- Controlled scalar set, never user input; variables are local to this function's scope.
    extract( $vars, EXTR_SKIP );
    include $template_path;
}

/**
 * Obtém status de indexação de todas as coleções
 *
 * @return array
 */
function get_collections_indexing_status(): array {
    global $wpdb;

    $vectors_table = $wpdb->prefix . 'oraculo_vectors';
    $jobs_table = $wpdb->prefix . 'oraculo_indexing_jobs';

    $collections = get_tainacan_collections();
    $status = [];

    // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- $vectors_table and $jobs_table are plugin-owned tables built from $wpdb->prefix + literal (oraculo_vectors, oraculo_indexing_jobs); table identifiers cannot be parameterized.
    foreach ($collections as $collection) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned table; per-collection index count for status display.
        $indexed_count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$vectors_table} WHERE collection_id = %d",
            $collection['id']
        ));

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned table; latest indexing job per collection.
        $job = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$jobs_table} WHERE collection_id = %d ORDER BY id DESC LIMIT 1",
            $collection['id']
        ), ARRAY_A);

        $percentage = $collection['items_count'] > 0
            ? round(($indexed_count / $collection['items_count']) * 100, 1)
            : 0;

        // Determinar status real baseado nos dados
        $job_status = $job['status'] ?? 'none';

        // Se há itens indexados e o percentual é >= 95%, considerar como completed
        if ($indexed_count > 0 && $percentage >= 95) {
            $display_status = 'completed';
        }
        // Se o job está em andamento (running ou processing)
        elseif (in_array($job_status, ['running', 'processing'])) {
            $display_status = 'running';
        }
        // Se há itens parcialmente indexados
        elseif ($indexed_count > 0 && $percentage < 95) {
            $display_status = 'partial';
        }
        // Se o job está pendente
        elseif ($job_status === 'pending') {
            $display_status = 'pending';
        }
        // Se nunca foi indexado
        else {
            $display_status = 'none';
        }

        $status[] = [
            'collection_id' => $collection['id'],
            'collection_name' => $collection['name'],
            'total_items' => $collection['items_count'],
            'indexed_items' => $indexed_count,
            'percentage' => $percentage,
            'job_status' => $display_status,
            'last_indexed' => $job['completed_at'] ?? null,
        ];
    }
    // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter

    return $status;
}
