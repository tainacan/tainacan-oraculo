<?php
/**
 * Controlador REST API
 *
 * @package Oraculo_Tainacan
 */

namespace Oraculo_Tainacan\API;

use Oraculo_Tainacan\Search\SearchEngine;
use Oraculo_Tainacan\Chat\ChatEngine;
use Oraculo_Tainacan\Indexing\IndexingManager;
use Oraculo_Tainacan\Analytics\AnalyticsManager;
use Oraculo_Tainacan\AI\AIProviderFactory;
use Oraculo_Tainacan\Vector\VectorStore;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * API REST do Oráculo Tainacan
 */
class RestController extends WP_REST_Controller {

    /**
     * Namespace da API
     */
    protected $namespace = 'oraculo/v1';

    /**
     * Registra rotas
     */
    public function register_routes(): void {
        // Busca
        register_rest_route($this->namespace, '/search', [
            [
                'methods' => 'POST',
                'callback' => [$this, 'search'],
                'permission_callback' => '__return_true',
                'args' => [
                    'query' => [
                        'required' => true,
                        'type' => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'collections' => [
                        'type' => 'array',
                        'default' => [],
                    ],
                    'max_results' => [
                        'type' => 'integer',
                        'default' => 10,
                    ],
                ],
            ],
        ]);

        // Chat
        register_rest_route($this->namespace, '/chat', [
            [
                'methods' => 'POST',
                'callback' => [$this, 'chat'],
                'permission_callback' => '__return_true',
                'args' => [
                    'message' => [
                        'required' => true,
                        'type' => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'session_id' => [
                        'type' => 'string',
                        'default' => '',
                    ],
                    'collections' => [
                        'type' => 'array',
                        'default' => [],
                    ],
                ],
            ],
        ]);

        // Conversas do usuário
        register_rest_route($this->namespace, '/conversations', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'get_conversations'],
                'permission_callback' => [$this, 'check_user_logged_in'],
            ],
        ]);

        // Mensagens de uma conversa
        register_rest_route($this->namespace, '/conversations/(?P<session_id>[a-zA-Z0-9-]+)/messages', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'get_conversation_messages'],
                'permission_callback' => [$this, 'check_user_logged_in'],
            ],
        ]);

        // Encerrar conversa
        register_rest_route($this->namespace, '/conversations/(?P<session_id>[a-zA-Z0-9-]+)/end', [
            [
                'methods' => 'POST',
                'callback' => [$this, 'end_conversation'],
                'permission_callback' => '__return_true',
            ],
        ]);

        // Feedback
        register_rest_route($this->namespace, '/feedback', [
            [
                'methods' => 'POST',
                'callback' => [$this, 'record_feedback'],
                'permission_callback' => '__return_true',
                'args' => [
                    'search_id' => ['type' => 'string'],
                    'message_id' => ['type' => 'integer'],
                    'feedback' => [
                        'required' => true,
                        'type' => 'string',
                        'enum' => ['positive', 'negative'],
                    ],
                ],
            ],
        ]);

        // Indexação (admin)
        register_rest_route($this->namespace, '/indexing/start', [
            [
                'methods' => 'POST',
                'callback' => [$this, 'start_indexing'],
                'permission_callback' => [$this, 'check_admin'],
                'args' => [
                    'collection_id' => [
                        'required' => true,
                        'type' => 'integer',
                    ],
                    'force' => [
                        'type' => 'boolean',
                        'default' => false,
                    ],
                ],
            ],
        ]);

        register_rest_route($this->namespace, '/indexing/status', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'get_indexing_status'],
                'permission_callback' => [$this, 'check_admin'],
                'args' => [
                    'collection_id' => ['type' => 'integer'],
                ],
            ],
        ]);

        register_rest_route($this->namespace, '/indexing/cancel', [
            [
                'methods' => 'POST',
                'callback' => [$this, 'cancel_indexing'],
                'permission_callback' => [$this, 'check_admin'],
                'args' => [
                    'collection_id' => [
                        'required' => true,
                        'type' => 'integer',
                    ],
                ],
            ],
        ]);

        // Analytics (admin)
        register_rest_route($this->namespace, '/analytics', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'get_analytics'],
                'permission_callback' => [$this, 'check_admin'],
                'args' => [
                    'period' => [
                        'type' => 'string',
                        'default' => 'month',
                        'enum' => ['today', 'week', 'month', 'year', 'all'],
                    ],
                ],
            ],
        ]);

        register_rest_route($this->namespace, '/analytics/timeline', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'get_analytics_timeline'],
                'permission_callback' => [$this, 'check_admin'],
                'args' => [
                    'period' => ['type' => 'string', 'default' => 'month'],
                    'granularity' => ['type' => 'string', 'default' => 'day'],
                ],
            ],
        ]);

        register_rest_route($this->namespace, '/analytics/export', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'export_analytics'],
                'permission_callback' => [$this, 'check_admin'],
                'args' => [
                    'period' => ['type' => 'string', 'default' => 'month'],
                    'format' => ['type' => 'string', 'default' => 'json'],
                ],
            ],
        ]);

        // Coleções
        register_rest_route($this->namespace, '/collections', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'get_collections'],
                'permission_callback' => '__return_true',
            ],
        ]);

        // Status dos vetores
        register_rest_route($this->namespace, '/vectors/stats', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'get_vector_stats'],
                'permission_callback' => [$this, 'check_admin'],
            ],
        ]);

        // Teste de conexão com provedor
        register_rest_route($this->namespace, '/providers/test', [
            [
                'methods' => 'POST',
                'callback' => [$this, 'test_provider'],
                'permission_callback' => [$this, 'check_admin'],
                'args' => [
                    'provider' => [
                        'required' => true,
                        'type' => 'string',
                    ],
                ],
            ],
        ]);

        // Lista de provedores
        register_rest_route($this->namespace, '/providers', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'get_providers'],
                'permission_callback' => [$this, 'check_admin'],
            ],
        ]);

        // Configurações
        register_rest_route($this->namespace, '/settings', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'get_settings'],
                'permission_callback' => [$this, 'check_admin'],
            ],
            [
                'methods' => 'POST',
                'callback' => [$this, 'update_settings'],
                'permission_callback' => [$this, 'check_admin'],
            ],
        ]);

        // Sugestões de busca
        register_rest_route($this->namespace, '/suggestions', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'get_suggestions'],
                'permission_callback' => '__return_true',
            ],
        ]);

        // Health check
        register_rest_route($this->namespace, '/health', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'health_check'],
                'permission_callback' => '__return_true',
            ],
        ]);
    }

    /**
     * Verifica se é admin
     */
    public function check_admin(): bool {
        return current_user_can('manage_options');
    }

    /**
     * Verifica se usuário está logado
     */
    public function check_user_logged_in(): bool {
        return is_user_logged_in();
    }

    /**
     * Endpoint de busca
     */
    public function search(WP_REST_Request $request): WP_REST_Response {
        $search_engine = new SearchEngine();

        $result = $search_engine->search(
            $request->get_param('query'),
            $request->get_param('collections'),
            [
                'max_results' => $request->get_param('max_results'),
            ]
        );

        if (is_wp_error($result)) {
            return new WP_REST_Response([
                'success' => false,
                'error' => $result->get_error_message(),
            ], 400);
        }

        return new WP_REST_Response([
            'success' => true,
            'data' => $result,
        ]);
    }

    /**
     * Endpoint de chat
     */
    public function chat(WP_REST_Request $request): WP_REST_Response {
        $chat_engine = new ChatEngine();

        $result = $chat_engine->chat(
            $request->get_param('message'),
            $request->get_param('session_id'),
            $request->get_param('collections')
        );

        if (is_wp_error($result)) {
            return new WP_REST_Response([
                'success' => false,
                'error' => $result->get_error_message(),
            ], 400);
        }

        return new WP_REST_Response([
            'success' => true,
            'data' => $result,
        ]);
    }

    /**
     * Obtém conversas do usuário
     */
    public function get_conversations(WP_REST_Request $request): WP_REST_Response {
        $chat_engine = new ChatEngine();
        $conversations = $chat_engine->get_user_conversations(get_current_user_id());

        return new WP_REST_Response([
            'success' => true,
            'data' => $conversations,
        ]);
    }

    /**
     * Obtém mensagens de uma conversa
     */
    public function get_conversation_messages(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;

        $session_id = $request->get_param('session_id');

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned table; per-request conversation lookup.
        $conversation = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}oraculo_conversations WHERE session_id = %s",
            $session_id
        ));

        if (!$conversation) {
            return new WP_REST_Response([
                'success' => false,
                'error' => __('Conversa não encontrada.', 'oraculo_tainacan'),
            ], 404);
        }

        $chat_engine = new ChatEngine();
        $messages = $chat_engine->get_messages($conversation->id);

        return new WP_REST_Response([
            'success' => true,
            'data' => $messages,
        ]);
    }

    /**
     * Encerra conversa
     */
    public function end_conversation(WP_REST_Request $request): WP_REST_Response {
        $chat_engine = new ChatEngine();
        $result = $chat_engine->end_conversation($request->get_param('session_id'));

        return new WP_REST_Response([
            'success' => $result,
        ]);
    }

    /**
     * Registra feedback
     */
    public function record_feedback(WP_REST_Request $request): WP_REST_Response {
        $analytics = new AnalyticsManager();

        $result = $analytics->record_feedback(
            $request->get_param('search_id') ?? '',
            $request->get_param('feedback'),
            $request->get_param('message_id') ?? 0
        );

        return new WP_REST_Response([
            'success' => $result,
            'message' => $result
                ? __('Obrigado pelo feedback!', 'oraculo_tainacan')
                : __('Erro ao registrar feedback.', 'oraculo_tainacan'),
        ]);
    }

    /**
     * Inicia indexação
     */
    public function start_indexing(WP_REST_Request $request): WP_REST_Response {
        $indexing = new IndexingManager();

        $result = $indexing->start_indexing(
            $request->get_param('collection_id'),
            $request->get_param('force')
        );

        if (is_wp_error($result)) {
            return new WP_REST_Response([
                'success' => false,
                'error' => $result->get_error_message(),
            ], 400);
        }

        return new WP_REST_Response([
            'success' => true,
            'data' => $result,
        ]);
    }

    /**
     * Obtém status de indexação
     */
    public function get_indexing_status(WP_REST_Request $request): WP_REST_Response {
        $indexing = new IndexingManager();
        $collection_id = $request->get_param('collection_id');

        $status = $indexing->get_status($collection_id ?: null);

        return new WP_REST_Response([
            'success' => true,
            'data' => $status,
        ]);
    }

    /**
     * Cancela indexação
     */
    public function cancel_indexing(WP_REST_Request $request): WP_REST_Response {
        $indexing = new IndexingManager();
        $result = $indexing->cancel_job($request->get_param('collection_id'));

        return new WP_REST_Response([
            'success' => $result,
        ]);
    }

    /**
     * Obtém analytics
     */
    public function get_analytics(WP_REST_Request $request): WP_REST_Response {
        $analytics = new AnalyticsManager();
        $period = $request->get_param('period');

        $data = [
            'stats' => $analytics->get_stats($period),
            'top_searches' => $analytics->get_top_searches($period),
            'failed_searches' => $analytics->get_failed_searches($period),
            'by_collection' => $analytics->get_stats_by_collection($period),
            'model_usage' => $analytics->get_model_usage($period),
            'cost_estimate' => $analytics->get_cost_estimate($period),
        ];

        return new WP_REST_Response([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * Obtém timeline de analytics
     */
    public function get_analytics_timeline(WP_REST_Request $request): WP_REST_Response {
        $analytics = new AnalyticsManager();

        $data = $analytics->get_searches_timeline(
            $request->get_param('period'),
            $request->get_param('granularity')
        );

        return new WP_REST_Response([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * Exporta analytics
     */
    public function export_analytics(WP_REST_Request $request): WP_REST_Response {
        $analytics = new AnalyticsManager();

        $format = $request->get_param('format');
        $data = $analytics->export($request->get_param('period'), $format);

        if ($format === 'csv') {
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="oraculo-analytics.csv"');
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV binary output sent as attachment; escaping would corrupt the data.
            echo $data;
            exit;
        }

        return new WP_REST_Response(json_decode($data, true));
    }

    /**
     * Obtém coleções
     */
    public function get_collections(WP_REST_Request $request): WP_REST_Response {
        $collections = \Oraculo_Tainacan\get_tainacan_collections();

        // Adicionar contagem de vetores indexados
        $vector_store = new VectorStore();

        foreach ($collections as &$collection) {
            $collection['indexed_count'] = $vector_store->count_by_collection($collection['id']);
        }

        return new WP_REST_Response([
            'success' => true,
            'data' => $collections,
        ]);
    }

    /**
     * Obtém estatísticas de vetores
     */
    public function get_vector_stats(WP_REST_Request $request): WP_REST_Response {
        $vector_store = new VectorStore();

        return new WP_REST_Response([
            'success' => true,
            'data' => $vector_store->get_stats(),
        ]);
    }

    /**
     * Testa conexão com provedor
     */
    public function test_provider(WP_REST_Request $request): WP_REST_Response {
        $factory = new AIProviderFactory();

        try {
            $provider = $factory->create($request->get_param('provider'));
            $result = $provider->test_connection();

            return new WP_REST_Response([
                'success' => $result['success'],
                'message' => $result['message'],
                'details' => $result['details'] ?? [],
            ]);
        } catch (\Exception $e) {
            return new WP_REST_Response([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Obtém lista de provedores
     */
    public function get_providers(WP_REST_Request $request): WP_REST_Response {
        $factory = new AIProviderFactory();

        return new WP_REST_Response([
            'success' => true,
            'data' => $factory->get_available_providers(),
        ]);
    }

    /**
     * Obtém configurações
     */
    public function get_settings(WP_REST_Request $request): WP_REST_Response {
        $options = \Oraculo_Tainacan\Oraculo_Tainacan::get_options();

        // Ocultar chaves de API
        $sensitive = ['openai_api_key', 'gemini_api_key', 'deepseek_api_key', 'groq_api_key', 'claude_api_key', 'generic_openai_api_key'];
        foreach ($sensitive as $key) {
            if (!empty($options[$key])) {
                $options[$key] = '••••••••';
            }
        }

        return new WP_REST_Response([
            'success' => true,
            'data' => $options,
        ]);
    }

    /**
     * Atualiza configurações
     */
    public function update_settings(WP_REST_Request $request): WP_REST_Response {
        $new_options = $request->get_json_params();

        $admin = new \Oraculo_Tainacan\Admin\AdminPage();
        $sanitized = $admin->sanitize_settings($new_options);

        update_option('oraculo_tainacan_options', $sanitized);

        return new WP_REST_Response([
            'success' => true,
            'message' => __('Configurações salvas com sucesso.', 'oraculo_tainacan'),
        ]);
    }

    /**
     * Obtém sugestões de busca
     */
    public function get_suggestions(WP_REST_Request $request): WP_REST_Response {
        $search_engine = new SearchEngine();
        $suggestions = $search_engine->get_popular_searches(5);

        $options = \Oraculo_Tainacan\Oraculo_Tainacan::get_options();
        $default_suggestions = $options['suggested_questions'] ?? [];

        return new WP_REST_Response([
            'success' => true,
            'data' => [
                'popular' => $suggestions,
                'default' => $default_suggestions,
            ],
        ]);
    }

    /**
     * Health check
     */
    public function health_check(WP_REST_Request $request): WP_REST_Response {
        $factory = new AIProviderFactory();
        $vector_store = new VectorStore();

        $health = [
            'status' => 'healthy',
            'version' => ORACULO_TAINACAN_VERSION,
            'tainacan_active' => class_exists('\Tainacan\Plugin'),
            'ai_provider_configured' => $factory->has_any_configured(),
            'current_provider' => $factory->get_current_provider(),
            'vectors_count' => $vector_store->get_stats()['total_vectors'] ?? 0,
            'php_version' => PHP_VERSION,
            'memory_usage' => \Oraculo_Tainacan\get_memory_usage(),
            'timestamp' => current_time('mysql'),
        ];

        return new WP_REST_Response($health);
    }
}
