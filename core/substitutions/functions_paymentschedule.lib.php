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
 * \file    paymentschedule/core/substitutions/functions_paymentschedule.lib.php
 * \ingroup paymentschedule
 * \brief   Key __PAYMENTSCHEDULE__: the installments of an invoice, proposal or order, for its notes, emails and ODT documents
 */


/**
 * Key __PAYMENTSCHEDULE__: one line per installment (date, amount, payment mode). Its description in the editor of the email templates.
 *
 * @param	array<string,mixed>		$substitutionarray	Keys to complete
 * @param	Translate				$outputlangs		Language of the output
 * @param	?CommonObject			$object				Document, null in the editor of the templates
 * @param	?array<string,mixed>	$parameters			Parameters of the caller
 * @return	void
 */
function paymentschedule_completesubstitutionarray(&$substitutionarray, $outputlangs, $object, $parameters = null)
{
	global $conf, $db;

	if (!is_object($object)) {
		$outputlangs->load('paymentschedule@paymentschedule');
		$substitutionarray['__PAYMENTSCHEDULE__'] = $outputlangs->trans('PaymentScheduleKey');
		return;
	}
	$substitutionarray['__PAYMENTSCHEDULE__'] = '';
	if (!in_array($object->element, array('facture', 'propal', 'commande')) || empty($object->id)) {
		return;
	}
	dol_include_once('/paymentschedule/class/paymentschedule.class.php');
	$paymentschedule = new PaymentSchedule($db);
	$schedule = $paymentschedule->fetch($object->element, (int) $object->id);
	if (!is_object($schedule)) {
		return;
	}
	$outputlangs->load('bills');
	$lines = array();
	foreach ($schedule->lines as $line) {
		if ($line->status == PaymentSchedule::STATUS_ABANDONED) {
			continue;
		}
		$code = (string) dol_getIdFromCode($db, $line->mode ?: (int) $object->mode_reglement_id, 'c_paiement', 'id', 'code', 1);
		$lines[] = dol_print_date($line->date, 'day', false, $outputlangs).' : '.price($line->amount, 0, $outputlangs, 1, -1, -1, $conf->currency)
			.($code && $code != '-1' ? ' ('.$outputlangs->transnoentitiesnoconv('PaymentType'.$code).')' : '');
	}
	$substitutionarray['__PAYMENTSCHEDULE__'] = implode("\n", $lines);
}
