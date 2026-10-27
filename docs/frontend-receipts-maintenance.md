# Frontend maintenance guide: order and transport receipts

Based on commit `3435089e2c2974cf84485923d51e645645468b00` (`feat: Implement order receipts functionality with API endpoints and frontend integration`).

This guide describes the consuming frontend changes and the behavior to preserve during maintenance. This repository contains the Laravel backend; it does not contain the receipt screens. Frontend component names, routing, and cache keys below must be adapted to that application's conventions.

Related documentation:

- [API integration and complete payload examples](frontend-order-receipts.md)
- [Backend deployment and legacy reconciliation](order-receipts-deployment.md)

## 1. Screens affected

| Screen | Required behavior |
| --- | --- |
| Shipping-order details | Add **إيصالات الأوردر** with list, details, create, edit, and admin cancellation. Allow multiple receipts before or after policies exist. |
| Order receipt form | Select an associated client and treasury; enter date, notes, and one or more expense items. |
| Transport receipts | Keep **إيصالات النقل** separate and require a policy belonging to the selected order. Update edit and cancellation requests as described below. |
| Client account statement | Render order receipts separately, include their server-provided totals, and preserve cancelled history. |
| Shared client statement and any frontend print/export view | Use the same receipt grouping and totals as the authenticated statement. |
| Treasury history | Show signed deductions, adjustments, and reversals, with navigation to the originating receipt where its ID is available. |
| Order/policy edit and delete dialogs | Explain receipt-history conflicts returned by the server. Cancellation does not remove financial history or unblock these operations. |

Creating a transport policy must leave existing order receipts in their original section without posting them again. If an expense is shared between clients, enter a separate receipt for each allocated share; copying the full amount to each client charges it multiple times.

## 2. API contract to preserve

Use the existing authenticated API client and Sanctum configuration. All paths in this guide include `/api`.

| Method | Path | Result |
| --- | --- | --- |
| GET | `/api/order-receipts/categories` | `{ success: true, data: [{ code, label }] }` |
| GET | `/api/order-receipts?ship_order_id=42&per_page=20&page=1` | Paginated list, newest ID first |
| GET | `/api/order-receipts/{id}` | Receipt details |
| POST | `/api/order-receipts` | Create: HTTP 201; successful idempotent replay: HTTP 200 |
| PATCH or PUT | `/api/order-receipts/{id}` | Partial update |
| POST | `/api/order-receipts/{id}/cancel` | Admin cancellation with `{ "reason": "سبب الإلغاء" }` |

The list supports `ship_order_id`, `client_id`, `status` (`posted` or `cancelled`), `per_page` (1–100, default 20), and `page`. Rows are at `response.data.data` in the JSON body; pagination metadata is at `response.data.current_page`, `last_page`, and `total`. With Axios, unwrap its outer `response.data` first. Detail and write JSON bodies contain the receipt directly at `data`.

Order receipt fields used by the UI:

| Field | Maintenance rule |
| --- | --- |
| `id` | Use for resource URLs and row identity. |
| `receipt_number` | Display as an opaque UUID string, not a sequential integer. |
| `ship_order_id`, `client_id`, `treasury_id` | Immutable after creation; `treasury_id` identifies the original charged treasury. |
| `receipt_date` | Date-only `YYYY-MM-DD`; avoid timezone conversion that shifts the calendar day. |
| `total`, `items[].amount` | Decimal strings; keep precision during parsing and display. |
| `status` | `posted` or `cancelled`; cancelled receipts are read-only. |
| `items` | Expense rows with `category`, optional `description`, and `amount`; server item IDs can change after edits. |
| `notes` | Optional text. |
| `cancelled_at`, `cancelled_by`, `cancellation_reason` | Cancellation history, when present. |
| `client`, `treasury` | Optional loaded relationships; do not assume every write response includes them. |

Creation replay and cancellation responses load `items` but may omit `client` and `treasury`. Refresh details before relying on those relationships. The server hides `idempotency_key` and `request_hash`; keep the pending creation key in frontend state.

Shipping-order list/detail responses also embed `order_receipts` with `items` and `client`. Do not concatenate those rows with a separately fetched receipt list. Use one collection for a view, or deduplicate by receipt `id`.

## 3. Form validation and request construction

Fetch category codes and Arabic labels from `/api/order-receipts/categories`. Keep unknown historical codes readable with a code/description fallback. `bill_of_lading_amendment` means the shipping document; it is not a transport `policy_id`.

Populate client options from the order's `shipLineClients` relationship and use each link's **`client_id`**, not the link record's `id`. Populate treasury options from the order's `treasuries`, respecting the user's treasury access. Always send both selected IDs, even when only one option exists. An admin must also choose a client and treasury associated with the order.

| Input | Validation |
| --- | --- |
| `receipt_date` | Required on create; `YYYY-MM-DD`. |
| `items` | 1–100 rows; an edit supplying items replaces the entire collection. |
| `items[].category` | Required; a code returned by the category endpoint. |
| `items[].amount` | Positive decimal, minimum `0.01`, maximum `9999999999999.99`, at most two decimal places. Send as a decimal string without separators or exponent notation. |
| Receipt total | Maximum `9999999999999.99` across all items. The server calculates the authoritative total. |
| `items[].description` | Optional, maximum 500 characters; nonblank when category is `other`. |
| `notes` | Optional, maximum 2,000 characters; send `null` to clear it. |
| Cancellation `reason` | Required string, maximum 2,000 characters. |

Build explicit payloads instead of spreading a receipt response or the whole form object into a request.

Create example:

```json
{
  "ship_order_id": 42,
  "client_id": 7,
  "treasury_id": 3,
  "receipt_date": "2026-10-27",
  "idempotency_key": "ba82ac06-e4a8-4f4e-9448-724b4b56e835",
  "notes": "مصاريف الأوردر",
  "items": [
    { "category": "customs_clearance", "amount": "100.25" },
    { "category": "other", "description": "رسوم مستند", "amount": "25.00" }
  ]
}
```

Edit example:

```json
{
  "notes": "تصحيح المصروفات",
  "items": [
    { "category": "port_receipts", "amount": "150.00" }
  ]
}
```

Editable fields are only `receipt_date`, `notes`, and `items`. Omitted fields remain unchanged. When sending `items`, include every intended row and only `category`, `description`, and `amount` per item. Do not include item `id` or `order_receipt_id`.

Never send `policy_id`, `total`, or `status` on order receipt writes. Also omit `ship_order_id`, `client_id`, `treasury_id`, and `idempotency_key` on edits. Keep a stable local row key while editing, then replace the saved collection from the server response.

Use integer cents or an existing decimal library for a local total preview. Keep server totals authoritative; JavaScript floating-point addition can introduce errors.

## 4. Submission, retry, edit, and cancellation behavior

### Create

1. Validate the form, generate one UUID with `crypto.randomUUID()`, and snapshot the exact payload for this submission.
2. Disable duplicate submission while the request is in flight.
3. On HTTP 201 or a successful HTTP 200 replay, show success, clear the pending submission, and refresh affected data.
4. On a timeout, connection loss, or another failure with an uncertain server outcome, retain the key and payload. Retry that same submission unchanged. Preserve the pending snapshot across navigation/reload if the UI supports recovery there.
5. Do not silently generate a new key or permit a changed payload to reuse the unresolved key. A receipt may already have been charged even though the response was lost.
6. On HTTP 409, show the server message and refresh receipts to resolve the conflict before starting another charge. The same key with different content or a different creator conflicts; a concurrent key collision can also return 409.

Only begin a new submission after the prior outcome is resolved. After a confirmed validation rejection, allow corrections; a corrected payload can start a new submission. Automatic network retries must reuse the original snapshot and key.

### Edit

Enable editing only for posted receipts the user can access. The backend posts only the difference between old and new totals: raising `125.25` to `150.00` deducts `24.75`; lowering it refunds the difference to the original treasury. Repeating the same edit does not apply another financial difference.

Serialize writes to a receipt in the UI and refetch before retrying an edit after an uncertain result. This commit provides no frontend revision token or optimistic concurrency contract; a stale complete item list can overwrite a newer edit.

### Cancel

Show cancellation controls only to admins, collect a reason, and wait for server success. Keep the receipt visible with its cancelled badge, reason, and timestamp; disable edit/cancel actions afterward. Repeated cancellation does not refund twice. Order receipts have no DELETE endpoint and no restore endpoint in this commit.

Changing an order's treasury affects future receipts. Existing receipt adjustments and refunds continue to use the receipt's original `treasury_id`, including when that treasury is no longer assigned to the order.

## 5. Refresh and cache maintenance

After confirmed create, edit, or cancellation, invalidate/refetch all affected views:

- Receipt detail and matching receipt lists, including filtered and paginated variants.
- Shipping-order detail and any cached order list embedding receipts.
- The receipt's original treasury balance and deduction history.
- Visible client account statements and their totals for the affected client/order, including unfiltered statements.
- Any open frontend print/export preview generated from those statements.

Include order, client, status, page, and page size in receipt-list cache identities. Include statement filters in statement cache identities. Reset the page appropriately when filters change, and prevent an older response from replacing results for newer filters.

Do not optimistically change treasury balances, client debt, or remove cancelled rows. If the write succeeds but a refresh fails, retain the confirmed success state and offer to reload; retrying the creation as a new submission can charge again. An already-open shared report may need reloading to show new data.

## 6. Client statements and treasury history

For `/api/reports/client-account-statements`, prefer exact `client_id` for a specific client screen. Existing `number` and partial `clientName` filters remain available. If both client filters are sent, both constrain the selected receipt rows.

Render these new fields:

- `data.ship_orders_details[].order_receipts`: receipts with items and client details, including cancelled history.
- `data.ship_orders_details[].order_receipts_sum`: posted order receipt total for that returned order, as a decimal string.
- `data.total_sum_order_receipts`: posted total across returned orders, as a decimal string.

The backend already calculates:

```text
net_amount = total_sum_noloan - total_covenant_amount
             + total_driver_extras + total_sum_order_receipts
```

Display returned `net_amount`, which retains its existing numeric type. Do not add order receipts again in the browser. Cancelled receipts contribute zero. Existing transport receipt totals retain their historical exclusion from this formula.

For multi-client orders, the new receipt amounts follow the selected client. Existing noloan, covenant, driver, and transport amounts retain their historical order-level semantics; do not label them as newly allocated client shares. Shared client statements use the same backend report logic and need the same rendering behavior.

Converted legacy receipts belong in the order receipt section once. Records marked `migrated_to_order_receipt_at` must not be merged back into active transport totals. The transport list and statement aggregations already exclude migrated records; direct historical reads can still expose them.

Treasury history uses `order_receipt_id` or `transport_receipt_id` to identify the origin. Types include `order_receipt`, `order_receipt_adjustment`, `order_receipt_reversal`, and corresponding `transport_receipt*` types. Positive amounts deduct cash; negative amounts return cash. Sum signed amounts, preserving minus signs in the display. Do not total absolute values or allow linked receipt deductions to be edited/deleted through generic deduction forms; those operations return 409. For historical receipt deductions lacking a source ID, show a read-only reconciliation message.

## 7. Transport receipt compatibility

These existing frontend workflows must change with this commit:

| Workflow | Contract and UI change |
| --- | --- |
| Create | `POST /api/transport-receipts` requires `ship_order_id` and matching `policy_id`. Optional `treasury_id` must belong to the order; otherwise the backend selects the order treasury. |
| Expense validation | Existing expense columns accept nonnegative values up to `999999.99`, with at most two decimal places; the combined total must be positive. Keep the existing flat expense-field payload rather than using order receipt `items`. |
| Edit | PATCH/PUT accepts expense fields only. Omit `ship_order_id`, `policy_id`, and `treasury_id`, even when unchanged. Omitted expenses remain unchanged. |
| Cancel | `DELETE /api/transport-receipts/{id}` now means admin cancellation and refund. Send JSON `{ "reason": "سبب الإلغاء" }`. With Axios use `delete(url, { data: { reason } })`. Retain the returned row. |
| State display | Transport receipts use `cancelled_at` for cancellation, unlike order receipts' `status`. Do not require an order-style `status` field on transport responses. |
| Policy eligibility | Settled policies or policies with any receipt history reject creation with 409. A cancelled receipt still occupies that policy's history. |
| Edit/cancel restrictions | Cancelled, migrated, unreconciled, missing-policy, settled-policy, or mismatched-policy records may return 409. Mark known ineligible rows read-only and surface the server message. |
| List handling | The transport list returns an array under `data`, unlike the paginated order receipt list. Cancelled transport records remain visible. |

An unresolved legacy receipt with no original treasury requires backend reconciliation. Do not repost it as a new receipt to work around the restriction. Reconciliation metadata changes do not authorize the frontend to post or refund cash.

Orders and policies with receipt history cannot be deleted. A policy with receipt history cannot move to another order. Replacing an order's client is blocked when it would remove a client with order receipt history. Show these server conflicts in the originating form/dialog, including after cancellation.

## 8. Error handling

| HTTP status | Frontend response |
| --- | --- |
| 401 | Use the application's existing authentication/session-expiry flow; retain an unresolved submission safely for recovery. |
| 403 | Explain denied treasury access or admin-only action; refresh permissions where appropriate. Backend authorization remains authoritative. |
| 404 | Show that the resource is unavailable and refresh the parent list. |
| 409 | Display `message`, refresh current state, and resolve the state/idempotency/history conflict. Do not automatically create a replacement receipt. |
| 422 | Map Laravel `errors` to controls, including `items.0.amount` and `items.0.description`; show form-level errors too. Insufficient funds and unrelated client/treasury selections use this status. |
| Network error / uncertain server failure | For creation, retain the original key and exact payload; offer recovery using that submission. |

Keep submitted item ordering stable while mapping indexed validation errors. The backend can return insufficient funds under `treasury_id` and money-limit errors under `amount` or `items`; show a form-level summary when no matching control exists.

## 9. Frontend regression checklist

Run these checks in the consuming frontend using a dedicated test environment. This is an acceptance checklist, not a claim that frontend tests exist in this repository.

- [ ] Create an order receipt on an order with zero policies; add multiple receipts and multiple expense rows.
- [ ] Create a policy afterward; existing order receipts remain unchanged and no cash is charged again.
- [ ] Verify client-link IDs are not sent as client IDs, and unavailable/unrelated treasuries cannot be selected successfully.
- [ ] Validate `other` descriptions, row limits, decimal precision, total overflow, date-only display, and indexed server errors.
- [ ] Simulate a lost create response; retry the same key/payload and confirm one receipt and one charge. Resolve 409 without a new automatic charge.
- [ ] Reject insufficient funds without adding a local receipt or changing the displayed balance.
- [ ] Edit notes/date without resending items; edit items with complete replacement and verify only the total difference affects cash.
- [ ] Cancel as admin, verify reason/history and one refund; verify non-admin cancellation is denied.
- [ ] Reassign the order treasury, then edit/cancel an existing receipt; refresh the original treasury's balance/history.
- [ ] Filter a multi-client statement by exact client ID; verify allocation, cancelled history, and server `net_amount` without double addition.
- [ ] Check authenticated, shared, and any print/export statement views for matching receipt grouping and totals.
- [ ] Verify positive/negative treasury history and blocked direct deduction editing/deletion.
- [ ] Verify transport policy requirements, expense-only edits, DELETE reason body, and cancelled-row rendering.
- [ ] Verify settled/unreconciled/migrated transport rows and protected order/policy/client changes show useful conflict messages.
- [ ] Switch filters/pages rapidly and recover from refresh failure after a successful write without duplicate submission.

## 10. Maintaining this guide

When backend receipt behavior changes, review these sources and update the API integration guide, this guide, frontend request builders, and relevant acceptance checks together:

| Backend source | Contract it controls |
| --- | --- |
| [routes/api.php](../routes/api.php) | Routes and authentication boundary |
| [OrderReceiptController.php](../app/Http/Controllers/OrderReceiptController.php) | Validation, pagination, permissions, idempotency, edits, cancellation |
| [OrderReceipt.php](../app/Models/OrderReceipt.php), [OrderReceiptItem.php](../app/Models/OrderReceiptItem.php) | Serialization, decimal/date casts, category definitions |
| [TransportReceiptController.php](../app/Http/Controllers/TransportReceiptController.php), [TransportReceipt.php](../app/Models/TransportReceipt.php) | Legacy compatibility, flat expense fields, edit/cancel restrictions |
| [ReceiptAccounting.php](../app/Services/ReceiptAccounting.php) | Signed accounting, original treasury authorization, money limits |
| [ReportsController.php](../app/Http/Controllers/ReportsController.php) | Statement filters, totals, shared-report behavior |
| [ShipOrderDataController.php](../app/Http/Controllers/ShipOrderDataController.php), [PolicyController.php](../app/Http/Controllers/PolicyController.php) | Embedded receipt data and parent-history restrictions |
| [TreasuryOperationsController.php](../app/Http/Controllers/TreasuryOperationsController.php) | Protection of receipt-generated deductions |
| [OrderReceiptTest.php](../tests/Feature/OrderReceiptTest.php) | Backend regression examples supporting these workflows |

Coordinate frontend deployment with the backend migrations and legacy reconciliation described in the deployment guide. Keep unresolved legacy receipts read-only until the backend is reconciled.
