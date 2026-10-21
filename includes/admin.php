<?php
/**
 * The Watchdog tab: registration, assets, settings, results and AJAX.
 */

if (!defined('ABSPATH')) exit;

/* ---------- registration ---------- */

/** Screen ID as WordPress built it — never hardcode it, it depends on the parent menu. */
function trwatch_hook_suffix($set = null) {
    static $hook = '';
    if ($set !== null) $hook = $set;
    return $hook;
}

function trwatch_screen_url() {
    return admin_url('admin.php?page=' . TRWATCH_SLUG);
}

add_action('admin_menu', function () {
    if (!trwatch_trp()) return;
    $hook = add_submenu_page('TRPHidden', __('Translation Watchdog', 'translation-watchdog-for-translatepress'), 'TRPHidden',
        'manage_options', TRWATCH_SLUG, 'trwatch_render');
    if (!$hook) return;
    trwatch_hook_suffix($hook);
    add_action('load-' . $hook, 'trwatch_handle_settings');
});

add_filter('trp_settings_tabs', function ($tabs) {
    $tabs[] = ['name' => __('Watchdog', 'translation-watchdog-for-translatepress'), 'url' => trwatch_screen_url(), 'page' => TRWATCH_SLUG];
    return $tabs;
}, 99);   // after TranslatePress' own tabs

add_filter('plugin_action_links_' . plugin_basename(TRWATCH_FILE), function ($links) {
    if (trwatch_trp()) {
        array_unshift($links, '<a href="' . esc_url(trwatch_screen_url()) . '">' . esc_html__('Open Watchdog', 'translation-watchdog-for-translatepress') . '</a>');
    }
    return $links;
});

// @wporg-remove-start — the earlier single-file version would add a second tab
add_action('admin_notices', function () {
    if (!function_exists('cstw_render') || !current_user_can('manage_options')) return;
    echo '<div class="notice notice-warning"><p>'
       . esc_html__('Translation Watchdog: the old must-use version (wp-content/mu-plugins/cs-translation-watchdog.php) is still installed. Delete that file — this plugin replaces it.', 'translation-watchdog-for-translatepress')
       . '</p></div>';
});
// @wporg-remove-end

add_action('admin_enqueue_scripts', function ($hook) {
    if ($hook === '' || $hook !== trwatch_hook_suffix()) return;

    // TranslatePress only loads its settings CSS on its own pages
    if (defined('TRP_PLUGIN_URL') && defined('TRP_PLUGIN_VERSION')) {
        wp_enqueue_style('trp-settings-style', TRP_PLUGIN_URL . 'assets/css/trp-back-end-style.css', [], TRP_PLUGIN_VERSION);
    }
    // CSS and JS are embedded in the page instead of loaded as files: some sites strip the ?ver= query string
    // (speed plugins) and send assets with a long browser cache, so an updated file would never reach the browser.
    $trwatch_dir = plugin_dir_path(TRWATCH_FILE);
    wp_register_style('trwatch-admin', false, [], TRWATCH_VERSION);
    wp_enqueue_style('trwatch-admin');
    wp_add_inline_style('trwatch-admin', (string) file_get_contents($trwatch_dir . 'assets/admin.css'));
    wp_register_script('trwatch-admin', false, [], TRWATCH_VERSION, true);
    wp_enqueue_script('trwatch-admin');
    wp_add_inline_script('trwatch-admin', (string) file_get_contents($trwatch_dir . 'assets/admin.js'));
    wp_add_inline_script('trwatch-admin', 'window.trwatchData = ' . wp_json_encode([
        'ajax'  => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('trwatch'),
        'batch' => trwatch_sources_per_batch(1),   // retry: target pages per request (each also fetches its original)
        'i18n'  => [
            /* translators: 1: pages done, 2: pages total */
            'collecting'  => __('Collecting pages…', 'translation-watchdog-for-translatepress'),
            /* translators: 1: pages done, 2: pages total */
            'checking'    => __('Checking %1$d / %2$d pages…', 'translation-watchdog-for-translatepress'),
            /* translators: 1: pages done, 2: pages total */
            'retrying'    => __('Retrying %1$d / %2$d pages…', 'translation-watchdog-for-translatepress'),
            'scanning'    => __('Scanning…', 'translation-watchdog-for-translatepress'),
            'soFar'       => __('possible issues so far', 'translation-watchdog-for-translatepress'),
            'scanFailed'  => __('Scan failed:', 'translation-watchdog-for-translatepress'),
            'retryFailed' => __('Retry failed:', 'translation-watchdog-for-translatepress'),
            'skipFailed'  => __('Could not skip:', 'translation-watchdog-for-translatepress'),
            'undoFailed'  => __('Could not undo:', 'translation-watchdog-for-translatepress'),
            'ignoreFailed' => __('Could not ignore the page:', 'translation-watchdog-for-translatepress'),
            'undoPageFailed' => __('Could not take the page back in:', 'translation-watchdog-for-translatepress'),
            'recheckFailed' => __('Could not check the page again:', 'translation-watchdog-for-translatepress'),
            'expired'     => __('Session expired — reload the page.', 'translation-watchdog-for-translatepress'),
            /* translators: %d: HTTP status code */
            'unexpected'  => __('Unexpected server response (HTTP %d).', 'translation-watchdog-for-translatepress'),
            'failed'      => __('Request failed.', 'translation-watchdog-for-translatepress'),
        ],
    ]) . ';', 'before');   // settings first, then the script that reads them
});

/** Settings form: saved before any output, then redirect (post/redirect/get). */
function trwatch_handle_settings() {
    if (!isset($_POST['trwatch_save'])) return;
    check_admin_referer('trwatch_settings');
    if (!current_user_can('manage_options')) wp_die(esc_html__('Not allowed.', 'translation-watchdog-for-translatepress'), 403);

    $allow = isset($_POST['trwatch_allow']) ? sanitize_textarea_field(wp_unslash($_POST['trwatch_allow'])) : '';
    update_option(TRWATCH_OPT_ALLOW, $allow, false);

    // validated against TranslatePress' languages
    $posted = isset($_POST['trwatch_langs']) && is_array($_POST['trwatch_langs']) ? array_map('sanitize_text_field', wp_unslash($_POST['trwatch_langs'])) : [];
    update_option(TRWATCH_OPT_LANGS, array_values(array_intersect($posted, array_keys(trwatch_target_languages()))), false);

    $selectors = isset($_POST['trwatch_ignore']) ? explode("
", sanitize_textarea_field(wp_unslash($_POST['trwatch_ignore']))) : [];
    $valid = $invalid = [];
    foreach (array_filter(array_map('trim', $selectors)) as $sel) {
        if (trwatch_selector_to_xpath($sel)) $valid[] = $sel; else $invalid[] = $sel;
    }
    update_option(TRWATCH_OPT_IGNORE, implode("
", $valid), false);

    $args = ['trwatch-saved' => '1'];
    if ($invalid) $args['trwatch-invalid'] = rawurlencode(implode(' ', $invalid));
    wp_safe_redirect(add_query_arg($args, trwatch_screen_url()));
    exit;
}

/* ---------- rendering ---------- */

/** Tags the result fragments use — every fragment goes through wp_kses() with this at the point of output. */
function trwatch_allowed_html() {
    return [
        'div'    => ['class' => true, 'data-url' => true],
        'h2'     => [],
        'h3'     => [],
        'p'      => ['class' => true],
        'strong' => ['class' => true],
        'span'   => ['class' => true, 'title' => true],
        'code'   => [],
        'ul'     => ['class' => true],
        'li'     => ['data-hash' => true, 'data-url' => true],
        'a'      => ['href' => true, 'target' => true, 'rel' => true, 'class' => true],
        'button' => ['type' => true, 'class' => true, 'id' => true, 'title' => true],
    ];
}

function trwatch_kses($html) {
    return wp_kses($html, trwatch_allowed_html());
}

function trwatch_where_label($where) {
    $labels = [
        'text'             => __('text', 'translation-watchdog-for-translatepress'),
        'alt'              => __('alt', 'translation-watchdog-for-translatepress'),
        'title'            => __('title', 'translation-watchdog-for-translatepress'),
        'placeholder'      => __('placeholder', 'translation-watchdog-for-translatepress'),
        'aria-label'       => __('aria-label', 'translation-watchdog-for-translatepress'),
        'title tag'        => __('title tag', 'translation-watchdog-for-translatepress'),
        'meta description' => __('meta description', 'translation-watchdog-for-translatepress'),
        'every page'       => __('every page', 'translation-watchdog-for-translatepress'),
    ];
    return $labels[$where] ?? $where;
}

function trwatch_status_badge($status) {
    $badges = [
        'missing'   => [__('Not translated', 'translation-watchdog-for-translatepress'),
                        __('TranslatePress knows this text but has no translation for it.', 'translation-watchdog-for-translatepress')],
        'not_shown' => [__('Translated, not shown', 'translation-watchdog-for-translatepress'),
                        __('A translation exists, but the page still shows the original — usually a spelling difference such as quotes, dashes or HTML entities.', 'translation-watchdog-for-translatepress')],
        'unknown'   => [__('Not in TranslatePress', 'translation-watchdog-for-translatepress'),
                        __('Not in TranslatePress\' string list — it may come from JavaScript, an image or a plugin TranslatePress does not see.', 'translation-watchdog-for-translatepress')],
    ];
    if (!isset($badges[$status])) return '';
    return '<span class="trwatch-badge trwatch-badge-' . esc_attr($status) . '" title="' . esc_attr($badges[$status][1]) . '">' . esc_html($badges[$status][0]) . '</span>';
}

/** Button that checks one page again, without a full scan. */
function trwatch_recheck_button() {
    return '<button type="button" class="button-link trwatch-recheck" title="' . esc_attr__('Check this page again', 'translation-watchdog-for-translatepress') . '">'
         . '<span class="dashicons dashicons-update"></span></button>';
}

/** Button that leaves a page out of future scans (reversible under "Ignored pages"). */
function trwatch_ignore_button() {
    return '<button type="button" class="button-link trwatch-ignore-page" title="' . esc_attr__('Leave this page out of future scans — undo under Ignored pages', 'translation-watchdog-for-translatepress') . '">'
         . esc_html__('Ignore page', 'translation-watchdog-for-translatepress') . '</button>';
}

/** One page with its findings. A label (the sitewide group) means it is not a single page, so no re-check button. */
function trwatch_card($url, $strings, $label = null) {
    $edit = add_query_arg('trp-edit-translation', 'true', $url);
    $h = '<div class="trwatch-page"' . ($label === null ? ' data-url="' . esc_attr($url) . '"' : '') . '><div class="trwatch-head"><a href="' . esc_url($url) . '" target="_blank" rel="noopener">'
       . esc_html($label ?? wp_make_link_relative($url)) . '</a>'
       . ($label === null ? trwatch_recheck_button() : '')
       . ($label === null ? trwatch_ignore_button() : '')   // right side, next to "Open in translator"
       . '<a class="button button-small" href="' . esc_url($edit) . '" target="_blank" rel="noopener">' . esc_html__('Open in translator', 'translation-watchdog-for-translatepress') . '</a>'
       . '<span class="trwatch-n">' . (int) count($strings) . '</span></div><ul>';
    foreach ($strings as $s) {
        $h .= '<li data-hash="' . esc_attr(md5($s['text'])) . '">'
            . trwatch_status_badge($s['status'] ?? 'unknown')
            . '<span class="trwatch-where">' . esc_html(trwatch_where_label($s['where'])) . '</span>'
            . '<span class="trwatch-text">' . esc_html($s['text'])
            . (!empty($s['el']) ? '<span class="trwatch-el" title="' . esc_attr__('The element this text is in — add it under Settings → Ignore elements to stop checking it', 'translation-watchdog-for-translatepress') . '">' . esc_html__('in', 'translation-watchdog-for-translatepress') . ' <code>' . esc_html($s['el']) . '</code></span>' : '')
            . '</span>'
            . '<button type="button" class="button-link trwatch-skip" title="' . esc_attr__('Not an issue — hide this string from now on', 'translation-watchdog-for-translatepress') . '">'
            . esc_html__('Skip', 'translation-watchdog-for-translatepress') . '</button></li>';
    }
    return $h . '</ul></div>';
}

function trwatch_error_card($url, $error) {
    return '<div class="trwatch-page trwatch-err" data-url="' . esc_attr($url) . '"><div class="trwatch-head"><a href="' . esc_url($url) . '" target="_blank" rel="noopener">'
         . esc_html(wp_make_link_relative($url)) . '</a>' . trwatch_recheck_button() . '<span class="trwatch-errmsg">' . esc_html($error) . '</span>' . trwatch_ignore_button() . '</div></div>';
}

/** A re-checked page with nothing left to fix. */
function trwatch_ok_card($url) {
    return '<div class="trwatch-page trwatch-ok" data-url="' . esc_attr($url) . '"><div class="trwatch-head"><a href="' . esc_url($url) . '" target="_blank" rel="noopener">'
         . esc_html(wp_make_link_relative($url)) . '</a>' . trwatch_recheck_button()
         . '<span class="trwatch-okmsg">' . esc_html__('✓ Nothing left to fix', 'translation-watchdog-for-translatepress') . '</span></div></div>';
}

/** Batch cards shown while a scan runs. */
function trwatch_batch_cards(array $pages) {
    $cards = '';
    $found = 0;
    foreach ($pages as $url => $p) {
        if ($p['error']) $cards .= trwatch_error_card($url, $p['error']);
        $strings = trwatch_filter_skipped($p['strings']);
        if (!$strings) continue;
        $found += count($strings);
        $cards .= trwatch_card($url, $strings);
    }
    return [$cards, $found];
}

function trwatch_saved_scan() {
    $scan = get_option(TRWATCH_OPT_RESULT);
    return is_array($scan) && !empty($scan['pages']) ? $scan : null;
}

/**
 * Strings on more than half of the (fetched) pages are header/footer — shown once.
 *
 * @return array [list of sitewide texts, text => first finding]
 */
function trwatch_sitewide(array $ok) {
    $total = count($ok);
    $count = [];
    $first = [];
    foreach ($ok as $p) foreach ($p['strings'] as $s) {
        $count[$s['text']] = ($count[$s['text']] ?? 0) + 1;
        $first[$s['text']] ??= $s;
    }
    return [array_keys(array_filter($count, fn($c) => $total > 4 && $c > $total / 2)), $first];
}

/** Results of one language: sitewide group + per page. Returns [html, issues]. */
function trwatch_language_html(array $pages) {
    foreach ($pages as &$p) $p['strings'] = trwatch_filter_skipped($p['strings']);
    unset($p);
    $ok = array_filter($pages, fn($p) => !$p['error']);
    $total = count($ok);

    [$sitewide, $first] = trwatch_sitewide($ok);

    $issues = 0;
    $out = '';
    if ($sitewide) {
        $url = array_key_first($ok);
        $issues += count($sitewide);
        $out .= '<h3>' . esc_html__('Sitewide (header, footer, popup…)', 'translation-watchdog-for-translatepress') . '</h3>'
              . trwatch_card($url, array_map(fn($t) => ['text' => $t, 'where' => 'every page', 'el' => $first[$t]['el'] ?? '', 'status' => $first[$t]['status'] ?? 'unknown'], $sitewide),
                  /* translators: %s: page path */
                  sprintf(__('Shown on most pages — fix once, e.g. on %s', 'translation-watchdog-for-translatepress'), wp_make_link_relative($url)));
    }
    $body = '';
    foreach ($ok as $url => $p) {
        $strings = array_values(array_filter($p['strings'], fn($s) => !in_array($s['text'], $sitewide, true)));
        if (!$strings) continue;
        $issues += count($strings);
        $body .= trwatch_card($url, $strings);
    }
    if ($body) $out .= '<h3>' . esc_html__('Per page', 'translation-watchdog-for-translatepress') . '</h3>' . $body;
    return [$out, $issues];
}

function trwatch_results_html() {
    $scan = trwatch_saved_scan();
    if (!$scan) return '<p>' . esc_html__('No scan yet.', 'translation-watchdog-for-translatepress') . '</p>';

    $names = trwatch_target_languages();
    $byLang = [];
    $errors = [];
    $ignored = trwatch_ignored_pages();
    foreach ($scan['pages'] as $url => $p) {
        if (!empty($p['source']) && in_array($p['source'], $ignored, true)) continue;
        $p += ['lang' => 'en_GB', 'error' => null, 'strings' => []];   // scans from older versions
        if ($p['error']) $errors[$url] = $p['error'];
        $byLang[$p['lang']][$url] = $p;
    }

    $issues = 0;
    $out = '';
    foreach ($byLang as $lang => $pages) {
        [$html, $n] = trwatch_language_html($pages);
        $issues += $n;
        if (count($byLang) > 1) $out .= '<h2>' . esc_html($names[$lang] ?? $lang) . '</h2>';
        $out .= $html;
    }

    if ($errors) {
        /* translators: %d: number of pages */
        $retry = sprintf(_n('Retry this %d', 'Retry these %d', count($errors), 'translation-watchdog-for-translatepress'), count($errors));
        $out .= '<h2>' . esc_html__('Could not fetch', 'translation-watchdog-for-translatepress')
              . ' <button type="button" id="trwatch-retry" class="button">' . esc_html($retry) . '</button></h2><ul class="trwatch-failed">';
        foreach ($errors as $u => $e) {
            $out .= '<li data-url="' . esc_attr($u) . '"><a href="' . esc_url($u) . '" target="_blank" rel="noopener">'
                  . esc_html(wp_make_link_relative($u)) . '</a> — ' . esc_html($e) . '</li>';
        }
        $out .= '</ul>';
    }
    if (!$issues && !$errors) {
        $out .= '<div class="notice notice-success inline"><p>' . esc_html__('No untranslated text found on any page.', 'translation-watchdog-for-translatepress') . '</p></div>';
    }

    $total = count($scan['pages']);
    $time = wp_date(get_option('date_format') . ' ' . get_option('time_format'), $scan['time']);
    return '<p class="trwatch-meta">' . esc_html__('Last scan:', 'translation-watchdog-for-translatepress') . ' <strong>' . esc_html($time) . '</strong> · '
         . '<strong class="trwatch-total">' . (int) $issues . '</strong> ' . esc_html__('possible issues', 'translation-watchdog-for-translatepress') . ' · '
         /* translators: %d: number of pages */
         . esc_html(sprintf(_n('%d page checked', '%d pages checked', $total, 'translation-watchdog-for-translatepress'), $total)) . '</p>' . $out;
}

function trwatch_ignored_html() {
    $pages = trwatch_ignored_pages();
    if (!$pages) return '<p class="description">' . esc_html__('No pages ignored.', 'translation-watchdog-for-translatepress') . '</p>';
    $h = '<ul class="trwatch-skiplist">';
    foreach ($pages as $u) {
        $h .= '<li data-url="' . esc_attr($u) . '"><span class="trwatch-text">' . esc_html(wp_make_link_relative($u)) . '</span>'
            . '<button type="button" class="button-link trwatch-unignore-page">' . esc_html__('Undo', 'translation-watchdog-for-translatepress') . '</button></li>';
    }
    return $h . '</ul>';
}

function trwatch_skipped_html() {
    $skipped = trwatch_skipped();
    if (!$skipped) return '<p class="description">' . esc_html__('Nothing skipped yet.', 'translation-watchdog-for-translatepress') . '</p>';
    $h = '<ul class="trwatch-skiplist">';
    foreach ($skipped as $t) {
        $h .= '<li data-hash="' . esc_attr(md5($t)) . '"><span class="trwatch-text">' . esc_html($t) . '</span>'
            . '<button type="button" class="button-link trwatch-unskip">' . esc_html__('Undo', 'translation-watchdog-for-translatepress') . '</button></li>';
    }
    return $h . '</ul>';
}

/* ---------- AJAX ---------- */

function trwatch_require_cap() {
    if (!current_user_can('manage_options')) wp_send_json_error(__('Not allowed.', 'translation-watchdog-for-translatepress'), 403);
}

/** Each scan gets its own state, so two open tabs don't mix their results. */
function trwatch_run_key($scanId) {
    return 'trwatch_run_' . $scanId;
}

function trwatch_posted_scan_id() {
    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- every caller runs check_ajax_referer() first
    return isset($_POST['scan']) ? sanitize_key(wp_unslash($_POST['scan'])) : '';
}

add_action('wp_ajax_trwatch_start', function () {
    check_ajax_referer('trwatch');
    trwatch_require_cap();
    $langs = trwatch_languages();
    if (!$langs) wp_send_json_error(__('TranslatePress has no translation language set up.', 'translation-watchdog-for-translatepress'));
    $sources = trwatch_source_urls();
    $scanId = strtolower(wp_generate_password(12, false));
    set_transient(trwatch_run_key($scanId), ['langs' => $langs, 'sources' => $sources, 'pages' => []], HOUR_IN_SECONDS);
    wp_send_json_success(['scan' => $scanId, 'total' => count($sources) * count($langs)]);
});

add_action('wp_ajax_trwatch_batch', function () {
    check_ajax_referer('trwatch');
    trwatch_require_cap();
    $scanId = trwatch_posted_scan_id();
    $run = $scanId ? get_transient(trwatch_run_key($scanId)) : false;
    if (!$run) wp_send_json_error(__('This scan has expired — click Refresh.', 'translation-watchdog-for-translatepress'));

    // offset counts source pages; the screen shows translated pages (sources × languages)
    $offset = isset($_POST['offset']) ? absint($_POST['offset']) : 0;
    $per = trwatch_sources_per_batch(count($run['langs']));
    $batch = trwatch_scan_pairs(trwatch_pairs_for(array_slice($run['sources'], $offset, $per), $run['langs']), trwatch_allowlist());
    $run['pages'] = array_merge($run['pages'], $batch);
    [$cards, $found] = trwatch_batch_cards($batch);

    $next = $offset + $per;
    if ($next >= count($run['sources'])) {
        update_option(TRWATCH_OPT_RESULT, ['langs' => $run['langs'], 'time' => time(), 'pages' => $run['pages']], false);
        delete_transient(trwatch_run_key($scanId));
        wp_send_json_success(['done' => true, 'html' => trwatch_kses(trwatch_results_html())]);
    }
    set_transient(trwatch_run_key($scanId), $run, HOUR_IN_SECONDS);
    wp_send_json_success([
        'done'  => false,
        'next'  => $next,
        'shown' => $next * count($run['langs']),
        'cards' => trwatch_kses($cards),
        'found' => $found,
    ]);
});

// rescan only pages that failed in the saved result; merges into it
add_action('wp_ajax_trwatch_retry', function () {
    check_ajax_referer('trwatch');
    trwatch_require_cap();
    $scan = trwatch_saved_scan();
    if (!$scan) wp_send_json_error(__('No scan saved.', 'translation-watchdog-for-translatepress'));

    // sanitized as URLs, then only accepted if they are in the saved failed list
    $posted = isset($_POST['urls']) && is_array($_POST['urls']) ? array_filter(map_deep(wp_unslash($_POST['urls']), 'esc_url_raw'), 'is_string') : [];
    $pairs = [];
    foreach (array_slice(array_values(array_unique($posted)), 0, trwatch_sources_per_batch(1)) as $u) {
        $p = $scan['pages'][$u] ?? null;
        if (!$p || empty($p['error']) || empty($p['source']) || empty($p['lang'])) continue;
        $pairs[] = ['source' => $p['source'], 'lang' => $p['lang'], 'target' => $u];
    }

    $fixed = 0;
    foreach (trwatch_scan_pairs($pairs, trwatch_allowlist()) as $u => $p) {
        $scan['pages'][$u] = $p;
        if (!$p['error']) $fixed++;
    }
    update_option(TRWATCH_OPT_RESULT, $scan, false);
    wp_send_json_success(['fixed' => $fixed]);
});

// check one page again (after fixing it in the translator) and return its new card
add_action('wp_ajax_trwatch_recheck', function () {
    check_ajax_referer('trwatch');
    trwatch_require_cap();
    $scan = trwatch_saved_scan();
    $url = isset($_POST['url']) ? esc_url_raw(wp_unslash($_POST['url'])) : '';
    $page = $scan['pages'][$url] ?? null;
    if (!$page || empty($page['source']) || empty($page['lang'])) {
        wp_send_json_error(__('This page is not in the last scan — click Refresh.', 'translation-watchdog-for-translatepress'));
    }

    $result = trwatch_scan_pairs([['source' => $page['source'], 'lang' => $page['lang'], 'target' => $url]], trwatch_allowlist());
    $scan['pages'][$url] = $result[$url];
    update_option(TRWATCH_OPT_RESULT, $scan, false);

    $new = $result[$url];
    if ($new['error']) wp_send_json_success(['html' => trwatch_kses(trwatch_error_card($url, $new['error'])), 'found' => 0]);

    // same view as the full results: skipped and sitewide strings are not listed per page
    $sameLang = array_filter($scan['pages'], fn($p) => ($p['lang'] ?? '') === $page['lang'] && empty($p['error']));
    foreach ($sameLang as &$p) $p['strings'] = trwatch_filter_skipped($p['strings']);
    unset($p);
    [$sitewide] = trwatch_sitewide($sameLang);
    $strings = array_values(array_filter(trwatch_filter_skipped($new['strings']), fn($s) => !in_array($s['text'], $sitewide, true)));

    wp_send_json_success([
        'html'  => trwatch_kses($strings ? trwatch_card($url, $strings) : trwatch_ok_card($url)),
        'found' => count($strings),
    ]);
});

add_action('wp_ajax_trwatch_results', function () {
    check_ajax_referer('trwatch');
    trwatch_require_cap();
    wp_send_json_success(['html' => trwatch_kses(trwatch_results_html())]);
});

/**
 * Every string the saved scan (and the scan in progress) found, keyed by md5 — the only values Skip accepts.
 * The screen sends the hash, not the text, so the exact string never has to survive sanitizing.
 */
function trwatch_known_texts($scanId) {
    $sets = [];
    $saved = trwatch_saved_scan();
    if ($saved) $sets[] = $saved['pages'];
    $run = $scanId ? get_transient(trwatch_run_key($scanId)) : false;
    if ($run) $sets[] = $run['pages'];
    $texts = [];
    foreach ($sets as $pages) foreach ($pages as $p) foreach (($p['strings'] ?? []) as $s) $texts[md5($s['text'])] = $s['text'];
    return $texts;
}

// leave a page out of future scans, or take it back in
add_action('wp_ajax_trwatch_ignore_page', function () {
    check_ajax_referer('trwatch');
    trwatch_require_cap();
    $url = isset($_POST['url']) ? esc_url_raw(wp_unslash($_POST['url'])) : '';
    $undo = isset($_POST['undo']) && '1' === sanitize_key(wp_unslash($_POST['undo']));
    $ignored = trwatch_ignored_pages();

    if ($undo) {
        // the posted URL is an original-language URL from the ignored list
        if (!in_array($url, $ignored, true)) wp_send_json_error(__('Not in the ignored list.', 'translation-watchdog-for-translatepress'));
        $ignored = array_values(array_diff($ignored, [$url]));
    } else {
        // the posted URL is a translated page; ignore its original, so every language skips it
        $saved = trwatch_saved_scan();
        $scanId = trwatch_posted_scan_id();
        $run = $scanId ? get_transient(trwatch_run_key($scanId)) : false;
        $page = $saved['pages'][$url] ?? ($run['pages'][$url] ?? null);
        if (!$page || empty($page['source'])) wp_send_json_error(__('This page is not in the last scan — click Refresh.', 'translation-watchdog-for-translatepress'));
        if (!in_array($page['source'], $ignored, true)) $ignored[] = $page['source'];
    }
    update_option(TRWATCH_OPT_IGNORED_PAGES, $ignored, false);
    wp_send_json_success(['html' => trwatch_kses(trwatch_ignored_html()), 'count' => count($ignored)]);
});

add_action('wp_ajax_trwatch_skip', function () {
    check_ajax_referer('trwatch');
    trwatch_require_cap();
    $hash = isset($_POST['hash']) ? sanitize_key(wp_unslash($_POST['hash'])) : '';
    $undo = isset($_POST['undo']) && '1' === sanitize_key(wp_unslash($_POST['undo']));
    $skipped = trwatch_skipped();

    if ($undo) {
        $keep = array_values(array_filter($skipped, fn($t) => md5($t) !== $hash));
        if (count($keep) === count($skipped)) wp_send_json_error(__('Not in the skipped list.', 'translation-watchdog-for-translatepress'));
        $skipped = $keep;
    } else {
        $text = trwatch_known_texts(trwatch_posted_scan_id())[$hash] ?? null;
        if ($text === null) wp_send_json_error(__('Unknown string — rescan and try again.', 'translation-watchdog-for-translatepress'));
        if (!in_array($text, $skipped, true)) $skipped[] = $text;
    }
    update_option(TRWATCH_OPT_SKIP, $skipped, false);
    wp_send_json_success(['html' => trwatch_kses(trwatch_skipped_html()), 'count' => count($skipped)]);
});

/* ---------- screen ---------- */

function trwatch_render() {
    $header = defined('TRP_PLUGIN_DIR') ? TRP_PLUGIN_DIR . 'partials/settings-header.php' : '';
    $targets = trwatch_target_languages();
    $langs = trwatch_languages();
    $names = implode(', ', array_map(fn($l) => $targets[$l] ?? $l, $langs));
    ?>
    <div id="trp-settings-page" class="wrap">
        <?php
        if ($header && file_exists($header)) require_once $header;
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- TranslatePress' own hook, renders its tab bar
        do_action('trp_settings_navigation_tabs');
        ?>
        <div class="trwatch-wrap">
            <?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flag after the settings redirect
            if (!empty($_GET['trwatch-saved'])) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Settings saved — click Refresh to apply them.', 'translation-watchdog-for-translatepress'); ?></p></div>
            <?php endif; ?>
            <?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only list after the settings redirect
            $trwatch_invalid = isset($_GET['trwatch-invalid']) ? sanitize_text_field(wp_unslash($_GET['trwatch-invalid'])) : '';
            if ($trwatch_invalid !== '') : ?>
                <div class="notice notice-warning is-dismissible"><p><?php
                    /* translators: %s: the selectors that were not saved */
                    echo esc_html(sprintf(__('These selectors are not supported and were not saved: %s', 'translation-watchdog-for-translatepress'), $trwatch_invalid));
                ?></p></div>
            <?php endif; ?>

            <h1><?php esc_html_e('Translation Watchdog', 'translation-watchdog-for-translatepress'); ?>
                <button id="trwatch-refresh" type="button" class="button button-primary" <?php disabled(!$targets); ?>><?php esc_html_e('Refresh', 'translation-watchdog-for-translatepress'); ?></button></h1>
            <p><?php
                /* translators: %s: language names */
                echo esc_html(sprintf(__('Compares every public page with its %s version, as a logged-out visitor sees them, and lists text that is still identical — text that was never translated. Names and terms you keep on purpose can be hidden with Skip.', 'translation-watchdog-for-translatepress'), $names));
            ?></p>
            <div id="trwatch-progress" hidden><progress max="100" value="0"></progress> <span></span></div>
            <div id="trwatch-results"><?php echo wp_kses(trwatch_results_html(), trwatch_allowed_html()); ?></div>

            <details class="trwatch-box"><summary><?php esc_html_e('Skipped strings', 'translation-watchdog-for-translatepress'); ?> (<span id="trwatch-skipcount"><?php echo (int) count(trwatch_skipped()); ?></span>)</summary>
                <div id="trwatch-skipped"><?php echo wp_kses(trwatch_skipped_html(), trwatch_allowed_html()); ?></div>
            </details>
            <details class="trwatch-box"><summary><?php esc_html_e('Ignored pages', 'translation-watchdog-for-translatepress'); ?> (<span id="trwatch-ignoredcount"><?php echo (int) count(trwatch_ignored_pages()); ?></span>)</summary>
                <p class="description"><?php esc_html_e('Left out of every scan, e.g. unpublished popups or seasonal pages. Undo to check a page again from the next scan.', 'translation-watchdog-for-translatepress'); ?></p>
                <div id="trwatch-ignored"><?php echo wp_kses(trwatch_ignored_html(), trwatch_allowed_html()); ?></div>
            </details>
            <details class="trwatch-box"><summary><?php esc_html_e('Settings', 'translation-watchdog-for-translatepress'); ?></summary>
                <form method="post" action="<?php echo esc_url(trwatch_screen_url()); ?>">
                    <?php wp_nonce_field('trwatch_settings'); ?>
                    <?php if (count($targets) > 1) : ?>
                        <fieldset><p><strong><?php esc_html_e('Languages to check', 'translation-watchdog-for-translatepress'); ?></strong></p>
                            <?php foreach ($targets as $code => $name) : ?>
                                <label style="margin-right:16px"><input type="checkbox" name="trwatch_langs[]" value="<?php echo esc_attr($code); ?>" <?php checked(in_array($code, $langs, true)); ?>> <?php echo esc_html($name); ?></label>
                            <?php endforeach; ?>
                        </fieldset>
                    <?php endif; ?>
                    <p><label for="trwatch-allow"><strong><?php esc_html_e('Allowlist', 'translation-watchdog-for-translatepress'); ?></strong> —
                        <?php esc_html_e('words or names that stay the same in every language (brands, product names), one per line', 'translation-watchdog-for-translatepress'); ?></label><br>
                        <textarea id="trwatch-allow" name="trwatch_allow" rows="8" cols="50"><?php echo esc_textarea(implode("\n", trwatch_allowlist())); ?></textarea></p>
                    <p><label for="trwatch-ignore"><strong><?php esc_html_e('Ignore elements', 'translation-watchdog-for-translatepress'); ?></strong> —
                        <?php esc_html_e('text inside these elements is not checked, one CSS selector per line. Each finding shows the element it is in.', 'translation-watchdog-for-translatepress'); ?></label><br>
                        <textarea id="trwatch-ignore" name="trwatch_ignore" rows="6" cols="50" placeholder=".screen-reader-text&#10;button.breakdance-menu-close-button&#10;#cookie-banner"><?php echo esc_textarea(implode("\n", trwatch_ignore_selectors())); ?></textarea><br>
                        <span class="description"><?php esc_html_e('Supported: tag, .class, #id, [attribute] and combinations such as button.close — no spaces or child selectors.', 'translation-watchdog-for-translatepress'); ?></span></p>
                    <p><button class="button" name="trwatch_save" value="1"><?php esc_html_e('Save settings', 'translation-watchdog-for-translatepress'); ?></button></p>
                </form>
            </details>
        </div>
    </div>
    <?php
}
