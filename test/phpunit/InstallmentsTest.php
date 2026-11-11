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
 * \file    installments/test/phpunit/InstallmentsTest.php
 * \ingroup installments
 * \brief   PHPUnit test for the module InstallmentSchedule
 */

global $conf, $user, $langs, $db;
// The module is in htdocs/custom/installments: the tests of Dolibarr are 5 levels up
require_once dirname(__FILE__).'/../../../../../test/phpunit/CommonClassTest.class.php';
require_once dirname(__FILE__).'/../../core/modules/modInstallments.class.php';
require_once dirname(__FILE__).'/../../class/installmentschedule.class.php';
require_once dirname(__FILE__).'/../../class/actions_installments.class.php';
require_once dirname(__FILE__).'/../../core/substitutions/functions_installments.lib.php';
require_once dirname(__FILE__).'/../../core/triggers/interface_99_modInstallments_InstallmentsTriggers.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/pdf.lib.php';

if (empty($user->id)) {
	print "Load permissions for admin user nb 1\n";
	$user->fetch(1);
	$user->loadRights();
}

$conf->global->MAIN_DISABLE_ALL_MAILS = 1;


/**
 * Import from temporary tables: the tables of the module Echeancier are never created for real in the database of the tests
 */
class InstallmentsOldTables extends InstallmentSchedule
{
	/**
	 * The temporary tables of the test stand for the tables of the module Echeancier
	 *
	 * @return	bool	True
	 */
	public function hasOldTables()
	{
		return true;
	}
}


/**
 * Class InstallmentsTest
 *
 * @backupGlobals disabled
 * @backupStaticAttributes enabled
 * @remarks	backupGlobals must be disabled to have db,conf,user and lang not erased.
 */
class InstallmentsTest extends CommonClassTest
{
	/**
	 * @var bool True if this class enabled the module
	 */
	private static $activated = false;

	/**
	 * @var ?HookManager Hook manager replaced by captureMails()
	 */
	private $savhookmanager = null;

	/**
	 * @var string Sender replaced by captureMails()
	 */
	private $savmailfrom = '';

	/**
	 * @var ?string Module BlockedLog, off in memory during a test: the demo database has not its key, its trigger refuses payments and validations
	 */
	private $savblockedlog = null;


	/**
	 * Enable the module (creation of its tables, so outside the transaction of the tests) and load its rights
	 *
	 * @return void
	 */
	public static function setUpBeforeClass(): void
	{
		global $db, $conf, $user;

		if (!isModEnabled('installments')) {
			require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
			$result = activateModule('modInstallments');
			self::assertEmpty($result['errors'], 'Failed to activate module installments: '.implode(', ', $result['errors']));
			$conf->setValues($db);
			$conf->global->MAIN_DISABLE_ALL_MAILS = 1;	// Reset by setValues()
			self::$activated = true;
		}
		$user->loadRights('', 1);
		self::assertTrue(isModEnabled('installments'), 'module installments must be enabled');

		parent::setUpBeforeClass();
	}

	/**
	 * Disable the module if this class enabled it
	 *
	 * @return void
	 */
	public static function tearDownAfterClass(): void
	{
		global $db, $conf;

		parent::tearDownAfterClass();
		if (self::$activated) {
			unActivateModule('modInstallments');
			$conf->setValues($db);
			$conf->global->MAIN_DISABLE_ALL_MAILS = 1;
		}
	}

	/**
	 * No PDF written by the payments and validations of the tests
	 *
	 * @return void
	 */
	protected function setUp(): void
	{
		global $conf;

		parent::setUp();
		$conf->global->MAIN_DISABLE_PDF_AUTOUPDATE = 1;
		if (isset($conf->modules['blockedlog'])) {
			$this->savblockedlog = $conf->modules['blockedlog'];
			unset($conf->modules['blockedlog']);
		}
	}

	/**
	 * Restore what a test changed in memory and delete what it created, even if it failed: the next test starts from a clean state
	 *
	 * @return void
	 */
	protected function tearDown(): void
	{
		global $conf, $hookmanager;

		$db = $this->savdb;
		if ($this->savhookmanager) {
			$hookmanager = $this->savhookmanager;
			$conf->global->MAIN_MAIL_EMAIL_FROM = $this->savmailfrom;
		}
		$conf->global->MAIN_DISABLE_ALL_MAILS = 1;
		if ($this->savblockedlog !== null) {
			$conf->modules['blockedlog'] = $this->savblockedlog;
		}
		unset($conf->global->MAIN_DISABLE_PDF_AUTOUPDATE, $conf->global->INSTALLMENTS_START_DATE, $conf->global->WITHDRAW_STRICT_CHECK_AMOUNT);

		$socs = "SELECT rowid FROM ".$db->prefix()."societe WHERE nom LIKE 'PSTEST%'";
		$resql = $db->query("SELECT ref FROM ".$db->prefix()."facture WHERE fk_soc IN (".$socs.")");
		while ($resql && ($obj = $db->fetch_object($resql))) {	// The PDF of the invoices sent by email
			if (dol_sanitizeFileName((string) $obj->ref) !== '') {
				dol_delete_dir_recursive($conf->facture->dir_output.'/'.dol_sanitizeFileName((string) $obj->ref));
			}
		}
		$invoices = "SELECT rowid FROM ".$db->prefix()."facture WHERE fk_soc IN (".$socs.")";
		$payments = array(0);	// Read first: the payments are deleted after their lines
		$resql = $db->query("SELECT fk_paiement FROM ".$db->prefix()."paiement_facture WHERE fk_facture IN (".$invoices.")");
		while ($resql && ($obj = $db->fetch_object($resql))) {
			$payments[] = (int) $obj->fk_paiement;
		}
		$payments = implode(',', $payments);
		$schedules = "SELECT rowid FROM ".$db->prefix()."installments_schedule WHERE (element = 'facture' AND fk_element IN (".$invoices."))";
		$schedules .= " OR (element = 'propal' AND fk_element IN (SELECT rowid FROM ".$db->prefix()."propal WHERE fk_soc IN (".$socs.")))";
		$schedules .= " OR (element = 'commande' AND fk_element IN (SELECT rowid FROM ".$db->prefix()."commande WHERE fk_soc IN (".$socs.")))";
		$sqls = array(
			"DELETE FROM ".$db->prefix()."installments_line WHERE fk_schedule IN (".$schedules.")",
			"DELETE FROM ".$db->prefix()."installments_schedule WHERE rowid IN (SELECT rowid FROM (".$schedules.") as s)",
			"DELETE FROM ".$db->prefix()."actioncomm_resources WHERE fk_actioncomm IN (SELECT id FROM ".$db->prefix()."actioncomm WHERE fk_soc IN (".$socs."))",
			"DELETE FROM ".$db->prefix()."actioncomm WHERE fk_soc IN (".$socs.")",
			"DELETE FROM ".$db->prefix()."paiement_facture WHERE fk_facture IN (".$invoices.")",
			"DELETE FROM ".$db->prefix()."bank_url WHERE type = 'payment' AND url_id IN (".$payments.")",
			"DELETE FROM ".$db->prefix()."bank WHERE rowid IN (SELECT fk_bank FROM ".$db->prefix()."paiement WHERE rowid IN (".$payments."))",
			"DELETE FROM ".$db->prefix()."paiement WHERE rowid IN (".$payments.")",
			"DELETE FROM ".$db->prefix()."prelevement_demande WHERE fk_facture IN (".$invoices.")",
			"DELETE FROM ".$db->prefix()."prelevement_bons WHERE ref LIKE 'PSTEST%'",
			"DELETE FROM ".$db->prefix()."societe_remise_except WHERE fk_soc IN (".$socs.")",
			"DELETE FROM ".$db->prefix()."element_element WHERE (sourcetype IN ('propal', 'commande') AND targettype = 'facture' AND fk_target IN (".$invoices."))",
			"DELETE FROM ".$db->prefix()."facturedet WHERE fk_facture IN (".$invoices.")",
			"DELETE FROM ".$db->prefix()."facture WHERE fk_soc IN (".$socs.")",
			"DELETE FROM ".$db->prefix()."propaldet WHERE fk_propal IN (SELECT rowid FROM ".$db->prefix()."propal WHERE fk_soc IN (".$socs."))",
			"DELETE FROM ".$db->prefix()."propal WHERE fk_soc IN (".$socs.")",
			"DELETE FROM ".$db->prefix()."commandedet WHERE fk_commande IN (SELECT rowid FROM ".$db->prefix()."commande WHERE fk_soc IN (".$socs."))",
			"DELETE FROM ".$db->prefix()."commande WHERE fk_soc IN (".$socs.")",
			"DELETE FROM ".$db->prefix()."societe_rib WHERE fk_soc IN (".$socs.")",
			"DELETE FROM ".$db->prefix()."societe_account WHERE fk_soc IN (".$socs.")",	// Made by develop for the online payment keys
			"DELETE FROM ".$db->prefix()."societe WHERE nom LIKE 'PSTEST%'",
			"DELETE FROM ".$db->prefix()."c_email_templates WHERE label LIKE 'PSTEST%'",
			"DELETE FROM ".$db->prefix()."c_installments_template WHERE code LIKE 'PSTEST%'",
			"DELETE FROM ".$db->prefix()."user_rights WHERE fk_user IN (SELECT rowid FROM ".$db->prefix()."user WHERE login LIKE 'pstest%')",
			"DELETE FROM ".$db->prefix()."user WHERE login LIKE 'pstest%'",
			"DELETE FROM ".$db->prefix()."const WHERE name = 'INSTALLMENTS_START_DATE'",
		);
		foreach ($sqls as $sql) {
			$this->assertTrue((bool) $db->query($sql), (string) $db->lasterror());
		}

		parent::tearDown();
	}

	/**
	 * Version, number, tables and templates created when enabled, jobs disabled, date of the first run deleted with the module
	 *
	 * @return void
	 */
	public function testInstallmentsDescriptor()
	{
		global $db;
		$db = $this->savdb;

		$module = new modInstallments($db);

		$this->assertSame(array('1.1.0', 231255, 'Guiltek', array(8, 1), 1, 1), array($module->version, $module->numero, $module->editor_name, $module->phpmin, $module->module_parts['triggers'], $module->module_parts['substitutions']));
		$this->assertSame(array('23125501', '23125502'), array($module->rights[0][0], $module->rights[1][0]), 'Not the rights 231251 and 231252 of the module Reminders');
		$this->assertSame(array(0, 0), array($module->cronjobs[0]['status'], $module->cronjobs[1]['status']));
		$this->assertSame(array('INSTALLMENTS_START_DATE', 1), array($module->const[0][0], $module->const[0][6]), 'A module enabled again does not invoice the past');
		foreach (array('installments_schedule', 'installments_line') as $table) {
			$this->assertTrue((bool) $db->query("SELECT rowid FROM ".$db->prefix().$table), 'Enabling the module creates its table '.$table.': '.$db->lasterror());
		}
		$installments = new InstallmentSchedule($db);
		$templates = $installments->fetchTemplates();
		$this->assertSame(array('3X', '30-70', '12M'), array_values(array_column($templates, 'code')), 'The templates ready to use');
		$this->assertSame(1, $installments->initTemplates());
		$this->assertCount(3, $installments->fetchTemplates(), 'Not added twice');
	}

	/**
	 * Equal installments with the rounding on the last one, a first one with its own share, dates by months and days
	 *
	 * @return void
	 */
	public function testInstallmentsGenerate()
	{
		$installments = new InstallmentSchedule($this->savdb);
		$first = dol_mktime(12, 0, 0, 1, 31, 2026, 'tzserver');

		$lines = $installments->generate(100.0, 3, 1, 0, $first);
		$this->assertSame(array(33.33, 33.33, 33.34), array_column($lines, 'amount'));
		$this->assertSame(array('2026-01-31', '2026-02-28', '2026-03-31'), array_map('installmentsTestDay', array_column($lines, 'date')), 'Month after month, end of month kept');

		$lines = $installments->generate(1000.0, 2, 0, 30, $first, 30.0, 7);
		$this->assertSame(array(300.0, 700.0), array_column($lines, 'amount'));
		$this->assertSame(array('2026-01-31', '2026-03-02'), array_map('installmentsTestDay', array_column($lines, 'date')));
		$this->assertSame(array(7, 7), array_column($lines, 'mode'));

		$this->assertSame(array(55.0), array_column($installments->generate(55.0, 0, 1, 0, $first), 'amount'), 'At least one installment');
		$this->assertCount(InstallmentSchedule::MAX_INSTALLMENTS, $installments->generate(55.0, 1000000, 1, 0, $first), 'At most 120');
	}

	/**
	 * Free installments of a template (percent:days[:eom]), the last one taking the rounding; a wrong rule gives nothing and says why
	 *
	 * @return void
	 */
	public function testInstallmentsSplitRule()
	{
		$installments = new InstallmentSchedule($this->savdb);
		$first = dol_mktime(12, 0, 0, 1, 31, 2026, 'tzserver');
		$template = (object) array('code' => 'PSTESTRULE', 'nb' => 2, 'months' => 1, 'days' => 0, 'first_percent' => 0.0, 'mode' => 7, 'split_rule' => ' 30:0; 30:30 ;40:60:eom;');

		$lines = $installments->generateFromTemplate($template, 1000.0, $first);
		$this->assertSame(array(300.0, 300.0, 400.0), array_column($lines, 'amount'));
		$this->assertSame(array('2026-01-31', '2026-03-02', '2026-04-30'), array_map('installmentsTestDay', array_column($lines, 'date')), '60 days end of month');
		$this->assertSame(array(7, 7, 7), array_column($lines, 'mode'));
		$template->split_rule = '33.33:0;33.33:30;33.34:60';
		$this->assertSame(array(33.33, 33.33, 33.34), array_column($installments->generateFromTemplate($template, 100.0, $first), 'amount'));
		$template->split_rule = '50:0;50:30';
		$this->assertSame(array(0.03, 0.02), array_column($installments->generateFromTemplate($template, 0.05, $first), 'amount'), 'The last one takes the rounding');
		$template->split_rule = '';
		$this->assertCount(2, $installments->generateFromTemplate($template, 100.0, $first), 'No free installments: the equal ones');

		foreach (array('30:0;30:30', 'abc', '30:x;70:0', '50:10:foo;50:0', '-10:0;110:0') as $wrong) {
			$template->split_rule = $wrong;
			$installments->error = '';
			$this->assertSame(array(), $installments->generateFromTemplate($template, 100.0, $first), $wrong);
			$this->assertStringContainsString('PSTESTRULE', $installments->error, $wrong);
		}
	}

	/**
	 * Template of a payment term: given to an invoice, a proposal or an order at its validation when it has no schedule;
	 * never to a credit note, never over an existing schedule; a wrong template does not stop the validation
	 *
	 * @return void
	 */
	public function testInstallmentsPaymentTermTemplate()
	{
		global $conf, $langs;

		$db = $this->savdb;
		$user = $this->savuser;
		$installments = new InstallmentSchedule($db);
		$trigger = new InterfaceInstallmentsTriggers($db);
		$code = (string) dol_getIdFromCode($db, 1, 'c_payment_term', 'rowid', 'code', 1);
		$sql = "INSERT INTO ".$db->prefix()."c_installments_template (entity, code, label, nb, split_rule, payment_term_code, position, active)";
		$sql .= " VALUES (".((int) $conf->entity).", 'PSTESTTERM', 'PSTEST term', 1, '40:0;60:30', '".$db->escape($code)."', 99, 1)";
		$this->assertTrue((bool) $db->query($sql), (string) $db->lasterror());
		$socid = $this->createCustomer('term');

		$invoice = $this->createInvoice($socid, array(array(1000, 20)));
		$this->assertSame(0, $trigger->runTrigger('BILL_VALIDATE', $invoice, $user, $langs, $conf), $trigger->error);
		$schedule = $installments->fetch('facture', $invoice->id);
		$this->assertSame(array(480.0, 720.0), array_column($schedule->lines, 'amount'), 'Created at the validation');
		$this->assertSame(dol_print_date($schedule->lines[0]->date, 'dayrfc'), $this->getDueDay($invoice->id), 'Due date: the first installment');
		$this->assertSame(0, $installments->applyPaymentTermTemplate($user, $invoice), 'Never over an existing schedule');

		$proposal = $this->createProposal($socid, array(array(100, 20, 0)));
		$proposal->cond_reglement_id = 1;
		$this->assertSame(0, $trigger->runTrigger('PROPAL_VALIDATE', $proposal, $user, $langs, $conf), $trigger->error);
		$this->assertSame(array(48.0, 72.0), array_column($installments->fetch('propal', $proposal->id)->lines, 'amount'), 'A proposal too');

		$credit = $this->createInvoice($socid, array(array(100, 20)));
		$credit->type = Facture::TYPE_CREDIT_NOTE;
		$this->assertSame(0, $installments->applyPaymentTermTemplate($user, $credit), 'Never a credit note');
		$other = $this->createInvoice($socid, array(array(100, 20)));
		$other->cond_reglement_id = (int) dol_getIdFromCode($db, '30D', 'c_payment_term', 'code', 'rowid', 1);
		$this->assertGreaterThan(1, $other->cond_reglement_id);
		$this->assertSame(0, $installments->applyPaymentTermTemplate($user, $other), 'Another payment term');
		$this->assertSame(0, $installments->fetch('facture', $other->id));

		$this->assertTrue((bool) $db->query("UPDATE ".$db->prefix()."c_installments_template SET split_rule = '40:0' WHERE code = 'PSTESTTERM'"));
		$wrong = $this->createInvoice($socid, array(array(100, 20)));
		$this->assertSame(0, $trigger->runTrigger('BILL_VALIDATE', $wrong, $user, $langs, $conf), 'A wrong template does not stop the validation');
		$this->assertSame(0, $installments->fetch('facture', $wrong->id));
	}

	/**
	 * Save: total and amounts checked, due date of the invoice on the first installment, a paid installment can no longer change,
	 * the schedule of a paid installment cannot be deleted, a user without the right of writing cannot save
	 *
	 * @return void
	 */
	public function testInstallmentsSave()
	{
		$db = $this->savdb;
		$user = $this->savuser;
		$installments = new InstallmentSchedule($db);
		$invoice = $this->createInvoice($this->createCustomer('save'), array(array(1000, 20)));
		$d1 = dol_mktime(12, 0, 0, 1, 10, 2030, 'tzserver');
		$d2 = dol_mktime(12, 0, 0, 2, 10, 2030, 'tzserver');
		$d3 = dol_mktime(12, 0, 0, 3, 10, 2030, 'tzserver');
		$lines = array(array('date' => $d1, 'amount' => 400.0, 'mode' => 0, 'note' => ''), array('date' => $d2, 'amount' => 400.0, 'mode' => 0, 'note' => ''),
			array('date' => $d3, 'amount' => 400.0, 'mode' => 0, 'note' => 'last'));

		$this->assertSame(-1, $installments->save($user, $invoice, array_slice($lines, 0, 2)), 'The total of the invoice is 1200');
		$this->assertSame(-1, $installments->save($user, $invoice, array_merge($lines, array(array('date' => $d3, 'amount' => 0.0, 'mode' => 0, 'note' => '')))), 'An amount of zero');
		$this->assertGreaterThan(0, $installments->save($user, $invoice, $lines), $installments->error);
		$this->assertSame('2030-01-10', $this->getDueDay($invoice->id), 'Due date of the invoice: its first installment');

		$this->addPayment($invoice->id, 500.0);
		$schedule = $installments->fetch('facture', $invoice->id);
		$moved = array(array('id' => $schedule->lines[0]->id, 'date' => $d2, 'amount' => 400.0, 'mode' => 0, 'note' => ''), $lines[1], $lines[2]);
		$this->assertSame(-1, $installments->save($user, $invoice, $moved), 'A paid installment stays as it is');
		$kept = array(array('id' => $schedule->lines[0]->id, 'date' => $d1, 'amount' => 400.0, 'mode' => 0, 'note' => ''), array('id' => $schedule->lines[1]->id, 'date' => $d2, 'amount' => 400.0, 'mode' => 0, 'note' => ''),
			array('date' => $d3, 'amount' => 200.0, 'mode' => 0, 'note' => ''), array('date' => $d3, 'amount' => 200.0, 'mode' => 0, 'note' => ''));
		$this->assertGreaterThan(0, $installments->save($user, $invoice, $kept), $installments->error);
		$schedule = $installments->fetch('facture', $invoice->id);
		$this->assertSame(array(400.0, 400.0, 200.0, 200.0), array_column($schedule->lines, 'amount'));
		$this->assertSame(-1, $installments->delete($user, $invoice), 'An installment is paid');

		$reader = new User($db);
		$reader->fetch($user->id);	// Rights not loaded
		$this->assertSame(-1, $installments->save($reader, $invoice, $kept), 'Right of writing');

		$writer = new User($db);	// May write schedules, not validate invoices: the invoices of the installments as drafts
		$writer->id = $user->id;
		$writer->rights = (object) array('installments' => (object) array('write' => 1));
		$order = $this->createOrder($invoice->socid, array(array(100, 20)));
		$this->assertGreaterThan(0, $installments->save($writer, $order, array(array('date' => $d1, 'amount' => 120.0, 'mode' => 0, 'note' => '')), 1, InstallmentSchedule::INVOICE_SENT), $installments->error);
		$this->assertSame(InstallmentSchedule::INVOICE_DRAFT, $installments->fetch('commande', $order->id)->invoice_mode);
	}

	/**
	 * What is paid goes to the installments by date: payments, credit notes, the amount of a payment being deleted, a closed invoice,
	 * an abandoned installment; the states paid, late and to come
	 *
	 * @return void
	 */
	public function testInstallmentsAllocate()
	{
		$db = $this->savdb;
		$installments = new InstallmentSchedule($db);
		$socid = $this->createCustomer('allocate');
		$invoice = $this->createInvoice($socid, array(array(1000, 20)));
		$past = dol_time_plus_duree(dol_now(), -10, 'd');
		$future = dol_time_plus_duree(dol_now(), 10, 'd');
		$lines = array((object) array('id' => 1, 'date' => $past, 'amount' => 400.0, 'status' => 0), (object) array('id' => 2, 'date' => $past, 'amount' => 400.0, 'status' => 0),
			(object) array('id' => 3, 'date' => $future, 'amount' => 400.0, 'status' => 0));

		$this->addPayment($invoice->id, 500.0);
		$this->assertSame(array(1 => 400.0, 2 => 100.0, 3 => 0.0), $installments->allocate($invoice, $lines));
		$this->assertSame(array(1 => 300.0, 2 => 0.0, 3 => 0.0), $installments->allocate($invoice, $lines, 200.0), 'A payment being deleted');

		$this->addCreditNote($socid, $invoice->id, 400.0);
		$this->assertSame(array(1 => 400.0, 2 => 400.0, 3 => 100.0), $installments->allocate($invoice, $lines), 'A credit note used pays too');

		$lines[1]->status = InstallmentSchedule::STATUS_ABANDONED;
		$this->assertSame(array(1 => 400.0, 2 => 0.0, 3 => 400.0), $installments->allocate($invoice, $lines), 'An abandoned installment gets nothing');

		$schedule = (object) array('billing' => 0);
		$paid = $installments->allocate($invoice, $lines);
		$this->assertSame(array('paid', 'abandoned', 'paid'), array($installments->getState($schedule, $lines[0], $paid[1], dol_now()), $installments->getState($schedule, $lines[1], $paid[2], dol_now()),
			$installments->getState($schedule, $lines[2], $paid[3], dol_now())));
		$this->assertSame(array('late', 'todo'), array($installments->getState($schedule, $lines[0], 0.0, dol_now()), $installments->getState($schedule, $lines[2], 100.0, dol_now())));

		$invoice->paye = 1;
		$this->assertSame(array(1 => 400.0, 2 => 0.0, 3 => 400.0), $installments->allocate($invoice, $lines), 'A closed invoice is paid');
	}

	/**
	 * The triggers keep the due date of the invoice on its next unpaid installment: payment created and deleted for real, payment by SQL
	 * then the trigger, invoice modified by hand; an invoice without schedule keeps its due date
	 *
	 * @return void
	 */
	public function testInstallmentsDueDateTriggers()
	{
		$db = $this->savdb;
		$user = $this->savuser;
		$installments = new InstallmentSchedule($db);
		$socid = $this->createCustomer('triggers');
		$invoice = $this->createInvoice($socid, array(array(1000, 20)));
		$lines = $installments->generate(1200.0, 3, 1, 0, dol_mktime(12, 0, 0, 1, 10, 2030, 'tzserver'));
		$this->assertGreaterThan(0, $installments->save($user, $invoice, $lines), $installments->error);
		$this->assertSame('2030-01-10', $this->getDueDay($invoice->id));

		$payment = new Paiement($db);
		$payment->datepaye = dol_now();
		$payment->amounts = array($invoice->id => 400.0);
		$payment->paiementid = (int) dol_getIdFromCode($db, 'LIQ', 'c_paiement', 'code', 'id', 1);
		$payment->paiementcode = 'LIQ';
		$this->assertGreaterThan(0, $payment->create($user, 0), (string) $payment->error);
		$this->assertSame('2030-02-10', $this->getDueDay($invoice->id), 'Trigger PAYMENT_CUSTOMER_CREATE of the core');
		$this->assertGreaterThan(0, $payment->delete($user), (string) $payment->error);
		$this->assertSame('2030-01-10', $this->getDueDay($invoice->id), 'Trigger PAYMENT_CUSTOMER_DELETE: the deleted payment left out');

		$this->addPayment($invoice->id, 800.0);
		$trigger = new InterfaceInstallmentsTriggers($db);
		$facture = new Facture($db);
		$facture->fetch($invoice->id);
		$this->assertSame(0, $trigger->runTrigger('BILL_UNPAYED', $facture, $user, $this->savlangs, $this->savconf));
		$this->assertSame('2030-03-10', $this->getDueDay($invoice->id));

		$facture->date_lim_reglement = dol_mktime(12, 0, 0, 6, 1, 2030, 'tzserver');
		$this->assertGreaterThan(0, $facture->update($user), (string) $facture->error);
		$this->assertSame('2030-03-10', $this->getDueDay($invoice->id), 'Trigger BILL_MODIFY: the schedule decides');

		$other = $this->createInvoice($socid, array(array(100, 20)));
		$before = $this->getDueDay($other->id);
		$this->addPayment($other->id, 50.0);
		$this->assertSame(0, $trigger->runTrigger('BILL_UNPAYED', $other, $user, $this->savlangs, $this->savconf));
		$this->assertSame($before, $this->getDueDay($other->id), 'No schedule, nothing changed');
	}

	/**
	 * Validation of an invoice made from an order with a schedule of expected payments: the schedule follows if the totals are the same
	 *
	 * @return void
	 */
	public function testInstallmentsCopyFromOrigin()
	{
		$db = $this->savdb;
		$user = $this->savuser;
		$installments = new InstallmentSchedule($db);
		$socid = $this->createCustomer('copy');
		$order = $this->createOrder($socid, array(array(1000, 20)));
		$lines = $installments->generate(1200.0, 2, 1, 0, dol_mktime(12, 0, 0, 1, 10, 2030, 'tzserver'), 0.0, 3);
		$this->assertGreaterThan(0, $installments->save($user, $order, $lines, 0), $installments->error);

		$invoice = $this->createInvoice($socid, array(array(1000, 20)), array('commande' => $order->id));
		$trigger = new InterfaceInstallmentsTriggers($db);
		$this->assertSame(0, $trigger->runTrigger('BILL_VALIDATE', $invoice, $user, $this->savlangs, $this->savconf));
		$schedule = $installments->fetch('facture', $invoice->id);
		$this->assertIsObject($schedule, 'Copied at the validation');
		$this->assertSame(array(600.0, 600.0), array_column($schedule->lines, 'amount'));
		$this->assertSame(array(3, 3), array_column($schedule->lines, 'mode'));
		$this->assertSame('2030-01-10', $this->getDueDay($invoice->id));

		$partial = $this->createInvoice($socid, array(array(500, 20)), array('commande' => $order->id));
		$this->assertSame(0, $installments->copyFromOrigin($user, $partial), 'Another total: nothing copied');
		$this->assertSame(0, $installments->fetch('facture', $partial->id));

		$this->assertSame(0, $installments->copyFromOrigin($user, $invoice), 'Already a schedule');
	}

	/**
	 * Invoice of an installment of a proposal with 2 VAT rates: one line per rate in proportion, the last installment takes what is left,
	 * the installment cannot be invoiced twice; deleting the invoice puts the installment to check; deleting the proposal its schedule
	 *
	 * @return void
	 */
	public function testInstallmentsBillingInvoices()
	{
		$db = $this->savdb;
		$user = $this->savuser;
		$installments = new InstallmentSchedule($db);
		$socid = $this->createCustomer('billing');
		$proposal = $this->createProposal($socid, array(array(100, 20, 0), array(50, 5.5, 1)));
		$this->assertSame(172.75, (float) price2num($proposal->total_ttc, 'MT'));
		$lines = $installments->generate(172.75, 3, 1, 0, dol_now());
		$this->assertGreaterThan(0, $installments->save($user, $proposal, $lines, 1, InstallmentSchedule::INVOICE_DRAFT), $installments->error);
		$schedule = $installments->fetch('propal', $proposal->id);
		$this->assertSame(1, $schedule->billing);

		$ids = array();
		foreach ($schedule->lines as $line) {
			$ids[] = $installments->createInvoice($user, $schedule, $line);
			$this->assertGreaterThan(0, end($ids), $installments->error);
		}
		$this->assertSame(-1, $installments->createInvoice($user, $schedule, $schedule->lines[0]), 'Already invoiced');
		$totals = array();
		foreach ($ids as $id) {
			$invoice = new Facture($db);
			$invoice->fetch($id);
			$totals[] = (float) price2num($invoice->total_ttc, 'MT');
			$this->assertSame(Facture::STATUS_DRAFT, (int) $invoice->status);
			$this->assertCount(2, $invoice->lines, 'One line per VAT rate');
			$invoice->fetchObjectLinked();
			$this->assertEquals(array($proposal->id), array_values($invoice->linkedObjectsIds['propal'] ?? array()), 'Linked to the proposal');
		}
		$this->assertSame(array(57.58, 57.58, 57.59), $totals);
		$sql = "SELECT fd.tva_tx, fd.product_type, SUM(fd.total_ttc) as total FROM ".$db->prefix()."facturedet as fd WHERE fd.fk_facture IN (".implode(',', $ids).") GROUP BY fd.tva_tx, fd.product_type ORDER BY fd.tva_tx";
		$resql = $db->query($sql);
		$groups = array();
		while ($obj = $db->fetch_object($resql)) {
			$groups[] = price2num($obj->tva_tx).'|'.$obj->product_type.'|'.price2num($obj->total, 'MT');
		}
		$this->assertSame(array('5.5|1|52.75', '20|0|120'), $groups, 'Each line of the proposal invoiced to the cent');

		// Cents: each invoice is its installment, each line of the proposal is invoiced in full
		$cents = $this->createProposal($socid, array(array(0.5, 0, 0), array(0.5, 0, 1)));
		$this->assertGreaterThan(0, $installments->save($user, $cents, $installments->generate(1.0, 3, 1, 0, dol_now()), 1), $installments->error);
		$schedule = $installments->fetch('propal', $cents->id);
		$centids = array();
		$centtotals = array();
		foreach ($schedule->lines as $line) {
			$centids[] = $installments->createInvoice($user, $schedule, $line);
			$invoice = new Facture($db);
			$invoice->fetch((int) end($centids));
			$centtotals[] = (float) price2num($invoice->total_ttc, 'MT');
		}
		$this->assertSame(array(0.33, 0.33, 0.34), $centtotals);
		$resql = $db->query("SELECT fd.product_type, SUM(fd.total_ttc) as total FROM ".$db->prefix()."facturedet as fd WHERE fd.fk_facture IN (".implode(',', $centids).") GROUP BY fd.product_type ORDER BY fd.product_type");
		$groups = array();
		while ($obj = $db->fetch_object($resql)) {
			$groups[] = (float) price2num($obj->total, 'MT');
		}
		$this->assertSame(array(0.5, 0.5), $groups);

		$trigger = new InterfaceInstallmentsTriggers($db);
		$invoice = new Facture($db);
		$invoice->fetch($ids[0]);
		$this->assertSame(0, $trigger->runTrigger('BILL_DELETE', $invoice, $user, $this->savlangs, $this->savconf));
		$schedule = $installments->fetch('propal', $proposal->id);
		$this->assertSame(array(0, InstallmentSchedule::STATUS_TOCHECK), array($schedule->lines[0]->invoiceid, $schedule->lines[0]->status), 'To check, never invoiced again by the job');

		$this->assertSame(0, $trigger->runTrigger('PROPAL_DELETE', $proposal, $user, $this->savlangs, $this->savconf));
		$this->assertSame(0, $installments->fetch('propal', $proposal->id));
	}

	/**
	 * Job of the invoices: from its first run on, the installments of the day and before, never one to check; a second run invoices
	 * nothing; mode sent: the invoice is validated and sent to the billing contact else the customer, with an event
	 *
	 * @return void
	 */
	public function testInstallmentsDueInvoicesJob()
	{
		$db = $this->savdb;
		$user = $this->savuser;
		$installments = new InstallmentSchedule($db);
		$socid = $this->createCustomer('job');
		$order = $this->createOrder($socid, array(array(300, 20)));
		$today = dol_now();
		$lines = array(array('date' => dol_time_plus_duree($today, -3, 'd'), 'amount' => 120.0, 'mode' => 0, 'note' => ''), array('date' => $today, 'amount' => 120.0, 'mode' => 0, 'note' => ''),
			array('date' => dol_time_plus_duree($today, 30, 'd'), 'amount' => 120.0, 'mode' => 0, 'note' => ''));
		$this->assertGreaterThan(0, $installments->save($user, $order, $lines, 1, InstallmentSchedule::INVOICE_DRAFT), $installments->error);
		$proposal = $this->createProposal($socid, array(array(100, 20, 0)));
		$this->assertGreaterThan(0, $installments->save($user, $proposal, array(array('date' => $today, 'amount' => 120.0, 'mode' => 0, 'note' => '')), 1), $installments->error);
		$this->assertTrue((bool) $db->query("UPDATE ".$db->prefix()."installments_line SET status = ".InstallmentSchedule::STATUS_TOCHECK." WHERE fk_schedule = (SELECT rowid FROM ".$db->prefix()."installments_schedule WHERE element = 'propal' AND fk_element = ".((int) $proposal->id).")"));

		$this->assertSame(0, $installments->createDueInvoices(), $installments->output);
		$this->assertSame(dol_print_date(dol_get_first_hour($today), 'dayrfc'), dol_print_date(getDolGlobalInt('INSTALLMENTS_START_DATE'), 'dayrfc'), 'Date of the first run');
		$schedule = $installments->fetch('commande', $order->id);
		$this->assertSame(0, $schedule->lines[0]->invoiceid, 'Before the first run: by hand');
		$this->assertGreaterThan(0, $schedule->lines[1]->invoiceid, 'The installment of the day');
		$this->assertSame(0, $schedule->lines[2]->invoiceid, 'To come');
		$this->assertSame(0, $installments->fetch('propal', $proposal->id)->lines[0]->invoiceid, 'To check: never by the job');

		$this->assertSame(0, $installments->createDueInvoices());
		$this->assertSame(1, $this->countRows("SELECT COUNT(rowid) as nb FROM ".$db->prefix()."element_element WHERE sourcetype = 'commande' AND fk_source = ".((int) $order->id)." AND targettype = 'facture'"), 'Second run: nothing more');

		// An author who may not validate invoices: the invoice of his schedule stays a draft, whatever the mode asked
		$author = new User($db);
		$author->login = 'pstestnorights';
		$author->lastname = 'PSTEST';
		$this->assertGreaterThan(0, $author->create($user), (string) $author->error);
		$other = $this->createOrder($socid, array(array(100, 20)));
		$this->assertGreaterThan(0, $installments->save($user, $other, array(array('date' => $today, 'amount' => 120.0, 'mode' => 0, 'note' => '')), 1, InstallmentSchedule::INVOICE_SENT), $installments->error);
		$this->assertTrue((bool) $db->query("UPDATE ".$db->prefix()."installments_schedule SET fk_user_creat = ".((int) $author->id)." WHERE element = 'commande' AND fk_element = ".((int) $other->id)));
		$otherschedule = $installments->fetch('commande', $other->id);
		$this->assertSame(InstallmentSchedule::INVOICE_SENT, $otherschedule->invoice_mode);
		$installments->errors = array();
		$draft = new Facture($db);
		$draft->fetch($installments->createInvoice($user, $otherschedule, $otherschedule->lines[0]));
		$this->assertSame(array(Facture::STATUS_DRAFT, 1), array((int) $draft->status, count($installments->errors)), 'A draft, and why');
		$this->assertSame(InstallmentSchedule::INVOICE_DRAFT, $installments->getInvoiceModeAllowed($author));
		$installments->errors = array();

		// Mode sent: validated, PDF and email to the billing contact
		$hook = $this->captureMails();
		$template = $this->createEmailTemplate('Invoice __REF__', 'Hello');
		$invoiceid = $installments->createInvoice($user, (object) array_merge((array) $schedule, array('invoice_mode' => InstallmentSchedule::INVOICE_SENT, 'fk_email_template' => $template)), $schedule->lines[2]);
		$this->assertGreaterThan(0, $invoiceid, $installments->error);
		$invoice = new Facture($db);
		$invoice->fetch($invoiceid);
		$this->assertSame(Facture::STATUS_VALIDATED, (int) $invoice->status, implode(', ', $installments->errors));
		$this->assertSame(array('pstest-job@example.com|Invoice '.$invoice->ref), $hook->mails, implode(', ', $installments->errors));
		$this->assertSame(1, $this->countRows("SELECT COUNT(id) as nb FROM ".$db->prefix()."actioncomm WHERE code = 'AC_BILL_SENTBYMAIL' AND fk_element = ".((int) $invoiceid)." AND elementtype = 'invoice'"));
	}

	/**
	 * A direct debit from the request to the payment, as the core does it: the order credited makes a payment of the installment
	 * (the invoice stays open, its due date goes to the next installment, whose request comes next); a rejection makes a negative
	 * payment: the installment is due again
	 *
	 * @return void
	 */
	public function testInstallmentsDirectDebitCycle()
	{
		$db = $this->savdb;
		$user = $this->savuser;
		$installments = new InstallmentSchedule($db);
		$invoice = $this->createInvoice($this->createCustomer('cycle'), array(array(1000, 20)));
		$pre = (int) dol_getIdFromCode($db, 'PRE', 'c_paiement', 'code', 'id', 1);
		$today = dol_now();
		$lines = array();
		foreach (array(3, 33, 63) as $days) {
			$lines[] = array('date' => dol_time_plus_duree($today, $days, 'd'), 'amount' => 400.0, 'mode' => $pre, 'note' => '');
		}
		$this->assertGreaterThan(0, $installments->save($user, $invoice, $lines), $installments->error);
		$this->assertSame(0, $installments->createDirectDebits(), $installments->output);
		$schedule = $installments->fetch('facture', $invoice->id);
		$this->assertGreaterThan(0, $schedule->lines[0]->debitid);

		// The order of the bank, credited: its request done, a payment of the installment
		$this->assertTrue((bool) $db->query("INSERT INTO ".$db->prefix()."prelevement_bons (ref, entity, datec, amount, statut, credite) VALUES ('PSTEST1', 1, '".$db->idate($today)."', 400, 2, 1)"));
		$order = (int) $db->last_insert_id($db->prefix().'prelevement_bons');
		$this->assertTrue((bool) $db->query("UPDATE ".$db->prefix()."prelevement_demande SET traite = 1, fk_prelevement_bons = ".$order." WHERE rowid = ".((int) $schedule->lines[0]->debitid)));
		foreach (array(400.0 => 1, -400.0 => 0) as $amount => $nextinstallment) {	// The credit, then its rejection
			$payment = new Paiement($db);
			$payment->datepaye = $today;
			$payment->amounts = array($invoice->id => $amount);
			$payment->paiementid = $pre;
			$payment->paiementcode = 'PRE';
			$this->assertGreaterThan(0, $payment->create($user, 0), (string) $payment->error);
			$this->assertSame(dol_print_date($schedule->lines[$nextinstallment]->date, 'dayrfc'), $this->getDueDay($invoice->id), 'Due date after a payment of '.$amount);
			if ($amount > 0) {
				$facture = new Facture($db);
				$facture->fetch($invoice->id);
				$this->assertSame(0, (int) $facture->paye, 'The invoice stays open');
				$this->assertTrue((bool) $db->query("UPDATE ".$db->prefix()."installments_line SET date_due = '".$db->idate(dol_time_plus_duree($today, 4, 'd'))."' WHERE rowid = ".((int) $schedule->lines[1]->id)));
				$this->assertSame(0, $installments->createDirectDebits(), $installments->output);
				$this->assertGreaterThan(0, $installments->fetch('facture', $invoice->id)->lines[1]->debitid, 'The request of the next installment');
				$schedule = $installments->fetch('facture', $invoice->id);
			}
		}
		$this->assertSame(2, $this->countRows("SELECT COUNT(rowid) as nb FROM ".$db->prefix()."prelevement_demande WHERE fk_facture = ".((int) $invoice->id)));
	}

	/**
	 * Job of the direct debits: one request for the first unpaid installment by direct debit in the days, never twice, a deleted request
	 * asked again; the installments paid by another mode or too far are left
	 *
	 * @return void
	 */
	public function testInstallmentsDirectDebitsJob()
	{
		$db = $this->savdb;
		$user = $this->savuser;
		$installments = new InstallmentSchedule($db);
		$socid = $this->createCustomer('debit');
		$invoice = $this->createInvoice($socid, array(array(1000, 20)));
		$pre = (int) dol_getIdFromCode($db, 'PRE', 'c_paiement', 'code', 'id', 1);
		$vir = (int) dol_getIdFromCode($db, 'VIR', 'c_paiement', 'code', 'id', 1);
		$today = dol_now();
		$lines = array(array('date' => dol_time_plus_duree($today, 2, 'd'), 'amount' => 400.0, 'mode' => $vir, 'note' => ''), array('date' => dol_time_plus_duree($today, 3, 'd'), 'amount' => 400.0, 'mode' => $pre, 'note' => ''),
			array('date' => dol_time_plus_duree($today, 40, 'd'), 'amount' => 400.0, 'mode' => $pre, 'note' => ''));
		$this->assertGreaterThan(0, $installments->save($user, $invoice, $lines), $installments->error);

		$this->assertSame(0, $installments->createDirectDebits(), $installments->output);
		$schedule = $installments->fetch('facture', $invoice->id);
		$this->assertSame(array(0, 0), array($schedule->lines[0]->debitid, $schedule->lines[2]->debitid), 'Another mode, too far');
		$this->assertGreaterThan(0, $schedule->lines[1]->debitid);
		$this->assertSame(400.0, (float) $this->countRows("SELECT amount as nb FROM ".$db->prefix()."prelevement_demande WHERE rowid = ".((int) $schedule->lines[1]->debitid)));

		$this->assertSame(0, $installments->createDirectDebits());
		$this->assertSame(1, $this->countRows("SELECT COUNT(rowid) as nb FROM ".$db->prefix()."prelevement_demande WHERE fk_facture = ".((int) $invoice->id)), 'Never twice');

		$this->assertTrue((bool) $db->query("DELETE FROM ".$db->prefix()."prelevement_demande WHERE fk_facture = ".((int) $invoice->id)));
		$this->assertSame(0, $installments->createDirectDebits());
		$this->assertSame(1, $this->countRows("SELECT COUNT(rowid) as nb FROM ".$db->prefix()."prelevement_demande WHERE fk_facture = ".((int) $invoice->id)), 'Asked again');

		// Several partial requests allowed: the next installment in the days gets its request, the first one not a second
		$this->savconf->global->WITHDRAW_STRICT_CHECK_AMOUNT = 1;
		$this->assertTrue((bool) $db->query("UPDATE ".$db->prefix()."installments_line SET date_due = '".$db->idate(dol_time_plus_duree($today, 4, 'd'))."' WHERE rowid = ".((int) $schedule->lines[2]->id)));
		$this->assertSame(0, $installments->createDirectDebits(), $installments->output);
		$this->assertSame(2, $this->countRows("SELECT COUNT(rowid) as nb FROM ".$db->prefix()."prelevement_demande WHERE fk_facture = ".((int) $invoice->id)), 'One request per installment');
	}

	/**
	 * Payments of selected installments: one payment per invoice for what is left on them, a second run records nothing
	 *
	 * @return void
	 */
	public function testInstallmentsRecordPayments()
	{
		$db = $this->savdb;
		$user = $this->savuser;
		$installments = new InstallmentSchedule($db);
		$invoice = $this->createInvoice($this->createCustomer('payments'), array(array(1000, 20)));
		$this->assertGreaterThan(0, $installments->save($user, $invoice, $installments->generate(1200.0, 3, 1, 0, dol_now())), $installments->error);
		$this->addPayment($invoice->id, 100.0);
		$schedule = $installments->fetch('facture', $invoice->id);
		$resql = $db->query("SELECT MIN(rowid) as id FROM ".$db->prefix()."bank_account WHERE clos = 0 AND courant = 1");
		$account = (int) $db->fetch_object($resql)->id;
		$ids = array($schedule->lines[0]->id, $schedule->lines[1]->id);

		$rep = new User($db);	// A sales representative of other customers: the invoice is not his
		$rep->id = $user->id;
		$rep->rights = (object) array('facture' => (object) array('paiement' => 1));
		$this->assertSame(0, $installments->recordPayments($rep, $ids, dol_now(), (int) dol_getIdFromCode($db, 'VIR', 'c_paiement', 'code', 'id', 1), $account), $installments->error);
		$this->assertSame(-1, $installments->recordPayments($user, $ids, dol_now(), 999999, $account), 'Unknown payment mode');
		$this->assertSame(-1, $installments->recordPayments($user, $ids, dol_now(), (int) dol_getIdFromCode($db, 'VIR', 'c_paiement', 'code', 'id', 1), 999999), 'Unknown account');

		$this->assertSame(1, $installments->recordPayments($user, $ids, dol_now(), (int) dol_getIdFromCode($db, 'VIR', 'c_paiement', 'code', 'id', 1), $account), implode(', ', $installments->errors).$installments->error);
		$this->assertSame(800.0, (float) $this->countRows("SELECT SUM(amount) as nb FROM ".$db->prefix()."paiement_facture WHERE fk_facture = ".((int) $invoice->id)));
		$this->assertSame(0, $installments->recordPayments($user, $ids, dol_now(), (int) dol_getIdFromCode($db, 'VIR', 'c_paiement', 'code', 'id', 1), $account), 'Second run: nothing left on them');
	}

	/**
	 * The 60 days rule: a company customer with a schedule beyond 60 days after the date of the invoice, not a private person
	 *
	 * @return void
	 */
	public function testInstallmentsSixtyDays()
	{
		$db = $this->savdb;
		$installments = new InstallmentSchedule($db);
		$company = $this->createInvoice($this->createCustomer('company', 'FR40303265045'), array(array(100, 20)));
		$person = $this->createInvoice($this->createCustomer('person'), array(array(100, 20)));
		$near = array(array('date' => dol_time_plus_duree((int) $company->date, 50, 'd')));
		$far = array(array('date' => dol_time_plus_duree((int) $company->date, 50, 'd')), array('date' => dol_time_plus_duree((int) $company->date, 61, 'd')));

		$this->assertFalse($installments->isBeyondSixtyDays($company, $near));
		$this->assertTrue($installments->isBeyondSixtyDays($company, $far));
		$this->assertFalse($installments->isBeyondSixtyDays($person, $far), 'Not between professionals');
	}

	/**
	 * Installments of the list: states and filters, an invoice of a billing schedule shown
	 *
	 * @return void
	 */
	public function testInstallmentsGetInstallments()
	{
		$db = $this->savdb;
		$user = $this->savuser;
		$installments = new InstallmentSchedule($db);
		$socid = $this->createCustomer('installments');
		$invoice = $this->createInvoice($socid, array(array(1000, 20)));
		$lines = array(array('date' => dol_time_plus_duree(dol_now(), -20, 'd'), 'amount' => 400.0, 'mode' => 0, 'note' => ''), array('date' => dol_time_plus_duree(dol_now(), -10, 'd'), 'amount' => 400.0, 'mode' => 0, 'note' => ''),
			array('date' => dol_time_plus_duree(dol_now(), 10, 'd'), 'amount' => 400.0, 'mode' => 0, 'note' => ''));
		$this->assertGreaterThan(0, $installments->save($user, $invoice, $lines), $installments->error);
		$this->addPayment($invoice->id, 450.0);
		$order = $this->createOrder($socid, array(array(100, 20)));
		$this->assertGreaterThan(0, $installments->save($user, $order, array(array('date' => dol_time_plus_duree(dol_now(), 5, 'd'), 'amount' => 120.0, 'mode' => 0, 'note' => '')), 1), $installments->error);

		$rows = $installments->getInstallments($user, array('socid' => $socid));
		$this->assertSame(array('paid', 'late', 'todo', 'todo'), array_column($rows, 'state'));
		$this->assertSame(array(400.0, 50.0, 0.0, 0.0), array_column($rows, 'paid'));
		$this->assertCount(2, $installments->getInstallments($user, array('socid' => $socid, 'states' => array('late', 'todo'), 'type' => 'payment')));
		$this->assertCount(1, $installments->getInstallments($user, array('socid' => $socid, 'type' => 'billing')));
		$this->assertCount(2, $installments->getInstallments($user, array('socid' => $socid, 'date_start' => dol_get_first_hour(dol_now()))));
	}

	/**
	 * Key __INSTALLMENTS__: its description in the editor, the installments of a document, nothing for a document without schedule
	 *
	 * @return void
	 */
	public function testInstallmentsSubstitution()
	{
		$db = $this->savdb;
		$langs = $this->savlangs;
		$installments = new InstallmentSchedule($db);
		$invoice = $this->createInvoice($this->createCustomer('subst'), array(array(100, 20)));
		$vir = (int) dol_getIdFromCode($db, 'VIR', 'c_paiement', 'code', 'id', 1);
		$this->assertGreaterThan(0, $installments->save($this->savuser, $invoice, array(array('date' => dol_mktime(12, 0, 0, 1, 10, 2030, 'tzserver'), 'amount' => 60.0, 'mode' => $vir, 'note' => ''),
			array('date' => dol_mktime(12, 0, 0, 2, 10, 2030, 'tzserver'), 'amount' => 60.0, 'mode' => $vir, 'note' => ''))), $installments->error);

		$keys = array();
		installments_completesubstitutionarray($keys, $langs, null);
		$this->assertNotSame('', $keys['__INSTALLMENTS__'], 'Its description in the editor');
		$keys = array();
		installments_completesubstitutionarray($keys, $langs, $invoice);
		$this->assertSame(2, count(explode("<br>\n", $keys['__INSTALLMENTS__'])));
		$this->assertStringContainsString(dol_print_date(dol_mktime(12, 0, 0, 2, 10, 2030, 'tzserver'), 'day', false, $langs), $keys['__INSTALLMENTS__']);
		$this->assertStringContainsString($langs->transnoentitiesnoconv('PaymentTypeVIR'), $keys['__INSTALLMENTS__']);
		$keys = array();
		installments_completesubstitutionarray($keys, $langs, $this->createInvoice($this->createCustomer('subst2'), array(array(100, 20))));
		$this->assertSame('', $keys['__INSTALLMENTS__']);
	}

	/**
	 * PDF of an invoice: room reserved in the area of the totals and one row per installment under them, the table of payments after
	 *
	 * @return void
	 */
	public function testInstallmentsPdfHooks()
	{
		$db = $this->savdb;
		$langs = $this->savlangs;
		$installments = new InstallmentSchedule($db);
		$invoice = $this->createInvoice($this->createCustomer('pdf'), array(array(100, 20)));
		$this->assertGreaterThan(0, $installments->save($this->savuser, $invoice, $installments->generate(120.0, 3, 1, 0, dol_now())), $installments->error);

		$hooks = new ActionsInstallments($db);
		$model = new stdClass();
		$model->heightforinfotot = 50;
		$model->page_largeur = 210;
		$model->marge_droite = 10;
		$action = '';
		$this->assertSame(0, $hooks->printUnderHeaderPDFline(array('object' => $invoice, 'outputlangs' => $langs), $model, $action, null));
		$this->assertSame(66, $model->heightforinfotot, 'A title and 3 installments');
		$pdf = pdf_getInstance();
		$pdf->AddPage();
		$index = 2;
		$this->assertSame(0, $hooks->afterPDFTotalTable(array('pdf' => $pdf, 'object' => $invoice, 'outputlangs' => $langs, 'index' => &$index, 'posy' => 200), $model, $action, null));
		$this->assertSame(6, $index, 'The rows of the schedule before the payments');

		$long = $this->createInvoice($this->createCustomer('pdflong'), array(array(3000, 20)));	// 36 installments, 6 paid: 30 left
		$this->assertGreaterThan(0, $installments->save($this->savuser, $long, $installments->generate(3600.0, 36, 1, 0, dol_now())), $installments->error);
		$this->addPayment($long->id, 600.0);
		$long->fetch($long->id);
		$model->heightforinfotot = 50;
		$this->assertSame(0, $hooks->printUnderHeaderPDFline(array('object' => $long, 'outputlangs' => $langs), $model, $action, null));
		$this->assertSame(94, $model->heightforinfotot, 'At most a title and 10 rows');
		$index = 2;
		$this->assertSame(0, $hooks->afterPDFTotalTable(array('pdf' => $pdf, 'object' => $long, 'outputlangs' => $langs, 'index' => &$index, 'posy' => 100), $model, $action, null));
		$this->assertSame(13, $index);

		$other = $this->createInvoice($this->createCustomer('pdf2'), array(array(100, 20)));
		$index = 2;
		$this->assertSame(0, $hooks->afterPDFTotalTable(array('pdf' => $pdf, 'object' => $other, 'outputlangs' => $langs, 'index' => &$index, 'posy' => 200), $model, $action, null));
		$this->assertSame(2, $index, 'No schedule, nothing drawn');
	}

	/**
	 * PDF of a proposal or an order: its schedule added to its public note while the PDF is made, the note given back after; nothing
	 * when the note has the key __INSTALLMENTS__ or for a single installment
	 *
	 * @return void
	 */
	public function testInstallmentsPdfNote()
	{
		global $langs;

		$user = $this->savuser;
		$installments = new InstallmentSchedule($this->savdb);
		$hooks = new ActionsInstallments($this->savdb);
		$socid = $this->createCustomer('pdfnote');
		$action = '';
		$model = null;
		$proposal = $this->createProposal($socid, array(array(300, 20, 0)));
		$proposal->note_public = 'Thanks';
		$this->assertGreaterThan(0, $installments->save($user, $proposal, $installments->generate(360.0, 3, 1, 0, dol_now())), $installments->error);
		$parameters = array('object' => $proposal, 'outputlangs' => $langs);

		$this->assertSame(0, $hooks->beforePDFCreation($parameters, $proposal, $action, null));
		$this->assertStringStartsWith('Thanks', $proposal->note_public);
		$this->assertSame(3, substr_count($proposal->note_public, '<tr>'), 'One row per installment');
		$this->assertSame(0, $hooks->afterPDFCreation($parameters, $model, $action, null));
		$this->assertSame('Thanks', $proposal->note_public, 'Given back');

		$proposal->note_public = 'Pay: __INSTALLMENTS__';
		$hooks->beforePDFCreation($parameters, $proposal, $action, null);
		$this->assertSame('Pay: __INSTALLMENTS__', $proposal->note_public, 'The key says where');

		$order = $this->createOrder($socid, array(array(100, 20)));
		$this->assertGreaterThan(0, $installments->save($user, $order, $installments->generate(120.0, 1, 1, 0, dol_now())), $installments->error);
		$hooks->beforePDFCreation(array('object' => $order, 'outputlangs' => $langs), $order, $action, null);
		$this->assertSame('', (string) $order->note_public, 'One installment: nothing');
	}

	/**
	 * Import from the tables of the module Echeancier (temporary copies): expected payments of an invoice; installments of a proposal
	 * invoiced by the old module, invoiced by hand (found: same customer and amount within 45 days, the invoice linked to the proposal
	 * first, each invoice once) or not found (to check, past or future); lines without amount left out, missing document, preview
	 * then import, nothing imported twice
	 *
	 * @return void
	 */
	public function testInstallmentsImport()
	{
		$db = $this->savdb;
		$user = $this->savuser;
		$socid = $this->createCustomer('import');
		$invoice = $this->createInvoice($socid, array(array(1000, 20)));
		$proposal = $this->createProposal($socid, array(array(400, 20, 1)));
		$billed = $this->createInvoice($socid, array(array(100, 20)));
		$byhand = $this->createInvoice($socid, array(array(100, 20)));
		$linked = $this->createInvoice($socid, array(array(100, 20)), array('propal' => $proposal->id));
		$decoy = $this->createInvoice($socid, array(array(100, 20)));
		$p = $db->prefix();
		$past = $db->idate(dol_time_plus_duree(dol_now(), -40, 'd'));
		$past2 = $db->idate(dol_time_plus_duree(dol_now(), -10, 'd'));
		$future = $db->idate(dol_time_plus_duree(dol_now(), 20, 'd'));
		$far = $db->idate(dol_time_plus_duree(dol_now(), 200, 'd'));
		foreach (array($billed->id => -10, $byhand->id => -7, $linked->id => 30, $decoy->id => 20) as $id => $days) {	// The linked invoice is farther; the invoice of the old module never taken twice
			$this->assertTrue((bool) $db->query("UPDATE ".$p."facture SET datef = '".$db->idate(dol_time_plus_duree(dol_now(), $days, 'd'))."' WHERE rowid = ".((int) $id)));
		}
		$sqls = array(
			"CREATE TEMPORARY TABLE ".$p."echeancier (id integer, type integer, montant numeric(24,8), nombre integer, id_element integer, id_client integer, date_debut date, num integer, invoice integer)",
			"CREATE TEMPORARY TABLE ".$p."echeance (rowid integer, fk_echeancier integer, fk_facture integer, date_reglement date, acompte integer, paye integer, montant numeric(24,8), montant_paye numeric(24,8), ecart numeric(24,8), type integer, fk_bank integer, note text, fk_prelevement integer, invoice integer)",
			"INSERT INTO ".$p."echeancier VALUES (1, 2, 1200, 3, ".((int) $invoice->id).", ".((int) $socid).", '".$past."', 0, 0)",
			"INSERT INTO ".$p."echeancier VALUES (2, 1, 480, 4, ".((int) $proposal->id).", ".((int) $socid).", '".$past."', 0, 1)",
			"INSERT INTO ".$p."echeancier VALUES (3, 1, 100, 1, 999999999, ".((int) $socid).", '".$past."', 0, 1)",
			"INSERT INTO ".$p."echeance VALUES (11, 1, ".((int) $invoice->id).", '".$past."', 0, 1, 400, 400, 0, 2, NULL, NULL, NULL, NULL)",
			"INSERT INTO ".$p."echeance VALUES (12, 1, ".((int) $invoice->id).", '".$past2."', 0, 0, 400, 0, 0, 2, NULL, NULL, NULL, NULL)",
			"INSERT INTO ".$p."echeance VALUES (13, 1, ".((int) $invoice->id).", '".$future."', 0, 0, 400, 0, 0, 2, NULL, 'last', NULL, NULL)",
			"INSERT INTO ".$p."echeance VALUES (14, 1, ".((int) $invoice->id).", '".$past2."', 0, 2, 0, 50, -50, 2, NULL, NULL, NULL, NULL)",
			"INSERT INTO ".$p."echeance VALUES (15, 1, ".((int) $invoice->id).", '".$past2."', 0, 0, -50, 0, 0, 2, NULL, NULL, NULL, NULL)",
			"INSERT INTO ".$p."echeance VALUES (21, 2, 0, '".$past."', 0, 0, 120, 0, 0, 1, NULL, NULL, NULL, ".((int) $billed->id).")",
			"INSERT INTO ".$p."echeance VALUES (22, 2, 0, '".$past2."', 0, 0, 120, 0, 0, 1, NULL, NULL, NULL, NULL)",
			"INSERT INTO ".$p."echeance VALUES (23, 2, 0, '".$future."', 0, 0, 120, 0, 0, 1, NULL, NULL, NULL, NULL)",
			"INSERT INTO ".$p."echeance VALUES (24, 2, 0, '".$far."', 0, 0, 120, 0, 0, 1, NULL, NULL, NULL, NULL)",
		);
		foreach ($sqls as $sql) {
			$this->assertTrue((bool) $db->query($sql), (string) $db->lasterror());
		}
		$installments = new InstallmentsOldTables($db);

		$report = $installments->import($user, true);
		$this->assertSame(array(2, 7, 1, 2), array($report['schedules'], $report['lines'], $report['tocheck'], count($report['matched'])), $installments->error);
		$this->assertCount(1, $report['skipped'], 'Missing document');
		$this->assertSame(0, $installments->fetch('facture', $invoice->id), 'Preview only');

		$report = $installments->import($user, false);
		$this->assertSame(array(2, 7, 1, 2), array($report['schedules'], $report['lines'], $report['tocheck'], count($report['matched'])), $installments->error);
		$schedule = $installments->fetch('facture', $invoice->id);
		$this->assertSame(array(400.0, 400.0, 400.0), array_column($schedule->lines, 'amount'), 'Lines without amount left out');
		$this->assertSame('last', $schedule->lines[2]->note);
		$this->assertSame(dol_print_date($schedule->lines[0]->date, 'dayrfc'), $this->getDueDay($invoice->id), 'Due date: the next unpaid installment (nothing paid in Dolibarr)');
		$schedule = $installments->fetch('propal', $proposal->id);
		$this->assertSame(1, $schedule->billing);
		$this->assertSame(array((int) $billed->id, (int) $byhand->id, (int) $linked->id, 0), array_column($schedule->lines, 'invoiceid'), 'The invoice linked to the proposal wins, the decoy stays free');
		$this->assertSame(array(0, 0, 0, InstallmentSchedule::STATUS_TOCHECK), array_column($schedule->lines, 'status'), 'Not found, even to come: to check');

		$report = $installments->import($user, false);
		$this->assertSame(0, $report['schedules'], 'Nothing imported twice');
		$drop = ($db->type == 'pgsql' ? "DROP TABLE " : "DROP TEMPORARY TABLE ");	// Without TEMPORARY, MySQL commits the transaction of the tests
		$this->assertTrue((bool) $db->query($drop.$p."echeance"));
		$this->assertTrue((bool) $db->query($drop.$p."echeancier"));
	}

	/**
	 * Only a signed proposal or a validated order is invoiced: never a draft, refused or cancelled one, by the job or by hand
	 *
	 * @return void
	 */
	public function testInstallmentsBillableDocuments()
	{
		$user = $this->savuser;
		$installments = new InstallmentSchedule($this->savdb);
		$socid = $this->createCustomer('billable');
		$line = array(array('date' => dol_now(), 'amount' => 120.0, 'mode' => 0, 'note' => ''));
		$documents = array(
			array($this->createProposal($socid, array(array(100, 20, 0)), Propal::STATUS_NOTSIGNED), false),
			array($this->createProposal($socid, array(array(100, 20, 0)), Propal::STATUS_DRAFT), false),
			array($this->createOrder($socid, array(array(100, 20)), Commande::STATUS_CANCELED), false),
			array($this->createOrder($socid, array(array(100, 20)), Commande::STATUS_DRAFT), false),
			array($this->createProposal($socid, array(array(100, 20, 0)), Propal::STATUS_BILLED), true),
			array($this->createOrder($socid, array(array(100, 20)), Commande::STATUS_CLOSED), true),
		);
		foreach ($documents as $document) {
			$this->assertGreaterThan(0, $installments->save($user, $document[0], $line, 1), $installments->error);
		}
		$this->assertSame(0, $installments->createDueInvoices(), 'The job does not even try: '.$installments->output);
		foreach ($documents as $document) {
			$schedule = $installments->fetch($document[0]->element, $document[0]->id);
			$this->assertSame($document[1], $schedule->lines[0]->invoiceid > 0, $document[0]->element.' status '.$document[0]->status);
			if (!$document[1]) {
				$this->assertSame(-1, $installments->createInvoice($user, $schedule, $schedule->lines[0]), 'Not by hand either');
			}
		}
	}

	/**
	 * Cases found by the review of 1.1.0: a schedule of an order not changed with it is not copied (the validation goes on); the invoice of
	 * an installment is not split by the template of its payment term; an amount too small for a template does not stop a validation;
	 * a schedule saved again keeps its installments to check and abandoned; an abandoned installment is not invoiced with the last one;
	 * only an installment to invoice can be abandoned, never linked to an abandoned invoice
	 *
	 * @return void
	 */
	public function testInstallmentsReviewCases()
	{
		global $conf;

		$db = $this->savdb;
		$user = $this->savuser;
		$p = $db->prefix();
		$installments = new InstallmentSchedule($db);
		$socid = $this->createCustomer('review');
		$today = dol_now();

		// Order of 1200 with expected payments, its total changed later: the invoice of 1500 is validated without schedule
		$order = $this->createOrder($socid, array(array(1000, 20)));
		$this->assertGreaterThan(0, $installments->save($user, $order, $installments->generate(1200.0, 3, 1, 0, $today)), $installments->error);
		$this->assertTrue((bool) $db->query("UPDATE ".$p."commande SET total_ttc = 1500 WHERE rowid = ".((int) $order->id)));
		$invoice = $this->createInvoice($socid, array(array(1250, 20)), array('commande' => $order->id));
		$this->assertSame(0, $installments->copyFromOrigin($user, $invoice), 'A stale schedule is not copied');
		$this->assertSame(0, $installments->fetch('facture', $invoice->id));

		// Template of the payment term 1: never on the invoice of an installment; an amount too small, said but not blocking
		$code = (string) dol_getIdFromCode($db, 1, 'c_payment_term', 'rowid', 'code', 1);
		$this->assertTrue((bool) $db->query("INSERT INTO ".$p."c_installments_template (entity, code, label, nb, split_rule, payment_term_code, position, active) VALUES (".((int) $conf->entity).", 'PSTESTREV', 'PSTEST rev', 1, '50:0;50:30', '".$db->escape($code)."', 99, 1)"));
		$billing = $this->createOrder($socid, array(array(300, 20)));
		$this->assertTrue((bool) $db->query("UPDATE ".$p."commande SET fk_cond_reglement = 1 WHERE rowid = ".((int) $billing->id)));
		$lines = array();
		foreach (array(0, 30, 60) as $days) {
			$lines[] = array('date' => dol_time_plus_duree($today, $days, 'd'), 'amount' => 120.0, 'mode' => 0, 'note' => '');
		}
		$this->assertGreaterThan(0, $installments->save($user, $billing, $lines, 1), $installments->error);
		$schedule = $installments->fetch('commande', $billing->id);
		$first = new Facture($db);
		$first->fetch($installments->createInvoice($user, $schedule, $schedule->lines[0], InstallmentSchedule::INVOICE_DRAFT));
		$this->assertSame(1, (int) $first->cond_reglement_id);
		$this->assertSame(0, $installments->applyPaymentTermTemplate($user, $first), 'An installment is not split again');
		$this->assertSame(0, $installments->fetch('facture', $first->id));
		$tiny = $this->createInvoice($socid, array(array(0.01, 0)));
		$this->assertSame(0, $installments->applyPaymentTermTemplate($user, $tiny), 'Half of 0.01: said, not blocking');

		// Saved again: the installment to check and the abandoned one stay so
		$this->assertTrue((bool) $db->query("UPDATE ".$p."installments_line SET status = ".InstallmentSchedule::STATUS_ABANDONED." WHERE rowid = ".((int) $schedule->lines[1]->id)));
		$schedule = $installments->fetch('commande', $billing->id);
		$given = array();
		foreach ($schedule->lines as $line) {
			$given[] = array('id' => $line->id, 'date' => $line->date, 'amount' => $line->amount, 'mode' => $line->mode, 'note' => $line->note);
		}
		$this->assertGreaterThan(0, $installments->save($user, $billing, $given, 1), $installments->error);
		$schedule = $installments->fetch('commande', $billing->id);
		$this->assertSame(InstallmentSchedule::STATUS_ABANDONED, $schedule->lines[1]->status, 'Kept abandoned');

		// The last installment: its own amount, the abandoned one is not invoiced
		$last = new Facture($db);
		$last->fetch($installments->createInvoice($user, $schedule, $schedule->lines[2], InstallmentSchedule::INVOICE_DRAFT));
		$this->assertSame(120.0, (float) price2num($last->total_ttc, 'MT'));

		// Abandon: installments to invoice only; never linked to an abandoned invoice
		$own = $this->createInvoice($socid, array(array(100, 20)));
		$this->assertGreaterThan(0, $installments->save($user, $own, $installments->generate(120.0, 2, 1, 0, $today)), $installments->error);
		$this->assertSame(-1, $installments->settle($user, $installments->fetch('facture', $own->id)->lines[0]->id, 0), 'An expected payment is never abandoned');
		$other = $this->createOrder($socid, array(array(100, 20)));
		$this->assertGreaterThan(0, $installments->save($user, $other, array(array('date' => $today, 'amount' => 120.0, 'mode' => 0, 'note' => '')), 1), $installments->error);
		$cancelled = $this->createInvoice($socid, array(array(100, 20)));
		$this->assertTrue((bool) $db->query("UPDATE ".$p."facture SET fk_statut = ".Facture::STATUS_ABANDONED." WHERE rowid = ".((int) $cancelled->id)));
		$this->assertSame(-1, $installments->settle($user, $installments->fetch('commande', $other->id)->lines[0]->id, $cancelled->id), 'Not an abandoned invoice');
	}

	/**
	 * Direct debit of one installment: the core sets the mode of the invoice to direct debit, the other installments keep the mode they had
	 * from the invoice (not debited)
	 *
	 * @return void
	 */
	public function testInstallmentsDebitKeepsOtherModes()
	{
		$db = $this->savdb;
		$user = $this->savuser;
		$installments = new InstallmentSchedule($db);
		$invoice = $this->createInvoice($this->createCustomer('debitmodes'), array(array(1000, 20)));
		$vir = (int) dol_getIdFromCode($db, 'VIR', 'c_paiement', 'code', 'id', 1);
		$pre = (int) dol_getIdFromCode($db, 'PRE', 'c_paiement', 'code', 'id', 1);
		$this->assertTrue((bool) $db->query("UPDATE ".$db->prefix()."facture SET fk_mode_reglement = ".$vir." WHERE rowid = ".((int) $invoice->id)));
		$invoice->fetch($invoice->id);
		$today = dol_now();
		$lines = array(array('date' => dol_time_plus_duree($today, 2, 'd'), 'amount' => 400.0, 'mode' => $pre, 'note' => ''), array('date' => dol_time_plus_duree($today, 3, 'd'), 'amount' => 400.0, 'mode' => 0, 'note' => ''),
			array('date' => dol_time_plus_duree($today, 40, 'd'), 'amount' => 400.0, 'mode' => 0, 'note' => ''));
		$this->assertGreaterThan(0, $installments->save($user, $invoice, $lines), $installments->error);

		$this->assertSame(0, $installments->createDirectDebits(), $installments->output);
		$schedule = $installments->fetch('facture', $invoice->id);
		$this->assertSame(array($pre, $vir, $vir), array_column($schedule->lines, 'mode'), 'The others keep the transfer');
		$this->assertSame(array(true, false), array($schedule->lines[0]->debitid > 0, $schedule->lines[1]->debitid > 0));
	}

	/**
	 * An invoice is never emailed without its PDF
	 *
	 * @return void
	 */
	public function testInstallmentsSendNeedsPdf()
	{
		global $langs;

		$installments = new InstallmentSchedule($this->savdb);
		$invoice = $this->createInvoice($this->createCustomer('nopdf'), array(array(100, 20)));
		$hook = $this->captureMails();
		$template = $this->createEmailTemplate('Invoice __REF__', 'Hello');
		$this->assertSame(-1, $installments->sendInvoice($this->savuser, $invoice, $template, $langs));
		$this->assertSame(array(), $hook->mails);
	}

	/**
	 * An installment to check: linked to the invoice made by hand (validated, same customer, not a credit note, not the invoice of
	 * another installment) or abandoned
	 *
	 * @return void
	 */
	public function testInstallmentsSettle()
	{
		$db = $this->savdb;
		$user = $this->savuser;
		$installments = new InstallmentSchedule($db);
		$socid = $this->createCustomer('settle');
		$order = $this->createOrder($socid, array(array(200, 20)));
		$past = dol_time_plus_duree(dol_now(), -20, 'd');
		$lines = array(array('date' => $past, 'amount' => 120.0, 'mode' => 0, 'note' => ''), array('date' => $past, 'amount' => 120.0, 'mode' => 0, 'note' => ''));
		$this->assertGreaterThan(0, $installments->save($user, $order, $lines, 1), $installments->error);
		$schedule = $installments->fetch('commande', $order->id);
		$good = $this->createInvoice($socid, array(array(100, 20)));
		$draft = $this->createInvoice($socid, array(array(100, 20)));
		$credit = $this->createInvoice($socid, array(array(100, 20)));
		$other = $this->createInvoice($this->createCustomer('settleother'), array(array(100, 20)));
		$this->assertTrue((bool) $db->query("UPDATE ".$db->prefix()."facture SET fk_statut = ".Facture::STATUS_DRAFT." WHERE rowid = ".((int) $draft->id)));
		$this->assertTrue((bool) $db->query("UPDATE ".$db->prefix()."facture SET type = ".Facture::TYPE_CREDIT_NOTE." WHERE rowid = ".((int) $credit->id)));

		foreach (array('draft' => $draft, 'credit note' => $credit, 'other customer' => $other) as $why => $invoice) {
			$this->assertSame(-1, $installments->settle($user, $schedule->lines[0]->id, $invoice->id), $why);
		}
		$this->assertSame(1, $installments->settle($user, $schedule->lines[0]->id, $good->id), $installments->error);
		$this->assertSame(-1, $installments->settle($user, $schedule->lines[1]->id, $good->id), 'One installment per invoice');
		$this->assertSame(1, $installments->settle($user, $schedule->lines[1]->id, 0), 'Abandoned');
		$schedule = $installments->fetch('commande', $order->id);
		$this->assertSame(array((int) $good->id, 0), array_column($schedule->lines, 'invoiceid'));
		$this->assertSame(array(InstallmentSchedule::STATUS_OPEN, InstallmentSchedule::STATUS_ABANDONED), array_column($schedule->lines, 'status'));
	}

	/**
	 * The list: expected payments of a proposal left out (its invoice takes them), installments of an abandoned invoice abandoned,
	 * and what is paid read like allocate(): payments, credit notes and deposits, not the other discounts
	 *
	 * @return void
	 */
	public function testInstallmentsListScope()
	{
		$db = $this->savdb;
		$user = $this->savuser;
		$installments = new InstallmentSchedule($db);
		$socid = $this->createCustomer('scope');
		$past = dol_time_plus_duree(dol_now(), -20, 'd');
		$proposal = $this->createProposal($socid, array(array(100, 20, 0)));
		$this->assertGreaterThan(0, $installments->save($user, $proposal, array(array('date' => $past, 'amount' => 120.0, 'mode' => 0, 'note' => ''))), $installments->error);
		$abandoned = $this->createInvoice($socid, array(array(100, 20)));
		$this->assertGreaterThan(0, $installments->save($user, $abandoned, array(array('date' => $past, 'amount' => 120.0, 'mode' => 0, 'note' => ''))), $installments->error);
		$this->assertTrue((bool) $db->query("UPDATE ".$db->prefix()."facture SET fk_statut = ".Facture::STATUS_ABANDONED." WHERE rowid = ".((int) $abandoned->id)));
		$invoice = $this->createInvoice($socid, array(array(100, 20)));
		$lines = array(array('date' => $past, 'amount' => 60.0, 'mode' => 0, 'note' => ''), array('date' => $past, 'amount' => 60.0, 'mode' => 0, 'note' => ''));
		$this->assertGreaterThan(0, $installments->save($user, $invoice, $lines), $installments->error);
		$credit = $this->createInvoice($socid, array(array(50, 20)));
		$this->assertTrue((bool) $db->query("UPDATE ".$db->prefix()."facture SET type = ".Facture::TYPE_CREDIT_NOTE." WHERE rowid = ".((int) $credit->id)));
		foreach (array((int) $credit->id, 'NULL') as $source) {	// A credit note used, and a discount coming from no invoice
			$sql = "INSERT INTO ".$db->prefix()."societe_remise_except (entity, fk_soc, amount_ht, amount_tva, amount_ttc, tva_tx, fk_user, description, fk_facture_source, fk_facture, datec)";
			$sql .= " VALUES (1, ".((int) $socid).", 50, 10, 60, 20, ".((int) $user->id).", 'PSTEST', ".$source.", ".((int) $invoice->id).", '".$db->idate(dol_now())."')";
			$this->assertTrue((bool) $db->query($sql), (string) $db->lasterror());
		}
		$invoice->fetch($invoice->id);
		$schedule = $installments->fetch('facture', $invoice->id);

		$rows = $installments->getInstallments($user, array('socid' => $socid));
		$this->assertSame(array('abandoned', 'paid', 'late'), array_column($rows, 'state'), 'Nothing of the proposal');
		$this->assertSame(array_values($installments->allocate($invoice, $schedule->lines)), array(60.0, 0.0));
		$this->assertSame(array(60.0, 0.0), array_column(array_slice($rows, 1), 'paid'), 'Like allocate()');
	}

	/**
	 * Replace the sending of emails by a hook that records them
	 *
	 * @return CommonHookActions	Hook, with the recipients and subject of each email in ->mails
	 */
	private function captureMails()
	{
		global $conf, $hookmanager;

		$this->savhookmanager = $hookmanager;
		$this->savmailfrom = getDolGlobalString('MAIN_MAIL_EMAIL_FROM');
		$conf->global->MAIN_DISABLE_ALL_MAILS = 0;
		$conf->global->MAIN_MAIL_EMAIL_FROM = 'robot@example.com';
		$hook = new class extends CommonHookActions {
			/**
			 * @var string[] Recipients and subject of each email
			 */
			public $mails = array();

			/**
			 * Record the email instead of sending it
			 *
			 * @param	array<string,mixed>	$parameters		Hook parameters
			 * @param	CMailFile			$object			Email
			 * @return	int									1 to replace the sending
			 */
			public function sendMail($parameters, $object)
			{
				$this->mails[] = $object->addr_to.'|'.$object->subject;
				return 1;
			}
		};
		$hookmanager = new HookManager($this->savdb);
		$hookmanager->contextarray = array('mail');
		$hookmanager->hooks['mail']['installmentstest'] = $hook;
		$hookmanager->hooksSorted['mail']['50:installmentstest'] = $hook;

		return $hook;
	}

	/**
	 * Number of rows counted by a query
	 *
	 * @param	string	$sql	Query returning the column nb
	 * @return	int|float		Number
	 */
	private function countRows($sql)
	{
		$resql = $this->savdb->query($sql);
		$this->assertTrue((bool) $resql, (string) $this->savdb->lasterror());
		$nb = $this->savdb->fetch_object($resql)->nb;

		return (strpos((string) $nb, '.') !== false ? (float) $nb : (int) $nb);
	}

	/**
	 * Due date of an invoice in the database
	 *
	 * @param	int		$id		Invoice
	 * @return	string			Day, YYYY-MM-DD
	 */
	private function getDueDay($id)
	{
		$resql = $this->savdb->query("SELECT date_lim_reglement FROM ".$this->savdb->prefix()."facture WHERE rowid = ".((int) $id));
		$this->assertTrue((bool) $resql, (string) $this->savdb->lasterror());

		return dol_print_date($this->savdb->jdate($this->savdb->fetch_object($resql)->date_lim_reglement), 'dayrfc');
	}

	/**
	 * Create a customer, with its billing email
	 *
	 * @param	string	$name		End of the name and of the email
	 * @param	string	$vatnumber	VAT number: a company
	 * @return	int					Id
	 */
	private function createCustomer($name, $vatnumber = '')
	{
		$soc = new Societe($this->savdb);
		$soc->name = 'PSTEST '.$name;
		$soc->email = 'pstest-'.$name.'@example.com';
		$soc->tva_intra = $vatnumber;
		$soc->client = 1;
		$soc->code_client = -1;
		$this->assertGreaterThanOrEqual(0, $soc->create($this->savuser), (string) $soc->error);

		return $soc->id;
	}

	/**
	 * Create a validated unpaid invoice
	 *
	 * @param	int								$socid		Customer
	 * @param	array<int,array{0:float,1:float}>	$lines		Price without tax and VAT rate of each line
	 * @param	array<string,int>				$linked		Linked documents
	 * @return	Facture								Invoice, read again
	 */
	private function createInvoice($socid, array $lines, array $linked = array())
	{
		$db = $this->savdb;
		$invoice = new Facture($db);
		$invoice->socid = $socid;
		$invoice->date = dol_now();
		$invoice->cond_reglement_id = 1;
		$invoice->linked_objects = $linked;
		$this->assertGreaterThan(0, $invoice->create($this->savuser), (string) $invoice->error);
		foreach ($lines as $line) {
			$this->assertGreaterThan(0, $invoice->addline('Test', $line[0], 1, $line[1]));
		}
		$this->assertTrue((bool) $db->query("UPDATE ".$db->prefix()."facture SET fk_statut = ".Facture::STATUS_VALIDATED.", paye = 0 WHERE rowid = ".((int) $invoice->id)), (string) $db->lasterror());
		$id = $invoice->id;
		$invoice = new Facture($db);
		$invoice->fetch($id);

		return $invoice;
	}

	/**
	 * Create a proposal
	 *
	 * @param	int											$socid	Customer
	 * @param	array<int,array{0:float,1:float,2:int}>		$lines	Price without tax, VAT rate and type (0 product, 1 service) of each line
	 * @param	int											$status	Status, signed by default
	 * @return	Propal												Proposal, read again
	 */
	private function createProposal($socid, array $lines, $status = Propal::STATUS_SIGNED)
	{
		$proposal = new Propal($this->savdb);
		$proposal->socid = $socid;
		$proposal->date = dol_now();
		$proposal->duree_validite = 30;
		$this->assertGreaterThan(0, $proposal->create($this->savuser), (string) $proposal->error);
		foreach ($lines as $line) {
			$this->assertGreaterThan(0, $proposal->addline('Test', $line[0], 1, $line[1], 0, 0, 0, 0, 'HT', 0, 0, $line[2]), (string) $proposal->error);
		}
		$this->assertTrue((bool) $this->savdb->query("UPDATE ".$this->savdb->prefix()."propal SET fk_statut = ".((int) $status)." WHERE rowid = ".((int) $proposal->id)));
		$id = $proposal->id;
		$proposal = new Propal($this->savdb);
		$proposal->fetch($id);

		return $proposal;
	}

	/**
	 * Create an order
	 *
	 * @param	int								$socid	Customer
	 * @param	array<int,array{0:float,1:float}>	$lines	Price without tax and VAT rate of each line
	 * @param	int								$status	Status, validated by default
	 * @return	Commande							Order, read again
	 */
	private function createOrder($socid, array $lines, $status = Commande::STATUS_VALIDATED)
	{
		$order = new Commande($this->savdb);
		$order->socid = $socid;
		$order->date = dol_now();
		$this->assertGreaterThan(0, $order->create($this->savuser), (string) $order->error);
		foreach ($lines as $line) {
			$this->assertGreaterThan(0, $order->addline('Test', $line[0], 1, $line[1]), (string) $order->error);
		}
		$this->assertTrue((bool) $this->savdb->query("UPDATE ".$this->savdb->prefix()."commande SET fk_statut = ".((int) $status)." WHERE rowid = ".((int) $order->id)));
		$id = $order->id;
		$order = new Commande($this->savdb);
		$order->fetch($id);

		return $order;
	}

	/**
	 * Record a payment of an invoice, by SQL: Paiement::create() runs the triggers
	 *
	 * @param	int		$id			Invoice
	 * @param	float	$amount		Amount paid
	 * @return	void
	 */
	private function addPayment($id, $amount)
	{
		$db = $this->savdb;
		$sql = "INSERT INTO ".$db->prefix()."paiement (entity, ref, datec, datep, amount, fk_paiement) VALUES (1, 'PSTEST', '".$db->idate(dol_now())."', '".$db->idate(dol_now())."', ".((float) $amount).", ".((int) dol_getIdFromCode($db, 'LIQ', 'c_paiement', 'code', 'id', 1)).")";
		$this->assertTrue((bool) $db->query($sql), (string) $db->lasterror());
		$payment = $db->last_insert_id($db->prefix().'paiement');
		$sql = "INSERT INTO ".$db->prefix()."paiement_facture (fk_facture, fk_paiement, amount, multicurrency_amount) VALUES (".((int) $id).", ".((int) $payment).", ".((float) $amount).", ".((float) $amount).")";
		$this->assertTrue((bool) $db->query($sql), (string) $db->lasterror());
	}

	/**
	 * A credit note used by an invoice, by SQL
	 *
	 * @param	int		$socid		Customer
	 * @param	int		$id			Invoice that uses it
	 * @param	float	$amount		Amount including tax
	 * @return	void
	 */
	private function addCreditNote($socid, $id, $amount)
	{
		$db = $this->savdb;
		$creditnote = $this->createInvoice($socid, array(array(-$amount / 1.2, 20)));
		$this->assertTrue((bool) $db->query("UPDATE ".$db->prefix()."facture SET type = ".Facture::TYPE_CREDIT_NOTE." WHERE rowid = ".((int) $creditnote->id)));
		$sql = "INSERT INTO ".$db->prefix()."societe_remise_except (entity, fk_soc, datec, amount_ht, amount_tva, amount_ttc, tva_tx, fk_user, fk_facture_source, fk_facture, description)";
		$sql .= " VALUES (1, ".((int) $socid).", '".$db->idate(dol_now())."', ".((float) $amount / 1.2).", ".((float) $amount - $amount / 1.2).", ".((float) $amount).", 20, ".((int) $this->savuser->id).", ".((int) $creditnote->id).", ".((int) $id).", 'PSTEST')";
		$this->assertTrue((bool) $db->query($sql), (string) $db->lasterror());
	}

	/**
	 * An email template of invoices, the default one of the tests
	 *
	 * @param	string	$topic		Subject
	 * @param	string	$content	Body
	 * @return	int					Id
	 */
	private function createEmailTemplate($topic, $content)
	{
		$db = $this->savdb;
		$sql = "INSERT INTO ".$db->prefix()."c_email_templates (entity, type_template, private, datec, label, lang, position, enabled, active, topic, joinfiles, content)";
		$sql .= " VALUES (1, 'facture_send', 0, '".$db->idate(dol_now())."', 'PSTEST', '', -1, '1', 1, '".$db->escape($topic)."', 1, '".$db->escape($content)."')";
		$this->assertTrue((bool) $db->query($sql), (string) $db->lasterror());

		return (int) $db->last_insert_id($db->prefix().'c_email_templates');
	}
}


/**
 * Day of a date, for the assertions
 *
 * @param	int		$date	Date
 * @return	string			YYYY-MM-DD
 */
function installmentsTestDay($date)
{
	return dol_print_date($date, 'dayrfc');
}
