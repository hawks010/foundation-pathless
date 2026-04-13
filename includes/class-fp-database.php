<?php
/**
 * Class FP_Database
 *
 * Ensures the Pathless data table exists and matches the schema the plugin expects.
 * Includes light migration from older schemas (link_url/link_text/status_* → new columns).
 */
if (!defined('ABSPATH')) exit;

class FP_Database
{
    const TABLE_NAME = 'fp_links';

    public static function init() {
        // Heal DB on every admin page load.
        add_action('admin_init', [self::class, 'ensure_schema']);
    }

    /** Create table if missing, then migrate/patch columns to expected schema. */
    public static function ensure_schema() {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;

        if (!self::table_exists($table)) {
            self::create_table($table);
            return;
        }

        self::migrate_schema($table);
    }

    /* ---------------------------------------------------------------------
     * Helpers
     * ------------------------------------------------------------------ */

    private static function table_exists(string $table): bool {
        global $wpdb;
        $found = $wpdb->get_var(
            $wpdb->prepare("SHOW TABLES LIKE %s", $wpdb->esc_like($table))
        );
        return ($found === $table);
    }

    private static function column_exists(string $table, string $column): bool {
        global $wpdb;
        return (bool) $wpdb->get_var(
            $wpdb->prepare("SHOW COLUMNS FROM `{$table}` LIKE %s", $column)
        );
    }

    private static function index_exists_on_column(string $table, string $column): bool {
        global $wpdb;
        $indexes = $wpdb->get_results("SHOW INDEX FROM `{$table}`", ARRAY_A);
        if (!$indexes) return false;
        foreach ($indexes as $idx) {
            if (!empty($idx['Column_name']) && strtolower($idx['Column_name']) === strtolower($column)) {
                return true;
            }
        }
        return false;
    }

    private static function add_index(string $table, string $column, string $index_name): void {
        global $wpdb;
        // Only attempt if column exists and index does not.
        if (!self::column_exists($table, $column)) return;
        if (self::index_exists_on_column($table, $column)) return;

        $index_name = preg_replace('/[^a-zA-Z0-9_]/', '', $index_name);
        $column     = preg_replace('/[^a-zA-Z0-9_]/', '', $column);

        if ($index_name === '' || $column === '') return; // absolute safety

        $sql = "ALTER TABLE `{$table}` ADD INDEX `{$index_name}` (`{$column}`)";
        $wpdb->query($sql);
    }

    /* ---------------------------------------------------------------------
     * Create / Migrate
     * ------------------------------------------------------------------ */

    /** Create the table with the schema expected by the current plugin code. */
    private static function create_table(string $table) {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE `{$table}` (
            `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            `post_id` BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            `url` TEXT NOT NULL,
            `source` VARCHAR(50) DEFAULT '',
            `link_status` VARCHAR(20) DEFAULT 'ok',
            `http_code` SMALLINT UNSIGNED DEFAULT 0,
            `redirect_to` TEXT,
            `accessibility_issues` TEXT,
            `dismissed` TINYINT(1) UNSIGNED DEFAULT 0,
            `last_checked` DATETIME NULL DEFAULT NULL,
            PRIMARY KEY (`id`)
        ) {$charset};";

        dbDelta($sql);

        // Add needed indexes explicitly.
        self::add_index($table, 'post_id',    'idx_post_id');
        self::add_index($table, 'link_status','idx_link_status');
        self::add_index($table, 'dismissed',  'idx_dismissed');
    }

    /** Migrate older/other schemas to the expected one (best effort). */
    private static function migrate_schema(string $table) {
        global $wpdb;

        $queries = [];

        // Harden id type.
        if (self::column_exists($table, 'id')) {
            $queries[] = "ALTER TABLE `{$table}` MODIFY `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT";
        }

        // Rename legacy link_url → url if needed
        if (!self::column_exists($table, 'url') && self::column_exists($table, 'link_url')) {
            $queries[] = "ALTER TABLE `{$table}` CHANGE `link_url` `url` TEXT NOT NULL";
        }

        // Add required columns if missing
        if (!self::column_exists($table, 'url'))            $queries[] = "ALTER TABLE `{$table}` ADD `url` TEXT NOT NULL AFTER `post_id`";
        if (!self::column_exists($table, 'source'))         $queries[] = "ALTER TABLE `{$table}` ADD `source` VARCHAR(50) DEFAULT '' AFTER `url`";
        if (!self::column_exists($table, 'link_status'))    $queries[] = "ALTER TABLE `{$table}` ADD `link_status` VARCHAR(20) DEFAULT 'ok' AFTER `source`";

        // http_code (migrate old status_code if present)
        $has_http = self::column_exists($table, 'http_code');
        $has_old  = self::column_exists($table, 'status_code');
        if (!$has_http && $has_old) {
            $queries[] = "ALTER TABLE `{$table}` ADD `http_code` SMALLINT UNSIGNED DEFAULT 0 AFTER `link_status`";
            $queries[] = "UPDATE `{$table}` SET `http_code` = 0 + `status_code` WHERE `status_code` IS NOT NULL";
        } elseif (!$has_http) {
            $queries[] = "ALTER TABLE `{$table}` ADD `http_code` SMALLINT UNSIGNED DEFAULT 0 AFTER `link_status`";
        }

        if (!self::column_exists($table, 'redirect_to'))          $queries[] = "ALTER TABLE `{$table}` ADD `redirect_to` TEXT AFTER `http_code`";
        if (!self::column_exists($table, 'accessibility_issues')) $queries[] = "ALTER TABLE `{$table}` ADD `accessibility_issues` TEXT AFTER `redirect_to`";
        if (!self::column_exists($table, 'dismissed'))            $queries[] = "ALTER TABLE `{$table}` ADD `dismissed` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0 AFTER `accessibility_issues`";

        if (!self::column_exists($table, 'last_checked')) {
            $queries[] = "ALTER TABLE `{$table}` ADD `last_checked` DATETIME NULL DEFAULT NULL AFTER `dismissed`";
        } else {
            $queries[] = "ALTER TABLE `{$table}` MODIFY `last_checked` DATETIME NULL DEFAULT NULL";
        }

        // Execute migrations
        foreach ($queries as $sql) {
            $wpdb->query($sql);
        }

        // Ensure indexes (only when column exists and index missing)
        self::add_index($table, 'post_id',    'idx_post_id');
        self::add_index($table, 'link_status','idx_link_status');
        self::add_index($table, 'dismissed',  'idx_dismissed');
    }
}

FP_Database::init();
