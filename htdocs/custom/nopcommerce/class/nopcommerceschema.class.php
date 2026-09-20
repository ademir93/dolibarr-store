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
 * \file        htdocs/custom/nopcommerce/class/nopcommerceschema.class.php
 * \ingroup     nopcommerce
 * \brief       Checks that the tables, columns and extrafield the module needs exist, and completes them.
 */

/**
 * Health check and repair of the module's database schema.
 *
 * Dolibarr creates a module's tables only when the module is enabled, and the deploy pipeline
 * does not migrate module tables. An instance that enabled the module before a table or column
 * was added therefore lacks it until someone disables and enables the module. This class makes
 * that visible and fixes it without touching any existing data.
 */
class NopCommerceSchema
{
	/**
	 * Name of the product extrafield holding the nopCommerce product id.
	 */
	const EXTERNAL_ID_EXTRAFIELD = 'nopcommerce_external_id';

	/**
	 * @var DoliDB Database handler
	 */
	public $db;

	/**
	 * @var string Error message of the last failed call
	 */
	public $error = '';

	/**
	 * @var string[] Error messages of the last failed call
	 */
	public $errors = array();

	/**
	 * Constructor
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct(DoliDB $db)
	{
		$this->db = $db;
	}

	/**
	 * Return what the transfer, order and reversal flows need to exist.
	 *
	 * Table names are given without the database prefix. Only the columns the code reads or
	 * writes are listed, not every column of the table.
	 *
	 * @return array{tables:array<string,string[]>,extrafields:array<string,string[]>}
	 */
	public static function requirements()
	{
		return array(
			'tables' => array(
				'nopcommerce_transfer' => array('rowid', 'fk_warehouse_source', 'fk_warehouse_destination', 'status', 'sync_flag', 'origin', 'pull_token'),
				'nopcommerce_transferline' => array('rowid', 'fk_nopcommercetransfer', 'fk_product', 'qty', 'nop_product_id', 'nop_combination_id'),
				'nop_order_completed' => array('rowid', 'nop_order_id'),
				'nop_order_complete_items' => array('rowid', 'fk_nop_order_completed', 'fk_product', 'nop_external_id', 'qty', 'qty_reversed'),
				'nop_order_reversal' => array('rowid', 'fk_nop_order_completed', 'nop_reversal_id'),
				'nop_order_reversal_items' => array('rowid', 'fk_nop_order_reversal', 'fk_product', 'nop_external_id', 'qty'),
				'product_extrafields' => array(self::EXTERNAL_ID_EXTRAFIELD),
			),
			'extrafields' => array(
				'product' => array(self::EXTERNAL_ID_EXTRAFIELD),
			),
		);
	}

	/**
	 * Check the live database against a list of requirements.
	 *
	 * @param	array{tables:array<string,string[]>,extrafields:array<string,string[]>}|null	$requirements	What to check, the module's own requirements by default
	 * @return	array{ok:bool,missing:string[]}		ok is true when nothing is missing; missing names each absent item
	 */
	public function check($requirements = null)
	{
		if ($requirements === null) {
			$requirements = self::requirements();
		}

		$missing = array();

		foreach ($requirements['tables'] as $table => $columns) {
			$fullname = $this->db->prefix().$table;
			$found = $this->listColumns($fullname);

			if ($found === null) {
				$missing[] = $fullname.' (table)';
				continue;
			}
			foreach ($columns as $column) {
				if (!in_array($column, $found, true)) {
					$missing[] = $fullname.'.'.$column.' (column)';
				}
			}
		}

		foreach ($requirements['extrafields'] as $elementtype => $names) {
			foreach ($names as $name) {
				if (!$this->extrafieldExists($elementtype, $name)) {
					$missing[] = $name.' ('.$elementtype.' extrafield)';
				}
			}
		}

		return array('ok' => empty($missing), 'missing' => $missing);
	}

	/**
	 * Complete the schema: run the module's table definitions again and make sure the
	 * extrafield exists.
	 *
	 * The definitions only create what is absent, and the errors Dolibarr tolerates when
	 * re-running them (table, column or key already there) are ignored, so this is safe to
	 * run repeatedly. It never drops a table or deletes a row.
	 *
	 * @return	int		1 if the schema was completed, -1 on error (see $this->error)
	 */
	public function repair()
	{
		include_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';

		$this->error = '';
		$this->errors = array();

		$dir = dirname(__FILE__).'/../sql/';

		$files = scandir($dir);
		if ($files === false) {
			$this->error = 'Cannot read the module table definitions';
			$this->errors[] = $this->error;
			return -1;
		}
		sort($files);

		// Same order as when the module is enabled: tables first, then their keys.
		$failed = array();
		foreach (array(false, true) as $keyfiles) {
			foreach ($files as $file) {
				if (substr($file, 0, 4) != 'llx_' || !preg_match('/\.sql$/i', $file)) {
					continue;
				}
				if ((bool) preg_match('/\.key\.sql$/i', $file) !== $keyfiles) {
					continue;
				}
				if (run_sql($dir.$file, 1, 0, 1) <= 0) {
					$failed[] = $file;
				}
			}
		}

		if (!empty($failed)) {
			$this->error = 'Failed to run: '.implode(', ', $failed);
			$this->errors[] = $this->error;
			return -1;
		}

		if ($this->ensureExternalIdExtrafield() < 0) {
			return -1;
		}

		return 1;
	}

	/**
	 * Create the product extrafield holding the nopCommerce product id, unless it exists.
	 *
	 * It is an extrafield so the product create and edit pages show and save it without
	 * touching core files.
	 *
	 * @return	int		1 if it exists or was created, -1 on error
	 */
	public function ensureExternalIdExtrafield()
	{
		if ($this->extrafieldExists('product', self::EXTERNAL_ID_EXTRAFIELD)) {
			return 1;
		}

		include_once DOL_DOCUMENT_ROOT.'/core/class/extrafields.class.php';
		$extrafields = new ExtraFields($this->db);
		$result = $extrafields->addExtraField(
			self::EXTERNAL_ID_EXTRAFIELD,
			'NopCommerceExternalId',
			'int',
			1,
			'11',
			'product',
			0,
			0,
			'',
			'',
			1,
			'',
			1,
			'NopCommerceExternalIdTooltip',
			'',
			'',
			'nopcommerce@nopcommerce',
			'1'
		);
		if ($result < 0) {
			$this->error = $extrafields->error;
			$this->errors[] = $this->error;
			return -1;
		}

		return 1;
	}

	/**
	 * List the columns of a table.
	 *
	 * @param	string			$table	Table name, with the database prefix
	 * @return	string[]|null			Column names, null when the table does not exist
	 */
	protected function listColumns($table)
	{
		$schema = ($this->db->type == 'pgsql' ? 'current_schema()' : 'DATABASE()');

		$sql = "SELECT column_name AS colname FROM information_schema.columns";
		$sql .= " WHERE table_schema = ".$schema." AND table_name = '".$this->db->escape($table)."'";

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			$this->errors[] = $this->error;
			return null;
		}

		$columns = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$columns[] = $obj->colname;
		}

		return empty($columns) ? null : $columns;
	}

	/**
	 * Tell whether an extrafield is defined.
	 *
	 * @param	string	$elementtype	Element the extrafield belongs to, for example product
	 * @param	string	$name			Extrafield name
	 * @return	bool
	 */
	protected function extrafieldExists($elementtype, $name)
	{
		$sql = "SELECT rowid FROM ".$this->db->prefix()."extrafields";
		$sql .= " WHERE elementtype = '".$this->db->escape($elementtype)."' AND name = '".$this->db->escape($name)."'";

		$resql = $this->db->query($sql);

		return $resql && $this->db->num_rows($resql) > 0;
	}
}
