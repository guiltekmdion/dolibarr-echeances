<?php
/* Copyright (C) 2004-2017  Laurent Destailleur     <eldy@users.sourceforge.net>
 * Copyright (C) 2024       Frédéric France         <frederic.france@free.fr>
 * Copyright (C) 2026		Maxime DION				<m.dion@guiltek.fr>
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
 * \file    paymentschedule/admin/setup.php
 * \ingroup paymentschedule
 * \brief   Setup of the module PaymentSchedule: days of the direct debits, state of the scheduled jobs
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
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.formsetup.class.php';
require_once DOL_DOCUMENT_ROOT.'/cron/class/cronjob.class.php';
dol_include_once('/paymentschedule/class/paymentschedule.class.php');
require_once '../lib/paymentschedule.lib.php';

/**
 * @var DoliDB $db
 * @var HookManager $hookmanager
 * @var Translate $langs
 * @var User $user
 */

$langs->loadLangs(array('admin', 'bills', 'cron', 'withdrawals', 'paymentschedule@paymentschedule'));

if (!isModEnabled('paymentschedule') || !$user->admin) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');
$hookmanager->initHooks(array('paymentschedulesetup', 'globalsetup'));

$formSetup = new FormSetup($db);
$item = $formSetup->newItem('PAYMENTSCHEDULE_DIRECTDEBIT_DAYS');
$item->nameText = $langs->trans('PaymentScheduleDebitDays');
$item->helpText = $langs->trans('PaymentScheduleDebitDaysHelp');
$item->defaultFieldValue = '5';
$item->cssClass = 'width50';


/*
 * Actions
 */

include DOL_DOCUMENT_ROOT.'/core/actions_setmoduleoptions.inc.php';


/*
 * View
 */

$paymentschedule = new PaymentSchedule($db);

llxHeader('', $langs->trans('PaymentScheduleSetup'), '', '', 0, 0, '', '', '', 'mod-paymentschedule page-admin-setup');

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.img_picto($langs->trans('BackToModuleList'), 'back', 'class="pictofixedwidth"').'<span class="hideonsmartphone">'.$langs->trans('BackToModuleList').'</span></a>';
print load_fiche_titre($langs->trans('PaymentScheduleSetup'), $linkback, 'title_setup');
print dol_get_fiche_head(paymentscheduleAdminPrepareHead(), 'settings', $langs->trans('PaymentScheduleSetup'), -1, 'fa-calendar-alt');

$jobs = array('createDueInvoices' => 'PaymentScheduleInvoiceJob');
if (isModEnabled('prelevement')) {
	$jobs['createDirectDebits'] = 'PaymentScheduleDebitJob';
}
foreach ($jobs as $method => $label) {
	$job = new Cronjob($db);
	$job->fetch(0, 'PaymentSchedule', $method);
	if ($job->status != Cronjob::STATUS_ENABLED) {
		print info_admin($langs->trans('JobMustBeEnabledFirst', $langs->transnoentitiesnoconv($label), '<a href="'.DOL_URL_ROOT.'/cron/list.php">'.$langs->transnoentitiesnoconv('CronList').'</a>'), 0, 0, 'warning');
	}
}
if ($paymentschedule->isCoreReminderJobEnabled()) {
	print info_admin($langs->trans('PaymentScheduleCoreReminderWarning'), 0, 0, 'warning');
}
if (isModEnabled('prelevement') && !getDolGlobalString('WITHDRAW_STRICT_CHECK_AMOUNT')) {
	print info_admin($langs->trans('PaymentScheduleStrictCheckInfo'), 0, 0, 'info');
}

print $formSetup->generateOutput(true);

print '<br>'.info_admin($langs->trans('PaymentScheduleTemplatesInfo', '<a href="'.DOL_URL_ROOT.'/admin/dict.php">'.$langs->transnoentitiesnoconv('Dictionaries').'</a>'), 0, 0, 'info');

print dol_get_fiche_end();

llxFooter();
$db->close();
