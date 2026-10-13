<?php

declare(strict_types=1);

namespace VeliraPay\WooCommerce\Orders;

/**
 * Finds the order a VeliraPay invoice was created for.
 */
interface OrderRepository
{
    /**
     * Find the order paid by the invoice with this code, if any.
     */
    public function findByInvoiceCode(string $code): ?Order;
}
