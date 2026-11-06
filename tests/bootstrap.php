<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

// The pure classes translate their order notes with WordPress's __(), which is all they need from it.
if (! function_exists('__')) {
    function __(string $text, string $domain = 'default'): string
    {
        return $text;
    }
}

// Checkout tests replace WordPress storage and HTTP without loading a store or reaching the network.
if (! class_exists('WC_Payment_Gateway')) {
    class WC_Payment_Gateway
    {
        public array $settings = [];

        public function get_option(string $key): string
        {
            return $this->settings[$key] ?? '';
        }

        public function get_return_url(WC_Order $order): string
        {
            return 'https://store.example/order-received/'.$order->get_id();
        }
    }
}

if (! class_exists('WC_Order')) {
    class WC_Order
    {
        private array $meta = [];

        private string $status = 'pending';

        private string $total = '49.90';

        private ?DateTime $datePaid = null;

        public function __construct(private int $id = 0) {}

        public function get_id(): int
        {
            return $this->id;
        }

        public function get_order_number(): string
        {
            return (string) $this->id;
        }

        public function get_order_key(): string
        {
            return 'wc_order_example';
        }

        public function get_formatted_billing_full_name(): string
        {
            return 'Grace Hopper';
        }

        public function get_billing_email(): string
        {
            return 'grace@example.com';
        }

        public function get_total(): string
        {
            return $this->total;
        }

        public function get_currency(): string
        {
            return 'EUR';
        }

        public function get_meta(string $key): mixed
        {
            return $this->meta[$key] ?? '';
        }

        public function update_meta_data(string $key, mixed $value): void
        {
            $this->meta[$key] = $value;
        }

        public function get_status(): string
        {
            return $this->status;
        }

        public function set_status(string $status): void
        {
            $this->status = $status;
        }

        public function update_status(string $status, string $note): void
        {
            $this->status = $status;
        }

        public function payment_complete(string $transactionId): void
        {
            $this->status = 'processing';
        }

        public function has_status(string|array $status): bool
        {
            return in_array($this->status, (array) $status, true);
        }

        public function get_payment_method(): string
        {
            return 'velirapay';
        }

        public function is_paid(): bool
        {
            return in_array($this->status, ['processing', 'completed'], true);
        }

        public function get_date_paid(): ?DateTime
        {
            return $this->datePaid;
        }

        public function set_date_paid(string $value): void
        {
            $this->datePaid = new DateTime($value);
        }

        public function add_order_note(string $note): void {}
    }
}

function wc_get_order(int $id): WC_Order|false
{
    return $GLOBALS['velirapay_test_orders'][$id] ?? false;
}

function wc_format_decimal(string $value, int $decimals): string
{
    return number_format((float) $value, $decimals, '.', '');
}

function wc_add_notice(string $message, string $type): void {}

function get_bloginfo(string $show): string
{
    return 'Example store';
}

function wp_specialchars_decode(string $text, int $quoteStyle): string
{
    return html_entity_decode($text, $quoteStyle);
}

function wc_get_logger(): object
{
    return new class
    {
        public function error(string $message, array $context): void {}
    };
}

function wp_remote_request(string $url, array $args): array
{
    return ($GLOBALS['velirapay_test_http'])($url, $args);
}

function is_wp_error(mixed $value): bool
{
    return false;
}

function wp_remote_retrieve_response_code(array $response): int
{
    return $response['status'];
}

function wp_remote_retrieve_body(array $response): string
{
    return $response['body'];
}

define('DAY_IN_SECONDS', 86400);
define('MINUTE_IN_SECONDS', 60);

function wc_get_orders(array $args): array
{
    $orders = array_filter($GLOBALS['velirapay_test_orders'] ?? [], static fn (WC_Order $order): bool => $order->has_status($args['status']));
    $result = ($args['return'] ?? null) === 'ids' ? array_keys($orders) : array_values($orders);

    return ($args['limit'] ?? -1) === -1 ? $result : array_slice($result, 0, $args['limit']);
}

function WC(): object
{
    return new class
    {
        public function payment_gateways(): object
        {
            return new class
            {
                public function payment_gateways(): array
                {
                    return ['velirapay' => $GLOBALS['velirapay_test_gateway']];
                }
            };
        }
    };
}

function get_option(string $name, mixed $default = false): mixed
{
    return $GLOBALS['velirapay_test_options'][$name] ?? $default;
}

function update_option(string $name, mixed $value, mixed $autoload = null): bool
{
    $GLOBALS['velirapay_test_options'][$name] = $value;

    return true;
}

function delete_option(string $name): bool
{
    unset($GLOBALS['velirapay_test_options'][$name]);

    return true;
}

function wp_next_scheduled(string $hook): int|false
{
    return $GLOBALS['velirapay_test_schedule'][$hook] ?? false;
}

function wp_schedule_single_event(int $timestamp, string $hook): bool
{
    $GLOBALS['velirapay_test_schedule'][$hook] = $timestamp;

    return true;
}

function wp_clear_scheduled_hook(string $hook): int
{
    unset($GLOBALS['velirapay_test_schedule'][$hook]);

    return 1;
}
