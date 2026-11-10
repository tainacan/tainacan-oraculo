<?php
/**
 * Controlador REST API
 *
 * @package Oraculo_Tainacan
 */

declare( strict_types=1 );

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
		register_rest_route(
			$this->namespace,
			'/search',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'search' ),
					'permission_callback' => array( $this, 'check_public_search' ),
					'args'                => array(
						'query'       => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
							'validate_callback' => static function ( $value ) {
								// Cap de tamanho: cada caractere extra vira custo de token no provedor de IA.
								return is_string( $value ) && '' !== trim( $value ) && mb_strlen( $value ) <= 500;
							},
						),
						'collections' => array(
							'type'     => 'array',
							'default'  => array(),
							'items'    => array( 'type' => 'integer' ),
							'maxItems' => 20,
						),
						'max_results' => array(
							'type'    => 'integer',
							'default' => 10,
							'minimum' => 1,
							'maximum' => 50,
						),
						// items: só os cartões, sem o modelo de linguagem;
						// answer: a síntese sobre a recuperação da etapa items.
						'phase'       => array(
							'type'    => 'string',
							'default' => '',
							'enum'    => array( '', 'items', 'answer' ),
						),
						// Devolvido pela etapa items; isenta a answer dos limites.
						'ticket'      => array(
							'type'    => 'string',
							'default' => '',
							'pattern' => '^([A-Za-z0-9]{24})?$',
						),
					),
				),
			)
		);

		// Chat
		register_rest_route(
			$this->namespace,
			'/chat',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'chat' ),
					'permission_callback' => array( $this, 'check_public_chat' ),
					'args'                => array(
						'message'     => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
							'validate_callback' => static function ( $value ) {
								// Cap de tamanho: cada caractere extra vira custo de token no provedor de IA.
								return is_string( $value ) && '' !== trim( $value ) && mb_strlen( $value ) <= 2000;
							},
						),
						'session_id'  => array(
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
							'validate_callback' => static function ( $value ) {
								return is_string( $value ) && preg_match( '/^[a-zA-Z0-9-]{0,64}$/', $value );
							},
						),
						'collections' => array(
							'type'     => 'array',
							'default'  => array(),
							'items'    => array( 'type' => 'integer' ),
							'maxItems' => 20,
						),
					),
				),
			)
		);

		// Conversas do usuário
		register_rest_route(
			$this->namespace,
			'/conversations',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_conversations' ),
					'permission_callback' => array( $this, 'check_user_logged_in' ),
				),
			)
		);

		// Mensagens de uma conversa
		register_rest_route(
			$this->namespace,
			'/conversations/(?P<session_id>[a-zA-Z0-9-]+)/messages',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_conversation_messages' ),
					'permission_callback' => array( $this, 'check_user_logged_in' ),
				),
			)
		);

		// Encerrar conversa (mutação: exige nonce; posse do session_id + dono checado no handler)
		register_rest_route(
			$this->namespace,
			'/conversations/(?P<session_id>[a-zA-Z0-9-]+)/end',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'end_conversation' ),
					'permission_callback' => array( $this, 'check_rest_nonce' ),
				),
			)
		);

		// Feedback
		register_rest_route(
			$this->namespace,
			'/feedback',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'record_feedback' ),
					'permission_callback' => array( $this, 'check_public_feedback' ),
					'args'                => array(
						'search_id'  => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'message_id' => array( 'type' => 'integer' ),
						'feedback'   => array(
							'required' => true,
							'type'     => 'string',
							'enum'     => array( 'positive', 'negative' ),
						),
					),
				),
			)
		);

		// Indexação (admin)
		register_rest_route(
			$this->namespace,
			'/indexing/start',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'start_indexing' ),
					'permission_callback' => array( $this, 'check_admin' ),
					'args'                => array(
						'collection_id' => array(
							'required' => true,
							'type'     => 'integer',
						),
						'force'         => array(
							'type'    => 'boolean',
							'default' => false,
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/indexing/status',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_indexing_status' ),
					'permission_callback' => array( $this, 'check_admin' ),
					'args'                => array(
						'collection_id' => array( 'type' => 'integer' ),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/indexing/cancel',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'cancel_indexing' ),
					'permission_callback' => array( $this, 'check_admin' ),
					'args'                => array(
						'collection_id' => array(
							'required' => true,
							'type'     => 'integer',
						),
					),
				),
			)
		);

		// Analytics (admin)
		register_rest_route(
			$this->namespace,
			'/analytics',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_analytics' ),
					'permission_callback' => array( $this, 'check_admin' ),
					'args'                => array(
						'period' => array(
							'type'    => 'string',
							'default' => 'month',
							'enum'    => array( 'today', 'week', 'month', 'year', 'all' ),
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/analytics/timeline',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_analytics_timeline' ),
					'permission_callback' => array( $this, 'check_admin' ),
					'args'                => array(
						'period'      => array(
							'type'    => 'string',
							'default' => 'month',
							'enum'    => array( 'today', 'week', 'month', 'year', 'all' ),
						),
						'granularity' => array(
							'type'    => 'string',
							'default' => 'day',
							'enum'    => array( 'hour', 'day', 'week', 'month' ),
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/analytics/export',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'export_analytics' ),
					'permission_callback' => array( $this, 'check_admin' ),
					'args'                => array(
						'period' => array(
							'type'    => 'string',
							'default' => 'month',
							'enum'    => array( 'today', 'week', 'month', 'year', 'all' ),
						),
						'format' => array(
							'type'    => 'string',
							'default' => 'json',
							'enum'    => array( 'json', 'csv' ),
						),
					),
				),
			)
		);

		// Coleções
		register_rest_route(
			$this->namespace,
			'/collections',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_collections' ),
					'permission_callback' => array( $this, 'check_search_enabled' ),
				),
			)
		);

		// Status dos vetores
		register_rest_route(
			$this->namespace,
			'/vectors/stats',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_vector_stats' ),
					'permission_callback' => array( $this, 'check_admin' ),
				),
			)
		);

		// Teste de conexão com provedor
		register_rest_route(
			$this->namespace,
			'/providers/test',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'test_provider' ),
					'permission_callback' => array( $this, 'check_admin' ),
					'args'                => array(
						'provider' => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
						),
					),
				),
			)
		);

		// Lista de provedores
		register_rest_route(
			$this->namespace,
			'/providers',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_providers' ),
					'permission_callback' => array( $this, 'check_admin' ),
				),
			)
		);

		// Configurações
		register_rest_route(
			$this->namespace,
			'/settings',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_settings' ),
					'permission_callback' => array( $this, 'check_admin' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'update_settings' ),
					'permission_callback' => array( $this, 'check_admin' ),
				),
			)
		);

		// Sugestões de busca
		register_rest_route(
			$this->namespace,
			'/suggestions',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_suggestions' ),
					'permission_callback' => array( $this, 'check_search_enabled' ),
				),
			)
		);

		// Teste ao vivo do Diagnóstico. Fica em /oraculo/ para seguir o mesmo
		// caminho da busca: modo rápido (mu-plugin) e processos PHP próprios.
		register_rest_route(
			$this->namespace,
			'/diagnostics/probe',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'diagnostics_probe' ),
					'permission_callback' => array( $this, 'check_admin' ),
					'args'                => array(
						'deep' => array(
							'type'    => 'boolean',
							'default' => false,
						),
					),
				),
			)
		);

		// Health check (expõe versões/config — restrito a admin)
		register_rest_route(
			$this->namespace,
			'/health',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'health_check' ),
					'permission_callback' => array( $this, 'check_admin' ),
				),
			)
		);
	}

	/**
	 * Teste ao vivo para a tela Diagnóstico
	 *
	 * @param WP_REST_Request $request Requisição.
	 * @return WP_REST_Response
	 */
	public function diagnostics_probe( WP_REST_Request $request ): WP_REST_Response {
		$data = \Oraculo_Tainacan\Admin\Diagnostics::probe( (bool) $request->get_param( 'deep' ) );
		return new WP_REST_Response( $data );
	}

	/**
	 * Verifica se é admin
	 */
	public function check_admin(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Verifica se usuário está logado
	 */
	public function check_user_logged_in(): bool {
		return is_user_logged_in();
	}

	/**
	 * Verifica o nonce REST (X-WP-Nonce ou _wpnonce).
	 *
	 * Visitantes anônimos recebem o nonce via wp_localize_script (restNonce);
	 * para usuários logados o core já valida o cookie auth.
	 *
	 * @param WP_REST_Request $request
	 * @return true|WP_Error
	 */
	public function check_rest_nonce( WP_REST_Request $request ) {
		$nonce = $request->get_header( 'X-WP-Nonce' );
		if ( empty( $nonce ) ) {
			$nonce = (string) $request->get_param( '_wpnonce' );
		}

		if ( empty( $nonce ) || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new WP_Error(
				'oraculo_invalid_nonce',
				__( 'Requisição não autorizada: nonce ausente ou inválido.', 'oraculo-tainacan' ),
				array( 'status' => 401 )
			);
		}

		return true;
	}

	/**
	 * Permission callback dos endpoints públicos de busca.
	 *
	 * @param WP_REST_Request $request
	 * @return true|WP_Error
	 */
	public function check_public_search( WP_REST_Request $request ) {
		return $this->check_public_endpoint( 'search', $request );
	}

	/**
	 * Permission callback dos endpoints públicos de chat.
	 *
	 * @param WP_REST_Request $request
	 * @return true|WP_Error
	 */
	public function check_public_chat( WP_REST_Request $request ) {
		return $this->check_public_endpoint( 'chat', $request );
	}

	/**
	 * Permission callback do endpoint público de feedback.
	 *
	 * @param WP_REST_Request $request
	 * @return true|WP_Error
	 */
	public function check_public_feedback( WP_REST_Request $request ) {
		return $this->check_public_endpoint( 'feedback', $request );
	}

	/**
	 * Endpoints de leitura leve ligados à busca (coleções, sugestões).
	 *
	 * @return bool
	 */
	public function check_search_enabled(): bool {
		$options = \Oraculo_Tainacan\Oraculo_Tainacan::get_options();
		return ! empty( $options['enable_search'] );
	}

	/**
	 * Proteção compartilhada dos endpoints públicos que consomem IA/gravam dados:
	 * gate pela opção enable_<feature>, nonce do widget e rate-limit por IP.
	 *
	 * @param string          $feature search|chat|feedback
	 * @param WP_REST_Request $request
	 * @return true|WP_Error
	 */
	private function check_public_endpoint( string $feature, WP_REST_Request $request ) {
		$options = \Oraculo_Tainacan\Oraculo_Tainacan::get_options();

		if ( empty( $options[ 'enable_' . $feature ] ) ) {
			return new WP_Error(
				'oraculo_feature_disabled',
				__( 'Este recurso está desativado.', 'oraculo-tainacan' ),
				array( 'status' => 403 )
			);
		}

		$nonce_check = $this->check_rest_nonce( $request );
		if ( is_wp_error( $nonce_check ) ) {
			return $nonce_check;
		}

		// Defaults por feature: chat conversacional tolera mais requisições/min.
		// Busca e chat vêm da tela Diagnóstico (opções rate_limit_*).
		$defaults = array(
			'search'   => (int) ( $options['rate_limit_search'] ?? 10 ),
			'chat'     => (int) ( $options['rate_limit_chat'] ?? 20 ),
			'feedback' => 30,
		);

		/**
		 * Filtra o máximo de requisições por minuto e por IP nos endpoints públicos.
		 *
		 * @param int    $max_per_minute Padrão: opções rate_limit_search (10) e rate_limit_chat (20); feedback 30.
		 * @param string $feature        search|chat|feedback.
		 */
		$max_per_minute = (int) apply_filters( 'oraculo_tainacan_rest_rate_limit', $defaults[ $feature ] ?? 10, $feature );

		// Redes liberadas (escola, museu atrás de um único IP): sem limite por
		// IP. O teto global continua valendo para elas.
		if ( self::ip_allowlisted( (string) ( $options['rate_limit_allowlist'] ?? '' ) ) ) {
			$max_per_minute = 0;
		}

		// Busca em duas etapas: a pergunta conta nos limites uma vez, na etapa
		// items. A answer só é isenta com o bilhete que a items devolveu, de uso
		// único (consumido aqui); sem ele, conta como uma busca inteira.
		$phase        = 'search' === $feature ? (string) $request->get_param( 'phase' ) : '';
		$answer_after = 'answer' === $phase && SearchEngine::claim_ticket( (string) $request->get_param( 'ticket' ) );

		if ( $max_per_minute > 0 && ! $answer_after && ! $this->check_rate_limit( $feature, $max_per_minute ) ) {
			return new WP_Error(
				'oraculo_rate_limited',
				__( 'Muitas requisições. Aguarde alguns instantes e tente novamente.', 'oraculo-tainacan' ),
				array( 'status' => 429 )
			);
		}

		// Teto global do site para chamadas que consomem IA (todos os IPs somados):
		// o limite por IP não protege contra abuso distribuído (botnets/scrapers).
		if ( in_array( $feature, array( 'search', 'chat' ), true ) && ! $answer_after ) {
			/**
			 * Filtra o máximo global de chamadas de IA por minuto (search + chat, site inteiro).
			 *
			 * @param int $global_max_per_minute Padrão: opção rate_limit_global (300 nas instalações novas;
			 *                                   60 em quem atualizou sem salvar a opção). Zero desativa o teto.
			 */
			$global_max = (int) apply_filters( 'oraculo_tainacan_rest_global_rate_limit', (int) ( $options['rate_limit_global'] ?? 60 ) );

			if ( $global_max > 0 ) {
				$global_key   = 'oraculo_rl_global_ai';
				$global_count = (int) get_transient( $global_key );

				if ( $global_count >= $global_max ) {
					return new WP_Error(
						'oraculo_global_rate_limited',
						__( 'O serviço está com alta demanda no momento. Tente novamente em instantes.', 'oraculo-tainacan' ),
						array( 'status' => 503 )
					);
				}

				set_transient( $global_key, $global_count + 1, MINUTE_IN_SECONDS );
			}
		}

		return true;
	}

	/**
	 * O IP do visitante está numa das redes liberadas?
	 *
	 * Usa REMOTE_ADDR, como o limite por IP: cabeçalhos X-Forwarded-For vêm do
	 * cliente e não servem para liberar nada.
	 *
	 * @param string $allowlist Um IP ou rede CIDR por linha (IPv4 ou IPv6).
	 * @return bool
	 */
	public static function ip_allowlisted( string $allowlist ): bool {
		if ( '' === trim( $allowlist ) || empty( $_SERVER['REMOTE_ADDR'] ) ) {
			return false;
		}

		$ip = filter_var( wp_unslash( $_SERVER['REMOTE_ADDR'] ), FILTER_VALIDATE_IP ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Validated as an IP address by filter_var; anything else becomes false.
		if ( false === $ip ) {
			return false;
		}

		foreach ( preg_split( '/[\s,]+/', $allowlist ) as $entry ) {
			if ( '' !== $entry && self::ip_in_range( $ip, $entry ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * IP dentro de um IP ou rede CIDR
	 *
	 * @param string $ip    IP validado.
	 * @param string $range "203.0.113.7", "203.0.113.0/24" ou "2001:db8::/32".
	 * @return bool
	 */
	public static function ip_in_range( string $ip, string $range ): bool {
		$parts  = explode( '/', $range, 2 );
		$net    = @inet_pton( $parts[0] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- inet_pton warns on malformed input typed by the admin; false is handled below.
		$packed = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Same as above; $ip is already validated.

		if ( false === $net || false === $packed ) {
			return false;
		}

		// Servidor dual-stack entrega IPv4 como ::ffff:a.b.c.d; compara como IPv4.
		$mapped = str_repeat( "\0", 10 ) . "\xff\xff";
		$prefix = isset( $parts[1] ) && ctype_digit( $parts[1] ) ? (int) $parts[1] : null;
		if ( 16 === strlen( $packed ) && 0 === strncmp( $packed, $mapped, 12 ) ) {
			$packed = substr( $packed, 12 );
		}
		if ( 16 === strlen( $net ) && 0 === strncmp( $net, $mapped, 12 ) ) {
			$net    = substr( $net, 12 );
			$prefix = null === $prefix ? null : max( 0, $prefix - 96 );
		}

		if ( strlen( $net ) !== strlen( $packed ) ) {
			return false;
		}

		$bits = strlen( $net ) * 8;
		$mask = null === $prefix ? $bits : min( $bits, $prefix );

		$bytes = intdiv( $mask, 8 );
		if ( 0 !== strncmp( $net, $packed, $bytes ) ) {
			return false;
		}

		$rest = $mask % 8;
		if ( 0 === $rest ) {
			return true;
		}

		$byte_mask = ( 0xFF << ( 8 - $rest ) ) & 0xFF;
		return ( ord( $net[ $bytes ] ) & $byte_mask ) === ( ord( $packed[ $bytes ] ) & $byte_mask );
	}

	/**
	 * Rate-limit simples por IP via transient (janela de 1 minuto).
	 *
	 * @param string $feature
	 * @param int    $max_per_minute
	 * @return bool
	 */
	private function check_rate_limit( string $feature, int $max_per_minute ): bool {
		$ip = isset( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
			: '';

		$key   = 'oraculo_rl_' . $feature . '_' . md5( $ip );
		$count = (int) get_transient( $key );

		if ( $count >= $max_per_minute ) {
			return false;
		}

		set_transient( $key, $count + 1, MINUTE_IN_SECONDS );

		return true;
	}

	/**
	 * Endpoint de busca
	 */
	public function search( WP_REST_Request $request ): WP_REST_Response {
		$search_engine = new SearchEngine();

		$result = $search_engine->search(
			$request->get_param( 'query' ),
			$request->get_param( 'collections' ),
			array(
				'max_results' => $request->get_param( 'max_results' ),
				'phase'       => (string) $request->get_param( 'phase' ),
				'ticket'      => (string) $request->get_param( 'ticket' ),
			)
		);

		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'error'   => $result->get_error_message(),
				),
				400
			);
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'data'    => $result,
			)
		);
	}

	/**
	 * Endpoint de chat
	 */
	public function chat( WP_REST_Request $request ): WP_REST_Response {
		$chat_engine = new ChatEngine();

		$result = $chat_engine->chat(
			$request->get_param( 'message' ),
			$request->get_param( 'session_id' ),
			$request->get_param( 'collections' )
		);

		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'error'   => $result->get_error_message(),
				),
				400
			);
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'data'    => $result,
			)
		);
	}

	/**
	 * Obtém conversas do usuário
	 */
	public function get_conversations( WP_REST_Request $request ): WP_REST_Response {
		$chat_engine   = new ChatEngine();
		$conversations = $chat_engine->get_user_conversations( get_current_user_id() );

		return new WP_REST_Response(
			array(
				'success' => true,
				'data'    => $conversations,
			)
		);
	}

	/**
	 * Obtém mensagens de uma conversa
	 */
	public function get_conversation_messages( WP_REST_Request $request ): WP_REST_Response {
		$conversation = $this->get_conversation_by_session( $request->get_param( 'session_id' ) );

		if ( ! $conversation ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'error'   => __( 'Conversa não encontrada.', 'oraculo-tainacan' ),
				),
				404
			);
		}

		// Apenas o dono da conversa (ou admin) pode ler as mensagens.
		if ( (int) $conversation->user_id !== get_current_user_id() && ! current_user_can( 'manage_options' ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'error'   => __( 'Você não tem permissão para acessar esta conversa.', 'oraculo-tainacan' ),
				),
				403
			);
		}

		$chat_engine = new ChatEngine();
		$messages    = $chat_engine->get_messages( (int) $conversation->id );

		return new WP_REST_Response(
			array(
				'success' => true,
				'data'    => $messages,
			)
		);
	}

	/**
	 * Encerra conversa
	 */
	public function end_conversation( WP_REST_Request $request ): WP_REST_Response {
		$session_id   = $request->get_param( 'session_id' );
		$conversation = $this->get_conversation_by_session( $session_id );

		if ( ! $conversation ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'error'   => __( 'Conversa não encontrada.', 'oraculo-tainacan' ),
				),
				404
			);
		}

		// Conversa de usuário logado só pode ser encerrada pelo dono (ou admin);
		// conversa anônima (user_id 0) usa a posse do session_id + nonce como credencial.
		$owner_id = (int) $conversation->user_id;
		if ( $owner_id > 0 && $owner_id !== get_current_user_id() && ! current_user_can( 'manage_options' ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'error'   => __( 'Você não tem permissão para encerrar esta conversa.', 'oraculo-tainacan' ),
				),
				403
			);
		}

		$chat_engine = new ChatEngine();
		$result      = $chat_engine->end_conversation( $session_id );

		return new WP_REST_Response(
			array(
				'success' => $result,
			)
		);
	}

	/**
	 * Busca conversa por session_id (tabela própria do plugin).
	 *
	 * @param string $session_id
	 * @return object|null
	 */
	private function get_conversation_by_session( string $session_id ) {
		global $wpdb;

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned table; per-request conversation lookup for authorization.
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, user_id FROM {$wpdb->prefix}oraculo_conversations WHERE session_id = %s",
				$session_id
			)
		);
	}

	/**
	 * Registra feedback
	 */
	public function record_feedback( WP_REST_Request $request ): WP_REST_Response {
		$analytics = new AnalyticsManager();

		$result = $analytics->record_feedback(
			$request->get_param( 'search_id' ) ?? '',
			$request->get_param( 'feedback' ),
			$request->get_param( 'message_id' ) ?? 0
		);

		return new WP_REST_Response(
			array(
				'success' => $result,
				'message' => $result
					? __( 'Obrigado pelo feedback!', 'oraculo-tainacan' )
					: __( 'Erro ao registrar feedback.', 'oraculo-tainacan' ),
			)
		);
	}

	/**
	 * Inicia indexação
	 */
	public function start_indexing( WP_REST_Request $request ): WP_REST_Response {
		$indexing = new IndexingManager();

		$result = $indexing->start_indexing(
			$request->get_param( 'collection_id' ),
			$request->get_param( 'force' )
		);

		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'error'   => $result->get_error_message(),
				),
				400
			);
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'data'    => $result,
			)
		);
	}

	/**
	 * Obtém status de indexação
	 */
	public function get_indexing_status( WP_REST_Request $request ): WP_REST_Response {
		$indexing      = new IndexingManager();
		$collection_id = $request->get_param( 'collection_id' );

		$status = $indexing->get_status( $collection_id ?: null );

		return new WP_REST_Response(
			array(
				'success' => true,
				'data'    => $status,
			)
		);
	}

	/**
	 * Cancela indexação
	 */
	public function cancel_indexing( WP_REST_Request $request ): WP_REST_Response {
		$indexing = new IndexingManager();
		$result   = $indexing->cancel_job( $request->get_param( 'collection_id' ) );

		return new WP_REST_Response(
			array(
				'success' => $result,
			)
		);
	}

	/**
	 * Obtém analytics
	 */
	public function get_analytics( WP_REST_Request $request ): WP_REST_Response {
		$analytics = new AnalyticsManager();
		$period    = $request->get_param( 'period' );

		$data = array(
			'stats'           => $analytics->get_stats( $period ),
			'top_searches'    => $analytics->get_top_searches( $period ),
			'failed_searches' => $analytics->get_failed_searches( $period ),
			'by_collection'   => $analytics->get_stats_by_collection( $period ),
			'model_usage'     => $analytics->get_model_usage( $period ),
			'cost_estimate'   => $analytics->get_cost_estimate( $period ),
		);

		return new WP_REST_Response(
			array(
				'success' => true,
				'data'    => $data,
			)
		);
	}

	/**
	 * Obtém timeline de analytics
	 */
	public function get_analytics_timeline( WP_REST_Request $request ): WP_REST_Response {
		$analytics = new AnalyticsManager();

		$data = $analytics->get_searches_timeline(
			$request->get_param( 'period' ),
			$request->get_param( 'granularity' )
		);

		return new WP_REST_Response(
			array(
				'success' => true,
				'data'    => $data,
			)
		);
	}

	/**
	 * Exporta analytics
	 */
	public function export_analytics( WP_REST_Request $request ): WP_REST_Response {
		$analytics = new AnalyticsManager();

		$format = $request->get_param( 'format' );
		$data   = $analytics->export( $request->get_param( 'period' ), $format );

		if ( $format === 'csv' ) {
			header( 'Content-Type: text/csv' );
			header( 'Content-Disposition: attachment; filename="oraculo-analytics.csv"' );
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV binary output sent as attachment; escaping would corrupt the data.
			echo $data;
			exit;
		}

		$decoded = json_decode( $data, true );

		return new WP_REST_Response( is_array( $decoded ) ? $decoded : array() );
	}

	/**
	 * Obtém coleções
	 */
	public function get_collections( WP_REST_Request $request ): WP_REST_Response {
		$collections = \Oraculo_Tainacan\get_tainacan_collections();

		// Adicionar contagem de vetores indexados
		$vector_store = new VectorStore();

		foreach ( $collections as &$collection ) {
			$collection['indexed_count'] = $vector_store->count_by_collection( $collection['id'] );
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'data'    => $collections,
			)
		);
	}

	/**
	 * Obtém estatísticas de vetores
	 */
	public function get_vector_stats( WP_REST_Request $request ): WP_REST_Response {
		$vector_store = new VectorStore();

		return new WP_REST_Response(
			array(
				'success' => true,
				'data'    => $vector_store->get_stats(),
			)
		);
	}

	/**
	 * Testa conexão com provedor
	 */
	public function test_provider( WP_REST_Request $request ): WP_REST_Response {
		$factory = new AIProviderFactory();

		try {
			$provider = $factory->create( $request->get_param( 'provider' ) );
			$result   = $provider->test_connection();

			return new WP_REST_Response(
				array(
					'success' => $result['success'],
					'message' => $result['message'],
					'details' => $result['details'] ?? array(),
				)
			);
		} catch ( \Exception $e ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => $e->getMessage(),
				),
				400
			);
		}
	}

	/**
	 * Obtém lista de provedores
	 */
	public function get_providers( WP_REST_Request $request ): WP_REST_Response {
		$factory = new AIProviderFactory();

		return new WP_REST_Response(
			array(
				'success' => true,
				'data'    => $factory->get_available_providers(),
			)
		);
	}

	/**
	 * Obtém configurações
	 */
	public function get_settings( WP_REST_Request $request ): WP_REST_Response {
		$options = \Oraculo_Tainacan\Oraculo_Tainacan::get_options();

		// Ocultar chaves de API
		$sensitive = array( 'openai_api_key', 'gemini_api_key', 'deepseek_api_key', 'groq_api_key', 'claude_api_key', 'generic_openai_api_key' );
		foreach ( $sensitive as $key ) {
			if ( ! empty( $options[ $key ] ) ) {
				$options[ $key ] = '••••••••';
			}
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'data'    => $options,
			)
		);
	}

	/**
	 * Atualiza configurações
	 */
	public function update_settings( WP_REST_Request $request ): WP_REST_Response {
		$new_options = $request->get_json_params();

		// Guard de schema: o corpo precisa ser um objeto JSON não vazio.
		if ( ! is_array( $new_options ) || empty( $new_options ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'error'   => __( 'Corpo da requisição inválido: esperado um objeto JSON de configurações.', 'oraculo-tainacan' ),
				),
				400
			);
		}

		$sanitized = \Oraculo_Tainacan\Admin\SettingsSanitizer::sanitize( $new_options );

		update_option( 'oraculo_tainacan_options', $sanitized );

		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => __( 'Configurações salvas com sucesso.', 'oraculo-tainacan' ),
			)
		);
	}

	/**
	 * Obtém sugestões de busca
	 */
	public function get_suggestions( WP_REST_Request $request ): WP_REST_Response {
		$search_engine = new SearchEngine();
		$suggestions   = $search_engine->get_popular_searches( 5 );

		$options             = \Oraculo_Tainacan\Oraculo_Tainacan::get_options();
		$default_suggestions = $options['suggested_questions'] ?? array();

		return new WP_REST_Response(
			array(
				'success' => true,
				'data'    => array(
					'popular' => $suggestions,
					'default' => $default_suggestions,
				),
			)
		);
	}

	/**
	 * Health check
	 */
	public function health_check( WP_REST_Request $request ): WP_REST_Response {
		$factory      = new AIProviderFactory();
		$vector_store = new VectorStore();

		$health = array(
			'status'                 => 'healthy',
			'version'                => ORACULO_TAINACAN_VERSION,
			'tainacan_active'        => class_exists( '\Tainacan\Plugin' ),
			'ai_provider_configured' => $factory->has_any_configured(),
			'current_provider'       => $factory->get_current_provider(),
			'vectors_count'          => $vector_store->get_stats()['total_vectors'] ?? 0,
			'php_version'            => PHP_VERSION,
			'memory_usage'           => \Oraculo_Tainacan\get_memory_usage(),
			'timestamp'              => current_time( 'mysql' ),
		);

		return new WP_REST_Response( $health );
	}
}
