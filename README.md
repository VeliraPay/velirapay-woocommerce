# VeliraPay for WooCommerce

[![Tests](https://github.com/VeliraPay/velirapay-woocommerce/actions/workflows/tests.yml/badge.svg)](https://github.com/VeliraPay/velirapay-woocommerce/actions/workflows/tests.yml)
[![License](https://img.shields.io/badge/license-GPL--2.0--or--later-blue)](LICENSE)

The official WooCommerce plugin for [VeliraPay](https://velirapay.com), the crypto payment processor. It adds a payment method that turns each order into a VeliraPay invoice, sends the customer to the invoice's hosted page to pick a coin and pay, and marks the order paid once the payment confirms.

- [Requirements](#requirements)
- [Installation](#installation)
- [Setup](#setup)
- [How orders map to invoices](#how-orders-map-to-invoices)
- [How statuses map](#how-statuses-map)
- [Webhooks](#webhooks)
- [Polling](#polling)
- [Test mode](#test-mode)
- [Refunds](#refunds)
- [Emails to customers](#emails-to-customers)
- [Privacy and external services](#privacy-and-external-services)
- [Development](#development)
- [Releases](#releases)

## Requirements

- WordPress 6.4 or later
- WooCommerce 8.3 or later, with the classic or the block checkout, on the posts table or on High-Performance Order Storage
- PHP 8.1 or later
- A store currency VeliraPay can bill in: USD, EUR, GBP, CAD, AUD, CHF or TRY. VeliraPay takes no payment under the currency's lowest price, about half a dollar: 0.50 USD, EUR, GBP or CHF, 0.70 CAD, 0.80 AUD or 25.00 TRY.

## Installation

Once the plugin is listed on WordPress.org, search for **VeliraPay for WooCommerce** under **Plugins → Add New Plugin**, install it and activate it. Until then, download `velirapay-for-woocommerce-<version>.zip` from the [releases](https://github.com/VeliraPay/velirapay-woocommerce/releases) and upload it under **Plugins → Add New Plugin → Upload Plugin**, or unzip it into `wp-content/plugins/`. The plugin has no runtime dependencies and needs no `composer install`.

New versions come from WordPress.org once the plugin is listed there, on WordPress's **Plugins** and **Updates** screens like any other plugin's. The zip unpacks to `velirapay-for-woocommerce/`, the plugin's slug on WordPress.org, so a copy installed from it gets those updates too. Until the plugin is listed, a new version is installed by uploading its zip. A copy installed from a zip of 0.3.1 or earlier sits in a `velirapay-woocommerce` folder instead: deactivate and delete it, then install the new one. Its settings are kept.

To build the zip yourself:

```bash
bin/build.sh
```

## Setup

1. Create an API key in the VeliraPay dashboard under **Developers → API keys**. Keys starting with `vp_live_` work in live mode, `vp_test_` in test mode. The plugin only creates, reads and voids invoices, so a restricted key with **Read invoices** and **Create invoices** (`invoices:read`, `invoices:write`) is all it needs. A key the store already uses can be restricted the same way with the pencil next to it on that page, with no need to paste it again.
2. Open **WooCommerce → Settings → Payments → VeliraPay** and paste the key into the live or test field. The prefix is checked when you save, and a new key is checked with VeliraPay (`GET /v1/account`, `GET /v1/invoices?per_page=1`): a key that is invalid or revoked, refused from the store's address, or without `invoices:read` is reported on the settings page, though saved anyway.
3. Copy the **Webhook URL** shown on that page: `https://your-store.example/wc-api/velirapay/`, `https://your-store.example/index.php/wc-api/velirapay/` or `https://your-store.example/?wc-api=velirapay`, depending on your permalinks. VeliraPay only delivers to an `https://` address on port 443 that answers within 15 seconds without redirecting, so the URL is always shown with `https://`, and the page warns when the site's own address is still `http://`. In the dashboard, under **Developers → Webhooks**, add an endpoint with it and tick `invoice.paid`, `invoice.voided`, `charge.payment_detected`, `charge.underpaid`, `charge.expired`, `charge.late_payment` and `charge.refunded`. Ticking every event works too: the rest only add notes.
4. Paste the endpoint's signing secret (`whsec_…`) into the matching webhook secret field.
5. Enable the gateway and save.
6. Back on the endpoint's page in the dashboard, press **Verify**. The plugin answers the challenge VeliraPay sends, and the endpoint receives nothing until it has.

Each mode has its own keys and endpoints, so do the dashboard steps once in live mode and once in test mode. The settings page shows a notice when the gateway is enabled without a key for its mode, or when the store currency is not one VeliraPay bills in, and when the checkout last found that the account takes no coin at all, with no payout address for any network; in each case the method is not offered at checkout. It also shows what VeliraPay last said refusing the current mode's key (401 or 403), such as a key without `invoices:write` at checkout, which cannot be checked without creating an invoice, until a request needing the same permission succeeds: a read of an invoice for `invoices:read`, a creation or void for `invoices:write`. A refusal of the coins on offer or the account, which need no permission, such as a key refused from the store's address, ends with any request that succeeds, while listing the coins, as the checkout does every ten minutes, never ends a refusal of an invoice request.

## How orders map to invoices

A store does not know which coin a customer wants to pay in, and a charge needs its coin up front, so an order becomes an invoice, whose hosted page lets the customer pick. USDT and USDC on Ethereum start at 10 of the token, so a smaller order simply does not offer them there. USDT on Tron asks the customer for a network surcharge of 2.50 USDT on top of the price, which the hosted page shows before the coin is chosen; the invoice, and what the order is paid for, stay the order total.

At checkout, and on the page where a customer pays for an existing order, the method is only offered when the total reaches the currency's lowest price (`Gateway::LOWEST_PRICES`, the same as VeliraPay's) and a coin the account takes can be paid for that much. The plugin asks `GET /v1/assets?currency=…` which coins the account takes and the lowest price of each, waits at most three seconds for the answer, and remembers it for ten minutes, or a failure to get it for three, falling back on the currency's lowest price meanwhile. On the block checkout the method is also left out as soon as a coupon or a removed item takes the total below that amount. It is only asked on the storefront's requests, the Store API requests the block checkout makes included, when the cart or the order being paid has a total, and never on admin pages, through WooCommerce's REST API or from cron.

When the customer chooses VeliraPay, the plugin creates an invoice with `POST /v1/invoices`:

| Invoice | |
| --- | --- |
| `amount` | The order total, as a two-decimal string. No line items are sent, so tax and shipping rounding can never make the invoice differ from the order. |
| `currency` | The order's currency. |
| `customer_name`, `customer_email` | The billing name and email. An order without a billing email, as one created in the admin may be, uses its customer's account email, and one with neither is refused at checkout before VeliraPay is asked. |
| `memo` | `Order #1042 at Your Store`. |
| `send_email` | `false`: the customer is sent straight to the hosted page, so the invoice itself is not emailed. This only governs the invoice email; see [Emails to customers](#emails-to-customers). |

Every request to the API goes to `https://api.velirapay.com`, waits at most 15 seconds, and never follows a redirect, so the API key is never sent anywhere else; a redirect is taken as an error.

When VeliraPay refuses the email, because its domain cannot receive mail (a typo, usually), or the amount, the customer is shown its message, such as "Crypto payment: Mail cannot be delivered to this address. Check how it is spelled.", and can correct it; any other failure shows a general notice. Either way the full message is noted on the order and logged.

The request carries the idempotency key `woocommerce-order-<order id>-<order key>-<hash>`, where the hash covers the invoice being replaced and the request details, so a retried checkout gets the same invoice back rather than a second one. The invoice's code and hosted URL are kept in the order's meta (`_velirapay_invoice_code`, `_velirapay_invoice_url`) and noted on the order, the order stays **Pending payment**, and the customer is redirected to the hosted page. There they pick a coin, which locks the exchange rate for 30 minutes, and send the payment.

If the customer comes back to pay the same order again, the open invoice is reused while its amount and currency still match the order. When they no longer do, the old invoice is voided and a new one created, under an idempotency key of its own so a retry can never be answered with an invoice voided since. An old invoice VeliraPay will not void because a payment to it is on its way is kept instead, with a note, and any other failure to void stops the checkout so two invoices are never payable at once. The order remembers the codes of the invoices it replaced (`_velirapay_superseded_invoice_code`), so a payment that reaches one of them anyway is still matched to it.

The old invoice is set aside among the replaced ones before it is voided, since VeliraPay announces the void at once: its `invoice.voided` is then not taken for the order's own invoice, which would cancel the order. If it cannot be voided it is the order's own again. If it was voided but the new invoice could not be created, the order is left with no invoice, which polling skips, and the customer's next attempt creates one under the same idempotency key, so a creation whose answer was lost is not made twice.

WooCommerce's **Hold stock** timer leaves alone an order whose invoice a customer is paying. Before it cancels a pending VeliraPay order, the plugin reads the invoice with `GET /v1/invoices/{id}`: one with a charge still pending, inside its 30-minute rate lock or with a transfer confirming, or with a short payment, keeps the order (a short one puts it on hold), and one found paid marks the order paid instead. While VeliraPay cannot be reached, it keeps only orders younger than the **Hold stock** minutes plus 90, the rate lock and an hour for the payment to confirm, and asks once a run rather than once an order. An invoice the current key cannot read, after the key was revoked or the mode switched, is left to the timer.

Cancelling an order, from the admin, from the customer's account or by the **Hold stock** timer, voids its invoice with `POST /v1/invoices/{id}/void`, so the hosted page stops taking payment. A void VeliraPay could not be reached for, was too busy for (429) or failed on its side (5xx) is tried again from WP-Cron after 1, 5, 15 and 60 minutes, as long as the order is still cancelled and names the same invoice, and only the last failure is noted. One refused because the invoice belongs to the other mode or account (404) is noted at once. VeliraPay answers 409 when the invoice is already paid or voided, or a short payment or one still confirming keeps it payable; the plugin then reads the invoice, notes the second case so you can settle it in the dashboard, along with what the invoice being paid would do to a cancelled order, and lets VeliraPay report what happens to it. A payment that completes the invoice anyway brings the order back as a pending order would be, when its `invoice.paid` arrives or when you choose **Check VeliraPay status**: to **Processing**, or **On hold** when the invoice was paid by accepting a short or late payment (see [How statuses map](#how-statuses-map)). WooCommerce takes the order's stock again either way. Until then, news of a payment only adds a note to the cancelled order.

Trashing or deleting an order still pending, on hold or failed voids its invoice the same way, so a payment to it is not left matching no order. That void runs from WP-Cron, so neither a single removal nor a bulk one waits on VeliraPay, and is retried there; it covers the trash of both order stores and of the posts table's own order list.

## How statuses map

The same mapping is applied whether the news comes from a webhook or from polling:

| VeliraPay | WooCommerce | |
| --- | --- | --- |
| `invoice.paid`, or an invoice polled as `paid` | `payment_complete()` | An order still pending, on hold or failed, or a cancelled one, moves to **Processing** or **Completed** with the paying charge's code as its transaction id. Applied once: a paid order is never completed again. |
| `invoice.paid` for a different amount or currency than the order now comes to | **On hold** | The order was edited after its invoice was raised. The note gives both amounts; it is added once, whether the news came from the webhook or from polling. A cancelled order is held too. |
| `invoice.paid` by accepting a late payment, or a short one | **On hold** | The paying charge had a late payment (`charge.late_payment` on an expired, canceled or underpaid charge), or was last reported short (`charge.underpaid`) with no transfer seen since (`charge.payment_detected`). VeliraPay only marks such a charge paid when you accept it, which you also have to do before refunding it, so the order is held for you to fulfil or cancel, a cancelled one included. The note is added once. A short payment the customer topped up in time completes the order as usual. When the top-up's `charge.payment_detected` is delivered after `invoice.paid`, as can happen on Tron, where a transfer is credited as soon as it is seen, the order is held at first and completed by its next poll: the hourly one, the order-received page or **Check VeliraPay status**. Without those webhooks, polling cannot tell the difference and a paid invoice completes the order. |
| `invoice.paid` for a cancelled order | `payment_complete()` or **On hold** | The invoice was paid after all, as when a payment still confirming kept VeliraPay from voiding it. The order is handled as a pending one would be, which is also how WooCommerce's `payment_complete()` treats a cancelled order: completed when the payment covered it in time, held as in the two rows above otherwise, and WooCommerce takes its stock again. The hourly poll and the order-received page only read orders waiting for payment, so a cancelled order is brought back by the webhook or by **Check VeliraPay status**. A cancelled order with a recorded payment date stays cancelled. An order in any other status that was never paid, such as a custom one, keeps its status and gets a note, unless the invoice no longer covers it, which puts it on hold. A trashed or deleted order is not looked up at all, so news about its invoice is acknowledged and ignored. |
| `invoice.paid` for an invoice the order replaced | **On hold** | Never counted as the order's payment. On an order already paid, only a note saying the customer paid twice. |
| `charge.payment_detected`, `charge.underpaid` or `charge.late_payment` for an invoice the order replaced | note | Money is moving to the old invoice; check it in the dashboard. Anything else about a replaced invoice, its void included, is ignored. |
| `charge.payment_detected` | **On hold** | A transfer was seen on chain and is gathering confirmations. The note says how much, in which coin, naming the network of a token or of a coin on another chain, such as `USDT (Tron)` or `ETH (Base)`. A Lightning payment is noted as final, since a paid Lightning invoice is, and VeliraPay counts it a moment later. A cancelled order only gets the note. |
| `charge.underpaid`, or an open invoice polled with an underpaid charge | **On hold** | The note says what was received against what was due. A cancelled order only gets the note; on one that was never paid, the note says that a top-up completes it and that accepting the short payment brings it back on hold. |
| `invoice.voided`, or an invoice polled as `void` | **Cancelled** | Unless the order was paid after all, in which case only a note is added. |
| `charge.expired` | note | The rate lock ran out; the customer can get a new quote on the hosted page. |
| `charge.expired` or `charge.canceled` with no transfer left, on an order held for a transfer seen to that charge | **Pending payment** | VeliraPay forgets a transfer the chain no longer knows after 30 minutes, then closes the charge with nothing paid to it, so the order waits for payment again and the customer can pay on the hosted page. An order also held for another reason, such as a short or late payment, stays on hold. |
| `charge.late_payment` on an expired, canceled or underpaid charge | **On hold** | Funds arrived after the charge expired or was canceled, or after an underpaid charge's rate lock ended. They wait for you to accept them in the dashboard, before any refund, so the order is held rather than left pending for the **Hold stock** timer to cancel. Only an order still pending, failed or on hold is moved; a cancelled one gets the note alone, which says, when the order was never paid, that accepting the payment brings it back on hold. |
| `charge.late_payment` on a paid charge | note | Funds arrived on top of a paid charge, and are counted automatically once confirmed. |
| `charge.refunded` | note | A refund you recorded, with the total refunded so far. |
| `payout.sent`, `payout.confirmed` | note | Your share of the charge went out, and reached your wallet. The plugin does not need them, so they are not among the events to tick. |
| `charge.created`, `charge.paid`, `charge.canceled`, `invoice.viewed`, anything else | note | |

A paid invoice sends both `charge.paid` and `invoice.paid`; only `invoice.paid` completes the order, so the order is fulfilled once.

Refunded orders and orders with a recorded payment date keep their status when delayed events arrive, including when the merchant moved a paid order to a custom status or cancelled it afterward. A cancelled order that was never paid is only brought back by its own invoice being paid; a detected, short or late payment, or an invoice it replaced being paid, only adds a note to it.

## Webhooks

The receiver listens at the WooCommerce API URL, `wc-api=velirapay`, and handles each delivery as follows:

- The `X-VeliraPay-Signature` header is checked exactly as VeliraPay signs: `t=<unix time>,v1=<hex>`, where `v1` is HMAC-SHA256 of `<t>.<raw body>` with the endpoint secret. A header with several `v1` values passes when one matches, a timestamp more than five minutes from now fails, and the comparison is constant-time. Every secret in both webhook secret fields is tried, so a delivery of either mode is verified; to rotate a secret without losing a delivery, list the old and the new one separated by a comma, then drop the old one. A failed check is answered with **400**.
- The dashboard's "Send test event" delivery, which carries `"test": true`, is answered with **200** and does nothing.
- A delivery whose `mode` is not the gateway's, live or test, is answered with **200** and ignored: a test payment can never fulfil a live order.
- The order is found by the invoice code in its meta, through `wc_get_orders`, which works on the posts table and on HPOS. A charge event is resolved through its charge's `invoice`. A delivery about no invoice at all, such as a payment-link charge on the same account, is answered with **200** and ignored; so is one about an invoice no order in the store uses (made in the dashboard, say), since VeliraPay retries refusals and switches an endpoint off after repeated ones. An invoice an order outgrew when its total changed is found through the order's replaced invoice codes and handled as in the table above.
- Each `event_id` is remembered in the order's meta (`_velirapay_events`), so a retried or resent delivery is applied once and answered with **200**.
- Once handled, the delivery is answered with **200**.

Deliveries answered with 4xx are logged under **WooCommerce → Status → Logs**, source `velirapay`.

## Polling

A webhook can be missed: the site was down, the endpoint was mistyped, or the secret was rotated. The plugin therefore also reads the invoice with `GET /v1/invoices/{id}` and applies the mapping above:

- when the customer lands on the order-received page of a pending or on-hold order,
- when you choose **Check VeliraPay status** under Order actions on the order's edit screen, which also notes the invoice's current state,
- every hour, through WP-Cron, for orders paid with VeliraPay that are younger than three days and still pending or on hold. Three days is how long VeliraPay keeps retrying a delivery.

Hourly polling snapshots the eligible order IDs and checks up to 50 at a time, with remaining batches scheduled at least a minute apart. Orders already paid or removed before their batch runs are skipped. This keeps older pending orders in the polling queue while leaving room under the API's per-key rate limit; WP-Cron needs traffic or a server cron job to run the batches.

## Test mode

Tick **Test mode** in the settings to use the test API key and have test deliveries, rather than live ones, act on orders. Test payments run on each chain's test network and move no real funds; a test charge's page in the dashboard has a **Simulate payment** button when no test coins are at hand. The coins on BNB Chain, Base, Arbitrum One and Polygon are offered in live mode only, so the test hosted page leaves them out. A simulated transfer is counted at once and never announced as detected, so a simulated short payment says nothing about whether a top-up followed: when its charge is paid, by another simulation or by accepting it, the order is completed rather than held. The two modes share nothing, so an invoice created in one is unknown to the other: switching modes while orders wait for payment leaves those orders to be paid in the mode they were created in. Retrying such an order fails until the original mode and account's key are restored; it never replaces a live invoice with a test invoice. Restore the original settings and use **Check VeliraPay status** to synchronize an order paid while its mode was inactive.

## Refunds

Crypto payments cannot be pulled back, so the gateway does not support WooCommerce's refund button. Send the coins back from your own wallet and record the refund on the charge in the VeliraPay dashboard, which sends `charge.refunded` and notes it on the order.

## Emails to customers

`send_email: false` only keeps VeliraPay from emailing the invoice. In live mode VeliraPay still emails the billing address, on the store's behalf and in the checkout's language, alongside WooCommerce's own emails:

- a receipt when the payment confirms,
- a notice when the payment falls short,
- a notice of each refund you record.

In test mode VeliraPay only emails addresses of the account's own members, so a test customer gets nothing.

## Privacy and external services

The plugin talks to one service, **VeliraPay's API**, `https://api.velirapay.com`, the payment processor it adds, and to nothing else. When a customer pays, it creates the order's invoice with the billing name and email, the order total and currency, and a memo naming the order number and the store. On the storefront and the block checkout, while a customer has something in the cart or pays for an existing order, it reads which coins the account takes in the store currency, at most once every ten minutes, sending nothing about the customer or the cart. It reads the order's invoice on the order-received page, from **Check VeliraPay status**, every hour for orders waiting for payment, and before WooCommerce's **Hold stock** timer cancels an order, and voids it when the order is cancelled, trashed or deleted. When an API key is saved, it reads the account and the invoices list to check the key. The customer then pays on VeliraPay's hosted page, and VeliraPay sends the store signed webhooks. VeliraPay's [terms](https://velirapay.com/terms) and [privacy policy](https://velirapay.com/privacy) apply.

Under **Settings → Privacy**, WordPress offers text for the store's privacy policy saying what is sent to VeliraPay and which emails it sends customers.

## Development

```bash
composer install
composer test        # PHPUnit
composer types       # PHPStan, with the WordPress and WooCommerce stubs
composer lint        # Pint
```

The API client, the signature check, the status mapping and the webhook handler are plain PHP, tested without WordPress: HTTP goes through an injected transport and the order behind a small interface.

CI also builds the zip and runs WordPress's [Plugin Check](https://wordpress.org/plugins/plugin-check/), which WordPress.org reviews plugins with, on the plugin it holds, and fails on any error.

## Releases

A release starts from a version tag, `v<version>`, on a commit on `main`. The tag, the plugin header's `Version`, `Plugin::VERSION` and readme.txt's `Stable tag` must agree, and the tests, static analysis, code style and Plugin Check must pass. The release workflow then builds the zip with `bin/build.sh` and publishes it as the tag's GitHub release, with the version's section of CHANGELOG.md as its notes.

It then deploys the same files to the plugin's Subversion repository on WordPress.org, `https://plugins.svn.wordpress.org/velirapay-for-woocommerce/`, as `trunk` and as the version's tag, with [10up's deploy action](https://github.com/10up/action-wordpress-plugin-deploy). The listing's banners and icons come from `.wordpress-org`, which stays out of the zip. WordPress.org offers the version to every site once readme.txt's `Stable tag` names it, which the tag check makes sure of. The deploy needs two repository secrets, `SVN_USERNAME`, the WordPress.org username with commit access to the plugin, and `SVN_PASSWORD`, the SVN password set under **Account & Security** in its WordPress.org profile. While they are not set, as until WordPress.org approves the plugin, the deploy's steps are skipped and a tag only publishes the GitHub release. Once they are, re-running the **Deploy to WordPress.org** job of a tag's release run deploys that version.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
