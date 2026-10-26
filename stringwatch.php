<?php
/**
 * Plugin Name:       Stringwatch – Untranslated Text Finder for TranslatePress
 * Description:       Adds a Stringwatch tab to the TranslatePress settings: compares every translated page with its original and lists text that was never translated — for any language combination.
 * Version:           0.4.1
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Requires Plugins:  translatepress-multilingual
 * Author:            Blue River Growth
 * Author URI:        https://bluerivergrowth.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       stringwatch
 * Domain Path:       /languages
 */

if (!defined('ABSPATH')) exit;

const TRWATCH_VERSION    = '0.4.1';
const TRWATCH_FILE       = __FILE__;
const TRWATCH_SLUG       = 'stringwatch';
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

