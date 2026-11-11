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
 * \file    paymentschedule/schedule.php
 * \ingroup paymentschedule
 * \brief   Tab Payment schedule of an invoice, a proposal or an order: its installments, their state, their invoices
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
require_once DOL_DOCUMENT_ROOT.'/core/lib/invoice.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/propal.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/order.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.formmail.class.php';
dol_include_once('/paymentschedule/class/paymentschedule.class.php');

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var HookManager $hookmanager
 * @var Translate $langs
 * @var User $user
 */

$langs->loadLangs(array('admin', 'bills', 'propal', 'orders', 'companies', 'paymentschedule@paymentschedule'));

$element = GETPOST('element', 'aZ09');
$id = GETPOSTINT('id');
$action = GETPOST('action', 'aZ09');
$confirm = GETPOST('confirm', 'alpha');
$lineid = GETPOSTINT('lineid');

if (!isset(PaymentSchedule::ELEMENTS[$element])) {
	recordNotFound();
}
$class = PaymentSchedule::ELEMENTS[$element];
$object = new $class($db);
if ($object->fetch($id) <= 0) {
	recordNotFound();
}
restrictedArea($user, $element, $object->id);
if (!isModEnabled('paymentschedule') || !$user->hasRight('paymentschedule', 'read') || !$user->hasRight($element, 'lire') || $user->socid > 0) {
	accessforbidden();
}
$permissiontowrite = $user->hasRight('paymentschedule', 'write') && $user->hasRight($element, 'creer');
$hookmanager->initHooks(array('paymentschedulecard', 'globalcard'));

$paymentschedule = new PaymentSchedule($db);
$schedule = $paymentschedule->fetch($element, $object->id);
if (!is_object($schedule) && $schedule < 0) {
	setEventMessages($paymentschedule->error, null, 'errors');
}
$schedule = (is_object($schedule) ? $schedule : null);
$canbilling = ($element != 'facture');
$self = $_SERVER['PHP_SELF'].'?element='.$element.'&id='.$object->id;
$editlines = null;	// Installments of the form: from the schedule, a template, or a refused save


/*
 * Actions
 */

$parameters = array();
$reshook = $hookmanager->executeHooks('doActions', $parameters, $object, $action); // Note that $action and $object may have been modified by some hooks
if ($reshook < 0) {
	setEventMessages($hookmanager->error, $hookmanager->errors, 'errors');
}

if (empty($reshook) && $action == 'generate' && $permissiontowrite) {
	$templateid = GETPOSTINT('templateid');
	$templates = $paymentschedule->fetchTemplates();
	$firstdate = GETPOSTDATE('firstdate', '12:00:00', 'tzserver') ?: dol_now();
	$editlines = array();	// The paid, invoiced or direct debited installments stay, the rest is shared
	$total = (float) $object->total_ttc;
	foreach ($schedule ? $paymentschedule->getLocked($object, $schedule) : array() as $line) {
		$editlines[] = array('id' => $line->id, 'date' => $line->date, 'amount' => $line->amount, 'mode' => $line->mode, 'note' => $line->note);
		$total -= $line->amount;
	}
	if ($templateid > 0 && is_array($templates) && isset($templates[$templateid])) {
		$template = $templates[$templateid];
		$generated = $paymentschedule->generate($total, $template->nb, $template->months, $template->days, $firstdate, $template->first_percent, $template->mode);
	} else {
		$generated = $paymentschedule->generate($total, GETPOSTINT('nb'), GETPOSTINT('months'), GETPOSTINT('days'), $firstdate,
			(float) price2num(GETPOST('firstpercent', 'alpha')), GETPOSTINT('mode'));
	}
	$editlines = array_merge($editlines, $generated);
	$action = 'edit';
}
if (empty($reshook) && $action == 'save' && $permissiontowrite) {
	$editlines = array();
	$nblines = GETPOSTINT('nblines');
	for ($n = 0; $n < $nblines; $n++) {
		$amount = (float) price2num(GETPOST('lineamount'.$n, 'alpha'), 'MT');
		if (GETPOST('linedelete'.$n, 'alpha') || ($amount == 0 && !GETPOSTINT('lineid'.$n))) {
			continue;
		}
		$editlines[] = array('id' => GETPOSTINT('lineid'.$n), 'date' => (int) GETPOSTDATE('linedate'.$n, '12:00:00', 'tzserver'), 'amount' => $amount,
			'mode' => GETPOSTINT('linemode'.$n), 'note' => GETPOST('linenote'.$n, 'alphanohtml'));
	}
	array_multisort(array_column($editlines, 'date'), SORT_ASC, $editlines);
	$result = $paymentschedule->save($user, $object, $editlines, GETPOSTINT('billing'), GETPOSTINT('invoice_mode'), GETPOSTINT('fk_email_template'));
	if ($result < 0) {
		setEventMessages($paymentschedule->error, null, 'errors');
		$action = 'edit';
	} else {
		setEventMessages($langs->trans('RecordSaved'), null);
		header('Location: '.$self);
		exit;
	}
}
if (empty($reshook) && $action == 'confirm_delete' && $confirm == 'yes' && $permissiontowrite) {
	if ($paymentschedule->delete($user, $object) < 0) {
		setEventMessages($paymentschedule->error, null, 'errors');
	} else {
		setEventMessages($langs->trans('RecordDeleted'), null);
	}
	header('Location: '.$self);
	exit;
}
if (empty($reshook) && $action == 'confirm_createinvoice' && $confirm == 'yes' && $permissiontowrite && $user->hasRight('facture', 'creer') && $schedule) {
	foreach ($schedule->lines as $line) {
		if ($line->id == $lineid) {
			$invoiceid = $paymentschedule->createInvoice($user, $schedule, $line);
			setEventMessages($invoiceid < 0 ? $paymentschedule->error : $langs->trans('PaymentScheduleInvoiceCreated'), $paymentschedule->errors, ($invoiceid < 0 || $paymentschedule->errors ? 'errors' : 'mesgs'));
		}
	}
	header('Location: '.$self);
	exit;
}
if (empty($reshook) && in_array($action, array('confirm_linkinvoice', 'confirm_abandon')) && $confirm == 'yes' && $permissiontowrite && $schedule
	&& in_array($lineid, array_column($schedule->lines, 'id'))) {	// Only an installment of this document
	if ($paymentschedule->settle($user, $lineid, ($action == 'confirm_linkinvoice' ? GETPOSTINT('invoiceid') : 0)) < 0) {
		setEventMessages($paymentschedule->error, null, 'errors');
	} else {
		setEventMessages($langs->trans('RecordSaved'), null);
	}
	header('Location: '.$self);
	exit;
}


/*
 * View
 */

$form = new Form($db);
$object->fetch_thirdparty();
$titles = array('facture' => 'InvoiceCustomer', 'propal' => 'Proposal', 'commande' => 'CustomerOrder');
llxHeader('', $langs->trans($titles[$element]).' - '.$langs->trans('PaymentSchedule'), '', '', 0, 0, '', '', '', 'mod-paymentschedule page-card');

if ($element == 'facture') {
	$object->totalpaid = $object->getSommePaiement();	// For the status shown by the banner
	$head = facture_prepare_head($object);
	$linkback = '<a href="'.DOL_URL_ROOT.'/compta/facture/list.php?restore_lastsearch_values=1">'.$langs->trans('BackToList').'</a>';
} elseif ($element == 'propal') {
	$head = propal_prepare_head($object);
	$linkback = '<a href="'.DOL_URL_ROOT.'/comm/propal/list.php?restore_lastsearch_values=1">'.$langs->trans('BackToList').'</a>';
} else {
	$head = commande_prepare_head($object);
	$linkback = '<a href="'.DOL_URL_ROOT.'/commande/list.php?restore_lastsearch_values=1">'.$langs->trans('BackToList').'</a>';
}
print dol_get_fiche_head($head, 'paymentschedule', $langs->trans($titles[$element]), -1, $object->picto);
$morehtmlref = '<div class="refidno">'.$object->thirdparty->getNomUrl(1, 'customer').'</div>';
dol_banner_tab($object, 'ref', $linkback, 1, 'ref', 'ref', $morehtmlref, '', 0, '', '', 1);
print dol_get_fiche_end();

if ($action == 'delete' && $schedule) {
	print $form->formconfirm($self, $langs->trans('PaymentScheduleDelete'), $langs->trans('PaymentScheduleConfirmDelete'), 'confirm_delete', '', 0, 1);
}
$current = null;
foreach ($schedule ? $schedule->lines : array() as $line) {
	if ($line->id == $lineid) {
		$current = $line;
	}
}
if ($current && $action == 'createinvoice') {
	$modes = array($langs->trans('Draft'), $langs->trans('Validated'), $langs->trans('PaymentScheduleValidatedAndSent'));
	$question = $langs->trans('PaymentScheduleConfirmCreateInvoice', dol_print_date($current->date, 'day'), price($current->amount, 0, $langs, 1, -1, -1, $conf->currency), $modes[$schedule->invoice_mode]);
	print $form->formconfirm($self.'&lineid='.$current->id, $langs->trans('PaymentScheduleCreateInvoice'), $question, 'confirm_createinvoice', '', 0, 1);
}
if ($current && $action == 'linkinvoice') {
	$invoices = array();
	$sql = "SELECT rowid, ref, datef, total_ttc FROM ".$db->prefix()."facture WHERE fk_soc = ".((int) $object->socid)." AND entity IN (".getEntity('invoice').")";
	$sql .= " AND fk_statut IN (".Facture::STATUS_VALIDATED.", ".Facture::STATUS_CLOSED.") AND type = ".Facture::TYPE_STANDARD.$db->order('datef', 'DESC').$db->plimit(100);
	$resql = $db->query($sql);
	while ($resql && $obj = $db->fetch_object($resql)) {
		$invoices[(int) $obj->rowid] = $obj->ref.' - '.dol_print_date($db->jdate($obj->datef), 'day').' - '.price((float) $obj->total_ttc, 0, $langs, 1, -1, -1, $conf->currency);
	}
	$formquestion = array(array('type' => 'select', 'name' => 'invoiceid', 'label' => $langs->trans('Invoice'), 'values' => $invoices));
	print $form->formconfirm($self.'&lineid='.$current->id, $langs->trans('PaymentScheduleLinkInvoice'), $langs->trans('PaymentScheduleConfirmLink', dol_print_date($current->date, 'day')), 'confirm_linkinvoice', $formquestion, 0, 1);
}
if ($current && $action == 'abandon') {
	print $form->formconfirm($self.'&lineid='.$current->id, $langs->trans('PaymentScheduleAbandon'), $langs->trans('PaymentScheduleConfirmAbandon', dol_print_date($current->date, 'day')), 'confirm_abandon', '', 0, 1);
}

// Warnings: the reminder job of the core, the 60 days rule, a total that no longer matches the document
$lines = ($editlines ?? ($schedule ? $schedule->lines : array()));
if ($element == 'facture' && $schedule && $paymentschedule->isCoreReminderJobEnabled()) {
	print info_admin($langs->trans('PaymentScheduleCoreReminderWarning'), 0, 0, 'warning');
}
if (($element == 'facture' || ($schedule && !$schedule->billing)) && $paymentschedule->isBeyondSixtyDays($object, $lines)) {	// Not for one invoice per installment
	print info_admin($langs->trans('PaymentScheduleSixtyDaysWarning'), 0, 0, 'warning');
}
if ($schedule && $action != 'edit') {
	$sum = 0.0;
	foreach ($schedule->lines as $line) {
		$sum += ($line->status == PaymentSchedule::STATUS_ABANDONED ? 0 : $line->amount);
	}
	if (round($sum - (float) $object->total_ttc, getDolGlobalInt('MAIN_MAX_DECIMALS_TOT', 2)) != 0) {
		print info_admin($langs->trans('PaymentScheduleTotalDiffers', price($sum, 0, $langs, 1, -1, -1, $conf->currency), price((float) $object->total_ttc, 0, $langs, 1, -1, -1, $conf->currency)), 0, 0, 'warning');
	}
}

if ($action == 'edit' && $permissiontowrite) {
	$locked = ($schedule ? $paymentschedule->getLocked($object, $schedule) : array());
	if ($editlines === null) {
		$editlines = array();
		foreach ($schedule ? $schedule->lines : array() as $line) {
			$editlines[] = array('id' => $line->id, 'date' => $line->date, 'amount' => $line->amount, 'mode' => $line->mode, 'note' => $line->note);
		}
	}

	// Calculation: a template, or N installments
	print '<form method="POST" action="'.$self.'">';
	print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="generate">';
	print '<table class="noborder centpercent"><tr class="liste_titre"><td colspan="2">'.$langs->trans('PaymentScheduleCalculate').'</td></tr>';
	$templates = $paymentschedule->fetchTemplates();
	$choices = array();
	foreach (is_array($templates) ? $templates : array() as $template) {
		$key = 'PaymentScheduleTemplate'.$template->code;
		$choices[$template->id] = ($langs->trans($key) != $key ? $langs->trans($key) : $template->label);
	}
	print '<tr class="oddeven"><td class="titlefield">'.$langs->trans('PaymentScheduleTemplate').'</td><td>'.$form->selectarray('templateid', $choices, GETPOSTINT('templateid'), $langs->trans('PaymentScheduleCustom'), 0, 0, '', 0, 0, 0, '', 'minwidth200');
	print ' &nbsp; '.$langs->trans('PaymentScheduleFirstDate').' '.$form->selectDate(GETPOSTDATE('firstdate', '12:00:00', 'tzserver') ?: dol_now(), 'firstdate', 0, 0, 0, '', 1, 1).'</td></tr>';
	print '<tr class="oddeven"><td>'.$langs->trans('PaymentScheduleCustom').'</td><td>';
	print '<input type="number" min="1" max="'.PaymentSchedule::MAX_INSTALLMENTS.'" name="nb" class="width50" value="'.(GETPOSTINT('nb') ?: 3).'"> '.$langs->trans('PaymentScheduleInstallments');
	print ' &nbsp; '.$langs->trans('PaymentScheduleEvery').' <input type="number" min="0" name="months" class="width50" value="'.(GETPOSTISSET('months') ? GETPOSTINT('months') : 1).'"> '.$langs->trans('Months');
	print ' + <input type="number" min="0" name="days" class="width50" value="'.GETPOSTINT('days').'"> '.$langs->trans('Days');
	print ' &nbsp; '.$langs->trans('PaymentScheduleFirstPercent').' <input type="text" name="firstpercent" class="width50" value="'.dol_escape_htmltag(GETPOST('firstpercent', 'alpha')).'"> %';
	print ' &nbsp; '.$form->select_types_paiements(GETPOSTINT('mode'), 'mode', 'CRDT', 0, 1, 0, 0, 1, '', 1);
	print '</td></tr></table>';
	print '<div class="center"><input type="submit" class="button" value="'.$langs->trans('PaymentScheduleCalculate').'"></div>';
	print '</form><br>';

	// The installments, editable except the paid, invoiced or direct debited ones
	print '<form method="POST" action="'.$self.'">';
	print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="save">';
	$rows = array_merge($editlines, array(array('id' => 0, 'date' => 0, 'amount' => 0.0, 'mode' => 0, 'note' => '')));	// A blank row to add an installment
	print '<input type="hidden" name="nblines" value="'.count($rows).'">';
	print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
	print '<tr class="liste_titre"><td>'.$langs->trans('Date').'</td><td class="right">'.$langs->trans('AmountTTC').'</td><td>'.$langs->trans('PaymentMode').'</td><td>'.$langs->trans('Note').'</td><td class="center">'.$langs->trans('Delete').'</td></tr>';
	foreach ($rows as $n => $row) {
		print '<tr class="oddeven"><td class="nowraponall"><input type="hidden" name="lineid'.$n.'" value="'.((int) ($row['id'] ?? 0)).'">';
		if (!empty($row['id']) && isset($locked[$row['id']])) {	// Kept as it is: given again in hidden fields
			print dol_print_date($row['date'], 'day').' '.$form->textwithpicto('', $langs->trans('PaymentScheduleLocked'));
			print '<input type="hidden" name="linedateday'.$n.'" value="'.dol_print_date($row['date'], '%d').'"><input type="hidden" name="linedatemonth'.$n.'" value="'.dol_print_date($row['date'], '%m').'">';
			print '<input type="hidden" name="linedateyear'.$n.'" value="'.dol_print_date($row['date'], '%Y').'"><input type="hidden" name="lineamount'.$n.'" value="'.price2num($row['amount']).'"></td>';
			print '<td class="right">'.price($row['amount'], 0, $langs, 1, -1, -1, $conf->currency).'</td>';
		} else {
			print $form->selectDate($row['date'] ?: -1, 'linedate'.$n, 0, 0, 1, '', 1, 0).'</td>';
			print '<td class="right"><input type="text" name="lineamount'.$n.'" class="width100 right" value="'.($row['amount'] ? price2num($row['amount']) : '').'"></td>';
		}
		print '<td>'.$form->select_types_paiements($row['mode'], 'linemode'.$n, 'CRDT', 0, 1, 0, 0, 1, '', 1).'</td>';
		print '<td><input type="text" name="linenote'.$n.'" class="minwidth200" value="'.dol_escape_htmltag($row['note']).'"></td>';
		print '<td class="center">'.(empty($row['id']) || !isset($locked[$row['id']]) ? '<input type="checkbox" name="linedelete'.$n.'" value="1">' : '').'</td></tr>';
	}
	print '<tr class="liste_total"><td>'.$langs->trans('Total').'</td><td class="right">'.price((float) $object->total_ttc, 0, $langs, 1, -1, -1, $conf->currency).'</td><td colspan="3"></td></tr>';
	print '</table></div>';

	if ($canbilling) {
		$formmail = new FormMail($db);
		$formmail->fetchAllEMailTemplate('facture_send', $user, null, 1);
		$emailtemplates = array();
		foreach ($formmail->lines_model as $model) {
			if (empty($model->private) && (empty($model->module) || isModEnabled($model->module))) {	// The job sees neither the private templates of a user nor those of a disabled module
				$emailtemplates[(int) $model->id] = $langs->trans(preg_replace('/\(|\)/', '', $model->label)).($model->lang ? ' ('.$model->lang.')' : '');
			}
		}
		print '<table class="border centpercent tableforfield">';
		print '<tr><td class="titlefield">'.$form->textwithpicto($langs->trans('PaymentScheduleBilling'), $langs->trans('PaymentScheduleBillingHelp')).'</td>';
		print '<td><input type="checkbox" name="billing" value="1"'.((GETPOSTISSET('billing') ? GETPOSTINT('billing') : ($schedule ? $schedule->billing : 1)) ? ' checked' : '').'></td></tr>';
		print '<tr><td>'.$langs->trans('PaymentScheduleInvoiceMode').'</td><td>';
		print $form->selectarray('invoice_mode', array($langs->trans('Draft'), $langs->trans('Validated'), $langs->trans('PaymentScheduleValidatedAndSent')), ($schedule ? $schedule->invoice_mode : GETPOSTINT('invoice_mode')));
		print ' &nbsp; '.$langs->trans('EmailTemplate').' '.$form->selectarray('fk_email_template', $emailtemplates, ($schedule ? $schedule->fk_email_template : GETPOSTINT('fk_email_template')), $langs->trans('Default'));
		print '</td></tr></table>';
	}
	print '<div class="center"><input type="submit" class="button button-save" value="'.$langs->trans('Save').'"> <a class="button button-cancel" href="'.$self.'">'.$langs->trans('Cancel').'</a></div>';
	print '</form>';
} else {
	print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
	print '<tr class="liste_titre"><td>'.$langs->trans('Date').'</td><td class="right">'.$langs->trans('AmountTTC').'</td><td>'.$langs->trans('PaymentMode').'</td>';
	print ($schedule && !$schedule->billing ? '<td class="right">'.$langs->trans('AlreadyPaid').'</td><td class="right">'.$langs->trans('RemainderToPay').'</td>' : '<td>'.$langs->trans('Invoice').'</td>');
	print '<td>'.$langs->trans('Status').'</td><td>'.$langs->trans('Note').'</td><td></td></tr>';
	if (!$schedule) {
		print '<tr class="oddeven"><td colspan="7"><span class="opacitymedium">'.$langs->trans('PaymentScheduleNone').'</span></td></tr>';
	} else {
		$paid = ($object instanceof Facture ? $paymentschedule->allocate($object, $schedule->lines) : array());
		$badges = array('paid' => 'status4', 'invoiced' => 'status4', 'late' => 'status8', 'todo' => 'status1', 'tocheck' => 'status3', 'abandoned' => 'status9');
		foreach ($schedule->lines as $line) {
			$state = $paymentschedule->getState($schedule, $line, ($paid[$line->id] ?? 0.0), dol_now());
			$code = (string) dol_getIdFromCode($db, $line->mode ?: (int) $object->mode_reglement_id, 'c_paiement', 'id', 'code', 1);
			print '<tr class="oddeven"><td class="nowraponall">'.dol_print_date($line->date, 'day').'</td>';
			print '<td class="right nowraponall amount">'.price($line->amount, 0, $langs, 1, -1, -1, $conf->currency).'</td>';
			print '<td>'.($code && $code != '-1' ? $langs->trans('PaymentType'.$code) : '').'</td>';
			if (!$schedule->billing) {
				print '<td class="right nowraponall">'.($element == 'facture' ? price($paid[$line->id] ?? 0.0, 0, $langs, 1, -1, -1, $conf->currency) : '').'</td>';
				print '<td class="right nowraponall">'.($element == 'facture' ? price($line->amount - ($paid[$line->id] ?? 0.0), 0, $langs, 1, -1, -1, $conf->currency) : '').'</td>';
			} else {
				print '<td class="nowraponall">';
				if ($line->invoiceid) {
					$invoice = new Facture($db);
					print ($invoice->fetch($line->invoiceid) > 0 ? $invoice->getNomUrl(1).' '.$invoice->getLibStatut(3, $invoice->getSommePaiement() + $invoice->getSumDepositsUsed() + $invoice->getSumCreditNotesUsed()) : '');
				}
				print '</td>';
			}
			print '<td>'.dolGetBadge($langs->trans('PaymentScheduleState'.ucfirst($state)), '', $badges[$state]);
			print ($line->debitid ? ' '.img_picto($langs->trans('PaymentScheduleDebitRequested'), 'bank', 'class="paddingleft"') : '').'</td>';
			print '<td>'.dolPrintLabel($line->note).'</td><td class="right nowraponall">';
			if ($schedule->billing && !$line->invoiceid && $line->status != PaymentSchedule::STATUS_ABANDONED) {
				print dolGetButtonAction('', $langs->trans('PaymentScheduleCreateInvoice'), 'default', $self.'&lineid='.$line->id.'&action=createinvoice&token='.newToken(), '', $permissiontowrite && $user->hasRight('facture', 'creer'), array('attr' => array('class' => 'small')));
				print dolGetButtonAction('', $langs->trans('PaymentScheduleLinkInvoice'), 'default', $self.'&lineid='.$line->id.'&action=linkinvoice&token='.newToken(), '', $permissiontowrite, array('attr' => array('class' => 'small')));
				print dolGetButtonAction('', $langs->trans('PaymentScheduleAbandon'), 'default', $self.'&lineid='.$line->id.'&action=abandon&token='.newToken(), '', $permissiontowrite, array('attr' => array('class' => 'small')));
			}
			print '</td></tr>';
		}
	}
	print '</table></div>';

	print '<div class="tabsAction">';
	print dolGetButtonAction('', $langs->trans($schedule ? 'Modify' : 'PaymentScheduleNew'), 'default', $self.'&action=edit&token='.newToken(), '', $permissiontowrite);
	if ($schedule) {
		print dolGetButtonAction('', $langs->trans('Delete'), 'delete', $self.'&action=delete&token='.newToken(), '', $permissiontowrite && empty($paymentschedule->getLocked($object, $schedule)));
	}
	print '</div>';
}

llxFooter();
$db->close();
