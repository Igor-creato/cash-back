<?php
/**
 * Cashback → Группы магазинов — admin страница для управления автогруппами
 * (дедуп между CPA-сетями, v12).
 *
 * Возможности:
 *   - Список групп: domain / members count / preferred / status.
 *   - Per-row actions: «Подтвердить» (auto → confirmed),
 *     «Снять pin» / «Pin product» через выпадашку, «Разделить product».
 *   - Bulk «Подтвердить» для status=auto групп.
 *
 * @package CashbackPlugin
 * @since   12.0.0
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

class Cashback_Shop_Groups_Admin {

    public const PAGE_SLUG          = 'cashback-shop-groups';
    public const ADMIN_POST_ACTION  = 'cashback_shop_group_action';
    public const NONCE_ACTION       = 'cashback_shop_group_action';
    public const PER_PAGE           = 20;

    public static function init(): void {
        add_action('admin_menu', array( self::class, 'register_menu' ), 32);
        add_action('admin_post_' . self::ADMIN_POST_ACTION, array( self::class, 'handle_action' ));
    }

    public static function register_menu(): void {
        add_submenu_page(
            'cashback-overview',
            'Группы магазинов',
            'Группы магазинов',
            'manage_options',
            self::PAGE_SLUG,
            array( self::class, 'render_page' )
        );
    }

    public static function handle_action(): void {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('Недостаточно прав.', 'cashback'), '', array( 'response' => 403 ));
        }
        check_admin_referer(self::NONCE_ACTION);

        $op       = isset($_POST['op']) ? sanitize_key((string) wp_unslash($_POST['op'])) : '';
        $group_id = isset($_POST['group_id']) ? (int) $_POST['group_id'] : 0;
        $product_id = isset($_POST['product_id']) ? (int) $_POST['product_id'] : 0;

        if (! class_exists('Cashback_Shop_Group_Resolver')) {
            self::redirect_with_notice('error', 'Group Resolver не доступен');
            return;
        }

        switch ($op) {
            case 'confirm':
                Cashback_Shop_Group_Resolver::confirm($group_id);
                self::redirect_with_notice('success', 'Группа #' . $group_id . ' подтверждена');
                return;

            case 'pin':
                if ($group_id > 0 && $product_id > 0) {
                    Cashback_Shop_Group_Resolver::pin_product($group_id, $product_id);
                    self::redirect_with_notice('success', 'Pin product=' . $product_id . ' для группы #' . $group_id);
                    return;
                }
                self::redirect_with_notice('error', 'Pin: нужны group_id + product_id');
                return;

            case 'unpin':
                Cashback_Shop_Group_Resolver::unpin($group_id);
                self::redirect_with_notice('success', 'Pin снят с группы #' . $group_id);
                return;

            case 'split':
                if ($product_id > 0) {
                    Cashback_Shop_Group_Resolver::split_member($product_id);
                    self::redirect_with_notice('success', 'Product #' . $product_id . ' выкинут из группы');
                    return;
                }
                self::redirect_with_notice('error', 'Split: нужен product_id');
                return;

            default:
                self::redirect_with_notice('error', 'Неизвестная операция: ' . $op);
        }
    }

    public static function render_page(): void {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('Недостаточно прав.', 'cashback'));
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin listing pagination, intval-cast.
        $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $total        = self::count_groups();
        $total_pages  = $total > 0 ? (int) ceil($total / self::PER_PAGE) : 0;
        if ($total_pages > 0 && $current_page > $total_pages) {
            $current_page = $total_pages;
        }
        $offset = ( $current_page - 1 ) * self::PER_PAGE;
        $groups = self::fetch_groups(self::PER_PAGE, $offset);

        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Группы магазинов', 'cashback'); ?></h1>
            <p class="description">
                <?php esc_html_e('Один магазин может быть в нескольких CPA-сетях; группы дедуплицируют его на витрине. Preferred — продукт с лучшей ставкой (или pin, если задан).', 'cashback'); ?>
            </p>

            <?php self::render_admin_notice(); ?>

            <?php if (empty($groups)) : ?>
                <p><?php esc_html_e('Группы ещё не созданы. Запустите импорт магазинов — группы автоматически сформируются по доменам.', 'cashback'); ?></p>
            <?php else : ?>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th><?php esc_html_e('Домен', 'cashback'); ?></th>
                            <th><?php esc_html_e('Members', 'cashback'); ?></th>
                            <th><?php esc_html_e('Preferred', 'cashback'); ?></th>
                            <th><?php esc_html_e('Pin', 'cashback'); ?></th>
                            <th><?php esc_html_e('Статус', 'cashback'); ?></th>
                            <th><?php esc_html_e('Действия', 'cashback'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($groups as $group) : ?>
                            <?php self::render_group_row($group); ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php
                Cashback_Pagination::render(array(
                    'total_items'  => $total,
                    'per_page'     => self::PER_PAGE,
                    'current_page' => $current_page,
                    'total_pages'  => $total_pages,
                    'page_slug'    => self::PAGE_SLUG,
                ));
                ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * @param array<string, mixed> $group
     */
    private static function render_group_row( array $group ): void {
        $group_id = (int) ( $group['id'] ?? 0 );
        $members  = self::fetch_members_with_titles($group_id);
        $pin_id   = (int) ( $group['pin_product_id'] ?? 0 );
        $pref_id  = (int) ( $group['preferred_product_id'] ?? 0 );

        ?>
        <tr>
            <td><?php echo esc_html((string) $group_id); ?></td>
            <td><?php echo esc_html((string) ( $group['domain'] ?? '' )); ?></td>
            <td>
                <?php
                if (empty($members)) {
                    echo '—';
                } else {
                    foreach ($members as $m) {
                        echo '<div>#' . esc_html((string) $m['id']) . ' '
                            . esc_html((string) $m['title']) . '</div>';
                    }
                }
                ?>
            </td>
            <td><?php echo $pref_id > 0 ? esc_html('#' . $pref_id) : '—'; ?></td>
            <td><?php echo $pin_id > 0 ? esc_html('#' . $pin_id) : '—'; ?></td>
            <td><?php echo esc_html((string) ( $group['status'] ?? 'auto' )); ?></td>
            <td>
                <?php self::render_action_form('confirm', $group_id); ?>
                <?php if ($pin_id > 0) : ?>
                    <?php self::render_action_form('unpin', $group_id); ?>
                <?php elseif (count($members) > 1) : ?>
                    <?php self::render_pin_form($group_id, $members); ?>
                <?php endif; ?>
            </td>
        </tr>
        <?php
    }

    private static function render_action_form( string $op, int $group_id, int $product_id = 0 ): void {
        $labels = array(
            'confirm' => 'Подтвердить',
            'unpin'   => 'Снять pin',
        );
        $label = $labels[ $op ] ?? $op;
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline">
            <input type="hidden" name="action" value="<?php echo esc_attr(self::ADMIN_POST_ACTION); ?>" />
            <input type="hidden" name="op" value="<?php echo esc_attr($op); ?>" />
            <input type="hidden" name="group_id" value="<?php echo esc_attr((string) $group_id); ?>" />
            <?php if ($product_id > 0) : ?>
                <input type="hidden" name="product_id" value="<?php echo esc_attr((string) $product_id); ?>" />
            <?php endif; ?>
            <?php wp_nonce_field(self::NONCE_ACTION); ?>
            <button type="submit" class="button button-small"><?php echo esc_html($label); ?></button>
        </form>
        <?php
    }

    /**
     * @param array<int, array<string, mixed>> $members
     */
    private static function render_pin_form( int $group_id, array $members ): void {
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline">
            <input type="hidden" name="action" value="<?php echo esc_attr(self::ADMIN_POST_ACTION); ?>" />
            <input type="hidden" name="op" value="pin" />
            <input type="hidden" name="group_id" value="<?php echo esc_attr((string) $group_id); ?>" />
            <select name="product_id">
                <?php foreach ($members as $m) : ?>
                    <option value="<?php echo esc_attr((string) $m['id']); ?>">
                        #<?php echo esc_html((string) $m['id']); ?>
                        — <?php echo esc_html((string) $m['title']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php wp_nonce_field(self::NONCE_ACTION); ?>
            <button type="submit" class="button button-small">Pin</button>
        </form>
        <?php
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function fetch_groups( int $per_page, int $offset ): array {
        global $wpdb;
        if (! isset($wpdb) || ! is_object($wpdb)) {
            return array();
        }
        $per_page = max(1, min(500, $per_page));
        $offset   = max(0, $offset);
        $table    = $wpdb->prefix . Cashback_Shop_Group_Resolver::TABLE_GROUPS;
        $rows     = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM %i ORDER BY id DESC LIMIT %d OFFSET %d',
            $table,
            $per_page,
            $offset
        ), ARRAY_A);
        return is_array($rows) ? $rows : array();
    }

    private static function count_groups(): int {
        global $wpdb;
        if (! isset($wpdb) || ! is_object($wpdb)) {
            return 0;
        }
        $table = $wpdb->prefix . Cashback_Shop_Group_Resolver::TABLE_GROUPS;
        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM %i',
            $table
        ));
    }

    /**
     * @return array<int, array{id: int, title: string}>
     */
    private static function fetch_members_with_titles( int $group_id ): array {
        if ($group_id <= 0) {
            return array();
        }
        $ids = Cashback_Shop_Group_Resolver::get_active_members($group_id);
        $out = array();
        foreach ($ids as $id) {
            $title = function_exists('get_the_title') ? (string) get_the_title($id) : '';
            $out[] = array(
                'id'    => $id,
                'title' => $title !== '' ? $title : ('#' . $id),
            );
        }
        return $out;
    }

    private static function redirect_with_notice( string $type, string $message ): void {
        $url = add_query_arg(
            array(
                'page'             => self::PAGE_SLUG,
                'cashback_notice'  => $type,
                'cashback_message' => rawurlencode($message),
            ),
            admin_url('admin.php')
        );
        wp_safe_redirect($url);
        exit;
    }

    private static function render_admin_notice(): void {
        // Read-only admin notice rendering после redirect от admin_post handler'а
        // (handle_action уже проверил nonce + capability). Здесь nonce не нужен.
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        if (empty($_GET['cashback_notice'])) {
            return;
        }
        $type    = sanitize_key((string) wp_unslash($_GET['cashback_notice']));
        $message = isset($_GET['cashback_message'])
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize_text_field применяется ниже после wp_unslash + rawurldecode.
            ? sanitize_text_field(rawurldecode((string) wp_unslash($_GET['cashback_message'])))
            : '';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
        $css = $type === 'success' ? 'notice-success' : 'notice-error';
        echo '<div class="notice ' . esc_attr($css) . ' is-dismissible"><p>'
            . esc_html($message) . '</p></div>';
    }
}
