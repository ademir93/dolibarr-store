# nopCommerce Native Transfer Capture Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Queue a product for nopCommerce sync automatically when a user transfers its stock into the webshop warehouse on Dolibarr's native stock transfer screen.

**Architecture:** A page-level hook on `stockproductcard` recognises the native transfer submit and parks a request-scoped intent; the `STOCK_MOVEMENT` trigger then writes the sync transfer, because it is the only seam that runs after the stock movement rows exist. The captured transfer is marked `origin='native'`, which makes the REST acknowledgement skip its stock-movement legs so stock is never moved twice.

**Tech Stack:** PHP 7.4+, Dolibarr 19+ custom module (`htdocs/custom/nopcommerce`), MySQL/InnoDB, PHPUnit via Dolibarr's `CommonClassTest` harness.

**Spec:** `docs/superpowers/specs/2026-09-12-nopcommerce-native-transfer-capture-design.md`

## Global Constraints

- PHP 7.4 minimum (`$this->phpmin = array(7, 4)`); no PHP 8-only syntax.
- Dolibarr 19 minimum (`$this->need_dolibarr_version = array(19, -3)`).
- **No core file modifications.** Every file you touch must be under `htdocs/custom/nopcommerce/`. This project re-applies core changes by hand after each Dolibarr upgrade and tracks them in a registry; this feature must add nothing to it. If you believe a core change is needed, stop and ask.
- Indent with **tabs**, matching every existing file in the module.
- Every new PHP and SQL file starts with the GPL v3 header used by the module's existing files, `Copyright (C) 2026 Demir Agovic`.
- New database columns ship twice: in the `CREATE TABLE` for fresh installs, and as a separate idempotent `ALTER TABLE` file for existing installs.
- Class name of a trigger file is dictated by its filename: `interface_<NN>_<module>_<Name>.class.php` must declare `class Interface<Name>`. A mismatch is silently skipped, not reported.
- After editing any PHP file, run `php -l` on it.

## Verification commands

```bash
php -l htdocs/custom/nopcommerce/class/nopcommercecapture.class.php
```

```bash
cd /var/www/html/dolibarr-store && ./vendor/bin/phpunit --no-configuration htdocs/custom/nopcommerce/test/NopCommerceCaptureTest.php
```

If `./vendor/bin/phpunit` does not exist, use the system `phpunit`. The test needs a
working Dolibarr install (it bootstraps `htdocs/master.inc.php` and talks to the real
database). The harness wraps every test class in a transaction and rolls it back in
`tearDownAfterClass`, so tests do not pollute data.

**After any change to the module descriptor** (`modNopCommerce.class.php`), the module must
be disabled and re-enabled in Dolibarr's admin UI for `module_parts` (hooks, triggers) and
new SQL to take effect. Several tasks below depend on this; it is called out where needed.

---

## Correction to the spec's test plan

The spec lists an automated test asserting that a failed capture leaves stock unchanged.
**That test cannot be written at the primary seam**, and the plan replaces it.

Dolibarr's transaction nesting is a counter, not savepoints
([DoliDB.class.php:265](../../../htdocs/core/db/DoliDB.class.php)): a nested `rollback()`
only decrements `transaction_opened` and undoes nothing. Only the outermost rollback issues
a real `ROLLBACK`. In production that is `product.php`'s, which is why atomicity holds. But
the PHPUnit harness opens the outermost transaction itself in `setUpBeforeClass`, so inside
a test nothing the capture wrote is actually rolled back and "assert stock unchanged" would
fail for the wrong reason.

What the module is actually responsible for is **returning a negative result so the caller
rolls back**. Task 4 tests that contract. The real rollback is existing Dolibarr behaviour
and is verified by manual step M5.

---

## File structure

| File | Responsibility |
|---|---|
| `class/nopcommercecapture.class.php` | **new.** Request-scoped capture intent, plus the capture logic that writes the transfer and tags the movements. All business rules live here so neither the hook nor the trigger carries any. |
| `class/actions_nopcommerce.class.php` | **new.** Page-level hook. Recognises the native transfer submit and parks an intent. No writes, no business rules. |
| `core/triggers/interface_95_modNopCommerce_NopCommerceCapture.class.php` | **new.** `STOCK_MOVEMENT` handler. Routes the two movement legs to the capture class and propagates failure. |
| `test/NopCommerceCaptureTest.php` | **new.** PHPUnit, module-local so a Dolibarr upgrade neither overwrites it nor needs a registration re-applied. |
| `sql/llx_nopcommerce_transfer.sql` | add the `origin` column for fresh installs. |
| `sql/llx_nopcommerce_transfer_origin.sql` | **new.** Idempotent `ALTER TABLE` for existing installs. |
| `class/nopcommercetransfer.class.php` | `origin` field and property, origin constants, `stockAlreadyMoved()`, forced-ref parameter on `validate()`, delete `setDraft()`. |
| `class/nopcommercesync.class.php` | skip the stock check and movement legs when already moved; fix the movement provenance string; two payload fields. |
| `class/api_nopcommerce.class.php` | acknowledgement response message must stop claiming stock moved. |
| `transfer_list.php` | rewritten as a list of product lines with cancel and delete row actions. |
| `langs/en_US/nopcommerce.lang` | add keys, remove dead keys, correct descriptions that the re-scope made false. |
| `doc/api.md` | payload fields, ref format, model note, known limitation. |
| `doc/testing.md` | **new.** How to run the module's tests. |

---

### Task 1: The `origin` column and the test harness

**Files:**
- Modify: `htdocs/custom/nopcommerce/sql/llx_nopcommerce_transfer.sql`
- Create: `htdocs/custom/nopcommerce/sql/llx_nopcommerce_transfer_origin.sql`
- Modify: `htdocs/custom/nopcommerce/class/nopcommercetransfer.class.php`
- Test: `htdocs/custom/nopcommerce/test/NopCommerceCaptureTest.php`
- Create: `htdocs/custom/nopcommerce/doc/testing.md`

**Interfaces:**
- Consumes: nothing.
- Produces: `NopCommerceTransfer::ORIGIN_MANUAL` (`'manual'`), `NopCommerceTransfer::ORIGIN_NATIVE` (`'native'`), `NopCommerceTransfer::ORIGIN_TYPE` (`'nopcommercetransfer@nopcommerce'`), public property `$origin` (string), `stockAlreadyMoved(): bool`. Also produces the test file every later task adds to.

- [ ] **Step 1: Write the failing test**

Create `htdocs/custom/nopcommerce/test/NopCommerceCaptureTest.php`:

```php
<?php
/* Copyright (C) 2026 Demir Agovic
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    htdocs/custom/nopcommerce/test/NopCommerceCaptureTest.php
 * \ingroup nopcommerce
 * \brief   PHPUnit tests for capturing native stock transfers.
 */

global $conf, $user, $langs, $db;

require_once dirname(__FILE__).'/../../../master.inc.php';
require_once dirname(__FILE__).'/../../../product/class/product.class.php';
require_once dirname(__FILE__).'/../../../product/stock/class/entrepot.class.php';
require_once dirname(__FILE__).'/../../../product/stock/class/mouvementstock.class.php';
require_once dirname(__FILE__).'/../../../../test/phpunit/CommonClassTest.class.php';
require_once dirname(__FILE__).'/../class/nopcommercetransfer.class.php';
require_once dirname(__FILE__).'/../class/nopcommercetransferline.class.php';

if (empty($user->id)) {
	$user->fetch(1);
	$user->loadRights();
}
$conf->global->MAIN_DISABLE_ALL_MAILS = 1;


/**
 * Class for PHPUnit tests of the nopCommerce capture.
 *
 * @backupGlobals disabled
 * @backupStaticAttributes enabled
 * @remarks backupGlobals must be disabled so db, conf, user and langs are not erased.
 */
class NopCommerceCaptureTest extends CommonClassTest
{
	/**
	 * A transfer built by hand has not moved its stock yet.
	 *
	 * @return void
	 */
	public function testManualOriginHasNotMovedStock()
	{
		global $db;
		$db = $this->savdb;

		$transfer = new NopCommerceTransfer($db);

		$this->assertSame(NopCommerceTransfer::ORIGIN_MANUAL, $transfer->origin, 'A new transfer defaults to the manual origin');
		$this->assertFalse($transfer->stockAlreadyMoved(), 'A manual transfer must still move stock on acknowledgement');
	}

	/**
	 * A captured transfer has already moved its stock on the native page.
	 *
	 * @return void
	 */
	public function testNativeOriginHasAlreadyMovedStock()
	{
		global $db;
		$db = $this->savdb;

		$transfer = new NopCommerceTransfer($db);
		$transfer->origin = NopCommerceTransfer::ORIGIN_NATIVE;

		$this->assertTrue($transfer->stockAlreadyMoved(), 'A captured transfer must not move stock again');
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd /var/www/html/dolibarr-store && ./vendor/bin/phpunit --no-configuration htdocs/custom/nopcommerce/test/NopCommerceCaptureTest.php`

Expected: FAIL — `Undefined constant NopCommerceTransfer::ORIGIN_MANUAL`.

- [ ] **Step 3: Add the constants, property, field and method**

In `class/nopcommercetransfer.class.php`, add after the existing `const STATUS_CANCELED = 9;`:

```php
	const ORIGIN_MANUAL = 'manual';
	const ORIGIN_NATIVE = 'native';

	/**
	 * Value written to llx_stock_mouvement.origintype so the stock movement list can
	 * resolve a movement back to its transfer. The part before the "@" must match the
	 * class file name on disk, the part after it the module directory.
	 */
	const ORIGIN_TYPE = 'nopcommercetransfer@nopcommerce';
```

In `$fields`, immediately after the `sync_flag` entry:

```php
		'origin' => array('type' => 'varchar(16)', 'label' => 'NopCommerceOrigin', 'enabled' => 1, 'position' => 49, 'notnull' => 1, 'visible' => 0, 'noteditable' => 1, 'default' => 'manual'),
```

Add the property next to `$sync_flag`:

```php
	/**
	 * @var string self::ORIGIN_MANUAL when built by hand, self::ORIGIN_NATIVE when captured
	 *             from a native stock transfer. Drives stockAlreadyMoved().
	 */
	public $origin = self::ORIGIN_MANUAL;
```

Add the method after `isPullable()`:

```php
	/**
	 * Return true when this transfer's stock was already moved outside the sync.
	 *
	 * A captured transfer moves its stock on the native stock transfer page, before
	 * nopCommerce ever sees it, so the acknowledgement must not move it a second time.
	 * Derived from the origin rather than stored, so a further capture source needs no
	 * schema change.
	 *
	 * @return bool	True when the stock has already moved
	 */
	public function stockAlreadyMoved()
	{
		return $this->origin !== self::ORIGIN_MANUAL;
	}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `cd /var/www/html/dolibarr-store && ./vendor/bin/phpunit --no-configuration htdocs/custom/nopcommerce/test/NopCommerceCaptureTest.php`

Expected: PASS, 2 tests.

- [ ] **Step 5: Add the column to the table definition**

In `sql/llx_nopcommerce_transfer.sql`, add immediately after the `sync_flag` line:

```sql
	origin                   varchar(16) NOT NULL DEFAULT 'manual', -- 'manual'=built by hand (legacy), 'native'=captured from a stock transfer
```

- [ ] **Step 6: Create the migration for existing installs**

Create `sql/llx_nopcommerce_transfer_origin.sql`:

```sql
-- ============================================================================
-- Copyright (C) 2026 Demir Agovic
--
-- This program is free software; you can redistribute it and/or modify
-- it under the terms of the GNU General Public License as published by
-- the Free Software Foundation; either version 3 of the License, or
-- (at your option) any later version.
--
-- This program is distributed in the hope that it will be useful,
-- but WITHOUT ANY WARRANTY; without even the implied warranty of
-- MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
-- GNU General Public License for more details.
--
-- You should have received a copy of the GNU General Public License
-- along with this program. If not, see <https://www.gnu.org/licenses/>.
-- ============================================================================

-- Adds the origin column to installs that already have llx_nopcommerce_transfer.
-- run_sql() tolerates DB_ERROR_COLUMN_ALREADY_EXISTS, so this is idempotent and is a
-- no-op on a fresh install where llx_nopcommerce_transfer.sql already declares it.

ALTER TABLE llx_nopcommerce_transfer ADD COLUMN origin varchar(16) NOT NULL DEFAULT 'manual';
```

- [ ] **Step 7: Apply the migration and verify the column exists**

Disable then re-enable the nopCommerce module in Dolibarr's admin UI (Home → Setup →
Modules). Then confirm:

```bash
mysql -e "SHOW COLUMNS FROM llx_nopcommerce_transfer LIKE 'origin';" dolibarr
```

Expected: one row, `varchar(16)`, `NO` null, default `manual`. Substitute your database
name if it is not `dolibarr`.

- [ ] **Step 8: Document how to run the tests**

Create `doc/testing.md`:

```markdown
# Running the nopCommerce module tests

The tests live with the module rather than in Dolibarr's `test/phpunit/` directory, so a
Dolibarr upgrade neither overwrites them nor requires a registration to be re-applied.
They are therefore **not** picked up by Dolibarr's `AllTests.php` and must be run
explicitly:

```bash
cd /var/www/html/dolibarr-store && ./vendor/bin/phpunit --no-configuration htdocs/custom/nopcommerce/test/NopCommerceCaptureTest.php
```

They bootstrap `htdocs/master.inc.php` and run against the real configured database. The
shared `CommonClassTest` harness wraps each test class in a transaction and rolls it back
afterwards, so they do not leave data behind.

Set `PHPUNIT_DEBUG=1` to see per-test tracing.

## What is deliberately not covered

Real transaction rollback. Dolibarr's nested transactions are a counter, not savepoints,
and the harness owns the outermost transaction, so a rollback inside a test undoes
nothing. The module's contract is to return a negative result so its caller rolls back,
which is what `testCaptureFailurePropagates` asserts. Verify the actual rollback by hand
on the native stock transfer page.
```

- [ ] **Step 9: Commit**

```bash
git add htdocs/custom/nopcommerce/sql htdocs/custom/nopcommerce/class/nopcommercetransfer.class.php htdocs/custom/nopcommerce/test htdocs/custom/nopcommerce/doc/testing.md
git commit -m "feat(nopcommerce): add transfer origin column and module test harness"
```

---

### Task 2: Forced ref on validate, and delete setDraft

**Files:**
- Modify: `htdocs/custom/nopcommerce/class/nopcommercetransfer.class.php`
- Test: `htdocs/custom/nopcommerce/test/NopCommerceCaptureTest.php`

**Interfaces:**
- Consumes: `NopCommerceTransfer::ORIGIN_MANUAL` from Task 1.
- Produces: `validate(User $user, int $notrigger = 0, string $forceref = ''): int`. `setDraft()` no longer exists.

- [ ] **Step 1: Write the failing test**

Add to `NopCommerceCaptureTest`:

```php
	/**
	 * Build a warehouse for tests and return its id.
	 *
	 * @param	string	$suffix		Suffix making the label unique
	 * @return	int					Warehouse id
	 */
	private function makeWarehouse($suffix)
	{
		global $db, $user;

		$warehouse = new Entrepot($db);
		$warehouse->initAsSpecimen();
		$warehouse->label .= ' phpunit nop '.$suffix;
		$warehouse->description .= ' phpunit nop '.$suffix;
		$id = $warehouse->create($user);
		$this->assertGreaterThan(0, $id, 'Failed to create warehouse '.$suffix.': '.$warehouse->error);

		return $id;
	}

	/**
	 * Build a simple product for tests and return it.
	 *
	 * @param	string	$suffix		Suffix making the ref unique
	 * @return	Product				Created product
	 */
	private function makeProduct($suffix)
	{
		global $db, $user;

		$product = new Product($db);
		$product->initAsSpecimen();
		$product->ref .= ' phpunit nop '.$suffix;
		$product->label .= ' phpunit nop '.$suffix;
		$id = $product->create($user);
		$this->assertGreaterThan(0, $id, 'Failed to create product '.$suffix.': '.$product->error);

		return $product;
	}

	/**
	 * validate() uses the ref it is given instead of the sequential counter.
	 *
	 * @return void
	 */
	public function testValidateAcceptsAForcedRef()
	{
		global $db, $user;
		$db = $this->savdb;

		$source = $this->makeWarehouse('vr-src');
		$dest = $this->makeWarehouse('vr-dst');
		$product = $this->makeProduct('vr');

		$transfer = new NopCommerceTransfer($db);
		$transfer->fk_warehouse_source = $source;
		$transfer->fk_warehouse_destination = $dest;
		$this->assertGreaterThan(0, $transfer->create($user), 'create failed: '.$transfer->error);
		$this->assertGreaterThan(0, $transfer->addLine($user, $product->id, 2.0, ''), 'addLine failed: '.$transfer->error);

		$this->assertGreaterThan(0, $transfer->validate($user, 0, 'NOP-M999001'), 'validate failed: '.$transfer->error);
		$this->assertSame('NOP-M999001', $transfer->ref, 'The forced ref must be used verbatim');
		$this->assertSame(NopCommerceTransfer::STATUS_PENDING, (int) $transfer->status, 'validate must move the transfer to pending');
	}

	/**
	 * setDraft() is gone, so a captured transfer can never be reopened and have a line
	 * added whose stock has not moved.
	 *
	 * @return void
	 */
	public function testSetDraftNoLongerExists()
	{
		$this->assertFalse(method_exists('NopCommerceTransfer', 'setDraft'), 'setDraft must be deleted, not guarded');
	}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `cd /var/www/html/dolibarr-store && ./vendor/bin/phpunit --no-configuration htdocs/custom/nopcommerce/test/NopCommerceCaptureTest.php`

Expected: `testValidateAcceptsAForcedRef` FAILS (the ref is `NOP<yymm>-0001`, not the forced value) and `testSetDraftNoLongerExists` FAILS (the method still exists).

- [ ] **Step 3: Add the forced-ref parameter**

In `class/nopcommercetransfer.class.php`, change the `validate` signature and its docblock:

```php
	/**
	 * Move the transfer to PENDING so nopCommerce can pull it. No stock is moved here:
	 * for a manual transfer the stock moves once nopCommerce acknowledges it, and for a
	 * captured one it already moved on the native stock transfer page.
	 *
	 * @param	User		$user		User that validates
	 * @param	int<0,1>	$notrigger	0=launch triggers after, 1=disable triggers
	 * @param	string		$forceref	Ref to use verbatim. Capture passes a ref derived
	 *                                  from the destination stock movement id, which is
	 *                                  unique by construction and so cannot race the way
	 *                                  getNextNumRef() does. Empty falls back to the counter.
	 * @return	int<-1,1>				Return integer <0 if KO, >0 if OK
	 */
	public function validate(User $user, $notrigger = 0, $forceref = '')
```

and inside it replace:

```php
		$newref = $this->getNextNumRef();
```

with:

```php
		$newref = ($forceref !== '') ? $forceref : $this->getNextNumRef();
```

- [ ] **Step 4: Delete setDraft()**

Delete the whole `setDraft()` method from `class/nopcommercetransfer.class.php` — the
docblock and the method body, from `/**` through the closing `}`. Leave `cancel()` and
`clearPullToken()` untouched; `cancel()` still calls `clearPullToken()`.

Then update `initAsSpecimen()`'s ref to the new shape:

```php
		$this->ref = 'NOP-M1187';
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `cd /var/www/html/dolibarr-store && ./vendor/bin/phpunit --no-configuration htdocs/custom/nopcommerce/test/NopCommerceCaptureTest.php`

Expected: PASS, 4 tests.

- [ ] **Step 6: Confirm nothing still calls setDraft**

Run:

```bash
grep -rn "setDraft\|confirm_setdraft" htdocs/custom/nopcommerce/ || echo "no callers remain"
```

Expected: `no callers remain`. If anything is listed, it is a leftover from the removed
card page — remove that reference too.

- [ ] **Step 7: Commit**

```bash
git add htdocs/custom/nopcommerce/class/nopcommercetransfer.class.php htdocs/custom/nopcommerce/test/NopCommerceCaptureTest.php
git commit -m "feat(nopcommerce): accept a forced ref on validate and delete setDraft"
```

---

### Task 3: The capture intent

**Files:**
- Create: `htdocs/custom/nopcommerce/class/nopcommercecapture.class.php`
- Test: `htdocs/custom/nopcommerce/test/NopCommerceCaptureTest.php`

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces: `NopCommerceCapture::expect(int $fk_product, int $source, int $destination): void`, `isExpected(): bool`, `expectedProduct(): int`, `expectedSource(): int`, `expectedDestination(): int`, `sourceMovementId(): int`, `forget(): void`, `recordSourceLeg(MouvementStock $m): int`, and the public static `$error` string.

- [ ] **Step 1: Write the failing test**

Add to `NopCommerceCaptureTest`:

```php
	/**
	 * No intent is parked unless the hook parks one.
	 *
	 * @return void
	 */
	public function testNoIntentByDefault()
	{
		NopCommerceCapture::forget();

		$this->assertFalse(NopCommerceCapture::isExpected(), 'Nothing must be captured without an intent');
	}

	/**
	 * The intent carries what the hook saw on the submitted form.
	 *
	 * @return void
	 */
	public function testIntentCarriesWhatTheHookSaw()
	{
		NopCommerceCapture::forget();
		NopCommerceCapture::expect(11, 22, 33);

		$this->assertTrue(NopCommerceCapture::isExpected());
		$this->assertSame(11, NopCommerceCapture::expectedProduct());
		$this->assertSame(22, NopCommerceCapture::expectedSource());
		$this->assertSame(33, NopCommerceCapture::expectedDestination());

		NopCommerceCapture::forget();
		$this->assertFalse(NopCommerceCapture::isExpected(), 'forget() must clear the intent');
	}

	/**
	 * The outbound leg is accepted only when the stock really left the configured source.
	 *
	 * The hook's own check reads the submitted id_entrepot, which the lot-specific form
	 * ignores in favour of the lot's warehouse, so the real enforcement is here.
	 *
	 * @return void
	 */
	public function testSourceLegFromAForeignWarehouseIsRejected()
	{
		global $db;
		$db = $this->savdb;

		NopCommerceCapture::forget();
		NopCommerceCapture::expect(11, 22, 33);

		$movement = new MouvementStock($db);
		$movement->id = 4242;
		$movement->entrepot_id = 99;

		$this->assertLessThan(0, NopCommerceCapture::recordSourceLeg($movement), 'A leg from warehouse 99 must be refused when 22 is configured');

		$movement->entrepot_id = 22;
		$this->assertGreaterThan(0, NopCommerceCapture::recordSourceLeg($movement), 'A leg from the configured source must be accepted');
		$this->assertSame(4242, NopCommerceCapture::sourceMovementId());

		NopCommerceCapture::forget();
	}
```

Add the require near the other module requires at the top of the file:

```php
require_once dirname(__FILE__).'/../class/nopcommercecapture.class.php';
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `cd /var/www/html/dolibarr-store && ./vendor/bin/phpunit --no-configuration htdocs/custom/nopcommerce/test/NopCommerceCaptureTest.php`

Expected: FAIL — `Failed opening required .../nopcommercecapture.class.php`.

- [ ] **Step 3: Create the capture class with its intent state**

Create `class/nopcommercecapture.class.php`:

```php
<?php
/* Copyright (C) 2026 Demir Agovic
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file        htdocs/custom/nopcommerce/class/nopcommercecapture.class.php
 * \ingroup     nopcommerce
 * \brief       Captures a native stock transfer into a nopCommerce sync transfer.
 */

require_once DOL_DOCUMENT_ROOT.'/product/stock/class/mouvementstock.class.php';
dol_include_once('/nopcommerce/class/nopcommercetransfer.class.php');
dol_include_once('/nopcommerce/class/nopcommercetransferline.class.php');


/**
 * Capture of a native stock transfer.
 *
 * Two seams cooperate to notice a native transfer, and this class holds everything that
 * is neither of them. The page hook knows which page was submitted but runs before the
 * stock moves; the STOCK_MOVEMENT trigger runs after the movement rows exist but cannot
 * tell which page caused them. So the hook parks an intent here, and the trigger hands
 * the movements here once they exist.
 *
 * All facts are read from the movements rather than the submitted form, because the
 * lot-specific transfer form takes its source warehouse from the lot and not from the
 * posted id_entrepot.
 */
class NopCommerceCapture
{
	/**
	 * @var ?array{product:int,source:int,destination:int} Parked intent, null when there is none
	 */
	private static $intent = null;

	/**
	 * @var int Id of the llx_stock_mouvement row of the outbound leg, 0 until it is seen
	 */
	private static $sourcemovementid = 0;

	/**
	 * @var string Last error, read by the trigger so it can be reported to the user
	 */
	public static $error = '';

	/**
	 * Record that the native transfer form was submitted for a pair of warehouses that
	 * the module is configured to capture.
	 *
	 * @param	int		$fk_product		Product the user submitted
	 * @param	int		$source			Configured source warehouse
	 * @param	int		$destination	Configured webshop warehouse
	 * @return	void
	 */
	public static function expect($fk_product, $source, $destination)
	{
		self::$intent = array(
			'product' => (int) $fk_product,
			'source' => (int) $source,
			'destination' => (int) $destination,
		);
		self::$sourcemovementid = 0;
		self::$error = '';
	}

	/**
	 * Is a capture expected in this request?
	 *
	 * @return bool	True when an intent is parked
	 */
	public static function isExpected()
	{
		return self::$intent !== null;
	}

	/**
	 * @return int	Product the intent was parked for, 0 when there is no intent
	 */
	public static function expectedProduct()
	{
		return self::$intent === null ? 0 : (int) self::$intent['product'];
	}

	/**
	 * @return int	Configured source warehouse, 0 when there is no intent
	 */
	public static function expectedSource()
	{
		return self::$intent === null ? 0 : (int) self::$intent['source'];
	}

	/**
	 * @return int	Configured webshop warehouse, 0 when there is no intent
	 */
	public static function expectedDestination()
	{
		return self::$intent === null ? 0 : (int) self::$intent['destination'];
	}

	/**
	 * @return int	Movement id of the outbound leg, 0 until it has been recorded
	 */
	public static function sourceMovementId()
	{
		return (int) self::$sourcemovementid;
	}

	/**
	 * Drop the intent. Called once a capture has run, and whenever the movements turn out
	 * not to match, so no later movement in the same request can be captured.
	 *
	 * @return void
	 */
	public static function forget()
	{
		self::$intent = null;
		self::$sourcemovementid = 0;
	}

	/**
	 * Record the outbound leg of the transfer.
	 *
	 * This is where the configured source warehouse is really enforced. The hook checks
	 * the posted id_entrepot, but product.php ignores that field when the form was opened
	 * for a specific lot and uses the lot's warehouse instead, so a user could otherwise
	 * pass the hook's check while the stock leaves a different warehouse.
	 *
	 * @param	MouvementStock	$m	The outbound movement
	 * @return	int<-1,1>			<0 when the stock did not leave the configured source
	 */
	public static function recordSourceLeg(MouvementStock $m)
	{
		if (self::$intent === null) {
			return -1;
		}
		if ((int) $m->entrepot_id !== self::expectedSource()) {
			self::$error = 'Stock left warehouse '.((int) $m->entrepot_id).' but '.self::expectedSource().' is configured as the source';
			return -1;
		}

		self::$sourcemovementid = (int) $m->id;

		return 1;
	}
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `cd /var/www/html/dolibarr-store && ./vendor/bin/phpunit --no-configuration htdocs/custom/nopcommerce/test/NopCommerceCaptureTest.php`

Expected: PASS, 7 tests.

- [ ] **Step 5: Lint and commit**

```bash
php -l htdocs/custom/nopcommerce/class/nopcommercecapture.class.php
```

```bash
git add htdocs/custom/nopcommerce/class/nopcommercecapture.class.php htdocs/custom/nopcommerce/test/NopCommerceCaptureTest.php
git commit -m "feat(nopcommerce): add the capture intent, enforcing the source warehouse"
```

---

### Task 4: Capture writes the transfer, driven by the trigger

This is the heart of the feature. The trigger is included here rather than split out
because the capture logic has no honest test without real trigger dispatch — calling
`capture()` directly would bypass the nested transactions and the dispatch order that the
interesting failures live in.

**Files:**
- Modify: `htdocs/custom/nopcommerce/class/nopcommercecapture.class.php`
- Create: `htdocs/custom/nopcommerce/core/triggers/interface_95_modNopCommerce_NopCommerceCapture.class.php`
- Modify: `htdocs/custom/nopcommerce/core/modules/modNopCommerce.class.php`
- Test: `htdocs/custom/nopcommerce/test/NopCommerceCaptureTest.php`

**Interfaces:**
- Consumes: `NopCommerceTransfer::ORIGIN_NATIVE`, `ORIGIN_TYPE` (Task 1); `validate($user, $notrigger, $forceref)` (Task 2); the whole intent API (Task 3).
- Produces: `NopCommerceCapture::capture(DoliDB $db, User $user, MouvementStock $m): int` returning the new transfer id or `<0`; `class InterfaceNopCommerceCapture`.

- [ ] **Step 1: Write the failing test**

Add to `NopCommerceCaptureTest`:

```php
	/**
	 * A native transfer between the configured warehouses is captured as one pending
	 * transfer holding one line, with both stock movements recorded and tagged.
	 *
	 * This drives the real seam: it performs the same two stock corrections product.php
	 * performs and lets Dolibarr's own trigger dispatch fire.
	 *
	 * @return void
	 */
	public function testNativeTransferIsCaptured()
	{
		global $conf, $db, $user;
		$db = $this->savdb;

		$source = $this->makeWarehouse('cap-src');
		$dest = $this->makeWarehouse('cap-dst');
		$product = $this->makeProduct('cap');

		$conf->global->NOPCOMMERCE_SOURCE_WAREHOUSE_ID = $source;
		$conf->global->NOPCOMMERCE_WEBSHOP_WAREHOUSE_ID = $dest;

		// Seed the source warehouse. No intent is parked yet, so this must not be captured.
		$this->assertGreaterThan(0, $product->correct_stock($user, $source, 10, 0, 'phpunit seed', 0, 'PHPUNITSEED'), 'Failed to seed stock');

		NopCommerceCapture::forget();
		NopCommerceCapture::expect($product->id, $source, $dest);

		// The two legs, in the order product.php performs them.
		$this->assertGreaterThan(0, $product->correct_stock($user, $source, 3, 1, 'phpunit transfer', 0, 'PHPUNITXFER'), 'Outbound leg failed');
		$this->assertGreaterThan(0, $product->correct_stock($user, $dest, 3, 0, 'phpunit transfer', 0, 'PHPUNITXFER'), 'Inbound leg failed');

		$sql = "SELECT rowid, ref, status, origin, sync_flag, fk_warehouse_source, fk_warehouse_destination";
		$sql .= " FROM ".MAIN_DB_PREFIX."nopcommerce_transfer";
		$sql .= " WHERE fk_warehouse_destination = ".((int) $dest);
		$resql = $db->query($sql);
		$this->assertNotFalse($resql, 'Query failed: '.$db->lasterror());
		$this->assertSame(1, (int) $db->num_rows($resql), 'Exactly one transfer must be captured');

		$obj = $db->fetch_object($resql);
		$this->assertSame(NopCommerceTransfer::ORIGIN_NATIVE, $obj->origin, 'The transfer must be marked as captured');
		$this->assertSame(NopCommerceTransfer::STATUS_PENDING, (int) $obj->status, 'The transfer must be pending, ready to pull');
		$this->assertSame(0, (int) $obj->sync_flag, 'A freshly captured transfer is not synced');
		$this->assertSame($source, (int) $obj->fk_warehouse_source, 'The source must be the warehouse the stock left');
		$this->assertStringStartsWith('NOP-M', $obj->ref, 'The ref must be derived from the movement id');

		$sql = "SELECT fk_product, qty, fk_mouvement_source, fk_mouvement_destination, sync_flag";
		$sql .= " FROM ".MAIN_DB_PREFIX."nopcommerce_transferline";
		$sql .= " WHERE fk_nopcommercetransfer = ".((int) $obj->rowid);
		$resql = $db->query($sql);
		$this->assertNotFalse($resql, 'Query failed: '.$db->lasterror());
		$this->assertSame(1, (int) $db->num_rows($resql), 'Exactly one line must be captured');

		$line = $db->fetch_object($resql);
		$this->assertSame((int) $product->id, (int) $line->fk_product, 'The line must carry the transferred product');
		$this->assertEquals(3.0, (float) $line->qty, 'The line must carry the transferred quantity');
		$this->assertGreaterThan(0, (int) $line->fk_mouvement_source, 'The outbound movement must be recorded');
		$this->assertGreaterThan(0, (int) $line->fk_mouvement_destination, 'The inbound movement must be recorded');

		// Both movements must point back at the transfer so the movement list can link to it.
		$sql = "SELECT COUNT(*) as nb FROM ".MAIN_DB_PREFIX."stock_mouvement";
		$sql .= " WHERE rowid IN (".((int) $line->fk_mouvement_source).", ".((int) $line->fk_mouvement_destination).")";
		$sql .= " AND origintype = '".$db->escape(NopCommerceTransfer::ORIGIN_TYPE)."'";
		$sql .= " AND fk_origin = ".((int) $obj->rowid);
		$resql = $db->query($sql);
		$this->assertNotFalse($resql, 'Query failed: '.$db->lasterror());
		$this->assertSame(2, (int) $db->fetch_object($resql)->nb, 'Both movements must be tagged with the transfer');

		$this->assertFalse(NopCommerceCapture::isExpected(), 'The intent must be forgotten after a capture');
	}

	/**
	 * A transfer between warehouses that are not the configured pair is left alone.
	 *
	 * @return void
	 */
	public function testNonMatchingWarehousePairIsNotCaptured()
	{
		global $conf, $db, $user;
		$db = $this->savdb;

		$source = $this->makeWarehouse('nm-src');
		$dest = $this->makeWarehouse('nm-dst');
		$other = $this->makeWarehouse('nm-oth');
		$product = $this->makeProduct('nm');

		$conf->global->NOPCOMMERCE_SOURCE_WAREHOUSE_ID = $source;
		$conf->global->NOPCOMMERCE_WEBSHOP_WAREHOUSE_ID = $dest;

		$this->assertGreaterThan(0, $product->correct_stock($user, $source, 10, 0, 'phpunit seed', 0, 'PHPUNITSEED'), 'Failed to seed stock');

		// No intent: this is what a transfer to an unrelated warehouse looks like.
		NopCommerceCapture::forget();

		$this->assertGreaterThan(0, $product->correct_stock($user, $source, 2, 1, 'phpunit other', 0, 'PHPUNITOTHER'), 'Outbound leg failed');
		$this->assertGreaterThan(0, $product->correct_stock($user, $other, 2, 0, 'phpunit other', 0, 'PHPUNITOTHER'), 'Inbound leg failed');

		$sql = "SELECT COUNT(*) as nb FROM ".MAIN_DB_PREFIX."nopcommerce_transfer";
		$sql .= " WHERE fk_warehouse_destination IN (".((int) $dest).", ".((int) $other).")";
		$resql = $db->query($sql);
		$this->assertNotFalse($resql, 'Query failed: '.$db->lasterror());
		$this->assertSame(0, (int) $db->fetch_object($resql)->nb, 'Nothing must be captured without a matching pair');
	}

	/**
	 * When the stock leaves a warehouse other than the configured source, nothing is
	 * captured even though an intent was parked, and the stock still moves.
	 *
	 * @return void
	 */
	public function testOutboundLegFromAnotherWarehouseIsNotCaptured()
	{
		global $conf, $db, $user;
		$db = $this->savdb;

		$source = $this->makeWarehouse('fs-src');
		$dest = $this->makeWarehouse('fs-dst');
		$foreign = $this->makeWarehouse('fs-for');
		$product = $this->makeProduct('fs');

		$conf->global->NOPCOMMERCE_SOURCE_WAREHOUSE_ID = $source;
		$conf->global->NOPCOMMERCE_WEBSHOP_WAREHOUSE_ID = $dest;

		$this->assertGreaterThan(0, $product->correct_stock($user, $foreign, 10, 0, 'phpunit seed', 0, 'PHPUNITSEED'), 'Failed to seed stock');

		NopCommerceCapture::forget();
		NopCommerceCapture::expect($product->id, $source, $dest);

		// The lot-specific form's hole: the gate passed, but the stock leaves elsewhere.
		$this->assertGreaterThan(0, $product->correct_stock($user, $foreign, 4, 1, 'phpunit foreign', 0, 'PHPUNITFOR'), 'Outbound leg failed');
		$this->assertGreaterThan(0, $product->correct_stock($user, $dest, 4, 0, 'phpunit foreign', 0, 'PHPUNITFOR'), 'Inbound leg failed');

		$sql = "SELECT COUNT(*) as nb FROM ".MAIN_DB_PREFIX."nopcommerce_transfer";
		$sql .= " WHERE fk_warehouse_destination = ".((int) $dest);
		$resql = $db->query($sql);
		$this->assertNotFalse($resql, 'Query failed: '.$db->lasterror());
		$this->assertSame(0, (int) $db->fetch_object($resql)->nb, 'A foreign source must not be captured');
	}

	/**
	 * A capture failure is reported as a negative result so the caller rolls back.
	 *
	 * This is the module's whole responsibility for atomicity. The actual rollback is
	 * product.php's outermost transaction and cannot be observed here, because Dolibarr's
	 * nested transactions are a counter rather than savepoints and this harness owns the
	 * outermost transaction. Verify the rollback by hand on the native page.
	 *
	 * @return void
	 */
	public function testCaptureFailurePropagates()
	{
		global $db, $user;
		$db = $this->savdb;

		$dest = $this->makeWarehouse('cf-dst');

		NopCommerceCapture::forget();
		// An intent whose source equals the destination: create() refuses such a transfer.
		NopCommerceCapture::expect(1, $dest, $dest);

		$movement = new MouvementStock($db);
		$movement->id = 777001;
		$movement->entrepot_id = $dest;
		$this->assertGreaterThan(0, NopCommerceCapture::recordSourceLeg($movement), 'Source leg should be accepted');

		$movement->product_id = 1;
		$movement->qty = 1;
		$movement->batch = '';
		$movement->label = 'phpunit failure';

		$this->assertLessThan(0, NopCommerceCapture::capture($db, $user, $movement), 'capture() must report failure so the caller rolls back');
		$this->assertNotSame('', NopCommerceCapture::$error, 'capture() must explain why it failed');

		NopCommerceCapture::forget();
	}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `cd /var/www/html/dolibarr-store && ./vendor/bin/phpunit --no-configuration htdocs/custom/nopcommerce/test/NopCommerceCaptureTest.php`

Expected: `testNativeTransferIsCaptured` FAILS asserting one transfer but finding zero;
`testCaptureFailurePropagates` FAILS with `Call to undefined method ...::capture()`.
`testNonMatchingWarehousePairIsNotCaptured` and
`testOutboundLegFromAnotherWarehouseIsNotCaptured` may already pass — that is expected,
they are the guards, and they must keep passing after the implementation lands.

- [ ] **Step 3: Add capture() and the movement tagging**

Append these two methods inside `class NopCommerceCapture` in
`class/nopcommercecapture.class.php`:

```php
	/**
	 * Record the transferred product as a pending sync transfer.
	 *
	 * Reuses the ordinary create/addLine/validate path so captured and manual transfers
	 * are built by the same code. The ref is derived from the destination movement id
	 * rather than the sequential counter: the counter reads the highest existing ref and
	 * increments it, so two simultaneous transfers compute the same ref and the loser's
	 * stock transfer would fail for a reason unrelated to anything that user did.
	 *
	 * @param	DoliDB			$db		Database handler
	 * @param	User			$user	User performing the stock transfer
	 * @param	MouvementStock	$m		The inbound movement, into the webshop warehouse
	 * @return	int<-1,max>				Id of the captured transfer, or <0 on failure
	 */
	public static function capture(DoliDB $db, User $user, MouvementStock $m)
	{
		if (self::$intent === null) {
			self::$error = 'No capture was expected';
			return -1;
		}
		if (empty(self::$sourcemovementid)) {
			self::$error = 'The outbound stock movement was never seen';
			return -1;
		}

		$transfer = new NopCommerceTransfer($db);
		$transfer->label = (string) $m->label;
		$transfer->fk_warehouse_source = self::expectedSource();
		$transfer->fk_warehouse_destination = (int) $m->entrepot_id;

		if ($transfer->create($user) <= 0) {
			self::$error = 'Failed to create the transfer: '.$transfer->error;
			return -1;
		}

		$lineid = $transfer->addLine($user, (int) $m->product_id, abs((float) $m->qty), (string) $m->batch);
		if ($lineid <= 0) {
			self::$error = 'Failed to add the product: '.$transfer->error;
			return -1;
		}

		// Record which stock movements this line is the bookkeeping for. applyAckSuccess
		// leaves these alone for a captured transfer instead of creating its own.
		$line = new NopCommerceTransferLine($db);
		if ($line->fetch($lineid) <= 0) {
			self::$error = 'Failed to reload the line: '.$line->error;
			return -1;
		}
		$line->fk_mouvement_source = self::sourceMovementId();
		$line->fk_mouvement_destination = (int) $m->id;
		if ($line->update($user) < 0) {
			self::$error = 'Failed to record the stock movements on the line: '.$line->error;
			return -1;
		}

		$transfer->origin = NopCommerceTransfer::ORIGIN_NATIVE;
		if ($transfer->update($user) < 0) {
			self::$error = 'Failed to mark the transfer as captured: '.$transfer->error;
			return -1;
		}

		if ($transfer->validate($user, 0, 'NOP-M'.((int) $m->id)) <= 0) {
			self::$error = 'Failed to validate the transfer: '.$transfer->error;
			return -1;
		}

		if (self::tagMovements($db, (int) $transfer->id, self::sourceMovementId(), (int) $m->id) < 0) {
			return -1;
		}

		return (int) $transfer->id;
	}

	/**
	 * Point both stock movements back at the transfer they belong to, so the stock
	 * movement list can resolve and link them.
	 *
	 * Only rows with no provenance are touched, so an existing one is never overwritten.
	 * The test has to accept 0 as well as NULL because MouvementStock::_create() writes
	 * 0 and '' rather than nulls when no origin was given.
	 *
	 * @param	DoliDB	$db					Database handler
	 * @param	int		$transferid			Transfer the movements belong to
	 * @param	int		$sourcemovementid	Outbound movement row id
	 * @param	int		$destmovementid		Inbound movement row id
	 * @return	int<-1,1>					<0 if KO, >0 if OK
	 */
	protected static function tagMovements(DoliDB $db, $transferid, $sourcemovementid, $destmovementid)
	{
		$sql = "UPDATE ".$db->prefix()."stock_mouvement";
		$sql .= " SET fk_origin = ".((int) $transferid).",";
		$sql .= " origintype = '".$db->escape(NopCommerceTransfer::ORIGIN_TYPE)."'";
		$sql .= " WHERE rowid IN (".((int) $sourcemovementid).", ".((int) $destmovementid).")";
		$sql .= " AND (fk_origin IS NULL OR fk_origin = 0)";

		if (!$db->query($sql)) {
			self::$error = 'Failed to tag the stock movements: '.$db->lasterror();
			return -1;
		}

		return 1;
	}
```

- [ ] **Step 4: Create the trigger**

Create `core/triggers/interface_95_modNopCommerce_NopCommerceCapture.class.php`:

```php
<?php
/* Copyright (C) 2026 Demir Agovic
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    htdocs/custom/nopcommerce/core/triggers/interface_95_modNopCommerce_NopCommerceCapture.class.php
 * \ingroup nopcommerce
 * \brief   Captures a native stock transfer once its movements exist.
 */

require_once DOL_DOCUMENT_ROOT.'/core/triggers/dolibarrtriggers.class.php';
dol_include_once('/nopcommerce/class/nopcommercecapture.class.php');


/**
 * Trigger that turns a native stock transfer into a nopCommerce sync transfer.
 *
 * The class name is not free: the loader derives it from the file name as
 * "Interface".ucfirst($third_segment), and silently skips the file when it does not
 * match. See interfaces.class.php.
 */
class InterfaceNopCommerceCapture extends DolibarrTriggers
{
	/**
	 * Constructor
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;

		$this->name = preg_replace('/^Interface/i', '', get_class($this));
		$this->family = "interface";
		$this->description = "Captures a native stock transfer into a nopCommerce sync transfer.";
		$this->version = self::VERSIONS['prod'];
		$this->picto = 'stock';
	}

	/**
	 * Run the trigger.
	 *
	 * Only acts when the page hook parked an intent, so ordinary stock movements and the
	 * movements applyAckSuccess() makes for manual transfers both fall straight through.
	 *
	 * @param	string		$action		Event code
	 * @param	CommonObject	$object	Object the event is about
	 * @param	User		$user		User that did the action
	 * @param	Translate	$langs		Translation handler
	 * @param	Conf		$conf		Configuration
	 * @return	int<-1,1>				0 if no action, >0 if OK, <0 if KO
	 */
	public function runTrigger($action, $object, User $user, Translate $langs, Conf $conf)
	{
		if ($action != 'STOCK_MOVEMENT') {
			return 0;
		}
		if (!NopCommerceCapture::isExpected()) {
			return 0;
		}

		// A kit's components each generate their own movement into the same warehouse,
		// because _createSubProduct() runs before this trigger fires. Only the product the
		// user actually submitted is captured.
		if ((int) $object->product_id !== NopCommerceCapture::expectedProduct()) {
			return 0;
		}

		// type 1 = stock leaving by a transfer, type 0 = stock arriving by a transfer.
		if ((int) $object->type === 1) {
			if (NopCommerceCapture::recordSourceLeg($object) < 0) {
				// The stock did not leave the configured source warehouse, so this is not
				// a capture case after all. Leave the transfer alone.
				NopCommerceCapture::forget();
			}
			return 0;
		}
		if ((int) $object->type !== 0) {
			return 0;
		}
		if ((int) $object->entrepot_id !== NopCommerceCapture::expectedDestination()) {
			return 0;
		}

		$result = NopCommerceCapture::capture($this->db, $user, $object);
		NopCommerceCapture::forget();

		if ($result < 0) {
			$langs->load('nopcommerce@nopcommerce');
			$this->errors[] = $langs->trans('NopCommerceCaptureFailed', NopCommerceCapture::$error);
			return -1;
		}

		return 1;
	}
}
```

- [ ] **Step 5: Register the trigger in the module descriptor**

In `core/modules/modNopCommerce.class.php`, change the `triggers` entry of
`$this->module_parts` from `0` to `1`:

```php
			'triggers'          => 1,
```

- [ ] **Step 6: Add the error message used by the trigger**

In `langs/en_US/nopcommerce.lang`, add under the `# Errors` section:

```
NopCommerceCaptureFailed = The product could not be queued for nopCommerce, so the stock transfer was not made: %s
```

- [ ] **Step 7: Re-enable the module so the trigger is picked up**

Disable then re-enable the nopCommerce module in Dolibarr's admin UI. The trigger
directory is only added to the search path from `module_parts`, which is read at module
enable time — without this the trigger never runs and the test fails with zero captured
transfers.

- [ ] **Step 8: Run the tests to verify they pass**

Run: `cd /var/www/html/dolibarr-store && ./vendor/bin/phpunit --no-configuration htdocs/custom/nopcommerce/test/NopCommerceCaptureTest.php`

Expected: PASS, 11 tests. If `testNativeTransferIsCaptured` still finds zero transfers,
the trigger is not being loaded: check the file name matches
`interface_95_modNopCommerce_NopCommerceCapture.class.php` exactly and the class is named
`InterfaceNopCommerceCapture`, then re-enable the module again.

- [ ] **Step 9: Lint and commit**

```bash
php -l htdocs/custom/nopcommerce/class/nopcommercecapture.class.php && php -l htdocs/custom/nopcommerce/core/triggers/interface_95_modNopCommerce_NopCommerceCapture.class.php && php -l htdocs/custom/nopcommerce/core/modules/modNopCommerce.class.php
```

```bash
git add htdocs/custom/nopcommerce/class/nopcommercecapture.class.php htdocs/custom/nopcommerce/core/triggers htdocs/custom/nopcommerce/core/modules/modNopCommerce.class.php htdocs/custom/nopcommerce/langs/en_US/nopcommerce.lang htdocs/custom/nopcommerce/test/NopCommerceCaptureTest.php
git commit -m "feat(nopcommerce): capture native stock transfers via the STOCK_MOVEMENT trigger"
```

---

### Task 5: The page hook

**Files:**
- Create: `htdocs/custom/nopcommerce/class/actions_nopcommerce.class.php`
- Modify: `htdocs/custom/nopcommerce/core/modules/modNopCommerce.class.php`

**Interfaces:**
- Consumes: `NopCommerceCapture::expect()` (Task 3).
- Produces: `class ActionsNopCommerce` with `doActions($parameters, &$object, &$action, $hookmanager): int`.

There is no automated test here. The hook's only job is to recognise a page submit, which
would need a fabricated request to drive, and the rule it checks is re-enforced by the
trigger (Task 3's `recordSourceLeg`) where it is already tested. Acceptance is the manual
end-to-end step.

- [ ] **Step 1: Create the hook class**

Create `class/actions_nopcommerce.class.php`:

```php
<?php
/* Copyright (C) 2026 Demir Agovic
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    htdocs/custom/nopcommerce/class/actions_nopcommerce.class.php
 * \ingroup nopcommerce
 * \brief   Hooks of the nopCommerce integration module.
 */

dol_include_once('/nopcommerce/class/nopcommercecapture.class.php');


/**
 * Hook class of the nopCommerce integration.
 */
class ActionsNopCommerce
{
	/**
	 * @var DoliDB Database handler
	 */
	public $db;

	/**
	 * @var string Error code or message
	 */
	public $error = '';

	/**
	 * @var string[] Errors
	 */
	public $errors = array();

	/**
	 * @var array<string,mixed> Hook results, propagated to $hookmanager->resArray
	 */
	public $results = array();

	/**
	 * @var ?string String printed by executeHooks() immediately after return
	 */
	public $resprints;

	/**
	 * Constructor
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Note that the native stock transfer page is about to move stock between the two
	 * warehouses this module is configured for, so the trigger can record the product
	 * once the stock movements exist.
	 *
	 * This only parks an intent. It never changes $action and never takes over the native
	 * flow, and it performs no writes: by the time this runs, product.php has not moved
	 * any stock yet and there are no movement rows to reference.
	 *
	 * No nopCommerce permission is required. Queueing the product is bookkeeping done on
	 * the user's behalf, like the stock movement row itself; demanding a permission here
	 * would stop a warehouse clerk who lacks it from transferring into the webshop
	 * warehouse at all.
	 *
	 * @param	array<string,mixed>	$parameters		Hook metadata
	 * @param	CommonObject		$object			Current object
	 * @param	string				$action			Current action
	 * @param	HookManager			$hookmanager	Hook manager
	 * @return	int									0 = OK, the native flow continues
	 */
	public function doActions($parameters, &$object, &$action, $hookmanager)
	{
		// HookManager runs a module's hook method only once, for the first context it
		// matches, so $parameters['currentcontext'] is not reliable. Qualify on the page's
		// full context list instead.
		if (!in_array('stockproductcard', (array) $hookmanager->contextarray)) {
			return 0;
		}
		if ($action != 'transfert_stock') {
			return 0;
		}
		if (GETPOST('cancel', 'alpha')) {
			return 0;
		}
		if (!isModEnabled('nopcommerce')) {
			return 0;
		}

		$source = getDolGlobalInt('NOPCOMMERCE_SOURCE_WAREHOUSE_ID');
		$destination = getDolGlobalInt('NOPCOMMERCE_WEBSHOP_WAREHOUSE_ID');

		// Unconfigured warehouses mean the feature is simply off, not an error.
		if ($source <= 0 || $destination <= 0) {
			return 0;
		}
		if (GETPOSTINT('id_entrepot') != $source) {
			return 0;
		}
		if (GETPOSTINT('id_entrepot_destination') != $destination) {
			return 0;
		}

		// An early exit only. The lot-specific form ignores the posted id_entrepot and
		// uses the lot's warehouse, so the source is re-checked against the real
		// outbound movement in NopCommerceCapture::recordSourceLeg().
		NopCommerceCapture::expect(GETPOSTINT('id'), $source, $destination);

		return 0;
	}
}
```

- [ ] **Step 2: Register the hook context**

In `core/modules/modNopCommerce.class.php`, change the `hooks` entry of
`$this->module_parts`:

```php
			'hooks'             => array('stockproductcard'),
```

- [ ] **Step 3: Lint both files**

```bash
php -l htdocs/custom/nopcommerce/class/actions_nopcommerce.class.php && php -l htdocs/custom/nopcommerce/core/modules/modNopCommerce.class.php
```

Expected: no syntax errors in either.

- [ ] **Step 4: Re-enable the module and verify end to end**

Disable then re-enable the nopCommerce module. Then, in Dolibarr:

1. Home → Setup → Modules → nopCommerce integration → setup. Set the source warehouse and
   the webshop warehouse to two real warehouses, and note them.
2. Open a product with stock in the source warehouse, go to its Stock tab, click
   *Transfer stock*.
3. Pick exactly those two warehouses, enter a quantity of 1, save.

Expected: the stock moves (source down 1, destination up 1) **and** Products →
nopCommerce transfers now lists a row for that product. Then repeat with the destination
set to a third, unrelated warehouse and confirm **no** new row appears.

- [ ] **Step 5: Commit**

```bash
git add htdocs/custom/nopcommerce/class/actions_nopcommerce.class.php htdocs/custom/nopcommerce/core/modules/modNopCommerce.class.php
git commit -m "feat(nopcommerce): recognise the native transfer submit via a page hook"
```

---

### Task 6: The acknowledgement must not move stock twice

**Files:**
- Modify: `htdocs/custom/nopcommerce/class/nopcommercesync.class.php`
- Modify: `htdocs/custom/nopcommerce/class/api_nopcommerce.class.php`
- Test: `htdocs/custom/nopcommerce/test/NopCommerceCaptureTest.php`

**Interfaces:**
- Consumes: `stockAlreadyMoved()` and `ORIGIN_TYPE` (Task 1), `ORIGIN_NATIVE` (Task 1).
- Produces: no new signatures; `applyAckSuccess(User, NopCommerceTransfer, array): int` keeps its signature and changes behaviour.

- [ ] **Step 1: Write the failing test**

Add to `NopCommerceCaptureTest`:

```php
	/**
	 * Acknowledging a captured transfer records the sync without moving stock again.
	 *
	 * @return void
	 */
	public function testAckOfACapturedTransferMovesNoStock()
	{
		global $conf, $db, $user;
		$db = $this->savdb;

		require_once dirname(__FILE__).'/../class/nopcommercesync.class.php';

		$source = $this->makeWarehouse('ack-src');
		$dest = $this->makeWarehouse('ack-dst');
		$product = $this->makeProduct('ack');

		$conf->global->NOPCOMMERCE_SOURCE_WAREHOUSE_ID = $source;
		$conf->global->NOPCOMMERCE_WEBSHOP_WAREHOUSE_ID = $dest;

		$this->assertGreaterThan(0, $product->correct_stock($user, $source, 10, 0, 'phpunit seed', 0, 'PHPUNITSEED'), 'Failed to seed stock');

		NopCommerceCapture::forget();
		NopCommerceCapture::expect($product->id, $source, $dest);
		$this->assertGreaterThan(0, $product->correct_stock($user, $source, 4, 1, 'phpunit transfer', 0, 'PHPUNITACK'), 'Outbound leg failed');
		$this->assertGreaterThan(0, $product->correct_stock($user, $dest, 4, 0, 'phpunit transfer', 0, 'PHPUNITACK'), 'Inbound leg failed');

		$sync = new NopCommerceSync($db);
		$stocksourcebefore = $sync->getStockInWarehouse($product->id, $source);
		$stockdestbefore = $sync->getStockInWarehouse($product->id, $dest);
		$this->assertEquals(6.0, $stocksourcebefore, 'The native transfer should have left 6 in the source');
		$this->assertEquals(4.0, $stockdestbefore, 'The native transfer should have put 4 in the destination');

		$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."nopcommerce_transfer WHERE fk_warehouse_destination = ".((int) $dest);
		$resql = $db->query($sql);
		$this->assertNotFalse($resql, 'Query failed: '.$db->lasterror());
		$transferid = (int) $db->fetch_object($resql)->rowid;

		$transfer = new NopCommerceTransfer($db);
		$this->assertGreaterThan(0, $transfer->fetch($transferid), 'Failed to reload the captured transfer');
		$this->assertGreaterThan(0, $transfer->fetchLines(), 'Failed to reload its lines');

		$this->assertGreaterThan(0, $sync->applyAckSuccess($user, $transfer, array()), 'applyAckSuccess failed: '.$sync->error);

		$this->assertEquals($stocksourcebefore, $sync->getStockInWarehouse($product->id, $source), 'The source stock must not move again');
		$this->assertEquals($stockdestbefore, $sync->getStockInWarehouse($product->id, $dest), 'The destination stock must not move again');
		$this->assertSame(NopCommerceTransfer::STATUS_SYNCED, (int) $transfer->status, 'The transfer must be marked synced');
		$this->assertSame(1, (int) $transfer->sync_flag, 'The sync flag must be raised');
	}

	/**
	 * A transfer built by hand still moves its stock when acknowledged. This is what
	 * protects rows created before capture existed.
	 *
	 * @return void
	 */
	public function testAckOfAManualTransferStillMovesStock()
	{
		global $db, $user;
		$db = $this->savdb;

		require_once dirname(__FILE__).'/../class/nopcommercesync.class.php';

		$source = $this->makeWarehouse('man-src');
		$dest = $this->makeWarehouse('man-dst');
		$product = $this->makeProduct('man');

		$this->assertGreaterThan(0, $product->correct_stock($user, $source, 10, 0, 'phpunit seed', 0, 'PHPUNITSEED'), 'Failed to seed stock');

		NopCommerceCapture::forget();

		$transfer = new NopCommerceTransfer($db);
		$transfer->fk_warehouse_source = $source;
		$transfer->fk_warehouse_destination = $dest;
		$this->assertGreaterThan(0, $transfer->create($user), 'create failed: '.$transfer->error);
		$this->assertGreaterThan(0, $transfer->addLine($user, $product->id, 3.0, ''), 'addLine failed: '.$transfer->error);
		$this->assertGreaterThan(0, $transfer->validate($user, 0, 'NOP-M999002'), 'validate failed: '.$transfer->error);
		$this->assertGreaterThan(0, $transfer->fetchLines(), 'Failed to reload the lines');

		$sync = new NopCommerceSync($db);
		$this->assertGreaterThan(0, $sync->applyAckSuccess($user, $transfer, array()), 'applyAckSuccess failed: '.$sync->error);

		$this->assertEquals(7.0, $sync->getStockInWarehouse($product->id, $source), 'A manual transfer must still decrement the source');
		$this->assertEquals(3.0, $sync->getStockInWarehouse($product->id, $dest), 'A manual transfer must still increment the destination');
	}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `cd /var/www/html/dolibarr-store && ./vendor/bin/phpunit --no-configuration htdocs/custom/nopcommerce/test/NopCommerceCaptureTest.php`

Expected: `testAckOfACapturedTransferMovesNoStock` FAILS — the source ends at 2 and the
destination at 8, because the acknowledgement moved the stock a second time.
`testAckOfAManualTransferStillMovesStock` should already pass and must keep passing.

- [ ] **Step 3: Skip the movement legs for a captured transfer**

In `class/nopcommercesync.class.php`, in `applyAckSuccess()`, replace the beginning of the
per-line loop. Change:

```php
		foreach ($transfer->lines as $line) {
			if (!$allownegative) {
```

into:

```php
		// A captured transfer moved its stock on the native stock transfer page before
		// nopCommerce ever saw it. Moving it again here would double-count it, and the
		// available-stock check below would reject the acknowledgement outright, because
		// the source warehouse has already been decremented.
		$stockalreadymoved = $transfer->stockAlreadyMoved();

		foreach ($transfer->lines as $line) {
			if (!$stockalreadymoved && !$allownegative) {
```

Then wrap the two movement blocks. Change:

```php
			$movementout = new MouvementStock($this->db);
```

into:

```php
			if (!$stockalreadymoved) {
				$movementout = new MouvementStock($this->db);
```

and indent the whole body through the end of the `$line->fk_mouvement_destination = $movementin->id;`
line by one extra tab, then close the block. The result, from `$movementout` to the close,
must read:

```php
			if (!$stockalreadymoved) {
				$movementout = new MouvementStock($this->db);
				$movementout->origin_type = NopCommerceTransfer::ORIGIN_TYPE;
				$movementout->origin_id = $transfer->id;
				$resultout = $movementout->livraison($user, $line->fk_product, $transfer->fk_warehouse_source, $line->qty, 0, $label, '', '', '', (string) $line->batch, 0, $inventorycode);
				if ($resultout < 0) {
					$this->error = $movementout->error;
					$this->errors = array_merge($this->errors, $movementout->errors);
					$this->db->rollback();
					return -1;
				}

				$movementin = new MouvementStock($this->db);
				$movementin->origin_type = NopCommerceTransfer::ORIGIN_TYPE;
				$movementin->origin_id = $transfer->id;
				$resultin = $movementin->reception($user, $line->fk_product, $transfer->fk_warehouse_destination, $line->qty, 0, $label, '', '', (string) $line->batch, '', 0, $inventorycode);
				if ($resultin < 0) {
					$this->error = $movementin->error;
					$this->errors = array_merge($this->errors, $movementin->errors);
					$this->db->rollback();
					return -1;
				}

				$line->fk_mouvement_source = $movementout->id;
				$line->fk_mouvement_destination = $movementin->id;
			}
```

Note the two `origin_type` assignments changed from the bare `'nopcommercetransfer'` to
`NopCommerceTransfer::ORIGIN_TYPE`. The bare form was unresolvable: `get_origin()` splits
on `@` and, finding none, derives the module directory from the class name, so it looked
for a `nopcommercetransfer` directory that does not exist, the include failed silently,
and the Origin column of the stock movement list rendered blank for every synced transfer.

The lines that follow — `$line->sync_flag = 1;` onward — stay outside the new block, so a
captured transfer still gets its flags, its nopCommerce ids and its line update.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `cd /var/www/html/dolibarr-store && ./vendor/bin/phpunit --no-configuration htdocs/custom/nopcommerce/test/NopCommerceCaptureTest.php`

Expected: PASS, 13 tests.

- [ ] **Step 5: Stop the API claiming stock moved**

In `class/api_nopcommerce.class.php`, in `ack()`, replace the success return's message.
Change:

```php
			return array(
				'transfer_id' => (int) $transfer->id,
				'ref' => $transfer->ref,
				'status' => (int) $transfer->status,
				'sync_flag' => true,
				'already_synced' => false,
				'message' => 'Stock moved and sync flag set to true',
			);
```

into:

```php
			return array(
				'transfer_id' => (int) $transfer->id,
				'ref' => $transfer->ref,
				'status' => (int) $transfer->status,
				'sync_flag' => true,
				'already_synced' => false,
				'message' => $transfer->stockAlreadyMoved()
					? 'Sync flag set to true. No stock was moved: this transfer was captured from a stock transfer that already moved it'
					: 'Stock moved and sync flag set to true',
			);
```

- [ ] **Step 6: Lint and commit**

```bash
php -l htdocs/custom/nopcommerce/class/nopcommercesync.class.php && php -l htdocs/custom/nopcommerce/class/api_nopcommerce.class.php
```

```bash
git add htdocs/custom/nopcommerce/class/nopcommercesync.class.php htdocs/custom/nopcommerce/class/api_nopcommerce.class.php htdocs/custom/nopcommerce/test/NopCommerceCaptureTest.php
git commit -m "fix(nopcommerce): do not move stock again when acknowledging a captured transfer"
```

---

### Task 7: Tell the webshop where a transfer came from

**Files:**
- Modify: `htdocs/custom/nopcommerce/class/nopcommercesync.class.php`
- Modify: `htdocs/custom/nopcommerce/doc/api.md`
- Test: `htdocs/custom/nopcommerce/test/NopCommerceCaptureTest.php`

**Interfaces:**
- Consumes: `stockAlreadyMoved()`, `$origin` (Task 1).
- Produces: `buildTransferPayload()` gains the keys `origin` (string) and `stock_already_moved` (bool).

- [ ] **Step 1: Write the failing test**

Add to `NopCommerceCaptureTest`:

```php
	/**
	 * The pulled payload tells the webshop where the transfer came from and whether its
	 * stock has already moved.
	 *
	 * @return void
	 */
	public function testPayloadCarriesTheOrigin()
	{
		global $db, $user;
		$db = $this->savdb;

		require_once dirname(__FILE__).'/../class/nopcommercesync.class.php';

		$source = $this->makeWarehouse('pl-src');
		$dest = $this->makeWarehouse('pl-dst');
		$product = $this->makeProduct('pl');

		$transfer = new NopCommerceTransfer($db);
		$transfer->fk_warehouse_source = $source;
		$transfer->fk_warehouse_destination = $dest;
		$this->assertGreaterThan(0, $transfer->create($user), 'create failed: '.$transfer->error);
		$this->assertGreaterThan(0, $transfer->addLine($user, $product->id, 1.0, ''), 'addLine failed: '.$transfer->error);
		$this->assertGreaterThan(0, $transfer->fetchLines(), 'Failed to reload the lines');

		$sync = new NopCommerceSync($db);

		$payload = $sync->buildTransferPayload($transfer);
		$this->assertSame(NopCommerceTransfer::ORIGIN_MANUAL, $payload['origin'], 'A hand-built transfer reports the manual origin');
		$this->assertFalse($payload['stock_already_moved'], 'A hand-built transfer has not moved its stock');

		$transfer->origin = NopCommerceTransfer::ORIGIN_NATIVE;
		$payload = $sync->buildTransferPayload($transfer);
		$this->assertSame(NopCommerceTransfer::ORIGIN_NATIVE, $payload['origin'], 'A captured transfer reports the native origin');
		$this->assertTrue($payload['stock_already_moved'], 'A captured transfer has already moved its stock');
	}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd /var/www/html/dolibarr-store && ./vendor/bin/phpunit --no-configuration htdocs/custom/nopcommerce/test/NopCommerceCaptureTest.php`

Expected: FAIL — `Undefined array key "origin"`.

- [ ] **Step 3: Add the two payload fields**

In `class/nopcommercesync.class.php`, in `buildTransferPayload()`, add after the
`'status' => (int) $transfer->status,` line:

```php
			'origin' => (string) $transfer->origin,
			'stock_already_moved' => $transfer->stockAlreadyMoved(),
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `cd /var/www/html/dolibarr-store && ./vendor/bin/phpunit --no-configuration htdocs/custom/nopcommerce/test/NopCommerceCaptureTest.php`

Expected: PASS, 14 tests.

- [ ] **Step 5: Document the contract change**

In `doc/api.md`, add these rows to the transfer-level field table of the pull response:

```markdown
| `origin` | `manual` when the transfer was built by hand (only possible before the stock transfer capture existed), `native` when it was captured from a Dolibarr stock transfer |
| `stock_already_moved` | `true` when Dolibarr has already moved the stock for this transfer. Acknowledging it records the products and raises the flags **without moving stock again** |
```

In the "Model" section, after the `sync_flag` row, add:

```markdown
A transfer is created by moving stock into the webshop warehouse on Dolibarr's native
stock transfer page, when the source and destination warehouses match the two configured
in the module setup. The stock therefore moves **at transfer time**, not at
acknowledgement time, and such a transfer reports `stock_already_moved: true`.
Acknowledging it records your product ids and marks it synced, but moves no stock.

Refs are of the form `NOP-M<stock movement id>`. They are unique but not sequential, and
nothing should be inferred from their order — key on `transfer_id`.
```

And add a new section at the end:

```markdown
## Known limitation: the sync is one-directional

Only stock moving **into** the webshop warehouse is reported. Moving stock back out — a
return to the parent warehouse, a correction — tells this API nothing, so the shop will
still believe the earlier quantity is available and can oversell.

`stock_in_webshop_warehouse` in the pull payload is a snapshot taken at pull time, so a
warehouse with no new transfers never refreshes. If your shop needs authoritative stock
levels, do not rely on this API for them.
```

- [ ] **Step 6: Commit**

```bash
git add htdocs/custom/nopcommerce/class/nopcommercesync.class.php htdocs/custom/nopcommerce/doc/api.md htdocs/custom/nopcommerce/test/NopCommerceCaptureTest.php
git commit -m "feat(nopcommerce): report transfer origin and stock-already-moved in the pull payload"
```

---

### Task 8: The list page becomes a product list

**Files:**
- Modify: `htdocs/custom/nopcommerce/transfer_list.php` (full rewrite of the query, the table and the actions block)

**Interfaces:**
- Consumes: `NopCommerceTransfer::cancel()`, `delete()`, `getLibStatut()`, `getNomUrl()`; `NopCommerceSync::getVariantAttributes()`.
- Produces: nothing for later tasks.

No automated test: this is a Dolibarr list page, and the existing suite has no precedent
for driving page rendering. Acceptance is the manual checks in Step 5.

The steps are ordered so that no step introduces a reference to something a later step
defines. The page will not render correctly until Step 6, because this is a rewrite of the
query and the table together — do not stop half way and expect a working page.

- [ ] **Step 1: Add the requires, the title and the sync service**

In `transfer_list.php`, add these two requires next to the existing ones (after
`require_once './lib/nopcommerce.lib.php';`):

```php
require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
require_once './class/nopcommercesync.class.php';
```

Next to `$transfertmp = new NopCommerceTransfer($db);` add:

```php
$synctmp = new NopCommerceSync($db);
$producttmp = new Product($db);
```

and change the title line to:

```php
$title = $langs->trans("NopCommerceSyncProducts");
```

- [ ] **Step 2: Add the row actions**

In `transfer_list.php`, immediately after the access-control block (`accessforbidden()`
checks) and before the `/*\n * View\n */` comment, insert:

```php
$permissiontocancel = $user->hasRight('nopcommerce', 'write');
$permissiontodelete = $user->hasRight('nopcommerce', 'delete');


/*
 * Actions
 */

$transferid = GETPOSTINT('transferid');

if ($action == 'confirm_cancel' && GETPOST('confirm', 'alpha') == 'yes' && $permissiontocancel && $transferid > 0) {
	$transfer = new NopCommerceTransfer($db);
	if ($transfer->fetch($transferid) > 0) {
		if ($transfer->cancel($user) > 0) {
			setEventMessages($langs->trans('NopCommerceTransferCanceled'), null, 'mesgs');
		} else {
			setEventMessages($langs->trans($transfer->error), null, 'errors');
		}
	}
	header("Location: ".$_SERVER["PHP_SELF"]);
	exit;
}

if ($action == 'confirm_delete' && GETPOST('confirm', 'alpha') == 'yes' && $permissiontodelete && $transferid > 0) {
	$transfer = new NopCommerceTransfer($db);
	if ($transfer->fetch($transferid) > 0) {
		if ($transfer->delete($user) > 0) {
			setEventMessages($langs->trans('RecordDeleted'), null, 'mesgs');
		} else {
			setEventMessages($langs->trans($transfer->error), null, 'errors');
		}
	}
	header("Location: ".$_SERVER["PHP_SELF"]);
	exit;
}
```

- [ ] **Step 3: Add the confirmation dialogs**

In `transfer_list.php`, immediately after the `llxHeader(...)` call, add:

```php
if ($action == 'cancel' && $permissiontocancel && $transferid > 0) {
	$canceltmp = new NopCommerceTransfer($db);
	if ($canceltmp->fetch($transferid) > 0) {
		print $form->formconfirm($_SERVER["PHP_SELF"].'?transferid='.$transferid, $langs->trans('NopCommerceCancelTransfer'), $langs->trans('NopCommerceConfirmCancelTransfer', $canceltmp->ref), 'confirm_cancel', '', 0, 1);
	}
}
if ($action == 'delete' && $permissiontodelete && $transferid > 0) {
	$deletetmp = new NopCommerceTransfer($db);
	if ($deletetmp->fetch($transferid) > 0) {
		print $form->formconfirm($_SERVER["PHP_SELF"].'?transferid='.$transferid, $langs->trans('NopCommerceDeleteTransfer'), $langs->trans('NopCommerceConfirmDeleteTransfer', $deletetmp->ref), 'confirm_delete', '', 0, 1);
	}
}
```

`$transferid`, `$permissiontocancel` and `$permissiontodelete` all come from Step 2, which
runs earlier in the file than this.

- [ ] **Step 4: Add the two new search parameters**

In `transfer_list.php`, next to the existing `$search_ref` assignment add:

```php
$search_product = GETPOST('search_product', 'alpha');
$search_batch = GETPOST('search_batch', 'alphanohtml');
```

In the "Purge search criteria" block add:

```php
	$search_product = '';
	$search_batch = '';
```

In the `$param` building block add:

```php
if ($search_product) {
	$param .= '&search_product='.urlencode($search_product);
}
if ($search_batch) {
	$param .= '&search_batch='.urlencode($search_batch);
}
```

- [ ] **Step 5: Replace the query with one over lines**

In `transfer_list.php`, replace the whole `$sql = ...` block (from `$sql = "SELECT t.rowid`
down to and including the `$sql .= " GROUP BY ..."` lines) with:

```php
$sql = "SELECT l.rowid as lineid, l.fk_product, l.fk_product_parent, l.qty, l.batch,";
$sql .= " l.sync_flag as line_sync_flag, l.sync_error, l.nop_product_id,";
$sql .= " t.rowid, t.ref, t.label, t.status, t.origin, t.sync_last_error,";
$sql .= " t.date_creation, t.date_pulled, t.date_synced,";
$sql .= " t.fk_warehouse_source, t.fk_warehouse_destination,";
$sql .= " p.ref as product_ref, p.label as product_label, p.fk_product_type,";
$sql .= " es.ref as source_ref, ed.ref as dest_ref";
$sql .= " FROM ".MAIN_DB_PREFIX."nopcommerce_transferline as l";
$sql .= " INNER JOIN ".MAIN_DB_PREFIX."nopcommerce_transfer as t ON t.rowid = l.fk_nopcommercetransfer";
$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."product as p ON p.rowid = l.fk_product";
$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."entrepot as es ON es.rowid = t.fk_warehouse_source";
$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."entrepot as ed ON ed.rowid = t.fk_warehouse_destination";
$sql .= " WHERE t.entity IN (".getEntity('nopcommercetransfer').")";
if ($search_ref) {
	$sql .= natural_search('t.ref', $search_ref);
}
if ($search_product) {
	$sql .= natural_search(array('p.ref', 'p.label'), $search_product);
}
if ($search_batch) {
	$sql .= natural_search('l.batch', $search_batch);
}
if ($search_status !== '' && $search_status != '-1') {
	$sql .= " AND t.status = ".((int) $search_status);
}
if ($search_sync_flag !== '' && $search_sync_flag != '-1') {
	$sql .= " AND l.sync_flag = ".((int) $search_sync_flag);
}
if ($search_warehouse > 0) {
	$sql .= " AND t.fk_warehouse_destination = ".((int) $search_warehouse);
}
```

Then change the default sort, replacing `$sortfield = 't.rowid';` with:

```php
	$sortfield = 'l.rowid';
```

- [ ] **Step 6: Replace the table with product columns**

In `transfer_list.php`, replace everything from `print '<tr class="liste_titre_filter">';`
down to and including the `if ($num == 0) { ... }` block with:

```php
$showbatch = isModEnabled('productbatch');
$nbcols = $showbatch ? 11 : 10;

// Filter row
print '<tr class="liste_titre_filter">';
print '<td class="liste_titre"><input type="text" class="flat maxwidth100" name="search_product" value="'.dol_escape_htmltag($search_product).'"></td>';
print '<td class="liste_titre"></td>';
if ($showbatch) {
	print '<td class="liste_titre"><input type="text" class="flat maxwidth75" name="search_batch" value="'.dol_escape_htmltag($search_batch).'"></td>';
}
print '<td class="liste_titre right"></td>';
print '<td class="liste_titre"></td>';
print '<td class="liste_titre">'.$formproduct->selectWarehouses($search_warehouse, 'search_warehouse', '', 1, 0, 0, '', 0, 0, array(), 'maxwidth150').'</td>';
print '<td class="liste_titre"><input type="text" class="flat maxwidth75" name="search_ref" value="'.dol_escape_htmltag($search_ref).'"></td>';
print '<td class="liste_titre center"></td>';
print '<td class="liste_titre center">'.$form->selectarray('search_status', array('' => '', '0' => $langs->trans('Draft'), '1' => $langs->trans('NopCommerceStatusPending'), '2' => $langs->trans('NopCommerceStatusSynced'), '3' => $langs->trans('NopCommerceStatusFailed'), '9' => $langs->trans('Canceled')), $search_status, 0, 0, 0, '', 0, 0, 0, '', 'maxwidth100').'</td>';
print '<td class="liste_titre center">'.$form->selectarray('search_sync_flag', array('' => '', '0' => $langs->trans('NopCommerceSyncFalse'), '1' => $langs->trans('NopCommerceSyncTrue')), $search_sync_flag, 0, 0, 0, '', 0, 0, 0, '', 'maxwidth75').'</td>';
print '<td class="liste_titre center maxwidthsearch">'.$form->showFilterButtons().'</td>';
print '</tr>';

// Title row
print '<tr class="liste_titre">';
print_liste_field_titre("Product", $_SERVER["PHP_SELF"], "p.ref", "", $param, "", $sortfield, $sortorder);
print_liste_field_titre("Attributes", $_SERVER["PHP_SELF"], "", "", $param, "", $sortfield, $sortorder);
if ($showbatch) {
	print_liste_field_titre("Batch", $_SERVER["PHP_SELF"], "l.batch", "", $param, "", $sortfield, $sortorder);
}
print_liste_field_titre("Qty", $_SERVER["PHP_SELF"], "l.qty", "", $param, "", $sortfield, $sortorder, 'right ');
print_liste_field_titre("NopCommerceSourceWarehouse", $_SERVER["PHP_SELF"], "es.ref", "", $param, "", $sortfield, $sortorder);
print_liste_field_titre("NopCommerceWebshopWarehouse", $_SERVER["PHP_SELF"], "ed.ref", "", $param, "", $sortfield, $sortorder);
print_liste_field_titre("Ref", $_SERVER["PHP_SELF"], "t.ref", "", $param, "", $sortfield, $sortorder);
print_liste_field_titre("DateCreation", $_SERVER["PHP_SELF"], "t.date_creation", "", $param, '', $sortfield, $sortorder, 'center ');
print_liste_field_titre("Status", $_SERVER["PHP_SELF"], "t.status", "", $param, '', $sortfield, $sortorder, 'center ');
print_liste_field_titre("NopCommerceSyncFlag", $_SERVER["PHP_SELF"], "l.sync_flag", "", $param, '', $sortfield, $sortorder, 'center ');
print_liste_field_titre('', $_SERVER["PHP_SELF"], "", '', '', '', $sortfield, $sortorder, 'center maxwidthsearch ');
print '</tr>';

$i = 0;

while ($i < min($num, $limit)) {
	$obj = $db->fetch_object($resql);
	if (!$obj) {
		break;
	}

	$transfertmp->id = $obj->rowid;
	$transfertmp->ref = $obj->ref;
	$transfertmp->status = $obj->status;

	$producttmp->id = $obj->fk_product;
	$producttmp->ref = $obj->product_ref;
	$producttmp->label = $obj->product_label;
	$producttmp->type = $obj->fk_product_type;

	$attributelabels = array();
	if (!empty($obj->fk_product_parent)) {
		foreach ($synctmp->getVariantAttributes($obj->fk_product) as $attribute) {
			$attributelabels[] = $attribute['attribute_label'].': '.$attribute['value_label'];
		}
	}

	print '<tr class="oddeven">';
	print '<td class="nowraponall">'.$producttmp->getNomUrl(1).'</td>';
	print '<td class="tdoverflowmax200">'.dol_escape_htmltag(implode(', ', $attributelabels)).'</td>';
	if ($showbatch) {
		print '<td class="tdoverflowmax100">'.dol_escape_htmltag((string) $obj->batch).'</td>';
	}
	print '<td class="right">'.price2num($obj->qty, 'MS').'</td>';
	print '<td class="tdoverflowmax150">'.dol_escape_htmltag((string) $obj->source_ref).'</td>';
	print '<td class="tdoverflowmax150">'.dol_escape_htmltag((string) $obj->dest_ref).'</td>';
	print '<td class="nowraponall">'.$transfertmp->getNomUrl(0).'</td>';
	print '<td class="center nowraponall">'.dol_print_date($db->jdate($obj->date_creation), 'dayhour').'</td>';
	print '<td class="center">'.$transfertmp->getLibStatut(5).'</td>';

	// Sync indicator, read only on purpose: marking a product synced by hand would claim
	// a sync that never happened.
	print '<td class="center">';
	print $obj->line_sync_flag ? img_picto($langs->trans('NopCommerceSyncTrue'), 'tick') : img_picto($langs->trans('NopCommerceSyncFalse'), 'off');
	$error = !empty($obj->sync_error) ? $obj->sync_error : $obj->sync_last_error;
	if (!empty($error)) {
		print ' '.$form->textwithpicto('', dol_escape_htmltag($error), 1, 'warning');
	}
	print '</td>';

	print '<td class="center nowraponall">';
	if ($permissiontocancel && in_array((int) $obj->status, array(NopCommerceTransfer::STATUS_PENDING, NopCommerceTransfer::STATUS_FAILED), true)) {
		print '<a class="reposition paddingright" href="'.$_SERVER["PHP_SELF"].'?action=cancel&transferid='.((int) $obj->rowid).'&token='.newToken().'" title="'.dol_escape_htmltag($langs->trans('NopCommerceCancelTransfer')).'">'.img_picto($langs->trans('NopCommerceCancelTransfer'), 'close_title').'</a>';
	}
	if ($permissiontodelete && (int) $obj->status != NopCommerceTransfer::STATUS_SYNCED) {
		print '<a class="reposition" href="'.$_SERVER["PHP_SELF"].'?action=delete&transferid='.((int) $obj->rowid).'&token='.newToken().'" title="'.dol_escape_htmltag($langs->trans('Delete')).'">'.img_delete().'</a>';
	}
	print '</td>';

	print '</tr>';

	$i++;
}

if ($num == 0) {
	print '<tr><td colspan="'.$nbcols.'"><span class="opacitymedium">'.$langs->trans("NopCommerceNoProductQueued").'</span></td></tr>';
}
```

- [ ] **Step 7: Lint, then verify in the browser**

```bash
php -l htdocs/custom/nopcommerce/transfer_list.php
```

Then open Products → nopCommerce transfers and check:

1. One row per captured transfer, showing product, quantity, both warehouses, and a sync
   indicator at the **end** of the row.
2. Filtering by product ref narrows the list; filtering by sync state shows only
   unsynced rows; the filter-clear button resets everything.
3. Sorting by product, quantity and date all work.
4. Transfer the same product twice and confirm **two** rows appear, each with its own
   quantity.
5. Cancel a row: a confirmation appears, then the row shows as Canceled and the cancel
   action is gone.
6. Delete a row: a confirmation appears, then the row is gone.
7. As a user with `nopcommerce` read but neither write nor delete, confirm neither action
   icon is shown.

- [ ] **Step 8: Commit**

```bash
git add htdocs/custom/nopcommerce/transfer_list.php
git commit -m "feat(nopcommerce): list queued products instead of transfers, with cancel and delete"
```

---

### Task 9: Language file

**Files:**
- Modify: `htdocs/custom/nopcommerce/langs/en_US/nopcommerce.lang`

**Interfaces:**
- Consumes: every key referenced by Tasks 4, 7 and 8.
- Produces: nothing for later tasks.

- [ ] **Step 1: Add the new keys**

In `langs/en_US/nopcommerce.lang`, under `# Objects` add:

```
NopCommerceSyncProducts = Products for nopCommerce sync
NopCommerceOrigin = Queued from
NopCommerceOriginManual = Built by hand
NopCommerceOriginNative = Stock transfer
```

Under `# Messages` add:

```
NopCommerceNoProductQueued = No product is queued for nopCommerce yet. Products are queued by transferring their stock into the webshop warehouse.
```

`NopCommerceCaptureFailed` was already added in Task 4, Step 6. Confirm it is present:

```bash
grep -n "NopCommerceCaptureFailed" htdocs/custom/nopcommerce/langs/en_US/nopcommerce.lang
```

Expected: one line. If absent, add it under `# Errors`:

```
NopCommerceCaptureFailed = The product could not be queued for nopCommerce, so the stock transfer was not made: %s
```

- [ ] **Step 2: Correct the descriptions the re-scope made false**

Three existing values now describe behaviour that no longer exists. Replace each line.

Replace the `NopCommerceDescriptionLong` line with:

```
NopCommerceDescriptionLong = Transferring a product's stock from the source warehouse into the webshop warehouse queues it for the webshop. nopCommerce polls this module for the products that are waiting, records them on its side, then reports the result. The stock moves when the transfer is made, so confirming a product does not move it again.
```

Replace the `NopCommerceSourceWarehouseId` and its tooltip with:

```
NopCommerceSourceWarehouseId = Source warehouse
NopCommerceSourceWarehouseIdTooltip = The warehouse stock is transferred from. A stock transfer is queued for nopCommerce only when it runs from this warehouse to the webshop warehouse below.
```

Replace the `NopCommerceWebshopWarehouseIdTooltip` line with:

```
NopCommerceWebshopWarehouseIdTooltip = The warehouse that holds the stock published on the nopCommerce webshop. A stock transfer into this warehouse from the source warehouse above queues the product for sync.
```

- [ ] **Step 3: Remove the keys left dead by the card page and setDraft**

Delete these six lines:

```bash
cd /var/www/html/dolibarr-store && sed -i '/^NopCommerceSetDraftTransfer = /d;/^NopCommerceConfirmSetDraftTransfer = /d;/^NopCommerceTransferSetToDraft = /d;/^NopCommerceAddProduct = /d;/^NopCommerceLineAdded = /d;/^CannotReopenASyncedTransfer = /d' htdocs/custom/nopcommerce/langs/en_US/nopcommerce.lang
```

- [ ] **Step 4: Verify nothing references a removed key**

```bash
for k in NopCommerceSetDraftTransfer NopCommerceConfirmSetDraftTransfer NopCommerceTransferSetToDraft NopCommerceAddProduct NopCommerceLineAdded CannotReopenASyncedTransfer; do grep -rn "$k" htdocs/custom/nopcommerce/ && echo "STILL REFERENCED: $k"; done; echo done
```

Expected: only `done`. Any `STILL REFERENCED` line means code still uses that key — put
the key back rather than leaving a broken message.

- [ ] **Step 5: Verify every key the code uses exists**

```bash
cd /var/www/html/dolibarr-store && for k in NopCommerceSyncProducts NopCommerceNoProductQueued NopCommerceCaptureFailed NopCommerceCancelTransfer NopCommerceConfirmCancelTransfer NopCommerceDeleteTransfer NopCommerceConfirmDeleteTransfer NopCommerceTransferCanceled; do grep -q "^$k = " htdocs/custom/nopcommerce/langs/en_US/nopcommerce.lang || echo "MISSING: $k"; done; echo done
```

Expected: only `done`.

- [ ] **Step 6: Commit**

```bash
git add htdocs/custom/nopcommerce/langs/en_US/nopcommerce.lang
git commit -m "feat(nopcommerce): update language keys for the capture flow"
```

---

### Task 10: Full-suite regression and manual acceptance

**Files:**
- No code changes. This task is the gate before the branch is considered done.

**Interfaces:**
- Consumes: everything.
- Produces: nothing.

- [ ] **Step 1: Run the module's tests**

Run: `cd /var/www/html/dolibarr-store && ./vendor/bin/phpunit --no-configuration htdocs/custom/nopcommerce/test/NopCommerceCaptureTest.php`

Expected: PASS, 14 tests, 0 failures, 0 errors.

- [ ] **Step 2: Lint every file the branch touched**

```bash
cd /var/www/html/dolibarr-store && for f in $(git diff --name-only main...HEAD -- '*.php'); do php -l "$f" || echo "SYNTAX ERROR: $f"; done; echo done
```

Expected: a "No syntax errors" line per file and no `SYNTAX ERROR` line.

- [ ] **Step 3: Confirm no core file was touched**

```bash
cd /var/www/html/dolibarr-store && git diff --name-only main...HEAD | grep -v '^htdocs/custom/nopcommerce/' | grep -v '^docs/' || echo "no core files touched"
```

Expected: `no core files touched`. Anything listed must be reverted or, if genuinely
needed, added to a core-changes registry — stop and ask first.

- [ ] **Step 4: Manual acceptance — M1 to M7**

Work through these in Dolibarr against the configured warehouse pair.

**M1 — variant.** Transfer a variant child product (a specific size) into the webshop
warehouse. Expect a row whose Attributes column shows the size, and whose payload carries
the parent and the attributes. Check the payload with:

```bash
curl -s -H "DOLAPIKEY: <your key>" "http://localhost/api/index.php/nopcommerce/transfers/pending?limit=5"
```

**M2 — batch, from the lot-specific screen.** For a lot-managed product, open the product's
Stock tab, find the lot row in the warehouse table and click its transfer link so the form
opens with the lot pre-selected. Transfer to the webshop warehouse. Expect the row's Batch
column to show the lot, and the **Source warehouse** column to show the **lot's**
warehouse. This is the case that would silently record the wrong warehouse if the source
were read from the submitted form.

**M3 — kit.** With `PRODUIT_SOUSPRODUITS` enabled, transfer a kit product. Expect exactly
**one** row, for the kit itself, not one per component.

**M4 — acknowledge end to end.** Note the stock in both warehouses. Pull a pending
transfer, then acknowledge it as a success, sending back the `pull_token` you received.
Expect the response message to say no stock was moved, the row's sync indicator to turn
on, and **the stock in both warehouses to be unchanged**.

**M5 — atomic rollback.** Temporarily break capture: in
`class/nopcommercecapture.class.php`, add `return -1;` as the first line of `capture()`.
Attempt a matching native transfer. Expect an error message on the page and **no stock
movement at all** in either warehouse. Then remove the `return -1;` and confirm a transfer
works again. This is the case the automated suite deliberately cannot cover.

**M6 — movement provenance.** Open Products → Stock → Movements. Find the two movements
from a captured transfer and confirm the Origin column shows a link to the transfer rather
than being blank. Then find a transfer that was synced **before** this branch and confirm
its movements' Origin column is now populated too.

**M7 — permissions.** As a user holding `stock → movement → create` but **no** nopCommerce
permission at all, perform a matching native transfer. Expect the stock to move and the
product to be queued. Then, as a user with nopCommerce read only, open the list and
confirm no cancel or delete icons appear.

- [ ] **Step 5: Commit any fixes, then report**

If any manual step failed, fix it and re-run Steps 1 to 3 before reporting done. Report
which of M1 to M7 passed, and paste the PHPUnit summary line.

---

## Deferred: two pre-existing bugs found while planning

Neither is in the spec. Both are one-line fixes in files this branch already touches.
**Do not fix them as part of this plan** — raise them with the user first, because widening
scope unasked is how a reviewable branch becomes an unreviewable one.

1. **The setup page shows raw constant names.** `admin/setup.php` translates each setting
   using the constant name as the key (`$langs->trans('NOPCOMMERCE_WEBSHOP_WAREHOUSE_ID')`),
   but the language file defines `NopCommerceWebshopWarehouseId`. The keys do not match, so
   the settings page renders `NOPCOMMERCE_WEBSHOP_WAREHOUSE_ID` literally. This sits on the
   one screen the user must configure for the feature to work at all.

2. **`NopCommerceNotEnoughStock` is unreachable for captured transfers but still reads as
   if it applies generally.** Harmless, but its wording assumes the acknowledgement moves
   stock.

---

## Task dependency order

Tasks are sequential: 1 → 2 → 3 → 4 → 5 → 6 → 7 → 8 → 9 → 10.

Tasks 8 and 9 could be done in either order, but the list page references keys that Task 9
adds, so doing 8 first means the page briefly renders untranslated key names. That is
cosmetic and self-correcting.

Task 4 requires a module disable/re-enable before its test can pass, and Task 5 requires
another one. Do not skip them: `module_parts` is only read at enable time.
