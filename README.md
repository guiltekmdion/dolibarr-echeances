# Payment schedules for Dolibarr: an invoice paid in several times, a proposal invoiced in several times

Free module made by [Guiltek](https://www.guiltek.fr), under the GPL v3 or later. Version française : [README-fr.md](README-fr.md).

In Dolibarr, an invoice has one due date and one payment mode. This module adds a **payment schedule**: several installments, each with its date, amount and payment mode. It serves two needs:

- **an invoice expected in several payments** (30% at the order and 70% after 30 days, 3 direct debits...);
- **a proposal or an order invoiced in several times**: each installment becomes an invoice on its date, made by a scheduled job.

## What it does

- **Payment schedule tab** on invoices, proposals and orders: the installments (date, amount, mode, paid, rest, state to come / late / paid / invoiced), made from a template or "in N times" (number, months and days between two installments, share of the first one), edited line by line, previewed before saving. An installment paid, invoiced or direct debited can no longer change.
- **What is paid** is never typed: the payments, credit notes and deposits of the invoice are given to its installments by date, oldest first.
- **Due date of the invoice = next unpaid installment**, computed again at each payment: the "late" lists, widgets, reminder job and PDF of Dolibarr follow the installments, with no change to the core.
- **Cash forecast of a bank account** (Banks > account > Upcoming entries): one row per installment left to pay, at its date, with its rank (`FA2611-0001 (2/3)`), instead of the whole rest of the invoice on one date. What the installments do not hold (an abandoned installment, a schedule that does not add up to the invoice) stays on the line of the invoice, so the future balance is the same as without the module. The installments of proposals and orders invoiced in several times are not in it: their invoices do not exist yet.
- **PDF**: the payment terms printed "As per the payment schedule"; the table of the installments under the totals of the invoice (model sponge; beyond 12 installments, in two columns; beyond 48, those left to pay and one line for the last ones), and in the public note of the proposals and orders (all models), in the currency of the document. Key `__INSTALLMENTS__` (one installment per line, in HTML) for the public note, the email templates and the ODT documents: a note with the key says itself where the schedule goes.
- **One invoice per installment** (proposals and orders): the job "Invoices of the installments" creates the invoice of each installment on its date, in proportion to each VAT rate of the document (the last installment takes what is left of each line), with the designations of its lines, in its currency and at its rate. Set by schedule: draft, validated, or validated and sent by email (template chosen, required; billing contact else email of the customer; PDF attached). Button "Invoice now" and mass action to do it by hand. **Never invoiced twice**: an installment is not invoiced when the invoices already linked to the document (or given to its installments) would go beyond its total; it comes "To check", why in its note. When all its installments are invoiced, the document is classified billed. An invoice that could not be validated or sent puts its installment "To check" until it is sent from its card.
- **Direct debits**: the job "Direct debits of the installments" creates the direct debit request of each installment paid by direct debit, some days before its date (5 by default), from its first run on, never for a disputed invoice. An installment without its own mode follows the mode of the invoice. After a rejection, nothing is asked again: you decide.
- **List Billing > Payment schedules**: all the installments, filters (state, type, mode, period, search), mass actions "Record the payments" (one payment per invoice with the reference of the transfer, never more than what the invoice has left to pay, an invoice with a direct debit in progress skipped) and "Invoice the installments" (never one to check). The same table in a tab of the customer.
- **Home widget**: the installments late, to check or expected this month.
- **Templates of payment schedules** in Setup > Dictionaries: in 3 times, 30% then 70% after 30 days, 12 monthly installments provided. A template may have **free installments** (`percent:days[:eom]` separated by `;`, e.g. `30:0;30:30;40:60:eom` = 30% at the date of the document, 30% after 30 days, 40% at the end of the month after 60 days) and a **payment term**: an invoice, a proposal or an order validated with this payment term and without schedule gets it.
- **60 days rule** (France, between professionals, article L441-10 of the Code de commerce): a warning when a schedule of expected payments of a company customer goes beyond 60 days after the date of the invoice.
- **Import of the module Echeancier** (Artis Auxilium): its schedules are copied from Setup > Payment schedules > Import from Echeancier, with a preview; its tables are only read. For each installment of a proposal or an order without invoice, the invoice made by hand is searched (same amount within 45 days): **only an invoice linked to the document is attached**; another invoice of the customer is only suggested, in the note of the installment. The others come "To check", past or future: the job never invoices them.
- **An installment to check** is never invoiced, by the job or by hand: on the tab of the document, "Already invoiced" (an invoice of the customer, not smaller than the installment), "Abandon", or "Invoice again" once checked. These actions, and "Not this invoice", need the right to create invoices too, and write an event in the agenda of the document.
- **A schedule made on a draft** whose total changed is put to the scale of the invoice at its validation (same dates and shares).

## With the reminders

The reminder job of Dolibarr (`sendEmailsRemindersOnInvoiceDueDate`) starts on the due date of the invoice: with a schedule, it would remind at each installment with the whole rest to pay. The module warns about it. The free module [Reminders](https://github.com/guiltekmdion/dolibarr-relances) (1.5.0 or later) reminds each late installment for its own amount, and never an installment whose direct debit is in progress.

## Tip without module

For simple cases, Dolibarr already does "one expected payment = one invoice": a payment term with a deposit (deposit invoice then final invoice from the proposal or the order), or a template invoice (recurring) for equal monthly installments.

## Limits

- The invoices of the installments are each their installment to the cent (what the customer pays); each one rounds its own VAT, so their VAT may differ by a cent or two from the document.
- The online payment link of an invoice proposes the whole rest of the invoice, not the installment.
- The module writes the due date of the invoices; disabled, it no longer does: the due date stays the one of the last payment.
- The cash forecast of Dolibarr and the transfer to the accounting know one due date per invoice (see below); the list of the module gives the installments.
- The widget and the list read all the installments at each display (fine for a few thousands).
- Two runs of the job at the same time (the job and "Run" by hand) are not locked against each other.
- The transfer to the accounting copies the due date of the invoice of that moment (its next unpaid installment), like any invoice: the accounting software gets one due date per invoice.
- Electronic invoicing (Factur-X): one due date per invoice, the installments are in the text of the PDF.
- The cash forecast uses the hooks `addMoreSQL` and `moreFamily` of Dolibarr: if another module gives its own query to the same hook, Dolibarr can not merge the two and the page may fail; if another module replaces the result of the hook, the installments are not listed, and the invoices with a schedule no longer are either.
- Version 1.1.0 changed the technical name (`installments`): 1.0.0 must be disabled and removed first. The paid module "Payment Schedule" of ATM can not be used at the same time on the same documents.

## Requirements

Dolibarr 24.0 or later, with the modules Customer invoices and Scheduled jobs (Commercial proposals, Sales orders, Direct debits and Banks if you use them). PHP 8.1 or later. MySQL/MariaDB or PostgreSQL.

## Installation

Before using schedules, **disable the reminder jobs of Dolibarr** ("Send reminder by email for validated and unpaid invoices"): they claim the whole rest of the invoice at each installment. Use the module Reminders instead.

1. Download `module_installments-X.Y.Z.zip` from the [releases](https://github.com/guiltekmdion/dolibarr-echeances/releases).
2. In Dolibarr: Home > Setup > Modules > Deploy/install external app/module, then send the zip. Or unzip it into `htdocs/custom` (the folder `installments` must be `htdocs/custom/installments`).
3. Enable the module **Payment schedules** (in Financial modules), then give the rights to the users.

After an update of the module, disable it and enable it again: **required**. The dates of the first runs of the jobs are kept (since 1.2.0).

## Setup

1. The templates of payment schedules: Home > Setup > Dictionaries > Templates of payment schedules (number, months and days between two installments, share of the first one, code of the payment mode; or free installments; code of the payment term that gives it).
2. To invoice and direct debit automatically: enable the jobs "Invoices of the installments" and "Direct debits of the installments" in Home > Admin tools > Scheduled jobs. **First run of each job**: it creates nothing for the installments before it. They are invoiced, or their direct debit asked, by hand.
3. Direct debits: Dolibarr keeps one pending request per invoice; set `WITHDRAW_STRICT_CHECK_AMOUNT` to 1 to allow several.

Rights: "Read payment schedules", "Create and modify payment schedules, invoice their installments" (with the rights of invoices, proposals and orders: the list shows the installments of the documents a user may read). Linking, abandoning or unlinking an installment needs the right to create invoices too. Recording payments needs the right to record the payments of invoices.

## Support

Questions and bugs: [issues](https://github.com/guiltekmdion/dolibarr-echeances/issues). Custom developments: [Guiltek](https://www.guiltek.fr).

## License

Copyright (C) 2026 Maxime DION, Guiltek.
This program is free software: you can redistribute it and/or modify it under the terms of the GNU General Public License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later version. See [COPYING](COPYING).
