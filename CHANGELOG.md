# Changelog

All notable changes to FastMagento Personalisation are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
Releases are cut automatically from `main` (see `.github/workflows/release.yml`): patch by default,
`#minor` / `feat:` for a minor bump, `#major` / `BREAKING CHANGE` for a major one, `[skip release]`
to skip.

## [0.3.1] - 2026-09-04

### Fixed
- **Adobe Commerce (EE) schema compatibility.** Purchase-history attribute loads
  (`catalog_product_entity_int` / `_text`), configurable-parent resolution
  (`catalog_product_relation.parent_id`), category names (`catalog_category_entity_varchar`) and
  exposure roll-up (`catalog_product_super_link.parent_id`) now resolve the link field through
  FastMagento's `Model\Db\EntityLink` (`row_id` on Commerce content staging). Open Source SQL is
  unchanged. Requires `parkktech/fastmagento` ^2.10. Verified on a Commerce-shaped copy of the demo
  catalogue: identical profiles, discrimination tables and `explain` output on both editions.


### Added
- **Profiled attributes come from the catalogue, not from the code.** Shoppers were profiled on a
  hard-wired `color,size`, which on any non-apparel store meant nothing was counted, nothing was
  lifted and nothing said why. New `Model\Personalization\ProfileAttributes` resolves the list:
  the admin setting **Attributes To Profile** (`profile_attributes`) when set, otherwise every
  filterable select/multiselect product attribute (colour and size first, widest first, capped at
  20), plus every mapped fact attribute. Used by the hourly cron, `profile:backfill`,
  `profile:inspect` and the discrimination build, so a profiled value is always a gated value.
  The doctor prints the resolved list as *Profiled attributes*.
- **Category-relative discrimination.** The discrimination build now also measures every category
  with 20+ products on its own (`store:<id>:cat:<id>` docs, one aggregation per attribute). A
  category listing is gated on **its own** population: a value rare store-wide but on more than
  half of one category is refused on that listing and lifted everywhere else. Smaller categories
  fall back to the store-wide table. `discrimination --show=<attr> --category=<id>` prints the
  table as that listing sees it; `explain` tags each clause with the share of the store or the
  category that carries the value.
- **Multiselect attributes are profiled.** Values in the TEXT table (material, pattern, features…)
  are split and each value counted; a product's observation is shared across its values.
- **Variants inherit parent attributes.** The purchased child carries what it varies on; material,
  style, fit and the like live on the configurable parent and are now inherited (child wins).
- README: *Which attributes it learns — any store, not just apparel* (resolution rules, the full
  uplift formula, the population table, the "store that only sells plastic pieces" case, setup
  steps for a non-apparel store) and a fourth demo shopper with no colour preference at all.

### Changed
- `fastmagento:profile:backfill --attributes`, `profile:inspect --attributes` and
  `personalization:discrimination --attributes` default to blank, meaning the resolved list;
  the commands print which list they used and where it came from.
- `PersonalizationConfig::DEFAULT_PROFILE_ATTRIBUTES` is deprecated and no longer read.

### Upgrade notes
- Run `bin/magento fastmagento:personalization:discrimination` once after upgrading (or wait
  for the hourly cron) so the per-category tables exist; until then listings are gated on the
  store-wide table exactly as before. Re-run `fastmagento:profile:backfill --restart` to pick up
  the wider attribute list on existing profiles.

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
