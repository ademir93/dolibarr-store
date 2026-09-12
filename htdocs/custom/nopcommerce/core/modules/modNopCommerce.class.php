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
 * \defgroup   nopcommerce     Module NopCommerce
 * \brief      nopCommerce integration module descriptor.
 *
 * \file        htdocs/custom/nopcommerce/core/modules/modNopCommerce.class.php
 * \ingroup     nopcommerce
 * \brief       Description and activation file for module NopCommerce
 */
include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';


/**
 * Description and activation class for module NopCommerce.
 *
 * Dolibarr never pushes to the webshop. nopCommerce polls the REST endpoints of this
 * module, records the products on its side, then acknowledges. Only on a successful
 * acknowledgement does Dolibarr move the stock and set the sync flag.
 */
class modNopCommerce extends DolibarrModules
{
	/**
	 * Constructor. Define names, constants, directories, boxes, permissions
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		global $conf, $langs;

		$this->db = $db;

		// Id for module (must be unique). MyStore uses 500100, this module uses the next free block.
		$this->numero = 500200;

		// Key text used to identify module (for permissions, menus, etc...)
		$this->rights_class = 'nopcommerce';

		// Family: 'base', 'crm', 'financial', 'hr', 'projects', 'products', 'ecm', 'technic', 'interface', 'other'
		$this->family = "interface";

		// Module position in the family on 2 digits ('01', '10', '20', ...)
		$this->module_position = '90';

		// Module label (no space allowed), used if translation string 'ModuleNopCommerceName' not found
		$this->name = preg_replace('/^mod/i', '', get_class($this));

		// Module description, used if translation string 'ModuleNopCommerceDesc' not found
		$this->description = "NopCommerceDescription";
		$this->descriptionlong = "NopCommerceDescriptionLong";

		// Author
		$this->editor_name = 'Demir Agovic';
		$this->editor_url = '';

		// Possible values: 'development', 'experimental', 'dolibarr', or a version string like 'x.y.z'
		$this->version = '1.0';

		// Key used in llx_const table to save module status enabled/disabled
		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);

		$this->picto = 'stock';

		// Define features supported by module
		$this->module_parts = array(
			'triggers'          => 1,
			'login'             => 0,
			'substitutions'     => 0,
			'menus'             => 0,
			'tpl'               => 0,
			'barcode'           => 0,
			'models'            => 0,
			'printing'          => 0,
			'theme'             => 0,
			'css'               => array(),
			'js'                => array(),
			'hooks'             => array(),
			'moduleforexternal' => 0,
			'websitetemplates'  => 0,
			'captcha'           => 0,
		);

		// Data directories to create when module is enabled
		$this->dirs = array("/nopcommerce/temp");

		// Config pages
		$this->config_page_url = array("setup.php@nopcommerce");

		// Dependencies. The API module is required because nopCommerce pulls over REST.
		$this->hidden = getDolGlobalInt('MODULE_NOPCOMMERCE_DISABLED');
		$this->depends = array('modProduct', 'modStock', 'modApi');
		$this->requiredby = array();
		$this->conflictwith = array();

		// Language file
		$this->langfiles = array("nopcommerce@nopcommerce");

		// Prerequisites
		$this->phpmin = array(7, 4);
		$this->need_dolibarr_version = array(19, -3);
		$this->need_javascript_ajax = 0;

		// Messages at activation
		$this->warnings_activation = array();
		$this->warnings_activation_ext = array();

		// Constants
		$this->const = array();

		if (!isModEnabled("nopcommerce")) {
			$conf->nopcommerce = new stdClass();
			$conf->nopcommerce->enabled = 0;
		}

		// Tabs
		$this->tabs = array();

		// Dictionaries
		$this->dictionaries = array();

		// Boxes/Widgets
		$this->boxes = array();

		// Cronjobs
		$this->cronjobs = array();

		// Permissions
		$this->rights = array();
		$r = 0;

		$r++;
		$this->rights[$r][0] = 500201;
		$this->rights[$r][1] = 'Read nopCommerce sync transfers';
		$this->rights[$r][2] = 'r';
		$this->rights[$r][3] = 0;
		$this->rights[$r][4] = 'read';

		$r++;
		$this->rights[$r][0] = 500202;
		$this->rights[$r][1] = 'Create and modify nopCommerce sync transfers';
		$this->rights[$r][2] = 'w';
		$this->rights[$r][3] = 0;
		$this->rights[$r][4] = 'write';

		$r++;
		$this->rights[$r][0] = 500203;
		$this->rights[$r][1] = 'Delete nopCommerce sync transfers';
		$this->rights[$r][2] = 'd';
		$this->rights[$r][3] = 0;
		$this->rights[$r][4] = 'delete';

		$r++;
		$this->rights[$r][0] = 500204;
		$this->rights[$r][1] = 'Use the nopCommerce sync API (pull pending transfers and acknowledge them)';
		$this->rights[$r][2] = 'w';
		$this->rights[$r][3] = 0;
		$this->rights[$r][4] = 'sync';

		// Main menu entries
		$this->menu = array();
		$r = 0;

		$this->menu[$r++] = array(
			'fk_menu'  => 'fk_mainmenu=products',
			'type'     => 'left',
			'titre'    => 'NopCommerceTransfers',
			'prefix'   => img_picto('', $this->picto, 'class="pictofixedwidth valignmiddle"'),
			'mainmenu' => 'products',
			'leftmenu' => 'nopcommerce',
			'url'      => '/nopcommerce/transfer_list.php',
			'langs'    => 'nopcommerce@nopcommerce',
			'position' => 1000 + $r,
			'enabled'  => "isModEnabled('nopcommerce')",
			'perms'    => '$user->hasRight("nopcommerce", "read")',
			'target'   => '',
			'user'     => 2,
		);

		// There is no "new transfer" entry on purpose. Products are never queued for sync by
		// hand: they are captured from the native stock transfer page when the warehouse pair
		// matches NOPCOMMERCE_SOURCE_WAREHOUSE_ID and NOPCOMMERCE_WEBSHOP_WAREHOUSE_ID.

		// Exports / Imports
		$r = 0;
	}

	/**
	 * Function called when module is enabled.
	 *
	 * @param  string       $options    Options when enabling module ('', 'noboxes')
	 * @return int<-1,1>                1 if OK, <=0 if KO
	 */
	public function init($options = '')
	{
		$result = $this->_load_tables('/nopcommerce/sql/');
		if ($result < 0) {
			return -1;
		}

		$this->remove($options);

		$sql = array();

		return $this->_init($sql, $options);
	}

	/**
	 * Function called when module is disabled. Tables are kept on purpose so that the
	 * sync history survives a disable/enable cycle.
	 *
	 * @param  string   $options    Options when disabling module ('', 'noboxes')
	 * @return int<-1,1>            1 if OK, <=0 if KO
	 */
	public function remove($options = '')
	{
		$sql = array();
		return $this->_remove($sql, $options);
	}
}
