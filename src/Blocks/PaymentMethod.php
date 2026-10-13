<?php

declare(strict_types=1);

namespace VeliraPay\WooCommerce\Blocks;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;
use VeliraPay\WooCommerce\Gateway;
use VeliraPay\WooCommerce\Plugin;

/**
 * Shows the gateway on the block checkout.
 */
final class PaymentMethod extends AbstractPaymentMethodType
{
    /**
     * The script handle of the block checkout integration.
     */
    private const SCRIPT_HANDLE = 'velirapay-blocks';

    /**
     * The payment method's name, which is the gateway's id.
     *
     * @var string
     */
    protected $name = Gateway::ID;

    /**
     * Load the gateway's settings.
     */
    public function initialize(): void
    {
        $settings = get_option('woocommerce_'.Gateway::ID.'_settings', []);

        $this->settings = is_array($settings) ? $settings : [];
    }

    /**
     * Determine if the gateway can be offered right now.
     */
    public function is_active(): bool
    {
        return Plugin::gateway()->is_available();
    }

    /**
     * Register the script that adds the method to the checkout block.
     *
     * @return list<string>
     */
    public function get_payment_method_script_handles(): array
    {
        $src = Plugin::url('assets/js/blocks.js');

        if ($src === '') {
            return [];
        }

        wp_register_script(
            self::SCRIPT_HANDLE,
            $src,
            ['wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities', 'wp-i18n'],
            Plugin::VERSION,
            true,
        );

        return [self::SCRIPT_HANDLE];
    }

    /**
     * Get what the script shows: the title, description and icon from the settings.
     *
     * @return array<string, mixed>
     */
    public function get_payment_method_data(): array
    {
        $gateway = Plugin::gateway();

        return [
            'title' => $gateway->get_title(),
            'description' => $gateway->get_description(),
            'icon' => Plugin::url('assets/icon.svg'),
            'supports' => ['products'],
        ];
    }
}
