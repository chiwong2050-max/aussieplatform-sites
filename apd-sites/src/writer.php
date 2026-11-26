<?php
/**
 * Content writer for tradie mini-websites. Pure PHP, no WordPress dependency
 * for the default path.
 *
 * Two writers:
 *  - apd_site_write_copy(): the no-AI writer. It builds friendly Australian
 *    wording from per-trade-family phrase banks, seeded by the business so two
 *    sites never read exactly the same.
 *  - apd_sites_ai_write(): optional OpenRouter path, only used when
 *    APD_SITES_AI is true and the key file exists. It always falls back to the
 *    no-AI writer.
 *
 * Rules baked into both paths: honest wording, no invented claims or numbers,
 * no prices unless the owner typed them, and family-safe language.
 */

if (!function_exists('apd_site_writer_service_suggestions')) {
    /**
     * Suggested service titles per family, for the builder's tick list.
     *
     * @return array family => string[]
     */
    function apd_site_writer_service_suggestions(): array
    {
        return array(
            'build' => array(
                'Electrical work', 'Plumbing', 'Gas fitting', 'Air conditioning',
                'Solar installation', 'Emergency call-outs', 'New installations', 'Repairs & maintenance',
            ),
            'outdoor' => array(
                'Lawn mowing', 'Garden care', 'Tree services', 'Pool servicing',
                'Landscaping', 'Hedging & pruning',
            ),
            'home' => array(
                'Home cleaning', 'Office cleaning', 'Deep cleaning', 'Carpet cleaning',
                'Window cleaning', 'End-of-lease cleaning', 'Pest control', 'Rubbish removal',
            ),
            'auto' => array(
                'Logbook servicing', 'Car repairs', 'Auto electrical', 'Brake repairs', 'Car detailing',
            ),
            'repair' => array(
                'Appliance repairs', 'Computer repairs', 'TV & antenna', 'Phone & tablet repairs',
            ),
            'pets' => array(
                'Dog walking', 'Pet grooming', 'Pet sitting', 'Mobile pet washing',
            ),
            'pro' => array(
                'Tutoring', 'Photography', 'Bookkeeping', 'Web design', 'Graphic design',
            ),
        );
    }
}

if (!function_exists('apd_site_service_options')) {
    /**
     * Service suggestions covering the families of the given trades, plus the
     * general "build" set so a listing with no known trade still gets a list.
     *
     * @param string[] $trades
     * @return string[]
     */
    function apd_site_service_options(array $trades): array
    {
        $suggestions = apd_site_writer_service_suggestions();
        $families    = function_exists('apd_site_families_for_trades') ? apd_site_families_for_trades($trades) : array();
        if (!$families) {
            $families = array('build');
        }
        $out = array();
        foreach ($families as $family) {
            foreach (($suggestions[$family] ?? array()) as $title) {
                $out[$title] = $title;
            }
        }
        return array_values($out);
    }
}

if (!function_exists('apd_site_writer_trade_label')) {
    /**
     * Human label for a trade slug, e.g. "air-conditioning" => "Air Conditioning".
     */
    function apd_site_writer_trade_label(string $trade): string
    {
        $trade = trim($trade);
        if ('' === $trade) {
            return 'tradie';
        }
        return ucwords(str_replace(array('-', '_'), ' ', $trade));
    }
}

if (!function_exists('apd_site_writer_pick')) {
    /**
     * Deterministic pick from a list, seeded so the same business always gets
     * the same wording but different businesses get different wording.
     *
     * @param string[] $list
     */
    function apd_site_writer_pick(array $list, string $seed, int $offset = 0): string
    {
        $list = array_values($list);
        if (!$list) {
            return '';
        }
        $index = (int) (abs(crc32($seed . '#' . $offset)) % count($list));
        return (string) $list[$index];
    }
}

if (!function_exists('apd_site_writer_banks')) {
    /**
     * Phrase banks per broad trade family. {name}, {area}, {state} and
     * {trade} are filled in at write time.
     *
     * @return array
     */
    function apd_site_writer_banks(): array
    {
        return array(
            'build' => array(
                'headlines' => array(
                    'Reliable {trade} work done right the first time',
                    'Your local {trade} for jobs big and small',
                    'Honest {trade} help, on time every time',
                    'Quality {trade} work in {area}',
                ),
                'subs' => array(
                    'Friendly, licensed {trade} covering {area}. Free quotes and clear pricing.',
                    'From quick fixes to full installs, we keep it simple and tidy.',
                    'Straight answers, fair pricing and workmanship you can count on.',
                    'Local {trade} ready to help with your next project.',
                ),
                'abouts' => array(
                    '{name} is a {trade} working across {area}. We turn up when we say we will, explain your options in plain English and leave the site clean. Call for a free quote.',
                    '{name} helps homes and businesses around {area} with {trade} work of every size. We keep the process simple: a clear quote, a tidy job and no surprises.',
                    'Based in {area}, {name} takes on {trade} jobs for locals who want the job done properly. Get in touch and we will talk through what you need.',
                ),
                'keywords' => array(
                    'electric' => 'Safe, compliant electrical work for homes and businesses, from new power points to full fit-outs.',
                    'plumb'    => 'Plumbing repairs and installations handled with care, from leaking taps to full bathroom reworks.',
                    'gas'      => 'Gas fitting carried out safely and to standard, from new cooktops to leak checks.',
                    'solar'    => 'Solar advice and installation that cuts your power bills without cutting corners.',
                    'air'      => 'Air conditioning installs, servicing and repairs to keep your place comfortable year round.',
                    'emergency' => 'Fast, tidy emergency call-outs when you need a hand straight away.',
                    'maintenance' => 'Ongoing maintenance that keeps your property safe, working and looking its best.',
                    'install'  => 'Neat, professional installations done properly the first time.',
                    'repair'   => 'Dependable repairs, with honest advice on whether to fix or replace.',
                ),
                'generic' => array(
                    'Professional %s with clear pricing and a tidy finish.',
                    'Reliable %s for local homes and businesses.',
                    'Straightforward %s, done properly and on time.',
                    'Friendly %s with no jargon and no surprises.',
                ),
            ),
            'outdoor' => array(
                'headlines' => array(
                    'Bring your outdoor space back to life',
                    'Your local {trade} in {area}',
                    'Outdoor work that makes a real difference',
                    'Tidy, reliable {trade} services',
                ),
                'subs' => array(
                    'Friendly {trade} services across {area}, with free quotes.',
                    'From quick tidy-ups to regular care, we keep your place looking its best.',
                    'Local, dependable and happy to help with the jobs you would rather not do.',
                    'Reliable outdoor services, done the way you want them.',
                ),
                'abouts' => array(
                    '{name} looks after outdoor spaces across {area}. We show up on time, work carefully and leave everything neat. Call for a free quote.',
                    '{name} is a local {trade} helping homes around {area} stay looking their best, without the fuss or the hard sell.',
                    'Covering {area}, {name} takes on {trade} work with a focus on doing the job properly and leaving things tidy.',
                ),
                'keywords' => array(
                    'lawn'    => 'Regular mowing and edging that keeps your lawn crisp all year.',
                    'garden'  => 'Gardening and planting care to keep your garden healthy and tidy.',
                    'tree'    => 'Careful tree work, including pruning, removals and clean-ups.',
                    'pool'    => 'Pool cleaning and servicing so the water is always ready for a swim.',
                    'landscap' => 'Landscaping that makes the most of your space, from paving to planting.',
                    'hedge'   => 'Neat hedging and trimming for a tidy, well-kept look.',
                ),
                'generic' => array(
                    'Careful, reliable %s for local homes.',
                    'Friendly %s with a tidy finish every time.',
                    'Dependable %s across the local area.',
                    'Straightforward %s, done the way you want.',
                ),
            ),
            'home' => array(
                'headlines' => array(
                    'A spotless result, without the fuss',
                    'Your local {trade} for a cleaner, calmer home',
                    'Reliable home services in {area}',
                    'Trusted {trade} help around the house',
                ),
                'subs' => array(
                    'Friendly home services across {area}, with clear pricing.',
                    'We take the hard work off your hands so you can get on with your day.',
                    'Careful, thorough and happy to follow your checklist.',
                    'Local, dependable help for busy homes and businesses.',
                ),
                'abouts' => array(
                    '{name} helps homes and businesses across {area} with {trade} services. We are thorough, friendly and easy to deal with. Ask us for a free quote.',
                    '{name} is a local {trade} looking after properties around {area}. We turn up on time, work to your list and leave the place the way you like it.',
                    'Covering {area}, {name} provides dependable {trade} services with honest pricing and no fuss.',
                ),
                'keywords' => array(
                    'residential' => 'Regular home cleaning that keeps every room fresh, from kitchens to bedrooms.',
                    'commercial'  => 'Reliable cleaning for offices, shops and workspaces that need to look their best.',
                    'deep'        => 'Thorough deep cleaning that tackles the spots a weekly tidy misses.',
                    'carpet'      => 'Carpet cleaning that lifts dirt, stains and odours for a fresher feel underfoot.',
                    'window'      => 'Streak-free window cleaning inside and out for clearer views.',
                    'bond'        => 'End-of-lease cleaning done to a standard that helps you get your bond back.',
                    'end of lease' => 'End-of-lease cleaning done to a standard that helps you get your bond back.',
                    'pest'        => 'Careful pest treatment that keeps your home comfortable and protected.',
                    'lock'        => 'Locksmith work to keep your home or business secure, day or night.',
                    'rubbish'     => 'Rubbish removal that clears the clutter and hauls it away.',
                    'removal'     => 'Careful removals to get your things where they need to go.',
                ),
                'generic' => array(
                    'Careful, thorough %s for local homes and businesses.',
                    'Friendly %s with clear pricing and a tidy finish.',
                    'Dependable %s across the area.',
                    'Straightforward %s, done properly and on time.',
                ),
            ),
            'auto' => array(
                'headlines' => array(
                    'Keep your car running its best',
                    'Your local {trade} in {area}',
                    'Honest car care, done properly',
                    'Reliable {trade} services for local drivers',
                ),
                'subs' => array(
                    'Friendly {trade} services across {area}, with clear pricing.',
                    'We explain what your car needs in plain English, then get it sorted.',
                    'Local, dependable and easy to deal with.',
                    'Straight answers and careful work for every vehicle.',
                ),
                'abouts' => array(
                    '{name} looks after vehicles for drivers across {area}. We keep it simple, explain what is needed and get you back on the road. Call for a quote.',
                    '{name} is a local {trade} helping drivers around {area} keep their cars safe and reliable.',
                    'Covering {area}, {name} takes on {trade} work with care and honest advice.',
                ),
                'keywords' => array(
                    'mechanic'  => 'Servicing and repairs to keep your car safe and dependable.',
                    'electric'  => 'Auto electrical work, from batteries to tricky faults.',
                    'detailing' => 'Careful detailing that brings your car back to its best.',
                    'service'   => 'Regular servicing that keeps your vehicle running smoothly.',
                    'brake'     => 'Brake checks and repairs for confident, safe stopping.',
                ),
                'generic' => array(
                    'Reliable %s for local drivers.',
                    'Careful %s with honest advice.',
                    'Dependable %s done properly.',
                    'Friendly %s across the area.',
                ),
            ),
            'repair' => array(
                'headlines' => array(
                    'Get it fixed, without the hassle',
                    'Your local {trade} in {area}',
                    'Friendly repairs you can rely on',
                    'Reliable {trade} help nearby',
                ),
                'subs' => array(
                    'Friendly repair services across {area}, with clear pricing.',
                    'We diagnose the problem, explain your options and get it working again.',
                    'Local, dependable and easy to deal with.',
                    'Straight answers and careful work.',
                ),
                'abouts' => array(
                    '{name} repairs and services appliances and equipment for locals across {area}. We explain the options and get it sorted. Call for a quote.',
                    '{name} is a local {trade} helping homes and businesses around {area} stay up and running.',
                    'Covering {area}, {name} takes on {trade} work with care and honest advice.',
                ),
                'keywords' => array(
                    'appliance' => 'Appliance repairs to get your home running smoothly again.',
                    'computer'  => 'Computer and device repairs, from slow machines to cracked screens.',
                    'antenna'   => 'Antenna and TV work for clear, reliable reception.',
                    'tv'        => 'TV setup and repairs for a picture you can count on.',
                    'phone'     => 'Phone and tablet repairs, done quickly and carefully.',
                ),
                'generic' => array(
                    'Reliable %s for local homes.',
                    'Careful %s with honest advice.',
                    'Dependable %s done properly.',
                    'Friendly %s across the area.',
                ),
            ),
            'pets' => array(
                'headlines' => array(
                    'Happy pets, happy owners',
                    'Your local {trade} in {area}',
                    'Friendly care for your furry friends',
                    'Trusted {trade} services nearby',
                ),
                'subs' => array(
                    'Friendly pet services across {area}, with clear pricing.',
                    'We treat every pet like our own, with patience and care.',
                    'Local, dependable and always happy to help.',
                    'Gentle, reliable care you and your pet can count on.',
                ),
                'abouts' => array(
                    '{name} cares for pets across {area}. We are gentle, patient and reliable, and we always treat your pet like our own. Get in touch to book.',
                    '{name} is a local {trade} looking after pets around {area}. You can count on us to turn up on time and take good care of them.',
                    'Covering {area}, {name} provides dependable {trade} services with care and patience.',
                ),
                'keywords' => array(
                    'groom' => 'Gentle grooming that keeps your pet comfortable and looking great.',
                    'dog'   => 'Reliable dog walking and care with plenty of attention.',
                    'walk'  => 'Regular walks to keep your dog happy, healthy and social.',
                    'wash'  => 'Careful washing and grooming for a fresh, happy pet.',
                ),
                'generic' => array(
                    'Gentle, reliable %s for local pets.',
                    'Friendly %s with care and patience.',
                    'Dependable %s you can trust.',
                    'Trusted %s across the area.',
                ),
            ),
            'pro' => array(
                'headlines' => array(
                    'Practical help from a local {trade}',
                    'Your local {trade} in {area}',
                    'Friendly, professional service',
                    'Reliable {trade} support nearby',
                ),
                'subs' => array(
                    'Friendly {trade} services across {area}, with clear pricing.',
                    'We make it easy, with plain-English advice and dependable service.',
                    'Local, professional and easy to deal with.',
                    'Straight answers and a job done well.',
                ),
                'abouts' => array(
                    '{name} works with clients across {area}, offering {trade} services with a friendly, no-fuss approach. Get in touch to find out more.',
                    '{name} is a local {trade} helping people around {area} with practical, dependable support.',
                    'Covering {area}, {name} provides {trade} services with clear communication and honest advice.',
                ),
                'keywords' => array(
                    'tutor'      => 'Patient, friendly tutoring tailored to how you or your child learn best.',
                    'photo'      => 'Natural, relaxed photography that captures the moment beautifully.',
                    'bookkeep'   => 'Tidy bookkeeping that keeps your records in order and your mind at ease.',
                    'web'        => 'Clear, easy-to-use websites built to suit your business.',
                    'design'     => 'Thoughtful design work that gets your message across.',
                    'account'    => 'Careful accounting support with plain-English advice.',
                ),
                'generic' => array(
                    'Professional %s with a friendly approach.',
                    'Reliable %s for local clients.',
                    'Dependable %s, done properly.',
                    'Straightforward %s with clear pricing.',
                ),
            ),
        );
    }
}

if (!function_exists('apd_site_writer_family_bank')) {
    /**
     * Bank for a trade family, falling back to the general "pro" bank.
     */
    function apd_site_writer_family_bank(array $banks, string $family): array
    {
        if (isset($banks[$family]) && is_array($banks[$family])) {
            return $banks[$family];
        }
        return $banks['pro'];
    }
}

if (!function_exists('apd_site_writer_service_line')) {
    /**
     * One short, honest description for a service title.
     */
    function apd_site_writer_service_line(string $family, string $title, string $seed, int $index): string
    {
        $banks = apd_site_writer_banks();
        $bank  = apd_site_writer_family_bank($banks, $family);
        $short = strtolower(trim($title));

        if (isset($bank['keywords']) && is_array($bank['keywords'])) {
            foreach ($bank['keywords'] as $keyword => $line) {
                if ('' !== $keyword && false !== strpos($short, (string) $keyword)) {
                    return (string) $line;
                }
            }
        }

        $pattern = apd_site_writer_pick($bank['generic'], $seed, 40 + $index);
        return sprintf($pattern, $short);
    }
}

if (!function_exists('apd_site_write_copy')) {
    /**
     * The no-AI writer: friendly Australian wording from the phrase banks.
     *
     * @param array $ctx business_name, trade, suburb, state, service_area, services[].
     * @return array hero_heading, hero_sub, about, cta_text, services[].
     */
    function apd_site_write_copy(array $ctx): array
    {
        $name    = trim((string) ($ctx['business_name'] ?? ''));
        $trade   = trim((string) ($ctx['trade'] ?? ''));
        $suburb  = trim((string) ($ctx['suburb'] ?? ''));
        $state   = trim((string) ($ctx['state'] ?? ''));
        $area    = trim((string) ($ctx['service_area'] ?? ''));
        $area    = '' !== $area ? $area : ($suburb !== '' ? $suburb : 'your area');
        $trade   = '' !== $trade ? $trade : 'tradie';
        $label   = apd_site_writer_trade_label($trade);

        $family = function_exists('apd_site_trade_family') ? apd_site_trade_family($trade) : null;
        $family = $family ?: 'pro';

        $banks = apd_site_writer_banks();
        $bank  = apd_site_writer_family_bank($banks, $family);

        $seed = strtolower($name . '|' . $trade . '|' . $area);
        if ('' === trim($seed, '|')) {
            $seed = 'aussie-tradie';
        }

        $replace = static function (string $text) use ($name, $area, $state, $label) {
            return str_replace(
                array('{name}', '{area}', '{state}', '{trade}'),
                array($name, $area, $state, $label),
                $text
            );
        };

        $heading = $replace(apd_site_writer_pick($bank['headlines'], $seed, 1));
        $sub     = $replace(apd_site_writer_pick($bank['subs'], $seed, 2));
        $about   = $replace(apd_site_writer_pick($bank['abouts'], $seed, 3));
        $cta     = apd_site_writer_pick(
            array('Get a free quote', 'Request a quote', 'Get in touch', 'Call for a quick quote'),
            $seed,
            4
        );

        $services = array();
        $input    = isset($ctx['services']) && is_array($ctx['services']) ? $ctx['services'] : array();
        $index    = 0;
        foreach ($input as $item) {
            $title = is_array($item) ? (string) ($item['title'] ?? '') : (string) $item;
            $title = trim($title);
            if ('' === $title) {
                continue;
            }
            $services[] = array(
                'title' => $title,
                'text'  => apd_site_writer_service_line($family, $title, $seed, $index),
                'image' => is_array($item) ? (string) ($item['image'] ?? '') : '',
            );
            $index++;
        }

        return array(
            'hero_heading' => trim($heading),
            'hero_sub'     => trim($sub),
            'about'        => trim($about),
            'cta_text'     => $cta,
            'services'     => $services,
        );
    }
}

/* ------------------------------------------------------------------------- *
 * Optional AI writer (OpenRouter). Off unless APD_SITES_AI is true.
 * ------------------------------------------------------------------------- */

if (!function_exists('apd_sites_ai_enabled')) {
    /**
     * True only when the switch is on and the key file is readable.
     */
    function apd_sites_ai_enabled(): bool
    {
        if (!defined('APD_SITES_AI') || !APD_SITES_AI) {
            return false;
        }
        if (!defined('APD_SITES_AI_KEY_FILE') || '' === (string) APD_SITES_AI_KEY_FILE) {
            return false;
        }
        return @is_readable((string) APD_SITES_AI_KEY_FILE);
    }
}

if (!function_exists('apd_sites_ai_model')) {
    function apd_sites_ai_model(): string
    {
        if (defined('APD_SITES_AI_MODEL') && '' !== (string) APD_SITES_AI_MODEL) {
            return (string) APD_SITES_AI_MODEL;
        }
        return 'openai/gpt-4o-mini';
    }
}

if (!function_exists('apd_sites_ai_daily_cap')) {
    function apd_sites_ai_daily_cap(): int
    {
        $cap = function_exists('get_option') ? (int) get_option('apd_sites_ai_daily_cap', 50) : 50;
        return $cap > 0 ? $cap : 50;
    }
}

if (!function_exists('apd_sites_ai_calls_today')) {
    function apd_sites_ai_calls_today(): int
    {
        if (!function_exists('get_option')) {
            return 0;
        }
        return (int) get_option('apd_sites_ai_calls_' . gmdate('Y-m-d'), 0);
    }
}

if (!function_exists('apd_sites_ai_log_call')) {
    function apd_sites_ai_log_call(): void
    {
        if (!function_exists('update_option')) {
            return;
        }
        $key = 'apd_sites_ai_calls_' . gmdate('Y-m-d');
        update_option($key, (int) get_option($key, 0) + 1, false);
    }
}

if (!function_exists('apd_sites_ai_key')) {
    /**
     * Read the API key from the configured file. Never logged, never echoed.
     */
    function apd_sites_ai_key(): string
    {
        if (!apd_sites_ai_enabled()) {
            return '';
        }
        $raw = @file_get_contents((string) APD_SITES_AI_KEY_FILE);
        return is_string($raw) ? trim($raw) : '';
    }
}

if (!function_exists('apd_sites_ai_prompt')) {
    /**
     * The user prompt. Only business name, trade, suburb and services are sent;
     * never the owner's email or phone.
     */
    function apd_sites_ai_prompt(array $ctx): string
    {
        $services = array();
        foreach (($ctx['services'] ?? array()) as $item) {
            $title = is_array($item) ? (string) ($item['title'] ?? '') : (string) $item;
            $title = trim($title);
            if ('' !== $title) {
                $services[] = $title;
            }
        }
        $lines   = array();
        $lines[] = 'Business name: ' . trim((string) ($ctx['business_name'] ?? ''));
        $lines[] = 'Trade: ' . trim((string) ($ctx['trade'] ?? ''));
        $lines[] = 'Suburb: ' . trim((string) ($ctx['suburb'] ?? ''));
        $lines[] = 'Service area: ' . trim((string) ($ctx['service_area'] ?? ''));
        $lines[] = 'Services: ' . ($services ? implode(', ', $services) : '(none listed)');
        return implode("\n", $lines);
    }
}

if (!function_exists('apd_sites_ai_write')) {
    /**
     * Ask OpenRouter for the copy. Returns null on any error so the caller
     * falls back to the no-AI writer.
     *
     * @return array|null
     */
    function apd_sites_ai_write(array $ctx)
    {
        if (!apd_sites_ai_enabled() || !function_exists('wp_remote_post')) {
            return null;
        }
        if (apd_sites_ai_calls_today() >= apd_sites_ai_daily_cap()) {
            return null;
        }
        $key = apd_sites_ai_key();
        if ('' === $key) {
            return null;
        }

        $system = 'You write short, honest website copy for Australian tradies. '
            . 'Use plain Australian English. Never invent statistics, awards, years in business, '
            . 'reviews, licence numbers or prices. No exclamation marks. Keep it family-safe. '
            . 'Reply with strict JSON only, shaped like: '
            . '{"hero_heading":"","hero_sub":"","about":"","services":[{"title":"","text":""}]}. '
            . 'Match the services exactly to the list you are given.';

        $body = array(
            'model'      => apd_sites_ai_model(),
            'max_tokens' => 600,
            'messages'   => array(
                array('role' => 'system', 'content' => $system),
                array('role' => 'user', 'content' => apd_sites_ai_prompt($ctx)),
            ),
        );

        $response = wp_remote_post('https://openrouter.ai/api/v1/chat/completions', array(
            'timeout' => 20,
            'headers' => array(
                'Authorization' => 'Bearer ' . $key,
                'Content-Type'  => 'application/json',
            ),
            'body'    => wp_json_encode($body),
        ));

        apd_sites_ai_log_call();

        if (is_wp_error($response)) {
            return null;
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        $raw  = (string) wp_remote_retrieve_body($response);
        if ($code < 200 || $code >= 300 || '' === $raw) {
            return null;
        }
        $json    = json_decode($raw, true);
        $content = $json['choices'][0]['message']['content'] ?? '';
        if (!is_string($content) || '' === trim($content)) {
            return null;
        }
        // The model may wrap the JSON in a code fence.
        $content = preg_replace('/^```(?:json)?|```$/m', '', trim($content));
        $copy    = json_decode(trim((string) $content), true);
        if (!is_array($copy) || empty($copy['hero_heading'])) {
            return null;
        }

        $services = array();
        if (isset($copy['services']) && is_array($copy['services'])) {
            foreach ($copy['services'] as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $services[] = array(
                    'title' => (string) ($item['title'] ?? ''),
                    'text'  => (string) ($item['text'] ?? ''),
                    'image' => '',
                );
            }
        }

        return array(
            'hero_heading' => (string) $copy['hero_heading'],
            'hero_sub'     => (string) ($copy['hero_sub'] ?? ''),
            'about'        => (string) ($copy['about'] ?? ''),
            'cta_text'     => (string) ($copy['cta_text'] ?? 'Get a free quote'),
            'services'     => $services,
        );
    }
}

if (!function_exists('apd_sites_write_copy')) {
    /**
     * "Write it for me": try the optional AI writer, always fall back to the
     * no-AI writer. Owner chosen service titles are kept either way.
     */
    function apd_sites_write_copy(array $ctx): array
    {
        $copy = apd_sites_ai_write($ctx);
        if (!is_array($copy)) {
            return apd_site_write_copy($ctx);
        }

        // Keep the owner's own service titles, using the AI text where it lines up.
        $owner   = isset($ctx['services']) && is_array($ctx['services']) ? array_values($ctx['services']) : array();
        $ai      = isset($copy['services']) && is_array($copy['services']) ? array_values($copy['services']) : array();
        $merged  = array();
        foreach ($owner as $i => $item) {
            $title = is_array($item) ? (string) ($item['title'] ?? '') : (string) $item;
            $title = trim($title);
            if ('' === $title) {
                continue;
            }
            $text = '';
            foreach ($ai as $candidate) {
                if (strtolower(trim((string) ($candidate['title'] ?? ''))) === strtolower($title)) {
                    $text = (string) ($candidate['text'] ?? '');
                    break;
                }
            }
            if ('' === $text && isset($ai[$i])) {
                $text = (string) ($ai[$i]['text'] ?? '');
            }
            $merged[] = array('title' => $title, 'text' => $text, 'image' => is_array($item) ? (string) ($item['image'] ?? '') : '');
        }
        $copy['services'] = $merged;

        return $copy;
    }
}
