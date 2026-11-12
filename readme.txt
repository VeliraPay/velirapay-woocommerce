=== VeliraPay for WooCommerce ===
Contributors: velirapay
Tags: crypto, bitcoin, ethereum, payments, woocommerce
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.1
WC requires at least: 8.3
WC tested up to: 11.3
Stable tag: 0.4.0
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

== External services ==

This plugin connects to VeliraPay's API, https://api.velirapay.com, the crypto payment processor it adds to the store, to take payments:

* When a customer pays an order, it creates a VeliraPay invoice for it, sending the customer's billing name and email address, the order total and currency, a memo with the order number and the store's name, and the addresses of the order's order-received and payment pages, for the hosted page to send the customer back to.
* On the store's pages and the block checkout, while a customer has something in the cart or pays for an existing order, it reads which coins the store's VeliraPay account takes in the store currency, at most once every ten minutes, sending nothing about the customer or the cart.
* It reads the order's invoice when the customer opens the order-received page, when you choose "Check VeliraPay status", every hour for orders waiting for payment, and before WooCommerce's "Hold stock" timer cancels an order.
* It voids the order's invoice when the order is cancelled, trashed or deleted.
* When you save an API key, it reads the account and its invoices to check the key.

The customer then pays on VeliraPay's hosted invoice page, and VeliraPay sends the store signed webhooks about the invoice. VeliraPay's terms of service: https://velirapay.com/terms. Its privacy policy: https://velirapay.com/privacy.

The plugin calls no other service.

== Installation ==

1. Under Plugins → Add New Plugin, search for "VeliraPay for WooCommerce", install it and activate it. WooCommerce 8.3 or later must be active.
2. In the VeliraPay dashboard, under Developers → API keys, create an API key. The plugin only creates, reads and voids invoices, so a restricted key with Read invoices and Create invoices is all it needs. A key the store already uses can be restricted the same way with the pencil next to it on that page, with no need to paste it again.
3. Go to WooCommerce → Settings → Payments → VeliraPay and paste the key into the live or test field. A new key is checked with VeliraPay when you save, and a key VeliraPay refuses later, at checkout or while polling, is reported in an admin notice.
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

Cancelling an order voids its invoice, so the hosted page no longer takes payment. An invoice with a payment already on its way cannot be voided. If that invoice is paid after all, the order is brought back as a pending one would be, once VeliraPay's invoice.paid webhook arrives or you choose "Check VeliraPay status" (the hourly check and the order-received page skip cancelled orders): it goes "Processing", or "On hold" when the invoice was paid by accepting a short or late payment, and WooCommerce takes its stock again. Until then, news of the payment only adds notes to the cancelled order.

= What if the customer pays too little, or too late? =

The order goes "On hold" with a note. The customer can still send the rest of a short payment on the invoice page, which completes the order as usual. A short payment you accept in the VeliraPay dashboard, or a late one, which has to be accepted there whether you keep it or refund it, leaves the order on hold rather than marking it paid: move it to "Processing" to fulfil it, or cancel it once the payment is refunded. This relies on the charge.underpaid, charge.payment_detected and charge.late_payment webhooks: without them, a paid invoice marks the order paid.

= What if a webhook never arrives? =

The plugin asks VeliraPay for the invoice's state when the customer opens the order-received page, when you choose "Check VeliraPay status" on the order's edit screen, and every hour for orders younger than three days that still wait for payment.

Hourly polling works through every eligible order in batches of up to 50, at least a minute apart. WP-Cron needs site traffic or a server cron job to run the batches.

= Can I test without real coins? =

Yes. Turn on test mode and use a test API key and a test endpoint: payments then run on the test networks, and a test charge's page in the VeliraPay dashboard has a "Simulate payment" button.

An order with an existing invoice keeps that invoice when you switch modes or accounts. To retry or check such an order, restore the original mode and account's API key; a live invoice is never replaced with a test invoice.

= Does VeliraPay email my customers? =

The invoice itself is not emailed: the customer is sent straight to its page. In live mode, VeliraPay still emails the billing address, on the store's behalf and alongside WooCommerce's own emails, a receipt when the payment confirms, a notice when it falls short, and a notice of each refund you record. In test mode it only emails addresses of your VeliraPay account's own members.

= How is the plugin updated? =

From WordPress.org, once the plugin is listed there: WordPress then offers each new version on its Plugins and Updates screens like any other plugin's. Until then, a new version is installed by uploading its zip from https://github.com/VeliraPay/velirapay-woocommerce/releases under Plugins → Add New Plugin → Upload Plugin.

= How are refunds handled? =

By hand. Send the coins back from your wallet and record the refund in the VeliraPay dashboard; the plugin adds a note to the order.

== Changelog ==

= 0.4.0 =
* A late payment puts an order still waiting for payment on hold, so the "Hold stock" timer no longer cancels it and voids its invoice meanwhile.
* An invoice paid by accepting a late payment, or a short one no top-up followed, holds the order instead of marking it paid. A short payment topped up in time still completes it.
* A detected or short payment only adds a note to a cancelled order. Its invoice being paid still brings it back, on hold when you accepted a short or late payment.
* The "Hold stock" timer no longer cancels an order whose invoice is being paid.
* The method is only offered for totals VeliraPay takes: the currency's lowest price, and a coin the account takes for that amount.
* Replacing an outdated invoice can no longer cancel the order, even when the new invoice cannot be created.
* An order held for a payment that never confirmed goes back to pending payment.
* The checkout shows why VeliraPay refused the customer's email or the amount, and an order without an email uses the customer's account email.
* Voiding a cancelled order's invoice is retried when VeliraPay cannot be reached, and a trashed or deleted unpaid order's invoice is voided too.
* API requests never follow redirects, the webhook URL is always shown with https://, and a new API key is checked with VeliraPay when saved.
* Order notes name the coin's network, and a Lightning payment is noted as final.
* The plugin's folder and text domain are now velirapay-for-woocommerce, its slug on WordPress.org, where new versions come from once it is listed there.
* Exception messages are escaped, and request input and saved keys are sanitized.
* The privacy policy guide suggests text about VeliraPay.
* Tested up to WooCommerce 11.3.

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

== Upgrade Notice ==

= 0.4.0 =
Orders paid by accepting a short or late payment now wait on hold for you. The plugin's folder is now velirapay-for-woocommerce: deactivate and delete a copy installed from an earlier zip before uploading this one. Its settings are kept.
