<?php

declare(strict_types=1);

namespace VeliraPay\WooCommerce\Tests\Support;

use VeliraPay\WooCommerce\Orders\Order;
use VeliraPay\WooCommerce\Orders\OrderRepository;

/**
 * Orders kept in memory, keyed by the codes of their invoices.
 */
final class FakeOrders implements OrderRepository
{
    /**
     * Create a repository of orders keyed by the code of their current invoice, and of the invoices they replaced.
     *
     * @param  array<string, Order>  $orders
     * @param  array<string, Order>  $superseded
     */
    public function __construct(private array $orders = [], private array $superseded = []) {}

    /**
     * {@inheritDoc}
     */
    public function findByInvoiceCode(string $code): ?Order
    {
        return $this->orders[$code] ?? null;
    }

    /**
     * {@inheritDoc}
     */
    public function findBySupersededInvoiceCode(string $code): ?Order
    {
        return $this->superseded[$code] ?? null;
    }
}
