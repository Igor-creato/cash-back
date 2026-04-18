<?php
/**
 * Единый класс пагинации для админ-панели плагина.
 *
 * Паттерн разметки и алгоритм выбора страниц скопирован с фронтенд-«Истории покупок»
 * (cashback-history.php::render_pagination). Рендерит счётчик «N элементов», стрелки
 * «/‹/›/» и кликабельные номера страниц с многоточиями для больших диапазонов.
 *
 * @package Cashback_Plugin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Cashback_Admin_Pagination {

	/**
	 * Рендер пагинации.
	 *
	 * @param array $args {
	 *     @type int    $total_items  Всего записей (для счётчика «N элементов»).
	 *     @type int    $current_page Текущая страница.
	 *     @type int    $total_pages  Всего страниц.
	 *     @type string $page_slug    Slug страницы админки (напр. 'cashback-banks').
	 *     @type array  $add_args     Доп. query-аргументы (фильтры/поиск). Default [].
	 *     @type int    $edge         Кол-во страниц на краю. Default 2.
	 *     @type int    $range        Радиус вокруг текущей. Default 2.
	 *     @type int    $per_page     Не используется (оставлено для совместимости вызовов). Default 0.
	 * }
	 * @return void
	 */
	public static function render( array $args ): void {
		$total_items  = isset( $args['total_items'] ) ? (int) $args['total_items'] : 0;
		$current_page = isset( $args['current_page'] ) ? max( 1, (int) $args['current_page'] ) : 1;
		$total_pages  = isset( $args['total_pages'] ) ? max( 0, (int) $args['total_pages'] ) : 0;
		$page_slug    = isset( $args['page_slug'] ) ? (string) $args['page_slug'] : '';
		$add_args     = isset( $args['add_args'] ) && is_array( $args['add_args'] ) ? $args['add_args'] : array();
		$edge         = isset( $args['edge'] ) ? max( 1, (int) $args['edge'] ) : 2;
		$range        = isset( $args['range'] ) ? max( 1, (int) $args['range'] ) : 2;

		if ( $current_page > $total_pages && $total_pages > 0 ) {
			$current_page = $total_pages;
		}

		echo '<div class="tablenav bottom">';
		echo '<div class="tablenav-pages">';

		echo '<span class="displaying-num">' . esc_html( sprintf(
			/* translators: %s: форматированное количество записей. */
			_n( '%s элемент', '%s элементов', $total_items, 'cashback-plugin' ),
			number_format_i18n( $total_items )
		) ) . '</span>';

		if ( $total_pages > 1 ) {
			self::render_links( $current_page, $total_pages, $page_slug, $add_args, $edge, $range );
		}

		echo '</div>';
		echo '<br class="clear"></div>';
	}

	/**
	 * Собирает URL для заданной страницы.
	 *
	 * @param int    $page      Номер страницы.
	 * @param string $page_slug Slug страницы админки.
	 * @param array  $add_args  Доп. query-аргументы.
	 * @return string
	 */
	private static function build_url( int $page, string $page_slug, array $add_args ): string {
		$base = remove_query_arg( 'paged', add_query_arg( 'page', $page_slug, admin_url( 'admin.php' ) ) );
		if ( ! empty( $add_args ) ) {
			$base = add_query_arg( $add_args, $base );
		}
		return add_query_arg( 'paged', $page, $base );
	}

	/**
	 * Рендер `<nav>` со стрелками и номерами страниц.
	 *
	 * @param int    $current   Текущая страница.
	 * @param int    $total     Всего страниц.
	 * @param string $page_slug Slug страницы.
	 * @param array  $add_args  Доп. аргументы.
	 * @param int    $edge      Крайние страницы.
	 * @param int    $range     Окно.
	 * @return void
	 */
	private static function render_links( int $current, int $total, string $page_slug, array $add_args, int $edge, int $range ): void {
		$pages = array();

		$edge_limit = min( $edge, $total );
		for ( $i = 1; $i <= $edge_limit; $i++ ) {
			$pages[] = $i;
		}
		$range_start = max( 1, $current - $range );
		$range_end   = min( $total, $current + $range );
		for ( $i = $range_start; $i <= $range_end; $i++ ) {
			$pages[] = $i;
		}
		$tail_start = max( 1, $total - $edge + 1 );
		for ( $i = $tail_start; $i <= $total; $i++ ) {
			$pages[] = $i;
		}

		$pages = array_values( array_unique( $pages ) );
		sort( $pages );

		echo '<nav class="cashback-admin-pagination" aria-label="' . esc_attr__( 'Навигация по страницам', 'cashback-plugin' ) . '">';
		echo '<ul class="page-numbers">';

		if ( $current > 1 ) {
			$first_url = self::build_url( 1, $page_slug, $add_args );
			$prev_url  = self::build_url( $current - 1, $page_slug, $add_args );
			echo '<li><a class="page-numbers first" href="' . esc_url( $first_url ) . '" aria-label="' . esc_attr__( 'Первая страница', 'cashback-plugin' ) . '">&laquo;</a></li>';
			echo '<li><a class="page-numbers prev" href="' . esc_url( $prev_url ) . '" aria-label="' . esc_attr__( 'Предыдущая страница', 'cashback-plugin' ) . '">&lsaquo;</a></li>';
		}

		$prev = 0;
		foreach ( $pages as $page ) {
			if ( $prev && ( $page - $prev ) > 1 ) {
				echo '<li><span class="page-numbers dots">&hellip;</span></li>';
			}
			if ( $page === $current ) {
				echo '<li><span class="page-numbers current" aria-current="page">' . esc_html( (string) $page ) . '</span></li>';
			} else {
				$url = self::build_url( $page, $page_slug, $add_args );
				echo '<li><a class="page-numbers" href="' . esc_url( $url ) . '">' . esc_html( (string) $page ) . '</a></li>';
			}
			$prev = $page;
		}

		if ( $current < $total ) {
			$next_url = self::build_url( $current + 1, $page_slug, $add_args );
			$last_url = self::build_url( $total, $page_slug, $add_args );
			echo '<li><a class="page-numbers next" href="' . esc_url( $next_url ) . '" aria-label="' . esc_attr__( 'Следующая страница', 'cashback-plugin' ) . '">&rsaquo;</a></li>';
			echo '<li><a class="page-numbers last" href="' . esc_url( $last_url ) . '" aria-label="' . esc_attr__( 'Последняя страница', 'cashback-plugin' ) . '">&raquo;</a></li>';
		}

		echo '</ul>';
		echo '</nav>';
	}
}
