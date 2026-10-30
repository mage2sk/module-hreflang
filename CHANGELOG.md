# Changelog

All notable changes to this extension are documented here. The format
is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/).

## [1.0.27] - 2026-10-30

### Changed
- Released under the MIT License: this module is free and open-source software.

## [1.0.26] - 2026-10-05

### Fixed
- Saving, deleting or mass deleting a hreflang group now refreshes the cached product, category and CMS pages of its members, so storefront hreflang tags change without a manual full page cache flush.
- Pages without hreflang alternates (search, cart, account, unmapped catalog pages and inactive groups) no longer output a lone self-referencing x-default tag, which made each store view claim a different x-default for the same page. The old output is available through the new Self-Referencing x-default on Unmapped Pages setting (default No).
- The x-default, scope, CMS relation, fallback and diagnostic settings are hidden while Enabled is No, and the Emit x-default help text now describes which URL is used.
