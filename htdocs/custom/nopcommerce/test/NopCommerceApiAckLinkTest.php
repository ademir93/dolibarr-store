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
 * \file    htdocs/custom/nopcommerce/test/NopCommerceApiAckLinkTest.php
 * \ingroup nopcommerce
 * \brief   Tests that POST /transfers/{id}/ack links the Dolibarr product to its nopCommerce product.
 *
 * Nobody types the nopCommerce external id by hand: the acknowledgement of a pulled transfer
 * stores it, and the orders of that product then find their way back to Dolibarr.
 */

require_once dirname(__FILE__).'/NopCommerceApiTestCase.php';
dol_include_once('/nopcommerce/class/nopcommercetransfer.class.php');
dol_include_once('/nopcommerce/class/nopcommercesync.class.php');

/**
 * Class NopCommerceApiAckLinkTest
 *
 * @backupGlobals disabled
 * @backupStaticAttributes enabled
 * @remarks	backupGlobals must be disabled to have db,conf,user and lang not erased.
 */
class NopCommerceApiAckLinkTest extends NopCommerceApiTestCase
{
	/**
	 * A transferred variant links its parent: the webshop holds one product per parent. A sale
	 * of that product then takes the sold size out of the webshop warehouse.
	 *
	 * @return void
	 */
	public function testAckOfAVariantLinksTheParentAndTheSaleIsMatched()
	{
		global $db;
		$db = $this->savdb;

		$webshop = $this->makeWebshopWarehouse('ackvar');
		$source = $this->makeSourceWarehouse('ackvar');
		$parent = $this->makeProduct('ackvar', 0);
		$children = $this->makeVariants($parent, 'ackvar', array('M', 'L'));
		$this->seed($children['M'], $source, 5);

		$nopproductid = $this->newNopId();
		$this->pullAndAck($source, $webshop, $children['M'], 5, $nopproductid);

		$this->assertSame($nopproductid, $this->externalIdOf($parent->id), 'The parent carries the nopCommerce product id');
		$this->assertEquals(5, $this->stockOf($children['M'], $webshop));

		$response = $this->api()->orderCompleted(array(
			'orderid' => $this->newNopId(),
			'items' => array(
				array('productid' => $nopproductid, 'attribute' => 'Size', 'attributeValue' => 'M', 'quantity' => 2),
			),
		));

		$this->assertSame($children['M'], $response['items'][0]['fk_product']);
		$this->assertEquals(3, $this->stockOf($children['M'], $webshop));
	}

	/**
	 * A product without variants is linked itself.
	 *
	 * @return void
	 */
	public function testAckOfASimpleProductLinksTheProduct()
	{
		global $db;
		$db = $this->savdb;

		$webshop = $this->makeWebshopWarehouse('acksimple');
		$source = $this->makeSourceWarehouse('acksimple');
		$product = $this->makeProduct('acksimple', 0);
		$this->seed($product->id, $source, 4);

		$nopproductid = $this->newNopId();
		$this->pullAndAck($source, $webshop, $product->id, 4, $nopproductid);

		$this->assertSame($nopproductid, $this->externalIdOf($product->id));
	}

	/**
	 * When the webshop product was deleted and pulled again, the new id replaces the old one.
	 *
	 * @return void
	 */
	public function testANewWebshopIdReplacesTheOldOne()
	{
		global $db;
		$db = $this->savdb;

		$webshop = $this->makeWebshopWarehouse('ackrenew');
		$source = $this->makeSourceWarehouse('ackrenew');
		$product = $this->makeProduct('ackrenew', $this->newNopId());
		$this->seed($product->id, $source, 2);

		$nopproductid = $this->newNopId();
		$this->pullAndAck($source, $webshop, $product->id, 2, $nopproductid);

		$this->assertSame($nopproductid, $this->externalIdOf($product->id));
	}

	/**
	 * An id the webshop reuses for another product leaves the product that held it, so a sale
	 * never matches two products.
	 *
	 * @return void
	 */
	public function testAnIdHeldByAnotherProductMovesToTheAcknowledgedOne()
	{
		global $db;
		$db = $this->savdb;

		$nopproductid = $this->newNopId();
		$webshop = $this->makeWebshopWarehouse('ackmove');
		$source = $this->makeSourceWarehouse('ackmove');
		$old = $this->makeProduct('ackmove-old', $nopproductid);
		$new = $this->makeProduct('ackmove-new', 0);
		$this->seed($new->id, $source, 3);

		$this->pullAndAck($source, $webshop, $new->id, 3, $nopproductid);

		$this->assertSame($nopproductid, $this->externalIdOf($new->id));
		$this->assertSame(0, $this->externalIdOf($old->id), 'The previous holder loses the id');

		$response = $this->api()->orderCompleted(array(
			'orderid' => $this->newNopId(),
			'items' => array(
				array('productid' => $nopproductid, 'quantity' => 1),
			),
		));

		$this->assertSame((int) $new->id, $response['items'][0]['fk_product']);
	}

	/**
	 * Build a source warehouse and return its id.
	 *
	 * @param	string	$suffix		Suffix making the label unique
	 * @return	int					Warehouse id
	 */
	private function makeSourceWarehouse($suffix)
	{
		global $db, $user;

		$warehouse = new Entrepot($db);
		$warehouse->initAsSpecimen();
		$warehouse->label .= ' phpunit nop ack source '.$suffix;
		$warehouse->description .= ' phpunit nop ack source '.$suffix;
		$id = $warehouse->create($user);
		$this->assertGreaterThan(0, $id, 'Failed to create warehouse '.$suffix.': '.$warehouse->error);

		return $id;
	}

	/**
	 * Build a pending transfer of one line, pull it and acknowledge it the way the webshop does.
	 *
	 * @param	int		$source			Source warehouse id
	 * @param	int		$webshop		Webshop warehouse id
	 * @param	int		$productid		Transferred product
	 * @param	float	$qty			Quantity
	 * @param	int		$nopproductid	Product id the webshop reports
	 * @return	void
	 */
	private function pullAndAck($source, $webshop, $productid, $qty, $nopproductid)
	{
		global $db, $user;

		$transfer = new NopCommerceTransfer($db);
		$transfer->fk_warehouse_source = $source;
		$transfer->fk_warehouse_destination = $webshop;
		$this->assertGreaterThan(0, $transfer->create($user), 'create failed: '.$transfer->error);
		$this->assertGreaterThan(0, $transfer->addLine($user, $productid, $qty, ''), 'addLine failed: '.$transfer->error);
		$this->assertGreaterThan(0, $transfer->validate($user, 0, 'NOP-A'.mt_rand(100000, 999999)), 'validate failed: '.$transfer->error);
		$this->assertGreaterThan(0, $transfer->fetchLines(), 'Failed to load the lines');

		$sync = new NopCommerceSync($db);
		$token = $sync->issuePullToken($transfer);
		$this->assertNotEmpty($token, 'Failed to issue a pull token: '.$sync->error);

		$response = $this->api()->ack($transfer->id, array(
			'pull_token' => $token,
			'success' => true,
			'lines' => array(
				array('line_id' => $transfer->lines[0]->id, 'success' => true, 'nop_product_id' => $nopproductid, 'nop_combination_id' => $this->newNopId()),
			),
		));

		$this->assertTrue($response['sync_flag'], 'The transfer must be confirmed');
	}

	/**
	 * Return the nopCommerce external id stored on a product, 0 when none.
	 *
	 * @param	int		$productid	Product id
	 * @return	int
	 */
	private function externalIdOf($productid)
	{
		global $db;

		$resql = $db->query("SELECT nopcommerce_external_id FROM ".MAIN_DB_PREFIX."product_extrafields WHERE fk_object = ".((int) $productid));
		$this->assertNotFalse($resql, 'Query failed: '.$db->lasterror());
		$obj = $db->fetch_object($resql);

		return $obj ? (int) $obj->nopcommerce_external_id : 0;
	}
}
