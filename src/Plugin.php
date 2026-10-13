<?php

declare(strict_types=1);

namespace VeliraPay\WooCommerce;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;
use Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry;
use Automattic\WooCommerce\Utilities\FeaturesUtil;
use Closure;
use VeliraPay\WooCommerce\Api\ApiException;
use VeliraPay\WooCommerce\Blocks\PaymentMethod;
use VeliraPay\WooCommerce\Orders\StatusSync;
use VeliraPay\WooCommerce\Orders\WooCommerceOrder;
use VeliraPay\WooCommerce\Orders\WooCommerceOrders;
use VeliraPay\WooCommerce\Webhooks\Handler;
use WC_Order;

/**
 * Wires the gateway, the webhook receiver and the status sync into WordPress and WooCommerce.
 */
final class Plugin
{
    /**
     * The plugin's version, as in its header.
     */
    public const VERSION = '0.1.0';

    /**
     * The WP-Cron hook that polls pending orders.
     */
    public const SYNC_HOOK = 'velirapay_sync_pending_orders';

    /**
     * The order action that polls one order from its edit screen.
     */
    public const CHECK_STATUS_ACTION = 'velirapay_check_status';

    /**
     * The source every log entry is filed under.
     */
    public const LOG_SOURCE = 'velirapay';

    /**
     * How many days a pending order is polled for, matching how long VeliraPay retries a webhook.
     */
    private const SYNC_DAYS = 3;

    /**
     * The main plugin file.
     */
    private static string $file = '';

    /**
     * Register the hooks that run before WooCommerce is known to be active.
     */
    public static function boot(string $file): void
    {
        self::$file = $file;

        add_action('before_woocommerce_init', [self::class, 'declareCompatibility']);
        // Fired while plugins load, so it has to be listened for before then.
        add_action('woocommerce_blocks_loaded', [self::class, 'registerBlocksIntegration']);
        add_action('plugins_loaded', [self::class, 'init']);
        register_deactivation_hook($file, [self::class, 'deactivate']);
    }

    /**
     * Tell WooCommerce the plugin works with order tables and the block checkout.
     */
    public static function declareCompatibility(): void
    {
        if (class_exists(FeaturesUtil::class)) {
            FeaturesUtil::declare_compatibility('custom_order_tables', self::$file, true);
            FeaturesUtil::declare_compatibility('cart_checkout_blocks', self::$file, true);
        }
    }

    /**
     * Register everything else once WooCommerce has loaded.
     */
    public static function init(): void
    {
        if (! class_exists('WC_Payment_Gateway')) {
            add_action('admin_notices', [self::class, 'missingWooCommerceNotice']);

            return;
        }

        add_filter('woocommerce_payment_gateways', [self::class, 'registerGateway']);
        add_action('woocommerce_api_'.Gateway::ID, [self::class, 'receiveWebhook']);
        add_action('woocommerce_order_status_cancelled', [self::class, 'voidInvoiceOfCancelledOrder'], 10, 2);
        add_filter('woocommerce_order_actions', [self::class, 'addOrderAction'], 10, 2);
        add_action('woocommerce_order_action_'.self::CHECK_STATUS_ACTION, [self::class, 'checkStatus']);
        add_action('template_redirect', [self::class, 'syncOnOrderReceivedPage'], 10);
        add_action('init', [self::class, 'scheduleSync']);
        add_action(self::SYNC_HOOK, [self::class, 'syncPendingOrders']);
        add_action('admin_notices', [self::class, 'configurationNotices']);
    }

    /**
     * Stop polling when the plugin is deactivated.
     */
    public static function deactivate(): void
    {
        wp_clear_scheduled_hook(self::SYNC_HOOK);
    }

    /**
     * Add the gateway to WooCommerce's list.
     *
     * @param  array<int|string, mixed>  $gateways
     * @return array<int|string, mixed>
     */
    public static function registerGateway(array $gateways): array
    {
        $gateways[] = Gateway::class;

        return $gateways;
    }

    /**
     * Offer the gateway on the block checkout as well.
     */
    public static function registerBlocksIntegration(): void
    {
        if (! class_exists(AbstractPaymentMethodType::class)) {
            return;
        }

        add_action('woocommerce_blocks_payment_method_type_registration', static function (PaymentMethodRegistry $registry): void {
            $registry->register(new PaymentMethod);
        });
    }

    /**
     * Answer a webhook delivery posted to the WooCommerce API URL.
     */
    public static function receiveWebhook(): void
    {
        $gateway = self::gateway();
        $payload = (string) file_get_contents('php://input');
        $signature = $_SERVER['HTTP_X_VELIRAPAY_SIGNATURE'] ?? null;
        $header = is_string($signature) ? sanitize_text_field(wp_unslash($signature)) : null;

        $handler = new Handler(new WooCommerceOrders, new StatusSync, $gateway->mode(), $gateway->webhookSecrets());
        $response = $handler->handle($payload, $header);

        if ($response['status'] >= 400) {
            wc_get_logger()->warning(sprintf('Webhook answered with HTTP %d: %s', $response['status'], $response['body']), ['source' => self::LOG_SOURCE]);
        }

        status_header($response['status']);
        header('Content-Type: text/plain; charset=utf-8');
        echo esc_html($response['body']);
        exit;
    }

    /**
     * Void the invoice of an order that was cancelled, so the customer cannot pay it any more.
     */
    public static function voidInvoiceOfCancelledOrder(int $orderId, mixed $order = null): void
    {
        $order = $order instanceof WC_Order ? $order : wc_get_order($orderId);

        if (! $order instanceof WC_Order || $order->get_payment_method() !== Gateway::ID) {
            return;
        }

        $code = WooCommerceOrder::invoiceCodeOf($order);
        $client = self::gateway()->client();

        if ($code === '' || $client === null) {
            return;
        }

        try {
            $client->voidInvoice($code);
            $order->add_order_note(__('The VeliraPay invoice was voided; it can no longer be paid.', 'velirapay-woocommerce'));
        } catch (ApiException $exception) {
            // A 409 means the invoice was already paid or voided, or has a payment on its way, which VeliraPay reports on its own.
            if (! $exception->isConflict()) {
                $order->add_order_note(sprintf(
                    /* translators: %s: error message */
                    __('The VeliraPay invoice could not be voided: %s', 'velirapay-woocommerce'),
                    $exception->getMessage(),
                ));
            }
        }
    }

    /**
     * Offer "Check VeliraPay status" on the edit screen of an order paid with VeliraPay.
     *
     * @param  array<string, string>  $actions
     * @return array<string, string>
     */
    public static function addOrderAction(array $actions, mixed $order = null): array
    {
        $order = $order instanceof WC_Order ? $order : ($GLOBALS['theorder'] ?? null);

        if ($order instanceof WC_Order && $order->get_payment_method() === Gateway::ID && WooCommerceOrder::invoiceCodeOf($order) !== '') {
            $actions[self::CHECK_STATUS_ACTION] = __('Check VeliraPay status', 'velirapay-woocommerce');
        }

        return $actions;
    }

    /**
     * Poll the order's invoice from its edit screen and note what came back.
     */
    public static function checkStatus(mixed $order): void
    {
        if (! $order instanceof WC_Order || ! current_user_can('edit_shop_orders')) {
            return;
        }

        $invoice = self::sync($order);

        if ($invoice === null) {
            $order->add_order_note(__('VeliraPay could not be asked about the invoice. Check the API key in the gateway settings and WooCommerce → Status → Logs.', 'velirapay-woocommerce'));

            return;
        }

        $order->add_order_note(sprintf(
            /* translators: 1: invoice number, 2: invoice status */
            __('VeliraPay invoice %1$s checked: it is %2$s.', 'velirapay-woocommerce'),
            is_scalar($invoice['number'] ?? null) ? (string) $invoice['number'] : '',
            is_scalar($invoice['status'] ?? null) ? (string) $invoice['status'] : '',
        ));
    }

    /**
     * Poll the invoice when the customer lands on the order-received page, so a missed webhook does not show a pending order.
     */
    public static function syncOnOrderReceivedPage(): void
    {
        if (! is_wc_endpoint_url('order-received')) {
            return;
        }

        $orderId = get_query_var('order-received', 0);
        $order = is_numeric($orderId) && (int) $orderId > 0 ? wc_get_order((int) $orderId) : null;
        $key = $_GET['key'] ?? null;
        $key = is_string($key) ? sanitize_text_field(wp_unslash($key)) : '';

        if (! $order instanceof WC_Order || $order->get_payment_method() !== Gateway::ID || ! hash_equals($order->get_order_key(), $key)) {
            return;
        }

        if ($order->has_status(['pending', 'on-hold']) && WooCommerceOrder::invoiceCodeOf($order) !== '') {
            self::sync($order);
        }
    }

    /**
     * Make sure pending orders are polled every hour.
     */
    public static function scheduleSync(): void
    {
        if (wp_next_scheduled(self::SYNC_HOOK) === false) {
            wp_schedule_event(time(), 'hourly', self::SYNC_HOOK);
        }
    }

    /**
     * Poll the invoices of recent orders still waiting for payment.
     */
    public static function syncPendingOrders(): void
    {
        if (self::gateway()->client() === null) {
            return;
        }

        $orders = wc_get_orders([
            'limit' => 50,
            'payment_method' => Gateway::ID,
            'status' => ['pending', 'on-hold'],
            'date_created' => '>'.(time() - self::SYNC_DAYS * DAY_IN_SECONDS),
        ]);

        foreach (is_array($orders) ? $orders : [] as $order) {
            if (WooCommerceOrder::invoiceCodeOf($order) !== '') {
                self::sync($order);
            }
        }
    }

    /**
     * Warn when the store cannot use the gateway as it is set up.
     */
    public static function configurationNotices(): void
    {
        if (! current_user_can('manage_woocommerce')) {
            return;
        }

        $gateway = self::gateway();
        $currency = get_woocommerce_currency();

        if (! in_array($currency, Gateway::CURRENCIES, true)) {
            self::notice(sprintf(
                /* translators: 1: store currency, 2: supported currencies */
                __('VeliraPay cannot take payments in %1$s, so it is not offered at checkout. It supports %2$s; the store currency is under WooCommerce → Settings → General.', 'velirapay-woocommerce'),
                $currency,
                implode(', ', Gateway::CURRENCIES),
            ));
        }

        if ($gateway->enabled === 'yes' && $gateway->apiKey() === '') {
            self::notice(sprintf(
                /* translators: 1: "live" or "test", 2: settings page URL */
                __('VeliraPay is enabled but has no %1$s API key, so it is not offered at checkout. <a href="%2$s">Add one in its settings</a>.', 'velirapay-woocommerce'),
                $gateway->mode(),
                esc_url(admin_url('admin.php?page=wc-settings&tab=checkout&section='.Gateway::ID)),
            ));
        }
    }

    /**
     * Explain that nothing works without WooCommerce.
     */
    public static function missingWooCommerceNotice(): void
    {
        if (current_user_can('activate_plugins')) {
            self::notice(__('VeliraPay for WooCommerce needs WooCommerce 8.3 or later to be installed and active.', 'velirapay-woocommerce'));
        }
    }

    /**
     * Get the gateway with its current settings.
     */
    public static function gateway(): Gateway
    {
        $gateways = WC()->payment_gateways()->payment_gateways();
        $gateway = $gateways[Gateway::ID] ?? null;

        return $gateway instanceof Gateway ? $gateway : new Gateway;
    }

    /**
     * Get the URL of a file in the plugin.
     */
    public static function url(string $path): string
    {
        return plugins_url($path, self::$file);
    }

    /**
     * Get the User-Agent API requests are sent with.
     */
    public static function userAgent(): string
    {
        return 'velirapay-woocommerce/'.self::VERSION;
    }

    /**
     * Get a transport that sends API requests through the WordPress HTTP API.
     *
     * @return Closure(string, string, array<string, string>, string|null, int): array{status: int, body: string}
     */
    public static function httpTransport(): Closure
    {
        $transport = static function (string $method, string $url, array $headers, ?string $body, int $timeout): array {
            $userAgent = $headers['User-Agent'] ?? '';
            unset($headers['User-Agent']);

            $args = [
                'method' => $method,
                'headers' => $headers,
                'timeout' => $timeout,
                'user-agent' => is_string($userAgent) ? $userAgent : '',
            ];

            if ($body !== null) {
                $args['body'] = $body;
            }

            $response = wp_remote_request($url, $args);

            if (is_wp_error($response)) {
                throw new ApiException('Could not reach the VeliraPay API: '.$response->get_error_message(), 0);
            }

            return [
                'status' => (int) wp_remote_retrieve_response_code($response),
                'body' => wp_remote_retrieve_body($response),
            ];
        };

        return $transport;
    }

    /**
     * Poll the order's invoice and apply its status, returning the invoice, or null when the API could not be asked.
     *
     * @return array<string, mixed>|null
     */
    private static function sync(WC_Order $order): ?array
    {
        $client = self::gateway()->client();
        $code = WooCommerceOrder::invoiceCodeOf($order);

        if ($client === null || $code === '') {
            return null;
        }

        try {
            $invoice = $client->retrieveInvoice($code);
        } catch (ApiException $exception) {
            wc_get_logger()->error(sprintf('Could not check invoice %s of order #%s: %s', $code, $order->get_order_number(), $exception->getMessage()), ['source' => self::LOG_SOURCE]);

            return null;
        }

        (new StatusSync)->applyInvoice(new WooCommerceOrder($order), $invoice);

        return $invoice;
    }

    /**
     * Print an admin notice.
     */
    private static function notice(string $message): void
    {
        echo '<div class="notice notice-warning"><p>'.wp_kses_post($message).'</p></div>';
    }
}
