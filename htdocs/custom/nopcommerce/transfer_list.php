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
require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
require_once './class/nopcommercetransfer.class.php';
require_once './class/nopcommercesync.class.php';
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
$search_product = GETPOST('search_product', 'alpha');
$search_batch = GETPOST('search_batch', 'alphanohtml');
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
	$sortfield = 'l.rowid';
}
if (!$sortorder) {
	$sortorder = 'DESC';
}

// Purge search criteria
if (GETPOST('button_removefilter_x', 'alpha') || GETPOST('button_removefilter.x', 'alpha') || GETPOST('button_removefilter', 'alpha')) {
	$search_ref = '';
	$search_product = '';
	$search_batch = '';
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

$permissiontocancel = $user->hasRight('nopcommerce', 'write');
$permissiontodelete = $user->hasRight('nopcommerce', 'delete');


/*
 * Actions
 */

$transferid = GETPOSTINT('transferid');

if ($action == 'confirm_cancel' && GETPOST('confirm', 'alpha') == 'yes' && $permissiontocancel && $transferid > 0) {
	$transfer = new NopCommerceTransfer($db);
	if ($transfer->fetch($transferid) > 0) {
		if ($transfer->cancel($user) > 0) {
			setEventMessages($langs->trans('NopCommerceTransferCanceled'), null, 'mesgs');
		} else {
			setEventMessages($langs->trans($transfer->error), null, 'errors');
		}
	}
	header("Location: ".$_SERVER["PHP_SELF"]);
	exit;
}

if ($action == 'confirm_delete' && GETPOST('confirm', 'alpha') == 'yes' && $permissiontodelete && $transferid > 0) {
	$transfer = new NopCommerceTransfer($db);
	if ($transfer->fetch($transferid) > 0) {
		if ($transfer->delete($user) > 0) {
			setEventMessages($langs->trans('RecordDeleted'), null, 'mesgs');
		} else {
			setEventMessages($langs->trans($transfer->error), null, 'errors');
		}
	}
	header("Location: ".$_SERVER["PHP_SELF"]);
	exit;
}


/*
 * View
 */

$form = new Form($db);
$formproduct = new FormProduct($db);
$transfertmp = new NopCommerceTransfer($db);
$synctmp = new NopCommerceSync($db);
$producttmp = new Product($db);

$title = $langs->trans("NopCommerceSyncProducts");
llxHeader('', $title, '', '', 0, 0, '', '', '', 'mod-nopcommerce page-transfer_list');

if ($action == 'cancel' && $permissiontocancel && $transferid > 0) {
	$canceltmp = new NopCommerceTransfer($db);
	if ($canceltmp->fetch($transferid) > 0) {
		print $form->formconfirm($_SERVER["PHP_SELF"].'?transferid='.$transferid, $langs->trans('NopCommerceCancelTransfer'), $langs->trans('NopCommerceConfirmCancelTransfer', $canceltmp->ref), 'confirm_cancel', '', 0, 1);
	}
}
if ($action == 'delete' && $permissiontodelete && $transferid > 0) {
	$deletetmp = new NopCommerceTransfer($db);
	if ($deletetmp->fetch($transferid) > 0) {
		print $form->formconfirm($_SERVER["PHP_SELF"].'?transferid='.$transferid, $langs->trans('NopCommerceDeleteTransfer'), $langs->trans('NopCommerceConfirmDeleteTransfer', $deletetmp->ref), 'confirm_delete', '', 0, 1);
	}
}

$sql = "SELECT l.rowid as lineid, l.fk_product, l.fk_product_parent, l.qty, l.batch,";
$sql .= " l.sync_flag as line_sync_flag, l.sync_error, l.nop_product_id,";
$sql .= " t.rowid, t.ref, t.label, t.status, t.origin, t.sync_last_error,";
$sql .= " t.date_creation, t.date_pulled, t.date_synced,";
$sql .= " t.fk_warehouse_source, t.fk_warehouse_destination,";
$sql .= " p.ref as product_ref, p.label as product_label, p.fk_product_type,";
$sql .= " es.ref as source_ref, ed.ref as dest_ref";
$sql .= " FROM ".MAIN_DB_PREFIX."nopcommerce_transferline as l";
$sql .= " INNER JOIN ".MAIN_DB_PREFIX."nopcommerce_transfer as t ON t.rowid = l.fk_nopcommercetransfer";
$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."product as p ON p.rowid = l.fk_product";
$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."entrepot as es ON es.rowid = t.fk_warehouse_source";
$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."entrepot as ed ON ed.rowid = t.fk_warehouse_destination";
$sql .= " WHERE t.entity IN (".getEntity('nopcommercetransfer').")";
if ($search_ref) {
	$sql .= natural_search('t.ref', $search_ref);
}
if ($search_product) {
	$sql .= natural_search(array('p.ref', 'p.label'), $search_product);
}
if ($search_batch) {
	$sql .= natural_search('l.batch', $search_batch);
}
if ($search_status !== '' && $search_status != '-1') {
	$sql .= " AND t.status = ".((int) $search_status);
}
if ($search_sync_flag !== '' && $search_sync_flag != '-1') {
	$sql .= " AND l.sync_flag = ".((int) $search_sync_flag);
}
if ($search_warehouse > 0) {
	$sql .= " AND t.fk_warehouse_destination = ".((int) $search_warehouse);
}

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
if ($search_product) {
	$param .= '&search_product='.urlencode($search_product);
}
if ($search_batch) {
	$param .= '&search_batch='.urlencode($search_batch);
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

// No "new" button: rows are never created by hand, only captured from the native stock
// transfer page when the warehouse pair matches the module setup.

print '<form method="POST" id="searchFormList" action="'.$_SERVER["PHP_SELF"].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="list">';
print '<input type="hidden" name="sortfield" value="'.dol_escape_htmltag($sortfield).'">';
print '<input type="hidden" name="sortorder" value="'.dol_escape_htmltag($sortorder).'">';

$completedordersbutton = dolGetButtonTitle($langs->trans("NopCommerceCompletedOrders"), '', 'fa fa-list paddingright', dol_buildpath('/nopcommerce/order_completed_list.php', 1));

print_barre_liste($title, $page, $_SERVER["PHP_SELF"], $param, $sortfield, $sortorder, '', $num, $nbtotalofrecords, 'stock', 0, $completedordersbutton, '', $limit, 0, 0, 1);

print '<div class="div-table-responsive">';
print '<table class="tagtable nobottomiftotal liste">';

$showbatch = isModEnabled('productbatch');
$nbcols = $showbatch ? 12 : 11;

// Filter row
print '<tr class="liste_titre_filter">';
print '<td class="liste_titre"><input type="text" class="flat maxwidth100" name="search_product" value="'.dol_escape_htmltag($search_product).'"></td>';
print '<td class="liste_titre"></td>';
if ($showbatch) {
	print '<td class="liste_titre"><input type="text" class="flat maxwidth75" name="search_batch" value="'.dol_escape_htmltag($search_batch).'"></td>';
}
print '<td class="liste_titre right"></td>';
print '<td class="liste_titre"></td>';
print '<td class="liste_titre">'.$formproduct->selectWarehouses($search_warehouse, 'search_warehouse', '', 1, 0, 0, '', 0, 0, array(), 'maxwidth150').'</td>';
print '<td class="liste_titre"><input type="text" class="flat maxwidth75" name="search_ref" value="'.dol_escape_htmltag($search_ref).'"></td>';
print '<td class="liste_titre center"></td>';
print '<td class="liste_titre center">'.$form->selectarray('search_status', array('' => '', '0' => $langs->trans('Draft'), '1' => $langs->trans('NopCommerceStatusPending'), '2' => $langs->trans('NopCommerceStatusSynced'), '3' => $langs->trans('NopCommerceStatusFailed'), '9' => $langs->trans('Canceled')), $search_status, 0, 0, 0, '', 0, 0, 0, '', 'maxwidth100').'</td>';
print '<td class="liste_titre center"></td>';
print '<td class="liste_titre center">'.$form->selectarray('search_sync_flag', array('' => '', '0' => $langs->trans('NopCommerceSyncFalse'), '1' => $langs->trans('NopCommerceSyncTrue')), $search_sync_flag, 0, 0, 0, '', 0, 0, 0, '', 'maxwidth75').'</td>';
print '<td class="liste_titre center maxwidthsearch">'.$form->showFilterButtons().'</td>';
print '</tr>';

// Title row
print '<tr class="liste_titre">';
print_liste_field_titre("Product", $_SERVER["PHP_SELF"], "p.ref", "", $param, "", $sortfield, $sortorder);
print_liste_field_titre("Attributes", $_SERVER["PHP_SELF"], "", "", $param, "", $sortfield, $sortorder);
if ($showbatch) {
	print_liste_field_titre("Batch", $_SERVER["PHP_SELF"], "l.batch", "", $param, "", $sortfield, $sortorder);
}
print_liste_field_titre("Qty", $_SERVER["PHP_SELF"], "l.qty", "", $param, "", $sortfield, $sortorder, 'right ');
print_liste_field_titre("NopCommerceSourceWarehouse", $_SERVER["PHP_SELF"], "es.ref", "", $param, "", $sortfield, $sortorder);
print_liste_field_titre("NopCommerceWebshopWarehouse", $_SERVER["PHP_SELF"], "ed.ref", "", $param, "", $sortfield, $sortorder);
print_liste_field_titre("Ref", $_SERVER["PHP_SELF"], "t.ref", "", $param, "", $sortfield, $sortorder);
print_liste_field_titre("DateCreation", $_SERVER["PHP_SELF"], "t.date_creation", "", $param, '', $sortfield, $sortorder, 'center ');
print_liste_field_titre("Status", $_SERVER["PHP_SELF"], "t.status", "", $param, '', $sortfield, $sortorder, 'center ');
print_liste_field_titre("NopCommerceProductId", $_SERVER["PHP_SELF"], "l.nop_product_id", "", $param, '', $sortfield, $sortorder, 'center ');
print_liste_field_titre("NopCommerceSyncFlag", $_SERVER["PHP_SELF"], "l.sync_flag", "", $param, '', $sortfield, $sortorder, 'center ');
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

	$producttmp->id = $obj->fk_product;
	$producttmp->ref = $obj->product_ref;
	$producttmp->label = $obj->product_label;
	$producttmp->type = $obj->fk_product_type;

	$attributelabels = array();
	if (!empty($obj->fk_product_parent)) {
		foreach ($synctmp->getVariantAttributes($obj->fk_product) as $attribute) {
			$attributelabels[] = $attribute['attribute_label'].': '.$attribute['value_label'];
		}
	}

	print '<tr class="oddeven">';
	print '<td class="nowraponall">'.$producttmp->getNomUrl(1).'</td>';
	print '<td class="tdoverflowmax200">'.dol_escape_htmltag(implode(', ', $attributelabels)).'</td>';
	if ($showbatch) {
		print '<td class="tdoverflowmax100">'.dol_escape_htmltag((string) $obj->batch).'</td>';
	}
	print '<td class="right">'.price2num($obj->qty, 'MS').'</td>';
	print '<td class="tdoverflowmax150">'.dol_escape_htmltag((string) $obj->source_ref).'</td>';
	print '<td class="tdoverflowmax150">'.dol_escape_htmltag((string) $obj->dest_ref).'</td>';
	print '<td class="nowraponall">'.$transfertmp->getNomUrl(0).'</td>';
	print '<td class="center nowraponall">'.dol_print_date($db->jdate($obj->date_creation), 'dayhour').'</td>';
	print '<td class="center">'.$transfertmp->getLibStatut(5).'</td>';
	print '<td class="center">'.($obj->nop_product_id !== null ? (int) $obj->nop_product_id : '').'</td>';

	// Sync indicator, read only on purpose: marking a product synced by hand would claim
	// a sync that never happened.
	print '<td class="center">';
	print $obj->line_sync_flag ? img_picto($langs->trans('NopCommerceSyncTrue'), 'tick') : img_picto($langs->trans('NopCommerceSyncFalse'), 'off');
	$error = !empty($obj->sync_error) ? $obj->sync_error : $obj->sync_last_error;
	if (!empty($error)) {
		print ' '.$form->textwithpicto('', dol_escape_htmltag($error), 1, 'warning');
	}
	print '</td>';

	print '<td class="center nowraponall">';
	if ($permissiontocancel && in_array((int) $obj->status, array(NopCommerceTransfer::STATUS_PENDING, NopCommerceTransfer::STATUS_FAILED), true)) {
		print '<a class="reposition paddingright" href="'.$_SERVER["PHP_SELF"].'?action=cancel&transferid='.((int) $obj->rowid).'&token='.newToken().'" title="'.dol_escape_htmltag($langs->trans('NopCommerceCancelTransfer')).'">'.img_picto($langs->trans('NopCommerceCancelTransfer'), 'close_title').'</a>';
	}
	if ($permissiontodelete && (int) $obj->status != NopCommerceTransfer::STATUS_SYNCED) {
		print '<a class="reposition" href="'.$_SERVER["PHP_SELF"].'?action=delete&transferid='.((int) $obj->rowid).'&token='.newToken().'" title="'.dol_escape_htmltag($langs->trans('Delete')).'">'.img_delete().'</a>';
	}
	print '</td>';

	print '</tr>';

	$i++;
}

if ($num == 0) {
	print '<tr><td colspan="'.$nbcols.'"><span class="opacitymedium">'.$langs->trans("NopCommerceNoProductQueued").'</span></td></tr>';
}

print '</table>';
print '</div>';
print '</form>';

$db->free($resql);

llxFooter();
$db->close();
