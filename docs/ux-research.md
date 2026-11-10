# UX Design Brief: SaaS Billing Admin + Customer Portal

Scope: admin app (invoices, customers, subscriptions, payments, support tickets) and customer portal.
Evidence key: **[F]** = rule taken from a page fetched during this research; **[P]** = established
industry practice / author judgement, not directly verified from the cited source (treat as convention).

## 1. Data tables and list pages
Sources: https://www.nngroup.com/articles/data-tables/ , https://carbondesignsystem.com/components/data-table/usage/

Do
- Freeze header row; freeze the first column (human-readable ID such as invoice number or customer name) on wide tables. [F]
- Keep sort distinct from filter. Show the sort arrow only on the sorted column; unsorted arrows on hover only. [F]
- Offer batch actions via row checkboxes; show an action bar once a row is selected; disable per-row menus while in batch mode. [F]
- Use a nonmodal side panel (or navigate to detail) for deeper row work so the table stays visible. [F]
- Offer density options (compact 32px, default 40px, comfortable 48px); keep header row the same height as rows. [F]
- Use skeleton rows for slow loads rather than a spinner. [F]
- Right-align numeric and currency columns, with tabular (monospaced) figures and consistent decimals; right-align the header too; left-align text. [P]
- Make the whole row (or at least the primary identifier cell) a link to the detail page; keep a visible link style on the identifier. [P]
- Paginate (25/50/100 per page, URL-persisted) for records users must find, compare or audit, which fits billing. Infinite scroll is unsuitable for footers, totals and "go back to row 340". [P]
- Empty states: say why it is empty (no data vs. filters hide everything) and offer one action ("Create invoice" or "Clear filters"). [P]
- Show total counts and, where useful, a totals row for currency columns (state the currency). [P]

Don't
- Crowd rows with many unlabeled icons or hide actions in hover-only gestures or a generic "Actions" menu. [F]
- Open a modal for deep row editing; it hides the adjacent records. [F]
- Use zebra/hover/borders as decoration only; use them to help users keep their place on wide tables. [F]
- Mix currencies in one right-aligned column without a currency code.

## 2. Filters and search
Sources: https://www.nngroup.com/articles/filter-categories-values/ , https://www.nngroup.com/articles/data-tables/

Do
- Make filters discoverable and show a clear indicator when any are active. [F]
- Use concrete, user-vocabulary labels ("Payment status", not "Type"); never internal codes as filter values. [F]
- Tailor the filter set to the object (invoices: status, customer, date range, amount; tickets: status, assignee, priority). [F]
- Primary pattern: status tabs with counts (All 120 / Draft 4 / Open 31 / Paid 80 / Overdue 5) above the table, then a search input and an inline filter bar. [P]
- Show applied filters as removable chips next to the bar, plus a single "Clear all". [P]
- Provide 3-5 saved/quick filters (e.g. "Overdue > 30 days", "Failed payments this week"), shareable by URL. [P]
- Keep filter state in the URL so refresh, back and sharing work. [P]
- Search: match on invoice number, customer name, email; show a helpful zero-results message. [P]

Don't
- Reuse one generic filter set on every list. [F]
- Hide the active filters inside a closed panel. [P]
- Let counts on tabs disagree with the table results.

## 3. Status indicators and badges
Sources: https://carbondesignsystem.com/patterns/status-indicator-pattern/ , https://www.nngroup.com/articles/indicators-validations-notifications/

Do
- Show exactly one status per object, derived from a single state machine (Draft, Open, Paid, Void, Uncollectible); show "Overdue" as the open state's urgency, replacing Open. [P]
- Pair color with a text label and, ideally, an icon or shape; never color alone. [F]
- Keep a consistent color meaning: red error, orange serious warning, yellow warning, green success, blue informational, gray draft/inactive. [F]
- Keep status colors at 3:1 against the background. [F]
- Use plain text when the status needs no action or is not significant (e.g. "Paid" in a long list can be a quiet gray/green text badge). [F]
- Limit indicators per view; more than 5-6 overwhelms. [F]
- Show secondary facts as text ("Due in 3 days", "12 days overdue"), not extra pills. [P]

Don't
- Render redundant pills such as Open + Overdue, or Paid + Succeeded. Pick the highest-attention one. [P]
- Attach indicators to elements that always look the same; they carry no signal. [F]
- Reuse one shape with different meanings or colors in the same product. [F]

## 4. Dashboards and KPI cards
Source: https://www.nngroup.com/articles/dashboards-preattentive/

Do
- Keep it to one screen of at-a-glance information focused on critical signals. [F]
- Put the most critical, time-sensitive items first: overdue amount, failed payments, open tickets, then revenue trends. [F]
- KPI card hierarchy: label (small), value (large, tabular figures), delta with period ("+4.2% vs previous 30 days"), optional sparkline. [P]
- Compare via bar/line charts (length and position are judged most accurately). [F]
- Label axes and units, state the date range and currency, direct-label lines where possible. [P]
- Make each KPI a link to the pre-filtered list that explains it. [P]

Don't
- Use pie/donut charts, radial gauges, treemaps or 3D charts for comparison. [F]
- Use color alone to encode quantity; pair with shape/grouping/labels. [F]
- Treat green/red deltas as the only cue; include a sign or arrow, and mind that "up" is not always good (e.g. churn, overdue). [P]
- Turn the dashboard into an exploration tool or portal. [F]

## 5. Forms and settings
Sources: https://www.nngroup.com/articles/form-design-white-space/ , https://design-system.service.gov.uk/patterns/question-pages/ , https://baymard.com/blog/inline-form-validation , https://www.nngroup.com/articles/confirmation-dialog/ , https://www.nngroup.com/articles/indicators-validations-notifications/

Do
- Labels above fields, close to their field; group related fields in blocks of roughly 4-6 with white space. [F]
- Single column layout; use side-by-side only for tightly coupled pairs (city/postcode, expiry/CVC). [P; GOV.UK examples use one column]
- Mark optional fields with "(optional)" rather than asterisks (GOV.UK); if most fields are required, this keeps noise low. [F]
- Hint text: one short sentence, placed between label and input. [F]
- Validate on blur (or at expected length), clear errors the moment the value becomes valid, keep entries on failed submit. [F]
- Error messages next to the field, state how to fix it, with icon plus text plus color; summarize at top on submit. [F]
- Destructive actions (void invoice, delete customer, cancel subscription): name the object and consequence, outcome-labelled buttons ("Void invoice" / "Keep invoice"), don't preselect the destructive option; prefer undo where possible; type-to-confirm only for rare severe actions. [F]
- Sticky save bar (Save / Discard) appears once a form is dirty on long settings pages; warn on navigating away. [P]
- Settings organization: left sub-navigation or section cards by topic (Company, Tax, Payment methods, Email templates, Team); one save scope per section. [P]

Don't
- Use placeholders as labels, or right-aligned labels. [F]
- Use toasts as the only error feedback. [F]
- Ask for the same information twice; pre-populate instead. [F]
- Confirm routine, reversible actions; it trains click-through. [F]

## 6. Detail pages (invoice, customer, subscription)
Source: https://www.nngroup.com/articles/data-tables/ (side-panel guidance); remaining items [P] from Polaris / Stripe Dashboard conventions (not verified: https://polaris-react.shopify.com , https://docs.stripe.com/dashboard/basics)

Do
- Summary header: title/ID, single status badge, key figures (amount, due date, customer), and 1 primary action plus a "More" menu. [P]
- Primary action top right of the header, labelled with a verb ("Send reminder", "Record payment"); it changes with state (Draft: Send; Open: Record payment; Paid: Download). [P]
- Two-column body: main content (line items, payments) left, metadata/related objects right (customer, subscription, tags). [P]
- Use sections on one scrolling page for 2-4 related blocks; use tabs only for distinct, heavy areas (Overview / Payments / Activity). Keep the default tab complete. [P]
- Activity timeline: reverse-chronological, absolute timestamp on hover, actor named, system vs user events distinguishable (created, sent, viewed, paid, reminder sent, voided). [P]
- Breadcrumb or back link to the filtered list the user came from, preserving filters. [P]

Don't
- Put destructive actions as the primary or adjacent-to-primary button. [P]
- Split a few fields across many tabs. [P]

## 7. Invoices, payment and receipts (customer portal)
Source: https://baymard.com/articles/checkout-usability (not fetched; Baymard checkout guidance, [P]). Form rules in section 5 apply.

Do
- Customer invoice page opens with: amount due (largest element), due date, status, and one prominent "Pay now" button above the fold; sticky on mobile. [P]
- Show line items, tax, discounts and total clearly with currency; link "Download PDF". [P]
- Offer guest pay-by-link without forcing account creation. [P]
- Keep payment form short: single column, saved methods first, card field with automatic formatting, accurate error text, no surprise fees. [P]
- Show processing state, then a confirmation with receipt number, amount, method (last 4), date, and "Download receipt". Send the same by email. [P]
- On failed payment, explain why and offer a retry or another method, keeping the entered data. [F: keep entries intact]
- Paid invoices: replace the Pay button with a "Paid on <date>" statement and receipt link. [P]

Don't
- Show multiple competing CTAs beside Pay now. [P]
- Use a toast as the only confirmation of payment. [F: toasts missed]
- Clear the form on a failed attempt. [F]

## 8. Support ticket UI
Sources: [P] general helpdesk conventions (Zendesk/Intercom/Linear style); form and status rules from sections 3 and 5.

Do
- Conversation thread in chronological order, with customer vs agent messages visually distinct (alignment or background) and the name, role and time on each. [P]
- Internal notes: clearly different (tinted background such as yellow, lock icon and "Internal note, not visible to customer" label); the composer needs an explicit Reply / Internal note toggle that also recolors the composer. [P]
- Composer fixed at the bottom or top of the thread, supporting attachments, canned responses, and a send button labelled with the mode ("Send reply" vs "Add note"). [P]
- Right sidebar metadata: status (one), priority, assignee, customer (link to billing record), related invoice, tags, created/updated. Editable inline. [P]
- Status changes and assignment appear in the thread as quiet system events. [P]

Don't
- Make internal notes look like replies. [P]
- Duplicate status in header badge and sidebar pill without a single source. [P]

## 9. Navigation and information architecture
Source: [P] conventions; command palette pattern as in Linear/Vercel (not fetched).

Do
- Left sidebar with 5-8 top-level groups by task: Overview; Billing (Invoices, Subscriptions, Payments); Customers; Support; Reports; Settings. Group headings, not deep trees. [P]
- Highlight the current item; collapsible to icons; show badge counts only for actionable items (open tickets, overdue). [P]
- Page title (h1) matches the nav label; one h1 per page; browser tab title reads "Invoices | App". [P]
- Breadcrumbs on detail pages (Invoices / INV-1042). [P]
- Global search and command palette (Cmd/Ctrl+K): jump to customer/invoice by number or name, and run common actions. [P]
- Customer portal: a minimal top nav (Invoices, Payment methods, Subscription, Support, Account). [P]

Don't
- Nest more than two levels in the sidebar. [P]
- Use different words for the same object across nav, titles and buttons. [P]

## 10. Accessibility (WCAG 2.2 AA)
Sources: https://www.w3.org/WAI/WCAG22/Understanding/target-size-minimum.html , https://www.w3.org/WAI/WCAG22/Understanding/non-text-contrast.html , https://www.w3.org/WAI/WCAG22/Understanding/focus-appearance.html , https://carbondesignsystem.com/patterns/status-indicator-pattern/

Do
- Text contrast at least 4.5:1 (3:1 for large text, 18pt or 14pt bold). [WCAG 1.4.3, [P] standard]
- Control boundaries, focus rings, icons and meaningful chart parts at least 3:1 (1.4.11). [F]
- Visible focus indicator on every interactive element; aim for the AAA-level guidance of at least 2px perimeter and 3:1 change, though only AA is required. Don't let sticky bars obscure the focused element (2.4.11). [F for 2.4.13 being AAA]
- Pointer targets at least 24x24 CSS px, or spacing such that a 24px circle on each doesn't overlap another target (2.5.8). Prefer 32-40px for table row controls. [F]
- Full keyboard operation: logical tab order, Esc closes dialogs, focus returns to trigger, row links reachable, skip link, no keyboard traps. [P]
- Use real table markup (`th scope`), `aria-sort`, labelled checkboxes, and live regions for async results and errors. [P]
- Provide non-drag alternatives for reordering and sliders (2.5.7). [F: GOV.UK mentions 2.5.1]

Don't
- Convey status, deltas or chart series by color alone. [F]
- Rely on hover-only reveals for essential actions. [F]

## 11. Responsive and mobile tables
Source: [P] common practice; NN/g freeze-column guidance https://www.nngroup.com/articles/data-tables/ [F]

Do
- Wide tables: horizontal scroll inside the container with frozen first column and visible scroll affordance. [F]
- Mobile: convert rows to cards showing identifier, status, amount and due date; whole card is the link. [P]
- Move filters to a bottom sheet/drawer with an applied-count badge; keep status tabs scrollable. [P]
- Primary CTA (Pay, Send reply) sticky at the bottom on mobile; target size 44px where possible. [P]
- Prioritize columns by breakpoint; allow column visibility control with a non-drag option. [F]

Don't
- Shrink text below 14px to fit columns. [P]
- Make the whole page scroll horizontally. [P]

## 12. Dark mode
Source: [P] Material/Apple conventions (https://m3.material.io/styles/color/system/overview , not fetched); contrast rules from section 10.

Do
- Define semantic color tokens for both themes; respect `prefers-color-scheme` and offer a manual toggle. [P]
- Use dark gray surfaces (not pure black) with elevation via lighter surfaces rather than shadows; avoid pure white text (use ~87% white). [P]
- Re-verify 4.5:1 text and 3:1 non-text contrast, including status badge colors and chart series, in dark mode. [F for 3:1 status rule]
- Desaturate large colored fills; keep status meaning (red/green) and still pair with text/icon. [P]
- Make PDFs, invoices and print views light regardless of theme. [P]

Don't
- Invert colors automatically or reuse light-mode shadows and borders unchanged. [P]

---

# Prioritised reviewer checklist (apply screen by screen)

## P0: must pass
1. One status per object; never Open + Overdue (or similar) together; the badge has text, not only color.
2. Every text pair at least 4.5:1 and every control border/focus/status/chart color at least 3:1, in light and dark.
3. All interactive targets at least 24x24 CSS px (prefer 32px+ in tables); no tiny icon-only buttons without labels.
4. Everything works by keyboard with visible focus; focus is not hidden behind sticky headers or bars.
5. Numeric and currency columns right-aligned, tabular figures, currency stated; text left-aligned.
6. Exactly one primary action per page/section, verb-labelled and in a predictable position; destructive actions are secondary.
7. Destructive confirmations name the object and outcome, use outcome-labelled buttons, and don't preselect destruction.
8. Errors appear next to the field with a fix-it message (icon + text + color); entries survive a failed submit; no error-only toasts.
9. Customer invoice page shows amount due, due date, status and a prominent Pay button above the fold on desktop and mobile.

## P1: should pass
10. Tables have sticky header (and frozen identifier column when wide), sortable columns with a single sort arrow, and clickable rows.
11. Row actions are visible or in a labelled overflow menu; batch actions appear in a bar when rows are selected.
12. List pages offer status tabs with counts, a search box, an inline filter bar, applied-filter chips and "Clear all".
13. Filter, sort and page state live in the URL and survive back/refresh.
14. Loading uses skeletons; empty states explain cause and offer one next action (distinguish "no data" from "no matches").
15. Pagination (not infinite scroll) with page size control and total count.
16. Forms are single column with labels above, hint text one short sentence, "(optional)" instead of asterisks, groups of about 4-6 fields.
17. Inline validation on blur, errors cleared as soon as fixed, summary of errors on submit.
18. Detail pages have a summary header (ID, status, key figures, primary action), then main content with a metadata side column, and an activity timeline.
19. Tabs only for distinct heavy areas; the default tab is self-sufficient; breadcrumb or back link returns to the filtered list.
20. Dashboard shows the most critical signals first; KPI cards have label, value, delta with comparison period and link to the filtered list.

## P2: polish
21. Charts: bar/line only, labeled axes and units, no pie/3D/gauges, no color-only encoding.
22. Support thread: internal notes are visually and verbally distinct, composer mode toggle recolors, sidebar metadata is editable and single-source.
23. Navigation: 5-8 top-level groups, no more than two levels, page title matches nav label, Cmd/Ctrl+K search.
24. Mobile: tables become cards or scroll with frozen first column; filters in a bottom sheet; sticky primary CTA.
25. Dark mode: semantic tokens, no pure black/white, status and chart colors re-checked, invoices/PDFs print in light.
26. Settings: grouped by topic with a sticky save bar and unsaved-changes warning; one save scope per section.
27. Payment confirmation page and email include receipt number, amount, method (last 4), date and a download link.

---

## Source index
- NN/g data tables: https://www.nngroup.com/articles/data-tables/
- NN/g filter categories: https://www.nngroup.com/articles/filter-categories-values/
- NN/g form labels/white space: https://www.nngroup.com/articles/form-design-white-space/
- NN/g indicators, validations, notifications: https://www.nngroup.com/articles/indicators-validations-notifications/
- NN/g dashboards: https://www.nngroup.com/articles/dashboards-preattentive/
- NN/g confirmation dialogs: https://www.nngroup.com/articles/confirmation-dialog/
- Baymard inline validation: https://baymard.com/blog/inline-form-validation
- GOV.UK question pages: https://design-system.service.gov.uk/patterns/question-pages/
- Carbon data table: https://carbondesignsystem.com/components/data-table/usage/
- Carbon status indicators: https://carbondesignsystem.com/patterns/status-indicator-pattern/
- WCAG 2.2 target size: https://www.w3.org/WAI/WCAG22/Understanding/target-size-minimum.html
- WCAG 2.2 non-text contrast: https://www.w3.org/WAI/WCAG22/Understanding/non-text-contrast.html
- WCAG 2.2 focus appearance (AAA): https://www.w3.org/WAI/WCAG22/Understanding/focus-appearance.html
- Not fetched, cited as convention only: Baymard checkout, Polaris, Stripe Dashboard, Material 3 color.
