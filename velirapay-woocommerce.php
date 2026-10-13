<?php

/**
 * Plugin Name: VeliraPay for WooCommerce
 * Plugin URI: https://github.com/VeliraPay/velirapay-woocommerce
 * Description: Take Bitcoin, Ethereum, USDC and other coins at checkout with VeliraPay. Each order becomes a VeliraPay invoice the customer pays on a hosted page.
 * Version: 0.1.0
 * Author: VeliraPay
 * Author URI: https://velirapay.com
 * Text Domain: velirapay-woocommerce
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * Requires Plugins: woocommerce
 * WC requires at least: 8.3
 * WC tested up to: 11.2
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

declare(strict_types=1);
use VeliraPay\WooCommerce\Plugin;

defined('ABSPATH') || exit;

spl_autoload_register(static function (string $class): void {
    $prefix = 'VeliraPay\\WooCommerce\\';

    if (! str_starts_with($class, $prefix)) {
        return;
    }

    $file = __DIR__.'/src/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';

    if (is_file($file)) {
        require $file;
    }
});

Plugin::boot(__FILE__);
