<?php
/**
 * Factory para criação de provedores de IA
 *
 * @package Oraculo_Tainacan
 */

namespace Oraculo_Tainacan\AI;

use Oraculo_Tainacan\AI\Providers\OpenAIProvider;
use Oraculo_Tainacan\AI\Providers\GeminiProvider;
use Oraculo_Tainacan\AI\Providers\DeepSeekProvider;
use Oraculo_Tainacan\AI\Providers\OllamaProvider;
use Oraculo_Tainacan\AI\Providers\GroqProvider;
use Oraculo_Tainacan\AI\Providers\ClaudeProvider;
use Oraculo_Tainacan\AI\Providers\GenericOpenAIProvider;

/**
 * Factory para instanciar provedores de IA
 */
class AIProviderFactory {

    /**
     * Mapa de provedores registrados
     * @var array
     */
    private static array $providers = [
        'openai' => OpenAIProvider::class,
        'gemini' => GeminiProvider::class,
        'deepseek' => DeepSeekProvider::class,
        'ollama' => OllamaProvider::class,
        'groq' => GroqProvider::class,
        'claude' => ClaudeProvider::class,
        'generic_openai' => GenericOpenAIProvider::class,
    ];

    /**
     * Cache de instâncias
     * @var array
     */
    private static array $instances = [];

    /**
     * Cria instância de provedor
     *
     * @param string $provider_id
     * @param array $config Configuração específica (opcional)
     * @return AIProviderInterface
     * @throws \InvalidArgumentException
     */
    public function create(string $provider_id, array $config = []): AIProviderInterface {
        if (!isset(self::$providers[$provider_id])) {
            throw new \InvalidArgumentException(
                /* translators: %s: AI provider identifier */
                esc_html( sprintf( __( 'Provedor de IA "%s" não encontrado.', 'oraculo_tainacan' ), $provider_id ) )
            );
        }

        // Se não há configuração específica, usar cache
        if (empty($config)) {
            if (!isset(self::$instances[$provider_id])) {
                $options = $this->get_provider_options($provider_id);
                self::$instances[$provider_id] = new self::$providers[$provider_id]($options);
            }
            return self::$instances[$provider_id];
        }

        // Criar nova instância com configuração específica
        return new self::$providers[$provider_id]($config);
    }

    /**
     * Cria provedor baseado nas opções do plugin
     *
     * @return AIProviderInterface
     * @throws \InvalidArgumentException
     */
    public function create_from_options(): AIProviderInterface {
        $options = \Oraculo_Tainacan\Oraculo_Tainacan::get_options();
        $provider_id = $options['ai_provider'] ?? 'openai';

        return $this->create($provider_id);
    }

    /**
     * Cria provedor para embeddings
     *
     * @return AIProviderInterface
     * @throws \RuntimeException
     */
    public function create_for_embeddings(): AIProviderInterface {
        $options = \Oraculo_Tainacan\Oraculo_Tainacan::get_options();
        $provider_id = $options['ai_provider'] ?? 'openai';

        $current_provider = $this->create($provider_id);
        if ($current_provider->supports_embeddings()) {
            return $current_provider;
        }

        // Fallback para OpenAI
        if (!empty($options['openai_api_key'])) {
            return $this->create('openai');
        }

        // Fallback para Ollama local
        if (!empty($options['ollama_url'])) {
            return $this->create('ollama');
        }

        throw new \RuntimeException(
            esc_html__( 'Nenhum provedor configurado suporta geração de embeddings. Configure OpenAI, Ollama ou um provedor compatível.', 'oraculo_tainacan' )
        );
    }

    /**
     * Obtém opções de configuração para um provedor
     *
     * @param string $provider_id
     * @return array
     */
    private function get_provider_options(string $provider_id): array {
        $options = \Oraculo_Tainacan\Oraculo_Tainacan::get_options();

        $common = [
            'timeout' => $options['request_timeout'] ?? 120,
            'max_tokens' => $options['max_tokens'] ?? 2000,
            'temperature' => $options['temperature'] ?? 0.7,
        ];

        switch ($provider_id) {
            case 'openai':
                $api_key = $options['openai_api_key'] ?? '';
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug log gated by WP_DEBUG.
                    error_log('[Oraculo Factory] Getting OpenAI options - api_key exists: ' . (!empty($api_key) ? 'yes' : 'no') . ', length: ' . strlen($api_key));
                }
                return array_merge($common, [
                    'api_key' => $api_key,
                    'model' => $options['openai_model'] ?? 'gpt-4o-mini',
                    'embedding_model' => $options['openai_embedding_model'] ?? 'text-embedding-ada-002',
                ]);

            case 'gemini':
                return array_merge($common, [
                    'api_key' => $options['gemini_api_key'] ?? '',
                    'model' => $options['gemini_model'] ?? 'gemini-1.5-pro',
                ]);

            case 'deepseek':
                return array_merge($common, [
                    'api_key' => $options['deepseek_api_key'] ?? '',
                    'model' => $options['deepseek_model'] ?? 'deepseek-chat',
                ]);

            case 'ollama':
                return array_merge($common, [
                    'base_url' => $options['ollama_url'] ?? 'http://localhost:11434',
                    'model' => $options['ollama_model'] ?? 'llama3.2',
                    'embedding_model' => $options['ollama_embedding_model'] ?? 'nomic-embed-text',
                ]);

            case 'groq':
                return array_merge($common, [
                    'api_key' => $options['groq_api_key'] ?? '',
                    'model' => $options['groq_model'] ?? 'llama-3.3-70b-versatile',
                ]);

            case 'claude':
                return array_merge($common, [
                    'api_key' => $options['claude_api_key'] ?? '',
                    'model' => $options['claude_model'] ?? 'claude-3-5-sonnet-latest',
                ]);

            case 'generic_openai':
                return array_merge($common, [
                    'api_base_url' => $options['generic_openai_api_base_url'] ?? '',
                    'api_key' => $options['generic_openai_api_key'] ?? '',
                    'model' => $options['generic_openai_model'] ?? '',
                    'embedding_model' => $options['generic_openai_embedding_model'] ?? '',
                ]);

            default:
                return $common;
        }
    }

    /**
     * Obtém lista de todos os provedores disponíveis
     *
     * @return array
     */
    public function get_available_providers(): array {
        $providers = [];

        foreach (self::$providers as $id => $class) {
            try {
                $instance = new $class($this->get_provider_options($id));
                $providers[] = [
                    'id' => $instance->get_id(),
                    'name' => $instance->get_name(),
                    'description' => $instance->get_description(),
                    'is_configured' => $this->is_provider_configured($id),
                    'supports_embeddings' => $instance->supports_embeddings(),
                    'supports_streaming' => $instance->supports_streaming(),
                    'models' => $instance->get_available_models(),
                    'embedding_models' => $instance->get_embedding_models(),
                ];
            } catch (\Exception $e) {
                continue;
            }
        }

        return $providers;
    }

    /**
     * Verifica se um provedor está configurado
     *
     * @param string $provider_id
     * @return bool
     */
    public function is_provider_configured(string $provider_id): bool {
        $options = \Oraculo_Tainacan\Oraculo_Tainacan::get_options();

        switch ($provider_id) {
            case 'openai':
                return !empty($options['openai_api_key']);
            case 'gemini':
                return !empty($options['gemini_api_key']);
            case 'deepseek':
                return !empty($options['deepseek_api_key']);
            case 'ollama':
                return !empty($options['ollama_url']);
            case 'groq':
                return !empty($options['groq_api_key']);
            case 'claude':
                return !empty($options['claude_api_key']);
            case 'generic_openai':
                return !empty($options['generic_openai_api_base_url']);
            default:
                return false;
        }
    }

    /**
     * Registra um novo provedor
     *
     * @param string $id
     * @param string $class
     */
    public static function register_provider(string $id, string $class): void {
        if (!is_subclass_of($class, AIProviderInterface::class)) {
            throw new \InvalidArgumentException(
                /* translators: %s: PHP class name */
                esc_html( sprintf( __( 'Classe %s deve implementar AIProviderInterface.', 'oraculo_tainacan' ), $class ) )
            );
        }

        self::$providers[$id] = $class;
    }

    /**
     * Remove provedor do registro
     *
     * @param string $id
     */
    public static function unregister_provider(string $id): void {
        unset(self::$providers[$id]);
        unset(self::$instances[$id]);
    }

    /**
     * Limpa cache de instâncias
     */
    public static function clear_cache(): void {
        self::$instances = [];
    }

    /**
     * Obtém o provedor configurado atualmente
     *
     * @return string
     */
    public function get_current_provider(): string {
        $options = \Oraculo_Tainacan\Oraculo_Tainacan::get_options();
        return $options['ai_provider'] ?? 'openai';
    }

    /**
     * Verifica se pelo menos um provedor está configurado
     *
     * @return bool
     */
    public function has_any_configured(): bool {
        foreach (array_keys(self::$providers) as $provider_id) {
            if ($this->is_provider_configured($provider_id)) {
                return true;
            }
        }
        return false;
    }
}
