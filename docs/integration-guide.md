# Integration guide

This guide takes a partner from nothing to a working payment in the sandbox. The full endpoint reference is in `openapi.yaml`.

## How a payment works

1. Your app calls `POST /codes` while the payer is online and has chosen an amount and a merchant. We ask your system to **hold** the funds, then return a code of digits.
2. Your app shows the code. The payer dials the voice number and keys the code, then `#`. No data connection is needed for this step.
3. We check the call and the code, then ask your system to **capture** the held funds for the merchant.
4. We send you webhooks (`code.redeemed`, then `transaction.settled` or `transaction.failed`). The payer hears only a generic thank-you; tell them the result yourself, from your webhook.

A code can be used once and expires after the time set on your account (default 10 minutes). Funds held for a code that is cancelled, expires or fails are released.

## Set up

1. Get an API key from the platform operator. Keep it secret; send it as `Authorization: Bearer <key>`.
2. Register your payers and merchants (these calls can be repeated safely):

       PUT /subscribers/cust-42      {"account_number": "2000000001", "bank_code": "058", "phone": "+2348012345678"}
       PUT /merchants/shop-7         {"name": "Corner Shop", "account_number": "3000000001", "bank_code": "011"}

   The subscriber's account is where funds are **held** when a code is issued; the merchant's is where captured funds are **credited**. Both an account number and a bank code are required. A merchant can also be created while issuing a code, by adding a `merchant` object (`name`, `account_number`, `bank_code`) to `POST /codes`.

3. Register a webhook endpoint and **save the secret from the response**; it is shown once:

       POST /webhook-endpoints       {"url": "https://you.example/hooks/offline"}

## Issue a code

    POST /codes
    Idempotency-Key: 6f1c...            (a fresh value per payment attempt)
    {
      "subscriber_reference": "cust-42",
      "merchant_reference": "shop-7",
      "amount_minor": 250000,
      "currency": "NGN"
    }

The response includes `code`. It is not stored in readable form and cannot be fetched again. If a request times out, send it again with the **same** `Idempotency-Key` and body: you get the same code back, never a second one. A different body with a used key is refused with `422`.

`amount_minor` is in the smallest unit (kobo for NGN), so `250000` is ₦2,500.00.

## Handle webhooks

Every delivery carries `Offline-Signature: t=<seconds>,v1=<hex>`. Verify it before trusting the body:

1. Read the **raw** request body, without parsing and re-serialising it.
2. Compute HMAC-SHA256 over `"<t>.<raw body>"` with your endpoint secret.
3. Compare with `v1` in constant time, and reject if `t` is more than five minutes from your clock.

The client libraries do this for you. Answer with any `2xx`. Anything else, or no answer within 10 seconds, is retried after 1 minute, 5 minutes, 30 minutes, 2 hours, 6 hours and 12 hours. The same event can arrive twice; keep the event `id` and skip repeats.

| Event | When | `data` |
| --- | --- | --- |
| `code.redeemed` | A valid call was verified | Code |
| `transaction.settled` | Your system captured the funds | Transaction |
| `transaction.failed` | Capture was declined, or you reported the hold released | Transaction |
| `code.expired` | The code reached its deadline unused | Code |

## States

`issued` → `redeemed` → `settled` or `failed`. An `issued` code can also become `cancelled` or `expired`. A `redeemed` code whose capture result is missing stays `redeemed` until reconciliation settles or fails it, so a short delay between `code.redeemed` and the final event is normal. Read `GET /transactions/{id}` if you need the current answer.

## Errors

Errors look like `{"error": {"code": "...", "message": "..."}}`.

| Status | `code` | Meaning |
| --- | --- | --- |
| 401 | `unauthenticated` | Missing or wrong key |
| 403 | `tenant_suspended` | Your account is suspended |
| 404 | `not_found` | Unknown id or reference, or it belongs to another account |
| 409 | `not_cancellable` / `request_in_progress` | Wrong state, or the first copy of this request is still running |
| 422 | `idempotency_key_required` / `idempotency_key_reused` | Missing key, or key reused with a different body |
| 422 | `settlement_rejected` | Your system refused the hold, or the amount is above your limit |
| 422 | (validation) | `{"message", "errors"}` listing the bad fields |
| 429 | | Too many requests; retry after the `Retry-After` header |

## Going live

Live accounts are never settled by the simulator. The platform operator connects your settlement system before your account is switched to live. Your system must make `capture` safe to repeat for the same transaction reference and must be able to report what happened to a capture, because we ask when a result is lost.
