=== VeliraPay for WooCommerce ===
Contributors: velirapay
Tags: crypto, bitcoin, ethereum, payments, woocommerce
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.1
WC requires at least: 8.3
WC tested up to: 11.2
Stable tag: 0.3.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Take Bitcoin, Ethereum, USDC and other coins at checkout. Each order becomes a VeliraPay invoice the customer pays on a hosted page.

== Description ==

[VeliraPay](https://velirapay.com) is a crypto payment processor. This plugin adds it as a payment method to WooCommerce, on the classic and the block checkout.

When a customer picks it, the order becomes a VeliraPay invoice for the order total, in the store currency, and the customer is sent to the invoice's hosted page. There they choose a coin, the exchange rate is locked for 30 minutes, and they send the payment. Once it confirms on-chain, VeliraPay tells the store through a signed webhook and the order is marked paid, exactly as it would be after a card payment.

* Every coin VeliraPay takes, chosen by the customer at the moment of payment.
* Orders priced in USD, EUR, GBP, CAD, AUD, CHF or TRY, the store's own currency.
* Webhooks checked against the endpoint's signing secret, each event applied once, with the store's test and live modes kept apart.
* A fallback that polls VeliraPay on the order-received page, from an order action, and every hour, so a missed webhook never leaves a paid order pending.
* Order notes for everything VeliraPay reports: payments seen on chain, underpayments, expired quotes, late payments and refunds.
* High-Performance Order Storage and block checkout support.

Refunds are not automated. Crypto cannot be pulled back: send the coins from your own wallet and record the refund in the VeliraPay dashboard, which notes it on the order.

== Installation ==

1. Install and activate the plugin. WooCommerce 8.3 or later must be active.
2. In the VeliraPay dashboard, under Developers → API keys, create an API key. The plugin only creates, reads and voids invoices, so a restricted key with Read invoices and Create invoices is all it needs. A key the store already uses can be restricted the same way with the pencil next to it on that page, with no need to paste it again.
3. Go to WooCommerce → Settings → Payments → VeliraPay and paste the key into the live or test field.
4. Under Developers → Webhooks in the dashboard, add an endpoint with the webhook URL shown on the settings page (VeliraPay only delivers to an https:// address on port 443 that answers within 15 seconds without redirecting) and tick the invoice.paid, invoice.voided, charge.payment_detected, charge.underpaid, charge.expired, charge.late_payment and charge.refunded events. Copy its signing secret into the settings page.
5. Enable the gateway and save.
6. Back on the endpoint's page in the dashboard, press Verify. The plugin answers the challenge VeliraPay sends, and the endpoint receives nothing until it has.

Live and test mode each need their own key and endpoint: switch the dashboard to test mode to create the test ones, then turn on "Test mode" in the settings to use them.

== Frequently Asked Questions ==

= Which currencies can the store use? =

USD, EUR, GBP, CAD, AUD, CHF and TRY. With any other store currency the gateway is not offered at checkout and a notice says so.

= Is there a smallest order? =

Yes. VeliraPay takes no payment under the currency's lowest price, about half a dollar: 0.50 USD, EUR, GBP or CHF, 0.70 CAD, 0.80 AUD or 25.00 TRY. Below it, or when no coin your account takes can be paid for the total, the method is not offered at checkout.

= Which coins can customers pay in? =

Every coin your VeliraPay account takes, which needs a payout address for its network under Payment settings in the dashboard. USDT and USDC on Ethereum start at 10 of the token, so a smaller order simply does not offer them. See https://velirapay.com/docs/assets for the list.

= What happens to the order while the customer pays? =

It stays "Pending payment" with the invoice's number and link in its notes. When a payment is seen on chain it goes "On hold", and when the payment confirms it goes "Processing" or "Completed", as any paid order would. If the customer never pays, it stays pending; WooCommerce's "Hold stock" setting cancels pending orders after the minutes you set, which also voids the invoice. An order whose invoice is being paid, with a coin picked and its rate still locked, a payment confirming or a short payment, is not cancelled by that timer.

= Does cancelling an order stop the payment? =

Cancelling an order voids its invoice, so the hosted page no longer takes payment. An invoice with a payment already on its way cannot be voided. A cancelled order is never brought back on its own: if its invoice is paid after all, the order stays cancelled and a note says so, for you to move it to "Processing" or refund the payment.

= What if the customer pays too little, or too late? =

The order goes "On hold" with a note. The customer can still send the rest of a short payment on the invoice page, which completes the order as usual. A short payment you accept in the VeliraPay dashboard, or a late one, which has to be accepted there whether you keep it or refund it, leaves the order on hold rather than marking it paid: move it to "Processing" to fulfil it, or cancel it once the payment is refunded. This relies on the charge.underpaid, charge.payment_detected and charge.late_payment webhooks: without them, a paid invoice marks the order paid.

= What if a webhook never arrives? =

The plugin asks VeliraPay for the invoice's state when the customer opens the order-received page, when you choose "Check VeliraPay status" on the order's edit screen, and every hour for orders younger than three days that still wait for payment.

Hourly polling works through every eligible order in batches of up to 50, at least a minute apart. WP-Cron needs site traffic or a server cron job to run the batches.

= Can I test without real coins? =

Yes. Turn on test mode and use a test API key and a test endpoint: payments then run on the test networks, and a test charge's page in the VeliraPay dashboard has a "Simulate payment" button.

An order with an existing invoice keeps that invoice when you switch modes or accounts. To retry or check such an order, restore the original mode and account's API key; a live invoice is never replaced with a test invoice.

= How are refunds handled? =

By hand. Send the coins back from your wallet and record the refund in the VeliraPay dashboard; the plugin adds a note to the order.

== Changelog ==

= 0.3.1 =
* Retrying an order after changing modes or accounts no longer replaces its existing invoice.
* Delayed events leave refunded orders and orders with a recorded payment date alone.
* Pending-order polling continues beyond fifty orders, in batches at least a minute apart.
* The settings explain that an endpoint receives nothing until it is verified.

= 0.3.0 =
* A payout.sent or payout.confirmed webhook adds a note saying your share of the charge went out or reached your wallet, on an endpoint subscribed to them.

= 0.2.0 =
* The webhook receiver answers the verification challenge an endpoint has to pass before it receives anything.
* A paid invoice only completes an order it still covers; one for a different amount or currency puts the order on hold with a note.
* Paying again for an order whose invoice was voided or outdated no longer reaches that invoice, and an outdated invoice with a payment on its way is kept rather than replaced.
* A payment that reaches an invoice the order replaced is noted, and puts the order on hold.

= 0.1.0 =
* First release: the payment method, the webhook receiver, the status sync and the settings page.
