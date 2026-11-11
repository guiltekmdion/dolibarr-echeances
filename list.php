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
$search = GETPOST('search', 'alphanohtml');
$search_state = GETPOSTISSET('search_state') ? GETPOST('search_state', 'aZ09') : 'open';
$search_type = GETPOST('search_type', 'aZ09');
$search_mode = GETPOSTINT('search_mode');
$search_date_start = GETPOSTDATE('search_date_start', '00:00:00', 'tzserver');
$search_date_end = GETPOSTDATE('search_date_end', '23:59:59', 'tzserver');
if (!in_array($sortfield, array('date', 'ref', 'socname', 'amount', 'state'))) {
	$sortfield = 'date';
}
// Names of the buttons of Form::showFilterButtons(), tested like the lists of the core for all browsers
$removefilter = (GETPOST('button_removefilter_x', 'alpha') || GETPOST('button_removefilter.x', 'alpha') || GETPOST('button_removefilter', 'alpha'));
if ($page < 0 || $removefilter || GETPOST('button_search_x', 'alpha') || GETPOST('button_search.x', 'alpha') || GETPOST('button_search', 'alpha')) {
	$page = 0;
}
if ($removefilter) {
	$search = '';
	$search_state = 'open';
	$search_type = '';
	$search_mode = 0;
	$search_date_start = 0;
	$search_date_end = 0;
}
$states = array('open' => array('late', 'todo', 'tocheck'), 'late' => array('late'), 'todo' => array('todo'), 'tocheck' => array('tocheck'), 'paid' => array('paid'),
	'invoiced' => array('invoiced'), 'abandoned' => array('abandoned'), 'all' => array());
$search_state = (isset($states[$search_state]) ? $search_state : 'open');

$param = ($limit != $conf->liste_limit ? '&limit='.((int) $limit) : '').($socid ? '&socid='.$socid : '').($search !== '' ? '&search='.urlencode($search) : '');
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

if (empty($reshook) && $confirm == 'yes' && $action == 'confirm_recordpayments' && isset($arrayofmassactions['prerecordpayments']) && !empty($toselect)) {
	$result = $installments->recordPayments($user, $toselect, GETPOSTDATE('paymentdate', '12:00:00', 'tzserver') ?: dol_now(), GETPOSTINT('paymentmode'), GETPOSTINT('accountid'));
	if ($result < 0) {
		setEventMessages($installments->error, $installments->errors, 'errors');
	} else {
		setEventMessages($langs->trans('InstallmentsNbPayments', $result), $installments->errors, ($installments->errors ? 'warnings' : 'mesgs'));
	}
	header('Location: '.$_SERVER['PHP_SELF'].'?'.ltrim($param, '&'));
	exit;
}
if (empty($reshook) && $confirm == 'yes' && $action == 'confirm_createinvoices' && isset($arrayofmassactions['precreateinvoices']) && !empty($toselect)) {
	$nb = 0;
	$errors = array();
	$rows = $installments->getInstallments($user, array('type' => 'billing'));
	foreach (is_array($rows) ? $rows : array() as $row) {
		if (!in_array($row->id, $toselect) || $row->invoiceid || $row->status == InstallmentSchedule::STATUS_ABANDONED || !$user->hasRight($row->element, 'creer')) {	// Like the tab: the right to modify the proposal or the order
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
$filters = array('socid' => $socid, 'type' => $search_type, 'search' => $search, 'date_start' => $search_date_start, 'date_end' => $search_date_end,
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
$moreforfilter = '<div class="divsearchfield">'.$langs->trans('Search').': <input type="text" class="maxwidth150" name="search" value="'.dolPrintHTMLForAttribute($search).'"></div>';
$moreforfilter .= '<div class="divsearchfield">'.$langs->trans('Status').': '.$form->selectarray('search_state', $statelabels, $search_state, 0, 0, 0, '', 0, 0, 0, '', 'maxwidth150').'</div>';
$moreforfilter .= '<div class="divsearchfield">'.$langs->trans('Type').': '.$form->selectarray('search_type', array('payment' => $langs->trans('InstallmentsToCollect'), 'billing' => $langs->trans('InstallmentsToInvoice')), $search_type, 1, 0, 0, '', 0, 0, 0, '', 'maxwidth150').'</div>';
$moreforfilter .= '<div class="divsearchfield">'.$langs->trans('PaymentMode').': '.$form->select_types_paiements($search_mode, 'search_mode', 'CRDT', 0, 1, 0, 0, 1, 'maxwidth150', 1).'</div>';
$moreforfilter .= '<div class="divsearchfield">'.$langs->trans('Date').': '.$form->selectDate($search_date_start ?: -1, 'search_date_start', 0, 0, 1, '', 1, 0).' - '.$form->selectDate($search_date_end ?: -1, 'search_date_end', 0, 0, 1, '', 1, 0).'</div>';
print '<div class="liste_titre liste_titre_bydiv centpercent">'.$moreforfilter.'</div>';

print '<div class="div-table-responsive">';
print '<table class="tagtable nobottomiftotal liste">';
print '<tr class="liste_titre_filter"><td class="liste_titre" colspan="8"></td>';
$parameters = array();
$reshook = $hookmanager->executeHooks('printFieldListOption', $parameters, $installments, $action); // Note that $action and $object may have been modified by hook
print $hookmanager->resPrint;
print '<td class="liste_titre center maxwidthsearch">'.$form->showFilterButtons().'</td></tr>';
print '<tr class="liste_titre">';
print_liste_field_titre('InstallmentsDueDate', $_SERVER['PHP_SELF'], 'date', '', $param, '', $sortfield, $sortorder);
print_liste_field_titre('Ref', $_SERVER['PHP_SELF'], 'ref', '', $param, '', $sortfield, $sortorder);
print_liste_field_titre('Customer', $_SERVER['PHP_SELF'], 'socname', '', $param, '', $sortfield, $sortorder);
print_liste_field_titre('AmountTTC', $_SERVER['PHP_SELF'], 'amount', '', $param, '', $sortfield, $sortorder, 'right ');
print_liste_field_titre('RemainderToPay', $_SERVER['PHP_SELF'], '', '', $param, '', $sortfield, $sortorder, 'right ');
print_liste_field_titre('PaymentMode', $_SERVER['PHP_SELF'], '', '', $param, '', $sortfield, $sortorder);
print_liste_field_titre('Type', $_SERVER['PHP_SELF'], '', '', $param, '', $sortfield, $sortorder);
print_liste_field_titre('Status', $_SERVER['PHP_SELF'], 'state', '', $param, '', $sortfield, $sortorder);
$parameters = array('param' => $param, 'sortfield' => $sortfield, 'sortorder' => $sortorder);
$reshook = $hookmanager->executeHooks('printFieldListTitle', $parameters, $installments, $action); // Note that $action and $object may have been modified by hook
print $hookmanager->resPrint;
print_liste_field_titre($massactionbutton ? $form->showCheckAddButtons('checkforselect', 1) : '', $_SERVER['PHP_SELF'], '', '', '', '', $sortfield, $sortorder, 'center maxwidthsearch ');
print '</tr>';

$badges = array('paid' => 'status4', 'invoiced' => 'status4', 'late' => 'status8', 'todo' => 'status1', 'tocheck' => 'status3', 'abandoned' => 'status9');
$societe = new Societe($db);
foreach ($rows as $row) {
	$societe->id = $row->socid;
	$societe->name = $row->socname;
	$societe->client = 1;
	$url = dol_buildpath('/installments/schedule.php', 1).'?element='.urlencode($row->element).'&id='.$row->fk_element;
	print '<tr class="oddeven">';
	print '<td class="nowraponall">'.dol_print_date($row->date, 'day').'</td>';
	print '<td class="nowraponall"><a href="'.$url.'">'.img_picto('', ($row->element == 'facture' ? 'bill' : ($row->element == 'propal' ? 'propal' : 'order')), 'class="pictofixedwidth"').dolPrintLabel($row->ref).'</a></td>';
	print '<td class="tdoverflowmax200">'.$societe->getNomUrl(1, 'customer').'</td>';
	print '<td class="right nowraponall amount">'.price($row->amount, 0, $langs, 1, -1, -1, $conf->currency).'</td>';
	print '<td class="right nowraponall amount">'.($row->state == 'abandoned' ? '' : price($row->remain, 0, $langs, 1, -1, -1, $conf->currency)).'</td>';
	print '<td>'.dolPrintLabel($installments->getModeLabel($row->mode, $langs)).'</td>';
	print '<td>'.$langs->trans($row->billing ? 'InstallmentsToInvoice' : 'InstallmentsToCollect').'</td>';
	print '<td class="nowraponall">'.dolGetBadge($langs->trans('InstallmentsState'.dol_ucfirst($row->state)), '', $badges[$row->state]);
	print ($row->invoiceref ? ' <a href="'.DOL_URL_ROOT.'/compta/facture/card.php?facid='.$row->invoiceid.'">'.dolPrintLabel($row->invoiceref).'</a>' : '').'</td>';
	$parameters = array('row' => $row);
	$reshook = $hookmanager->executeHooks('printFieldListValue', $parameters, $installments, $action); // Note that $action and $object may have been modified by hook
	print $hookmanager->resPrint;
	print '<td class="nowrap center"><input id="cb'.$row->id.'" class="flat checkforselect" type="checkbox" name="toselect[]" value="'.$row->id.'"'.(in_array($row->id, $toselect) ? ' checked' : '').'></td>';
	print '</tr>';
}
if ($num == 0) {
	print '<tr><td colspan="9"><span class="opacitymedium">'.$langs->trans('NoRecordFound').'</span></td></tr>';
} else {
	print '<tr class="liste_total"><td colspan="4">'.$langs->trans('Total').'</td><td class="right nowraponall">'.price($total, 0, $langs, 1, -1, -1, $conf->currency).'</td><td colspan="4"></td></tr>';
}
print '</table>';
print '</div>';
print '</form>';

llxFooter();
$db->close();
