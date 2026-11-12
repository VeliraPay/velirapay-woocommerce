<?php

declare(strict_types=1);

namespace VeliraPay\WooCommerce;

use InvalidArgumentException;
use VeliraPay\WooCommerce\Api\ApiException;
use VeliraPay\WooCommerce\Api\Client;
use VeliraPay\WooCommerce\Orders\StatusSync;
use VeliraPay\WooCommerce\Orders\WooCommerceOrder;
use VeliraPay\WooCommerce\Webhooks\Signature;
use WC_Admin_Settings;
use WC_Cart;
use WC_Order;
use WC_Payment_Gateway;
use WP_User;

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
     * The lowest price VeliraPay takes in each currency, about half a dollar, below which a payment is mostly network fees.
     */
    public const LOWEST_PRICES = ['USD' => '0.50', 'EUR' => '0.50', 'GBP' => '0.50', 'CAD' => '0.70', 'AUD' => '0.80', 'CHF' => '0.50', 'TRY' => '25.00'];

    /**
     * The prefix of an endpoint's signing secret.
     */
    private const SECRET_PREFIX = 'whsec_';

    /**
     * How many seconds the coins on offer are remembered for.
     */
    private const ASSETS_CACHE_SECONDS = 600;

    /**
     * How many seconds a failure to read the coins on offer is remembered for, falling back on the lowest prices meanwhile.
     */
    private const ASSETS_FAILURE_SECONDS = 180;

    /**
     * How many seconds a checkout waits for the coins on offer.
     */
    private const ASSETS_TIMEOUT = 3;

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
            __('Save these settings, then press Verify on the endpoint in the VeliraPay dashboard. Deliveries start only after verification succeeds.', 'velirapay-woocommerce'),
        ];

        $html = '<tr valign="top"><th scope="row" class="titledesc"><label for="'.esc_attr($this->get_field_key($key)).'">'.esc_html($title).'</label></th>';
        $html .= '<td class="forminp"><fieldset><legend class="screen-reader-text"><span>'.esc_html($title).'</span></legend>';
        $html .= '<input class="input-text regular-input" type="text" readonly id="'.esc_attr($this->get_field_key($key)).'" value="'.esc_attr($this->webhookUrl()).'" onfocus="this.select()" />';

        if (wp_parse_url(home_url(), PHP_URL_SCHEME) !== 'https') {
            $html .= '<p class="description" style="color:#b32d2e">'.esc_html__('This site\'s address starts with http://. VeliraPay only sends webhooks to https:// addresses on port 443, so the site has to answer on HTTPS at the URL above, without redirecting.', 'velirapay-woocommerce').'</p>';
        }

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
     * Keep a live key only when it has the live prefix, and check a new one with VeliraPay.
     */
    public function validate_live_api_key_field(string $key, mixed $value): string
    {
        return $this->validateKey($key, $value, Client::LIVE_PREFIX);
    }

    /**
     * Keep a test key only when it has the test prefix, and check a new one with VeliraPay.
     */
    public function validate_test_api_key_field(string $key, mixed $value): string
    {
        return $this->validateKey($key, $value, Client::TEST_PREFIX);
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
     * Offer the gateway only when it can create invoices for the store's currency, and, at checkout, when the total
     * reaches the currency's lowest price and a coin the account takes can pay it.
     */
    public function is_available(): bool
    {
        if (! parent::is_available() || $this->apiKey() === '' || ! in_array(get_woocommerce_currency(), self::CURRENCIES, true)) {
            return false;
        }

        $due = $this->amountDue();

        if ($due === null || self::cents($due['amount']) <= 0) {
            return true;
        }

        $lowest = $this->lowestPayableAmount($due['currency']);

        return $lowest !== null && self::cents($due['amount']) >= self::cents($lowest);
    }

    /**
     * Get the smallest total a customer can pay in a currency, such as "0.50": its lowest price, raised to the cheapest
     * coin the account takes when VeliraPay can say, or null when it bills in no such currency or the account takes no coin.
     */
    public function lowestPayableAmount(string $currency): ?string
    {
        $lowest = self::LOWEST_PRICES[$currency] ?? null;

        if ($lowest === null) {
            return null;
        }

        $assets = $this->assetsFor($currency);

        if ($assets === null) {
            return $lowest;
        }

        $cheapest = null;

        foreach ($assets as $asset) {
            if ($asset['accepted'] === false) {
                continue;
            }

            // A coin with a minimum of its own has no price while it cannot be priced, which is no reason to hide it.
            $price = max(self::cents($lowest), $asset['minimum_price'] === null ? 0 : self::cents($asset['minimum_price']));
            $cheapest = $cheapest === null ? $price : min($cheapest, $price);
        }

        return $cheapest === null ? null : number_format($cheapest / 100, 2, '.', '');
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
        } catch (InvalidArgumentException $exception) {
            wc_add_notice(self::customerNotice($exception->getMessage()), 'error');

            return ['result' => 'failure'];
        } catch (ApiException $exception) {
            wc_get_logger()->error(sprintf('Could not create an invoice for order #%s: %s', $order->get_order_number(), $exception->getMessage()), ['source' => Plugin::LOG_SOURCE]);
            $order->add_order_note(sprintf(
                /* translators: %s: error message */
                __('VeliraPay could not create an invoice: %s', 'velirapay-woocommerce'),
                $exception->getMessage(),
            ));
            wc_add_notice(self::refusalNotice($exception), 'error');

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
     * Get the URL VeliraPay posts webhooks to, always on https, which VeliraPay requires whatever the site's address says.
     */
    public function webhookUrl(): string
    {
        return WC()->api_request_url(self::ID, true);
    }

    /**
     * Get what the customer is about to pay: the order on the order-pay page, the cart at checkout, or null anywhere else.
     *
     * @return array{amount: string, currency: string}|null
     */
    private function amountDue(): ?array
    {
        $orderPay = get_query_var('order-pay');
        $orderId = is_numeric($orderPay) ? (int) $orderPay : 0;

        if ($orderId > 0) {
            $order = wc_get_order($orderId);

            return $order instanceof WC_Order ? ['amount' => (string) $order->get_total(), 'currency' => $order->get_currency()] : null;
        }

        // WooCommerce only loads a cart on the storefront, whatever its property's type says: not in the admin, REST or cron.
        $cart = get_object_vars(WC())['cart'] ?? null;

        return $cart instanceof WC_Cart ? ['amount' => (string) $cart->get_total('edit'), 'currency' => get_woocommerce_currency()] : null;
    }

    /**
     * Get the coins on offer in a currency, whether the account takes each and the lowest price each can be paid at,
     * remembered for ten minutes, or null while VeliraPay cannot be asked.
     *
     * @return list<array{accepted: bool|null, minimum_price: string|null}>|null
     */
    private function assetsFor(string $currency): ?array
    {
        $client = $this->client();

        if ($client === null) {
            return null;
        }

        $cacheKey = $this->assetsCacheKey($currency);
        $cached = get_transient($cacheKey);

        if ($cached === 'unavailable') {
            return null;
        }

        if (is_array($cached)) {
            return self::payableCoins($cached);
        }

        try {
            $assets = self::payableCoins($client->listAssets($currency, self::ASSETS_TIMEOUT));
        } catch (ApiException) {
            set_transient($cacheKey, 'unavailable', self::ASSETS_FAILURE_SECONDS);

            return null;
        }

        set_transient($cacheKey, $assets, self::ASSETS_CACHE_SECONDS);

        return $assets;
    }

    /**
     * Determine if VeliraPay last told the checkout that the account takes no coin at all in a currency, from what the
     * checkout remembered, without asking again.
     */
    public function takesNoCoin(string $currency): bool
    {
        $cached = $this->apiKey() === '' ? false : get_transient($this->assetsCacheKey($currency));

        if (! is_array($cached)) {
            return false;
        }

        foreach (self::payableCoins($cached) as $coin) {
            if ($coin['accepted'] !== false) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get the transient the coins on offer in a currency are remembered in, keyed by the key itself, since which coins
     * are taken depends on its account.
     */
    private function assetsCacheKey(string $currency): string
    {
        return 'velirapay_assets_'.substr(hash('sha256', $this->apiKey()), 0, 16).'_'.$currency;
    }

    /**
     * Keep what a checkout needs to know of each coin: whether the account takes it, and the lowest price it can be paid at.
     *
     * @param  array<array-key, mixed>  $assets
     * @return list<array{accepted: bool|null, minimum_price: string|null}>
     */
    private static function payableCoins(array $assets): array
    {
        $coins = [];

        foreach ($assets as $asset) {
            if (is_array($asset)) {
                $minimum = $asset['minimum_price'] ?? null;

                $coins[] = [
                    'accepted' => is_bool($asset['accepted'] ?? null) ? $asset['accepted'] : null,
                    'minimum_price' => is_string($minimum) && is_numeric($minimum) ? $minimum : null,
                ];
            }
        }

        return $coins;
    }

    /**
     * Get an amount in hundredths, such as 4990 for "49.90".
     */
    private static function cents(string $amount): int
    {
        return is_numeric($amount) ? (int) round((float) $amount * 100) : 0;
    }

    /**
     * Get the order's open invoice, or create one.
     *
     * @return array<string, mixed>
     *
     * @throws ApiException
     * @throws InvalidArgumentException when a new invoice is needed and the order has no email to make it out to.
     */
    private function invoiceFor(WC_Order $order, Client $client): array
    {
        $amount = wc_format_decimal($order->get_total(), 2);
        $currency = $order->get_currency();
        $code = WooCommerceOrder::invoiceCodeOf($order);
        $existing = $code !== '' ? $client->retrieveInvoice($code) : null;
        $status = $existing['status'] ?? null;

        if ($existing !== null && $status === 'paid') {
            (new StatusSync)->applyInvoice(new WooCommerceOrder($order), $existing);

            return $existing;
        }

        if ($existing !== null && $status === 'open' && ($existing['amount'] ?? null) === $amount && ($existing['currency'] ?? null) === $currency) {
            return $existing;
        }

        $email = $this->customerEmail($order);

        // Checked before an outdated invoice is voided, so the customer can add one and still pay it.
        if ($email === '') {
            throw new InvalidArgumentException(__('an email address is needed to pay. Please enter your billing email address.', 'velirapay-woocommerce'));
        }

        if ($existing !== null && $status === 'open' && ! $this->voidOutdated($client, $order, $code, $existing)) {
            return $existing;
        }

        // An invoice that can no longer be paid is set aside before it is replaced, as an outdated one is before its void.
        if ($existing !== null && $status !== 'open') {
            WooCommerceOrder::setInvoiceAside($order, $code);
        }

        // The invoice the new one replaces: the current one, or the one a replacement that failed to be created set aside.
        $superseded = WooCommerceOrder::supersededInvoiceCodesOf($order);
        $replaced = $code !== '' ? $code : ($superseded === [] ? '' : $superseded[array_key_last($superseded)]);

        $name = trim($order->get_formatted_billing_full_name());

        $params = [
            'customer_name' => mb_substr($name !== '' ? $name : __('Customer', 'velirapay-woocommerce'), 0, 255),
            'customer_email' => $email,
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

        // Keyed by the invoice it replaces as well as its details, so a replay can never hand back an invoice voided since,
        // and a retry after a creation whose answer was lost gets that invoice rather than a second one.
        $key = sprintf('woocommerce-order-%d-%s-%s', $order->get_id(), $order->get_order_key(), substr(sha1($replaced."\n".(string) json_encode($params)), 0, 16));

        $invoice = $this->createInvoice($client, $params, $key);

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
     * Get the email the invoice is made out to: the billing email, or, for an order without one, as one created in the
     * admin may be, the account email of the customer it is for.
     */
    private function customerEmail(WC_Order $order): string
    {
        $email = trim($order->get_billing_email());

        if ($email === '' && $order->get_customer_id() > 0) {
            $user = get_userdata($order->get_customer_id());
            $email = $user instanceof WP_User ? trim($user->user_email) : '';
        }

        return $email;
    }

    /**
     * Get what the customer is told when the invoice could not be created: what VeliraPay said about their email or the
     * amount, which they can do something about, or a general notice for anything else.
     */
    private static function refusalNotice(ApiException $exception): string
    {
        if ($exception->status === 422) {
            foreach (['customer_email', 'amount'] as $field) {
                $message = $exception->errors[$field][0] ?? '';

                if ($message !== '') {
                    return self::customerNotice($message);
                }
            }
        }

        return __('The crypto payment could not be started. Please try again or choose another payment method.', 'velirapay-woocommerce');
    }

    /**
     * Word a message for the customer at checkout, escaped.
     */
    private static function customerNotice(string $message): string
    {
        return sprintf(
            /* translators: %s: what stopped the payment, such as "Mail cannot be delivered to this address." */
            __('Crypto payment: %s', 'velirapay-woocommerce'),
            esc_html($message),
        );
    }

    /**
     * Create the invoice, waiting out the same checkout submitted twice, whose first request still holds the key.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     *
     * @throws ApiException
     */
    private function createInvoice(Client $client, array $params, string $key): array
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return $client->createInvoice($params, $key);
            } catch (ApiException $exception) {
                if (! $exception->isConflict() || $attempt >= 3) {
                    throw $exception;
                }

                sleep(1);
            }
        }
    }

    /**
     * Void an invoice the order has outgrown, returning false when it must be kept because a payment to it is on its way.
     *
     * It is set aside first, since VeliraPay announces the void at once, before the new invoice is remembered; one that
     * could not be voided is the order's own again. Once voided it stays aside, even if no new invoice can be created.
     *
     * @param  array<string, mixed>  $invoice  The invoice as the API last described it.
     *
     * @throws ApiException when it could not be voided for another reason, so it is not left payable beside a new one.
     */
    private function voidOutdated(Client $client, WC_Order $order, string $code, array $invoice): bool
    {
        WooCommerceOrder::setInvoiceAside($order, $code);

        try {
            $client->voidInvoice($code);

            return true;
        } catch (ApiException $exception) {
            WooCommerceOrder::restoreInvoice($order, $code, is_scalar($invoice['hosted_url'] ?? null) ? (string) $invoice['hosted_url'] : '');

            if (! $exception->isConflict()) {
                throw $exception;
            }
        }

        $order->add_order_note(sprintf(
            /* translators: 1: invoice code, 2: order total and currency */
            __('The order now comes to %2$s, but VeliraPay invoice %1$s already has a payment on its way, so it was kept rather than replaced. Check the amount once it is paid.', 'velirapay-woocommerce'),
            $code,
            wc_format_decimal($order->get_total(), 2).' '.$order->get_currency(),
        ));

        return false;
    }

    /**
     * Keep a posted key only when it has its mode's prefix, and ask VeliraPay about a new one. The key is saved whatever
     * VeliraPay answers, with an error on the settings page when it refuses the key.
     */
    private function validateKey(string $key, mixed $value, string $prefix): string
    {
        $saved = $this->get_option($key);
        $apiKey = $this->validatePrefixed($key, $value, $prefix);

        if ($apiKey !== '' && $apiKey !== trim($saved)) {
            Plugin::forgetRefusals((string) Client::modeOf($apiKey));
            $this->checkKey($key, $apiKey);
        }

        return $apiKey;
    }

    /**
     * Ask VeliraPay about a key: the account, which needs no permission, tells a valid key from a revoked one or one
     * refused from this site's address, and the invoices list needs invoices:read. Whether it may create invoices only
     * shows when the checkout first tries, which the admin notices then report. A VeliraPay that cannot be reached is
     * not taken as a refusal.
     */
    private function checkKey(string $key, string $apiKey): void
    {
        $client = new Client($apiKey, Plugin::httpTransport(), Plugin::userAgent());

        try {
            $client->request('GET', 'account');
            $client->request('GET', 'invoices?per_page=1');
        } catch (ApiException $exception) {
            if ($exception->status === 0 || $exception->status === 429 || $exception->status >= 500) {
                return;
            }

            $this->settingsError(sprintf(
                /* translators: 1: field name, 2: VeliraPay's message */
                __('VeliraPay refused the %1$s: %2$s It was saved anyway; check it under Developers → API keys in the VeliraPay dashboard.', 'velirapay-woocommerce'),
                $this->fieldTitle($key),
                $exception->getMessage(),
            ));
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
                $this->settingsError(sprintf(
                    /* translators: 1: field name, 2: expected prefix */
                    __('%1$s was not saved: it must start with %2$s.', 'velirapay-woocommerce'),
                    $this->fieldTitle($key),
                    $prefix,
                ));

                return $this->get_option($key);
            }
        }

        return $list ? implode(',', $items) : $value;
    }

    /**
     * Get a settings field's title, such as "Live API key".
     */
    private function fieldTitle(string $key): string
    {
        $field = $this->form_fields[$key] ?? null;

        return is_array($field) && is_string($field['title'] ?? null) ? $field['title'] : $key;
    }

    /**
     * Show an error on the settings screen; a REST update of the settings has no screen to show it on.
     */
    private function settingsError(string $message): void
    {
        if (class_exists(WC_Admin_Settings::class)) {
            WC_Admin_Settings::add_error($message);
        }
    }
}
