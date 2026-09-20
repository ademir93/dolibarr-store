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
 * \file    htdocs/custom/nopcommerce/test/NopCommerceReadinessTest.php
 * \ingroup nopcommerce
 * \brief   Tests of the read-only readiness report shown on the setup page.
 *
 * The report has no REST method, so like the schema check it is exercised directly. The
 * instance holds other products, so every assertion looks for the products the test created.
 */

require_once dirname(__FILE__).'/NopCommerceApiTestCase.php';
require_once dirname(__FILE__).'/../class/nopcommercereadiness.class.php';

/**
 * Class NopCommerceReadinessTest
 *
 * @backupGlobals disabled
 * @backupStaticAttributes enabled
 * @remarks	backupGlobals must be disabled to have db,conf,user and lang not erased.
 */
class NopCommerceReadinessTest extends NopCommerceApiTestCase
{
	/**
	 * A product with stock in the webshop warehouse and no nopCommerce id is listed; a mapped one,
	 * and a variant whose parent is mapped, are not.
	 *
	 * @return void
	 */
	public function testUnmappedProductWithStockIsListed()
	{
		global $db;
		$db = $this->savdb;

		$warehouse = $this->makeWebshopWarehouse('unmapped');
		$unmapped = $this->makeProduct('unmapped', 0);
		$mapped = $this->makeProduct('mapped', $this->newNopId());
		$parent = $this->makeProduct('parent', $this->newNopId());
		$children = $this->makeVariants($parent, 'parent', array('M'));
		foreach (array($unmapped->id, $mapped->id, $children['M']) as $productid) {
			$this->seed($productid, $warehouse, 2);
		}

		$report = (new NopCommerceReadiness($db))->report(1000);
		$listed = array_column($report['unmapped_with_stock'], 'product_id');

		$this->assertContains((string) $unmapped->id, array_map('strval', $listed));
		$this->assertNotContains((string) $mapped->id, array_map('strval', $listed));
		$this->assertNotContains((string) $children['M'], array_map('strval', $listed), 'A variant resolves through its mapped parent');
	}

	/**
	 * A nopCommerce id carried by two products is listed with both of them.
	 *
	 * @return void
	 */
	public function testSharedExternalIdIsListed()
	{
		global $db;
		$db = $this->savdb;

		$this->makeWebshopWarehouse('shared');
		$externalid = $this->newNopId();
		$first = $this->makeProduct('shared-a', $externalid);
		$second = $this->makeProduct('shared-b', $externalid);

		$report = (new NopCommerceReadiness($db))->report(1000);

		$found = null;
		foreach ($report['shared_external_ids'] as $row) {
			if ((int) $row['external_id'] === $externalid) {
				$found = $row;
			}
		}
		$this->assertNotNull($found, 'The shared id is reported');
		$this->assertSame(2, $found['products']);
		$this->assertStringContainsString($first->ref, $found['refs']);
		$this->assertStringContainsString($second->ref, $found['refs']);
	}

	/**
	 * A mapped product with no stock in the webshop warehouse is listed; one with stock is not.
	 *
	 * @return void
	 */
	public function testMappedProductWithoutStockIsListed()
	{
		global $db;
		$db = $this->savdb;

		$warehouse = $this->makeWebshopWarehouse('nostock');
		$empty = $this->makeProduct('empty', $this->newNopId());
		$stocked = $this->makeProduct('stocked', $this->newNopId());
		$this->seed($stocked->id, $warehouse, 3);

		$report = (new NopCommerceReadiness($db))->report(1000);
		$listed = array_map('strval', array_column($report['mapped_without_stock'], 'product_id'));

		$this->assertContains((string) $empty->id, $listed);
		$this->assertNotContains((string) $stocked->id, $listed);
	}

	/**
	 * An attribute value carried by several variants of one product is listed, with its attribute
	 * so the operator can tell a colour from a size.
	 *
	 * @return void
	 */
	public function testValueSharedByVariantsIsListedWithItsAttribute()
	{
		global $db;
		$db = $this->savdb;

		$this->makeWebshopWarehouse('sharedvalue');
		$parent = $this->makeProduct('sharedvalue', $this->newNopId());
		$this->makeVariants($parent, 'sharedvalue', array('M', 'L'), 'Blue');

		$report = (new NopCommerceReadiness($db))->report(1000);

		$found = array_filter($report['shared_variant_values'], function ($row) use ($parent) {
			return (int) $row['parent_id'] === (int) $parent->id;
		});
		$this->assertCount(1, $found, 'Only the shared colour is listed, not the sizes');
		$row = array_values($found)[0];
		$this->assertSame('Colour', $row['attribute']);
		$this->assertSame('Blue', $row['value']);
		$this->assertEquals(2, $row['variants']);
	}
}
