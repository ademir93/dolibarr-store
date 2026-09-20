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
 * \file        htdocs/custom/nopcommerce/class/nopcommercereadiness.class.php
 * \ingroup     nopcommerce
 * \brief       Read-only report of what would make a webshop sale fail to resolve in Dolibarr.
 */

/**
 * Report on how ready the catalogue is for webshop sales.
 *
 * A sale reaches Dolibarr as a nopCommerce product id and a size. It is applied only if the
 * product carrying that id resolves to a single variant that holds enough stock in the webshop
 * warehouse. This class lists, without changing anything, the products where that is unlikely
 * to hold, so they can be fixed before a customer hits the failure.
 *
 * A product resolves when it carries the nopCommerce id itself or, for a variant, when its
 * parent does.
 */
class NopCommerceReadiness
{
	/**
	 * @var DoliDB Database handler
	 */
	public $db;

	/**
	 * @var string Error message of the last failed call
	 */
	public $error = '';

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
	 * Build the report.
	 *
	 * @param	int		$limit		Maximum number of rows per section
	 * @return	array<string,mixed>|false	The report, false on a database error (see $this->error)
	 *
	 * The report holds:
	 *  - warehouse_id: the webshop warehouse it was built for
	 *  - unmapped_with_stock: products holding stock in the webshop warehouse that resolve to no nopCommerce id
	 *  - shared_external_ids: nopCommerce ids carried by several products that are not variants
	 *  - shared_variant_values: attribute values carried by several variants of one product
	 *  - mapped_without_stock: sellable products with a nopCommerce id that hold no stock in the webshop warehouse
	 */
	public function report($limit = 200)
	{
		$this->error = '';
		$limit = max(1, (int) $limit);
		$warehouseid = getDolGlobalInt('NOPCOMMERCE_WEBSHOP_WAREHOUSE_ID');

		$unmapped = $this->unmappedWithStock($warehouseid, $limit);
		$sharedids = $this->sharedExternalIds($limit);
		$sharedvalues = $this->sharedVariantValues($limit);
		$nostock = $this->mappedWithoutStock($warehouseid, $limit);

		if ($unmapped === false || $sharedids === false || $sharedvalues === false || $nostock === false) {
			return false;
		}

		return array(
			'warehouse_id' => $warehouseid,
			'unmapped_with_stock' => $unmapped,
			'shared_external_ids' => $sharedids,
			'shared_variant_values' => $sharedvalues,
			'mapped_without_stock' => $nostock,
		);
	}

	/**
	 * Products holding stock in the webshop warehouse that resolve to no nopCommerce id.
	 *
	 * @param	int		$warehouseid	Webshop warehouse
	 * @param	int		$limit			Maximum number of rows
	 * @return	array<int,array<string,mixed>>|false
	 */
	protected function unmappedWithStock($warehouseid, $limit)
	{
		if ($warehouseid <= 0) {
			return array();
		}

		$sql = "SELECT p.rowid as product_id, p.ref, p.label, ps.reel as stock";
		$sql .= " FROM ".$this->db->prefix()."product_stock as ps";
		$sql .= " INNER JOIN ".$this->db->prefix()."product as p ON p.rowid = ps.fk_product";
		$sql .= " LEFT JOIN ".$this->db->prefix()."product_extrafields as e ON e.fk_object = p.rowid";
		$sql .= " LEFT JOIN ".$this->db->prefix()."product_attribute_combination as pc ON pc.fk_product_child = p.rowid";
		$sql .= " LEFT JOIN ".$this->db->prefix()."product_extrafields as pe ON pe.fk_object = pc.fk_product_parent";
		$sql .= " WHERE ps.fk_entrepot = ".((int) $warehouseid)." AND ps.reel > 0";
		$sql .= " AND p.entity IN (".getEntity('product').")";
		$sql .= " AND COALESCE(e.nopcommerce_external_id, 0) = 0 AND COALESCE(pe.nopcommerce_external_id, 0) = 0";
		$sql .= " ORDER BY p.ref";
		$sql .= $this->db->plimit($limit);

		return $this->fetchRows($sql);
	}

	/**
	 * nopCommerce ids carried by several products that are not variants.
	 *
	 * Variants clone their parent's id, so they are set aside: only the products a sale could
	 * land on directly count.
	 *
	 * @param	int		$limit		Maximum number of ids
	 * @return	array<int,array<string,mixed>>|false	One row per id: external_id, products (count), refs
	 */
	protected function sharedExternalIds($limit)
	{
		$sql = "SELECT e.nopcommerce_external_id as external_id, p.ref";
		$sql .= " FROM ".$this->db->prefix()."product_extrafields as e";
		$sql .= " INNER JOIN ".$this->db->prefix()."product as p ON p.rowid = e.fk_object";
		$sql .= " LEFT JOIN ".$this->db->prefix()."product_attribute_combination as pc ON pc.fk_product_child = e.fk_object";
		$sql .= " WHERE pc.rowid IS NULL AND e.nopcommerce_external_id > 0";
		$sql .= " AND p.entity IN (".getEntity('product').")";
		$sql .= " ORDER BY e.nopcommerce_external_id, p.ref";

		$rows = $this->fetchRows($sql);
		if ($rows === false) {
			return false;
		}

		$byid = array();
		foreach ($rows as $row) {
			$byid[(int) $row['external_id']][] = $row['ref'];
		}

		$shared = array();
		foreach ($byid as $externalid => $refs) {
			if (count($refs) > 1) {
				$shared[] = array('external_id' => $externalid, 'products' => count($refs), 'refs' => implode(', ', $refs));
			}
		}

		return array_slice($shared, 0, $limit);
	}

	/**
	 * Attribute values carried by several variants of one product.
	 *
	 * A sale that sends such a value cannot pick one variant by the value alone. Only the
	 * attribute the shop sells by matters, and Dolibarr does not know which one that is, so a
	 * value shared under another attribute (a colour, say) is listed too: check the attribute
	 * column.
	 *
	 * @param	int		$limit		Maximum number of rows
	 * @return	array<int,array<string,mixed>>|false	One row per shared value: parent_id, ref, attribute, value, variants
	 */
	protected function sharedVariantValues($limit)
	{
		$sql = "SELECT c.fk_product_parent as parent_id, p.ref, pa.label as attribute, pav.value as value, COUNT(DISTINCT c.fk_product_child) as variants";
		$sql .= " FROM ".$this->db->prefix()."product_attribute_combination as c";
		$sql .= " INNER JOIN ".$this->db->prefix()."product_attribute_combination2val as c2v ON c2v.fk_prod_combination = c.rowid";
		$sql .= " INNER JOIN ".$this->db->prefix()."product_attribute as pa ON pa.rowid = c2v.fk_prod_attr";
		$sql .= " INNER JOIN ".$this->db->prefix()."product_attribute_value as pav ON pav.rowid = c2v.fk_prod_attr_val";
		$sql .= " INNER JOIN ".$this->db->prefix()."product as p ON p.rowid = c.fk_product_parent";
		$sql .= " WHERE c.entity IN (".getEntity('product').")";
		$sql .= " GROUP BY c.fk_product_parent, p.ref, pa.rowid, pa.label, pav.rowid, pav.value";
		$sql .= " HAVING COUNT(DISTINCT c.fk_product_child) > 1";
		$sql .= " ORDER BY p.ref, pa.label, pav.value";
		$sql .= $this->db->plimit($limit);

		return $this->fetchRows($sql);
	}

	/**
	 * Sellable products with a nopCommerce id that hold no stock in the webshop warehouse.
	 *
	 * Only products a sale lands on are listed: a product that has variants is not, its variants are.
	 *
	 * @param	int		$warehouseid	Webshop warehouse
	 * @param	int		$limit			Maximum number of rows
	 * @return	array<int,array<string,mixed>>|false
	 */
	protected function mappedWithoutStock($warehouseid, $limit)
	{
		if ($warehouseid <= 0) {
			return array();
		}

		$sql = "SELECT p.rowid as product_id, p.ref, p.label, COALESCE(NULLIF(e.nopcommerce_external_id, 0), NULLIF(pe.nopcommerce_external_id, 0)) as external_id";
		$sql .= " FROM ".$this->db->prefix()."product as p";
		$sql .= " LEFT JOIN ".$this->db->prefix()."product_extrafields as e ON e.fk_object = p.rowid";
		$sql .= " LEFT JOIN ".$this->db->prefix()."product_attribute_combination as pc ON pc.fk_product_child = p.rowid";
		$sql .= " LEFT JOIN ".$this->db->prefix()."product_extrafields as pe ON pe.fk_object = pc.fk_product_parent";
		$sql .= " LEFT JOIN ".$this->db->prefix()."product_attribute_combination as child ON child.fk_product_parent = p.rowid";
		$sql .= " LEFT JOIN ".$this->db->prefix()."product_stock as ps ON ps.fk_product = p.rowid AND ps.fk_entrepot = ".((int) $warehouseid);
		$sql .= " WHERE child.rowid IS NULL AND p.tosell = 1 AND p.entity IN (".getEntity('product').")";
		$sql .= " AND COALESCE(NULLIF(e.nopcommerce_external_id, 0), NULLIF(pe.nopcommerce_external_id, 0)) IS NOT NULL";
		$sql .= " AND COALESCE(ps.reel, 0) <= 0";
		$sql .= " ORDER BY p.ref";
		$sql .= $this->db->plimit($limit);

		return $this->fetchRows($sql);
	}

	/**
	 * Run a query and return its rows as arrays.
	 *
	 * @param	string	$sql	Query
	 * @return	array<int,array<string,mixed>>|false	Rows, false on a database error
	 */
	protected function fetchRows($sql)
	{
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return false;
		}

		$rows = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$rows[] = (array) $obj;
		}

		return $rows;
	}
}
