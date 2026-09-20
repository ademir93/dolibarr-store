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
 * \file    htdocs/custom/nopcommerce/test/NopCommerceSchemaTest.php
 * \ingroup nopcommerce
 * \brief   Tests of the schema health check and repair.
 *
 * This is the one place the tests do not enter through the REST methods: the unhealthy case needs
 * a table or column to be absent, and the transaction harness cannot roll back DDL. The check is
 * therefore exercised directly with a requirement list naming things that do not exist, and the
 * repair is run once, on the real schema, where it is idempotent and only ever adds.
 */

require_once dirname(__FILE__).'/NopCommerceApiTestCase.php';
require_once dirname(__FILE__).'/../class/nopcommerceschema.class.php';

/**
 * Class NopCommerceSchemaTest
 *
 * @backupGlobals disabled
 * @backupStaticAttributes enabled
 * @remarks	backupGlobals must be disabled to have db,conf,user and lang not erased.
 */
class NopCommerceSchemaTest extends NopCommerceApiTestCase
{
	/**
	 * A missing table and a missing column are both named, prefixed the way the database has them.
	 *
	 * @return void
	 */
	public function testMissingTableAndColumnAreNamed()
	{
		global $db;
		$db = $this->savdb;

		$schema = new NopCommerceSchema($db);

		$result = $schema->check(array(
			'tables' => array(
				'nopcommerce_transfer' => array('status', 'a_column_that_does_not_exist'),
				'nop_table_that_does_not_exist' => array('rowid'),
			),
			'extrafields' => array(),
		));

		$this->assertFalse($result['ok']);
		$this->assertContains($db->prefix().'nopcommerce_transfer.a_column_that_does_not_exist (column)', $result['missing']);
		$this->assertContains($db->prefix().'nop_table_that_does_not_exist (table)', $result['missing']);
		$this->assertCount(2, $result['missing'], 'Only what is really absent is named');
	}

	/**
	 * A missing extrafield definition is named too, because order lines resolve through it.
	 *
	 * @return void
	 */
	public function testMissingExtrafieldIsNamed()
	{
		global $db;
		$db = $this->savdb;

		$schema = new NopCommerceSchema($db);

		$result = $schema->check(array(
			'tables' => array(),
			'extrafields' => array('product' => array('nopcommerce_field_that_does_not_exist')),
		));

		$this->assertFalse($result['ok']);
		$this->assertSame(array('nopcommerce_field_that_does_not_exist (product extrafield)'), $result['missing']);
	}

	/**
	 * Repair completes the schema without touching history, and running it again changes nothing.
	 *
	 * @return void
	 */
	public function testRepairCompletesTheSchemaAndIsRepeatable()
	{
		global $db;
		$db = $this->savdb;

		$schema = new NopCommerceSchema($db);

		$this->assertGreaterThan(0, $schema->repair(), 'First repair failed: '.$schema->error);
		$this->assertTrue($schema->check()['ok'], 'The schema is healthy after repair, missing: '.implode(', ', $schema->check()['missing']));

		$this->assertGreaterThan(0, $schema->repair(), 'A second repair must be harmless: '.$schema->error);
		$this->assertTrue($schema->check()['ok']);
	}
}
