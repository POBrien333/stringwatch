<?php
/**
 * Plugin Name:       Translation Watchdog for TranslatePress
 * Description:       Adds a Watchdog tab to the TranslatePress settings: compares every translated page with its original and lists text that was never translated — for any language combination.
 * Version:           0.3.8
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Requires Plugins:  translatepress-multilingual
 * Author:            Patrick O'Brien
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       translation-watchdog-for-translatepress
 * Domain Path:       /languages
 * Update URI:        https://github.com/POBrien333/translation-watchdog-for-translatepress
 */

if (!defined('ABSPATH')) exit;

const TRWATCH_VERSION    = '0.3.8';
const TRWATCH_FILE       = __FILE__;
const TRWATCH_SLUG       = 'translation-watchdog-for-translatepress';
const TRWATCH_OPT_RESULT = 'trwatch_last_scan';
const TRWATCH_OPT_ALLOW  = 'trwatch_allowlist';
const TRWATCH_OPT_SKIP   = 'trwatch_skipped';
const TRWATCH_OPT_LANGS  = 'trwatch_languages';
const TRWATCH_OPT_IGNORE = 'trwatch_ignore_selectors';
const TRWATCH_OPT_IGNORED_PAGES = 'trwatch_ignored_pages';
const TRWATCH_BATCH      = 8;    // requests per batch, fetched in parallel
const TRWATCH_TIMEOUT    = 20;

require_once __DIR__ . '/includes/trp.php';
require_once __DIR__ . '/includes/scanner.php';
require_once __DIR__ . '/includes/admin.php';
// @wporg-remove-start — GitHub edition only (wordpress.org delivers updates and translations itself)
require_once __DIR__ . '/includes/updater.php';

add_action('plugins_loaded', function () {
    // distributed via GitHub, not wordpress.org, so translations are not loaded automatically
    // phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound
    load_plugin_textdomain('translation-watchdog-for-translatepress', false, dirname(plugin_basename(__FILE__)) . '/languages');
});
// @wporg-remove-end

// @wporg-remove-start — migrations from pre-release versions, not needed for new installs
register_activation_hook(__FILE__, function () {
    // take over data from the earlier single-file (mu-plugin) version
    $map = ['cstw_last_scan' => TRWATCH_OPT_RESULT, 'cstw_allowlist' => TRWATCH_OPT_ALLOW, 'cstw_skipped' => TRWATCH_OPT_SKIP];
    foreach ($map as $old => $new) {
        $value = get_option($old, null);
        if ($value !== null && get_option($new, null) === null) add_option($new, $value, '', false);
    }
    // 1.0 stored a single language
    $single = get_option('trwatch_language', null);
    if ($single !== null) {
        if (get_option(TRWATCH_OPT_LANGS, null) === null) add_option(TRWATCH_OPT_LANGS, [(string) $single], '', false);
        delete_option('trwatch_language');
    }
});
// @wporg-remove-end
