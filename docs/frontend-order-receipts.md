# Frontend integration: order receipts

Implemented 2026-10-10. All endpoints below use the `/api` prefix and the existing Sanctum authentication. This repository contains the backend; implement these UI changes in the consuming frontend.

## Behavior

Add an **إيصالات الأوردر** section to shipping-order details. It must be usable before any transport policies exist and remain usable afterward. Keep **إيصالات النقل** as a separate section requiring a policy. Creating a policy does not move, duplicate, or charge existing order receipts.

An order receipt has one client, one original treasury, a date, notes, and multiple positive expense items. Each amount is charged to the selected client in the client statement. If an expense is shared, create separate receipts for each client's allocated share; do not copy the full expense to every client.

Select clients from `shipLineClients` and treasuries from the order's `treasuries`. Even with one client/treasury, send their IDs explicitly. After posting, order/client/treasury associations are immutable. Changing the order's treasury affects future receipts only.

## API

| Method | Endpoint | Purpose |
| --- | --- | --- |
| GET | `/order-receipts/categories` | Supported expense codes and Arabic labels |
| GET | `/order-receipts?ship_order_id=42&per_page=20&page=1` | Paginated receipts |
| POST | `/order-receipts` | Create and charge once |
| GET | `/order-receipts/{id}` | Receipt details |
| PATCH / PUT | `/order-receipts/{id}` | Edit date, notes, or replace expense items |
| POST | `/order-receipts/{id}/cancel` | Admin cancellation and refund |

List filters: `ship_order_id`, `client_id`, `status` (`posted` or `cancelled`), `per_page` (1–100, default 20), and `page`. List response is `{ "success": true, "data": { "data": [...], "current_page": 1, "last_page": 1, "total": 1, ... } }`. Detail/write responses use `{ "success": true, "data": { ...receipt } }`.

Load the category list from the endpoint rather than maintaining a second enum. Response:

```json
{
  "success": true,
  "data": [
    { "code": "customs_clearance", "label": "تخليص جمركي" },
    { "code": "port_receipts", "label": "إيصالات ميناء" },
    { "code": "other", "label": "أخرى" }
  ]
}
```

The actual endpoint includes customs clearance, shipping bill amendment, brokers, VGM, X-ray, data entry, yard, port authority, weight, agency, explosives, scales, cashier, reweighing, Sina Marine, tunnel/ferry, container repairs, port receipts, X-ray permission, allocation, guarantee, form, official receipts, and other expenses. Road/driver expense fields remain in transport receipts. `bill_of_lading_amendment` refers to the shipping document, not the application's transport `policy_id`.

## Create

```json
{
  "ship_order_id": 42,
  "client_id": 7,
  "treasury_id": 3,
  "receipt_date": "2026-10-10",
  "idempotency_key": "ba82ac06-e4a8-4f4e-9448-724b4b56e835",
  "notes": "مصاريف قبل إصدار بوالص النقل",
  "items": [
    { "category": "customs_clearance", "amount": "100.25" },
    { "category": "other", "description": "رسوم استخراج مستند", "amount": "25.00" }
  ]
}
```

Generate `idempotency_key` once with `crypto.randomUUID()` per new submission. Retain it and the exact payload while retrying a timed-out/failed network request. A successful retry returns the existing receipt with HTTP 200; initial creation returns HTTP 201. A key reused with different content or by another user returns 409. After confirmed creation, generate a new key for a genuinely new receipt. Do not silently regenerate the key after a timeout. A concurrent conflicting key can return 409; refresh the list before attempting another charge.

Use decimal strings, at most two decimal places. Each item must be greater than zero, with up to 13 integer digits; total must fit `DECIMAL(15,2)`. Require 1–100 items. A category of `other` requires a nonblank description; descriptions allow 500 characters. Notes allow 2,000 characters. Dates use `YYYY-MM-DD`. Do not send `policy_id`, `total`, or `status`; the server computes and controls these values.

Receipt response fields:

```json
{
  "success": true,
  "data": {
    "id": 12,
    "receipt_number": "1faf523b-8509-4b61-ac4c-bf97fd5f32ba",
    "ship_order_id": 42,
    "client_id": 7,
    "treasury_id": 3,
    "created_by": 1,
    "receipt_date": "2026-10-10",
    "total": "125.25",
    "status": "posted",
    "notes": "مصاريف قبل إصدار بوالص النقل",
    "items": [
      { "id": 21, "order_receipt_id": 12, "category": "customs_clearance", "description": null, "amount": "100.25" },
      { "id": 22, "order_receipt_id": 12, "category": "other", "description": "رسوم استخراج مستند", "amount": "25.00" }
    ]
  }
}
```

Timestamps and loaded `client`/`treasury` may also be present. Receipt numbers are UUIDs; display them as opaque strings. `idempotency_key` and `request_hash` are not returned. Money fields `total`, item `amount`, and the new statement receipt totals are decimal strings. Use the server's totals; avoid adding JavaScript floating-point amounts as authoritative money.

## Edit and cancel

A partial edit may contain `receipt_date`, `notes`, and/or `items`. Omitted items remain unchanged. If `items` is supplied, it replaces the entire item list; item IDs may change. Do not submit old item IDs or `order_receipt_id` inside item objects.

```json
{
  "notes": "تصحيح المصروفات",
  "items": [ { "category": "port_receipts", "amount": "150.00" } ]
}
```

The server charges/refunds only the difference. Repeating the same edit has no additional financial impact. Do not send `ship_order_id`, `client_id`, `treasury_id`, `idempotency_key`, `total`, `policy_id`, or `status` in an edit.

Cancel using `POST /order-receipts/{id}/cancel` with `{ "reason": "سبب الإلغاء" }`. Cancellation is admin-only. Keep the row visible with a cancelled badge, cancellation reason/date, and disabled edit actions. The response has `status: "cancelled"`, `cancelled_at`, `cancelled_by`, and `cancellation_reason`. Repeated cancellation does not refund again. There is no physical delete endpoint for order receipts.

After each write, refresh receipt lists, the order, the treasury balance/deductions, and any visible statement. Do not optimistically alter a cash balance before the server confirms success.

## Client statement changes

`GET /reports/client-account-statements` supports existing `number` and `clientName` filters plus optional exact `client_id`. Prefer `client_id` for a specific client's screen; name matching remains a partial search.

New fields:

- `data.ship_orders_details[].order_receipts`: selected-client receipts with items and client details, including cancelled history.
- `data.ship_orders_details[].order_receipts_sum`: posted order receipt sum, decimal string.
- `data.total_sum_order_receipts`: posted sum across returned orders, decimal string.

The revised formula is:

```text
net_amount = total_sum_noloan - total_covenant_amount
             + total_driver_extras + total_sum_order_receipts
```

`net_amount` retains its existing numeric response type. Existing transport fields remain separate and transport totals retain their historical exclusion from this formula. Do not add order receipts to `net_amount` again in the browser. Cancelled order receipts contribute zero. Converted legacy receipts appear in the new section only and are excluded from old transport totals. Conversion to order receipts also brings those amounts into the new debt formula.

For multi-client orders, the **new order receipt amounts** are allocated by selected `client_id` (or matching client names). Existing noloan, covenant, driver, and transport figures retain their historical order-level semantics; this change does not redistribute historical order charges. Do not present the legacy figures as newly allocated per-client balances.

Order list/detail responses now include the Eloquent JSON relationship `order_receipts`, containing `items` and `client`. Client statement shared links reuse the same report logic, so render the same new section in shared reports. Keep cancelled history out of expense totals even when it is displayed.

## Transport receipt compatibility changes

- `POST /transport-receipts` requires `policy_id` belonging to `ship_order_id`. It accepts an optional associated `treasury_id`, otherwise uses the order's treasury.
- Expense amounts use at most two decimal places; each existing transport column supports up to `999999.99`. Total must be positive.
- Policy already settled or having receipt history returns 409. Cancellation retains that history; it does not permit posting a replacement against the same policy.
- `PATCH /transport-receipts/{id}` accepts expense fields only. Omit `ship_order_id`, `policy_id`, and `treasury_id`, even when unchanged.
- Existing `DELETE /transport-receipts/{id}` now performs admin-only cancellation and refund. Send a JSON body `{ "reason": "سبب الإلغاء" }`. It retains the record and exposes `cancelled_at` instead of deleting it.
- Unreconciled legacy receipts return 409 on edit/cancel. Display them read-only with a reconciliation-required message; never repost them as new receipts to bypass this restriction.
- Orders/policies with receipt history cannot be deleted. A policy with receipt history cannot move to another order. A client with order receipt history cannot be removed by replacing the order's client.
- Direct update/delete of receipt-generated treasury deductions returns 409. Link users to the originating receipt workflow instead.
- New treasury history rows have `order_receipt_id` or `transport_receipt_id`. Positive amounts deduct cash; negative adjustments/reversals return cash. Display the sign and sum signed amounts; do not use absolute values when calculating totals. Types include `order_receipt`, `order_receipt_adjustment`, `order_receipt_reversal`, and the corresponding `transport_receipt*` types. Receipt totals are not the sum of the absolute history amounts.

## Error handling and UI acceptance

Validation returns HTTP 422 with Laravel `message` and `errors` keyed by field, including paths such as `items.0.amount`. Insufficient funds and unrelated client/treasury selections also return 422. Missing resources return 404. Unauthorized treasury access/admin actions return 403. State conflicts, idempotency conflicts, legacy reconciliation requirements, and protected deduction operations return 409 with `message`.

Disable create while a submission is in flight. Support adding/removing expense rows and searching categories. Show client and original treasury on receipt details. Restrict cancel controls to admins. Test creating receipts on a zero-policy order, multiple receipts, retrying a timeout, insufficient funds, changing one item, repeated cancellation, original treasury refunds after order reassignment, multi-client filtering, shared statements, and read-only legacy receipts.
