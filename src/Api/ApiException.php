<?php

declare(strict_types=1);

namespace VeliraPay\WooCommerce\Api;

use RuntimeException;

/**
 * Thrown when the VeliraPay API answers with an error, or cannot be reached.
 */
final class ApiException extends RuntimeException
{
    /**
     * Create a new exception.
     *
     * @param  int  $status  The HTTP status the API answered with, or 0 when it could not be reached.
     * @param  array<string, list<string>>  $errors  The validation messages, keyed by field.
     */
    public function __construct(string $message, public readonly int $status, public readonly array $errors = [])
    {
        parent::__construct($message, $status);
    }

    /**
     * Create the exception for an error response.
     *
     * @param  array<array-key, mixed>  $body  The decoded response body.
     */
    public static function fromResponse(int $status, array $body): self
    {
        $message = $body['message'] ?? null;
        $errors = [];

        foreach (is_array($body['errors'] ?? null) ? $body['errors'] : [] as $field => $messages) {
            $errors[(string) $field] = array_values(array_filter(is_array($messages) ? $messages : [$messages], 'is_string'));
        }

        return new self(
            is_string($message) && $message !== '' ? $message : "The VeliraPay API answered with HTTP {$status}.",
            $status,
            $errors,
        );
    }

    /**
     * Determine if the API refused the action in the object's current state.
     */
    public function isConflict(): bool
    {
        return $this->status === 409;
    }

    /**
     * Determine if the object does not exist, or belongs to the other mode.
     */
    public function isNotFound(): bool
    {
        return $this->status === 404;
    }
}
