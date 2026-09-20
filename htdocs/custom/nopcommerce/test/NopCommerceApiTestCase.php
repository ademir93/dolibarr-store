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
 * \file    htdocs/custom/nopcommerce/test/NopCommerceApiTestCase.php
 * \ingroup nopcommerce
 * \brief   Shared base for the tests that enter through the module's REST methods.
 */

global $conf, $user, $langs, $db;

require_once dirname(__FILE__).'/../../../master.inc.php';
require_once dirname(__FILE__).'/../../../product/class/product.class.php';
require_once dirname(__FILE__).'/../../../product/stock/class/entrepot.class.php';
require_once dirname(__FILE__).'/../../../variants/class/ProductAttribute.class.php';
require_once dirname(__FILE__).'/../../../variants/class/ProductAttributeValue.class.php';
require_once dirname(__FILE__).'/../../../variants/class/ProductCombination.class.php';
require_once dirname(__FILE__).'/../../../../test/phpunit/CommonClassTest.class.php';

// The REST classes are normally loaded by Restler; load the same pieces by hand.
require_once DOL_DOCUMENT_ROOT.'/includes/restler/framework/Luracast/Restler/AutoLoader.php';
spl_autoload_register(Luracast\Restler\AutoLoader::instance());
require_once DOL_DOCUMENT_ROOT.'/api/class/api.class.php';
require_once DOL_DOCUMENT_ROOT.'/api/class/api_access.class.php';
dol_include_once('/nopcommerce/class/api_nopcommerce.class.php');

if (empty($user->id)) {
	$user->fetch(1);
	$user->loadRights();
}
$conf->global->MAIN_DISABLE_ALL_MAILS = 1;

use Luracast\Restler\RestException;

/**
 * Base class for the tests that call the NopCommerce REST methods the way the webshop does:
 * with a decoded JSON body, asserting on the array that comes back or the RestException thrown.
 *
 * Each test class rolls back the data it creates through the shared CommonClassTest harness.
 */
abstract class NopCommerceApiTestCase extends CommonClassTest
{
	/**
	 * Return the REST class, acting as the given user (the admin by default).
	 *
	 * @param	User|null	$actor	User the call runs as
	 * @return	NopCommerce
	 */
	protected function api($actor = null)
	{
		global $user;

		DolibarrApiAccess::$user = ($actor !== null ? $actor : $user);

		return new NopCommerce();
	}

	/**
	 * Call a REST method expecting it to be refused, and return the exception.
	 *
	 * @param	int			$code	Expected HTTP status code
	 * @param	callable	$call	Call that must throw
	 * @return	RestException
	 */
	protected function assertRestError($code, callable $call)
	{
		try {
			$call();
		} catch (RestException $e) {
			$this->assertSame($code, $e->getCode(), 'Unexpected status code, message: '.$e->getMessage());
			return $e;
		}

		$this->fail('Expected a RestException with code '.$code.' but the call succeeded');
	}

	/**
	 * Return an id unlikely to exist on either side.
	 *
	 * @return int
	 */
	protected function newNopId()
	{
		return mt_rand(900000000, 2000000000);
	}

	/**
	 * Build a warehouse, configure it as the webshop warehouse and return its id.
	 *
	 * @param	string	$suffix		Suffix making the label unique
	 * @return	int					Warehouse id
	 */
	protected function makeWebshopWarehouse($suffix)
	{
		global $conf, $db, $user;

		$warehouse = new Entrepot($db);
		$warehouse->initAsSpecimen();
		$warehouse->label .= ' phpunit nop api '.$suffix;
		$warehouse->description .= ' phpunit nop api '.$suffix;
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
	 * @param	int		$externalid		nopCommerce product id, or 0 for an unmapped product
	 * @return	Product					Created product
	 */
	protected function makeProduct($suffix, $externalid)
	{
		global $db, $user;

		$product = new Product($db);
		$product->initAsSpecimen();
		$product->ref .= ' phpunit nop api '.$suffix;
		$product->label .= ' phpunit nop api '.$suffix;
		if ($externalid > 0) {
			$product->array_options = array('options_nopcommerce_external_id' => $externalid);
		}
		$id = $product->create($user);
		$this->assertGreaterThan(0, $id, 'Failed to create product '.$suffix.': '.$product->error);

		return $product;
	}

	/**
	 * Build a Size attribute with the given values and one variant of the product per value.
	 * When a second attribute value is given, every variant also carries it (for example a
	 * single Colour), so the second attribute exists next to the size on the Dolibarr side.
	 *
	 * @param	Product			$parent			Parent product
	 * @param	string			$suffix			Suffix making the attribute ref unique
	 * @param	string[]		$values			Size values, for example S and M
	 * @param	string|null		$secondvalue	Value of a second attribute carried by every variant
	 * @return	array<string,int>				Id of the variant child product, keyed by size value
	 */
	protected function makeVariants(Product $parent, $suffix, array $values, $secondvalue = null)
	{
		global $db, $user;

		$attribute = new ProductAttribute($db);
		$attribute->ref = 'PHPUNITAPI'.strtoupper($suffix).mt_rand(1000, 9999);
		$attribute->label = 'Size';
		$attributeid = $attribute->create($user);
		$this->assertGreaterThan(0, $attributeid, 'Failed to create attribute: '.$attribute->error);

		$secondattributeid = 0;
		$secondvalueid = 0;
		if ($secondvalue !== null) {
			$second = new ProductAttribute($db);
			$second->ref = 'PHPUNITAPICOL'.strtoupper($suffix).mt_rand(1000, 9999);
			$second->label = 'Colour';
			$secondattributeid = $second->create($user);
			$this->assertGreaterThan(0, $secondattributeid, 'Failed to create second attribute: '.$second->error);

			$secondattributevalue = new ProductAttributeValue($db);
			$secondattributevalue->fk_product_attribute = $secondattributeid;
			$secondattributevalue->ref = $secondvalue;
			$secondattributevalue->value = $secondvalue;
			$secondvalueid = $secondattributevalue->create($user);
			$this->assertGreaterThan(0, $secondvalueid, 'Failed to create second value: '.$secondattributevalue->error);
		}

		$children = array();
		foreach ($values as $value) {
			$attributevalue = new ProductAttributeValue($db);
			$attributevalue->fk_product_attribute = $attributeid;
			$attributevalue->ref = $value;
			$attributevalue->value = $value;
			$valueid = $attributevalue->create($user);
			$this->assertGreaterThan(0, $valueid, 'Failed to create value '.$value.': '.$attributevalue->error);

			$combination = new ProductCombination($db);
			$sel = array($attributeid => $valueid);
			$impact = array($attributeid => array($valueid => array('weight' => 0, 'price' => 0)));
			if ($secondattributeid > 0) {
				$sel[$secondattributeid] = $secondvalueid;
				$impact[$secondattributeid] = array($secondvalueid => array('weight' => 0, 'price' => 0));
			}
			$this->assertGreaterThan(0, $combination->createProductCombination($user, $parent, $sel, $impact), 'Failed to create variant '.$value.': '.$combination->error);

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
	protected function seed($productid, $warehouseid, $qty)
	{
		global $db, $user;

		$product = new Product($db);
		$this->assertGreaterThan(0, $product->fetch($productid), 'Failed to load product '.$productid);
		$this->assertGreaterThan(0, $product->correct_stock($user, $warehouseid, $qty, 0, 'phpunit seed', 0, 'PHPUNITSEED'), 'Failed to seed stock');
	}

	/**
	 * Return the stock a product holds in a warehouse.
	 *
	 * @param	int		$productid		Product id
	 * @param	int		$warehouseid	Warehouse id
	 * @return	float
	 */
	protected function stockOf($productid, $warehouseid)
	{
		global $db;

		$sql = "SELECT reel FROM ".MAIN_DB_PREFIX."product_stock WHERE fk_product = ".((int) $productid)." AND fk_entrepot = ".((int) $warehouseid);
		$resql = $db->query($sql);
		$this->assertNotFalse($resql, 'Query failed: '.$db->lasterror());
		$obj = $db->fetch_object($resql);

		return $obj ? (float) $obj->reel : 0.0;
	}
}
