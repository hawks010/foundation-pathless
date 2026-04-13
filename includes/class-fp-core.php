<?php
if (!defined('ABSPATH')) exit;

if (!class_exists('FP_Core')):

class FP_Core
{
    const TABLE = 'fp_links';
    const CRON_HOOK = 'fp_weekly_scan_event';
    const REST_NS = 'foundation-pathless/v1';

    public static function init()
    {
        // Activation / Deactivation
        if (function_exists('register_activation_hook')) {
            $plugin_file = defined('FP_PATHLESS_PLUGIN_FILE') ? FP_PATHLESS_PLUGIN_FILE : (defined('FP_PLUGIN_FILE') ? FP_PLUGIN_FILE : __FILE__);
            register_activation_hook($plugin_file, [__CLASS__, 'activate']);
            register_deactivation_hook($plugin_file, [__CLASS__, 'deactivate']);
        }

        // DB self‑heal + cron
        add_action('admin_init', [__CLASS__, 'maybe_install']);
        add_filter('cron_schedules', [__CLASS__, 'add_weekly_schedule']);
        add_action(self::CRON_HOOK, [__CLASS__, 'run_weekly_scan']);
        add_action('update_option_fp_enable_scheduled_scan', [__CLASS__, 'toggle_scheduled_scan'], 10, 2);

        // AJAX (kept as a secondary path; some hosts block it)
        add_action('wp_ajax_fp_start_scan', [__CLASS__, 'ajax_start_scan']);
        add_action('wp_ajax_fp_check_scan_status', [__CLASS__, 'ajax_check_scan_status']);
        add_action('wp_ajax_fp_dismiss_link', [__CLASS__, 'ajax_dismiss_link']);

        // REST API — primary transport (bypasses many WAF blocks)
        add_action('rest_api_init', [__CLASS__, 'register_rest_routes']);
    }

    /* ---------------------------------------------------------------------
     * Activation / Deactivation
     * ------------------------------------------------------------------ */

    public static function activate(){ self::create_table(); if (get_option('fp_enable_scheduled_scan')) self::schedule_weekly_scan(); }
    public static function deactivate(){ self::unschedule_weekly_scan(); }

    /* ---------------------------------------------------------------------
     * Install / DB
     * ------------------------------------------------------------------ */

    public static function maybe_install(){ if (!self::table_exists()) self::create_table(); }

    public static function create_table()
    {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE {$table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            post_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            url TEXT NOT NULL,
            source VARCHAR(50) DEFAULT '',
            link_status VARCHAR(20) DEFAULT 'ok',
            http_code SMALLINT UNSIGNED DEFAULT 0,
            redirect_to TEXT,
            accessibility_issues TEXT,
            dismissed TINYINT(1) UNSIGNED DEFAULT 0,
            last_checked DATETIME DEFAULT NULL,
            PRIMARY KEY(id),
            KEY post_id (post_id),
            KEY link_status (link_status),
            KEY dismissed (dismissed)
        ) {$charset};");
    }

    private static function table_exists(): bool
    {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $found = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $wpdb->esc_like($table)));
        return ($found === $table);
    }

    /* ---------------------------------------------------------------------
     * Cron
     * ------------------------------------------------------------------ */
    public static function add_weekly_schedule($s){ if (!isset($s['weekly'])) $s['weekly']=['interval'=>7*DAY_IN_SECONDS,'display'=>__('Once Weekly','foundation-pathless')]; return $s; }
    public static function schedule_weekly_scan(){ if (!wp_next_scheduled(self::CRON_HOOK)) wp_schedule_event(time()+MINUTE_IN_SECONDS,'weekly',self::CRON_HOOK); }
    public static function unschedule_weekly_scan(){ if ($t=wp_next_scheduled(self::CRON_HOOK)) wp_unschedule_event($t,self::CRON_HOOK); }
    public static function toggle_scheduled_scan($o,$n){ (bool)$n ? self::schedule_weekly_scan() : self::unschedule_weekly_scan(); }
    public static function run_weekly_scan(){ self::start_scan_background(true); }

    /* ---------------------------------------------------------------------
     * Auth helpers
     * ------------------------------------------------------------------ */

    private static function rest_permission(\WP_REST_Request $req): bool
    {
        // Require admin capability
        if (!current_user_can('manage_options')) return false;

        // Verify REST nonce header (created by wp_create_nonce('wp_rest'))
        $nonce = $req->get_header('x_wp_nonce');
        if (!$nonce) $nonce = $req->get_header('X-WP-Nonce');
        return (bool) wp_verify_nonce($nonce, 'wp_rest');
    }

    // AJAX fallbacks — accept nonce from multiple places; never 403 (so WAFs don’t kill the transport)
    private static function get_request_nonce(): string
    {
        if (isset($_GET['_wpnonce'])) return (string) $_GET['_wpnonce'];
        if (isset($_POST['nonce']))   return (string) $_POST['nonce'];
        if (!empty($_SERVER['HTTP_X_WP_NONCE'])) return (string) $_SERVER['HTTP_X_WP_NONCE'];
        return '';
    }
    private static function is_authorised_ajax(): bool
    {
        $nonce_ok = wp_verify_nonce(self::get_request_nonce(), 'fp_ajax_nonce');
        $is_admin = current_user_can('manage_options');
        $relax = defined('FP_PATHLESS_RELAX_NONCE') && FP_PATHLESS_RELAX_NONCE;
        return ($nonce_ok && $is_admin) || ($relax && $is_admin);
    }

    /* ---------------------------------------------------------------------
     * REST routes
     * ------------------------------------------------------------------ */
    public static function register_rest_routes()
    {
        register_rest_route(self::REST_NS, '/start', [
            'methods'  => \WP_REST_Server::READABLE, // GET
            'permission_callback' => [__CLASS__, 'rest_permission'],
            'callback' => function(\WP_REST_Request $req){
                // If already scanning, nudge + return status
                if (get_option('fp_scan_status') === 'scanning') {
                    self::maybe_process_one_batch();
                    return new \WP_REST_Response(self::current_status(), 200);
                }

                self::reset_progress();
                if (!self::start_scan_background()) {
                    return new \WP_REST_Response(['error' => 'scanner_unavailable'], 200);
                }
                self::maybe_process_one_batch();
                return new \WP_REST_Response(['ok'=>true] + self::current_status(), 200);
            }
        ]);

        register_rest_route(self::REST_NS, '/status', [
            'methods'  => \WP_REST_Server::READABLE,
            'permission_callback' => [__CLASS__, 'rest_permission'],
            'callback' => function(\WP_REST_Request $req){
                if (get_option('fp_scan_status') === 'scanning') self::maybe_process_one_batch();
                return new \WP_REST_Response(self::current_status(), 200);
            }
        ]);

        register_rest_route(self::REST_NS, '/dismiss', [
            'methods'  => \WP_REST_Server::READABLE,
            'permission_callback' => [__CLASS__, 'rest_permission'],
            'args'     => ['id' => ['required'=>true, 'type'=>'integer']],
            'callback' => function(\WP_REST_Request $req){
                global $wpdb; $table = $wpdb->prefix . self::TABLE;
                $id = absint($req['id']);
                if (!$id) return new \WP_REST_Response(['error'=>'invalid_id'], 200);
                $res = $wpdb->update($table, ['dismissed'=>1], ['id'=>$id], ['%d'], ['%d']);
                if (false === $res) return new \WP_REST_Response(['error'=>'db_error'], 200);
                return new \WP_REST_Response(['dismissed'=>$id], 200);
            }
        ]);
    }

    /* ---------------------------------------------------------------------
     * AJAX (secondary path; HTTP 200 even on auth fail)
     * ------------------------------------------------------------------ */
    public static function ajax_start_scan()
    {
        if (!self::is_authorised_ajax()) { wp_send_json_error(['message'=>'auth_failed']); }
        if (get_option('fp_scan_status')==='scanning'){ self::maybe_process_one_batch(); wp_send_json_success(self::current_status()); }
        self::reset_progress();
        if (!self::start_scan_background()){ update_option('fp_scan_status','idle',false); wp_send_json_error(['message'=>'scanner_unavailable']); }
        self::maybe_process_one_batch();
        wp_send_json_success(['ok'=>true]+self::current_status());
    }

    public static function ajax_check_scan_status()
    {
        if (!self::is_authorised_ajax()) { wp_send_json_error(['message'=>'auth_failed']); }
        if (get_option('fp_scan_status')==='scanning') self::maybe_process_one_batch();
        wp_send_json_success(self::current_status());
    }

    public static function ajax_dismiss_link()
    {
        if (!self::is_authorised_ajax()) { wp_send_json_error(['message'=>'auth_failed']); }
        $id = isset($_REQUEST['link_id']) ? absint($_REQUEST['link_id']) : 0;
        if(!$id) wp_send_json_error(['message'=>'invalid_id']);
        global $wpdb; $table=$wpdb->prefix.self::TABLE;
        $res = $wpdb->update($table,['dismissed'=>1],['id'=>$id],['%d'],['%d']);
        if(false===$res) wp_send_json_error(['message'=>'db_error']);
        wp_send_json_success(['dismissed'=>$id]);
    }

    /* ---------------------------------------------------------------------
     * Scan/progress
     * ------------------------------------------------------------------ */
    private static function reset_progress(){ update_option('fp_scan_status','scanning',false); update_option('fp_scan_started',time(),false); update_option('fp_scan_progress',0,false); update_option('fp_scan_scanned',0,false); update_option('fp_scan_total',0,false); }

    private static function start_scan_background(bool $silent=false): bool
    {
        do_action('fp_pathless_before_start_scan');
        if (!class_exists('FP_Background_Scanner')) { if(!$silent) do_action('fp_pathless_missing_scanner'); return false; }
        $s = new FP_Background_Scanner();
        if (method_exists($s,'queue_full_site_scan')) $s->queue_full_site_scan();
        elseif (method_exists($s,'start_full_scan'))   $s->start_full_scan();
        elseif (method_exists($s,'dispatch_full_scan'))$s->dispatch_full_scan();
        elseif (method_exists($s,'start'))             $s->start();
        elseif (method_exists($s,'dispatch'))          $s->dispatch();
        else { if (has_action('fp_pathless_start_scan')) do_action('fp_pathless_start_scan',$s); else return false; }
        do_action('fp_pathless_after_start_scan');
        return true;
    }

    private static function maybe_process_one_batch()
    {
        if (!class_exists('FP_Background_Scanner')) return;
        $s = new FP_Background_Scanner();
        if (method_exists($s, 'process_one_batch')) $s->process_one_batch();
    }

    private static function current_status(): array
    {
        return [
            'status'   => get_option('fp_scan_status','idle'),
            'progress' => (int) get_option('fp_scan_progress',0),
            'scanned'  => (int) get_option('fp_scan_scanned',0),
            'total'    => (int) get_option('fp_scan_total',0),
        ];
        }
}

endif;
