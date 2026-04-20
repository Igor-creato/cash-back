/**
 * Cashback Contact Form — Frontend JS.
 *
 * Обработка отправки формы, валидация, SmartCaptcha, сообщения.
 *
 * @since 2.1.0
 */
(function ($) {
    'use strict';

    if (typeof cbContactForm === 'undefined') {
        return;
    }

    var config = cbContactForm;
    var captchaLoaded = false;
    var captchaWidgetId = null;
    var captchaToken = '';

    // =========================================================================
    // SmartCaptcha
    // =========================================================================

    function loadCaptchaScript(callback) {
        if (captchaLoaded) {
            callback();
            return;
        }

        var script = document.createElement('script');
        script.src = config.captchaJsUrl;
        script.async = true;

        var timeout = setTimeout(function () {
            console.warn('[CB Contact] SmartCaptcha load timeout');
            callback();
        }, 5000);

        script.onload = function () {
            clearTimeout(timeout);
            captchaLoaded = true;
            callback();
        };

        script.onerror = function () {
            clearTimeout(timeout);
            console.warn('[CB Contact] SmartCaptcha load error');
            callback();
        };

        document.head.appendChild(script);
    }

    function renderCaptcha() {
        var container = document.getElementById('cb-contact-captcha-widget');
        if (!container || !config.captchaClientKey) return;

        if (typeof window.smartCaptcha !== 'undefined') {
            captchaWidgetId = window.smartCaptcha.render('cb-contact-captcha-widget', {
                sitekey: config.captchaClientKey,
                callback: function (token) {
                    captchaToken = token;
                    $('#cb-contact-captcha-token').val(token);
                },
                'expired-callback': function () {
                    captchaToken = '';
                    $('#cb-contact-captcha-token').val('');
                },
            });
        }
    }

    function resetCaptcha() {
        captchaToken = '';
        $('#cb-contact-captcha-token').val('');
        if (typeof window.smartCaptcha !== 'undefined' && captchaWidgetId !== null) {
            window.smartCaptcha.reset(captchaWidgetId);
        }
    }

    // =========================================================================
    // Messages
    // =========================================================================

    function showMessage(text, type) {
        var $msg = $('#cb-contact-messages');
        $msg.removeClass('cb-msg-success cb-msg-error')
            .addClass(type === 'success' ? 'cb-msg-success' : 'cb-msg-error')
            .html(text.replace(/\n/g, '<br>'))
            .show();

        // Scroll to message
        $('html, body').animate({
            scrollTop: $msg.offset().top - 100
        }, 300);
    }

    function hideMessage() {
        $('#cb-contact-messages').hide().empty();
    }

    // =========================================================================
    // Validation
    // =========================================================================

    function validateForm() {
        var valid = true;
        var fields = ['#cb-contact-name', '#cb-contact-email', '#cb-contact-subject', '#cb-contact-message'];

        fields.forEach(function (sel) {
            var $f = $(sel);
            $f.removeClass('cb-field-error');

            if (!$f.val() || !$f.val().trim()) {
                $f.addClass('cb-field-error');
                valid = false;
            }
        });

        // Email format
        var email = $('#cb-contact-email').val() || '';
        if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
            $('#cb-contact-email').addClass('cb-field-error');
            valid = false;
        }

        // Message min length
        var msg = ($('#cb-contact-message').val() || '').trim();
        if (msg.length > 0 && msg.length < 10) {
            $('#cb-contact-message').addClass('cb-field-error');
            valid = false;
        }

        // CAPTCHA
        if (config.captchaRequired && !captchaToken) {
            showMessage('Пожалуйста, пройдите проверку (капча).', 'error');
            return false;
        }

        if (!valid) {
            showMessage('Пожалуйста, заполните все обязательные поля корректно.', 'error');
        }

        return valid;
    }

    // =========================================================================
    // Submit
    // =========================================================================

    $(document).on('submit', '#cb-contact-form', function (e) {
        e.preventDefault();

        hideMessage();

        if (!validateForm()) return;

        var $btn = $('#cb-contact-submit');
        var originalText = $btn.text();

        $btn.prop('disabled', true)
            .html('<span class="cb-contact-spinner"></span>Отправка...');

        // iter-30 F-30-003: идемпотентность через request_id (UUID v4).
        // Один submit-клик → один id. При сетевом ретрае клиент пошлёт тот же id,
        // и сервер вернёт прежний успешный ответ вместо повторной отправки email.
        var $form = $('#cb-contact-form');
        var requestId = $form.data('cbRequestId');
        if (!requestId) {
            requestId = (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function')
                ? crypto.randomUUID()
                : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
                    var r = (Math.random() * 16) | 0;
                    return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16);
                });
            $form.data('cbRequestId', requestId);
        }

        var data = {
            action: 'cashback_contact_submit',
            nonce: config.nonce,
            request_id: requestId,
            contact_name: $('#cb-contact-name').val(),
            contact_email: $('#cb-contact-email').val(),
            contact_subject: $('#cb-contact-subject').val(),
            contact_message: $('#cb-contact-message').val(),
            cb_captcha_token: captchaToken
        };

        // Honeypot + timing (если bot-protection.js добавил поля)
        var $hp = $form.find('input[name="cb_website_url"]');
        if ($hp.length) {
            data.cb_website_url = $hp.val();
        }
        var $ts = $form.find('input[name="cb_form_ts"]');
        if ($ts.length) {
            data.cb_form_ts = $ts.val();
        }

        $.post(config.ajaxUrl, data)
            .done(function (response) {
                if (response.success) {
                    showMessage(response.data.message, 'success');
                    // Очистить форму
                    $form[0].reset();
                    // iter-30 F-30-003: сбрасываем request_id, чтобы следующая отправка получила свежий UUID.
                    $form.removeData('cbRequestId');
                    resetCaptcha();
                } else {
                    var msg = (response.data && response.data.message) || 'Произошла ошибка.';
                    showMessage(msg, 'error');
                    if (response.data && response.data.code === 'captcha_failed') {
                        resetCaptcha();
                    }
                }
            })
            .fail(function (xhr) {
                if (xhr.status === 429) {
                    showMessage('Слишком много сообщений. Попробуйте позже.', 'error');
                } else {
                    showMessage('Ошибка сети. Попробуйте ещё раз.', 'error');
                }
            })
            .always(function () {
                $btn.prop('disabled', false).text(originalText);
            });
    });

    // Clear error on input
    $(document).on('input change', '#cb-contact-form input, #cb-contact-form textarea', function () {
        $(this).removeClass('cb-field-error');
    });

    // =========================================================================
    // Init
    // =========================================================================

    $(function () {
        if (config.captchaRequired && config.captchaClientKey) {
            loadCaptchaScript(function () {
                renderCaptcha();
            });
        }
    });

})(jQuery);
