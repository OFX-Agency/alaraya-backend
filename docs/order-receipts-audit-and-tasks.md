# Order receipts: audit and implementation tasks

Date: 2026-10-10. Initial audit/proposal; implementation now exists in the working tree. See frontend-order-receipts.md and order-receipts-deployment.md for the delivered behavior and rollout.

## Scope and requirement

Review covers the order, policy, transport receipt, treasury deduction, and client statement paths in this repository. It is a static code review, not a production database reconciliation or a full-project security audit. The initial review did not connect to the application database. Implementation tests subsequently ran isolated migrations and fixtures in SQLite memory only. Existing ActivityLogTest explicitly writes to the application database, and phpunit.xml does not configure a separate database.

Allow multiple itemized receipts against a shipping order before policies exist. Deduct each receipt from an authorized treasury and show it in the client account statement. Transport receipts remain attached to a specific policy. Order receipts may also be added after policies exist; creating a policy must not move or charge an existing order receipt again.

## Confirmed findings

1. TransportReceiptController::store accepts nullable policy_id, but the original transport_receipts migration declares it non-nullable. The actual deployed schema must be inspected before assuming null-policy legacy receipts exist.
2. Receipt updates locate a treasury deduction by type, order description, and latest row. Multiple receipts per order make this ambiguous. The update also changes the description, so subsequent updates can create another deduction row. This can make deduction history inconsistent with the balance.
3. Updates allow ship_order_id changes while calculating treasury impact from the old order, and do not revalidate the policy/order pair or settled state. They also read receipt amounts before locking the receipt.
4. Creation does not lock treasury/policy rows; the one-receipt-per-policy rule is only an application check. Concurrent requests can overspend or create duplicate policy receipts.
5. Receipt deletion does not reverse its treasury impact. Foreign keys also cascade receipts when an order or policy is deleted, bypassing an explicit receipt reversal workflow.
6. Receipt operations use the order's current first treasury. The order treasury can later be reassigned; refunds must use the treasury originally charged.
7. Generic treasury deduction update/delete routes can change or remove receipt-generated deductions independently of receipt amounts. These must be blocked or routed through the receipt workflow.
8. Client statements already total all order transport receipts, but net_amount excludes transport receipt totals. Whether the new receipt total affects net_amount needs an explicit business rule; do not silently change the historical formula.
9. Correction after implementation review: the totalOrdersNoloans initialization and usage are consistent. The initial suspected typo was not a defect. Statement tests cover the existing total.
10. Receipt list/show/create/update lack the treasury access scoping used by order/policy lists. Authentication alone does not enforce access to a particular order or treasury.

## Recommended design

Add a separate order receipt resource instead of weakening transport receipt requirements. Keep existing transport receipt IDs, policy associations, and API meaning intact.

Proposed additive schema:

- order_receipts: id, ship_order_id, treasury_id (original charged treasury), unique receipt_number, receipt_date, total DECIMAL(15,2), status, created_by, cancellation metadata, optional notes, timestamps.
- order_receipt_items: id, order_receipt_id, item_code or approved category, description, amount DECIMAL(15,2). Server computes total; require at least one positive item. Confirm the actual allowed item list before implementation.
- treasury_deductions: nullable order_receipt_id and transport_receipt_id references. Index references; for the existing mutable-deduction design enforce one deduction per receipt where supported. Prevent a row from referring to both types. Existing unrelated deductions retain null references.
- transport_receipts: additive original treasury reference, nullable for unresolved legacy records; unique policy_id only after duplicate audit. Keep policy_id required for new transport receipts. Do not make it nullable merely to support order receipts.

Use explicit foreign keys and deletion restrictions for new financial records. Prefer cancellation with a separately recorded reversal and activity log over physical deletion. Document reversal sign/type and ensure treasury reports include it correctly. Never mix a signed reversal with another balance refund that charges/refunds twice.

Implement receipt creation, amount adjustment, and cancellation through a shared transactional service. Lock receipt/policy and treasury rows in a consistent order, check available funds after acquiring locks, use decimal-safe arithmetic, and make retries idempotent. Keep order/policy/original-treasury associations immutable after posting. An order's future treasury reassignment must not alter existing receipt ownership.

## Tasks in implementation order

- [ ] T1 — Finalize business rules: approved item names, who may create/update/cancel, treatment of receipts after policy settlement, whether amounts are charged to the client, handling multiple clients on an order, and the revised net_amount formula if required. Confirm one transport receipt per policy remains intended.
- [ ] T2 — Build a read-only preflight report: deployed schema/migration status; null, missing, mismatched, and duplicate policy links; receipt item totals; order/treasury associations; deduction candidates; current balances and statement totals. Export exceptions for review. Do not print credentials.
- [ ] T3 — Add the new tables and nullable links with new migrations. Do not edit previously applied migrations. Use restrictive deletion behavior for new financial relationships. Test both a fresh install and an upgrade from a representative database copy.
- [ ] T4 — Add receipt models, item validation, transactional service, idempotency, original treasury tracking, permissions, and activity logs. Add order receipt CRUD/read routes plus cancellation; reject policy_id on this resource.
- [ ] T5 — Repair transport receipt validation, locking, deduction linkage, settled-state checks, and immutable associations. Prevent order/policy deletion from silently destroying posted receipts. Block independent generic deduction edits/deletes for linked receipts, including unresolved historical receipt deductions until reconciled.
- [ ] T6 — Extend order details and client statements with order_receipts, order_receipts_sum, and total_sum_order_receipts. Preserve existing transport fields and count each receipt once. Verify existing noloan totals. Include new receipts in shared report output. Change net_amount only under the agreed rule and document compatibility.
- [ ] T7 — Update consuming frontend (not present here), API/Postman examples, error responses, and permissions. Add order-level item entry and receipt listing without requiring a policy, and separate order receipt/transport receipt sections in statements.
- [ ] T8 — Implement reviewed legacy backfill as a separate resumable command with dry-run, explicit source-to-target mapping, batch transactions, checkpoints, and rerun protection. No financial posting during backfill.
- [ ] T9 — Verify against a dedicated test database: no-policy order receipt; required matching policy for transport; insufficient balance rollback; multiple receipts; partial/repeated updates; cancellation once; duplicate request retry; concurrent posting/editing; unauthorized treasury access; changing order treasury; protected parent deletion; generic deduction restrictions; statement totals; migration upgrade and backfill reruns.
- [ ] T10 — Deploy in stages, reconcile, enable the new endpoints/UI, and record a non-destructive rollback procedure.

## Existing data and migration rollout

1. Take a restorable backup and test restoration. Run preflight against a database copy first. Capture counts, sums per receipt category, original treasury balances, and client statement output.
2. Apply additive migrations while the feature is disabled. Existing transport receipts and deductions remain intact. Do not run migrate:fresh, reset, or destructive rollback in a populated environment.
3. Keep valid policy-linked transport receipts as transport receipts. Review duplicate policy links before adding a unique constraint; never delete financial duplicates just to make a migration pass.
4. If actual null-policy legacy receipts exist, do not guess a policy from order/date. Review and classify them as order receipts, preserve amounts/dates/provenance and every nonzero item, and retain a unique legacy source mapping. Keep unresolved records visible as legacy exceptions until resolved. Ensure migrated records are excluded from the old statement aggregation so they appear once.
5. Link existing deductions only when evidence uniquely identifies the receipt and original treasury. Descriptions, amount, and timestamps can suggest candidates but are not proof when there are multiple receipts, repeated edits, or treasury reassignment. Leave ambiguous links unresolved and require manual reconciliation; do not fabricate a deduction.
6. Backfill only metadata/classification. Do not call receipt posting handlers, deduct cash again, refund cash, or recalculate balances from incomplete deduction history. Historical discrepancies are a separate reviewed correction with an audit trail.
7. Compare pre/post record coverage and sums, ensure every moved item appears exactly once, and verify treasury balances are identical immediately before and after backfill. Confirm any changed statement grouping preserves totals unless a separately approved business rule intentionally changes them.
8. Enforce additional constraints only after exceptions are resolved. Cut over clients and prevent old API paths from posting order-only transport receipts. Coordinate a short write pause or a precise cutoff so backfill and live writes cannot process the same receipt.
9. On rollback, disable new writes and keep the new tables and posted financial records. An old application release that cannot read them is not a safe full rollback after live posting. Use a compatibility release/forward fix; never drop populated receipt tables to roll back a feature.

## Decisions still needed

- Exact approved receipt items; the request says specific items but does not name them.
- Whether all order receipt items are recoverable from the customer and how net_amount should change. Displaying an expense does not by itself define its effect on customer debt.
- For multi-client orders, whether the expense is order-wide or allocated to a particular client.
- Confirmation of actual deployed schema/data and any historical manual treasury adjustments.
