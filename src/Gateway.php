<?php

declare(strict_types=1);

namespace VeliraPay\WooCommerce;

use VeliraPay\WooCommerce\Api\ApiException;
use VeliraPay\WooCommerce\Api\Client;
use VeliraPay\WooCommerce\Orders\StatusSync;
use VeliraPay\WooCommerce\Orders\WooCommerceOrder;
use VeliraPay\WooCommerce\Webhooks\Signature;
use WC_Admin_Settings;
use WC_Order;
use WC_Payment_Gateway;

/**
 * The VeliraPay payment method: each order becomes an invoice the customer pays on VeliraPay's hosted page.
 */
final class Gateway extends WC_Payment_Gateway
{
    /**
     * The gateway's id, also the wc-api endpoint webhooks are posted to.
     */
    public const ID = 'velirapay';

    /**
     * The store currencies VeliraPay can bill in.
     */
    public const CURRENCIES = ['USD', 'EUR', 'GBP', 'CAD', 'AUD', 'CHF', 'TRY'];

    /**
     * The prefix of an endpoint's signing secret.
     */
    private const SECRET_PREFIX = 'whsec_';

    /**
     * Set the gateway up from its saved settings.
     */
    public function __construct()
    {
        $this->id = self::ID;
        $this->icon = Plugin::url('assets/icon.svg');
        $this->has_fields = false;
        $this->method_title = __('VeliraPay', 'velirapay-woocommerce');
        $this->method_description = __('Take Bitcoin, Ethereum, USDC and other coins. The customer picks a coin on VeliraPay\'s hosted invoice page and the order is marked paid once the payment confirms. Refunds are not automated: send the coins back from your own wallet and record the refund in the VeliraPay dashboard.', 'velirapay-woocommerce');
        $this->supports = ['products'];

        $this->init_form_fields();
        $this->init_settings();

        $this->title = $this->get_option('title');
        $this->description = $this->get_option('description');

        add_action('woocommerce_update_options_payment_gateways_'.$this->id, function (): void {
            $this->process_admin_options();
        });
    }

    /**
     * Define the settings form.
     */
    public function init_form_fields(): void
    {
        $this->form_fields = [
            'enabled' => [
                'title' => __('Enable/Disable', 'velirapay-woocommerce'),
                'type' => 'checkbox',
                'label' => __('Enable VeliraPay', 'velirapay-woocommerce'),
                'default' => 'no',
            ],
            'title' => [
                'title' => __('Title', 'velirapay-woocommerce'),
                'type' => 'text',
                'description' => __('What the customer sees at checkout.', 'velirapay-woocommerce'),
                'default' => __('Crypto (VeliraPay)', 'velirapay-woocommerce'),
                'desc_tip' => true,
            ],
            'description' => [
                'title' => __('Description', 'velirapay-woocommerce'),
                'type' => 'textarea',
                'description' => __('Shown under the title at checkout.', 'velirapay-woocommerce'),
                'default' => __('Pay with Bitcoin, Ethereum, USDC or another coin. You will be sent to VeliraPay to pick a coin; the amount is then locked for 30 minutes while you pay. Crypto payments cannot be refunded automatically: contact us if you need one.', 'velirapay-woocommerce'),
                'desc_tip' => true,
            ],
            'testmode' => [
                'title' => __('Test mode', 'velirapay-woocommerce'),
                'type' => 'checkbox',
                'label' => __('Use the test API key and test webhook secret', 'velirapay-woocommerce'),
                'description' => __('Test mode runs on the test networks: no real coins move. Switch the VeliraPay dashboard to test mode to create a test key and a test endpoint.', 'velirapay-woocommerce'),
                'default' => 'no',
            ],
            'live_api_key' => [
                'title' => __('Live API key', 'velirapay-woocommerce'),
                'type' => 'password',
                'description' => __('Starts with vp_live_. Create one in the VeliraPay dashboard under Developers → API keys.', 'velirapay-woocommerce'),
            ],
            'test_api_key' => [
                'title' => __('Test API key', 'velirapay-woocommerce'),
                'type' => 'password',
                'description' => __('Starts with vp_test_. Create one with the dashboard in test mode.', 'velirapay-woocommerce'),
            ],
            'webhook_url' => [
                'title' => __('Webhook URL', 'velirapay-woocommerce'),
                'type' => 'velirapay_webhook_url',
            ],
            'live_webhook_secret' => [
                'title' => __('Live webhook secret', 'velirapay-woocommerce'),
                'type' => 'password',
                'description' => __('The signing secret of your live endpoint, starting with whsec_. While you rotate it, list the old and the new one separated by a comma.', 'velirapay-woocommerce'),
            ],
            'test_webhook_secret' => [
                'title' => __('Test webhook secret', 'velirapay-woocommerce'),
                'type' => 'password',
                'description' => __('The signing secret of your test endpoint, starting with whsec_.', 'velirapay-woocommerce'),
            ],
        ];
    }

    /**
     * Render the webhook URL to register, with the steps to set the endpoint up.
     *
     * @param  array<string, mixed>  $data
     */
    public function generate_velirapay_webhook_url_html(string $key, array $data): string
    {
        $title = is_string($data['title'] ?? null) ? $data['title'] : '';
        $events = ['invoice.paid', 'invoice.voided', 'charge.payment_detected', 'charge.underpaid', 'charge.expired', 'charge.late_payment', 'charge.refunded'];

        $steps = [
            __('Switch the VeliraPay dashboard to the mode you are setting up, live or test, and open Developers → Webhooks.', 'velirapay-woocommerce'),
            sprintf(
                /* translators: %s: event types */
                __('Add an endpoint with the URL above and tick these events: %s.', 'velirapay-woocommerce'),
                implode(', ', $events),
            ),
            __('Copy the endpoint\'s signing secret into the matching field below. Each mode has its own endpoint and secret.', 'velirapay-woocommerce'),
        ];

        $html = '<tr valign="top"><th scope="row" class="titledesc"><label for="'.esc_attr($this->get_field_key($key)).'">'.esc_html($title).'</label></th>';
        $html .= '<td class="forminp"><fieldset><legend class="screen-reader-text"><span>'.esc_html($title).'</span></legend>';
        $html .= '<input class="input-text regular-input" type="text" readonly id="'.esc_attr($this->get_field_key($key)).'" value="'.esc_attr($this->webhookUrl()).'" onfocus="this.select()" />';
        $html .= '<ol class="description" style="margin-top:8px">';

        foreach ($steps as $step) {
            $html .= '<li>'.esc_html($step).'</li>';
        }

        return $html.'</ol></fieldset></td></tr>';
    }

    /**
     * Save nothing for the webhook URL: it is read from the site.
     */
    public function validate_velirapay_webhook_url_field(string $key, mixed $value): string
    {
        return '';
    }

    /**
     * Keep a live key only when it has the live prefix.
     */
    public function validate_live_api_key_field(string $key, mixed $value): string
    {
        return $this->validatePrefixed($key, $value, Client::LIVE_PREFIX);
    }

    /**
     * Keep a test key only when it has the test prefix.
     */
    public function validate_test_api_key_field(string $key, mixed $value): string
    {
        return $this->validatePrefixed($key, $value, Client::TEST_PREFIX);
    }

    /**
     * Keep the live secrets only when each has the secret prefix.
     */
    public function validate_live_webhook_secret_field(string $key, mixed $value): string
    {
        return $this->validatePrefixed($key, $value, self::SECRET_PREFIX, list: true);
    }

    /**
     * Keep the test secrets only when each has the secret prefix.
     */
    public function validate_test_webhook_secret_field(string $key, mixed $value): string
    {
        return $this->validatePrefixed($key, $value, self::SECRET_PREFIX, list: true);
    }

    /**
     * Offer the gateway only when it can create invoices for the store's currency.
     */
    public function is_available(): bool
    {
        return parent::is_available()
            && $this->apiKey() !== ''
            && in_array(get_woocommerce_currency(), self::CURRENCIES, true);
    }

    /**
     * Create the order's invoice and send the customer to its hosted page.
     *
     * @param  int  $order_id
     * @return array{result: string, redirect?: string}
     */
    public function process_payment($order_id): array
    {
        $order = wc_get_order($order_id);
        $client = $this->client();

        if (! $order instanceof WC_Order || $client === null) {
            wc_add_notice(__('This payment method is not set up yet. Please choose another one.', 'velirapay-woocommerce'), 'error');

            return ['result' => 'failure'];
        }

        try {
            $invoice = $this->invoiceFor($order, $client);
        } catch (ApiException $exception) {
            wc_get_logger()->error(sprintf('Could not create an invoice for order #%s: %s', $order->get_order_number(), $exception->getMessage()), ['source' => Plugin::LOG_SOURCE]);
            $order->add_order_note(sprintf(
                /* translators: %s: error message */
                __('VeliraPay could not create an invoice: %s', 'velirapay-woocommerce'),
                $exception->getMessage(),
            ));
            wc_add_notice(__('The crypto payment could not be started. Please try again or choose another payment method.', 'velirapay-woocommerce'), 'error');

            return ['result' => 'failure'];
        }

        if (($invoice['status'] ?? null) === 'paid') {
            return ['result' => 'success', 'redirect' => $this->get_return_url($order)];
        }

        return ['result' => 'success', 'redirect' => is_string($invoice['hosted_url'] ?? null) ? $invoice['hosted_url'] : $this->get_return_url($order)];
    }

    /**
     * Get an API client for the current mode, or null while the key for it is missing.
     */
    public function client(): ?Client
    {
        $apiKey = $this->apiKey();

        if (Client::modeOf($apiKey) !== $this->mode()) {
            return null;
        }

        return new Client($apiKey, Plugin::httpTransport(), Plugin::userAgent());
    }

    /**
     * Get the mode the gateway works in: "live" or "test".
     */
    public function mode(): string
    {
        return $this->get_option('testmode') === 'yes' ? 'test' : 'live';
    }

    /**
     * Get the API key of the current mode.
     */
    public function apiKey(): string
    {
        return trim($this->get_option($this->mode() === 'test' ? 'test_api_key' : 'live_api_key'));
    }

    /**
     * Get every webhook secret, live and test, so a delivery of either mode is verified before its mode is looked at.
     *
     * @return list<string>
     */
    public function webhookSecrets(): array
    {
        return [
            ...Signature::parseSecrets($this->get_option('live_webhook_secret')),
            ...Signature::parseSecrets($this->get_option('test_webhook_secret')),
        ];
    }

    /**
     * Get the URL VeliraPay posts webhooks to.
     */
    public function webhookUrl(): string
    {
        return WC()->api_request_url(self::ID);
    }

    /**
     * Get the order's open invoice, or create one.
     *
     * @return array<string, mixed>
     *
     * @throws ApiException
     */
    private function invoiceFor(WC_Order $order, Client $client): array
    {
        $amount = wc_format_decimal($order->get_total(), 2);
        $currency = $order->get_currency();
        $code = WooCommerceOrder::invoiceCodeOf($order);

        if ($code !== '') {
            $existing = $this->existingInvoice($client, $code);
            $status = $existing['status'] ?? null;

            if ($status === 'paid') {
                (new StatusSync)->applyInvoice(new WooCommerceOrder($order), $existing);

                return $existing;
            }

            if ($status === 'open' && ($existing['amount'] ?? null) === $amount && ($existing['currency'] ?? null) === $currency) {
                return $existing;
            }

            if ($status === 'open') {
                $this->voidQuietly($client, $code);
            }
        }

        $name = trim($order->get_formatted_billing_full_name());

        $params = [
            'customer_name' => mb_substr($name !== '' ? $name : __('Customer', 'velirapay-woocommerce'), 0, 255),
            'customer_email' => $order->get_billing_email(),
            'amount' => $amount,
            'currency' => $currency,
            'memo' => mb_substr(sprintf(
                /* translators: 1: order number, 2: store name */
                __('Order #%1$s at %2$s', 'velirapay-woocommerce'),
                $order->get_order_number(),
                wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES),
            ), 0, 500),
            'send_email' => false,
        ];

        $key = sprintf('woocommerce-order-%d-%s', $order->get_id(), $order->get_order_key());

        try {
            $invoice = $client->createInvoice($params, $key);
        } catch (ApiException $exception) {
            if (! $exception->isConflict()) {
                throw $exception;
            }

            // The key was first used for this order with other details, so it needs a key of its own.
            $invoice = $client->createInvoice($params, $key.'-'.substr(sha1((string) json_encode($params)), 0, 8));
        }

        WooCommerceOrder::rememberInvoice($order, $invoice);

        $order->add_order_note(sprintf(
            /* translators: 1: invoice number, 2: hosted page URL */
            __('VeliraPay invoice %1$s created: %2$s', 'velirapay-woocommerce'),
            is_scalar($invoice['number'] ?? null) ? (string) $invoice['number'] : '',
            is_scalar($invoice['hosted_url'] ?? null) ? (string) $invoice['hosted_url'] : '',
        ));

        return $invoice;
    }

    /**
     * Get the invoice created for the order earlier, or nothing when it belongs to the other mode.
     *
     * @return array<string, mixed>
     *
     * @throws ApiException
     */
    private function existingInvoice(Client $client, string $code): array
    {
        try {
            return $client->retrieveInvoice($code);
        } catch (ApiException $exception) {
            if ($exception->isNotFound()) {
                return [];
            }

            throw $exception;
        }
    }

    /**
     * Void an invoice the order has outgrown, without letting a failure stop the new one.
     */
    private function voidQuietly(Client $client, string $code): void
    {
        try {
            $client->voidInvoice($code);
        } catch (ApiException $exception) {
            wc_get_logger()->warning(sprintf('Could not void the outdated invoice %s: %s', $code, $exception->getMessage()), ['source' => Plugin::LOG_SOURCE]);
        }
    }

    /**
     * Keep a posted value only when it, or each comma-separated item of it, has the expected prefix.
     */
    private function validatePrefixed(string $key, mixed $value, string $prefix, bool $list = false): string
    {
        $value = is_string($value) ? trim(stripslashes($value)) : '';
        $items = $list ? Signature::parseSecrets($value) : [$value];

        foreach ($items as $item) {
            if ($item !== '' && ! str_starts_with($item, $prefix)) {
                $field = $this->form_fields[$key] ?? null;
                $title = is_array($field) && is_string($field['title'] ?? null) ? $field['title'] : $key;

                // The settings screen shows these; a REST update of the settings has no screen to show them on.
                if (class_exists(WC_Admin_Settings::class)) {
                    WC_Admin_Settings::add_error(sprintf(
                        /* translators: 1: field name, 2: expected prefix */
                        __('%1$s was not saved: it must start with %2$s.', 'velirapay-woocommerce'),
                        $title,
                        $prefix,
                    ));
                }

                return $this->get_option($key);
            }
        }

        return $list ? implode(',', $items) : $value;
    }
}
