<x-mail::message>
# Wema payment reconcile report

The `payments:reconcile-wema` command finished at **{{ $generatedAt }}**{{ $dryRun ? ' (dry run — no payments were updated)' : '' }}.

The Excel attachment compares **what we sent to Wema** (our `WEMA-*` orderId, invoice id, amount) with **what Wema/AlatPay returned** (transaction id, status, amount, metadata).

- Pending payments reviewed: **{{ $pendingCount }}**
- Fulfilled: **{{ $fulfilled }}**
- Skipped / unconfirmed: **{{ $skipped }}**
- No AlatPay transaction id: **{{ $noTxId }}**
- Errors: **{{ $failed }}**
- Wema completed charges with no matching pending payment: **{{ $unmatched }}**
- AlatPay transactions prefetched: **{{ $prefetched }}**

{{ config('app.name') }}
</x-mail::message>
