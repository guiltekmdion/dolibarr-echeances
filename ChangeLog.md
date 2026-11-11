# CHANGELOG MODULE PAYMENTSCHEDULE FOR [DOLIBARR ERP CRM](https://www.dolibarr.org)

## 1.0.0

- Payment schedules on invoices (several expected payments) and on proposals and orders (one invoice per installment).
- What is paid given to the installments by date; due date of the invoice on the next unpaid installment, so the core shows the right delay.
- Job creating the invoices of the installments on their date (draft, validated, or validated and sent); job creating the direct debit requests.
- Schedule under the totals of the PDF of the invoice, key `__PAYMENTSCHEDULE__`, list of the installments with mass payments, tab of the customer, widget.
- Templates of schedules (dictionary), warning of the 60 days rule between professionals in France.
- Import of the schedules of the module Echeancier (Artis Auxilium).
