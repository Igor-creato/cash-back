<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Гвоздь против регрессии: каждый REST-маршрут social-auth, который рендерит
 * HTML через WP_REST_Response, ДОЛЖЕН быть перечислен в HTML_ROUTES — иначе
 * фильтр `rest_pre_serve_request` не перехватит ответ и WP REST отдаст
 * JSON-кодированный HTML с Content-Type: text/html, после чего браузер
 * пытается отрисовать `П...` и литеральные `\n` как HTML и ломается.
 *
 * Поймано 2026-05-12 после VK-входа с уже занятым email: POST /email-prompt
 * был зарегистрирован, но не добавлен в HTML_ROUTES. См.
 * [[project_social_email_prompt_html_routes]] и обсуждение в session.
 */
#[Group('social-auth')]
#[Group('rest')]
class SocialAuthHtmlRoutesWhitelistTest extends TestCase
{
    private string $plugin_root;
    private string $router_path;

    protected function setUp(): void
    {
        $this->plugin_root = dirname(__DIR__, 3);
        $this->router_path = $this->plugin_root . '/includes/social-auth/class-social-auth-router.php';
    }

    /**
     * Маршруты, чьи handler'ы возвращают сырой HTML и должны быть в whitelist.
     * Источник истины — register_rest_route() в самом классе + handler'ы,
     * которые либо вызывают render_*_form() / echo HTML, либо возвращают
     * WP_REST_Response со строкой HTML в body.
     *
     * @return array<int, string>
     */
    private function expected_html_routes(): array
    {
        return array(
            // POST — на ошибке валидации (включая «email уже занят») рендерит
            // ту же форму через render_email_prompt_form().
            '/cashback/v1/social/email-prompt',
            // GET — render_email_prompt_form() напрямую.
            '/cashback/v1/social/email-prompt-form',
            // GET — render_register_consent_form().
            '/cashback/v1/social/register-consent-form',
            // POST — на ошибке валидации рендерит register-consent-form.
            '/cashback/v1/social/register-consent',
        );
    }

    public function test_router_file_exists(): void
    {
        $this->assertFileExists($this->router_path);
    }

    public function test_html_routes_constant_contains_all_expected_routes(): void
    {
        $source = (string) file_get_contents($this->router_path);
        $this->assertNotEmpty($source);

        $constant = $this->extract_html_routes_array($source);
        $this->assertNotNull($constant, 'HTML_ROUTES constant not found or malformed');

        foreach ($this->expected_html_routes() as $route) {
            $this->assertContains(
                $route,
                $constant,
                sprintf(
                    'Route "%s" returns HTML but is missing from HTML_ROUTES — '
                    . 'WP REST will JSON-encode the body and break the browser render.',
                    $route
                )
            );
        }
    }

    public function test_html_routes_does_not_contain_unknown_entries(): void
    {
        $source = (string) file_get_contents($this->router_path);
        $constant = $this->extract_html_routes_array($source);
        $this->assertNotNull($constant);

        $unknown = array_diff($constant, $this->expected_html_routes());
        $this->assertSame(
            array(),
            $unknown,
            'HTML_ROUTES contains entries not declared in expected_html_routes(): '
            . implode(', ', $unknown)
            . '. Either the route was added but the test was not updated, or '
            . 'the entry is stale.'
        );
    }

    public function test_email_prompt_post_handler_returns_wp_rest_response_with_html(): void
    {
        // Поведенческая страховка: если handle_email_prompt() перестанет
        // возвращать render_email_prompt_form() (например, ушёл на wp_redirect
        // во всех ветках), HTML_ROUTES для /email-prompt станет ненужным и
        // тест test_html_routes_does_not_contain_unknown_entries напомнит снять
        // его из whitelist. Это контрольная точка контракта.
        $source = (string) file_get_contents($this->router_path);
        $this->assertMatchesRegularExpression(
            '/function\s+handle_email_prompt\s*\(/',
            $source,
            'handle_email_prompt() not found in router'
        );
        $this->assertMatchesRegularExpression(
            '/render_email_prompt_form\s*\(/',
            $source,
            'router no longer calls render_email_prompt_form() — re-evaluate '
            . 'whether POST /email-prompt still needs to be in HTML_ROUTES.'
        );
    }

    /**
     * Достаёт значения массива HTML_ROUTES из исходника по brace-balance,
     * без зависимости от форматирования.
     *
     * @return array<int, string>|null
     */
    private function extract_html_routes_array( string $source ): ?array
    {
        if (!preg_match('/HTML_ROUTES\s*=\s*array\s*\(/', $source, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $open_pos = (int) $m[0][1] + strlen($m[0][0]) - 1; // позиция '('
        $depth    = 0;
        $end      = -1;
        $len      = strlen($source);

        for ($i = $open_pos; $i < $len; $i++) {
            $c = $source[ $i ];
            if ($c === '(') {
                $depth++;
            } elseif ($c === ')') {
                $depth--;
                if ($depth === 0) {
                    $end = $i;
                    break;
                }
            }
        }
        if ($end < 0) {
            return null;
        }

        $inner = substr($source, $open_pos + 1, $end - $open_pos - 1);
        // Убрать построчные комментарии.
        $inner = (string) preg_replace('~//[^\n]*~', '', $inner);

        $routes = array();
        if (preg_match_all("~'((?:[^'\\\\]|\\\\.)+)'~", $inner, $matches)) {
            foreach ($matches[1] as $value) {
                $routes[] = (string) stripcslashes($value);
            }
        }
        return $routes;
    }
}
