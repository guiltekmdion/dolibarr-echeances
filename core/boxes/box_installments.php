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
 * \file    installments/core/boxes/box_installments.php
 * \ingroup installments
 * \brief   Widget: the late installments and those expected up to the end of the month
 */

include_once DOL_DOCUMENT_ROOT.'/core/boxes/modules_boxes.php';
dol_include_once('/installments/class/installmentschedule.class.php');


/**
 * Widget of the module InstallmentSchedule
 */
class box_installments extends ModeleBoxes
{
	/**
	 * @var string Alphanumeric ID. Populated by the constructor.
	 */
	public $boxcode = 'installments';

	/**
	 * @var string Box icon (in configuration page)
	 */
	public $boximg = 'fa-calendar-alt';

	/**
	 * @var string Box label (in configuration page)
	 */
	public $boxlabel = 'InstallmentsWidget';

	/**
	 * @var string Box language file if it needs a specific language file.
	 */
	public $lang = 'installments@installments';

	/**
	 * @var string[] Module dependencies
	 */
	public $depends = array('installments');


	/**
	 * Constructor
	 *
	 * @param	DoliDB	$db		Database handler
	 * @param	string	$param	More parameters
	 */
	public function __construct(DoliDB $db, $param = '')
	{
		global $user;

		parent::__construct($db, $param);
		$this->hidden = !($user->hasRight('installments', 'read') && $user->hasRight('facture', 'lire'));
	}

	/**
	 * Load the installments late or due up to the end of the month
	 *
	 * @param	int<0,max>	$max	Maximum number of records to load
	 * @return	void
	 */
	public function loadBox($max = 5)
	{
		global $conf, $langs, $user;

		$this->max = $max;
		$langs->loadLangs(array('bills', 'installments@installments'));
		$this->info_box_head = array('text' => $langs->trans('InstallmentsWidgetTitle', $max), 'limit' => 0, 'sublink' => dol_buildpath('/installments/list.php', 1),
			'subpicto' => 'list', 'subtext' => $langs->trans('List'));
		$this->info_box_contents = array();
		if ($this->hidden) {
			return;
		}
		$installments = new InstallmentSchedule($this->db);
		$rows = $installments->getInstallments($user, array('date_end' => dol_get_last_day((int) dol_print_date(dol_now(), '%Y'), (int) dol_print_date(dol_now(), '%m')),
			'states' => array('late', 'todo', 'tocheck')));	// To check: never invoiced by the job, the user decides
		if (!is_array($rows)) {
			$this->info_box_contents[0][0] = array('td' => '', 'maxlength' => 500, 'text' => dolPrintLabel($installments->error));
			return;
		}
		foreach (array_slice($rows, 0, $max) as $i => $row) {
			$url = dol_buildpath('/installments/schedule.php', 1).'?element='.urlencode($row->element).'&id='.$row->fk_element;
			$this->info_box_contents[$i][0] = array('td' => 'class="nowraponall"', 'text' => dolPrintLabel($row->ref), 'url' => $url);
			$this->info_box_contents[$i][1] = array('td' => 'class="tdoverflowmax150"', 'text' => dolPrintLabel($row->socname));
			$this->info_box_contents[$i][2] = array('td' => 'class="nowraponall center"', 'text' => dol_print_date($row->date, 'day').($row->state == 'late' ? ' '.img_warning($langs->trans('Late')) : '')
				.($row->state == 'tocheck' ? ' '.img_picto($langs->trans('InstallmentsStateTocheck'), 'help') : ''), 'asis' => 1);
			$this->info_box_contents[$i][3] = array('td' => 'class="nowraponall right amount"', 'text' => price($row->amount - $row->paid, 0, $langs, 1, -1, -1, $conf->currency));
			$this->info_box_contents[$i][4] = array('td' => 'class="right"', 'text' => $langs->trans($row->billing ? 'InstallmentsToInvoice' : 'InstallmentsToCollect'));
		}
		if (empty($rows)) {
			$this->info_box_contents[0][0] = array('td' => 'class="center"', 'text' => '<span class="opacitymedium">'.$langs->trans('None').'</span>', 'asis' => 1);
		}
	}

	/**
	 * Show the widget
	 *
	 * @param	?array<array{text?:string,sublink?:string,subtext?:string,subpicto?:?string,picto?:string,nbcol?:int,limit?:int,subclass?:string,graph?:int<0,1>,target?:string}>	$head	Title of the box
	 * @param	?array<array{tr?:string,td?:string,target?:string,text?:string,text2?:string,textnoformat?:string,tooltip?:string,logo?:string,url?:string,maxlength?:int,asis?:int<0,1>}>	$contents	Lines of the box
	 * @param	int<0,1>	$nooutput	No print, only return string
	 * @return	string
	 */
	public function showBox($head = null, $contents = null, $nooutput = 0)
	{
		return parent::showBox($this->info_box_head, $this->info_box_contents, $nooutput);
	}
}
