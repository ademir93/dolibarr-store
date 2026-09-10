<?php
/* Copyright (C) 2026 Demir Agovic
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    htdocs/custom/nopcommerce/admin/setup.php
 * \ingroup nopcommerce
 * \brief   nopCommerce integration setup page.
 */

// Load Dolibarr environment
$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include str_replace("..", "", $_SERVER["CONTEXT_DOCUMENT_ROOT"])."/main.inc.php";
}
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1;
$j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) {
	$i--;
	$j--;
}
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1))."/main.inc.php")) {
	$res = @include substr($tmp, 0, ($i + 1))."/main.inc.php";
}
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php")) {
	$res = @include dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php";
}
if (!$res && file_exists("../main.inc.php")) {
	$res = @include "../main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

// Libraries
require_once DOL_DOCUMENT_ROOT."/core/lib/admin.lib.php";
require_once DOL_DOCUMENT_ROOT."/product/stock/class/entrepot.class.php";
require_once DOL_DOCUMENT_ROOT."/product/class/html.formproduct.class.php";
require_once '../lib/nopcommerce.lib.php';

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var HookManager $hookmanager
 * @var Translate $langs
 * @var User $user
 */

// Translations
$langs->loadLangs(array("admin", "stocks", "nopcommerce@nopcommerce"));

$hookmanager->initHooks(array('nopcommercesetup', 'globalsetup'));

// Parameters
$action = GETPOST('action', 'aZ09');
$backtopage = GETPOST('backtopage', 'alpha');

// Access control
if (!$user->admin) {
	accessforbidden();
}

// The settings this page manages. Each one is stored as a Dolibarr constant.
$arrayofparameters = array(
	'NOPCOMMERCE_WEBSHOP_WAREHOUSE_ID' => array('type' => 'warehouse', 'css' => 'minwidth300'),
	'NOPCOMMERCE_SOURCE_WAREHOUSE_ID' => array('type' => 'warehouse', 'css' => 'minwidth300'),
	'NOPCOMMERCE_PULL_LIMIT' => array('type' => 'integer', 'css' => 'maxwidth100', 'default' => 50),
	'NOPCOMMERCE_ACK_REQUIRE_TOKEN' => array('type' => 'yesno', 'css' => '', 'default' => 1),
	'NOPCOMMERCE_ALLOW_NEGATIVE_SOURCE_STOCK' => array('type' => 'yesno', 'css' => '', 'default' => 0),
);


/*
 * Actions
 */

if ($action == 'update') {
	$error = 0;

	$db->begin();

	foreach ($arrayofparameters as $key => $val) {
		$value = GETPOST($key, 'alphanohtml');
		if ($val['type'] == 'integer' || $val['type'] == 'warehouse' || $val['type'] == 'yesno') {
			$value = (int) $value;
		}

		$result = dolibarr_set_const($db, $key, $value, 'chaine', 0, '', $conf->entity);
		if ($result < 0) {
			$error++;
			break;
		}
	}

	if (!$error) {
		$db->commit();
		setEventMessages($langs->trans("SetupSaved"), null, 'mesgs');
	} else {
		$db->rollback();
		setEventMessages($langs->trans("Error"), null, 'errors');
	}

	header("Location: ".$_SERVER["PHP_SELF"]);
	exit;
}


/*
 * View
 */

$form = new Form($db);
$formproduct = new FormProduct($db);

$title = $langs->trans("NopCommerceSetup");
llxHeader('', $title, '', '', 0, 0, '', '', '', 'mod-nopcommerce page-admin_setup');

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans("BackToModuleList").'</a>';
print load_fiche_titre($title, $linkback, 'title_setup');

$head = nopcommerceAdminPrepareHead();
print dol_get_fiche_head($head, 'settings', $langs->trans("NopCommerceSetup"), -1, 'stock');

print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="update">';

print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td class="titlefield">'.$langs->trans("Parameter").'</td><td>'.$langs->trans("Value").'</td></tr>';

foreach ($arrayofparameters as $key => $val) {
	print '<tr class="oddeven">';
	print '<td>';
	print $form->textwithpicto($langs->trans($key), $langs->trans($key.'Tooltip'));
	print '</td>';
	print '<td>';
	if ($val['type'] == 'warehouse') {
		print $formproduct->selectWarehouses(getDolGlobalInt($key), $key, 'warehouseopen', 1, 0, 0, '', 0, 0, array(), $val['css']);
	} elseif ($val['type'] == 'yesno') {
		print $form->selectyesno($key, getDolGlobalInt($key, $val['default']), 1);
	} else {
		print '<input type="text" class="'.$val['css'].'" name="'.$key.'" value="'.dol_escape_htmltag((string) getDolGlobalInt($key, $val['default'])).'">';
	}
	print '</td>';
	print '</tr>';
}

print '</table>';

print '<div class="center"><input type="submit" class="button button-edit" value="'.$langs->trans("Save").'"></div>';
print '</form>';

// Endpoints the nopCommerce plugin has to call. Shown here so the shop side can be
// configured without digging through the code.
$apibase = nopcommerceApiBaseUrl();

print '<br>';
print load_fiche_titre($langs->trans("NopCommerceApiEndpoints"), '', '');
print '<div class="opacitymedium justify">'.$langs->trans("NopCommerceApiHint").'</div><br>';

print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td class="titlefield">'.$langs->trans("Method").'</td><td>URL</td></tr>';
$endpoints = array(
	'GET' => array(
		$apibase.'/status',
		$apibase.'/transfers/pending?limit=50',
		$apibase.'/transfers/{id}',
	),
	'POST' => array(
		$apibase.'/transfers/{id}/ack',
	),
);
foreach ($endpoints as $method => $urls) {
	foreach ($urls as $url) {
		print '<tr class="oddeven"><td>'.$method.'</td><td class="wordbreak"><code>'.dol_escape_htmltag($url).'</code></td></tr>';
	}
}
print '</table>';

print dol_get_fiche_end();

llxFooter();
$db->close();
