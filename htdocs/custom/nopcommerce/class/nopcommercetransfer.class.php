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
 * \file        htdocs/custom/nopcommerce/class/nopcommercetransfer.class.php
 * \ingroup     nopcommerce
 * \brief       CRUD class for a nopCommerce sync transfer.
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/commonobject.class.php';
dol_include_once('/nopcommerce/class/nopcommercetransferline.class.php');


/**
 * Class for a nopCommerce sync transfer.
 *
 * A transfer holds the products that move from the parent warehouse to the webshop
 * warehouse. It is the unit nopCommerce pulls, and the unit it acknowledges.
 *
 * Life cycle:
 *   DRAFT     lines are editable, nothing is exposed to nopCommerce
 *   PENDING   exposed to nopCommerce, no stock has moved yet
 *   SYNCED    nopCommerce confirmed, stock moved, sync_flag = 1
 *   FAILED    nopCommerce reported an error, no stock moved, still pullable
 *   CANCELED  abandoned before it was ever synced
 */
class NopCommerceTransfer extends CommonObject
{
	/**
	 * @var string ID of module.
	 */
	public $module = 'nopcommerce';

	/**
	 * @var string ID to identify managed object.
	 */
	public $element = 'nopcommercetransfer';

	/**
	 * @var string Prefix used to build trigger codes.
	 */
	public $TRIGGER_PREFIX = 'NOPCOMMERCE_TRANSFER';

	/**
	 * @var string Name of table without prefix where object is stored.
	 */
	public $table_element = 'nopcommerce_transfer';

	/**
	 * @var string Name of the line table without prefix.
	 */
	public $table_element_line = 'nopcommerce_transferline';

	/**
	 * @var string Field of the line table pointing back to this object.
	 */
	public $fk_element = 'fk_nopcommercetransfer';

	/**
	 * @var string Class name of a line.
	 */
	public $class_element_line = 'NopCommerceTransferLine';

	/**
	 * @var string Permissions are flat on this module: hasRight('nopcommerce', 'read').
	 */
	public $element_for_permission = 'nopcommerce';

	/**
	 * @var string Picto.
	 */
	public $picto = 'stock';

	/**
	 * @var int<0,1> Does object support extrafields ?
	 */
	public $isextrafieldmanaged = 0;

	/**
	 * @var int<0,1>|string Does this object support multicompany module ?
	 */
	public $ismultientitymanaged = 1;

	const STATUS_DRAFT = 0;
	const STATUS_PENDING = 1;
	const STATUS_SYNCED = 2;
	const STATUS_FAILED = 3;
	const STATUS_CANCELED = 9;

	const ORIGIN_MANUAL = 'manual';
	const ORIGIN_NATIVE = 'native';

	/**
	 * Value written to llx_stock_mouvement.origintype so the stock movement list can
	 * resolve a movement back to its transfer. The part before the "@" must match the
	 * class file name on disk, the part after it the module directory.
	 */
	const ORIGIN_TYPE = 'nopcommercetransfer@nopcommerce';

	/**
	 * @inheritdoc
	 * @var array<string,array<string,mixed>>
	 */
	public $fields = array(
		'rowid' => array('type' => 'integer', 'label' => 'TechnicalID', 'enabled' => 1, 'position' => 1, 'notnull' => 1, 'visible' => 0, 'noteditable' => 1, 'index' => 1, 'css' => 'left'),
		'ref' => array('type' => 'varchar(128)', 'label' => 'Ref', 'enabled' => 1, 'position' => 20, 'notnull' => 1, 'visible' => 1, 'index' => 1, 'searchall' => 1, 'showoncombobox' => 1, 'noteditable' => 1, 'default' => '(PROV)'),
		'entity' => array('type' => 'integer', 'label' => 'Entity', 'enabled' => 1, 'position' => 25, 'notnull' => 1, 'visible' => 0, 'index' => 1),
		'label' => array('type' => 'varchar(255)', 'label' => 'Label', 'enabled' => 1, 'position' => 30, 'notnull' => 0, 'visible' => 1, 'alwayseditable' => 1, 'searchall' => 1, 'css' => 'minwidth300'),
		'fk_warehouse_source' => array('type' => 'integer:Entrepot:product/stock/class/entrepot.class.php', 'label' => 'NopCommerceSourceWarehouse', 'picto' => 'stock', 'enabled' => 1, 'position' => 40, 'notnull' => 1, 'visible' => 1, 'index' => 1, 'csslist' => 'tdoverflowmax150'),
		'fk_warehouse_destination' => array('type' => 'integer:Entrepot:product/stock/class/entrepot.class.php', 'label' => 'NopCommerceWebshopWarehouse', 'picto' => 'stock', 'enabled' => 1, 'position' => 41, 'notnull' => 1, 'visible' => 1, 'index' => 1, 'csslist' => 'tdoverflowmax150'),
		'sync_flag' => array('type' => 'integer', 'label' => 'NopCommerceSyncFlag', 'enabled' => 1, 'position' => 50, 'notnull' => 1, 'visible' => 1, 'default' => '0', 'noteditable' => 1, 'index' => 1, 'arrayofkeyval' => array(0 => 'NopCommerceSyncFalse', 1 => 'NopCommerceSyncTrue')),
		'origin' => array('type' => 'varchar(16)', 'label' => 'NopCommerceOrigin', 'enabled' => 1, 'position' => 49, 'notnull' => 1, 'visible' => 0, 'noteditable' => 1, 'default' => 'manual'),
		'sync_attempts' => array('type' => 'integer', 'label' => 'NopCommerceSyncAttempts', 'enabled' => 1, 'position' => 51, 'notnull' => 1, 'visible' => -1, 'default' => '0', 'noteditable' => 1),
		'sync_last_error' => array('type' => 'text', 'label' => 'NopCommerceSyncLastError', 'enabled' => 1, 'position' => 52, 'notnull' => 0, 'visible' => -1, 'noteditable' => 1, 'cssview' => 'wordbreak'),
		'pull_token' => array('type' => 'varchar(64)', 'label' => 'NopCommercePullToken', 'enabled' => 1, 'position' => 53, 'notnull' => 0, 'visible' => 0, 'noteditable' => 1),
		'date_pulled' => array('type' => 'datetime', 'label' => 'NopCommerceDatePulled', 'enabled' => 1, 'position' => 54, 'notnull' => 0, 'visible' => -1, 'noteditable' => 1),
		'date_synced' => array('type' => 'datetime', 'label' => 'NopCommerceDateSynced', 'enabled' => 1, 'position' => 55, 'notnull' => 0, 'visible' => -1, 'noteditable' => 1),
		'note_public' => array('type' => 'html', 'label' => 'NotePublic', 'enabled' => 1, 'position' => 61, 'notnull' => 0, 'visible' => 0),
		'note_private' => array('type' => 'html', 'label' => 'NotePrivate', 'enabled' => 1, 'position' => 62, 'notnull' => 0, 'visible' => 0),
		'date_creation' => array('type' => 'datetime', 'label' => 'DateCreation', 'enabled' => 1, 'position' => 500, 'notnull' => 1, 'visible' => -2),
		// tms is maintained by the database through ON UPDATE CURRENT_TIMESTAMP, so it is deliberately not declared here
		'fk_user_creat' => array('type' => 'integer:User:user/class/user.class.php', 'label' => 'UserAuthor', 'picto' => 'user', 'enabled' => 1, 'position' => 510, 'notnull' => 1, 'visible' => -2, 'foreignkey' => '0'),
		'fk_user_modif' => array('type' => 'integer:User:user/class/user.class.php', 'label' => 'UserModif', 'picto' => 'user', 'enabled' => 1, 'position' => 511, 'notnull' => -1, 'visible' => -2),
		'import_key' => array('type' => 'varchar(14)', 'label' => 'ImportId', 'enabled' => 1, 'position' => 1000, 'notnull' => -1, 'visible' => -2),
		'status' => array('type' => 'integer', 'label' => 'Status', 'enabled' => 1, 'position' => 2000, 'notnull' => 1, 'visible' => 1, 'index' => 1, 'default' => '0', 'arrayofkeyval' => array(0 => 'Draft', 1 => 'NopCommerceStatusPending', 2 => 'NopCommerceStatusSynced', 3 => 'NopCommerceStatusFailed', 9 => 'Canceled')),
	);

	/**
	 * @var int ID
	 */
	public $rowid;
	/**
	 * @var string Ref
	 */
	public $ref;
	/**
	 * @var int Entity
	 */
	public $entity;
	/**
	 * @var string Label
	 */
	public $label;
	/**
	 * @var int Source (parent) warehouse id
	 */
	public $fk_warehouse_source;
	/**
	 * @var int Destination (webshop) warehouse id
	 */
	public $fk_warehouse_destination;
	/**
	 * @var int 0 = not synced with nopCommerce, 1 = synced
	 */
	public $sync_flag;
	/**
	 * @var string self::ORIGIN_MANUAL when built by hand, self::ORIGIN_NATIVE when captured
	 *             from a native stock transfer. Drives stockAlreadyMoved().
	 */
	public $origin = self::ORIGIN_MANUAL;
	/**
	 * @var int Number of acknowledgements received so far
	 */
	public $sync_attempts;
	/**
	 * @var ?string Last error reported by nopCommerce
	 */
	public $sync_last_error;
	/**
	 * @var ?string Token issued on the last pull, required to acknowledge
	 */
	public $pull_token;
	/**
	 * @var ?int Date of last pull
	 */
	public $date_pulled;
	/**
	 * @var ?int Date the transfer was confirmed by nopCommerce
	 */
	public $date_synced;
	/**
	 * @var ?int Status
	 */
	public $status;
	/**
	 * @var ?int Date of creation
	 */
	public $date_creation;
	/**
	 * @var int User that created
	 */
	public $fk_user_creat;
	/**
	 * @var ?int User that modified
	 */
	public $fk_user_modif;
	/**
	 * @var ?string Import key
	 */
	public $import_key;

	/**
	 * @var NopCommerceTransferLine[] Lines
	 */
	public $lines = array();


	/**
	 * Constructor
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct(DoliDB $db)
	{
		global $langs;

		$this->db = $db;

		if (!getDolGlobalInt('MAIN_SHOW_TECHNICAL_ID') && isset($this->fields['rowid'])) {
			$this->fields['rowid']['visible'] = 0;
		}
		if (!isModEnabled('multicompany') && isset($this->fields['entity'])) {
			$this->fields['entity']['enabled'] = 0;
		}

		foreach ($this->fields as $key => $val) {
			if (isset($val['enabled']) && empty($val['enabled'])) {
				unset($this->fields[$key]);
			}
		}

		if (is_object($langs)) {
			foreach ($this->fields as $key => $val) {
				if (!empty($val['arrayofkeyval']) && is_array($val['arrayofkeyval'])) {
					foreach ($val['arrayofkeyval'] as $key2 => $val2) {
						$this->fields[$key]['arrayofkeyval'][$key2] = $langs->trans($val2);
					}
				}
			}
		}
	}

	/**
	 * Create object into database
	 *
	 * @param	User		$user		User that creates
	 * @param	int<0,1>	$notrigger	0=launch triggers after, 1=disable triggers
	 * @return	int<-1,max>				Return integer <0 if KO, Id of created object if OK
	 */
	public function create(User $user, $notrigger = 0)
	{
		global $conf;

		if (empty($this->fk_warehouse_source) || empty($this->fk_warehouse_destination)) {
			$this->error = 'BothWarehousesAreRequired';
			$this->errors[] = $this->error;
			return -1;
		}
		if ($this->fk_warehouse_source == $this->fk_warehouse_destination) {
			$this->error = 'SourceAndDestinationWarehousesMustDiffer';
			$this->errors[] = $this->error;
			return -1;
		}

		if (empty($this->entity)) {
			$this->entity = $conf->entity;
		}
		$this->status = self::STATUS_DRAFT;
		$this->sync_flag = 0;
		$this->sync_attempts = 0;

		return $this->createCommon($user, $notrigger);
	}

	/**
	 * Load object in memory from the database
	 *
	 * @param	int		$id		Id object
	 * @param	?string	$ref	Ref
	 * @return	int<-1,1>		Return integer <0 if KO, 0 if not found, >0 if OK
	 */
	public function fetch($id, $ref = null)
	{
		return $this->fetchCommon($id, $ref);
	}

	/**
	 * Load the lines of the transfer into $this->lines
	 *
	 * @return int<-1,1>	Return integer <0 if KO, >0 if OK
	 */
	public function fetchLines()
	{
		$this->lines = array();

		return $this->fetchLinesCommon('', 1);
	}

	/**
	 * Update object into database
	 *
	 * @param	User		$user		User that modifies
	 * @param	int<0,1>	$notrigger	0=launch triggers after, 1=disable triggers
	 * @return	int<-1,1>				Return integer <0 if KO, >0 if OK
	 */
	public function update(User $user, $notrigger = 0)
	{
		return $this->updateCommon($user, $notrigger);
	}

	/**
	 * Delete object in database, lines first.
	 *
	 * @param	User		$user		User that deletes
	 * @param	int<0,1>	$notrigger	0=launch triggers after, 1=disable triggers
	 * @return	int<-1,1>				Return integer <0 if KO, >0 if OK
	 */
	public function delete(User $user, $notrigger = 0)
	{
		if ($this->status == self::STATUS_SYNCED) {
			$this->error = 'CannotDeleteASyncedTransfer';
			$this->errors[] = $this->error;
			return -1;
		}

		$this->db->begin();

		$sql = "DELETE FROM ".$this->db->prefix().$this->table_element_line;
		$sql .= " WHERE ".$this->fk_element." = ".((int) $this->id);
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			$this->errors[] = $this->error;
			$this->db->rollback();
			return -1;
		}

		$result = $this->deleteCommon($user, $notrigger);
		if ($result <= 0) {
			$this->db->rollback();
			return -1;
		}

		$this->db->commit();

		return 1;
	}

	/**
	 * Add a product line to a draft transfer.
	 *
	 * @param	User	$user		User that adds the line
	 * @param	int		$fk_product	Id of the product (a variant child product for a sized article)
	 * @param	float	$qty		Quantity to transfer, must be > 0
	 * @param	string	$batch		Lot or serial number, empty when the product is not batch managed
	 * @return	int<-1,max>			Return integer <0 if KO, id of the line if OK
	 */
	public function addLine(User $user, $fk_product, $qty, $batch = '')
	{
		if ($this->status != self::STATUS_DRAFT) {
			$this->error = 'OnlyADraftTransferCanBeModified';
			$this->errors[] = $this->error;
			return -1;
		}
		if ($qty <= 0) {
			$this->error = 'QuantityMustBePositive';
			$this->errors[] = $this->error;
			return -1;
		}

		require_once DOL_DOCUMENT_ROOT.'/variants/class/ProductCombination.class.php';

		$combination = new ProductCombination($this->db);
		$fk_product_parent = 0;
		if ($combination->fetchByFkProductChild($fk_product, 1) > 0) {
			$fk_product_parent = $combination->fk_product_parent;
		}

		$line = new NopCommerceTransferLine($this->db);
		$line->fk_nopcommercetransfer = $this->id;
		$line->fk_product = $fk_product;
		$line->fk_product_parent = $fk_product_parent;
		$line->qty = $qty;
		$line->batch = $batch;
		$line->position = $this->getNextLinePosition();
		$line->sync_flag = 0;

		$result = $line->create($user);
		if ($result < 0) {
			$this->error = $line->error;
			$this->errors = $line->errors;
			return -1;
		}

		return $result;
	}

	/**
	 * Delete one line of a draft transfer.
	 *
	 * @param	User	$user		User that deletes the line
	 * @param	int		$idline		Id of the line
	 * @return	int<-1,1>			Return integer <0 if KO, >0 if OK
	 */
	public function deleteLine(User $user, $idline)
	{
		if ($this->status != self::STATUS_DRAFT) {
			$this->error = 'OnlyADraftTransferCanBeModified';
			$this->errors[] = $this->error;
			return -1;
		}

		return $this->deleteLineCommon($user, $idline);
	}

	/**
	 * Return the position to use for the next line.
	 *
	 * @return int	Next position
	 */
	protected function getNextLinePosition()
	{
		$sql = "SELECT MAX(position) as maxposition FROM ".$this->db->prefix().$this->table_element_line;
		$sql .= " WHERE ".$this->fk_element." = ".((int) $this->id);

		$resql = $this->db->query($sql);
		if (!$resql) {
			return 0;
		}
		$obj = $this->db->fetch_object($resql);

		return $obj ? ((int) $obj->maxposition + 1) : 0;
	}

	/**
	 * Move the transfer to PENDING so nopCommerce can pull it. No stock is moved here:
	 * for a manual transfer the stock moves once nopCommerce acknowledges it, and for a
	 * captured one it already moved on the native stock transfer page.
	 *
	 * @param	User		$user		User that validates
	 * @param	int<0,1>	$notrigger	0=launch triggers after, 1=disable triggers
	 * @param	string		$forceref	Ref to use verbatim. Capture passes a ref derived
	 *                                  from the destination stock movement id, which is
	 *                                  unique by construction and so cannot race the way
	 *                                  getNextNumRef() does. Empty falls back to the counter.
	 * @return	int<-1,1>				Return integer <0 if KO, >0 if OK
	 */
	public function validate(User $user, $notrigger = 0, $forceref = '')
	{
		if ($this->status != self::STATUS_DRAFT) {
			$this->error = 'OnlyADraftTransferCanBeValidated';
			$this->errors[] = $this->error;
			return -1;
		}

		$this->fetchLines();
		if (empty($this->lines)) {
			$this->error = 'ATransferNeedsAtLeastOneLine';
			$this->errors[] = $this->error;
			return -1;
		}

		$this->db->begin();

		$newref = ($forceref !== '') ? $forceref : $this->getNextNumRef();
		if ($newref === '') {
			$this->error = 'FailedToBuildTheNextRef';
			$this->errors[] = $this->error;
			$this->db->rollback();
			return -1;
		}

		$sql = "UPDATE ".$this->db->prefix().$this->table_element;
		$sql .= " SET ref = '".$this->db->escape($newref)."',";
		$sql .= " status = ".((int) self::STATUS_PENDING);
		$sql .= " WHERE rowid = ".((int) $this->id);

		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			$this->errors[] = $this->error;
			$this->db->rollback();
			return -1;
		}

		if (!$notrigger) {
			$result = $this->call_trigger($this->TRIGGER_PREFIX.'_VALIDATE', $user);
			if ($result < 0) {
				$this->db->rollback();
				return -1;
			}
		}

		$this->ref = $newref;
		$this->status = self::STATUS_PENDING;

		$this->db->commit();

		return 1;
	}

	/**
	 * Cancel the transfer. Refused once nopCommerce has confirmed it.
	 *
	 * @param	User		$user		User that acts
	 * @param	int<0,1>	$notrigger	0=launch triggers after, 1=disable triggers
	 * @return	int<-1,1>				Return integer <0 if KO, >0 if OK
	 */
	public function cancel(User $user, $notrigger = 0)
	{
		if ($this->status == self::STATUS_SYNCED) {
			$this->error = 'CannotCancelASyncedTransfer';
			$this->errors[] = $this->error;
			return -1;
		}

		$result = $this->setStatusCommon($user, self::STATUS_CANCELED, $notrigger, $this->TRIGGER_PREFIX.'_CANCEL');
		if ($result < 0) {
			return -1;
		}

		return $this->clearPullToken();
	}

	/**
	 * Drop the pull token so an acknowledgement that is still in flight cannot be applied.
	 *
	 * @return int<-1,1>	Return integer <0 if KO, >0 if OK
	 */
	protected function clearPullToken()
	{
		$sql = "UPDATE ".$this->db->prefix().$this->table_element;
		$sql .= " SET pull_token = NULL";
		$sql .= " WHERE rowid = ".((int) $this->id);

		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			$this->errors[] = $this->error;
			return -1;
		}

		$this->pull_token = null;

		return 1;
	}

	/**
	 * Return true when the transfer is waiting for nopCommerce, that is when it is
	 * exposed by the pull endpoint. A failed transfer stays pullable so a retry works.
	 *
	 * @return bool	True when nopCommerce may pull this transfer
	 */
	public function isPullable()
	{
		return in_array((int) $this->status, array(self::STATUS_PENDING, self::STATUS_FAILED), true);
	}

	/**
	 * Return true when this transfer's stock was already moved outside the sync.
	 *
	 * A captured transfer moves its stock on the native stock transfer page, before
	 * nopCommerce ever sees it, so the acknowledgement must not move it a second time.
	 * Derived from the origin rather than stored, so a further capture source needs no
	 * schema change.
	 *
	 * @return bool	True when the stock has already moved
	 */
	public function stockAlreadyMoved()
	{
		return $this->origin !== self::ORIGIN_MANUAL;
	}

	/**
	 * Build the next ref, in the form NOP{yymm}-{counter}.
	 *
	 * @return string	Next ref, or an empty string on failure
	 */
	public function getNextNumRef()
	{
		$prefix = 'NOP'.dol_print_date(dol_now(), '%y%m').'-';

		$sql = "SELECT ref FROM ".$this->db->prefix().$this->table_element;
		$sql .= " WHERE ref LIKE '".$this->db->escape($prefix)."%'";
		$sql .= " AND entity = ".((int) $this->entity);
		$sql .= " ORDER BY ref DESC";
		$sql .= $this->db->plimit(1);

		$resql = $this->db->query($sql);
		if (!$resql) {
			return '';
		}

		$counter = 1;
		$obj = $this->db->fetch_object($resql);
		if ($obj) {
			$counter = ((int) substr($obj->ref, strlen($prefix))) + 1;
		}

		return $prefix.sprintf('%04d', $counter);
	}

	/**
	 * Return the label of the status
	 *
	 * @param	int<0,6>	$mode	0=long label, 1=short label, 2=Picto + short label, 3=Picto, 4=Picto + long label, 5=Short label + Picto, 6=Long label + Picto
	 * @return	string				Label of status
	 */
	public function getLibStatut($mode = 0)
	{
		return $this->LibStatut($this->status, $mode);
	}

	// phpcs:disable PEAR.NamingConventions.ValidFunctionName.ScopeNotCamelCaps
	/**
	 * Return the label of a given status
	 *
	 * @param	?int		$status	Id status
	 * @param	int<0,6>	$mode	0=long label, 1=short label, 2=Picto + short label, 3=Picto, 4=Picto + long label, 5=Short label + Picto, 6=Long label + Picto
	 * @return	string				Label of status
	 */
	public function LibStatut($status, $mode = 0)
	{
		// phpcs:enable
		global $langs;

		$langs->loadLangs(array('nopcommerce@nopcommerce'));

		$labels = array(
			self::STATUS_DRAFT => $langs->transnoentitiesnoconv('Draft'),
			self::STATUS_PENDING => $langs->transnoentitiesnoconv('NopCommerceStatusPending'),
			self::STATUS_SYNCED => $langs->transnoentitiesnoconv('NopCommerceStatusSynced'),
			self::STATUS_FAILED => $langs->transnoentitiesnoconv('NopCommerceStatusFailed'),
			self::STATUS_CANCELED => $langs->transnoentitiesnoconv('Canceled'),
		);

		$statuscodes = array(
			self::STATUS_DRAFT => 'status0',
			self::STATUS_PENDING => 'status1',
			self::STATUS_SYNCED => 'status4',
			self::STATUS_FAILED => 'status8',
			self::STATUS_CANCELED => 'status9',
		);

		$status = (int) $status;
		$label = isset($labels[$status]) ? $labels[$status] : '';
		$statuscode = isset($statuscodes[$status]) ? $statuscodes[$status] : 'status0';

		return dolGetStatus($label, $label, '', $statuscode, $mode);
	}

	/**
	 * Return a link to the object card
	 *
	 * @param	int		$withpicto				Include picto in link
	 * @param	string	$option					On what the link points to
	 * @param	int		$notooltip				1=Disable tooltip
	 * @param	string	$morecss				Add more css on link
	 * @param	int		$save_lastsearch_value	-1=Auto, 0=No save of lastsearch_values when clicking, 1=Save lastsearch_values
	 * @return	string							String with URL
	 */
	public function getNomUrl($withpicto = 0, $option = '', $notooltip = 0, $morecss = '', $save_lastsearch_value = -1)
	{
		global $conf, $langs;

		if (!empty($conf->dol_no_mouse_hover)) {
			$notooltip = 1;
		}

		// A transfer has no card page: it is an internal envelope, never edited by hand. The
		// link goes to the sync list filtered on this ref, which is the only place it is shown.
		$url = dol_buildpath('/nopcommerce/transfer_list.php', 1).'?search_ref='.urlencode((string) $this->ref);

		$label = img_picto('', $this->picto).' <u>'.$langs->trans("NopCommerceTransfer").'</u>';
		$label .= '<br><b>'.$langs->trans('Ref').':</b> '.$this->ref;

		$linkstart = '<a href="'.$url.'" title="'.dol_escape_htmltag($label, 1).'" class="classfortooltip'.($morecss ? ' '.$morecss : '').'">';
		$linkend = '</a>';

		$result = $linkstart;
		if ($withpicto) {
			$result .= img_object(($notooltip ? '' : $label), $this->picto, 'class="paddingright"', 0, 0, $notooltip ? 0 : 1);
		}
		$result .= $this->ref;
		$result .= $linkend;

		return $result;
	}

	/**
	 * Initialise object with example values
	 *
	 * @return int
	 */
	public function initAsSpecimen()
	{
		$this->initAsSpecimenCommon();
		$this->ref = 'NOP-M1187';

		return 1;
	}
}
