<?php
/* Copyright (C) 2026		Maxime DION				<m.dion@guiltek.fr>
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

declare(strict_types=1);

/**
 * \file    installments/list.php
 * \ingroup installments
 * \brief   Installments of all the schedules (Billing > Payment schedules), or of one customer (tab of the thirdparty)
 */

// Load Dolibarr environment
$res = 0;
// Try main.inc.php into web root known defined into CONTEXT_DOCUMENT_ROOT (not always defined)
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include str_replace("..", "", $_SERVER["CONTEXT_DOCUMENT_ROOT"])."/main.inc.php";
}
// Try main.inc.php into web root detected using web root calculated from SCRIPT_FILENAME
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
// Try main.inc.php using relative path
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
require_once DOL_DOCUMENT_ROOT.'/core/lib/company.lib.php';
require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';
dol_include_once('/installments/class/installmentschedule.class.php');

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var HookManager $hookmanager
 * @var Translate $langs
 * @var User $user
 */

$langs->loadLangs(array('bills', 'banks', 'companies', 'installments@installments'));

if (!isModEnabled('installments') || !isModEnabled('invoice') || !$user->hasRight('installments', 'read') || !$user->hasRight('facture', 'lire') || $user->socid > 0) {
	accessforbidden();
}

$socid = GETPOSTINT('socid');
if ($socid > 0) {	// Tab of a thirdparty: only one the user can see
	restrictedArea($user, 'societe', $socid, '&societe');
}
$action = GETPOST('action', 'aZ09');
$massaction = GETPOST('massaction', 'alpha');
$confirm = GETPOST('confirm', 'alpha');
$toselect = GETPOST('toselect', 'array:int');
$limit = GETPOSTINT('limit') ?: $conf->liste_limit;
$sortfield = GETPOST('sortfield', 'aZ09comma');
$sortorder = GETPOST('sortorder', 'aZ09comma') ?: 'ASC';
$page = GETPOSTISSET('pageplusone') ? (GETPOSTINT('pageplusone') - 1) : GETPOSTINT('page');
$contextpage = 'installmentslist';
$search_ref = GETPOST('search_ref', 'alphanohtml');
$search_company = GETPOST('search_company', 'alphanohtml');
$search_state = GETPOSTISSET('search_state') ? GETPOST('search_state', 'aZ09') : 'open';
$search_type = GETPOST('search_type', 'aZ09');
$search_type = (in_array($search_type, array('payment', 'billing')) ? $search_type : '');	// The empty choice of Form::selectarray() is -1
$search_mode = GETPOSTINT('search_mode');
$search_date_start = GETPOSTDATE('search_date_start', '00:00:00', 'tzserver');
$search_date_end = GETPOSTDATE('search_date_end', '23:59:59', 'tzserver');
$sortfields = array('date', 'ref', 'socname', 'amount', 'remain', 'state');
if (!in_array($sortfield, $sortfields)) {
	$sortfield = 'date';
}
// Columns of the list, in order, that the user can hide like in the lists of the core
$arrayfields = array('date' => array('label' => 'InstallmentsDueDate', 'checked' => '1', 'css' => 'center'), 'ref' => array('label' => 'Ref', 'checked' => '1'),
	'socname' => array('label' => 'Customer', 'checked' => '1'), 'amount' => array('label' => 'AmountTTC', 'checked' => '1', 'css' => 'right'),
	'remain' => array('label' => 'RemainderToPay', 'checked' => '1', 'css' => 'right'), 'mode' => array('label' => 'PaymentMode', 'checked' => '1'),
	'type' => array('label' => 'Type', 'checked' => '1'), 'state' => array('label' => 'Status', 'checked' => '1', 'css' => 'center'));
// Names of the buttons of Form::showFilterButtons(), tested like the lists of the core for all browsers
$removefilter = (GETPOST('button_removefilter_x', 'alpha') || GETPOST('button_removefilter.x', 'alpha') || GETPOST('button_removefilter', 'alpha'));
if ($page < 0 || $removefilter || GETPOST('button_search_x', 'alpha') || GETPOST('button_search.x', 'alpha') || GETPOST('button_search', 'alpha')) {
	$page = 0;
}
if ($removefilter) {
	$search_ref = '';
	$search_company = '';
	$search_state = 'open';
	$search_type = '';
	$search_mode = 0;
	$search_date_start = 0;
	$search_date_end = 0;
}
$states = array('open' => array('late', 'todo', 'tocheck'), 'late' => array('late'), 'todo' => array('todo'), 'tocheck' => array('tocheck'), 'paid' => array('paid'),
	'invoiced' => array('invoiced'), 'abandoned' => array('abandoned'), 'all' => array());
$search_state = (isset($states[$search_state]) ? $search_state : 'open');

$param = ($limit != $conf->liste_limit ? '&limit='.((int) $limit) : '').($socid ? '&socid='.$socid : '');
$param .= ($search_ref !== '' ? '&search_ref='.urlencode($search_ref) : '').($search_company !== '' ? '&search_company='.urlencode($search_company) : '');
$param .= '&search_state='.urlencode($search_state).($search_type ? '&search_type='.urlencode($search_type) : '').($search_mode ? '&search_mode='.$search_mode : '');
$param .= ($search_date_start ? '&search_date_startday='.dol_print_date($search_date_start, '%d').'&search_date_startmonth='.dol_print_date($search_date_start, '%m').'&search_date_startyear='.dol_print_date($search_date_start, '%Y') : '');
$param .= ($search_date_end ? '&search_date_endday='.dol_print_date($search_date_end, '%d').'&search_date_endmonth='.dol_print_date($search_date_end, '%m').'&search_date_endyear='.dol_print_date($search_date_end, '%Y') : '');

$installments = new InstallmentSchedule($db);
$hookmanager->initHooks(array('installmentslist'));

$arrayofmassactions = array();
if ($user->hasRight('facture', 'paiement')) {
	$arrayofmassactions['prerecordpayments'] = $langs->trans('InstallmentsRecordPayments');
}
if ($user->hasRight('installments', 'write') && $user->hasRight('facture', 'creer')) {
	$arrayofmassactions['precreateinvoices'] = $langs->trans('InstallmentsCreateInvoices');
}


/*
 * Actions
 */

if (GETPOST('cancel', 'alpha') || !GETPOST('confirmmassaction', 'alpha') || !isset($arrayofmassactions[$massaction])) {
	$massaction = '';
}
if ($massaction && empty($toselect)) {
	setEventMessages($langs->trans('NoRecordSelected'), null, 'warnings');
	$massaction = '';
}

$parameters = array('arrayofselected' => $toselect);
$reshook = $hookmanager->executeHooks('doActions', $parameters, $installments, $action); // Note that $action and $object may have been modified by some hooks
if ($reshook < 0) {
	setEventMessages($hookmanager->error, $hookmanager->errors, 'errors');
}
if (empty($reshook)) {	// Columns chosen by the user
	include DOL_DOCUMENT_ROOT.'/core/actions_changeselectedfields.inc.php';
}

if (empty($reshook) && $confirm == 'yes' && $action == 'confirm_recordpayments' && isset($arrayofmassactions['prerecordpayments']) && !empty($toselect)) {	// Test on permission already done: the mass actions of the user
	$result = $installments->recordPayments($user, $toselect, GETPOSTDATE('paymentdate', '12:00:00', 'tzserver') ?: dol_now(), GETPOSTINT('paymentmode'), GETPOSTINT('accountid'), GETPOST('paymentnum', 'alphanohtml'));
	if ($result < 0) {
		setEventMessages($installments->error, $installments->errors, 'errors');
	} else {
		setEventMessages($langs->trans('InstallmentsNbPayments', $result), $installments->errors, ($installments->errors ? 'warnings' : 'mesgs'));
	}
	header('Location: '.$_SERVER['PHP_SELF'].'?'.ltrim($param, '&'));
	exit;
}
if (empty($reshook) && $confirm == 'yes' && $action == 'confirm_createinvoices' && isset($arrayofmassactions['precreateinvoices']) && !empty($toselect)) {	// Test on permission already done: the mass actions of the user
	$nb = 0;
	$errors = array();
	$rows = $installments->getInstallments($user, array('type' => 'billing'));
	foreach (is_array($rows) ? $rows : array() as $row) {
		if (!in_array($row->id, $toselect) || $row->invoiceid || $row->status != InstallmentSchedule::STATUS_OPEN || !$user->hasRight($row->element, 'creer')) {	// Like the tab: the right to modify the proposal or the order; never one to check
			continue;
		}
		$schedule = $installments->fetch($row->element, $row->fk_element);
		foreach (is_object($schedule) ? $schedule->lines : array() as $line) {
			if ($line->id == $row->id) {
				if ($installments->createInvoice($user, $schedule, $line) > 0) {
					$nb++;
				} else {
					$errors[] = $installments->error;
				}
			}
		}
	}
	setEventMessages($langs->trans('InstallmentsInvoicesCreated', $nb), array_merge($errors, $installments->errors), ($errors || $installments->errors ? 'warnings' : 'mesgs'));
	header('Location: '.$_SERVER['PHP_SELF'].'?'.ltrim($param, '&'));
	exit;
}


/*
 * View
 */

$form = new Form($db);
$filters = array('socid' => $socid, 'type' => $search_type, 'ref' => $search_ref, 'company' => $search_company, 'date_start' => $search_date_start, 'date_end' => $search_date_end,
	'states' => $states[$search_state], 'mode' => $search_mode);
$rows = $installments->getInstallments($user, $filters);
if (!is_array($rows)) {
	setEventMessages($installments->error, null, 'errors');
	$rows = array();
}
$total = 0.0;
foreach ($rows as $row) {
	$row->remain = ($row->billing ? $row->amount : $row->amount - $row->paid);
	$total += ($row->state == 'abandoned' ? 0.0 : $row->remain);
}
$rows = dol_sort_array($rows, $sortfield, $sortorder);
$nbtotalofrecords = count($rows);
if ($limit * $page >= $nbtotalofrecords) {	// A filter reduced the list below the page asked
	$page = 0;
}
$rows = array_slice($rows, $limit * $page, $limit);
$num = count($rows);
$massactionbutton = $form->selectMassAction('', $arrayofmassactions);

$title = $langs->trans('InstallmentsMenu');
llxHeader('', $title, '', '', 0, 0, '', '', '', 'mod-installments page-list');

if ($socid > 0) {	// Tab of the thirdparty
	$societe = new Societe($db);
	$societe->fetch($socid);
	print dol_get_fiche_head(societe_prepare_head($societe), 'installments', $langs->trans('ThirdParty'), -1, 'company');
	dol_banner_tab($societe, 'socid', '<a href="'.DOL_URL_ROOT.'/societe/list.php?restore_lastsearch_values=1">'.$langs->trans('BackToList').'</a>', ($user->socid ? 0 : 1), 'rowid', 'nom');
	print dol_get_fiche_end();
}

print '<form method="POST" id="searchFormList" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="formfilteraction" id="formfilteraction" value="list">';
print '<input type="hidden" name="socid" value="'.((int) $socid).'">';
print '<input type="hidden" name="sortfield" value="'.dolPrintHTMLForAttribute($sortfield).'">';
print '<input type="hidden" name="sortorder" value="'.dolPrintHTMLForAttribute($sortorder).'">';
print '<input type="hidden" name="page" value="'.((int) $page).'">';

print_barre_liste($title, $page, $_SERVER['PHP_SELF'], $param, $sortfield, $sortorder, $massactionbutton, $num, $nbtotalofrecords, 'fa-calendar-alt', 0, '', '', $limit, 0, 0, 1);

if ($massaction == 'prerecordpayments') {
	$modes = array();
	$resql = $db->query("SELECT id, code, libelle FROM ".$db->prefix()."c_paiement WHERE active = 1 AND type IN (0, 2) AND entity IN (".getEntity('c_paiement').")".$db->order('libelle', 'ASC'));
	while ($resql && $obj = $db->fetch_object($resql)) {
		$key = 'PaymentType'.$obj->code;
		$modes[(int) $obj->id] = ($langs->trans($key) != $key ? $langs->trans($key) : $obj->libelle);
	}
	$questions = array(
		array('type' => 'date', 'name' => 'paymentdate', 'label' => $langs->trans('Date'), 'value' => dol_now()),
		array('type' => 'select', 'name' => 'paymentmode', 'label' => $langs->trans('PaymentMode'), 'values' => $modes, 'default' => dol_getIdFromCode($db, 'VIR', 'c_paiement', 'code', 'id', 1)),
		array('type' => 'text', 'name' => 'paymentnum', 'label' => $langs->trans('Numero').' <em>('.$langs->trans('ChequeOrTransferNumber').')</em>'),
	);
	if (isModEnabled('bank')) {	// The account of the invoices by default, else the first current account (a cash account takes only cash)
		$accounts = array();
		$default = getDolGlobalInt('FACTURE_RIB_NUMBER');
		$resql = $db->query("SELECT rowid, label, courant FROM ".$db->prefix()."bank_account WHERE clos = 0 AND entity IN (".getEntity('bank_account').")".$db->order('label', 'ASC'));
		while ($resql && $obj = $db->fetch_object($resql)) {
			$accounts[(int) $obj->rowid] = $obj->label;
			$default = ($default ?: ((int) $obj->courant == Account::TYPE_CURRENT ? (int) $obj->rowid : 0));
		}
		$questions[] = array('type' => 'select', 'name' => 'accountid', 'label' => $langs->trans('AccountToCredit'), 'values' => $accounts, 'default' => $default);
	}
	print $form->formconfirm($_SERVER['PHP_SELF'].'?'.ltrim($param, '&'), $arrayofmassactions[$massaction], $langs->trans('InstallmentsConfirmPayments', count($toselect)), 'confirm_recordpayments', $questions, 1, 0, 260, 600, 1);
}
if ($massaction == 'precreateinvoices') {
	print $form->formconfirm($_SERVER['PHP_SELF'].'?'.ltrim($param, '&'), $arrayofmassactions[$massaction], $langs->trans('InstallmentsConfirmInvoices', count($toselect)), 'confirm_createinvoices', array(), 1, 0, 200, 500, 1);
}

$statelabels = array();
foreach (array_keys($states) as $state) {
	$statelabels[$state] = $langs->trans('InstallmentsState'.dol_ucfirst($state));
}
$selectedfields = $form->multiSelectArrayWithCheckbox('selectedfields', $arrayfields, $contextpage, $conf->main_checkbox_left_column);	// Also applies the choice of the user to $arrayfields
$selectedfields .= ($massactionbutton ? $form->showCheckAddButtons('checkforselect', 1) : '');
$left = $conf->main_checkbox_left_column;
$searchfields = array(
	'date' => '<div class="nowrap">'.$form->selectDate($search_date_start ?: -1, 'search_date_start', 0, 0, 1, '', 1, 0, 0, '', '', '', '', 1, '', $langs->trans('From'))
		.'</div><div class="nowrap">'.$form->selectDate($search_date_end ?: -1, 'search_date_end', 0, 0, 1, '', 1, 0, 0, '', '', '', '', 1, '', $langs->trans('to')).'</div>',
	'ref' => '<input type="text" class="flat maxwidth75" name="search_ref" value="'.dolPrintHTMLForAttribute($search_ref).'">',
	'socname' => '<input type="text" class="flat maxwidth100" name="search_company" value="'.dolPrintHTMLForAttribute($search_company).'">',
	'mode' => $form->select_types_paiements($search_mode, 'search_mode', 'CRDT', 0, 1, 0, 0, 1, 'maxwidth100', 1),
	'type' => $form->selectarray('search_type', array('payment' => $langs->trans('InstallmentsToCollect'), 'billing' => $langs->trans('InstallmentsToInvoice')), $search_type, 1, 0, 0, '', 0, 0, 0, '', 'maxwidth100'),
	'state' => $form->selectarray('search_state', $statelabels, $search_state, 0, 0, 0, '', 0, 0, 0, '', 'maxwidth100'),
);

print '<div class="div-table-responsive">';
print '<table class="tagtable nobottomiftotal noborder liste">';
print '<tr class="liste_titre_filter">'.($left ? '<td class="liste_titre center maxwidthsearch">'.$form->showFilterButtons('left').'</td>' : '');
foreach ($arrayfields as $key => $field) {
	if (!empty($field['checked'])) {
		print '<td class="liste_titre'.(empty($field['css']) ? '' : ' '.$field['css']).'">'.(isset($searchfields[$key]) ? $searchfields[$key] : '').'</td>';
	}
}
$parameters = array('arrayfields' => $arrayfields);
$reshook = $hookmanager->executeHooks('printFieldListOption', $parameters, $installments, $action); // Note that $action and $object may have been modified by hook
print $hookmanager->resPrint;
print ($left ? '' : '<td class="liste_titre center maxwidthsearch">'.$form->showFilterButtons().'</td>').'</tr>';
print '<tr class="liste_titre">';
if ($left) {
	print_liste_field_titre($selectedfields, $_SERVER['PHP_SELF'], '', '', '', '', $sortfield, $sortorder, 'center maxwidthsearch ');
}
foreach ($arrayfields as $key => $field) {
	if (!empty($field['checked'])) {
		print_liste_field_titre($field['label'], $_SERVER['PHP_SELF'], (in_array($key, $sortfields) ? $key : ''), '', $param, '', $sortfield, $sortorder, (empty($field['css']) ? '' : $field['css'].' '));
	}
}
$parameters = array('arrayfields' => $arrayfields, 'param' => $param, 'sortfield' => $sortfield, 'sortorder' => $sortorder);
$reshook = $hookmanager->executeHooks('printFieldListTitle', $parameters, $installments, $action); // Note that $action and $object may have been modified by hook
print $hookmanager->resPrint;
if (!$left) {
	print_liste_field_titre($selectedfields, $_SERVER['PHP_SELF'], '', '', '', '', $sortfield, $sortorder, 'center maxwidthsearch ');
}
print '</tr>';

$badges = array('paid' => 'status4', 'invoiced' => 'status4', 'late' => 'status8', 'todo' => 'status1', 'tocheck' => 'status3', 'abandoned' => 'status9');
$societe = new Societe($db);
foreach ($rows as $row) {
	$societe->id = $row->socid;
	$societe->name = $row->socname;
	$societe->client = 1;
	$url = dol_buildpath('/installments/schedule.php', 1).'?element='.urlencode($row->element).'&id='.$row->fk_element;
	$cells = array(
		'date' => '<td class="center nowraponall">'.dol_print_date($row->date, 'day').'</td>',
		'ref' => '<td class="nowraponall"><a href="'.$url.'">'.img_picto('', ($row->element == 'facture' ? 'bill' : ($row->element == 'propal' ? 'propal' : 'order')), 'class="pictofixedwidth"').dolPrintLabel($row->ref).'</a></td>',
		'socname' => '<td class="tdoverflowmax200">'.$societe->getNomUrl(1, 'customer').'</td>',
		'amount' => '<td class="right nowraponall amount">'.price($row->amount, 0, $langs, 1, -1, -1, $conf->currency).'</td>',
		'remain' => '<td class="right nowraponall amount">'.($row->state == 'abandoned' ? '' : price($row->remain, 0, $langs, 1, -1, -1, $conf->currency)).'</td>',
		'mode' => '<td>'.dolPrintLabel($installments->getModeLabel($row->mode, $langs)).'</td>',
		'type' => '<td>'.$langs->trans($row->billing ? 'InstallmentsToInvoice' : 'InstallmentsToCollect').'</td>',
		'state' => '<td class="center nowraponall">'.dolGetBadge($langs->trans('InstallmentsState'.dol_ucfirst($row->state)), '', $badges[$row->state])
			.($row->invoiceref ? ' <a href="'.DOL_URL_ROOT.'/compta/facture/card.php?facid='.$row->invoiceid.'">'.dolPrintLabel($row->invoiceref).'</a>' : '')
			.($row->invoicedraft ? ' <span class="opacitymedium">('.$langs->trans('Draft').')</span>' : '').'</td>',
	);
	$checkbox = '<td class="nowrap center"><input id="cb'.$row->id.'" class="flat checkforselect" type="checkbox" name="toselect[]" value="'.$row->id.'"'.(in_array($row->id, $toselect) ? ' checked' : '').'></td>';
	print '<tr class="oddeven">'.($left ? $checkbox : '');
	foreach ($arrayfields as $key => $field) {
		print (empty($field['checked']) ? '' : $cells[$key]);
	}
	$parameters = array('row' => $row, 'arrayfields' => $arrayfields);
	$reshook = $hookmanager->executeHooks('printFieldListValue', $parameters, $installments, $action); // Note that $action and $object may have been modified by hook
	print $hookmanager->resPrint;
	print ($left ? '' : $checkbox).'</tr>';
}
if ($num == 0) {
	print '<tr><td colspan="'.(1 + count(array_filter(array_column($arrayfields, 'checked')))).'"><span class="opacitymedium">'.$langs->trans('NoRecordFound').'</span></td></tr>';
} else {	// The label in the first cell without a total, like the lists of the core
	$label = $langs->trans('Total');
	print '<tr class="liste_total">';
	if ($left) {
		print '<td>'.$label.'</td>';
		$label = '';
	}
	foreach ($arrayfields as $key => $field) {
		if (!empty($field['checked'])) {
			print ($key == 'remain' ? '<td class="right nowraponall">'.price($total, 0, $langs, 1, -1, -1, $conf->currency).'</td>' : '<td>'.$label.'</td>');
			$label = '';
		}
	}
	print ($left ? '' : '<td></td>').'</tr>';
}
print '</table>';
print '</div>';
print '</form>';

llxFooter();
$db->close();
