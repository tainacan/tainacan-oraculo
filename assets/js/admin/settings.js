/**
 * Oráculo Tainacan - Configurações (aba settings)
 *
 * Extraído do inline <script> de templates/admin/settings.php.
 * Config/i18n via objeto localizado OraculoAdminPage.
 */

(function($) {
	'use strict';

	$(document).ready(function() {
		var cfg = window.OraculoAdminPage || {};
		var s = cfg.strings || {};

		function escapeHtml(value) {
			if (value === null || value === undefined) {
				return '';
			}
			return String(value).replace(/[&<>"']/g, function(ch) {
				return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[ch];
			});
		}

		// Tabs
		$('.oraculo-tab-btn').on('click', function() {
			var tab = $(this).data('tab');
			$('.oraculo-tab-btn').removeClass('active');
			$(this).addClass('active');
			$('.oraculo-tab-content').removeClass('active');
			$('#tab-' + tab).addClass('active');
		});

		// Provider selection
		$('.oraculo-provider-card input[type="radio"]').on('change', function() {
			var provider = $(this).val();
			$('.oraculo-provider-card').removeClass('selected');
			$(this).closest('.oraculo-provider-card').addClass('selected');
			$('.oraculo-provider-settings').hide();
			$('#provider-settings-' + provider).show();
		});

		// O card inteiro seleciona o provedor (o CSS já sinaliza com
		// cursor:pointer): clicar na descrição/área fora do label marca o
		// radio e dispara o change acima. Cliques no próprio label/radio
		// seguem o fluxo nativo para não disparar duas vezes.
		$('.oraculo-provider-card').on('click', function(e) {
			if ($(e.target).closest('label, input').length) {
				return;
			}
			var radio = $(this).find('input[type="radio"]');
			if (radio.length && !radio.prop('checked')) {
				radio.prop('checked', true).trigger('change');
			}
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
			button.prop('disabled', true).text(s.testing || '...');

			$.ajax({
				url: cfg.ajaxUrl,
				type: 'POST',
				dataType: 'json',
				data: {
					action: 'oraculo_test_connection',
					provider: provider,
					nonce: cfg.nonce
				},
				success: function(response) {
					button.prop('disabled', false).text(s.testConnection || 'Test');
					if (response && response.success) {
						window.alert('✅ ' + (response.data && response.data.message ? response.data.message : s.connectionOk));
					} else {
						window.alert('❌ ' + (response && response.data && response.data.message ? response.data.message : s.connectionFailed));
					}
				},
				error: function(xhr, status, error) {
					button.prop('disabled', false).text(s.testConnection || 'Test');
					window.alert('❌ ' + s.connError + ': ' + error);
				}
			});
		});

		// Buscar modelos que a conta desta chave realmente libera (não o
		// catálogo estático embutido no plugin). Funciona antes de salvar:
		// usa o que estiver digitado no campo de chave/URL do mesmo painel.
		$('.oraculo-fetch-models').on('click', function(e) {
			e.preventDefault();
			var button = $(this);
			var provider = button.data('provider');
			var panel = button.closest('.oraculo-provider-settings');
			var status = $('.oraculo-fetch-models-status[data-provider="' + provider + '"]');
			// Provedores por URL (sem API key): Ollama e CLIP.
			var isUrlProvider = provider === 'ollama' || provider === 'clip';
			// Compatível com OpenAI: URL e chave (opcional) juntas.
			var isGeneric = provider === 'generic_openai';

			var apiKey = isUrlProvider ? '' : (panel.find('input[type="password"]').val() || '').trim();
			var ollamaUrl = (isUrlProvider || isGeneric) ? (panel.find('input[type="url"]').val() || '').trim() : '';

			button.prop('disabled', true);
			status.removeClass('oraculo-fetch-error oraculo-fetch-ok').text(s.fetchingModels || 'Buscando...');

			$.ajax({
				url: cfg.ajaxUrl,
				type: 'POST',
				dataType: 'json',
				data: {
					action: 'oraculo_list_models',
					provider: provider,
					api_key: apiKey,
					ollama_url: ollamaUrl,
					nonce: cfg.nonce
				},
				success: function(response) {
					button.prop('disabled', false);
					if (!response || !response.success) {
						var msg = (response && response.data && response.data.message) || s.fetchModelsError || 'Erro';
						status.addClass('oraculo-fetch-error').text('❌ ' + msg);
						return;
					}

					var models = response.data.models || [];

					if (isGeneric) {
						var chatList = $('#oraculo-generic_openai-models-list').empty();
						var embeddingList = $('#oraculo-generic_openai-embedding-models-list').empty();
						models.forEach(function(m) {
							(m.embedding ? embeddingList : chatList).append($('<option>').attr('value', m.id));
						});
					} else if (isUrlProvider) {
						var datalist = $('#oraculo-' + provider + '-models-list');
						datalist.empty();
						models.forEach(function(m) {
							datalist.append($('<option>').attr('value', m.id));
						});
					} else {
						var select = panel.find('select.oraculo-model-select[data-provider="' + provider + '"]');
						var current = select.val();
						select.empty();
						models.forEach(function(m) {
							var label = m.name || m.id;
							select.append($('<option>').attr('value', m.id).text(label));
						});
						// Mantém o modelo já selecionado se a conta ainda o libera;
						// senão cai no primeiro da lista buscada.
						if (models.some(function(m) { return m.id === current; })) {
							select.val(current);
						} else if (models.length) {
							select.val(models[0].id);
						}
					}

					var countMsg = (s.modelsFound || '%d modelo(s) encontrado(s) nesta conta.').replace('%d', models.length);
					status.addClass('oraculo-fetch-ok').text('✅ ' + countMsg);
				},
				error: function(xhr, statusText, error) {
					button.prop('disabled', false);
					status.addClass('oraculo-fetch-error').text('❌ ' + (s.connError || 'Erro de conexão') + ': ' + error);
				}
			});
		});

		// Clear cache
		$('#clear-cache').on('click', function() {
			var button = $(this);
			button.prop('disabled', true).text(s.clearing || '...');

			$.ajax({
				url: cfg.ajaxUrl,
				type: 'POST',
				dataType: 'json',
				data: {
					action: 'oraculo_clear_cache',
					nonce: cfg.nonce
				},
				success: function(response) {
					button.prop('disabled', false).text(s.clearCache || 'Clear');
					if (response && response.success) {
						window.alert('✅ ' + s.cacheCleared);
					} else {
						window.alert('❌ ' + (response && response.data && response.data.message ? response.data.message : s.cacheError));
					}
				},
				error: function(xhr, status, error) {
					button.prop('disabled', false).text(s.clearCache || 'Clear');
					window.alert('❌ ' + s.connError + ': ' + error);
				}
			});
		});

		// Clear vectors
		$('#clear-vectors').on('click', function() {
			if (!window.confirm(s.confirmClearVectors)) {
				return;
			}

			var button = $(this);
			button.prop('disabled', true).text(s.clearing || '...');

			$.ajax({
				url: cfg.ajaxUrl,
				type: 'POST',
				dataType: 'json',
				data: {
					action: 'oraculo_clear_all_vectors',
					nonce: cfg.nonce
				},
				success: function(response) {
					button.prop('disabled', false).text(s.clearVectors || 'Clear');
					if (response && response.success) {
						window.alert('✅ ' + s.vectorsCleared);
					} else {
						window.alert('❌ ' + (response && response.data && response.data.message ? response.data.message : s.vectorsError));
					}
				},
				error: function(xhr, status, error) {
					button.prop('disabled', false).text(s.clearVectors || 'Clear');
					window.alert('❌ ' + s.connError + ': ' + error);
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

			$button.prop('disabled', true).val(s.saving || '...');

			$.ajax({
				url: cfg.ajaxUrl,
				type: 'POST',
				dataType: 'json',
				data: $form.serialize() + '&action=oraculo_save_settings&nonce=' + encodeURIComponent(cfg.nonce),
				success: function(response) {
					if (response && response.success) {
						$notice.removeClass('notice-error').addClass('notice-success')
							.html('<p>' + escapeHtml(s.saved) + '</p>').show();
					} else {
						var msg = (response && response.data && response.data.message) ? response.data.message : s.saveError;
						$notice.removeClass('notice-success').addClass('notice-error')
							.html('<p>' + escapeHtml(msg) + '</p>').show();
					}
				},
				error: function(xhr, status, error) {
					$notice.removeClass('notice-success').addClass('notice-error')
						.html('<p>' + escapeHtml(s.connError + ': ' + error) + '</p>').show();
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
})(jQuery);
