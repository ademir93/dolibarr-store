<?php
/* Copyright (C) 2026 MyStore
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
 * \file    custom/mystore/class/actions_mystore.class.php
 * \ingroup mystore
 * \brief   Hooks for MyStore module: partial refund (credit note) from the TakePOS
 *          screen, plus an Epson TM-T20 (80mm thermal) receipt layout.
 *
 * Registered on contexts 'takeposinvoice' and 'takeposfrontend' (see modMyStore module_parts).
 * - completeTakePosInvoiceHeader: injects a "Refund" button next to the core
 *   "Create credit note" button and a modal to pick lines/quantities to return.
 * - doActions: handles action=refundlines (AJAX): creates a partial credit note,
 *   restocks returned products into the terminal warehouse and registers the
 *   cash refund payment. Answers JSON and exits.
 * - TakeposReceipt: replaces the browser "Print ticket" HTML (takepos/receipt.php)
 *   with a receipt formatted for an Epson TM-T20 (80mm monospace roll printer).
 */

require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';

/**
 * Class ActionsMystore
 */
class ActionsMystore
{
	/**
	 * @var DoliDB Database handler.
	 */
	public $db;

	/**
	 * @var string Error code (or message)
	 */
	public $error = '';

	/**
	 * @var string[] Errors
	 */
	public $errors = array();

	/**
	 * @var string[] Warnings
	 */
	public $warnings = array();

	/**
	 * @var array Hook results. Propagated to $hookmanager->resArray for later reuse
	 */
	public $results = array();

	/**
	 * @var ?string String displayed by executeHook() immediately after return
	 */
	public $resprints;

	/**
	 * Constructor
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Is the given user a member of the "sales" landing group?
	 *
	 * The group name defaults to "Sales" and can be overridden with the global
	 * constant MYSTORE_POS_LANDING_GROUP. Membership is scoped to the current
	 * entity. Shared by the login redirect and the header CSS hiding below.
	 *
	 * @param User $user User to test
	 * @return bool			True if the user belongs to the target group
	 */
	private function userInSalesGroup($user)
	{
		if (empty($user) || empty($user->id)) {
			return false;
		}

		$targetgroup = getDolGlobalString('MYSTORE_POS_LANDING_GROUP', 'Sales');
		if (empty($targetgroup)) {
			return false;
		}

		$ismember = false;
		$sql = "SELECT ug.rowid";
		$sql .= " FROM ".MAIN_DB_PREFIX."usergroup as ug";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."usergroup_user as ugu ON ugu.fk_usergroup = ug.rowid";
		$sql .= " WHERE ugu.fk_user = ".((int) $user->id);
		$sql .= " AND ug.entity IN (".getEntity('usergroup').")";
		$sql .= " AND ug.nom = '".$this->db->escape($targetgroup)."'";
		$resql = $this->db->query($sql);
		if ($resql) {
			$ismember = ($this->db->num_rows($resql) > 0);
			$this->db->free($resql);
		}

		return $ismember;
	}

	/**
	 * printCommonFooter hook (context 'main', fired on every back-office page).
	 *
	 * For members of the "sales" group, injects a small CSS block that hides three
	 * top-header items on the right side: the Home tab, the global search input and
	 * its clear/submit affordance. Everyone else is left untouched.
	 *
	 * printCommonFooter does not emit $hookmanager->resPrint, so the HTML must be
	 * printed directly here (returning 0 keeps the normal footer running).
	 *
	 * @param array<string,mixed>	$parameters		Hook metadata (context, etc...)
	 * @param CommonObject|string	$object			Current object (unused)
	 * @param string				$action			Current action
	 * @param HookManager			$hookmanager	Hook manager
	 * @return int									0 = OK
	 */
	public function printCommonFooter($parameters, &$object, &$action, $hookmanager)
	{
		global $user;

		if (!$this->userInSalesGroup($user)) {
			return 0;
		}

		print "\n<!-- mystore: hide header items for the Sales group -->\n";
		print '<style type="text/css">'."\n";
		// Home tab in the top horizontal menu.
		print "#mainmenutd_home { display: none !important; }\n";
		// Whole top-right global search dropdown (input + magnifier + clear button).
		print "#topmenu-global-search-dropdown { display: none !important; }\n";
		// Fallbacks in case the search input is rendered outside that container.
		print "#top-global-search-input { display: none !important; }\n";
		print "#top-global-search-input::-webkit-search-cancel-button { -webkit-appearance: none; display: none !important; }\n";
		print "</style>\n";

		return 0;
	}

	/**
	 * printFieldListWhere hook (contexts 'invoicelist' and 'poslist', fired while
	 * compta/facture/list.php and the TakePOS sales-history popup build the invoice
	 * list SQL).
	 *
	 * For users holding the mystore->poshistory permission (and who are not
	 * administrators, who already see everything), restricts the invoice list to
	 * TakePOS sales (module_source = 'takepos') and further narrows it to the
	 * terminal(s) whose configured warehouse (CASHDESK_ID_WAREHOUSEn) matches the
	 * user's own default warehouse. Everyone else is left untouched.
	 *
	 * @param array<string,mixed>	$parameters		Hook metadata (context, etc...)
	 * @param CommonObject|string	$object			Current object (unused)
	 * @param string				$action			Current action
	 * @param HookManager			$hookmanager	Hook manager
	 * @return int									0 = OK ($this->resprints holds the extra SQL condition)
	 */
	public function printFieldListWhere($parameters, &$object, &$action, $hookmanager)
	{
		global $user;

		$contexts = explode(':', (string) ($parameters['context'] ?? ''));
		if (!in_array('invoicelist', $contexts) && !in_array('poslist', $contexts)) {
			return 0;
		}
		if (empty($user) || !empty($user->admin)) {
			return 0;
		}
		if (!$user->hasRight('mystore', 'poshistory')) {
			return 0;
		}

		// Scope invoice lists to TakePOS sales; f is the llx_facture alias in compta/facture/list.php
		$where = " AND f.module_source = 'takepos'";

		// Limit to terminals whose configured warehouse matches the user's default warehouse
		if (!empty($user->fk_warehouse)) {
			$terminals = array();
			$numterminals = max(1, getDolGlobalInt('TAKEPOS_NUM_TERMINALS'));
			for ($i = 1; $i <= $numterminals; $i++) {
				if (getDolGlobalInt('CASHDESK_ID_WAREHOUSE'.$i) == (int) $user->fk_warehouse) {
					$terminals[] = "'".$this->db->escape((string) $i)."'";
				}
			}
			if (count($terminals) > 0) {
				$where .= " AND f.pos_source IN (".implode(',', $terminals).")";
			}
		}

		$this->resprints = $where;
		return 0;
	}

	/**
	 * afterLogin hook (context 'login', fired once from main.inc.php right after a
	 * successful authentication, before the MAIN_LANDING_PAGE redirect block).
	 *
	 * Sends members of the "sales" group straight to the POS terminal so that a
	 * salesperson lands on /takepos/index.php on login instead of the dashboard.
	 * Administrators and every other group are left untouched.
	 *
	 * The target group name defaults to "Sales" and can be overridden with the
	 * global constant MYSTORE_POS_LANDING_GROUP (Setup > Other Setup, or via
	 * Home > Setup > Other). The redirect only happens if the user actually holds
	 * the takepos->run permission, so a mis-configured group can never lock a user
	 * onto a page they are not allowed to open.
	 *
	 * @param array<string,mixed>	$parameters		Hook metadata (currentcontext, dol_authmode, dol_loginfo...)
	 * @param User					$object			The freshly logged-in user
	 * @param string				$action			Current action
	 * @param HookManager			$hookmanager	Hook manager
	 * @return int									0 = continue (this method exits on redirect)
	 */
	public function afterLogin($parameters, &$object, &$action, $hookmanager)
	{
		global $conf;

		// Only act in the login context.
		if (empty($parameters['currentcontext']) || $parameters['currentcontext'] != 'login') {
			return 0;
		}

		$user = $object;

		// Never redirect administrators.
		if (empty($user) || !empty($user->admin)) {
			return 0;
		}

		// Only redirect users who are actually allowed to use the POS terminal.
		if (!$user->hasRight('takepos', 'run')) {
			return 0;
		}

		// Only members of the "sales" group are sent to the POS terminal.
		if (!$this->userInSalesGroup($user)) {
			return 0;
		}

		// Send the salesperson straight to the POS terminal.
		$newurl = dol_buildpath('/takepos/index.php', 1);
		if ($_SERVER["PHP_SELF"] != $newurl) {   // avoid a redirect loop
			header('Location: '.$newurl);
			exit;
		}

		return 0;
	}

	/**
	 * Answer current AJAX request with JSON and stop.
	 *
	 * @param array<string,mixed> $data Data to encode
	 * @return never
	 */
	private function answerJson($data)
	{
		if (function_exists('top_httphead')) {
			top_httphead('application/json');
		} elseif (!headers_sent()) {
			header('Content-Type: application/json');
		}
		print json_encode($data);
		exit;
	}

	/**
	 * Create ALL selected variant combinations at once (cartesian product).
	 *
	 * Hooked on context 'combinationcard' from htdocs/variants/combinations.php, for the
	 * "Create all defined combinations" submit. Selected attribute/value pairs arrive in
	 * $parameters['features'], each entry "attributeId-valueId" (accumulated in the session
	 * by core). Core keys them by attribute id, so several values of the SAME attribute
	 * overwrite each other and only one combination is ever created. Here we group the
	 * values per attribute and create one product combination for every element of the
	 * cartesian product across attributes.
	 *
	 * @param array<string,mixed>	$parameters		Hook metadata; contains 'id' and 'features'
	 * @param Product				$object			Parent product (loaded by combinations.php)
	 * @param string				$action			Current action ('add' or 'create')
	 * @param HookManager			$hookmanager	Hook manager
	 * @return int									>0/<0 = handled (core skips), 0 = not handled (core runs). Exits on success.
	 */
	private function createAllVariantCombinations($parameters, &$object, &$action, $hookmanager)
	{
		global $langs, $user;

		require_once DOL_DOCUMENT_ROOT.'/variants/class/ProductAttribute.class.php';
		require_once DOL_DOCUMENT_ROOT.'/variants/class/ProductAttributeValue.class.php';
		require_once DOL_DOCUMENT_ROOT.'/variants/class/ProductCombination.class.php';

		$langs->loadLangs(array('products', 'errors'));

		$id = (int) (isset($parameters['id']) ? $parameters['id'] : $object->id);
		$features = (isset($parameters['features']) && is_array($parameters['features'])) ? $parameters['features'] : array();

		// Nothing selected: let core handle it (plain "add" page load, or its own ErrorFieldsRequired).
		if (empty($features)) {
			return 0;
		}

		// Re-read the same submit fields core reads. Kept self-contained so we do not depend on
		// combinations.php page-level variables.
		$reference = trim((string) GETPOST('reference', 'alpha'));
		if (empty($reference)) {
			$reference = false;
		}
		$weight_impact = (float) price2num(GETPOSTFLOAT('weight_impact', 2));
		$price_impact_percent = (bool) GETPOST('price_impact_percent');
		$price_impact = $price_impact_percent ? GETPOSTFLOAT('price_impact', 2) : GETPOSTFLOAT('price_impact', 'MU');
		$price_impact = price2num($price_impact);
		$clone_categories = (bool) GETPOST('clone_categories');

		$level_price_impact = array_map('floatval', array_map('price2num', GETPOST('level_price_impact', 'array')));
		$level_price_impact_percent = array_map('boolval', GETPOST('level_price_impact_percent', 'array'));
		if (!getDolGlobalString('PRODUIT_MULTIPRICES') && !getDolGlobalString('PRODUIT_CUSTOMER_PRICES_AND_MULTIPRICES')) {
			$level_price_impact = array(1 => $price_impact);
			$level_price_impact_percent = array(1 => $price_impact_percent);
		}

		// 1) Group selected values by attribute (validated + deduplicated).
		$prodattr = new ProductAttribute($this->db);
		$prodattr_val = new ProductAttributeValue($this->db);
		$grouped = array();
		foreach ($features as $feature) {
			$explode = explode('-', $feature);
			$attrid = (int) $explode[0];
			$valid = isset($explode[1]) ? (int) $explode[1] : 0;
			if ($attrid <= 0 || $valid <= 0) {
				continue;
			}
			if ($prodattr->fetch($attrid) <= 0 || $prodattr_val->fetch($valid) <= 0) {
				continue;
			}
			if (!isset($grouped[$attrid])) {
				$grouped[$attrid] = array();
			}
			if (!in_array($valid, $grouped[$attrid])) {
				$grouped[$attrid][] = $valid;
			}
		}

		if (empty($grouped)) {
			setEventMessages($langs->trans('ErrorFieldsRequired'), null, 'errors');
			return 1; // handled (invalid selection): prevent core from re-processing
		}

		// 2) Cartesian product: one value per attribute per combination.
		$combos = array(array());
		foreach ($grouped as $attrid => $valueids) {
			$next = array();
			foreach ($combos as $combo) {
				foreach ($valueids as $vid) {
					$combo[$attrid] = $vid;
					$next[] = $combo;
				}
			}
			$combos = $next;
		}

		// 3) Create each combination, skipping any that already exists.
		$prodcomb = new ProductCombination($this->db);
		$created = 0;
		$existing = 0;
		$error = 0;

		$this->db->begin();
		foreach ($combos as $sanit_features) {
			if ($prodcomb->fetchByProductCombination2ValuePairs($id, $sanit_features)) {
				$existing++;
				continue;
			}

			// Only force a reference when a single combination is created; forcing the same
			// reference on several variants would collide them onto one product.
			$forced_ref = (count($combos) == 1) ? $reference : false;

			$result = $prodcomb->createProductCombination($user, $object, $sanit_features, array(), $level_price_impact_percent, $level_price_impact, $weight_impact, $forced_ref, '', $clone_categories);
			if ($result > 0) {
				$created++;
			} else {
				$langs->load('errors');
				setEventMessages($prodcomb->error, $prodcomb->errors, 'errors');
				$error++;
				break;
			}
		}

		if ($error) {
			$this->db->rollback();
			$this->errors[] = 'createProductCombinationFailed';
			return -1; // handled with error: core must not create anything
		}

		$this->db->commit();

		if ($created > 0) {
			setEventMessages($langs->trans('RecordSaved'), null, 'mesgs');
		} elseif ($existing > 0) {
			setEventMessages($langs->trans('ErrorRecordAlreadyExists'), null, 'errors');
		}

		unset($_SESSION['addvariant_'.$id]);

		// Same redirect as core after a successful create. Safe: combinations.php sends no output
		// before llxHeader() (which is well after this action block).
		header('Location: '.dol_buildpath('/variants/combinations.php?id='.$id, 2));
		exit();
	}

	/**
	 * Handle action=refundlines called by the Refund modal (AJAX POST).
	 * Creates a credit note containing only the selected lines/quantities of the
	 * source invoice, validates it with stock increase into the terminal warehouse
	 * (same mechanism as core takepos action=creditnote) and registers the cash
	 * refund payment (same mechanism as core takepos action=valid).
	 *
	 * @param array<string,mixed>	$parameters		Hook metadata (context, etc...)
	 * @param Facture				$object			The source invoice (loaded by takepos/invoice.php from invoiceid param)
	 * @param string				$action			Current action
	 * @param HookManager			$hookmanager	Hook manager
	 * @return int									0 = OK (this method exits when it handles the action)
	 */
	public function doActions($parameters, &$object, &$action, $hookmanager)
	{
		global $conf, $langs, $user;

		// MyStore: on the product variants page (context 'combinationcard') core only ever
		// creates a SINGLE combination when several are selected. We take over the "Create"
		// submit and create EVERY selected combination as a cartesian product instead.
		// See htdocs/custom/mystore/doc/variants-combinations-multi-create.md.
		if (in_array('combinationcard', (array) $hookmanager->contextarray)) {
			return $this->createAllVariantCombinations($parameters, $object, $action, $hookmanager);
		}

		// This module registers hooks under several contexts (takeposinvoice, login, main).
		// HookManager runs a module's hook method only ONCE, for the first context it finds
		// (see $modulealreadyexecuted in hookmanager.class.php), so $parameters['currentcontext']
		// may be 'main' here rather than 'takeposinvoice'. Qualify on the page's full context
		// list instead - it contains 'takeposinvoice' only on the POS invoice page.
		if (!in_array('takeposinvoice', (array) $hookmanager->contextarray)) {
			return 0;
		}
		if ($action != 'refundlines') {
			return 0;
		}

		$langs->load('mystore@mystore');

		if (!$user->hasRight('takepos', 'run') || !$user->hasRight('facture', 'creer')) {
			$this->answerJson(array('ok' => 0, 'error' => $langs->trans('NotEnoughPermissions')));
		}

		// Source invoice must be a validated (or paid) standard invoice
		if ($object->id <= 0 || $object->type == Facture::TYPE_CREDIT_NOTE || $object->status < Facture::STATUS_VALIDATED) {
			$this->answerJson(array('ok' => 0, 'error' => $langs->trans('MyStoreRefundErrorSourceInvoice')));
		}

		$lineids = GETPOST('refundlineid', 'array:int');
		$lineqtys = GETPOST('refundqty', 'array:int');

		// Build map "source line rowid" => "qty to refund", clamped to original line qty
		$sourcelines = array();
		foreach ($object->lines as $line) {
			$sourcelines[$line->id] = $line;
		}
		$torefund = array();
		if (is_array($lineids) && is_array($lineqtys)) {
			foreach ($lineids as $i => $lineid) {
				$lineid = (int) $lineid;
				$qty = isset($lineqtys[$i]) ? (float) $lineqtys[$i] : 0;
				if ($lineid > 0 && $qty > 0 && array_key_exists($lineid, $sourcelines)) {
					$torefund[$lineid] = min($qty, (float) $sourcelines[$lineid]->qty);
				}
			}
		}
		if (empty($torefund)) {
			$this->answerJson(array('ok' => 0, 'error' => $langs->trans('MyStoreRefundErrorNoLines')));
		}

		$term = isset($_SESSION['takeposterminal']) ? $_SESSION['takeposterminal'] : '';

		$error = 0;
		$errormessage = '';
		$this->db->begin();

		// Create the credit note with same source properties as core takepos action=creditnote
		$creditnote = new Facture($this->db);
		$creditnote->socid = $object->socid;
		$creditnote->date = dol_now();
		$creditnote->module_source = 'takepos';	// So the credit note appears in POS history and cash desk closing
		$creditnote->pos_source = $term;
		$creditnote->type = Facture::TYPE_CREDIT_NOTE;
		$creditnote->fk_facture_source = $object->id;
		$result = $creditnote->create($user);
		if ($result <= 0) {
			$this->db->rollback();
			$this->answerJson(array('ok' => 0, 'error' => ($creditnote->error ? $creditnote->error : 'Error creating credit note')));
		}

		// Add only the selected lines with the selected quantities.
		// Facture::addline() inverts signs itself for credit notes (qty kept positive,
		// unit price made negative) and computes correct totals for the partial qty.
		foreach ($torefund as $lineid => $qty) {
			$line = $sourcelines[$lineid];

			$vatrate = (string) $line->tva_tx;
			if (!empty($line->vat_src_code) && !preg_match('/\(/', $vatrate)) {
				$vatrate .= ' ('.$line->vat_src_code.')';
			}

			$result = $creditnote->addline(
				$line->desc,
				$line->subprice,
				$qty,
				$vatrate,
				$line->localtax1_tx,
				$line->localtax2_tx,
				$line->fk_product,
				$line->remise_percent,
				'', // date_start
				'', // date_end
				0, // fk_code_ventilation
				$line->info_bits,
				0, // fk_remise_except
				'HT',
				0, // pu_ttc
				$line->product_type,
				-1, // rang
				$line->special_code,
				'', // origin
				0, // origin_id
				0, // fk_parent_line
				$line->fk_fournprice,
				$line->pa_ht,
				'', // label
				is_array($line->array_options) ? $line->array_options : array(),
				100, // situation_percent
				0, // fk_prev_id
				$line->fk_unit
			);
			if ($result <= 0) {
				$error++;
				$errormessage = $creditnote->error;
				break;
			}
		}

		if ($error) {
			$this->db->rollback();
			$this->answerJson(array('ok' => 0, 'error' => ($errormessage ? $errormessage : 'Error adding refund lines')));
		}

		// Reload a clean object (totals, lines) before validation
		$creditnote->fetch($creditnote->id);

		// Validate the credit note. Same stock logic as core takepos action=creditnote:
		// forcing STOCK_CALCULATE_ON_BILL makes validate() record a stock reception
		// (increase) into the terminal warehouse for each product line.
		$constantforkey = 'CASHDESK_NO_DECREASE_STOCK'.$term;
		$allowstockchange = getDolGlobalString($constantforkey) != "1";

		if (isModEnabled('stock') && !isModEnabled('productbatch') && $allowstockchange) {
			$savconst = getDolGlobalString('STOCK_CALCULATE_ON_BILL');
			$conf->global->STOCK_CALCULATE_ON_BILL = 1;

			$constantforkey = 'CASHDESK_ID_WAREHOUSE'.$term;
			dol_syslog("MyStore refund: validate credit note with stock change into warehouse defined into constant ".$constantforkey." = ".getDolGlobalString($constantforkey));

			$res = $creditnote->validate($user, '', getDolGlobalInt($constantforkey), 0, 0);

			$conf->global->STOCK_CALCULATE_ON_BILL = $savconst;
		} else {
			$res = $creditnote->validate($user);
		}
		if ($res < 0) {
			$this->db->rollback();
			$this->answerJson(array('ok' => 0, 'error' => ($creditnote->error ? $creditnote->error : 'Error validating credit note')));
		}

		// Batch/lot products: record the stock receptions manually (same as core takepos action=creditnote)
		if (isModEnabled('stock') && isModEnabled('productbatch') && $allowstockchange) {
			require_once DOL_DOCUMENT_ROOT.'/product/stock/class/mouvementstock.class.php';
			$constantforkey = 'CASHDESK_ID_WAREHOUSE'.$term;
			$inventorycode = dol_print_date(dol_now(), 'dayhourlog');
			$labeltakeposmovement = 'TakePOS - '.$langs->trans("CreditNote").' '.$creditnote->ref;

			foreach ($creditnote->lines as $line) {
				if ($line->fk_product <= 0) {
					continue;
				}
				// Use the warehouse id defined on invoice line else in the setup
				$warehouseid = ($line->fk_warehouse ? $line->fk_warehouse : getDolGlobalInt($constantforkey));
				if ($warehouseid <= 0) {
					continue;
				}
				$mouvP = new MouvementStock($this->db);
				$mouvP->setOrigin($creditnote->element, $creditnote->id);
				$res2 = $mouvP->reception($user, $line->fk_product, $warehouseid, $line->qty, $line->price, $labeltakeposmovement, '', '', (string) $line->batch, '', 0, $inventorycode);
				if ($res2 < 0) {
					$error++;
					$errormessage = $mouvP->error;
					break;
				}
			}
			if ($error) {
				$this->db->rollback();
				$this->answerJson(array('ok' => 0, 'error' => ($errormessage ? $errormessage : 'Error recording stock movement')));
			}
		}

		// Register the cash refund (negative payment), same mechanism as core takepos action=valid
		require_once DOL_DOCUMENT_ROOT.'/compta/paiement/class/paiement.class.php';

		$paid = 0;
		$amountrefunded = 0;
		$bankaccount = getDolGlobalInt('CASHDESK_ID_BANKACCOUNT_CASH'.$term);
		$remaintopay = $creditnote->getRemainToPay(); // Negative for a credit note

		if ($remaintopay < 0 && (!isModEnabled('bank') || $bankaccount > 0)) {
			// Retrieve id of payment mode 'LIQ' (cash)
			$paiementid = 0;
			$sql = "SELECT id FROM ".MAIN_DB_PREFIX."c_paiement";
			$sql .= " WHERE entity IN (".getEntity('c_paiement').")";
			$sql .= " AND code = 'LIQ'";
			$resql = $this->db->query($sql);
			if ($resql) {
				$objp = $this->db->fetch_object($resql);
				if ($objp) {
					$paiementid = $objp->id;
				}
			}

			$payment = new Paiement($this->db);
			$payment->datepaye = dol_now();
			$payment->fk_account = $bankaccount;
			$payment->amounts[$creditnote->id] = $remaintopay;
			$payment->paiementid = $paiementid;
			$payment->paiementcode = 'LIQ';
			$payment->num_payment = '';

			$res = $payment->create($user);
			if ($res < 0) {
				$error++;
				$errormessage = $payment->error;
			} elseif (isModEnabled('bank')) {
				$res = $payment->addPaymentToBank($user, 'payment', '(CustomerInvoicePayment)', $bankaccount, '', '');
				if ($res < 0) {
					$error++;
					$errormessage = $payment->error;
				}
			}

			if (!$error) {
				$amountrefunded = abs($remaintopay);
				$remaintopay = $creditnote->getRemainToPay();
				if (empty(price2num($remaintopay, 'MT'))) {
					$creditnote->setPaid($user);
					$creditnote->setPaymentMethods($paiementid);
					$paid = 1;
				}
			}
		}

		if ($error) {
			$this->db->rollback();
			$this->answerJson(array('ok' => 0, 'error' => ($errormessage ? $errormessage : 'Error recording refund payment')));
		}

		$this->db->commit();

		if ($paid) {
			// Currency code (not symbol): the message is shown in a js alert() where HTML entities would not render
			$message = $langs->trans('MyStoreRefundDone', $creditnote->ref, price($amountrefunded).' '.$conf->currency);
		} else {
			$message = $langs->trans('MyStoreRefundDoneNoPayment', $creditnote->ref);
		}

		$this->answerJson(array(
			'ok' => 1,
			'creditnoteid' => $creditnote->id,
			'ref' => $creditnote->ref,
			'paid' => $paid,
			'message' => $message
		));
	}

	/**
	 * Inject the "Refund" button (next to the core "Create credit note" button)
	 * and the refund modal for the invoice currently displayed on the POS screen.
	 * Only a <script> tag is emitted: the hook output lands inside a <tr> of the
	 * POS lines table, where raw HTML would be moved around by the browser.
	 *
	 * @param array<string,mixed>	$parameters		Hook metadata (context, etc...)
	 * @param Facture				$object			The invoice currently displayed
	 * @param string				$action			Current action
	 * @param HookManager			$hookmanager	Hook manager
	 * @return int									0 = OK
	 */
	public function completeTakePosInvoiceHeader($parameters, &$object, &$action, $hookmanager)
	{
		global $conf, $langs, $user;

		$this->resprints = '';

		// This module registers hooks under several contexts (takeposinvoice, login, main).
		// HookManager runs a module's hook method only ONCE, for the first context it finds
		// (see $modulealreadyexecuted in hookmanager.class.php), so $parameters['currentcontext']
		// may be 'main' here rather than 'takeposinvoice'. Qualify on the page's full context
		// list instead - it contains 'takeposinvoice' only on the POS invoice page.
		if (!in_array('takeposinvoice', (array) $hookmanager->contextarray)) {
			return 0;
		}

		// After a completed sale (action=valid), core takepos runs parent.ClearSearch(true)
		// (takepos/invoice.php), which triggers Search2 with an empty term; that empty-term
		// branch blanks every product tile (#prodiv*) and returns WITHOUT reloading the
		// catalog, so the product grid stays empty until a full page reload. Re-render the
		// catalog for the current terminal/warehouse so the products reappear automatically.
		// This script prints at invoice.php:~1876, i.e. AFTER the ClearSearch call, so it wins.
		// Kept above the credit-note gates below so it runs even when the refund button is off.
		$repaintscript = '';
		if ($action == 'valid') {
			$repaintscript = '<script>
(function () {
	try {
		if (typeof parent.PrintCategories === "function") { parent.PrintCategories(0); }
		if (typeof parent.LoadProducts === "function") { parent.LoadProducts(0); }
	} catch (e) { if (window.console) { console.error("mystore: catalog repaint failed", e); } }
})();
</script>'."\n";
		}
		$this->resprints = $repaintscript;

		// Same visibility conditions as the core "Create credit note" button (takepos/invoice.php)
		if (!(($action == "valid" || $action == "history" || ($action == "addline" && $object->status == Facture::STATUS_CLOSED))
			&& $object->type != Facture::TYPE_CREDIT_NOTE
			&& !getDolGlobalString('TAKEPOS_NO_CREDITNOTE'))) {
			return 0;
		}
		// Show the Refund button to every POS role (same visibility as the core
		// "Create credit note" button, which is not gated by facture rights either).
		// Actually performing the refund still requires facture->creer — that check
		// lives in the doActions() refundlines handler, not here.
		if (empty($object->id) || $object->id <= 0 || empty($object->lines)) {
			return 0;
		}

		$langs->load('mystore@mystore');

		// Build one row per refundable invoice line
		$rowshtml = '';
		foreach ($object->lines as $line) {
			if ($line->qty <= 0) {
				continue;
			}
			$label = '';
			if (!empty($line->product_label)) {
				$label = $line->product_label;
			} elseif (!empty($line->desc)) {
				$label = $line->desc;
			} elseif (!empty($line->product_ref)) {
				$label = $line->product_ref;
			}
			$unitttc = ($line->qty != 0) ? price2num($line->total_ttc / $line->qty, 'MT') : 0;

			$rowshtml .= '<tr style="border-bottom:1px solid #ddd;">';
			$rowshtml .= '<td class="left" style="padding:6px 4px;">'.dol_escape_htmltag($label).'</td>';
			$rowshtml .= '<td class="right" style="padding:6px 4px;">'.price($unitttc, 0, $langs, 1, -1, -1, $conf->currency).'</td>';
			$rowshtml .= '<td class="center" style="padding:6px 4px;">'.price2num($line->qty).'</td>';
			$rowshtml .= '<td class="center nowrap" style="padding:6px 4px;">';
			$rowshtml .= '<button type="button" class="mystore-refund-step" data-dir="-1" style="width:38px;height:38px;font-size:18px;">-</button> ';
			$rowshtml .= '<input type="number" class="mystore-refund-qty" data-lineid="'.((int) $line->id).'" data-puttc="'.$unitttc.'" value="0" min="0" max="'.price2num($line->qty).'" step="1" style="width:52px;height:34px;text-align:center;font-size:16px;"> ';
			$rowshtml .= '<button type="button" class="mystore-refund-step" data-dir="1" style="width:38px;height:38px;font-size:18px;">+</button>';
			$rowshtml .= '</td>';
			$rowshtml .= '</tr>';
		}
		if (empty($rowshtml)) {
			return 0;
		}

		$modalhtml = '<div id="ModalRefund" class="modal">';
		$modalhtml .= '<div class="modal-content" style="width:90%;max-width:700px;">';
		$modalhtml .= '<div class="modal-header">';
		$modalhtml .= '<span class="close" onclick="document.getElementById(\'ModalRefund\').style.display=\'none\';">&times;</span>';
		$modalhtml .= '<h3>'.dol_escape_htmltag($langs->trans('MyStoreRefundTitle', $object->ref)).'</h3>';
		$modalhtml .= '</div>';
		$modalhtml .= '<div class="modal-body">';
		$modalhtml .= '<table class="centpercent" style="border-collapse:collapse;">';
		$modalhtml .= '<tr class="liste_titre">';
		$modalhtml .= '<th class="left">'.dol_escape_htmltag($langs->trans('MyStoreRefundProduct')).'</th>';
		$modalhtml .= '<th class="right">'.dol_escape_htmltag($langs->trans('MyStoreRefundUnitPrice')).'</th>';
		$modalhtml .= '<th class="center">'.dol_escape_htmltag($langs->trans('MyStoreRefundQtySold')).'</th>';
		$modalhtml .= '<th class="center">'.dol_escape_htmltag($langs->trans('MyStoreRefundQtyToReturn')).'</th>';
		$modalhtml .= '</tr>';
		$modalhtml .= $rowshtml;
		$modalhtml .= '</table><br>';
		$modalhtml .= '<button type="button" class="block" id="ModalRefundDo">'.dol_escape_htmltag($langs->trans('MyStoreRefundDo')).'</button>';
		$modalhtml .= '<button type="button" class="block" onclick="document.getElementById(\'ModalRefund\').style.display=\'none\';">'.dol_escape_htmltag($langs->trans('Cancel')).'</button>';
		$modalhtml .= '</div></div></div>';

		$mystoreissales = $this->userInSalesGroup($user) ? 'true' : 'false';

		$script = '<script>
(function () {
	// Inject the Refund button independently of the modal, so a modal-init error can
	// never prevent the button from appearing. The core credit-note button is part of
	// the fragment that takepos loads into #poslines via AJAX, so it may not be painted
	// yet when this script first runs - retry for a few seconds until it exists.
	function mystoreInjectRefund(attempts) {
		var cnbtn = $("#poslines").find("button[onclick*=\'ModalCreditNote\'], a[onclick*=\'ModalCreditNote\']").first();
		if (cnbtn.length == 0) {
			if (attempts > 0) { setTimeout(function () { mystoreInjectRefund(attempts - 1); }, 150); }
			return;
		}
		if ($("#mystorerefundbutton").length == 0) {
			cnbtn.after(\' <button id="mystorerefundbutton" type="button">'.dol_escape_js($langs->trans('MyStoreRefund')).'</button>\');
			$("#mystorerefundbutton").on("click", function () { ModalBox("ModalRefund"); });
		}
		// Hide the native "Create credit note" button only for members of the sales group.
		if ('.$mystoreissales.') { cnbtn.hide(); } else { cnbtn.show(); }
	}
	mystoreInjectRefund(20);

	try {
	// (Re)create the refund modal with the data of the invoice currently loaded
	$("#ModalRefund").remove();
	$("body").append('.json_encode($modalhtml).');

	// Touch friendly +/- steppers
	$("#ModalRefund .mystore-refund-step").on("click", function () {
		var input = $(this).closest("td").find(".mystore-refund-qty");
		var val = (parseInt(input.val(), 10) || 0) + parseInt($(this).attr("data-dir"), 10);
		var max = parseInt(input.attr("max"), 10) || 0;
		if (val < 0) { val = 0; }
		if (val > max) { val = max; }
		input.val(val);
	});

	// Submit the refund
	$("#ModalRefundDo").on("click", function () {
		var ids = [], qtys = [], total = 0;
		$("#ModalRefund .mystore-refund-qty").each(function () {
			var q = parseInt($(this).val(), 10) || 0;
			var max = parseInt($(this).attr("max"), 10) || 0;
			if (q > max) { q = max; }
			if (q > 0) {
				ids.push($(this).attr("data-lineid"));
				qtys.push(q);
				total += q * parseFloat($(this).attr("data-puttc"));
			}
		});
		if (ids.length == 0) {
			alert("'.dol_escape_js($langs->trans('MyStoreRefundNothingSelected')).'");
			return;
		}
		var totaltxt = total.toFixed(2) + " '.dol_escape_js($conf->currency).'";
		if (!confirm("'.dol_escape_js($langs->trans('MyStoreRefundConfirm', '%s')).'".replace("%s", totaltxt))) {
			return;
		}
		$("#ModalRefundDo").prop("disabled", true);
		$.ajax({
			type: "POST",
			url: "'.DOL_URL_ROOT.'/takepos/invoice.php",
			dataType: "json",
			data: {
				action: "refundlines",
				token: "'.newToken().'",
				invoiceid: '.((int) $object->id).',
				place: (typeof place === "undefined" ? 0 : place),
				refundlineid: ids,
				refundqty: qtys
			},
			success: function (r) {
				$("#ModalRefundDo").prop("disabled", false);
				if (r && r.ok) {
					document.getElementById("ModalRefund").style.display = "none";
					alert(r.message);
					// Load the created credit note into the POS screen (core history path)
					$("#poslines").load("'.DOL_URL_ROOT.'/takepos/invoice.php?action=history&token='.newToken().'&placeid=" + r.creditnoteid);
				} else {
					alert(r && r.error ? r.error : "Error");
				}
			},
			error: function (xhr) {
				$("#ModalRefundDo").prop("disabled", false);
				alert("Refund error: " + (xhr.responseText || xhr.status));
			}
		});
	});
	} catch (e) {
		if (window.console) { console.error("mystore: refund modal init failed", e); }
	}
})();
</script>';

		$this->resprints = $repaintscript.$script;

		return 0;
	}

	/**
	 * Hook: replace the TakePOS browser receipt with an Epson TM-T20 layout.
	 *
	 * Fires from htdocs/takepos/receipt.php on context 'takeposfrontend'
	 * (action 'TakeposReceipt'). receipt.php has already emitted <html><head> (via
	 * top_htmlhead) and <body>; when we set $hookmanager->resPrint it is printed and
	 * the page returns immediately, skipping the whole default receipt. So we output
	 * BODY CONTENT ONLY (with our own inline <style>), sized for an 80mm thermal roll
	 * (~72mm printable), monospace, and we trigger window.print() ourselves.
	 *
	 * Honors the same admin settings as the core receipt where relevant:
	 * TAKEPOS_RECEIPT_NAME, TAKEPOS_SHOW_CUSTOMER, TAKEPOS_HIDE_DATE_OF_PRINTING,
	 * TAKEPOS_SHOW_HT_RECEIPT, TAKEPOS_HEADER / TAKEPOS_FOOTER (+ terminal suffix).
	 *
	 * Note: in LNE certified mode receipt.php never calls this hook (format is forced),
	 * so this method only takes effect when LNE is off.
	 *
	 * @param array			$parameters		Hook parameters (empty here)
	 * @param Facture		$object			The POS invoice being printed
	 * @param string		$action			Current action
	 * @param HookManager	$hookmanager	Hook manager
	 * @return int							0 = OK
	 */
	public function TakeposReceipt($parameters, &$object, &$action, $hookmanager)
	{
		global $conf, $langs, $mysoc;

		// Only act on the POS front receipt page.
		if (!in_array('takeposfrontend', (array) $hookmanager->contextarray)) {
			return 0;
		}
		if (empty($object) || empty($object->id)) {
			return 0;
		}

		$langs->loadLangs(array('mystore@mystore', 'cashdesk', 'companies', 'bills', 'main'));
		$currency = $conf->currency;
		$term = isset($_SESSION['takeposterminal']) ? $_SESSION['takeposterminal'] : '0';

		$showht = getDolGlobalInt('TAKEPOS_SHOW_HT_RECEIPT');

		// --- Free header/footer text (same constants + substitutions as core receipt) ---
		$freeheader = getDolGlobalString('TAKEPOS_HEADER').getDolGlobalString('TAKEPOS_HEADER'.$term);
		$freefooter = getDolGlobalString('TAKEPOS_FOOTER').getDolGlobalString('TAKEPOS_FOOTER'.$term);
		if ($freeheader !== '' || $freefooter !== '') {
			$substitutionarray = getCommonSubstitutionArray($langs, 0, null, $object);
			complete_substitutions_array($substitutionarray, $langs, $object);
			if ($freeheader !== '') {
				$freeheader = make_substitutions($freeheader, $substitutionarray);
			}
			if ($freefooter !== '') {
				$freefooter = make_substitutions($freefooter, $substitutionarray);
			}
		}

		// --- Group VAT by rate (for the tax breakdown block) ---
		$vatgroups = array();
		if (is_array($object->lines)) {
			foreach ($object->lines as $line) {
				$rate = (string) price2num($line->tva_tx);
				if (!isset($vatgroups[$rate])) {
					$vatgroups[$rate] = array('base' => 0, 'vat' => 0);
				}
				$vatgroups[$rate]['base'] += $line->total_ht;
				$vatgroups[$rate]['vat'] += $line->total_tva;
			}
			ksort($vatgroups, SORT_NUMERIC);
		}

		// --- Payments + change (no payment data is in scope at the hook; query it) ---
		$payments = array();
		$totalchange = 0;
		$sql = "SELECT p.datep as date, p.num_paiement as num, pf.amount as amount, p.pos_change as pos_change, cp.code as code";
		$sql .= " FROM ".MAIN_DB_PREFIX."paiement_facture as pf";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."paiement as p ON p.rowid = pf.fk_paiement";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."c_paiement as cp ON cp.id = p.fk_paiement AND cp.entity IN (".getEntity('c_paiement').")";
		$sql .= " WHERE pf.fk_facture = ".((int) $object->id);
		$sql .= " ORDER BY p.datep";
		$resql = $this->db->query($sql);
		if ($resql) {
			while ($row = $this->db->fetch_object($resql)) {
				$label = $langs->transnoentitiesnoconv("PaymentTypeShort".$row->code);
				if ($label == "PaymentTypeShort".$row->code) {
					$label = $row->code;
				}
				$payments[] = array('label' => $label, 'amount' => (float) $row->amount);
				$totalchange += (float) $row->pos_change;
			}
			$this->db->free($resql);
		}

		// ------------------------------------------------------------------ output
		$out = '<style>
* { margin:0; padding:0; box-sizing:border-box; }
body { width:72mm; margin:0 auto; padding:2mm 0; color:#000; background:#fff;
       font-family:"Courier New",Consolas,monospace; font-size:12px; line-height:1.25; }
.tm-c { text-align:center; } .tm-r { text-align:right; } .tm-l { text-align:left; }
.tm-b { font-weight:bold; }
.tm-name { font-size:16px; font-weight:bold; }
.tm-big { font-size:15px; }
.tm-sep { border:0; border-top:1px dashed #000; margin:4px 0; }
.tm-row { display:flex; justify-content:space-between; align-items:baseline; gap:6px; }
.tm-row > span:last-child { white-space:nowrap; }
.tm-item { margin:2px 0; }
.tm-muted { font-size:11px; }
@media print { @page { margin:0; size:72mm auto; } body { width:72mm; } }
</style>
';
		$out .= '<div id="tm-receipt">';

		// Company header
		$out .= '<div class="tm-c tm-name">'.dol_escape_htmltag($mysoc->name).'</div>';
		$addr = array();
		if (!empty($mysoc->address)) {
			$addr[] = dol_escape_htmltag($mysoc->address);
		}
		$cityline = trim($mysoc->zip.' '.$mysoc->town);
		if ($cityline !== '') {
			$addr[] = dol_escape_htmltag($cityline);
		}
		if (!empty($mysoc->phone)) {
			$addr[] = $langs->transnoentitiesnoconv("Phone").': '.dol_escape_htmltag($mysoc->phone);
		}
		if (!empty($mysoc->idprof1)) {
			$addr[] = dol_escape_htmltag($langs->transcountrynoentities("ProfId1Short", $mysoc->country_code).': '.$mysoc->idprof1);
		}
		if (!empty($mysoc->tva_intra)) {
			$addr[] = dol_escape_htmltag($langs->transnoentitiesnoconv("VATIntra").': '.$mysoc->tva_intra);
		}
		if (count($addr)) {
			$out .= '<div class="tm-c tm-muted">'.implode('<br>', $addr).'</div>';
		}

		// Free header text
		if ($freeheader !== '') {
			$out .= '<div class="tm-c tm-muted">'.dol_nl2br($freeheader).'</div>';
		}

		$out .= '<hr class="tm-sep">';

		// Receipt meta (ref, date, terminal)
		$receiptlabel = getDolGlobalString('TAKEPOS_RECEIPT_NAME');
		if ($receiptlabel === '') {
			$receiptlabel = $langs->transnoentitiesnoconv("InvoiceRef");
		}
		$out .= '<div class="tm-row"><span>'.dol_escape_htmltag($receiptlabel).'</span><span class="tm-b">'.dol_escape_htmltag($object->ref).'</span></div>';
		if (!getDolGlobalInt('TAKEPOS_HIDE_DATE_OF_PRINTING')) {
			$out .= '<div class="tm-row"><span>'.$langs->transnoentitiesnoconv("Date").'</span><span>'.dol_print_date($object->date ? $object->date : dol_now(), 'dayhour').'</span></div>';
		}
		$out .= '<div class="tm-row"><span>'.$langs->transnoentitiesnoconv("PointOfSale").'</span><span>'.dol_escape_htmltag($term).'</span></div>';

		// Customer (optional)
		if (getDolGlobalInt('TAKEPOS_SHOW_CUSTOMER') && $object->socid > 0) {
			$object->fetch_thirdparty();
			if (!empty($object->thirdparty->name)) {
				$out .= '<div class="tm-row"><span>'.$langs->transnoentitiesnoconv("Customer").'</span><span>'.dol_escape_htmltag($object->thirdparty->name).'</span></div>';
			}
		}

		$out .= '<hr class="tm-sep">';

		// Line items: label on its own line, then "qty x unit" + line total
		if (is_array($object->lines)) {
			foreach ($object->lines as $line) {
				$label = !empty($line->product_label) ? $line->product_label : $line->desc;
				$label = dol_string_nohtmltag($label);
				$unit = ($line->qty != 0) ? ($showht ? $line->total_ht / $line->qty : $line->total_ttc / $line->qty) : 0;
				$linetotal = $showht ? $line->total_ht : $line->total_ttc;
				$out .= '<div class="tm-item">';
				$out .= '<div class="tm-l">'.dol_escape_htmltag($label).'</div>';
				$out .= '<div class="tm-row tm-muted"><span>'.price2num($line->qty).' x '.price($unit, 0, $langs, 0, -1, -1, $currency).'</span><span class="tm-b">'.price($linetotal, 0, $langs, 0, -1, -1, $currency).'</span></div>';
				$out .= '</div>';
			}
		}

		$out .= '<hr class="tm-sep">';

		// Totals
		if ($showht) {
			$out .= '<div class="tm-row"><span>'.$langs->transnoentitiesnoconv("TotalHT").'</span><span>'.price($object->total_ht, 0, $langs, 0, -1, -1, $currency).'</span></div>';
			foreach ($vatgroups as $rate => $g) {
				$out .= '<div class="tm-row tm-muted"><span>'.$langs->transnoentitiesnoconv("VAT").' '.vatrate($rate, true).'</span><span>'.price($g['vat'], 0, $langs, 0, -1, -1, $currency).'</span></div>';
			}
			$out .= '<div class="tm-row"><span>'.$langs->transnoentitiesnoconv("TotalVAT").'</span><span>'.price($object->total_tva, 0, $langs, 0, -1, -1, $currency).'</span></div>';
		} else {
			// TTC receipt: still show the VAT contained per rate (common on fiscal tickets)
			foreach ($vatgroups as $rate => $g) {
				$out .= '<div class="tm-row tm-muted"><span>'.$langs->transnoentitiesnoconv("VAT").' '.vatrate($rate, true).'</span><span>'.price($g['vat'], 0, $langs, 0, -1, -1, $currency).'</span></div>';
			}
		}
		$out .= '<div class="tm-row tm-big tm-b"><span>'.$langs->transnoentitiesnoconv("TotalTTC").'</span><span>'.price($object->total_ttc, 0, $langs, 0, -1, -1, $currency).'</span></div>';

		// Payments + change
		if (count($payments)) {
			$out .= '<hr class="tm-sep">';
			foreach ($payments as $p) {
				$out .= '<div class="tm-row"><span>'.dol_escape_htmltag($p['label']).'</span><span>'.price($p['amount'], 0, $langs, 0, -1, -1, $currency).'</span></div>';
			}
			if ($totalchange > 0) {
				$out .= '<div class="tm-row"><span>'.$langs->transnoentitiesnoconv("Change").'</span><span>'.price($totalchange, 0, $langs, 0, -1, -1, $currency).'</span></div>';
			}
		}

		// Free footer text
		if ($freefooter !== '') {
			$out .= '<hr class="tm-sep">';
			$out .= '<div class="tm-c tm-muted">'.dol_nl2br($freefooter).'</div>';
		}

		$out .= '<div class="tm-c tm-muted" style="margin-top:6px;">'.$langs->transnoentitiesnoconv("MyStoreReceiptThanks").'</div>';
		$out .= '</div>'; // #tm-receipt

		// We took over the page before its auto window.print(); trigger it ourselves.
		$out .= '<script>window.onload = function () { window.print(); };</script>';

		$hookmanager->resPrint = $out;

		return 0;
	}
}
