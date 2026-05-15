<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * P5 — push/pull dedup-config validation panel.
 *
 * Structural (source+regex; harness has no live DB / WP admin) — mirrors the
 * ApiClientSyncAtomicityTest methodology. Guards that the diagnostic that
 * SURFACES dedup misconfig (instead of silently duplicating) stays wired:
 * AJAX registration, nonce+cap, the report well-formedness logic, and the
 * server-side render hook in the validation tab.
 */
#[Group('split-order')]
final class DedupConfigValidationAjaxTest extends TestCase
{
    private const FILE = __DIR__ . '/../../../admin/class-cashback-admin-api-validation.php';

    private function src(): string
    {
        $s = file_get_contents(self::FILE);
        $this->assertIsString($s);
        return $s;
    }

    private function method_body(string $name): string
    {
        $src = $this->src();
        $pos = strpos($src, 'function ' . $name . '(');
        $this->assertIsInt($pos, $name . '() must exist');
        $brace = strpos($src, '{', $pos);
        $this->assertIsInt($brace);
        $depth = 0;
        $len   = strlen($src);
        for ($i = $brace; $i < $len; $i++) {
            if ($src[$i] === '{') {
                $depth++;
            } elseif ($src[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($src, $brace, $i - $brace + 1);
                }
            }
        }
        $this->fail('no closing brace for ' . $name);
    }

    public function test_ajax_action_is_registered(): void
    {
        $this->assertMatchesRegularExpression(
            "/add_action\(\s*'wp_ajax_cashback_validate_dedup_config'\s*,\s*array\(\s*\\\$this\s*,\s*'ajax_validate_dedup_config'\s*\)\s*\)/",
            $this->src(),
            'wp_ajax_cashback_validate_dedup_config must be registered to ajax_validate_dedup_config'
        );
    }

    public function test_handler_has_nonce_and_capability_guards(): void
    {
        $body = $this->method_body('ajax_validate_dedup_config');
        $this->assertStringContainsString(
            "check_ajax_referer('cashback_api_validation', 'nonce')",
            $body,
            'handler must verify the api-validation nonce'
        );
        $this->assertStringContainsString(
            "current_user_can('manage_options')",
            $body,
            'handler must require manage_options'
        );
        $this->assertStringContainsString(
            'compute_dedup_config_report()',
            $body,
            'handler must delegate to the shared report builder'
        );
        $this->assertStringContainsString('wp_send_json_success', $body);
    }

    public function test_report_builder_checks_native_synthetic_and_receiver_source(): void
    {
        $body = $this->method_body('compute_dedup_config_report');

        // native ⇒ an API field must map to uniq_id
        $this->assertMatchesRegularExpression(
            "/\\\$has_native && \\\$uniq_src === ''/",
            $body,
            'native mode must flag a missing uniq_id mapping'
        );
        // synthetic ⇒ synthetic_fields must be non-empty
        $this->assertMatchesRegularExpression(
            "/!\\\$has_native && \\\$synthetic_fields === array\(\)/",
            $body,
            'synthetic mode must flag empty synthetic_fields'
        );
        // receiver source must be declared (push/pull)
        $this->assertStringContainsString(
            "\$receiver_src === ''",
            $body,
            'must flag missing receiver_uniq_source'
        );
        // has_native derivation matches the resolver contract (null == native)
        $this->assertMatchesRegularExpression(
            "/\(\s*\\\$decoded === null\s*\)\s*\?\s*true/",
            $body,
            'has_native must treat null dedup_identity as native (legacy parity)'
        );
        // status is ok only when no issues
        $this->assertStringContainsString("\$issues === array() ? 'ok' : 'warn'", $body);
    }

    public function test_panel_is_rendered_in_validation_tab(): void
    {
        $tab = $this->method_body('render_validation_tab');
        $this->assertStringContainsString(
            '$this->render_dedup_config_panel();',
            $tab,
            'render_validation_tab must invoke the dedup-config panel'
        );
    }

    public function test_panel_escapes_all_dynamic_output(): void
    {
        $body = $this->method_body('render_dedup_config_panel');
        // No raw echo of report fields without esc_html.
        $this->assertStringNotContainsString('echo $row[', $body, 'dynamic output must be escaped');
        $this->assertStringContainsString('esc_html(', $body);
    }
}
