<?php
/**
 * Provedor OpenAI genérico (compatível com APIs OpenAI-style)
 *
 * Contribuição original de Mateus Luna (tainacan/tainacan-oraculo#4),
 * portada para a 2.14.0.
 *
 * @package Oraculo_Tainacan
 */

declare( strict_types=1 );

namespace Oraculo_Tainacan\AI\Providers;

use Oraculo_Tainacan\AI\AbstractAIProvider;
use WP_Error;

/**
 * Implementação de provedor OpenAI genérico com endpoint configurável
 *
 * Serve a qualquer servidor que fale o protocolo da OpenAI (/models,
 * /chat/completions, /embeddings): Open WebUI, LiteLLM, vLLM, LocalAI, o
 * /v1 do Ollama, gateways institucionais etc. A API key é opcional.
 */
class GenericOpenAIProvider extends AbstractAIProvider {

	/**
	 * Prefixo de cache para modelos remotos
	 */
	private const MODELS_CACHE_PREFIX = 'oraculo_generic_openai_models_v2_';

	/**
	 * Tempo de cache dos modelos em segundos
	 */
	private const MODELS_CACHE_TTL = DAY_IN_SECONDS;

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
		return __( 'OpenAI Compatível (Genérico)', 'oraculo-tainacan' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description(): string {
		return __( 'Qualquer servidor compatível com a API da OpenAI (Open WebUI, LiteLLM, vLLM, Ollama /v1...), com URL base própria e descoberta de modelos.', 'oraculo-tainacan' );
	}

	/**
	 * {@inheritdoc}
	 *
	 * Só lê o cache preenchido por test_connection()/list_remote_models():
	 * get_available_providers() monta todos os provedores em várias telas, e
	 * consultar /models ali faria cada carregamento do admin esperar o
	 * servidor remoto (ou o timeout inteiro, se ele estiver fora do ar).
	 */
	public function get_available_models(): array {
		$chat_models = array();
		foreach ( $this->get_cached_models() as $model ) {
			$model_id = $this->extract_model_id( $model );
			if ( '' === $model_id || $this->is_embedding_model( $model, $model_id ) ) {
				continue;
			}
			$chat_models[] = $this->normalize_model( $model, $model_id );
		}
		return $chat_models;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_embedding_models(): array {
		$embedding_models = array();
		foreach ( $this->get_cached_models() as $model ) {
			$model_id = $this->extract_model_id( $model );
			if ( '' === $model_id || ! $this->is_embedding_model( $model, $model_id ) ) {
				continue;
			}
			$embedding_models[] = $this->normalize_model( $model, $model_id );
		}
		return $embedding_models;
	}

	/**
	 * {@inheritdoc}
	 *
	 * O endpoint pode ou não expor /embeddings; isso só se descobre com um
	 * modelo configurado. Declarar suporte permite escolhê-lo como provedor
	 * de embeddings na tela de indexação; sem modelo, generate_embedding()
	 * devolve um erro claro.
	 */
	public function supports_embeddings(): bool {
		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function is_configured(): bool {
		return '' !== $this->get_api_base_url();
	}

	/**
	 * {@inheritdoc}
	 */
	public function test_connection(): array {
		if ( ! $this->is_configured() ) {
			return array(
				'success' => false,
				'message' => __( 'URL base da API não configurada.', 'oraculo-tainacan' ),
				'details' => array(),
			);
		}

		$models = $this->fetch_and_cache_models();

		if ( is_wp_error( $models ) ) {
			return array(
				'success' => false,
				'message' => $models->get_error_message(),
				'details' => array(),
			);
		}

		return array(
			'success' => true,
			'message' => __( 'Conexão estabelecida com sucesso!', 'oraculo-tainacan' ),
			'details' => array(
				'models_available' => count( $models ),
				'api_base_url'     => $this->get_api_base_url(),
			),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function list_remote_models() {
		if ( ! $this->is_configured() ) {
			return new WP_Error( 'not_configured', __( 'URL base da API não configurada.', 'oraculo-tainacan' ) );
		}

		$models = $this->fetch_and_cache_models();
		if ( is_wp_error( $models ) ) {
			return $models;
		}

		$list = array();
		foreach ( $models as $model ) {
			$model_id = $this->extract_model_id( $model );
			if ( '' === $model_id ) {
				continue;
			}
			$normalized              = $this->normalize_model( $model, $model_id );
			$normalized['embedding'] = $this->is_embedding_model( $model, $model_id );
			$list[]                  = $normalized;
		}

		if ( empty( $list ) ) {
			return new WP_Error( 'models_empty', __( 'O provedor não retornou modelos.', 'oraculo-tainacan' ) );
		}

		usort( $list, static fn( $a, $b ) => strcmp( $a['id'], $b['id'] ) );

		return $list;
	}

	/**
	 * {@inheritdoc}
	 */
	public function generate_embedding( string $text, ?string $model = null ) {
		if ( ! $this->is_configured() ) {
			return new WP_Error( 'not_configured', __( 'Provedor OpenAI compatível não configurado.', 'oraculo-tainacan' ) );
		}

		$text = $this->validate_input( $text, 8191 * 4 );
		if ( '' === $text ) {
			return new WP_Error( 'empty_input', __( 'Texto não pode estar vazio.', 'oraculo-tainacan' ) );
		}

		$model = $this->resolve_embedding_model( $model );
		if ( '' === $model ) {
			return new WP_Error( 'missing_embedding_model', __( 'Nenhum modelo de embedding configurado para o provedor OpenAI compatível.', 'oraculo-tainacan' ) );
		}

		$result = $this->request_embeddings_with_openwebui_fallback( $text, $model );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( empty( $result['vectors'][0] ) ) {
			return new WP_Error( 'invalid_response', __( 'Resposta inválida da API de embeddings.', 'oraculo-tainacan' ) );
		}

		return array(
			'embedding' => $result['vectors'][0],
			'model'     => $model,
			'usage'     => $result['usage'],
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function generate_embeddings_batch( array $texts, ?string $model = null ) {
		if ( ! $this->is_configured() ) {
			return new WP_Error( 'not_configured', __( 'Provedor OpenAI compatível não configurado.', 'oraculo-tainacan' ) );
		}

		if ( empty( $texts ) ) {
			return new WP_Error( 'empty_input', __( 'Lista de textos não pode estar vazia.', 'oraculo-tainacan' ) );
		}

		$clean_texts = array();
		foreach ( $texts as $text ) {
			$clean = $this->validate_input( (string) $text, 8191 * 4 );
			if ( '' !== $clean ) {
				$clean_texts[] = $clean;
			}
		}

		if ( empty( $clean_texts ) ) {
			return new WP_Error( 'empty_input', __( 'Nenhum texto válido foi informado para embeddings.', 'oraculo-tainacan' ) );
		}

		$model = $this->resolve_embedding_model( $model );
		if ( '' === $model ) {
			return new WP_Error( 'missing_embedding_model', __( 'Nenhum modelo de embedding configurado para o provedor OpenAI compatível.', 'oraculo-tainacan' ) );
		}

		$result = $this->request_embeddings_with_openwebui_fallback( $clean_texts, $model );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// O indexador casa vetor e texto pela posição: um lote com menos
		// vetores que textos gravaria vetores nos itens errados.
		if ( count( $result['vectors'] ) !== count( $clean_texts ) ) {
			return new WP_Error( 'invalid_response', __( 'Resposta inválida da API de embeddings.', 'oraculo-tainacan' ) );
		}

		return array(
			'embeddings' => $result['vectors'],
			'model'      => $model,
			'usage'      => $result['usage'],
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function generate_response( string $prompt, string $system_prompt = '', array $options = array() ) {
		return $this->chat(
			array(
				array(
					'role'    => 'user',
					'content' => $prompt,
				),
			),
			$system_prompt,
			$options
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function chat( array $messages, string $system_prompt = '', array $options = array() ) {
		if ( ! $this->is_configured() ) {
			return new WP_Error( 'not_configured', __( 'Provedor OpenAI compatível não configurado.', 'oraculo-tainacan' ) );
		}

		$options = $this->prepare_options( $options );
		$model   = (string) ( $options['model'] ?? $this->get_config( 'model', '' ) );
		if ( '' === $model ) {
			return new WP_Error( 'missing_model', __( 'Nenhum modelo de chat configurado para o provedor OpenAI compatível.', 'oraculo-tainacan' ) );
		}

		$response = $this->make_request_with_retry(
			$this->get_api_base_url() . '/chat/completions',
			array(
				'model'       => $model,
				'messages'    => $this->format_messages( $messages, $system_prompt ),
				'max_tokens'  => $options['max_tokens'],
				'temperature' => $options['temperature'],
			),
			$this->get_headers()
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( ! isset( $response['choices'][0]['message']['content'] ) ) {
			return new WP_Error( 'invalid_response', __( 'Resposta inválida da API.', 'oraculo-tainacan' ) );
		}

		$finish_reason = $response['choices'][0]['finish_reason'] ?? 'stop';

		// Mesmo cuidado do OpenAIProvider: modelo de raciocínio que esgota o
		// teto antes de escrever devolve content vazio — sem o erro, a busca
		// mostraria (e guardaria em cache) uma resposta em branco.
		if ( '' === trim( (string) $response['choices'][0]['message']['content'] ) && 'length' === $finish_reason ) {
			return new WP_Error(
				'empty_response_length',
				__( 'O modelo de IA esgotou o limite de tokens antes de responder. Aumente o limite de tokens nas configurações ou escolha outro modelo.', 'oraculo-tainacan' )
			);
		}

		return array(
			'response'      => $response['choices'][0]['message']['content'],
			'model'         => $model,
			'usage'         => $this->normalize_usage( $response['usage'] ?? array() ),
			'cost'          => 0,
			'finish_reason' => $finish_reason,
		);
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
	public function stream_response( string $prompt, string $system_prompt, callable $callback, array $options = array() ): void {
		if ( ! $this->is_configured() ) {
			$callback( '', true, new WP_Error( 'not_configured', __( 'Provedor não configurado.', 'oraculo-tainacan' ) ) );
			return;
		}

		$options = $this->prepare_options( $options );
		$model   = (string) ( $options['model'] ?? $this->get_config( 'model', '' ) );
		if ( '' === $model ) {
			$callback( '', true, new WP_Error( 'missing_model', __( 'Nenhum modelo de chat configurado para o provedor OpenAI compatível.', 'oraculo-tainacan' ) ) );
			return;
		}

		$body = array(
			'model'       => $model,
			'messages'    => $this->format_messages(
				array(
					array(
						'role'    => 'user',
						'content' => $prompt,
					),
				),
				$system_prompt
			),
			'max_tokens'  => $options['max_tokens'],
			'temperature' => $options['temperature'],
			'stream'      => true,
		);

		$headers = array( 'Content-Type: application/json' );
		foreach ( $this->get_headers() as $name => $value ) {
			$headers[] = $name . ': ' . $value;
		}

        // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_init -- WP HTTP API lacks streaming callback support required for SSE.
		$ch = curl_init( $this->get_api_base_url() . '/chat/completions' );

        // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt_array -- WP HTTP API lacks streaming callback support required for SSE.
		curl_setopt_array(
			$ch,
			array(
				CURLOPT_POST           => true,
				CURLOPT_POSTFIELDS     => wp_json_encode( $body ),
				CURLOPT_HTTPHEADER     => $headers,
				CURLOPT_RETURNTRANSFER => false,
				CURLOPT_WRITEFUNCTION  => function ( $ch, $data ) use ( $callback ) {
					$lines = explode( "\n", (string) $data );
					foreach ( $lines as $line ) {
						if ( strpos( $line, 'data: ' ) !== 0 ) {
							continue;
						}
						$json = substr( $line, 6 );
						if ( '[DONE]' === $json ) {
							$callback( '', true );
							return strlen( $data );
						}
						$decoded = json_decode( $json, true );
						if ( isset( $decoded['choices'][0]['delta']['content'] ) ) {
							$callback( $decoded['choices'][0]['delta']['content'], false );
						}
					}
					return strlen( $data );
				},
				CURLOPT_TIMEOUT        => $this->timeout,
			)
		);

        // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_exec -- WP HTTP API lacks streaming callback support required for SSE.
		curl_exec( $ch );

        // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_errno -- WP HTTP API lacks streaming callback support required for SSE.
		if ( curl_errno( $ch ) ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_error -- WP HTTP API lacks streaming callback support required for SSE.
			$callback( '', true, new WP_Error( 'curl_error', curl_error( $ch ) ) );
		}

        // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_close -- WP HTTP API lacks streaming callback support required for SSE.
		curl_close( $ch );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_pricing( string $model ): array {
		return array();
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_model_limit( string $model ): int {
		return 131072;
	}

	/**
	 * Obtém URL base da API
	 *
	 * @return string
	 */
	private function get_api_base_url(): string {
		return rtrim( trim( (string) $this->get_config( 'api_base_url', '' ) ), '/' );
	}

	/**
	 * Obtém headers para requisições (Authorization só com chave)
	 *
	 * @return array
	 */
	private function get_headers(): array {
		$headers = array();
		$api_key = trim( $this->get_api_key() );

		if ( '' !== $api_key ) {
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
	private function resolve_embedding_model( ?string $requested_model ): string {
		if ( is_string( $requested_model ) && '' !== trim( $requested_model ) ) {
			return trim( $requested_model );
		}

		$configured = trim( (string) $this->get_config( 'embedding_model', '' ) );
		if ( '' !== $configured ) {
			return $configured;
		}

		$models = $this->get_embedding_models();
		return $models[0]['id'] ?? '';
	}

	/**
	 * Modelos remotos já em cache (nunca vai à rede)
	 *
	 * @return array
	 */
	private function get_cached_models(): array {
		if ( ! $this->is_configured() ) {
			return array();
		}

		$cached = get_transient( $this->get_models_cache_key() );
		return is_array( $cached ) ? $cached : array();
	}

	/**
	 * Consulta /models e guarda o resultado em cache
	 *
	 * @return array|WP_Error
	 */
	private function fetch_and_cache_models() {
		$response = $this->make_request(
			$this->get_api_base_url() . '/models',
			array(),
			$this->get_headers(),
			'GET'
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$models = $this->extract_models_payload( $response );
		set_transient( $this->get_models_cache_key(), $models, self::MODELS_CACHE_TTL );

		return $models;
	}

	/**
	 * Extrai lista de modelos de diferentes formatos de payload
	 *
	 * @param array $response
	 * @return array
	 */
	private function extract_models_payload( array $response ): array {
		foreach ( array( 'data', 'items', 'models' ) as $key ) {
			if ( isset( $response[ $key ] ) && is_array( $response[ $key ] ) ) {
				return array_values( array_filter( $response[ $key ], 'is_array' ) );
			}
		}
		return array();
	}

	/**
	 * Normaliza metadados de um modelo
	 *
	 * @param array  $model
	 * @param string $model_id
	 * @return array
	 */
	private function normalize_model( array $model, string $model_id ): array {
		return array(
			'id'          => $model_id,
			'name'        => sanitize_text_field( (string) ( $model['name'] ?? $model_id ) ),
			'description' => sanitize_text_field( (string) ( $model['description'] ?? '' ) ),
		);
	}

	/**
	 * Extrai ID do modelo suportando diferentes payloads
	 *
	 * @param array $model
	 * @return string
	 */
	private function extract_model_id( array $model ): string {
		foreach ( array( 'id', 'model', 'base_model_id' ) as $key ) {
			$id = trim( (string) ( $model[ $key ] ?? '' ) );
			if ( '' !== $id ) {
				return $id;
			}
		}
		return '';
	}

	/**
	 * Verifica se um modelo é de embedding
	 *
	 * Usa o tipo/capacidades declarados quando o servidor os informa (Open
	 * WebUI, LiteLLM) e, na falta deles, o nome do modelo.
	 *
	 * @param array  $model
	 * @param string $model_id
	 * @return bool
	 */
	private function is_embedding_model( array $model, string $model_id ): bool {
		if ( 'embedding' === strtolower( (string) ( $model['type'] ?? '' ) ) ) {
			return true;
		}

		$capabilities = $model['meta']['capabilities'] ?? $model['capabilities'] ?? array();
		if ( is_array( $capabilities ) ) {
			if ( ! empty( $capabilities['embeddings'] ) || ! empty( $capabilities['embedding'] ) ) {
				return true;
			}
			if ( in_array( 'embedding', $capabilities, true ) || in_array( 'embeddings', $capabilities, true ) ) {
				return true;
			}
		}

		$model_id = strtolower( $model_id );
		foreach ( array( 'embedding', 'embed-', 'text-embedding', 'nomic-embed', 'mxbai-embed', 'bge-', 'all-minilm' ) as $pattern ) {
			if ( false !== strpos( $model_id, $pattern ) ) {
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
		return self::MODELS_CACHE_PREFIX . md5( $this->get_api_base_url() );
	}

	/**
	 * Requisição de embeddings com fallback para Open WebUI + Ollama
	 *
	 * O Open WebUI expõe chat em /api, mas não /api/embeddings; quando a URL
	 * base termina em /api e o endpoint padrão dá 404 (ou não traz vetores),
	 * tenta o proxy do Ollama que o próprio Open WebUI publica.
	 *
	 * @param string|array $input
	 * @param string       $model
	 * @return array|WP_Error
	 */
	private function request_embeddings_with_openwebui_fallback( $input, string $model ) {
		\Oraculo_Tainacan\debug_log( 'GenericOpenAI embedding -> ' . $this->get_api_base_url() . '/embeddings | model=' . $model . ' | ' . ( is_array( $input ) ? 'batch' : 'single' ) );

		$response = $this->make_request_with_retry(
			$this->get_api_base_url() . '/embeddings',
			array(
				'model' => $model,
				'input' => $input,
			),
			$this->get_headers()
		);

		$vectors = array();
		if ( ! is_wp_error( $response ) ) {
			$vectors = $this->extract_vectors_from_embedding_response( $response );
			if ( ! empty( $vectors ) ) {
				return array(
					'vectors' => $vectors,
					'usage'   => $this->normalize_usage( $response['usage'] ?? array() ),
				);
			}
		}

		$should_try_fallback = true;
		if ( is_wp_error( $response ) ) {
			$error_data          = $response->get_error_data();
			$status              = is_array( $error_data ) ? (int) ( $error_data['status'] ?? 0 ) : 0;
			$should_try_fallback = 404 === $status;
		}

		if ( ! $should_try_fallback || ! $this->looks_like_openwebui_api() ) {
			return is_wp_error( $response )
				? $response
				: new WP_Error( 'invalid_response', __( 'Resposta inválida da API de embeddings.', 'oraculo-tainacan' ) );
		}

		$fallback_response = $this->make_request_with_retry(
			$this->get_openwebui_origin() . '/ollama/api/embed',
			array(
				'model' => $model,
				'input' => $input,
			),
			$this->get_headers()
		);

		if ( is_wp_error( $fallback_response ) ) {
			return $fallback_response;
		}

		$fallback_vectors = $this->extract_vectors_from_embedding_response( $fallback_response );
		if ( empty( $fallback_vectors ) ) {
			return new WP_Error( 'invalid_response', __( 'Resposta inválida da API de embeddings.', 'oraculo-tainacan' ) );
		}

		return array(
			'vectors' => $fallback_vectors,
			'usage'   => array(),
		);
	}

	/**
	 * Extrai vetores de formatos OpenAI e Ollama
	 *
	 * @param array $response
	 * @return array
	 */
	private function extract_vectors_from_embedding_response( array $response ): array {
		$vectors = array();

		if ( isset( $response['data'] ) && is_array( $response['data'] ) ) {
			// A ordem do lote vem no campo index, não na posição do array.
			$data = $response['data'];
			usort( $data, static fn( $a, $b ) => ( $a['index'] ?? 0 ) <=> ( $b['index'] ?? 0 ) );
			foreach ( $data as $item ) {
				if ( ! empty( $item['embedding'] ) && is_array( $item['embedding'] ) ) {
					$vectors[] = $item['embedding'];
				}
			}
		}

		if ( isset( $response['embeddings'] ) && is_array( $response['embeddings'] ) ) {
			foreach ( $response['embeddings'] as $embedding ) {
				if ( is_array( $embedding ) ) {
					$vectors[] = $embedding;
				}
			}
		}

		if ( isset( $response['embedding'] ) && is_array( $response['embedding'] ) ) {
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
		return (bool) preg_match( '#/api/?$#', $this->get_api_base_url() );
	}

	/**
	 * Obtém origem sem sufixo /api para fallback Ollama do Open WebUI
	 *
	 * @return string
	 */
	private function get_openwebui_origin(): string {
		return rtrim( preg_replace( '#/api/?$#', '', $this->get_api_base_url() ) ?? $this->get_api_base_url(), '/' );
	}
}
