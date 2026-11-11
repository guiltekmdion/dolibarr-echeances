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
 * \file    paymentschedule/core/triggers/interface_99_modPaymentSchedule_PaymentScheduleTriggers.class.php
 * \ingroup paymentschedule
 * \brief   Due date of the invoices kept on their next unpaid installment, schedules copied from the order or proposal, and deleted with their document
 */

require_once DOL_DOCUMENT_ROOT.'/core/triggers/dolibarrtriggers.class.php';


/**
 * Triggers of the module PaymentSchedule. They never stop the action of the core: an error is only logged.
 */
class InterfacePaymentScheduleTriggers extends DolibarrTriggers
{
	/**
	 * Constructor
	 *
	 * @param	DoliDB	$db		Database handler
	 */
	public function __construct($db)
	{
		parent::__construct($db);
		$this->family = 'financial';
		$this->description = 'Due dates of the invoices with a payment schedule';
		$this->version = self::VERSIONS['prod'];
		$this->picto = 'fa-calendar-alt';
	}

	/**
	 * Function called when a Dolibarr business event is done
	 *
	 * @param	string			$action		Event action code
	 * @param	CommonObject	$object		Object
	 * @param	User			$user		Object user
	 * @param	Translate		$langs		Object langs
	 * @param	Conf			$conf		Object conf
	 * @return	int							0 (an error is logged, never returned)
	 */
	public function runTrigger($action, $object, User $user, Translate $langs, Conf $conf)
	{
		if (!isModEnabled('paymentschedule')) {
			return 0;
		}
		dol_include_once('/paymentschedule/class/paymentschedule.class.php');
		$schedule = new PaymentSchedule($this->db);
		$result = 0;

		switch ($action) {
			case 'BILL_VALIDATE':
				'@phan-var-force Facture $object';
				$result = min($schedule->copyFromOrigin($user, $object), $schedule->syncDueDate($object));
				break;
			case 'BILL_MODIFY':	// A due date or payment terms changed by hand: the schedule decides
			case 'BILL_PAYED':
			case 'BILL_UNPAYED':
				'@phan-var-force Facture $object';
				$result = $schedule->syncDueDate($object);
				break;
			case 'PAYMENT_CUSTOMER_CREATE':	// The lines of llx_paiement_facture are already there
				'@phan-var-force Paiement $object';
				foreach ((array) $object->amounts as $invoiceid => $amount) {
					if ((float) $amount != 0) {
						$result = min($result, $this->sync($schedule, (int) $invoiceid));
					}
				}
				break;
			case 'PAYMENT_CUSTOMER_DELETE':	// The lines of llx_paiement_facture are still there: their amount is left out
				'@phan-var-force Paiement $object';
				$amounts = $object->getAmountsArray();
				foreach (is_array($amounts) ? $amounts : array() as $invoiceid => $amount) {
					$result = min($result, $this->sync($schedule, (int) $invoiceid, (float) $amount));
				}
				break;
			case 'DISCOUNT_MODIFY':	// A credit note or a deposit used by an invoice
				'@phan-var-force DiscountAbsolute $object';
				$invoiceid = (int) $object->fk_facture;
				if (!$invoiceid && $object->fk_facture_line > 0) {
					$resql = $this->db->query("SELECT fk_facture FROM ".$this->db->prefix()."facturedet WHERE rowid = ".((int) $object->fk_facture_line));
					$obj = ($resql ? $this->db->fetch_object($resql) : null);
					$invoiceid = ($obj ? (int) $obj->fk_facture : 0);
				}
				$result = ($invoiceid ? $this->sync($schedule, $invoiceid) : 0);
				break;
			case 'BILL_DELETE':	// Its schedule goes with it; an installment it invoiced is to check again, never invoiced again by the job
				$result = $schedule->delete($user, $object, true);
				$sql = "UPDATE ".$this->db->prefix()."paymentschedule_line SET fk_facture = NULL, status = ".PaymentSchedule::STATUS_TOCHECK." WHERE fk_facture = ".((int) $object->id);
				$result = ($this->db->query($sql) ? $result : -1);
				break;
			case 'PROPAL_DELETE':
			case 'ORDER_DELETE':
				$result = $schedule->delete($user, $object, true);
				break;
		}
		if ($result < 0) {
			dol_syslog(__METHOD__.' '.$action.' '.$object->id.' '.($schedule->error ?: $this->db->lasterror()), LOG_ERR);
		}

		return 0;
	}

	/**
	 * Due date of an invoice from its installments
	 *
	 * @param	PaymentSchedule		$schedule	Schedules
	 * @param	int					$invoiceid	Invoice
	 * @param	float				$excluded	Amount of a payment being deleted
	 * @return	int								1 if changed, 0 if nothing to do, -1 if error
	 */
	private function sync(PaymentSchedule $schedule, $invoiceid, $excluded = 0.0)
	{
		$invoice = new Facture($this->db);
		if ($invoice->fetch($invoiceid) <= 0) {
			return -1;
		}

		return $schedule->syncDueDate($invoice, $excluded);
	}
}
