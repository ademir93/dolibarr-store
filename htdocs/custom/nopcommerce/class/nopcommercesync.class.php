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
	 * @var int Row id of llx_nop_order_completed the last syncProduct() call recorded or found
	 */
	public $completedorderid = 0;

	/**
	 * @var array<int,array<string,mixed>> Items the last syncProduct() call applied
	 */
	public $completeditems = array();

	/**
	 * @var int Row id of llx_nop_order_reversal the last reverseOrder() call recorded or found
	 */
	public $reversalid = 0;

	/**
	 * @var array<int,array<string,mixed>> Items the last reverseOrder() call applied
	 */
	public $reverseditems = array();

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
			'origin' => (string) $transfer->origin,
			'stock_already_moved' => $transfer->stockAlreadyMoved(),
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
	 * @param	bool	$forupdate		Lock the stock row until the transaction ends, so a concurrent call cannot spend the same stock
	 * @return	float					Physical stock, 0 when the product has no row for that warehouse
	 */
	public function getStockInWarehouse($fk_product, $fk_entrepot, $forupdate = false)
	{
		$sql = "SELECT reel FROM ".$this->db->prefix()."product_stock";
		$sql .= " WHERE fk_product = ".((int) $fk_product);
		$sql .= " AND fk_entrepot = ".((int) $fk_entrepot);
		if ($forupdate) {
			$sql .= " FOR UPDATE";
		}

		$resql = $this->db->query($sql);
		if (!$resql) {
			return 0.0;
		}
		$obj = $this->db->fetch_object($resql);

		return $obj ? (float) $obj->reel : 0.0;
	}

	/**
	 * Apply an order nopCommerce reports as completed.
	 *
	 * Every sold line is matched to a Dolibarr product through its nopCommerce external id,
	 * narrowed to the variant carrying the sold attribute value, and taken out of the
	 * webshop warehouse. The order and its items are then recorded. All of it happens in
	 * one database transaction: if any line cannot be matched or moved, no stock moves and
	 * nothing is recorded.
	 *
	 * An order already recorded is not applied again.
	 *
	 * @param	User	$user			User the API call runs as
	 * @param	int		$noporderid		Order id on the nopCommerce side
	 * @param	array<int,array<string,mixed>>	$lines	Sold lines as nopCommerce sends them: productid, attribute, attributeValue, quantity
	 * @return	int<-3,1>				1 if applied, 0 if the order was already recorded, -1 on a database or stock movement error, -2 if a line matches no single product, -3 if the webshop warehouse lacks the stock
	 */
	public function syncProduct(User $user, $noporderid, array $lines)
	{
		global $langs;

		$langs->loadLangs(array('nopcommerce@nopcommerce'));

		$this->completedorderid = 0;
		$this->completeditems = array();

		$warehouseid = getDolGlobalInt('NOPCOMMERCE_WEBSHOP_WAREHOUSE_ID');
		if ($warehouseid <= 0) {
			$this->error = $langs->transnoentitiesnoconv('NopCommerceWebshopWarehouseNotSet');
			$this->errors[] = $this->error;
			return -1;
		}

		$existing = $this->fetchCompletedOrderId($noporderid);
		if ($existing < 0) {
			return -1;
		}
		if ($existing > 0) {
			$this->completedorderid = $existing;
			return 0;
		}

		$allownegative = getDolGlobalInt('NOPCOMMERCE_ALLOW_NEGATIVE_SOURCE_STOCK');
		$label = $langs->transnoentitiesnoconv('NopCommerceOrderMovementLabel', $noporderid);
		$inventorycode = 'NOPORDER-'.((int) $noporderid);
		$now = dol_now();

		$this->db->begin();

		// The order row goes in first. Its unique key makes a concurrent call for the same
		// order wait here and then fail, instead of taking the stock out a second time.
		$sql = "INSERT INTO ".$this->db->prefix()."nop_order_completed (nop_order_id, date_creation)";
		$sql .= " VALUES (".((int) $noporderid).", '".$this->db->idate($now)."')";

		if (!$this->db->query($sql)) {
			$alreadyexists = ($this->db->lasterrno() == 'DB_ERROR_RECORD_ALREADY_EXISTS');
			$this->error = $this->db->lasterror();
			$this->db->rollback();
			if ($alreadyexists) {
				$this->error = '';
				$this->completedorderid = max(0, $this->fetchCompletedOrderId($noporderid));
				return 0;
			}
			$this->errors[] = $this->error;
			return -1;
		}
		$completedid = (int) $this->db->last_insert_id($this->db->prefix()."nop_order_completed");

		foreach ($lines as $line) {
			$nopproductid = (int) $line['productid'];
			$attribute = isset($line['attribute']) ? trim((string) $line['attribute']) : '';
			$attributevalue = isset($line['attributeValue']) ? trim((string) $line['attributeValue']) : '';
			$qty = (int) $line['quantity'];

			$fk_product = $this->resolveOrderLineProduct($nopproductid, $attribute, $attributevalue);
			if ($fk_product <= 0) {
				$this->db->rollback();
				return $fk_product;
			}

			if (!$allownegative) {
				$available = $this->getStockInWarehouse($fk_product, $warehouseid, true);
				if ($available < $qty) {
					$this->error = $langs->transnoentitiesnoconv('NopCommerceNotEnoughStockInWebshop', $fk_product, $available, $qty);
					$this->errors[] = $this->error;
					$this->db->rollback();
					return -3;
				}
			}

			$movement = new MouvementStock($this->db);
			$movementid = $movement->livraison($user, $fk_product, $warehouseid, $qty, 0, $label, '', '', '', '', 0, $inventorycode);
			if ($movementid < 0) {
				$this->error = $movement->error;
				$this->errors = array_merge($this->errors, $movement->errors);
				$this->db->rollback();
				return -1;
			}

			$sql = "INSERT INTO ".$this->db->prefix()."nop_order_complete_items (fk_nop_order_completed, fk_product, nop_external_id, qty, date_creation)";
			$sql .= " VALUES (".$completedid.", ".((int) $fk_product).", ".$nopproductid.", ".$qty.", '".$this->db->idate($now)."')";

			if (!$this->db->query($sql)) {
				$this->error = $this->db->lasterror();
				$this->errors[] = $this->error;
				$this->db->rollback();
				return -1;
			}

			$this->completeditems[] = array(
				'productid' => $nopproductid,
				'attribute' => $attribute,
				'attributeValue' => $attributevalue,
				'quantity' => $qty,
				'fk_product' => (int) $fk_product,
				'stock_movement_id' => (int) $movementid,
				'stock_in_webshop_warehouse' => $this->getStockInWarehouse($fk_product, $warehouseid),
			);
		}

		$this->db->commit();

		$this->completedorderid = $completedid;

		return 1;
	}

	/**
	 * Return the row id of an order already recorded as completed.
	 *
	 * @param	int		$noporderid		Order id on the nopCommerce side
	 * @return	int						Row id of llx_nop_order_completed, 0 if not recorded, -1 on error
	 */
	public function fetchCompletedOrderId($noporderid)
	{
		$sql = "SELECT rowid FROM ".$this->db->prefix()."nop_order_completed";
		$sql .= " WHERE nop_order_id = ".((int) $noporderid);

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			$this->errors[] = $this->error;
			return -1;
		}
		$obj = $this->db->fetch_object($resql);

		return $obj ? (int) $obj->rowid : 0;
	}

	/**
	 * Reverse an order nopCommerce reports as cancelled, refunded or returned.
	 *
	 * Puts stock back into the webshop warehouse for a previously recorded completed
	 * order, either in full or for the specific items and quantities given. An item can
	 * never be reversed for more than the order still has outstanding for it: each row of
	 * `llx_nop_order_complete_items` tracks how much of its original quantity has already
	 * been reversed, and the check is made against what remains.
	 *
	 * All of it happens in one database transaction: if any requested item cannot be
	 * matched or exceeds what remains, no stock moves and nothing is recorded.
	 *
	 * A reversal already recorded under the same reversalid is not applied again.
	 *
	 * @param	User	$user			User the API call runs as
	 * @param	int		$noporderid		Order id on the nopCommerce side, previously recorded as completed
	 * @param	string	$reversalid		Id of this cancellation/refund/return on the nopCommerce side. Makes a replay idempotent
	 * @param	array<int,array<string,mixed>>	$items	Items to reverse: productid, quantity. Empty to reverse everything still outstanding
	 * @return	int<-3,1>				1 if applied, 0 if this reversal was already recorded, -1 on a database or stock movement error, -2 if the order is not recorded as completed, -3 if an item asks for more than remains outstanding
	 */
	public function reverseOrder(User $user, $noporderid, $reversalid, array $items)
	{
		global $langs;

		$langs->loadLangs(array('nopcommerce@nopcommerce'));

		$this->reversalid = 0;
		$this->reverseditems = array();

		$warehouseid = getDolGlobalInt('NOPCOMMERCE_WEBSHOP_WAREHOUSE_ID');
		if ($warehouseid <= 0) {
			$this->error = $langs->transnoentitiesnoconv('NopCommerceWebshopWarehouseNotSet');
			$this->errors[] = $this->error;
			return -1;
		}

		$completedid = $this->fetchCompletedOrderId($noporderid);
		if ($completedid < 0) {
			return -1;
		}
		if ($completedid == 0) {
			$this->error = $langs->transnoentitiesnoconv('NopCommerceOrderNotFound', $noporderid);
			$this->errors[] = $this->error;
			return -2;
		}
		$this->completedorderid = $completedid;

		$label = $langs->transnoentitiesnoconv('NopCommerceOrderReversalMovementLabel', $noporderid, $reversalid);
		$now = dol_now();

		$this->db->begin();

		$sql = "INSERT INTO ".$this->db->prefix()."nop_order_reversal (fk_nop_order_completed, nop_reversal_id, date_creation)";
		$sql .= " VALUES (".$completedid.", '".$this->db->escape($reversalid)."', '".$this->db->idate($now)."')";

		if (!$this->db->query($sql)) {
			$alreadyexists = ($this->db->lasterrno() == 'DB_ERROR_RECORD_ALREADY_EXISTS');
			$this->error = $this->db->lasterror();
			$this->db->rollback();
			if ($alreadyexists) {
				$this->error = '';
				$this->reversalid = max(0, $this->fetchReversalId($completedid, $reversalid));
				return 0;
			}
			$this->errors[] = $this->error;
			return -1;
		}
		$reversalrowid = (int) $this->db->last_insert_id($this->db->prefix()."nop_order_reversal");
		$inventorycode = 'NOPREVERSAL-'.((int) $noporderid).'-'.$reversalrowid;

		if (empty($items)) {
			$plan = $this->planFullReversal($completedid);
			if ($plan === false) {
				$this->db->rollback();
				return -1;
			}
		} else {
			$plan = array();
			foreach ($items as $item) {
				$nopproductid = (int) $item['productid'];
				$qty = (int) $item['quantity'];

				$applied = $this->planItemReversal($completedid, $nopproductid, $qty, $plan);
				if ($applied < 0) {
					$this->db->rollback();
					return $applied;
				}
			}
		}

		foreach ($plan as $entry) {
			$movement = new MouvementStock($this->db);
			$movementid = $movement->reception($user, $entry['fk_product'], $warehouseid, $entry['qty'], 0, $label, '', '', '', '', 0, $inventorycode);
			if ($movementid < 0) {
				$this->error = $movement->error;
				$this->errors = array_merge($this->errors, $movement->errors);
				$this->db->rollback();
				return -1;
			}

			$sqlupd = "UPDATE ".$this->db->prefix()."nop_order_complete_items";
			$sqlupd .= " SET qty_reversed = qty_reversed + ".((int) $entry['qty']);
			$sqlupd .= " WHERE rowid = ".((int) $entry['rowid']);

			if (!$this->db->query($sqlupd)) {
				$this->error = $this->db->lasterror();
				$this->errors[] = $this->error;
				$this->db->rollback();
				return -1;
			}

			$sqlins = "INSERT INTO ".$this->db->prefix()."nop_order_reversal_items (fk_nop_order_reversal, fk_product, nop_external_id, qty, date_creation)";
			$sqlins .= " VALUES (".$reversalrowid.", ".((int) $entry['fk_product']).", ".($entry['nop_external_id'] !== null ? (int) $entry['nop_external_id'] : 'NULL').", ".((int) $entry['qty']).", '".$this->db->idate($now)."')";

			if (!$this->db->query($sqlins)) {
				$this->error = $this->db->lasterror();
				$this->errors[] = $this->error;
				$this->db->rollback();
				return -1;
			}

			$this->reverseditems[] = array(
				'productid' => $entry['nop_external_id'],
				'quantity' => (int) $entry['qty'],
				'fk_product' => (int) $entry['fk_product'],
				'stock_movement_id' => (int) $movementid,
				'stock_in_webshop_warehouse' => $this->getStockInWarehouse($entry['fk_product'], $warehouseid),
			);
		}

		$this->db->commit();

		$this->reversalid = $reversalrowid;

		return 1;
	}

	/**
	 * Build the reversal plan for every item of an order that still has an outstanding
	 * quantity, locking the rows until the transaction ends.
	 *
	 * @param	int		$completedid	Row id of llx_nop_order_completed
	 * @return	array<int,array<string,mixed>>|false	Plan keyed by item row id, false on a database error
	 */
	private function planFullReversal($completedid)
	{
		$plan = array();

		$sql = "SELECT rowid, fk_product, nop_external_id, qty, qty_reversed FROM ".$this->db->prefix()."nop_order_complete_items";
		$sql .= " WHERE fk_nop_order_completed = ".((int) $completedid);
		$sql .= " AND qty > qty_reversed";
		$sql .= " ORDER BY rowid ASC";
		$sql .= " FOR UPDATE";

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			$this->errors[] = $this->error;
			return false;
		}

		while ($obj = $this->db->fetch_object($resql)) {
			$remaining = ((int) $obj->qty) - ((int) $obj->qty_reversed);
			if ($remaining <= 0) {
				continue;
			}
			$plan[(int) $obj->rowid] = array(
				'rowid' => (int) $obj->rowid,
				'fk_product' => (int) $obj->fk_product,
				'nop_external_id' => $obj->nop_external_id !== null ? (int) $obj->nop_external_id : null,
				'qty' => $remaining,
			);
		}

		return $plan;
	}

	/**
	 * Add the reversal of one requested item to the plan, spending the outstanding
	 * quantity of the matching item rows of the order oldest first, locking the rows
	 * until the transaction ends.
	 *
	 * Matches on the nopCommerce product id recorded at completion time
	 * (`nop_external_id`), the same value the request carries, so no catalog lookup is
	 * needed: the product was already resolved once, when the order was completed.
	 *
	 * @param	int							$completedid	Row id of llx_nop_order_completed
	 * @param	int							$nopproductid	Product id on the nopCommerce side
	 * @param	int							$qtyneeded		Quantity to reverse for this item
	 * @param	array<int,array<string,mixed>>	$plan		Plan being built, keyed by item row id. Modified in place
	 * @return	int<-3,0>									0 on success, -1 on a database error, -3 if not enough remains outstanding
	 */
	private function planItemReversal($completedid, $nopproductid, $qtyneeded, array &$plan)
	{
		global $langs;

		$sql = "SELECT rowid, fk_product, nop_external_id, qty, qty_reversed FROM ".$this->db->prefix()."nop_order_complete_items";
		$sql .= " WHERE fk_nop_order_completed = ".((int) $completedid);
		$sql .= " AND nop_external_id = ".((int) $nopproductid);
		$sql .= " AND qty > qty_reversed";
		$sql .= " ORDER BY rowid ASC";
		$sql .= " FOR UPDATE";

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			$this->errors[] = $this->error;
			return -1;
		}

		$remainingneeded = $qtyneeded;
		while ($remainingneeded > 0 && ($obj = $this->db->fetch_object($resql))) {
			$rowid = (int) $obj->rowid;
			$alreadyplanned = isset($plan[$rowid]) ? $plan[$rowid]['qty'] : 0;
			$available = ((int) $obj->qty) - ((int) $obj->qty_reversed) - $alreadyplanned;
			if ($available <= 0) {
				continue;
			}
			$take = min($available, $remainingneeded);

			if (!isset($plan[$rowid])) {
				$plan[$rowid] = array(
					'rowid' => $rowid,
					'fk_product' => (int) $obj->fk_product,
					'nop_external_id' => $obj->nop_external_id !== null ? (int) $obj->nop_external_id : null,
					'qty' => 0,
				);
			}
			$plan[$rowid]['qty'] += $take;
			$remainingneeded -= $take;
		}

		if ($remainingneeded > 0) {
			$this->error = $langs->transnoentitiesnoconv('NopCommerceNotEnoughRecordedToReverse', $nopproductid, ($qtyneeded - $remainingneeded), $qtyneeded);
			$this->errors[] = $this->error;
			return -3;
		}

		return 0;
	}

	/**
	 * Return the row id of a reversal already recorded for an order.
	 *
	 * @param	int		$completedid	Row id of llx_nop_order_completed
	 * @param	string	$reversalid		Id of the reversal on the nopCommerce side
	 * @return	int						Row id of llx_nop_order_reversal, 0 if not recorded, -1 on error
	 */
	public function fetchReversalId($completedid, $reversalid)
	{
		$sql = "SELECT rowid FROM ".$this->db->prefix()."nop_order_reversal";
		$sql .= " WHERE fk_nop_order_completed = ".((int) $completedid);
		$sql .= " AND nop_reversal_id = '".$this->db->escape($reversalid)."'";

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			$this->errors[] = $this->error;
			return -1;
		}
		$obj = $this->db->fetch_object($resql);

		return $obj ? (int) $obj->rowid : 0;
	}

	/**
	 * Find the Dolibarr product a sold nopCommerce line takes its stock from.
	 *
	 * The product is the one whose extrafield nopcommerce_external_id holds the nopCommerce
	 * product id. When it has variants, the variant is the one carrying the sold value, for
	 * example M. The attribute name is only used to choose between several variants that
	 * carry that value, because nopCommerce and Dolibarr may name the attribute differently
	 * (Size on one side, Veličina on the other).
	 *
	 * @param	int		$nopproductid		Product id on the nopCommerce side
	 * @param	string	$attribute			Attribute name, for example Size. May be empty
	 * @param	string	$attributevalue		Sold attribute value, for example M. May be empty for a product without variants
	 * @return	int							Id of the product to take the stock from, -1 on a database error, -2 when no single product matches
	 */
	public function resolveOrderLineProduct($nopproductid, $attribute, $attributevalue)
	{
		global $langs;

		// Creating variants clones the parent, extrafields included, so the variants usually
		// carry the parent's external id too. They are set aside in favour of their parent.
		$sql = "SELECT e.fk_object, pc.fk_product_parent FROM ".$this->db->prefix()."product_extrafields as e";
		$sql .= " INNER JOIN ".$this->db->prefix()."product as p ON p.rowid = e.fk_object";
		$sql .= " LEFT JOIN ".$this->db->prefix()."product_attribute_combination as pc ON pc.fk_product_child = e.fk_object";
		$sql .= " WHERE e.nopcommerce_external_id = ".((int) $nopproductid);
		$sql .= " AND p.entity IN (".getEntity('product').")";

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			$this->errors[] = $this->error;
			return -1;
		}

		$productids = array();
		$parentids = array();
		while ($obj = $this->db->fetch_object($resql)) {
			if (empty($obj->fk_product_parent)) {
				$productids[(int) $obj->fk_object] = (int) $obj->fk_object;
			} else {
				$parentids[(int) $obj->fk_product_parent] = (int) $obj->fk_product_parent;
			}
		}
		// Only variants hold the external id: use their parent when they share one.
		if (count($productids) == 0 && count($parentids) == 1) {
			$productids = $parentids;
		}
		$productids = array_values($productids);

		if (count($productids) != 1) {
			$this->error = $langs->transnoentitiesnoconv(count($productids) ? 'NopCommerceProductAmbiguous' : 'NopCommerceProductNotFound', $nopproductid);
			$this->errors[] = $this->error;
			return -2;
		}
		$productid = $productids[0];

		$sql = "SELECT c.fk_product_child, pa.ref as attribute_ref, pa.label as attribute_label,";
		$sql .= " pav.ref as value_ref, pav.value as value_label";
		$sql .= " FROM ".$this->db->prefix()."product_attribute_combination as c";
		$sql .= " INNER JOIN ".$this->db->prefix()."product_attribute_combination2val as c2v ON c2v.fk_prod_combination = c.rowid";
		$sql .= " INNER JOIN ".$this->db->prefix()."product_attribute as pa ON pa.rowid = c2v.fk_prod_attr";
		$sql .= " INNER JOIN ".$this->db->prefix()."product_attribute_value as pav ON pav.rowid = c2v.fk_prod_attr_val";
		$sql .= " WHERE c.fk_product_parent = ".((int) $productid);
		$sql .= " AND c.entity IN (".getEntity('product').")";

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			$this->errors[] = $this->error;
			return -1;
		}

		$hasvariants = false;
		$matchingvalue = array();
		$matchingattribute = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$hasvariants = true;
			if (!$this->sameText($obj->value_ref, $attributevalue) && !$this->sameText($obj->value_label, $attributevalue)) {
				continue;
			}
			$matchingvalue[(int) $obj->fk_product_child] = true;
			if ($this->sameText($obj->attribute_ref, $attribute) || $this->sameText($obj->attribute_label, $attribute)) {
				$matchingattribute[(int) $obj->fk_product_child] = true;
			}
		}

		// A product without variants, or a variant child that holds the external id itself.
		if (!$hasvariants) {
			return $productid;
		}

		if ($attributevalue === '') {
			$this->error = $langs->transnoentitiesnoconv('NopCommerceVariantValueRequired', $nopproductid);
			$this->errors[] = $this->error;
			return -2;
		}

		$candidates = array_keys($matchingvalue);
		if (count($candidates) > 1 && count($matchingattribute) > 0) {
			$candidates = array_keys($matchingattribute);
		}

		if (count($candidates) != 1) {
			$this->error = $langs->transnoentitiesnoconv(count($candidates) ? 'NopCommerceVariantAmbiguous' : 'NopCommerceVariantNotFound', $nopproductid, $attribute, $attributevalue);
			$this->errors[] = $this->error;
			return -2;
		}

		return $candidates[0];
	}

	/**
	 * Compare two labels the way a shop user would: trimmed and case-insensitive.
	 *
	 * @param	string|null	$stored		Value stored in Dolibarr
	 * @param	string		$sent		Value sent by nopCommerce
	 * @return	bool					True when they match. An empty sent value never matches
	 */
	private function sameText($stored, $sent)
	{
		if ($sent === '') {
			return false;
		}

		return mb_strtolower(trim((string) $stored), 'UTF-8') === mb_strtolower($sent, 'UTF-8');
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
