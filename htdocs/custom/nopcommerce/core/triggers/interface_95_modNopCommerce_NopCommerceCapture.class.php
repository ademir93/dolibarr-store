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
 * \file    htdocs/custom/nopcommerce/core/triggers/interface_95_modNopCommerce_NopCommerceCapture.class.php
 * \ingroup nopcommerce
 * \brief   Captures a native stock transfer once its movements exist.
 */

require_once DOL_DOCUMENT_ROOT.'/core/triggers/dolibarrtriggers.class.php';
dol_include_once('/nopcommerce/class/nopcommercecapture.class.php');


/**
 * Trigger that turns a native stock transfer into a nopCommerce sync transfer.
 *
 * The class name is not free: the loader derives it from the file name as
 * "Interface".ucfirst($third_segment), and silently skips the file when it does not
 * match. See interfaces.class.php.
 */
class InterfaceNopCommerceCapture extends DolibarrTriggers
{
	/**
	 * Constructor
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;

		$this->name = preg_replace('/^Interface/i', '', get_class($this));
		$this->family = "interface";
		$this->description = "Captures a native stock transfer into a nopCommerce sync transfer.";
		$this->version = self::VERSIONS['prod'];
		$this->picto = 'stock';
	}

	/**
	 * Run the trigger.
	 *
	 * Only acts when the page hook parked an intent, so ordinary stock movements and the
	 * movements applyAckSuccess() makes for manual transfers both fall straight through.
	 *
	 * @param	string		$action		Event code
	 * @param	CommonObject	$object	Object the event is about
	 * @param	User		$user		User that did the action
	 * @param	Translate	$langs		Translation handler
	 * @param	Conf		$conf		Configuration
	 * @return	int<-1,1>				0 if no action, >0 if OK, <0 if KO
	 */
	public function runTrigger($action, $object, User $user, Translate $langs, Conf $conf)
	{
		if ($action != 'STOCK_MOVEMENT') {
			return 0;
		}
		if (!NopCommerceCapture::isExpected()) {
			return 0;
		}

		// A kit's components each generate their own movement into the same warehouse,
		// because _createSubProduct() runs before this trigger fires. Only the product the
		// user actually submitted is captured.
		if ((int) $object->product_id !== NopCommerceCapture::expectedProduct()) {
			return 0;
		}

		// type 1 = stock leaving by a transfer, type 0 = stock arriving by a transfer.
		if ((int) $object->type === 1) {
			if (NopCommerceCapture::recordSourceLeg($object) < 0) {
				// The stock did not leave the configured source warehouse, so this is not
				// a capture case after all. Leave the transfer alone.
				NopCommerceCapture::forget();
			}
			return 0;
		}
		if ((int) $object->type !== 0) {
			return 0;
		}
		if ((int) $object->entrepot_id !== NopCommerceCapture::expectedDestination()) {
			return 0;
		}

		$result = NopCommerceCapture::capture($this->db, $user, $object);
		NopCommerceCapture::forget();

		if ($result < 0) {
			$langs->load('nopcommerce@nopcommerce');
			$this->errors[] = $langs->trans('NopCommerceCaptureFailed', NopCommerceCapture::$error);
			return -1;
		}

		return 1;
	}
}
