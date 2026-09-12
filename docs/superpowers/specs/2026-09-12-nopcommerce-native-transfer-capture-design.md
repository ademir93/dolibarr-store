# Capturing native Dolibarr stock transfers for nopCommerce sync

Date: 2026-09-12
Status: approved, not yet implemented

## Problem

The nopcommerce module has one way in: a user builds a transfer on
`custom/nopcommerce/transfer_card.php`, picking a source warehouse, a destination
warehouse and a set of products with quantities. That flow stays exactly as it is.

Users also transfer stock from the native Dolibarr screen
`product/stock/product.php?id=<id>&action=transfer`, choosing the same pair of
warehouses. Today those transfers are invisible to the module, so the product never
reaches nopCommerce. It must be captured for sync instead.

## Decisions

These were settled during brainstorming and the design depends on all five.

| Question | Decision |
|---|---|
| Stock timing | The native movement stands. The captured transfer is marked as already-moved, and the acknowledgement skips the movement legs. Stock is never moved twice. |
| Capture trigger | Capture when the native transfer's destination is `NOPCOMMERCE_WEBSHOP_WAREHOUSE_ID`. The source is whatever the user picked. |
| Grouping | One nopcommerce transfer per native transfer, written straight as `PENDING`, no human step. |
| Scope | Strictly `product/stock/product.php` with `action=transfert_stock`. Mass stock move, the native StockTransfer module and movement reversal are out of scope. |
| Failure policy | Atomic. A capture failure rolls the native stock movements back and shows the user an error. |
| Visibility | The distinction is exposed in the REST payload. No origin display on the card or list. |

## Architecture

Two seams, each supplying what only it can.

```
POST action=transfert_stock  (product/stock/product.php)
          |
          v
  doActions hook  [context: stockproductcard]            <-- page scope lives here
  actions_nopcommerce.class.php
    is this the native transfer form?
    is id_entrepot_destination == webshop warehouse?
    -> park a capture intent on a class static
          |
          v
  product.php transfert_stock block
    correct_stock(..., movement=1)  ->  _create(type 1)   out leg
       |                                    |
       |                                    +-- STOCK_MOVEMENT trigger
       |                                          intent parked? record out-leg
       |                                          movement id + source warehouse
       v
    correct_stock(..., movement=0)  ->  _create(type 0)   in leg
                                            |
                                            +-- STOCK_MOVEMENT trigger
                                                  intent parked + dest is webshop?
                                                  -> create transfer + line,
                                                     PENDING, origin='native',
                                                     both movement ids stamped
```

Why this split: the page identity is known only to the hook, because a `STOCK_MOVEMENT`
trigger sees a movement, not a request. The movement row id is known only to the
trigger, because `_create` assigns it after the `stockMovementCreate` hook has already
run. Neither seam can do the job alone.

### Why the trigger, and not a hook, does the writing

- `MouvementStock::_create()` fires `stockMovementCreate` at its **start**
  (`mouvementstock.class.php:262`), before the row exists. It cannot supply
  `fk_mouvement_source` / `fk_mouvement_destination`.
- `call_trigger('STOCK_MOVEMENT')` fires near the **end** of `_create`
  (`mouvementstock.class.php:679`), with `product_id`, `entrepot_id`, `qty`, `type`,
  `batch` and `id` all populated.
- `product.php`'s `doActions` hook (`product.php:175`) runs before the
  `transfert_stock` block at `product.php:369`, so it cannot observe the outcome.

### Why atomicity comes for free

Transactions nest: `product.php` opens one, `correct_stock` opens one, `_create` opens
one. A trigger returning `-1` makes `_create` roll back and return `<0`;
`correct_stock` then returns `-1`; `product.php` increments `$error` and rolls back the
outer transaction. The stock movements and the captured transfer therefore commit or
fail together, with no extra transaction handling of our own.

### Facts come from the movements, not from the POST

The intent carries only *that* this is a capture case. Every fact the transfer needs —
product, quantity, batch, source warehouse, both movement ids — is read from the two
movement objects the trigger receives.

This is deliberate. When the native form is opened for a specific lot
(`pdluoid` set), `product.php:422` takes the source warehouse from
`$pdluo->warehouseid`, **not** from the posted `id_entrepot`. Reading the POST would
record the wrong source warehouse in that case. The `type 1` movement's
`entrepot_id` is always right.

## Components

### 1. `class/actions_nopcommerce.class.php` (new)

Hook class, following `custom/mystore/class/actions_mystore.class.php`.

`doActions($parameters, &$object, &$action, $hookmanager)`:

1. Return `0` unless `'stockproductcard'` is in `$hookmanager->contextarray`.
   Qualify on `contextarray`, not `$parameters['currentcontext']` — HookManager runs a
   module's hook method only once, for the first matching context
   (`$modulealreadyexecuted` in `hookmanager.class.php`). This module registers only
   one context today, but the pattern is already documented in `actions_mystore.class.php`
   and should be kept.
2. Return `0` unless `$action == 'transfert_stock'` and no `cancel` was posted.
3. Return `0` unless `isModEnabled('nopcommerce')`.
4. Return `0` unless `GETPOSTINT('id_entrepot_destination') == getDolGlobalInt('NOPCOMMERCE_WEBSHOP_WAREHOUSE_ID')`
   and that value is `> 0`.
5. Park the intent: `NopCommerceCapture::expect(GETPOSTINT('id'), GETPOSTINT('id_entrepot_destination'))`.
6. Return `0`. The hook never alters `$action` or short-circuits the native flow.

The module descriptor gains `'hooks' => array('stockproductcard')` in `module_parts`.

### 2. `class/nopcommercecapture.class.php` (new)

The intent holder and the capture logic, so neither the hook nor the trigger carries
business rules.

```
NopCommerceCapture::expect(int $fk_product, int $fk_warehouse_destination): void
NopCommerceCapture::isExpected(): bool
NopCommerceCapture::expectedProduct(): int
NopCommerceCapture::expectedDestination(): int
NopCommerceCapture::forget(): void
NopCommerceCapture::recordSourceLeg(MouvementStock $m): void
NopCommerceCapture::capture(DoliDB $db, User $user, MouvementStock $m): int
```

State is a private static, request-scoped. A request handles at most one native
transfer, so a single slot is enough; `forget()` is called once `capture()` has run
so a later movement in the same request cannot be captured twice.

`capture()` does, in order:

1. Resolve `fk_product_parent` via `ProductCombination::fetchByFkProductChild`, exactly
   as `NopCommerceTransfer::addLine()` does.
2. Insert the transfer with `status = PENDING`, `origin = 'native'`, `sync_flag = 0`,
   `sync_attempts = 0`, `entity = $conf->entity`, `ref` from `getNextNumRef()`,
   `fk_warehouse_source` from the recorded out leg, `fk_warehouse_destination` from this
   movement, `label` from the movement label, `date_creation = dol_now()`,
   `fk_user_creat = $user->id`.
3. Insert one line: `fk_product`, `fk_product_parent`, `qty` (absolute value of the
   in-leg qty), `batch`, `position = 0`, `sync_flag = 0`,
   `fk_mouvement_source` from the out leg, `fk_mouvement_destination` from this leg.
4. Fire `NOPCOMMERCE_TRANSFER_VALIDATE` so the captured transfer raises the same
   trigger a manually validated one does.
5. Return `>0`, or `<0` with `$error` set, which the trigger propagates.

The transfer is written **directly as PENDING with a real ref** rather than created as
a draft and then validated. Two reasons: `create()` inserts `ref = '(PROV)'` and
`llx_nopcommerce_transfer` has a unique index on `(ref, entity)`, so two concurrent
captures would collide on `(PROV)`; and a captured transfer has no draft phase that
means anything.

`getNextNumRef()` reads `MAX(ref)` and then inserts, which is racy under concurrency.
The unique index turns that race into a detectable insert failure rather than a
duplicate ref. `capture()` retries the ref once on
`DB_ERROR_RECORD_ALREADY_EXISTS`; a second failure returns `<0` and the native
transfer rolls back, which is the agreed policy. This race already exists on manual
validate and is not made worse here.

### 3. `core/triggers/interface_95_modNopCommerce_NopCommerceCapture.class.php` (new)

Extends `DolibarrTriggers`. Handles `STOCK_MOVEMENT` only; returns `0` for everything
else.

```
if ($action != 'STOCK_MOVEMENT')                              return 0;
if (!NopCommerceCapture::isExpected())                        return 0;
if ($object->product_id != NopCommerceCapture::expectedProduct())     return 0;  // kit guard
if ($object->type == 1) { NopCommerceCapture::recordSourceLeg($object); return 0; }
if ($object->type != 0)                                       return 0;
if ($object->entrepot_id != NopCommerceCapture::expectedDestination()) return 0;
$res = NopCommerceCapture::capture($this->db, $user, $object);
NopCommerceCapture::forget();
if ($res < 0) { $this->errors[] = ...; return -1; }
return 0;
```

`'triggers' => 1` is set in the module descriptor's `module_parts`, which
`conf.class.php:603` maps to the directory `/nopcommerce/core/triggers/`, resolved under
`custom/` by `dol_buildpath`.

Two naming constraints come from the loader's regex in `interfaces.class.php:156`,
`/^interface_([0-9]+)_([^_]+)_(.+)\.class\.php$/i`:

- the second segment cannot contain an underscore, so `modNopCommerce` is valid;
- the class name must be `"Interface".ucfirst($reg[3])`, so the file above **must**
  declare `class InterfaceNopCommerceCapture extends DolibarrTriggers`. A mismatch is
  logged and silently skipped rather than raising an error.

The loader also derives the module from the second segment and skips the file unless
`isModEnabled('nopcommerce')` (`interfaces.class.php:168`), so the trigger needs no
module check of its own.

**No recursion risk.** `applyAckSuccess()` calls `livraison()` and `reception()` for
manual transfers, which fire `STOCK_MOVEMENT` and so reach this trigger. `isExpected()`
is false there — nothing parked an intent — so it returns `0` immediately.

**The kit guard is load-bearing.** `_create` calls `_createSubProduct` *before*
`call_trigger` (`mouvementstock.class.php:672`), and `_createSubProduct` calls
`_create` per child, so each child of a kit fires its own `STOCK_MOVEMENT` with the
same type and warehouse. `product.php` passes no
`$disablestockchangeforsubproduct`, so with `PRODUIT_SOUSPRODUITS` enabled a kit
transfer would otherwise capture one transfer per child component. Matching
`$object->product_id` against the product the user actually submitted confines capture
to that product.

### 4. Schema: one new column

`origin varchar(16) NOT NULL DEFAULT 'manual'` on `llx_nopcommerce_transfer`.
Values: `'manual'` (built on `transfer_card.php`) and `'native'` (captured from the
native page).

One column, not two. "Stock already moved" is derived, not stored:

```php
public function stockAlreadyMoved()
{
    return $this->origin !== 'manual';
}
```

That derivation extends correctly if mass stock move or the StockTransfer module is
ever captured — any non-manual origin has already moved its stock.

Delivered as two edits, which is the standard Dolibarr pattern:

- the column is added to `sql/llx_nopcommerce_transfer.sql` for fresh installs;
- `sql/llx_nopcommerce_transfer_origin.sql` carries
  `ALTER TABLE llx_nopcommerce_transfer ADD COLUMN origin varchar(16) NOT NULL DEFAULT 'manual';`
  for installs that already have the table.

`_load_tables` runs `llx_*.sql` in sorted order, and `run_sql` tolerates
`DB_ERROR_COLUMN_ALREADY_EXISTS` by default (`admin.lib.php:329`), so the ALTER is
idempotent and harmless on a fresh install. `'.'` sorts before `'_'`, so the base
`CREATE TABLE` runs before the ALTER.

Add `origin` to `NopCommerceTransfer::$fields` with `'visible' => 0` and
`'noteditable' => 1`, so `transfer_list.php` does not gain a column.

### 5. `applyAckSuccess` branches on the derived flag

In `NopCommerceSync::applyAckSuccess()` the per-line loop currently always runs the
source-stock check, `livraison()` and `reception()`. For a transfer where
`stockAlreadyMoved()` is true, all three are skipped and the existing
`fk_mouvement_source` / `fk_mouvement_destination` values, written at capture time,
are left untouched. Everything else is unchanged: `sync_flag`, `nop_product_id`,
`nop_combination_id`, `status = SYNCED`, `date_synced`, `sync_attempts`, and the
`NOPCOMMERCE_TRANSFER_SYNCED` trigger.

Skipping the stock check matters as much as skipping the movement. After a native
transfer the source has already been decremented, so the
`NOPCOMMERCE_ALLOW_NEGATIVE_SOURCE_STOCK` check would reject an otherwise healthy
acknowledgement.

`applyAckFailure` needs no change: it moves no stock. A captured transfer that the
shop rejects becomes `FAILED` and stays pullable, which is correct — the stock has
moved in Dolibarr and the shop simply has not recorded it yet.

### 6. Integrity guards on the transfer class

A captured transfer lands as `PENDING`, and `transfer_card.php` offers *Set draft* for
`PENDING` transfers. Without a guard a user could send a captured transfer back to
draft, add a line whose stock has **not** moved, and have `applyAckSuccess` skip the
movement for the whole transfer — flagging that line synced while its stock never
moves.

So both of these refuse when `stockAlreadyMoved()` is true:

- `setDraft()` — error `CannotReopenACapturedTransfer`
- `addLine()` — error `CannotAddALineToACapturedTransfer`

`cancel()` and `delete()` keep their current rules. Cancelling a captured transfer
that the shop has not yet recorded is legitimate; neither touches stock, so neither can
double-move.

These are data-integrity guards in the class, not UI work. The buttons stay visible and
error when used, consistent with the "payload only, no UI changes" decision.

### 7. REST payload

`NopCommerceSync::buildTransferPayload()` gains two fields at the transfer level:

```json
{
  "transfer_id": 42,
  "ref": "NOP2609-0012",
  "origin": "native",
  "stock_already_moved": true,
  "...": "unchanged"
}
```

`origin` is the stored string; `stock_already_moved` is `stockAlreadyMoved()`. Both are
additive, so an existing nopCommerce plugin keeps working untouched. `doc/api.md` is
updated: the two fields in the payload table, and a short note in the model section
saying that a `native`-origin transfer has already moved its stock in Dolibarr and that
acknowledging it records the products without moving stock again.

`getPullableTransferIds()` is unchanged — captured transfers are `PENDING` with
`sync_flag = 0`, so they are already pullable.

### 8. Language keys

Added to `langs/en_US/nopcommerce.lang`: `NopCommerceOrigin`,
`NopCommerceOriginManual`, `NopCommerceOriginNative`,
`CannotReopenACapturedTransfer`, `CannotAddALineToACapturedTransfer`,
`NopCommerceCaptureFailed`.

## Files touched

| File | Change |
|---|---|
| `class/actions_nopcommerce.class.php` | new — hook class, parks the intent |
| `class/nopcommercecapture.class.php` | new — intent state and capture logic |
| `core/triggers/interface_95_modNopCommerce_NopCommerceCapture.class.php` | new — `STOCK_MOVEMENT` handler |
| `sql/llx_nopcommerce_transfer.sql` | add `origin` column |
| `sql/llx_nopcommerce_transfer_origin.sql` | new — ALTER for existing installs |
| `core/modules/modNopCommerce.class.php` | `'hooks' => array('stockproductcard')`, `'triggers' => 1` |
| `class/nopcommercetransfer.class.php` | `origin` field + property, `stockAlreadyMoved()`, guards in `setDraft()` and `addLine()` |
| `class/nopcommercesync.class.php` | skip stock check and movement legs when already moved; two payload fields |
| `langs/en_US/nopcommerce.lang` | new keys |
| `doc/api.md` | payload fields and a model note |

`transfer_card.php` and `transfer_list.php` are untouched.

## Testing

Dolibarr has no test harness in this repo, so verification is manual against a running
instance. Each case states what to check in the database and in the UI.

1. **Capture happens.** Native transfer of a simple product into the webshop
   warehouse. Expect: one new `llx_nopcommerce_transfer` with `status = 1`,
   `origin = 'native'`, `sync_flag = 0`, a real `NOPyymm-NNNN` ref; one transferline
   with the right product, qty, and both `fk_mouvement_*` ids pointing at the two
   `llx_stock_mouvement` rows; source and destination warehouses matching what was
   picked; stock moved once.
2. **No capture when the destination is elsewhere.** Native transfer into any other
   warehouse. Expect: no new transfer row, stock moved normally.
3. **Acknowledgement moves no stock.** Pull the captured transfer, ack success. Expect:
   `status = 2`, `sync_flag = 1`, `date_synced` set, `nop_product_id` recorded, and
   **stock identical to before the ack** in both warehouses.
4. **Manual flow unchanged.** Build a transfer on `transfer_card.php`, validate, pull,
   ack success. Expect: `origin = 'manual'` and stock moved by the ack exactly as
   today. This is the regression that matters most.
5. **Variant product.** Capture a variant child. Expect `fk_product_parent` set, and
   `attributes` populated in the pull payload.
6. **Batch product, lot-specific form.** Open the native form from a lot row so
   `pdluoid` is set, and transfer. Expect `batch` recorded on the line and
   `fk_warehouse_source` equal to the **lot's** warehouse, not the posted
   `id_entrepot`.
7. **Atomic failure.** Force `capture()` to fail. Expect the native page to show an
   error, no new transfer row, and **stock unchanged** in both warehouses.
8. **Guards.** On a captured transfer try *Set draft*, and try adding a line. Expect
   both refused with their messages, and the transfer still `PENDING`.
9. **Kit product.** With `PRODUIT_SOUSPRODUITS` enabled, transfer a kit into the
   webshop warehouse. Expect exactly **one** captured transfer, for the kit product
   itself, not one per component.
10. **Existing install migration.** Enable the module on a database that already has
    `llx_nopcommerce_transfer` without `origin`. Expect the column added with every
    existing row defaulting to `'manual'`, and those transfers still moving stock on
    ack.

## Out of scope

- Capturing mass stock move, the native StockTransfer module, or movement reversal.
  The capture decision is confined to the hook, so adding one later is a change in one
  place.
- Showing origin on the transfer card or list, or filtering the list by it.
- Any change to how `transfer_card.php` builds transfers.
- Reversing a captured transfer's stock when the shop reports failure. A `FAILED`
  captured transfer keeps its moved stock and stays pullable for retry.
