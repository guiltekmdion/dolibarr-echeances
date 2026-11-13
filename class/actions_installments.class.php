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
 * \brief   Hooks: the schedule in the PDF of an invoice (under its totals), of a proposal or an order (in its note), their payment terms "as per the schedule", a warning when the due date of an invoice is edited by hand, and one row per installment in the cash forecast of a bank account
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/commonhookactions.class.php';
dol_include_once('/installments/class/installmentschedule.class.php');


/**
 * Hooks of the module InstallmentSchedule
 */
class ActionsInstallments extends CommonHookActions
{
	const PDF_MAX_ROWS = 12;	// Rows of the schedule in the PDF with their payment mode; more installments: two columns of dates and amounts
	const PDF_MAX_COMPACT = 48;	// Installments in two columns at most (24 rows), for the room left under the totals; beyond, the ones left to pay

	/**
	 * @var DoliDB Database handler
	 */
	public $db;

	/**
	 * @var array<string,array<int,array{label:string,short:string,amount:float,paid:bool}>> Rows of the PDF by document, read by the first hook for the second one
	 */
	private $pdfrows = array();

	/**
	 * @var array<string,array{0:Translate,1:string,2:?string}> Payment terms printed "as per the schedule" while the PDF of a document is made: language, key, old text
	 */
	private $terms = array();

	/**
	 * @var array<int,stdClass> Installments left to pay in the cash forecast of a bank account, by id, read by addMoreSQL for moreFamily
	 */
	private $forecast = array();

	/**
	 * @var array<int,float> Amount left to pay in these installments, by id of invoice
	 */
	private $forecastdue = array();


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
				// @phan-suppress-next-line PhanUndeclaredProperty
				$object->heightforinfotot += 4 * ($this->getPdfHeight($rows) + 1);
			}
		}

		return 0;
	}

	/**
	 * PDF of an invoice: its schedule under the totals, one row per installment with its payment mode, or two columns of dates and
	 * amounts for a long schedule. The amounts are in the currency of the totals; a paid installment is grey.
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
		'@phan-var-force Translate $outputlangs';
		$rows = $this->getPdfRows($invoice, $outputlangs);
		if (empty($rows)) {
			return 0;
		}
		$pdf = $parameters['pdf'];
		'@phan-var-force TCPDF $pdf';
		$fontsize = pdf_getPDFFontSize($outputlangs);
		$x = 120;	// Columns of the totals of the core models
		$width = $object->page_largeur - $object->marge_droite - $x;
		$y = (float) $parameters['posy'] + 4 * ((int) $parameters['index'] + 1);
		$pdf->SetTextColor(0, 0, 0);
		$pdf->SetFont('', 'B', $fontsize - 1);
		$pdf->SetXY($x, $y);
		$pdf->MultiCell($width, 4, $outputlangs->transnoentities('InstallmentSchedule'), 0, 'L', false);
		$pdf->SetFont('', '', $fontsize - 2);
		$height = $this->getPdfHeight($rows);
		$columns = (count($rows) > self::PDF_MAX_ROWS ? 2 : 1);
		$colwidth = $width / $columns;
		foreach ($rows as $i => $row) {
			$cellx = $x + $colwidth * (int) floor($i / $height);
			$celly = $y + 4 * (1 + $i % $height);
			$pdf->SetTextColor($row['paid'] ? 128 : 0, $row['paid'] ? 128 : 0, $row['paid'] ? 128 : 0);
			$pdf->SetXY($cellx, $celly);
			$pdf->Cell($colwidth - 22, 4, ($columns > 1 ? $row['short'] : $row['label']), 0, 0, 'L', false, '', 1);	// Shrunk to fit: never over the next row
			$pdf->SetXY($cellx + $colwidth - 22, $celly);
			$pdf->Cell(20, 4, price($row['amount'], 0, $outputlangs), 0, 0, 'R', false, '', 1);
		}
		$pdf->SetTextColor(0, 0, 0);
		$parameters['index'] += $height + 1;	// The table of the payments comes after

		return 0;
	}

	/**
	 * PDF of a document with a schedule: its payment terms printed "as per the schedule" (the code of the terms is kept, only its text
	 * in the language of the PDF changes, given back after); for a proposal or an order, its schedule added to its public note, in a
	 * copy of the document the model prints (their models keep no room under their totals, the note has its own; the document itself
	 * is never changed, even if the PDF fails). Nothing in the note if it already has the key __INSTALLMENTS__.
	 *
	 * @param	array<string,mixed>	$parameters		Hook metadatas (object = document, outputlangs)
	 * @param	CommonObject		$object			Document the model prints
	 * @param	?string				$action			Action
	 * @param	HookManager			$hookmanager	Hook manager
	 * @return	int									0
	 */
	public function beforePDFCreation($parameters, &$object, &$action, $hookmanager)
	{
		$document = $parameters['object'] ?? null;
		if (!($document instanceof Facture || $document instanceof Propal || $document instanceof Commande)) {
			return 0;
		}
		$outputlangs = $parameters['outputlangs'];
		'@phan-var-force Translate $outputlangs';
		$rows = $this->getPdfRows($document, $outputlangs);
		if (empty($rows)) {
			return 0;
		}
		if (!empty($document->cond_reglement_code)) {
			$key = 'PaymentCondition'.$document->cond_reglement_code;
			$this->terms[$document->element.$document->id] = array($outputlangs, $key, $outputlangs->tab_translate[$key] ?? null);
			$outputlangs->tab_translate[$key] = $outputlangs->transnoentitiesnoconv('InstallmentsPdfPaymentTerms');
		}
		if ($document instanceof Facture || strpos((string) $document->note_public, '__INSTALLMENTS__') !== false) {
			return 0;
		}
		$schedule = new InstallmentSchedule($this->db);
		$html = '<strong>'.dol_escape_htmltag($outputlangs->transnoentities('InstallmentSchedule')).'</strong><br><table>';
		foreach ($rows as $row) {
			$html .= '<tr><td>'.dol_escape_htmltag($row['label']).'</td><td align="right">'.price($row['amount'], 0, $outputlangs, 1, -1, -1, $schedule->toDocumentCurrency($document, 0.0)[1]).'</td></tr>';
		}
		$object = clone $document;	// The model prints the copy: the note of the document stays as it is
		$object->note_public = dol_concatdesc((string) $document->note_public, $html.'</table>');

		return 0;
	}

	/**
	 * PDF made: the text of the payment terms given back
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
		if (is_object($document) && isset($this->terms[$document->element.$document->id])) {
			list($outputlangs, $key, $old) = $this->terms[$document->element.$document->id];
			if ($old === null) {
				unset($outputlangs->tab_translate[$key]);
			} else {
				$outputlangs->tab_translate[$key] = $old;
			}
			unset($this->terms[$document->element.$document->id]);
		}

		return 0;
	}

	/**
	 * Cash forecast of a bank account (compta/bank/treso.php): one row per installment left to pay, at its date, for the invoices with a schedule.
	 * The core keeps listing each invoice (see moreFamily): all is read here, in one query, for the two hooks.
	 *
	 * @param	array<string,mixed>	$parameters		Hook metadatas (context, etc...)
	 * @param	Account				$object			Bank account
	 * @param	?string				$action			Action
	 * @param	HookManager			$hookmanager	Hook manager
	 * @return	int									0, the query is in $this->results['sql'] (the core takes it only after a 0)
	 */
	public function addMoreSQL($parameters, &$object, &$action, $hookmanager)
	{
		$this->forecast = array();
		$this->forecastdue = array();
		if (!($object instanceof Account)) {
			return 0;
		}
		$schedule = new InstallmentSchedule($this->db);
		$lines = $schedule->getForecast((int) $object->id);
		foreach (is_array($lines) ? $lines : array() as $id => $line) {
			$this->forecast[$id] = $line;
			$this->forecastdue[$line->invoiceid] = ($this->forecastdue[$line->invoiceid] ?? 0.0) + $line->left;
		}
		if ($this->forecast) {
			$sql = "SELECT 'installment' as family, f.rowid as objid, l.rowid as ref, l.amount as total_ttc, f.type, l.date_due as dlr, s.rowid as socid, s.nom as name, s.fournisseur";
			$sql .= " FROM ".$this->db->prefix()."installments_line as l";
			$sql .= " INNER JOIN ".$this->db->prefix()."installments_schedule as sc ON sc.rowid = l.fk_schedule";
			$sql .= " INNER JOIN ".$this->db->prefix()."facture as f ON f.rowid = sc.fk_element";
			$sql .= " LEFT JOIN ".$this->db->prefix()."societe as s ON s.rowid = f.fk_soc";
			$sql .= " WHERE l.rowid IN (".$this->db->sanitize(implode(',', array_keys($this->forecast))).")";
			$this->results = array('sql' => $sql);
		}

		return 0;
	}

	/**
	 * Cash forecast of a bank account: the rows of the installments (invoice with its rank, third party, rest of the installment), and the invoice
	 * of the core given what its installments hold, so it shows only the rest they do not (nothing when they hold all of it).
	 *
	 * @param	array<string,mixed>	$parameters		Hook metadatas (obj = row, totalpayment = already paid, as the core read it)
	 * @param	CommonObject		$object			Not used
	 * @param	?string				$action			Action
	 * @param	HookManager			$hookmanager	Hook manager
	 * @return	int									0, the values are in $this->results
	 */
	public function moreFamily($parameters, &$object, &$action, $hookmanager)
	{
		$obj = $parameters['obj'];
		'@phan-var-force stdClass $obj';
		if ($obj->family == 'invoice' && isset($this->forecastdue[(int) $obj->objid])) {
			$this->results = array('totalpayment' => (float) $parameters['totalpayment'] + $this->forecastdue[(int) $obj->objid]);
		} elseif ($obj->family == 'installment' && isset($this->forecast[(int) $obj->ref])) {
			$line = $this->forecast[(int) $obj->ref];
			$invoice = new Facture($this->db);	// Without a read, as the core does
			$invoice->id = $line->invoiceid;
			$invoice->ref = $line->ref;
			$invoice->type = (int) $obj->type;
			$thirdparty = new Societe($this->db);
			$thirdparty->id = (int) $obj->socid;
			$thirdparty->name = (string) $obj->name;
			$this->results = array('ref' => $invoice->getNomUrl(1, '').' ('.$line->rank.'/'.$line->count.')', 'refcomp' => $thirdparty->getNomUrl(1, '', 24),
				'totalpayment' => (float) $obj->total_ttc - $line->left);
		}

		return 0;
	}

	/**
	 * Rows of the schedule of a document for its PDF: date and payment mode (and state of an invoice), amount in the currency of the document.
	 * All the installments, at most PDF_MAX_COMPACT: beyond, the ones left to pay, the last ones on one row.
	 *
	 * @param	CommonObject	$document		Invoice, proposal or order
	 * @param	Translate		$outputlangs	Language of the PDF
	 * @return	array<int,array{label:string,short:string,amount:float,paid:bool}>	Rows, none for a document without schedule of at least 2 installments
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
				$day = dol_print_date($line->date, 'day', false, $outputlangs);
				$ispaid = ($document instanceof Facture && $schedule->getState($data, $line, $paid[$line->id], dol_now()) == 'paid');
				$label = $day.($mode !== '' ? ' - '.$mode : '').($ispaid ? ' ('.$outputlangs->transnoentitiesnoconv('InstallmentsStatePaid').')' : '');
				$rows[] = array('label' => $label, 'short' => $day, 'amount' => $schedule->toDocumentCurrency($document, $line->amount)[0], 'paid' => $ispaid);
			}
			if (count($rows) > self::PDF_MAX_COMPACT) {	// A very long schedule: the installments left to pay, the last ones on one row
				$rows = array_values(array_filter($rows, 'installmentsIsUnpaid'));
				if (count($rows) > self::PDF_MAX_COMPACT) {
					$more = array_slice($rows, self::PDF_MAX_COMPACT - 1);
					$rows = array_slice($rows, 0, self::PDF_MAX_COMPACT - 1);
					$text = $outputlangs->transnoentitiesnoconv('InstallmentsPdfMore', (string) count($more));
					$rows[] = array('label' => $text, 'short' => $text, 'amount' => array_sum(array_column($more, 'amount')), 'paid' => false);
				}
			}
		}
		$this->pdfrows[$key] = $rows;

		return $rows;
	}

	/**
	 * Rows of 4 mm the schedule takes under the totals, title apart: one per installment, or two columns for a long schedule
	 *
	 * @param	array<int,array{label:string,short:string,amount:float,paid:bool}>	$rows	Rows of the PDF
	 * @return	int		Number of rows
	 */
	private function getPdfHeight(array $rows)
	{
		return (count($rows) > self::PDF_MAX_ROWS ? (int) ceil(count($rows) / 2) : count($rows));
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
