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
 * \file    installments/class/actions_installments.class.php
 * \ingroup installments
 * \brief   Hooks: the schedule in the PDF of an invoice (under its totals), of a proposal or an order (in its note), and a warning when the due date of an invoice is edited by hand
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/commonhookactions.class.php';
dol_include_once('/installments/class/installmentschedule.class.php');


/**
 * Hooks of the module InstallmentSchedule
 */
class ActionsInstallments extends CommonHookActions
{
	const PDF_MAX_ROWS = 10;	// Rows of the schedule in the PDF, for the room left under the totals

	/**
	 * @var DoliDB Database handler
	 */
	public $db;

	/**
	 * @var array<string,array<int,array{label:string,amount:float,paid:bool}>> Rows of the PDF by document, read by the first hook for the second one
	 */
	private $pdfrows = array();

	/**
	 * @var array<string,string> Public notes of the proposals and orders while their PDF is made
	 */
	private $notes = array();


	/**
	 * Constructor
	 *
	 * @param	DoliDB	$db		Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Card of an invoice: its due date follows the schedule, an edit by hand is replaced at the next payment
	 *
	 * @param	array<string,mixed>	$parameters		Hook metadatas (context, etc...)
	 * @param	CommonObject		$object			Invoice
	 * @param	?string				$action			Action
	 * @param	HookManager			$hookmanager	Hook manager
	 * @return	int									0
	 */
	public function doActions($parameters, &$object, &$action, $hookmanager)
	{
		global $langs;

		if (in_array('invoicecard', explode(':', (string) $parameters['context'])) && in_array($action, array('setpaymentterm', 'setconditions')) && $object instanceof Facture) {
			$schedule = new InstallmentSchedule($this->db);
			if (is_object($schedule->fetch('facture', (int) $object->id))) {
				$langs->load('installments@installments');
				setEventMessages($langs->trans('InstallmentsDueDateManaged'), null, 'warnings');
			}
		}

		return 0;
	}

	/**
	 * PDF of an invoice: room for its schedule in the area of the totals of the last page
	 *
	 * @param	array<string,mixed>	$parameters		Hook metadatas (object = invoice)
	 * @param	ModelePDFFactures	$object			Model of the PDF
	 * @param	?string				$action			Action
	 * @param	HookManager			$hookmanager	Hook manager
	 * @return	int									0
	 */
	public function printUnderHeaderPDFline($parameters, &$object, &$action, $hookmanager)
	{
		$invoice = $parameters['object'] ?? null;
		if ($invoice instanceof Facture && property_exists($object, 'heightforinfotot')) {
			$rows = $this->getPdfRows($invoice, $parameters['outputlangs']);
			if (!empty($rows)) {
				$object->heightforinfotot += 4 * (count($rows) + 1);
			}
		}

		return 0;
	}

	/**
	 * PDF of an invoice: its schedule under the totals, one row per installment
	 *
	 * @param	array<string,mixed>	$parameters		Hook metadatas (pdf, object = invoice, outputlangs, index, posy)
	 * @param	ModelePDFFactures	$object			Model of the PDF
	 * @param	?string				$action			Action
	 * @param	HookManager			$hookmanager	Hook manager
	 * @return	int									0
	 */
	public function afterPDFTotalTable($parameters, &$object, &$action, $hookmanager)
	{
		$invoice = $parameters['object'] ?? null;
		if (!($invoice instanceof Facture)) {
			return 0;
		}
		$outputlangs = $parameters['outputlangs'];
		$rows = $this->getPdfRows($invoice, $outputlangs);
		if (empty($rows)) {
			return 0;
		}
		$pdf = $parameters['pdf'];
		$fontsize = pdf_getPDFFontSize($outputlangs);
		$x = 120;	// Columns of the totals of the core models
		$width = $object->page_largeur - $object->marge_droite - $x;
		$y = (float) $parameters['posy'] + 4 * ((int) $parameters['index'] + 1);
		$pdf->SetTextColor(0, 0, 0);
		$pdf->SetFont('', 'B', $fontsize - 1);
		$pdf->SetXY($x, $y);
		$pdf->MultiCell($width, 4, $outputlangs->transnoentities('InstallmentSchedule'), 0, 'L', false);
		$pdf->SetFont('', '', $fontsize - 2);
		foreach ($rows as $row) {
			$y += 4;
			$pdf->SetXY($x, $y);
			$pdf->MultiCell($width - 30, 4, $row['label'], 0, 'L', false);
			$pdf->SetXY($x + $width - 30, $y);
			$pdf->MultiCell(30, 4, price($row['amount'], 0, $outputlangs), 0, 'R', false);
		}
		$parameters['index'] += count($rows) + 1;	// The table of the payments comes after

		return 0;
	}

	/**
	 * PDF of a proposal or an order: its schedule added to its public note while the PDF is made (their models keep no room under
	 * their totals, the note has its own). Nothing if the note already has the key __INSTALLMENTS__.
	 *
	 * @param	array<string,mixed>	$parameters		Hook metadatas (object = document, outputlangs)
	 * @param	CommonObject		$object			Document
	 * @param	?string				$action			Action
	 * @param	HookManager			$hookmanager	Hook manager
	 * @return	int									0
	 */
	public function beforePDFCreation($parameters, &$object, &$action, $hookmanager)
	{
		global $conf;

		$document = $parameters['object'] ?? null;
		if (!($document instanceof Propal || $document instanceof Commande) || strpos((string) $document->note_public, '__INSTALLMENTS__') !== false) {
			return 0;
		}
		$outputlangs = $parameters['outputlangs'];
		$rows = $this->getPdfRows($document, $outputlangs);
		if (empty($rows)) {
			return 0;
		}
		$html = '<strong>'.dol_escape_htmltag($outputlangs->transnoentities('InstallmentSchedule')).'</strong><table>';
		foreach ($rows as $row) {
			$html .= '<tr><td>'.dol_escape_htmltag($row['label']).'</td><td align="right">'.price($row['amount'], 0, $outputlangs, 1, -1, -1, $conf->currency).'</td></tr>';
		}
		$this->notes[$document->element.$document->id] = (string) $document->note_public;
		$document->note_public = dol_concatdesc((string) $document->note_public, $html.'</table>');

		return 0;
	}

	/**
	 * PDF of a proposal or an order made: its public note back
	 *
	 * @param	array<string,mixed>	$parameters		Hook metadatas (object = document)
	 * @param	CommonDocGenerator	$object			Model of the PDF
	 * @param	?string				$action			Action
	 * @param	HookManager			$hookmanager	Hook manager
	 * @return	int									0
	 */
	public function afterPDFCreation($parameters, &$object, &$action, $hookmanager)
	{
		$document = $parameters['object'] ?? null;
		if (is_object($document) && isset($this->notes[$document->element.$document->id])) {
			$document->note_public = $this->notes[$document->element.$document->id];
			unset($this->notes[$document->element.$document->id]);
		}

		return 0;
	}

	/**
	 * Rows of the schedule of a document for its PDF: date, payment mode (and state of an invoice), amount
	 *
	 * @param	CommonObject	$document		Invoice, proposal or order
	 * @param	Translate		$outputlangs	Language of the PDF
	 * @return	array<int,array{label:string,amount:float,paid:bool}>	Rows, none for a document without schedule of at least 2 installments
	 */
	private function getPdfRows(CommonObject $document, Translate $outputlangs)
	{
		$key = $document->element.$document->id;
		if (isset($this->pdfrows[$key])) {
			return $this->pdfrows[$key];
		}
		$rows = array();
		$schedule = new InstallmentSchedule($this->db);
		$data = $schedule->fetch($document->element, (int) $document->id);
		if (is_object($data) && count($data->lines) > 1) {
			$outputlangs->loadLangs(array('bills', 'installments@installments'));
			$paid = ($document instanceof Facture ? $schedule->allocate($document, $data->lines) : array());
			foreach ($data->lines as $line) {
				if ($line->status == InstallmentSchedule::STATUS_ABANDONED) {
					continue;
				}
				$mode = $schedule->getModeLabel($line->mode ?: (int) $document->mode_reglement_id, $outputlangs);
				$label = dol_print_date($line->date, 'day', false, $outputlangs).($mode !== '' ? ' - '.$mode : '');
				$ispaid = ($document instanceof Facture && $schedule->getState($data, $line, $paid[$line->id], dol_now()) == 'paid');
				$label .= ($ispaid ? ' ('.$outputlangs->transnoentitiesnoconv('InstallmentsStatePaid').')' : '');
				$rows[] = array('label' => $label, 'amount' => $line->amount, 'paid' => $ispaid);
			}
			if (count($rows) > self::PDF_MAX_ROWS) {	// A long schedule: the installments left to pay, the last ones on one line
				$rows = array_values(array_filter($rows, 'installmentsIsUnpaid'));
				if (count($rows) > self::PDF_MAX_ROWS) {
					$more = array_slice($rows, self::PDF_MAX_ROWS - 1);
					$rows = array_slice($rows, 0, self::PDF_MAX_ROWS - 1);
					$rows[] = array('label' => $outputlangs->transnoentitiesnoconv('InstallmentsPdfMore', count($more)), 'amount' => array_sum(array_column($more, 'amount')), 'paid' => false);
				}
			}
		}
		$this->pdfrows[$key] = $rows;

		return $rows;
	}
}


/**
 * Filter of the rows of the PDF: an installment left to pay
 *
 * @param	array{paid:bool}	$row	Row
 * @return	bool						True if not paid
 */
function installmentsIsUnpaid($row)
{
	return !$row['paid'];
}
