# Changelog

All notable changes to this plugin are documented here. It follows [Semantic Versioning](https://semver.org).

## Unreleased

- A late payment to an expired, canceled or underpaid charge puts an order still pending, failed or on hold on hold, instead of only noting it, so WooCommerce's **Hold stock** timer no longer cancels the order, and voids its invoice, while the money waits for you to accept it. A cancelled order still only gets the note.
- An invoice paid by accepting a late payment, or a short one that no top-up followed, puts the order on hold instead of marking it paid, since VeliraPay has you accept such a payment before refunding it as well as to keep it. The order and its notes say what to do next. A short payment the customer topped up in time still completes the order, at its next poll when the top-up is reported after the invoice was paid. Telling the two apart needs the `charge.underpaid`, `charge.payment_detected` and `charge.late_payment` webhooks, and a short payment made with test mode's **Simulate payment**, whose top-ups are never announced, is not held.
- A detected or short payment, or the payment of an invoice the order replaced, only adds a note to a cancelled order instead of putting it on hold. Its invoice being paid still brings it back as a pending order would be, as WooCommerce's `payment_complete()` does: to **Processing** when the payment covered it in time, or on hold when the invoice was paid by accepting a short or late payment, or no longer covers the order.
- The notes on short and late payments say what accepting them in the VeliraPay dashboard does to the order.
- The method is only offered when the total reaches the currency's lowest price (0.50 USD, EUR, GBP or CHF, 0.70 CAD, 0.80 AUD, 25.00 TRY), which VeliraPay refused an invoice under, and a coin the account takes can be paid for it, which the hosted page otherwise had none of. The coins on offer come from `GET /v1/assets`, remembered for ten minutes; while VeliraPay cannot be asked, the lowest price alone decides. The block checkout leaves the method out as the total drops below that amount, and an admin notice says so when the account takes no coin at all.
- WooCommerce's **Hold stock** timer no longer cancels an order whose invoice a customer is paying, inside the 30-minute rate lock, with a payment confirming or with a short payment, which voided the invoice and turned the payment into a late one. The plugin reads the invoice before the timer cancels an order, and applies it when it was paid. While VeliraPay cannot be reached, only orders younger than the **Hold stock** minutes plus 90 are kept.
- Replacing an outdated invoice no longer cancels the order the customer is paying. Its old invoice is set aside before the void, whose webhook could arrive before the new invoice was saved and cancel the order, and is the order's own again if it cannot be voided. When the new invoice cannot be created after the void, the order is left with no invoice instead of the voided one, which its webhook and polling took for the order's and cancelled it, and the next attempt creates the invoice under the same idempotency key.
- An order put on hold by a payment seen on chain that never confirmed goes back to **Pending payment** when VeliraPay closes the charge with no transfer left, instead of staying on hold indefinitely. An order held for any other reason as well stays on hold.
- When VeliraPay refuses the customer's email, whose domain cannot receive mail, or the amount, the checkout shows its message, such as "Crypto payment: Mail cannot be delivered to this address. Check how it is spelled.", instead of a general notice the customer could do nothing about.
- An order without a billing email, as one created in the admin may be, is made out to its customer's account email, and one with neither is refused at checkout before VeliraPay is asked, instead of sending VeliraPay an empty email.
- Voiding a cancelled order's invoice is tried again from WP-Cron, after 1, 5, 15 and 60 minutes, when VeliraPay could not be reached, was busy or failed, instead of leaving the invoice payable after one try; only the last failure is noted. When VeliraPay refuses the void because a short payment, or one still confirming, keeps the invoice payable, a note says so.
- Trashing or deleting an order still pending, on hold or failed voids its invoice, from WP-Cron so the removal never waits on VeliraPay; before, the invoice stayed payable and a payment to it matched no order.
- API requests no longer follow redirects, which WordPress would have sent the API key along with to wherever they pointed; a redirect is taken as an error, as the other official libraries do.
- The settings page always shows the webhook URL with `https://`, which VeliraPay requires, and warns when the site's own address is still `http://`.
- A new API key is checked with VeliraPay when the settings are saved: one that is invalid or revoked, refused from the store's address, or without `invoices:read` is reported on the settings page, and saved anyway. A key VeliraPay refuses later, such as one without `invoices:write` at checkout, shows in an admin notice until a request needing the same permission succeeds, rather than only in the failed order's notes; listing the coins on offer, which needs none, never hides a refused invoice request.
- Order notes name a token, or a coin on another chain, by its ticker and network, such as "USDT (Tron)" or "ETH (Base)", instead of its code, and a Lightning payment is noted as final rather than waiting for confirmations.
- WordPress offers new versions of the plugin on its own update screens: an `Update URI` header points it at the GitHub repository, and the plugin answers its update check with the latest release's zip when it is newer, asking GitHub at most every twelve hours. Stores on 0.3.1 or earlier update to this version by hand once.
- WordPress's privacy policy guide suggests text saying what is sent to VeliraPay and which emails VeliraPay sends customers, and the readme lists both external services the plugin calls, VeliraPay's API and GitHub's, with what it sends them and when.
- The documentation says which emails VeliraPay sends customers despite `send_email: false`: a receipt, a short-payment notice and refund notices in live mode.
- A version tag only publishes the plugin zip once the tests, static analysis and code style pass, `Plugin::VERSION` agrees with the plugin header, readme.txt's stable tag and the tag, and the tagged commit is on `main`. The zip is then built and published by a job of its own that installs no dependencies, so nothing the tests install can change it or reach the release.

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
