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

    /**
     * Find the order that used the invoice with this code before it was replaced by a new one, if any.
     */
    public function findBySupersededInvoiceCode(string $code): ?Order;
}
