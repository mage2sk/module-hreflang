# Changelog

## 1.0.13 - 2026-05-13

- Fix homepage hreflang resolution on multi-store installs where the admin "Default Pages" picker stored the home page as `<identifier>|<page_id>`. The picker disambiguates between several CMS pages sharing the same identifier across stores by appending `|<page_id>` to the stored value; the previous lookup passed that literal string to `cms_page.identifier`, never matched a row, and fell back to the self-referencing `x-default` only. `ViewModel\Hreflang::detectCmsPageId()` now splits on `|` and, when the suffix is a positive integer, returns it directly as the authoritative page id.
