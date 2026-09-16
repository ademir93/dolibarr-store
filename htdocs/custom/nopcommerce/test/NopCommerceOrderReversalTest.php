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
 * \file    htdocs/custom/nopcommerce/test/NopCommerceOrderReversalTest.php
 * \ingroup nopcommerce
 * \brief   PHPUnit tests for reversing an order nopCommerce reports as completed.
 */

global $conf, $user, $langs, $db;

require_once dirname(__FILE__).'/../../../master.inc.php';
require_once dirname(__FILE__).'/../../../product/class/product.class.php';
require_once dirname(__FILE__).'/../../../product/stock/class/entrepot.class.php';
require_once dirname(__FILE__).'/../../../variants/class/ProductAttribute.class.php';
require_once dirname(__FILE__).'/../../../variants/class/ProductAttributeValue.class.php';
require_once dirname(__FILE__).'/../../../variants/class/ProductCombination.class.php';
require_once dirname(__FILE__).'/../../../../test/phpunit/CommonClassTest.class.php';
require_once dirname(__FILE__).'/../class/nopcommercesync.class.php';

if (empty($user->id)) {
	$user->fetch(1);
	$user->loadRights();
}
$conf->global->MAIN_DISABLE_ALL_MAILS = 1;


/**
 * Class for PHPUnit tests of the order reversal sync.
 *
 * Failures are asserted through their return code only. The harness owns the outermost
 * transaction and Dolibarr's nested transactions are a counter, so the rollback itself
 * cannot be observed here (see doc/testing.md).
 *
 * @backupGlobals disabled
 * @backupStaticAttributes enabled
 * @remarks backupGlobals must be disabled so db, conf, user and langs are not erased.
 */
class NopCommerceOrderReversalTest extends CommonClassTest
{
	/**
	 * Reversing a whole order puts every outstanding item back into the webshop warehouse.
	 *
	 * @return void
	 */
	public function testFullReversalPutsEverythingBack()
	{
		global $db, $user;
		$db = $this->savdb;

		$warehouse = $this->makeWebshopWarehouse('full');
		$externalid = $this->newNopId();
		$parent = $this->makeProduct('full', $externalid);
		$children = $this->makeVariants($parent, 'full', array('M'));
		$this->seed($children['M'], $warehouse, 5);

		$orderid = $this->newNopId();
		$sync = new NopCommerceSync($db);
		$lines = array(array('productid' => $externalid, 'attribute' => 'Size', 'attributeValue' => 'M', 'quantity' => 2));
		$this->assertSame(1, $sync->syncProduct($user, $orderid, $lines), 'syncProduct failed: '.$sync->error);
		$this->assertEquals(3.0, $sync->getStockInWarehouse($children['M'], $warehouse));

		$result = $sync->reverseOrder($user, $orderid, 'RET-'.$orderid, array());
		$this->assertSame(1, $result, 'reverseOrder failed: '.$sync->error);
		$this->assertEquals(5.0, $sync->getStockInWarehouse($children['M'], $warehouse), 'The full quantity must be back');
		$this->assertCount(1, $sync->reverseditems);
		$this->assertSame(2, $sync->reverseditems[0]['quantity']);

		// A second full reversal has nothing left to reverse: it succeeds but moves nothing.
		$again = $sync->reverseOrder($user, $orderid, 'RET-'.$orderid.'-again', array());
		$this->assertSame(1, $again, 'A second full reversal must still succeed: '.$sync->error);
		$this->assertEquals(5.0, $sync->getStockInWarehouse($children['M'], $warehouse), 'Nothing left to put back');
		$this->assertCount(0, $sync->reverseditems);
	}

	/**
	 * Reversing specific items only puts back the quantity asked for.
	 *
	 * @return void
	 */
	public function testPartialReversalCanBeFollowedByAnother()
	{
		global $db, $user;
		$db = $this->savdb;

		$warehouse = $this->makeWebshopWarehouse('partial');
		$externalid = $this->newNopId();
		$parent = $this->makeProduct('partial', $externalid);
		$children = $this->makeVariants($parent, 'partial', array('M'));
		$this->seed($children['M'], $warehouse, 10);

		$orderid = $this->newNopId();
		$sync = new NopCommerceSync($db);
		$lines = array(array('productid' => $externalid, 'attribute' => 'Size', 'attributeValue' => 'M', 'quantity' => 5));
		$this->assertSame(1, $sync->syncProduct($user, $orderid, $lines), 'syncProduct failed: '.$sync->error);
		$this->assertEquals(5.0, $sync->getStockInWarehouse($children['M'], $warehouse));

		$items = array(array('productid' => $externalid, 'quantity' => 2));
		$this->assertSame(1, $sync->reverseOrder($user, $orderid, 'RET-1', $items), 'First reversal failed: '.$sync->error);
		$this->assertEquals(7.0, $sync->getStockInWarehouse($children['M'], $warehouse));

		$this->assertSame(1, $sync->reverseOrder($user, $orderid, 'RET-2', $items), 'Second reversal failed: '.$sync->error);
		$this->assertEquals(9.0, $sync->getStockInWarehouse($children['M'], $warehouse));

		// Only 1 unit remains outstanding (5 sold - 2 - 2): asking for 2 more must be refused.
		$this->assertSame(-3, $sync->reverseOrder($user, $orderid, 'RET-3', $items), 'Reversing more than remains must be refused');
		$this->assertEquals(9.0, $sync->getStockInWarehouse($children['M'], $warehouse), 'A refused reversal must not move stock');

		$last = array(array('productid' => $externalid, 'quantity' => 1));
		$this->assertSame(1, $sync->reverseOrder($user, $orderid, 'RET-4', $last), 'Reversing exactly what remains must succeed: '.$sync->error);
		$this->assertEquals(10.0, $sync->getStockInWarehouse($children['M'], $warehouse));
	}

	/**
	 * Replaying the same reversal id is safe and changes nothing the second time.
	 *
	 * @return void
	 */
	public function testSameReversalIsAppliedOnce()
	{
		global $db, $user;
		$db = $this->savdb;

		$warehouse = $this->makeWebshopWarehouse('replay');
		$externalid = $this->newNopId();
		$parent = $this->makeProduct('replay', $externalid);
		$children = $this->makeVariants($parent, 'replay', array('M'));
		$this->seed($children['M'], $warehouse, 5);

		$orderid = $this->newNopId();
		$sync = new NopCommerceSync($db);
		$lines = array(array('productid' => $externalid, 'attribute' => 'Size', 'attributeValue' => 'M', 'quantity' => 2));
		$this->assertSame(1, $sync->syncProduct($user, $orderid, $lines), 'syncProduct failed: '.$sync->error);

		$items = array(array('productid' => $externalid, 'quantity' => 2));
		$this->assertSame(1, $sync->reverseOrder($user, $orderid, 'RET-once', $items), 'First call failed: '.$sync->error);
		$firstid = $sync->reversalid;
		$this->assertEquals(5.0, $sync->getStockInWarehouse($children['M'], $warehouse));

		$this->assertSame(0, $sync->reverseOrder($user, $orderid, 'RET-once', $items), 'Replay must report the reversal as already recorded');
		$this->assertSame($firstid, $sync->reversalid);
		$this->assertEquals(5.0, $sync->getStockInWarehouse($children['M'], $warehouse), 'Stock must not move a second time');
	}

	/**
	 * An order that was never recorded as completed is refused.
	 *
	 * @return void
	 */
	public function testUnknownOrderIsRefused()
	{
		global $db, $user;
		$db = $this->savdb;

		$this->makeWebshopWarehouse('unknownorder');

		$sync = new NopCommerceSync($db);
		$items = array(array('productid' => $this->newNopId(), 'quantity' => 1));

		$this->assertSame(-2, $sync->reverseOrder($user, $this->newNopId(), 'RET-unknown', $items), 'An unrecorded order must be refused');
		$this->assertNotSame('', $sync->error, 'The refusal must be explained');
	}

	/**
	 * A product that was never recorded on that order is refused as an over-reversal.
	 *
	 * @return void
	 */
	public function testUnrecordedItemIsRefused()
	{
		global $db, $user;
		$db = $this->savdb;

		$warehouse = $this->makeWebshopWarehouse('wrongitem');
		$externalid = $this->newNopId();
		$parent = $this->makeProduct('wrongitem', $externalid);
		$children = $this->makeVariants($parent, 'wrongitem', array('M'));
		$this->seed($children['M'], $warehouse, 5);

		$orderid = $this->newNopId();
		$sync = new NopCommerceSync($db);
		$lines = array(array('productid' => $externalid, 'attribute' => 'Size', 'attributeValue' => 'M', 'quantity' => 2));
		$this->assertSame(1, $sync->syncProduct($user, $orderid, $lines), 'syncProduct failed: '.$sync->error);

		$items = array(array('productid' => $this->newNopId(), 'quantity' => 1));
		$this->assertSame(-3, $sync->reverseOrder($user, $orderid, 'RET-wrong', $items), 'A product not recorded on this order must be refused');
		$this->assertEquals(3.0, $sync->getStockInWarehouse($children['M'], $warehouse), 'No stock must move');
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
		$warehouse->label .= ' phpunit nop reversal '.$suffix;
		$warehouse->description .= ' phpunit nop reversal '.$suffix;
		$id = $warehouse->create($user);
		$this->assertGreaterThan(0, $id, 'Failed to create warehouse '.$suffix.': '.$warehouse->error);

		$conf->global->NOPCOMMERCE_WEBSHOP_WAREHOUSE_ID = $id;
		$conf->global->NOPCOMMERCE_ALLOW_NEGATIVE_SOURCE_STOCK = 0;

		return $id;
	}

	/**
	 * Build a product carrying a nopCommerce external id.
	 *
	 * @param	string	$suffix			Suffix making the ref unique
	 * @param	int		$externalid		nopCommerce product id
	 * @return	Product					Created product
	 */
	private function makeProduct($suffix, $externalid)
	{
		global $db, $user;

		$product = new Product($db);
		$product->initAsSpecimen();
		$product->ref .= ' phpunit nop reversal '.$suffix;
		$product->label .= ' phpunit nop reversal '.$suffix;
		$product->array_options = array('options_nopcommerce_external_id' => $externalid);
		$id = $product->create($user);
		$this->assertGreaterThan(0, $id, 'Failed to create product '.$suffix.': '.$product->error);

		return $product;
	}

	/**
	 * Build a Size attribute with the given values and one variant of the product per value.
	 *
	 * @param	Product				$parent		Parent product
	 * @param	string				$suffix		Suffix making the attribute ref unique
	 * @param	string[]			$values		Values, for example S and M
	 * @return	array<string,int>				Id of the variant child product, keyed by value
	 */
	private function makeVariants(Product $parent, $suffix, array $values)
	{
		global $db, $user;

		$attribute = new ProductAttribute($db);
		$attribute->ref = 'PHPUNITNOPREV'.strtoupper($suffix).mt_rand(1000, 9999);
		$attribute->label = 'Size';
		$attributeid = $attribute->create($user);
		$this->assertGreaterThan(0, $attributeid, 'Failed to create attribute: '.$attribute->error);

		$children = array();
		foreach ($values as $value) {
			$attributevalue = new ProductAttributeValue($db);
			$attributevalue->fk_product_attribute = $attributeid;
			$attributevalue->ref = $value;
			$attributevalue->value = $value;
			$valueid = $attributevalue->create($user);
			$this->assertGreaterThan(0, $valueid, 'Failed to create value '.$value.': '.$attributevalue->error);

			$combination = new ProductCombination($db);
			$this->assertGreaterThan(0, $combination->createProductCombination($user, $parent, array($attributeid => $valueid), array($attributeid => array($valueid => array('weight' => 0, 'price' => 0)))), 'Failed to create variant '.$value.': '.$combination->error);

			$sql = "SELECT c.fk_product_child FROM ".MAIN_DB_PREFIX."product_attribute_combination as c";
			$sql .= " INNER JOIN ".MAIN_DB_PREFIX."product_attribute_combination2val as c2v ON c2v.fk_prod_combination = c.rowid";
			$sql .= " WHERE c.fk_product_parent = ".((int) $parent->id)." AND c2v.fk_prod_attr_val = ".((int) $valueid);
			$resql = $db->query($sql);
			$this->assertNotFalse($resql, 'Query failed: '.$db->lasterror());
			$children[$value] = (int) $db->fetch_object($resql)->fk_product_child;
		}

		return $children;
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
