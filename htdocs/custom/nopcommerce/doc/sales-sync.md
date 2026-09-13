# Syncing sold stock from nopCommerce to Dolibarr

Instructions for implementing the reverse direction called out in
[api.md](api.md#not-built-yet): today Dolibarr only pushes stock **into** the webshop
warehouse (see api.md). Nothing tells Dolibarr when that stock is **sold**, so the
webshop warehouse quantity in Dolibarr drifts high over time and can oversell.

This document specifies the contract for closing that loop: nopCommerce reports what it
sold, on a schedule, and Dolibarr decrements the webshop warehouse to match. It is written
for whoever implements the `sync_products` scheduled task in the nopCommerce plugin
(`Nop.Plugin.Misc.DolibarrIntegration`, nop-store repo) and the matching endpoint on the
Dolibarr side (this repo, `htdocs/custom/nopcommerce`).

**Status: not implemented on either side yet.** This is the spec to build against, not a
description of existing behaviour.

## Direction and ownership

- nopCommerce is the source of truth for *what sold*.
- Dolibarr is the source of truth for *stock levels*.
- nopCommerce pushes; Dolibarr never polls nopCommerce. Same call direction as the
  existing ack in api.md, so the plugin only ever makes outbound HTTP calls.

## What "sold" means here

Report a line the moment its stock is committed and will not be released back to
nopCommerce's own inventory — typically order payment/shipment, whatever event in
nopCommerce already decrements its own stock. Do not report on "added to cart" or
"order placed but unpaid" if nopCommerce's own stock isn't decremented at that point:
this endpoint mirrors nopCommerce's stock, it doesn't drive it.

## The nopCommerce-side task: `sync_products`

Runs every 5 minutes (already scheduled, currently empty). Each run:

1. Read the last successfully reported watermark (a timestamp or last order-item id,
   persisted in the plugin's own settings — not in Dolibarr).
2. Query order items fulfilled since that watermark.
3. Build one batch payload (see below) and `POST` it to Dolibarr.
4. On a `200` response, advance the watermark to the value returned in the response
   (see `high_watermark` below) — not to "now", so a line created mid-run by another
   process is never skipped.
5. On any error (network, non-200, or a per-line failure in the response), do **not**
   advance the watermark. The next run 5 minutes later retries the same items. This is
   why the task can be empty-safe: a crash mid-run loses nothing.

Each nopCommerce order item must carry a stable, unique id (`nop_order_item_id` below)
so a retried batch cannot double-decrement Dolibarr's stock. Reuse whatever id
nopCommerce already assigns order items — do not invent a new one.

## The Dolibarr-side endpoint (to build)

Follows the same conventions as the existing `ack` endpoint in
[api_nopcommerce.class.php](../class/api_nopcommerce.class.php): same auth, same
`DolibarrApi` class, same per-line result shape.

```
POST /api/index.php/nopcommerce/sales
DOLAPIKEY: <api key of the integration user>
Content-Type: application/json
```

Requires the same permission as every other endpoint here: `nopcommerce->sync`
(id 500204).

Request body:

```json
{
  "lines": [
    {
      "nop_order_item_id": 88213,
      "dolibarr_product_id": 908,
      "qty": 2,
      "sold_at": "2026-09-13T10:04:00+00:00"
    }
  ]
}
```

| Field | Required | Meaning |
|---|---|---|
| `nop_order_item_id` | yes | nopCommerce's own order-item id. The idempotency key — Dolibarr must refuse to apply the same id twice |
| `dolibarr_product_id` | yes | The Dolibarr product id. nopCommerce already has this: it came in the `product_id` field of every line in the `GET /transfers/pending` payload (api.md) when the product was first pushed. Send it directly rather than `nop_product_id`/`nop_combination_id`, so Dolibarr never has to reverse-look-up a mapping that might be ambiguous or stale |
| `qty` | yes | Quantity sold, always positive |
| `sold_at` | yes | When nopCommerce considers the item sold. Used only for `date_synced`-style bookkeeping, not for ordering — the idempotency key is `nop_order_item_id`, not this timestamp |

A product nopCommerce never received through the pull (no `dolibarr_product_id` known)
cannot be reported here — there is nothing on the Dolibarr side to decrement.

### What Dolibarr does, per line, inside one database transaction

1. If `nop_order_item_id` was already applied (needs a new table, see below), skip it
   and report it as already-applied rather than erroring — this is what makes a retried
   batch safe.
2. Otherwise, write an exit stock movement of `qty` from
   `NOPCOMMERCE_WEBSHOP_WAREHOUSE_ID` for `dolibarr_product_id`.
   `NOPCOMMERCE_ALLOW_NEGATIVE_SOURCE_STOCK` should gate this the same way it already
   gates the forward sync in `nopcommercesync.class.php`, since a webshop warehouse
   going negative here means Dolibarr's mirrored count was already wrong, not that this
   call is wrong.
3. Record `nop_order_item_id` as applied, so step 1 can find it next time.

If a line fails, that line's failure must not roll back the lines around it in the same
batch — report per-line results, same pattern as `ack`'s response. A single bad line
should not stall every other line behind it for 5 more minutes.

### Proposed new table: `llx_nopcommerce_saleline`

Needed for the idempotency check in step 1. Follows the module's existing SQL
conventions (create file for fresh installs, separate idempotent `ALTER`/`CREATE` for
existing installs, per the pattern in `sql/llx_nopcommerce_transfer_origin.sql`).

| Column | Type | Notes |
|---|---|---|
| `rowid` | integer, PK | |
| `nop_order_item_id` | integer, unique | The idempotency key |
| `fk_product` | integer | Dolibarr product id decremented |
| `qty` | double | |
| `fk_mouvement` | integer | The `llx_stock_mouvement` row this created, for traceability the same way `nop_product_id`/`nop_combination_id` trace the forward direction |
| `date_creation` | datetime | |

### Response

```json
{
  "applied": 1,
  "already_applied": 0,
  "failed": 0,
  "high_watermark": 88213,
  "lines": [
    {"nop_order_item_id": 88213, "success": true, "error": ""}
  ]
}
```

`high_watermark` is the highest `nop_order_item_id` Dolibarr has now recorded across
*all* time for this integration, not just this batch — nopCommerce should advance its
own watermark to this value only when every line in the batch succeeded or was already
applied; if anything failed, keep retrying from the old watermark next run.

### Status codes

Same table as api.md's, plus:

| Code | When |
|---|---|
| 400 | No JSON body, `lines` missing, or a line missing a required field |
| 403 | The API key holds no `nopcommerce->sync` permission |
| 500 | The stock movement or the database write failed for reasons unrelated to the data itself |

Note there is no 404/409 here unlike `ack`: this endpoint doesn't operate on an existing
Dolibarr record nopCommerce is expected to already know the id of, and there is no token
to go stale.

## Testing

Mirror the existing module test harness (`test/NopCommerceCaptureTest.php`,
[testing.md](testing.md)): PHPUnit against a real Dolibarr install, wrapped in a
transaction the harness rolls back. At minimum, assert:

- reporting the same `nop_order_item_id` twice only decrements stock once,
- a batch with one bad line and one good line applies the good one,
- `NOPCOMMERCE_ALLOW_NEGATIVE_SOURCE_STOCK = 0` refuses to take the webshop warehouse
  negative.

On the nopCommerce side, verify the watermark truly does not advance past a failed
line — run the task twice against a mocked failing endpoint and confirm the second run
resends the same items.
