-- Copyright (C) 2026		Maxime DION				<m.dion@guiltek.fr>
--
-- This program is free software: you can redistribute it and/or modify
-- it under the terms of the GNU General Public License as published by
-- the Free Software Foundation, either version 3 of the License, or
-- (at your option) any later version.
--
-- This program is distributed in the hope that it will be useful,
-- but WITHOUT ANY WARRANTY; without even the implied warranty of
-- MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
-- GNU General Public License for more details.
--
-- You should have received a copy of the GNU General Public License
-- along with this program.  If not, see https://www.gnu.org/licenses/.
--
-- Schedules of the module InstallmentSchedule: one per invoice, proposal or order


CREATE TABLE llx_installments_schedule(
	rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	entity integer DEFAULT 1 NOT NULL,
	element varchar(32) NOT NULL,					-- facture, propal or commande
	fk_element integer NOT NULL,
	billing tinyint DEFAULT 0 NOT NULL,				-- 1 = each installment becomes an invoice (proposals and orders), 0 = expected payments
	invoice_mode tinyint DEFAULT 0 NOT NULL,		-- invoice of an installment: 0 = draft, 1 = validated, 2 = validated and sent by email
	fk_email_template integer DEFAULT 0 NOT NULL,	-- llx_c_email_templates (type facture_send) of the mode 2, 0 = the default one
	date_creation datetime NOT NULL,
	tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	fk_user_creat integer NOT NULL,
	import_key varchar(14)							-- ech<id> when imported from the module Echeancier
) ENGINE=innodb;
