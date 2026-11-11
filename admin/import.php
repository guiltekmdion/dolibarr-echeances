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
 * \file    installments/admin/import.php
 * \ingroup installments
 * \brief   Import of the schedules of the module Echeancier (Artis Auxilium): preview, then import
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
dol_include_once('/installments/class/installmentschedule.class.php');
require_once '../lib/installments.lib.php';

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

$langs->loadLangs(array('admin', 'bills', 'installments@installments'));

if (!isModEnabled('installments') || !$user->admin) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');
$confirm = GETPOST('confirm', 'alpha');
$installments = new InstallmentSchedule($db);


/*
 * Actions
 */

if ($action == 'confirm_import' && $confirm == 'yes') {
	$report = $installments->import($user, false);
	if (!is_array($report)) {
		setEventMessages($installments->error, null, 'errors');
	} else {
		setEventMessages($langs->trans('InstallmentsImportDone', $report['schedules'], $report['lines'], $report['tocheck']), null);
	}
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}


/*
 * View
 */

$form = new Form($db);
$report = $installments->import($user, true);

llxHeader('', $langs->trans('InstallmentsSetup'), '', '', 0, 0, '', '', '', 'mod-installments page-admin-import');

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.img_picto($langs->trans('BackToModuleList'), 'back', 'class="pictofixedwidth"').'<span class="hideonsmartphone">'.$langs->trans('BackToModuleList').'</span></a>';
print load_fiche_titre($langs->trans('InstallmentsSetup'), $linkback, 'title_setup');
print dol_get_fiche_head(installmentsAdminPrepareHead(), 'import', $langs->trans('InstallmentsSetup'), -1, 'fa-calendar-alt');

if ($action == 'import') {
	print $form->formconfirm($_SERVER['PHP_SELF'], $langs->trans('InstallmentsImport'), $langs->trans('InstallmentsConfirmImport'), 'confirm_import', '', 0, 1);
}

print '<span class="opacitymedium">'.$langs->trans('InstallmentsImportHelp').'</span><br><br>';
if (!is_array($report)) {
	print info_admin(dolPrintLabel($installments->error), 0, 0, 'error');
} else {
	print '<table class="noborder centpercent">';
	print '<tr class="liste_titre"><td colspan="2">'.$langs->trans('InstallmentsImportPreview').'</td></tr>';
	print '<tr class="oddeven"><td class="titlefield">'.$langs->trans('InstallmentsImportSchedules').'</td><td>'.$report['schedules'].'</td></tr>';
	print '<tr class="oddeven"><td>'.$langs->trans('InstallmentsImportLines').'</td><td>'.$report['lines'].'</td></tr>';
	print '<tr class="oddeven"><td>'.$langs->trans('InstallmentsStateTocheck').'</td><td>'.$report['tocheck'].'</td></tr>';
	foreach (array('different' => 'InstallmentsImportDifferent', 'skipped' => 'InstallmentsImportSkipped') as $key => $label) {
		if (!empty($report[$key])) {
			print '<tr class="oddeven"><td>'.$langs->trans($label).'</td><td>'.implode('<br>', array_map('dolPrintLabel', $report[$key])).'</td></tr>';
		}
	}
	print '</table>';
	print '<div class="tabsAction">'.dolGetButtonAction('', $langs->trans('InstallmentsImport'), 'default', $_SERVER['PHP_SELF'].'?action=import&token='.newToken(), '', $report['schedules'] > 0).'</div>';
}

print dol_get_fiche_end();

llxFooter();
$db->close();
