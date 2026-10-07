# Shop G2: provider framework and Admin Payments

## Audit and scope

G1 is unchanged: it owns exact USD amounts, payment state transitions, event
deduplication and atomic order payment-status updates. Checkout F2 still prepares
Pending + Unpaid orders; it neither selects providers nor creates payment attempts.
Legacy order behavior remains as implemented before G2. Admin V2 provides the
module navigation and neutral CMS styling reused by Payments.

G2 adds safe provider settings, a fixed internal registry and an adapter contract.
There are no provider SDKs, HTTP gateway calls, webhook routes, redirects, customer
buttons or changes to stock/cart/order/fulfillment behavior.

## Schema and migration

Apply `migrations/20261007_shop_g2_payment_providers.sql` after G1 to the selected
database. It checks prerequisites and required schema objects, creates
`shop_payment_providers`, and seeds only disabled Test metadata for `paypal` and
`stripe`. Reapplying preserves existing enabled/mode/timestamp values. Fresh
installations receive the same definition in `database.sql`.

The table contains only `provider`, `enabled`, `mode`, `created_at`, `updated_at`.
There are no identifiers, credential fields or adapter class/filename fields.
Provider keys and enabled values have database constraints. Configured/available
status is calculated from the server, never accepted from Admin or stored as a
connection claim. Existing business rows and G1 tables are untouched. Like G1,
the migration guard detects prerequisites/required objects, not every possible
schema drift; incompatible existing objects require inspection.

## Server credentials

Production resolution reads environment variables first. An optional protected
`SHOP_PAYMENT_PROVIDER_CREDENTIALS` constant in local `api/config.php` can provide
an array indexed by provider, then mode, then field. Environment values explicitly
set empty disable the local fallback. Neither config.php nor secrets were changed
or created for G2. config.php remains ignored.

Environment names follow `DYNDEL_{PROVIDER}_{TEST|LIVE}_{FIELD}`:

| Provider | Required fields in each environment |
| --- | --- |
| PayPal | `CLIENT_ID`, `CLIENT_SECRET`, `WEBHOOK_ID` |
| Stripe | `PUBLISHABLE_KEY`, `SECRET_KEY`, `WEBHOOK_SECRET` |

All required nonempty fields indicate **presence**, not verified account access or
valid API credentials. Test and Live never fall back to one another. Admin shows
only a credential-presence indicator; it never accepts or returns credential
values, including client IDs or publishable keys. All identifiers stay in protected
server configuration in V1. Deployment can use environment variables without
changing the registry or database.

No credentials are required for G2 testing. Readiness tests use in-memory markers
and inert fixture adapters; no accounts, keys or gateway requests are involved.

## Registry, adapters and availability

`ShopPaymentProviderRegistry` has exactly two fixed entries. Unknown keys and
runtime paths/classes from Admin input are rejected. Only trusted PHP composition
can supply adapter objects; production uses `ShopUnconnectedProviderAdapter` for
both entries. These dormant adapters throw on session/callback operations.

`ShopPaymentProviderAdapter` establishes `ready()`, `createSession()` (with a
persisted G1 payment record), and `verifyCallback()` (authenticate and normalize
to `ShopVerifiedProviderResult`). An internal `processCallback()` demonstrates
verified-result handoff to G1; it has no public HTTP route. Implemented adapters
must verify signature, account, environment and payment identity before returning
that result. G1 still validates persisted amounts, provider identity and transitions.

Effective availability requires supported + configured + enabled, **and** an
implemented adapter. This extra gate intentionally keeps G2 discovery empty even
if metadata is enabled and credentials later appear. Fixture adapters test the
configured/enabled/ready case without implementing a provider. Status is Not
configured when credentials are missing, Configured when credentials are present
but integration is dormant, and Enabled/Disabled once an adapter is ready. The
enabled setting is shown separately, so it cannot be mistaken for a connection.

## API and Admin

| Action | Access | Response/behavior |
| --- | --- | --- |
| `admin-payment-providers` GET | Admin session | USD, whitelisted metadata and session CSRF token |
| `save-payment-provider` POST | Admin session + CSRF | Validated provider/mode/enabled update only |
| `payment-providers` GET | Public | USD and available providers' key/displayName/mode only |

Responses are `no-store`. Uploads, extra fields (including secrets), unknown keys,
invalid modes/enabled values, invalid CSRF and wrong HTTP methods are rejected.
The public endpoint returns no internal readiness/timestamp/credential information.
Checkout does not consume it yet.

Admin Payments opens with provider cards, Configure actions and read-only USD.
The editor contains only mode and enabled settings, credential-presence text and
manual server-configuration guidance. It uses existing module navigation, CMS
controls and neutral theme tokens. No public pointer/KAI effects enter Admin.

## Decisions deferred before Provider #1

Zero-total orders, refunds, late-success reconciliation, gateway session assignment,
inventory finalization and fulfillment remain deferred. G2 does not change G1
semantics or add a mode/session snapshot to payments. Before enabling a real
adapter, settle per-attempt environment/account/session binding, session assignment
after gateway creation, processing verified callbacks after provider disable/mode
changes, retry handling and production readiness. The internal demonstration
callback path uses current availability and mode; it is not yet a durable webhook
policy for in-flight payments. Do not expose it directly without those decisions.

## Tests

Run G2, G1, Shop V2, F1/F2 and Brand Identity PHP tests sequentially, then
`tests/shop_ui_browser_regression.py`. The G2 suite checks migrations/fresh schema,
authentication/CSRF, validation, safe output, readiness matrix, mode separation,
adapter handoff and complete business-row fingerprints. Browser tests cover the
desktop/mobile module, editor/focus behavior, saving, reload persistence and
unchanged public/cart/checkout flows. Test settings/timestamps are restored;
temporary databases/sessions are removed. Screenshots use a separate G2 artifact
directory so prior homepage review artifacts are preserved.
