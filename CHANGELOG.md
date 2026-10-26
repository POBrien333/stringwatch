# Changelog

## 0.4.1

- Author shown as "Blue River Growth", linked to https://bluerivergrowth.com/.

## 0.4.0

- Renamed to **Stringwatch – Untranslated Text Finder for TranslatePress**, as requested by the WordPress.org plugin review (the name needs a distinctive identifier up front). Slug, text domain, main file and tab are now `stringwatch` / "Stringwatch". The internal prefix `trwatch_` is unchanged, so settings and scan results are kept.

## 0.3.9

- "Ignore page" moved to the right, next to "Open in translator".
- Prepared for the WordPress.org plugin directory: the GitHub updater and the `Update URI` header are removed, so updates will come from WordPress.org once the plugin is listed. Translations are loaded by WordPress.org as well. Migrations from pre-release versions are removed.
- readme.txt: full description, FAQ, screenshot captions; plugin-directory banner, icons and screenshots in `.wordpress-org/`.

## 0.3.8

- **Ignore page** on each page card: leaves the page out of future scans, for every language. Listed under **Ignored pages** with Undo — the page is checked again from the next scan (e.g. a seasonal popup once it goes live).
- Post types excluded from search are no longer scanned. Page builders register their template libraries as public (Elementor: `elementor_library` — popups, headers, sections), which listed every template as a page. New filter `trwatch_post_types`.

## 0.3.7

- Fix: after an update the Watchdog screen could keep the old look (blue underlined icon, no indent) until a hard reload. Some sites strip the `?ver=` query string from file URLs and send CSS with a one-year browser cache. The screen's CSS and JS are now embedded in the page, so updates take effect immediately.
- Fix: "Check again" on Dashboard → Updates did not see a new release for up to 6 hours, because the plugin cached GitHub's answer. "Check again" now clears that cache, and it lasts 1 hour instead of 6.

## 0.3.6

- The Watchdog content is indented like the TranslatePress header and tabs.
- No line under the re-check icon (any underline, border or shadow switched off); keyboard focus shows an outline instead.

## 0.3.5

- Plugin icon (`assets/icon.svg`, animated): shown in the README and on Dashboard → Updates.
- `assets/icon-static.svg` (still, no CSS — passes WordPress SVG sanitizers, opens in design tools) and `icon-256.png` / `icon-512.png`, for use elsewhere. Not in the plugin zip.

## 0.3.4

- Re-check icon: no underline (WordPress underlines link-style buttons, and the line spun with the icon).

## 0.3.3

- Re-check button (↻) next to each page link: checks that one page again and replaces its card — "✓ Nothing left to fix" when done. Also on pages that could not be fetched. Updates the saved result.

## 0.3.2

- No element label on image findings: page builders give every image the same class, so it adds nothing; the alt text identifies the image.

## 0.3.1

- The element under a finding is only shown when it has a class or ID (bare tags such as `strong` or `a` are useless for ignoring and read like part of the text), and is prefixed with "in".

## 0.3.0

- Each finding shows the element it is in (e.g. `button.breakdance-menu-close-button`).
- Fix: a batch could exceed the PHP time limit when pages are fetched one by one (local sites).
- Fix: the script and stylesheet are reloaded whenever they change (an old cached script made Skip fail with "Unknown string").
- New setting **Ignore elements**: CSS selectors (tag, .class, #id, [attribute] and combinations) whose text is not checked. Unsupported selectors are reported, not saved.

## 0.2.0

- Detection works for any language pair: each translated page is compared with its original; identical text is checked against TranslatePress' string tables (not translated / translated but not shown / not in TranslatePress). Deliberately identical translations are hidden.
- Several translation languages can be checked in one scan.
- Meta descriptions are checked too.
- Code-like identifiers (cookie names such as `_ga_…`, camelCase keys, file names) are ignored.
- Updates from GitHub releases.
- Replaces the German word list and its filters (`trwatch_source_words`, `trwatch_target_words`).
- Local sites (self-signed certificates) are fetched one page at a time instead of with direct cURL calls; `trwatch_sslverify` filter.

## 0.1.0

- First standalone version (previously a must-use plugin on citationstyler.com; installed as "1.0.0").
