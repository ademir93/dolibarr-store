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
require_once dirname(__FILE__).'/../class/nopcommercecapture.class.php';

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
}
