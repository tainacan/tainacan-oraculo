<?php
/**
 * Classe abstrata base para provedores de IA
 *
 * @package Oraculo_Tainacan
 */

namespace Oraculo_Tainacan\AI;

use Oraculo_Tainacan\Oraculo_Tainacan;
use WP_Error;

/**
 * Classe abstrata que implementa funcionalidades comuns a todos os provedores
 */
abstract class AbstractAIProvider implements AIProviderInterface {

    /**
     * Configurações do provedor
     * @var array
     */
    protected array $config = [];

    /**
     * Último erro ocorrido
     * @var string|null
     */
    protected ?string $last_error = null;

    /**
     * Timeout padrão para requisições (segundos)
     * @var int
     */
    protected int $timeout = 120;

    /**
     * Cache de respostas
     * @var array
     */
    protected array $response_cache = [];

    /**
     * Construtor
     *
     * @param array $config
     */
    public function __construct(array $config = []) {
        $this->configure($config);
    }

    /**
     * {@inheritdoc}
     */
    public function configure(array $options): void {
        $this->config = array_merge($this->config, $options);

        if (isset($options['timeout'])) {
            $this->timeout = (int) $options['timeout'];
        }
    }

    /**
     * {@inheritdoc}
     */
    public function get_last_error(): ?string {
        return $this->last_error;
    }

    /**
     * Define o último erro
     *
     * @param string $error
     */
    protected function set_error(string $error): void {
        $this->last_error = $error;
        \Oraculo_Tainacan\debug_log('AI Provider Error: ' . $error, null, 'error');
    }

    /**
     * Limpa o último erro
     */
    protected function clear_error(): void {
        $this->last_error = null;
    }

    /**
     * Faz requisição HTTP para a API
     *
     * @param string $url
     * @param array $body
     * @param array $headers
     * @param string $method
     * @return array|WP_Error
     */
    protected function make_request(string $url, array $body = [], array $headers = [], string $method = 'POST') {
        $this->clear_error();

        $args = [
            'method' => $method,
            'timeout' => $this->timeout,
            'headers' => array_merge([
                'Content-Type' => 'application/json',
            ], $headers),
        ];

        if (!empty($body) && $method !== 'GET') {
            $args['body'] = wp_json_encode($body);
        }

        \Oraculo_Tainacan\debug_log('API Request', [
            'url' => $url,
            'method' => $method,
            'body_size' => isset($args['body']) ? strlen($args['body']) : 0,
        ]);

        $start_time = microtime(true);
        $response = wp_remote_request($url, $args);
        $duration = round((microtime(true) - $start_time) * 1000);

        \Oraculo_Tainacan\debug_log('API Response', [
            'duration_ms' => $duration,
            'is_error' => is_wp_error($response),
        ]);

        if (is_wp_error($response)) {
            $this->set_error($response->get_error_message());
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if ($code >= 400) {
            $error_message = $this->parse_error_response($data, $code);

            // Adicionar detalhes extras se disponíveis
            if ($data && isset($data['error'])) {
                $details = [];
                if (isset($data['error']['type'])) {
                    $details[] = 'Tipo: ' . $data['error']['type'];
                }
                if (isset($data['error']['code'])) {
                    $details[] = 'Código: ' . $data['error']['code'];
                }
                if (!empty($details)) {
                    $error_message .= ' (' . implode(', ', $details) . ')';
                }
            }

            $this->set_error($error_message);
            return new WP_Error('api_error', $error_message, ['status' => $code, 'body' => $data]);
        }

        return $data;
    }

    /**
     * Faz requisição com retry automático
     *
     * @param string $url
     * @param array $body
     * @param array $headers
     * @param int $max_retries
     * @return array|WP_Error
     */
    protected function make_request_with_retry(string $url, array $body = [], array $headers = [], int $max_retries = 3) {
        $attempt = 0;
        $last_error = null;

        while ($attempt < $max_retries) {
            $response = $this->make_request($url, $body, $headers);

            if (!is_wp_error($response)) {
                return $response;
            }

            $last_error = $response;
            $error_data = $response->get_error_data();

            // Não retry para erros de autenticação ou requisição inválida
            if (isset($error_data['status']) && in_array($error_data['status'], [401, 403, 400, 404])) {
                return $response;
            }

            $attempt++;

            // Rate limit - aguardar mais tempo
            if (isset($error_data['status']) && $error_data['status'] === 429) {
                $wait_time = pow(2, $attempt) * 5; // 10s, 20s, 40s
            } else {
                $wait_time = pow(2, $attempt); // 2s, 4s, 8s
            }

            \Oraculo_Tainacan\debug_log("Retry attempt {$attempt} after {$wait_time}s", null, 'warning');
            sleep($wait_time);
        }

        return $last_error;
    }

    /**
     * Analisa resposta de erro da API
     *
     * @param array|null $data
     * @param int $code
     * @return string
     */
    protected function parse_error_response(?array $data, int $code): string {
        if ($data === null) {
            return $this->get_http_error_message($code);
        }

        // Formato OpenAI
        if (isset($data['error']['message'])) {
            return $data['error']['message'];
        }

        // Formato Gemini
        if (isset($data['error']['status'])) {
            return $data['error']['message'] ?? $data['error']['status'];
        }

        // Outros formatos
        if (isset($data['message'])) {
            return $data['message'];
        }

        return $this->get_http_error_message($code);
    }

    /**
     * Obtém mensagem de erro HTTP padrão
     *
     * @param int $code
     * @return string
     */
    protected function get_http_error_message(int $code): string {
        $messages = [
            400 => __('Requisição inválida. Verifique os parâmetros.', 'oraculo_tainacan'),
            401 => __('Não autorizado. Verifique sua chave de API.', 'oraculo_tainacan'),
            403 => __('Acesso negado. Sua chave de API não tem permissão.', 'oraculo_tainacan'),
            404 => __('Recurso não encontrado.', 'oraculo_tainacan'),
            429 => __('Limite de requisições excedido. Aguarde e tente novamente.', 'oraculo_tainacan'),
            500 => __('Erro interno do servidor da API.', 'oraculo_tainacan'),
            502 => __('Gateway inválido. Servidor da API indisponível.', 'oraculo_tainacan'),
            503 => __('Serviço temporariamente indisponível.', 'oraculo_tainacan'),
        ];

        /* translators: %d: HTTP error status code */
        return $messages[$code] ?? sprintf(__('Erro HTTP %d', 'oraculo_tainacan'), $code);
    }

    /**
     * Valida texto de entrada
     *
     * @param string $text
     * @param int $max_length
     * @return string
     */
    protected function validate_input(string $text, int $max_length = 100000): string {
        $text = trim($text);

        if (empty($text)) {
            return '';
        }

        // Limitar tamanho
        if (mb_strlen($text) > $max_length) {
            $text = mb_substr($text, 0, $max_length);
        }

        return $text;
    }

    /**
     * Formata mensagens para o formato da API
     *
     * @param array $messages
     * @param string $system_prompt
     * @return array
     */
    protected function format_messages(array $messages, string $system_prompt = ''): array {
        $formatted = [];

        // Adicionar prompt do sistema se fornecido
        if (!empty($system_prompt)) {
            $formatted[] = [
                'role' => 'system',
                'content' => $system_prompt,
            ];
        }

        // Adicionar mensagens
        foreach ($messages as $message) {
            $role = $message['role'] ?? 'user';
            $content = $message['content'] ?? '';

            if (!empty($content)) {
                $formatted[] = [
                    'role' => in_array($role, ['user', 'assistant', 'system']) ? $role : 'user',
                    'content' => $content,
                ];
            }
        }

        return $formatted;
    }

    /**
     * Obtém configuração com valor padrão
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    protected function get_config(string $key, $default = null) {
        return $this->config[$key] ?? $default;
    }

    /**
     * Verifica se uma chave de API está configurada
     *
     * @param string $key_name
     * @return bool
     */
    protected function has_api_key(string $key_name = 'api_key'): bool {
        return !empty($this->config[$key_name]);
    }

    /**
     * Obtém chave de API descriptografada
     *
     * @param string $key_name
     * @return string
     */
    protected function get_api_key(string $key_name = 'api_key'): string {
        $key = $this->config[$key_name] ?? '';

        // Verificar se está criptografada (base64 + prefixo específico)
        if (strpos($key, 'enc:') === 0) {
            return \Oraculo_Tainacan\decrypt_value(substr($key, 4));
        }

        return $key;
    }

    /**
     * Gera cache key para resposta
     *
     * @param string $type
     * @param string $input
     * @param array $options
     * @return string
     */
    protected function get_cache_key(string $type, string $input, array $options = []): string {
        $data = [
            'provider' => $this->get_id(),
            'type' => $type,
            'input' => $input,
            'options' => $options,
        ];

        return 'oraculo_' . md5(wp_json_encode($data));
    }

    /**
     * Obtém resposta do cache
     *
     * @param string $cache_key
     * @return mixed|null
     */
    protected function get_cached_response(string $cache_key) {
        // Cache em memória primeiro
        if (isset($this->response_cache[$cache_key])) {
            return $this->response_cache[$cache_key];
        }

        // Cache transient
        return get_transient($cache_key);
    }

    /**
     * Salva resposta no cache
     *
     * @param string $cache_key
     * @param mixed $response
     * @param int $expiration
     */
    protected function set_cached_response(string $cache_key, $response, int $expiration = 3600): void {
        // Cache em memória
        $this->response_cache[$cache_key] = $response;

        // Cache transient
        set_transient($cache_key, $response, $expiration);
    }

    /**
     * {@inheritdoc}
     */
    public function supports_embeddings(): bool {
        return !empty($this->get_embedding_models());
    }

    /**
     * {@inheritdoc}
     */
    public function supports_streaming(): bool {
        return false; // Subclasses podem sobrescrever
    }

    /**
     * {@inheritdoc}
     */
    public function stream_response(string $prompt, string $system_prompt, callable $callback, array $options = []): void {
        // Implementação padrão: fallback para resposta não-streaming
        $response = $this->generate_response($prompt, $system_prompt, $options);

        if (!is_wp_error($response)) {
            $callback($response['response'], true);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function calculate_cost(array $usage, string $model): float {
        $pricing = $this->get_pricing($model);

        if (empty($pricing)) {
            return 0.0;
        }

        $input_tokens = $usage['prompt_tokens'] ?? 0;
        $output_tokens = $usage['completion_tokens'] ?? 0;

        // Preços são por 1000 tokens
        $input_cost = ($input_tokens / 1000) * $pricing['input'];
        $output_cost = ($output_tokens / 1000) * $pricing['output'];

        return round($input_cost + $output_cost, 6);
    }

    /**
     * Prepara opções padrão para requisição
     *
     * @param array $options
     * @return array
     */
    protected function prepare_options(array $options): array {
        $plugin_options = \Oraculo_Tainacan\Oraculo_Tainacan::get_options();
        $configured_model = trim((string) $this->get_config('model', ''));
        $first_listed_model = $this->get_available_models()[0]['id'] ?? '';
        $default_model = $configured_model !== '' ? $configured_model : $first_listed_model;

        return array_merge([
            'model' => $default_model,
            'max_tokens' => $plugin_options['max_tokens'] ?? 2000,
            'temperature' => $plugin_options['temperature'] ?? 0.7,
        ], $options);
    }

    /**
     * Normaliza resposta de uso de tokens
     *
     * @param array $usage
     * @return array
     */
    protected function normalize_usage(array $usage): array {
        return [
            'prompt_tokens' => $usage['prompt_tokens'] ?? $usage['input_tokens'] ?? 0,
            'completion_tokens' => $usage['completion_tokens'] ?? $usage['output_tokens'] ?? 0,
            'total_tokens' => $usage['total_tokens'] ?? (
                ($usage['prompt_tokens'] ?? $usage['input_tokens'] ?? 0) +
                ($usage['completion_tokens'] ?? $usage['output_tokens'] ?? 0)
            ),
        ];
    }
}
