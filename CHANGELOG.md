# Changelog

All notable changes to this plugin are documented here. It follows [Semantic Versioning](https://semver.org).

## Unreleased

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
