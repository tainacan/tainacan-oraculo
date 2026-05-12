<?php
/**
 * Provedor OpenAI genérico (compatível com APIs OpenAI-style)
 *
 * @package Oraculo_Tainacan
 */

namespace Oraculo_Tainacan\AI\Providers;

use Oraculo_Tainacan\AI\AbstractAIProvider;
use WP_Error;

/**
 * Implementação de provedor OpenAI genérico com endpoint configurável
 */
class GenericOpenAIProvider extends AbstractAIProvider {

    /**
     * Prefixo de cache para modelos remotos
     */
    private const MODELS_CACHE_PREFIX = 'oraculo_generic_openai_models_v2_';

    /**
     * Tempo de cache dos modelos em segundos
     */
    private const MODELS_CACHE_TTL = 600;

    /**
     * {@inheritdoc}
     */
    public function get_id(): string {
        return 'generic_openai';
    }

    /**
     * {@inheritdoc}
     */
    public function get_name(): string {
        return 'OpenAI Compatível (Genérico)';
    }

    /**
     * {@inheritdoc}
     */
    public function get_description(): string {
        return __('Use qualquer provedor compatível com a API OpenAI, com URL base customizada e descoberta dinâmica de modelos.', 'oraculo_tainacan');
    }

    /**
     * {@inheritdoc}
     */
    public function get_available_models(): array {
        $models = $this->get_remote_models();
        $chat_models = [];

        foreach ($models as $model) {
            $model_id = $this->extract_model_id($model);
            if ($model_id === '' || $this->is_embedding_model($model, $model_id)) {
                continue;
            }

            $chat_models[] = $this->normalize_model($model, $model_id);
        }

        return $chat_models;
    }

    /**
     * {@inheritdoc}
     */
    public function get_embedding_models(): array {
        $models = $this->get_remote_models();
        $embedding_models = [];

        foreach ($models as $model) {
            $model_id = $this->extract_model_id($model);
            if ($model_id === '' || !$this->is_embedding_model($model, $model_id)) {
                continue;
            }

            $embedding_models[] = $this->normalize_model($model, $model_id);
        }

        return $embedding_models;
    }

    /**
     * {@inheritdoc}
     */
    public function supports_embeddings(): bool {
        if (parent::supports_embeddings()) {
            return true;
        }

        return trim((string) $this->get_config('embedding_model', '')) !== '';
    }

    /**
     * {@inheritdoc}
     */
    public function is_configured(): bool {
        return $this->get_api_base_url() !== '';
    }

    /**
     * {@inheritdoc}
     */
    public function test_connection(): array {
        if (!$this->is_configured()) {
            return [
                'success' => false,
                'message' => __('URL base da API não configurada.', 'oraculo_tainacan'),
                'details' => [],
            ];
        }

        $response = $this->request_models_payload();

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'message' => $response->get_error_message(),
                'details' => [],
            ];
        }

        $models = $this->extract_models_payload($response);
        $cache_key = $this->get_models_cache_key();
        set_transient($cache_key, $models, self::MODELS_CACHE_TTL);

        return [
            'success' => true,
            'message' => __('Conexão estabelecida com sucesso!', 'oraculo_tainacan'),
            'details' => [
                'models_available' => count($models),
                'api_base_url' => $this->get_api_base_url(),
            ],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function generate_embedding(string $text, ?string $model = null) {
        if (!$this->is_configured()) {
            return new WP_Error('not_configured', __('Provedor Generic OpenAI não configurado.', 'oraculo_tainacan'));
        }

        $text = $this->validate_input($text, 8191 * 4);
        if ($text === '') {
            return new WP_Error('empty_input', __('Texto não pode estar vazio.', 'oraculo_tainacan'));
        }

        $model = $this->resolve_embedding_model($model);
        if ($model === '') {
            return new WP_Error('missing_embedding_model', __('Nenhum modelo de embedding configurado para o provedor Generic OpenAI.', 'oraculo_tainacan'));
        }

        $result = $this->request_embeddings_with_openwebui_fallback($text, $model);
        if (is_wp_error($result)) {
            return $result;
        }

        if (empty($result['vectors'][0])) {
            return new WP_Error('invalid_response', __('Resposta inválida da API de embeddings.', 'oraculo_tainacan'));
        }

        return [
            'embedding' => $result['vectors'][0],
            'model' => $model,
            'usage' => $result['usage'],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function generate_embeddings_batch(array $texts, ?string $model = null) {
        if (!$this->is_configured()) {
            return new WP_Error('not_configured', __('Provedor Generic OpenAI não configurado.', 'oraculo_tainacan'));
        }

        if (empty($texts)) {
            return new WP_Error('empty_input', __('Lista de textos não pode estar vazia.', 'oraculo_tainacan'));
        }

        $clean_texts = [];
        foreach ($texts as $text) {
            $clean = $this->validate_input((string) $text, 8191 * 4);
            if ($clean !== '') {
                $clean_texts[] = $clean;
            }
        }

        if (empty($clean_texts)) {
            return new WP_Error('empty_input', __('Nenhum texto válido foi informado para embeddings.', 'oraculo_tainacan'));
        }

        $model = $this->resolve_embedding_model($model);
        if ($model === '') {
            return new WP_Error('missing_embedding_model', __('Nenhum modelo de embedding configurado para o provedor Generic OpenAI.', 'oraculo_tainacan'));
        }

        $result = $this->request_embeddings_with_openwebui_fallback($clean_texts, $model);
        if (is_wp_error($result)) {
            return $result;
        }

        if (empty($result['vectors'])) {
            return new WP_Error('invalid_response', __('Resposta inválida da API de embeddings.', 'oraculo_tainacan'));
        }

        return [
            'embeddings' => $result['vectors'],
            'model' => $model,
            'usage' => $result['usage'],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function generate_response(string $prompt, string $system_prompt = '', array $options = []) {
        return $this->chat([['role' => 'user', 'content' => $prompt]], $system_prompt, $options);
    }

    /**
     * {@inheritdoc}
     */
    public function chat(array $messages, string $system_prompt = '', array $options = []) {
        if (!$this->is_configured()) {
            return new WP_Error('not_configured', __('Provedor Generic OpenAI não configurado.', 'oraculo_tainacan'));
        }

        $options = $this->prepare_options($options);
        $model = $options['model'] ?? $this->get_config('model', '');
        if ($model === '') {
            return new WP_Error('missing_model', __('Nenhum modelo de chat configurado para o provedor Generic OpenAI.', 'oraculo_tainacan'));
        }

        $response = $this->make_request_with_retry(
            $this->get_api_base_url() . '/chat/completions',
            [
                'model' => $model,
                'messages' => $this->format_messages($messages, $system_prompt),
                'max_tokens' => $options['max_tokens'],
                'temperature' => $options['temperature'],
            ],
            $this->get_headers()
        );

        if (is_wp_error($response)) {
            return $response;
        }

        if (!isset($response['choices'][0]['message']['content'])) {
            return new WP_Error('invalid_response', __('Resposta inválida da API.', 'oraculo_tainacan'));
        }

        $usage = $this->normalize_usage($response['usage'] ?? []);

        return [
            'response' => $response['choices'][0]['message']['content'],
            'model' => $model,
            'usage' => $usage,
            'cost' => 0,
            'finish_reason' => $response['choices'][0]['finish_reason'] ?? 'stop',
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function supports_streaming(): bool {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function stream_response(string $prompt, string $system_prompt, callable $callback, array $options = []): void {
        if (!$this->is_configured()) {
            $callback('', true, new WP_Error('not_configured', __('Provedor não configurado.', 'oraculo_tainacan')));
            return;
        }

        $options = $this->prepare_options($options);
        $model = $options['model'] ?? $this->get_config('model', '');
        if ($model === '') {
            $callback('', true, new WP_Error('missing_model', __('Nenhum modelo de chat configurado para o provedor Generic OpenAI.', 'oraculo_tainacan')));
            return;
        }

        $body = [
            'model' => $model,
            'messages' => $this->format_messages([['role' => 'user', 'content' => $prompt]], $system_prompt),
            'max_tokens' => $options['max_tokens'],
            'temperature' => $options['temperature'],
            'stream' => true,
        ];

        // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_init -- WP HTTP API lacks streaming callback support required for SSE.
        $ch = curl_init($this->get_api_base_url() . '/chat/completions');
        $headers = ['Content-Type: application/json'];
        foreach ($this->get_headers() as $name => $value) {
            $headers[] = $name . ': ' . $value;
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt_array -- WP HTTP API lacks streaming callback support required for SSE.
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => wp_json_encode($body),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_WRITEFUNCTION => function($ch, $data) use ($callback) {
                $lines = explode("\n", (string) $data);
                foreach ($lines as $line) {
                    if (strpos($line, 'data: ') !== 0) {
                        continue;
                    }

                    $json = substr($line, 6);
                    if ($json === '[DONE]') {
                        $callback('', true);
                        return strlen($data);
                    }

                    $decoded = json_decode($json, true);
                    if (isset($decoded['choices'][0]['delta']['content'])) {
                        $callback($decoded['choices'][0]['delta']['content'], false);
                    }
                }

                return strlen($data);
            },
            CURLOPT_TIMEOUT => $this->timeout,
        ]);

        // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_exec -- WP HTTP API lacks streaming callback support required for SSE.
        curl_exec($ch);

        // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_errno -- WP HTTP API lacks streaming callback support required for SSE.
        if (curl_errno($ch)) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_error -- WP HTTP API lacks streaming callback support required for SSE.
            $callback('', true, new WP_Error('curl_error', curl_error($ch)));
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_close -- WP HTTP API lacks streaming callback support required for SSE.
        curl_close($ch);
    }

    /**
     * {@inheritdoc}
     */
    public function get_pricing(string $model): array {
        return [];
    }

    /**
     * {@inheritdoc}
     */
    public function get_model_limit(string $model): int {
        return 131072;
    }

    /**
     * Obtém URL base da API
     *
     * @return string
     */
    private function get_api_base_url(): string {
        return rtrim(trim((string) $this->get_config('api_base_url', '')), '/');
    }

    /**
     * Obtém headers para requisições
     *
     * @return array
     */
    private function get_headers(): array {
        $headers = [];
        $api_key = trim((string) $this->get_api_key());
        if ($api_key !== '') {
            $headers['Authorization'] = 'Bearer ' . $api_key;
        }

        return $headers;
    }

    /**
     * Resolve modelo de embedding efetivo
     *
     * @param string|null $requested_model
     * @return string
     */
    private function resolve_embedding_model(?string $requested_model): string {
        if (is_string($requested_model) && trim($requested_model) !== '') {
            return trim($requested_model);
        }

        $configured = trim((string) $this->get_config('embedding_model', ''));
        if ($configured !== '') {
            return $configured;
        }

        $models = $this->get_embedding_models();
        return $models[0]['id'] ?? '';
    }

    /**
     * Obtém modelos remotos com cache
     *
     * @return array
     */
    private function get_remote_models(): array {
        if (!$this->is_configured()) {
            return [];
        }

        $cache_key = $this->get_models_cache_key();
        $cached = get_transient($cache_key);
        if ($cached !== false && is_array($cached)) {
            return $cached;
        }

        $response = $this->request_models_payload();
        if (is_wp_error($response)) {
            return [];
        }

        $models = $this->extract_models_payload($response);
        set_transient($cache_key, $models, self::MODELS_CACHE_TTL);

        return $models;
    }

    /**
     * Requisição de listagem de modelos
     *
     * @return array|WP_Error
     */
    private function request_models_payload() {
        return $this->make_request(
            $this->get_api_base_url() . '/models',
            [],
            $this->get_headers(),
            'GET'
        );
    }

    /**
     * Extrai lista de modelos de diferentes formatos de payload
     *
     * @param array $response
     * @return array
     */
    private function extract_models_payload(array $response): array {
        if (isset($response['data']) && is_array($response['data'])) {
            return $response['data'];
        }

        if (isset($response['items']) && is_array($response['items'])) {
            return $response['items'];
        }

        if (isset($response['models']) && is_array($response['models'])) {
            return $response['models'];
        }

        return [];
    }

    /**
     * Normaliza metadados de um modelo
     *
     * @param array $model
     * @param string $model_id
     * @return array
     */
    private function normalize_model(array $model, string $model_id): array {
        return [
            'id' => $model_id,
            'name' => sanitize_text_field((string) ($model['name'] ?? $model_id)),
            'description' => sanitize_text_field((string) ($model['description'] ?? __('Modelo remoto', 'oraculo_tainacan'))),
        ];
    }

    /**
     * Extrai ID do modelo suportando diferentes payloads
     *
     * @param array $model
     * @return string
     */
    private function extract_model_id(array $model): string {
        $candidates = [
            $model['id'] ?? '',
            $model['model'] ?? '',
            $model['base_model_id'] ?? '',
        ];

        foreach ($candidates as $candidate) {
            $id = trim((string) $candidate);
            if ($id !== '') {
                return $id;
            }
        }

        return '';
    }

    /**
     * Verifica se um modelo é de embedding
     *
     * @param array $model
     * @param string $model_id
     * @return bool
     */
    private function is_embedding_model(array $model, string $model_id): bool {
        $type = strtolower((string) ($model['type'] ?? ''));
        if ($type === 'embedding') {
            return true;
        }

        $capabilities = $model['meta']['capabilities'] ?? $model['capabilities'] ?? [];
        if (is_array($capabilities)) {
            if (!empty($capabilities['embeddings']) || !empty($capabilities['embedding'])) {
                return true;
            }
            if (in_array('embedding', $capabilities, true) || in_array('embeddings', $capabilities, true)) {
                return true;
            }
        }

        $model_id = strtolower($model_id);
        $patterns = [
            'embedding',
            'embed-',
            'text-embedding',
            'nomic-embed',
            'mxbai-embed',
            'bge-',
            'all-minilm',
        ];

        foreach ($patterns as $pattern) {
            if (strpos($model_id, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Gera chave de cache para lista de modelos
     *
     * @return string
     */
    private function get_models_cache_key(): string {
        return self::MODELS_CACHE_PREFIX . md5($this->get_api_base_url());
    }

    /**
     * Requisição de embeddings com fallback para Open WebUI + Ollama
     *
     * @param string|array $input
     * @param string $model
     * @return array|WP_Error
     */
    private function request_embeddings_with_openwebui_fallback($input, string $model) {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug log gated by WP_DEBUG.
            error_log('[Oraculo GenericOpenAI] Embedding request -> ' . $this->get_api_base_url() . '/embeddings | model=' . $model . ' | input_type=' . (is_array($input) ? 'batch' : 'single'));
        }

        $response = $this->make_request_with_retry(
            $this->get_api_base_url() . '/embeddings',
            [
                'model' => $model,
                'input' => $input,
            ],
            $this->get_headers()
        );

        $vectors = [];
        $usage = [];

        if (!is_wp_error($response)) {
            $vectors = $this->extract_vectors_from_embedding_response($response);
            $usage = $this->normalize_usage($response['usage'] ?? []);
        }

        if (!is_wp_error($response) && !empty($vectors)) {
            return [
                'vectors' => $vectors,
                'usage' => $usage,
            ];
        }

        $should_try_fallback = false;
        if (is_wp_error($response)) {
            $error_data = $response->get_error_data();
            $status = is_array($error_data) ? (int) ($error_data['status'] ?? 0) : 0;
            $should_try_fallback = $status === 404;
        } elseif (empty($vectors)) {
            $should_try_fallback = true;
        }

        if (!$should_try_fallback || !$this->looks_like_openwebui_api()) {
            return is_wp_error($response)
                ? $response
                : new WP_Error('invalid_response', __('Resposta inválida da API de embeddings.', 'oraculo_tainacan'));
        }

        $fallback_response = $this->make_request_with_retry(
            $this->get_openwebui_origin() . '/ollama/api/embed',
            [
                'model' => $model,
                'input' => $input,
            ],
            $this->get_headers()
        );

        if (is_wp_error($fallback_response)) {
            return $fallback_response;
        }

        $fallback_vectors = $this->extract_vectors_from_embedding_response($fallback_response);
        if (empty($fallback_vectors)) {
            return new WP_Error('invalid_response', __('Resposta inválida da API de embeddings.', 'oraculo_tainacan'));
        }

        return [
            'vectors' => $fallback_vectors,
            'usage' => [],
        ];
    }

    /**
     * Extrai vetores de formatos OpenAI e Ollama
     *
     * @param array $response
     * @return array
     */
    private function extract_vectors_from_embedding_response(array $response): array {
        $vectors = [];

        if (isset($response['data']) && is_array($response['data'])) {
            foreach ($response['data'] as $item) {
                if (!empty($item['embedding']) && is_array($item['embedding'])) {
                    $vectors[] = $item['embedding'];
                }
            }
        }

        if (isset($response['embeddings']) && is_array($response['embeddings'])) {
            foreach ($response['embeddings'] as $embedding) {
                if (is_array($embedding)) {
                    $vectors[] = $embedding;
                }
            }
        }

        if (isset($response['embedding']) && is_array($response['embedding'])) {
            $vectors[] = $response['embedding'];
        }

        return $vectors;
    }

    /**
     * Detecta se a URL base parece endpoint /api do Open WebUI
     *
     * @return bool
     */
    private function looks_like_openwebui_api(): bool {
        return (bool) preg_match('#/api/?$#', $this->get_api_base_url());
    }

    /**
     * Obtém origem sem sufixo /api para fallback Ollama do Open WebUI
     *
     * @return string
     */
    private function get_openwebui_origin(): string {
        return rtrim(preg_replace('#/api/?$#', '', $this->get_api_base_url()) ?? $this->get_api_base_url(), '/');
    }
}
