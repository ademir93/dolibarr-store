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
 * \file    htdocs/custom/nopcommerce/test/NopCommerceSalesTest.php
 * \ingroup nopcommerce
 * \brief   PHPUnit tests for applying a sold line nopCommerce reports through /sales.
 */

global $conf, $user, $langs, $db;

require_once dirname(__FILE__).'/../../../master.inc.php';
require_once dirname(__FILE__).'/../../../product/class/product.class.php';
require_once dirname(__FILE__).'/../../../product/stock/class/entrepot.class.php';
require_once dirname(__FILE__).'/../../../../test/phpunit/CommonClassTest.class.php';
require_once dirname(__FILE__).'/../class/nopcommercesync.class.php';

if (empty($user->id)) {
	$user->fetch(1);
	$user->loadRights();
}
$conf->global->MAIN_DISABLE_ALL_MAILS = 1;


/**
 * Class for PHPUnit tests of the /sales line sync.
 *
 * Failures are asserted through their return value only. The harness owns the outermost
 * transaction and Dolibarr's nested transactions are a counter, so a rollback inside
 * applySalesLine() cannot be observed here (see doc/testing.md).
 *
 * @backupGlobals disabled
 * @backupStaticAttributes enabled
 * @remarks backupGlobals must be disabled so db, conf, user and langs are not erased.
 */
class NopCommerceSalesTest extends CommonClassTest
{
	/**
	 * A sold line is taken out of the webshop warehouse and recorded as applied.
	 *
	 * @return void
	 */
	public function testSoldLineIsTakenOutOfTheWebshop()
	{
		global $db, $user;
		$db = $this->savdb;

		$warehouse = $this->makeWebshopWarehouse('ok');
		$product = $this->makeProduct('ok');
		$this->seed($product->id, $warehouse, 5);

		$sync = new NopCommerceSync($db);
		$result = $sync->applySalesLine($user, $this->newNopId(), $product->id, 2, dol_now());

		$this->assertSame('applied', $result['status'], 'applySalesLine failed: '.$result['error']);
		$this->assertNull($result['error']);
		$this->assertEquals(3.0, $sync->getStockInWarehouse($product->id, $warehouse), 'The sold line must be decremented');
	}

	/**
	 * Reporting the same nop_order_item_id twice only takes the stock out once.
	 *
	 * @return void
	 */
	public function testSameLineIsAppliedOnce()
	{
		global $db, $user;
		$db = $this->savdb;

		$warehouse = $this->makeWebshopWarehouse('twice');
		$product = $this->makeProduct('twice');
		$this->seed($product->id, $warehouse, 5);

		$noporderitemid = $this->newNopId();
		$sync = new NopCommerceSync($db);

		$first = $sync->applySalesLine($user, $noporderitemid, $product->id, 2, dol_now());
		$this->assertSame('applied', $first['status'], 'First call failed: '.$first['error']);

		$second = $sync->applySalesLine($user, $noporderitemid, $product->id, 2, dol_now());
		$this->assertSame('already_applied', $second['status'], 'Second call must report the line as already applied');
		$this->assertNull($second['error']);

		$this->assertEquals(3.0, $sync->getStockInWarehouse($product->id, $warehouse), 'Stock must be taken out only once');
	}

	/**
	 * An unknown Dolibarr product id is refused without claiming the idempotency key, so
	 * a later retry under the same nop_order_item_id (once the mapping is fixed) can still
	 * succeed instead of being stuck as a permanent failure.
	 *
	 * @return void
	 */
	public function testUnknownProductIsRefusedWithoutClaimingTheKey()
	{
		global $db, $user;
		$db = $this->savdb;

		$warehouse = $this->makeWebshopWarehouse('unknown');
		$noporderitemid = $this->newNopId();

		$sync = new NopCommerceSync($db);
		$result = $sync->applySalesLine($user, $noporderitemid, 999999999, 1, dol_now());
		$this->assertSame('failed', $result['status'], 'An unknown product must be refused');
		$this->assertNotNull($result['error'], 'The refusal must be explained');

		$product = $this->makeProduct('unknown');
		$this->seed($product->id, $warehouse, 5);

		$retry = $sync->applySalesLine($user, $noporderitemid, $product->id, 1, dol_now());
		$this->assertSame('applied', $retry['status'], 'A retry with a valid product must be able to apply: '.$retry['error']);
	}

	/**
	 * The webshop warehouse is not taken below zero, and the idempotency key is freed so
	 * a retry after restocking can still succeed.
	 *
	 * @return void
	 */
	public function testNotEnoughStockIsRefusedWithoutClaimingTheKey()
	{
		global $db, $user;
		$db = $this->savdb;

		$warehouse = $this->makeWebshopWarehouse('short');
		$product = $this->makeProduct('short');
		$this->seed($product->id, $warehouse, 1);

		$noporderitemid = $this->newNopId();
		$sync = new NopCommerceSync($db);

		$result = $sync->applySalesLine($user, $noporderitemid, $product->id, 2, dol_now());
		$this->assertSame('failed', $result['status'], 'An order larger than the stock must be refused');
		$this->assertEquals(1.0, $sync->getStockInWarehouse($product->id, $warehouse), 'No stock must move');

		$this->seed($product->id, $warehouse, 3);
		$retry = $sync->applySalesLine($user, $noporderitemid, $product->id, 2, dol_now());
		$this->assertSame('applied', $retry['status'], 'A retry once restocked must be able to apply: '.$retry['error']);
	}

	/**
	 * Return an id unlikely to exist on either side.
	 *
	 * @return int
	 */
	private function newNopId()
	{
		return mt_rand(900000000, 2000000000);
	}

	/**
	 * Build a warehouse, configure it as the webshop warehouse and return its id.
	 *
	 * @param	string	$suffix		Suffix making the label unique
	 * @return	int					Warehouse id
	 */
	private function makeWebshopWarehouse($suffix)
	{
		global $conf, $db, $user;

		$warehouse = new Entrepot($db);
		$warehouse->initAsSpecimen();
		$warehouse->label .= ' phpunit nop sales '.$suffix;
		$warehouse->description .= ' phpunit nop sales '.$suffix;
		$id = $warehouse->create($user);
		$this->assertGreaterThan(0, $id, 'Failed to create warehouse '.$suffix.': '.$warehouse->error);

		$conf->global->NOPCOMMERCE_WEBSHOP_WAREHOUSE_ID = $id;
		$conf->global->NOPCOMMERCE_ALLOW_NEGATIVE_SOURCE_STOCK = 0;

		return $id;
	}

	/**
	 * Build a plain product.
	 *
	 * @param	string	$suffix		Suffix making the ref unique
	 * @return	Product				Created product
	 */
	private function makeProduct($suffix)
	{
		global $db, $user;

		$product = new Product($db);
		$product->initAsSpecimen();
		$product->ref .= ' phpunit nop sales '.$suffix;
		$product->label .= ' phpunit nop sales '.$suffix;
		$id = $product->create($user);
		$this->assertGreaterThan(0, $id, 'Failed to create product '.$suffix.': '.$product->error);

		return $product;
	}

	/**
	 * Put stock of a product into a warehouse.
	 *
	 * @param	int		$productid		Product id
	 * @param	int		$warehouseid	Warehouse id
	 * @param	int		$qty			Quantity
	 * @return	void
	 */
	private function seed($productid, $warehouseid, $qty)
	{
		global $db, $user;

		$product = new Product($db);
		$this->assertGreaterThan(0, $product->fetch($productid), 'Failed to load product '.$productid);
		$this->assertGreaterThan(0, $product->correct_stock($user, $warehouseid, $qty, 0, 'phpunit seed', 0, 'PHPUNITSEED'), 'Failed to seed stock');
	}
}
