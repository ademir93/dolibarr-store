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
 * \file    htdocs/custom/nopcommerce/test/NopCommerceApiStatusTest.php
 * \ingroup nopcommerce
 * \brief   Tests of the status, transfer read and removed-endpoint behaviour, through the REST methods.
 */

require_once dirname(__FILE__).'/NopCommerceApiTestCase.php';
require_once dirname(__FILE__).'/../class/nopcommercetransfer.class.php';

/**
 * Class NopCommerceApiStatusTest
 *
 * @backupGlobals disabled
 * @backupStaticAttributes enabled
 * @remarks	backupGlobals must be disabled to have db,conf,user and lang not erased.
 */
class NopCommerceApiStatusTest extends NopCommerceApiTestCase
{
	/**
	 * The status answers with the build version, the schema health and a UTC timestamp with a
	 * numeric offset, as the frozen contract says.
	 *
	 * @return void
	 */
	public function testStatusReportsVersionSchemaHealthAndOffsetTimestamp()
	{
		global $db;
		$db = $this->savdb;

		$warehouseid = $this->makeWebshopWarehouse('status');

		$status = $this->api()->status();

		$this->assertSame('ok', $status['status']);
		$this->assertSame('1.1', $status['module_version'], 'The contract changed, so the build version moved');
		$this->assertSame($warehouseid, $status['webshop_warehouse_id']);
		$this->assertIsInt($status['pending_transfers']);
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', $status['server_time'], 'Timestamps carry a numeric offset, not a Z suffix');
		$this->assertTrue($status['schema_ok'], 'A complete schema reports healthy, missing: '.implode(', ', $status['schema_missing']));
		$this->assertSame(array(), $status['schema_missing']);
	}

	/**
	 * The timestamps of a transfer are UTC with a numeric offset too.
	 *
	 * @return void
	 */
	public function testTransferTimestampCarriesANumericOffset()
	{
		global $db, $user;
		$db = $this->savdb;

		$source = $this->makeWebshopWarehouse('tr-src');
		$dest = $this->makeWebshopWarehouse('tr-dst');
		$product = $this->makeProduct('tr', 0);

		$transfer = new NopCommerceTransfer($db);
		$transfer->fk_warehouse_source = $source;
		$transfer->fk_warehouse_destination = $dest;
		$this->assertGreaterThan(0, $transfer->create($user), 'create failed: '.$transfer->error);
		$this->assertGreaterThan(0, $transfer->addLine($user, $product->id, 1.0, ''), 'addLine failed: '.$transfer->error);

		$payload = $this->api()->getTransfer($transfer->id);

		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', $payload['date_creation']);
	}

	/**
	 * The superseded sold-lines endpoint is gone: there is one sale contract.
	 *
	 * @return void
	 */
	public function testSoldLinesEndpointIsGone()
	{
		$this->assertFalse(method_exists($this->api(), 'sales'), 'POST /sales was superseded by /order_completed and removed');
	}
}
