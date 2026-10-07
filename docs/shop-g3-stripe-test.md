# Shop G3: Stripe Test Checkout

## Architecture and scope

G1 remains responsible for exact USD amounts, atomic payment/order updates,
terminal transitions and event deduplication. G2 owns fixed provider registration,
protected credentials and authenticated Admin settings. F2 persists Pending +
Unpaid orders. G3 connects these components to hosted Stripe Checkout in Test
mode. PayPal, Live Stripe, refunds, fulfillment and inventory finalization remain
deferred. Paid orders still have Pending fulfillment status and unchanged stock.

The adapter uses the official v1 REST API through PHP cURL with certificate
verification, fixed Stripe endpoints, timeouts and API version
`2026-09-30.endive`. There is no SDK dependency or browser Stripe key. Card
details remain on Stripe. The current create-session API uses
`allowed_payment_method_types` to filter card methods. Eligible wallets are
Stripe's hosted behavior; this project does not implement a separate Google Pay
integration. Automatic tax and adaptive pricing are disabled to preserve the
persisted USD total. A single aggregated order line charges that exact total.

References: [Checkout Session creation](https://docs.stripe.com/api/checkout/sessions/create),
[API versioning](https://docs.stripe.com/api/versioning),
[request idempotency](https://docs.stripe.com/api/idempotent_requests).

## Schema and deployment

Apply `migrations/20261007_shop_g3_stripe_test.sql` after G1 and G2, before
deploying the PHP changes. It adds nullable provider-mode, hosted-session URL and
expiry columns to `shop_payments`. Reapplying preserves rows and settings.
Historical attempts retain NULL mode; they are never guessed to be Test. Fresh
installations receive the same columns through `database.sql`.

G1's create function accepts an optional mode snapshot and persists it atomically
with the attempt. Existing callers may omit it. Its transition rules are unchanged.

## Protected configuration

Configure these values in the Apache/PHP server environment or the existing
ignored local `api/config.php`; never put values in tracked files, Admin, browser
storage, screenshots or logs:

| Name | Purpose |
| --- | --- |
| `DYNDEL_STRIPE_TEST_SECRET_KEY` | Stripe sandbox secret API key |
| `DYNDEL_STRIPE_TEST_WEBHOOK_SECRET` | Signing secret from the active local CLI listener |
| `DYNDEL_PAYMENT_RETURN_BASE_URL` | Canonical site origin plus application path |

The existing optional `SHOP_PAYMENT_PROVIDER_CREDENTIALS` constant accepts an
array indexed by `stripe`, `test`, then `secret_key` and `webhook_secret`.
`SHOP_PAYMENT_RETURN_BASE_URL` is the optional local constant for the base URL.
Explicit environment values take precedence. No publishable key is required for
this hosted server-side integration; this supersedes G2's dormant Stripe field
list. Values must have Test API/signing-secret formats; readiness indicates
configuration presence, not successful account authentication.

The default return base is `http://localhost/dyndel-portfolio`. Set the canonical
base explicitly when using another host/path. HTTPS is required except for local
loopback HTTP. Browser input and Host headers never choose return URLs. Restart
Apache after changing its environment. CLI PHP environment and Apache environment
can differ, so check Admin's safe configured indicator as well.

Configure both secrets first, then explicitly enable Stripe in Test mode in Admin
Payments. Nothing auto-enables. Live mode remains unavailable even with Live
credentials. Public discovery exposes only effectively available providers.

## Ownership, sessions and callbacks

Successful F2 checkout binds the order to its PHP session and issues a session
CSRF token. Session creation accepts only the owned order ID and that token,
never a browser amount, currency, provider or redirect. PHP session storage must
be writable and cookies must survive the hosted redirect.

An advisory per-order lock serializes session creation. The attempt snapshots
Stripe/Test, order, exact amount and currency before sending the API request.
Its deterministic idempotency key remains stable after a timeout. The validated
Stripe response supplies immutable session ID, URL and expiry. Cached sessions
are reused. Unbound uncertain attempts older than 23 hours require administrator
reconciliation: Stripe may prune idempotency keys after 24 hours, so blindly
recreating them could duplicate a charge. Changing the canonical base or account
credentials during an uncertain attempt also requires reconciliation.

Disabling Stripe or selecting Live blocks new session creation. Existing Test
attempt callbacks still verify using Test signing configuration and their stored
mode/session binding. Keep that signing secret available for in-flight attempts.
An already bound, unexpired hosted session remains retrievable server-side;
the storefront's current availability gate can suppress its retry button.

`api/stripe_webhook.php` accepts POST raw bodies up to 1 MiB. It verifies the
Stripe-Signature HMAC against the unmodified body, uses constant-time comparison
and a five-minute timestamp tolerance, and accepts only direct Test Checkout
events. Session ID, mode, order metadata/client reference, amount and currency
must match a persisted attempt. Paid requires completed/paid session state and
a payment-intent reference. Browser success URLs cannot mark Paid.

Completed-but-unpaid sessions wait for authoritative settlement. Verified expiry
or async failure produces Cancelled/Failed without paying the order. Unsupported
signed events are acknowledged and ignored. A webhook arriving before session
binding receives a retriable 503. Invalid signatures/bindings receive 400;
unconfigured verification receives 503. G1 handles duplicate events and atomic
Paid updates. No raw payloads, provider errors or secrets are logged by this code.

## Return pages and cart

Success and cancellation use the session-owned state endpoint. Success polls at
most twelve times, two seconds apart, then offers manual rechecking. Cancellation
does not cancel a real Stripe session or claim payment failure; it preserves the
cart while displaying the actual server state.

Only confirmed server-side Paid can remove cart quantities. The browser compares
the checkout cart snapshot with the order's purchased product IDs/quantities and
subtracts those original quantities from the current cart. Added quantities and
unrelated products survive. A per-order receipt marker prevents repeat removal;
Web Locks serialize supported browsers' tabs. Without the original snapshot,
the cart stays unchanged. Storage failures leave recovery manual. Identical items
removed and re-added cannot be distinguished by the current quantity-only cart
model; a future cart revision model would be needed for that distinction.

## Local official Stripe CLI setup

Install the [official Stripe CLI](https://docs.stripe.com/stripe-cli), authenticate
with your sandbox account, and start forwarding snapshot events:

```powershell
stripe login
stripe listen --latest --events checkout.session.completed,checkout.session.expired,checkout.session.async_payment_succeeded,checkout.session.async_payment_failed --forward-to http://localhost/dyndel-portfolio/api/stripe_webhook.php
```

Use the listener's signing secret only in protected local configuration. Keep the
listener running. Use the same sandbox account for the API key and listener.
The CLI defaults to sandbox requests; do not add `--live`. It requires no local
Dashboard webhook registration. `--latest` aligns events with the currently
pinned API version; after Stripe advances its latest version, review compatibility
or use a version-matched registered sandbox webhook endpoint. Do not forward thin
events or Connect events. See [official listen reference](https://docs.stripe.com/cli/listen).

## Manual sandbox acceptance

1. Start Apache/MariaDB, configure Test credentials, listener and canonical base,
   and enable Stripe/Test in Admin. Confirm effective availability.
2. Add an internal product, note its stock and checkout total, and submit Checkout.
   Confirm one Pending + Unpaid order and one bound Test attempt/session.
3. Complete hosted Checkout with an official [Stripe test card](https://docs.stripe.com/testing).
   Use no real card details. Observe a verified forwarded event with HTTP 200.
4. Confirm G1 records the event and Paid payment/order status, stock remains
   unchanged, and the return page confirms Paid before removing purchased cart
   quantities. Added quantities/unrelated products must remain.
5. Reload the return page and resend the same official event: no duplicate payment,
   extra cart removal or stock change. Also test browser cancellation and pending
   success returns; neither may imply Paid or clear the cart.
6. Disable Stripe/switch Admin to Live during an existing Test session: new
   attempts must stop while its correctly signed Test payment event still works.

A generic CLI trigger creates unrelated fixture metadata and is not proof of a
bound checkout payment. Use the real hosted session created by this application.

Manual hosted sandbox acceptance was confirmed on 2026-10-07: Stripe Test
Checkout received the prepared order and authoritative USD total, the test
payment succeeded, and Stripe CLI forwarded its verified webhook with HTTP 200.
The return page confirmed server-side Paid before clearing purchased cart items.
The pre-commit database audit confirmed one consistent Paid Test attempt and
processed event, Pending fulfillment status, and unchanged product inventory.
Automated fixtures never bypass production signature/ownership checks.

## Automated evidence and next milestone

Network-free `tests/shop_g3_stripe_test.php` exercises the real adapter/parser
with injected REST responses, ephemeral signing material, adversarial signatures
and bindings, disabled/mode behavior, timeout retry, stale retry refusal, HTTP
ownership/CSRF, exact amounts, immutable stock and fixture cleanup. Reversed
response metadata key order is intentionally covered.

`tests/shop_ui_browser_regression.py` covers desktop/mobile pending, cancellation,
confirmed Paid and quantity-preserving cart removal. Its CLI-only fixture helper
requires a newly created named browser fixture and the exact checkout token.
Existing G1/G2/F2/F1/Shop/Brand regressions also pass. Database fixtures, provider
settings and counters are restored; business data and user upload directories
are preserved. New screenshots are in `.tmp-shop-g3-review/`.
The browser suite temporarily disables providers and restores their exact
settings afterward, so configured local credentials cannot trigger real gateway
requests from disposable checkout fixtures.

Next: Shop G4 inventory finalization and fulfillment foundations, with transaction
locking, oversell policy, idempotent fulfillment and reconciliation. No stock is
reserved in G3, so simultaneous Paid purchases can exceed available inventory.
Zero totals, refunds and late-success reconciliation of terminal attempts remain
separate decisions. G1 deliberately rejects conflicting terminal transitions;
administrators must reconcile those exceptions before fulfillment.
