# Memória do projeto (decisões, armadilhas, pendências)

> Para retomar o trabalho em outra máquina ou instância. Complementa o
> [CONTEXT.md](CONTEXT.md), que descreve a arquitetura. Atualizado em 10/11/2026 (versão 2.14.0).
> Endereços, credenciais e comandos de servidor ficam no repositório privado de infraestrutura,
> não aqui (este repositório é público).

## Onde está

- Repositório `marcossigismundo/oraculo_tainacan`, branch `main`. A pasta local se chama
  `oraculo_tainacan`; no site, o plugin fica em `oraculo-tainacan` (com hífen).
- Instalado em dois sites de teste: o do TainacanBiblio (sem busca em documentos; 2.13.0) e o
  laboratório de OCR, que tem a coleção "OCR — documentos digitalizados (laboratório)" (fica na
  2.10.2 por decisão do Marcos; o deploy pelo mapa publica nos dois — voltar o laboratório depois).
- **Repositório oficial (público): `tainacan/tainacan-oraculo`.** Em 10/11/2026 a `main` dele
  estava parada em `ad057c0` (08/05/2026), ancestral direta desta `main`. Branches enviados lá:
  `release/2.13.1` (cópia exata da `main` daqui) e `release/2.14.0` (2.13.1 + PR #4 do Mateus
  portado). Plano: PR `release/2.14.0` → `main` lá, por merge commit ou rebase (nunca squash, para as
  duas histórias continuarem batendo), e depois decidir qual repositório vira o de trabalho.

## Linha do tempo das decisões

- **2.14.0 (10/11/2026), PRs do Mateus Luna no repositório oficial.** Dos quatro PRs abertos em
  `tainacan/tainacan-oraculo` (12/05/2026), #1 (`detect_environment()` da aba de depuração), #2
  (clique no cartão do provedor, resolvido na 2.5.0) e #3 (modelo configurado ignorado) já estavam
  resolvidos aqui por outro caminho. O #4 (provedor OpenAI genérico + estratégia de RAG) foi portado:
  `GenericOpenAIProvider` mantém a lógica dele (descoberta em /models, detecção de modelo de
  embedding por tipo/capacidade/nome, fallback `/ollama/api/embed` do Open WebUI), com três
  mudanças: (1) **a lista de modelos só lê cache** — `get_available_providers()` monta todos os
  provedores em várias telas, e no PR original isso consultava /models a cada carregamento (com o
  servidor fora do ar, cada tela esperaria o timeout; falha não era cacheada); o cache é preenchido
  por "Testar Conexão"/"Buscar modelos"; (2) a busca em duas etapas: sem itens (provedor, ou híbrido
  que falhou) a etapa `items` já devolve a resposta inteira, senão o front mostraria "nada
  encontrado"; (3) sem contexto, a busca manda a pergunta pura, não o prompt "itens listados
  abaixo". Testado só com servidor falso (`php -S`, 13 verificações), **não no laboratório**.

- **2.7.x (22/10/2026), velocidade e layout.** Busca vetorial em duas fases: assinatura binária
  (`embedding_bits`, Hamming) seleciona candidatos e só eles recebem o cosseno exato (20× o limite,
  mínimo 400). Numa coleção de 14,5 mil vetores, a busca foi de 9–18 s para ~0,2 s com recall@20 de
  99%, e o chat de ~20 s para ~5 s (o resto é o modelo). A resposta da "Busca com IA" virou síntese
  com citações [n] ligadas a cartões numerados. Motivo: a resposta antiga misturava URLs soltas no
  texto com cartões repetidos, e o "0,6 s" que parecia rápido era cache.
- **2.8.x (22/10/2026), BIA coerente com a busca.** A BIA usa o mesmo escopo
  (`ThemeIntegration::get_search_scope()`) e a mesma consulta reduzida ao assunto
  (`retrieval_query()`). "livros sobre o nordeste" × "o que tem para nordeste?" passou de 0 para
  10 itens em comum. Painel ocupa a linha inteira da listagem, pode ser recolhido e reaberto sem
  nova busca, tons pastel com cartões brancos (contraste).
- **2.10.x (23–24/10/2026), busca dentro dos documentos.** `DocumentIndexer` + `ChunkStore` +
  fila `_oraculo_docs_pending`: lê o texto do OCR (plugin Tainacan OCR Search), de PDFs com camada
  de texto (pdftotext) e o `document_content_index` do núcleo; trechos por página; cartões e BIA
  citam arquivo e página. Decisão: o Oráculo **não faz OCR**, só consome — o OCR fica no Tainacan
  OCR Search (local, livre, serve também à busca nativa).

- **2.12.0 (29/10/2026), busca em duas etapas.** A IA é ~85% do tempo: `POST /search` com
  `phase=items` devolve os cartões (~1,6 s no navegador) e um bilhete de uso único; `phase=answer`
  com o bilhete escreve a síntese sobre a recuperação guardada (só item, similaridade e trechos) e o
  JS troca só o bloco da resposta. O bilhete isenta a answer dos limites (a items já contou). Cache
  do embedding da pergunta só na busca (2 h). Sem transação entre origem e tabela lateral: deadlock
  na cópia desfazia também o vetor (achado do code-review high).
- **2.13.0 (29/10/2026), uso intenso.** Teste de carga com 40 buscas + 30 conversas simultâneas
  numa VPS de 1 núcleo: o gargalo eram os processos PHP presos esperando a IA e, ao aumentá-los, a
  CPU. Configuração recomendada = gpt-4.1-mini + modo rápido + processos PHP próprios: pior caso de
  64 s para 26,5 s; página inicial de 22,6 s para 0,7 s durante o pico. Entregas: aba
  **Diagnóstico** (`Admin\Diagnostics`, teste ao vivo em `POST diagnostics/probe`), **modo rápido**
  (`Performance\FastRest` copia `mu-plugin/oraculo-tainacan-rest-leve.php` para `mu-plugins`; nas
  rotas search/chat/probe filtra `option_active_plugins` para só o Oráculo, + Tainacan no modo CLIP,
  que usa `get_permalink`), limites como opções (`rate_limit_*`, teto global 60 → 300, redes
  liberadas por CIDR sobre `REMOTE_ADDR`), cache da 1ª mensagem da BIA, gpt-4.1-mini como padrão.
  Modelo local (qwen 1.5B/2B no Ollama, CPU) descartado para uso ao vivo: 58–210 s e alucinação.
  Números completos no manual (seção 11) e no repositório de infraestrutura.
- **2.11.0 (29/10/2026), busca vetorial dentro do MariaDB 11.7+.** Tabelas laterais estreitas
  `oraculo_vectors_native` e `oraculo_chunks_native` (id, item, coleção, anexo, `embedding_vec
  VECTOR(n)`), preenchidas por cron em faixas de id e espelhadas em toda gravação e remoção
  (`NativeVector`); o cosseno é calculado no banco e só os ids voltam. **Tabela lateral, não
  coluna:** a de vetores tem ~40 KB por linha (1,28 GB no site do TainacanBiblio, buffer do InnoDB
  de 512 MB) e, com a coluna dentro dela, varrer o acervo todo levou 5–8 s lendo o disco. Medido no MariaDB 11.8 do site do
  TainacanBiblio (38 mil vetores, 1536 dimensões): varredura exata ~110 ms no acervo todo, ~30 ms
  numa coleção de 8 mil. **Sem índice HNSW de propósito:** com filtro por coleção ele devolveu
  13–46% de recall e consultas de 18–32 s (filtra depois de buscar), e só rende com ~500 MB de
  cache. Resolve os muros de memória e transferência da `ANALISE-LIMITE-VETORES.md` sem banco
  vetorial externo; continua O(n) no banco (~3 µs por vetor), então um índice só faria sentido
  acima de ~100–200 mil vetores e sem filtro.

## Armadilhas que já custaram tempo

- **VECTOR no MariaDB.** `vector` é palavra reservada (por isso `embedding_vec`); a coluna tem
  dimensão fixa, então linhas de outro modelo ficam fora da tabela lateral; o `VEC_FromText` aceita
  o JSON do `embedding_data` e o BLOB float32 do `ChunkStore` vai direto para a coluna.
  `VEC_FromText(%s)` dentro do `ORDER BY` é reavaliado **a cada linha** (+1,8 s em 8 mil): converter
  uma vez numa variável de sessão (`SET @oraculo_q = …`).

- **Modo rápido e `Requires Plugins`.** O mu-plugin tira o Tainacan da lista de ativos só nas
  rotas do Oráculo; funciona porque o núcleo não confere dependências ao carregar. Nunca deixar a
  lista enxuta ser gravada: o mu-plugin bloqueia `update_option('active_plugins')` nesses pedidos.
  Qualquer código novo na busca/chat que chame classe do Tainacan quebra no modo rápido — inclua o
  Tainacan em `FastRest::plugins()` ou evite a chamada.
- **Grupo PHP próprio** é reconhecido pela variável `ORACULO_PHP_POOL` (receita do manual). Editar
  o vhost pelo painel do CloudPanel apaga o `fastcgi_pass $…_php`.
- **Itens do Tainacan têm `exclude_from_search`.** Consulta com `post_type => 'any'` não acha
  nenhum item. Use a lista explícita (`AutoIndexer::get_item_post_types()`).
- **Cache da busca.** A chave leva `format` (5 desde a 2.10.0). Mudou o formato da resposta,
  incremente. Ao medir velocidade, "Respondido em <1 s" costuma ser cache.
- **Prompt salvo antigo.** Sites com prompt de sistema salvo de versões antigas pedem "forneça o
  link"; por isso as regras de formato vão no **fim** do prompt da mensagem, onde prevalecem.
- **Coleção sintética do site do TainacanBiblio.** ~976 títulos "X sobre/para a Y nordestina" com
  metadados aleatórios: em assunto amplo o top-10 é amostra, e respostas "estranhas" (filatelia,
  vidro) vêm dos dados, não do plugin.
- **strict_types + `$wpdb`.** `$wpdb` devolve string; cast na origem (ver CONTEXT.md).
- **Página citada** é a do PDF; livros com numeração impressa própria podem diferir.

## Pendências

- **2.14.0 (10/11/2026)**: testar no laboratório com um servidor compatível real (Open WebUI ou o
  /v1 do Ollama): salvar URL, "Testar Conexão", "Buscar modelos", busca nas três estratégias, BIA
  com "provedor", e indexar com o genérico como provedor de embeddings. Depois, abrir o PR no
  repositório oficial, marcar o Mateus para revisão e fechar os PRs #1–#4 lá com comentário.

- **2.13.0 (29/10/2026)**: Gemini (Flash-Lite) não entrou na comparação de modelos por falta de
  chave; o teste de carga em `docs/manual` é reproduzível com o mesmo cenário.
- **2.12.0 publicada (29/10/2026)**, release v2.12.0 com ZIP e o manual PDF (`docs/manual/`).
  Falta testar a tabela lateral dos **trechos** num site com documentos (o laboratório de OCR, que
  ainda está na 2.10.2) e, se houver interesse em publicar no diretório, instalar as regras do Plugin
  Check no composer para o `phpcs.xml.dist` (TNC-R08). Ressalvas da revisão não corrigidas:
  `AutoIndexer::enqueue_collection` faz ~3 consultas por item (enfileirar 42 mil itens de uma vez);
  CSS da aba mira `.search-control-item--search` do tema; CLIP sem `_prime_post_caches`.

- Teste de navegador com usuário logado na "Busca com IA" (só o visitante foi testado).
- Fora do MariaDB 11.7+ a varredura continua em PHP (duas fases), com os limites da
  `ANALISE-LIMITE-VETORES.md`. No 11.7+ ela é no banco e linear (~3 µs por vetor): medir de novo
  quando um site passar de ~100 mil vetores + trechos.
- Existe uma 3.0.0 inativa num terceiro site de teste que não está no GitHub (registrada no
  repositório de infraestrutura).
