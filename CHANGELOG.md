# Changelog

All notable changes to this plugin are documented here. It follows [Semantic Versioning](https://semver.org).

## 0.4.10 - 2026-11-28

- When VeliraPay cannot say whether an earlier attempt to create an order's invoice went through, the plugin now also looks for that invoice among the paid ones. One that was paid in the meantime becomes the order's and completes it, instead of a second invoice asking the customer to pay again.
- An order remembers the mode its invoice was made in, and cancelling or trashing the order voids the invoice with that mode's key, at once or from WP-Cron. A store switched to the other mode since used to be told the invoice did not exist, which left it payable. Orders placed before this update are voided with the current mode's key, as before.

## 0.4.9 - 2026-11-24

- When VeliraPay cannot say whether an earlier attempt to create an order's invoice went through, which it now answers with a 409 for a day rather than risk a second invoice, the plugin looks for that invoice among the open ones, by the order's return addresses, memo, amount and currency, and takes it as the order's. Only when VeliraPay holds none does the checkout go on under a second idempotency key; when the open invoices cannot be read, or more than one fits, nothing is created and the order keeps a note.
- An `invoice.paid` that does not say how it was paid is refused with a 503 as well when the store has no API key to read the invoice with, instead of completing the order.
- News of an invoice whose order has since moved to another payment method neither completes nor cancels that order: a payment to it puts the order on hold with a note, as for a replaced invoice, and its void is ignored.
- An `invoice.voided` delivered after the invoice was paid no longer cancels the order held for you, whether it was held because you accepted the payment or because the order's total had changed.
- An `invoice.paid` that does not say how its charge became paid, as a retry of a delivery first made before 2026-11-24 does not, is checked against the invoice as VeliraPay has it now, so a short payment you accepted is held even then. While the invoice cannot be read the order is left as it is and the delivery answered with a 503, so VeliraPay sends it again later.

## 0.4.8 - 2026-11-24

- A webhook is only believed about the mode whose secret signed it: a live delivery signed with a test secret is refused with a 400, since a test secret is often shared with a staging site or a developer. Each endpoint's secret has to be in its own mode's field. A store with a live secret pasted into the test field, or the reverse, now refuses that mode's webhooks until the secret is moved; orders are still completed by the plugin's polling meanwhile.
- An invoice paid because you accepted a short or late payment in the VeliraPay dashboard is held on hold whatever order its webhooks arrive in, or when none arrive: VeliraPay now says on each of an invoice's charges when it was accepted (`accepted_at`), and the plugin reads that before its own record of earlier webhooks. A short payment the customer topped up completes the order even when the store never saw the top-up.

## 0.4.7 - 2026-11-20

- Orders with a billing name can pay even when the store has no email address for the customer.

## 0.4.6 - 2026-11-19

- Requests to VeliraPay are encoded with `wp_json_encode()`, and the webhook URL field on the settings page no longer carries an inline `onfocus` script.
- readme.txt says new versions come from WordPress.org.

## 0.4.5 - 2026-11-19

- What VeliraPay sends, webhook deliveries and API answers alike, is sanitized as soon as it is decoded, before it reaches an order note, the order's meta or a redirect: text with `sanitize_text_field()`, URLs with `esc_url_raw()`. A webhook's raw body is still checked against its signature as it came.

## 0.4.4 - 2026-11-17

- A detected payment is noted the same way in every coin: the Lightning-specific order note and the "BTC (Lightning)" label are gone, since VeliraPay no longer offers Bitcoin over Lightning.

## 0.4.3 - 2026-11-16

- The plugin's admin notices (a store currency VeliraPay does not bill in, a missing API key, an account that takes no coin, a key VeliraPay refused, and WooCommerce missing) show only on the Plugins screen and WooCommerce's settings and order lists, instead of on every admin page, as WordPress.org's guidelines ask.

## 0.4.2 - 2026-11-14

- The note on a late payment to a charge VeliraPay quoted again in the coin the customer sent says so first, such as "The customer sent USDT (Tron) instead of TRX, so VeliraPay quoted charge k3v9x2m7q8wz again in USDT (Tron): 60.52 USDT (Tron) due instead of 166.333334 TRX.", before what accepting the payment does. TRX and USDT on Tron share deposit addresses, and VeliraPay switches a charge that closed with none of its own coin while the other arrived, keeping the first quote in the charge's `switched_from`. The order is held, and handled once you accept the payment, as for any other late payment. Needs VeliraPay's `switched_from`, which an older API does not send.

## 0.4.1 - 2026-11-12

- The invoice's hosted page sends the customer back to the store: once they have paid it offers a button back to the order-received page, and until then a link back to the order's own payment page, to pay for it another way. The plugin sends both as the invoice's `success_url` and `cancel_url`, and leaves out an address VeliraPay would refuse, such as a store served over plain HTTP or on `localhost`, so checkout still works there. Needs VeliraPay's invoice return addresses, which an older API ignores.

## 0.4.0 - 2026-11-12

- A late payment to an expired, canceled or underpaid charge now holds an order still waiting for payment (pending, failed or on hold) instead of only noting it, so WooCommerce's **Hold stock** timer no longer cancels the order, and voids its invoice, while the money waits for you to accept it. A cancelled order still only gets the note.
- An invoice paid by accepting a late payment, or a short one that no top-up followed, puts the order on hold instead of marking it paid, since VeliraPay has you accept such a payment before refunding it as well as to keep it. The order and its notes say what to do next. A short payment the customer topped up in time still completes the order, at its next poll when the top-up is reported after the invoice was paid. Telling the two apart needs the `charge.underpaid`, `charge.payment_detected` and `charge.late_payment` webhooks, and a short payment made with test mode's **Simulate payment**, whose top-ups are never announced, is not held.
- A detected or short payment, or the payment of an invoice the order replaced, only adds a note to a cancelled order instead of putting it on hold. Its invoice being paid still brings it back, handled as a pending order would be, since WooCommerce's `payment_complete()` takes cancelled orders too: to **Processing** when the payment covered it in time, or **On hold** when the invoice was paid by accepting a short or late payment, or no longer covers the order. This happens when `invoice.paid` arrives or you choose **Check VeliraPay status**, since the hourly poll and the order-received page skip cancelled orders.
- The notes on short and late payments say what accepting them in the VeliraPay dashboard does to the order, and on a cancelled order that was never paid, that accepting brings it back on hold, where WooCommerce takes its stock again.
- The method is only offered when the total reaches the currency's lowest price (0.50 USD, EUR, GBP or CHF, 0.70 CAD, 0.80 AUD, 25.00 TRY), which VeliraPay refused an invoice under, and a coin the account takes can be paid for it, which the hosted page otherwise had none of. The coins on offer come from `GET /v1/assets`, remembered for ten minutes; while VeliraPay cannot be asked, the lowest price alone decides. The block checkout leaves the method out as the total drops below that amount, and an admin notice says so when the account takes no coin at all.
- WooCommerce's **Hold stock** timer no longer cancels an order whose invoice a customer is paying, inside the 30-minute rate lock, with a payment confirming or with a short payment, which voided the invoice and turned the payment into a late one. The plugin reads the invoice before the timer cancels an order, and applies it when it was paid. While VeliraPay cannot be reached, only orders younger than the **Hold stock** minutes plus 90 are kept.
- Replacing an outdated invoice no longer cancels the order the customer is paying. Its old invoice is set aside before the void, whose webhook could arrive before the new invoice was saved and cancel the order, and is the order's own again if it cannot be voided. When the new invoice cannot be created after the void, the order is left with no invoice instead of the voided one, which its webhook and polling took for the order's and cancelled it, and the next attempt creates the invoice under the same idempotency key.
- An order put on hold by a payment seen on chain that never confirmed goes back to **Pending payment** when VeliraPay closes the charge with no transfer left, instead of staying on hold indefinitely. An order held for any other reason as well stays on hold.
- When VeliraPay refuses the customer's email, whose domain cannot receive mail, or the amount, the checkout shows its message, such as "Crypto payment: Mail cannot be delivered to this address. Check how it is spelled.", instead of a general notice the customer could do nothing about.
- An order without a billing email, as one created in the admin may be, is made out to its customer's account email, and one with neither is refused at checkout before VeliraPay is asked, instead of sending VeliraPay an empty email.
- Voiding a cancelled order's invoice is tried again from WP-Cron, after 1, 5, 15 and 60 minutes, when VeliraPay could not be reached, was busy or failed, instead of leaving the invoice payable after one try; only the last failure is noted. When VeliraPay refuses the void because a short payment, or one still confirming, keeps the invoice payable, a note says so, and what the invoice being paid would do to the cancelled order.
- Trashing or deleting an order still pending, on hold or failed voids its invoice, from WP-Cron so the removal never waits on VeliraPay; before, the invoice stayed payable and a payment to it matched no order.
- API requests no longer follow redirects, which WordPress would have sent the API key along with to wherever they pointed; a redirect is taken as an error, as the other official libraries do.
- The settings page always shows the webhook URL with `https://`, which VeliraPay requires, and warns when the site's own address is still `http://`.
- A new API key is checked with VeliraPay when the settings are saved: one that is invalid or revoked, refused from the store's address, or without `invoices:read` is reported on the settings page, and saved anyway. A key VeliraPay refuses later, such as one without `invoices:write` at checkout, shows in an admin notice until a request needing the same permission succeeds, rather than only in the failed order's notes; listing the coins on offer, which needs none, never hides a refused invoice request.
- Order notes name a token, or a coin on another chain, by its ticker and network, such as "USDT (Tron)" or "ETH (Base)", instead of its code, and a Lightning payment is noted as final rather than waiting for confirmations.
- The plugin's folder and text domain are now `velirapay-for-woocommerce`, its slug on WordPress.org, which only takes a name with WooCommerce in it as "for WooCommerce", and the zip is `velirapay-for-woocommerce-<version>.zip`. The gateway, its settings, the order meta and the webhook URL stay as they were, so a copy installed from an earlier zip, in a `velirapay-woocommerce` folder, is replaced by deactivating and deleting it, then uploading this one, and its settings are kept.
- New versions come from WordPress.org once the plugin is listed there, like any other plugin's. Until then, a new version is installed by uploading its zip from the GitHub releases.
- WordPress's privacy policy guide suggests text saying what is sent to VeliraPay and which emails VeliraPay sends customers, and the readme lists the one external service the plugin calls, VeliraPay's API, with what it sends and when.
- The documentation says which emails VeliraPay sends customers despite `send_email: false`: a receipt, a short-payment notice and refund notices in live mode.
- A version tag only publishes the plugin zip once the tests, static analysis, code style and WordPress's Plugin Check pass, `Plugin::VERSION` agrees with the plugin header, readme.txt's stable tag and the tag, and the tagged commit is on `main`. The zip is then built and published by a job of its own that installs no dependencies, so nothing the tests install can change it or reach the release.
- Exception messages, the API's among them, are escaped, the webhook's signature header and the order key on the order-received page are unslashed and sanitized, saved API keys and webhook secrets are sanitized, and every PHP file stops unless WordPress loads it, so the plugin passes WordPress's Plugin Check. The signature header VeliraPay sends, `t=<unix time>,v1=<hex>` with one `v1` or two, comes through sanitizing unchanged.
- CI runs WordPress's Plugin Check, which WordPress.org reviews plugins with, on the plugin as the zip holds it, and fails on any error it reports.
- A version tag also deploys the plugin to WordPress.org, the same files as the zip with the listing's banners and icons from `.wordpress-org`, once the `SVN_USERNAME` and `SVN_PASSWORD` secrets are set. Until then, as while WordPress.org reviews the plugin, the deploy is skipped and the tag only publishes the GitHub release.
- Tested up to WooCommerce 11.3.

## 0.3.1 - 2026-11-06

- Retrying an order after changing modes or accounts no longer replaces its existing invoice.
- Delayed events preserve refunded orders and orders with a recorded payment date.
- Pending-order polling continues beyond fifty orders in batches at least a minute apart.
- Settings explain webhook verification; documentation covers invoice idempotency and mode changes.

## 0.3.0 - 2026-11-04

- A `payout.sent` or `payout.confirmed` webhook adds a note saying your share of the charge went out or reached your wallet, on an endpoint subscribed to them. Neither changes the order, and they are not among the events the plugin asks you to tick.

## 0.2.0 - 2026-11-03

- The webhook receiver answers the `webhook.verification` challenge VeliraPay now sends an endpoint when it is added or its URL changes, which it must answer before it receives anything else.
- A paid invoice only completes an order it still covers: one for a different amount or currency than the order now comes to, after the order was edited, puts it on hold with a note instead of marking it paid.
- Paying again for an order whose invoice was voided, or whose total changed and changed back, no longer sends the customer to the voided invoice: each new invoice is created under a key of its own rather than one a replay could answer with an old invoice.
- An outdated invoice that already has a payment on its way is kept, with a note, instead of being replaced by one the payment would never reach; one that cannot be voided for another reason stops the checkout rather than staying payable beside the new one.
- The same checkout submitted twice waits for the first request's invoice instead of creating a second one.
- An order remembers the invoices it replaced. A payment that still reaches one, after it was voided, is no longer ignored: a paid replaced invoice puts the order on hold (or, on an order already paid, notes that the customer paid twice), and a payment detected, short or late on one is noted.

## 0.1.0 - 2026-10-13

First release.

- A payment method, on the classic and the block checkout, that turns each order into a VeliraPay invoice and sends the customer to its hosted page to pick a coin and pay.
- A webhook receiver at the WooCommerce API URL that checks signatures against the live and test secrets, ignores test deliveries and the other mode's, and applies each event once: paid invoices complete payment, detected and short payments put the order on hold, voided invoices cancel it, and everything else is noted on the order.
- The same status mapping applied by polling: on the order-received page, from the "Check VeliraPay status" order action, and every hour for orders younger than three days that still wait for payment.
- Invoices voided when their order is cancelled.
- Live and test API keys and webhook secrets, checked for their prefixes when saved, and admin notices for an unsupported store currency or a missing key.
- High-Performance Order Storage and block checkout compatibility.
