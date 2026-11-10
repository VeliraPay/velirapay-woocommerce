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
- [Development](#development)

## Requirements

- WordPress 6.4 or later
- WooCommerce 8.3 or later, with the classic or the block checkout, on the posts table or on High-Performance Order Storage
- PHP 8.1 or later
- A store currency VeliraPay can bill in: USD, EUR, GBP, CAD, AUD, CHF or TRY

## Installation

Download the latest zip from the [releases](https://github.com/VeliraPay/velirapay-woocommerce/releases) and upload it under **Plugins → Add New → Upload Plugin**, or put the folder in `wp-content/plugins/`. The plugin has no runtime dependencies and needs no `composer install`.

To build the zip yourself:

```bash
bin/build.sh
```

## Setup

1. Create an API key in the VeliraPay dashboard under **Developers → API keys**. Keys starting with `vp_live_` work in live mode, `vp_test_` in test mode. The plugin only creates, reads and voids invoices, so a restricted key with **Read invoices** and **Create invoices** (`invoices:read`, `invoices:write`) is all it needs. A key the store already uses can be restricted the same way with the pencil next to it on that page, with no need to paste it again.
2. Open **WooCommerce → Settings → Payments → VeliraPay** and paste the key into the live or test field. The prefix is checked when you save.
3. Copy the **Webhook URL** shown on that page, `https://your-store.example/?wc-api=velirapay` or `https://your-store.example/wc-api/velirapay/` depending on your permalinks. In the dashboard, under **Developers → Webhooks**, add an endpoint with it and tick `invoice.paid`, `invoice.voided`, `charge.payment_detected`, `charge.underpaid`, `charge.expired`, `charge.late_payment` and `charge.refunded`. Ticking every event works too: the rest only add notes.
4. Paste the endpoint's signing secret (`whsec_…`) into the matching webhook secret field.
5. Enable the gateway and save.
6. Back on the endpoint's page in the dashboard, press **Verify**. The plugin answers the challenge VeliraPay sends, and the endpoint receives nothing until it has.

Each mode has its own keys and endpoints, so do the dashboard steps once in live mode and once in test mode. The settings page shows a notice when the gateway is enabled without a key for its mode, or when the store currency is not one VeliraPay bills in; in both cases the method is not offered at checkout.

## How orders map to invoices

A store does not know which coin a customer wants to pay in, and a charge needs its coin up front, so an order becomes an invoice, whose hosted page lets the customer pick. USDT and USDC on Ethereum start at 10 of the token, so a smaller order simply does not offer them there.

When the customer chooses VeliraPay, the plugin creates an invoice with `POST /v1/invoices`:

| Invoice | |
| --- | --- |
| `amount` | The order total, as a two-decimal string. No line items are sent, so tax and shipping rounding can never make the invoice differ from the order. |
| `currency` | The order's currency. |
| `customer_name`, `customer_email` | The billing name and email. |
| `memo` | `Order #1042 at Your Store`. |
| `send_email` | `false`: the customer is sent straight to the hosted page, so no email is needed. |

The request carries the idempotency key `woocommerce-order-<order id>-<order key>-<hash>`, where the hash covers the invoice being replaced and the request details, so a retried checkout gets the same invoice back rather than a second one. The invoice's code and hosted URL are kept in the order's meta (`_velirapay_invoice_code`, `_velirapay_invoice_url`) and noted on the order, the order stays **Pending payment**, and the customer is redirected to the hosted page. There they pick a coin, which locks the exchange rate for 30 minutes, and send the payment.

If the customer comes back to pay the same order again, the open invoice is reused while its amount and currency still match the order. When they no longer do, the old invoice is voided and a new one created, under an idempotency key of its own so a retry can never be answered with an invoice voided since. An old invoice VeliraPay will not void because a payment to it is on its way is kept instead, with a note, and any other failure to void stops the checkout so two invoices are never payable at once. The order remembers the codes of the invoices it replaced (`_velirapay_superseded_invoice_code`), so a payment that reaches one of them anyway is still matched to it.

Cancelling an order, from the admin, from the customer's account or by WooCommerce's **Hold stock** timer, voids its invoice with `POST /v1/invoices/{id}/void`, so the hosted page stops taking payment. VeliraPay answers 409 when the invoice is already paid or voided, or has a payment on its way; the plugin takes that as the invoice no longer being voidable and lets VeliraPay report what happens to it.

## How statuses map

The same mapping is applied whether the news comes from a webhook or from polling:

| VeliraPay | WooCommerce | |
| --- | --- | --- |
| `invoice.paid`, or an invoice polled as `paid` | `payment_complete()` | The order moves to **Processing** or **Completed** with the paying charge's code as its transaction id. Applied once: a paid order is never completed again. |
| `invoice.paid` for a different amount or currency than the order now comes to | **On hold** | The order was edited after its invoice was raised. The note gives both amounts; it is added once, whether the news came from the webhook or from polling. |
| `invoice.paid` for an invoice the order replaced | **On hold** | Never counted as the order's payment. On an order already paid, only a note saying the customer paid twice. |
| `charge.payment_detected`, `charge.underpaid` or `charge.late_payment` for an invoice the order replaced | note | Money is moving to the old invoice; check it in the dashboard. Anything else about a replaced invoice, its void included, is ignored. |
| `charge.payment_detected` | **On hold** | A transfer was seen on chain and is gathering confirmations. The note says how much, in which coin. |
| `charge.underpaid`, or an open invoice polled with an underpaid charge | **On hold** | The note says what was received against what was due. |
| `invoice.voided`, or an invoice polled as `void` | **Cancelled** | Unless the order was paid after all, in which case only a note is added. |
| `charge.expired` | note | The rate lock ran out; the customer can get a new quote on the hosted page. |
| `charge.late_payment` | note | Funds arrived after the charge expired or was canceled, or after an underpaid charge's rate lock ended, to accept in the dashboard before any refund, or on top of a paid one, which is counted automatically. |
| `charge.refunded` | note | A refund you recorded, with the total refunded so far. |
| `payout.sent`, `payout.confirmed` | note | Your share of the charge went out, and reached your wallet. The plugin does not need them, so they are not among the events to tick. |
| `charge.created`, `charge.paid`, `charge.canceled`, `invoice.viewed`, anything else | note | |

A paid invoice sends both `charge.paid` and `invoice.paid`; only `invoice.paid` completes the order, so the order is fulfilled once.

Refunded orders and orders with a recorded payment date keep their status when delayed events arrive, including when the merchant moved a paid order to a custom status or cancelled it afterward.

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

Tick **Test mode** in the settings to use the test API key and have test deliveries, rather than live ones, act on orders. Test payments run on each chain's test network and move no real funds; a test charge's page in the dashboard has a **Simulate payment** button when no test coins are at hand. The two modes share nothing, so an invoice created in one is unknown to the other: switching modes while orders wait for payment leaves those orders to be paid in the mode they were created in. Retrying such an order fails until the original mode and account's key are restored; it never replaces a live invoice with a test invoice. Restore the original settings and use **Check VeliraPay status** to synchronize an order paid while its mode was inactive.

## Refunds

Crypto payments cannot be pulled back, so the gateway does not support WooCommerce's refund button. Send the coins back from your own wallet and record the refund on the charge in the VeliraPay dashboard, which sends `charge.refunded` and notes it on the order.

## Development

```bash
composer install
composer test        # PHPUnit
composer types       # PHPStan, with the WordPress and WooCommerce stubs
composer lint        # Pint
```

The API client, the signature check, the status mapping and the webhook handler are plain PHP, tested without WordPress: HTTP goes through an injected transport and the order behind a small interface.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
