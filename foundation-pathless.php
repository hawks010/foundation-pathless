<?php
/**
 * Plugin Name:       Foundation: Pathless
 * Plugin URI:        https://github.com/hawks010/foundation-pathless
 * Description:       Finds broken links, unreachable URLs, and failed redirects before your visitors do. Part of the Foundation series by Inkfire Limited.
 * Version:           1.5.4
 * Author:            Sonny x Inkfire
 * Author URI:        https://inkfire.co.uk/
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       foundation-pathless
 * Domain Path:       /languages
 * Update URI:        https://github.com/hawks010/foundation-pathless
 */

if (!defined('ABSPATH')) exit;

/* -------------------------------------------------------------------------
 * Constants (plugin-scoped) + legacy aliases
 * ------------------------------------------------------------------------- */
if (!defined('FP_PATHLESS_VERSION'))     define('FP_PATHLESS_VERSION', '1.5.4');
if (!defined('FP_PATHLESS_PLUGIN_FILE')) define('FP_PATHLESS_PLUGIN_FILE', __FILE__);
if (!defined('FP_PATHLESS_PLUGIN_PATH')) define('FP_PATHLESS_PLUGIN_PATH', plugin_dir_path(FP_PATHLESS_PLUGIN_FILE));
if (!defined('FP_PATHLESS_PLUGIN_URL'))  define('FP_PATHLESS_PLUGIN_URL', plugin_dir_url(FP_PATHLESS_PLUGIN_FILE));
if (!defined('FP_PATHLESS_CORE_SLUG'))   define('FP_PATHLESS_CORE_SLUG', 'foundation-pathless');
if (!defined('FP_PATHLESS_MIN_CORE'))    define('FP_PATHLESS_MIN_CORE', '0.1.0');

// Legacy aliases for older includes that expect FP_* (define only if missing)
if (!defined('FP_VERSION'))      define('FP_VERSION', FP_PATHLESS_VERSION);
if (!defined('FP_PLUGIN_FILE'))  define('FP_PLUGIN_FILE', FP_PATHLESS_PLUGIN_FILE);
if (!defined('FP_PLUGIN_PATH'))  define('FP_PLUGIN_PATH', FP_PATHLESS_PLUGIN_PATH);
if (!defined('FP_PLUGIN_URL'))   define('FP_PLUGIN_URL', FP_PATHLESS_PLUGIN_URL);

require_once FP_PATHLESS_PLUGIN_PATH . 'includes/class-fp-github-updater.php';

if (class_exists('Foundation_Pathless_Github_Updater') && method_exists('Foundation_Pathless_Github_Updater', 'instance')) {
    Foundation_Pathless_Github_Updater::instance();
}

/* -------------------------------------------------------------------------
 * Foundation Core integration
 * ------------------------------------------------------------------------- */
function foundation_pathless_core_is_available(): bool {
    return function_exists('foundation_core_register_addon')
        && defined('FOUNDATION_CORE_VERSION')
        && version_compare(FOUNDATION_CORE_VERSION, FP_PATHLESS_MIN_CORE, '>=');
}

function foundation_pathless_log_to_core(string $level, string $event, array $context = []): void {
    if (function_exists('foundation_core_log_event')) {
        foundation_core_log_event($level, $event, $context, FP_PATHLESS_CORE_SLUG);
    }
}

function foundation_pathless_is_isolated_by_core(): bool {
    if (!function_exists('foundation_core_get_safe_mode_manager')) {
        return false;
    }

    $manager = foundation_core_get_safe_mode_manager();
    return is_object($manager)
        && method_exists($manager, 'is_isolated')
        && $manager->is_isolated(FP_PATHLESS_CORE_SLUG);
}

function foundation_pathless_register_with_core(): void {
    if (!function_exists('foundation_core_register_addon')) {
        return;
    }

    $result = foundation_core_register_addon([
        'slug'                  => FP_PATHLESS_CORE_SLUG,
        'name'                  => 'Foundation: Pathless',
        'version'               => FP_PATHLESS_VERSION,
        'type'                  => 'commercial-addon',
        'channel'               => 'stable',
        'min_core_version'      => FP_PATHLESS_MIN_CORE,
        'requires_license'      => true,
        'admin_page_slug'       => 'foundation-pathless-dashboard',
        'product_url'           => 'https://inkfire.co.uk/',
        'support_url'           => 'https://inkfire.co.uk/',
        'docs_url'              => 'https://inkfire.co.uk/',
        'module_class'          => 'Foundation_Pathless',
        'health_check_callback' => 'foundation_pathless_health_check',
        'status'                => foundation_pathless_is_isolated_by_core() ? 'isolated' : 'active',
    ]);

    if (is_wp_error($result)) {
        foundation_pathless_log_to_core(
            'error',
            'addon_registration_failed',
            [
                'code'    => $result->get_error_code(),
                'message' => $result->get_error_message(),
            ]
        );
        return;
    }

    foundation_pathless_log_to_core(
        'info',
        'addon_registered_with_core',
        [
            'version'          => FP_PATHLESS_VERSION,
            'min_core_version' => FP_PATHLESS_MIN_CORE,
        ]
    );
}
add_action('foundation_core_register_addons', 'foundation_pathless_register_with_core');

function foundation_pathless_health_check(array $addon = [], array $context = [], $registry = null): array {
    global $wpdb;

    $table = $wpdb->prefix . 'fp_links';
    $table_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) === $table;
    $critical_classes = [
        'FP_Core' => class_exists('FP_Core'),
        'FP_Database' => class_exists('FP_Database'),
        'FP_Scanner' => class_exists('FP_Scanner'),
        'FP_Background_Scanner' => class_exists('FP_Background_Scanner'),
    ];
    $missing = array_keys(array_filter($critical_classes, static function ($present) {
        return !$present;
    }));

    $state = ($table_exists && empty($missing)) ? 'ok' : 'degraded';

    return [
        'state' => $state,
        'message' => 'ok' === $state
            ? __('Pathless scanner, database table, and background worker are available.', 'foundation-pathless')
            : __('Pathless is running, but one or more runtime checks are degraded.', 'foundation-pathless'),
        'data' => [
            'table_exists' => $table_exists,
            'missing_classes' => $missing,
            'scan_status' => get_option('fp_scan_status', 'idle'),
            'scheduled_scan_enabled' => (bool) get_option('fp_enable_scheduled_scan', false),
        ],
        'context' => $context,
    ];
}

function foundation_pathless_core_notice(): void {
    if (!current_user_can('manage_options')) {
        return;
    }

    if (foundation_pathless_core_is_available()) {
        return;
    }

    $message = function_exists('foundation_core_register_addon')
        ? sprintf(
            /* translators: %s: minimum Foundation Core version. */
            __('Foundation: Pathless is running in legacy mode because Foundation Core is older than %s.', 'foundation-pathless'),
            FP_PATHLESS_MIN_CORE
        )
        : __('Foundation: Pathless is running in legacy mode until Foundation Core is installed and active.', 'foundation-pathless');

    echo '<div class="notice notice-warning"><p><strong>Foundation: Pathless</strong> — ' . esc_html($message) . '</p></div>';
}
add_action('admin_notices', 'foundation_pathless_core_notice');

function foundation_pathless_safe_mode_notice(): void {
    if (!current_user_can('manage_options') || !foundation_pathless_is_isolated_by_core()) {
        return;
    }

    echo '<div class="notice notice-error"><p><strong>Foundation: Pathless</strong> — '
        . esc_html__('This addon is currently isolated by Foundation Core safe mode. Restore it from Foundation > Addons when you are ready to re-enable Pathless runtime hooks.', 'foundation-pathless')
        . '</p></div>';
}
add_action('admin_notices', 'foundation_pathless_safe_mode_notice');

/* -------------------------------------------------------------------------
 * Safe Activation / Deactivation (captures unexpected output)
 * ------------------------------------------------------------------------- */
register_activation_hook(FP_PATHLESS_PLUGIN_FILE, function () {
    $captured = '';
    $prev_display = ini_get('display_errors');
    @ini_set('display_errors', '0');
    ob_start();

    try {
        // Ensure DB schema exists.
        require_once FP_PATHLESS_PLUGIN_PATH . 'includes/class-fp-database.php';
        if (class_exists('FP_Database')) {
            // Wire the hook and force a one-off ensure.
            FP_Database::init();
            if (method_exists('FP_Database', 'ensure_schema')) {
                FP_Database::ensure_schema();
            } elseif (method_exists('FP_Database', 'check_and_create_table')) {
                FP_Database::check_and_create_table();
            }
        }

        // Schedule cron etc. via FP_Core if available.
        $core = FP_PATHLESS_PLUGIN_PATH . 'includes/class-fp-core.php';
        if (file_exists($core)) {
            require_once $core;
            if (class_exists('FP_Core') && method_exists('FP_Core', 'activate')) {
                FP_Core::activate();
            }
        }
    } catch (\Throwable $e) {
        update_option('fp_pathless_activation_error', $e->getMessage(), false);
    } finally {
        $captured = ob_get_clean();
        @ini_set('display_errors', $prev_display);
    }

    if (!empty($captured)) {
        update_option('fp_pathless_activation_output', $captured, false);
    }
});

register_deactivation_hook(FP_PATHLESS_PLUGIN_FILE, function () {
    $captured = '';
    ob_start();

    try {
        $core = FP_PATHLESS_PLUGIN_PATH . 'includes/class-fp-core.php';
        if (file_exists($core)) {
            require_once $core;
            if (class_exists('FP_Core') && method_exists('FP_Core', 'deactivate')) {
                FP_Core::deactivate();
            }
        }
    } catch (\Throwable $e) {
        update_option('fp_pathless_deactivation_error', $e->getMessage(), false);
    } finally {
        $captured = ob_get_clean();
    }

    if (!empty($captured)) {
        update_option('fp_pathless_deactivation_output', $captured, false);
    }
});

// After activation/deactivation, show any captured text as a tidy notice (won’t break headers)
add_action('admin_notices', function () {
    foreach (['activation','deactivation'] as $phase) {
        $msg  = get_option("fp_pathless_{$phase}_output");
        $err  = get_option("fp_pathless_{$phase}_error");
        if (empty($msg) && empty($err)) continue;

        echo '<div class="notice notice-warning"><p><strong>Foundation: Pathless</strong> ';
        echo esc_html(ucfirst($phase) . ' produced messages:');
        echo '</p>';

        if (!empty($err)) {
            echo '<p><code>' . esc_html($err) . '</code></p>';
        }
        if (!empty($msg)) {
            $snippet = mb_substr(wp_strip_all_tags($msg), 0, 1200);
            echo '<pre style="white-space:pre-wrap;max-height:16em;overflow:auto;border:1px solid #ccd0d4;padding:.5em;background:#fff;">'
               . esc_html($snippet)
               . (mb_strlen($msg) > 1200 ? "\n…(truncated)…" : '')
               . '</pre>';
        }
        echo '</div>';

        delete_option("fp_pathless_{$phase}_output");
        delete_option("fp_pathless_{$phase}_error");
    }
});

/* -------------------------------------------------------------------------
 * Main plugin class
 * ------------------------------------------------------------------------- */
if (!class_exists('Foundation_Pathless')):

final class Foundation_Pathless
{
    private static $_instance = null;
    public  $scanner_process = null;

    public static function instance() {
        if (is_null(self::$_instance)) self::$_instance = new self();
        return self::$_instance;
    }

    private function __construct() {
        // Load translations early
        add_action('plugins_loaded', [$this, 'load_textdomain'], 1);
        // Then load plugin parts
        add_action('plugins_loaded', [$this, 'init_plugin'], 5);
    }

    public function load_textdomain() {
        load_plugin_textdomain(
            'foundation-pathless',
            false,
            dirname(plugin_basename(FP_PATHLESS_PLUGIN_FILE)) . '/languages'
        );
    }

    /**
     * Load dependencies and initialise components.
     */
    public function init_plugin() {
        foundation_pathless_log_to_core(
            'info',
            'addon_bootstrap_started',
            [
                'version' => FP_PATHLESS_VERSION,
                'core_available' => foundation_pathless_core_is_available(),
            ]
        );

        if (!$this->load_dependencies()) {
            // Missing dependencies: admin notice already queued in loader.
            foundation_pathless_log_to_core(
                'error',
                'addon_bootstrap_dependency_failure',
                [
                    'version' => FP_PATHLESS_VERSION,
                ]
            );
            return;
        }

        if (foundation_pathless_is_isolated_by_core()) {
            foundation_pathless_log_to_core(
                'warning',
                'addon_bootstrap_skipped_safe_mode',
                [
                    'reason' => 'core_safe_mode_isolated',
                ]
            );
            return;
        }

        // Background scanner instance (optional if library present)
        if (class_exists('FP_Background_Scanner')) {
            $this->scanner_process = new FP_Background_Scanner();
        }

        // Core wires: cron, AJAX/REST, DB self-heal
        if (class_exists('FP_Core')) {
            FP_Core::init();
        }

        // Admin UI
        if (is_admin() && class_exists('FP_Admin_UI')) {
            FP_Admin_UI::init();
        }

        foundation_pathless_log_to_core(
            'info',
            'addon_bootstrap_completed',
            [
                'admin_ui' => is_admin(),
                'scanner_process' => is_object($this->scanner_process),
            ]
        );
    }

    /**
     * Include all necessary class files. Uses absolute paths.
     */
    private function load_dependencies(): bool {
        // Vendor library: WP Background Processing
        $bg_lib = FP_PATHLESS_PLUGIN_PATH . 'lib/wp-background-processing/wp-background-processing.php';
        if (!file_exists($bg_lib)) {
            add_action('admin_notices', function () use ($bg_lib) {
                echo '<div class="notice notice-error"><p><strong>Foundation: Pathless</strong> — '
                   . esc_html__('Missing dependency:', 'foundation-pathless') . ' <code>'
                   . esc_html($bg_lib)
                   . '</code>. '
                   . esc_html__('Please ensure the background processing library is present at', 'foundation-pathless')
                   . ' <code>lib/wp-background-processing/</code>.</p></div>';
            });
            // Continue so admin screens still load; scanner has REST tick fallback.
        } else {
            require_once $bg_lib;
        }

        // Core components
        $core_files = [
            FP_PATHLESS_PLUGIN_PATH . 'includes/class-fp-database.php',
            FP_PATHLESS_PLUGIN_PATH . 'includes/class-fp-scanner.php',
            FP_PATHLESS_PLUGIN_PATH . 'includes/class-fp-accessibility-checker.php',
            FP_PATHLESS_PLUGIN_PATH . 'includes/class-fp-background-scanner.php',
            FP_PATHLESS_PLUGIN_PATH . 'includes/class-fp-core.php',
        ];
        foreach ($core_files as $file) {
            if (!file_exists($file)) {
                add_action('admin_notices', function () use ($file) {
                    echo '<div class="notice notice-error"><p><strong>Foundation: Pathless</strong> — '
                       . esc_html__('Missing file:', 'foundation-pathless') . ' <code>'
                       . esc_html($file) . '</code>.</p></div>';
                });
                return false;
            }
            require_once $file;
        }

        // Admin components
        if (is_admin()) {
            $admin_files = [
                FP_PATHLESS_PLUGIN_PATH . 'includes/class-fp-list-table.php',
                FP_PATHLESS_PLUGIN_PATH . 'includes/class-fp-admin-ui.php',
            ];
            foreach ($admin_files as $file) {
                if (!file_exists($file)) {
                    add_action('admin_notices', function () use ($file) {
                        echo '<div class="notice notice-error"><p><strong>Foundation: Pathless</strong> — '
                           . esc_html__('Missing admin file:', 'foundation-pathless') . ' <code>'
                           . esc_html($file) . '</code>.</p></div>';
                    });
                    return false;
                }
                require_once $file;
            }
        }

        return true;
    }
}

endif;

/* -------------------------------------------------------------------------
 * Bootstrap singleton
 * ------------------------------------------------------------------------- */
if (!function_exists('Foundation_Pathless')) {
    function Foundation_Pathless() { return Foundation_Pathless::instance(); }
}
Foundation_Pathless();
