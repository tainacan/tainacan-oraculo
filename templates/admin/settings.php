<?php
/**
 * Template de Configurações
 *
 * @package Oraculo_Tainacan
 */

if (!defined('ABSPATH')) exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template file; all variables are local to this included template scope.

$options = \Oraculo_Tainacan\Oraculo_Tainacan::get_options();
$factory = new \Oraculo_Tainacan\AI\AIProviderFactory();
$providers = $factory->get_available_providers();
$collections = \Oraculo_Tainacan\get_tainacan_collections();
?>

<div class="oraculo-settings-content">
    <div class="notice oraculo-notice" style="display:none;"></div>
    <form method="post" id="oraculo-settings-form">
        <?php wp_nonce_field('oraculo_admin', 'nonce'); ?>

        <div class="oraculo-settings-tabs">
            <nav class="oraculo-tabs-nav">
                <button type="button" class="oraculo-tab-btn active" data-tab="providers">
                    <?php esc_html_e('Provedores de IA', 'oraculo_tainacan'); ?>
                </button>
                <button type="button" class="oraculo-tab-btn" data-tab="general">
                    <?php esc_html_e('Geral', 'oraculo_tainacan'); ?>
                </button>
                <button type="button" class="oraculo-tab-btn" data-tab="prompts">
                    <?php esc_html_e('Prompts', 'oraculo_tainacan'); ?>
                </button>
                <button type="button" class="oraculo-tab-btn" data-tab="appearance">
                    <?php esc_html_e('Aparência', 'oraculo_tainacan'); ?>
                </button>
                <button type="button" class="oraculo-tab-btn" data-tab="advanced">
                    <?php esc_html_e('Avançado', 'oraculo_tainacan'); ?>
                </button>
            </nav>

            <!-- Tab: Provedores de IA -->
            <div class="oraculo-tab-content active" id="tab-providers">
                <h2><?php esc_html_e('Provedores de IA', 'oraculo_tainacan'); ?></h2>
                <p class="description">
                    <?php esc_html_e('Selecione e configure o provedor de IA para geração de respostas.', 'oraculo_tainacan'); ?>
                </p>

                <div class="oraculo-providers-grid">
                    <?php foreach ($providers as $provider): ?>
                    <div class="oraculo-provider-card <?php echo $options['ai_provider'] === $provider['id'] ? 'selected' : ''; ?>"
                         data-provider="<?php echo esc_attr($provider['id']); ?>">
                        <label class="oraculo-provider-label">
                            <span class="oraculo-provider-label-row">
                                <input type="radio"
                                       name="oraculo_tainacan_options[ai_provider]"
                                       value="<?php echo esc_attr($provider['id']); ?>"
                                       <?php checked($options['ai_provider'], $provider['id']); ?>>
                                <span class="oraculo-provider-name"><?php echo esc_html($provider['name']); ?></span>
                                <?php if ($provider['is_configured']): ?>
                                    <span class="oraculo-provider-badge configured"><?php esc_html_e('Configurado', 'oraculo_tainacan'); ?></span>
                                <?php endif; ?>
                            </span>
                            <p class="oraculo-provider-description"><?php echo esc_html($provider['description']); ?></p>
                            <span class="oraculo-provider-features">
                                <?php if ($provider['supports_embeddings']): ?>
                                    <span class="feature">📊 Embeddings</span>
                                <?php endif; ?>
                                <?php if ($provider['supports_streaming']): ?>
                                    <span class="feature">⚡ Streaming</span>
                                <?php endif; ?>
                            </span>
                        </label>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- Configurações específicas de cada provedor -->
                <div class="oraculo-provider-settings" id="provider-settings-openai" style="<?php echo $options['ai_provider'] === 'openai' ? '' : 'display:none;'; ?>">
                    <h3>OpenAI (ChatGPT)</h3>
                    <table class="form-table">
                        <tr>
                            <th><?php esc_html_e('API Key', 'oraculo_tainacan'); ?></th>
                            <td>
                                <input type="password"
                                       name="oraculo_tainacan_options[openai_api_key]"
                                       value="<?php echo !empty($options['openai_api_key']) ? '••••••••' : ''; ?>"
                                       class="regular-text"
                                       placeholder="sk-...">
                                <button type="button" class="button oraculo-test-connection" data-provider="openai">
                                    <?php esc_html_e('Testar Conexão', 'oraculo_tainacan'); ?>
                                </button>
                                <p class="description">
                                    <a href="https://platform.openai.com/api-keys" target="_blank">
                                        <?php esc_html_e('Obter API Key', 'oraculo_tainacan'); ?>
                                    </a>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e('Modelo de Chat', 'oraculo_tainacan'); ?></th>
                            <td>
                                <select name="oraculo_tainacan_options[openai_model]">
                                    <?php
                                    $openai_models = [
                                        'gpt-4o' => 'GPT-4o (Recomendado)',
                                        'gpt-4o-mini' => 'GPT-4o Mini (Econômico)',
                                        'gpt-4-turbo' => 'GPT-4 Turbo',
                                        'gpt-4' => 'GPT-4',
                                        'gpt-3.5-turbo' => 'GPT-3.5 Turbo',
                                        'o1' => 'o1 (Raciocínio)',
                                        'o1-mini' => 'o1 Mini',
                                    ];
                                    foreach ($openai_models as $id => $name):
                                    ?>
                                    <option value="<?php echo esc_attr($id); ?>" <?php selected($options['openai_model'] ?? 'gpt-4o-mini', $id); ?>>
                                        <?php echo esc_html($name); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e('Modelo de Embedding', 'oraculo_tainacan'); ?></th>
                            <td>
                                <select name="oraculo_tainacan_options[openai_embedding_model]">
                                    <option value="text-embedding-ada-002" <?php selected($options['openai_embedding_model'] ?? '', 'text-embedding-ada-002'); ?>>Ada 002 (Padrão)</option>
                                    <option value="text-embedding-3-small" <?php selected($options['openai_embedding_model'] ?? '', 'text-embedding-3-small'); ?>>Embedding 3 Small (Econômico)</option>
                                    <option value="text-embedding-3-large" <?php selected($options['openai_embedding_model'] ?? '', 'text-embedding-3-large'); ?>>Embedding 3 Large (Melhor qualidade)</option>
                                </select>
                            </td>
                        </tr>
                    </table>
                </div>

                <div class="oraculo-provider-settings" id="provider-settings-gemini" style="<?php echo $options['ai_provider'] === 'gemini' ? '' : 'display:none;'; ?>">
                    <h3>Google Gemini</h3>
                    <table class="form-table">
                        <tr>
                            <th><?php esc_html_e('API Key', 'oraculo_tainacan'); ?></th>
                            <td>
                                <input type="password"
                                       name="oraculo_tainacan_options[gemini_api_key]"
                                       value="<?php echo !empty($options['gemini_api_key']) ? '••••••••' : ''; ?>"
                                       class="regular-text">
                                <button type="button" class="button oraculo-test-connection" data-provider="gemini">
                                    <?php esc_html_e('Testar Conexão', 'oraculo_tainacan'); ?>
                                </button>
                                <p class="description">
                                    <a href="https://aistudio.google.com/app/apikey" target="_blank">
                                        <?php esc_html_e('Obter API Key', 'oraculo_tainacan'); ?>
                                    </a>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e('Modelo', 'oraculo_tainacan'); ?></th>
                            <td>
                                <select name="oraculo_tainacan_options[gemini_model]">
                                    <option value="gemini-2.0-flash-exp" <?php selected($options['gemini_model'] ?? '', 'gemini-2.0-flash-exp'); ?>>Gemini 2.0 Flash (Experimental)</option>
                                    <option value="gemini-1.5-pro" <?php selected($options['gemini_model'] ?? '', 'gemini-1.5-pro'); ?>>Gemini 1.5 Pro</option>
                                    <option value="gemini-1.5-flash" <?php selected($options['gemini_model'] ?? '', 'gemini-1.5-flash'); ?>>Gemini 1.5 Flash</option>
                                </select>
                            </td>
                        </tr>
                    </table>
                </div>

                <div class="oraculo-provider-settings" id="provider-settings-ollama" style="<?php echo $options['ai_provider'] === 'ollama' ? '' : 'display:none;'; ?>">
                    <h3>Ollama (Local)</h3>
                    <table class="form-table">
                        <tr>
                            <th><?php esc_html_e('URL do Servidor', 'oraculo_tainacan'); ?></th>
                            <td>
                                <input type="url"
                                       name="oraculo_tainacan_options[ollama_url]"
                                       value="<?php echo esc_attr($options['ollama_url'] ?? 'http://localhost:11434'); ?>"
                                       class="regular-text">
                                <button type="button" class="button oraculo-test-connection" data-provider="ollama">
                                    <?php esc_html_e('Testar Conexão', 'oraculo_tainacan'); ?>
                                </button>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e('Modelo de Chat', 'oraculo_tainacan'); ?></th>
                            <td>
                                <input type="text"
                                       name="oraculo_tainacan_options[ollama_model]"
                                       value="<?php echo esc_attr($options['ollama_model'] ?? 'llama3.2'); ?>"
                                       class="regular-text"
                                       placeholder="llama3.2">
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e('Modelo de Embedding', 'oraculo_tainacan'); ?></th>
                            <td>
                                <input type="text"
                                       name="oraculo_tainacan_options[ollama_embedding_model]"
                                       value="<?php echo esc_attr($options['ollama_embedding_model'] ?? 'nomic-embed-text'); ?>"
                                       class="regular-text"
                                       placeholder="nomic-embed-text">
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- Adicione configurações similares para outros provedores... -->
            </div>

            <!-- Tab: Geral -->
            <div class="oraculo-tab-content" id="tab-general">
                <h2><?php esc_html_e('Configurações Gerais', 'oraculo_tainacan'); ?></h2>

                <table class="form-table">
                    <tr>
                        <th><?php esc_html_e('Coleções Padrão', 'oraculo_tainacan'); ?></th>
                        <td>
                            <select name="oraculo_tainacan_options[default_collections][]" multiple class="oraculo-multiselect">
                                <?php foreach ($collections as $collection): ?>
                                <option value="<?php echo esc_attr($collection['id']); ?>"
                                        <?php echo in_array($collection['id'], $options['default_collections'] ?? []) ? 'selected' : ''; ?>>
                                    <?php echo esc_html($collection['name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description"><?php esc_html_e('Coleções usadas por padrão nas buscas.', 'oraculo_tainacan'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Máximo de Tokens', 'oraculo_tainacan'); ?></th>
                        <td>
                            <input type="number"
                                   name="oraculo_tainacan_options[max_tokens]"
                                   value="<?php echo esc_attr($options['max_tokens'] ?? 2000); ?>"
                                   min="100" max="16000" step="100">
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Temperatura', 'oraculo_tainacan'); ?></th>
                        <td>
                            <input type="range"
                                   name="oraculo_tainacan_options[temperature]"
                                   value="<?php echo esc_attr($options['temperature'] ?? 0.7); ?>"
                                   min="0" max="2" step="0.1"
                                   id="temperature-slider">
                            <span id="temperature-value"><?php echo esc_html($options['temperature'] ?? 0.7); ?></span>
                            <p class="description"><?php esc_html_e('0 = mais determinístico, 2 = mais criativo', 'oraculo_tainacan'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Limiar de Similaridade', 'oraculo_tainacan'); ?></th>
                        <td>
                            <input type="range"
                                   name="oraculo_tainacan_options[similarity_threshold]"
                                   value="<?php echo esc_attr($options['similarity_threshold'] ?? 0.3); ?>"
                                   min="0" max="1" step="0.05"
                                   id="similarity-slider">
                            <span id="similarity-value"><?php echo esc_html(($options['similarity_threshold'] ?? 0.3) * 100); ?>%</span>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Máximo de Resultados', 'oraculo_tainacan'); ?></th>
                        <td>
                            <input type="number"
                                   name="oraculo_tainacan_options[max_results]"
                                   value="<?php echo esc_attr($options['max_results'] ?? 10); ?>"
                                   min="1" max="50">
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Funcionalidades', 'oraculo_tainacan'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="oraculo_tainacan_options[enable_chat]" value="1"
                                       <?php checked($options['enable_chat'] ?? true); ?>>
                                <?php esc_html_e('Habilitar Chat', 'oraculo_tainacan'); ?>
                            </label><br>
                            <label>
                                <input type="checkbox" name="oraculo_tainacan_options[enable_search]" value="1"
                                       <?php checked($options['enable_search'] ?? true); ?>>
                                <?php esc_html_e('Habilitar Busca', 'oraculo_tainacan'); ?>
                            </label><br>
                            <label>
                                <input type="checkbox" name="oraculo_tainacan_options[enable_analytics]" value="1"
                                       <?php checked($options['enable_analytics'] ?? true); ?>>
                                <?php esc_html_e('Habilitar Analytics', 'oraculo_tainacan'); ?>
                            </label><br>
                            <label>
                                <input type="checkbox" name="oraculo_tainacan_options[enable_feedback]" value="1"
                                       <?php checked($options['enable_feedback'] ?? true); ?>>
                                <?php esc_html_e('Habilitar Feedback', 'oraculo_tainacan'); ?>
                            </label>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- Tab: Prompts -->
            <div class="oraculo-tab-content" id="tab-prompts">
                <h2><?php esc_html_e('Prompts Personalizados', 'oraculo_tainacan'); ?></h2>

                <table class="form-table">
                    <tr>
                        <th><?php esc_html_e('Prompt do Sistema', 'oraculo_tainacan'); ?></th>
                        <td>
                            <textarea name="oraculo_tainacan_options[system_prompt]"
                                      rows="8" class="large-text code"><?php echo esc_textarea($options['system_prompt'] ?? ''); ?></textarea>
                            <p class="description"><?php esc_html_e('Instruções gerais para o assistente.', 'oraculo_tainacan'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Mensagem de Boas-vindas', 'oraculo_tainacan'); ?></th>
                        <td>
                            <textarea name="oraculo_tainacan_options[welcome_message]"
                                      rows="3" class="large-text"><?php echo esc_textarea($options['welcome_message'] ?? ''); ?></textarea>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Perguntas Sugeridas', 'oraculo_tainacan'); ?></th>
                        <td>
                            <div id="suggested-questions">
                                <?php
                                $suggested = $options['suggested_questions'] ?? [];
                                foreach ($suggested as $index => $question):
                                ?>
                                <div class="suggested-question-row">
                                    <input type="text"
                                           name="oraculo_tainacan_options[suggested_questions][]"
                                           value="<?php echo esc_attr($question); ?>"
                                           class="regular-text">
                                    <button type="button" class="button remove-question">✕</button>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <button type="button" class="button" id="add-question">
                                <?php esc_html_e('Adicionar Pergunta', 'oraculo_tainacan'); ?>
                            </button>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- Tab: Aparência -->
            <div class="oraculo-tab-content" id="tab-appearance">
                <h2><?php esc_html_e('Aparência', 'oraculo_tainacan'); ?></h2>

                <table class="form-table">
                    <tr>
                        <th><?php esc_html_e('Cor Primária', 'oraculo_tainacan'); ?></th>
                        <td>
                            <input type="color"
                                   name="oraculo_tainacan_options[appearance][primary_color]"
                                   value="<?php echo esc_attr($options['appearance']['primary_color'] ?? '#1f2f56'); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Cor de Destaque', 'oraculo_tainacan'); ?></th>
                        <td>
                            <input type="color"
                                   name="oraculo_tainacan_options[appearance][accent_color]"
                                   value="<?php echo esc_attr($options['appearance']['accent_color'] ?? '#b5e0e3'); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Posição do Chat', 'oraculo_tainacan'); ?></th>
                        <td>
                            <select name="oraculo_tainacan_options[appearance][chat_position]">
                                <option value="bottom-right" <?php selected($options['appearance']['chat_position'] ?? '', 'bottom-right'); ?>><?php esc_html_e('Inferior Direito', 'oraculo_tainacan'); ?></option>
                                <option value="bottom-left" <?php selected($options['appearance']['chat_position'] ?? '', 'bottom-left'); ?>><?php esc_html_e('Inferior Esquerdo', 'oraculo_tainacan'); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Opções de Exibição', 'oraculo_tainacan'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="oraculo_tainacan_options[appearance][show_sources]" value="1"
                                       <?php checked($options['appearance']['show_sources'] ?? true); ?>>
                                <?php esc_html_e('Mostrar fontes nas respostas', 'oraculo_tainacan'); ?>
                            </label><br>
                            <label>
                                <input type="checkbox" name="oraculo_tainacan_options[appearance][show_similarity]" value="1"
                                       <?php checked($options['appearance']['show_similarity'] ?? false); ?>>
                                <?php esc_html_e('Mostrar score de similaridade', 'oraculo_tainacan'); ?>
                            </label>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- Tab: Avançado -->
            <div class="oraculo-tab-content" id="tab-advanced">
                <h2><?php esc_html_e('Configurações Avançadas', 'oraculo_tainacan'); ?></h2>

                <table class="form-table">
                    <tr>
                        <th><?php esc_html_e('Tamanho do Batch', 'oraculo_tainacan'); ?></th>
                        <td>
                            <input type="number"
                                   name="oraculo_tainacan_options[batch_size]"
                                   value="<?php echo esc_attr($options['batch_size'] ?? 25); ?>"
                                   min="5" max="100">
                            <p class="description"><?php esc_html_e('Itens processados por vez na indexação.', 'oraculo_tainacan'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Timeout de Requisição', 'oraculo_tainacan'); ?></th>
                        <td>
                            <input type="number"
                                   name="oraculo_tainacan_options[request_timeout]"
                                   value="<?php echo esc_attr($options['request_timeout'] ?? 120); ?>"
                                   min="30" max="300">
                            <span><?php esc_html_e('segundos', 'oraculo_tainacan'); ?></span>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Duração do Cache', 'oraculo_tainacan'); ?></th>
                        <td>
                            <input type="number"
                                   name="oraculo_tainacan_options[cache_duration]"
                                   value="<?php echo esc_attr($options['cache_duration'] ?? 3600); ?>"
                                   min="0" max="86400">
                            <span><?php esc_html_e('segundos (0 = desativado)', 'oraculo_tainacan'); ?></span>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Campos para Indexar', 'oraculo_tainacan'); ?></th>
                        <td>
                            <?php
                            $index_fields = $options['index_fields'] ?? ['title', 'description'];
                            $available_fields = ['title' => 'Título', 'description' => 'Descrição', 'metadata' => 'Metadados'];
                            foreach ($available_fields as $field => $label):
                            ?>
                            <label>
                                <input type="checkbox"
                                       name="oraculo_tainacan_options[index_fields][]"
                                       value="<?php echo esc_attr($field); ?>"
                                       <?php checked(in_array($field, $index_fields)); ?>>
                                <?php echo esc_html($label); ?>
                            </label><br>
                            <?php endforeach; ?>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Modo Debug', 'oraculo_tainacan'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="oraculo_tainacan_options[debug_mode]" value="1"
                                       <?php checked($options['debug_mode'] ?? false); ?>>
                                <?php esc_html_e('Habilitar logs de debug', 'oraculo_tainacan'); ?>
                            </label>
                        </td>
                    </tr>
                </table>

                <h3><?php esc_html_e('Manutenção', 'oraculo_tainacan'); ?></h3>
                <p>
                    <button type="button" class="button" id="clear-cache">
                        <?php esc_html_e('Limpar Cache', 'oraculo_tainacan'); ?>
                    </button>
                    <button type="button" class="button" id="clear-vectors">
                        <?php esc_html_e('Limpar Vetores', 'oraculo_tainacan'); ?>
                    </button>
                    <button type="button" class="button" id="export-settings">
                        <?php esc_html_e('Exportar Configurações', 'oraculo_tainacan'); ?>
                    </button>
                </p>
            </div>
        </div>

        <?php submit_button(__('Salvar Configurações', 'oraculo_tainacan')); ?>
    </form>
</div>

<!-- Estilos movidos para admin-page.css -->

<script>
jQuery(document).ready(function($) {
    // Configuração AJAX - usar variável global do WordPress
    var oraculoAjax = {
        ajaxUrl: (typeof ajaxurl !== 'undefined') ? ajaxurl : '<?php echo esc_url(admin_url('admin-ajax.php')); ?>',
        nonce: '<?php echo esc_js( wp_create_nonce('oraculo_admin') ); ?>'
    };

    // Tabs
    $('.oraculo-tab-btn').on('click', function() {
        var tab = $(this).data('tab');
        $('.oraculo-tab-btn').removeClass('active');
        $(this).addClass('active');
        $('.oraculo-tab-content').removeClass('active');
        $('#tab-' + tab).addClass('active');
    });

    // Provider selection
    function oraculoApplyProviderSelection(provider, card) {
        $('.oraculo-provider-card').removeClass('selected');
        card.addClass('selected');
        $('.oraculo-provider-settings').hide();
        var panel = $('#provider-settings-' + provider);
        if (panel.length) {
            panel.show();
        }
    }

    $('.oraculo-provider-card input[type="radio"]').on('change', function() {
        var provider = $(this).val();
        var card = $(this).closest('.oraculo-provider-card');
        oraculoApplyProviderSelection(provider, card);
    });

    $('.oraculo-providers-grid').on('click', '.oraculo-provider-card', function() {
        var card = $(this);
        var radio = card.find('input[type="radio"]');
        if (!radio.prop('checked')) {
            radio.prop('checked', true).trigger('change');
            return;
        }
        oraculoApplyProviderSelection(radio.val(), card);
    });

    // Sliders
    $('#temperature-slider').on('input', function() {
        $('#temperature-value').text($(this).val());
    });
    $('#similarity-slider').on('input', function() {
        $('#similarity-value').text(Math.round($(this).val() * 100) + '%');
    });

    // Add question
    $('#add-question').on('click', function() {
        var row = '<div class="suggested-question-row">' +
                  '<input type="text" name="oraculo_tainacan_options[suggested_questions][]" class="regular-text">' +
                  '<button type="button" class="button remove-question">✕</button>' +
                  '</div>';
        $('#suggested-questions').append(row);
    });

    // Remove question
    $(document).on('click', '.remove-question', function() {
        $(this).closest('.suggested-question-row').remove();
    });

    // Test connection
    $('.oraculo-test-connection').addClass('has-handler').on('click', function(e) {
        e.preventDefault();
        var button = $(this);
        var provider = button.data('provider');
        button.prop('disabled', true).text('<?php esc_html_e('Testando...', 'oraculo_tainacan'); ?>');

        $.ajax({
            url: oraculoAjax.ajaxUrl,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'oraculo_test_connection',
                provider: provider,
                nonce: oraculoAjax.nonce
            },
            success: function(response) {
                button.prop('disabled', false).text('<?php esc_html_e('Testar Conexão', 'oraculo_tainacan'); ?>');
                if (response && response.success) {
                    alert('✅ ' + (response.data && response.data.message ? response.data.message : '<?php esc_html_e('Conexão OK!', 'oraculo_tainacan'); ?>'));
                } else {
                    alert('❌ ' + (response && response.data && response.data.message ? response.data.message : '<?php esc_html_e('Erro na conexão', 'oraculo_tainacan'); ?>'));
                }
            },
            error: function(xhr, status, error) {
                button.prop('disabled', false).text('<?php esc_html_e('Testar Conexão', 'oraculo_tainacan'); ?>');
                alert('❌ <?php esc_html_e('Erro de conexão', 'oraculo_tainacan'); ?>: ' + error);
            }
        });
    });

    // Clear cache
    $('#clear-cache').on('click', function() {
        var button = $(this);
        button.prop('disabled', true).text('<?php esc_html_e('Limpando...', 'oraculo_tainacan'); ?>');

        $.ajax({
            url: oraculoAjax.ajaxUrl,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'oraculo_clear_cache',
                nonce: oraculoAjax.nonce
            },
            success: function(response) {
                button.prop('disabled', false).text('<?php esc_html_e('Limpar Cache', 'oraculo_tainacan'); ?>');
                if (response && response.success) {
                    alert('✅ <?php esc_html_e('Cache limpo com sucesso!', 'oraculo_tainacan'); ?>');
                } else {
                    alert('❌ ' + (response && response.data && response.data.message ? response.data.message : '<?php esc_html_e('Erro ao limpar cache', 'oraculo_tainacan'); ?>'));
                }
            },
            error: function(xhr, status, error) {
                button.prop('disabled', false).text('<?php esc_html_e('Limpar Cache', 'oraculo_tainacan'); ?>');
                alert('❌ <?php esc_html_e('Erro de conexão', 'oraculo_tainacan'); ?>: ' + error);
            }
        });
    });

    // Clear vectors
    $('#clear-vectors').on('click', function() {
        if (!confirm('<?php esc_html_e('Tem certeza? Isso removerá todos os vetores indexados.', 'oraculo_tainacan'); ?>')) {
            return;
        }

        var button = $(this);
        button.prop('disabled', true).text('<?php esc_html_e('Limpando...', 'oraculo_tainacan'); ?>');

        $.ajax({
            url: oraculoAjax.ajaxUrl,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'oraculo_clear_all_vectors',
                nonce: oraculoAjax.nonce
            },
            success: function(response) {
                button.prop('disabled', false).text('<?php esc_html_e('Limpar Vetores', 'oraculo_tainacan'); ?>');
                if (response && response.success) {
                    alert('✅ <?php esc_html_e('Vetores limpos com sucesso!', 'oraculo_tainacan'); ?>');
                } else {
                    alert('❌ ' + (response && response.data && response.data.message ? response.data.message : '<?php esc_html_e('Erro ao limpar vetores', 'oraculo_tainacan'); ?>'));
                }
            },
            error: function(xhr, status, error) {
                button.prop('disabled', false).text('<?php esc_html_e('Limpar Vetores', 'oraculo_tainacan'); ?>');
                alert('❌ <?php esc_html_e('Erro de conexão', 'oraculo_tainacan'); ?>: ' + error);
            }
        });
    });

    // Salvar configurações via AJAX
    $('#oraculo-settings-form').on('submit', function(e) {
        e.preventDefault();

        var $form = $(this);
        var $button = $form.find('input[type="submit"], button[type="submit"]');
        var $notice = $('.oraculo-notice');
        var originalText = $button.val() || $button.text();

        $button.prop('disabled', true).val('<?php esc_html_e('Salvando...', 'oraculo_tainacan'); ?>');

        $.ajax({
            url: oraculoAjax.ajaxUrl,
            type: 'POST',
            dataType: 'json',
            data: $form.serialize() + '&action=oraculo_save_settings',
            success: function(response) {
                if (response && response.success) {
                    $notice.removeClass('notice-error').addClass('notice-success')
                        .html('<p><?php esc_html_e('Configurações salvas com sucesso!', 'oraculo_tainacan'); ?></p>').show();
                } else {
                    var msg = (response && response.data && response.data.message) ? response.data.message : '<?php esc_html_e('Erro ao salvar', 'oraculo_tainacan'); ?>';
                    $notice.removeClass('notice-success').addClass('notice-error')
                        .html('<p>' + msg + '</p>').show();
                }
            },
            error: function(xhr, status, error) {
                $notice.removeClass('notice-success').addClass('notice-error')
                    .html('<p><?php esc_html_e('Erro de conexão', 'oraculo_tainacan'); ?>: ' + error + '</p>').show();
            },
            complete: function() {
                $button.prop('disabled', false).val(originalText);
                setTimeout(function() {
                    $notice.fadeOut();
                }, 5000);
            }
        });
    });
});
</script>
<?php // phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound ?>
