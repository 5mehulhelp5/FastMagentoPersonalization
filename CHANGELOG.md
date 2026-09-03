# Changelog

All notable changes to FastMagento Personalisation are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
Releases are cut automatically from `main` (see `.github/workflows/release.yml`): patch by default,
`#minor` / `feat:` for a minor bump, `#major` / `BREAKING CHANGE` for a major one, `[skip release]`
to skip.

## [0.1.0]

First standalone release of the personalisation feature, extracted from FastMagento core's
`beta/personalization` branch (core phase 12). Beta.

### Added
- Shopper profiles from orders, views, searches, facet selections and reviews; boosts applied to
  search, listings and recommendation rows; exploration slot; A/B test and auto-weight tuning.
- Admin Personalisation Dashboard; `fastmagento:profile:*` and `fastmagento:personalization:*`
  commands; hourly refresh and daily tune cron jobs; the `fastmagento:doctor` Personalisation
  section (registered through core's check-provider pool).
- Self-contained storefront capture bundle `fastmagento-personalization.js`.

### Requires
- `parkktech/fastmagento` `^2.7` — the first core release carrying the extraction seams
  (`CheckProviderInterface`, `LinkProductCollectionPlugin::orderForDisplay()`,
  `QueryDecoratorInterface`, `ExplorationWindowInterface`, `EventRecorderInterface`).
