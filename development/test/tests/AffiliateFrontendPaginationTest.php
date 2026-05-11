<?php
/**
 * Структурные тесты унификации пагинации Партнёрской программы.
 *
 * Проверяют:
 *  - PHP: разделение таблиц и пагинации на 2 контейнера каждая;
 *  - PHP: render-методы возвращают current_page+total_pages;
 *  - PHP: AJAX-хендлеры кладут current_page+total_pages в response;
 *  - JS: клик-делегаты слушают новые контейнеры пагинации;
 *  - JS: используется window.CashbackPagination.build.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class AffiliateFrontendPaginationTest extends TestCase {

	private function plugin_root(): string {
		return dirname( __DIR__, 3 );
	}

	private function source( string $rel ): string {
		$path = $this->plugin_root() . '/' . ltrim( $rel, '/' );
		$this->assertFileExists( $path, "Missing: $rel" );
		return (string) file_get_contents( $path );
	}

	public function test_php_renders_two_separate_pagination_containers(): void {
		$src = $this->source( 'affiliate/class-affiliate-frontend.php' );
		$this->assertStringContainsString(
			'id="affiliate-accruals-pagination"',
			$src,
			'endpoint_content() должен рендерить отдельный <div id="affiliate-accruals-pagination">'
		);
		$this->assertStringContainsString(
			'id="affiliate-referrals-pagination"',
			$src,
			'endpoint_content() должен рендерить отдельный <div id="affiliate-referrals-pagination">'
		);
	}

	public function test_php_render_methods_return_pagination_meta(): void {
		$src = $this->source( 'affiliate/class-affiliate-frontend.php' );
		$this->assertMatchesRegularExpression(
			'/function\s+render_accruals_table\s*\([^)]*\)\s*:\s*array\b/',
			$src,
			'render_accruals_table должен возвращать array (current_page, total_pages)'
		);
		$this->assertMatchesRegularExpression(
			'/function\s+render_referrals_table\s*\([^)]*\)\s*:\s*array\b/',
			$src,
			'render_referrals_table должен возвращать array (current_page, total_pages)'
		);
	}

	public function test_php_ajax_response_includes_pagination_meta(): void {
		$src = $this->source( 'affiliate/class-affiliate-frontend.php' );
		$this->assertMatchesRegularExpression(
			"/'current_page'\s*=>/",
			$src,
			'AJAX-ответ должен содержать current_page'
		);
		$this->assertMatchesRegularExpression(
			"/'total_pages'\s*=>/",
			$src,
			'AJAX-ответ должен содержать total_pages'
		);
	}

	public function test_js_uses_common_pagination_builder(): void {
		$src = $this->source( 'assets/js/affiliate-frontend.js' );
		$this->assertStringContainsString(
			'window.CashbackPagination.build',
			$src,
			'JS должен пересобирать пагинацию через window.CashbackPagination.build()'
		);
	}

	public function test_js_click_delegates_target_pagination_containers(): void {
		$src = $this->source( 'assets/js/affiliate-frontend.js' );
		$this->assertStringContainsString(
			'#affiliate-accruals-pagination .page-numbers[data-page]',
			$src,
			'JS должен слушать клики на #affiliate-accruals-pagination, не на container'
		);
		$this->assertStringContainsString(
			'#affiliate-referrals-pagination .page-numbers[data-page]',
			$src,
			'JS должен слушать клики на #affiliate-referrals-pagination, не на container'
		);
	}
}
