<?php

declare(strict_types=1);

namespace VeliraPay\WooCommerce\Tests\Support;

use VeliraPay\WooCommerce\Orders\Order;
use VeliraPay\WooCommerce\Orders\OrderRepository;

/**
 * Orders kept in memory, keyed by the code of their invoice.
 */
final class FakeOrders implements OrderRepository
{
    /**
     * Create a repository of orders keyed by invoice code.
     *
     * @param  array<string, Order>  $orders
     */
    public function __construct(private array $orders = []) {}

    /**
     * {@inheritDoc}
     */
    public function findByInvoiceCode(string $code): ?Order
    {
        return $this->orders[$code] ?? null;
    }
}
