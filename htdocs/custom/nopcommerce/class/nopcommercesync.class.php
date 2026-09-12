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
 * \file        htdocs/custom/nopcommerce/class/nopcommercesync.class.php
 * \ingroup     nopcommerce
 * \brief       Service that builds the payload nopCommerce pulls and applies its answer.
 */

require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
require_once DOL_DOCUMENT_ROOT.'/product/stock/class/entrepot.class.php';
require_once DOL_DOCUMENT_ROOT.'/product/stock/class/mouvementstock.class.php';
dol_include_once('/nopcommerce/class/nopcommercetransfer.class.php');
dol_include_once('/nopcommerce/class/nopcommercetransferline.class.php');


/**
 * Service for the nopCommerce product sync.
 *
 * Dolibarr is passive here. nopCommerce asks for the transfers that are waiting, records
 * the products on its own side, then reports back. Stock moves only on a success report.
 */
class NopCommerceSync
{
	/**
	 * @var DoliDB Database handler
	 */
	public $db;

	/**
	 * @var string Last error
	 */
	public $error = '';

	/**
	 * @var string[] Errors
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
	 * Return the ids of the transfers nopCommerce may pull, oldest first.
	 *
	 * A failed transfer stays in the list so that a later retry picks it up again.
	 *
	 * @param	int		$limit				Maximum number of transfers to return
	 * @param	int		$warehouseid		Restrict to this destination (webshop) warehouse, 0 for all
	 * @param	int		$includefailed		1 to also return the transfers a previous attempt failed on
	 * @return	int[]						Array of transfer ids, empty array if none
	 */
	public function getPullableTransferIds($limit = 50, $warehouseid = 0, $includefailed = 1)
	{
		global $conf;

		$statuses = array(NopCommerceTransfer::STATUS_PENDING);
		if ($includefailed) {
			$statuses[] = NopCommerceTransfer::STATUS_FAILED;
		}

		$sql = "SELECT rowid FROM ".$this->db->prefix()."nopcommerce_transfer";
		$sql .= " WHERE entity IN (".getEntity('nopcommercetransfer').")";
		$sql .= " AND sync_flag = 0";
		$sql .= " AND status IN (".$this->db->sanitize(implode(',', $statuses)).")";
		if ($warehouseid > 0) {
			$sql .= " AND fk_warehouse_destination = ".((int) $warehouseid);
		}
		$sql .= " ORDER BY rowid ASC";
		$sql .= $this->db->plimit($limit);

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			$this->errors[] = $this->error;
			return array();
		}

		$ids = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$ids[] = (int) $obj->rowid;
		}

		return $ids;
	}

	/**
	 * Issue a fresh pull token on a transfer and stamp the pull date.
	 *
	 * The token is what makes the acknowledgement idempotent: a stale acknowledgement
	 * that carries a token from an earlier pull is refused.
	 *
	 * @param	NopCommerceTransfer	$transfer	Transfer being pulled
	 * @return	string							The new token, empty string on failure
	 */
	public function issuePullToken(NopCommerceTransfer $transfer)
	{
		$token = bin2hex(random_bytes(24));

		$sql = "UPDATE ".$this->db->prefix()."nopcommerce_transfer";
		$sql .= " SET pull_token = '".$this->db->escape($token)."',";
		$sql .= " date_pulled = '".$this->db->idate(dol_now())."'";
		$sql .= " WHERE rowid = ".((int) $transfer->id);

		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			$this->errors[] = $this->error;
			return '';
		}

		$transfer->pull_token = $token;
		$transfer->date_pulled = dol_now();

		return $token;
	}

	/**
	 * Build the payload of one transfer, the way nopCommerce consumes it.
	 *
	 * @param	NopCommerceTransfer	$transfer	Transfer, lines already loaded
	 * @return	array<string,mixed>				Payload
	 */
	public function buildTransferPayload(NopCommerceTransfer $transfer)
	{
		$sourcewarehouse = new Entrepot($this->db);
		$sourcewarehouse->fetch($transfer->fk_warehouse_source);
		$destwarehouse = new Entrepot($this->db);
		$destwarehouse->fetch($transfer->fk_warehouse_destination);

		$payload = array(
			'transfer_id' => (int) $transfer->id,
			'ref' => $transfer->ref,
			'label' => $transfer->label,
			'status' => (int) $transfer->status,
			'sync_flag' => (bool) $transfer->sync_flag,
			'sync_attempts' => (int) $transfer->sync_attempts,
			'pull_token' => $transfer->pull_token,
			'source_warehouse' => array(
				'id' => (int) $transfer->fk_warehouse_source,
				'ref' => $sourcewarehouse->ref,
				'label' => $sourcewarehouse->label,
			),
			'webshop_warehouse' => array(
				'id' => (int) $transfer->fk_warehouse_destination,
				'ref' => $destwarehouse->ref,
				'label' => $destwarehouse->label,
			),
			'date_creation' => dol_print_date($transfer->date_creation, 'dayhourrfc'),
			'lines' => array(),
		);

		foreach ($transfer->lines as $line) {
			$payload['lines'][] = $this->buildLinePayload($line, $transfer);
		}

		return $payload;
	}

	/**
	 * Build the payload of one line: the product, its variant attributes and its quantities.
	 *
	 * @param	NopCommerceTransferLine	$line		Line to describe
	 * @param	NopCommerceTransfer		$transfer	Parent transfer
	 * @return	array<string,mixed>					Payload of the line
	 */
	public function buildLinePayload(NopCommerceTransferLine $line, NopCommerceTransfer $transfer)
	{
		$product = new Product($this->db);
		$product->fetch($line->fk_product);

		$data = array(
			'line_id' => (int) $line->id,
			'product_id' => (int) $line->fk_product,
			'ref' => $product->ref,
			'label' => $product->label,
			'description' => $product->description,
			'barcode' => $product->barcode,
			'price' => (float) $product->price,
			'price_ttc' => (float) $product->price_ttc,
			'tva_tx' => (float) $product->tva_tx,
			'weight' => (float) $product->weight,
			'weight_units' => $product->weight_units,
			'is_variant' => !empty($line->fk_product_parent),
			'parent' => null,
			'attributes' => array(),
			'qty' => (float) $line->qty,
			'batch' => $line->batch,
			'stock_in_webshop_warehouse' => $this->getStockInWarehouse($line->fk_product, $transfer->fk_warehouse_destination),
			'stock_in_source_warehouse' => $this->getStockInWarehouse($line->fk_product, $transfer->fk_warehouse_source),
			'nop_product_id' => $line->nop_product_id !== null ? (int) $line->nop_product_id : null,
			'nop_combination_id' => $line->nop_combination_id !== null ? (int) $line->nop_combination_id : null,
			'sync_flag' => (bool) $line->sync_flag,
		);

		if (!empty($line->fk_product_parent)) {
			$parent = new Product($this->db);
			$parent->fetch($line->fk_product_parent);
			$data['parent'] = array(
				'product_id' => (int) $line->fk_product_parent,
				'ref' => $parent->ref,
				'label' => $parent->label,
			);
			$data['attributes'] = $this->getVariantAttributes($line->fk_product);
		}

		return $data;
	}

	/**
	 * Return the attribute/value pairs of a variant child product, for example Size = M.
	 *
	 * @param	int		$fk_product_child	Id of the variant child product
	 * @return	array<int,array<string,mixed>>	List of attributes, empty when the product is not a variant
	 */
	public function getVariantAttributes($fk_product_child)
	{
		$sql = "SELECT pa.rowid as attribute_id, pa.ref as attribute_ref, pa.label as attribute_label,";
		$sql .= " pav.rowid as value_id, pav.ref as value_ref, pav.value as value_label";
		$sql .= " FROM ".$this->db->prefix()."product_attribute_combination as c";
		$sql .= " INNER JOIN ".$this->db->prefix()."product_attribute_combination2val as c2v ON c2v.fk_prod_combination = c.rowid";
		$sql .= " INNER JOIN ".$this->db->prefix()."product_attribute as pa ON pa.rowid = c2v.fk_prod_attr";
		$sql .= " INNER JOIN ".$this->db->prefix()."product_attribute_value as pav ON pav.rowid = c2v.fk_prod_attr_val";
		$sql .= " WHERE c.fk_product_child = ".((int) $fk_product_child);
		$sql .= " AND c.entity IN (".getEntity('product').")";
		$sql .= " ORDER BY pa.position ASC, pav.position ASC";

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			$this->errors[] = $this->error;
			return array();
		}

		$attributes = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$attributes[] = array(
				'attribute_id' => (int) $obj->attribute_id,
				'attribute_ref' => $obj->attribute_ref,
				'attribute_label' => $obj->attribute_label,
				'value_id' => (int) $obj->value_id,
				'value_ref' => $obj->value_ref,
				'value_label' => $obj->value_label,
			);
		}

		return $attributes;
	}

	/**
	 * Return the physical stock of a product in one warehouse.
	 *
	 * @param	int		$fk_product		Product id
	 * @param	int		$fk_entrepot	Warehouse id
	 * @return	float					Physical stock, 0 when the product has no row for that warehouse
	 */
	public function getStockInWarehouse($fk_product, $fk_entrepot)
	{
		$sql = "SELECT reel FROM ".$this->db->prefix()."product_stock";
		$sql .= " WHERE fk_product = ".((int) $fk_product);
		$sql .= " AND fk_entrepot = ".((int) $fk_entrepot);

		$resql = $this->db->query($sql);
		if (!$resql) {
			return 0.0;
		}
		$obj = $this->db->fetch_object($resql);

		return $obj ? (float) $obj->reel : 0.0;
	}

	/**
	 * Apply a success answer from nopCommerce: move the stock, then raise the sync flags.
	 *
	 * The whole transfer is applied in one database transaction. If any line cannot be
	 * moved, nothing is written and the transfer stays pullable.
	 *
	 * @param	User				$user		User the API call runs as
	 * @param	NopCommerceTransfer	$transfer	Transfer being acknowledged, lines loaded
	 * @param	array<int,array<string,mixed>>	$lineresults	Per line data sent by nopCommerce, keyed by line id
	 * @return	int<-1,1>						Return integer <0 if KO, >0 if OK
	 */
	public function applyAckSuccess(User $user, NopCommerceTransfer $transfer, array $lineresults = array())
	{
		global $langs;

		$langs->loadLangs(array('nopcommerce@nopcommerce'));

		$allownegative = getDolGlobalInt('NOPCOMMERCE_ALLOW_NEGATIVE_SOURCE_STOCK');

		$this->db->begin();

		$label = $langs->transnoentitiesnoconv('NopCommerceMovementLabel', $transfer->ref);
		$inventorycode = 'NOPSYNC-'.$transfer->ref;

		// A captured transfer moved its stock on the native stock transfer page before
		// nopCommerce ever saw it. Moving it again here would double-count it, and the
		// available-stock check below would reject the acknowledgement outright, because
		// the source warehouse has already been decremented.
		$stockalreadymoved = $transfer->stockAlreadyMoved();

		foreach ($transfer->lines as $line) {
			if (!$stockalreadymoved && !$allownegative) {
				$available = $this->getStockInWarehouse($line->fk_product, $transfer->fk_warehouse_source);
				if ($available < $line->qty) {
					$this->error = $langs->transnoentitiesnoconv('NopCommerceNotEnoughStock', $line->fk_product, $available, $line->qty);
					$this->errors[] = $this->error;
					$this->db->rollback();
					return -1;
				}
			}

			if (!$stockalreadymoved) {
				$movementout = new MouvementStock($this->db);
				$movementout->origin_type = NopCommerceTransfer::ORIGIN_TYPE;
				$movementout->origin_id = $transfer->id;
				$resultout = $movementout->livraison($user, $line->fk_product, $transfer->fk_warehouse_source, $line->qty, 0, $label, '', '', '', (string) $line->batch, 0, $inventorycode);
				if ($resultout < 0) {
					$this->error = $movementout->error;
					$this->errors = array_merge($this->errors, $movementout->errors);
					$this->db->rollback();
					return -1;
				}

				$movementin = new MouvementStock($this->db);
				$movementin->origin_type = NopCommerceTransfer::ORIGIN_TYPE;
				$movementin->origin_id = $transfer->id;
				$resultin = $movementin->reception($user, $line->fk_product, $transfer->fk_warehouse_destination, $line->qty, 0, $label, '', '', (string) $line->batch, '', 0, $inventorycode);
				if ($resultin < 0) {
					$this->error = $movementin->error;
					$this->errors = array_merge($this->errors, $movementin->errors);
					$this->db->rollback();
					return -1;
				}

				$line->fk_mouvement_source = $movementout->id;
				$line->fk_mouvement_destination = $movementin->id;
			}

			$line->sync_flag = 1;
			$line->sync_error = null;

			if (isset($lineresults[$line->id])) {
				$result = $lineresults[$line->id];
				if (isset($result['nop_product_id'])) {
					$line->nop_product_id = (int) $result['nop_product_id'];
				}
				if (isset($result['nop_combination_id'])) {
					$line->nop_combination_id = (int) $result['nop_combination_id'];
				}
			}

			if ($line->update($user) < 0) {
				$this->error = $line->error;
				$this->errors = array_merge($this->errors, $line->errors);
				$this->db->rollback();
				return -1;
			}
		}

		$sql = "UPDATE ".$this->db->prefix()."nopcommerce_transfer";
		$sql .= " SET status = ".((int) NopCommerceTransfer::STATUS_SYNCED).",";
		$sql .= " sync_flag = 1,";
		$sql .= " sync_attempts = sync_attempts + 1,";
		$sql .= " sync_last_error = NULL,";
		$sql .= " pull_token = NULL,";
		$sql .= " date_synced = '".$this->db->idate(dol_now())."',";
		$sql .= " fk_user_modif = ".((int) $user->id);
		$sql .= " WHERE rowid = ".((int) $transfer->id);

		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			$this->errors[] = $this->error;
			$this->db->rollback();
			return -1;
		}

		$transfer->status = NopCommerceTransfer::STATUS_SYNCED;
		$transfer->sync_flag = 1;
		$transfer->sync_attempts++;
		$transfer->sync_last_error = null;
		$transfer->pull_token = null;
		$transfer->date_synced = dol_now();

		$result = $transfer->call_trigger('NOPCOMMERCE_TRANSFER_SYNCED', $user);
		if ($result < 0) {
			$this->error = $transfer->error;
			$this->errors = array_merge($this->errors, $transfer->errors);
			$this->db->rollback();
			return -1;
		}

		$this->db->commit();

		return 1;
	}

	/**
	 * Apply a failure answer from nopCommerce. No stock is moved and the sync flag stays
	 * false, so the transfer is still returned by the next pull.
	 *
	 * @param	User				$user		User the API call runs as
	 * @param	NopCommerceTransfer	$transfer	Transfer being acknowledged, lines loaded
	 * @param	string				$errormessage	Error reported by nopCommerce
	 * @param	array<int,array<string,mixed>>	$lineresults	Per line data sent by nopCommerce, keyed by line id
	 * @return	int<-1,1>						Return integer <0 if KO, >0 if OK
	 */
	public function applyAckFailure(User $user, NopCommerceTransfer $transfer, $errormessage, array $lineresults = array())
	{
		$this->db->begin();

		foreach ($transfer->lines as $line) {
			if (!isset($lineresults[$line->id])) {
				continue;
			}
			$result = $lineresults[$line->id];

			$line->sync_flag = 0;
			$line->sync_error = isset($result['error']) ? dol_trunc((string) $result['error'], 2000, 'right', 'UTF-8', 1) : null;
			if (isset($result['nop_product_id'])) {
				$line->nop_product_id = (int) $result['nop_product_id'];
			}
			if (isset($result['nop_combination_id'])) {
				$line->nop_combination_id = (int) $result['nop_combination_id'];
			}

			if ($line->update($user) < 0) {
				$this->error = $line->error;
				$this->errors = array_merge($this->errors, $line->errors);
				$this->db->rollback();
				return -1;
			}
		}

		$sql = "UPDATE ".$this->db->prefix()."nopcommerce_transfer";
		$sql .= " SET status = ".((int) NopCommerceTransfer::STATUS_FAILED).",";
		$sql .= " sync_flag = 0,";
		$sql .= " sync_attempts = sync_attempts + 1,";
		$sql .= " sync_last_error = '".$this->db->escape(dol_trunc((string) $errormessage, 4000, 'right', 'UTF-8', 1))."',";
		$sql .= " pull_token = NULL,";
		$sql .= " fk_user_modif = ".((int) $user->id);
		$sql .= " WHERE rowid = ".((int) $transfer->id);

		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			$this->errors[] = $this->error;
			$this->db->rollback();
			return -1;
		}

		$transfer->status = NopCommerceTransfer::STATUS_FAILED;
		$transfer->sync_attempts++;
		$transfer->sync_last_error = $errormessage;
		$transfer->pull_token = null;

		$result = $transfer->call_trigger('NOPCOMMERCE_TRANSFER_SYNCFAILED', $user);
		if ($result < 0) {
			$this->error = $transfer->error;
			$this->errors = array_merge($this->errors, $transfer->errors);
			$this->db->rollback();
			return -1;
		}

		$this->db->commit();

		return 1;
	}
}
