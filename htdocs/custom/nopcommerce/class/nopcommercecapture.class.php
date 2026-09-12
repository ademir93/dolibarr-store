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

	/**
	 * Record the transferred product as a pending sync transfer.
	 *
	 * Reuses the ordinary create/addLine/validate path so captured and manual transfers
	 * are built by the same code. The ref is derived from the destination movement id
	 * rather than the sequential counter: the counter reads the highest existing ref and
	 * increments it, so two simultaneous transfers compute the same ref and the loser's
	 * stock transfer would fail for a reason unrelated to anything that user did.
	 *
	 * @param	DoliDB			$db		Database handler
	 * @param	User			$user	User performing the stock transfer
	 * @param	MouvementStock	$m		The inbound movement, into the webshop warehouse
	 * @return	int<-1,max>				Id of the captured transfer, or <0 on failure
	 */
	public static function capture(DoliDB $db, User $user, MouvementStock $m)
	{
		if (self::$intent === null) {
			self::$error = 'No capture was expected';
			return -1;
		}
		if (empty(self::$sourcemovementid)) {
			self::$error = 'The outbound stock movement was never seen';
			return -1;
		}

		$transfer = new NopCommerceTransfer($db);
		$transfer->label = (string) $m->label;
		$transfer->fk_warehouse_source = self::expectedSource();
		$transfer->fk_warehouse_destination = (int) $m->entrepot_id;

		if ($transfer->create($user) <= 0) {
			self::$error = 'Failed to create the transfer: '.$transfer->error;
			return -1;
		}

		$lineid = $transfer->addLine($user, (int) $m->product_id, abs((float) $m->qty), (string) $m->batch);
		if ($lineid <= 0) {
			self::$error = 'Failed to add the product: '.$transfer->error;
			return -1;
		}

		// Record which stock movements this line is the bookkeeping for. applyAckSuccess
		// leaves these alone for a captured transfer instead of creating its own.
		$line = new NopCommerceTransferLine($db);
		if ($line->fetch($lineid) <= 0) {
			self::$error = 'Failed to reload the line: '.$line->error;
			return -1;
		}
		$line->fk_mouvement_source = self::sourceMovementId();
		$line->fk_mouvement_destination = (int) $m->id;
		if ($line->update($user) < 0) {
			self::$error = 'Failed to record the stock movements on the line: '.$line->error;
			return -1;
		}

		$transfer->origin = NopCommerceTransfer::ORIGIN_NATIVE;
		if ($transfer->update($user) < 0) {
			self::$error = 'Failed to mark the transfer as captured: '.$transfer->error;
			return -1;
		}

		if ($transfer->validate($user, 0, 'NOP-M'.((int) $m->id)) <= 0) {
			self::$error = 'Failed to validate the transfer: '.$transfer->error;
			return -1;
		}

		if (self::tagMovements($db, (int) $transfer->id, self::sourceMovementId(), (int) $m->id) < 0) {
			return -1;
		}

		return (int) $transfer->id;
	}

	/**
	 * Point both stock movements back at the transfer they belong to, so the stock
	 * movement list can resolve and link them.
	 *
	 * Only rows with no provenance are touched, so an existing one is never overwritten.
	 * The test has to accept 0 as well as NULL because MouvementStock::_create() writes
	 * 0 and '' rather than nulls when no origin was given.
	 *
	 * @param	DoliDB	$db					Database handler
	 * @param	int		$transferid			Transfer the movements belong to
	 * @param	int		$sourcemovementid	Outbound movement row id
	 * @param	int		$destmovementid		Inbound movement row id
	 * @return	int<-1,1>					<0 if KO, >0 if OK
	 */
	protected static function tagMovements(DoliDB $db, $transferid, $sourcemovementid, $destmovementid)
	{
		$sql = "UPDATE ".$db->prefix()."stock_mouvement";
		$sql .= " SET fk_origin = ".((int) $transferid).",";
		$sql .= " origintype = '".$db->escape(NopCommerceTransfer::ORIGIN_TYPE)."'";
		$sql .= " WHERE rowid IN (".((int) $sourcemovementid).", ".((int) $destmovementid).")";
		$sql .= " AND (fk_origin IS NULL OR fk_origin = 0)";

		if (!$db->query($sql)) {
			self::$error = 'Failed to tag the stock movements: '.$db->lasterror();
			return -1;
		}

		return 1;
	}
}
