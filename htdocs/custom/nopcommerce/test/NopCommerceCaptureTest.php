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
}
