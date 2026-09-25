# nopCommerce sync API (Dolibarr side)

Dolibarr never calls the webshop. The nopCommerce plugin polls these endpoints, records
the products on its own side, then reports the result back. Stock moves in Dolibarr only
when that report says success.

## Model

| Dolibarr thing | Meaning |
|---|---|
| Parent warehouse | Where the stock sits before it is put on sale online |
| Webshop warehouse | The warehouse whose content is published on nopCommerce |
| Transfer (`llx_nopcommerce_transfer`) | One batch of products moving parent -> webshop. The unit nopCommerce pulls and acknowledges |
| Transfer line (`llx_nopcommerce_transferline`) | One product of that batch. For a sized article this is the variant child product, so each size is its own line |
| `sync_flag` | On the transfer and on every line. `0` = not yet recorded by nopCommerce, `1` = recorded |
| `origin` | `manual` when the transfer was built by hand (only possible before the stock transfer capture existed), `native` when it was captured from a Dolibarr stock transfer |
| `stock_already_moved` | `true` when Dolibarr has already moved the stock for this transfer. Acknowledging it records the products and raises the flags **without moving stock again** |

A transfer is created by moving stock into the webshop warehouse on Dolibarr's native
stock transfer page, when the source and destination warehouses match the two configured
in the module setup. The stock therefore moves **at transfer time**, not at
acknowledgement time, and such a transfer reports `stock_already_moved: true`.
Acknowledging it records your product ids and marks it synced, but moves no stock.

Refs are of the form `NOP-M<stock movement id>`. They are unique but not sequential, and
nothing should be inferred from their order — key on `transfer_id`.

## Transfer life cycle

```
DRAFT ---- validate ----> PENDING ---- ack success ----> SYNCED
                             |                            (stock moved, sync_flag = 1)
                             |
                             +-------- ack failure -----> FAILED
                                                          (no stock moved, sync_flag stays 0,
                                                           still returned by the next pull)
```

`CANCELED` is reachable from `DRAFT`, `PENDING` and `FAILED`. A `SYNCED` transfer can no
longer be sent back to draft, canceled or deleted.

## Authentication

Every call needs a Dolibarr API key in the `DOLAPIKEY` header. The user that owns the key
needs the permission *Use the nopCommerce sync API* (`nopcommerce->sync`, id 500204).

```
DOLAPIKEY: <api key of the integration user>
Content-Type: application/json
```

## Endpoints

Base URL: `https://<dolibarr>/api/index.php/nopcommerce`

### `GET /status`

Health check for the plugin configuration screen. Changes nothing.

```json
{
  "status": "ok",
  "dolibarr_version": "23.0.3",
  "module_version": "1.1",
  "server_time": "2026-09-10T09:12:44+00:00",
  "webshop_warehouse_id": 4,
  "pending_transfers": 2,
  "schema_ok": true,
  "schema_missing": []
}
```

`module_version` identifies the build that answers. It changes when the contract changes, so the
webshop's "Test connection" shows whether a fix is deployed.

`schema_ok` is `false`, and `schema_missing` names each absent table, column or extrafield, on an
instance that enabled the module before the order and reversal tables were added. Dolibarr
creates a module's tables only when the module is enabled and the deploy does not migrate them.
Complete the schema from the setup page (see "Schema health and readiness" below).

Every timestamp in every response is ISO-8601 in UTC with a numeric offset (`+00:00`), never a
`Z` suffix.

### `GET /transfers/pending`

Returns the transfers waiting to be recorded, oldest first. Transfers a previous attempt
failed on are returned again so a retry works.

Query parameters:

| Name | Default | Meaning |
|---|---|---|
| `limit` | 50 | Maximum number of transfers, clamped to 200 |
| `warehouse_id` | module setup | Only transfers whose destination is this webshop warehouse |
| `include_failed` | 1 | `0` to skip the transfers a previous attempt failed on |

Every returned transfer gets a **fresh `pull_token`**. That token has to be sent back in
the acknowledgement. A token from an earlier pull is refused, which is what stops a stale
acknowledgement from being applied.

```json
{
  "server_time": "2026-09-10T09:12:44+00:00",
  "webshop_warehouse_id": 4,
  "count": 1,
  "transfers": [
    {
      "transfer_id": 12,
      "ref": "NOP2609-0003",
      "label": "Autumn drop",
      "status": 1,
      "sync_flag": false,
      "sync_attempts": 0,
      "pull_token": "9f2c...",
      "source_warehouse": {"id": 1, "ref": "MAIN", "label": "Main warehouse"},
      "webshop_warehouse": {"id": 4, "ref": "WEBSHOP", "label": "Webshop"},
      "date_creation": "2026-09-10T08:55:00+00:00",
      "lines": [
        {
          "line_id": 41,
          "product_id": 908,
          "ref": "TSHIRT-BLK-M",
          "label": "T-shirt black",
          "description": "",
          "barcode": "3760123456789",
          "price": 19.90,
          "price_ttc": 23.88,
          "tva_tx": 20.0,
          "weight": 0.2,
          "weight_units": 0,
          "is_variant": true,
          "parent": {"product_id": 900, "ref": "TSHIRT-BLK", "label": "T-shirt black"},
          "attributes": [
            {
              "attribute_id": 2,
              "attribute_ref": "SIZE",
              "attribute_label": "Size",
              "value_id": 7,
              "value_ref": "M",
              "value_label": "M"
            }
          ],
          "qty": 10,
          "batch": null,
          "stock_in_webshop_warehouse": 0,
          "stock_in_source_warehouse": 34,
          "nop_product_id": null,
          "nop_combination_id": null,
          "sync_flag": false
        }
      ]
    }
  ]
}
```

`weight` is always normalized to kilograms and `weight_units` is always `0`, whatever unit the
product is weighed in on the Dolibarr side (kg, g, t, oz or lb). nopCommerce's own weight-unit
handling is currently buggy for non-metric units — it would store a pound-denominated weight as if
it were the same number of kilograms instead of converting it — so this endpoint does the conversion
itself rather than relying on `weight_units` to be honoured on the other end.

### `GET /transfers/{id}`

Reads one transfer whatever its status. Issues no pull token, so it is safe to call for
a transfer that was already handled.

### `POST /transfers/{id}/ack`

The report from nopCommerce.

Request body:

```json
{
  "pull_token": "9f2c...",
  "success": true,
  "error": "",
  "lines": [
    {
      "line_id": 41,
      "success": true,
      "nop_product_id": 55,
      "nop_combination_id": 9,
      "error": ""
    }
  ]
}
```

| Field | Required | Meaning |
|---|---|---|
| `pull_token` | yes, unless the setup disables the check | The token that came with the transfer in the pull |
| `success` | yes | `true` when nopCommerce recorded the whole transfer |
| `error` | on failure | Message stored in `sync_last_error` and shown on the transfer card |
| `lines` | no | Per line detail. `nop_product_id` and `nop_combination_id` are stored on the Dolibarr line, and on success `nop_product_id` also sets the product extrafield `nopcommerce_external_id` (see Product resolution) |

On `success: true` Dolibarr, inside a single database transaction:

1. checks that the source warehouse holds enough stock for every line,
2. writes an exit movement from the source warehouse and an entry movement into the
   webshop warehouse for each line,
3. sets `sync_flag = 1` on every line and on the transfer,
4. sets the transfer status to `SYNCED` and stamps `date_synced`.

If any line fails, nothing is written and the transfer stays pullable.

Response:

```json
{
  "transfer_id": 12,
  "ref": "NOP2609-0003",
  "status": 2,
  "sync_flag": true,
  "already_synced": false,
  "message": "Stock moved and sync flag set to true"
}
```

On `success: false` no stock moves, `sync_attempts` is incremented, the error is stored,
the status becomes `FAILED`, and the transfer comes back in the next pull.

Calling the acknowledgement twice is safe. A transfer that is already `SYNCED` answers
`200` with `already_synced: true` and nothing is changed.

## Status codes

| Code | When |
|---|---|
| 400 | No JSON body, or `success` missing |
| 403 | The API key holds no `nopcommerce->sync` permission |
| 404 | No transfer with that id |
| 409 | The transfer is not waiting for nopCommerce, or the `pull_token` is stale |
| 500 | The stock movement or the database write failed. The message carries the reason |

## Settings

| Constant | Default | Meaning |
|---|---|---|
| `NOPCOMMERCE_WEBSHOP_WAREHOUSE_ID` | none | The warehouse published on nopCommerce. Default destination of a transfer and default filter of the pull |
| `NOPCOMMERCE_SOURCE_WAREHOUSE_ID` | none | Default parent warehouse of a new transfer |
| `NOPCOMMERCE_PULL_LIMIT` | 50 | Default number of transfers per pull |
| `NOPCOMMERCE_ACK_REQUIRE_TOKEN` | 1 | Require the `pull_token` on acknowledgement. Turn off only while testing |
| `NOPCOMMERCE_ALLOW_NEGATIVE_SOURCE_STOCK` | 0 | Allow an acknowledgement to push the source warehouse below zero |

## Completed orders: `POST /order_completed`

The webshop calls this when an order is marked Paid, once per order. Dolibarr takes the sold
quantities out of the webshop warehouse (`NOPCOMMERCE_WEBSHOP_WAREHOUSE_ID`).

The request body is an **object**: the order id and a non-empty list of items. This is the
contract the webshop plugin sends.

```json
{
  "orderid": 1001,
  "items": [
    {"productid": 55, "attribute": "Veličina", "attributeValue": "M", "quantity": 2}
  ]
}
```

`orderid` is the nopCommerce order id and `productid` the nopCommerce product id. A line without
attributes omits `attribute` and `attributeValue` (present-but-empty values count as absent) and
resolves by `productid` alone.

For each item, Dolibarr:

1. finds the product whose extrafield `nopcommerce_external_id` equals `productid`. Creating
   variants copies the parent's extrafields, so variants holding the same id are ignored in favour
   of their parent,
2. if that product has variants, picks the one whose value equals `attributeValue`
   (case-insensitive, matched on the value or its ref). `attribute` is only used to choose
   between several variants carrying that value, since the attribute may be named
   differently on each side (Size / Veličina),
3. refuses the order if the webshop warehouse has less than `quantity`, unless
   `NOPCOMMERCE_ALLOW_NEGATIVE_SOURCE_STOCK` is on,
4. writes an exit stock movement (inventory code `NOPORDER-<orderid>`).

It then records the order in `llx_nop_order_completed` and one row per item in
`llx_nop_order_complete_items`. The order is applied in one database transaction: if one item
fails, no stock moves and nothing is recorded for it.

The answer is flat, with one entry per requested item, in request order:

```json
{
  "already_completed": false,
  "items": [
    {"fk_product": 778, "stock_movement_id": 9001, "stock_reel": 23}
  ],
  "order_id": 1001,
  "completed_id": 7,
  "message": "Stock taken out of the webshop warehouse and order recorded"
}
```

`stock_reel` is the stock left in the webshop warehouse. `order_id`, `completed_id` and `message`
are informational; a client may ignore them.

**Idempotency.** The order is keyed on `orderid`. Sending an order that is already recorded
answers `already_completed: true` with an empty `items` list and moves no stock. The webshop
sends no idempotency key of its own and can redeliver, so this is what protects against a double
decrement.

| Code | When |
|---|---|
| 400 | No body, `orderid` missing or not positive, `items` missing, empty or not a list, an item that is not an object, or an item without a positive `productid` or `quantity` |
| 403 | The API key holds no `nopcommerce->sync` permission |
| 404 | An item matches no product, or no single variant. Nothing was applied |
| 409 | The webshop warehouse lacks the stock for an item. Nothing was applied |
| 500 | Webshop warehouse not configured, or the stock movement or database write failed |

### Deprecated form: a bare list of lines

An earlier draft of the contract sent a bare JSON array, one entry per line, each with its own
`orderid`. It is still accepted, unchanged, and answers in its own shape, so nothing already
integrated breaks. New callers should use the object form above.

```json
[
  {"orderid": 1001, "productid": 55, "attribute": "Size", "attributeValue": "M", "quantity": 2}
]
```

The answer is `{"orders": [{"order_id", "completed_id", "already_completed", "message", "items"}]}`
with one entry per order. Lines are grouped by `orderid`; each order is applied in its own
transaction, an earlier order stays applied if a later one fails, and the call stops at the first
order that fails. Both forms share one record of what was applied, so an order delivered in one
form and redelivered in the other is recognised.

## Product resolution

`order_completed` and `order_reversal` both act on a **nopCommerce** product id. Resolving it back
to a Dolibarr product relies on the `nopcommerce_external_id` product extrafield. A successful
acknowledgement sets it: each line's `nop_product_id` is written on the line's parent product, or
on the product itself when it has no variants. Any other product still holding that id loses it,
so a webshop product deleted and pulled again moves its link to the new id, and an order never
matches two products. A product that never came through a transfer has no link; the readiness
report on the setup page lists the products that still lack it.

## Reversing a completed order: `POST /order_reversal`

Undoes an order previously reported to `/order_completed`, in whole or in part: a
cancellation, a refund, or goods returned and accepted. Dolibarr writes an **entry**
stock movement back into the webshop warehouse (`NOPCOMMERCE_WEBSHOP_WAREHOUSE_ID`).

```json
{
  "orderid": 1001,
  "reversalid": "RET-4821",
  "items": [
    {"productid": 55, "attribute": "Veličina", "attributeValue": "M", "quantity": 1}
  ]
}
```

| Field | Required | Meaning |
|---|---|---|
| `orderid` | yes | The nopCommerce order id, previously recorded by `/order_completed` |
| `reversalid` | yes | Id of this cancellation/refund/return on the nopCommerce side. Replaying the same value is what makes the call idempotent — a different value on the same order is a separate, additional reversal |
| `items` | no | `productid`, `quantity` and optionally `attribute` and `attributeValue` to reverse. Omit it, or send an empty array, to reverse everything the order still has outstanding |

`items[].productid` is matched against the nopCommerce product id recorded at
completion time (`nop_external_id` on `llx_nop_order_complete_items`), **not**
re-resolved through the product catalog: the product was already resolved once, when
the order was completed, so the reversal only has to find that same recorded row again.

**Which size came back.** A product id alone does not say which size was returned when the
order recorded several variants of the product. `attribute` and `attributeValue` name it, with
the same matching rules as in `/order_completed` (value matched case-insensitively, the
attribute name only choosing between variants that share a value). Then only the recorded rows
of the variant carrying that size are credited, still capped at what remains outstanding on them.

- With a size, and the order recorded no such variant: `409`, nothing applied.
- Without a size, and the order recorded a single variant of the product: credited as before.
- Without a size, and the order recorded several variants of the product: `409` asking for the
  size, nothing applied. Dolibarr refuses rather than credit a guessed size.
- Reversing a whole order (no `items`) is unaffected.

For each item Dolibarr:

1. finds the completed order's recorded item rows carrying that `productid`,
2. refuses if the quantity asked for is more than those rows still have outstanding
   (original `qty` minus what earlier reversals already took off it),
3. writes an entry stock movement for the quantity taken (inventory code
   `NOPREVERSAL-<orderid>-<reversal row id>`),
4. adds that quantity to `qty_reversed` on the recorded item rows it drew from, so a
   later, separate reversal sees what is left.

All of it runs in a single database transaction. If any item asks for more than remains
outstanding, no stock moves and nothing is recorded. Sending a reversal already recorded
under the same `reversalid` answers `200` with `already_reversed: true` and changes
nothing.

```json
{
  "order_id": 1001,
  "completed_id": 7,
  "reversal_id": "RET-4821",
  "already_reversed": false,
  "message": "Entry stock movement written into the webshop warehouse and reversal recorded",
  "items": [
    {"productid": 55, "quantity": 1,
     "fk_product": 5, "stock_movement_id": 900, "stock_in_webshop_warehouse": 4}
  ]
}
```

| Code | When |
|---|---|
| 400 | No JSON body, `orderid` or `reversalid` missing, an item without `productid` or a positive `quantity`, or an `attribute` or `attributeValue` that is not text |
| 403 | The API key holds no `nopcommerce->sync` permission |
| 404 | No order with that `orderid` is recorded as completed. Nothing was applied |
| 409 | An item asks for more than remains outstanding for it, names a size the order never recorded, or leaves out a size the order needs. Nothing was applied |
| 500 | Webshop warehouse not configured, or the stock movement or database write failed |

An order recorded before this endpoint existed has `qty = 0` on its item rows (the
quantity was never captured), so a reversal against it correctly finds nothing
outstanding rather than guessing a quantity — see
[`sql/llx_nop_order_complete_items_qty.sql`](../sql/llx_nop_order_complete_items_qty.sql).

## Known limitation: the sync is one-directional

`/order_completed` only ever takes stock out, and until now there was no way to tell
Dolibarr that a shop order was cancelled, refunded, or its goods returned and accepted:
the ERP would write off goods that never left the shop, and returned stock could never
become sellable again. `/order_reversal` above closes that gap for orders reported
through `/order_completed`.

What is still one-directional: only stock moving **into** the webshop warehouse through
a transfer is reported. Moving stock back out by a native Dolibarr stock transfer to the
parent warehouse — a correction unrelated to a shop order — tells this API nothing, so
the shop will still believe the earlier quantity is available and can oversell. Reverse
that kind of movement with a stock transfer in the other direction on the Dolibarr side;
there is nothing for nopCommerce to call for it.

`/order_reversal` matches on the nopCommerce product id recorded at completion time. When the
order recorded several variants of one product, the size on the item decides which is credited
(see "Which size came back" above); without it the request is refused rather than guessed.

`stock_in_webshop_warehouse` in the pull payload is a snapshot taken at pull time, so a
warehouse with no new transfers never refreshes. If your shop needs authoritative stock
levels, do not rely on this API for them.

## Schema health and readiness

The setup page (Home > Setup > Modules > nopCommerce) has two extra sections.

**Database schema.** Lists any missing table, column or extrafield the order flow needs, and a
**Repair the schema** button. Repair runs the module's table definitions again and makes sure the
`nopcommerce_external_id` extrafield exists. It only adds what is missing, never drops a table or
deletes a row, and is safe to press repeatedly. The same health is reported by `GET /status`
(`schema_ok`, `schema_missing`).

**Readiness for webshop sales.** Read only. Lists where a sale is unlikely to resolve:

- products with stock in the webshop warehouse that resolve to no nopCommerce id (a variant
  resolves through its parent),
- nopCommerce ids carried by several products that are not variants,
- attribute values carried by several variants of one product, shown with their attribute. Only a
  shared **size** is a problem, since a sale sends the size alone; a shared colour is not,
- sellable products with a nopCommerce id and no stock in the webshop warehouse.
