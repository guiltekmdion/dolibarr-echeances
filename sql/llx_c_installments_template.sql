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
-- Dictionary of the module InstallmentSchedule: templates of schedules (Setup > Dictionaries)


CREATE TABLE llx_c_installments_template(
	rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	entity integer DEFAULT 1 NOT NULL,
	code varchar(16) NOT NULL,
	label varchar(128) NOT NULL,
	nb integer DEFAULT 1 NOT NULL,					-- number of installments
	months integer DEFAULT 0 NOT NULL,				-- months between two installments
	days integer DEFAULT 0 NOT NULL,				-- days between two installments, added to the months
	first_percent double(6,3) DEFAULT 0 NOT NULL,	-- share of the first installment (%), 0 = equal installments
	mode_code varchar(16),							-- code of the payment mode (llx_c_paiement), empty = the one of the document
	split_rule varchar(255),						-- free installments "percent:days[:eom];..." (e.g. 30:0;30:30;40:60:eom), instead of nb, months, days and first_percent
	payment_term_code varchar(16),					-- code of a payment term (llx_c_payment_term): its documents get this schedule at their validation
	position integer DEFAULT 0 NOT NULL,
	active tinyint DEFAULT 1 NOT NULL
) ENGINE=innodb;
