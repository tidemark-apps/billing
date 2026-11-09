# CJK text in PDFs

Invoice and quote PDFs are built with dompdf and its bundled DejaVu Sans, which covers Latin, Greek and Cyrillic but has no Chinese, Japanese or Korean (CJK) glyphs; those characters would print as empty boxes. Tidemark can use optional Noto Sans font packs for them.

## Install

```bash
php artisan billing:fonts:install              # Simplified Chinese + Japanese + Korean (about 22 MB)
php artisan billing:fonts:install --pack=tc    # add Traditional Chinese forms (7 MB)
php artisan billing:fonts:install --remove     # uninstall (or --pack=jp --remove)
```

Without SSH: **Settings > System > PDF fonts** has an Install / Remove button per pack. When an invoice or quote contains CJK text and no pack is installed, its admin page shows a notice, and a warning is written to the log whenever such a PDF is rendered (it still renders, with empty boxes where the glyphs would be).

## How it works

- Packs are the static Regular weight of Google's Noto Sans SC, TC, JP and KR (SIL Open Font License 1.1) as TrueType-outline TTFs, which dompdf can embed (the official Noto CJK `.otf` files are CFF and cannot be used). They are downloaded from jsDelivr's copy of the pinned `@expo-google-fonts` 0.4.4 npm releases. URL, SHA-256 and expected size of each file are in `config/pdf-fonts.php`; a download whose checksum or size differs is discarded, never installed.
- Files go to `storage/app/dompdf/fonts/` together with a metrics file and dompdf's `installed-fonts.json`. Only `storage/` needs to be writable, no binaries are used, and nothing is added to the release zip (`scripts/build-release.sh` excludes `storage/app/dompdf`).
- At render time the document body is scanned. If it has no CJK characters the HTML is not touched at all, so Latin PDFs are identical to before. Otherwise each run of CJK characters is wrapped in a span selecting a pack: kana use Japanese, hangul uses Korean, ideographs follow the surrounding text (Japanese beside kana, Korean beside hangul, otherwise Simplified Chinese). dompdf cannot fall back glyph by glyph, so the renderer reads each font's glyph table and sends any character the preferred pack lacks (for example a simplified ideograph in Japanese text) to a pack that has it.
- To prefer Traditional Chinese forms for plain ideographs, install the `tc` pack and set the setting `billing.pdf_han` to `tc`.
- Chinese, Japanese and Korean text is not bold: the packs are Regular only, so bold CJK renders at regular weight (Latin text stays bold).

## Requirements and limits

- Embedding a CJK font takes roughly 75 MB of PHP memory on top of the normal request. The renderer raises `memory_limit` to 512M for such documents when the host lets it; if the limit stays below 192M it renders without the CJK fonts and logs a warning instead of crashing. Latin documents are unaffected.
- Generated PDFs only embed the glyphs they use (font subsetting), so they stay small (tens of KB).
- Emoji and rare symbols are not in these fonts.
