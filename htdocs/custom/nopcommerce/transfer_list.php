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
 * \file    htdocs/custom/nopcommerce/transfer_list.php
 * \ingroup nopcommerce
 * \brief   List of nopCommerce sync transfers.
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

require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
require_once DOL_DOCUMENT_ROOT.'/product/class/html.formproduct.class.php';
require_once './class/nopcommercetransfer.class.php';
require_once './lib/nopcommerce.lib.php';

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

$langs->loadLangs(array("stocks", "products", "nopcommerce@nopcommerce"));

// Parameters
$action = GETPOST('action', 'aZ09');
$search_ref = GETPOST('search_ref', 'alpha');
$search_status = GETPOST('search_status', 'intcomma');
$search_sync_flag = GETPOST('search_sync_flag', 'intcomma');
$search_warehouse = GETPOSTINT('search_warehouse');
$optioncss = GETPOST('optioncss', 'aZ');

$limit = GETPOSTINT('limit') ? GETPOSTINT('limit') : $conf->liste_limit;
$sortfield = GETPOST('sortfield', 'aZ09comma');
$sortorder = GETPOST('sortorder', 'aZ09comma');
$page = GETPOSTISSET('pageplusone') ? (GETPOSTINT('pageplusone') - 1) : GETPOSTINT("page");
if (empty($page) || $page < 0) {
	$page = 0;
}
$offset = $limit * $page;
if (!$sortfield) {
	$sortfield = 't.rowid';
}
if (!$sortorder) {
	$sortorder = 'DESC';
}

// Purge search criteria
if (GETPOST('button_removefilter_x', 'alpha') || GETPOST('button_removefilter.x', 'alpha') || GETPOST('button_removefilter', 'alpha')) {
	$search_ref = '';
	$search_status = '';
	$search_sync_flag = '';
	$search_warehouse = 0;
}

// Access control
if (!isModEnabled('nopcommerce')) {
	accessforbidden();
}
if (!$user->hasRight('nopcommerce', 'read')) {
	accessforbidden();
}


/*
 * View
 */

$form = new Form($db);
$formproduct = new FormProduct($db);
$transfertmp = new NopCommerceTransfer($db);

$title = $langs->trans("NopCommerceTransfers");
llxHeader('', $title, '', '', 0, 0, '', '', '', 'mod-nopcommerce page-transfer_list');

$sql = "SELECT t.rowid, t.ref, t.label, t.status, t.sync_flag, t.sync_attempts, t.sync_last_error,";
$sql .= " t.date_creation, t.date_pulled, t.date_synced,";
$sql .= " t.fk_warehouse_source, t.fk_warehouse_destination,";
$sql .= " es.ref as source_ref, ed.ref as dest_ref,";
$sql .= " COUNT(l.rowid) as nblines";
$sql .= " FROM ".MAIN_DB_PREFIX."nopcommerce_transfer as t";
$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."entrepot as es ON es.rowid = t.fk_warehouse_source";
$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."entrepot as ed ON ed.rowid = t.fk_warehouse_destination";
$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."nopcommerce_transferline as l ON l.fk_nopcommercetransfer = t.rowid";
$sql .= " WHERE t.entity IN (".getEntity('nopcommercetransfer').")";
if ($search_ref) {
	$sql .= natural_search('t.ref', $search_ref);
}
if ($search_status !== '' && $search_status != '-1') {
	$sql .= " AND t.status = ".((int) $search_status);
}
if ($search_sync_flag !== '' && $search_sync_flag != '-1') {
	$sql .= " AND t.sync_flag = ".((int) $search_sync_flag);
}
if ($search_warehouse > 0) {
	$sql .= " AND t.fk_warehouse_destination = ".((int) $search_warehouse);
}
$sql .= " GROUP BY t.rowid, t.ref, t.label, t.status, t.sync_flag, t.sync_attempts, t.sync_last_error,";
$sql .= " t.date_creation, t.date_pulled, t.date_synced, t.fk_warehouse_source, t.fk_warehouse_destination, es.ref, ed.ref";

$sqlcount = $sql;
$sql .= $db->order($sortfield, $sortorder);
$sql .= $db->plimit($limit + 1, $offset);

$resqlcount = $db->query($sqlcount);
$nbtotalofrecords = $resqlcount ? $db->num_rows($resqlcount) : 0;

$resql = $db->query($sql);
if (!$resql) {
	dol_print_error($db);
	exit;
}
$num = $db->num_rows($resql);

$param = '';
if ($search_ref) {
	$param .= '&search_ref='.urlencode($search_ref);
}
if ($search_status !== '') {
	$param .= '&search_status='.urlencode($search_status);
}
if ($search_sync_flag !== '') {
	$param .= '&search_sync_flag='.urlencode($search_sync_flag);
}
if ($search_warehouse > 0) {
	$param .= '&search_warehouse='.urlencode((string) $search_warehouse);
}

$newcardbutton = '';
if ($user->hasRight('nopcommerce', 'write')) {
	$newcardbutton = dolGetButtonTitle($langs->trans('NewNopCommerceTransfer'), '', 'fa fa-plus-circle', dol_buildpath('/nopcommerce/transfer_card.php', 1).'?action=create');
}

print '<form method="POST" id="searchFormList" action="'.$_SERVER["PHP_SELF"].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="list">';
print '<input type="hidden" name="sortfield" value="'.dol_escape_htmltag($sortfield).'">';
print '<input type="hidden" name="sortorder" value="'.dol_escape_htmltag($sortorder).'">';

print_barre_liste($title, $page, $_SERVER["PHP_SELF"], $param, $sortfield, $sortorder, '', $num, $nbtotalofrecords, 'stock', 0, $newcardbutton, '', $limit, 0, 0, 1);

print '<div class="div-table-responsive">';
print '<table class="tagtable nobottomiftotal liste">';

// Filter row
print '<tr class="liste_titre_filter">';
print '<td class="liste_titre"><input type="text" class="flat maxwidth100" name="search_ref" value="'.dol_escape_htmltag($search_ref).'"></td>';
print '<td class="liste_titre"></td>';
print '<td class="liste_titre">'.$formproduct->selectWarehouses($search_warehouse, 'search_warehouse', '', 1, 0, 0, '', 0, 0, array(), 'maxwidth150').'</td>';
print '<td class="liste_titre right"></td>';
print '<td class="liste_titre center">'.$form->selectarray('search_sync_flag', array('' => '', '0' => $langs->trans('NopCommerceSyncFalse'), '1' => $langs->trans('NopCommerceSyncTrue')), $search_sync_flag, 0, 0, 0, '', 0, 0, 0, '', 'maxwidth75').'</td>';
print '<td class="liste_titre center"></td>';
print '<td class="liste_titre center"></td>';
print '<td class="liste_titre center">'.$form->selectarray('search_status', array('' => '', '0' => $langs->trans('Draft'), '1' => $langs->trans('NopCommerceStatusPending'), '2' => $langs->trans('NopCommerceStatusSynced'), '3' => $langs->trans('NopCommerceStatusFailed'), '9' => $langs->trans('Canceled')), $search_status, 0, 0, 0, '', 0, 0, 0, '', 'maxwidth100').'</td>';
print '<td class="liste_titre center maxwidthsearch">'.$form->showFilterButtons().'</td>';
print '</tr>';

// Title row
print '<tr class="liste_titre">';
print_liste_field_titre("Ref", $_SERVER["PHP_SELF"], "t.ref", "", $param, "", $sortfield, $sortorder);
print_liste_field_titre("Label", $_SERVER["PHP_SELF"], "t.label", "", $param, "", $sortfield, $sortorder);
print_liste_field_titre("NopCommerceWebshopWarehouse", $_SERVER["PHP_SELF"], "ed.ref", "", $param, "", $sortfield, $sortorder);
print_liste_field_titre("NopCommerceTransferLines", $_SERVER["PHP_SELF"], "", "", $param, '', $sortfield, $sortorder, 'right ');
print_liste_field_titre("NopCommerceSyncFlag", $_SERVER["PHP_SELF"], "t.sync_flag", "", $param, '', $sortfield, $sortorder, 'center ');
print_liste_field_titre("NopCommerceDatePulled", $_SERVER["PHP_SELF"], "t.date_pulled", "", $param, '', $sortfield, $sortorder, 'center ');
print_liste_field_titre("NopCommerceDateSynced", $_SERVER["PHP_SELF"], "t.date_synced", "", $param, '', $sortfield, $sortorder, 'center ');
print_liste_field_titre("Status", $_SERVER["PHP_SELF"], "t.status", "", $param, '', $sortfield, $sortorder, 'center ');
print_liste_field_titre('', $_SERVER["PHP_SELF"], "", '', '', '', $sortfield, $sortorder, 'center maxwidthsearch ');
print '</tr>';

$i = 0;
while ($i < min($num, $limit)) {
	$obj = $db->fetch_object($resql);
	if (!$obj) {
		break;
	}

	$transfertmp->id = $obj->rowid;
	$transfertmp->ref = $obj->ref;
	$transfertmp->status = $obj->status;

	print '<tr class="oddeven">';
	print '<td class="nowraponall">'.$transfertmp->getNomUrl(1).'</td>';
	print '<td class="tdoverflowmax200">'.dol_escape_htmltag($obj->label).'</td>';
	print '<td class="tdoverflowmax150">'.dol_escape_htmltag($obj->dest_ref).'</td>';
	print '<td class="right">'.((int) $obj->nblines).'</td>';
	print '<td class="center">'.($obj->sync_flag ? img_picto($langs->trans('NopCommerceSyncTrue'), 'tick') : img_picto($langs->trans('NopCommerceSyncFalse'), 'off')).'</td>';
	print '<td class="center nowraponall">'.dol_print_date($db->jdate($obj->date_pulled), 'dayhour').'</td>';
	print '<td class="center nowraponall">'.dol_print_date($db->jdate($obj->date_synced), 'dayhour').'</td>';
	print '<td class="center">'.$transfertmp->getLibStatut(5);
	if (!empty($obj->sync_last_error)) {
		print ' '.$form->textwithpicto('', dol_escape_htmltag($obj->sync_last_error), 1, 'warning');
	}
	print '</td>';
	print '<td class="center"></td>';
	print '</tr>';

	$i++;
}

if ($num == 0) {
	print '<tr><td colspan="9"><span class="opacitymedium">'.$langs->trans("NoRecordFound").'</span></td></tr>';
}

print '</table>';
print '</div>';
print '</form>';

$db->free($resql);

llxFooter();
$db->close();
