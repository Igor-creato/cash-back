<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Trait для рендеринга пагинации в админ-панели.
 *
 * Используется в Cashback_Payouts_Admin и Cashback_Users_Management_Admin.
 */
trait AdminPaginationTrait
{
    /**
     * Рендерит пагинацию с использованием WordPress paginate_links().
     *
     * @param array $args {
     *     @type int    $total_items  Общее количество записей.
     *     @type int    $per_page     Записей на странице.
     *     @type int    $current_page Текущая страница.
     *     @type int    $total_pages  Общее количество страниц.
     *     @type string $page_slug    Slug страницы админки.
     *     @type array  $add_args     Дополнительные query-аргументы.
     * }
     * @return void
     */
    private function render_pagination(array $args): void
    {
        $total_items = (int) $args['total_items'];
        $per_page = (int) $args['per_page'];
        $current_page = (int) $args['current_page'];
        $total_pages = (int) $args['total_pages'];
        $page_slug = $args['page_slug'];
        $add_args = $args['add_args'];

        if ($total_pages <= 1) {
            return;
        }

        // Явно формируем базовый URL для предотвращения trailing slash проблемы
        $base_url = remove_query_arg('paged', add_query_arg('page', $page_slug, admin_url('admin.php')));

        $pagination_links = paginate_links([
            'base'      => add_query_arg('paged', '%#%', $base_url),
            'format'    => '',
            'total'     => $total_pages,
            'current'   => $current_page,
            'add_args'  => $add_args,
            'type'      => 'plain',
            'prev_text' => '&lsaquo; ' . __('Предыдущая', 'cashback-plugin'),
            'next_text' => __('Следующая', 'cashback-plugin') . ' &rsaquo;',
        ]);

        if ($pagination_links) {
            echo '<div class="tablenav bottom">';
            echo '<div class="tablenav-pages">';
            echo '<span class="displaying-num">' . sprintf(_n('%s запись', '%s записей', $total_items, 'cashback-plugin'), number_format_i18n($total_items)) . '</span>';
            echo '<span class="pagination-links">';
            echo wp_kses_post($pagination_links);
            echo '</span>';
            echo '</div>';
            echo '<br class="clear"></div>';
        }
    }
}
