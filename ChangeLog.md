# CHANGELOG MODULE INSTALLMENTS FOR [DOLIBARR ERP CRM](https://www.dolibarr.org)

## 1.1.0

- **Technical name `installments`** (folder, classes, tables `llx_installments_*`, constants `INSTALLMENTS_*`): the paid module Payment Schedule of ATM uses the names of 1.0.0. Disable and remove 1.0.0 first; its schedules are not kept (it was published one day before).
- Templates with free installments (`30:0;30:30;40:60:eom`) and linked to a payment term: an invoice, a proposal or an order validated with this payment term gets the schedule.
- Schedule in the PDF of the proposals and orders (in their public note while the PDF is made).
- Import from Echeancier: the invoices made by hand are found for the installments (same amount within 45 days, linked to the document first); the others come "To check", past or future, never invoiced by the job.
- A refused, draft or cancelled proposal or order is never invoiced; an invoice is never emailed without its PDF; local taxes kept in the invoices of the installments; an invoice linked to one installment only.
- List and widget: expected payments of proposals and orders on their tab only, installments of an abandoned invoice abandoned.
- Triggers return their errors (the core cancels its action); payment modes added by the user shown with their label.
- Direct debit of one installment: the other installments keep the mode they had from the invoice (the core sets the mode of the invoice to direct debit). A schedule saved again keeps its installments to check and abandoned; an abandoned installment is never invoiced with the last one.
- A schedule of an order or proposal not changed with its document is not copied to its invoice, and a template that can not apply is said: the validation always goes on.
- Checked with phpcs, phpstan, phan and the coding tests of Dolibarr (`build/check.sh`, in the CI too); tests on MySQL and PostgreSQL, Dolibarr 24.0 and develop.

## 1.0.0

- Payment schedules on invoices (several expected payments) and on proposals and orders (one invoice per installment).
- What is paid given to the installments by date; due date of the invoice on the next unpaid installment, so the core shows the right delay.
- Job creating the invoices of the installments on their date (draft, validated, or validated and sent); job creating the direct debit requests.
- Schedule under the totals of the PDF of the invoice, key `__PAYMENTSCHEDULE__`, list of the installments with mass payments, tab of the customer, widget.
- Templates of schedules (dictionary), warning of the 60 days rule between professionals in France.
- Import of the schedules of the module Echeancier (Artis Auxilium).
