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
 * \file    installments/class/installmentschedule.class.php
 * \ingroup installments
 * \brief   Payment schedules: several expected payments on a customer invoice, or one invoice per installment of a proposal or an order
 */

require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/comm/propal/class/propal.class.php';
require_once DOL_DOCUMENT_ROOT.'/commande/class/commande.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/paiement/class/paiement.class.php';
require_once DOL_DOCUMENT_ROOT.'/comm/action/class/actioncomm.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/CMailFile.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.formmail.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';


/**
 * Schedules of the documents and their installments.
 * What is paid is never stored: the payments, credit notes and deposits of an invoice are given to its installments by date, oldest first.
 */
class InstallmentSchedule
{
	const STATUS_OPEN = 0;
	const STATUS_TOCHECK = 1;		// Imported from the module Echeancier: past installment that may have been invoiced by hand
	const STATUS_ABANDONED = 9;

	const INVOICE_DRAFT = 0;
	const INVOICE_VALIDATED = 1;
	const INVOICE_SENT = 2;

	const MAX_INSTALLMENTS = 120;	// Ten years of monthly installments

	/**
	 * @var array<string,string> Documents that can have a schedule: element => class
	 */
	const ELEMENTS = array('facture' => 'Facture', 'propal' => 'Propal', 'commande' => 'Commande');

	/**
	 * @var DoliDB Database handler
	 */
	public $db;

	/**
	 * @var string Last error
	 */
	public $error = '';

	/**
	 * @var string[] Errors of a run on several installments
	 */
	public $errors = array();

	/**
	 * @var string Report of a scheduled job
	 */
	public $output = '';

	/**
	 * @var int Decimals of the totals (MAIN_MAX_DECIMALS_TOT)
	 */
	public $decimals;

	/**
	 * @var ?array<int,string[]> Payment modes read once: id => code, label
	 */
	private $modes;


	/**
	 * Constructor
	 *
	 * @param	DoliDB	$db		Database handler
	 */
	public function __construct(DoliDB $db)
	{
		$this->db = $db;
		$this->decimals = getDolGlobalInt('MAIN_MAX_DECIMALS_TOT', 2);
	}

	/**
	 * Label of a payment mode in a language: its translation, else its label in the dictionary (a mode added by the user)
	 *
	 * @param	int			$modeid			Payment mode (llx_c_paiement)
	 * @param	Translate	$outputlangs	Language
	 * @return	string						Label, '' for none
	 */
	public function getModeLabel($modeid, Translate $outputlangs)
	{
		if (!isset($this->modes)) {
			$this->modes = array();
			$resql = $this->db->query("SELECT id, code, libelle FROM ".$this->db->prefix()."c_paiement WHERE entity IN (".getEntity('c_paiement').")");
			while ($resql && ($obj = $this->db->fetch_object($resql))) {
				$this->modes[(int) $obj->id] = array((string) $obj->code, (string) $obj->libelle);
			}
		}
		if (empty($this->modes[(int) $modeid])) {
			return '';
		}
		list($code, $label) = $this->modes[(int) $modeid];
		$key = 'PaymentType'.$code;
		$outputlangs->load('bills');

		return ($outputlangs->transnoentitiesnoconv($key) != $key ? $outputlangs->transnoentitiesnoconv($key) : $label);
	}

	/**
	 * A proposal is invoiced once signed, an order once validated: never a draft, refused or cancelled one
	 *
	 * @param	CommonObject	$source		Proposal or order
	 * @return	bool						True if its installments can be invoiced
	 */
	public function isBillable(CommonObject $source)
	{
		if ($source instanceof Propal) {
			return in_array((int) $source->status, array(Propal::STATUS_SIGNED, Propal::STATUS_BILLED));
		}

		return ($source instanceof Commande && (int) $source->status >= Commande::STATUS_VALIDATED);
	}

	/**
	 * The schedule of a document, with its installments by date
	 *
	 * @param	string	$element	facture, propal or commande
	 * @param	int		$fkelement	Id of the document
	 * @return	stdClass|int<-1,0>	Schedule (lines: installments), 0 if the document has none, -1 if error
	 */
	public function fetch($element, $fkelement)
	{
		$sql = "SELECT rowid, element, fk_element, billing, invoice_mode, fk_email_template, fk_user_creat FROM ".$this->db->prefix()."installments_schedule";
		$sql .= " WHERE entity IN (".getEntity('installments').") AND element = '".$this->db->escape($element)."' AND fk_element = ".((int) $fkelement);
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$obj = $this->db->fetch_object($resql);
		if (!$obj) {
			return 0;
		}
		$schedule = (object) array('id' => (int) $obj->rowid, 'element' => (string) $obj->element, 'fk_element' => (int) $obj->fk_element, 'billing' => (int) $obj->billing,
			'invoice_mode' => (int) $obj->invoice_mode, 'fk_email_template' => (int) $obj->fk_email_template, 'fk_user_creat' => (int) $obj->fk_user_creat, 'lines' => array());

		$sql = "SELECT rowid, rang, date_due, amount, fk_mode_reglement, fk_facture, fk_prelevement_demande, status, note FROM ".$this->db->prefix()."installments_line";
		$sql .= " WHERE fk_schedule = ".((int) $schedule->id).$this->db->order('date_due,rang,rowid', 'ASC,ASC,ASC');
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		while ($obj = $this->db->fetch_object($resql)) {
			$schedule->lines[] = (object) array('id' => (int) $obj->rowid, 'rang' => (int) $obj->rang, 'date' => (int) $this->db->jdate($obj->date_due), 'amount' => (float) $obj->amount,
				'mode' => (int) $obj->fk_mode_reglement, 'invoiceid' => (int) $obj->fk_facture, 'debitid' => (int) $obj->fk_prelevement_demande, 'status' => (int) $obj->status,
				'note' => (string) $obj->note);
		}

		return $schedule;
	}

	/**
	 * Installments of all the schedules with their state, for the list and the widget. What is paid is given by schedule, so all the
	 * installments of a schedule are read before the filters on dates and states.
	 *
	 * @param	User	$user		User reading: one without the right to see all customers gets its own customers
	 * @param	array{socid?:int,type?:string,search?:string,date_start?:int,date_end?:int,states?:string[],mode?:int}	$filters	Filters (type: payment or billing)
	 * @return	array<int,stdClass>|int<-1,-1>	Installments by date, -1 if error
	 */
	public function getInstallments(User $user, array $filters = array())
	{
		$decimals = $this->decimals;
		$sql = "SELECT l.rowid, l.fk_schedule, l.rang, l.date_due, l.amount, l.fk_mode_reglement, l.fk_facture, l.fk_prelevement_demande, l.status, l.note,";
		$sql .= " s.element, s.fk_element, s.billing, COALESCE(f.ref, p.ref, c.ref) as ref, so.rowid as socid, so.nom as socname,";
		$sql .= " f.paye, f.fk_statut as invoicestatus, COALESCE(f.fk_mode_reglement, p.fk_mode_reglement, c.fk_mode_reglement) as docmode, fi.ref as invoiceref,";
		$sql .= " (SELECT SUM(pf.amount) FROM ".$this->db->prefix()."paiement_facture as pf WHERE pf.fk_facture = f.rowid) as paid,";
		$sql .= " (SELECT SUM(rc.amount_ttc) FROM ".$this->db->prefix()."societe_remise_except as rc INNER JOIN ".$this->db->prefix()."facture as fs ON fs.rowid = rc.fk_facture_source";
		$sql .= " WHERE rc.fk_facture = f.rowid AND fs.type IN (".((int) Facture::TYPE_CREDIT_NOTE).", ".((int) Facture::TYPE_DEPOSIT).")) as discounts";	// Like allocate()
		$sql .= " FROM ".$this->db->prefix()."installments_line as l";
		$sql .= " INNER JOIN ".$this->db->prefix()."installments_schedule as s ON s.rowid = l.fk_schedule";
		$sql .= " LEFT JOIN ".$this->db->prefix()."facture as f ON s.element = 'facture' AND f.rowid = s.fk_element";
		$sql .= " LEFT JOIN ".$this->db->prefix()."propal as p ON s.element = 'propal' AND p.rowid = s.fk_element";
		$sql .= " LEFT JOIN ".$this->db->prefix()."commande as c ON s.element = 'commande' AND c.rowid = s.fk_element";
		$sql .= " INNER JOIN ".$this->db->prefix()."societe as so ON so.rowid = COALESCE(f.fk_soc, p.fk_soc, c.fk_soc)";
		$sql .= " LEFT JOIN ".$this->db->prefix()."facture as fi ON fi.rowid = l.fk_facture";
		$sql .= " WHERE s.entity IN (".getEntity('installments').")";
		$sql .= " AND (s.element = 'facture' OR s.billing = 1)";	// Expected payments of a proposal or an order: on its tab only, its invoice takes them
		if (!empty($filters['socid'])) {
			$sql .= " AND so.rowid = ".((int) $filters['socid']);
		}
		if (!empty($filters['type'])) {
			$sql .= " AND s.billing = ".($filters['type'] == 'billing' ? 1 : 0);
		}
		if (!empty($filters['search'])) {
			$sql .= natural_search(array('so.nom', 'f.ref', 'p.ref', 'c.ref', 'fi.ref'), $filters['search']);
		}
		if (!$user->hasRight('societe', 'client', 'voir')) {
			$sql .= " AND EXISTS (SELECT sc.fk_soc FROM ".$this->db->prefix()."societe_commerciaux as sc WHERE sc.fk_soc = so.rowid AND sc.fk_user = ".((int) $user->id).")";
		}
		$sql .= $this->db->order('l.date_due,l.rang,l.rowid', 'ASC,ASC,ASC');	// By date in each schedule too, for the payments given by date
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$now = dol_now();
		$rows = array();
		$left = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$row = (object) array('id' => (int) $obj->rowid, 'scheduleid' => (int) $obj->fk_schedule, 'rang' => (int) $obj->rang, 'date' => (int) $this->db->jdate($obj->date_due),
				'amount' => (float) $obj->amount, 'mode' => ((int) $obj->fk_mode_reglement ?: (int) $obj->docmode), 'invoiceid' => (int) $obj->fk_facture,
				'invoiceref' => (string) $obj->invoiceref, 'debitid' => (int) $obj->fk_prelevement_demande, 'status' => (int) $obj->status, 'note' => (string) $obj->note,
				'element' => (string) $obj->element, 'fk_element' => (int) $obj->fk_element, 'billing' => (int) $obj->billing, 'ref' => (string) $obj->ref,
				'socid' => (int) $obj->socid, 'socname' => (string) $obj->socname, 'paid' => 0.0);
			if ($row->element == 'facture' && (int) $obj->invoicestatus == Facture::STATUS_ABANDONED) {
				$row->status = self::STATUS_ABANDONED;
			}
			if (!$row->billing && $row->status != self::STATUS_ABANDONED) {	// Payments, credit notes and deposits of the invoice given by date
				$left[$row->scheduleid] ??= ((int) $obj->invoicestatus == Facture::STATUS_DRAFT ? 0.0 : (float) $obj->paid + (float) $obj->discounts);
				$row->paid = ($obj->paye ? $row->amount : round(min($row->amount, max(0.0, $left[$row->scheduleid])), $decimals));
				$left[$row->scheduleid] -= $row->paid;
			}
			$row->state = $this->getState((object) array('billing' => $row->billing), $row, $row->paid, $now);
			$rows[] = $row;
		}

		$filtered = array();
		foreach ($rows as $row) {
			if ((!empty($filters['date_start']) && $row->date < $filters['date_start']) || (!empty($filters['date_end']) && $row->date > $filters['date_end'])
				|| (!empty($filters['states']) && !in_array($row->state, $filters['states'])) || (!empty($filters['mode']) && $row->mode != $filters['mode'])) {
				continue;
			}
			$filtered[] = $row;
		}

		return $filtered;
	}

	/**
	 * Installments of equal amounts, the first one with its own share if asked, the last one taking the rounding
	 *
	 * @param	float	$total			Amount to share, including tax
	 * @param	int		$nb				Number of installments
	 * @param	int		$months			Months between two installments
	 * @param	int		$days			Days between two installments, added to the months
	 * @param	int		$firstdate		Date of the first installment
	 * @param	float	$firstpercent	Share of the first installment (%), 0 for equal installments
	 * @param	int		$mode			Payment mode of the installments (llx_c_paiement), 0 = the one of the document
	 * @return	array<int,array{date:int,amount:float,mode:int,note:string}>	Installments
	 */
	public function generate($total, $nb, $months, $days, $firstdate, $firstpercent = 0.0, $mode = 0)
	{
		$decimals = $this->decimals;
		$nb = min(max(1, $nb), self::MAX_INSTALLMENTS);
		$first = ($firstpercent > 0 && $nb > 1 ? round($total * $firstpercent / 100, $decimals) : null);
		$each = round(($total - (float) $first) / ($first === null ? $nb : $nb - 1), $decimals);
		$start = dol_getdate($firstdate);
		$base = dol_mktime(12, 0, 0, $start['mon'], $start['mday'], $start['year'], 'tzserver');
		$lines = array();
		$sum = 0.0;
		for ($i = 0; $i < $nb; $i++) {
			$amount = ($i == $nb - 1 ? round($total - $sum, $decimals) : ($i == 0 && $first !== null ? $first : $each));
			$sum += $amount;
			$date = dol_time_plus_duree($base, $i * $months, 'm', 1, 'tzserver');	// A 31st gives the last day of a shorter month
			$lines[] = array('date' => dol_time_plus_duree($date, $i * $days, 'd'), 'amount' => $amount, 'mode' => $mode, 'note' => '');
		}

		return $lines;
	}

	/**
	 * Installments of a template of the dictionary: its free lines if it has some, else its equal installments
	 *
	 * @param	stdClass	$template	Template (fetchTemplates)
	 * @param	float		$total		Amount to share, including tax
	 * @param	int			$firstdate	Date of the document, or of the first installment
	 * @return	array<int,array{date:int,amount:float,mode:int,note:string}>	Installments, none if the free lines are wrong (see error)
	 */
	public function generateFromTemplate(stdClass $template, $total, $firstdate)
	{
		global $langs;

		if ($template->split_rule === '') {
			return $this->generate($total, $template->nb, $template->months, $template->days, $firstdate, $template->first_percent, $template->mode);
		}
		$langs->load('installments@installments');
		$parts = array_values(array_diff(array_map('trim', explode(';', $template->split_rule)), array('')));
		$start = dol_getdate($firstdate);
		$base = dol_mktime(12, 0, 0, $start['mon'], $start['mday'], $start['year'], 'tzserver');
		$lines = array();
		$percents = 0.0;
		$sum = 0.0;
		foreach ($parts as $i => $part) {	// percent:days[:eom], the last one taking the rounding
			$fields = array_map('trim', explode(':', $part));
			$percent = (float) price2num($fields[0]);
			$eom = strtolower($fields[2] ?? '');
			if ($percent <= 0 || !preg_match('/^\d+$/', $fields[1] ?? '0') || !in_array($eom, array('', 'eom', 'fdm')) || count($parts) > self::MAX_INSTALLMENTS) {
				$this->error = dolPrintLabel($langs->transnoentitiesnoconv('InstallmentsErrorSplitRule', $template->code, $part));
				return array();
			}
			$percents += $percent;
			$amount = ($i == count($parts) - 1 ? round($total - $sum, $this->decimals) : round($total * $percent / 100, $this->decimals));
			$sum += $amount;
			$date = dol_time_plus_duree($base, (int) ($fields[1] ?? 0), 'd');
			if ($eom) {	// End of the month of the date
				$day = dol_getdate($date);
				$last = dol_getdate(dol_get_last_day($day['year'], $day['mon'], 'tzserver'));
				$date = dol_mktime(12, 0, 0, $day['mon'], $last['mday'], $day['year'], 'tzserver');
			}
			$lines[] = array('date' => $date, 'amount' => $amount, 'mode' => $template->mode, 'note' => '');
		}
		if (empty($lines) || round($percents, 3) != 100.0) {
			$this->error = dolPrintLabel($langs->transnoentitiesnoconv('InstallmentsErrorSplitRule', $template->code, $template->split_rule));
			return array();
		}

		return $lines;
	}

	/**
	 * At the validation of a document without schedule: the template of the dictionary linked to its payment term
	 *
	 * @param	User			$user		User validating
	 * @param	CommonObject	$object		Invoice (standard), proposal or order
	 * @return	int<-1,1>					1 if created, 0 if nothing to do, -1 if error
	 */
	public function applyPaymentTermTemplate(User $user, CommonObject $object)
	{
		'@phan-var-force Facture|Propal|Commande $object';
		if (($object instanceof Facture && (int) $object->type != Facture::TYPE_STANDARD) || (float) $object->total_ttc <= 0 || empty($object->cond_reglement_id)) {
			return 0;
		}
		if ($object instanceof Facture) {	// The invoice of an installment is not split again
			$resql = $this->db->query("SELECT COUNT(rowid) as nb FROM ".$this->db->prefix()."installments_line WHERE fk_facture = ".((int) $object->id));
			$obj = ($resql ? $this->db->fetch_object($resql) : null);
			if (!$obj || $obj->nb > 0) {
				return ($obj ? 0 : -1);
			}
		}
		$code = (string) dol_getIdFromCode($this->db, (int) $object->cond_reglement_id, 'c_payment_term', 'rowid', 'code', 1);
		$templates = $this->fetchTemplates();
		if (!is_array($templates) || $code === '' || $code === '-1') {
			return (is_array($templates) ? 0 : -1);
		}
		foreach ($templates as $template) {
			if ($template->payment_term_code !== $code) {
				continue;
			}
			$existing = $this->fetch($object->element, (int) $object->id);
			if ($existing !== 0) {
				return (is_object($existing) ? 0 : -1);
			}
			$lines = $this->generateFromTemplate($template, (float) $object->total_ttc, (int) $object->date);
			$this->error = (empty($lines) ? $this->error : '');
			if (!empty($lines) && $this->store($user, $object, $lines, 0, 0, 0) > 0) {
				return 1;
			}
			if ($this->db->lasterror()) {
				return -1;
			}
			dol_syslog(__METHOD__.' '.$this->error, LOG_WARNING);	// A wrong template, an amount too small: said, the validation goes on
			setEventMessages($this->error, null, 'warnings');
			return 0;
		}

		return 0;
	}

	/**
	 * Active templates of schedules of the dictionary
	 *
	 * @return	array<int,stdClass>|int<-1,-1>	Templates by id, -1 if error
	 */
	public function fetchTemplates()
	{
		$sql = "SELECT rowid, code, label, nb, months, days, first_percent, mode_code, split_rule, payment_term_code FROM ".$this->db->prefix()."c_installments_template";
		$sql .= " WHERE entity IN (".getEntity('c_installments_template').") AND active = 1".$this->db->order('position,label', 'ASC,ASC');
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$templates = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$templates[(int) $obj->rowid] = (object) array('id' => (int) $obj->rowid, 'code' => (string) $obj->code, 'label' => (string) $obj->label, 'nb' => (int) $obj->nb,
				'months' => (int) $obj->months, 'days' => (int) $obj->days, 'first_percent' => (float) $obj->first_percent,
				'mode' => ($obj->mode_code ? (int) dol_getIdFromCode($this->db, $obj->mode_code, 'c_paiement', 'code', 'id', 1) : 0),
				'split_rule' => trim((string) $obj->split_rule), 'payment_term_code' => (string) $obj->payment_term_code);
		}

		return $templates;
	}

	/**
	 * The templates ready to use, added when the dictionary of the entity is empty (activation of the module)
	 *
	 * @return	int<-1,1>	1 if OK, -1 if error
	 */
	public function initTemplates()
	{
		global $conf, $langs;

		$sql = "SELECT COUNT(rowid) as nb FROM ".$this->db->prefix()."c_installments_template WHERE entity = ".((int) $conf->entity);
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$obj = $this->db->fetch_object($resql);
		if ($obj && $obj->nb > 0) {
			return 1;
		}
		$langs->load('installments@installments');
		$templates = array(array('3X', 3, 1, 0, 0), array('30-70', 2, 0, 30, 30), array('12M', 12, 1, 0, 0));	// Code, number, months, days, first share
		foreach ($templates as $i => $template) {	// Labels in the language of the administrator who enables the module
			$sql = "INSERT INTO ".$this->db->prefix()."c_installments_template (entity, code, label, nb, months, days, first_percent, position, active)";
			$sql .= " VALUES (".((int) $conf->entity).", '".$this->db->escape($template[0])."', '".$this->db->escape($langs->transnoentitiesnoconv('InstallmentsTemplate'.str_replace('-', '', $template[0])))."', ".((int) $template[1]).", ".((int) $template[2]);
			$sql .= ", ".((int) $template[3]).", ".((float) $template[4]).", ".((int) $i + 1).", 1)";
			if (!$this->db->query($sql)) {
				$this->error = $this->db->lasterror();
				return -1;
			}
		}

		return 1;
	}

	/**
	 * Amounts paid of the installments of an invoice: payments, credit notes and deposits given by date, oldest first.
	 * A closed invoice has all its installments paid, an abandoned installment nothing.
	 *
	 * @param	Facture					$invoice	Invoice
	 * @param	array<int,stdClass>		$lines		Its installments, by date
	 * @param	float					$excluded	Amount of a payment being deleted
	 * @return	array<int,float>					Amount paid by id of installment
	 */
	public function allocate(Facture $invoice, array $lines, $excluded = 0.0)
	{
		$decimals = $this->decimals;
		$left = (float) $invoice->getSommePaiement() + (float) $invoice->getSumCreditNotesUsed() + (float) $invoice->getSumDepositsUsed() - $excluded;
		$paid = array();
		foreach ($lines as $line) {
			if ($line->status == self::STATUS_ABANDONED) {
				$paid[$line->id] = 0.0;
				continue;
			}
			$paid[$line->id] = ($invoice->paye ? $line->amount : round(min($line->amount, max(0.0, $left)), $decimals));
			$left -= $paid[$line->id];
		}

		return $paid;
	}

	/**
	 * State of an installment: paid, late, todo (to come), invoiced, tocheck or abandoned
	 *
	 * @param	stdClass	$schedule	Schedule
	 * @param	stdClass	$line		Installment
	 * @param	float		$paid		Its amount paid (expected payments)
	 * @param	int			$now		Date of the reading
	 * @return	string					State
	 */
	public function getState(stdClass $schedule, stdClass $line, $paid, $now)
	{
		if ($line->status == self::STATUS_ABANDONED) {
			return 'abandoned';
		}
		if ($schedule->billing) {
			return ($line->invoiceid ? 'invoiced' : ($line->status == self::STATUS_TOCHECK ? 'tocheck' : ($line->date < dol_get_first_hour($now) ? 'late' : 'todo')));
		}
		if (round($line->amount - $paid, $this->decimals) <= 0) {
			return 'paid';
		}

		return ($line->date < dol_get_first_hour($now) ? 'late' : 'todo');
	}

	/**
	 * Save the schedule of a document. Its open installments are replaced by the new ones; an installment paid, invoiced or direct debited
	 * must be given again unchanged (same id, date and amount).
	 *
	 * @param	User			$user			User saving
	 * @param	CommonObject	$object			Invoice, proposal or order
	 * @param	array<int,array{id?:int,date:int,amount:float,mode:int,note:string}>	$lines	Installments
	 * @param	int				$billing		1 = one invoice per installment (proposals and orders)
	 * @param	int				$invoicemode	Invoice of an installment: 0 = draft, 1 = validated, 2 = validated and sent
	 * @param	int				$emailtemplate	Email template of the mode 2, 0 = the default one
	 * @return	int								Id of the schedule, <0 if error
	 */
	public function save(User $user, CommonObject $object, array $lines, $billing = 0, $invoicemode = 0, $emailtemplate = 0)
	{
		global $langs;

		if (!$user->hasRight('installments', 'write')) {
			$langs->load('installments@installments');
			$this->error = $langs->trans('NotEnoughPermissions');
			return -1;
		}

		return $this->store($user, $object, $lines, $billing, $invoicemode, $emailtemplate);
	}

	/**
	 * Save a schedule without checking the rights of the user (copy at the validation of an invoice)
	 *
	 * @param	User			$user			User saving
	 * @param	CommonObject	$object			Invoice, proposal or order
	 * @param	array<int,array{id?:int,date:int,amount:float,mode:int,note:string}>	$lines	Installments
	 * @param	int				$billing		1 = one invoice per installment
	 * @param	int				$invoicemode	Invoice of an installment: 0 = draft, 1 = validated, 2 = validated and sent
	 * @param	int				$emailtemplate	Email template of the mode 2
	 * @return	int								Id of the schedule, <0 if error
	 */
	private function store(User $user, CommonObject $object, array $lines, $billing, $invoicemode, $emailtemplate)
	{
		global $conf, $langs;

		$langs->load('installments@installments');
		if (!isset(self::ELEMENTS[$object->element])) {
			$this->error = 'Bad element '.$object->element;
			return -1;
		}
		$billing = ($object->element == 'facture' ? 0 : (int) (bool) $billing);
		$invoicemode = min(max(0, (int) $invoicemode), $this->getInvoiceModeAllowed($user));	// Validated or sent only by a user allowed to
		$decimals = $this->decimals;
		$total = 0.0;
		if (count($lines) > self::MAX_INSTALLMENTS) {
			$this->error = $langs->trans('InstallmentsErrorLine');
			return -1;
		}
		foreach ($lines as $line) {
			if (empty($line['date']) || (float) $line['amount'] <= 0) {
				$this->error = $langs->trans('InstallmentsErrorLine');
				return -1;
			}
			$total += (float) $line['amount'];
		}
		if (empty($lines) || round($total - (float) $object->total_ttc, $decimals) != 0) {
			$this->error = $langs->trans('InstallmentsErrorTotal', price($total, 0, $langs, 1, -1, -1, $conf->currency), price((float) $object->total_ttc, 0, $langs, 1, -1, -1, $conf->currency));
			return -1;
		}

		$schedule = $this->fetch($object->element, (int) $object->id);
		if (!is_object($schedule) && $schedule < 0) {
			return -1;
		}
		$locked = (is_object($schedule) ? $this->getLocked($object, $schedule) : array());
		$oldstatus = (is_object($schedule) ? array_column($schedule->lines, 'status', 'id') : array());
		$given = array();
		foreach ($lines as $line) {
			if (!empty($line['id']) && isset($locked[$line['id']])) {
				$given[$line['id']] = $line;
			}
		}
		foreach ($locked as $id => $old) {	// A paid, invoiced or direct debited installment stays as it is
			if (!isset($given[$id]) || dol_print_date($given[$id]['date'], 'dayrfc') != dol_print_date($old->date, 'dayrfc') || round((float) $given[$id]['amount'] - $old->amount, $decimals) != 0) {
				$this->error = $langs->trans('InstallmentsErrorLocked', dol_print_date($old->date, 'day'));
				return -1;
			}
		}

		$this->db->begin();
		if (is_object($schedule)) {
			$id = $schedule->id;
			$sql = "UPDATE ".$this->db->prefix()."installments_schedule SET billing = ".((int) $billing).", invoice_mode = ".((int) $invoicemode).", fk_email_template = ".((int) $emailtemplate);
			$sql .= " WHERE rowid = ".((int) $id);
			$sqldel = "DELETE FROM ".$this->db->prefix()."installments_line WHERE fk_schedule = ".((int) $id);
			if (!empty($locked)) {
				$sqldel .= " AND rowid NOT IN (".$this->db->sanitize(implode(',', array_map('intval', array_keys($locked)))).")";
			}
			$ok = ($this->db->query($sql) && $this->db->query($sqldel));
		} else {
			$sql = "INSERT INTO ".$this->db->prefix()."installments_schedule (entity, element, fk_element, billing, invoice_mode, fk_email_template, date_creation, fk_user_creat)";
			$sql .= " VALUES (".((int) $object->entity).", '".$this->db->escape($object->element)."', ".((int) $object->id).", ".((int) $billing).", ".((int) $invoicemode);
			$sql .= ", ".((int) $emailtemplate).", '".$this->db->idate(dol_now())."', ".((int) $user->id).")";
			$ok = (bool) $this->db->query($sql);
			$id = ($ok ? (int) $this->db->last_insert_id($this->db->prefix()."installments_schedule") : 0);
		}
		$rang = 0;
		foreach ($lines as $line) {	// One query per installment: at most MAX_INSTALLMENTS
			$rang++;
			if (!$ok) {
				break;
			}
			if (!empty($line['id']) && isset($locked[$line['id']])) {
				$ok = (bool) $this->db->query("UPDATE ".$this->db->prefix()."installments_line SET rang = ".((int) $rang)." WHERE rowid = ".((int) $line['id']));
				continue;
			}
			$status = (!empty($line['id']) && isset($oldstatus[$line['id']]) ? $oldstatus[$line['id']] : self::STATUS_OPEN);	// Abandoned, to check: kept
			$ok = $this->insertLine($id, $rang, (int) $line['date'], (float) $line['amount'], (int) $line['mode'], (string) $line['note'], $status);
		}
		if ($ok && $object instanceof Facture) {
			$ok = ($this->syncDueDate($object) >= 0);
		}
		if (!$ok) {
			$this->error = ($this->error ?: $this->db->lasterror());
			$this->db->rollback();
			return -1;
		}
		$this->db->commit();

		return $id;
	}

	/**
	 * Insert an installment
	 *
	 * @param	int		$scheduleid		Schedule
	 * @param	int		$rang			Position
	 * @param	int		$date			Due date
	 * @param	float	$amount			Amount including tax
	 * @param	int		$mode			Payment mode, 0 = the one of the document
	 * @param	string	$note			Note
	 * @param	int		$status			Status
	 * @param	int		$invoiceid		Invoice of the installment
	 * @param	string	$importkey		Import key
	 * @return	bool					True if OK
	 */
	private function insertLine($scheduleid, $rang, $date, $amount, $mode, $note, $status = self::STATUS_OPEN, $invoiceid = 0, $importkey = '')
	{
		$sql = "INSERT INTO ".$this->db->prefix()."installments_line (fk_schedule, rang, date_due, amount, fk_mode_reglement, fk_facture, status, note, import_key)";
		$sql .= " VALUES (".((int) $scheduleid).", ".((int) $rang).", '".$this->db->idate($date)."', ".((float) price2num($amount, 'MT')).", ".((int) $mode);
		$sql .= ", ".($invoiceid > 0 ? (int) $invoiceid : "NULL").", ".((int) $status).", ".($note !== '' ? "'".$this->db->escape($note)."'" : "NULL");
		$sql .= ", ".($importkey !== '' ? "'".$this->db->escape($importkey)."'" : "NULL").")";

		return (bool) $this->db->query($sql);
	}

	/**
	 * Installments that can no longer change: paid in part or in full, invoiced, or with a direct debit request
	 *
	 * @param	CommonObject	$object		Document of the schedule
	 * @param	stdClass		$schedule	Schedule
	 * @return	array<int,stdClass>			Installments by id
	 */
	public function getLocked(CommonObject $object, stdClass $schedule)
	{
		$paid = ($object instanceof Facture ? $this->allocate($object, $schedule->lines) : array());
		$locked = array();
		foreach ($schedule->lines as $line) {
			if ($line->invoiceid || $line->debitid || ($paid[$line->id] ?? 0) > 0) {
				$locked[$line->id] = $line;
			}
		}

		return $locked;
	}

	/**
	 * Highest mode of the invoices of the installments a user may ask: validated with the right to validate invoices, sent with the
	 * right to send them too (like the card of an invoice)
	 *
	 * @param	User	$user	User
	 * @return	int				INVOICE_DRAFT, INVOICE_VALIDATED or INVOICE_SENT
	 */
	public function getInvoiceModeAllowed(User $user)
	{
		$advanced = getDolGlobalString('MAIN_USE_ADVANCED_PERMS');
		if (!($advanced ? $user->hasRight('facture', 'invoice_advance', 'validate') : $user->hasRight('facture', 'creer'))) {
			return self::INVOICE_DRAFT;
		}

		return ((!$advanced || $user->hasRight('facture', 'invoice_advance', 'send')) ? self::INVOICE_SENT : self::INVOICE_VALIDATED);
	}

	/**
	 * Delete the schedule of a document, refused when an installment is paid, invoiced or direct debited
	 *
	 * @param	User			$user		User deleting
	 * @param	CommonObject	$object		Document
	 * @param	bool			$force		True when the document itself is deleted
	 * @return	int<-1,1>					1 if OK, -1 if error
	 */
	public function delete(User $user, CommonObject $object, $force = false)
	{
		global $langs;

		$schedule = $this->fetch($object->element, (int) $object->id);
		if (!is_object($schedule)) {
			return ($schedule < 0 ? -1 : 1);
		}
		if (!$force && (!$user->hasRight('installments', 'write') || !empty($this->getLocked($object, $schedule)))) {
			$langs->load('installments@installments');
			$this->error = $langs->trans('InstallmentsErrorDelete');
			return -1;
		}
		$this->db->begin();
		if (!$this->db->query("DELETE FROM ".$this->db->prefix()."installments_line WHERE fk_schedule = ".((int) $schedule->id))
			|| !$this->db->query("DELETE FROM ".$this->db->prefix()."installments_schedule WHERE rowid = ".((int) $schedule->id))) {
			$this->error = $this->db->lasterror();
			$this->db->rollback();
			return -1;
		}
		$this->db->commit();

		return 1;
	}

	/**
	 * Due date of the invoice = date of its next unpaid installment (the last one once all are paid): the core then shows the right
	 * delay in its lists, widgets, forecast, reminder job and PDF. Written without trigger.
	 *
	 * @param	Facture		$invoice	Invoice
	 * @param	float		$excluded	Amount of a payment being deleted
	 * @return	int<-1,1>				1 if changed, 0 if nothing to do, -1 if error
	 */
	public function syncDueDate(Facture $invoice, $excluded = 0.0)
	{
		if ($invoice->status == Facture::STATUS_ABANDONED) {
			return 0;
		}
		$schedule = $this->fetch('facture', (int) $invoice->id);
		if (!is_object($schedule)) {
			return $schedule;
		}
		$paid = $this->allocate($invoice, $schedule->lines, $excluded);
		$next = 0;
		$last = 0;
		foreach ($schedule->lines as $line) {
			if ($line->status == self::STATUS_ABANDONED) {
				continue;
			}
			$last = $line->date;
			if (!$next && round($line->amount - $paid[$line->id], $this->decimals) > 0) {
				$next = $line->date;
			}
		}
		$date = ($next ?: $last);
		if (!$date || ($invoice->date_lim_reglement && dol_print_date($invoice->date_lim_reglement, 'dayrfc') == dol_print_date($date, 'dayrfc'))) {
			return 0;
		}
		$sql = "UPDATE ".$this->db->prefix()."facture SET date_lim_reglement = '".$this->db->idate($date)."' WHERE rowid = ".((int) $invoice->id);
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$invoice->date_lim_reglement = $date;

		return 1;
	}

	/**
	 * At the validation of an invoice without schedule: the schedule of expected payments of its order or proposal, when the totals are the same
	 *
	 * @param	User		$user		User validating
	 * @param	Facture		$invoice	Invoice
	 * @return	int<-1,1>				1 if copied, 0 if nothing to copy, -1 if error
	 */
	public function copyFromOrigin(User $user, Facture $invoice)
	{
		$schedule = $this->fetch('facture', (int) $invoice->id);
		if ($schedule !== 0) {
			return (is_object($schedule) ? 0 : -1);
		}
		$sql = "SELECT ee.sourcetype, ee.fk_source FROM ".$this->db->prefix()."element_element as ee";	// No join on the text columns: their collations may differ
		$sql .= " WHERE ee.targettype = 'facture' AND ee.fk_target = ".((int) $invoice->id)." AND ee.sourcetype IN ('commande', 'propal')";
		$sql .= $this->db->order('ee.sourcetype', 'ASC');	// The order before the proposal: the closest origin
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		while ($obj = $this->db->fetch_object($resql)) {
			$class = self::ELEMENTS[$obj->sourcetype];
			$source = new $class($this->db);
			$origin = $this->fetch((string) $obj->sourcetype, (int) $obj->fk_source);
			if (!is_object($origin) || $origin->billing || $source->fetch((int) $obj->fk_source) <= 0 || round((float) $source->total_ttc - (float) $invoice->total_ttc, $this->decimals) != 0) {
				continue;
			}
			$lines = array();
			foreach ($origin->lines as $line) {
				if ($line->status != self::STATUS_ABANDONED) {
					$lines[] = array('date' => $line->date, 'amount' => $line->amount, 'mode' => $line->mode, 'note' => $line->note);
				}
			}
			if (round(array_sum(array_column($lines, 'amount')) - (float) $invoice->total_ttc, $this->decimals) != 0) {	// A schedule not changed with its document: not copied
				continue;
			}

			return ($this->store($user, $invoice, $lines, 0, 0, 0) > 0 ? 1 : -1);
		}

		return 0;
	}

	/**
	 * The 60 days rule between professionals in France (article L441-10 of the Code de commerce): a schedule of expected payments
	 * of a company customer must not end more than 60 days after the date of the document.
	 *
	 * @param	CommonObject			$object		Invoice, proposal or order
	 * @param	array<int,stdClass|array{date:int}>	$lines	Installments
	 * @return	bool								True if the schedule goes beyond
	 */
	public function isBeyondSixtyDays(CommonObject $object, array $lines)
	{
		if (empty($lines) || empty($object->date)) {
			return false;
		}
		if (!is_object($object->thirdparty)) {
			$object->fetch_thirdparty();
		}
		if (!is_object($object->thirdparty) || $object->thirdparty->isACompany() <= 0) {
			return false;
		}
		$last = 0;
		foreach ($lines as $line) {
			$last = max($last, (int) (is_object($line) ? $line->date : $line['date']));
		}

		'@phan-var-force Facture|Propal|Commande $object';
		return $last > dol_time_plus_duree((int) $object->date, 60, 'd');
	}

	/**
	 * Create the invoice of an installment of a proposal or an order: one line per VAT rate and type of the document, in proportion;
	 * the last installment takes what is left of each line. The invoice is then validated or sent if the schedule asks for it.
	 *
	 * @param	User		$user		User creating
	 * @param	stdClass	$schedule	Billing schedule
	 * @param	stdClass	$line		Installment
	 * @param	int			$mode		-1 = mode of the schedule, else 0 = draft only
	 * @return	int						Id of the invoice, <0 if error
	 */
	public function createInvoice(User $user, stdClass $schedule, stdClass $line, $mode = -1)
	{
		global $conf, $langs;

		$langs->load('installments@installments');
		if (!$schedule->billing || $line->invoiceid || $line->status == self::STATUS_ABANDONED || !isset(self::ELEMENTS[$schedule->element])) {
			$this->error = $langs->trans('InstallmentsErrorNotToInvoice');
			return -1;
		}
		if (!$user->hasRight('facture', 'creer')) {
			$this->error = $langs->trans('NotEnoughPermissions');
			return -1;
		}
		$class = self::ELEMENTS[$schedule->element];
		$source = new $class($this->db);
		if ($source->fetch($schedule->fk_element) <= 0) {
			$this->error = $source->error;
			return -1;
		}
		if (!$this->isBillable($source)) {
			$this->error = $source->ref.': '.$langs->trans('InstallmentsErrorNotBillable');
			return -1;
		}
		$source->fetch_thirdparty();
		$decimals = $this->decimals;

		// Amounts of the document by VAT rate, local taxes and type of line
		$groups = array();
		foreach ($source->lines as $sourceline) {
			if (!in_array((int) $sourceline->product_type, array(0, 1)) || (int) $sourceline->special_code == 3) {	// Titles, subtotals and options are not invoiced
				continue;
			}
			$key = price2num($sourceline->tva_tx).'|'.$sourceline->vat_src_code.'|'.((int) $sourceline->product_type).'|'.price2num($sourceline->localtax1_tx).'|'.price2num($sourceline->localtax2_tx);
			$groups[$key] = ($groups[$key] ?? 0.0) + (float) $sourceline->total_ttc;
		}
		$open = 0;	// Installments left to invoice besides this one
		$nb = 0;
		$rank = 0;
		$invoiced = array();
		$abandoned = false;
		foreach ($schedule->lines as $other) {
			if ($other->status == self::STATUS_ABANDONED) {
				$abandoned = true;
				continue;
			}
			$nb++;
			if ($other->id == $line->id) {
				$rank = $nb;
			} elseif (!$other->invoiceid) {
				$open++;
			} else {
				$invoiced[] = $other->invoiceid;
			}
		}
		$amounts = array();
		if ($open == 0 && !$abandoned) {	// Last installment: what is left of each line, after the invoices of the other installments (an abandoned one is not invoiced)
			$billed = $this->getInvoicedByGroup($invoiced);
			if (!is_array($billed)) {
				return -1;
			}
			foreach ($groups as $key => $amount) {
				$amounts[$key] = round($amount - ($billed[$key] ?? 0.0), $decimals);
			}
		} else {
			$ratio = ((float) $source->total_ttc != 0 ? $line->amount / (float) $source->total_ttc : 0.0);
			$largest = '';
			foreach ($groups as $key => $amount) {
				$amounts[$key] = round($amount * $ratio, $decimals);
				$largest = ($largest === '' || abs($amount) > abs($groups[$largest]) ? $key : $largest);
			}
			if ($largest !== '') {	// The rounding on the largest line: the invoice is the amount of the installment
				$amounts[$largest] = round($amounts[$largest] + $line->amount - array_sum($amounts), $decimals);
			}
		}

		$outputlangs = $langs;
		if (!empty($source->thirdparty->default_lang) && $source->thirdparty->default_lang != $langs->defaultlang) {
			$outputlangs = new Translate('', $conf);
			$outputlangs->setDefaultLang($source->thirdparty->default_lang);
			$outputlangs->loadLangs(array('main', 'bills', 'installments@installments'));
		}

		$invoice = new Facture($this->db);
		$invoice->socid = $source->socid;
		$invoice->type = Facture::TYPE_STANDARD;
		$invoice->date = dol_now();
		$invoice->cond_reglement_id = (int) $source->cond_reglement_id;
		$invoice->mode_reglement_id = ($line->mode ?: (int) $source->mode_reglement_id);
		$invoice->fk_account = (int) $source->fk_account;
		$invoice->fk_project = (int) $source->fk_project;
		$invoice->ref_client = (string) $source->ref_client;
		$invoice->linked_objects = array($schedule->element => $source->id);

		$this->db->begin();
		$invoiceid = $invoice->create($user);
		$error = ($invoiceid <= 0 ? $invoice->error : '');
		$desc = $outputlangs->transnoentities('InstallmentsInvoiceLine', (string) $rank, (string) $nb, $source->ref).($line->note !== '' ? "\n".$line->note : '');
		foreach ($amounts as $key => $amount) {
			if ($error || $amount == 0) {
				continue;
			}
			list($rate, $code, $type, $localtax1, $localtax2) = explode('|', $key);
			if ($invoice->addline($desc, 0, 1, $rate.($code ? ' ('.$code.')' : ''), (float) $localtax1, (float) $localtax2, 0, 0, '', '', 0, 0, 0, 'TTC', $amount, (int) $type) < 0) {
				$error = $invoice->error;
			}
		}
		if (!$error) {	// The installment is taken in the same transaction: a second run cannot invoice it again
			$sql = "UPDATE ".$this->db->prefix()."installments_line SET fk_facture = ".((int) $invoiceid).", status = ".self::STATUS_OPEN;
			$sql .= " WHERE rowid = ".((int) $line->id)." AND fk_facture IS NULL";
			$resql = $this->db->query($sql);
			$error = (!$resql ? $this->db->lasterror() : ($this->db->affected_rows($resql) != 1 ? $langs->trans('InstallmentsErrorNotToInvoice') : ''));
		}
		if ($error) {
			$this->db->rollback();
			$this->error = $source->ref.': '.$error;
			return -1;
		}
		$this->db->commit();
		$line->invoiceid = $invoiceid;

		$mode = ($mode < 0 ? $schedule->invoice_mode : $mode);
		if ($mode >= self::INVOICE_VALIDATED) {	// Validated and sent only if the author of the schedule (his rights read again) and the user may
			$author = new User($this->db);
			if ($author->fetch((int) ($schedule->fk_user_creat ?? 0)) > 0) {
				$author->loadRights();
			}
			$allowed = min($this->getInvoiceModeAllowed($user), $author->id > 0 ? $this->getInvoiceModeAllowed($author) : self::INVOICE_DRAFT);	// The user who runs it too
			if ($allowed < $mode) {
				$this->errors[] = $source->ref.': '.$langs->trans('InstallmentsErrorModeNotAllowed');
				$mode = $allowed;
			}
		}
		if ($mode >= self::INVOICE_VALIDATED) {
			$invoice->fetch($invoiceid);
			if ($invoice->validate($user) <= 0) {
				$this->errors[] = $source->ref.': '.$invoice->error;
				return $invoiceid;
			}
			$invoice->fetch($invoiceid);
			if ((!getDolGlobalString('MAIN_DISABLE_PDF_AUTOUPDATE') || $mode == self::INVOICE_SENT)	// Never sent without its PDF
				&& $invoice->generateDocument($invoice->model_pdf ?: getDolGlobalString('FACTURE_ADDON_PDF'), $outputlangs) <= 0) {
				$this->errors[] = $invoice->ref.': '.$invoice->error;
				return $invoiceid;
			}
			if ($mode == self::INVOICE_SENT && $this->sendInvoice($user, $invoice, $schedule->fk_email_template, $outputlangs) < 0) {
				$this->errors[] = $invoice->ref.': '.$this->error;
			}
		}

		return $invoiceid;
	}

	/**
	 * Amounts including tax of invoices by VAT rate and type of line (abandoned invoices left out)
	 *
	 * @param	int[]	$invoiceids		Invoices
	 * @return	array<string,float>|int<-1,-1>	Amounts by "rate|code|type", -1 if error
	 */
	private function getInvoicedByGroup(array $invoiceids)
	{
		$billed = array();
		if (empty($invoiceids)) {
			return $billed;
		}
		$sql = "SELECT fd.tva_tx, fd.vat_src_code, fd.product_type, fd.localtax1_tx, fd.localtax2_tx, SUM(fd.total_ttc) as total FROM ".$this->db->prefix()."facturedet as fd";
		$sql .= " INNER JOIN ".$this->db->prefix()."facture as f ON f.rowid = fd.fk_facture";
		$sql .= " WHERE f.rowid IN (".$this->db->sanitize(implode(',', array_map('intval', $invoiceids))).") AND f.fk_statut <> ".((int) Facture::STATUS_ABANDONED);
		$sql .= " GROUP BY fd.tva_tx, fd.vat_src_code, fd.product_type, fd.localtax1_tx, fd.localtax2_tx";
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		while ($obj = $this->db->fetch_object($resql)) {
			$key = price2num($obj->tva_tx).'|'.$obj->vat_src_code.'|'.((int) $obj->product_type).'|'.price2num($obj->localtax1_tx).'|'.price2num($obj->localtax2_tx);
			$billed[$key] = ($billed[$key] ?? 0.0) + (float) $obj->total;
		}

		return $billed;
	}

	/**
	 * Send a validated invoice by email: its billing contacts, else the email of the customer; PDF attached; event in the agenda
	 *
	 * @param	User		$user			User sending
	 * @param	Facture		$invoice		Validated invoice, with its PDF
	 * @param	int			$templateid		Email template (type facture_send), 0 = the default one
	 * @param	Translate	$outputlangs	Language of the customer
	 * @return	int<-1,1>					1 if sent, -1 if error
	 */
	public function sendInvoice(User $user, Facture $invoice, $templateid, Translate $outputlangs)
	{
		global $langs;

		$invoice->fetch_thirdparty();
		$formmail = new FormMail($this->db);
		$tpl = $formmail->getEMailTemplate($this->db, 'facture_send', $user, $outputlangs, $templateid);
		if (!is_object($tpl) || empty($tpl->id)) {
			$langs->load('errors');
			$this->error = $langs->trans('ErrorFailedToFindEmailTemplate', (string) $templateid);
			return -1;
		}
		$to = array();
		foreach ($invoice->liste_contact(-1, 'external', 0, 'BILLING') as $contact) {
			if (!empty($contact['email']) && !empty($contact['statuscontact'])) {
				$to[] = $contact['email'];
			}
		}
		if (empty($to) && !empty($invoice->thirdparty->email)) {
			$to[] = $invoice->thirdparty->email;
		}
		if (empty($to)) {
			$langs->load('mails');
			$this->error = dolPrintLabel($langs->transnoentitiesnoconv('NoRecipientEmail', $invoice->thirdparty->name));
			return -1;
		}
		$from = ($tpl->email_from ?: getDolGlobalString('MAIN_MAIL_EMAIL_FROM'));
		if (empty($from)) {
			$langs->load('errors');
			$this->error = $langs->trans('ErrorSetupOfEmailsNotComplete');
			return -1;
		}

		$subst = getCommonSubstitutionArray($outputlangs, 0, null, $invoice);
		complete_substitutions_array($subst, $outputlangs, $invoice);
		$content = $outputlangs->transnoentitiesnoconv($tpl->content);
		if (!dol_textishtml($content)) {
			$content = dol_htmlentitiesbr($content);
		}
		$content = make_substitutions($content, $subst, $outputlangs, 1);
		$topic = make_substitutions($tpl->topic ?: $outputlangs->transnoentitiesnoconv('SendBillRef', $invoice->ref), $subst, $outputlangs, 1);
		$files = array();
		$mimes = array();
		$names = array();
		$file = DOL_DATA_ROOT.'/'.$invoice->last_main_doc;
		if (!$invoice->last_main_doc || !dol_is_file($file)) {	// An invoice is never sent without its PDF
			$langs->load('errors');
			$this->error = $langs->trans('ErrorFileNotFound', $invoice->ref.'.pdf');
			return -1;
		}
		$files[] = $file;
		$mimes[] = dol_mimetype($file);
		$names[] = dol_basename($file);
		$to = implode(',', array_unique($to));
		$errorsto = getDolGlobalString('MAIN_MAIL_ERRORS_TO');
		$mail = new CMailFile($topic, $to, $from, $content, $files, $mimes, $names, $tpl->email_tocc, $tpl->email_tobcc, 0, 1, $errorsto, '', 'inv'.$invoice->id, '', 'standard');
		if (!empty($mail->error) || !$mail->sendfile()) {
			$this->error = $mail->error;
			return -1;
		}

		$event = new ActionComm($this->db);
		$event->type_code = 'AC_OTH_AUTO';
		$event->code = 'AC_BILL_SENTBYMAIL';
		$event->label = $langs->transnoentities('InstallmentsEventSent', $invoice->ref);
		$event->note_private = $content;
		$event->socid = $invoice->socid;
		$event->fk_project = (int) $invoice->fk_project;
		$event->elementtype = 'invoice';
		$event->elementid = $invoice->id;
		$event->datep = dol_now();
		$event->datef = $event->datep;
		$event->percentage = -1;
		$event->authorid = $user->id;
		$event->userownerid = $user->id;
		$event->email_msgid = $mail->msgid;
		$event->email_subject = dol_trunc($topic, 250, 'right', 'UTF-8', 1);
		$event->email_from = $from;
		$event->email_to = dol_trunc($to, 250, 'right', 'UTF-8', 1);
		$event->errors_to = $errorsto;
		if ($event->create($user) < 0) {
			dol_syslog(__METHOD__.' '.$invoice->ref.' sent but not recorded: '.$event->error, LOG_ERR);
		}

		return 1;
	}

	/**
	 * Scheduled job: the invoices of the installments due today or before, from the first run of the job on (the older ones are invoiced
	 * by hand), never an installment to check. One error does not stop the others.
	 *
	 * @return	int		0 if OK, 1 if some installments failed
	 */
	public function createDueInvoices()
	{
		global $conf, $langs, $user;

		$langs->loadLangs(array('bills', 'installments@installments'));
		$this->output = '';
		$this->errors = array();
		$today = dol_get_first_hour(dol_now());
		if (!getDolGlobalInt('INSTALLMENTS_START_DATE')) {
			require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
			dolibarr_set_const($this->db, 'INSTALLMENTS_START_DATE', (string) $today, 'chaine', 0, '', $conf->entity);
		}
		$start = getDolGlobalInt('INSTALLMENTS_START_DATE', $today);

		$sql = "SELECT l.rowid, s.element, s.fk_element FROM ".$this->db->prefix()."installments_line as l";
		$sql .= " INNER JOIN ".$this->db->prefix()."installments_schedule as s ON s.rowid = l.fk_schedule";
		$sql .= " LEFT JOIN ".$this->db->prefix()."propal as p ON s.element = 'propal' AND p.rowid = s.fk_element";
		$sql .= " LEFT JOIN ".$this->db->prefix()."commande as c ON s.element = 'commande' AND c.rowid = s.fk_element";
		$sql .= " WHERE s.entity IN (".getEntity('installments').") AND s.billing = 1 AND l.status = ".self::STATUS_OPEN." AND l.fk_facture IS NULL";
		$sql .= " AND (p.fk_statut IN (".((int) Propal::STATUS_SIGNED).", ".((int) Propal::STATUS_BILLED).") OR c.fk_statut >= ".((int) Commande::STATUS_VALIDATED).")";	// Like isBillable()
		$sql .= " AND l.date_due <= '".$this->db->idate($today)."' AND l.date_due >= '".$this->db->idate(dol_get_first_hour($start))."'";
		$sql .= $this->db->order('l.date_due,l.rowid', 'ASC,ASC');
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			$this->output = $this->error;
			return 1;
		}
		$todo = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$todo[] = $obj;
		}
		$nb = 0;
		foreach ($todo as $obj) {	// The installments due today: one invoice each, its queries can not be grouped
			$schedule = $this->fetch((string) $obj->element, (int) $obj->fk_element);	// Read again: an installment invoiced meanwhile is skipped
			$line = null;
			foreach (is_object($schedule) ? $schedule->lines : array() as $candidate) {
				if ($candidate->id == $obj->rowid) {
					$line = $candidate;
				}
			}
			if (!$line || $line->invoiceid || $line->status != self::STATUS_OPEN) {
				continue;
			}
			if ($this->createInvoice($user, $schedule, $line) > 0) {
				$nb++;
			} else {
				$this->errors[] = $this->error;
			}
		}
		$this->output = $langs->trans('InstallmentsInvoicesCreated', $nb).(empty($this->errors) ? '' : "\n".implode("\n", $this->errors));

		return (empty($this->errors) ? 0 : 1);
	}

	/**
	 * Scheduled job: a direct debit request for each unpaid installment of an invoice paid by direct debit, some days before its date.
	 * The core keeps one pending request per invoice unless WITHDRAW_STRICT_CHECK_AMOUNT is set: the next installment waits for the next run.
	 *
	 * @return	int		0 if OK, 1 if some invoices failed
	 */
	public function createDirectDebits()
	{
		global $langs, $user;

		$langs->loadLangs(array('bills', 'withdrawals', 'installments@installments'));
		$this->output = '';
		$this->errors = array();
		$limit = dol_time_plus_duree(dol_get_first_hour(dol_now()), getDolGlobalInt('INSTALLMENTS_DIRECTDEBIT_DAYS', 5), 'd');
		$modeid = (int) dol_getIdFromCode($this->db, 'PRE', 'c_paiement', 'code', 'id', 1);
		if ($modeid <= 0) {	// No mode "direct debit" in the dictionary
			return 0;
		}

		$sql = "SELECT DISTINCT s.fk_element FROM ".$this->db->prefix()."installments_line as l";
		$sql .= " INNER JOIN ".$this->db->prefix()."installments_schedule as s ON s.rowid = l.fk_schedule AND s.element = 'facture'";
		$sql .= " INNER JOIN ".$this->db->prefix()."facture as f ON f.rowid = s.fk_element";
		$sql .= " WHERE s.entity IN (".getEntity('installments').") AND l.status = ".self::STATUS_OPEN." AND l.date_due <= '".$this->db->idate($limit)."'";
		$sql .= " AND (l.fk_mode_reglement = ".((int) $modeid)." OR (l.fk_mode_reglement = 0 AND f.fk_mode_reglement = ".((int) $modeid)."))";
		$sql .= " AND f.fk_statut = ".((int) Facture::STATUS_VALIDATED)." AND f.paye = 0";
		$sql .= " AND NOT EXISTS (SELECT pd.rowid FROM ".$this->db->prefix()."prelevement_demande as pd WHERE pd.rowid = l.fk_prelevement_demande)";
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			$this->output = $this->error;
			return 1;
		}
		$invoiceids = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$invoiceids[] = (int) $obj->fk_element;
		}
		$nb = 0;
		foreach ($invoiceids as $invoiceid) {
			$invoice = new Facture($this->db);
			$schedule = $this->fetch('facture', $invoiceid);
			if ($invoice->fetch($invoiceid) <= 0 || !is_object($schedule)) {
				$this->errors[] = $invoiceid.': '.($invoice->error ?: $this->error);
				continue;
			}
			$paid = $this->allocate($invoice, $schedule->lines);
			foreach ($schedule->lines as $line) {
				$remain = round($line->amount - $paid[$line->id], $this->decimals);
				if ($line->status != self::STATUS_OPEN || $line->date > $limit || $remain <= 0 || ($line->mode ?: (int) $invoice->mode_reglement_id) != $modeid || $this->debitExists($line->debitid)) {
					continue;
				}
				$this->db->begin();	// The request and its installment together
				$before = (int) $invoice->mode_reglement_id;
				$result = $invoice->demande_prelevement($user, (float) $remain);
				if ($result <= 0) {	// 0: a request of this invoice is pending
					$this->db->rollback();
					if ($result < 0) {
						$this->errors[] = $invoice->ref.': '.$invoice->error;
					}
					break;
				}
				$sql = "UPDATE ".$this->db->prefix()."installments_line SET fk_prelevement_demande = (SELECT MAX(pd.rowid) FROM ".$this->db->prefix()."prelevement_demande as pd";
				$sql .= " WHERE pd.fk_facture = ".((int) $invoice->id)." AND pd.sourcetype = 'facture') WHERE rowid = ".((int) $line->id);
				// The core sets the mode of the invoice to direct debit: the other installments keep the mode they had from the invoice
				$sqlmode = "UPDATE ".$this->db->prefix()."installments_line SET fk_mode_reglement = ".((int) $before);
				$sqlmode .= " WHERE fk_schedule = ".((int) $schedule->id)." AND fk_mode_reglement = 0";
				if (!$this->db->query($sql) || ($before != $modeid && $before > 0 && !$this->db->query($sqlmode))) {
					$this->errors[] = $invoice->ref.': '.$this->db->lasterror();
					$this->db->rollback();
					break;
				}
				$this->db->commit();
				$nb++;
			}
		}
		$this->output = $langs->trans('InstallmentsDebitsCreated', $nb).(empty($this->errors) ? '' : "\n".implode("\n", $this->errors));

		return (empty($this->errors) ? 0 : 1);
	}

	/**
	 * Whether a direct debit request still exists (a request deleted by a user is asked again)
	 *
	 * @param	int		$debitid	Request
	 * @return	bool				True if it exists
	 */
	private function debitExists($debitid)
	{
		if ($debitid <= 0) {
			return false;
		}
		$resql = $this->db->query("SELECT rowid FROM ".$this->db->prefix()."prelevement_demande WHERE rowid = ".((int) $debitid));

		return ($resql && $this->db->num_rows($resql) > 0);
	}

	/**
	 * Record the payment of installments of invoices: one payment per invoice for what is left to pay on its selected installments.
	 * An installment already paid is skipped, so a second run records nothing.
	 *
	 * @param	User	$user		User recording
	 * @param	int[]	$lineids	Installments
	 * @param	int		$datepaye	Date of the payment
	 * @param	int		$modeid		Payment mode (llx_c_paiement)
	 * @param	int		$accountid	Bank account, 0 if the module Banks is off
	 * @return	int					Number of payments recorded, <0 if error
	 */
	public function recordPayments(User $user, array $lineids, $datepaye, $modeid, $accountid)
	{
		global $langs;

		$langs->loadLangs(array('bills', 'banks', 'installments@installments'));
		$this->errors = array();
		if (!$user->hasRight('facture', 'paiement') || empty($lineids)) {
			$this->error = $langs->trans('NotEnoughPermissions');
			return -1;
		}
		$sql = "SELECT id FROM ".$this->db->prefix()."c_paiement WHERE id = ".((int) $modeid)." AND active = 1 AND entity IN (".getEntity('c_paiement').")";
		$resql = $this->db->query($sql);
		if (!$resql || !$this->db->num_rows($resql)) {
			$this->error = $langs->trans('ErrorFieldRequired', $langs->transnoentitiesnoconv('PaymentMode'));
			return -1;
		}
		if (isModEnabled('bank')) {	// An open account of the entity
			require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';
			$account = new Account($this->db);
			if ($accountid <= 0 || $account->fetch($accountid) <= 0 || (int) $account->status != Account::STATUS_OPEN || !in_array((int) $account->entity, array_map('intval', explode(',', getEntity('bank_account'))))) {
				$this->error = $langs->trans('ErrorFieldRequired', $langs->transnoentitiesnoconv('AccountToCredit'));
				return -1;
			}
		}
		$sql = "SELECT l.rowid, s.fk_element FROM ".$this->db->prefix()."installments_line as l";
		$sql .= " INNER JOIN ".$this->db->prefix()."installments_schedule as s ON s.rowid = l.fk_schedule AND s.element = 'facture'";
		$sql .= " INNER JOIN ".$this->db->prefix()."facture as f ON f.rowid = s.fk_element AND f.entity IN (".getEntity('invoice').")";
		$sql .= " WHERE s.entity IN (".getEntity('installments').") AND l.rowid IN (".$this->db->sanitize(implode(',', array_map('intval', $lineids))).")";
		if (!$user->hasRight('societe', 'client', 'voir')) {	// Like the list: a sales representative only pays the invoices of its customers
			$sql .= " AND EXISTS (SELECT sc.fk_soc FROM ".$this->db->prefix()."societe_commerciaux as sc WHERE sc.fk_soc = f.fk_soc AND sc.fk_user = ".((int) $user->id).")";
		}
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$byinvoice = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$byinvoice[(int) $obj->fk_element][] = (int) $obj->rowid;
		}
		$nb = 0;
		$code = dol_getIdFromCode($this->db, $modeid, 'c_paiement', 'id', 'code', 1);
		foreach ($byinvoice as $invoiceid => $ids) {
			$invoice = new Facture($this->db);
			$schedule = $this->fetch('facture', $invoiceid);
			if ($invoice->fetch($invoiceid) <= 0 || !is_object($schedule) || $invoice->status != Facture::STATUS_VALIDATED || $invoice->paye) {
				continue;
			}
			$paid = $this->allocate($invoice, $schedule->lines);
			$amount = 0.0;
			foreach ($schedule->lines as $line) {
				if (in_array($line->id, $ids) && $line->status != self::STATUS_ABANDONED) {
					$amount += $line->amount - $paid[$line->id];
				}
			}
			$amount = (float) price2num($amount, 'MT');
			if ($amount <= 0) {
				continue;
			}
			$this->db->begin();
			$payment = new Paiement($this->db);
			$payment->datepaye = $datepaye;
			$payment->amounts = array($invoice->id => $amount);
			$payment->paiementid = $modeid;
			$payment->paiementcode = (string) $code;
			$payment->num_payment = '';
			$payment->note_private = $langs->transnoentities('InstallmentsBulkPayment');
			$payment->fk_account = $accountid;
			$result = $payment->create($user, 1);
			if ($result > 0 && isModEnabled('bank')) {
				$result = $payment->addPaymentToBank($user, 'payment', '(CustomerInvoicePayment)', $accountid, '', '');
			}
			if ($result < 0) {
				$this->db->rollback();
				$this->errors[] = $invoice->ref.': '.$payment->error;
				continue;
			}
			$this->db->commit();
			$nb++;
		}

		return $nb;
	}

	/**
	 * Attach an installment of a billing schedule to an invoice made by hand, or abandon it
	 *
	 * @param	User	$user		User
	 * @param	int		$lineid		Installment
	 * @param	int		$invoiceid	Invoice of the same customer, 0 to abandon the installment
	 * @return	int<-1,1>			1 if OK, -1 if error
	 */
	public function settle(User $user, $lineid, $invoiceid)
	{
		global $langs;

		$langs->load('installments@installments');
		$sql = "SELECT l.rowid, l.fk_facture, s.element, s.fk_element, s.billing FROM ".$this->db->prefix()."installments_line as l";
		$sql .= " INNER JOIN ".$this->db->prefix()."installments_schedule as s ON s.rowid = l.fk_schedule";
		$sql .= " WHERE s.entity IN (".getEntity('installments').") AND l.rowid = ".((int) $lineid);
		$resql = $this->db->query($sql);
		$obj = ($resql ? $this->db->fetch_object($resql) : null);
		if (!$obj || !$user->hasRight('installments', 'write') || !$obj->billing || !empty($obj->fk_facture)) {	// Installments to invoice only
			$this->error = ($resql ? $langs->trans('InstallmentsErrorNotToInvoice') : $this->db->lasterror());
			return -1;
		}
		if ($invoiceid > 0) {
			$class = self::ELEMENTS[$obj->element];
			$source = new $class($this->db);
			$invoice = new Facture($this->db);
			$sqlused = "SELECT COUNT(rowid) as nb FROM ".$this->db->prefix()."installments_line WHERE fk_facture = ".((int) $invoiceid);
			$resused = $this->db->query($sqlused);
			$used = ($resused ? $this->db->fetch_object($resused) : null);
			if ($source->fetch((int) $obj->fk_element) <= 0 || $invoice->fetch($invoiceid) <= 0 || $invoice->socid != $source->socid
				|| !in_array((int) $invoice->entity, array_map('intval', explode(',', getEntity('invoice'))))
				|| in_array((int) $invoice->status, array(Facture::STATUS_DRAFT, Facture::STATUS_ABANDONED)) || $invoice->type == Facture::TYPE_CREDIT_NOTE || !$used || $used->nb > 0) {	// One installment per invoice
				$this->error = $langs->trans('InstallmentsErrorInvoice');
				return -1;
			}
			$sql = "UPDATE ".$this->db->prefix()."installments_line SET fk_facture = ".((int) $invoiceid).", status = ".self::STATUS_OPEN." WHERE rowid = ".((int) $lineid);
		} else {
			$sql = "UPDATE ".$this->db->prefix()."installments_line SET status = ".self::STATUS_ABANDONED." WHERE rowid = ".((int) $lineid)." AND fk_facture IS NULL";
		}
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}

		return 1;
	}

	/**
	 * Whether the reminder job of the core is enabled: it sends a reminder for each installment with the whole rest to pay,
	 * the module Reminders reminds an installment for its own amount.
	 *
	 * @return	bool	True if enabled (or unknown)
	 */
	public function isCoreReminderJobEnabled()
	{
		$sql = "SELECT COUNT(rowid) as nb FROM ".$this->db->prefix()."cronjob";
		$sql .= " WHERE entity IN (0, ".getEntity('cron').") AND objectname = 'Facture' AND methodename = 'sendEmailsRemindersOnInvoiceDueDate' AND status = 1";
		$resql = $this->db->query($sql);
		if (!$resql) {
			return true;
		}
		$obj = $this->db->fetch_object($resql);

		return ($obj && $obj->nb > 0);
	}

	/**
	 * Whether the tables of the module Echeancier are there
	 *
	 * @return	bool	True if <prefix>echeancier exists
	 */
	public function hasOldTables()
	{
		$tables = $this->db->DDLListTables($this->db->database_name, $this->db->prefix().'echeancier');

		return (is_array($tables) && in_array($this->db->prefix().'echeancier', $tables));
	}

	/**
	 * Schedules of the module Echeancier (Artis Auxilium) to import: its tables <prefix>echeancier and <prefix>echeance.
	 * Expected payments of an invoice keep their dates and amounts (what is paid is given again by date); installments of
	 * proposals and orders invoiced by the old module keep their invoice; the past ones without invoice are to check, never invoiced
	 * by the job. Lines without amount (payments recorded by the old module) are left out. The old tables are only read.
	 *
	 * @param	User	$user		User importing
	 * @param	bool	$dryrun		True to only count
	 * @return	array{schedules:int,lines:int,tocheck:int,skipped:string[],different:string[]}|int<-1,-1>	Report, -1 if error
	 */
	public function import(User $user, $dryrun = true)
	{
		global $conf, $langs;

		$langs->load('installments@installments');
		if (!$user->admin) {
			$this->error = $langs->trans('NotEnoughPermissions');
			return -1;
		}
		$report = array('schedules' => 0, 'lines' => 0, 'tocheck' => 0, 'matched' => array(), 'skipped' => array(), 'different' => array());
		if (!$this->hasOldTables()) {
			return $report;
		}
		$types = array(1 => 'propal', 2 => 'facture', 3 => 'commande');
		$used = array();	// Invoices of an installment, each one used once: the ones the module Echeancier made first
		$resql = $this->db->query("SELECT invoice FROM ".$this->db->prefix()."echeance WHERE invoice > 0");
		while ($resql && ($obj = $this->db->fetch_object($resql))) {
			$used[(int) $obj->invoice] = 1;
		}
		$resql = $this->db->query("SELECT * FROM ".$this->db->prefix()."echeancier ORDER BY id");
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$olds = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$olds[] = $obj;
		}
		if (!$dryrun) {
			$this->db->begin();
		}
		foreach ($olds as $old) {	// Run once, at the import: a few queries per old schedule
			$element = $types[(int) $old->type] ?? '';
			$label = 'ech'.$old->id;
			if (!$element) {
				$report['skipped'][] = $label.': '.$langs->trans('InstallmentsImportUnknownType');
				continue;
			}
			$class = self::ELEMENTS[$element];
			$object = new $class($this->db);
			if ($object->fetch((int) $old->id_element) <= 0) {
				$report['skipped'][] = $label.': '.$langs->trans('InstallmentsImportNoDocument');
				continue;
			}
			$existing = $this->fetch($element, (int) $object->id);
			if ($existing !== 0) {	// Already imported, or a schedule made with this module
				if (is_object($existing)) {
					$report['skipped'][] = $object->ref.': '.$langs->trans('InstallmentsImportExists');
				}
				continue;
			}
			$billing = ($element != 'facture' && !empty($old->invoice) ? 1 : 0);
			$candidates = ($billing ? $this->getImportCandidates($object) : array());
			if (!is_array($candidates)) {
				if (!$dryrun) {
					$this->db->rollback();
				}
				return -1;
			}
			$lines = array();
			$total = 0.0;
			$resline = $this->db->query("SELECT * FROM ".$this->db->prefix()."echeance WHERE fk_echeancier = ".((int) $old->id)." AND montant > 0 ORDER BY date_reglement, rowid");
			if (!$resline) {
				$this->error = $this->db->lasterror();
				if (!$dryrun) {
					$this->db->rollback();
				}
				return -1;
			}
			$pairs = array();
			while ($oldline = $this->db->fetch_object($resline)) {
				$date = (int) $this->db->jdate($oldline->date_reglement);
				$invoiceid = ($billing && !empty($oldline->invoice) ? (int) $oldline->invoice : 0);
				$lines[] = array('date' => $date, 'amount' => (float) $oldline->montant, 'invoiceid' => $invoiceid, 'status' => self::STATUS_OPEN, 'note' => (string) $oldline->note,
					'key' => 'ech'.$oldline->rowid);
				$total += (float) $oldline->montant;
				foreach ($billing && !$invoiceid ? $candidates : array() as $candidate) {	// Invoiced by hand? Same amount, within 45 days
					if (abs($candidate->total - (float) $oldline->montant) <= 0.01 && abs($candidate->date - $date) <= 45 * 86400) {
						$pairs[] = array(count($lines) - 1, $candidate, abs($candidate->date - $date));
					}
				}
			}
			usort($pairs, 'installmentsComparePairs');	// The best pairs first: an invoice linked to the document, then the closest date
			foreach ($pairs as $pair) {
				list($i, $candidate) = $pair;
				if (!$lines[$i]['invoiceid'] && !isset($used[$candidate->id])) {
					$lines[$i]['invoiceid'] = $candidate->id;
					$used[$candidate->id] = 1;
					$report['matched'][] = $object->ref.' '.dol_print_date($lines[$i]['date'], 'day').' '.price($lines[$i]['amount'], 0, $langs, 1, -1, -1, $conf->currency).' → '.$candidate->ref;
				}
			}
			foreach ($lines as $i => $line) {	// Never invoiced by the job: past or future, to check by hand
				if ($billing && !$line['invoiceid']) {
					$lines[$i]['status'] = self::STATUS_TOCHECK;
					$report['tocheck']++;
				}
			}
			if (empty($lines)) {
				$report['skipped'][] = $object->ref.': '.$langs->trans('InstallmentsImportNoLine');
				continue;
			}
			if (round($total - (float) $object->total_ttc, $this->decimals) != 0) {
				$report['different'][] = $object->ref.': '.price($total, 0, $langs, 1, -1, -1, $conf->currency).' / '.price((float) $object->total_ttc, 0, $langs, 1, -1, -1, $conf->currency);
			}
			$report['schedules']++;
			$report['lines'] += count($lines);
			if ($dryrun) {
				continue;
			}
			$sql = "INSERT INTO ".$this->db->prefix()."installments_schedule (entity, element, fk_element, billing, invoice_mode, fk_email_template, date_creation, fk_user_creat, import_key)";
			$sql .= " VALUES (".((int) $object->entity).", '".$this->db->escape($element)."', ".((int) $object->id).", ".((int) $billing).", 0, 0, '".$this->db->idate(dol_now())."'";
			$sql .= ", ".((int) $user->id).", '".$this->db->escape($label)."')";
			$ok = (bool) $this->db->query($sql);
			$id = ($ok ? (int) $this->db->last_insert_id($this->db->prefix()."installments_schedule") : 0);
			foreach ($lines as $rang => $line) {
				$ok = $ok && $this->insertLine($id, $rang + 1, $line['date'], $line['amount'], (int) $object->mode_reglement_id, $line['note'], $line['status'], $line['invoiceid'], $line['key']);
			}
			if (!$ok) {
				$this->error = $object->ref.': '.$this->db->lasterror();
				$this->db->rollback();
				return -1;
			}
		}
		if (!$dryrun) {
			$this->db->commit();
			foreach ($olds as $old) {	// The due dates of the invoices: their next unpaid installment instead of the last one
				$invoice = new Facture($this->db);
				if ((int) $old->type == 2 && $invoice->fetch((int) $old->id_element) > 0) {
					$this->syncDueDate($invoice);
				}
			}
		}

		return $report;
	}

	/**
	 * Invoices that may have been made by hand for the installments of a proposal or an order: validated standard invoices of its
	 * customer not linked to an installment yet, the ones linked to the document marked
	 *
	 * @param	CommonObject	$object		Proposal or order
	 * @return	array<int,stdClass>|int<-1,-1>	Invoices (id, ref, date, total, linked), -1 if error
	 */
	private function getImportCandidates(CommonObject $object)
	{
		'@phan-var-force Propal|Commande $object';
		$sql = "SELECT f.rowid, f.ref, f.datef, f.total_ttc, (SELECT COUNT(ee.rowid) FROM ".$this->db->prefix()."element_element as ee";
		$sql .= " WHERE (ee.sourcetype = '".$this->db->escape($object->element)."' AND ee.fk_source = ".((int) $object->id)." AND ee.targettype = 'facture' AND ee.fk_target = f.rowid)";
		$sql .= " OR (ee.targettype = '".$this->db->escape($object->element)."' AND ee.fk_target = ".((int) $object->id)." AND ee.sourcetype = 'facture' AND ee.fk_source = f.rowid)) as linked";
		$sql .= " FROM ".$this->db->prefix()."facture as f";
		$sql .= " WHERE f.fk_soc = ".((int) $object->socid)." AND f.entity IN (".getEntity('invoice').") AND f.type = ".((int) Facture::TYPE_STANDARD);
		$sql .= " AND f.fk_statut IN (".((int) Facture::STATUS_VALIDATED).", ".((int) Facture::STATUS_CLOSED).")";
		$sql .= " AND NOT EXISTS (SELECT l.rowid FROM ".$this->db->prefix()."installments_line as l WHERE l.fk_facture = f.rowid)";
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$candidates = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$candidates[] = (object) array('id' => (int) $obj->rowid, 'ref' => (string) $obj->ref, 'date' => (int) $this->db->jdate($obj->datef),
				'total' => (float) $obj->total_ttc, 'linked' => (int) ($obj->linked > 0));
		}

		return $candidates;
	}
}


/**
 * Order of the pairs installment - invoice of the import: an invoice linked to the document first, then the closest date
 *
 * @param	array{0:int,1:stdClass,2:int}	$a	Pair (installment, invoice, gap in seconds)
 * @param	array{0:int,1:stdClass,2:int}	$b	Pair
 * @return	int									Negative if $a first, positive if $b first, 0 if equal
 */
function installmentsComparePairs($a, $b)
{
	return ($b[1]->linked <=> $a[1]->linked) ?: ($a[2] <=> $b[2]);
}
