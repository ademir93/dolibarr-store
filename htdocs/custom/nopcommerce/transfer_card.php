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
 * \file    htdocs/custom/nopcommerce/transfer_card.php
 * \ingroup nopcommerce
 * \brief   Card of one nopCommerce sync transfer.
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
require_once './class/nopcommercetransferline.class.php';
require_once './class/nopcommercesync.class.php';
require_once './lib/nopcommerce.lib.php';

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

$langs->loadLangs(array("stocks", "products", "other", "nopcommerce@nopcommerce"));

// Parameters
$id = GETPOSTINT('id');
$ref = GETPOST('ref', 'alpha');
$action = GETPOST('action', 'aZ09');
$confirm = GETPOST('confirm', 'alpha');
$lineid = GETPOSTINT('lineid');
$backtopage = GETPOST('backtopage', 'alpha');

$object = new NopCommerceTransfer($db);
$sync = new NopCommerceSync($db);

if ($id > 0 || !empty($ref)) {
	$result = $object->fetch($id, $ref);
	if ($result <= 0) {
		dol_print_error($db, $object->error);
		exit;
	}
	$object->fetchLines();
}

// Access control
if (!isModEnabled('nopcommerce')) {
	accessforbidden();
}
if (!$user->hasRight('nopcommerce', 'read')) {
	accessforbidden();
}

$permissiontoread = $user->hasRight('nopcommerce', 'read');
$permissiontoadd = $user->hasRight('nopcommerce', 'write');
$permissiontodelete = $user->hasRight('nopcommerce', 'delete');

$backurlforlist = dol_buildpath('/nopcommerce/transfer_list.php', 1);


/*
 * Actions
 */

if ($action == 'add' && $permissiontoadd) {
	$object->label = GETPOST('label', 'alphanohtml');
	$object->fk_warehouse_source = GETPOSTINT('fk_warehouse_source');
	$object->fk_warehouse_destination = GETPOSTINT('fk_warehouse_destination');
	$object->note_private = GETPOST('note_private', 'restricthtml');

	$result = $object->create($user);
	if ($result > 0) {
		header("Location: ".$_SERVER["PHP_SELF"]."?id=".$object->id);
		exit;
	}

	setEventMessages($langs->trans($object->error), null, 'errors');
	$action = 'create';
}

if ($action == 'update' && $permissiontoadd) {
	$object->label = GETPOST('label', 'alphanohtml');
	if ($object->status == NopCommerceTransfer::STATUS_DRAFT) {
		$object->fk_warehouse_source = GETPOSTINT('fk_warehouse_source');
		$object->fk_warehouse_destination = GETPOSTINT('fk_warehouse_destination');
	}

	$result = $object->update($user);
	if ($result > 0) {
		header("Location: ".$_SERVER["PHP_SELF"]."?id=".$object->id);
		exit;
	}

	setEventMessages($langs->trans($object->error), null, 'errors');
	$action = 'edit';
}

if ($action == 'addline' && $permissiontoadd) {
	$fk_product = GETPOSTINT('fk_product');
	$qty = price2num(GETPOST('qty', 'alpha'), 'MS');
	$batch = GETPOST('batch', 'alphanohtml');

	if (empty($fk_product)) {
		setEventMessages($langs->trans("ErrorFieldRequired", $langs->transnoentitiesnoconv("Product")), null, 'errors');
	} else {
		$result = $object->addLine($user, $fk_product, (float) $qty, $batch);
		if ($result > 0) {
			setEventMessages($langs->trans("NopCommerceLineAdded"), null, 'mesgs');
			header("Location: ".$_SERVER["PHP_SELF"]."?id=".$object->id);
			exit;
		}
		setEventMessages($langs->trans($object->error), null, 'errors');
	}
}

if ($action == 'confirm_deleteline' && $confirm == 'yes' && $permissiontoadd) {
	$result = $object->deleteLine($user, $lineid);
	if ($result > 0) {
		setEventMessages($langs->trans("NopCommerceLineDeleted"), null, 'mesgs');
		header("Location: ".$_SERVER["PHP_SELF"]."?id=".$object->id);
		exit;
	}
	setEventMessages($langs->trans($object->error), null, 'errors');
	$action = '';
}

if ($action == 'confirm_validate' && $confirm == 'yes' && $permissiontoadd) {
	$result = $object->validate($user);
	if ($result > 0) {
		setEventMessages($langs->trans("NopCommerceTransferValidated"), null, 'mesgs');
		header("Location: ".$_SERVER["PHP_SELF"]."?id=".$object->id);
		exit;
	}
	setEventMessages($langs->trans($object->error), null, 'errors');
	$action = '';
}

if ($action == 'confirm_setdraft' && $confirm == 'yes' && $permissiontoadd) {
	$result = $object->setDraft($user);
	if ($result > 0) {
		setEventMessages($langs->trans("NopCommerceTransferSetToDraft"), null, 'mesgs');
		header("Location: ".$_SERVER["PHP_SELF"]."?id=".$object->id);
		exit;
	}
	setEventMessages($langs->trans($object->error), null, 'errors');
	$action = '';
}

if ($action == 'confirm_cancel' && $confirm == 'yes' && $permissiontoadd) {
	$result = $object->cancel($user);
	if ($result > 0) {
		setEventMessages($langs->trans("NopCommerceTransferCanceled"), null, 'mesgs');
		header("Location: ".$_SERVER["PHP_SELF"]."?id=".$object->id);
		exit;
	}
	setEventMessages($langs->trans($object->error), null, 'errors');
	$action = '';
}

if ($action == 'confirm_delete' && $confirm == 'yes' && $permissiontodelete) {
	$result = $object->delete($user);
	if ($result > 0) {
		setEventMessages($langs->trans("RecordDeleted"), null, 'mesgs');
		header("Location: ".$backurlforlist);
		exit;
	}
	setEventMessages($langs->trans($object->error), null, 'errors');
	$action = '';
}


/*
 * View
 */

$form = new Form($db);
$formproduct = new FormProduct($db);

$title = $langs->trans("NopCommerceTransfer");
llxHeader('', $title, '', '', 0, 0, '', '', '', 'mod-nopcommerce page-transfer_card');


// Create mode
if ($action == 'create') {
	if (!$permissiontoadd) {
		accessforbidden();
	}

	print load_fiche_titre($langs->trans("NewNopCommerceTransfer"), '', 'stock');

	print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="add">';

	print dol_get_fiche_head(array(), '');

	print '<table class="border centpercent">';

	print '<tr><td class="titlefieldcreate">'.$langs->trans("Label").'</td>';
	print '<td><input type="text" class="minwidth300" name="label" value="'.dol_escape_htmltag(GETPOST('label', 'alphanohtml')).'"></td></tr>';

	$defaultsource = GETPOSTINT('fk_warehouse_source') ? GETPOSTINT('fk_warehouse_source') : getDolGlobalInt('NOPCOMMERCE_SOURCE_WAREHOUSE_ID');
	print '<tr><td class="fieldrequired">'.$langs->trans("NopCommerceSourceWarehouse").'</td>';
	print '<td>'.$formproduct->selectWarehouses($defaultsource, 'fk_warehouse_source', 'warehouseopen', 1, 0, 0, '', 0, 0, array(), 'minwidth300').'</td></tr>';

	$defaultdest = GETPOSTINT('fk_warehouse_destination') ? GETPOSTINT('fk_warehouse_destination') : getDolGlobalInt('NOPCOMMERCE_WEBSHOP_WAREHOUSE_ID');
	print '<tr><td class="fieldrequired">'.$langs->trans("NopCommerceWebshopWarehouse").'</td>';
	print '<td>'.$formproduct->selectWarehouses($defaultdest, 'fk_warehouse_destination', 'warehouseopen', 1, 0, 0, '', 0, 0, array(), 'minwidth300').'</td></tr>';

	print '<tr><td>'.$langs->trans("NotePrivate").'</td>';
	print '<td><textarea name="note_private" class="centpercent" rows="3"></textarea></td></tr>';

	print '</table>';

	print dol_get_fiche_end();

	print $form->buttonsSaveCancel("Create", 'Cancel', array(), 0, '', '');
	print '</form>';
} elseif ($object->id > 0) {
	// View / edit mode
	$head = nopcommerceTransferPrepareHead($object);
	print dol_get_fiche_head($head, 'card', $langs->trans("NopCommerceTransfer"), -1, 'stock');

	// Confirmation dialogs
	$formconfirm = '';
	if ($action == 'validate') {
		$formconfirm = $form->formconfirm($_SERVER["PHP_SELF"].'?id='.$object->id, $langs->trans('NopCommerceValidateTransfer'), $langs->trans('NopCommerceConfirmValidateTransfer', $object->ref), 'confirm_validate', '', 0, 1);
	} elseif ($action == 'setdraft') {
		$formconfirm = $form->formconfirm($_SERVER["PHP_SELF"].'?id='.$object->id, $langs->trans('NopCommerceSetDraftTransfer'), $langs->trans('NopCommerceConfirmSetDraftTransfer', $object->ref), 'confirm_setdraft', '', 0, 1);
	} elseif ($action == 'cancel') {
		$formconfirm = $form->formconfirm($_SERVER["PHP_SELF"].'?id='.$object->id, $langs->trans('NopCommerceCancelTransfer'), $langs->trans('NopCommerceConfirmCancelTransfer', $object->ref), 'confirm_cancel', '', 0, 1);
	} elseif ($action == 'delete') {
		$formconfirm = $form->formconfirm($_SERVER["PHP_SELF"].'?id='.$object->id, $langs->trans('NopCommerceDeleteTransfer'), $langs->trans('NopCommerceConfirmDeleteTransfer', $object->ref), 'confirm_delete', '', 0, 1);
	} elseif ($action == 'deleteline') {
		$formconfirm = $form->formconfirm($_SERVER["PHP_SELF"].'?id='.$object->id.'&lineid='.$lineid, $langs->trans('NopCommerceDeleteLine'), $langs->trans('NopCommerceConfirmDeleteLine'), 'confirm_deleteline', '', 0, 1);
	}
	print $formconfirm;

	$linkback = '<a href="'.$backurlforlist.'?restore_lastsearch_values=1">'.$langs->trans("BackToList").'</a>';
	$morehtmlref = '<div class="refidno">';
	$morehtmlref .= dol_escape_htmltag($object->label);
	$morehtmlref .= '</div>';

	dol_banner_tab($object, 'ref', $linkback, 1, 'ref', 'ref', $morehtmlref);

	print '<div class="fichecenter">';
	print '<div class="fichehalfleft">';
	print '<div class="underbanner clearboth"></div>';

	print '<table class="border centpercent tableforfield">';

	$sourcewarehouse = new Entrepot($db);
	$sourcewarehouse->fetch($object->fk_warehouse_source);
	$destwarehouse = new Entrepot($db);
	$destwarehouse->fetch($object->fk_warehouse_destination);

	print '<tr><td class="titlefield">'.$langs->trans("NopCommerceSourceWarehouse").'</td>';
	print '<td>'.($sourcewarehouse->id > 0 ? $sourcewarehouse->getNomUrl(1) : '').'</td></tr>';

	print '<tr><td>'.$langs->trans("NopCommerceWebshopWarehouse").'</td>';
	print '<td>'.($destwarehouse->id > 0 ? $destwarehouse->getNomUrl(1) : '').'</td></tr>';

	print '<tr><td>'.$langs->trans("DateCreation").'</td>';
	print '<td>'.dol_print_date($object->date_creation, 'dayhour').'</td></tr>';

	print '</table>';
	print '</div>';

	print '<div class="fichehalfright">';
	print '<div class="underbanner clearboth"></div>';

	print '<table class="border centpercent tableforfield">';

	print '<tr><td class="titlefield">'.$langs->trans("NopCommerceSyncFlag").'</td><td>';
	print $object->sync_flag ? img_picto('', 'tick').' '.$langs->trans("NopCommerceSyncTrue") : img_picto('', 'off').' '.$langs->trans("NopCommerceSyncFalse");
	print '</td></tr>';

	print '<tr><td>'.$langs->trans("NopCommerceSyncAttempts").'</td>';
	print '<td>'.((int) $object->sync_attempts).'</td></tr>';

	print '<tr><td>'.$langs->trans("NopCommerceDatePulled").'</td>';
	print '<td>'.dol_print_date($object->date_pulled, 'dayhour').'</td></tr>';

	print '<tr><td>'.$langs->trans("NopCommerceDateSynced").'</td>';
	print '<td>'.dol_print_date($object->date_synced, 'dayhour').'</td></tr>';

	if (!empty($object->sync_last_error)) {
		print '<tr><td>'.$langs->trans("NopCommerceSyncLastError").'</td>';
		print '<td class="wordbreak error">'.dol_escape_htmltag($object->sync_last_error).'</td></tr>';
	}

	print '</table>';
	print '</div>';
	print '</div>';
	print '<div class="clearboth"></div>';

	print dol_get_fiche_end();


	// Lines
	print load_fiche_titre($langs->trans("NopCommerceTransferLines"), '', '');

	print '<div class="div-table-responsive-no-min">';
	print '<table class="noborder centpercent">';
	print '<tr class="liste_titre">';
	print '<td>'.$langs->trans("Product").'</td>';
	print '<td>'.$langs->trans("Attributes").'</td>';
	if (isModEnabled('productbatch')) {
		print '<td>'.$langs->trans("Batch").'</td>';
	}
	print '<td class="right">'.$langs->trans("Qty").'</td>';
	print '<td class="right">'.$langs->trans("NopCommerceSourceWarehouse").'</td>';
	print '<td class="right">'.$langs->trans("NopCommerceWebshopWarehouse").'</td>';
	print '<td class="center">'.$langs->trans("NopCommerceSyncFlag").'</td>';
	print '<td class="center">'.$langs->trans("NopCommerceProductId").'</td>';
	print '<td class="center"></td>';
	print '</tr>';

	$nbcols = isModEnabled('productbatch') ? 9 : 8;

	if (empty($object->lines)) {
		print '<tr><td colspan="'.$nbcols.'"><span class="opacitymedium">'.$langs->trans("NopCommerceNoLineYet").'</span></td></tr>';
	}

	foreach ($object->lines as $line) {
		$product = new Product($db);
		$product->fetch($line->fk_product);

		$attributes = $sync->getVariantAttributes($line->fk_product);
		$attributelabels = array();
		foreach ($attributes as $attribute) {
			$attributelabels[] = $attribute['attribute_label'].': '.$attribute['value_label'];
		}

		print '<tr class="oddeven">';
		print '<td>'.$product->getNomUrl(1).'</td>';
		print '<td class="tdoverflowmax200">'.dol_escape_htmltag(implode(', ', $attributelabels)).'</td>';
		if (isModEnabled('productbatch')) {
			print '<td>'.dol_escape_htmltag((string) $line->batch).'</td>';
		}
		print '<td class="right">'.price2num($line->qty, 'MS').'</td>';
		print '<td class="right">'.price2num($sync->getStockInWarehouse($line->fk_product, $object->fk_warehouse_source), 'MS').'</td>';
		print '<td class="right">'.price2num($sync->getStockInWarehouse($line->fk_product, $object->fk_warehouse_destination), 'MS').'</td>';
		print '<td class="center">';
		print $line->sync_flag ? img_picto($langs->trans('NopCommerceSyncTrue'), 'tick') : img_picto($langs->trans('NopCommerceSyncFalse'), 'off');
		if (!empty($line->sync_error)) {
			print ' '.$form->textwithpicto('', dol_escape_htmltag($line->sync_error), 1, 'warning');
		}
		print '</td>';
		print '<td class="center">'.($line->nop_product_id !== null ? (int) $line->nop_product_id : '').'</td>';
		print '<td class="center">';
		if ($object->status == NopCommerceTransfer::STATUS_DRAFT && $permissiontoadd) {
			print '<a class="reposition" href="'.$_SERVER["PHP_SELF"].'?id='.$object->id.'&lineid='.$line->id.'&action=deleteline&token='.newToken().'">'.img_delete().'</a>';
		}
		print '</td>';
		print '</tr>';
	}

	// Add a product to a draft transfer
	if ($object->status == NopCommerceTransfer::STATUS_DRAFT && $permissiontoadd && $action != 'deleteline') {
		print '<tr class="liste_titre nodrag nodrop">';
		print '<td colspan="'.$nbcols.'">'.$langs->trans("NopCommerceAddProduct").'</td>';
		print '</tr>';

		print '</table></div>';

		print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'?id='.$object->id.'">';
		print '<input type="hidden" name="token" value="'.newToken().'">';
		print '<input type="hidden" name="action" value="addline">';
		print '<input type="hidden" name="id" value="'.$object->id.'">';

		print '<table class="noborder centpercent">';
		print '<tr class="oddeven">';
		print '<td>'.$form->select_produits(0, 'fk_product', '', 0, 0, 1, 2, '', 0, array(), 0, '1', 0, 'minwidth300', 0, '', null, 1).'</td>';
		if (isModEnabled('productbatch')) {
			print '<td><input type="text" class="maxwidth100" name="batch" value="" placeholder="'.$langs->trans("Batch").'"></td>';
		}
		print '<td class="right"><input type="text" class="maxwidth50 right" name="qty" value="1"></td>';
		print '<td class="center"><input type="submit" class="button button-add small" value="'.$langs->trans("Add").'"></td>';
		print '</tr>';
		print '</table>';
		print '</form>';
	} else {
		print '</table></div>';
	}


	// Action buttons
	print '<div class="tabsAction">';

	if ($action != 'validate' && $action != 'setdraft' && $action != 'cancel' && $action != 'delete' && $action != 'deleteline') {
		if ($object->status == NopCommerceTransfer::STATUS_DRAFT && $permissiontoadd) {
			if (!empty($object->lines)) {
				print dolGetButtonAction('', $langs->trans('NopCommerceValidateTransfer'), 'default', $_SERVER["PHP_SELF"].'?id='.$object->id.'&action=validate&token='.newToken(), '');
			} else {
				print dolGetButtonAction($langs->trans('ATransferNeedsAtLeastOneLine'), $langs->trans('NopCommerceValidateTransfer'), 'default', '#', '', false);
			}
		}

		if (in_array((int) $object->status, array(NopCommerceTransfer::STATUS_PENDING, NopCommerceTransfer::STATUS_FAILED), true) && $permissiontoadd) {
			print dolGetButtonAction('', $langs->trans('NopCommerceSetDraftTransfer'), 'default', $_SERVER["PHP_SELF"].'?id='.$object->id.'&action=setdraft&token='.newToken(), '');
			print dolGetButtonAction('', $langs->trans('NopCommerceCancelTransfer'), 'delete', $_SERVER["PHP_SELF"].'?id='.$object->id.'&action=cancel&token='.newToken(), '');
		}

		if ($object->status != NopCommerceTransfer::STATUS_SYNCED && $permissiontodelete) {
			print dolGetButtonAction('', $langs->trans('Delete'), 'delete', $_SERVER["PHP_SELF"].'?id='.$object->id.'&action=delete&token='.newToken(), '');
		}
	}

	print '</div>';
}

llxFooter();
$db->close();
