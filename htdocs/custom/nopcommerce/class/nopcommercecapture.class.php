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
 * \file        htdocs/custom/nopcommerce/class/nopcommercecapture.class.php
 * \ingroup     nopcommerce
 * \brief       Captures a native stock transfer into a nopCommerce sync transfer.
 */

require_once DOL_DOCUMENT_ROOT.'/product/stock/class/mouvementstock.class.php';
dol_include_once('/nopcommerce/class/nopcommercetransfer.class.php');
dol_include_once('/nopcommerce/class/nopcommercetransferline.class.php');


/**
 * Capture of a native stock transfer.
 *
 * Two seams cooperate to notice a native transfer, and this class holds everything that
 * is neither of them. The page hook knows which page was submitted but runs before the
 * stock moves; the STOCK_MOVEMENT trigger runs after the movement rows exist but cannot
 * tell which page caused them. So the hook parks an intent here, and the trigger hands
 * the movements here once they exist.
 *
 * All facts are read from the movements rather than the submitted form, because the
 * lot-specific transfer form takes its source warehouse from the lot and not from the
 * posted id_entrepot.
 */
class NopCommerceCapture
{
	/**
	 * @var ?array{product:int,source:int,destination:int} Parked intent, null when there is none
	 */
	private static $intent = null;

	/**
	 * @var int Id of the llx_stock_mouvement row of the outbound leg, 0 until it is seen
	 */
	private static $sourcemovementid = 0;

	/**
	 * @var string Last error, read by the trigger so it can be reported to the user
	 */
	public static $error = '';

	/**
	 * Record that the native transfer form was submitted for a pair of warehouses that
	 * the module is configured to capture.
	 *
	 * @param	int		$fk_product		Product the user submitted
	 * @param	int		$source			Configured source warehouse
	 * @param	int		$destination	Configured webshop warehouse
	 * @return	void
	 */
	public static function expect($fk_product, $source, $destination)
	{
		self::$intent = array(
			'product' => (int) $fk_product,
			'source' => (int) $source,
			'destination' => (int) $destination,
		);
		self::$sourcemovementid = 0;
		self::$error = '';
	}

	/**
	 * Is a capture expected in this request?
	 *
	 * @return bool	True when an intent is parked
	 */
	public static function isExpected()
	{
		return self::$intent !== null;
	}

	/**
	 * @return int	Product the intent was parked for, 0 when there is no intent
	 */
	public static function expectedProduct()
	{
		return self::$intent === null ? 0 : (int) self::$intent['product'];
	}

	/**
	 * @return int	Configured source warehouse, 0 when there is no intent
	 */
	public static function expectedSource()
	{
		return self::$intent === null ? 0 : (int) self::$intent['source'];
	}

	/**
	 * @return int	Configured webshop warehouse, 0 when there is no intent
	 */
	public static function expectedDestination()
	{
		return self::$intent === null ? 0 : (int) self::$intent['destination'];
	}

	/**
	 * @return int	Movement id of the outbound leg, 0 until it has been recorded
	 */
	public static function sourceMovementId()
	{
		return (int) self::$sourcemovementid;
	}

	/**
	 * Drop the intent. Called once a capture has run, and whenever the movements turn out
	 * not to match, so no later movement in the same request can be captured.
	 *
	 * @return void
	 */
	public static function forget()
	{
		self::$intent = null;
		self::$sourcemovementid = 0;
	}

	/**
	 * Record the outbound leg of the transfer.
	 *
	 * This is where the configured source warehouse is really enforced. The hook checks
	 * the posted id_entrepot, but product.php ignores that field when the form was opened
	 * for a specific lot and uses the lot's warehouse instead, so a user could otherwise
	 * pass the hook's check while the stock leaves a different warehouse.
	 *
	 * @param	MouvementStock	$m	The outbound movement
	 * @return	int<-1,1>			<0 when the stock did not leave the configured source
	 */
	public static function recordSourceLeg(MouvementStock $m)
	{
		if (self::$intent === null) {
			return -1;
		}
		if ((int) $m->entrepot_id !== self::expectedSource()) {
			self::$error = 'Stock left warehouse '.((int) $m->entrepot_id).' but '.self::expectedSource().' is configured as the source';
			return -1;
		}

		self::$sourcemovementid = (int) $m->id;

		return 1;
	}
}
