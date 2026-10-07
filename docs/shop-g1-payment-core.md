# Shop G1 Payment Core

G1 is an internal PHP domain service, not a gateway integration. Checkout still
prepares Pending + Unpaid orders. It creates no payment attempts automatically,
clears no carts, changes no stock, and performs no fulfillment.

## Audit and compatibility

F1 quotes current products and shipping using integer cents; F2 persists USD
order/item snapshots and deduplicates checkout attempts. `shop_orders.status`
tracks fulfillment, while `payment_status` tracks payment. Legacy orders remain
readable; the core accepts new attempts only for positive-total, Pending + Unpaid
`checkout_v2` orders. Zero-total checkout orders need a separate future free-order
policy. Historical orders receive no synthetic payment records.

## Installation

After F1/F2, import `migrations/20261007_shop_g1_payment_core.sql` into the selected
database. It creates only `shop_payments` and `shop_payment_events`, is repeatable,
checks prerequisites and required object names, and performs no business-data
updates. `database.sql` contains the same tables for fresh installs. MySQL DDL
auto-commits: incompatible pre-existing objects require inspection, not automatic
repair. The guard is not a complete schema-drift detector.

## Service contract

Load `shop_products.php`, `shop_checkout.php`, then `shop_payments.php` internally.
Domain methods throw exceptions and own their transactions; they must not be
called inside an existing transaction. No method is wired to an HTTP action.

`shop_payment_create(PDO, orderId, provider, attemptReference, sessionId?)`
reads and locks the order, derives its exact DECIMAL amount and USD currency,
and persists a pending attempt. Provider names are extensible lowercase ASCII
identifiers, not a Stripe enum. References, sessions and captures are unique
within a provider and case-sensitive. Reusing an attempt reference returns its
existing record only when all details match. One pending/paid attempt per order
is enforced by the core's order lock. All future writers must use this service;
the database uniqueness indexes separately prevent reference/session/capture reuse.

`shop_payment_process_verified_event(PDO, paymentId, provider, eventId, eventType,
status, verifiedAmount, verifiedCurrency, captureId?, failureCode?)` accepts only
an adapter-authenticated, normalized result. Amount is a canonical decimal string
with two fractional digits and must equal the stored attempt AND, for a new
transition, the persisted order. Floats and browser prices have no authority.

Permitted transitions are `pending -> paid | failed | cancelled`. Terminal states
cannot reopen or switch. A matching repeat of a terminal result is a no-op;
conflicting captures/failure codes are rejected. Paid results require a capture
identifier. A successful transition sets `paid_at` and the order's
`payment_status = paid`, leaving fulfillment status alone. Failure/cancellation
leaves the order Unpaid so a new attempt with a new reference is possible.
Refund transitions are deferred until a verified refund model exists.

## Event atomicity and retries

The core locks order then payment, validates the result, inserts an event,
applies the transition, and marks the event processed in one transaction.
`UNIQUE(provider, provider_event_id)` prevents duplicate finalization, including
concurrent deliveries. Same-ID retries must match the SHA-256 hash of normalized
result fields; another payment/status/amount/capture cannot reuse that ID.
Different event IDs for the same paid capture are recorded without repeating
the order update. Failed transactions roll back the event and transition so
an adapter can retry. Rejected events are not persisted by G1.

Only IDs, normalized status, timestamps, an optional sanitized failure code and
the result hash are stored. No raw payloads, customer/provider secrets or error
messages are stored. Nullable event relationships reserve room for a future
unmatched-event inbox; the G1 service always supplies both payment and order.
Composite foreign keys bind the event to the same payment/order/provider.
RESTRICT foreign keys preserve payment audit history and prevent deleting
referenced orders. G1 adds no deletion API.

## Future provider boundary

Stripe/PayPal adapters will authenticate server-side requests/webhooks, verify
signature/account/environment, resolve persisted attempt/session IDs, and call
the core with a verified amount/currency and stable capture/event identifiers.
Secrets must come from local/server configuration, never Git or client JS.
Do not expose the core directly to untrusted requests; its function name does
not cryptographically verify a provider. Session assignment after a gateway
request and provider-specific retry/error handling can be added in G2.

The paid branch inside the core transaction is the G3/G4 extension boundary for
inventory checks and fulfillment/outbox work. Do not enable real providers until
those policies are agreed: G1 performs no reservation, stock decrement, delivery,
refunds or reconciliation of late success after a terminal failure/cancellation.

## Validation

Run `php tests/shop_g1_payment_core_test.php`, then the existing Shop V2, F1,
F2, Brand Identity and browser regressions sequentially against local XAMPP.
G1 tests apply the migration twice, validate fresh-install DDL in an isolated
temporary database, exercise core transitions and duplicate events, and remove
only their own fixtures. Business-row fingerprints and stock are checked before
and after. Browser regression confirms unchanged cart/checkout behavior.
