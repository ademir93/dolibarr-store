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
 * \file    htdocs/custom/nopcommerce/test/NopCommerceApiOrderReversalTest.php
 * \ingroup nopcommerce
 * \brief   Tests of POST /order_reversal, entered through the REST method.
 *
 * Orders are recorded through POST /order_completed first, the way the webshop does, so a
 * reversal always acts on what the completed-order endpoint really wrote.
 */

require_once dirname(__FILE__).'/NopCommerceApiTestCase.php';

/**
 * Class NopCommerceApiOrderReversalTest
 *
 * @backupGlobals disabled
 * @backupStaticAttributes enabled
 * @remarks	backupGlobals must be disabled to have db,conf,user and lang not erased.
 */
class NopCommerceApiOrderReversalTest extends NopCommerceApiTestCase
{
	/**
	 * A reversal with no items puts back everything the order still has outstanding.
	 *
	 * @return void
	 */
	public function testReversalWithoutItemsPutsEverythingBack()
	{
		global $db;
		$db = $this->savdb;

		list($warehouse, $externalid, $children) = $this->makeSizedProduct('full', array('M', 'L'), 5);
		$orderid = $this->completeOrder($externalid, array('M' => 2, 'L' => 1));

		$response = $this->api()->orderReversal(array('orderid' => $orderid, 'reversalid' => 'CANCEL-1'));

		$this->assertFalse($response['already_reversed']);
		$this->assertCount(2, $response['items']);
		$this->assertGreaterThan(0, $response['items'][0]['stock_movement_id']);
		$this->assertArrayHasKey('stock_in_webshop_warehouse', $response['items'][0]);
		$this->assertEquals(5, $this->stockOf($children['M'], $warehouse));
		$this->assertEquals(5, $this->stockOf($children['L'], $warehouse));
	}

	/**
	 * Two sizes of one product on one order, one of them returned: only that size's stock moves,
	 * whichever line was recorded first.
	 *
	 * @return void
	 */
	public function testReturnOfOneSizeRestoresOnlyThatSize()
	{
		global $db;
		$db = $this->savdb;

		list($warehouse, $externalid, $children) = $this->makeSizedProduct('twosizes', array('M', 'L'), 5);
		// M is recorded first, so an oldest-first credit would put the return on M.
		$orderid = $this->completeOrder($externalid, array('M' => 1, 'L' => 1));
		$this->assertEquals(4, $this->stockOf($children['M'], $warehouse));
		$this->assertEquals(4, $this->stockOf($children['L'], $warehouse));

		$response = $this->api()->orderReversal(array(
			'orderid' => $orderid,
			'reversalid' => 'RETURN-L',
			'items' => array(array('productid' => $externalid, 'attribute' => 'Size', 'attributeValue' => 'L', 'quantity' => 1)),
		));

		$this->assertCount(1, $response['items']);
		$this->assertSame($children['L'], $response['items'][0]['fk_product']);
		$this->assertEquals(5, $this->stockOf($children['L'], $warehouse), 'The returned size is back');
		$this->assertEquals(4, $this->stockOf($children['M'], $warehouse), 'The other size stays sold');

		// The other size can then be returned on its own, matched case-insensitively whatever
		// the attribute is called on the webshop.
		$this->api()->orderReversal(array(
			'orderid' => $orderid,
			'reversalid' => 'RETURN-M',
			'items' => array(array('productid' => $externalid, 'attribute' => 'Veličina', 'attributeValue' => 'm', 'quantity' => 1)),
		));
		$this->assertEquals(5, $this->stockOf($children['M'], $warehouse));
	}

	/**
	 * Without a size, a reversal still works when the order recorded a single variant of the product.
	 *
	 * @return void
	 */
	public function testReversalWithoutSizeWorksWhenOnlyOneVariantWasRecorded()
	{
		global $db;
		$db = $this->savdb;

		list($warehouse, $externalid, $children) = $this->makeSizedProduct('onevariant', array('M', 'L'), 5);
		$orderid = $this->completeOrder($externalid, array('M' => 3));

		$this->api()->orderReversal(array(
			'orderid' => $orderid,
			'reversalid' => 'RETURN-1',
			'items' => array(array('productid' => $externalid, 'quantity' => 2)),
		));

		$this->assertEquals(4, $this->stockOf($children['M'], $warehouse));
		$this->assertEquals(5, $this->stockOf($children['L'], $warehouse));
	}

	/**
	 * Without a size, a reversal is refused when the order recorded several variants of the
	 * product, because it cannot tell which one came back. It says a size is needed and moves nothing.
	 *
	 * @return void
	 */
	public function testReversalWithoutSizeIsRefusedWhenSeveralVariantsWereRecorded()
	{
		global $db;
		$db = $this->savdb;

		list($warehouse, $externalid, $children) = $this->makeSizedProduct('ambiguous', array('M', 'L'), 5);
		$orderid = $this->completeOrder($externalid, array('M' => 1, 'L' => 1));

		$error = $this->assertRestError(409, function () use ($orderid, $externalid) {
			$this->api()->orderReversal(array(
				'orderid' => $orderid,
				'reversalid' => 'RETURN-AMBIGUOUS',
				'items' => array(array('productid' => $externalid, 'quantity' => 1)),
			));
		});

		$this->assertStringContainsStringIgnoringCase('size', $error->getMessage());
		$this->assertEquals(4, $this->stockOf($children['M'], $warehouse));
		$this->assertEquals(4, $this->stockOf($children['L'], $warehouse));
	}

	/**
	 * A size the order never had is refused: there is nothing recorded to put back.
	 *
	 * @return void
	 */
	public function testReversalOfASizeTheOrderNeverHadIsRefused()
	{
		global $db;
		$db = $this->savdb;

		list($warehouse, $externalid, $children) = $this->makeSizedProduct('neverhad', array('M', 'L'), 5);
		$orderid = $this->completeOrder($externalid, array('M' => 1));

		$this->assertRestError(409, function () use ($orderid, $externalid) {
			$this->api()->orderReversal(array(
				'orderid' => $orderid,
				'reversalid' => 'RETURN-L',
				'items' => array(array('productid' => $externalid, 'attribute' => 'Size', 'attributeValue' => 'L', 'quantity' => 1)),
			));
		});
		$this->assertEquals(5, $this->stockOf($children['L'], $warehouse));
		$this->assertEquals(4, $this->stockOf($children['M'], $warehouse));
	}

	/**
	 * Sending the same reversal id again changes nothing.
	 *
	 * @return void
	 */
	public function testSameReversalIdIsAppliedOnce()
	{
		global $db;
		$db = $this->savdb;

		list($warehouse, $externalid, $children) = $this->makeSizedProduct('replay', array('M'), 5);
		$orderid = $this->completeOrder($externalid, array('M' => 2));
		$body = array(
			'orderid' => $orderid,
			'reversalid' => 'RETURN-ONCE',
			'items' => array(array('productid' => $externalid, 'attribute' => 'Size', 'attributeValue' => 'M', 'quantity' => 1)),
		);

		$first = $this->api()->orderReversal($body);
		$second = $this->api()->orderReversal($body);

		$this->assertFalse($first['already_reversed']);
		$this->assertTrue($second['already_reversed']);
		$this->assertEquals(4, $this->stockOf($children['M'], $warehouse), 'Put back once, not twice');
	}

	/**
	 * A different reversal id applies on top of an earlier one, never for more than remains.
	 *
	 * @return void
	 */
	public function testSecondReversalIsCappedByWhatRemains()
	{
		global $db;
		$db = $this->savdb;

		list($warehouse, $externalid, $children) = $this->makeSizedProduct('capped', array('M'), 5);
		$orderid = $this->completeOrder($externalid, array('M' => 2));
		$item = function ($qty) use ($externalid) {
			return array(array('productid' => $externalid, 'attribute' => 'Size', 'attributeValue' => 'M', 'quantity' => $qty));
		};

		$this->api()->orderReversal(array('orderid' => $orderid, 'reversalid' => 'RETURN-A', 'items' => $item(1)));
		$this->assertRestError(409, function () use ($orderid, $item) {
			$this->api()->orderReversal(array('orderid' => $orderid, 'reversalid' => 'RETURN-B', 'items' => $item(2)));
		});
		$this->assertEquals(4, $this->stockOf($children['M'], $warehouse), 'The refused reversal put nothing back');

		$this->api()->orderReversal(array('orderid' => $orderid, 'reversalid' => 'RETURN-C', 'items' => $item(1)));
		$this->assertEquals(5, $this->stockOf($children['M'], $warehouse));
	}

	/**
	 * An order nobody recorded is a 404.
	 *
	 * @return void
	 */
	public function testUnknownOrderIsRefused()
	{
		global $db;
		$db = $this->savdb;

		$this->makeWebshopWarehouse('unknown');

		$this->assertRestError(404, function () {
			$this->api()->orderReversal(array('orderid' => $this->newNopId(), 'reversalid' => 'CANCEL-X'));
		});
	}

	/**
	 * Every malformed body is a 400.
	 *
	 * @return void
	 */
	public function testMalformedBodiesAreRefusedWith400()
	{
		global $db;
		$db = $this->savdb;

		$this->makeWebshopWarehouse('malformed');

		$bodies = array(
			'no body' => null,
			'no order id' => array('reversalid' => 'X'),
			'no reversal id' => array('orderid' => 1001),
			'blank reversal id' => array('orderid' => 1001, 'reversalid' => '  '),
			'items not a list' => array('orderid' => 1001, 'reversalid' => 'X', 'items' => 'M'),
			'item without product id' => array('orderid' => 1001, 'reversalid' => 'X', 'items' => array(array('quantity' => 1))),
			'item with zero quantity' => array('orderid' => 1001, 'reversalid' => 'X', 'items' => array(array('productid' => 55, 'quantity' => 0))),
			'item with a size that is not text' => array('orderid' => 1001, 'reversalid' => 'X', 'items' => array(array('productid' => 55, 'attributeValue' => array('M'), 'quantity' => 1))),
		);

		foreach ($bodies as $name => $body) {
			$this->assertRestError(400, function () use ($body) {
				$this->api()->orderReversal($body);
			});
		}
	}

	/**
	 * A key without the sync right is a 403.
	 *
	 * @return void
	 */
	public function testCallerWithoutTheSyncRightIsRefused()
	{
		global $db;
		$db = $this->savdb;

		$this->makeWebshopWarehouse('forbidden');

		$this->assertRestError(403, function () use ($db) {
			$this->api(new User($db))->orderReversal(array('orderid' => 1001, 'reversalid' => 'X'));
		});
	}

	/**
	 * Build a webshop warehouse and a product with one variant per size, each holding stock.
	 *
	 * @param	string		$suffix		Suffix making the names unique
	 * @param	string[]	$sizes		Size values
	 * @param	int			$stock		Stock put on every variant
	 * @return	array{0:int,1:int,2:array<string,int>}	Warehouse id, nopCommerce product id, variant ids by size
	 */
	private function makeSizedProduct($suffix, array $sizes, $stock)
	{
		$warehouse = $this->makeWebshopWarehouse($suffix);
		$externalid = $this->newNopId();
		$parent = $this->makeProduct($suffix, $externalid);
		$children = $this->makeVariants($parent, $suffix, $sizes);
		foreach ($children as $child) {
			$this->seed($child, $warehouse, $stock);
		}

		return array($warehouse, $externalid, $children);
	}

	/**
	 * Record an order for the product through POST /order_completed, one item per size in the
	 * order given.
	 *
	 * @param	int					$externalid		nopCommerce product id
	 * @param	array<string,int>	$quantities		Quantity sold, by size
	 * @return	int								nopCommerce order id
	 */
	private function completeOrder($externalid, array $quantities)
	{
		$orderid = $this->newNopId();
		$items = array();
		foreach ($quantities as $size => $qty) {
			$items[] = array('productid' => $externalid, 'attribute' => 'Size', 'attributeValue' => $size, 'quantity' => $qty);
		}

		$response = $this->api()->orderCompleted(array('orderid' => $orderid, 'items' => $items));
		$this->assertFalse($response['already_completed']);

		return $orderid;
	}
}
