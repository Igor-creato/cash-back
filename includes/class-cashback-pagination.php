<?php
/**
 * Единый класс пагинации для плагина Cashback.
 *
 * Поддерживает два режима:
 *  - 'link' — URL-ссылки (?paged=N), используется в админке (перезагрузка страницы).
 *  - 'ajax' — href="#" с data-page, используется во фронтенде (AJAX-перерисовка).
 *
 * @package Cashback_Plugin
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Cashback_Pagination {

	/**
	 * Рендер пагинации.
	 *
	 * @param array $args {
	 *     @type string $mode            'link' | 'ajax'. Обязательный.
	 *     @type int    $total_items     Всего записей (для счётчика "N элементов" в режиме 'link').
	 *     @type int    $current_page    Текущая страница.
	 *     @type int    $total_pages     Всего страниц.
	 *     @type string $page_slug       Slug admin-страницы (только для 'link'), напр. 'cashback-users'.
	 *     @type array  $add_args        Доп. query-аргументы (только для 'link').
	 *     @type int    $edge            Страниц на краю. Default 2.
	 *     @type int    $range           Радиус вокруг текущей. Default 2.
	 *     @type string $container_class CSS-класс nav. Default: 'cashback-admin-pagination' (link) /
	 *                                   'woocommerce-pagination' (ajax).
	 *     @type bool   $show_counter    Показывать счётчик "N элементов". Default: true для 'link', false для 'ajax'.
	 * }
	 * @return void
	 */
	public static function render( array $args ): void {
		$mode = isset( $args['mode'] ) ? (string) $args['mode'] : 'link';
		if ( 'link' !== $mode && 'ajax' !== $mode ) {
			$mode = 'link';
		}

		$total_items  = isset( $args['total_items'] ) ? (int) $args['total_items'] : 0;
		$current_page = isset( $args['current_page'] ) ? max( 1, (int) $args['current_page'] ) : 1;
		$total_pages  = isset( $args['total_pages'] ) ? max( 0, (int) $args['total_pages'] ) : 0;
		$page_slug    = isset( $args['page_slug'] ) ? (string) $args['page_slug'] : '';
		$add_args     = isset( $args['add_args'] ) && is_array( $args['add_args'] ) ? $args['add_args'] : array();
		$edge         = isset( $args['edge'] ) ? max( 1, (int) $args['edge'] ) : 2;
		$range        = isset( $args['range'] ) ? max( 1, (int) $args['range'] ) : 2;

		$default_container = ( 'link' === $mode ) ? 'cashback-admin-pagination' : 'woocommerce-pagination';
		$container_class   = isset( $args['container_class'] ) && '' !== (string) $args['container_class']
			? (string) $args['container_class']
			: $default_container;

		$show_counter = isset( $args['show_counter'] ) ? (bool) $args['show_counter'] : ( 'link' === $mode );

		if ( $current_page > $total_pages && $total_pages > 0 ) {
			$current_page = $total_pages;
		}

		if ( 'link' === $mode ) {
			echo '<div class="tablenav bottom">';
			echo '<div class="tablenav-pages">';
		}

		if ( $show_counter ) {
			echo '<span class="displaying-num">' . esc_html( sprintf(
				/* translators: %s: форматированное количество записей. */
				_n( '%s элемент', '%s элементов', $total_items, 'cashback-plugin' ),
				number_format_i18n( $total_items )
			) ) . '</span>';
		}

		if ( $total_pages > 1 ) {
			self::render_links( $mode, $current_page, $total_pages, $page_slug, $add_args, $edge, $range, $container_class );
		}

		if ( 'link' === $mode ) {
			echo '</div>';
			echo '<br class="clear"></div>';
		}
	}

	/**
	 * Собирает URL для заданной страницы (режим 'link').
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
	 * Собирает список номеров страниц для отображения (edge/range/dots).
	 *
	 * @param int $current Текущая страница.
	 * @param int $total   Всего страниц.
	 * @param int $edge    Страниц на краю.
	 * @param int $range   Радиус вокруг текущей.
	 * @return int[]
	 */
	private static function build_page_list( int $current, int $total, int $edge, int $range ): array {
		$pages      = array();
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
		return $pages;
	}

	/**
	 * Рендер <nav> со стрелками и номерами страниц.
	 *
	 * @param string $mode            'link' | 'ajax'.
	 * @param int    $current         Текущая страница.
	 * @param int    $total           Всего страниц.
	 * @param string $page_slug       Slug страницы.
	 * @param array  $add_args        Доп. query-аргументы.
	 * @param int    $edge            Крайние страницы.
	 * @param int    $range           Окно.
	 * @param string $container_class CSS-класс nav.
	 * @return void
	 */
	private static function render_links( string $mode, int $current, int $total, string $page_slug, array $add_args, int $edge, int $range, string $container_class ): void {
		$pages     = self::build_page_list( $current, $total, $edge, $range );
		$is_link   = ( 'link' === $mode );
		$nav_label = $is_link
			? __( 'Навигация по страницам', 'cashback-plugin' )
			: __( 'Навигация по страницам', 'cashback-plugin' );

		echo '<nav class="' . esc_attr( $container_class ) . '" aria-label="' . esc_attr( $nav_label ) . '">';
		echo '<ul class="page-numbers">';

		if ( $current > 1 ) {
			if ( $is_link ) {
				$first_url = self::build_url( 1, $page_slug, $add_args );
				$prev_url  = self::build_url( $current - 1, $page_slug, $add_args );
				echo '<li><a class="page-numbers first" href="' . esc_url( $first_url ) . '" aria-label="' . esc_attr__( 'Первая страница', 'cashback-plugin' ) . '">&laquo;</a></li>';
				echo '<li><a class="page-numbers prev" href="' . esc_url( $prev_url ) . '" aria-label="' . esc_attr__( 'Предыдущая страница', 'cashback-plugin' ) . '">&lsaquo;</a></li>';
			} else {
				echo '<li><a class="page-numbers prev" href="#" data-page="' . esc_attr( (string) ( $current - 1 ) ) . '" aria-label="' . esc_attr__( 'Предыдущая страница', 'cashback-plugin' ) . '">&lsaquo;</a></li>';
			}
		}

		$prev = 0;
		foreach ( $pages as $page ) {
			if ( $prev && ( $page - $prev ) > 1 ) {
				echo '<li><span class="page-numbers dots">&hellip;</span></li>';
			}
			if ( $page === $current ) {
				if ( $is_link ) {
					echo '<li><span class="page-numbers current" aria-current="page">' . esc_html( (string) $page ) . '</span></li>';
				} else {
					// В режиме ajax текущая страница тоже кликабельна как <a class="current"> — совместимо с существующими JS-обработчиками фронта.
					echo '<li><a class="page-numbers current" href="#" data-page="' . esc_attr( (string) $page ) . '" aria-current="page">' . esc_html( (string) $page ) . '</a></li>';
				}
			} elseif ( $is_link ) {
					$url = self::build_url( $page, $page_slug, $add_args );
					echo '<li><a class="page-numbers" href="' . esc_url( $url ) . '">' . esc_html( (string) $page ) . '</a></li>';
				} else {
					echo '<li><a class="page-numbers" href="#" data-page="' . esc_attr( (string) $page ) . '">' . esc_html( (string) $page ) . '</a></li>';
			}
			$prev = $page;
		}

		if ( $current < $total ) {
			if ( $is_link ) {
				$next_url = self::build_url( $current + 1, $page_slug, $add_args );
				$last_url = self::build_url( $total, $page_slug, $add_args );
				echo '<li><a class="page-numbers next" href="' . esc_url( $next_url ) . '" aria-label="' . esc_attr__( 'Следующая страница', 'cashback-plugin' ) . '">&rsaquo;</a></li>';
				echo '<li><a class="page-numbers last" href="' . esc_url( $last_url ) . '" aria-label="' . esc_attr__( 'Последняя страница', 'cashback-plugin' ) . '">&raquo;</a></li>';
			} else {
				echo '<li><a class="page-numbers next" href="#" data-page="' . esc_attr( (string) ( $current + 1 ) ) . '" aria-label="' . esc_attr__( 'Следующая страница', 'cashback-plugin' ) . '">&rsaquo;</a></li>';
			}
		}

		echo '</ul>';
		echo '</nav>';
	}
}
