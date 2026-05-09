<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Cashback_Affiliate_Activation
 *
 * Opt-in активация партнёрской (реферальной) программы. До явного акцепта
 * Условий программы (consent_type='affiliate_program') партнёрский функционал
 * в Личном кабинете скрыт. После активации — обычный UI с реферальной ссылкой,
 * статистикой и таблицами.
 *
 * Согласие фиксируется через Cashback_Legal_Consent_Manager в журнале
 * `wp_cashback_consent_log` с привязкой к актуальной версии шаблона
 * legal/templates/affiliate-program.php (см. Cashback_Legal_Documents::TYPE_AFFILIATE_PROGRAM).
 *
 * @since 2.0.0
 */
class Cashback_Affiliate_Activation {

    private const AJAX_ACTION = 'cashback_affiliate_activate';
    private const NONCE_ACTION = 'affiliate_frontend_nonce';
    private const IDEMPOTENCY_SCOPE = 'affiliate_activate';
    private const IDEMPOTENCY_TTL = 600;
    private const CONSENT_SOURCE = 'affiliate_activation';

    private static bool $initialised = false;

    public static function init(): void {
        if (self::$initialised) {
            return;
        }
        self::$initialised = true;

        add_action('wp_ajax_' . self::AJAX_ACTION, array( __CLASS__, 'handle_ajax_activate' ));
    }

    /**
     * Активирован ли партнёрский функционал у пользователя.
     */
    public static function is_activated( int $user_id ): bool {
        if ($user_id <= 0) {
            return false;
        }
        if (!class_exists('Cashback_Legal_Consent_Manager')
            || !class_exists('Cashback_Legal_Documents')) {
            // Fail-open: если legal-подсистема не загружена, не блокируем UI —
            // защита от случайной деактивации legal-модуля при разработке.
            return true;
        }
        return Cashback_Legal_Consent_Manager::has_active_consent(
            $user_id,
            Cashback_Legal_Documents::TYPE_AFFILIATE_PROGRAM
        );
    }

    /**
     * Render блока активации в Личном кабинете. Возвращает HTML; печать на
     * стороне вызывающего (frontend endpoint_content).
     */
    public static function render_activation_form( int $user_id ): string {
        if ($user_id <= 0) {
            return '';
        }

        $rules_url = '';
        if (class_exists('Cashback_Legal_Pages_Installer')) {
            $rules_url = Cashback_Legal_Pages_Installer::get_url_for_type(
                Cashback_Legal_Documents::TYPE_AFFILIATE_PROGRAM
            );
        }

        $request_id = self::generate_request_id();
        $nonce      = wp_create_nonce(self::NONCE_ACTION);

        ob_start();
        ?>
        <div class="cashback-affiliate-section cashback-affiliate-activation"
            data-cashback-affiliate-activation>
            <h3><?php echo esc_html__('Активация партнёрской программы', 'cashback-plugin'); ?></h3>
            <p>
                <?php
                echo esc_html__(
                    'Партнёрская программа — это отдельная публичная оферта в дополнение к Пользовательскому соглашению. Перед началом участия ознакомьтесь с её условиями и подтвердите акцепт.',
                    'cashback-plugin'
                );
                ?>
            </p>
            <p class="cashback-affiliate-activation-summary">
                <strong><?php echo esc_html__('Кратко:', 'cashback-plugin'); ?></strong>
                <?php
                echo esc_html__(
                    'Сервис не является финансовой организацией и налоговым агентом; реферальное вознаграждение представляет собой бонусное вознаграждение, выплачиваемое от подтверждённых CPA-сетью операций приглашённых пользователей; запрещены самореферал, мультиаккаунты, ботовый трафик и недобросовестные методы продвижения; нарушение влечёт отмену начислений и блокировку партнёрского функционала.',
                    'cashback-plugin'
                );
                ?>
            </p>
            <?php if ($rules_url !== '') : ?>
                <p class="cashback-affiliate-activation-link">
                    <a href="<?php echo esc_url($rules_url); ?>" target="_blank" rel="noopener noreferrer">
                        <?php echo esc_html__('Полный текст Условий партнёрской (реферальной) программы', 'cashback-plugin'); ?>
                    </a>
                </p>
            <?php endif; ?>

            <form class="cashback-affiliate-activation-form"
                    data-cashback-affiliate-activate
                    method="post"
                    action="<?php echo esc_url(admin_url('admin-ajax.php')); ?>">
                <input type="hidden" name="action" value="<?php echo esc_attr(self::AJAX_ACTION); ?>">
                <input type="hidden" name="nonce" value="<?php echo esc_attr($nonce); ?>">
                <input type="hidden" name="request_id" value="<?php echo esc_attr($request_id); ?>">

                <label class="cashback-affiliate-activation-checkbox">
                    <input type="checkbox" name="accept" value="1" required>
                    <span>
                        <?php
                        echo esc_html__(
                            'Я ознакомлен(а) и принимаю Условия партнёрской (реферальной) программы.',
                            'cashback-plugin'
                        );
                        ?>
                    </span>
                </label>

                <button type="submit" class="button button-primary cashback-affiliate-activation-submit">
                    <?php echo esc_html__('Активировать партнёрскую программу', 'cashback-plugin'); ?>
                </button>

                <p class="cashback-affiliate-activation-note">
                    <?php
                    echo esc_html__(
                        'Согласие можно отозвать в любой момент через Личный кабинет. Отзыв не влияет на ранее начисленное реферальное бонусное вознаграждение по подтверждённым операциям.',
                        'cashback-plugin'
                    );
                    ?>
                </p>

                <div class="cashback-affiliate-activation-error" data-cashback-affiliate-error hidden></div>
            </form>
        </div>
        <script>
        (function () {
            var form = document.querySelector('[data-cashback-affiliate-activate]');
            if (!form || form.dataset.cbBound === '1') {
                return;
            }
            form.dataset.cbBound = '1';
            form.addEventListener('submit', function (event) {
                event.preventDefault();
                var errorBox = form.querySelector('[data-cashback-affiliate-error]');
                var submit = form.querySelector('button[type="submit"]');
                if (errorBox) {
                    errorBox.hidden = true;
                    errorBox.textContent = '';
                }
                if (submit) {
                    submit.disabled = true;
                }
                var fd = new FormData(form);
                fetch(form.getAttribute('action'), {
                    method: 'POST',
                    body: fd,
                    credentials: 'same-origin'
                }).then(function (r) {
                    return r.json().catch(function () { return null; });
                }).then(function (json) {
                    if (json && json.success) {
                        window.location.reload();
                        return;
                    }
                    var msg = (json && json.data && json.data.message)
                        ? json.data.message
                        : '<?php echo esc_js(__('Не удалось активировать. Попробуйте ещё раз.', 'cashback-plugin')); ?>';
                    if (errorBox) {
                        errorBox.hidden = false;
                        errorBox.textContent = msg;
                    }
                    if (submit) {
                        submit.disabled = false;
                    }
                }).catch(function () {
                    if (errorBox) {
                        errorBox.hidden = false;
                        errorBox.textContent = '<?php echo esc_js(__('Ошибка сети. Проверьте соединение и повторите.', 'cashback-plugin')); ?>';
                    }
                    if (submit) {
                        submit.disabled = false;
                    }
                });
            });
        })();
        </script>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * AJAX-обработчик активации.
     *
     * Шаги:
     *   1. Nonce + auth check.
     *   2. Idempotency claim по (scope, user_id, request_id) — повторный POST
     *      с тем же request_id не дублирует запись в журнал.
     *   3. record_consent('affiliate_program', source='affiliate_activation').
     *   4. Audit log 'affiliate_activated' (best-effort).
     *   5. JSON success с redirect_url на ту же страницу (refresh для рендера UI).
     */
    public static function handle_ajax_activate(): void {
        if (!check_ajax_referer(self::NONCE_ACTION, 'nonce', false)) {
            wp_send_json_error(array( 'message' => __('Сессия устарела, обновите страницу и попробуйте снова.', 'cashback-plugin') ), 403);
            return;
        }

        if (!is_user_logged_in()) {
            wp_send_json_error(array( 'message' => __('Необходимо войти в аккаунт.', 'cashback-plugin') ), 401);
            return;
        }

        $user_id = get_current_user_id();
        if ($user_id <= 0) {
            wp_send_json_error(array( 'message' => __('Не удалось определить пользователя.', 'cashback-plugin') ), 401);
            return;
        }

        $accept = isset($_POST['accept']) ? sanitize_text_field((string) wp_unslash($_POST['accept'])) : '';
        if ($accept !== '1') {
            wp_send_json_error(array( 'message' => __('Необходимо принять Условия программы.', 'cashback-plugin') ), 400);
            return;
        }

        $request_id_raw = isset($_POST['request_id']) ? sanitize_text_field((string) wp_unslash($_POST['request_id'])) : '';
        $request_id     = self::sanitize_request_id($request_id_raw);
        if ($request_id === '') {
            wp_send_json_error(array( 'message' => __('Некорректный идентификатор запроса.', 'cashback-plugin') ), 400);
            return;
        }

        if (class_exists('Cashback_Idempotency')
            && !Cashback_Idempotency::claim(
                self::IDEMPOTENCY_SCOPE,
                $user_id,
                $request_id,
                self::IDEMPOTENCY_TTL
            )) {
            // Повторный запрос с тем же request_id — рассматриваем как успех,
            // чтобы клиент не страдал от дрожащего соединения.
            wp_send_json_success(array(
                'message' => __('Партнёрская программа уже активирована.', 'cashback-plugin'),
                'reload'  => true,
            ));
            return;
        }

        if (!class_exists('Cashback_Legal_Consent_Manager')
            || !class_exists('Cashback_Legal_Documents')) {
            wp_send_json_error(array( 'message' => __('Сервис временно недоступен. Попробуйте позже.', 'cashback-plugin') ), 503);
            return;
        }

        $consent_id = Cashback_Legal_Consent_Manager::record_consent(
            $user_id,
            Cashback_Legal_Documents::TYPE_AFFILIATE_PROGRAM,
            self::CONSENT_SOURCE,
            $request_id
        );

        if (!$consent_id) {
            wp_send_json_error(array( 'message' => __('Не удалось зафиксировать согласие. Попробуйте ещё раз.', 'cashback-plugin') ), 500);
            return;
        }

        // Best-effort audit log: ошибка журнала не должна валить активацию.
        if (class_exists('Cashback_Encryption')
            && method_exists('Cashback_Encryption', 'write_audit_log')) {
            try {
                Cashback_Encryption::write_audit_log(
                    'affiliate_activated',
                    $user_id,
                    'affiliate_program',
                    (int) $consent_id,
                    array( 'source' => self::CONSENT_SOURCE )
                );
            } catch (\Throwable $audit_error) {
                // Audit некритичен для UX активации; не валим основную логику.
                unset($audit_error);
            }
        }

        wp_send_json_success(array(
            'message' => __('Партнёрская программа активирована.', 'cashback-plugin'),
            'reload'  => true,
        ));
    }

    /**
     * Генерация UUID v7-подобного идентификатора запроса для идемпотентности.
     * Использует cashback_generate_uuid7() при наличии, иначе wp_generate_uuid4().
     */
    private static function generate_request_id(): string {
        if (function_exists('cashback_generate_uuid7')) {
            return cashback_generate_uuid7(false);
        }
        if (function_exists('wp_generate_uuid4')) {
            return wp_generate_uuid4();
        }
        return bin2hex(random_bytes(16));
    }

    private static function sanitize_request_id( string $raw ): string {
        $raw = trim($raw);
        if ($raw === '' || strlen($raw) > 64) {
            return '';
        }
        if (preg_match('/^[a-f0-9\-]{8,64}$/i', $raw) !== 1) {
            return '';
        }
        return $raw;
    }
}
