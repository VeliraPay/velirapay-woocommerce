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

        public string $enabled = 'yes';

        public function get_option(string $key): string
        {
            return $this->settings[$key] ?? '';
        }

        public function get_return_url(WC_Order $order): string
        {
            return 'https://store.example/order-received/'.$order->get_id();
        }

        public function is_available(): bool
        {
            return $this->enabled === 'yes';
        }
    }
}

if (! class_exists('WC_Meta_Data')) {
    class WC_Meta_Data
    {
        public function __construct(private array $meta = []) {}

        public function __get(string $key): mixed
        {
            return $this->meta[$key] ?? null;
        }
    }
}

if (! class_exists('WP_User')) {
    class WP_User
    {
        public string $user_email = '';
    }
}

if (! class_exists('WC_Cart')) {
    class WC_Cart
    {
        private string $total = '0';

        public function get_total(string $context = 'view'): string
        {
            return $this->total;
        }

        public function set_total(string $value): void
        {
            $this->total = $value;
        }
    }
}

if (! class_exists('WC_Order')) {
    class WC_Order
    {
        /**
         * Every meta key's values, in the order they were added, as WooCommerce keeps several rows under one key.
         */
        private array $meta = [];

        private string $status = 'pending';

        private string $total = '49.90';

        private string $currency = 'EUR';

        private string $billingEmail = 'grace@example.com';

        private int $customerId = 0;

        private string $createdVia = 'checkout';

        private ?DateTime $dateCreated = null;

        private ?DateTime $datePaid = null;

        private string $transactionId = '';

        private string $paymentMethod = 'velirapay';

        public array $notes = [];

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
            return $this->billingEmail;
        }

        public function set_billing_email(string $email): void
        {
            $this->billingEmail = $email;
        }

        public function get_customer_id(): int
        {
            return $this->customerId;
        }

        public function set_customer_id(int $id): void
        {
            $this->customerId = $id;
        }

        public function get_total(): string
        {
            return $this->total;
        }

        public function set_total(string $total): void
        {
            $this->total = $total;
        }

        public function get_currency(): string
        {
            return $this->currency;
        }

        public function set_currency(string $currency): void
        {
            $this->currency = $currency;
        }

        public function get_created_via(): string
        {
            return $this->createdVia;
        }

        public function set_created_via(string $value): void
        {
            $this->createdVia = $value;
        }

        public function get_date_created(): ?DateTime
        {
            return $this->dateCreated;
        }

        public function set_date_created(string $value): void
        {
            $this->dateCreated = new DateTime($value);
        }

        public function get_meta(string $key = '', bool $single = true): mixed
        {
            $values = $this->meta[$key] ?? [];

            if ($single) {
                return $values === [] ? '' : $values[0];
            }

            return array_map(static fn (mixed $value): WC_Meta_Data => new WC_Meta_Data(['key' => $key, 'value' => $value]), $values);
        }

        public function update_meta_data(string $key, mixed $value): void
        {
            $this->meta[$key] = [$value];
        }

        public function add_meta_data(string $key, mixed $value, bool $unique = false): void
        {
            $this->meta[$key][] = $value;
        }

        public function delete_meta_data(string $key): void
        {
            unset($this->meta[$key]);
        }

        public function delete_meta_data_value(string $key, mixed $value): void
        {
            $this->meta[$key] = array_values(array_filter($this->meta[$key] ?? [], static fn (mixed $kept): bool => $kept !== $value));

            if ($this->meta[$key] === []) {
                unset($this->meta[$key]);
            }
        }

        public function save(): int
        {
            return $this->id;
        }

        public function get_status(): string
        {
            return $this->status;
        }

        public function set_status(string $status): void
        {
            $this->status = $status;
        }

        public function update_status(string $status, string $note = ''): bool
        {
            $this->status = $status;

            if ($note !== '') {
                $this->notes[] = $note;
            }

            return true;
        }

        // As WooCommerce does: only an order waiting for payment, or a cancelled one, is moved on, and it gets a payment date.
        public function payment_complete(string $transactionId = ''): bool
        {
            if (! in_array($this->status, ['on-hold', 'pending', 'failed', 'cancelled'], true)) {
                return false;
            }

            $this->transactionId = $transactionId;
            $this->datePaid ??= new DateTime;
            $this->status = 'processing';

            return true;
        }

        public function get_transaction_id(): string
        {
            return $this->transactionId;
        }

        public function has_status(string|array $status): bool
        {
            return in_array($this->status, (array) $status, true);
        }

        public function get_payment_method(): string
        {
            return $this->paymentMethod;
        }

        public function set_payment_method(string $method): void
        {
            $this->paymentMethod = $method;
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

        public function add_order_note(string $note): int
        {
            $this->notes[] = $note;

            return count($this->notes);
        }
    }
}

function wc_get_order(int $id): WC_Order|false
{
    return $GLOBALS['velirapay_test_orders'][$id] ?? false;
}

function wc_get_order_notes(array $args): array
{
    $order = $GLOBALS['velirapay_test_orders'][$args['order_id'] ?? 0] ?? null;

    return $order instanceof WC_Order ? array_map(static fn (string $note): stdClass => (object) ['content' => $note], $order->notes) : [];
}

function wc_format_decimal(string $value, int $decimals): string
{
    return number_format((float) $value, $decimals, '.', '');
}

function wc_add_notice(string $message, string $type): void
{
    $GLOBALS['velirapay_test_notices'][] = [$type, $message];
}

function get_bloginfo(string $show): string
{
    return 'Example store';
}

function wp_specialchars_decode(string $text, int $quoteStyle): string
{
    return html_entity_decode($text, $quoteStyle);
}

function esc_html(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES);
}

function wc_get_logger(): object
{
    return new class
    {
        public function error(string $message, array $context): void
        {
            $GLOBALS['velirapay_test_logs'][] = $message;
        }

        public function warning(string $message, array $context): void
        {
            $GLOBALS['velirapay_test_logs'][] = $message;
        }
    };
}

function wp_remote_request(string $url, array $args): array
{
    return ($GLOBALS['velirapay_test_http'])($url, $args);
}

function wp_remote_get(string $url, array $args): array
{
    return ($GLOBALS['velirapay_test_http'])($url, $args);
}

function is_wp_error(mixed $value): bool
{
    return $value instanceof WP_Error;
}

if (! class_exists('WP_Error')) {
    class WP_Error
    {
        public function __construct(private string $message = '') {}

        public function get_error_message(): string
        {
            return $this->message;
        }
    }
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
define('HOUR_IN_SECONDS', 3600);
define('MINUTE_IN_SECONDS', 60);
define('WEEK_IN_SECONDS', 604800);

function wc_get_orders(array $args): array
{
    $orders = array_filter($GLOBALS['velirapay_test_orders'] ?? [], static function (WC_Order $order) use ($args): bool {
        if (isset($args['meta_key'])) {
            $values = array_map(static fn (WC_Meta_Data $meta): mixed => $meta->value, $order->get_meta($args['meta_key'], false));

            return in_array($args['meta_value'] ?? null, $values, true);
        }

        return $order->has_status($args['status']);
    });
    $result = ($args['return'] ?? null) === 'ids' ? array_keys($orders) : array_values($orders);

    return ($args['limit'] ?? -1) === -1 ? $result : array_slice($result, 0, $args['limit']);
}

function WC(): object
{
    return new class
    {
        public ?WC_Cart $cart;

        public function __construct()
        {
            $this->cart = $GLOBALS['velirapay_test_cart'] ?? null;
        }

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

        public function api_request_url(string $request, ?bool $ssl = null): string
        {
            return ($ssl === true ? 'https' : 'http').'://store.example/wc-api/'.$request.'/';
        }
    };
}

function get_woocommerce_currency(): string
{
    return $GLOBALS['velirapay_test_currency'] ?? 'EUR';
}

function get_query_var(string $name, mixed $default = ''): mixed
{
    return $GLOBALS['velirapay_test_query_vars'][$name] ?? $default;
}

function absint(mixed $value): int
{
    return abs((int) $value);
}

function get_userdata(int $id): WP_User|false
{
    return $GLOBALS['velirapay_test_users'][$id] ?? false;
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

function get_transient(string $name): mixed
{
    return $GLOBALS['velirapay_test_transients'][$name] ?? false;
}

function set_transient(string $name, mixed $value, int $expiration = 0): bool
{
    $GLOBALS['velirapay_test_transients'][$name] = $value;

    return true;
}

function delete_transient(string $name): bool
{
    unset($GLOBALS['velirapay_test_transients'][$name]);

    return true;
}

function get_site_transient(string $name): mixed
{
    return $GLOBALS['velirapay_test_transients'][$name] ?? false;
}

function set_site_transient(string $name, mixed $value, int $expiration = 0): bool
{
    $GLOBALS['velirapay_test_transients'][$name] = $value;

    return true;
}

function wp_next_scheduled(string $hook, array $args = []): int|false
{
    return $GLOBALS['velirapay_test_schedule'][$hook] ?? false;
}

function wp_schedule_single_event(int $timestamp, string $hook, array $args = []): bool
{
    $GLOBALS['velirapay_test_schedule'][$hook] = $timestamp;
    $GLOBALS['velirapay_test_events'][] = [$timestamp, $hook, $args];

    return true;
}

function wp_clear_scheduled_hook(string $hook): int
{
    unset($GLOBALS['velirapay_test_schedule'][$hook]);

    return 1;
}
