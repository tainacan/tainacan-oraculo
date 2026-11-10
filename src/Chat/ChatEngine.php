<?php
/**
 * Motor de Chat com Contexto
 *
 * @package Oraculo_Tainacan
 */

declare( strict_types=1 );

namespace Oraculo_Tainacan\Chat;

use Oraculo_Tainacan\AI\AIProviderFactory;
use Oraculo_Tainacan\Search\SearchEngine;
use WP_Error;

/**
 * Motor de chat conversacional com RAG
 */
class ChatEngine {

	/**
	 * Suffixes das tabelas (sem prefixo do WordPress).
	 * Constantes de classe: garantem que os nomes das tabelas
	 * nunca podem vir de input do usuário em tempo de execução.
	 */
	private const TABLE_CONVERSATIONS = 'oraculo_conversations';
	private const TABLE_MESSAGES      = 'oraculo_messages';

	/**
	 * Factory de provedores de IA
	 *
	 * @var AIProviderFactory
	 */
	private AIProviderFactory $ai_factory;

	/**
	 * Motor de busca
	 *
	 * @var SearchEngine
	 */
	private SearchEngine $search_engine;

	/**
	 * Opções do plugin
	 *
	 * @var array
	 */
	private array $options;

	/**
	 * Nome das tabelas
	 *
	 * @var string
	 */
	private string $conversations_table;
	private string $messages_table;

	/**
	 * Construtor
	 */
	public function __construct() {
		global $wpdb;

		$this->ai_factory          = new AIProviderFactory();
		$this->search_engine       = new SearchEngine();
		$this->options             = \Oraculo_Tainacan\Oraculo_Tainacan::get_options();
		$this->conversations_table = $wpdb->prefix . self::TABLE_CONVERSATIONS;
		$this->messages_table      = $wpdb->prefix . self::TABLE_MESSAGES;
	}

	/**
	 * Processa mensagem de chat
	 *
	 * @param string $message Mensagem do usuário
	 * @param string $session_id ID da sessão (opcional, será criado se não existir)
	 * @param array  $collection_ids IDs das coleções para busca
	 * @param array  $options Opções adicionais
	 * @return array|WP_Error
	 */
	public function chat( string $message, string $session_id = '', array $collection_ids = array(), array $options = array() ) {
		$start_time = microtime( true );

		// Validar mensagem
		$message = trim( $message );
		if ( empty( $message ) ) {
			return new WP_Error( 'empty_message', __( 'A mensagem não pode estar vazia.', 'oraculo-tainacan' ) );
		}

		// Obter ou criar conversa
		if ( empty( $session_id ) ) {
			$session_id = \Oraculo_Tainacan\generate_session_id();
		}

		$conversation = $this->get_or_create_conversation( $session_id, $collection_ids );

		// O session_id vem do localStorage do navegador. Se a conversa é de
		// outro usuário (saiu da conta, outra pessoa entrou no mesmo navegador),
		// não continuar o histórico alheio: abre sessão nova, que volta ao
		// cliente em session_id e substitui a guardada.
		if ( ! is_wp_error( $conversation ) && get_current_user_id() !== (int) $conversation['user_id'] ) {
			$session_id   = \Oraculo_Tainacan\generate_session_id();
			$conversation = $this->get_or_create_conversation( $session_id, $collection_ids );
		}

		if ( is_wp_error( $conversation ) ) {
			return $conversation;
		}

		try {
			// Histórico ANTES de salvar a mensagem atual: assim ele contém só
			// os turnos anteriores. Salvar primeiro fazia a mensagem aparecer
			// duplicada no prompt (no HISTÓRICO e em "MENSAGEM DO USUÁRIO") e
			// roubava um dos 10 slots de contexto real da conversa.
			$history = $this->get_conversation_history( $conversation['id'], 10 );

			// Salvar mensagem do usuário
			$this->save_message( $conversation['id'], 'user', $message );

			// Itens do acervo usados como contexto (vêm de answer() ou do cache).
			$sources = array();

			// Primeira mensagem de uma conversa: sem histórico, a mesma pergunta
			// nas mesmas coleções tem a mesma resposta. Numa aula ou num evento
			// muita gente abre a BIA com a mesma pergunta — e ao mesmo tempo:
			// quem chega enquanto outro pedido gera a resposta espera por ela
			// em vez de chamar a IA de novo.
			$first_key = empty( $history ) ? $this->first_message_cache_key( $message, $collection_ids ) : '';
			$cached    = '' !== $first_key ? get_transient( $first_key ) : false;
			$lock      = '';

			if ( '' !== $first_key && ! is_array( $cached ) ) {
				$lock = $this->claim_first_message( $first_key );
				if ( '' === $lock ) {
					$cached = $this->wait_first_message( $first_key );
				}
			}

			if ( is_array( $cached ) && isset( $cached['ai']['response'], $cached['sources'] ) ) {
				// Do cache não se gastou nada: zera uso e custo nas estatísticas.
				$ai_response          = $cached['ai'];
				$ai_response['usage'] = array(
					'prompt_tokens'     => 0,
					'completion_tokens' => 0,
					'total_tokens'      => 0,
				);
				$ai_response['cost']  = 0;
				$sources              = (array) $cached['sources'];
			} else {
				$cacheable   = true;
				$ai_response = $this->answer( $message, $history, $collection_ids, $options, $sources, $cacheable );

				// Resposta dada sem o acervo (busca falhou no pico) não vai para
				// o cache: outra pessoa receberia "não encontrei" por uma hora.
				if ( '' !== $first_key && $cacheable && ! is_wp_error( $ai_response ) ) {
					set_transient(
						$first_key,
						array(
							'ai'      => $ai_response,
							'sources' => $sources,
						),
						(int) ( $this->options['cache_duration'] ?? 3600 )
					);
				}
				if ( '' !== $lock ) {
					delete_option( $lock );
				}
				if ( is_wp_error( $ai_response ) ) {
					return $ai_response;
				}
			}

			// Salvar resposta
			$message_id = $this->save_message(
				$conversation['id'],
				'assistant',
				$ai_response['response'],
				$ai_response['usage']['total_tokens'] ?? 0,
				$ai_response['model'],
				$sources
			);

			// Atualizar conversa
			$this->update_conversation( $conversation['id'] );

			$response_time = round( ( microtime( true ) - $start_time ) * 1000 );

			return array(
				'session_id'       => $session_id,
				'message_id'       => $message_id,
				'response'         => $ai_response['response'],
				'sources'          => $this->format_sources_for_response( $sources ),
				'usage'            => $ai_response['usage'],
				'model'            => $ai_response['model'],
				'cost'             => $ai_response['cost'] ?? 0,
				'response_time_ms' => $response_time,
			);

		} catch ( \Exception $e ) {
			\Oraculo_Tainacan\debug_log( 'Chat error: ' . $e->getMessage(), null, 'error' );
			return new WP_Error( 'chat_error', $e->getMessage() );
		}
	}

	/**
	 * Reserva a geração de uma primeira mensagem
	 *
	 * add_option() é um INSERT numa chave única: só um pedido consegue. Uma
	 * reserva de mais de 60 s é de um pedido que morreu e é retomada.
	 *
	 * @param string $first_key Chave do cache.
	 * @return string Nome da reserva, ou '' se outro pedido já está gerando.
	 */
	private function claim_first_message( string $first_key ): string {
		$lock = $first_key . '_lock';

		if ( add_option( $lock, time(), '', false ) ) {
			return $lock;
		}

		if ( time() - (int) get_option( $lock, 0 ) > MINUTE_IN_SECONDS ) {
			delete_option( $lock );
			return add_option( $lock, time(), '', false ) ? $lock : '';
		}

		return '';
	}

	/**
	 * Espera a resposta que outro pedido está gerando (até ~15 s)
	 *
	 * @param string $first_key Chave do cache.
	 * @return array|false A resposta em cache, ou false (aí este pedido gera a sua).
	 */
	private function wait_first_message( string $first_key ) {
		$lock     = $first_key . '_lock';
		$deadline = microtime( true ) + 15;

		while ( microtime( true ) < $deadline ) {
			usleep( 300000 );

			// Sem cache de objetos, get_option guarda "não existe" na memória
			// do pedido; sem limpar, o laço nunca veria a resposta chegar.
			if ( ! wp_using_ext_object_cache() ) {
				wp_cache_delete( 'notoptions', 'options' );
				wp_cache_delete( '_transient_' . $first_key, 'options' );
				wp_cache_delete( '_transient_timeout_' . $first_key, 'options' );
				wp_cache_delete( $lock, 'options' );
			}

			$cached = get_transient( $first_key );
			if ( is_array( $cached ) ) {
				return $cached;
			}

			// O outro pedido terminou sem resposta para o cache (erro): desiste.
			if ( false === get_option( $lock ) ) {
				return false;
			}
		}

		return false;
	}

	/**
	 * Chave do cache da primeira mensagem ('' com o cache desligado)
	 *
	 * Leva provedor, modelo, prompt de sistema, idioma e a versão do índice
	 * (como a chave da busca): trocar qualquer um, ou reindexar, não
	 * reaproveita resposta antiga.
	 *
	 * @param string $message        Mensagem.
	 * @param int[]  $collection_ids Coleções.
	 * @return string
	 */
	private function first_message_cache_key( string $message, array $collection_ids ): string {
		if ( (int) ( $this->options['cache_duration'] ?? 3600 ) <= 0 ) {
			return '';
		}

		$collection_ids = array_map( 'intval', $collection_ids );
		sort( $collection_ids );
		$provider = (string) ( $this->options['ai_provider'] ?? '' );

		return 'oraculo_chat1_' . md5(
			(string) wp_json_encode(
				array(
					mb_strtolower( preg_replace( '/\s+/u', ' ', $message ) ),
					$collection_ids,
					$provider,
					$this->options[ $provider . '_model' ] ?? '',
					$this->get_chat_system_prompt(),
					$this->options['max_results'] ?? 10,
					$this->options['rag_strategy'] ?? 'local',
					determine_locale(),
					\Oraculo_Tainacan\get_index_version(),
				)
			)
		);
	}

	/**
	 * Busca o contexto e gera a resposta da BIA
	 *
	 * @param string $message        Mensagem.
	 * @param array  $history        Turnos anteriores.
	 * @param int[]  $collection_ids Coleções.
	 * @param array  $options        Opções da chamada.
	 * @param array  $sources        Saída: itens usados como contexto.
	 * @param bool   $cacheable      Saída: false quando a busca no acervo falhou.
	 * @return array|WP_Error Resposta do provedor.
	 */
	private function answer( string $message, array $history, array $collection_ids, array $options, array &$sources, bool &$cacheable ) {
		$context = '';

		// Estratégia de RAG 'provider' (tainacan-oraculo#4): a conversa vai
		// direto ao provedor, sem consultar os vetores. 'local' e 'hybrid' se
		// comportam igual aqui: a BIA já segue sem contexto se a busca falha.
		$rag_from_provider = 'provider' === ( $this->options['rag_strategy'] ?? 'local' );

		if ( ! $rag_from_provider && $this->should_search_context( $message, $history ) ) {
			// Follow-ups não têm âncora semântica sozinhos ("e quem
			// escreveu?"): o embedding só da mensagem atual não recupera
			// nada. A query de busca leva junto as últimas perguntas do
			// usuário, para a continuação herdar o assunto da conversa.
			$search_query = $this->build_search_query( $message, $history );

			// Mesmo número de itens da aba "Busca com IA" (com 5, a BIA via só
			// metade do que a busca mostra), com teto de 10: cada item vira
			// ~300 caracteres de contexto em todo turno da conversa.
			$chat_limit     = max( 1, min( 10, (int) ( $this->options['max_results'] ?? 10 ) ) );
			$search_results = $this->search_engine->semantic_search( $search_query, $collection_ids, $chat_limit );

			if ( is_wp_error( $search_results ) ) {
				$cacheable = false;
			} elseif ( ! empty( $search_results ) ) {
				$context = $this->format_context( $search_results );
				$sources = $search_results;
			}
		}

		// Construir prompt
		$system_prompt = $this->get_chat_system_prompt();
		$chat_prompt   = $this->build_chat_prompt( $message, $context, $history );

		// Gerar resposta
		$chat_provider = $this->ai_factory->create_from_options();

		return $chat_provider->generate_response(
			$chat_prompt,
			$system_prompt,
			array(
				'max_tokens'  => $options['max_tokens'] ?? $this->options['max_tokens'] ?? 2000,
				'temperature' => $options['temperature'] ?? $this->options['temperature'] ?? 0.7,
			)
		);
	}

	/**
	 * Obtém ou cria conversa
	 *
	 * @param string $session_id
	 * @param array  $collection_ids
	 * @return array|WP_Error
	 */
	private function get_or_create_conversation( string $session_id, array $collection_ids ) {
		global $wpdb;

		// Buscar conversa existente
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- $this->conversations_table is $wpdb->prefix . self::TABLE_CONVERSATIONS (class constant; cannot receive user input); table identifier cannot be parameterized.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- session lookup per request; caching per-request only.
		$conversation = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->conversations_table} WHERE session_id = %s AND status = 'active'",
				$session_id
			),
			ARRAY_A
		);
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter

		if ( $conversation ) {
			// O wpdb devolve tudo como string; com strict_types no arquivo,
			// passar esse id a métodos tipados (save_message etc.) é TypeError
			// fatal — o chat quebrava na SEGUNDA mensagem de toda sessão, quando
			// a conversa já existia e o id vinha do banco em vez do insert_id.
			$conversation['id']      = (int) $conversation['id'];
			$conversation['user_id'] = (int) ( $conversation['user_id'] ?? 0 );
			return $conversation;
		}

		// Criar nova conversa
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom plugin table; no WP core API available.
		$result = $wpdb->insert(
			$this->conversations_table,
			array(
				'session_id'     => $session_id,
				'user_id'        => get_current_user_id(),
				'collection_ids' => wp_json_encode( $collection_ids ),
				'status'         => 'active',
				'created_at'     => current_time( 'mysql' ),
			),
			array( '%s', '%d', '%s', '%s', '%s' )
		);

		if ( $result === false ) {
			return new WP_Error( 'db_error', __( 'Falha ao criar conversa.', 'oraculo-tainacan' ) );
		}

		return array(
			'id'             => $wpdb->insert_id,
			'session_id'     => $session_id,
			'user_id'        => get_current_user_id(),
			'collection_ids' => $collection_ids,
		);
	}

	/**
	 * Salva mensagem
	 *
	 * @param int    $conversation_id
	 * @param string $role
	 * @param string $content
	 * @param int    $tokens
	 * @param string $model
	 * @param array  $sources
	 * @return int|false ID da mensagem ou false
	 */
	private function save_message(
		int $conversation_id,
		string $role,
		string $content,
		int $tokens = 0,
		string $model = '',
		array $sources = array()
	) {
		global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom plugin table; no WP core API available.
		$result = $wpdb->insert(
			$this->messages_table,
			array(
				'conversation_id' => $conversation_id,
				'role'            => $role,
				'content'         => $content,
				'tokens_used'     => $tokens,
				'model_used'      => $model,
				'sources_json'    => ! empty( $sources ) ? wp_json_encode( $sources ) : null,
				'created_at'      => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%d', '%s', '%s', '%s' )
		);
		\Oraculo_Tainacan\oraculo_tainacan_flush_cache();

		return $result !== false ? $wpdb->insert_id : false;
	}

	/**
	 * Obtém histórico da conversa
	 *
	 * @param int $conversation_id
	 * @param int $limit
	 * @return array
	 */
	private function get_conversation_history( int $conversation_id, int $limit = 10 ): array {
		global $wpdb;

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- $this->messages_table is $wpdb->prefix . self::TABLE_MESSAGES (class constant; cannot receive user input); table identifier cannot be parameterized.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- history fetched per-request for active chat.
		$messages = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT role, content FROM {$this->messages_table}
             WHERE conversation_id = %d
             ORDER BY id DESC
             LIMIT %d",
				$conversation_id,
				$limit
			),
			ARRAY_A
		);
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter

		// Inverter para ordem cronológica
		return array_reverse( $messages );
	}

	/**
	 * Atualiza timestamp da conversa
	 *
	 * @param int $conversation_id
	 */
	private function update_conversation( int $conversation_id ): void {
		global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table (oraculo_conversations); UPDATE write operation; caching N/A.
		$wpdb->update(
			$this->conversations_table,
			array( 'updated_at' => current_time( 'mysql' ) ),
			array( 'id' => $conversation_id ),
			array( '%s' ),
			array( '%d' )
		);
		\Oraculo_Tainacan\oraculo_tainacan_flush_cache();
	}

	/**
	 * Verifica se deve buscar contexto para esta mensagem
	 *
	 * @param string $message
	 * @param array  $history
	 * @return bool
	 */
	private function should_search_context( string $message, array $history ): bool {
		// Sempre buscar se não há histórico
		if ( empty( $history ) ) {
			return true;
		}

		// Só não busca em acknowledgements puros ("ok", "obrigado"...). Todo o
		// resto busca — inclusive follow-ups curtos ("e o autor dele?"), que a
		// heurística antiga de contagem de palavras descartava: são justamente
		// as perguntas de continuação que mais precisam de contexto do acervo,
		// e a query aumentada (build_search_query) lhes dá âncora semântica.
		$simple_followups = array( 'sim', 'não', 'nao', 'ok', 'certo', 'entendi', 'obrigado', 'obrigada', 'valeu', 'legal', 'blz', 'beleza' );

		return ! in_array( strtolower( trim( $message ) ), $simple_followups, true );
	}

	/**
	 * Monta a query de recuperação semântica levando o assunto da conversa
	 *
	 * O embedding da mensagem isolada funciona para perguntas completas, mas
	 * follow-ups referenciais ("e quem escreveu?", "me fale mais sobre ele")
	 * não carregam o assunto — a busca voltava vazia e a resposta perdia o
	 * fundamento no acervo exatamente nas continuações. Anexar as últimas
	 * perguntas do usuário dá a âncora; quando a mensagem atual já é longa e
	 * específica, ela domina o embedding e o sufixo não atrapalha.
	 *
	 * @param string $message Mensagem atual.
	 * @param array  $history Histórico em ordem cronológica (role/content).
	 * @return string
	 */
	private function build_search_query( string $message, array $history ): string {
		if ( empty( $history ) ) {
			return $message;
		}

		// Últimas 2 perguntas do usuário, mais recente primeiro, cap curto:
		// é âncora de assunto, não um segundo corpo de busca.
		$previous = array();
		foreach ( array_reverse( $history ) as $msg ) {
			if ( ( $msg['role'] ?? '' ) === 'user' && '' !== trim( (string) $msg['content'] ) ) {
				$previous[] = \Oraculo_Tainacan\truncate_text( (string) $msg['content'], 150 );
				if ( count( $previous ) >= 2 ) {
					break;
				}
			}
		}

		if ( empty( $previous ) ) {
			return $message;
		}

		// Contexto anterior primeiro, pergunta atual por último (mais peso ao
		// final na maioria dos encoders de embedding é irrelevante; o que
		// importa é o assunto presente no texto).
		return implode( ' ', array_reverse( $previous ) ) . ' ' . $message;
	}

	/**
	 * Formata contexto para o prompt
	 *
	 * @param array $items
	 * @return string
	 */
	private function format_context( array $items ): string {
		if ( empty( $items ) ) {
			return '';
		}

		$parts = array();
		foreach ( $items as $index => $item ) {
			$num     = $index + 1;
			$snippet = \Oraculo_Tainacan\truncate_text( wp_strip_all_tags( (string) ( $item['snippet'] ?? '' ) ), 300 );

			// Trechos do documento (PDF/OCR) com página: de onde vem a resposta
			// quando o assunto está no conteúdo, não nos metadados.
			foreach ( (array) ( $item['passages'] ?? array() ) as $passage ) {
				$snippet .= "\n" . sprintf(
					/* translators: 1: file and page label, 2: passage text */
					__( 'Trecho de %1$s: %2$s', 'oraculo-tainacan' ),
					$passage['label'],
					$passage['context'] ?? wp_strip_all_tags( (string) $passage['text'] )
				);
			}

			// Sem URL: o widget lista as fontes com link logo abaixo da resposta.
			$parts[] = sprintf( '[%d] %s', $num, $item['title'] . ': ' . $snippet );
		}

		return implode( "\n\n", $parts );
	}

	/**
	 * Obtém prompt do sistema para chat
	 *
	 * @return string
	 */
	private function get_chat_system_prompt(): string {
		$base_prompt = $this->options['system_prompt'] ?? '';

		/**
		 * Persona do chat. Prefixada estruturalmente (e não só no prompt
		 * default) para valer também em instalações que já têm prompts
		 * salvos de versões anteriores.
		 *
		 * @param string $persona Texto da persona.
		 */
		$persona = apply_filters(
			'oraculo_tainacan_chat_persona',
			__(
				'Você é BIA, a Bibliotecária de IA deste acervo digital. Você é acolhedora, curiosa e apaixonada pelo acervo que cuida — conversa como uma bibliotecária de referência experiente: acessível, sem jargão, mas precisa. Apresente-se como BIA quando fizer sentido (primeira interação), sem repetir a apresentação a cada resposta.',
				'oraculo-tainacan'
			)
		);

		$chat_additions = __(
			'

Você está em uma conversa contínua. Lembre-se do contexto das mensagens anteriores.
Seja conversacional e amigável, mas mantenha a precisão das informações.
Responda de forma breve (até 2 parágrafos curtos ou uma lista de até 5 tópicos), como numa conversa.
Quando citar itens do acervo, use o título em **negrito**. Não escreva URLs, números de referência como [1] nem frases como "link disponível": as fontes já aparecem com link abaixo da resposta.
Se o acervo não tiver a informação, diga com franqueza e sugira como reformular ou onde procurar.',
			'oraculo-tainacan'
		);

		return $persona . "\n\n" . $base_prompt . $chat_additions;
	}

	/**
	 * Constrói prompt de chat
	 *
	 * @param string $message
	 * @param string $context
	 * @param array  $history
	 * @return string
	 */
	private function build_chat_prompt( string $message, string $context, array $history ): string {
		$parts = array();

		// Histórico
		if ( ! empty( $history ) ) {
			$parts[] = 'HISTÓRICO DA CONVERSA:';
			foreach ( $history as $msg ) {
				$role    = $msg['role'] === 'user' ? 'Usuário' : 'Assistente';
				$parts[] = "{$role}: " . \Oraculo_Tainacan\truncate_text( $msg['content'], 500 );
			}
			$parts[] = '';
		}

		// Contexto do acervo
		if ( ! empty( $context ) ) {
			$parts[] = 'INFORMAÇÕES RELEVANTES DO ACERVO:';
			$parts[] = $context;
			$parts[] = '';
		}

		// Mensagem atual
		$parts[] = 'MENSAGEM DO USUÁRIO: ' . $message;
		$parts[] = '';
		// No fim do prompt, depois de tudo: prompts de sistema salvos em versões
		// antigas mandam "forneça o link", e a BIA terminava cada item com
		// "Link disponível no registro" — o widget já lista as fontes com link.
		$parts[] = __( 'Responda de forma útil e conversacional, em poucas linhas: destaque só os 3 a 5 itens mais relevantes, com o título em **negrito** e uma frase sobre cada um. Não escreva URLs, números de referência como [1] nem frases como "link disponível": a lista completa de fontes, com links, aparece logo abaixo da sua resposta.', 'oraculo-tainacan' );

		return implode( "\n", $parts );
	}

	/**
	 * Formata fontes para resposta
	 *
	 * @param array $sources
	 * @return array
	 */
	private function format_sources_for_response( array $sources ): array {
		return array_map(
			function ( $source ) {
				return array(
					'id'              => $source['id'] ?? null,
					'title'           => $source['title'] ?? '',
					'url'             => $source['url'] ?? '',
					'collection_name' => $source['collection_name'] ?? '',
					// Arquivo e página do trecho que sustentou a resposta.
					'where'           => (string) ( $source['passages'][0]['label'] ?? '' ),
				);
			},
			$sources
		);
	}

	/**
	 * Obtém conversas do usuário
	 *
	 * @param int $user_id
	 * @param int $limit
	 * @return array
	 */
	public function get_user_conversations( int $user_id, int $limit = 20 ): array {
		global $wpdb;

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- $this->conversations_table and $this->messages_table are $wpdb->prefix . self::TABLE_CONVERSATIONS / self::TABLE_MESSAGES (class constants; cannot receive user input); table identifiers cannot be parameterized.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- user conversations list; not cached (changes frequently).
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT c.*, COUNT(m.id) as message_count
             FROM {$this->conversations_table} c
             LEFT JOIN {$this->messages_table} m ON c.id = m.conversation_id
             WHERE c.user_id = %d
             GROUP BY c.id
             ORDER BY c.updated_at DESC
             LIMIT %d",
				$user_id,
				$limit
			),
			ARRAY_A
		);
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
	}

	/**
	 * Obtém mensagens de uma conversa
	 *
	 * @param int $conversation_id
	 * @param int $limit
	 * @return array
	 */
	public function get_messages( int $conversation_id, int $limit = 50 ): array {
		global $wpdb;

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- $this->messages_table is $wpdb->prefix . self::TABLE_MESSAGES (class constant; cannot receive user input); table identifier cannot be parameterized.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- messages change frequently during active conversation.
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->messages_table}
             WHERE conversation_id = %d
             ORDER BY created_at ASC
             LIMIT %d",
				$conversation_id,
				$limit
			),
			ARRAY_A
		);
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
	}

	/**
	 * Encerra conversa
	 *
	 * @param string $session_id
	 * @return bool
	 */
	public function end_conversation( string $session_id ): bool {
		global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table (oraculo_conversations); UPDATE write operation; caching N/A.
		$result = $wpdb->update(
			$this->conversations_table,
			array(
				'status'     => 'ended',
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'session_id' => $session_id ),
			array( '%s', '%s' ),
			array( '%s' )
		);
		\Oraculo_Tainacan\oraculo_tainacan_flush_cache();

		return $result !== false;
	}

	/**
	 * Registra feedback em uma mensagem
	 *
	 * @param int    $message_id
	 * @param string $feedback
	 * @return bool
	 */
	public function record_feedback( int $message_id, string $feedback ): bool {
		global $wpdb;

		$valid_feedback = array( 'positive', 'negative' );
		if ( ! in_array( $feedback, $valid_feedback ) ) {
			return false;
		}

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table (oraculo_messages); UPDATE write operation; caching N/A.
		$result = $wpdb->update(
			$this->messages_table,
			array( 'feedback' => $feedback ),
			array( 'id' => $message_id ),
			array( '%s' ),
			array( '%d' )
		);
		\Oraculo_Tainacan\oraculo_tainacan_flush_cache();

		return $result !== false;
	}

	/**
	 * Gera título para conversa baseado no conteúdo
	 *
	 * @param int $conversation_id
	 * @return string
	 */
	public function generate_conversation_title( int $conversation_id ): string {
		global $wpdb;

		// Obter primeira mensagem do usuário
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- $this->messages_table is $wpdb->prefix . self::TABLE_MESSAGES (class constant; cannot receive user input); table identifier cannot be parameterized.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- first message lookup for title generation.
		$first_message = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT content FROM {$this->messages_table}
             WHERE conversation_id = %d AND role = 'user'
             ORDER BY id ASC LIMIT 1",
				$conversation_id
			)
		);
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter

		if ( ! $first_message ) {
			return __( 'Nova conversa', 'oraculo-tainacan' );
		}

		// Truncar para título
		$title = \Oraculo_Tainacan\truncate_text( $first_message, 50 );

		// Atualizar título na conversa
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table (oraculo_conversations); UPDATE write operation; caching N/A.
		$wpdb->update(
			$this->conversations_table,
			array( 'title' => $title ),
			array( 'id' => $conversation_id ),
			array( '%s' ),
			array( '%d' )
		);
		\Oraculo_Tainacan\oraculo_tainacan_flush_cache();

		return $title;
	}

	/**
	 * Limpa conversas antigas
	 *
	 * @param int $days_old
	 * @return int Número de conversas removidas
	 */
	public function cleanup_old_conversations( int $days_old = 30 ): int {
		global $wpdb;

		// Obter IDs de conversas antigas
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- $this->conversations_table and $this->messages_table are $wpdb->prefix . self::TABLE_CONVERSATIONS / self::TABLE_MESSAGES (class constants; cannot receive user input); table identifiers cannot be parameterized.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- cleanup operation; returns IDs for subsequent DELETE.
		$old_conversations = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$this->conversations_table}
             WHERE updated_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
				$days_old
			)
		);

		if ( empty( $old_conversations ) ) {
			return 0;
		}

		$old_conversations = array_map( 'intval', $old_conversations );
		$ids_placeholder   = implode( ',', array_fill( 0, count( $old_conversations ), '%d' ) );

		// Remover mensagens — $old_conversations is array_map('intval', ...)d above; type-safe to interpolate.
        // phpcs:disable WordPress.DB.PreparedSQL.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- $old_conversations is array_map('intval', ...)d above; type-safe to interpolate; $ids_placeholder constructed via array_fill('%d'); count matches args spread; bulk DELETE for oraculo_messages; caching N/A for writes.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$this->messages_table} WHERE conversation_id IN ($ids_placeholder)",
				$old_conversations
			)
		);
        // phpcs:enable WordPress.DB.PreparedSQL.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		// Remover conversas
        // phpcs:disable WordPress.DB.PreparedSQL.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- $old_conversations is array_map('intval', ...)d above; type-safe to interpolate; $ids_placeholder constructed via array_fill('%d'); count matches args spread; bulk DELETE for oraculo_conversations; caching N/A for writes.
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$this->conversations_table} WHERE id IN ($ids_placeholder)",
				$old_conversations
			)
		);
        // phpcs:enable WordPress.DB.PreparedSQL.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
		\Oraculo_Tainacan\oraculo_tainacan_flush_cache();

		return $deleted;
	}
}
