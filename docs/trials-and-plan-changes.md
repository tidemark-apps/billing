# Free trials and plan changes

Both are part of the core, no plugin required.

## Free trials

Set **Free trial (days)** on a product (Admin → Products). Anything above 0 turns it on.

- A customer who orders that product starts a trial: the service is provisioned straight away, **no invoice is raised**, and the first renewal date is the end of the trial.
- When the trial ends the billing run raises the first invoice (the first period's price, plus the setup fee if there is one). The invoice goes through the normal route: autopay if the customer has a saved card, otherwise a pay link by email, then reminders, suspension and termination if it stays unpaid.
- A reminder email (and the `service.trial_ending` event) goes out `billing.trial_reminder_days` before the trial ends (default 3, set it to -1 to turn it off).
- Cancelling during a trial ends the service immediately and nothing is ever billed.
- A trial is **once per customer per product**, however the first one ended. A second order is billed normally.
- **Require a saved payment method** on the product makes the customer add a card before the trial can start. Staff placing an order or API calls are not blocked by this when they pass `trial: false` or create the service themselves.
- Trials apply to recurring cycles only. One-time products are never trials.
- Staff can **extend** a trial or **end it now and invoice** from the service page, and skip a trial when creating a service ("bill now").
- Changing plan during a trial is free: the new plan's price is what the trial converts to.

Settings: `billing.trial_reminder_days` (default 3) and `billing.trial_invoice_days_before` (default 0: the first invoice is raised on the day the trial ends; raise it to invoice ahead).

Events: `service.trial_started`, `service.trial_ending`, `service.trial_converted` (the first invoice was raised).

## Upgrades, downgrades and cycle changes

A service moves to another product, another billing cycle, or both, from the service page (staff), from the customer portal, or the API.

**Who can change what.** Customers can move to other active, visible products in the same product group, and to other cycles of their own product, when the product has **Customers can change plan** ticked and the store-wide setting `billing.customer_plan_changes` is on (default). Staff can move a service to any active product. Only active, recurring services that are not being cancelled can change.

**Timing.**

| | Default | Customer can choose | Staff can choose |
|---|---|---|---|
| Upgrade (higher price per month) | now | now or at renewal | now or at renewal |
| Downgrade (lower price per month) | at renewal | at renewal only | now or at renewal |
| Cycle change on the same product | now | now or at renewal | now or at renewal |

**Proration (changes made now).** Billing is by the day.

- Same cycle: the unused part of the current period is credited at the old price and the rest of the period is charged at the new price. One invoice carries both lines, and the renewal date does not move.
- Different cycle: the unused part of the old period is credited and one full period of the new cycle is charged; the new period starts today.
- During a trial nothing is prorated, because nothing has been paid.

**Credit is not refunded or carried forward.** If the credit is bigger than the new charge (an immediate downgrade by staff), no invoice is raised and the extra credit is forfeited. The preview shows this before staff confirm. Use a scheduled downgrade to avoid it.

**At renewal.** A change scheduled for the renewal is applied by the daily billing run on the renewal date, with no proration, and the renewal invoice that day is for the new plan. It can be cancelled any time before then.

**What happens on the server.** The service's product, price and cycle are updated, and the provisioning module's change-package step runs (cPanel/WHM and the other bundled modules switch the hosting package). If the module call fails the plan change still stands, the failure is logged on the service, and staff are emailed. The customer gets an email, and `service.plan_changed` (or `service.plan_change_scheduled`) fires.

**Usage billing.** Usage on the old product's meters up to the moment of an immediate change is billed on its own invoice first; the new plan's meters start clean.

**Payment.** An upgrade takes effect immediately and its invoice is due the same day, so autopay charges it at once for customers with a saved card. If it is not paid, it follows the normal overdue rules like any invoice.

Every change is recorded (who, when, from → to, credit, charge, invoice) and shown on the service page.

Editing a service's price or cycle directly on the admin service form is still possible as an override: it does not prorate or invoice anything.
