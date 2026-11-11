<?php
/* Copyright (C) 2026		Maxime DION				<m.dion@guiltek.fr>
 * Copyright (C) 2025       Frédéric France         <frederic.france@free.fr>
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
 * \file    installments/lib/installments.lib.php
 * \ingroup installments
 * \brief   Library files with common functions for InstallmentSchedule
 */

/**
 * Prepare admin pages header: settings, import of the module Echeancier when its tables are there, about
 *
 * @return array<array{string,string,string}>
 */
function installmentsAdminPrepareHead()
{
	global $db, $langs, $conf;

	$langs->load("installments@installments");

	$h = 0;
	$head = array();

	$head[$h][0] = dolBuildUrl(dol_buildpath("/installments/admin/setup.php", 1));
	$head[$h][1] = $langs->trans("Settings");
	$head[$h][2] = 'settings';
	$h++;

	dol_include_once('/installments/class/installmentschedule.class.php');
	$installments = new InstallmentSchedule($db);
	if ($installments->hasOldTables()) {
		$head[$h][0] = dolBuildUrl(dol_buildpath("/installments/admin/import.php", 1));
		$head[$h][1] = $langs->trans("InstallmentsImport");
		$head[$h][2] = 'import';
		$h++;
	}

	$head[$h][0] = dolBuildUrl(dol_buildpath("/installments/admin/about.php", 1));
	$head[$h][1] = $langs->trans("About");
	$head[$h][2] = 'about';
	$h++;

	complete_head_from_modules($conf, $langs, null, $head, $h, 'installments@installments');

	complete_head_from_modules($conf, $langs, null, $head, $h, 'installments@installments', 'remove');

	return $head;
}
