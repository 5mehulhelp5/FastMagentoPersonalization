# Changelog

All notable changes to FastMagento Personalisation are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
Releases are cut automatically from `main` (see `.github/workflows/release.yml`): patch by default,
`#minor` / `feat:` for a minor bump, `#major` / `BREAKING CHANGE` for a major one, `[skip release]`
to skip.

## [0.2.0] - 2026-09-04

### Added
- **Position-aware personalised category listings** (default on). The merchant's position order
  becomes a decaying prior and the shopper's gated boosts a lift: `prior(rank) = exp(−rank/band)`,
  `lift = 1 + strength × (boost − 1)`, re-ranked over the page window in the response plugin.
  A product the shopper is owed moves at most *band* positions (12); products without a
  preference keep their merchant order; deep pages, shopper-chosen sorts, guests and the control
  arm are untouched. Settings: *Category Listing Order For Profiled Shoppers* (personalised |
  position), *Band*, *Strength*. Doctor reports the live mode; `explain --category=<id>` shows it.
- **Category-scoped affinities.** Profiles now carry, per category bought from (ancestors
  included, resolved through configurable parents), the affinities measured on those purchases;
  a listing uses them in place of the global set with the *Category-Specific Preference Bonus*
  (150 %). Profile mapping gains `category_affinities` (added to a live index by a mapping PUT;
  run `fastmagento:profile:backfill --restart` to populate existing profiles). The page-cache
  signature is computed per listed category.

### Fixed
- Category evidence for purchases is read through the configurable/bundle parent: the purchased
  variant carries no category assignment, so category affinities were almost always empty.

## [0.1.5] - 2026-09-03

### Added
- **`Setup/Uninstall`.** `module:uninstall --remove-data` deletes the five OpenSearch indices
  this module owns, its configuration, cron schedule rows and flags.

### Changed
- **Doctor on a fresh install.** "Profile index does not exist" and "Discrimination table not
  measured" are warnings that name the hourly refresh cron (which creates and measures them),
  not failures. The provider still reports nothing at all when profile building is off, and the
  core doctor carries no personalisation checks — they exist only while this module is installed.

## [0.1.4] - 2026-09-02

### Fixed
- A product page no longer records an impression of its own product (it inflated exposure for the product being viewed).

## [0.1.3] - 2026-09-02

### Fixed
- Orders were never recorded for the A/B conversion rate; the report now has a denominator and a numerator.

## [0.1.2] - 2026-09-02

### Changed
- The module owns the personalisation index names (`IndexNames`) instead of borrowing core's.
- Beta planning, acceptance records and measurement harnesses moved into this package.

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
