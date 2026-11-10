# Knowledge base

An optional help centre: articles in nested categories, written in Markdown with a visual editor, published at `/kb`.

## Turning it on

Settings, Knowledge base:

| Setting | Effect |
| --- | --- |
| Enable the knowledge base | Off by default. While off, every `/kb` page and every admin endpoint is a plain 404 and the menu entries disappear. |
| Let guests read it | On by default. When off, visitors who are not signed in are sent to the login page; any signed-in customer or staff member can read. Drafts and hidden categories are never shown to customers either way. |
| Title, Introduction | The heading and one-line intro on the `/kb` home page. |

Staff manage content under Knowledge base, Articles. Every staff member can edit articles; staff see drafts and hidden categories on the public pages with a note.

## Structure

- **Categories** nest to any depth (Getting started, Install, Windows ...). A category has a name, optional description and Lucide icon (shown on the home page), a link name and a "Visible to customers" switch. Hiding a category hides everything inside it.
- **Articles** live in one category (or at the top level), have a title, optional summary, a link name, a status (draft or published) and a Markdown body.
- Move categories and articles up or down with the arrows. Deleting a category needs it to be empty first.
- Addresses follow the nesting: `/kb/getting-started/install/windows/server-setup`. A category and an article cannot share an address under the same parent. `search`, `media` and `feedback` are reserved at the top level.

## Writing

The editor opens in Write mode (visual); the Markdown tab shows the source. Everything is stored as Markdown. Supported:

- Headings (h2 to h4 get anchors and feed "On this page"), bold, italic, strikethrough, links, images, quotes, rules
- Tables, bullet lists, numbered lists (shown as numbered steps), task lists
- Fenced code blocks with a language (`php`, `bash`, `html`, `json` ...): shown with a copy button and syntax colours
- Callouts: start a quote with `[!NOTE]`, `[!TIP]`, `[!IMPORTANT]`, `[!WARNING]` or `[!CAUTION]`, either on the same paragraph or as the first paragraph of the quote. The toolbar's Callout button inserts one.
- Plain HTML where Markdown cannot say it: `<kbd>Ctrl</kbd>`, `<details><summary>`, `<sub>`, `<sup>`, `<mark>`, tables with alignment. Only a safe list of tags and attributes is kept; scripts, frames, forms, event handlers and `javascript:` links are removed when the page is rendered.
- Images: drop or paste into the editor (PNG, JPG, GIF, WebP up to 5 MB). They are stored privately in `storage/app/private/kb` and served by `/kb/media/...`, so they follow the same guest setting as the articles. SVG is refused.

The visual editor writes bullet lists with `*` and may re-wrap some spacing; the result renders the same.

## Reader features

Home page with category cards, most read and recently updated; category pages with subcategories and articles; article pages with breadcrumbs, side menu, "On this page", previous/next within the category, last updated date and a "Was this article helpful?" vote (one per visitor session). Search looks in title, summary and body of published, visible articles. Page views count once per session and not for staff.

## Moving documentation in

Paste or import Markdown into articles. Starlight/MDX extras map as: `:::note` or `<Aside type="note">` to a `> [!NOTE]` quote; `<Steps>` around a numbered list to the plain numbered list (lists are always shown as steps); `<Card>`/`<CardGrid>` have no equivalent (categories show as cards on the home page).

## Technical notes

- Tables: `kb_categories` and `kb_articles` (migration `2026_11_14_100000`). No revision history yet.
- Markdown is rendered with `league/commonmark` (GitHub-flavoured), then cleaned by `App\Services\Knowledgebase\HtmlSanitizer`. The tree and URL logic is `App\Services\Knowledgebase\Knowledgebase`.
- The editor is Toast UI Editor 3.2.2 (MIT), bundled into `public/assets/vendor/toastui/` and loaded only on the article form (see `scripts/editor/README.md`). Syntax colours use highlight.js 11 and load only on pages with code blocks in a known language.
- Routes: `routes/kb.php` (reader), `routes/admin/kb.php` (admin).
