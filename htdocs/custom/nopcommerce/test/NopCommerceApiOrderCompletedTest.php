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
 * \file    htdocs/custom/nopcommerce/test/NopCommerceApiOrderCompletedTest.php
 * \ingroup nopcommerce
 * \brief   Tests of POST /order_completed, entered through the REST method.
 *
 * The request and response bodies below are the ones in the frozen contract of the webshop
 * plugin (nop-store, docs/dolibarr-side-integration-tasks.md, endpoint 5). When one side
 * changes a shape, a test here fails.
 */

require_once dirname(__FILE__).'/NopCommerceApiTestCase.php';

/**
 * Class NopCommerceApiOrderCompletedTest
 *
 * @backupGlobals disabled
 * @backupStaticAttributes enabled
 * @remarks	backupGlobals must be disabled to have db,conf,user and lang not erased.
 */
class NopCommerceApiOrderCompletedTest extends NopCommerceApiTestCase
{
	/**
	 * A sized line takes the sold variant out of the webshop warehouse, and the answer is the
	 * flat shape the webshop reads: already_completed, then one entry per item in request order
	 * with the product, the stock movement and the remaining stock.
	 *
	 * @return void
	 */
	public function testEnvelopeOrderTakesTheSoldVariantOutOfTheWebshop()
	{
		global $db;
		$db = $this->savdb;

		$warehouse = $this->makeWebshopWarehouse('sized');
		$externalid = $this->newNopId();
		$parent = $this->makeProduct('sized', $externalid);
		$children = $this->makeVariants($parent, 'sized', array('M', 'L'));
		$this->seed($children['M'], $warehouse, 5);
		$this->seed($children['L'], $warehouse, 5);

		$response = $this->api()->orderCompleted(array(
			'orderid' => $this->newNopId(),
			'items' => array(
				array('productid' => $externalid, 'attribute' => 'Size', 'attributeValue' => 'M', 'quantity' => 2),
			),
		));

		$this->assertFalse($response['already_completed']);
		$this->assertCount(1, $response['items']);
		$this->assertSame($children['M'], $response['items'][0]['fk_product']);
		$this->assertGreaterThan(0, $response['items'][0]['stock_movement_id']);
		$this->assertEquals(3, $response['items'][0]['stock_reel'], 'stock_reel is what remains in the webshop warehouse');
		$this->assertEquals(3, $this->stockOf($children['M'], $warehouse));
		$this->assertEquals(5, $this->stockOf($children['L'], $warehouse), 'The other size is untouched');
	}

	/**
	 * A second attribute on the Dolibarr variants does not get in the way of the size match.
	 *
	 * @return void
	 */
	public function testSecondAttributeOnTheVariantsDoesNotBreakTheSizeMatch()
	{
		global $db;
		$db = $this->savdb;

		$warehouse = $this->makeWebshopWarehouse('twoattr');
		$externalid = $this->newNopId();
		$parent = $this->makeProduct('twoattr', $externalid);
		$children = $this->makeVariants($parent, 'twoattr', array('M', 'L'), 'Blue');
		$this->seed($children['M'], $warehouse, 4);
		$this->seed($children['L'], $warehouse, 4);

		$response = $this->api()->orderCompleted(array(
			'orderid' => $this->newNopId(),
			'items' => array(
				array('productid' => $externalid, 'attribute' => 'Veličina', 'attributeValue' => 'l', 'quantity' => 1),
			),
		));

		$this->assertSame($children['L'], $response['items'][0]['fk_product']);
		$this->assertEquals(3, $this->stockOf($children['L'], $warehouse));
		$this->assertEquals(4, $this->stockOf($children['M'], $warehouse));
	}

	/**
	 * A line without attributes omits both keys, and resolves by product id alone.
	 *
	 * @return void
	 */
	public function testLineWithoutAttributesResolvesByProductIdAlone()
	{
		global $db;
		$db = $this->savdb;

		$warehouse = $this->makeWebshopWarehouse('plain');
		$externalid = $this->newNopId();
		$product = $this->makeProduct('plain', $externalid);
		$this->seed($product->id, $warehouse, 6);

		$response = $this->api()->orderCompleted(array(
			'orderid' => $this->newNopId(),
			'items' => array(
				array('productid' => $externalid, 'quantity' => 3),
			),
		));

		$this->assertSame((int) $product->id, $response['items'][0]['fk_product']);
		$this->assertEquals(3, $this->stockOf($product->id, $warehouse));
	}

	/**
	 * Present-but-empty attribute values count as absent.
	 *
	 * @return void
	 */
	public function testEmptyAttributeValuesAreTreatedAsAbsent()
	{
		global $db;
		$db = $this->savdb;

		$warehouse = $this->makeWebshopWarehouse('empty');
		$externalid = $this->newNopId();
		$product = $this->makeProduct('empty', $externalid);
		$this->seed($product->id, $warehouse, 2);

		$response = $this->api()->orderCompleted(array(
			'orderid' => $this->newNopId(),
			'items' => array(
				array('productid' => $externalid, 'attribute' => '', 'attributeValue' => '', 'quantity' => 1),
			),
		));

		$this->assertFalse($response['already_completed']);
		$this->assertEquals(1, $this->stockOf($product->id, $warehouse));
	}

	/**
	 * Delivering the same order again is recognised: already_completed, no items, no stock moved.
	 *
	 * @return void
	 */
	public function testReplayIsRecognisedAndMovesNothing()
	{
		global $db;
		$db = $this->savdb;

		$warehouse = $this->makeWebshopWarehouse('replay');
		$externalid = $this->newNopId();
		$parent = $this->makeProduct('replay', $externalid);
		$children = $this->makeVariants($parent, 'replay', array('M'));
		$this->seed($children['M'], $warehouse, 5);

		$body = array(
			'orderid' => $this->newNopId(),
			'items' => array(
				array('productid' => $externalid, 'attribute' => 'Size', 'attributeValue' => 'M', 'quantity' => 2),
			),
		);

		$first = $this->api()->orderCompleted($body);
		$second = $this->api()->orderCompleted($body);

		$this->assertFalse($first['already_completed']);
		$this->assertTrue($second['already_completed']);
		$this->assertSame(array(), $second['items'], 'A replay writes nothing, so it reports no items');
		$this->assertEquals(3, $this->stockOf($children['M'], $warehouse), 'Stock moved once, not twice');
	}

	/**
	 * An order with an unresolvable item is refused with a 404 and moves nothing.
	 *
	 * The unresolvable item comes first on purpose. The transaction harness owns the outermost
	 * transaction, so a rollback inside a test undoes nothing: neither stock moved by an earlier
	 * item nor the recorded order row would be seen coming back. That all-or-nothing rollback is
	 * verified by hand (see doc/testing.md).
	 *
	 * @return void
	 */
	public function testOrderWithAnUnresolvableItemIsRefusedAndMovesNothing()
	{
		global $db;
		$db = $this->savdb;

		$warehouse = $this->makeWebshopWarehouse('atomic');
		$externalid = $this->newNopId();
		$product = $this->makeProduct('atomic', $externalid);
		$this->seed($product->id, $warehouse, 5);

		$body = array(
			'orderid' => $this->newNopId(),
			'items' => array(
				array('productid' => $this->newNopId(), 'quantity' => 1),
				array('productid' => $externalid, 'quantity' => 1),
			),
		);

		$this->assertRestError(404, function () use ($body) {
			$this->api()->orderCompleted($body);
		});
		$this->assertEquals(5, $this->stockOf($product->id, $warehouse), 'The resolvable item did not move either');
	}

	/**
	 * Every malformed body is a 400 that says what is wrong.
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
			'empty body' => array(),
			'no order id' => array('items' => array(array('productid' => 55, 'quantity' => 1))),
			'zero order id' => array('orderid' => 0, 'items' => array(array('productid' => 55, 'quantity' => 1))),
			'no items' => array('orderid' => 1001),
			'empty items' => array('orderid' => 1001, 'items' => array()),
			'items not a list' => array('orderid' => 1001, 'items' => 'M'),
			'item not an object' => array('orderid' => 1001, 'items' => array(7)),
			'item without product id' => array('orderid' => 1001, 'items' => array(array('quantity' => 1))),
			'item with zero quantity' => array('orderid' => 1001, 'items' => array(array('productid' => 55, 'quantity' => 0))),
		);

		foreach ($bodies as $name => $body) {
			$this->assertRestError(400, function () use ($body) {
				$this->api()->orderCompleted($body);
			});
		}
	}

	/**
	 * A product nobody mapped is a 404 that names the webshop product id.
	 *
	 * @return void
	 */
	public function testUnmappedProductIsRefusedWithItsIdInTheMessage()
	{
		global $db;
		$db = $this->savdb;

		$this->makeWebshopWarehouse('unmapped');
		$unknown = $this->newNopId();

		$error = $this->assertRestError(404, function () use ($unknown) {
			$this->api()->orderCompleted(array('orderid' => $this->newNopId(), 'items' => array(array('productid' => $unknown, 'quantity' => 1))));
		});

		$this->assertStringContainsString((string) $unknown, $error->getMessage());
	}

	/**
	 * A size no variant carries is a 404 and moves nothing.
	 *
	 * @return void
	 */
	public function testUnknownSizeIsRefused()
	{
		global $db;
		$db = $this->savdb;

		$warehouse = $this->makeWebshopWarehouse('badsize');
		$externalid = $this->newNopId();
		$parent = $this->makeProduct('badsize', $externalid);
		$children = $this->makeVariants($parent, 'badsize', array('M'));
		$this->seed($children['M'], $warehouse, 5);

		$this->assertRestError(404, function () use ($externalid) {
			$this->api()->orderCompleted(array(
				'orderid' => $this->newNopId(),
				'items' => array(array('productid' => $externalid, 'attribute' => 'Size', 'attributeValue' => 'XXL', 'quantity' => 1)),
			));
		});
		$this->assertEquals(5, $this->stockOf($children['M'], $warehouse));
	}

	/**
	 * Not enough stock in the webshop warehouse is a 409 that speaks of stock, and moves nothing.
	 *
	 * @return void
	 */
	public function testInsufficientStockIsRefusedWith409()
	{
		global $db;
		$db = $this->savdb;

		$warehouse = $this->makeWebshopWarehouse('lowstock');
		$externalid = $this->newNopId();
		$product = $this->makeProduct('lowstock', $externalid);
		$this->seed($product->id, $warehouse, 1);

		$error = $this->assertRestError(409, function () use ($externalid) {
			$this->api()->orderCompleted(array('orderid' => $this->newNopId(), 'items' => array(array('productid' => $externalid, 'quantity' => 3))));
		});

		$this->assertStringContainsStringIgnoringCase('stock', $error->getMessage());
		$this->assertEquals(1, $this->stockOf($product->id, $warehouse));
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
		$nobody = new User($db);

		$this->assertRestError(403, function () use ($nobody) {
			$this->api($nobody)->orderCompleted(array('orderid' => 1001, 'items' => array(array('productid' => 55, 'quantity' => 1))));
		});
	}

	/**
	 * The legacy bare list of lines still works, with its own answer, and shares the order
	 * idempotency with the new form.
	 *
	 * @return void
	 */
	public function testLegacyBareListStillWorksWithItsOwnAnswer()
	{
		global $db;
		$db = $this->savdb;

		$warehouse = $this->makeWebshopWarehouse('legacy');
		$externalid = $this->newNopId();
		$parent = $this->makeProduct('legacy', $externalid);
		$children = $this->makeVariants($parent, 'legacy', array('M'));
		$this->seed($children['M'], $warehouse, 5);
		$orderid = $this->newNopId();

		$response = $this->api()->orderCompleted(array(
			array('orderid' => $orderid, 'productid' => $externalid, 'attribute' => 'Size', 'attributeValue' => 'M', 'quantity' => 2),
		));

		$this->assertCount(1, $response['orders']);
		$this->assertSame($orderid, $response['orders'][0]['order_id']);
		$this->assertFalse($response['orders'][0]['already_completed']);
		$this->assertEquals(3, $response['orders'][0]['items'][0]['stock_in_webshop_warehouse']);

		$replay = $this->api()->orderCompleted(array(
			'orderid' => $orderid,
			'items' => array(array('productid' => $externalid, 'attribute' => 'Size', 'attributeValue' => 'M', 'quantity' => 2)),
		));
		$this->assertTrue($replay['already_completed'], 'The two forms share one record of what was applied');
		$this->assertEquals(3, $this->stockOf($children['M'], $warehouse));
	}
}
