<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('admin')]
#[Group('shortcodes')]
final class AdminShortcodesTabTest extends TestCase {

    public function test_shortcodes_tab_documents_link_checker_shortcode(): void {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/admin/statistics.php');

        self::assertStringContainsString('<!-- [cashback_link_checker] -->', $source);
        self::assertStringContainsString('[cashback_link_checker]', $source);
        self::assertStringContainsString('cashback_link_checker placeholder=', $source);
        self::assertStringContainsString('Проверяет ссылку на товар или магазин', $source);
        self::assertStringContainsString('Кэшбэк не гарантируется', $source);
        self::assertStringContainsString('<code>placeholder</code>', $source);
    }
}
