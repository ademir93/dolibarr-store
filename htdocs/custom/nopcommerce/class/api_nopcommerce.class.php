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
 * \file    htdocs/custom/nopcommerce/class/api_nopcommerce.class.php
 * \ingroup nopcommerce
 * \brief   REST endpoints nopCommerce polls to pull products and report the result.
 */

use Luracast\Restler\RestException;

dol_include_once('/nopcommerce/class/nopcommercetransfer.class.php');
dol_include_once('/nopcommerce/class/nopcommercetransferline.class.php');
dol_include_once('/nopcommerce/class/nopcommercesync.class.php');


/**
 * API class for the nopCommerce product sync.
 *
 * Dolibarr never calls the webshop. nopCommerce polls GET /nopcommerce/transfers/pending,
 * writes the products on its own side, then calls POST /nopcommerce/transfers/{id}/ack.
 * The stock is moved and the sync flag is raised only when that acknowledgement reports
 * a success.
 *
 * @access protected
 * @class  DolibarrApiAccess {@requires user,external}
 */
class NopCommerce extends DolibarrApi
{
	/**
	 * @var NopCommerceSync {@type NopCommerceSync}
	 */
	public $sync;

	/**
	 * Constructor
	 */
	public function __construct()
	{
		global $db;
		$this->db = $db;
		$this->sync = new NopCommerceSync($this->db);
	}

	/**
	 * Ping the sync endpoint
	 *
	 * Lets the nopCommerce plugin check its API key and its warehouse setup without
	 * changing anything.
	 *
	 * @return	array<string,mixed>		Server time, configured webshop warehouse and number of waiting transfers
	 *
	 * @url	GET status
	 *
	 * @throws RestException 403 Not allowed
	 */
	public function status()
	{
		if (!DolibarrApiAccess::$user->hasRight('nopcommerce', 'sync')) {
			throw new RestException(403, 'Access to the nopCommerce sync API not allowed for login '.DolibarrApiAccess::$user->login);
		}

		$warehouseid = getDolGlobalInt('NOPCOMMERCE_WEBSHOP_WAREHOUSE_ID');
		$pending = $this->sync->getPullableTransferIds(1000, $warehouseid);

		return array(
			'status' => 'ok',
			'dolibarr_version' => DOL_VERSION,
			'module_version' => '1.0',
			'server_time' => dol_print_date(dol_now(), 'dayhourrfc'),
			'webshop_warehouse_id' => $warehouseid,
			'pending_transfers' => count($pending),
		);
	}

	/**
	 * List the transfers waiting to be recorded on nopCommerce
	 *
	 * Returns every transfer that is validated but not yet confirmed by nopCommerce,
	 * with its products, their variant attributes such as Size, and their quantities.
	 * A transfer a previous attempt failed on is returned again so a retry works.
	 *
	 * Each returned transfer carries a fresh pull_token. That token has to be sent back
	 * in the acknowledgement, which is what stops a stale acknowledgement from being
	 * applied twice.
	 *
	 * @param	int		$limit				Maximum number of transfers to return
	 * @param	int		$warehouse_id		Restrict to this webshop warehouse. 0 uses the warehouse configured in the module setup
	 * @param	int		$include_failed		1 to also return the transfers a previous attempt failed on, 0 to skip them
	 * @return	array<string,mixed>			Waiting transfers with their lines
	 *
	 * @url	GET transfers/pending
	 *
	 * @throws RestException 403 Not allowed
	 * @throws RestException 500 Internal Server Error
	 */
	public function pending($limit = 50, $warehouse_id = 0, $include_failed = 1)
	{
		if (!DolibarrApiAccess::$user->hasRight('nopcommerce', 'sync')) {
			throw new RestException(403, 'Access to the nopCommerce sync API not allowed for login '.DolibarrApiAccess::$user->login);
		}

		$limit = min(max((int) $limit, 1), 200);
		if (empty($warehouse_id)) {
			$warehouse_id = getDolGlobalInt('NOPCOMMERCE_WEBSHOP_WAREHOUSE_ID');
		}

		$ids = $this->sync->getPullableTransferIds($limit, $warehouse_id, (int) $include_failed);

		$transfers = array();
		foreach ($ids as $id) {
			$transfer = new NopCommerceTransfer($this->db);
			if ($transfer->fetch($id) <= 0) {
				continue;
			}
			if ($transfer->fetchLines() < 0) {
				throw new RestException(500, 'Failed to load the lines of transfer '.$id.': '.$transfer->error);
			}

			$token = $this->sync->issuePullToken($transfer);
			if ($token === '') {
				throw new RestException(500, 'Failed to issue a pull token for transfer '.$id.': '.$this->sync->error);
			}

			$transfers[] = $this->sync->buildTransferPayload($transfer);
		}

		return array(
			'server_time' => dol_print_date(dol_now(), 'dayhourrfc'),
			'webshop_warehouse_id' => (int) $warehouse_id,
			'count' => count($transfers),
			'transfers' => $transfers,
		);
	}

	/**
	 * Get one transfer
	 *
	 * Reads a transfer whatever its status, without issuing a pull token. Useful for the
	 * nopCommerce side to re-read a transfer it already handled.
	 *
	 * @param	int		$id			Id of the transfer
	 * @return	array<string,mixed>	The transfer with its lines
	 *
	 * @url	GET transfers/{id}
	 *
	 * @throws RestException 403 Not allowed
	 * @throws RestException 404 Not found
	 * @throws RestException 500 Internal Server Error
	 */
	public function getTransfer($id)
	{
		if (!DolibarrApiAccess::$user->hasRight('nopcommerce', 'sync')) {
			throw new RestException(403, 'Access to the nopCommerce sync API not allowed for login '.DolibarrApiAccess::$user->login);
		}

		$transfer = new NopCommerceTransfer($this->db);
		if ($transfer->fetch($id) <= 0) {
			throw new RestException(404, 'Transfer not found');
		}
		if ($transfer->fetchLines() < 0) {
			throw new RestException(500, 'Failed to load the lines of transfer '.$id.': '.$transfer->error);
		}

		return $this->sync->buildTransferPayload($transfer);
	}

	/**
	 * Acknowledge a transfer
	 *
	 * nopCommerce calls this once it has tried to record the products of the transfer.
	 *
	 * On success Dolibarr moves the stock of every line out of the source warehouse and
	 * into the webshop warehouse, then sets the sync flag of the transfer and of each of
	 * its lines to true. On failure nothing is moved, the flag stays false, the error is
	 * stored and the transfer is returned by the next pull.
	 *
	 * Body:
	 *     {
	 *       "pull_token": "the token returned by the pull",
	 *       "success": true,
	 *       "error": "message, only when success is false",
	 *       "lines": [
	 *         {"line_id": 12, "success": true, "nop_product_id": 55, "nop_combination_id": 9, "error": ""}
	 *       ]
	 *     }
	 *
	 * Calling it twice is safe: an already confirmed transfer answers already_synced
	 * without touching the stock again.
	 *
	 * @param	int		$id				Id of the transfer
	 * @param	array	$request_data	Acknowledgement sent by nopCommerce
	 * @return	array<string,mixed>		Resulting state of the transfer
	 *
	 * @url	POST transfers/{id}/ack
	 *
	 * @throws RestException 400 Bad Request
	 * @throws RestException 403 Not allowed
	 * @throws RestException 404 Not found
	 * @throws RestException 409 Conflict
	 * @throws RestException 500 Internal Server Error
	 */
	public function ack($id, $request_data = null)
	{
		if (!DolibarrApiAccess::$user->hasRight('nopcommerce', 'sync')) {
			throw new RestException(403, 'Access to the nopCommerce sync API not allowed for login '.DolibarrApiAccess::$user->login);
		}
		if (!is_array($request_data)) {
			throw new RestException(400, 'A JSON body is required');
		}
		if (!array_key_exists('success', $request_data)) {
			throw new RestException(400, 'Field success is required in the body');
		}

		$transfer = new NopCommerceTransfer($this->db);
		if ($transfer->fetch($id) <= 0) {
			throw new RestException(404, 'Transfer not found');
		}

		if ((int) $transfer->status == NopCommerceTransfer::STATUS_SYNCED) {
			return array(
				'transfer_id' => (int) $transfer->id,
				'ref' => $transfer->ref,
				'status' => (int) $transfer->status,
				'sync_flag' => true,
				'already_synced' => true,
				'message' => 'Transfer was already confirmed, nothing was changed',
			);
		}

		if (!$transfer->isPullable()) {
			throw new RestException(409, 'Transfer '.$transfer->ref.' is not waiting for nopCommerce, its status is '.$transfer->status);
		}

		if (getDolGlobalInt('NOPCOMMERCE_ACK_REQUIRE_TOKEN', 1)) {
			$token = isset($request_data['pull_token']) ? (string) $request_data['pull_token'] : '';
			if (empty($transfer->pull_token) || !hash_equals((string) $transfer->pull_token, $token)) {
				throw new RestException(409, 'Invalid or stale pull_token, pull the transfer again before acknowledging it');
			}
		}

		if ($transfer->fetchLines() < 0) {
			throw new RestException(500, 'Failed to load the lines of transfer '.$id.': '.$transfer->error);
		}

		$lineresults = $this->indexLineResults($request_data);
		$success = filter_var($request_data['success'], FILTER_VALIDATE_BOOLEAN);

		if ($success) {
			$result = $this->sync->applyAckSuccess(DolibarrApiAccess::$user, $transfer, $lineresults);
			if ($result < 0) {
				throw new RestException(500, 'Failed to apply the transfer: '.$this->sync->error);
			}

			return array(
				'transfer_id' => (int) $transfer->id,
				'ref' => $transfer->ref,
				'status' => (int) $transfer->status,
				'sync_flag' => true,
				'already_synced' => false,
				'message' => $transfer->stockAlreadyMoved()
					? 'Sync flag set to true. No stock was moved: this transfer was captured from a stock transfer that already moved it'
					: 'Stock moved and sync flag set to true',
			);
		}

		$errormessage = isset($request_data['error']) ? (string) $request_data['error'] : 'nopCommerce reported a failure without a message';

		$result = $this->sync->applyAckFailure(DolibarrApiAccess::$user, $transfer, $errormessage, $lineresults);
		if ($result < 0) {
			throw new RestException(500, 'Failed to record the failure: '.$this->sync->error);
		}

		return array(
			'transfer_id' => (int) $transfer->id,
			'ref' => $transfer->ref,
			'status' => (int) $transfer->status,
			'sync_flag' => false,
			'already_synced' => false,
			'message' => 'Failure recorded, no stock was moved, the transfer stays pullable',
		);
	}

	/**
	 * Report a completed order
	 *
	 * nopCommerce calls this when an order is completed, with the products it sold.
	 * Dolibarr finds each product by its nopCommerce external id (the product extrafield
	 * nopcommerce_external_id), picks the variant carrying the sold attribute value, such
	 * as Size = M, and takes the quantity out of the webshop warehouse. The order and its
	 * items are then recorded.
	 *
	 * It is all or nothing: if one item matches no product or variant, or the webshop
	 * warehouse lacks its stock, no stock moves and nothing is recorded.
	 *
	 * Body:
	 *     {
	 *       "orderid": 1001,
	 *       "items": [
	 *         {"productid": 55, "attribute": "Size", "attributeValue": "M", "quantity": 2}
	 *       ]
	 *     }
	 *
	 * Calling it twice for the same order is safe: an order already recorded answers
	 * already_completed without touching the stock again.
	 *
	 * @param	array	$request_data	Completed order sent by nopCommerce
	 * @return	array<string,mixed>		Recorded order with the product and remaining stock of each item
	 *
	 * @url	POST order_completed
	 *
	 * @throws RestException 400 Bad Request
	 * @throws RestException 403 Not allowed
	 * @throws RestException 404 Not found
	 * @throws RestException 409 Conflict
	 * @throws RestException 500 Internal Server Error
	 */
	public function orderCompleted($request_data = null)
	{
		if (!DolibarrApiAccess::$user->hasRight('nopcommerce', 'sync')) {
			throw new RestException(403, 'Access to the nopCommerce sync API not allowed for login '.DolibarrApiAccess::$user->login);
		}
		if (!is_array($request_data)) {
			throw new RestException(400, 'A JSON body is required');
		}

		$noporderid = isset($request_data['orderid']) ? (int) $request_data['orderid'] : 0;
		if ($noporderid <= 0) {
			throw new RestException(400, 'Field orderid is required in the body and has to be a positive integer');
		}
		if (empty($request_data['items']) || !is_array($request_data['items'])) {
			throw new RestException(400, 'Field items is required in the body and has to hold at least one product');
		}

		foreach ($request_data['items'] as $index => $item) {
			if (!is_array($item) || empty($item['productid']) || (int) $item['productid'] <= 0) {
				throw new RestException(400, 'Item '.$index.': productid is required and has to be a positive integer');
			}
			if (!isset($item['quantity']) || (int) $item['quantity'] <= 0) {
				throw new RestException(400, 'Item '.$index.': quantity has to be greater than zero');
			}
		}

		$result = $this->sync->syncProduct(DolibarrApiAccess::$user, $noporderid, array_values($request_data['items']));
		if ($result == -2) {
			throw new RestException(404, 'Order '.$noporderid.' was not applied: '.$this->sync->error);
		}
		if ($result == -3) {
			throw new RestException(409, 'Order '.$noporderid.' was not applied: '.$this->sync->error);
		}
		if ($result < 0) {
			throw new RestException(500, 'Failed to apply order '.$noporderid.': '.$this->sync->error);
		}

		return array(
			'order_id' => $noporderid,
			'completed_id' => (int) $this->sync->completedorderid,
			'already_completed' => ($result == 0),
			'message' => $result == 0
				? 'Order was already recorded, nothing was changed'
				: 'Stock taken out of the webshop warehouse and order recorded',
			'items' => $this->sync->completeditems,
		);
	}

	/**
	 * Turn the lines of the body into a map keyed by line id.
	 *
	 * @param	array<string,mixed>				$request_data	Body of the acknowledgement
	 * @return	array<int,array<string,mixed>>					Per line data keyed by line id
	 */
	protected function indexLineResults(array $request_data)
	{
		$lineresults = array();

		if (empty($request_data['lines']) || !is_array($request_data['lines'])) {
			return $lineresults;
		}

		foreach ($request_data['lines'] as $lineresult) {
			if (!is_array($lineresult) || empty($lineresult['line_id'])) {
				continue;
			}
			$lineresults[(int) $lineresult['line_id']] = $lineresult;
		}

		return $lineresults;
	}
}
