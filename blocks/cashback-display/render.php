<?php
/**
 * Server-side render for cashback/cashback-display block.
 *
 * Reads `_cashback_display_value` and `_cashback_display_label` post-meta from
 * the current product and renders an independent HTML node — without touching
 * the price block.
 *
 * Available variables (provided by WP block runtime):
 *
 * @var array      $attributes Block attributes.
 * @var string     $content    Saved block content (always empty for dynamic block).
 * @var \WP_Block  $block      Block instance with context.
 *
 * @package Cashback_Plugin
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

$cashback_block_post_id = 0;
if (isset($block) && $block instanceof \WP_Block && isset($block->context['postId'])) {
    $cashback_block_post_id = (int) $block->context['postId'];
}
if (0 === $cashback_block_post_id) {
    $cashback_block_post_id = (int) get_the_ID();
}
if (0 === $cashback_block_post_id) {
    return '';
}

if (!function_exists('wc_get_product')) {
    return '';
}

$cashback_block_product = wc_get_product($cashback_block_post_id);
if (!$cashback_block_product instanceof \WC_Product) {
    return '';
}

if ($cashback_block_product->get_type() !== 'external') {
    return '';
}

if (function_exists('is_cart') && is_cart()) {
    return '';
}
if (function_exists('is_checkout') && is_checkout()) {
    return '';
}

if (!class_exists('WC_Affiliate_URL_Params')) {
    return '';
}

$cashback_block_inner = WC_Affiliate_URL_Params::render_cashback_html(
    $cashback_block_post_id,
    'block',
    true
);

if ('' === $cashback_block_inner) {
    return '';
}

$cashback_block_wrapper_attrs = function_exists('get_block_wrapper_attributes')
    ? get_block_wrapper_attributes(array( 'class' => 'cashback-display-block-wrap' ))
    : 'class="cashback-display-block-wrap"';

return sprintf(
    '<div %1$s>%2$s</div>',
    $cashback_block_wrapper_attrs, // already escaped by core helper.
    wp_kses_post($cashback_block_inner)
);
