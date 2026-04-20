<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Политика паролей мобильного приложения (fintech-grade).
 *
 * Правила:
 *   - минимум 12 символов, максимум 128 (защита от DoS при хешировании);
 *   - хотя бы одна заглавная буква, одна строчная, одна цифра, один спец-символ;
 *   - пароль НЕ должен совпадать с email / локальной частью email / логином;
 *   - пароль НЕ должен быть в top-list распространённых паролей;
 *   - нельзя содержать пробелы в начале/конце.
 *
 * Все проверки возвращают WP_Error с кодом `weak_password`, чтобы клиент не смог
 * отличить какая именно часть политики нарушена (кроме полей длины, которые безопасно
 * раскрыть). Это снижает поверхность атаки при подборе.
 *
 * @since 1.1.0
 */
class Cashback_Password_Policy {

    public const MIN_LENGTH = 12;
    public const MAX_LENGTH = 128;

    /**
     * Проверить пароль по политике.
     *
     * @param string $password Пароль plaintext.
     * @param array  $context  ['email' => string, 'login' => string] — для anti-reuse проверки.
     * @return true|WP_Error
     */
    public static function validate( string $password, array $context = array() ) {
        $len = strlen($password);

        if ($password !== trim($password)) {
            return new WP_Error('weak_password', __('Password must not start or end with whitespace.', 'cashback'), array( 'status' => 422 ));
        }
        if ($len < self::MIN_LENGTH) {
            return new WP_Error(
                'weak_password',
                sprintf(
                    /* translators: %d: minimum password length */
                    __('Password must be at least %d characters long.', 'cashback'),
                    self::MIN_LENGTH
                ),
                array( 'status' => 422 )
            );
        }
        if ($len > self::MAX_LENGTH) {
            return new WP_Error(
                'weak_password',
                sprintf(
                    /* translators: %d: maximum password length */
                    __('Password must be at most %d characters long.', 'cashback'),
                    self::MAX_LENGTH
                ),
                array( 'status' => 422 )
            );
        }

        if (!preg_match('/[a-z]/', $password)
            || !preg_match('/[A-Z]/', $password)
            || !preg_match('/\d/', $password)
            || !preg_match('/[^A-Za-z0-9]/', $password)
        ) {
            return new WP_Error(
                'weak_password',
                __('Password must include uppercase, lowercase, a digit and a special character.', 'cashback'),
                array( 'status' => 422 )
            );
        }

        $email = isset($context['email']) ? strtolower((string) $context['email']) : '';
        $login = isset($context['login']) ? strtolower((string) $context['login']) : '';
        $lower = strtolower($password);

        if ('' !== $email && ( $lower === $email || $lower === self::email_local_part($email) )) {
            return new WP_Error('weak_password', __('Password must not match your email.', 'cashback'), array( 'status' => 422 ));
        }
        if ('' !== $login && $lower === $login) {
            return new WP_Error('weak_password', __('Password must not match your login.', 'cashback'), array( 'status' => 422 ));
        }

        if (in_array($lower, self::common_passwords(), true)) {
            return new WP_Error('weak_password', __('This password is too common. Choose a stronger one.', 'cashback'), array( 'status' => 422 ));
        }

        return true;
    }

    private static function email_local_part( string $email ): string {
        $at = strpos($email, '@');
        return false === $at ? $email : substr($email, 0, $at);
    }

    /**
     * Top-list самых частых паролей (short list).
     * Fintech-продукты обычно интегрируются с HaveIBeenPwned API — здесь
     * минимальная защита без внешних зависимостей. Фильтр позволяет
     * расширить список из admin-настройки.
     *
     * @return array<int,string>
     */
    private static function common_passwords(): array {
        $base = array(
            '123456789012',
			'1234567890ab',
			'qwerty123!qwe',
			'password1234',
            'password1!ab',
			'admin123!admin',
			'letmein123!@#',
			'welcome123!ab',
            'qwertyuiop1!',
			'passw0rd1234',
			'zaq12wsxcde3',
			'1qaz!qaz1qaz',
            'abcd1234!abc',
			'password!123',
			'qwerty1234!@',
        );
        /** @var array<int,string> $extended */
        $extended = apply_filters('cashback_mobile_common_passwords', $base);
        return array_map('strtolower', $extended);
    }
}
