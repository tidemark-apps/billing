# Internal accounts

Tick **Internal account** on a client (Admin → Clients → edit, or `is_internal` in the API) for your own team, test customers and anyone who is on the house.

**Prices.** Every service the client has is set to 0. New orders, quotes turned into services, plan changes and usage charges are all free for them, with no setup fees. The renewal invoices they still get are for 0 and settle themselves. The price a service would normally cost is remembered, and turning the flag off puts it back.

**Reports.** Internal clients are left out of the revenue, outstanding and overdue figures, active-service and new-client counts on the dashboard, the totals on the payments and invoices pages, and the same summaries in the REST API. Their invoices, payments and services still appear in the lists (marked "Internal" on the client), so nothing is hidden from staff. Plugins and your own queries can use `->external()` on `Client`, `Invoice`, `Payment`, `Service` and `Quote` to do the same.

**Things to know.**
- Invoices that were already open when you marked the client internal are left as they are; void or mark them paid yourself.
- Editing an internal service's price keeps it at 0. A figure you type becomes the price they would pay if the flag came off.
- Servers still count internal services toward their capacity.
