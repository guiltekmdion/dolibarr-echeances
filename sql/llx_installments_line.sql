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
-- Installments of the schedules of the module InstallmentSchedule


CREATE TABLE llx_installments_line(
	rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	fk_schedule integer NOT NULL,
	rang integer DEFAULT 0 NOT NULL,
	date_due date NOT NULL,
	amount double(24,8) NOT NULL,					-- including tax, in the currency of the company
	fk_mode_reglement integer DEFAULT 0 NOT NULL,	-- llx_c_paiement, 0 = the one of the document
	fk_facture integer,								-- invoice of the installment (billing schedules)
	fk_prelevement_demande integer,					-- direct debit request of the installment (llx_prelevement_demande)
	status tinyint DEFAULT 0 NOT NULL,				-- 0 = open, 1 = to check (imported, maybe invoiced by hand), 9 = abandoned
	note text,
	import_key varchar(14)
) ENGINE=innodb;
