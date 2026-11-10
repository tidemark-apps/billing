# UI consistency guide (Tidemark Billing ↔ Tidemark Analytics)

All Tidemark apps share one look and feel. Billing's design tokens in `public/assets/app.css` (lines 1–140) are already identical to Analytics'. Drift comes from per-screen CSS, one-off components and inline styles. This guide says what "consistent" means; the reference is the running Analytics app.

## Reference
- Analytics demo (read-only, already running): http://127.0.0.1:8002, sign in `demo@example.com` / `password`. It is a single-page app: open `/sites`, then use the sidebar (`/settings`, `/settings/keys`, `/settings/users`, `/settings/organization`, `/settings/teams`; a site's own pages show lists, tabs, tables, filters). Use your OWN browser tab, never navigate another agent's tab.
- Analytics source (read, never edit): `/Users/michael/Development/analytics-software/tidemark/public/assets/app.css`, `.../public/assets/js/ui.js` (components), `.../CLAUDE.md` sections "Front end" and "UI copy".
- Method: open the Analytics screen that is closest to yours, then yours, at 1280 wide, dark and light. Compare *measured* values with `getBoundingClientRect()` / `getComputedStyle()` (heights, paddings, font size/weight, radius, gaps), not just by eye.

## The rules (from Analytics' own contributor guide)
1. **Tokens only.** Colours, radii, spacing come from CSS variables (`--foreground`, `--muted-foreground`, `--border`, `--brand`, `--destructive`, `--warning`, `--success`, `--signal`, `--radius*`). No hex/rgb in views or CSS (exception: email and PDF templates, which must work without the stylesheet). No inline `style=` except a genuinely dynamic value (a progress width). Use the utilities `.mt-8/.mt-12/.mt-16/.mt-24`, `.mb-8/.mb-16`, `.m-0`, `.nowrap`, `.break-all`, `.w-full`, `.link-underline`, `.muted`, `.small`, `.right`, `.row`, `.spread`, `.stack`.
2. **Control heights.** Default controls (`input`, `select`, `.btn`) are 36px; `.btn.sm`/small selects 32px; `.btn.lg` 40px. Controls placed side by side are the same height: never a `.btn.sm` beside a default input. Under `@media (pointer: coarse), (max-width: 720px)` controls are 44px, small controls/tabs 40px: add new controls to the existing touch-scale block, don't invent a breakpoint.
3. **Reuse before you build; rule of three.** Don't create a class family or component for something that already exists. No new area prefixes (`cc-`, `sp-`, `sys-`, `portal-`): name generically (`.sumhead`, `.thread`, `.timeline`). A thing used in two places is a shared primitive; ask the lead rather than copying.
4. **Icons** are Lucide from `public/assets/icons.svg` via `<x-icon>`; icon-only buttons need `aria-label`.
5. **Copy**: plain words written to the person running the business ("Delete this client?", "Saved"), sentence case, no jargon, no exclamation marks, no emoji. Say what happens and what is not touched. Name things the same everywhere (client, service, invoice, ticket, department). Toasts are short past-tense; errors say what to do next.
6. **Accessibility**: real `<button>`/`<label>`, `role="alert"` on errors, 4.5:1 text, 3:1 controls, visible focus.

## Canonical primitives (use these; migrate away from the area copies)
| Need | Use | Replaces |
|---|---|---|
| Page title + actions | `<x-page-head>` (h1 24/600). One primary action, secondary `.btn`, overflow in `<x-menu>` | ad-hoc header rows |
| Overflow menu | `<x-menu label="More">` with `.item` rows; destructive rows `.item.danger` | `x-customers-menu`, `.sys-menu`, hand-built `pop-wrap` |
| Sortable column | `<x-sort>` (`.th-sort`) | `x-customers-sort`/`.cc-sort`, `.sp-sort` |
| List-page views | `<x-view-tabs>` with counts + `.filters` bar | custom tab rows |
| Status | `<x-status>` pill; ONE per object | stacked pills |
| Key figures strip | `.sumhead` (cells with `.k` label, `.v` value, `.s` sub) | `.cc-summary`, `.summary-strip`, `.pay-hero` stat clusters |
| Settings/edit card | `<x-setting>`: `.setting-h` title + one-line description, `.setting-b` fields, `.setting-f` muted footer holding the help text and the Save button (Analytics' SettingCard). A tab/page that saves several topics together is ONE card with sub-headings and one footer, not a floating bar | `.savebar`, `.sys-section`, per-area "form" wrappers |
| Document forms (invoice/quote, long line editors) | `.form-bar` sticky total + actions (only here) | n/a |
| Destructive area | `<x-setting danger>` / `.card.setting.danger-zone` at the bottom of the page | custom danger markup |
| Notices | `.notice` / `.alert` | custom banners |
| Empty | `<x-empty>` | custom |
| Timeline / thread | `.timeline` for events; ticket thread keeps its own layout but uses the same tokens, avatar and tag styles as the rest | per-area timelines |
| Form validation / dirty / secret fields | `data-validate`, `data-dirty-form` + `[data-savebar]` footer state, `[data-secret]` (behaviour lives in `public/assets/js/app.js`; do NOT add inline `<script>` copies; `<x-system-form-js />` is deprecated, remove it) | per-page scripts |
| Segmented / radio cards | one pattern (`.seg` / `.choice`) | `.sp-choice-opt`, `.cycle-opt`, `.sys-role` |

## What "done" means for a screen
- Looks like it belongs next to the Analytics equivalent: same page-head, card padding, label/help/error rhythm, table density, tab style, pill size, button hierarchy.
- No deprecated component, no inline colour, no ad-hoc spacing, no copy that breaks the voice rules.
- Neighbouring controls measure the same height (state your measurements for the pages you touched in the report).
- 375px and 1280px, light and dark, console clean.
