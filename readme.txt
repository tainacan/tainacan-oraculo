=== Oráculo Tainacan ===
Contributors: tainacancommunity
Tags: tainacan, ai, search, rag, openai
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 8.0
Stable tag: 2.14.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI-powered natural-language search and chat for Tainacan digital archives. RAG with multiple providers (OpenAI, Gemini, Claude, DeepSeek, Groq).

== Description ==

Oráculo Tainacan adds Artificial Intelligence capabilities to Tainacan, enabling:

* **Semantic Search (RAG)**: Find items by meaning, not just keywords
* **AI Chat**: Converse about the archive with conversation memory and extracted facts
* **Vector Indexing**: Generate embeddings for items to enable similarity search
* **Multiple Providers**: OpenAI, Google Gemini, Claude (Anthropic), DeepSeek, Groq and Ollama (local)
* **Analytics**: Track usage metrics, searches, feedback and performance
* **Per-collection Prompts**: Customizable system/search/chat prompts per collection
* **Webhooks**: Trigger external integrations on plugin events
* **Export**: Export conversations and analytics reports
* **Multimodal Search**: Search combining text and other signals
* **Smart Suggestions**: Dynamic suggested questions based on context
* **WP-CLI**: Commands for batch indexing, statistics and maintenance
* **REST API**: 20 endpoints under /wp-json/oraculo/v1

== Requirements ==

* WordPress 6.0 or higher
* PHP 8.0 or higher
* Tainacan plugin 1.0+ installed and active (integration via Pages API)
* API key for at least one AI provider, or Ollama running locally

== Installation ==

1. Upload the plugin via Plugins > Add New > Upload Plugin
2. Activate the plugin through the Plugins menu in WordPress
3. Go to Tainacan > Oráculo AI in the admin menu
4. Configure your API key in the Settings tab
5. Index your collections in the Indexing tab

== Configuration ==

1. **Choose an AI Provider**: OpenAI, Gemini, Claude, DeepSeek, Groq or Ollama
2. **Configure the API Key**: Enter the key for your chosen provider
3. **Select the Model**: Choose chat and embedding models
4. **Adjust Parameters**: Temperature, max tokens, similarity threshold, batch size
5. **Enable Features**: Chat, search, analytics and feedback can each be toggled individually

== Usage ==

**Shortcodes:**

* `[oraculo_search]` - Semantic search widget
* `[oraculo_chat]` - AI chat widget

**Search shortcode parameters:**

* `collection` - Collection ID (optional; uses all collections if omitted)
* `placeholder` - Placeholder text for the search field
* `show_suggestions` - Show suggestions (true/false)

Example: `[oraculo_search collection="123" placeholder="What are you looking for?" show_suggestions="true"]`

== CLI Commands ==

* `wp oraculo index --collection=<id>` - Index a collection
* `wp oraculo reindex --all` - Re-index all collections
* `wp oraculo stats` - Usage statistics
* `wp oraculo clear-cache` - Clear search cache

== REST API ==

Namespace: `/wp-json/oraculo/v1`. Main routes:

* `POST /search` - Semantic search
* `POST /chat` - Chat message
* `GET /conversations` - List conversations
* `POST /feedback` - Record feedback
* `POST /indexing/start` - Start indexing
* `GET /indexing/status` - Indexing status
* `GET /analytics` - Aggregated metrics
* `GET /providers` - Available providers
* `POST /providers/test` - Test provider connection
* `GET /health` - Health check

== Supported AI Providers ==

1. **OpenAI** - GPT-4o, GPT-4o-mini, GPT-3.5-turbo + text-embedding-ada-002/3
2. **Google Gemini** - gemini-1.5-flash, gemini-1.5-pro
3. **Claude (Anthropic)** - claude-3.5-sonnet, claude-3-opus/haiku
4. **DeepSeek** - deepseek-chat, deepseek-coder
5. **Groq** - llama-3.1-70b, mixtral-8x7b, gemma2-9b
6. **Ollama** - Any local model (full privacy; embeddings via nomic-embed-text)

== Frequently Asked Questions ==

= Does this plugin work without Tainacan? =

No. Oráculo Tainacan is an add-on for the Tainacan digital archive plugin and requires Tainacan 1.0 or higher.

= Which AI provider should I use? =

OpenAI is the most tested option. Ollama is recommended for fully local, private deployments.

= Is my data sent to third parties? =

Only the text content of your archived items is sent to the AI provider you configure. When using Ollama, everything stays local.

== Changelog ==

= 2.14.0 =
* New provider "OpenAI Compatível (Genérico)": any server that speaks the OpenAI API (Open WebUI, LiteLLM, vLLM, LocalAI, Ollama's /v1, institutional gateways) with its own base URL and an optional API key (encrypted like the others). Models are discovered from the server's /models ("Testar Conexão" or "Buscar modelos") and split into chat and embedding suggestions; the list is cached and never fetched just by opening an admin screen. It can also generate the embeddings (Indexação tab). When the base URL ends in /api (Open WebUI) and /embeddings is missing, embeddings go through Open WebUI's Ollama proxy. Ported from Mateus Luna's contribution (tainacan/tainacan-oraculo#4)
* New "Estratégia de RAG" setting (Geral tab): Local (default, as before: the answer uses the items found in the Oráculo vectors), Provedor (no local retrieval: the question goes straight to the model, for providers that already consult the collection, e.g. Open WebUI with a knowledge base; no item cards) and Híbrido (local, but if the embedding or the vector search fails the search answers with the provider alone instead of an error; that answer is not cached). The strategy is part of the search and BIA cache keys. Without context, the search sends the plain question instead of the "items listed below" prompt. Also from tainacan/tainacan-oraculo#4

= 2.13.1 =
* Manual: new page "Servidor para um acervo de 100 mil itens" with the recommended VM (4 vCPU, 8 GB, NVMe, MariaDB 11.7+, 16 Oráculo PHP processes) and projected times. Documentation only; no code changes

= 2.13.0 =
* New "Diagnóstico" tab: checks what matters when many people use the search and the BIA at the same time (provider, model, fast mode, own PHP processes, rate limits, native vector search, answer cache, server), says in plain words what each item means and what to do, and has a live test that times each step (WordPress load on the Oráculo route, embedding, vector search, a short AI answer). Numbers in the texts come from a load test with 40 searches and 30 BIA conversations at the same time on a 1-core server (see the "Uso intenso" guide in the manual)
* Fast mode (optional, one click in Diagnóstico): a small mu-plugin, copied by the plugin to `wp-content/mu-plugins` and removed when you turn it off or deactivate the Oráculo, loads only the Oráculo on the public search and chat routes. It only acts when WordPress's own index.php serves the request and the route is in the URL path (never wp-login.php, xmlrpc.php or a route in the query string), so login protection plugins always load. Each request spends less than half the CPU (0.05 s against 0.13 s); in the 70-person peak the total time went from 38 s to 26.5 s with identical results. The Tainacan plugin stays loaded in visual search (CLIP) mode. Filter `oraculo_tainacan_fast_rest_plugins` keeps other plugins. Not available on multisite
* Rate limits are now options in Diagnóstico: site-wide AI calls per minute (300 on new installs, since 60 refused part of a 70-person peak; sites updated from 2.12 keep 60 until the value is changed in Diagnóstico, so an update never raises the AI spend ceiling on its own), searches and BIA messages per minute per visitor, and allowed networks (IP or CIDR, IPv4/IPv6) without the per-visitor limit, for classrooms and museums behind a single IP (IPv4 addresses mapped in IPv6 are matched too). The existing filters still win
* The first message of a BIA conversation is cached like a search (same question, collections, provider, model, system prompt, language and index version) for the cache duration; a cached answer is recorded with zero tokens and cost. Simultaneous identical first messages wait for the one being generated instead of each calling the AI; an answer given without the collection (retrieval failed) is not cached
* OpenAI: `gpt-4.1-mini` is the default model for new installs and is marked as recommended (about 1.9 s per answer in the test, against about 6 s for `gpt-5-nano`, with shorter and correct answers). Existing sites keep their chosen model
* REST: `POST oraculo/v1/diagnostics/probe` (administrators only), used by the live test

= 2.12.0 =
* Performance: "Busca com IA" answers in two steps. The first request (`phase=items`) returns the item cards without calling the language model (about 85% of the search time) plus a single-use ticket; the second (`phase=answer`, with the ticket) writes the synthesis on top of the stored retrieval, without embedding or searching again. Cards show in about a second; only the answer block is filled in when the synthesis arrives, so focus and scroll on the cards stay. Cached answers and "nothing found" come complete in the first request; with sources hidden the tab keeps a single request. A request without `phase` works as before
* Performance: the embedding of a search question is cached for 2 hours (key: provider, model and text); the BIA chat does not cache (messages rarely repeat)
* Performance: the two-phase PHP fallback caps vectors still without a binary signature at the rescore pool (a whole collection used to go to the PHP cosine)
* Rate limits: a two-step search counts once, on `items`, in the per-IP and in the global AI limits; `answer` is exempt only with the ticket from `items`, consumed on first use
* Security: public suggestions only show searches made by at least 3 different visitors (`oraculo_tainacan_popular_min_visitors`), so one person neither publishes what they typed nor pushes a text into the list by repeating it; the query works under ONLY_FULL_GROUP_BY (MySQL 5.7+), where it used to fail and return nothing
* Security: the analytics CSV export neutralizes spreadsheet formulas typed by visitors (=, +, -, @); the `height` attribute of `[oraculo_chat]` only accepts a number and a unit (no extra CSS); feedback labels in the shortcode result are escaped
* Synchronous indexing checks the nonce and capability before raising limits, and raises memory with `wp_raise_memory_limit()`
* Native vectors: writes and deletes no longer run inside a transaction with the side tables (a deadlock while copying to the side table would also roll back the vector itself); copy failures go to the retry list and failed deletes schedule a prune
* Header: `Requires Plugins: tainacan`

= 2.11.0 =
* Performance: on MariaDB 11.7+ the vector search runs inside the database. Two narrow side tables (`oraculo_vectors_native`, `oraculo_chunks_native`: id, item, collection and a `VECTOR(n)` column) are filled in the background and kept in sync on every write and delete; the cosine is computed by the database and only the closest ids come back to PHP — no embeddings loaded into PHP memory. Side tables, not a column in the vectors table: its ~40 KB rows outgrow the InnoDB buffer and a full scan took 5–8 s. Measured on MariaDB 11.8 with 38,000 vectors of 1,536 dimensions
* No vector (HNSW) index on purpose: MariaDB filters after searching the index, and with a collection filter it returned 13–46% recall and queries of up to 30 s
* Other databases (MySQL, MariaDB < 11.7), or while the column is being filled, keep the two-phase search. Rows with another dimension (embedding model changed) are left out of the database search; the side tables follow the dimension of the current model on their own
* WP-CLI: `wp oraculo native status|sync|rebuild|drop`. A dump with the side tables only restores on MariaDB 11.7+: run `wp oraculo native drop` before moving it to MySQL or an older MariaDB
* Fix: vector export with embeddings no longer includes the binary `embedding_bits` column
* Fix: `wp oraculo search --limit=N` died with a TypeError (the limit arrived as a string under strict_types)

= 2.10.2 =
* Fix: an attachment already processed by Tainacan OCR Search that has no text (blank page) is no longer reported as needing OCR

= 2.10.1 =
* Fix: the document queue queried post_type 'any', which skips Tainacan item types (exclude_from_search), so no document was ever processed; `documents status` counted unextracted PDFs as needing OCR

= 2.10.0 =
* Feature: search and the BIA chat find items by the content of their documents. Text comes from the Tainacan OCR Search plugin (`_tainacan_ocr_text`, per attachment, with page breaks), from the plugin's own extraction of PDFs that already have a text layer (pdftotext, cached per file), or from Tainacan's `document_content_index`. It is split into ~1,600-character passages per page, each with its own embedding, in the new table `oraculo_chunks` (float32 + binary signature, same two-phase search). Turn on with the "document" field in the indexing settings
* Search merges passages into items (score = best of metadata vector and best passage); items found only by document content are included. Cards show "No documento" with file, page and excerpt, linking to the PDF page (`#page=N`); the AI context gets the passages and cites pages. BIA sources show file and page
* Documents have their own queue (`_oraculo_docs_pending`) processed by the worker with a 120 s budget and at most 256 new passages per item per pass, retried with backoff and never dropped. Reindexed on OCR completion (`tainacan_ocr_search_text_updated`), attachment added or removed, and `document_content_index` changes; removing an item, collection or "all vectors" removes its passages
* WP-CLI: `wp oraculo documents index|status --collection=<id>`
* Fix: term highlighting used one replace per term, so a short term ("mar") matched inside the `<mark>` of a longer one ("história")

= 2.9.0 =
* UI: the AI answer panel can be collapsed and reopened without searching again. The close button became a "Recolher" / "Mostrar resposta" toggle; the collapsed bar shows the question and reopens on click, and returning to the AI tab brings back the last answer

= 2.8.5 =
* Fix: the AI answer panel always takes the full row of the Tainacan items page (a flex container with wrap) and pushes filters and items down; it used to share the row with the filters sidebar, which ended up beside the answer

= 2.8.4 =
* UI: cited item cards keep a white background (dark text on the green tint had poor contrast); they are marked by a thick left border and shadow instead

= 2.8.3 =
* UI: stronger pastel tones in the AI search panel; the theme accent is already light, so tints now lean toward the text colour instead of white

= 2.8.2 =
* Fix: the chat format rule (3 to 5 highlighted items, no URLs or link placeholders) moved to the end of the message prompt, after the context; system prompts saved by older versions ask for links and still won when the rule lived only in the system prompt

= 2.8.1 =
* Fix: BIA no longer ends every item with "Link disponível no item [n]"; the chat widget has no numbered citations, so the persona now forbids URLs, [n] references and that phrase

= 2.8.0 =
* Fix: the BIA chat now searches the same scope as the "Busca com IA" tab (the current collection, or the default collections) and retrieves the same number of items (max_results instead of 5). It used to search the whole repository, so the same question returned items from other collections. The chat header shows the collection when the chat is limited to it, and the [oraculo_chat] shortcode now honours its collections attribute
* Change: the retrieval embedding uses only the subject of the question: question form, articles, prepositions and generic collection words ("o que tem", "sobre", "livros", "obras"...) are removed first (filter oraculo_tainacan_retrieval_stopwords); capitalised words after the first are kept as proper names ("São Paulo"); the model still gets the full question. In a collection with ~1,000 near-identical titles, "livros sobre o nordeste" and "o que tem para nordeste?" had no item in common, because the preposition decided the top 10
* UI: the AI search panel stands out more, with stronger pastel tones derived from the theme accent colour (tinted header and background, the answer in a white card, cited items highlighted)

= 2.7.2 =
* UI: item card metadata labels drop the MARC code and qualifier ("[100] Autor Principal — Pessoa" becomes "Autor Principal") and are no longer bold; answer list bullets restored against the theme reset

= 2.7.1 =
* Tune: vector rescore pool default raised to 20x the limit (min 400) after measuring recall on real data: 99% recall@20 at ~0.2 s, versus 80% with the 2.7.0 default of 100

= 2.7.0 =
* Performance: local vector search runs in two phases. Each vector gets a 1-bit-per-dimension signature (new column embedding_bits, filled on upgrade by a background cron or by `wp oraculo optimize`); candidates are preselected by Hamming distance and only the best ones (filter oraculo_tainacan_vector_rescore_pool, default 20x the limit, min 400) get the exact cosine. Measured on a 14.5k-vector collection: 9-18 s down to ~0.2 s with 99% recall@20 against the exact scan; BIA chat from ~20 s to ~5 s
* UI: the "Busca com IA" answer is now a short synthesis with numbered citations [n] that jump to the matching item cards; cards are fully clickable, show the item thumbnail, a number badge and up to 3 short metadata fields instead of the raw indexed text
* Change: fixed answer-format rules are appended to every search prompt (even customized ones) and item URLs are no longer sent to the model, so answers stop repeating the cards as plain-text title/author/link lists. The search cache key changed, retiring answers cached in the old format
* Change: BIA chat answers are shorter and cite titles in bold instead of pasting URLs (sources are already listed below the answer)

= 2.6.4 =
* Fix: OpenAI reasoning models (GPT-5 family, o-series) now get reasoning_effort "minimal" (original GPT-5 family) or "low" (others), filterable via oraculo_tainacan_openai_reasoning_effort, with automatic fallback when a model rejects the value. gpt-5-nano was spending the whole 2000-token budget on reasoning, returning an empty answer after more than two minutes
* Fix: an empty answer cut by the token limit (finish_reason "length") now returns a clear error instead of an empty response that was also cached

= 2.6.3 =
* Fix: local vector search no longer exhausts memory on large collections (a 14k-item collection hit the 512M limit and returned a critical error). The scan now reads only id + embedding in keyset batches of 500 (filter oraculo_tainacan_vector_scan_batch), keeps only the top-k and fetches details just for them. Latency is still O(N); the external vector service remains the long-term path

= 2.6.2 =
* Fix: anonymous visitors with a stale nonce also get rest_cookie_invalid_nonce from core, whose rest-nonce endpoint only serves logged-in users; the REST client now falls back to the plugin's own nonce action in that case and retries once

= 2.6.1 =
* Fix: AI search tab, floating chat, [oraculo_search] and [oraculo_chat] no longer fail with "A verificação do cookie falhou" (rest_cookie_invalid_nonce) when the session changes after the page loads (login/logout in another tab, expired session, tab left open). A shared REST client renews the wp_rest nonce through admin-ajax and retries the request once

= 2.6.0 =
* Feature: the chat assistant is now BIA — Bibliotecária de IA. The persona is injected structurally into the chat system prompt (filterable via oraculo_tainacan_chat_persona, applies even over prompts stored by previous versions), and the whole widget carries her identity: gradient book-monogram avatar (inline SVG, follows the appearance colors), name and role in the header with online status, named typing indicator ("BIA está pesquisando no acervo…") and a BIA-signed welcome
* Feature: chat layout redesigned — clean light theme driven by the appearance color options, messenger-style bubbles with BIA's avatar, pill suggestion chips, refined sources block, focus ring on the input, smooth open/close, reduced-motion support and mobile full-screen behavior
* Feature: flow improvements — "new conversation" button now available in the floating widget header (previously embedded-only), Esc closes the window, aria-live message log and labeled controls for screen readers
* Both chat surfaces (floating widget and [oraculo_chat] shortcode) share the same visual system

= 2.5.3 =
* Fix: follow-up questions now stay grounded in the archive — the semantic search behind the chat used only the current message as its query, so referential follow-ups ("e quem escreveu?") retrieved nothing and the answer lost its archive grounding exactly on continuations. The retrieval query now carries the user's previous questions as topical anchor, and short follow-ups are no longer skipped by the word-count heuristic (only pure acknowledgements like "ok"/"obrigado" skip retrieval)
* Fix: the current message no longer appears twice in the prompt (once in the history block, once as the current message) — history is fetched before saving the new message, freeing one of the ten context slots for real conversation history

= 2.5.2 =
* Fix: chat crashed with a critical error on the second message of every session — the conversation id read back from the database is a string, and passing it to the strictly-typed save_message()/history methods is a fatal TypeError under strict_types. Ids are now cast at the single point where the row is read
* Fix: the "Busca com IA" tab silently disappeared from the site — the settings sanitizer (which register_setting also runs on every update_option) materialized theme_integration.enabled=false whenever a save did not carry the sub-array, permanently overriding the enabled-by-default behavior. Checkbox semantics are now key-presence based: the form always posts the key (hidden inputs added for all flags), and an absent key preserves the stored value instead of disabling features
* Fix: the same absent-key protection now covers enable_chat/enable_search/enable_analytics/enable_feedback/debug_mode, which were one partial programmatic save away from being silently switched off

= 2.5.1 =
* Fix: API keys were double-encrypted on save — register_setting() wires the same sanitizer as the option sanitize_callback, so the admin save encrypted once manually and update_option() encrypted the already-encrypted value again; one decryption pass then sent the literal "enc:..." string to the provider ("Incorrect API key provided: enc:..."). Encryption is now idempotent (an enc: value is never re-encrypted) and get_api_key() decrypts in layers, healing keys already stored double-encrypted without requiring re-entry
* Fix: is_configured() now validates the decrypted key — a corrupted enc: value no longer counts as configured (it used to send an empty Bearer to the API instead of saying the key needs reconfiguring)
* Fix: "Testar Conexão" showed a ✅ in front of authentication failures — the AJAX envelope now reflects the actual test result
* Fix: indexing that fails for every item no longer reports "Indexação concluída!" in green — it reports a failure pointing at the embeddings provider key, and the collection status shows an error state

= 2.5.0 =
* Change: the CLIP visual search backend is now selected as an AI provider card ("Busca Visual (CLIP)"), side by side with OpenAI/Claude/etc., instead of a separate "search backend" select buried in the General tab. Its panel carries the API URL, model (with "fetch models from server") and test connection; picking it routes search through CLIP and disables chat with an explanatory message. Stored configs using the old search_backend option keep working and are migrated on the next save
* Fix: provider cards were unclickable — the decorative ::before overlay (position:absolute, full card) painted above the static content and swallowed every click, so the radio never toggled. The overlay is now pointer-events:none and the whole card selects the provider on click, matching its cursor:pointer affordance
* Fix: the Analytics tab died with a critical error as soon as it had real data — the templates declare strict_types and passed the string counts coming from wpdb straight into number_format(), a TypeError on PHP 8. All number_format() calls in the admin templates now cast explicitly (also latent in the Indexing and Debug tabs)
* Fix: the Debug tab fataled on a call to detect_environment(), a helper that never existed; it now reports the environment via the real is_hostinger()/is_xampp() detectors

= 2.4.1 =
* Fix: chat/search always ran on the first model of the provider catalog, silently ignoring the model chosen in settings — prepare_options() injected catalog[0] unconditionally, so the configured model never won. Exposed when 2.4.0 reordered the OpenAI catalog and every install started calling GPT-5.2 regardless of configuration
* Fix: OpenAI reasoning-family models (GPT-5.x, o1/o3/o4) rejected requests with `max_tokens`/custom `temperature` — the provider now sends `max_completion_tokens` (and omits temperature) for those families, with an adaptive retry that fixes the parameters when the API reports unsupported_parameter for models newer than this version
* Fix: the AI search tab showed the generic "Não foi possível concluir a busca" instead of the real backend error (the 400 payload uses `error`, which the error branch never read)

= 2.4.0 =
* Feature: "Buscar modelos da conta" — every AI provider settings panel can query the provider's own /models endpoint with the key currently typed (even before saving) and list only the models that account's plan actually unlocks, instead of relying solely on the catalog hardcoded in the plugin. Works for OpenAI, Claude, Gemini, Groq, DeepSeek (real API query) and Ollama (installed models via /api/tags); the static catalog remains the fallback until a search is run
* Security: API keys are now encrypted at rest (AES-256-CBC via wp_salt) instead of stored as plain text in wp_options; keys saved before this version keep working unchanged (the decryption path already handled both formats — this release is what starts actually encrypting on save)
* Dev: new AIProviderInterface::list_remote_models() implemented by all six providers; new wp_ajax_oraculo_list_models endpoint

= 2.3.0 =
* Feature: automatic indexing — new and edited items are queued on save and indexed in the background, so they become searchable within minutes instead of waiting for a manual reindex
* Feature: items sent to trash, unpublished or deleted are removed from the index immediately, so search no longer returns items visitors cannot open
* Feature: daily reconciliation cron re-syncs the index with the collection (backup restores, direct imports, items that exhausted retries) and removes orphan vectors
* Feature: CLIP visual search backend — optional integration with the IBRAM AI API (FastAPI + pgvector). Natural language queries run in the CLIP image-text space and temporal constraints ("obras do século 21") are parsed into metadata filters; item images are indexed remotely with normalized facets (year/decade/century/collection). No AI-generated answer in this mode: results are the closest works, with a deterministic summary
* Feature: queue status card and "process now" button on the indexing screen; new WP-CLI commands `wp oraculo queue <status|process|reconcile>` and `wp oraculo clip <health|models|search|index>`
* Fix: search and suggestion caches are now versioned by the index, so a freshly indexed item shows up right away instead of after the cache TTL (up to 1 hour)
* Fix: `wp oraculo index` no longer aborts on a call to a non-existent method; it now reports the result of the (already synchronous) indexing run
* Fix: indexing an item whose collection was deleted no longer raises a fatal error
* Fix: the "Campos para Indexar" checkboxes and batch size on the indexing screen now persist to the options the indexer actually reads (they previously wrote orphan options), and the embeddings-provider select is honored by the provider factory
* Dev: new filters `oraculo_tainacan_auto_index_enabled`, `oraculo_tainacan_index_delay` and `oraculo_tainacan_queue_batch_size`
* New: Tainacan theme integration — an "AI Search" tab injected next to the default search field on all Tainacan items lists, with AI answer panel, suggested questions, related items grid and deep link support (?oraculo_q=question); new settings tab "Tema Tainacan"
* Fix: unchecking "show sources" / "show similarity" now persists; the missing key used to fall back to the default instead of false
* Fix: settings sanitization unified in SettingsSanitizer for both the admin form and the REST /settings endpoint; options absent from the submitted form keep their stored value instead of being wiped
* Cleanup: the legacy admin bundle (assets/js/admin.js, assets/css/admin.css and the OraculoAdmin localized object) is gone

= 2.1.0 =
* Security: escape all dynamic content injected into HTML by the widgets (AI responses, item titles/snippets, error messages); markdown links restricted to safe URL schemes
* Security: REST endpoints hardened — nonce + per-IP rate limit on public search/chat/feedback, conversation ownership checks, admin-only health endpoint, argument schemas everywhere
* Security: admin page menu requires manage_options; remote image download via WordPress HTTP API
* Refactor: Tainacan Pages integration moved to the plugin's own namespace with the core Singleton_Instance trait, registered under the Tainacan "More" menu; dead legacy admin page removed
* Refactor: all inline template scripts extracted to enqueued files; assets load only where used (plugin admin page, shortcode pages, floating chat)
* Refactor: public widgets now use the oraculo/v1 REST API exclusively; nopriv admin-ajax handlers removed
* Dev: strict phpcs ruleset (WordPress-Core/Docs/Extra + Security + PHPCompatibilityWP) with composer tooling; declare(strict_types=1) across the codebase; text domain migrated to oraculo-tainacan
* Security: input size caps in the REST schema (query ≤ 500 chars, chat message ≤ 2000, collections ≤ 20) and a site-wide ceiling of 60 AI calls/minute across all IPs
* Security: the daily oraculo_cleanup_old_data cron finally has a handler — search logs (90d), conversations/messages (30d) and expired memory are purged instead of growing without bound
* Fix: unchecking "show sources" / "show similarity" now persists; the missing key used to fall back to the default instead of false
* Fix: settings sanitization unified in SettingsSanitizer for both the admin form and the REST /settings endpoint; options absent from the submitted form (search/chat prompts, unused provider models) keep their stored value instead of being wiped
* Fix: the AI search tab now shows the real message on rate limit / disabled feature / invalid nonce instead of reporting "no items found"
* Cleanup: the legacy admin bundle (assets/js/admin.js, assets/css/admin.css and the OraculoAdmin localized object) is gone — every handler it registered was either superseded by the per-tab scripts or bound to selectors no template renders

= 2.1.0 =
* New: Tainacan theme integration — an "AI Search" tab is injected next to the default search field on all Tainacan items lists (collection archives, repository archive, taxonomy term archives and pages using the faceted search block/shortcode)
* New: AI answer panel below the search bar with staged loading feedback, suggested questions, related items grid, feedback buttons and a "refine in traditional search" action
* New: settings tab "Tema Tainacan" (enable/disable, tab label, placeholder, search scope, suggestions)
* New: deep link support — `?oraculo_q=question` opens the AI tab and runs the search automatically
* The integration follows the active theme through the --tainacan-* CSS variables and keeps state across Vue re-renders of the Tainacan items list

= 2.0.3 =
* Harden chat textarea color/background against theme resets

= 2.0.2 =
* Fix chat title color (h3) overridden by WordPress theme

= 2.0.1 =
* Fix text contrast issues in frontend and chat widget
* Fix invisible text in chat input (white on white)
* Fix invisible text in user messages (navy on navy)
* Fix chat header subtitle contrast (dark on dark)
* Improve placeholder and auxiliary text contrast to pass WCAG AA
* Fix invalid CSS declaration in search hero

= 2.0.0 =
* Complete rewrite with service-oriented architecture and PSR-4 namespaces
* Requires PHP 8.0 and WordPress 6.0
* Integration with Tainacan 1.0+ via Pages API (\Tainacan\Pages)
* New providers: Claude (Anthropic) and Groq
* Conversation memory with summaries and facts extracted per session
* Customizable prompts per collection
* Webhooks for external integrations
* Export of conversations and analytics
* Multimodal search and smart suggestions
* Expanded REST API (20 endpoints under /oraculo/v1)
* Redesigned admin dashboard with tabs (Dashboard, Analytics, Indexing, Settings, Debug)

= 1.0.0 =
* Initial release
* Semantic search with RAG
* Integrated AI chat
* Support for 4 AI providers
* Analytics dashboard
* Initial Tainacan integration

== Upgrade Notice ==

= 2.0.1 =
Fixes accessibility contrast issues in frontend and chat. Update recommended.

= 2.0.0 =
Major rewrite. Requires PHP 8.0+, WordPress 6.0+ and Tainacan 1.0+. New tables are created automatically on activation; existing settings are preserved.
