# ADR-0061: One setting for the merchant validation timeout, every dependent limit derived from it

- **Status:** Proposed. Requested by the integration with pbx-payments (2026-10-06), whose callback has to credit a balance and call external systems before it answers; it needs the owner's acceptance. Amends the 5-second total of plan 15.8.5, [ADR-0024](0024-pre-payment-validation.md), [ADR-0050](0050-linear-payment-flow-authorize-validate-capture.md) and [ADR-0058](0058-pre-payment-validation-phase-5.md), and the time limits of [ADR-0051](0051-checkout-and-card-payments-phase-4.md).
- **Date:** 2026-10-06
- **Source:** plan 15.8.5; ADR-0035, ADR-0036, ADR-0039 (container and deployment limits); the `cirox-payment-links` change (pbx-payments side: spec B1, design CX-1).

## Context

The pre-payment validation waited at most 5 seconds in total (2 seconds to connect), written as two numbers in `config/axispay.php` that had to be kept equal by hand. Longer limits were derived from that 5 seconds in several places, each hardcoded on its own:

| Place | Value | Why it was that value |
|---|---|---|
| `checkout.request_budget_seconds` | 50 s | validation + one bounded Stripe call (42 s) + margin |
| `checkout.confirmation_lease_seconds` | 90 s | outlives the request budget |
| `$timeout` of the payment jobs | 115 s | two Stripe calls + validation + margin |
| queue `retry_after` | 150 s | above every job timeout |
| `QUEUE_TIMEOUT` (worker) | 120 s | above the job timeout, below `retry_after` |
| `CompleteAuthorizedPaymentJob::$uniqueFor`, `ProcessProviderEventJob::$uniqueFor` | 600 s, 1500 s | the jobs' tries, backoffs and delay |
| nginx `fastcgi_read_timeout`, PHP-FPM `request_terminate_timeout`, `max_execution_time` | 60 s, 65 s, 30 s | above the request budget |

A merchant whose callback does real work (pbx-payments credits a SIP balance inside it) cannot answer in 5 seconds. Raising only the callback timeout would break the rest: the request would be killed by nginx, a job would be handed to a second worker while it still runs, a lease would expire under its holder.

Where the validation really runs (verified in the code): only inside `CaptureAuthorizedPayment`, reached by the payer's request, by `CompleteAuthorizedPaymentJob` and, through `SyncPaymentAttempt`, by `ProcessProviderEventJob` and `ReconcilePaymentAttemptsJob`. `CloseAttemptOfClosedLinkJob` (void only) and `CheckApiKeyConnectionsJob` never call the merchant.

## Decision

1. **One setting.** `AXISPAY_VALIDATION_TIMEOUT_SECONDS` (T): whole seconds, default **30**, allowed **5 to 60**. It is both the total time of the merchant call and the single source of every dependent limit. The connection timeout is `min(2, T)`.
2. **One place for the formulas.** `App\Modules\Payments\Data\ValidationTimeouts` derives:

   | Limit | Formula | T = 5 (as before) | T = 30 (default) |
   |---|---|---|---|
   | `checkout.request_budget_seconds` | T + 45 | 50 | 75 |
   | `checkout.confirmation_lease_seconds` | request budget + 40 | 90 | 115 |
   | `$timeout` of the jobs that run the validation | T + 110 | 115 | 140 |
   | queue `retry_after` (every connection) | job timeout + 35 | 150 | 175 |
   | `QUEUE_TIMEOUT` default | job timeout + 5 | 120 | 145 |
   | `CompleteAuthorizedPaymentJob::$uniqueFor` | 60 + 3 × job timeout + 150 + 45 | 600 | 675 |
   | `ProcessProviderEventJob::$uniqueFor` | 5 × job timeout + 925 | 1500 | 1625 |
   | nginx `fastcgi_read_timeout` | request budget + 10 | 60 | 85 |
   | PHP-FPM `request_terminate_timeout` | request budget + 15 | 65 | 90 |
   | php.ini `max_execution_time` | request budget − 20 | 30 | 55 |

   At T = 5 every value equals what the platform had before, so setting T to 5 is an exact rollback. The configuration files read `ValidationTimeouts` instead of holding numbers. `ReconcilePaymentAttemptsJob::$uniqueFor` (900 s, its schedule slot) and the jobs that never call the merchant keep their own fixed values, which stay below `retry_after` for every allowed T.
3. **Boot validator.** The application refuses to boot (`ValidationTimeouts::assertConfigured()`, from `PaymentsServiceProvider`) with a T outside 5 to 60, with a derived configuration key that no longer follows T, or with a queue `retry_after` below the derived value (a longer one is allowed). The error names the key.
4. **Container limits.** The values the shell needs (web server and PHP-FPM limits, the worker's default timeout) are printed by `php artisan axispay:validation-timeouts` and exported by `docker/app/entrypoint.sh`, so the formulas exist once, in PHP. nginx cannot read the environment and `/etc/nginx` is read-only for the container user, so the entrypoint writes `fastcgi_read_timeout` to `/tmp/axispay-timeouts.conf`, which `nginx.conf` includes; PHP-FPM and php.ini read `${AXISPAY_…}` environment variables. An explicit `QUEUE_TIMEOUT` is still accepted when it is at least the job timeout and below `retry_after`; otherwise the container refuses to start.
5. **Operations.** Every role (web, worker, scheduler) must run with the same T: jobs take their timeout from the configuration of the process that creates them. The stop grace period grows with it (`QUEUE_TIMEOUT` + 30 s in production, + 90 s all-in-one): the deployment guides give the numbers for the default.
6. **The payload names the payment attempt.** The validation body adds `data.payment.id` (`pay_…`, the attempt). It is the same in the immediate retry and in every later call about that attempt, and different for each new attempt of the link, so a merchant can recognize a repeated call and match the attempt with later events. `data.payment_link.id`, `data.payment_link.client_reference_id` and the top-level `livemode` were already sent.

## Consequences

- The default changes from 5 to 30 seconds for every merchant: a slow merchant server now holds the payer's page longer (up to the request budget of 75 s) and a pending validation holds the attempt's lease for longer. Merchants who want the old behavior set T to 5.
- The merchant-facing texts (panel help, failure messages, guides, OpenAPI) say "30 seconds by default"; the panel reads the value in force from the configuration.
- Tests that count seconds of the old budget, lease or job timeout pin T = 5 (`ValidationTestHelpers::pinValidationTimeout()`); `ValidationTimeoutsTest` proves the table above for every T from 5 to 60.
- A larger T needs a longer stop grace period, more FPM workers in the worst case (a payer request lasts longer) and `retry_after` longer than before; the first two are operator decisions, the last is derived.
- `docs/plans/master.md` still says "5 s" in section 15.8; this ADR supersedes it there until the plan is revised.
