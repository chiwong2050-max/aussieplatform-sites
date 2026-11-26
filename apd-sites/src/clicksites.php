<?php
/**
 * ClickSites / Next.js page builder. Pure PHP, no WordPress dependency.
 *
 * ClickSites pages are Next.js exports: the real content lives inside the
 * __NEXT_DATA__ JSON, either as a "content" object (header, aboutUs,
 * contentWithImages, services, gallery, testimonials, cta) or as a rendered
 * "html" string. This module reads that structure and rebuilds a clean,
 * responsive template that uses the plugin's placeholder system instead of
 * the demo copy, so no fake stats, awards, reviews or licence numbers and no
 * ClickSites branding survive.
 */

if (!function_exists('apd_site_cs_rgb_to_hex')) {
    /**
     * "rgb(56, 78, 70)" / "#384e46" => "#384e46". Returns null when unknown.
     */
    function apd_site_cs_rgb_to_hex(string $value): ?string
    {
        $value = trim($value);
        if (preg_match('/#([0-9a-f]{6})\b/i', $value, $m)) {
            return '#' . strtolower($m[1]);
        }
        if (preg_match('/#([0-9a-f]{3})\b/i', $value, $m)) {
            $c = $m[1];
            return '#' . strtolower($c[0] . $c[0] . $c[1] . $c[1] . $c[2] . $c[2]);
        }
        if (preg_match('/rgba?\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})/i', $value, $m)) {
            return sprintf('#%02x%02x%02x', (int) $m[1], (int) $m[2], (int) $m[3]);
        }
        return null;
    }
}

if (!function_exists('apd_site_cs_colors_in')) {
    /**
     * Every rgb()/hex colour mentioned in a chunk of HTML/CSS.
     *
     * @return string[] hex colours
     */
    function apd_site_cs_colors_in(string $text): array
    {
        $out = array();
        if (preg_match_all('/rgba?\([^)]*\)|#[0-9a-f]{3,6}\b/i', $text, $m)) {
            foreach ($m[0] as $raw) {
                $hex = apd_site_cs_rgb_to_hex($raw);
                if ($hex) {
                    $out[] = $hex;
                }
            }
        }
        return $out;
    }
}

if (!function_exists('apd_site_cs_luma')) {
    function apd_site_cs_luma(string $hex): int
    {
        $hex = ltrim($hex, '#');
        if (6 !== strlen($hex)) {
            return 255;
        }
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));
        return (int) round(0.299 * $r + 0.587 * $g + 0.114 * $b);
    }
}

if (!function_exists('apd_site_cs_saturation')) {
    function apd_site_cs_saturation(string $hex): int
    {
        $hex = ltrim($hex, '#');
        if (6 !== strlen($hex)) {
            return 0;
        }
        $c = array(
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2)),
        );
        $max = max($c);
        $min = min($c);
        return $max - $min;
    }
}

if (!function_exists('apd_site_cs_shade')) {
    /**
     * Darken a hex colour by a factor 0..1.
     */
    function apd_site_cs_shade(string $hex, float $factor): string
    {
        $hex = ltrim($hex, '#');
        if (6 !== strlen($hex)) {
            return $hex;
        }
        $parts = array();
        for ($i = 0; $i < 3; $i++) {
            $c = hexdec(substr($hex, $i * 2, 2));
            $c = (int) max(0, min(255, round($c * (1 - $factor))));
            $parts[] = sprintf('%02x', $c);
        }
        return '#' . implode('', $parts);
    }
}

if (!function_exists('apd_site_cs_pick_palette')) {
    /**
     * Derive a small, sensible palette from the design's own colours.
     *
     * @return array bg, accent, accent2, muted, font
     */
    function apd_site_cs_pick_palette(array $content, string $rendered): array
    {
        $palette = array(
            'bg'      => '#ffffff',
            'accent'  => '#0b6bcb',
            'accent2' => '#084e93',
            'muted'   => '#5a6b78',
            'font'    => 'system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif',
        );

        // Background: the hero overlay colour when present.
        $hero_html = isset($content['header']['html']) ? (string) $content['header']['html'] : '';
        if (preg_match('/class="is-overlay"[^>]*background-color:\s*([^;"]+)/i', $hero_html, $m)) {
            $hex = apd_site_cs_rgb_to_hex($m[1]);
            if ($hex) {
                $palette['bg'] = $hex;
            }
        }

        // Muted text colour from the hero sub-headline when present.
        if (preg_match('/id="header_subheadline"[^>]*color:\s*([^;"]+)/i', $rendered, $m)) {
            $hex = apd_site_cs_rgb_to_hex($m[1]);
            if ($hex) {
                $palette['muted'] = $hex;
            }
        }

        // Accent: the most saturated, mid-tone colour used by a button.
        $button_colors = array();
        foreach (array('header', 'cta') as $key) {
            $html = isset($content[$key]['html']) ? (string) $content[$key]['html'] : '';
            if (preg_match_all('/<a\b[^>]*style="([^"]*)"/i', $html, $mm)) {
                foreach ($mm[1] as $style) {
                    foreach (apd_site_cs_colors_in($style) as $hex) {
                        $button_colors[] = $hex;
                    }
                }
            }
        }
        $best = '';
        $best_score = -1;
        foreach ($button_colors as $hex) {
            $luma = apd_site_cs_luma($hex);
            if ($luma < 25 || $luma > 235) {
                continue;
            }
            $score = apd_site_cs_saturation($hex);
            if ($score > $best_score) {
                $best_score = $score;
                $best = $hex;
            }
        }
        if ('' !== $best && $best_score >= 20) {
            $palette['accent'] = $best;
        }
        $palette['accent2'] = apd_site_cs_shade($palette['accent'], 0.28);

        // Font: prefer an explicit family, else a type-* utility class.
        if (preg_match('/font-family:\s*([^;"]+)/i', $rendered, $mf)) {
            $family = trim(preg_replace('/\s+/', ' ', $mf[1]));
            if ('' !== $family && false === stripos($family, 'inherit')) {
                $palette['font'] = $family . ', system-ui, sans-serif';
            }
        } elseif (preg_match('/\btype-([a-z0-9]+)\b/i', $rendered, $mt)) {
            $palette['font'] = ucfirst($mt[1]) . ', system-ui, sans-serif';
        }

        return $palette;
    }
}

if (!function_exists('apd_site_cs_fonts')) {
    /**
     * Google Font families referenced by the design, as font query strings.
     *
     * @return string[] e.g. "Poppins:wght@400;600;700"
     */
    function apd_site_cs_fonts(string $text): array
    {
        $found = array();
        if (preg_match_all('#fonts\.googleapis\.com/css2?[^"\'<>]*#i', $text, $m)) {
            foreach ($m[0] as $url) {
                if (preg_match('/family=([^&"\'<>]+)/i', $url, $f)) {
                    $found[] = rawurldecode($f[1]);
                }
            }
        }
        if (!$found && preg_match('/font-family:\s*([A-Za-z][A-Za-z0-9 ]+)/i', $text, $m2)) {
            $found[] = trim($m2[1]) . ':wght@400;600;700';
        }
        return array_values(array_unique($found));
    }
}

if (!function_exists('apd_site_cs_next_data')) {
    /**
     * Pull the Next.js payload out of an exported page.
     *
     * @return array|null ['name','content'(array|null),'rendered'(string)]
     */
    function apd_site_cs_next_data(string $html)
    {
        if (!preg_match('#<script id="__NEXT_DATA__"[^>]*>(.*?)</script>#s', $html, $m)) {
            return null;
        }
        $json = json_decode($m[1], true);
        if (!is_array($json)) {
            return null;
        }
        $data = $json['props']['pageProps']['data'] ?? null;
        if (!is_array($data)) {
            return null;
        }
        $content = null;
        if (isset($data['content']) && is_string($data['content'])) {
            $decoded = json_decode($data['content'], true);
            if (is_array($decoded)) {
                $content = $decoded;
            }
        }
        return array(
            'name'     => isset($data['name']) ? (string) $data['name'] : '',
            'content'  => $content,
            'rendered' => isset($data['html']) ? (string) $data['html'] : '',
        );
    }
}

if (!function_exists('apd_site_cs_inner')) {
    /**
     * Inner HTML of the first element carrying the given id.
     */
    function apd_site_cs_inner(string $html, string $id): string
    {
        if (preg_match('#<([a-z0-9]+)\b[^>]*\bid="' . preg_quote($id, '#') . '"[^>]*>(.*?)</\1>#is', $html, $m)) {
            return $m[2];
        }
        return '';
    }
}

if (!function_exists('apd_site_cs_has_id')) {
    function apd_site_cs_has_id(string $html, string $id): bool
    {
        return (bool) preg_match('/\bid="' . preg_quote($id, '#') . '"/i', $html);
    }
}

if (!function_exists('apd_site_cs_text')) {
    /**
     * Plain text from a fragment: entities decoded, tags stripped, collapsed.
     */
    function apd_site_cs_text(string $html): string
    {
        $text = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = strip_tags($text);
        $text = preg_replace('/\s+/', ' ', $text);
        return trim((string) $text);
    }
}

if (!function_exists('apd_site_cs_claims')) {
    /**
     * Made-up credibility claims we never want to carry over from a demo.
     *
     * @return string[] the matched phrases
     */
    function apd_site_cs_claims(string $text): array
    {
        $hits = array();
        $patterns = array(
            '/\b(?:founded|established|since)\s+(?:in\s+)?(?:19|20)\d{2}\b/i',
            '/\b(?:over|more than|with)\s+\d+\+?\s+years?\b/i',
            '/\b\d+\+?\s+years?\s+(?:of\s+)?(?:experience|in\s+business|serving)\b/i',
            '/\b(?:award[- ]winning|award winning)\b/i',
            '/\b(?:licen[cs]e|lic)\s*(?:no\.?|number|#)\s*[:\-]?\s*[A-Z0-9\-]{4,}/i',
            '/\b\d{3}\s?\d{3}\s?\d{3}\b/',                       // ABN-looking
            '/\b(?:certified|accredited|insured)\s+(?:team|professionals|company)\b/i',
            '/\b(?:satisfaction\s+guaranteed|100%\s+guarantee)\b/i',
        );
        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $text, $m)) {
                foreach ($m[0] as $hit) {
                    $hits[] = trim((string) $hit);
                }
            }
        }
        return array_values(array_unique($hits));
    }
}

if (!function_exists('apd_site_cs_base_css')) {
    /**
     * Shared, mobile-first stylesheet used by every ClickSites template.
     */
    function apd_site_cs_base_css(): string
    {
        return <<<CSS
*,
*::before,
*::after { box-sizing: border-box; }

html { -webkit-text-size-adjust: 100%; }

body {
  margin: 0;
  font-family: var(--apd-font);
  font-size: 17px;
  line-height: 1.6;
  color: var(--apd-ink);
  background: var(--apd-bg);
}

img { display: block; max-width: 100%; height: auto; }

a { color: var(--apd-accent); }

h1, h2, h3 { line-height: 1.18; margin: 0 0 .5em; }
h1 { font-size: clamp(2rem, 6vw, 3.25rem); }
h2 { font-size: clamp(1.5rem, 4vw, 2.25rem); }
h3 { font-size: 1.2rem; }

.apd-wrap { width: 100%; max-width: 1080px; margin: 0 auto; padding: 0 18px; }
.apd-section { padding: 48px 0; border-top: 1px solid var(--apd-line); }
.apd-lead { font-size: 1.1rem; color: var(--apd-muted); }

.apd-topbar {
  position: sticky; top: 0; z-index: 20;
  background: rgba(255, 255, 255, .94);
  border-bottom: 1px solid var(--apd-line);
}
.apd-topbar-inner { display: flex; align-items: center; justify-content: space-between; gap: 12px; min-height: 62px; }
.apd-brand { display: flex; align-items: center; gap: 10px; font-weight: 700; color: inherit; text-decoration: none; }
.apd-logo { width: 40px; height: 40px; border-radius: 8px; object-fit: cover; }
.apd-call {
  display: inline-flex; align-items: center; min-height: 44px; padding: 0 16px;
  border-radius: 999px; background: var(--apd-accent); color: #fff; font-weight: 600; text-decoration: none;
}

.apd-hero { padding: 56px 0 44px; }
.apd-hero-img { margin-top: 28px; border-radius: var(--apd-radius); width: 100%; }
.apd-kicker { margin: 0 0 10px; text-transform: uppercase; letter-spacing: .08em; font-size: .8rem; font-weight: 600; color: var(--apd-accent-2); }

.apd-btn {
  display: inline-flex; align-items: center; justify-content: center;
  min-height: 48px; padding: 12px 22px; margin: 4px 8px 4px 0;
  border-radius: 999px; background: var(--apd-accent); color: #fff;
  font-weight: 600; text-decoration: none;
}
.apd-btn-ghost { background: transparent; color: var(--apd-accent); border: 2px solid currentColor; }

.apd-grid { display: grid; gap: 18px; grid-template-columns: 1fr; list-style: none; margin: 0; padding: 0; }
.apd-card { padding: 20px; border: 1px solid var(--apd-line); border-radius: var(--apd-radius); background: #fff; }
.apd-card h3 { margin-top: 0; }
.apd-card p { margin: 0; color: var(--apd-muted); }
.apd-card-img img { border-radius: 10px; margin-bottom: 12px; }

.apd-gallery-grid { display: grid; gap: 12px; grid-template-columns: repeat(2, 1fr); list-style: none; margin: 0; padding: 0; }
.apd-gallery-grid img { border-radius: 10px; width: 100%; height: 100%; object-fit: cover; aspect-ratio: 4 / 3; }

.apd-testimonials blockquote { margin: 0 0 16px; padding: 18px; border-left: 4px solid var(--apd-accent); background: #f8fafc; border-radius: 0 10px 10px 0; }
.apd-testimonials cite { display: block; margin-top: 8px; color: var(--apd-muted); font-style: normal; font-size: .92rem; }

.apd-split { display: grid; gap: 24px; grid-template-columns: 1fr; }
.apd-about-img { border-radius: var(--apd-radius); }
.apd-facts { margin: 0; }
.apd-facts dt { font-weight: 600; margin-top: 12px; }
.apd-facts dd { margin: 2px 0 0; color: var(--apd-muted); }

.apd-footer { padding: 26px 0; border-top: 1px solid var(--apd-line); font-size: .9rem; color: var(--apd-muted); text-align: center; }
.apd-footer a { color: inherit; }

@media (min-width: 700px) {
  .apd-grid { grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); }
  .apd-gallery-grid { grid-template-columns: repeat(3, 1fr); }
  .apd-split { grid-template-columns: 1.1fr 1fr; align-items: center; }
  .apd-section { padding: 64px 0; }
}
CSS;
    }
}

if (!function_exists('apd_site_cs_build')) {
    /**
     * Rebuild a clean template from the parsed ClickSites page.
     *
     * @param array $nd   Output of apd_site_cs_next_data().
     * @return array template_html, style_css, stock, fonts, claims, removed, notes
     */
    function apd_site_cs_build(array $nd): array
    {
        $content  = is_array($nd['content']) ? $nd['content'] : array();
        $rendered = (string) $nd['rendered'];
        $removed  = array();
        $notes    = array();
        $claims   = array();

        $palette = apd_site_cs_pick_palette($content, $rendered);
        $fonts   = apd_site_cs_fonts($rendered . ' ' . json_encode($content));
        // Some exports ship a local type-*.css instead of a Google Fonts URL;
        // fall back to the family the palette already chose.
        if (!$fonts && !empty($palette['font'])) {
            $family = trim(explode(',', (string) $palette['font'])[0]);
            if ('' !== $family && !preg_match('/^(?:system-ui|-apple-system|sans-serif|serif|inherit)$/i', $family)) {
                $fonts[] = $family . ':wght@400;600;700';
            }
        }

        $hero_heading = apd_site_cs_text(apd_site_cs_inner($rendered, 'header_headline'));
        $hero_sub     = apd_site_cs_text(apd_site_cs_inner($rendered, 'header_subheadline'));

        foreach (array($hero_heading, $hero_sub) as $sample) {
            foreach (apd_site_cs_claims($sample) as $hit) {
                $claims[] = $hit;
            }
        }
        // Demo copy is replaced by placeholders, so it is removed by design.
        $removed[] = 'demo headline and sub-heading text';
        $removed[] = 'demo about, services, gallery and testimonial copy';

        $hero_image  = isset($content['header']['image']) ? (string) $content['header']['image'] : '';
        $about_image = isset($content['aboutUs']['image']) ? (string) $content['aboutUs']['image'] : '';

        // Services: title + short text.
        $services = array();
        if (isset($content['services']['items']) && is_array($content['services']['items'])) {
            foreach ($content['services']['items'] as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $services[] = array(
                    'title' => (string) ($item['service'] ?? ''),
                    'text'  => (string) ($item['description'] ?? ''),
                    'image' => (string) ($item['image'] ?? ''),
                );
            }
        }

        // Why-us: the first feature block (honest, generic benefits).
        $why_us = array();
        if (isset($content['contentWithImages'][0]['insights']) && is_array($content['contentWithImages'][0]['insights'])) {
            foreach ($content['contentWithImages'][0]['insights'] as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $why_us[] = array(
                    'title' => (string) ($item['subtitle'] ?? ''),
                    'text'  => (string) ($item['description'] ?? ''),
                    'image' => (string) ($item['image'] ?? ''),
                );
            }
            // Blocks that name certifications or guarantees are not reused.
            if (isset($content['contentWithImages'][1]['insights'])) {
                $removed[] = 'feature block with unverifiable certification / guarantee claims';
            }
        }

        // Gallery images.
        $gallery = array();
        if (isset($content['gallery']) && is_array($content['gallery'])) {
            foreach ($content['gallery'] as $item) {
                if (is_array($item) && !empty($item['url'])) {
                    $gallery[] = array('image' => (string) $item['url'], 'caption' => '');
                } elseif (is_string($item) && '' !== $item && false === strpos($item, '<')) {
                    $gallery[] = array('image' => $item, 'caption' => '');
                }
            }
        }

        // Testimonials are never carried over: demo reviews would be fake.
        if (!empty($content['testimonials']['items'])) {
            $removed[] = 'demo testimonials (no fake reviews)';
        }

        $has_hero    = apd_site_cs_has_id($rendered, 'header_headline');
        $has_about   = apd_site_cs_has_id($rendered, 'aboutUs_description');
        $has_services = apd_site_cs_has_id($rendered, 'services_title') || apd_site_cs_has_id($rendered, 'service_1');
        $has_cta     = apd_site_cs_has_id($rendered, 'cta_text');

        if (!$has_hero) {
            $notes[] = 'No hero section found in the export.';
        }

        $template = apd_site_cs_markup(array(
            'has_hero'     => $has_hero,
            'has_about'    => $has_about,
            'has_services' => $has_services,
            'has_why'      => !empty($why_us),
            'has_gallery'  => !empty($gallery),
            'has_cta'      => $has_cta,
            'hero_image'   => $hero_image,
        ));

        $css = apd_site_cs_palette_css($palette) . "\n" . apd_site_cs_base_css() . "\n";

        // Stock images the owner keeps until they upload their own.
        $stock = array(
            'hero_image'  => $hero_image,
            'about_image' => $about_image,
            'gallery'     => array_map(static function ($g) {
                return array('image' => $g['image'], 'caption' => '');
            }, $gallery),
            'services'    => array_map(static function ($s) {
                return array('title' => $s['title'], 'text' => $s['text'], 'image' => $s['image']);
            }, $services),
            'why_us'      => array_map(static function ($w) {
                return array('title' => $w['title'], 'text' => $w['text'], 'image' => $w['image']);
            }, $why_us),
        );

        $notes[] = 'Rebuilt from the __NEXT_DATA__ schema (rendered HTML used to detect sections).';

        return array(
            'template_html' => $template,
            'style_css'     => $css,
            'stock'         => $stock,
            'fonts'         => $fonts,
            'palette'       => $palette,
            'claims'        => array_values(array_unique($claims)),
            'removed'       => $removed,
            'notes'         => $notes,
        );
    }
}

if (!function_exists('apd_site_cs_palette_css')) {
    function apd_site_cs_palette_css(array $palette): string
    {
        return ':root{'
            . '--apd-accent:' . $palette['accent'] . ';'
            . '--apd-accent-2:' . $palette['accent2'] . ';'
            . '--apd-bg:' . $palette['bg'] . ';'
            . '--apd-muted:' . $palette['muted'] . ';'
            . '--apd-ink:#12202b;'
            . '--apd-line:#e6ecf2;'
            . '--apd-radius:14px;'
            . '--apd-font:' . $palette['font'] . ';'
            . '}';
    }
}

if (!function_exists('apd_site_cs_markup')) {
    /**
     * The rebuilt page markup, with {{field}} and {{#if}}/{{#each}} blocks.
     */
    function apd_site_cs_markup(array $f): string
    {
        $html = '';

        $html .= '<header class="apd-topbar"><div class="apd-wrap apd-topbar-inner">'
            . '<a class="apd-brand" href="#top">'
            . '{{#if logo}}<img class="apd-logo" src="{{logo}}" alt="" width="40" height="40" loading="lazy">{{/if}}'
            . '<span>{{business_name}}</span></a>'
            . '<a class="apd-call" href="tel:{{phone}}">Call {{phone}}</a>'
            . '</div></header>';

        $html .= '<main id="top">';

        if (!empty($f['has_hero'])) {
            $html .= '<section class="apd-hero"><div class="apd-wrap">'
                . '{{#if service_area}}<p class="apd-kicker">{{service_area}}</p>{{/if}}'
                . '<h1>{{hero_heading}}</h1>'
                . '<p class="apd-lead">{{hero_sub}}</p>'
                . '<p class="apd-actions"><a class="apd-btn" href="tel:{{phone}}">Call {{phone}}</a> '
                . '<a class="apd-btn apd-btn-ghost" href="#contact">{{cta_text}}</a></p>'
                . '{{#if hero_image}}<img class="apd-hero-img" src="{{hero_image}}" alt="{{business_name}}" width="1200" height="800">{{/if}}'
                . '</div></section>';
        }

        if (!empty($f['has_about'])) {
            $html .= '<section class="apd-section apd-about"><div class="apd-wrap apd-split">'
                . '<div class="apd-copy"><h2>About us</h2><p>{{about}}</p></div>'
                . '{{#if about_image}}<img class="apd-about-img" src="{{about_image}}" alt="" width="800" height="600" loading="lazy">{{/if}}'
                . '</div></section>';
        }

        if (!empty($f['has_services'])) {
            $html .= '<section class="apd-section apd-services"><div class="apd-wrap">'
                . '<h2>Our services</h2>'
                . '<ul class="apd-grid">{{#each services}}<li class="apd-card apd-card-img">'
                . '{{#if image}}<img src="{{image}}" alt="" width="600" height="400" loading="lazy">{{/if}}'
                . '<h3>{{title}}</h3><p>{{text}}</p></li>{{/each}}</ul>'
                . '</div></section>';
        }

        if (!empty($f['has_why'])) {
            $html .= '{{#if why_us}}<section class="apd-section apd-why"><div class="apd-wrap">'
                . '<h2>Why choose us</h2>'
                . '<ul class="apd-grid">{{#each why_us}}<li class="apd-card apd-card-img">'
                . '{{#if image}}<img src="{{image}}" alt="" width="600" height="400" loading="lazy">{{/if}}'
                . '<h3>{{title}}</h3><p>{{text}}</p></li>{{/each}}</ul>'
                . '</div></section>{{/if}}';
        }

        if (!empty($f['has_gallery'])) {
            $html .= '{{#if gallery}}<section class="apd-section apd-gallery"><div class="apd-wrap">'
                . '<h2>Recent work</h2>'
                . '<ul class="apd-gallery-grid">{{#each gallery}}<li>'
                . '<img src="{{image}}" alt="{{caption}}" width="600" height="450" loading="lazy"></li>{{/each}}</ul>'
                . '</div></section>{{/if}}';
        }

        $html .= '{{#if testimonials}}<section class="apd-section apd-testimonials"><div class="apd-wrap">'
            . '<h2>What clients say</h2>'
            . '{{#each testimonials}}<blockquote><p>{{text}}</p><cite>{{author}}</cite></blockquote>{{/each}}'
            . '</div></section>{{/if}}';

        $html .= '<section class="apd-section apd-contact" id="contact"><div class="apd-wrap apd-split">'
            . '<div class="apd-copy"><h2>Get in touch</h2>'
            . '<p class="apd-lead">{{cta_text}}</p>'
            . '<p><a class="apd-btn" href="tel:{{phone}}">Call {{phone}}</a></p>';
        if (!empty($f['has_cta'])) {
            $html .= '<p><a class="apd-btn apd-btn-ghost" href="mailto:{{email}}">Email us</a></p>';
        }
        $html .= '</div>'
            . '<dl class="apd-facts">'
            . '<dt>Phone</dt><dd><a href="tel:{{phone}}">{{phone}}</a></dd>'
            . '<dt>Email</dt><dd><a href="mailto:{{email}}">{{email}}</a></dd>'
            . '{{#if service_area}}<dt>Service area</dt><dd>{{service_area}}</dd>{{/if}}'
            . '{{#if hours}}<dt>Hours</dt><dd>{{hours}}</dd>{{/if}}'
            . '{{#if abn}}<dt>ABN</dt><dd>{{abn}}</dd>{{/if}}'
            . '{{#if licence}}<dt>Licence</dt><dd>{{licence}}</dd>{{/if}}'
            . '{{#if suburb}}<dt>Based in</dt><dd>{{suburb}} {{state}}</dd>{{/if}}'
            . '</dl></div></section>';

        $html .= '</main>';

        return $html;
    }
}
