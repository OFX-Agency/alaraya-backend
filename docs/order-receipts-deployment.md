# Order receipts: rollout and legacy data

Backend implementation is in the working tree. No migration or reconciliation has been applied to the configured application database. Tests use SQLite in memory.

## Delivered behavior

Two additive migrations create order receipt/items tables, add original treasury/cancellation/legacy conversion metadata to transport receipts, and add receipt references to treasury deductions. A second migration changes transport receipt parent foreign keys from cascading deletes to restrictive deletes. SQLite uses equivalent BEFORE DELETE protection triggers because this Laravel version cannot replace SQLite foreign keys in place.

Deposit, transfer, manual deduction, and policy covenant creation/refund paths now acquire treasury locks inside transactions so they cannot overwrite concurrent receipt balance changes. New receipt accounting uses locked transactions, integer cents, immutable original treasury ownership, and signed adjustment/reversal deduction rows. Order creation uses a unique UUID idempotency key; transport creation locks and checks the associated policy. We deliberately do not add a unique policy constraint to a possibly populated legacy table: old duplicates must first be audited, and new transport writes are serialized on the policy. Existing duplicate receipts are not silently deleted.

Order receipts require an associated client; shared expenses are split explicitly into separate client receipts. All posted new receipt items increase client statement net_amount. Existing transport receipt amounts retain their historical net formula treatment. Legacy null-policy conversion intentionally brings those amounts into the new order-receipt net formula.

Cancellation retains financial history and returns cash once. Legacy transport edits/cancellation are blocked until a reviewed mapping identifies the original treasury. No backfill automatically matches free-text descriptions or creates missing deductions.

## Deployment procedure

1. Back up the database and test restoration. Inspect actual migration status/schema, especially whether `transport_receipts.policy_id` is nullable in production and whether previous manual modifications exist. Use a representative database copy for rehearsal.
2. Coordinate maintenance for receipt/order/policy/treasury writes and deploy the backend with the consuming frontend together. Existing clients must omit immutable transport fields on edits and send a cancellation reason on DELETE. Migrations must run before serving code that reads the new columns/tables.
3. Apply new migrations using `php artisan migrate --force` on the reviewed target environment. These migrations do not change balances, post expenses, or convert old receipts. Do not use migrate:fresh/reset.
4. Capture the read-only JSON report with `php artisan receipts:audit`. It includes receipt totals, duplicate policy links, unresolved deduction records, and treasury balances. Store reports securely because they contain operational financial data.
5. Review each ambiguous receipt/deduction association with business records. Confirm original treasury, receipt identity, existing manual refunds, repeated edits, and any corrupted historical deduction totals. Do not assume current order treasury is the original treasury. Historical discrepancies require separate approved correcting entries; the mapping command rejects mismatched sums.
6. Prepare a reviewed JSON mapping and run dry-run first, then apply. The command works one row per transaction; a failure leaves earlier committed rows intact. Correct the mapping and rerun; no balances are modified.
7. Compare coverage, item totals, signed deduction sums, and before/after balances. Every converted receipt must appear once in statements, treasury balances must be unchanged, and changes to net_amount must match the agreed new receipt formula. Keep unresolved receipts read-only.
8. Enable user access/resume writes after frontend compatibility, permissions, shared reports, cancellation behavior, and reconciliation have been verified.

## Reviewed mapping

```json
[
  {
    "receipt_id": 81,
    "treasury_id": 3,
    "deduction_ids": [109]
  },
  {
    "receipt_id": 82,
    "treasury_id": 3,
    "client_id": 7,
    "deduction_ids": [110, 113]
  }
]
```

```sh
php artisan receipts:reconcile /absolute/path/reviewed-mapping.json
php artisan receipts:reconcile /absolute/path/reviewed-mapping.json --apply
```

`receipt_id` is the existing transport receipt ID. All deductions must belong to the reviewed original treasury and their signed total must equal the current receipt total. Only legacy transport receipt deduction types are accepted. Deductions already owned by another receipt are rejected. A successful dry-run validates the same associations without writing.

For a policy-linked legacy receipt, apply only records its original treasury and links existing deductions. For an actually null-policy legacy receipt, `client_id` is required and must belong to the order. Apply creates an order receipt with preserved original creation date, every positive expense item, unique source ID, and explanatory legacy item descriptions. Unsupported old item categories use `other` without dropping the original field name. It links the existing deduction rows, marks the old receipt as migrated, and excludes the old row from transport aggregation. The old record is retained for provenance. Negative/nonpositive legacy cases require manual review.

The command never calls a posting/refund handler and never changes treasury balances. It does not repair already incorrect balances or deduction history. Reruns preserve converted records and reject conflicting mappings. Do not run backfill concurrently with financial writes; use the maintenance window above.

## Verification and limitations

Run the isolated suite:

```sh
php artisan test --filter='OrderReceiptTest|ListingSearchPaginationTest'
```

Validated: 17 tests, 169 assertions. It covers new receipts without policies, idempotency, signed adjustments/refunds, insufficient funds, client allocations, original treasury ownership, access controls, transport policy requirements, statement totals, database deletion protection, additive upgrades preserving existing data, dry-run/repeated legacy linking, and null-policy conversion without charging twice.

SQLite tests do not prove MySQL row-lock concurrency or production ALTER behavior. Before rollout, rehearse both migrations and simultaneous receipt posting/editing against a copy running the production database engine. The existing ActivityLogTest explicitly writes to the application's configured database; do not run the entire test suite against production credentials.

No production records have been inspected or converted. Actual exception counts and any required correcting entries remain deployment reconciliation work. The original suspected noloan variable spelling issue was rechecked and is not a defect.

## Rollback

Use a forward fix once live receipts exist. Keep new tables/history and disable writes during recovery; an old release cannot report the new charges accurately. The additive migration refuses destructive rollback when financial receipt data exists, and parent-delete protection is intentionally retained by the second migration's down method. Restore a backup only as a coordinated database/application recovery with all subsequent financial writes accounted for.
