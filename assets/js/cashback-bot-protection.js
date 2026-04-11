/**
 * Cashback Bot Protection — Frontend.
 *
 * 1. Honeypot: инжектирует скрытое поле cb_website_url во все формы плагина
 * 2. Timing: записывает timestamp загрузки формы (cb_form_ts)
 * 3. SmartCaptcha: lazy-load Яндекс SmartCaptcha при ответе captcha_required
 * 4. AJAX interceptor: автоматически добавляет cb_captcha_token к запросам
 *
 * @since 2.1.0
 */
(function ($) {
    'use strict';

    if (typeof cbBotProtection === 'undefined') {
        return;
    }

    var config = cbBotProtection;
    var captchaToken = sessionStorage.getItem('cb_captcha_token') || '';
    var captchaTokenTime = parseInt(sessionStorage.getItem('cb_captcha_token_time') || '0', 10);
    var captchaScriptLoaded = false;
    var captchaWidgetId = null;
    var pendingRetry = null;

    // Время жизни токена — 10 минут
    var TOKEN_TTL_MS = 10 * 60 * 1000;

    /**
     * Проверить, не истёк ли кешированный токен.
     */
    function isTokenValid() {
        if (!captchaToken) return false;
        return (Date.now() - captchaTokenTime) < TOKEN_TTL_MS;
    }

    /**
     * Сохранить токен.
     */
    function saveToken(token) {
        captchaToken = token;
        captchaTokenTime = Date.now();
        sessionStorage.setItem('cb_captcha_token', token);
        sessionStorage.setItem('cb_captcha_token_time', String(captchaTokenTime));
    }

    /**
     * Очистить токен.
     */
    function clearToken() {
        captchaToken = '';
        captchaTokenTime = 0;
        sessionStorage.removeItem('cb_captcha_token');
        sessionStorage.removeItem('cb_captcha_token_time');
    }

    // =========================================================================
    // Honeypot + Timing injection
    // =========================================================================

    /**
     * Список селекторов форм плагина.
     */
    var formSelectors = [
        '#withdrawal-form',
        '#support-create-form',
        '#claim-form',
        'form[data-cb-protected]'
    ];

    /**
     * Инжектировать honeypot и timing во все формы плагина.
     */
    function injectProtectionFields() {
        var selector = formSelectors.join(',');
        var $forms = $(selector);

        $forms.each(function () {
            var $form = $(this);

            // Не инжектировать повторно
            if ($form.data('cb-protected')) return;
            $form.data('cb-protected', true);

            // Honeypot — привлекательное для ботов имя, скрыто CSS-ом
            $form.append(
                '<div class="cb-hp-wrap" aria-hidden="true">' +
                '<label for="cb_website_url_' + $form.attr('id') + '">Website</label>' +
                '<input type="text" name="cb_website_url" id="cb_website_url_' + $form.attr('id') + '" ' +
                'value="" tabindex="-1" autocomplete="off">' +
                '</div>'
            );

            // Timing — timestamp загрузки формы
            $form.append(
                '<input type="hidden" name="cb_form_ts" value="' + Date.now() + '">'
            );
        });
    }

    // =========================================================================
    // SmartCaptcha
    // =========================================================================

    /**
     * Загрузить скрипт SmartCaptcha (lazy).
     */
    function loadCaptchaScript(callback) {
        if (captchaScriptLoaded) {
            callback();
            return;
        }

        var script = document.createElement('script');
        script.src = config.captchaJsUrl;
        script.async = true;

        var timeout = setTimeout(function () {
            // Таймаут 5с — graceful degradation
            console.warn('[CB Bot Protection] SmartCaptcha script load timeout');
            if (pendingRetry) {
                retryWithoutCaptcha();
            }
        }, 5000);

        script.onload = function () {
            clearTimeout(timeout);
            captchaScriptLoaded = true;
            callback();
        };

        script.onerror = function () {
            clearTimeout(timeout);
            console.warn('[CB Bot Protection] SmartCaptcha script load error');
            if (pendingRetry) {
                retryWithoutCaptcha();
            }
        };

        document.head.appendChild(script);
    }

    /**
     * Показать виджет SmartCaptcha.
     */
    function showCaptchaWidget(clientKey) {
        // Ищем контейнер CAPTCHA ближе к активной форме, или общий
        var $container = $('.cb-captcha-container:visible').first();
        if (!$container.length) {
            $container = $('.cb-captcha-container').first();
        }

        if (!$container.length) {
            // Создаём контейнер динамически
            $container = $('<div class="cb-captcha-container cb-captcha-overlay"></div>');
            $('body').append($container);
        }

        $container.show().empty();

        // Добавляем обёртку
        var $wrapper = $(
            '<div class="cb-captcha-widget">' +
            '<p class="cb-captcha-message">Пожалуйста, подтвердите, что вы не робот:</p>' +
            '<div id="cb-smartcaptcha-widget"></div>' +
            '<button type="button" class="cb-captcha-close">&times;</button>' +
            '</div>'
        );
        $container.append($wrapper);

        // Кнопка закрытия
        $wrapper.find('.cb-captcha-close').on('click', function () {
            $container.hide().empty();
            pendingRetry = null;
        });

        // Рендер SmartCaptcha
        if (typeof window.smartCaptcha !== 'undefined') {
            captchaWidgetId = window.smartCaptcha.render('cb-smartcaptcha-widget', {
                sitekey: clientKey,
                callback: onCaptchaSuccess,
            });
        }
    }

    /**
     * Callback при успешном прохождении CAPTCHA.
     */
    function onCaptchaSuccess(token) {
        saveToken(token);

        // Скрыть виджет
        $('.cb-captcha-container').hide().empty();

        // Retry отложенный запрос
        if (pendingRetry) {
            var retry = pendingRetry;
            pendingRetry = null;
            retry.data.cb_captcha_token = token;
            $.ajax(retry);
        }
    }

    /**
     * Retry без CAPTCHA (graceful degradation при недоступности SmartCaptcha).
     */
    function retryWithoutCaptcha() {
        if (!pendingRetry) return;
        var retry = pendingRetry;
        pendingRetry = null;
        $.ajax(retry);
    }

    // =========================================================================
    // AJAX Interceptor
    // =========================================================================

    /**
     * Перехватчик jQuery AJAX для добавления защитных полей.
     */
    $.ajaxPrefilter(function (options, originalOptions) {
        // Только запросы к admin-ajax.php
        if (!options.url || options.url.indexOf('admin-ajax.php') === -1) {
            return;
        }

        var data = options.data || '';

        // Проверяем, что это действие нашего плагина (по наличию action в data)
        // Добавляем captcha token если есть
        if (isTokenValid() && typeof data === 'string' && data.indexOf('cb_captcha_token') === -1) {
            options.data = data + (data ? '&' : '') + 'cb_captcha_token=' + encodeURIComponent(captchaToken);
        } else if (isTokenValid() && typeof data === 'object' && data !== null && !data.cb_captcha_token) {
            data.cb_captcha_token = captchaToken;
        }
    });

    /**
     * Глобальный обработчик ответов — ловит captcha_required.
     */
    $(document).ajaxComplete(function (event, xhr, settings) {
        if (!settings.url || settings.url.indexOf('admin-ajax.php') === -1) {
            return;
        }

        var response;
        try {
            response = JSON.parse(xhr.responseText);
        } catch (e) {
            return;
        }

        if (!response || response.success !== false || !response.data) {
            return;
        }

        if (response.data.code === 'captcha_required' && response.data.client_key) {
            // Сохраняем параметры запроса для retry
            pendingRetry = $.extend(true, {}, settings);
            // Удаляем обработчики оригинального запроса, чтобы retry не дублировал
            delete pendingRetry.success;
            delete pendingRetry.error;
            delete pendingRetry.complete;

            // Восстанавливаем оригинальные callbacks для retry
            if (settings._cbOrigSuccess) {
                pendingRetry.success = settings._cbOrigSuccess;
            }
            if (settings._cbOrigError) {
                pendingRetry.error = settings._cbOrigError;
            }

            clearToken();

            loadCaptchaScript(function () {
                showCaptchaWidget(response.data.client_key);
            });
        }
    });

    // =========================================================================
    // Init
    // =========================================================================

    $(function () {
        injectProtectionFields();

        // MutationObserver для динамически добавленных форм
        if (typeof MutationObserver !== 'undefined') {
            var observer = new MutationObserver(function () {
                injectProtectionFields();
            });
            observer.observe(document.body, { childList: true, subtree: true });
        }
    });

})(jQuery);
