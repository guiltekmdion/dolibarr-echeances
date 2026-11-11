<?php
/* Copyright (C) 2004-2018	Laurent Destailleur			<eldy@users.sourceforge.net>
 * Copyright (C) 2018-2019	Nicolas ZABOURI				<info@inovea-conseil.com>
 * Copyright (C) 2019-2024	Frédéric France				<frederic.france@free.fr>
 * Copyright (C) 2026		Maxime DION				<m.dion@guiltek.fr>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

declare(strict_types=1);

/**
 * 	\defgroup   paymentschedule     Module PaymentSchedule
 *  \brief      PaymentSchedule module descriptor.
 *
 *  \file       htdocs/paymentschedule/core/modules/modPaymentSchedule.class.php
 *  \ingroup    paymentschedule
 *  \brief      Description and activation file for module PaymentSchedule
 */
include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';


/**
 *  Description and activation class for module PaymentSchedule
 */
class modPaymentSchedule extends DolibarrModules
{
	/**
	 * Constructor. Define names, constants, directories, boxes, permissions
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		global $conf, $langs;

		$this->db = $db;

		// Id for module (must be unique).
		// Use here a free id (See in Home -> System information -> Dolibarr for list of used modules id).
		$this->numero = 231255; // In the range 231250-231259 of Guiltek (module Reminders: 231250)

		// Key text used to identify module (for permissions, menus, etc...)
		$this->rights_class = 'paymentschedule';

		// Family can be 'base' (core modules),'crm','financial','hr','projects','products','ecm','technic' (transverse modules),'interface' (link with external tools),'other','...'
		$this->family = "financial";

		// Module position in the family on 2 digits ('01', '10', '20', ...)
		$this->module_position = '13';	// Just after Customer invoices and Reminders

		// Module label (no space allowed), used if translation string 'ModulePaymentScheduleName' not found (PaymentSchedule is name of module).
		$this->name = preg_replace('/^mod/i', '', get_class($this));

		// Module description, used if translation string 'ModulePaymentScheduleDesc' not found (PaymentSchedule is name of module).
		$this->description = 'Payment schedules: an invoice paid in several installments, or a proposal or an order invoiced in several times';
		// Used only if file README.md and README-LL.md not found.
		$this->descriptionlong = 'Payment schedules: an invoice paid in several installments, or a proposal or an order invoiced in several times';

		// Author
		$this->editor_name = 'Guiltek';
		$this->editor_url = 'https://www.guiltek.fr';		// Must be an external online web site
		$this->editor_squarred_logo = '';

		// Possible values for version are: 'development', 'experimental', 'dolibarr', 'dolibarr_deprecated', 'experimental_deprecated' or a version string like 'x.y.z'
		$this->version = '1.0.0';

		// Key used in llx_const table to save module status enabled/disabled (where PAYMENTSCHEDULE is value of property name of module in uppercase)
		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);

		// Name of image file used for this module.
		$this->picto = 'fa-calendar-alt';

		// Define some features supported by module (triggers, login, substitutions, menus, css, etc...)
		$this->module_parts = array(
			// Set this to 1 if module has its own trigger directory (core/triggers)
			'triggers' => 1,	// Due date of the invoices on their next unpaid installment, copy from the order or proposal, deletions
			// Set this to 1 if module has its own login method file (core/login)
			'login' => 0,
			// Set this to 1 if module has its own substitution function file (core/substitutions)
			'substitutions' => 1,	// Key __PAYMENTSCHEDULE__ for the notes, emails and ODT documents
			// Set this to 1 if module has its own menus handler directory (core/menus)
			'menus' => 0,
			// Set this to 1 if module overwrite template dir (core/tpl)
			'tpl' => 0,
			// Set this to 1 if module has its own barcode directory (core/modules/barcode)
			'barcode' => 0,
			// Set this to 1 if module has its own models directory (core/modules/xxx)
			'models' => 0,
			// Set this to 1 if module has its own printing directory (core/modules/printing)
			'printing' => 0,
			// Set this to 1 if module has its own theme directory (theme)
			'theme' => 0,
			// Set this to relative path of css file if module has its own css file
			'css' => array(),
			// Set this to relative path of js file if module must load a js on all pages
			'js' => array(),
			// Set here all hooks context managed by module. To find available hook context, make a "grep -r '>initHooks(' *" on source code. You can also set hook context to 'all'
			/* BEGIN MODULEBUILDER HOOKSCONTEXTS */
			'hooks' => array(
				'invoicecard',		// Warning when the due date of an invoice with a schedule is edited by hand
				'pdfgeneration',	// The schedule under the totals of the PDF of an invoice
			),
			/* END MODULEBUILDER HOOKSCONTEXTS */
			// Set this to 1 if features of module are opened to external users
			'moduleforexternal' => 0,
			// Set this to 1 if the module provides a website template into doctemplates/websites/website_template-mytemplate
			'websitetemplates' => 0,
			// Set this to 1 if the module provides a captcha driver
			'captcha' => 0
		);

		// Data directories to create when module is enabled.
		$this->dirs = array();	// The module stores no file

		// Config pages. Put here list of php page, stored into paymentschedule/admin directory, to use to setup module.
		$this->config_page_url = array("setup.php@paymentschedule");

		// Dependencies
		$this->hidden = getDolGlobalInt('MODULE_PAYMENTSCHEDULE_DISABLED'); // A condition to disable module;
		$this->depends = array('modFacture', 'modCron');	// The invoices of the installments and the direct debits are made by scheduled jobs
		$this->requiredby = array();
		$this->conflictwith = array();

		// The language file dedicated to your module
		$this->langfiles = array("paymentschedule@paymentschedule");

		// Prerequisites
		$this->phpmin = array(8, 1); // Minimum version of PHP required by module
		$this->need_dolibarr_version = array(24, 0); // Minimum version of Dolibarr required by module
		$this->need_javascript_ajax = 0;

		// Messages at activation
		$this->warnings_activation = array();
		$this->warnings_activation_ext = array();

		// Constants: the date of the first run of the job is deleted with the module, so a module enabled again does not invoice the installments passed meanwhile
		$this->const = array(
			0 => array('PAYMENTSCHEDULE_START_DATE', 'chaine', '', 'Date of the first run of the job: the installments due before are invoiced by hand', 0, 'current', 1),
		);

		if (!isModEnabled("paymentschedule")) {
			$conf->paymentschedule = new stdClass();
			$conf->paymentschedule->enabled = 0;
		}

		// Array to add new pages in new tabs
		/* BEGIN MODULEBUILDER TABS */
		$this->tabs = array(
			'invoice:+paymentschedule:PaymentSchedule:paymentschedule@paymentschedule:$user->hasRight("paymentschedule", "read") && $user->hasRight("facture", "lire"):/paymentschedule/schedule.php?element=facture&id=__ID__',
			'propal:+paymentschedule:PaymentSchedule:paymentschedule@paymentschedule:$user->hasRight("paymentschedule", "read") && $user->hasRight("propal", "lire"):/paymentschedule/schedule.php?element=propal&id=__ID__',
			'order:+paymentschedule:PaymentSchedule:paymentschedule@paymentschedule:$user->hasRight("paymentschedule", "read") && $user->hasRight("commande", "lire"):/paymentschedule/schedule.php?element=commande&id=__ID__',
			'thirdparty:+paymentschedule:PaymentSchedule:paymentschedule@paymentschedule:$user->hasRight("paymentschedule", "read") && $user->hasRight("facture", "lire"):/paymentschedule/list.php?socid=__ID__',
		);
		/* END MODULEBUILDER TABS */

		// Dictionaries
		/* BEGIN MODULEBUILDER DICTIONARIES */
		$this->dictionaries = array(
			'langs' => 'paymentschedule@paymentschedule',
			'tabname' => array('c_paymentschedule_template'),
			'tablib' => array('PaymentScheduleTemplates'),
			'tabsql' => array('SELECT t.rowid as rowid, t.code, t.label, t.nb, t.months, t.days, t.first_percent, t.mode_code, t.position, t.active, t.entity FROM '.$this->db->prefix().'c_paymentschedule_template as t WHERE t.entity IN ('.getEntity('c_paymentschedule_template').')'),
			'tabsqlsort' => array('position ASC, label ASC'),
			'tabfield' => array('code,label,nb,months,days,first_percent,mode_code,position'),
			'tabfieldvalue' => array('code,label,nb,months,days,first_percent,mode_code,position'),
			'tabfieldinsert' => array('code,label,nb,months,days,first_percent,mode_code,position,entity'),
			'tabrowid' => array('rowid'),
			'tabcond' => array(isModEnabled('paymentschedule')),
			'tabhelp' => array(array('nb' => $langs->trans('PaymentScheduleHelpNb'), 'months' => $langs->trans('PaymentScheduleHelpMonths'), 'days' => $langs->trans('PaymentScheduleHelpDays'),
				'first_percent' => $langs->trans('PaymentScheduleHelpFirstPercent'), 'mode_code' => $langs->trans('PaymentScheduleHelpModeCode'))),
		);
		/* END MODULEBUILDER DICTIONARIES */

		// Boxes/Widgets
		/* BEGIN MODULEBUILDER WIDGETS */
		$this->boxes = array(
			0 => array(
				'file' => 'box_paymentschedule.php@paymentschedule',
				'note' => 'Installments expected this month and late ones',
				'enabledbydefaulton' => 'Home',
			),
		);
		/* END MODULEBUILDER WIDGETS */

		// Cronjobs (List of cron jobs entries to add when module is enabled)
		/* BEGIN MODULEBUILDER CRON */
		$this->cronjobs = array(
			0 => array(
				'label' => 'PaymentScheduleInvoiceJob:paymentschedule@paymentschedule',
				'jobtype' => 'method',
				'class' => '/paymentschedule/class/paymentschedule.class.php',
				'objectname' => 'PaymentSchedule',
				'method' => 'createDueInvoices',
				'parameters' => '',
				'comment' => 'PaymentScheduleInvoiceJobComment',
				'frequency' => 1,
				'unitfrequency' => 3600 * 24,
				'status' => 0,
				'test' => 'isModEnabled("paymentschedule") && isModEnabled("invoice")',
				'priority' => 50,
			),
			1 => array(
				'label' => 'PaymentScheduleDebitJob:paymentschedule@paymentschedule',
				'jobtype' => 'method',
				'class' => '/paymentschedule/class/paymentschedule.class.php',
				'objectname' => 'PaymentSchedule',
				'method' => 'createDirectDebits',
				'parameters' => '',
				'comment' => 'PaymentScheduleDebitJobComment',
				'frequency' => 1,
				'unitfrequency' => 3600 * 24,
				'status' => 0,
				'test' => 'isModEnabled("paymentschedule") && isModEnabled("prelevement")',
				'priority' => 50,
			),
		);
		/* END MODULEBUILDER CRON */

		// Permissions provided by this module
		$this->rights = array();
		$r = 0;
		/* BEGIN MODULEBUILDER PERMISSIONS */
		$this->rights[$r][0] = $this->numero.sprintf("%02d", 1); // Permission id (must not be already used)
		$this->rights[$r][1] = 'Read payment schedules'; // Permission label
		$this->rights[$r][4] = 'read'; // In php code, permission will be checked by test if ($user->hasRight('paymentschedule', 'read'))
		$r++;
		$this->rights[$r][0] = $this->numero.sprintf("%02d", 2);
		$this->rights[$r][1] = 'Create and modify payment schedules, invoice their installments';
		$this->rights[$r][4] = 'write';
		$r++;
		/* END MODULEBUILDER PERMISSIONS */

		// Main menu entries to add
		$this->menu = array();
		$r = 0;
		/* BEGIN MODULEBUILDER TOPMENU */
		// No top menu: the list of the installments is in the left menu of Billing
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=billing',
			'type' => 'left',
			'titre' => 'PaymentScheduleMenu',
			'prefix' => img_picto('', $this->picto, 'class="paddingright pictofixedwidth"'),
			'mainmenu' => 'billing',
			'leftmenu' => 'paymentschedule',
			'url' => '/paymentschedule/list.php',
			'langs' => 'paymentschedule@paymentschedule',
			'position' => 120,
			'enabled' => 'isModEnabled("paymentschedule") && isModEnabled("invoice")',
			'perms' => '$user->hasRight("paymentschedule", "read") && $user->hasRight("facture", "lire")',
			'target' => '',
			'user' => 0,
		);
		/* END MODULEBUILDER TOPMENU */
	}

	/**
	 *  Function called when module is enabled.
	 *  The init function add constants, boxes, permissions and menus (defined in constructor) into Dolibarr database.
	 *  It also creates data directories
	 *
	 *  @param      string  $options    Options when enabling module ('', 'noboxes')
	 *  @return     int<-1,1>          	1 if OK, <=0 if KO
	 */
	public function init($options = '')
	{
		$result = $this->_load_tables('/paymentschedule/sql/');
		if ($result < 0) {
			return -1;
		}

		// The templates ready to use, when the dictionary of the entity is empty
		dol_include_once('/paymentschedule/class/paymentschedule.class.php');
		$paymentschedule = new PaymentSchedule($this->db);
		if ($paymentschedule->initTemplates() < 0) {
			$this->error = $paymentschedule->error;
			return -1;
		}

		// Permissions
		$this->remove($options);

		return $this->_init(array(), $options);
	}

	/**
	 *	Function called when module is disabled.
	 *	Remove from database constants, boxes and permissions from Dolibarr database.
	 *	Data directories are not deleted
	 *
	 *	@param	string		$options	Options when enabling module ('', 'noboxes')
	 *	@return	int<-1,1>				1 if OK, <=0 if KO
	 */
	public function remove($options = '')
	{
		return $this->_remove(array(), $options);
	}
}
