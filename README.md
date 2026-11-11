# Payment schedules for Dolibarr: an invoice paid in several times, a proposal invoiced in several times

Free module made by [Guiltek](https://www.guiltek.fr), under the GPL v3 or later. Version française : [README-fr.md](README-fr.md).

In Dolibarr, an invoice has one due date and one payment mode. This module adds a **payment schedule**: several installments, each with its date, amount and payment mode. It serves two needs:

- **an invoice expected in several payments** (30% at the order and 70% after 30 days, 3 direct debits...);
- **a proposal or an order invoiced in several times**: each installment becomes an invoice on its date, made by a scheduled job.

## What it does

- **Payment schedule tab** on invoices, proposals and orders: the installments (date, amount, mode, paid, rest, state to come / late / paid / invoiced), made from a template or "in N times" (number, months and days between two installments, share of the first one), edited line by line, previewed before saving. An installment paid, invoiced or direct debited can no longer change.
- **What is paid** is never typed: the payments, credit notes and deposits of the invoice are given to its installments by date, oldest first.
- **Due date of the invoice = next unpaid installment**, computed again at each payment: the "late" lists, widgets, reminder job and PDF of Dolibarr follow the installments, with no change to the core. The cash forecast of Dolibarr takes this date, but for the whole rest to pay of the invoice.
- **PDF of the invoice** (model sponge): the table of the installments under the totals (beyond 10 installments, those left to pay and one line for the last ones). Key `__INSTALLMENTS__` (one installment per line, in HTML) for the public note, the email templates and the ODT documents.
- **One invoice per installment** (proposals and orders): the job "Invoices of the installments" creates the invoice of each installment on its date, in proportion to each VAT rate of the document (the last installment takes what is left of each line). Set by schedule: draft, validated, or validated and sent by email (chosen template, billing contact else email of the customer, PDF attached). Button "Invoice now" and mass action to do it by hand.
- **Direct debits**: the job "Direct debits of the installments" creates the direct debit request of each installment paid by direct debit, some days before its date (5 by default).
- **List Billing > Payment schedules**: all the installments, filters (state, type, mode, period, search), mass actions "Record the payments" (one payment per invoice, once the bank statement is checked) and "Invoice the installments". The same table in a tab of the customer.
- **Home widget**: the installments late or expected this month.
- **Templates of payment schedules** in Setup > Dictionaries: in 3 times, 30% then 70% after 30 days, 12 monthly installments provided.
- **60 days rule** (France, between professionals, article L441-10 of the Code de commerce): a warning when a schedule of expected payments of a company customer goes beyond 60 days after the date of the invoice.
- **Import of the module Echeancier** (Artis Auxilium): its schedules are copied from Setup > Payment schedules > Import from Echeancier, with a preview; its tables are only read. The past installments of proposals and orders without invoice come "To check": they were maybe invoiced by hand, the job never invoices them.

## With the reminders

The reminder job of Dolibarr (`sendEmailsRemindersOnInvoiceDueDate`) starts on the due date of the invoice: with a schedule, it would remind at each installment with the whole rest to pay. The module warns about it. The free module [Reminders](https://github.com/guiltekmdion/dolibarr-relances) (1.4.0 or later) reminds each late installment for its own amount.

## Tip without module

For simple cases, Dolibarr already does "one expected payment = one invoice": a payment term with a deposit (deposit invoice then final invoice from the proposal or the order), or a template invoice (recurring) for equal monthly installments.

## Requirements

Dolibarr 24.0 or later, with the modules Customer invoices and Scheduled jobs (Commercial proposals, Sales orders, Direct debits and Banks if you use them). PHP 8.1 or later. MySQL/MariaDB or PostgreSQL.

## Installation

1. Download `module_installments-X.Y.Z.zip` from the [releases](https://github.com/guiltekmdion/dolibarr-echeances/releases).
2. In Dolibarr: Home > Setup > Modules > Deploy/install external app/module, then send the zip. Or unzip it into `htdocs/custom` (the folder `installments` must be `htdocs/custom/installments`).
3. Enable the module **Payment schedules** (in Financial modules), then give the rights to the users.

After an update of the module, disable it and enable it again: **required**.

## Setup

1. The templates of payment schedules: Home > Setup > Dictionaries > Templates of payment schedules (number, months and days between two installments, share of the first one, code of the payment mode).
2. To invoice and direct debit automatically: enable the jobs "Invoices of the installments" and "Direct debits of the installments" in Home > Admin tools > Scheduled jobs. **First run of the job of the invoices**: it creates nothing for the installments before it. They are invoiced by hand.
3. Direct debits: Dolibarr keeps one pending request per invoice; set `WITHDRAW_STRICT_CHECK_AMOUNT` to 1 to allow several.

Rights: "Read payment schedules", "Create and modify payment schedules, invoice their installments" (with the rights of invoices, proposals and orders). Recording payments needs the right to record the payments of invoices.

## Support

Questions and bugs: [issues](https://github.com/guiltekmdion/dolibarr-echeances/issues). Custom developments: [Guiltek](https://www.guiltek.fr).

## License

Copyright (C) 2026 Maxime DION, Guiltek.
This program is free software: you can redistribute it and/or modify it under the terms of the GNU General Public License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later version. See [COPYING](COPYING).
