<?php
/* Copyright (C) 2004-2018  Laurent Destailleur     <eldy@users.sourceforge.net>
 * Copyright (C) 2018-2019  Nicolas ZABOURI         <info@inovea-conseil.com>
 * Copyright (C) 2019-2024  Frédéric France         <frederic.france@free.fr>
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
 * \defgroup   mystore     Module MyStore
 * \brief      MyStore module descriptor.
 *
 * \file        htdocs/custom/mystore/core/modules/modMyStore.class.php
 * \ingroup     mystore
 * \brief       Description and activation file for module MyStore
 */
include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';


/**
 * Description and activation class for module MyStore
 */
class modMyStore extends DolibarrModules
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

		// Id for module (must be unique).
		// Use a free id from https://wiki.dolibarr.org/index.php/List_of_modules_id
		$this->numero = 500100;

		// Key text used to identify module (for permissions, menus, etc...)
		$this->rights_class = 'mystore';

		// Family: 'base', 'crm', 'financial', 'hr', 'projects', 'products', 'ecm', 'technic', 'interface', 'other'
		$this->family = "other";

		// Module position in the family on 2 digits ('01', '10', '20', ...)
		$this->module_position = '90';

		// Module label (no space allowed), used if translation string 'ModuleMyStoreName' not found
		$this->name = preg_replace('/^mod/i', '', get_class($this));

		// Module description, used if translation string 'ModuleMyStoreDesc' not found
		$this->description = "MyStoreDescription";
		$this->descriptionlong = "MyStoreDescription";

		// Author
		$this->editor_name = 'Editor name';
		$this->editor_url = 'https://www.example.com';

		// Possible values: 'development', 'experimental', 'dolibarr', or a version string like 'x.y.z'
		$this->version = '1.0';

		// Key used in llx_const table to save module status enabled/disabled
		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);

		$this->picto = 'store';

		// Define features supported by module
		$this->module_parts = array(
			'triggers'          => 0,
			'login'             => 0,
			'substitutions'     => 0,
			'menus'             => 0,
			'tpl'               => 1,
			'barcode'           => 0,
			'models'            => 0,
			'printing'          => 0,
			'theme'             => 0,
			'css'               => array('/mystore/css/mystore.css'),
			'js'                => array(),
			'hooks'             => array('takeposinvoice', 'takeposfrontend', 'login', 'main', 'combinationcard', 'invoicelist', 'poslist'),
			'moduleforexternal' => 0,
			'websitetemplates'  => 0,
			'captcha'           => 0,
		);

		// Data directories to create when module is enabled
		$this->dirs = array("/mystore/temp");

		// Config pages
		$this->config_page_url = array("setup.php@mystore");

		// Dependencies
		$this->hidden = getDolGlobalInt('MODULE_MYSTORE_DISABLED');
		$this->depends = array();
		$this->requiredby = array();
		$this->conflictwith = array();

		// Language file
		$this->langfiles = array("mystore@mystore");

		// Prerequisites
		$this->phpmin = array(7, 2);
		$this->need_dolibarr_version = array(19, -3);
		$this->need_javascript_ajax = 0;

		// Messages at activation
		$this->warnings_activation = array();
		$this->warnings_activation_ext = array();

		// Constants
		$this->const = array();

		if (!isModEnabled("mystore")) {
			$conf->mystore = new stdClass();
			$conf->mystore->enabled = 0;
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
		$this->rights[$r][0] = 500101;
		$this->rights[$r][1] = 'See TakePOS sales history (invoices of own terminal warehouse only)';
		$this->rights[$r][2] = 'a';
		$this->rights[$r][3] = 0;
		$this->rights[$r][4] = 'poshistory';

		// Main menu entries
		$this->menu = array();
		$r = 0;

		$this->menu[$r++] = array(
			'fk_menu'  => '',
			'type'     => 'top',
			'titre'    => 'ModuleMyStoreName',
			'prefix'   => img_picto('', $this->picto, 'class="pictofixedwidth valignmiddle"'),
			'mainmenu' => 'mystore',
			'leftmenu' => '',
			'url'      => '/mystore/mystoreindex.php',
			'langs'    => 'mystore@mystore',
			'position' => 1000 + $r,
			'enabled'  => "isModEnabled('mystore')",
			'perms'    => '1',
			'target'   => '',
			'user'     => 2,
		);

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
		global $conf;

		$result = $this->_load_tables('/mystore/sql/');
		if ($result < 0) {
			return -1;
		}

		$this->remove($options);

		// Force Dolibarr to look into this module's langs directory first, so that
		// MyStore's own translations (e.g. langs/sr_RS/cashdesk.lang, the Serbian
		// Latin POS terminal translation) take priority over the core lang files.
		// This is the documented mechanism (see Translate::setDefaultLang / load).
		dolibarr_set_const($this->db, 'MAIN_FORCELANGDIR', '/custom/mystore', 'chaine', 0, 'Priority to MyStore lang files (POS Serbian Latin)', $conf->entity);

		$sql = array();

		return $this->_init($sql, $options);
	}

	/**
	 * Function called when module is disabled.
	 *
	 * @param  string   $options    Options when disabling module ('', 'noboxes')
	 * @return int<-1,1>            1 if OK, <=0 if KO
	 */
	public function remove($options = '')
	{
		global $conf;

		// Drop the lang-directory priority set in init() so core lang files are
		// used again once MyStore is disabled.
		dolibarr_del_const($this->db, 'MAIN_FORCELANGDIR', $conf->entity);

		$sql = array();
		return $this->_remove($sql, $options);
	}
}
