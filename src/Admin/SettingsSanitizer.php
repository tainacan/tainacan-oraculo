<?php
/**
 * Sanitização das configurações do plugin
 *
 * Allowlist por chave com limites numéricos; API keys com placeholder
 * (••••••••) preservam o valor já armazenado. Chaves ausentes na entrada
 * caem para o valor já salvo (e só então para o padrão), de modo que um
 * formulário que renderiza apenas parte das opções não apague o restante.
 *
 * Ponto único de sanitização: usado tanto pelo POST do admin
 * (wp_ajax_oraculo_save_settings) quanto pelo endpoint REST /settings.
 *
 * @package Oraculo_Tainacan
 */

declare( strict_types=1 );

namespace Oraculo_Tainacan\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sanitiza o array de opções do plugin (allowlist estrita).
 */
class SettingsSanitizer {

	/**
	 * Sanitiza configurações
	 *
	 * @param array $input Opções brutas (REST/form).
	 * @return array Opções sanitizadas.
	 */
	public static function sanitize( array $input ): array {
		$sanitized = array();
		$current   = \Oraculo_Tainacan\Oraculo_Tainacan::get_options();

		/**
		 * Valor de texto: entrada > valor salvo > padrão.
		 *
		 * @param string $key     Chave da opção.
		 * @param string $default Padrão quando nem entrada nem valor salvo existem.
		 * @return string
		 */
		$text = static function ( string $key, string $default ) use ( $input, $current ): string {
			$value = $input[ $key ] ?? $current[ $key ] ?? $default;
			return sanitize_text_field( (string) $value );
		};

		// Provider
		$sanitized['ai_provider'] = $text( 'ai_provider', 'openai' );

		// API Keys - só atualizar se não for placeholder
		$api_keys = array(
			'openai_api_key',
			'gemini_api_key',
			'deepseek_api_key',
			'groq_api_key',
			'claude_api_key',
			'generic_openai_api_key',
		);

		foreach ( $api_keys as $key ) {
			if ( isset( $input[ $key ] ) && $input[ $key ] !== '••••••••' && ! empty( $input[ $key ] ) ) {
				$raw = sanitize_text_field( (string) $input[ $key ] );

				// Idempotência obrigatória: register_settings() registra este
				// sanitizer como sanitize_callback da opção, então TODO
				// update_option() o executa de novo sobre o valor já sanitizado.
				// Sem esta guarda, o save do admin criptografava duas vezes
				// (manual + callback) e o get_api_key() de uma passada só
				// mandava "enc:..." literal para o provedor.
				$sanitized[ $key ] = ( 0 === strpos( $raw, 'enc:' ) )
					? $raw
					: 'enc:' . \Oraculo_Tainacan\encrypt_value( $raw );
			} else {
				// Placeholder ou campo vazio: mantém o valor já salvo (já
				// criptografado, se veio de um save feito após esta mudança).
				$sanitized[ $key ] = $current[ $key ] ?? '';
			}
		}

		// Modelos
		$sanitized['openai_model']           = $text( 'openai_model', 'gpt-4.1-mini' );
		$sanitized['openai_embedding_model'] = $text( 'openai_embedding_model', 'text-embedding-3-small' );
		$sanitized['gemini_model']           = $text( 'gemini_model', 'gemini-2.5-flash' );
		$sanitized['deepseek_model']         = $text( 'deepseek_model', 'deepseek-chat' );
		$sanitized['ollama_model']           = $text( 'ollama_model', 'llama3.2' );
		$sanitized['ollama_embedding_model'] = $text( 'ollama_embedding_model', 'nomic-embed-text' );
		$sanitized['groq_model']             = $text( 'groq_model', 'llama-3.3-70b-versatile' );
		$sanitized['claude_model']           = $text( 'claude_model', 'claude-sonnet-5' );

		$sanitized['ollama_url'] = esc_url_raw(
			(string) ( $input['ollama_url'] ?? $current['ollama_url'] ?? 'http://localhost:11434' )
		);

		// Provedor compatível com OpenAI: URL própria e modelos de nome livre
		// (vêm do /models do servidor configurado, não de um catálogo fixo).
		$sanitized['generic_openai_api_base_url']    = esc_url_raw(
			(string) ( $input['generic_openai_api_base_url'] ?? $current['generic_openai_api_base_url'] ?? '' )
		);
		$sanitized['generic_openai_model']           = $text( 'generic_openai_model', '' );
		$sanitized['generic_openai_embedding_model'] = $text( 'generic_openai_embedding_model', '' );

		// Estratégia de RAG: local (vetores do Oráculo), provider (sem
		// recuperação local — o provedor responde sozinho, ex.: Open WebUI com
		// base de conhecimento) ou hybrid (local; se o embedding ou a busca
		// vetorial falharem, segue sem contexto em vez de devolver erro).
		$rag_strategy              = $text( 'rag_strategy', 'local' );
		$sanitized['rag_strategy'] = in_array( $rag_strategy, array( 'local', 'provider', 'hybrid' ), true ) ? $rag_strategy : 'local';

		// Parâmetros numéricos
		$sanitized['max_tokens'] = absint( $input['max_tokens'] ?? $current['max_tokens'] ?? 2000 );
		$sanitized['max_tokens'] = max( 100, min( 16000, $sanitized['max_tokens'] ) );

		$sanitized['temperature'] = floatval( $input['temperature'] ?? $current['temperature'] ?? 0.7 );
		$sanitized['temperature'] = max( 0, min( 2, $sanitized['temperature'] ) );

		$sanitized['similarity_threshold'] = floatval( $input['similarity_threshold'] ?? $current['similarity_threshold'] ?? 0.3 );
		$sanitized['similarity_threshold'] = max( 0, min( 1, $sanitized['similarity_threshold'] ) );

		$sanitized['max_results'] = absint( $input['max_results'] ?? $current['max_results'] ?? 10 );
		$sanitized['max_results'] = max( 1, min( 50, $sanitized['max_results'] ) );

		$sanitized['batch_size'] = absint( $input['batch_size'] ?? $current['batch_size'] ?? 25 );
		$sanitized['batch_size'] = max( 5, min( 100, $sanitized['batch_size'] ) );

		// Limites de uso (tela Diagnóstico). Zero no global desliga o teto;
		// por visitante, zero também desliga, como no filtro de sempre.
		// Padrão 60 só para quem atualizou sem salvar: um save qualquer não pode
		// quintuplicar o teto em silêncio. Instalação nova grava 300 na ativação.
		$sanitized['rate_limit_global'] = min( 10000, absint( $input['rate_limit_global'] ?? $current['rate_limit_global'] ?? 60 ) );
		$sanitized['rate_limit_search'] = min( 1000, absint( $input['rate_limit_search'] ?? $current['rate_limit_search'] ?? 10 ) );
		$sanitized['rate_limit_chat']   = min( 1000, absint( $input['rate_limit_chat'] ?? $current['rate_limit_chat'] ?? 20 ) );

		// Redes liberadas: um IP ou CIDR por linha; o que não for endereço sai.
		$allow                             = preg_split( '/[\s,]+/', (string) ( $input['rate_limit_allowlist'] ?? $current['rate_limit_allowlist'] ?? '' ) );
		$allow                             = array_filter(
			(array) $allow,
			static function ( $entry ): bool {
				$parts = explode( '/', (string) $entry, 2 );
				if ( false === filter_var( $parts[0], FILTER_VALIDATE_IP ) ) {
					return false;
				}
				$max = false !== strpos( $parts[0], ':' ) ? 128 : 32;
				return ! isset( $parts[1] ) || ( ctype_digit( $parts[1] ) && (int) $parts[1] <= $max );
			}
		);
		$sanitized['rate_limit_allowlist'] = implode( "\n", array_slice( array_values( array_unique( $allow ) ), 0, 100 ) );

		$sanitized['request_timeout'] = absint( $input['request_timeout'] ?? $current['request_timeout'] ?? 120 );
		$sanitized['cache_duration']  = absint( $input['cache_duration'] ?? $current['cache_duration'] ?? 3600 );

		// Booleanos de checkbox: a CHAVE presente decide (o formulário envia
		// sempre a chave, via input hidden com valor 0, então desmarcar
		// continua funcionando). Chave AUSENTE não é "desmarcado" — é um save
		// parcial/programático (o sanitize_callback do register_setting roda
		// em todo update_option), e aí o valor salvo é preservado. Sem isso,
		// qualquer gravação parcial desligava chat/busca silenciosamente.
		$bool = static function ( string $key, bool $default ) use ( $input, $current ): bool {
			if ( array_key_exists( $key, $input ) ) {
				return ! empty( $input[ $key ] );
			}
			return ! empty( $current[ $key ] ?? $default );
		};

		$sanitized['enable_chat']      = $bool( 'enable_chat', true );
		$sanitized['enable_search']    = $bool( 'enable_search', true );
		$sanitized['enable_analytics'] = $bool( 'enable_analytics', true );
		$sanitized['enable_feedback']  = $bool( 'enable_feedback', true );
		$sanitized['debug_mode']       = $bool( 'debug_mode', false );

		// Coleções
		$collections                      = $input['default_collections'] ?? $current['default_collections'] ?? array();
		$sanitized['default_collections'] = array_values( array_map( 'absint', (array) $collections ) );

		// Prompts
		$sanitized['system_prompt']   = wp_kses_post( (string) ( $input['system_prompt'] ?? $current['system_prompt'] ?? '' ) );
		$sanitized['search_prompt']   = wp_kses_post( (string) ( $input['search_prompt'] ?? $current['search_prompt'] ?? '' ) );
		$sanitized['chat_prompt']     = wp_kses_post( (string) ( $input['chat_prompt'] ?? $current['chat_prompt'] ?? '' ) );
		$sanitized['welcome_message'] = sanitize_textarea_field( (string) ( $input['welcome_message'] ?? $current['welcome_message'] ?? '' ) );

		// Perguntas sugeridas
		$questions                        = $input['suggested_questions'] ?? $current['suggested_questions'] ?? array();
		$sanitized['suggested_questions'] = array_values(
			array_filter( array_map( 'sanitize_text_field', (array) $questions ) )
		);

		// Campos de indexação
		$index_fields              = $input['index_fields'] ?? $current['index_fields'] ?? array( 'title', 'description' );
		$sanitized['index_fields'] = array_values( array_map( 'sanitize_text_field', (array) $index_fields ) );

		// Busca visual (AI API CLIP do IBRAM)
		// O backend de busca é derivado do provedor selecionado: o CLIP virou
		// um card de provedor como os demais (o select "Backend de Busca" da
		// aba Geral foi removido). Derivar aqui também migra configurações
		// antigas: quem tinha search_backend=clip salvo mas escolher outro
		// provedor no card volta ao backend local automaticamente.
		$sanitized['search_backend'] = ( 'clip' === $sanitized['ai_provider'] ) ? 'clip' : 'local';
		$sanitized['clip_api_url']   = esc_url_raw( $input['clip_api_url'] ?? '' );
		$sanitized['clip_api_model'] = sanitize_text_field( $input['clip_api_model'] ?? 'ViT-L-14' );

		$sanitized['clip_api_timeout'] = absint( $input['clip_api_timeout'] ?? 60 );
		$sanitized['clip_api_timeout'] = max( 5, min( 300, $sanitized['clip_api_timeout'] ) );

		// Aparência
		$appearance              = $input['appearance'] ?? $current['appearance'] ?? array();
		$sanitized['appearance'] = \Oraculo_Tainacan\sanitize_appearance( (array) $appearance );

		// Integração com o tema Tainacan
		$sanitized['theme_integration'] = self::sanitize_theme_integration(
			(array) ( $input['theme_integration'] ?? $current['theme_integration'] ?? array() ),
			(array) ( $current['theme_integration'] ?? array() )
		);

		return $sanitized;
	}

	/**
	 * Sanitiza o bloco de integração com o tema Tainacan.
	 *
	 * A mesma regra dos booleanos do nível superior: chave presente decide
	 * (o formulário sempre envia enabled via input hidden), chave ausente
	 * preserva o valor salvo e só então cai no padrão. Sem isso, o
	 * sanitize_callback do register_setting — que roda em todo update_option —
	 * materializava enabled=false num save que não trazia o sub-array, e a
	 * aba "Busca com IA" sumia do site sem ninguém ter desmarcado nada.
	 *
	 * @param array $input   Sub-array theme_integration da entrada (ou o salvo, se ausente).
	 * @param array $current Sub-array theme_integration já armazenado.
	 * @return array
	 */
	private static function sanitize_theme_integration( array $input, array $current = array() ): array {
		$defaults = \Oraculo_Tainacan\Frontend\ThemeIntegration::get_default_settings();

		$tab_label   = sanitize_text_field( (string) ( $input['tab_label'] ?? $current['tab_label'] ?? '' ) );
		$placeholder = sanitize_text_field( (string) ( $input['placeholder'] ?? $current['placeholder'] ?? '' ) );
		$scope       = (string) ( $input['scope'] ?? $current['scope'] ?? '' );

		$flag = static function ( string $key ) use ( $input, $current, $defaults ): bool {
			if ( array_key_exists( $key, $input ) ) {
				return ! empty( $input[ $key ] );
			}
			return ! empty( $current[ $key ] ?? $defaults[ $key ] );
		};

		return array(
			'enabled'          => $flag( 'enabled' ),
			'tab_label'        => '' !== $tab_label ? $tab_label : $defaults['tab_label'],
			'placeholder'      => '' !== $placeholder ? $placeholder : $defaults['placeholder'],
			'scope'            => in_array( $scope, array( 'collection', 'all' ), true ) ? $scope : 'collection',
			'show_suggestions' => $flag( 'show_suggestions' ),
		);
	}
}
