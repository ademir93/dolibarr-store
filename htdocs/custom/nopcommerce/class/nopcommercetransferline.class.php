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
 * \file        htdocs/custom/nopcommerce/class/nopcommercetransferline.class.php
 * \ingroup     nopcommerce
 * \brief       CRUD class for one product line of a nopCommerce sync transfer.
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/commonobjectline.class.php';


/**
 * Class for one product line of a nopCommerce sync transfer.
 *
 * One line is one product. For an article with sizes, the product is the variant child
 * product, so each size is its own line and carries its own sync flag.
 */
class NopCommerceTransferLine extends CommonObjectLine
{
	/**
	 * @var string ID of module.
	 */
	public $module = 'nopcommerce';

	/**
	 * @var string ID to identify managed object.
	 */
	public $element = 'nopcommercetransferline';

	/**
	 * @var string Prefix used to build trigger codes.
	 */
	public $TRIGGER_PREFIX = 'NOPCOMMERCE_TRANSFERLINE';

	/**
	 * @var string Name of table without prefix where object is stored.
	 */
	public $table_element = 'nopcommerce_transferline';

	/**
	 * @var string Parent element.
	 */
	public $parent_element = 'nopcommercetransfer';

	/**
	 * @var string Field pointing back to the parent.
	 */
	public $fk_parent_attribute = 'fk_nopcommercetransfer';

	/**
	 * @var int<0,1> Does object support extrafields ?
	 */
	public $isextrafieldmanaged = 0;

	/**
	 * @var int<0,1>|string Does this object support multicompany module ?
	 */
	public $ismultientitymanaged = 0;

	/**
	 * @inheritdoc
	 * @var array<string,array<string,mixed>>
	 */
	public $fields = array(
		'rowid' => array('type' => 'integer', 'label' => 'TechnicalID', 'enabled' => 1, 'position' => 1, 'notnull' => 1, 'visible' => 0, 'noteditable' => 1, 'index' => 1),
		'fk_nopcommercetransfer' => array('type' => 'integer', 'label' => 'NopCommerceTransfer', 'enabled' => 1, 'position' => 10, 'notnull' => 1, 'visible' => 0, 'index' => 1),
		'fk_product' => array('type' => 'integer:Product:product/class/product.class.php', 'label' => 'Product', 'picto' => 'product', 'enabled' => 1, 'position' => 20, 'notnull' => 1, 'visible' => 1, 'index' => 1),
		'fk_product_parent' => array('type' => 'integer:Product:product/class/product.class.php', 'label' => 'ParentProduct', 'picto' => 'product', 'enabled' => 1, 'position' => 21, 'notnull' => 1, 'visible' => -1, 'default' => '0'),
		'qty' => array('type' => 'real', 'label' => 'Qty', 'enabled' => 1, 'position' => 30, 'notnull' => 1, 'visible' => 1, 'default' => '0', 'isameasure' => 1, 'css' => 'maxwidth75imp'),
		'batch' => array('type' => 'varchar(128)', 'label' => 'Batch', 'enabled' => 'isModEnabled("productbatch")', 'position' => 35, 'notnull' => 0, 'visible' => -1),
		'position' => array('type' => 'integer', 'label' => 'Position', 'enabled' => 1, 'position' => 40, 'notnull' => 1, 'visible' => 0, 'default' => '0'),
		'sync_flag' => array('type' => 'integer', 'label' => 'NopCommerceSyncFlag', 'enabled' => 1, 'position' => 50, 'notnull' => 1, 'visible' => 1, 'default' => '0', 'noteditable' => 1, 'arrayofkeyval' => array(0 => 'NopCommerceSyncFalse', 1 => 'NopCommerceSyncTrue')),
		'sync_error' => array('type' => 'text', 'label' => 'NopCommerceSyncLastError', 'enabled' => 1, 'position' => 51, 'notnull' => 0, 'visible' => -1, 'noteditable' => 1, 'cssview' => 'wordbreak'),
		'nop_product_id' => array('type' => 'integer', 'label' => 'NopCommerceProductId', 'enabled' => 1, 'position' => 60, 'notnull' => 0, 'visible' => -1, 'noteditable' => 1),
		'nop_combination_id' => array('type' => 'integer', 'label' => 'NopCommerceCombinationId', 'enabled' => 1, 'position' => 61, 'notnull' => 0, 'visible' => -1, 'noteditable' => 1),
		'fk_mouvement_source' => array('type' => 'integer', 'label' => 'NopCommerceMovementSource', 'enabled' => 1, 'position' => 70, 'notnull' => 0, 'visible' => 0, 'noteditable' => 1),
		'fk_mouvement_destination' => array('type' => 'integer', 'label' => 'NopCommerceMovementDestination', 'enabled' => 1, 'position' => 71, 'notnull' => 0, 'visible' => 0, 'noteditable' => 1),
		// tms is maintained by the database through ON UPDATE CURRENT_TIMESTAMP, so it is deliberately not declared here
	);

	/**
	 * @var int ID
	 */
	public $rowid;
	/**
	 * @var int Parent transfer id
	 */
	public $fk_nopcommercetransfer;
	/**
	 * @var int Product id, the variant child product for a sized article
	 */
	public $fk_product;
	/**
	 * @var int Parent product id when fk_product is a variant, 0 otherwise
	 */
	public $fk_product_parent;
	/**
	 * @var float Quantity
	 */
	public $qty;
	/**
	 * @var ?string Lot or serial number
	 */
	public $batch;
	/**
	 * @var int Position of the line
	 */
	public $position;
	/**
	 * @var int 0 = not synced with nopCommerce, 1 = synced
	 */
	public $sync_flag;
	/**
	 * @var ?string Error reported by nopCommerce for this line
	 */
	public $sync_error;
	/**
	 * @var ?int Product id on the nopCommerce side
	 */
	public $nop_product_id;
	/**
	 * @var ?int Attribute combination id on the nopCommerce side
	 */
	public $nop_combination_id;
	/**
	 * @var ?int Stock movement written on the source warehouse
	 */
	public $fk_mouvement_source;
	/**
	 * @var ?int Stock movement written on the destination warehouse
	 */
	public $fk_mouvement_destination;


	/**
	 * Constructor
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct(DoliDB $db)
	{
		$this->db = $db;

		foreach ($this->fields as $key => $val) {
			if (isset($val['enabled']) && empty($val['enabled'])) {
				unset($this->fields[$key]);
			}
		}
	}

	/**
	 * Create line into database
	 *
	 * @param	User		$user		User that creates
	 * @param	int<0,1>	$notrigger	0=launch triggers after, 1=disable triggers
	 * @return	int<-1,max>				Return integer <0 if KO, Id of created object if OK
	 */
	public function create(User $user, $notrigger = 1)
	{
		return $this->createCommon($user, $notrigger);
	}

	/**
	 * Load line in memory from the database
	 *
	 * @param	int		$id		Id of the line
	 * @return	int<-1,1>		Return integer <0 if KO, 0 if not found, >0 if OK
	 */
	public function fetch($id)
	{
		return $this->fetchCommon($id);
	}

	/**
	 * Update line into database
	 *
	 * @param	User		$user		User that modifies
	 * @param	int<0,1>	$notrigger	0=launch triggers after, 1=disable triggers
	 * @return	int<-1,1>				Return integer <0 if KO, >0 if OK
	 */
	public function update(User $user, $notrigger = 1)
	{
		return $this->updateCommon($user, $notrigger);
	}

	/**
	 * Delete line from database
	 *
	 * @param	User		$user		User that deletes
	 * @param	int<0,1>	$notrigger	0=launch triggers after, 1=disable triggers
	 * @return	int<-1,1>				Return integer <0 if KO, >0 if OK
	 */
	public function delete(User $user, $notrigger = 1)
	{
		return $this->deleteCommon($user, $notrigger);
	}
}
