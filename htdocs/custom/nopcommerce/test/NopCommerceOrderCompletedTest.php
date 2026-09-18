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
 * \file    htdocs/custom/nopcommerce/test/NopCommerceOrderCompletedTest.php
 * \ingroup nopcommerce
 * \brief   PHPUnit tests for applying an order nopCommerce reports as completed.
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
 * Class for PHPUnit tests of the completed order sync.
 *
 * Failures are asserted through their return code only. The harness owns the outermost
 * transaction and Dolibarr's nested transactions are a counter, so the rollback itself
 * cannot be observed here (see doc/testing.md).
 *
 * @backupGlobals disabled
 * @backupStaticAttributes enabled
 * @remarks backupGlobals must be disabled so db, conf, user and langs are not erased.
 */
class NopCommerceOrderCompletedTest extends CommonClassTest
{
	/**
	 * A sold variant is taken out of the webshop warehouse and the order is recorded.
	 *
	 * @return void
	 */
	public function testSoldVariantIsTakenOutOfTheWebshop()
	{
		global $db, $user;
		$db = $this->savdb;

		$warehouse = $this->makeWebshopWarehouse('ok');
		$externalid = $this->newNopId();
		$parent = $this->makeProduct('ok', $externalid);
		$children = $this->makeVariants($parent, 'ok', array('S', 'M'));
		$this->seed($children['S'], $warehouse, 5);
		$this->seed($children['M'], $warehouse, 5);

		$orderid = $this->newNopId();
		$sync = new NopCommerceSync($db);
		$lines = array(array('productid' => $externalid, 'attribute' => 'Size', 'attributeValue' => 'M', 'quantity' => 2));

		$this->assertSame(1, $sync->syncProduct($user, $orderid, $lines), 'syncProduct failed: '.$sync->error);

		$this->assertEquals(3.0, $sync->getStockInWarehouse($children['M'], $warehouse), 'The sold variant must be decremented');
		$this->assertEquals(5.0, $sync->getStockInWarehouse($children['S'], $warehouse), 'Another variant must not move');
		$this->assertGreaterThan(0, $sync->completedorderid, 'The completed order id must be returned');

		$sql = "SELECT i.fk_product, i.nop_external_id FROM ".MAIN_DB_PREFIX."nop_order_complete_items as i";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."nop_order_completed as o ON o.rowid = i.fk_nop_order_completed";
		$sql .= " WHERE o.nop_order_id = ".((int) $orderid);
		$resql = $db->query($sql);
		$this->assertNotFalse($resql, 'Query failed: '.$db->lasterror());
		$this->assertSame(1, (int) $db->num_rows($resql), 'Exactly one item must be recorded');
		$obj = $db->fetch_object($resql);
		$this->assertSame($children['M'], (int) $obj->fk_product, 'The item must point at the sold variant');
		$this->assertSame($externalid, (int) $obj->nop_external_id, 'The item must keep the nopCommerce product id');
	}

	/**
	 * Reporting the same order twice only takes the stock out once.
	 *
	 * @return void
	 */
	public function testSameOrderIsAppliedOnce()
	{
		global $db, $user;
		$db = $this->savdb;

		$warehouse = $this->makeWebshopWarehouse('twice');
		$externalid = $this->newNopId();
		$parent = $this->makeProduct('twice', $externalid);
		$children = $this->makeVariants($parent, 'twice', array('M'));
		$this->seed($children['M'], $warehouse, 5);

		$orderid = $this->newNopId();
		$sync = new NopCommerceSync($db);
		$lines = array(array('productid' => $externalid, 'attribute' => 'Size', 'attributeValue' => 'M', 'quantity' => 2));

		$this->assertSame(1, $sync->syncProduct($user, $orderid, $lines), 'First call failed: '.$sync->error);
		$firstid = $sync->completedorderid;
		$this->assertSame(0, $sync->syncProduct($user, $orderid, $lines), 'Second call must report the order as already recorded');
		$this->assertSame($firstid, $sync->completedorderid, 'The second call must return the recorded order');
		$this->assertEquals(3.0, $sync->getStockInWarehouse($children['M'], $warehouse), 'Stock must be taken out only once');
	}

	/**
	 * The value is matched case-insensitively, whatever the attribute is called on each side.
	 *
	 * @return void
	 */
	public function testValueMatchesWhateverTheAttributeIsCalled()
	{
		global $db, $user;
		$db = $this->savdb;

		$warehouse = $this->makeWebshopWarehouse('name');
		$externalid = $this->newNopId();
		$parent = $this->makeProduct('name', $externalid);
		$children = $this->makeVariants($parent, 'name', array('S', 'M'));
		$this->seed($children['M'], $warehouse, 5);

		$sync = new NopCommerceSync($db);
		$lines = array(array('productid' => $externalid, 'attribute' => 'Veličina', 'attributeValue' => 'm', 'quantity' => 1));

		$this->assertSame(1, $sync->syncProduct($user, $this->newNopId(), $lines), 'syncProduct failed: '.$sync->error);
		$this->assertEquals(4.0, $sync->getStockInWarehouse($children['M'], $warehouse), 'The variant M must be decremented');
	}

	/**
	 * A product without variants is decremented directly.
	 *
	 * @return void
	 */
	public function testProductWithoutVariantsIsTakenOutDirectly()
	{
		global $db, $user;
		$db = $this->savdb;

		$warehouse = $this->makeWebshopWarehouse('simple');
		$externalid = $this->newNopId();
		$product = $this->makeProduct('simple', $externalid);
		$this->seed($product->id, $warehouse, 4);

		$sync = new NopCommerceSync($db);
		$lines = array(array('productid' => $externalid, 'attribute' => '', 'attributeValue' => '', 'quantity' => 3));

		$this->assertSame(1, $sync->syncProduct($user, $this->newNopId(), $lines), 'syncProduct failed: '.$sync->error);
		$this->assertEquals(1.0, $sync->getStockInWarehouse($product->id, $warehouse), 'The product must be decremented');
	}

	/**
	 * An unknown external id refuses the order.
	 *
	 * @return void
	 */
	public function testUnknownExternalIdIsRefused()
	{
		global $db, $user;
		$db = $this->savdb;

		$this->makeWebshopWarehouse('unknown');

		$sync = new NopCommerceSync($db);
		$lines = array(array('productid' => $this->newNopId(), 'attribute' => 'Size', 'attributeValue' => 'M', 'quantity' => 1));

		$this->assertSame(-2, $sync->syncProduct($user, $this->newNopId(), $lines), 'An unknown product must be refused');
		$this->assertNotSame('', $sync->error, 'The refusal must be explained');
	}

	/**
	 * A value no variant carries refuses the order, and so does a missing value.
	 *
	 * @return void
	 */
	public function testUnknownOrMissingVariantIsRefused()
	{
		global $db, $user;
		$db = $this->savdb;

		$this->makeWebshopWarehouse('variant');
		$externalid = $this->newNopId();
		$parent = $this->makeProduct('variant', $externalid);
		$this->makeVariants($parent, 'variant', array('S', 'M'));

		$sync = new NopCommerceSync($db);

		$lines = array(array('productid' => $externalid, 'attribute' => 'Size', 'attributeValue' => 'XXL', 'quantity' => 1));
		$this->assertSame(-2, $sync->syncProduct($user, $this->newNopId(), $lines), 'A value no variant carries must be refused');

		$lines = array(array('productid' => $externalid, 'attribute' => '', 'attributeValue' => '', 'quantity' => 1));
		$this->assertSame(-2, $sync->syncProduct($user, $this->newNopId(), $lines), 'A product with variants needs the value');
	}

	/**
	 * The webshop warehouse is not taken below zero.
	 *
	 * @return void
	 */
	public function testNotEnoughStockIsRefused()
	{
		global $db, $user;
		$db = $this->savdb;

		$warehouse = $this->makeWebshopWarehouse('short');
		$externalid = $this->newNopId();
		$parent = $this->makeProduct('short', $externalid);
		$children = $this->makeVariants($parent, 'short', array('M'));
		$this->seed($children['M'], $warehouse, 1);

		$sync = new NopCommerceSync($db);
		$lines = array(array('productid' => $externalid, 'attribute' => 'Size', 'attributeValue' => 'M', 'quantity' => 2));

		$this->assertSame(-3, $sync->syncProduct($user, $this->newNopId(), $lines), 'An order larger than the stock must be refused');
		$this->assertEquals(1.0, $sync->getStockInWarehouse($children['M'], $warehouse), 'No stock must move');
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
		$warehouse->label .= ' phpunit nop order '.$suffix;
		$warehouse->description .= ' phpunit nop order '.$suffix;
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
		$product->ref .= ' phpunit nop order '.$suffix;
		$product->label .= ' phpunit nop order '.$suffix;
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
		$attribute->ref = 'PHPUNITNOPSIZE'.strtoupper($suffix).mt_rand(1000, 9999);
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
