/**
 * Oráculo Tainacan - JavaScript do Painel Administrativo
 */

(function($) {
    'use strict';

    window.OraculoAdmin = {
        ajaxUrl: ajaxurl,
        nonce: OraculoAdmin?.nonce || '',

        init: function() {
            this.bindEvents();
            this.initTabs();
            this.initTooltips();
            this.initProviderCards();
            this.checkPendingIndexing();
        },

        bindEvents: function() {
            var self = this;

            // Test provider connection
            $(document).on('click', '.oraculo-test-provider', function(e) {
                e.preventDefault();
                var btn = $(this);
                var provider = btn.data('provider');
                self.testProvider(provider, btn);
            });

            // Start indexing
            $(document).on('click', '.oraculo-btn-index', function(e) {
                e.preventDefault();
                var btn = $(this);
                var collectionId = btn.data('collection');
                self.startIndexing(collectionId, btn);
            });

            // Clear vectors
            $(document).on('click', '.oraculo-btn-clear', function(e) {
                e.preventDefault();
                var btn = $(this);
                var collectionId = btn.data('collection');
                self.clearVectors(collectionId, btn);
            });

            // Save settings
            $(document).on('submit', '#oraculo-settings-form', function(e) {
                e.preventDefault();
                self.saveSettings($(this));
            });

            // Clear cache
            $(document).on('click', '#oraculo-clear-cache', function(e) {
                e.preventDefault();
                self.clearCache($(this));
            });

            // Optimize DB
            $(document).on('click', '#oraculo-optimize-db', function(e) {
                e.preventDefault();
                self.optimizeDb($(this));
            });

            // Toggle password visibility
            $(document).on('click', '.oraculo-toggle-password', function() {
                var input = $(this).siblings('input');
                var type = input.attr('type') === 'password' ? 'text' : 'password';
                input.attr('type', type);
                $(this).text(type === 'password' ? '👁' : '🔒');
            });
        },

        initTabs: function() {
            var self = this;

            $('.oraculo-tabs .oraculo-tab').on('click', function() {
                var tab = $(this);
                var target = tab.data('tab');

                // Update active tab
                tab.siblings().removeClass('active');
                tab.addClass('active');

                // Update active content
                var container = tab.closest('.oraculo-tabs-container');
                container.find('.oraculo-tab-content').removeClass('active');
                container.find('.oraculo-tab-content[data-tab="' + target + '"]').addClass('active');

                // Store preference
                if (tab.data('persist')) {
                    localStorage.setItem('oraculo_active_tab', target);
                }
            });

            // Restore active tab
            var savedTab = localStorage.getItem('oraculo_active_tab');
            if (savedTab) {
                $('.oraculo-tab[data-tab="' + savedTab + '"]').click();
            }
        },

        initTooltips: function() {
            // Simple tooltip implementation
            $('[data-tooltip]').each(function() {
                $(this).addClass('oraculo-tooltip');
            });
        },

        initProviderCards: function() {
            var applySelection = function(provider, card) {
                $('.oraculo-provider-card').removeClass('selected');
                card.addClass('selected');
                $('.oraculo-provider-settings').hide();
                var panel = $('#provider-settings-' + provider);
                if (panel.length) {
                    panel.show();
                }
            };

            $(document).on('change', '.oraculo-provider-card input[type="radio"]', function() {
                var card = $(this).closest('.oraculo-provider-card');
                applySelection($(this).val(), card);
            });

            $(document).on('click', '.oraculo-providers-grid .oraculo-provider-card', function() {
                var card = $(this);
                var radio = card.find('input[type="radio"]');

                if (!radio.prop('checked')) {
                    radio.prop('checked', true).trigger('change');
                    return;
                }

                applySelection(radio.val(), card);
            });
        },

        testProvider: function(provider, btn) {
            var originalText = btn.text();
            btn.prop('disabled', true).text('Testando...');

            $.ajax({
                url: this.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'oraculo_test_provider',
                    nonce: this.nonce,
                    provider: provider
                },
                success: function(response) {
                    btn.prop('disabled', false).text(originalText);

                    var result = btn.siblings('.test-result');
                    if (!result.length) {
                        result = $('<div class="test-result"></div>');
                        btn.after(result);
                    }

                    if (response.success) {
                        result.html('<span class="oraculo-status oraculo-status-success">✓ ' + response.data.message + '</span>');
                    } else {
                        result.html('<span class="oraculo-status oraculo-status-error">✗ ' + response.data.message + '</span>');
                    }
                },
                error: function() {
                    btn.prop('disabled', false).text(originalText);
                    OraculoAdmin.showNotice('error', 'Erro de conexão');
                }
            });
        },

        startIndexing: function(collectionId, btn) {
            var self = this;
            var originalText = btn.text();
            var row = btn.closest('tr');

            btn.prop('disabled', true).text('Iniciando...');

            $.ajax({
                url: this.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'oraculo_start_indexing',
                    nonce: this.nonce,
                    collection_id: collectionId
                },
                success: function(response) {
                    if (response.success) {
                        self.addLog('Indexação iniciada para coleção #' + collectionId, 'info');
                        self.pollIndexingStatus(collectionId, row, btn);
                    } else {
                        btn.prop('disabled', false).text(originalText);
                        self.showNotice('error', response.data.message);
                    }
                },
                error: function() {
                    btn.prop('disabled', false).text(originalText);
                    self.showNotice('error', 'Erro de conexão');
                }
            });
        },

        pollIndexingStatus: function(collectionId, row, btn) {
            var self = this;

            $.ajax({
                url: this.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'oraculo_get_indexing_status',
                    nonce: this.nonce,
                    collection_id: collectionId
                },
                success: function(response) {
                    if (response.success) {
                        var data = response.data;

                        // Update progress
                        row.find('.indexed-count').text(data.indexed_items.toLocaleString());
                        row.find('.oraculo-progress-fill').css('width', data.percentage + '%');
                        row.find('.oraculo-progress-text').text(data.percentage + '%');

                        // Update button text
                        btn.text('Indexando... ' + data.percentage + '%');

                        if (data.status === 'processing') {
                            setTimeout(function() {
                                self.pollIndexingStatus(collectionId, row, btn);
                            }, 3000);
                        } else if (data.status === 'completed') {
                            btn.prop('disabled', false).text('Reindexar');
                            row.find('.oraculo-status').removeClass().addClass('oraculo-status oraculo-status-completed').text('Concluído');
                            self.addLog('Indexação concluída: ' + data.indexed_items + ' itens', 'success');
                            self.showNotice('success', 'Indexação concluída com sucesso!');
                        } else if (data.status === 'error') {
                            btn.prop('disabled', false).text('Reindexar');
                            row.find('.oraculo-status').removeClass().addClass('oraculo-status oraculo-status-error').text('Erro');
                            self.addLog('Erro na indexação: ' + (data.error || 'Desconhecido'), 'error');
                            self.showNotice('error', data.error || 'Erro na indexação');
                        }
                    }
                }
            });
        },

        clearVectors: function(collectionId, btn) {
            if (!confirm('Tem certeza que deseja limpar os vetores desta coleção?')) {
                return;
            }

            var self = this;
            var row = btn.closest('tr');

            $.ajax({
                url: this.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'oraculo_clear_vectors',
                    nonce: this.nonce,
                    collection_id: collectionId
                },
                success: function(response) {
                    if (response.success) {
                        row.find('.indexed-count').text('0');
                        row.find('.oraculo-progress-fill').css('width', '0%');
                        row.find('.oraculo-progress-text').text('0%');
                        row.find('.oraculo-status').removeClass().addClass('oraculo-status oraculo-status-idle').text('Não indexado');
                        btn.remove();
                        self.addLog('Vetores limpos da coleção #' + collectionId, 'success');
                        self.showNotice('success', 'Vetores removidos com sucesso!');
                    } else {
                        self.showNotice('error', response.data.message);
                    }
                }
            });
        },

        saveSettings: function(form) {
            var self = this;
            var btn = form.find('button[type="submit"]');
            var originalText = btn.text();

            btn.prop('disabled', true).text('Salvando...');

            $.ajax({
                url: this.ajaxUrl,
                type: 'POST',
                data: form.serialize() + '&action=oraculo_save_settings&nonce=' + this.nonce,
                success: function(response) {
                    btn.prop('disabled', false).text(originalText);

                    if (response.success) {
                        self.showNotice('success', 'Configurações salvas com sucesso!');
                    } else {
                        self.showNotice('error', response.data.message);
                    }
                },
                error: function() {
                    btn.prop('disabled', false).text(originalText);
                    self.showNotice('error', 'Erro ao salvar configurações');
                }
            });
        },

        clearCache: function(btn) {
            var self = this;
            var originalText = btn.text();
            btn.prop('disabled', true).text('Limpando...');

            $.ajax({
                url: this.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'oraculo_clear_cache',
                    nonce: this.nonce
                },
                success: function(response) {
                    btn.prop('disabled', false).text(originalText);
                    if (response.success) {
                        self.showNotice('success', 'Cache limpo com sucesso!');
                    }
                }
            });
        },

        optimizeDb: function(btn) {
            var self = this;
            var originalText = btn.text();
            btn.prop('disabled', true).text('Otimizando...');

            $.ajax({
                url: this.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'oraculo_optimize_db',
                    nonce: this.nonce
                },
                success: function(response) {
                    btn.prop('disabled', false).text(originalText);
                    if (response.success) {
                        self.showNotice('success', 'Banco de dados otimizado!');
                    }
                }
            });
        },

        checkPendingIndexing: function() {
            var self = this;

            // Check for any in-progress indexing
            $('.oraculo-status-processing').each(function() {
                var row = $(this).closest('tr');
                var collectionId = row.data('collection-id');
                var btn = row.find('.oraculo-btn-index');

                if (collectionId && btn.length) {
                    btn.prop('disabled', true).text('Indexando...');
                    self.pollIndexingStatus(collectionId, row, btn);
                }
            });
        },

        addLog: function(message, type) {
            var log = $('#oraculo-indexing-log, .oraculo-log');
            if (!log.length) return;

            log.find('.oraculo-log-empty').remove();

            var time = new Date().toLocaleTimeString();
            var entry = $('<div class="oraculo-log-entry ' + (type || '') + '">')
                .html('<span class="oraculo-log-time">[' + time + ']</span> ' + message);

            log.append(entry);
            log.scrollTop(log[0].scrollHeight);
        },

        showNotice: function(type, message) {
            var icon = {
                'success': '✓',
                'error': '✗',
                'warning': '⚠',
                'info': 'ℹ'
            }[type] || 'ℹ';

            var notice = $('<div class="oraculo-alert oraculo-alert-' + type + ' oraculo-fade-in">')
                .html('<span class="oraculo-alert-icon">' + icon + '</span><div class="oraculo-alert-content">' + message + '</div>');

            // Remove existing notices
            $('.oraculo-admin .oraculo-alert').remove();

            // Add new notice after header
            var header = $('.oraculo-admin-header');
            if (header.length) {
                header.after(notice);
            } else {
                $('.oraculo-admin h1').first().after(notice);
            }

            // Auto-remove after 5 seconds
            setTimeout(function() {
                notice.fadeOut(function() {
                    notice.remove();
                });
            }, 5000);
        },

        showModal: function(title, content, buttons) {
            var modal = $('<div class="oraculo-modal-overlay">')
                .html('<div class="oraculo-modal oraculo-slide-up">' +
                    '<div class="oraculo-modal-header">' +
                        '<h3>' + title + '</h3>' +
                        '<button class="oraculo-modal-close">&times;</button>' +
                    '</div>' +
                    '<div class="oraculo-modal-body">' + content + '</div>' +
                    '<div class="oraculo-modal-footer"></div>' +
                '</div>');

            if (buttons) {
                var footer = modal.find('.oraculo-modal-footer');
                buttons.forEach(function(btn) {
                    var button = $('<button class="button ' + (btn.primary ? 'button-primary' : '') + '">')
                        .text(btn.label)
                        .on('click', function() {
                            if (btn.callback) btn.callback();
                            if (btn.close !== false) OraculoAdmin.closeModal();
                        });
                    footer.append(button);
                });
            }

            modal.find('.oraculo-modal-close').on('click', this.closeModal);
            modal.on('click', function(e) {
                if ($(e.target).hasClass('oraculo-modal-overlay')) {
                    OraculoAdmin.closeModal();
                }
            });

            $('body').append(modal);
            return modal;
        },

        closeModal: function() {
            $('.oraculo-modal-overlay').fadeOut(function() {
                $(this).remove();
            });
        },

        formatNumber: function(num) {
            return num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        },

        confirm: function(message, callback) {
            this.showModal('Confirmação', '<p>' + message + '</p>', [
                { label: 'Cancelar', close: true },
                { label: 'Confirmar', primary: true, callback: callback }
            ]);
        }
    };

    // Initialize on DOM ready
    $(document).ready(function() {
        if ($('.oraculo-admin').length) {
            OraculoAdmin.init();
        }
    });

})(jQuery);
