<?php
/**
 * Plugin Name:       Foundation: Pathless
 * Plugin URI:        https://github.com/hawks010/foundation-pathless
 * Description:       Finds broken links, unreachable URLs, and failed redirects before your visitors do. Part of the Foundation series by Inkfire Limited.
 * Version:           1.5.3
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
if (!defined('FP_PATHLESS_VERSION'))     define('FP_PATHLESS_VERSION', '1.5.3');
if (!defined('FP_PATHLESS_PLUGIN_FILE')) define('FP_PATHLESS_PLUGIN_FILE', __FILE__);
if (!defined('FP_PATHLESS_PLUGIN_PATH')) define('FP_PATHLESS_PLUGIN_PATH', plugin_dir_path(FP_PATHLESS_PLUGIN_FILE));
if (!defined('FP_PATHLESS_PLUGIN_URL'))  define('FP_PATHLESS_PLUGIN_URL', plugin_dir_url(FP_PATHLESS_PLUGIN_FILE));

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
        if (!$this->load_dependencies()) {
            // Missing dependencies: admin notice already queued in loader.
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
