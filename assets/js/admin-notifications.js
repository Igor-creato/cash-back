(function ($) {
    'use strict';

    var cfg = window.cashbackAdminNotifications || {};
    var i18n = cfg.i18n || {};

    // =============================
    // Настройки (toggle + save)
    // =============================

    // Toggle label update
    $(document).on('change', '.cashback-admin-toggle input', function () {
        var $label = $(this).closest('.cashback-admin-toggle').find('.cashback-admin-toggle-label');
        $label.text(this.checked ? 'Включено' : 'Выключено');
    });

    // Save settings
    $(document).on('submit', '#cashback-admin-notification-form', function (e) {
        e.preventDefault();

        var $btn = $('#cashback-save-notification-admin-settings');
        var $status = $('#cashback-admin-notification-status');

        $btn.prop('disabled', true);
        $status.text('Сохранение...').removeClass('error');

        $.ajax({
            url: cfg.ajaxUrl,
            type: 'POST',
            data: $(this).serialize() + '&action=cashback_admin_save_notification_settings&nonce=' + cfg.nonce,
            success: function (response) {
                if (response.success) {
                    $status.text(response.data.message);
                } else {
                    $status.text(response.data.message || 'Ошибка').addClass('error');
                }
            },
            error: function () {
                $status.text('Ошибка сети').addClass('error');
            },
            complete: function () {
                $btn.prop('disabled', false);
                setTimeout(function () {
                    $status.text('');
                }, 3000);
            }
        });
    });

    // =============================
    // Вкладка «Рассылка»
    // =============================

    function getBroadcastBody() {
        // TinyMCE активен?
        if (window.tinymce) {
            var ed = window.tinymce.get('cashback-broadcast-body');
            if (ed && !ed.isHidden()) {
                return ed.getContent();
            }
        }
        return $('#cashback-broadcast-body').val() || '';
    }

    function collectFilters() {
        var filters = {
            statuses: [],
            roles: [],
            has_cashback: 0
        };
        $('#cashback-broadcast-form input[name="filters[statuses][]"]:checked').each(function () {
            filters.statuses.push($(this).val());
        });
        $('#cashback-broadcast-form input[name="filters[roles][]"]:checked').each(function () {
            filters.roles.push($(this).val());
        });
        if ($('#cashback-broadcast-form input[name="filters[has_cashback]"]').is(':checked')) {
            filters.has_cashback = 1;
        }
        return filters;
    }

    function setBroadcastStatus(text, kind) {
        var $s = $('#cashback-broadcast-status');
        $s.removeClass('error success').text(text || '');
        if (kind === 'error') $s.addClass('error');
        else if (kind === 'success') $s.addClass('success');
    }

    // Preview count
    function refreshCount() {
        var $btn = $('#cashback-broadcast-count-btn');
        var $val = $('#cashback-broadcast-count-value');
        $btn.prop('disabled', true);
        $val.text(i18n.calculating || 'Считаем...');

        $.ajax({
            url: cfg.ajaxUrl,
            type: 'POST',
            data: {
                action: 'cashback_broadcast_preview_count',
                nonce: cfg.broadcastNonce,
                filters: collectFilters()
            },
            success: function (response) {
                if (response.success) {
                    $val.text(response.data.count);
                } else {
                    $val.text('0');
                    setBroadcastStatus((response.data && response.data.message) || 'Ошибка', 'error');
                }
            },
            error: function () {
                $val.text('—');
                setBroadcastStatus(i18n.networkError || 'Ошибка сети', 'error');
            },
            complete: function () {
                $btn.prop('disabled', false);
            }
        });
    }

    $(document).on('click', '#cashback-broadcast-count-btn', refreshCount);

    $(document).on('change', '#cashback-broadcast-form input[name^="filters"]', function () {
        // Автопересчёт с лёгким дебаунсом
        clearTimeout(window._cbBroadcastCountTimer);
        window._cbBroadcastCountTimer = setTimeout(refreshCount, 400);
    });

    // Первый подсчёт при загрузке вкладки
    if ($('#cashback-broadcast-form').length) {
        refreshCount();
    }

    // Preview HTML
    $(document).on('click', '#cashback-broadcast-preview-btn', function () {
        var subject = $.trim($('#cashback-broadcast-subject').val());
        var body = $.trim(getBroadcastBody());
        if (!subject) { setBroadcastStatus(i18n.emptySubject || 'Заполните тему.', 'error'); return; }
        if (!body)    { setBroadcastStatus(i18n.emptyBody || 'Заполните тело.', 'error'); return; }
        setBroadcastStatus('');

        $.ajax({
            url: cfg.ajaxUrl,
            type: 'POST',
            data: {
                action: 'cashback_broadcast_preview_html',
                nonce: cfg.broadcastNonce,
                subject: subject,
                body: body
            },
            success: function (response) {
                if (response.success) {
                    var $modal = $('#cashback-broadcast-preview-modal').show();
                    var iframe = document.getElementById('cashback-broadcast-preview-frame');
                    if (iframe) {
                        // Sandbox без allow-scripts/allow-same-origin — скрипты в preview не исполняются.
                        iframe.setAttribute('sandbox', '');
                        iframe.setAttribute('srcdoc', response.data.html);
                    }
                } else {
                    setBroadcastStatus((response.data && response.data.message) || 'Ошибка', 'error');
                }
            },
            error: function () {
                setBroadcastStatus(i18n.networkError || 'Ошибка сети', 'error');
            }
        });
    });

    $(document).on('click', '#cashback-broadcast-preview-close, #cashback-broadcast-preview-modal', function (e) {
        if (e.target !== this) return; // закрываем только по клику на фон/кнопку
        $('#cashback-broadcast-preview-modal').hide();
        var iframe = document.getElementById('cashback-broadcast-preview-frame');
        if (iframe) iframe.setAttribute('srcdoc', '');
    });

    // Submit campaign
    $(document).on('submit', '#cashback-broadcast-form', function (e) {
        e.preventDefault();
        var subject = $.trim($('#cashback-broadcast-subject').val());
        var body = $.trim(getBroadcastBody());
        if (!subject) { setBroadcastStatus(i18n.emptySubject || 'Заполните тему.', 'error'); return; }
        if (!body)    { setBroadcastStatus(i18n.emptyBody || 'Заполните тело.', 'error'); return; }

        if (!window.confirm(i18n.confirmSend || 'Отправить?')) {
            return;
        }

        var $submit = $('#cashback-broadcast-submit-btn');
        $submit.prop('disabled', true);
        setBroadcastStatus(i18n.sending || 'Отправка...');

        $.ajax({
            url: cfg.ajaxUrl,
            type: 'POST',
            data: {
                action: 'cashback_broadcast_create',
                nonce: cfg.broadcastNonce,
                campaign_uuid: $('#cashback-broadcast-uuid').val(),
                subject: subject,
                body: body,
                filters: collectFilters()
            },
            success: function (response) {
                if (response.success) {
                    setBroadcastStatus(response.data.message || 'Готово', 'success');
                    setTimeout(function () { window.location.reload(); }, 1200);
                } else {
                    setBroadcastStatus((response.data && response.data.message) || 'Ошибка', 'error');
                    $submit.prop('disabled', false);
                }
            },
            error: function () {
                setBroadcastStatus(i18n.networkError || 'Ошибка сети', 'error');
                $submit.prop('disabled', false);
            }
        });
    });

    // Cancel campaign
    $(document).on('click', '.cashback-broadcast-cancel', function () {
        var $btn = $(this);
        var campaignId = parseInt($btn.data('campaign-id'), 10);
        if (!campaignId) return;
        if (!window.confirm(i18n.confirmCancel || 'Отменить рассылку?')) {
            return;
        }
        $btn.prop('disabled', true);

        $.ajax({
            url: cfg.ajaxUrl,
            type: 'POST',
            data: {
                action: 'cashback_broadcast_cancel',
                nonce: cfg.broadcastNonce,
                campaign_id: campaignId
            },
            success: function (response) {
                if (response.success) {
                    window.location.reload();
                } else {
                    window.alert((response.data && response.data.message) || 'Ошибка');
                    $btn.prop('disabled', false);
                }
            },
            error: function () {
                window.alert(i18n.networkError || 'Ошибка сети');
                $btn.prop('disabled', false);
            }
        });
    });

    // Polling status for active campaigns in history
    function pollActive() {
        var $rows = $('.cashback-broadcast-history tr[data-campaign-id]').filter(function () {
            var badge = $(this).find('.cashback-broadcast-badge');
            return badge.hasClass('cashback-broadcast-badge-queued') || badge.hasClass('cashback-broadcast-badge-sending');
        });
        if (!$rows.length) return;

        $rows.each(function () {
            var $row = $(this);
            var campaignId = parseInt($row.data('campaign-id'), 10);
            if (!campaignId) return;

            $.ajax({
                url: cfg.ajaxUrl,
                type: 'GET',
                data: {
                    action: 'cashback_broadcast_status',
                    nonce: cfg.broadcastNonce,
                    campaign_id: campaignId
                },
                success: function (response) {
                    if (!response.success) return;
                    var d = response.data;
                    $row.find('.cashback-broadcast-sent').text(d.sent_count);
                    $row.find('.cashback-broadcast-failed').text(d.failed_count);
                    var badgeClass = 'cashback-broadcast-badge-' + d.status;
                    var $badge = $row.find('.cashback-broadcast-badge');
                    $badge.attr('class', 'cashback-broadcast-badge ' + badgeClass);
                    if (d.status === 'done' || d.status === 'cancelled') {
                        // финализировать UI — уберём кнопку отмены и обновим текст статуса
                        $row.find('.cashback-broadcast-cancel').remove();
                    }
                }
            });
        });
    }

    if ($('.cashback-broadcast-history').length) {
        setInterval(pollActive, 5000);
    }

})(jQuery);
