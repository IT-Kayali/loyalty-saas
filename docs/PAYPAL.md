# PayPal SaaS subscriptions

This module bills the platform's business owners. It does not collect shop/customer payments or sell prepaid passes. One owner subscription covers that owner's businesses. No PayPal account, plan, payment or live subscription was created during development.

## Configuration

1. Create a PayPal Business REST app in **Sandbox** and matching active subscription plans. Store credentials privately in `config.php` or the environment, never in Git or WordPress/browser code.
2. Set `paypal.client_id`, `paypal.secret`, `paypal.webhook_id` and `paypal.mode=sandbox`. The client ID is intentionally visible to PayPal's browser SDK, the secret is server-only.
3. Map the existing shared plan keys under `paypal.plans.KEY.plan_id` and `currency`. The example uses `starter` and `pro`. Their allowances/features remain under `stripe.plans` for backward compatibility. PayPal controls the actual price, taxes and interval. Verify the configured currency against the PayPal plan. The approval window must show the final terms before a buyer agrees.
4. Register `https://YOUR-INSTALLATION/paypal/webhook` on the same app/environment. Subscribe to `BILLING.SUBSCRIPTION.CREATED`, `ACTIVATED`, `UPDATED`, `CANCELLED`, `SUSPENDED`, `EXPIRED`, `PAYMENT.FAILED`, plus `PAYMENT.SALE.COMPLETED`, `PAYMENT.SALE.REFUNDED`, and `PAYMENT.SALE.REVERSED`. Subscription event names have the `BILLING.SUBSCRIPTION.` prefix.
5. Serve over HTTPS. The billing page loads PayPal JavaScript SDK v6 and checks recurring-payment eligibility. Only this page widens the default CSP to PayPal resources. Reverse proxies must preserve the signature headers and original request body. Webhooks must bypass session CSRF, other billing POSTs must not.
6. `stripe.enforce_limits=true` enables the shared write limits for either provider. With it off, no plan is a paywall. Billing, sign-in and privacy access remain available when an account lapses.

Without all credentials the module stays disabled and does not load the PayPal SDK. These settings indicate configuration, not successful provider connectivity. Before live use, complete the sandbox acceptance checklist below. Never switch an existing installation's environment casually; sandbox/live credentials, webhook IDs, plan IDs and persisted bindings must match. No automatic environment migration exists.

## Subscribe, cancel and change plan

Open **Billing → PayPal billing** (`/billing/paypal`). SDK v6 starts an approval session with a subscription created by the server. Create sends `plan_id` and a locally generated tenant/attempt `custom_id`, without deprecated `application_context` fields. SDK callbacks never grant access. A server GET of the bound subscription must confirm its actual state and configured plan.

Only `ACTIVE` is entitled. Pending approval, unknown states, suspension, expiry and cancellation do not grant writes. An ACTIVE subscription with failed payment count greater than zero or an unresolved refund/reversal hold is `past_due`. Stored data is not deleted. A browser callback is neither a payment receipt nor evidence of activation.

A plan change **never cancels an existing subscription automatically**. Explicitly cancelling requires a separate checkbox and button explaining that write access may stop immediately. Cancellation is not scheduled for the end of a period. After PayPal confirms termination, the owner can select a replacement plan and separately approve it. If they leave before approval, no new active subscription exists. No automatic refunds, proration, annual interval selector, plan revision, payment-method portal or cross-provider migration wizard is included.

Open/pending Stripe checkout and nonterminal Stripe subscriptions prevent PayPal signup; PayPal attempts prevent parallel Stripe signup. An old-provider webhook cannot overwrite the current provider. An old Stripe checkout must be completed/expired and reconciled before switching, it is not silently discarded.

## Reliability and webhook verification

- A request ID and exact plan/custom binding are stored before an external create. Ambiguous create errors reuse that identity. PayPal documents 72-hour retention; unresolved attempts older than 70 hours stop for operator review instead of minting another subscription. Pending same-plan retries reuse the known subscription ID.
- Every webhook is verified by posting to a fixed PayPal verification endpoint. The original event JSON bytes are embedded unchanged in that request. Header values, algorithm and certificate URL are checked; the app never downloads an untrusted certificate URL.
- Verification failure does not mutate billing. Provider outages return a retryable failure. Event IDs are deduplicated in the same transaction as status changes. Authoritative subscription reads, exact custom binding and plan ID determine access, not event order or a return URL.
- PayPal simulator mock events cannot use postback verification. Tests with the local adapter validate our behavior, not PayPal's real cryptography or sandbox acceptance.
- Successful signed sale events record sale-ID → subscription-ID bindings. Refunds can contain only `sale_id`; those look up the recorded original sale. A refund before its sale event returns HTTP 500 and is not marked processed, allowing provider retry. Conflicting sale bindings are rejected. Refund/reversal holds survive later ACTIVE events and new signup attempts.
- PayPal API correlation IDs are logged without tokens, secrets or full customer response bodies. Back up `subscriptions`, attempts, sale bindings and event receipts with the normal database backup.

## Refund/manual reconciliation

This is a conservative access hold, not a refund or accounting system. No money is moved by the reconciliation tool.

1. Investigate the payment/refund/reversal in the matching PayPal Business account. Verify the owner, subscription ID, sale ID and dispute/case outcome from the provider. Do not guess a binding from an email address or plan name.
2. For an unmapped sale, recover/resend the original sale event from PayPal, then resend the refund/reversal. Keep the listener healthy and inspect delivery status. PayPal documents delivery retries up to 25 times across three days; do not rely on retries beyond that. An unresolved event must be tracked by the operator, not treated as processed. If the required event cannot be recovered, keep the hold/access issue open and reconcile with PayPal support. This build has no manual sale-binding editor and does not use deprecated Payments v1 sale lookups.
3. Refresh the account's authoritative subscription state from the server:

   ```sh
   php tools/paypal-reconcile.php --user=123
   ```

4. Only after the refund/reversal case has actually been resolved and continued service is approved by the platform operator, release its hold with the external case reference:

   ```sh
   php tools/paypal-reconcile.php --user=123 --release-hold --reason=RESOLVED-CASE-REFERENCE
   ```

   This is privileged server access, not a merchant self-service button. It clears no money or dispute. The operation rolls back unless a fresh provider read confirms ACTIVE with no failed payments. Release and reason are audited. Never put personal financial details or secrets in the reason. Take a private backup first. Do not clear holds with ad hoc SQL or a browser request.

Provider event payload variants and missed events remain acceptance-test obligations. Automatic hold resolution, full transaction reconciliation, refund/proration controls and a graphical operator case workflow are not implemented.

## Tests and acceptance limits

```sh
PHP_BIN=php python tests/run-paypal.py
```

The suite uses disposable installs, a local transport adapter and an intercepted SDK test double. It checks state/tenant/plan binding, durable retry identity, original JSON verification transport, tampering, missing signatures, certificate allowlist, HTTP lifecycle and event dedup, early sale-ID refunds, persistent holds, explicit cancellation, operator hold release/audit, SDK eligibility/Create/onApprove/Cancel/error and desktop/mobile layouts. No real provider is contacted by the adapter.

Before enabling live payments, a configured sandbox app must pass: buyer approval/popup and eligibility; correct configured price/currency/interval/taxes; cancellation; suspension/payment failure and recovery; duplicate/out-of-order events; real signature verification; refunds/reversals including sale_id-only payloads; retry/recovery for missed events; provider switch prevention and old-provider events; keyboard/screen reader checks. Validate the live configuration separately. No genuine sandbox or live transaction, OAuth call or PayPal cryptographic acceptance was performed in development.

## Sources checked

- https://developer.paypal.com/docs/subscriptions/integrate/ (SDK v6, server-create and authoritative verification)
- https://developer.paypal.com/api/subscriptions/v1/subscriptions-create (Create contract, request-ID retention)
- https://developer.paypal.com/api/subscriptions/v1/subscriptions-cancel (explicit cancellation)
- https://developer.paypal.com/api/rest/authentication
- https://developer.paypal.com/api/rest/webhooks/rest (raw bytes, postback limitations and retries)
- https://developer.paypal.com/subscriptions/webhooks
- https://developer.paypal.com/payment-links-buttons/troubleshooting (CSP guidance)
- https://rewardloyalty.co/docs/5.x/plans-and-billing/billing-paypal (reference scope/behavior, not proof of parity)
- https://github.com/paypal-examples/paypal-dev-support/issues/15 (historical user report of refund payload differences, not an API guarantee)
