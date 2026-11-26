# APD Sites

A WordPress **must-use plugin** that adds tradie mini-websites to the
[aussieplatform.app](https://aussieplatform.app) directory. It reuses the
helpers from the directory's own `zz-apd-core.php` (`apd_trades()`,
`apd_get()`, `apd_layout()`, `apd_nonce()`, `apd_family_ok()`, `apd_tb()`, …)
and hooks the existing biz page through `wp_footer` instead of editing core
files.

The directory exposes a listing as a **database row object** (`apd_get()`
returns `$l->id`, `$l->user_id`, `$l->name`, `$l->trades` as a comma separated
string, `$l->suburb_id`, `$l->phone`, `$l->website`, `$l->email`, `$l->abn`,
`$l->licence`, `$l->descr`, `$l->hours`), and `apd_url($l)` returns the relative
`/biz/<id>-<slug>/` path. The plugin follows those conventions exactly.

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

## Constants

Set these at the top of `zz-apd-sites.php` (the first three default to `false`):

| Constant | Default | Effect |
| --- | --- | --- |
| `APD_SITES_ON` | `false` | `false` hides every entry point and returns 404 for the routes. Admins can still use the routes for testing. |
| `APD_SITES_BILLING` | `false` | `false` shows no price, no payment step and no payment keys. Publishing just starts the free trial. |
| `APD_SITES_INDEXING` | `false` | `false` marks every `/site/` page `noindex,follow` (meta tag plus `X-Robots-Tag`) and keeps it out of sitemaps. `robots.txt` is never touched. |
| `APD_SITES_AI` | `false` | `true` enables the optional OpenRouter writer. It stays off unless a readable key file exists. |
| `APD_SITES_AI_KEY_FILE` | *(empty)* | Absolute path to a file holding the OpenRouter API key. The key is read on demand and never logged or stored. |
| `APD_SITES_AI_MODEL` | `openai/gpt-4o-mini` | OpenRouter model id used for the one-off copy call. |

The daily AI cap is a WP option, `apd_sites_ai_daily_cap` (default `50`).

## Builder flow — `/my-site/<listing_id>/`

**Owner only.** A site can only be built by the listing owner
(`listing.user_id == apd_me()`) or an admin; anyone else is redirected to
`/claim/<id>/`. The builder is a 7-step wizard, one screen at a time, with big
buttons, and every step saves as a **draft** so the owner can come back and edit
until they press **Publish**.

1. **Pick a design** — templates for the listing's trade family are listed
   first, then every other template, each with a live thumbnail.
2. **Your details** — prefilled from the real listing (name, phone, email,
   suburb, ABN, licence, logo, description, trades) and editable. ABN is
   validated against the ATO weights check (`apd_site_abn_valid()`) and the
   phone against `apd_site_phone_valid()`.
3. **Your services** — a tick list built from `apd_site_service_options()` for
   the trades, plus free-text rows for the owner's own services.
4. **Words** — one button, **"Write it for me"** (see *Writing copy* below).
5. **Photos** — upload up to 8 (resized to max 1600px, converted to WebP, EXIF
   stripped), or keep the template's stock images.
6. **Preview** — full live preview at desktop and phone widths.
7. **Publish** — shows the plan **"Free 14-day trial, then A$9/month or
   A$49/year (GST included)"**. While `APD_SITES_BILLING` is `false` publishing
   simply sets `status = pending`, stores `trial_start`, and adds the site to
   the existing admin review queue (`apd_tb('reports')`, reason **Site
   review**). No card, no payment code. A `apd_sites_billing_required` filter is
   left in place so a Stripe step can be slotted in later without touching the
   flow.

Published pages live at **`https://aussieplatform.app/site/<slug>/`** and only
render when `APD_SITES_ON` is `true` **and** an admin has approved the site.
They carry a **"Website by aussieplatform.app"** footer link. After the trial
ends with billing still off the site **stays live** and the owner only sees a
friendly renewal banner — there is no lockout.

## Writing copy

Step 4 always works without any API key:

- **Default (no AI).** `apd_site_write_copy()` builds friendly Australian
  wording from per-trade-family phrase banks in `src/writer.php` (one bank for
  each of build, outdoor, home, auto, repair, pets, pro). Sentences are seeded
  by the business name and vary, so two sites never read the same.
- **Optional AI.** `apd_sites_ai_write()` is used only when `APD_SITES_AI` is
  `true` **and** `APD_SITES_AI_KEY_FILE` points at a readable file on the
  server. It makes one OpenRouter `chat/completions` request per site with a
  short prompt (`APD_SITES_AI_MODEL`, default `openai/gpt-4o-mini`), capped at
  **600 output tokens** and a **20 second timeout**. A daily cap
  (`apd_sites_ai_daily_cap`, default 50) is tracked in a WP option and logged
  per day in the admin screen. Only the business name, trade, suburb and
  services are sent — **never** emails or phone numbers. The key is never
  hard-coded and never logged.
- **Fallback.** On *any* error (missing key, cap reached, timeout, bad
  response) the code silently falls back to the no-AI writer.

Both paths obey the same rules: honest wording, no invented claims or numbers,
no prices unless the owner typed them, no fake reviews, family-safe language.

## Templates

One folder per template: `templates/<id>/` holding `template.html`, `style.css`,
a `thumbnail` image and `meta.json`.

```json
{
  "id": "cleaning-a",
  "name": "Fresh Home Cleaning",
  "example": false,
  "family": "cleaning",
  "trades": ["cleaner", "carpet-cleaning", "window-cleaning"],
  "thumbnail": "thumb.jpg"
}
```

- `trades` uses the real slugs from `apd_trades()`, or `"*"` for any trade.
- Placeholders are `{{field}}`, `{{#each services}}…{{/each}}`,
  `{{#if key}}…{{/if}}`; all values are escaped when rendered.
- `{{testimonials}}` stays hidden until the owner adds **real** reviews — the
  template never ships fake ones.

Three neutral **"Example layout"** templates ship for any trade
(`example: true`, `trades: ["*"]`): `example-classic`, `example-services`,
`example-gallery`. Four real designs imported from our own ClickSites exports
ship with `example: false`:

| Template | Family | Trades |
| --- | --- | --- |
| `cleaning-a`, `cleaning-b` | cleaning | cleaner, carpet-cleaning, window-cleaning |
| `electrical-a` | electrical | electrician, air-conditioning, solar |
| `plumbing-a` | plumbing | plumber, gasfitter |

Trade families live in `src/trade-families.php`. Every slug from
`apd_trades()` belongs to exactly one family: **build, outdoor, home, auto,
repair, pets, pro**. Design families (`apd_site_design_families()`) are
narrower and are what the importer's `--family=` maps to.

## Adding a new ClickSites design

Two steps, both repeatable. Raw exports are **not** committed (see
`.gitignore`); re-fetch them on demand.

1. **Download** the public ClickSites page and localise its images:

   ```
   php bin/fetch-clicksites.php https://aussieplatform.clicksitepages.com/<slug> import/<folder>
   ```

   This saves `import/<folder>/index.html` (rewriting every
   `media.clicksites.ai` image and same-origin `/assets` image to a local
   `assets/` path) so the import below runs fully offline. Google Fonts links
   are kept; nothing else external is.

2. **Import** the folder into a template:

   ```
   php bin/import-clicksites.php import/<folder> <template-id> --family=cleaning
   php bin/import-clicksites.php import/<folder> <template-id> --trades=plumber,gasfitter
   ```

   The importer detects the Next.js export format automatically: it uses the
   rendered HTML when present and otherwise rebuilds the sections from
   `__NEXT_DATA__`. It then:

   - strips `<script>`, `on*` attributes, `javascript:` URLs, iframes, external
     `<link>`/`@import` tags, analytics and tracking pixels, ClickSites
     branding, and any form posting off-site (the four real templates are
     regression-tested for this);
   - removes made-up stats, awards, "years in business", fake reviews and fake
     licence numbers;
   - replaces the sample text with the canonical placeholders
     (`{{business_name}} {{phone}} {{email}} {{suburb}} {{state}}
     {{service_area}} {{abn}} {{licence}} {{logo}} {{hero_heading}}
     {{hero_sub}} {{about}} {{services[]}} {{why_us[]}} {{testimonials[]}}
     {{cta_text}} {{hours}} {{gallery[]}}`);
   - compresses the images to WebP, writes a `thumbnail`, and writes
     `meta.json` with `example: false`;
   - prints what it removed and any placeholders left for a human to map.

Templates are mobile-first and lazy-load their images.

## Admin

**WP admin → Tradie sites** lists every site with its status (draft, in review,
trial, live, expired), trial days left, an **approve** / **reject with a
reason** action and a **Preview** link. The status shown is derived from the
stored status and the trial date (`apd_site_trial_state()`); with billing off an
ended trial reads as *expired* but the site stays live. The same screen shows the
AI usage log (calls per day) and the current daily cap.

## Safety

- Every text field runs through `apd_family_ok()`; a failure holds the page for
  review.
- No fake reviews, testimonials or star ratings. No fake prices, stats, awards
  or licence numbers.
- The importer strips scripts, trackers and off-site forms; the owner-only
  builder is enforced server-side (`apd_site_can_manage()`).
- No AdSense or ad slots are added; the site's existing single Auto ads loader
  stays as it is.
- Nonces on every POST, plus rate limits on submit and upload.
- No secrets in git; the AI key lives only in a server-side file.

## Tests

A tiny runner (no Composer, WordPress stubs for the pure `src/` code):

```
cd apd-sites
php tests/run.php
```

It covers the importer (including a Next.js `__NEXT_DATA__` export and the four
real templates), the trade family mapping, the gallery ordering, the renderer
with missing fields, the validator, indexing and route gating, owner-only
access, the no-AI writer for every family, the AI fallback, trial start on
publish, and runs `php -l` on every file.

## Layout

```
zz-apd-sites.php            loader (constants + includes)
apd-sites/
  src/                      pure PHP: renderer, sanitiser, validator, trade
                            mapper, router gate, importer, clicksites parser,
                            writer (no-AI + AI), trial helpers
  wp/                       routes, DB table, uploads, admin screen, biz link
  templates/                <id>/{template.html, style.css, meta.json, thumb}
  bin/                      fetch-clicksites.php, import-clicksites.php
  tests/                    run.php + fixtures
reference/                  read-only copies of the directory's own mu-plugins
import/                     raw ClickSites exports (gitignored)
```
