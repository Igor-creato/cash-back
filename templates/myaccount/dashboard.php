<?php
/**
 * My Account Dashboard (cash-back override)
 *
 * Override стандартного шаблона WooCommerce: убран описательный абзац
 * «From your account dashboard you can view your recent orders…»,
 * нерелевантный для кэшбэк-сервиса (реальных WC-заказов нет, вкладки кабинета свои).
 *
 * При мажорных обновлениях WooCommerce сверяться с
 * wp-content/plugins/woocommerce/templates/myaccount/dashboard.php
 * по @version и синхронизировать остальной HTML.
 *
 * @see     https://woocommerce.com/document/template-structure/
 * @package Cashback\Templates
 * @version 4.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/** @var WP_User $current_user Передаётся WooCommerce через wc_get_template(). */

$allowed_html = array(
	'a' => array(
		'href' => array(),
	),
);
?>

<p>
	<?php
	printf(
		/* translators: 1: user display name 2: logout url */
		wp_kses( __( 'Hello %1$s (not %1$s? <a href="%2$s">Log out</a>)', 'woocommerce' ), $allowed_html ),
		'<strong>' . esc_html( $current_user->display_name ) . '</strong>',
		esc_url( wc_logout_url() )
	);
	?>
</p>

<?php
	/**
	 * My Account dashboard.
	 *
	 * @since 2.6.0
	 */
	do_action( 'woocommerce_account_dashboard' );

	/**
	 * Deprecated woocommerce_before_my_account action.
	 *
	 * @deprecated 2.6.0
	 */
	do_action( 'woocommerce_before_my_account' );

	/**
	 * Deprecated woocommerce_after_my_account action.
	 *
	 * @deprecated 2.6.0
	 */
	do_action( 'woocommerce_after_my_account' );

/* Omit closing PHP tag at the end of PHP files to avoid "headers already sent" issues. */
