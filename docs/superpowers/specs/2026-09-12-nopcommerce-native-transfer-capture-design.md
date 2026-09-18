# Capturing native Dolibarr stock transfers for nopCommerce sync

Date: 2026-09-12
Status: approved, partially landed (see "Already landed")

## Problem

Products are queued for nopCommerce sync by moving stock into the webshop warehouse.
There is no hand-built queue any more: the module's only job is to notice that a stock
transfer happened, record the product for sync, and let nopCommerce pull it.

Users perform that transfer on the native Dolibarr screen
`product/stock/product.php?id=<id>&action=transfer`, choosing the same source and
destination warehouses that the module setup names. Today those transfers are invisible
to the module, so the product never reaches nopCommerce.

## Scope change from the first draft

The first version of this design assumed a second, manual entry point —
`transfer_card.php`, where a user picked warehouses and assembled a multi-product
transfer by hand. That page is gone. The module now has exactly one way in, the native
stock transfer page, and exactly one screen, a list of products queued for sync.

What this removed from the design: the manual create/edit flow, per-transfer warehouse
selection, the draft phase, and the `validate` / `set draft` user actions.

## Decisions

Every decision below is settled. The design depends on all of them.

| # | Question | Decision |
|---|---|---|
| 1 | Stock timing | The native movement stands. The captured transfer is marked as already-moved and the acknowledgement skips the movement legs, so stock is never moved twice. |
| 2 | Capture condition | Capture when **both** warehouses match the setup: source `== NOPCOMMERCE_SOURCE_WAREHOUSE_ID` and destination `== NOPCOMMERCE_WEBSHOP_WAREHOUSE_ID`. |
| 3 | Grouping | One nopcommerce transfer per native transfer, written as `PENDING`, no human step. |
| 4 | Page scope | Strictly `product/stock/product.php` with `action=transfert_stock`. Mass stock move, the native StockTransfer module and movement reversal are out of scope. |
| 5 | Failure policy | Atomic. A capture failure rolls the native stock movements back and shows the user an error. |
| 6 | Visibility | Exposed in the REST payload. No origin column on the list. |
| 7 | Seam | Hook for page scope, trigger for the write (approach A). |
| 8 | Marker | A single `origin` column; "stock already moved" is derived from it. |
| 9 | Permission to capture | None. Capture requires no nopcommerce right — it is bookkeeping performed on the user's behalf. |
| 10 | Movement provenance | Back-tag both movement rows with `origintype` / `fk_origin`, but only where they are empty. |
| 11 | Outbound stock | Not captured. Documented as a known one-directional limitation. |
| 12 | Tests | PHPUnit for the stock-correctness cases, manual for the rest. |
| 13 | Broken origin link | Fixed as part of this work. |
| 14 | Storage model | Keep `llx_nopcommerce_transfer` + `llx_nopcommerce_transferline` as internal storage. The list shows **lines**, not transfers. The REST contract is unchanged. |
| 15 | Sync indicator | Read-only. No click-to-toggle. |
| 16 | Row granularity | One row per transfer event. A product transferred twice appears twice, each with its own quantity and sync state. |
| 17 | Row actions | Cancel and delete. `DRAFT` becomes unreachable and `validate` has no user-facing caller. |
| 18 | Misconfiguration | Silent. A non-matching pair is not a capture case; unset settings mean the feature is simply off. No setup-page warning. |
| 19 | Write path | Capture reuses `create()` → `addLine()` → `validate()` rather than inserting directly. |
| 20 | Ref | Derived from the destination movement id, `NOP-M<mvid>`. No counter, no race. |
| 21 | Reopen path | `setDraft()` is deleted. No guards are needed. |
| 22 | Test location | Module-local, `htdocs/custom/nopcommerce/test/`. |

## Already landed

Commit `b6feb74971f` removed the manual entry point: `transfer_card.php`, the
*New nopCommerce transfer* menu entry, the list's new-card button, the dead
`nopcommerceTransferPrepareHead()`, and the `NewNopCommerceTransfer` language key.
`NopCommerceTransfer::getNomUrl()` now points at the sync list filtered on the ref
instead of the deleted page.

Everything else in this document is outstanding.

## Corrections to the first draft

Recorded because the reasoning, not just the conclusion, was wrong.

**The `(PROV)` collision argument was false.** The first draft justified bypassing the
draft phase on the grounds that two concurrent captures would collide on
`ref = '(PROV)'`. They cannot. `createCommon()` never writes that literal:
`setSaveQuery` passes `ref` through as null, `dol_string_nospecial()` converts it to
`''`, which makes the default-substitution branch unreachable, and a post-insert
`UPDATE` inside the same transaction renames the row to `(PROV<rowid>)`, unique by
construction ([commonobject.class.php:10731](../../../htdocs/core/class/commonobject.class.php)).
The literal `'(PROV)'` never appears in the table. Reusing the existing write path
(decision 19) is therefore safe, and the real ref race lives in `getNextNumRef()`
instead — addressed by decision 20.

**Dolibarr does have a test harness.** The first draft claimed none existed. `test/phpunit/`
holds a substantial suite, and `MouvementStockTest.php` bootstraps via
`htdocs/master.inc.php` against a real database and already exercises `MouvementStock`.
That is the pattern this feature's tests follow.

**The `addLine()` guard was redundant.** `addLine()` already refuses any transfer that is
not `DRAFT` ([nopcommercetransfer.class.php:357](../../../htdocs/custom/nopcommerce/class/nopcommercetransfer.class.php)).
A captured transfer is `PENDING`, so the proposed guard could never fire. The only way
into the hole was `setDraft()` reopening a captured transfer, and that method is now
deleted rather than guarded.

## A property worth keeping: no core changes

This project tracks every modification to files outside the module in a registry
([CORE-CHANGES.md](../../../htdocs/custom/mystore/doc/CORE-CHANGES.md)), because Dolibarr
upgrades overwrite core files and each change has to be re-applied by hand afterwards.

This design touches **no core files**. The `stockproductcard` hook context
([product.php:138](../../../htdocs/product/stock/product.php)) and the `STOCK_MOVEMENT`
trigger ([mouvementstock.class.php:679](../../../htdocs/product/stock/class/mouvementstock.class.php))
both already exist. Nothing is added to the registry and nothing needs re-applying after
an upgrade. This is a constraint on future changes here, not just a happy accident:
decision 22 chose a module-local test directory specifically to avoid registering a
suite in the core `test/phpunit/AllTests.php`.

## Architecture

```
POST action=transfert_stock  (product/stock/product.php)
          |
          v
  doActions hook  [context: stockproductcard]            <-- page scope lives here
  actions_nopcommerce.class.php
    native transfer form? not cancelled?
    source      == NOPCOMMERCE_SOURCE_WAREHOUSE_ID  ?
    destination == NOPCOMMERCE_WEBSHOP_WAREHOUSE_ID ?
    -> park a capture intent
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
                                                  -> create + addLine + validate
                                                     PENDING, origin='native',
                                                     ref NOP-M<mvid>,
                                                     both movement ids stamped,
                                                     both movement rows back-tagged
```

Why the split: page identity is known only to the hook, because a `STOCK_MOVEMENT`
trigger sees a movement, not a request. The movement row id is known only to the
trigger, because `_create` assigns it after the `stockMovementCreate` hook has already
run. Neither seam can do the job alone.

### Why the trigger does the writing

- `_create()` fires `stockMovementCreate` at its **start**
  ([mouvementstock.class.php:262](../../../htdocs/product/stock/class/mouvementstock.class.php)),
  before the row exists, so it cannot supply `fk_mouvement_destination`.
- `call_trigger('STOCK_MOVEMENT')` fires near the **end**, with `product_id`,
  `entrepot_id`, `qty`, `type`, `batch` and `id` all populated.
- `product.php`'s `doActions` hook runs at line 175, before the `transfert_stock` block
  at line 369, so it cannot observe the outcome.

### Why atomicity comes for free

Transactions nest: `product.php` opens one, `correct_stock` opens one, `_create` opens
one. A trigger returning `-1` makes `_create` roll back and return `<0`;
`correct_stock` returns `-1`; `product.php` increments `$error` and rolls back the outer
transaction. Stock movements and the captured transfer commit or fail together, with no
transaction handling of our own.

Note that a missing `stock/mouvement/creer` right makes `product.php` skip the whole
block silently rather than calling `accessforbidden()`, so no capture can occur without
a stock right either.

### Facts come from the movements, not the POST

The intent carries only *that* this is a capture case, plus the product id for the kit
guard. Every other fact — quantity, batch, source warehouse, both movement ids — is read
from the two movement objects.

This is deliberate. When the native form is opened for a specific lot (`pdluoid` set),
[product.php:422](../../../htdocs/product/stock/product.php) takes the source warehouse
from `$pdluo->warehouseid`, **not** from the posted `id_entrepot`. Reading the POST would
record the wrong source warehouse in that case. The `type 1` movement's `entrepot_id` is
always right.

The hook reads `id_entrepot` for its *gate*, but the gate alone is **not** sufficient to
satisfy decision 2, and the trigger must re-verify. For a lot-specific transfer the form
still posts `id_entrepot` while `product.php` uses `$pdluo->warehouseid` instead, so the
two can disagree: a user who opens the form from a lot in warehouse A and then switches
the source select to the configured source would pass the hook's gate even though the
stock actually leaves A.

So the intent carries the expected source, and the trigger checks the out leg's real
`entrepot_id` against it before capturing. If they differ, the intent is forgotten and
nothing is captured — the transfer is simply not a capture case. The hook's gate is an
early exit that avoids parking an intent for the common non-matching case; the trigger's
check is the one that actually enforces decision 2.

## Components

### 1. `class/actions_nopcommerce.class.php` (new)

Hook class, following `custom/mystore/class/actions_mystore.class.php`.

`doActions($parameters, &$object, &$action, $hookmanager)`:

1. Return `0` unless `'stockproductcard'` is in `$hookmanager->contextarray`. Qualify on
   `contextarray`, not `$parameters['currentcontext']` — HookManager runs a module's hook
   method only once, for the first matching context (`$modulealreadyexecuted` in
   `hookmanager.class.php`). This module registers one context today, but the pattern is
   already documented in `actions_mystore.class.php` and should be kept.
2. Return `0` unless `$action == 'transfert_stock'` and no `cancel` was posted.
3. Return `0` unless `isModEnabled('nopcommerce')`.
4. Read `$source = getDolGlobalInt('NOPCOMMERCE_SOURCE_WAREHOUSE_ID')` and
   `$dest = getDolGlobalInt('NOPCOMMERCE_WEBSHOP_WAREHOUSE_ID')`. Return `0` unless both
   are `> 0`, `GETPOSTINT('id_entrepot') == $source` and
   `GETPOSTINT('id_entrepot_destination') == $dest`.
5. `NopCommerceCapture::expect(GETPOSTINT('id'), $source, $dest)`.
6. Return `0`. The hook never alters `$action` and never short-circuits the native flow.

No permission check (decision 9). The module descriptor gains
`'hooks' => array('stockproductcard')`.

### 2. `class/nopcommercecapture.class.php` (new)

The intent holder and the capture logic, so neither the hook nor the trigger carries
business rules.

```
NopCommerceCapture::expect(int $fk_product, int $source, int $dest): void
NopCommerceCapture::isExpected(): bool
NopCommerceCapture::expectedProduct(): int
NopCommerceCapture::expectedSource(): int
NopCommerceCapture::expectedDestination(): int
NopCommerceCapture::forget(): void
NopCommerceCapture::recordSourceLeg(MouvementStock $m): int
NopCommerceCapture::capture(DoliDB $db, User $user, MouvementStock $m): int
```

`recordSourceLeg()` returns `<0` when the out leg's `entrepot_id` does not equal
`expectedSource()`, which the trigger treats as "not a capture case" — it forgets the
intent and returns `0`, leaving the native transfer untouched. This is the check that
enforces decision 2; see "Facts come from the movements" above for why the hook's gate
cannot.

State is a private static, request-scoped. A request handles at most one native transfer,
so one slot is enough; `forget()` runs once `capture()` has, so no later movement in the
same request can be captured again.

`capture()`, in order:

1. Build the transfer: `label` from the movement label, `fk_warehouse_source` from the
   recorded out leg, `fk_warehouse_destination` from this movement.
2. `create($user)` — gives a `DRAFT` row with a `(PROV<id>)` ref, `entity` from
   `$conf->entity`, `fk_user_creat` from `$user`.
3. `addLine($user, $m->product_id, abs($m->qty), (string) $m->batch)` — resolves
   `fk_product_parent` via `ProductCombination::fetchByFkProductChild`.
4. Stamp the line's `fk_mouvement_source` (recorded out leg) and
   `fk_mouvement_destination` (`$m->id`).
5. Stamp the transfer's `origin = 'native'`.
6. `validate($user, 0, 'NOP-M'.$m->id)` — moves it to `PENDING` with the forced ref and
   fires `NOPCOMMERCE_TRANSFER_VALIDATE`.
7. Back-tag both movement rows (see below).
8. Return `>0`, or `<0` with `$error` set for the trigger to propagate.

Step ordering note: `addLine()` must precede `validate()` because it requires `DRAFT`.
With `setDraft()` deleted there is no other ordering constraint — in particular it does
not matter whether `origin` is stamped before or after the line is added.

**Nested triggers.** `capture()` runs inside the `STOCK_MOVEMENT` trigger and itself
fires `NOPCOMMERCE_TRANSFER_VALIDATE`, so triggers nest one level. No core trigger
reacts to that event, and this module's own trigger handles only `STOCK_MOVEMENT`, so
there is no recursion. Test 11 covers it.

### 3. `core/triggers/interface_95_modNopCommerce_NopCommerceCapture.class.php` (new)

Extends `DolibarrTriggers`. Handles `STOCK_MOVEMENT` only.

```
if ($action != 'STOCK_MOVEMENT')                                       return 0;
if (!NopCommerceCapture::isExpected())                                 return 0;
if ($object->product_id != NopCommerceCapture::expectedProduct())      return 0;  // kit guard
if ($object->type == 1) {                                                         // out leg
    if (NopCommerceCapture::recordSourceLeg($object) < 0) {
        NopCommerceCapture::forget();      // real source != configured source
    }
    return 0;
}
if ($object->type != 0)                                                return 0;
if ($object->entrepot_id != NopCommerceCapture::expectedDestination()) return 0;
$res = NopCommerceCapture::capture($this->db, $user, $object);
NopCommerceCapture::forget();
if ($res < 0) { $this->errors[] = ...; return -1; }
return 0;
```

`'triggers' => 1` in `module_parts`, which
[conf.class.php:603](../../../htdocs/core/class/conf.class.php) maps to
`/nopcommerce/core/triggers/`, resolved under `custom/` by `dol_buildpath`.

Two naming constraints come from the loader's regex
([interfaces.class.php:156](../../../htdocs/core/class/interfaces.class.php)),
`/^interface_([0-9]+)_([^_]+)_(.+)\.class\.php$/i`:

- the second segment cannot contain an underscore, so `modNopCommerce` is valid;
- the class name must be `"Interface".ucfirst($reg[3])`, so the file **must** declare
  `class InterfaceNopCommerceCapture extends DolibarrTriggers`. A mismatch is logged and
  silently skipped rather than raising an error.

The loader also derives the module from the second segment and skips the file unless
`isModEnabled('nopcommerce')`, so the trigger needs no module check of its own.

**No recursion risk from the ack path.** `applyAckSuccess()` fires `STOCK_MOVEMENT` for
manual transfers, reaching this trigger; `isExpected()` is false there, so it returns `0`
immediately.

**The kit guard is load-bearing.** `_create` calls `_createSubProduct` *before*
`call_trigger`, and `_createSubProduct` calls `_create` per child, so each child of a kit
fires its own `STOCK_MOVEMENT` with the same type and warehouse. `product.php` passes no
`$disablestockchangeforsubproduct`, so with `PRODUIT_SOUSPRODUITS` enabled a kit transfer
would otherwise capture one transfer per component. Matching `$object->product_id`
against the submitted product confines capture to that product.

### 4. Schema: one new column

`origin varchar(16) NOT NULL DEFAULT 'manual'` on `llx_nopcommerce_transfer`. Values
`'manual'` and `'native'`.

One column, not two. "Stock already moved" is derived:

```php
public function stockAlreadyMoved()
{
    return $this->origin !== 'manual';
}
```

Every row created from now on is `'native'`, so the column is constant for new data. It
still earns its place twice over: rows created by the deleted card page before this
change are `'manual'` and their stock has **not** moved, so they must still move stock on
ack; and the derivation extends correctly if another capture source is ever added.

Delivered as two edits:

- the column added to `sql/llx_nopcommerce_transfer.sql` for fresh installs;
- `sql/llx_nopcommerce_transfer_origin.sql` carrying
  `ALTER TABLE llx_nopcommerce_transfer ADD COLUMN origin varchar(16) NOT NULL DEFAULT 'manual';`
  for installs that already have the table.

`_load_tables` runs `llx_*.sql` in sorted order and `run_sql` tolerates
`DB_ERROR_COLUMN_ALREADY_EXISTS` by default
([admin.lib.php:329](../../../htdocs/core/lib/admin.lib.php)), so the ALTER is idempotent
and harmless on a fresh install. `'.'` sorts before `'_'`, so the base `CREATE TABLE`
runs first.

Add `origin` to `$fields` with `'visible' => 0` and `'noteditable' => 1`.

### 5. `validate()` gains a forced ref; `setDraft()` is deleted

`validate(User $user, $notrigger = 0, $forceref = '')`. When `$forceref` is non-empty it
is used verbatim; otherwise `getNextNumRef()` is consulted as before.

Capture always passes `'NOP-M'.$m->id`. The destination movement id is unique by
construction, so there is no `SELECT MAX` and no race — two concurrent native transfers
produce different refs without coordination. This matters because a ref collision under
the atomic policy would fail the user's **stock transfer**, for a reason unrelated to
anything they did.

`getNextNumRef()` is retained as the fallback but is unreachable while capture is the only
caller of `validate()`. It is kept rather than deleted so `validate()` remains coherent
if a second caller ever appears.

`setDraft()` is deleted outright. It has no caller — the card page is gone and the list
offers only cancel and delete — and no meaning, because a `FAILED` transfer is already
pullable via `isPullable()` and so never needs reopening. Deleting it is what closes the
reopen hole; no guards are added anywhere.

`initAsSpecimen()` sets `ref = 'NOP2601-0001'`; update it to the `NOP-M<id>` shape for
consistency.

### 6. `applyAckSuccess` branches on the derived flag

In the per-line loop, when `stockAlreadyMoved()` is true, skip the source-stock check,
`livraison()` and `reception()`, and leave the `fk_mouvement_*` values written at capture
time untouched. Everything else is unchanged: `sync_flag`, `nop_product_id`,
`nop_combination_id`, `status = SYNCED`, `date_synced`, `sync_attempts`, and the
`NOPCOMMERCE_TRANSFER_SYNCED` trigger.

Skipping the stock check matters as much as skipping the movement. After a native
transfer the source has already been decremented, so the
`NOPCOMMERCE_ALLOW_NEGATIVE_SOURCE_STOCK` check would reject an otherwise healthy
acknowledgement.

`applyAckFailure` needs no change: it moves no stock. A captured transfer the shop
rejects becomes `FAILED` and stays pullable, which is correct — the stock has moved in
Dolibarr and the shop has simply not recorded it yet.

### 7. Movement provenance, and a pre-existing bug fixed

`applyAckSuccess` currently sets `origin_type = 'nopcommercetransfer'` with no module
suffix. `MouvementStock::get_origin()` splits on `@` and, finding none, derives the
module name from the class name, so it tries
`dol_include_once('/nopcommercetransfer/class/nopcommercetransfer.class.php')` — a
directory that does not exist. The include fails and the Origin column in
[movement_list.php:1505](../../../htdocs/product/stock/movement_list.php) renders
**blank** for every synced transfer. This is pre-existing, not introduced here.

Fix: use `'nopcommercetransfer@nopcommerce'` everywhere. The left side must match the
on-disk filename exactly, which lowercase `nopcommercetransfer` does; PHP resolves the
class name case-insensitively, so `ucfirst()` binding to `NopCommerceTransfer` is fine.

At 31 characters the value fits `MouvementStock::$fields`' declared `varchar(32)` with
one character spare, even though the column itself is `varchar(64)`. Renaming the module
directory to anything longer would truncate at that declared width.

Back-tagging, per decision 10, after `validate()`:

```sql
UPDATE llx_stock_mouvement
   SET fk_origin = <transfer id>, origintype = 'nopcommercetransfer@nopcommerce'
 WHERE rowid IN (<out leg id>, <in leg id>)
   AND (fk_origin IS NULL OR fk_origin = 0)
```

`MouvementStock` has no `update()` method, so this is direct SQL. The `IS NULL OR = 0`
condition is required, not cosmetic: `_create` writes `0` and `''` rather than NULL when
no origin is set, so a NULL-only test would never match. The condition also guarantees an
existing provenance is never overwritten.

Both rows are updated inside the native transaction; the in-leg row exists by then
because `_create` has already inserted it and assigned `$mvid`.

### 8. The list page becomes a product list

`transfer_list.php` currently lists transfers. It becomes a list of **lines**, which is
the screen the module is for. Each captured transfer has exactly one line, so line and
transfer are one-to-one and a row action acts on the row's transfer.

Query: `llx_nopcommerce_transferline` joined to `llx_nopcommerce_transfer`, to
`llx_product`, and to `llx_entrepot` twice for the two warehouse refs, filtered by
`getEntity('nopcommercetransfer')`.

Columns, with the sync indicator at the end as asked:

| Column | Source |
|---|---|
| Product | `Product::getNomUrl()` |
| Attributes | `NopCommerceSync::getVariantAttributes()`, variants only |
| Batch | `line.batch`, only when `productbatch` is enabled |
| Qty | `line.qty` |
| Source warehouse | joined `entrepot.ref` |
| Webshop warehouse | joined `entrepot.ref` |
| Date | `transfer.date_creation` |
| Pulled / Synced | `transfer.date_pulled`, `transfer.date_synced` |
| Status | `transfer.getLibStatut(5)` |
| Synced | read-only tick or dot from `line.sync_flag`, with `sync_error` in a tooltip |
| Actions | cancel, delete |

Filters: product, batch, sync flag, status. The existing ref filter is kept so
`getNomUrl()`'s link still lands somewhere — `search_ref` matches `transfer.ref`.

Row actions (decision 17), each with a confirmation:

- **Cancel** — `$user->hasRight('nopcommerce','write')`, offered while the transfer is
  `PENDING` or `FAILED`, calls the existing `cancel()`. Preferred over delete for a
  mistake: a cancelled row stays visible and answers "why did this product never reach
  the shop", where a deleted row is indistinguishable from one never captured.
- **Delete** — `$user->hasRight('nopcommerce','delete')`, offered while the transfer is
  not `SYNCED`, calls the existing `delete()`, for genuine junk.

No validate and no set-draft action: `DRAFT` is unreachable for new rows and
`setDraft()` no longer exists. The status filter keeps its `Draft` option so any
pre-existing draft rows remain visible.

### 9. REST payload

`buildTransferPayload()` gains two transfer-level fields:

```json
{
  "transfer_id": 42,
  "ref": "NOP-M1187",
  "origin": "native",
  "stock_already_moved": true,
  "...": "unchanged"
}
```

`origin` is the stored string; `stock_already_moved` is `stockAlreadyMoved()`. Both are
additive, so an existing nopCommerce plugin keeps working. Keeping the derived field
means the shop never has to hardcode a list of origin strings.

`getPullableTransferIds()` is unchanged — captured transfers are `PENDING` with
`sync_flag = 0`, so they are already pullable.

`doc/api.md` updates: the two fields in the payload table; a model note that a
`native`-origin transfer has already moved its stock in Dolibarr and that acknowledging
it records the products without moving stock again; the ref format; and the
known-limitation note below.

### 10. Known limitation to document

Capture is one-directional. Move ten units in and the shop is told; move six back out to
the source warehouse and nothing tells the shop, which still believes ten are available
and can oversell. `stock_in_webshop_warehouse` in the payload is only a snapshot taken
when something is pulled, so a warehouse with no new transfers never refreshes.

This is accepted, not solved (decision 11). It goes in `doc/api.md` so the shop-side team
can plan around a one-directional contract rather than discovering it through an
oversell.

### 11. Language keys

Added to `langs/en_US/nopcommerce.lang`: `NopCommerceOrigin`,
`NopCommerceOriginManual`, `NopCommerceOriginNative`, `NopCommerceCaptureFailed`,
`NopCommerceSyncProducts` (the list title), `NopCommerceConfirmCancelLine`,
`NopCommerceConfirmDeleteLine`.

Removed: keys left unused by the card page's removal and by `setDraft()`'s deletion —
`NopCommerceSetDraftTransfer`, `NopCommerceConfirmSetDraftTransfer`,
`NopCommerceTransferSetToDraft`, `NopCommerceAddProduct`, `NopCommerceLineAdded`,
`CannotReopenASyncedTransfer`. Verify each against the final code before deleting; the
module's language directory is outside the `check-translations` hook's scope
(`htdocs/langs/en_US/` only), so nothing will catch a mistake here automatically.

## Files touched

| File | Change |
|---|---|
| `class/actions_nopcommerce.class.php` | new — hook class, parks the intent |
| `class/nopcommercecapture.class.php` | new — intent state and capture logic |
| `core/triggers/interface_95_modNopCommerce_NopCommerceCapture.class.php` | new — `STOCK_MOVEMENT` handler |
| `sql/llx_nopcommerce_transfer.sql` | add `origin` column |
| `sql/llx_nopcommerce_transfer_origin.sql` | new — ALTER for existing installs |
| `core/modules/modNopCommerce.class.php` | `'hooks' => array('stockproductcard')`, `'triggers' => 1` |
| `class/nopcommercetransfer.class.php` | `origin` field + property, `stockAlreadyMoved()`, `validate()` forced ref, delete `setDraft()`, `initAsSpecimen()` ref shape |
| `class/nopcommercesync.class.php` | skip stock check and movement legs when already moved; fix `origin_type`; two payload fields |
| `transfer_list.php` | rewritten as a product/line list with cancel and delete row actions |
| `langs/en_US/nopcommerce.lang` | add and remove keys |
| `doc/api.md` | payload fields, ref format, model note, known limitation |
| `test/NopCommerceCaptureTest.php` | new — PHPUnit, module-local |

No core files. Nothing for the core-changes registry.

## Testing

### Automated — `htdocs/custom/nopcommerce/test/NopCommerceCaptureTest.php`

Follows `test/phpunit/MouvementStockTest.php`: `require` `htdocs/master.inc.php` (a
deeper relative path from here), extend `CommonClassTest`, run against a configured
Dolibarr database. Not registered in the core `AllTests.php` (decision 22), so it is run
explicitly; record the command in `doc/`.

Cases, chosen because each one fails silently and expensively:

1. **Capture happens.** Native-equivalent movement pair with the configured warehouses
   produces one `PENDING` transfer, `origin = 'native'`, ref `NOP-M<mvid>`, one line with
   the right product, qty, batch and both `fk_mouvement_*` ids.
2. **Ack moves no stock.** Ack a captured transfer and assert warehouse stock is
   **byte-identical** before and after, while `status = 2`, `sync_flag = 1` and
   `date_synced` are set.
3. **Manual rows still move stock.** A transfer with `origin = 'manual'` still moves
   stock on ack. This is the regression that matters most — it is the only thing standing
   between pre-existing rows and silently un-moved stock.
4. **Atomic failure.** Force `capture()` to fail and assert stock is unchanged in both
   warehouses and no transfer row exists.
5. **Kit captures once.** With `PRODUIT_SOUSPRODUITS` enabled, a kit produces exactly one
   captured transfer, for the kit product.
6. **Wrong warehouse pair captures nothing.** A movement pair that does not match the
   configured warehouses produces no transfer row.
7. **Out leg from the wrong source captures nothing.** Park an intent whose expected
   source is the configured warehouse, then run an out leg from a *different* warehouse.
   Assert no transfer row and that the stock movements still commit. This is the
   lot-specific hole the hook's gate cannot catch, so it needs a test of its own rather
   than being folded into case 6.

### Manual

8. **End to end from the real page.** Native transfer with the configured pair; check the
   row appears in the list with the product, qty and an unsynced indicator.
9. **Lot-specific form.** Open the form from a lot row so `pdluoid` is set. Assert
   `batch` is recorded and `fk_warehouse_source` is the **lot's** warehouse.
10. **Variant.** Capture a variant child; `fk_product_parent` set and `attributes`
   populated in the payload.
11. **Row actions.** Cancel a captured row and confirm it shows as `CANCELED` and stops
    being pulled; delete another and confirm it is gone. Check both respect their rights.
12. **Origin column.** Confirm the stock movement list's Origin column now links to the
    transfer for both a captured and a previously synced transfer — this is the
    pre-existing bug from component 7, so verify it against a row created before the fix
    as well.
13. **Migration.** Enable the module on a database that already has the table without
    `origin`; the column is added and existing rows default to `'manual'`.
14. **No rights, no capture.** A user without `stock/mouvement/creer` cannot transfer at
    all; a user with it but without any nopcommerce right captures successfully
    (decision 9).

## Out of scope

- Capturing mass stock move, the native StockTransfer module, or movement reversal. The
  decision lives in the hook, so adding one later is a one-place change.
- Telling the shop when stock leaves the webshop warehouse (component 10).
- Reversing a captured transfer's stock when the shop reports failure. A `FAILED`
  captured transfer keeps its moved stock and stays pullable.
- A setup-page warning for unset warehouses (decision 18). Blank settings silently
  disable capture.
- Any click-to-toggle on the sync indicator (decision 15).

## Pre-existing draft rows

Rows left `DRAFT` by the deleted card page can no longer be validated or pulled, and the
list offers cancel only from `PENDING` or `FAILED`, so they are inert.

They are left alone: no migration touches them. They are development leftovers rather
than real data, they cost nothing where they sit, and the list's status filter still has
its `Draft` option so they stay visible to anyone who looks. Deleting rows as a side
effect of a schema migration is the kind of thing that is regretted exactly once, and an
inert row is a much smaller problem than a migration that removed something real. Clean
them up by hand if any turn out to exist and bother you.
