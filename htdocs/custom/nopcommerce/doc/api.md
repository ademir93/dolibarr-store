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
  "module_version": "1.0",
  "server_time": "2026-09-10T09:12:44+00:00",
  "webshop_warehouse_id": 4,
  "pending_transfers": 2
}
```

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
| `lines` | no | Per line detail. `nop_product_id` and `nop_combination_id` are stored on the Dolibarr line so the two sides stay mapped |

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

## Not built yet

The reverse direction, where nopCommerce reports its own sales so that Dolibarr decrements
the webshop warehouse, is not part of this version. It will be a separate endpoint added
alongside the nopCommerce plugin.

## Known limitation: the sync is one-directional

Only stock moving **into** the webshop warehouse is reported. Moving stock back out — a
return to the parent warehouse, a correction — tells this API nothing, so the shop will
still believe the earlier quantity is available and can oversell.

`stock_in_webshop_warehouse` in the pull payload is a snapshot taken at pull time, so a
warehouse with no new transfers never refreshes. If your shop needs authoritative stock
levels, do not rely on this API for them.
