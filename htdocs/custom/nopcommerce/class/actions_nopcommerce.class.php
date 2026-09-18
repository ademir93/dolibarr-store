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
 * \file    htdocs/custom/nopcommerce/class/actions_nopcommerce.class.php
 * \ingroup nopcommerce
 * \brief   Hooks of the nopCommerce integration module.
 */

dol_include_once('/nopcommerce/class/nopcommercecapture.class.php');


/**
 * Hook class of the nopCommerce integration.
 */
class ActionsNopCommerce
{
	/**
	 * @var DoliDB Database handler
	 */
	public $db;

	/**
	 * @var string Error code or message
	 */
	public $error = '';

	/**
	 * @var string[] Errors
	 */
	public $errors = array();

	/**
	 * @var array<string,mixed> Hook results, propagated to $hookmanager->resArray
	 */
	public $results = array();

	/**
	 * @var ?string String printed by executeHooks() immediately after return
	 */
	public $resprints;

	/**
	 * Constructor
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Note that the native stock transfer page is about to move stock between the two
	 * warehouses this module is configured for, so the trigger can record the product
	 * once the stock movements exist.
	 *
	 * This only parks an intent. It never changes $action and never takes over the native
	 * flow, and it performs no writes: by the time this runs, product.php has not moved
	 * any stock yet and there are no movement rows to reference.
	 *
	 * No nopCommerce permission is required. Queueing the product is bookkeeping done on
	 * the user's behalf, like the stock movement row itself; demanding a permission here
	 * would stop a warehouse clerk who lacks it from transferring into the webshop
	 * warehouse at all.
	 *
	 * @param	array<string,mixed>	$parameters		Hook metadata
	 * @param	CommonObject		$object			Current object
	 * @param	string				$action			Current action
	 * @param	HookManager			$hookmanager	Hook manager
	 * @return	int									0 = OK, the native flow continues
	 */
	public function doActions($parameters, &$object, &$action, $hookmanager)
	{
		// HookManager runs a module's hook method only once, for the first context it
		// matches, so $parameters['currentcontext'] is not reliable. Qualify on the page's
		// full context list instead.
		if (!in_array('stockproductcard', (array) $hookmanager->contextarray)) {
			return 0;
		}
		if ($action != 'transfert_stock') {
			return 0;
		}
		if (GETPOST('cancel', 'alpha')) {
			return 0;
		}
		if (!isModEnabled('nopcommerce')) {
			return 0;
		}

		$source = getDolGlobalInt('NOPCOMMERCE_SOURCE_WAREHOUSE_ID');
		$destination = getDolGlobalInt('NOPCOMMERCE_WEBSHOP_WAREHOUSE_ID');

		// Unconfigured warehouses mean the feature is simply off, not an error.
		if ($source <= 0 || $destination <= 0) {
			return 0;
		}
		if (GETPOSTINT('id_entrepot') != $source) {
			return 0;
		}
		if (GETPOSTINT('id_entrepot_destination') != $destination) {
			return 0;
		}

		// An early exit only. The lot-specific form ignores the posted id_entrepot and
		// uses the lot's warehouse, so the source is re-checked against the real
		// outbound movement in NopCommerceCapture::recordSourceLeg().
		NopCommerceCapture::expect(GETPOSTINT('id'), $source, $destination);

		return 0;
	}
}
