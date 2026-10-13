<?php

declare(strict_types=1);

namespace VeliraPay\WooCommerce\Orders;

use WC_Order;

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
        // meta_key and meta_value reach WP_Query as they are on the posts store, and become a meta query on the order tables.
        $orders = wc_get_orders([
            'limit' => 1,
            'meta_key' => WooCommerceOrder::INVOICE_CODE_META,
            'meta_value' => $code,
        ]);

        $order = is_array($orders) ? reset($orders) : false;

        return $order instanceof WC_Order ? new WooCommerceOrder($order) : null;
    }
}
