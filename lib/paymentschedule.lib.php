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
 * \file    paymentschedule/lib/paymentschedule.lib.php
 * \ingroup paymentschedule
 * \brief   Library files with common functions for PaymentSchedule
 */

/**
 * Prepare admin pages header: settings, import of the module Echeancier when its tables are there, about
 *
 * @return array<array{string,string,string}>
 */
function paymentscheduleAdminPrepareHead()
{
	global $db, $langs, $conf;

	$langs->load("paymentschedule@paymentschedule");

	$h = 0;
	$head = array();

	$head[$h][0] = dolBuildUrl(dol_buildpath("/paymentschedule/admin/setup.php", 1));
	$head[$h][1] = $langs->trans("Settings");
	$head[$h][2] = 'settings';
	$h++;

	$tables = $db->DDLListTables($db->database_name, $db->prefix().'echeancier');
	if (is_array($tables) && in_array($db->prefix().'echeancier', $tables)) {
		$head[$h][0] = dolBuildUrl(dol_buildpath("/paymentschedule/admin/import.php", 1));
		$head[$h][1] = $langs->trans("PaymentScheduleImport");
		$head[$h][2] = 'import';
		$h++;
	}

	$head[$h][0] = dolBuildUrl(dol_buildpath("/paymentschedule/admin/about.php", 1));
	$head[$h][1] = $langs->trans("About");
	$head[$h][2] = 'about';
	$h++;

	complete_head_from_modules($conf, $langs, null, $head, $h, 'paymentschedule@paymentschedule');

	complete_head_from_modules($conf, $langs, null, $head, $h, 'paymentschedule@paymentschedule', 'remove');

	return $head;
}
