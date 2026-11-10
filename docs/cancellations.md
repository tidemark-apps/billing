# Cancellations

Settings, Billing, **Cancellations** control how customers end a service. Staff are never restricted.

| Setting | Options | Default |
| --- | --- | --- |
| When a cancellation takes effect | At the end of the billing term / Immediately / Let the customer choose | End of the billing term |
| Refund the unused time when a service is cancelled immediately | on / off | off |

**End of the billing term.** The service keeps running until its next due date, then the daily billing run cancels it. No further invoice is raised.

**Immediately.** The remote account is removed straight away. With the refund switched on, the unused days of the period the latest paid invoice covered are refunded, by the day and including tax, through the payment that covered it (capped by what that payment still allows). A refund the gateway refuses does not stop the cancellation: the customer is told it will be done by hand, and the failure is logged in the activity log.

**Let the customer choose.** The cancel box on the service page shows both options, and says how much an immediate cancellation would refund.

Things that are always immediate, whatever the setting: services that have not started yet and free trials. Nothing was paid for those, so nothing is refunded.

**Staff.** The cancel button in the admin danger zone always cancels now, with a "Refund … for the unused time" checkbox that starts ticked when the refund setting is on. The admin API takes `immediately` and `refund`; the client API takes `immediately`, which is refused with `422` while only end-of-term cancellation is allowed. Both return `meta.refund` (`amount`, `currency`, `status` of `issued` or `failed`) when a refund was attempted.
