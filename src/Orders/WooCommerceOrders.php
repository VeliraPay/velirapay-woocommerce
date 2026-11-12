<?php

declare(strict_types=1);

namespace VeliraPay\WooCommerce\Orders;

use WC_Order;

defined('ABSPATH') || exit;

/**
 * Looks orders up by invoice code, on the posts table and on WooCommerce's own order tables alike.
 */
final class WooCommerceOrders implements OrderRepository
{
    /**
     * {@inheritDoc}
     */
    public function findByInvoiceCode(string $code): ?Order
    {
        return self::findByMeta(WooCommerceOrder::INVOICE_CODE_META, $code);
    }

    /**
     * {@inheritDoc}
     */
    public function findBySupersededInvoiceCode(string $code): ?Order
    {
        return self::findByMeta(WooCommerceOrder::SUPERSEDED_INVOICE_CODE_META, $code);
    }

    /**
     * Find the order with a meta value under a key.
     */
    private static function findByMeta(string $key, string $value): ?Order
    {
        // meta_key and meta_value reach WP_Query as they are on the posts store, and become a meta query on the order tables.
        // phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- A webhook names only the invoice, so its order is found by it: one order, once a delivery.
        $orders = wc_get_orders([
            'limit' => 1,
            'meta_key' => $key,
            'meta_value' => $value,
        ]);
        // phpcs:enable

        $order = is_array($orders) ? reset($orders) : false;

        return $order instanceof WC_Order ? new WooCommerceOrder($order) : null;
    }
}
