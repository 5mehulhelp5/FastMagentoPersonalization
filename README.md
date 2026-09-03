# ParkkTech FastMagento Personalisation

Per-shopper personalisation for [FastMagento](https://github.com/parkktech/FastMagento): a profile
built from what a shopper buys, views, searches for and filters by, applied as boosts to the
OpenSearch queries FastMagento already serves — search, category listings, and the related /
up-sell / cross-sell rows on a product page. An exploration slot gives under-exposed products a
measured share of the page so ranking does not only reward what already sold.

It is an **optional companion** to FastMagento core. Core installs and serves without it, and every
page it touches is byte-identical to core when it is switched off. It ships **dark and off**: nothing
changes on the storefront until you turn it on.

> Status: beta (0.x). Releases are cut automatically from `main` by GitHub Actions (tag + GitHub
> Release + Packagist update). Requires FastMagento core **2.7 or later** — the first core release
> that carries the extraction seams; earlier cores do not have them.

## Requirements

- `parkktech/fastmagento` (FastMagento core), same store, same OpenSearch cluster
- PHP 8.1 – 8.5, Magento 2.4.x with OpenSearch as the search engine
- A full-page cache in front of the storefront is supported and expected; see *How capture works*

## Install

Today, from a path repository:

```json
"repositories": {
    "parkk-fastmagento-personalization": {
        "type": "path",
        "url": "/path/to/FastMagentoPersonalization"
    }
}
```

```bash
composer require parkktech/fastmagento-personalization
bin/magento module:enable ParkkTech_FastMagentoPersonalization
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento setup:static-content:deploy -f
bin/magento cache:flush
```

From Packagist, once core ≥ 2.7 is available: `composer require parkktech/fastmagento-personalization`,
same steps after. The module declares `<sequence>` after `ParkkTech_FastMagento` and depends on it in
`composer.json`; it cannot be installed without core.

Uninstall is `module:disable` + `setup:upgrade`; core keeps serving with no personalisation code
on the page.

## The two switches and the dial

All configuration lives under **Stores › Configuration › FastMagento › Personalisation (beta)**.
Config paths are unchanged from when this shipped inside core.

| Path | Default | Meaning |
|---|---|---|
| `fastmagento/personalization/build_profiles` | `1` | Build and refresh shopper profiles (hourly cron + on-demand CLI). Safe to leave on: nothing is served from them until `enabled`. |
| `fastmagento/personalization/enabled` | `0` | Serve personalised ranking. Off ⇒ byte-identical to core. |
| `fastmagento/personalization/exploration_percent` | `10` | Share of a page reserved for under-exposed products. `0` disables the slot. Never touches curated link rows. |

Per-surface strength dials (`impact_search`, `impact_plp`, `impact_recommendations`, default
25 / 25 / 50), the gates (`min_strength`, `min_confidence`), decay (`half_life_days`, 180) and the
A/B test (`ab_enabled`, off) are in the same group; each field's admin comment explains it.

## Operator CLI

| Command | What it does |
|---|---|
| `fastmagento:profile:backfill` | Build shopper profiles for every customer with order history (resumable) |
| `fastmagento:profile:inspect --customer=<id>` | Show the purchase-derived attribute affinities for a shopper (read-only) |
| `fastmagento:personalization:explain --customer=<id>` | Show the scoring clauses personalisation would add for one shopper, and why |
| `fastmagento:personalization:exposure` | Measure conversion per impression, so a product that was never shown is not read as one that never sold |
| `fastmagento:personalization:discrimination` | Measure per-value catalogue discrimination (IDF) used to gate boosts |
| `fastmagento:personalization:tune` | Run one auto-weight tuning step against the measured A/B conversion |

Two cron jobs keep the data fresh: `fastmagento_personalization_refresh` (`15 * * * *`, profiles,
exposure and discrimination, incremental) and `fastmagento_personalization_tune` (`10 4 * * *`).

`bin/magento fastmagento:doctor` (from core) gains a **Personalisation** section when this module is
installed: index, mapping, backfill coverage, capture path, cron heartbeat, every plugin/observer
wiring, the exploration slot and the A/B test. It is the first thing to run when something looks
wrong.

## Data-subject requests

There is deliberately no shopper-facing profile page. A request to see or erase what the store has
inferred about a customer is handled by the operator:

```bash
bin/magento fastmagento:profile:inspect --customer=<id>            # what is held
bin/magento fastmagento:profile:inspect --customer=<id> --forget-facts   # clear inferred facts
```

Profiles are rebuilt from order history by the next refresh; delete the customer's orders and
events if the request is a full erasure.

## How capture works

With a full-page cache in front, PHP does not see most listing requests, so the browser reports
views, dwell, impressions and facet selections to `/fastmagento/event/collect` from this module's
own `fastmagento-personalization.js`. That bundle is self-contained (no jQuery, RequireJS or Alpine,
and no dependency on core's `fastmagento.js`), so it degrades on its own if only one package is
deployed. Without a full-page cache, PHP records facet selections itself and the browser stays
quiet; `fastmagento:doctor` reports which mode is active.

## Acceptance evidence (summary)

Measured on a live store with the whole-system harnesses that capture each surface with the
feature off, with it on, and with the module removed, and compare query counts against recorded
baselines. In short:

- **Off is byte-identical** on PLP, search page, instant search, PDP and GraphQL search, after
  per-surface, A/A-demonstrated normalisation only (SURF-03, EXPL-03).
- **Query budget is flat**: turning personalisation or the exploration slot on never raised the
  MySQL query count of a render, and the OpenSearch query total stayed within budget (SURF-04,
  EXPL-03).
- **Link rows are re-ordered, never edited**: for 15 fixture shoppers, related / up-sell /
  cross-sell rows kept the merchant's exact set, tied products kept the merchant's order, and
  movement only happened for shoppers the gates said were owed it (SURF-01).
- **GraphQL is covered** with its own-area plugin registration and token identity (SURF-02).
- **An under-exposed product actually surfaces** in the exploration slot, and the slot never
  touches a curated link row (EXPL-01, EXPL-02, EXPL-04).
- **Doctor covers every wiring point** and was shown to FAIL when each declaration was disabled
  (SURF-05, EXPL-04).

## Constraints

Two rules this package never breaks: the exploration slot never touches a curated related /
up-sell / cross-sell row (it only re-orders them), and every page must be byte-identical with the
feature off.

## License

OSL-3.0 / AFL-3.0, same as FastMagento core.
