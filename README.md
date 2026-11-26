# APD Sites

A WordPress **must-use plugin** that adds tradie mini-websites to the
[aussieplatform.app](https://aussieplatform.app) directory. It reuses the
helpers from the directory's own `zz-apd-core.php` (in `apd_trades()`,
`apd_get()`, `apd_layout()`, `apd_nonce()`, `apd_family_ok()`, `apd_tb()`, …)
and hooks the existing biz page with an action instead of editing core files.

- Target: **PHP 8.3**, **WordPress 6.x / 7.x**.
- No Composer runtime dependencies, no JavaScript framework.
- Every switch ships **off**.

## Install

1. Copy the loader and its folder into the MU-plugins directory:

   ```
   wp-content/mu-plugins/zz-apd-sites.php
   wp-content/mu-plugins/apd-sites/
   ```

2. `zz-apd-sites.php` loads **after** `zz-apd-core.php` (MU-plugins load in
   alphabetical order). If the core helper `apd_get()` is missing the loader
   bails out quietly and loads nothing.

3. The plugin creates the `apd_tb('sites')` table on `init` via `dbDelta`,
   tracked by the `apd_sites_db_version` option.

## Switches

Set these at the top of `zz-apd-sites.php` (all default to `false`):

| Constant | Effect |
| --- | --- |
| `APD_SITES_ON` | `false` hides every entry point and returns 404 for the routes. Admins can still use the routes for testing. |
| `APD_SITES_BILLING` | `false` shows no price, no payment step and no payment keys. |
| `APD_SITES_INDEXING` | `false` marks every `/site/` page `noindex,follow` (meta tag plus `X-Robots-Tag`) and keeps it out of sitemaps. `robots.txt` is never touched. |

## Flow

- **Owner only.** A site can be built by the listing owner
  (`listing.user_id == apd_me()`). Other people are sent to `/claim/<id>/`.
- `/my-site/<listing_id>/` — the layout gallery. Templates that match the
  listing's trades come first, then the `*` ones.
- The build form is generated from each template's `meta.json` and prefilled
  only from real listing fields (name, trades, suburb, phone, website, email,
  ABN, licence, hours, description). Values are validated against their `max`.
- Photo upload accepts **jpg, png or webp** only, **max 5 MB** each, through the
  WordPress media library. EXIF is stripped and the owner must tick
  *"These are my own photos"*.
- Preview, then **Submit**, which sets the site to `pending` and adds a row to
  `apd_tb('reports')` with reason **Site review** so it appears in the existing
  admin queue. A small admin screen (`manage_options`) approves or rejects it.
- Live pages are at **`/site/<listing_id>-<slug>/`**, rendered with the
  template's own CSS and a footer link "Listed on aussieplatform" back to
  `apd_url($l)`. Pending, rejected or draft pages return 404 to the public; the
  owner and admins can still preview them.
- On the biz page the owner sees a **"Make a free website for this listing"**
  link (via the `apd_biz_actions` action).

## Templates

One folder per template: `templates/<id>/` holding `template.html`, `style.css`
and `meta.json`.

```json
{
  "id": "example-classic",
  "name": "Classic hero",
  "example": true,
  "trades": ["*"],
  "fields": [
    { "key": "name", "label": "Your business name", "type": "text", "required": true, "max": 80 }
  ]
}
```

- `trades` uses the real slugs from `apd_trades()`, or `"*"` for any trade.
- Placeholders are `{{field}}` and `{{#each services}}…{{/each}}`.
- All values are escaped when rendered.

Three placeholder templates ship now, each labelled **"Example layout"** in the
gallery (`example: true`, `trades: ["*"]`, neutral labels, no fake names,
prices, reviews or photos): `example-classic`, `example-services`,
`example-gallery`.

Trade families live in `src/trade-families.php`. Every slug from
`apd_trades()` belongs to exactly one family: **build, outdoor, home, auto,
repair, pets, pro**.

## Import command

Turn an exported ClickSites HTML folder into the template format:

```
php bin/import-clicksites.php import/<folder> <template-id> --trades=plumber,electrician
php bin/import-clicksites.php import/<folder> <template-id> --family=build
```

The importer:

- strips `<script>`, `on*` attributes, `javascript:` URLs, iframes, external
  `<link>`/`@import` tags, analytics and tracking pixels, and any forms posting
  off-site;
- keeps the layout and CSS and copies local images into the template;
- replaces obvious sample text with `{{field}}` placeholders where possible and
  lists everything left for a human to map;
- writes `meta.json` with `example: false` and prints a report of what was
  removed and which placeholders are unknown.

## Safety

- Every text field runs through `apd_family_ok()`; a failure holds the page for
  review.
- No fake reviews, testimonials or star ratings. No AI-written text.
- No AdSense or ad slots are added; the site's existing single Auto ads loader
  stays as it is.
- Nonces on every POST, plus rate limits on submit and upload.
- No secrets in git.

## Tests

A tiny runner (no Composer, WordPress stubs for the pure `src/` code):

```
cd apd-sites
php tests/run.php
```

It covers the importer, the trade family mapping, the gallery ordering, the
renderer escaping, the validator, the indexing head and the route gating, and
runs `php -l` on every file.

## Layout

```
zz-apd-sites.php            loader (switches + includes)
apd-sites/
  src/                      pure PHP: renderer, sanitiser, validator,
                            trade mapper, router gate, importer
  wp/                       routes, DB table, uploads, admin screen, biz link
  templates/                <id>/{template.html, style.css, meta.json}
  bin/                      import-clicksites.php
  tests/                    run.php + fixtures
reference/                  read-only copies of the directory's own mu-plugins
```
