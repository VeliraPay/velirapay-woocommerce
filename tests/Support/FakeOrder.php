<?php

declare(strict_types=1);

namespace VeliraPay\WooCommerce\Tests\Support;

use VeliraPay\WooCommerce\Orders\Order;

/**
 * An order that records what the status mapping does to it.
 */
final class FakeOrder implements Order
{
    /**
     * The notes added, in order.
     *
     * @var list<string>
     */
    public array $notes = [];

    /**
     * The webhook events applied.
     *
     * @var list<string>
     */
    public array $events = [];

    /**
     * The transaction id the payment was recorded with.
     */
    public ?string $transactionId = null;

    /**
     * Create an order in a status.
     */
    public function __construct(public string $status = 'pending') {}

    /**
     * {@inheritDoc}
     */
    public function status(): string
    {
        return $this->status;
    }

    /**
     * {@inheritDoc}
     */
    public function isPaid(): bool
    {
        return in_array($this->status, ['processing', 'completed'], true);
    }

    /**
     * {@inheritDoc}
     */
    public function markPaid(string $transactionId, string $note): void
    {
        $this->transactionId = $transactionId;
        $this->status = 'processing';
        $this->notes[] = $note;
    }

    /**
     * {@inheritDoc}
     */
    public function setStatus(string $status, string $note): void
    {
        $this->status = $status;
        $this->notes[] = $note;
    }

    /**
     * {@inheritDoc}
     */
    public function addNote(string $note): void
    {
        $this->notes[] = $note;
    }

    /**
     * {@inheritDoc}
     */
    public function hasHandledEvent(string $eventId): bool
    {
        return in_array($eventId, $this->events, true);
    }

    /**
     * {@inheritDoc}
     */
    public function rememberEvent(string $eventId): void
    {
        $this->events[] = $eventId;
    }
}
