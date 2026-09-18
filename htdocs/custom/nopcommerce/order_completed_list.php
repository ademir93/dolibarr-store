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
 * \file    htdocs/custom/nopcommerce/order_completed_list.php
 * \ingroup nopcommerce
 * \brief   List of the orders nopCommerce reported as completed.
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
require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
require_once './class/nopcommercesync.class.php';
require_once './lib/nopcommerce.lib.php';

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

$langs->loadLangs(array("stocks", "products", "orders", "nopcommerce@nopcommerce"));

// Parameters
$search_orderid = GETPOST('search_orderid', 'alpha');
$search_product = GETPOST('search_product', 'alpha');

$limit = GETPOSTINT('limit') ? GETPOSTINT('limit') : $conf->liste_limit;
$sortfield = GETPOST('sortfield', 'aZ09comma');
$sortorder = GETPOST('sortorder', 'aZ09comma');
$page = GETPOSTISSET('pageplusone') ? (GETPOSTINT('pageplusone') - 1) : GETPOSTINT("page");
if (empty($page) || $page < 0) {
	$page = 0;
}
$offset = $limit * $page;
if (!$sortfield) {
	$sortfield = 'o.rowid';
}
if (!$sortorder) {
	$sortorder = 'DESC';
}

// Purge search criteria
if (GETPOST('button_removefilter_x', 'alpha') || GETPOST('button_removefilter.x', 'alpha') || GETPOST('button_removefilter', 'alpha')) {
	$search_orderid = '';
	$search_product = '';
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
$synctmp = new NopCommerceSync($db);
$producttmp = new Product($db);

$title = $langs->trans("NopCommerceCompletedOrders");
llxHeader('', $title, '', '', 0, 0, '', '', '', 'mod-nopcommerce page-order_completed_list');

// The product join is only needed to let the filter search the items of an order.
$sqlfrom = " FROM ".MAIN_DB_PREFIX."nop_order_completed as o";
$sqlfrom .= " LEFT JOIN ".MAIN_DB_PREFIX."nop_order_complete_items as i ON i.fk_nop_order_completed = o.rowid";
$sqlfrom .= " LEFT JOIN ".MAIN_DB_PREFIX."product as p ON p.rowid = i.fk_product";

$sqlwhere = " WHERE 1 = 1";
if ($search_orderid !== '') {
	$sqlwhere .= natural_search('o.nop_order_id', $search_orderid, 1);
}
if ($search_product) {
	$sqlwhere .= natural_search(array('p.ref', 'p.label'), $search_product);
}

$sqlcount = "SELECT COUNT(DISTINCT o.rowid) as nbtotal".$sqlfrom.$sqlwhere;
$resqlcount = $db->query($sqlcount);
if (!$resqlcount) {
	dol_print_error($db);
	exit;
}
$nbtotalofrecords = (int) $db->fetch_object($resqlcount)->nbtotal;

$sql = "SELECT o.rowid, o.nop_order_id, o.date_creation, COUNT(i.rowid) as nbitems".$sqlfrom.$sqlwhere;
$sql .= " GROUP BY o.rowid, o.nop_order_id, o.date_creation";
$sql .= $db->order($sortfield, $sortorder);
$sql .= $db->plimit($limit + 1, $offset);

$resql = $db->query($sql);
if (!$resql) {
	dol_print_error($db);
	exit;
}
$num = $db->num_rows($resql);

$orders = array();
$i = 0;
while ($i < min($num, $limit)) {
	$obj = $db->fetch_object($resql);
	if (!$obj) {
		break;
	}
	$orders[(int) $obj->rowid] = $obj;
	$i++;
}
$db->free($resql);

// The items of every order on this page, in one query, so each modal is ready on click.
$itemsbyorder = array();
if (count($orders) > 0) {
	$sqlitems = "SELECT i.rowid, i.fk_nop_order_completed, i.fk_product, i.nop_external_id,";
	$sqlitems .= " p.ref as product_ref, p.label as product_label, p.fk_product_type,";
	$sqlitems .= " c.fk_product_parent";
	$sqlitems .= " FROM ".MAIN_DB_PREFIX."nop_order_complete_items as i";
	$sqlitems .= " LEFT JOIN ".MAIN_DB_PREFIX."product as p ON p.rowid = i.fk_product";
	$sqlitems .= " LEFT JOIN ".MAIN_DB_PREFIX."product_attribute_combination as c ON c.fk_product_child = i.fk_product";
	$sqlitems .= " WHERE i.fk_nop_order_completed IN (".$db->sanitize(implode(',', array_keys($orders))).")";
	$sqlitems .= " ORDER BY i.rowid ASC";

	$resqlitems = $db->query($sqlitems);
	if (!$resqlitems) {
		dol_print_error($db);
		exit;
	}
	while ($objitem = $db->fetch_object($resqlitems)) {
		$itemsbyorder[(int) $objitem->fk_nop_order_completed][] = $objitem;
	}
	$db->free($resqlitems);
}

$param = '';
if ($search_orderid !== '') {
	$param .= '&search_orderid='.urlencode($search_orderid);
}
if ($search_product) {
	$param .= '&search_product='.urlencode($search_product);
}

print '<form method="POST" id="searchFormList" action="'.$_SERVER["PHP_SELF"].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="list">';
print '<input type="hidden" name="sortfield" value="'.dol_escape_htmltag($sortfield).'">';
print '<input type="hidden" name="sortorder" value="'.dol_escape_htmltag($sortorder).'">';

$backtotransfers = dolGetButtonTitle($langs->trans("NopCommerceTransfers"), '', 'fa fa-exchange-alt paddingright', dol_buildpath('/nopcommerce/transfer_list.php', 1));

print_barre_liste($title, $page, $_SERVER["PHP_SELF"], $param, $sortfield, $sortorder, '', $num, $nbtotalofrecords, 'stock', 0, $backtotransfers, '', $limit, 0, 0, 1);

print '<div class="opacitymedium justify">'.$langs->trans("NopCommerceCompletedOrdersHint").'</div><br>';

print '<div class="div-table-responsive">';
print '<table class="tagtable nobottomiftotal liste">';

// Filter row
print '<tr class="liste_titre_filter">';
print '<td class="liste_titre"><input type="text" class="flat maxwidth100" name="search_orderid" value="'.dol_escape_htmltag($search_orderid).'"></td>';
print '<td class="liste_titre"><input type="text" class="flat maxwidth150" name="search_product" value="'.dol_escape_htmltag($search_product).'"></td>';
print '<td class="liste_titre center"></td>';
print '<td class="liste_titre center maxwidthsearch">'.$form->showFilterButtons().'</td>';
print '</tr>';

// Title row
print '<tr class="liste_titre">';
print_liste_field_titre("NopCommerceOrderId", $_SERVER["PHP_SELF"], "o.nop_order_id", "", $param, "", $sortfield, $sortorder);
print_liste_field_titre("NopCommerceOrderProductCount", $_SERVER["PHP_SELF"], "", "", $param, "", $sortfield, $sortorder, 'center ');
print_liste_field_titre("DateCreation", $_SERVER["PHP_SELF"], "o.date_creation", "", $param, '', $sortfield, $sortorder, 'center ');
print_liste_field_titre('', $_SERVER["PHP_SELF"], "", '', '', '', $sortfield, $sortorder, 'center maxwidthsearch ');
print '</tr>';

foreach ($orders as $orderrowid => $obj) {
	print '<tr class="oddeven cursorpointer nopordertrigger" data-orderid="'.$orderrowid.'">';
	print '<td class="nowraponall">'.dol_escape_htmltag((string) $obj->nop_order_id).'</td>';
	print '<td class="center">'.((int) $obj->nbitems).'</td>';
	print '<td class="center nowraponall">'.dol_print_date($db->jdate($obj->date_creation), 'dayhour').'</td>';
	print '<td class="center">'.img_picto($langs->trans("NopCommerceShowOrderProducts"), 'object_stock').'</td>';
	print '</tr>';
}

if (count($orders) == 0) {
	print '<tr><td colspan="4"><span class="opacitymedium">'.$langs->trans("NopCommerceNoCompletedOrderYet").'</span></td></tr>';
}

print '</table>';
print '</div>';
print '</form>';

// One hidden block per order, turned into a modal by jQuery UI on click. They live outside
// the form because the dialog widget moves its content to the end of the body.
foreach ($orders as $orderrowid => $obj) {
	print '<div id="nopordermodal-'.$orderrowid.'" class="nopordermodal" style="display: none;" title="'.dol_escape_htmltag($langs->trans("NopCommerceOrderProducts", $obj->nop_order_id)).'">';

	$items = isset($itemsbyorder[$orderrowid]) ? $itemsbyorder[$orderrowid] : array();

	if (count($items) == 0) {
		print '<span class="opacitymedium">'.$langs->trans("NopCommerceNoProductOnThisOrder").'</span>';
	} else {
		print '<div class="div-table-responsive-no-min">';
		print '<table class="noborder centpercent">';
		print '<tr class="liste_titre">';
		print '<td>'.$langs->trans("Product").'</td>';
		print '<td>'.$langs->trans("Attributes").'</td>';
		print '<td class="center">'.$langs->trans("NopCommerceExternalId").'</td>';
		print '</tr>';

		foreach ($items as $item) {
			$producttmp->id = $item->fk_product;
			$producttmp->ref = $item->product_ref;
			$producttmp->label = $item->product_label;
			$producttmp->type = $item->fk_product_type;

			$attributelabels = array();
			if (!empty($item->fk_product_parent)) {
				foreach ($synctmp->getVariantAttributes($item->fk_product) as $attribute) {
					$attributelabels[] = $attribute['attribute_label'].': '.$attribute['value_label'];
				}
			}

			print '<tr class="oddeven">';
			print '<td class="nowraponall">'.($producttmp->ref !== null ? $producttmp->getNomUrl(1) : dol_escape_htmltag((string) $item->fk_product)).'</td>';
			print '<td class="tdoverflowmax200">'.dol_escape_htmltag(implode(', ', $attributelabels)).'</td>';
			print '<td class="center">'.($item->nop_external_id !== null ? (int) $item->nop_external_id : '').'</td>';
			print '</tr>';
		}

		print '</table>';
		print '</div>';
	}

	print '</div>';
}

if (count($orders) > 0) {
	print '<script type="text/javascript">'."\n";
	print 'jQuery(document).ready(function() {'."\n";
	print '	jQuery(".nopordermodal").dialog({autoOpen: false, modal: true, height: "auto", width: Math.min(jQuery(window).width() - 40, 700)});'."\n";
	print '	jQuery(".nopordertrigger").click(function() {'."\n";
	print '		jQuery("#nopordermodal-" + jQuery(this).attr("data-orderid")).dialog("open");'."\n";
	print '		return false;'."\n";
	print '	});'."\n";
	print '});'."\n";
	print '</script>'."\n";
}

llxFooter();
$db->close();
