<?php

class FP_Admin_UI {
    public static function init() {
        add_action('admin_menu', [self::class, 'add_admin_menu']);
        add_action('admin_init', [self::class, 'register_settings']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_admin_assets']);
    }

    private static function admin_hooks() {
        return [
            'foundation_page_foundation-pathless-dashboard',
            'toplevel_page_foundation-by-inkfire',
        ];
    }

    public static function enqueue_admin_assets($hook) {
        if (!in_array($hook, self::admin_hooks(), true)) {
            return;
        }

        $asset_version = defined('FP_PATHLESS_VERSION') ? FP_PATHLESS_VERSION : time();
        $asset_base = trailingslashit(FP_PATHLESS_PLUGIN_URL) . 'assets/admin/';

        wp_enqueue_style(
            'foundation-admin-shell',
            $asset_base . 'foundation-admin-shell.css',
            [],
            $asset_version
        );

        wp_enqueue_script(
            'foundation-admin-shell',
            $asset_base . 'foundation-admin-shell.js',
            ['wp-element'],
            $asset_version,
            true
        );

        wp_enqueue_script(
            'foundation-pathless-admin',
            $asset_base . 'pathless-admin.js',
            [],
            $asset_version,
            true
        );

        $summary = self::get_dashboard_summary();
        $config = self::get_shell_config($summary);

        wp_add_inline_script(
            'foundation-admin-shell',
            'window.foundationAdminShellData = ' . wp_json_encode($config) . ';',
            'before'
        );

        wp_localize_script(
            'foundation-pathless-admin',
            'fpPathlessAdmin',
            [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'restRoot' => esc_url_raw(rest_url(FP_Core::REST_NS . '/')),
                'restNonce' => wp_create_nonce('wp_rest'),
                'ajaxNonce' => wp_create_nonce('fp_ajax_nonce'),
            ]
        );
    }

    public static function add_admin_menu() {
        global $admin_page_hooks;
        $parent_slug = 'foundation-by-inkfire';

        if (empty($admin_page_hooks[$parent_slug])) {
            add_menu_page(
                __('Foundation', 'foundation-pathless'),
                __('Foundation', 'foundation-pathless'),
                'manage_options',
                $parent_slug,
                null,
                'dashicons-hammer',
                12
            );
            remove_submenu_page($parent_slug, $parent_slug);
        }

        add_submenu_page(
            $parent_slug,
            __('Pathless', 'foundation-pathless'),
            __('Pathless', 'foundation-pathless'),
            'manage_options',
            'foundation-pathless-dashboard',
            [self::class, 'render_dashboard_page']
        );

        remove_submenu_page($parent_slug, 'fp-settings');
    }

    public static function register_settings() {
        register_setting('fp_settings_group', 'fp_link_timeout');
        register_setting('fp_settings_group', 'fp_a11y_blacklist');
        register_setting('fp_settings_group', 'fp_enable_scheduled_scan', [
            'type' => 'boolean',
            'sanitize_callback' => 'rest_sanitize_boolean',
        ]);
    }

    private static function get_dashboard_summary() {
        global $wpdb;

        $table = $wpdb->prefix . 'fp_links';

        return [
            'total' => (int) $wpdb->get_var("SELECT COUNT(id) FROM $table WHERE dismissed = 0"),
            'broken' => (int) $wpdb->get_var("SELECT COUNT(id) FROM $table WHERE dismissed = 0 AND link_status = 'broken'"),
            'a11y' => (int) $wpdb->get_var("SELECT COUNT(id) FROM $table WHERE dismissed = 0 AND accessibility_issues != ''"),
            'status' => get_option('fp_scan_status', 'idle'),
        ];
    }

    private static function get_shell_config(array $summary) {
        return [
            'plugin' => 'pathless',
            'rootId' => 'foundation-admin-app',
            'eyebrow' => __('Foundation command centre', 'foundation-pathless'),
            'title' => __('Foundation: Pathless', 'foundation-pathless'),
            'description' => __('The same Pathless scanner, list table, dismiss actions, and option names are still here. This pass only modernises the admin shell so it matches the wider Foundation pattern.', 'foundation-pathless'),
            'badge' => sprintf(__('v%s', 'foundation-pathless'), defined('FP_PATHLESS_VERSION') ? FP_PATHLESS_VERSION : '1.0.0'),
            'themeStorageKey' => 'foundation-pathless-theme',
            'actions' => [
                [
                    'label' => __('Refresh dashboard', 'foundation-pathless'),
                    'href' => admin_url('admin.php?page=foundation-pathless-dashboard'),
                    'variant' => 'solid',
                ],
                [
                    'label' => __('GitHub backup', 'foundation-pathless'),
                    'href' => 'https://github.com/hawks010/foundation-pathless',
                    'target' => '_blank',
                    'variant' => 'ghost',
                ],
            ],
            'metrics' => [
                [
                    'label' => __('Links monitored', 'foundation-pathless'),
                    'value' => number_format_i18n($summary['total']),
                    'meta' => __('Active URLs in the current results set.', 'foundation-pathless'),
                ],
                [
                    'label' => __('Broken links', 'foundation-pathless'),
                    'value' => number_format_i18n($summary['broken']),
                    'meta' => __('Hard failures that need attention.', 'foundation-pathless'),
                    'tone' => $summary['broken'] > 0 ? 'danger' : '',
                ],
                [
                    'label' => __('Accessibility flags', 'foundation-pathless'),
                    'value' => number_format_i18n($summary['a11y']),
                    'meta' => __('Non-descriptive or empty link text findings.', 'foundation-pathless'),
                    'tone' => $summary['a11y'] > 0 ? 'accent' : '',
                ],
                [
                    'label' => __('Scanner state', 'foundation-pathless'),
                    'value' => $summary['status'] === 'scanning' ? __('Running', 'foundation-pathless') : __('Idle', 'foundation-pathless'),
                    'meta' => $summary['status'] === 'scanning' ? __('A scan is already in progress.', 'foundation-pathless') : __('Ready to run a new sweep.', 'foundation-pathless'),
                ],
            ],
            'sections' => [
                [
                    'id' => 'pathless-operations',
                    'navLabel' => __('Operations', 'foundation-pathless'),
                    'eyebrow' => __('Scan operations', 'foundation-pathless'),
                    'title' => __('Run scans and review health', 'foundation-pathless'),
                    'description' => __('This section keeps the existing scan trigger, progress bar, and health callouts intact.', 'foundation-pathless'),
                    'templateId' => 'fp-pathless-operations',
                ],
                [
                    'id' => 'pathless-analysis',
                    'navLabel' => __('Analysis', 'foundation-pathless'),
                    'eyebrow' => __('Results workspace', 'foundation-pathless'),
                    'title' => __('Broken links and dismiss actions', 'foundation-pathless'),
                    'description' => __('The existing WordPress list table still powers the results grid, bulk actions, and dismiss controls.', 'foundation-pathless'),
                    'templateId' => 'fp-pathless-analysis',
                ],
                [
                    'id' => 'pathless-settings',
                    'navLabel' => __('Settings', 'foundation-pathless'),
                    'eyebrow' => __('Rules and cadence', 'foundation-pathless'),
                    'title' => __('Timeouts, phrases, and scheduled scans', 'foundation-pathless'),
                    'description' => __('These fields still save through the same `fp_settings_group` options flow.', 'foundation-pathless'),
                    'templateId' => 'fp-pathless-settings',
                ],
            ],
        ];
    }

    private static function render_template($id, $html) {
        printf(
            '<template id="%1$s">%2$s</template>',
            esc_attr($id),
            $html
        );
    }

    private static function get_operations_markup(array $summary) {
        ob_start();
        ?>
        <div class="fp-card">
            <h2 class="fp-card-title"><?php esc_html_e('Run scanner', 'foundation-pathless'); ?></h2>
            <div class="fp-stats-grid">
                <div class="fp-stat-box">
                    <span class="fp-stat-value"><?php echo number_format_i18n($summary['total']); ?></span>
                    <span class="fp-stat-label"><?php esc_html_e('Links monitored', 'foundation-pathless'); ?></span>
                </div>
                <div class="fp-stat-box">
                    <span class="fp-stat-value fp-stat-broken"><?php echo number_format_i18n($summary['broken']); ?></span>
                    <span class="fp-stat-label"><?php esc_html_e('Broken links', 'foundation-pathless'); ?></span>
                </div>
            </div>
            <p class="description"><?php esc_html_e('Kick off a fresh sweep using the same REST and AJAX endpoints Pathless already uses in production.', 'foundation-pathless'); ?></p>
            <button id="fp-trigger-scan" class="button button-primary button-large" <?php disabled($summary['status'], 'scanning'); ?>>
                <?php echo $summary['status'] === 'scanning' ? esc_html__('Scan in progress...', 'foundation-pathless') : esc_html__('Start new scan', 'foundation-pathless'); ?>
            </button>
            <div class="fp-scan-progress-bar-container">
                <div id="fp-scan-progress-bar" class="fp-scan-progress-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"></div>
            </div>
            <div id="fp-scan-status-text" class="fp-scan-status-text"></div>
        </div>

        <div class="fp-card">
            <h2 class="fp-card-title"><?php esc_html_e('Accessibility health', 'foundation-pathless'); ?></h2>
            <div class="fp-stats-grid">
                <div class="fp-stat-box">
                    <span class="fp-stat-value fp-stat-a11y"><?php echo number_format_i18n($summary['a11y']); ?></span>
                    <span class="fp-stat-label"><?php esc_html_e('Accessibility issues', 'foundation-pathless'); ?></span>
                </div>
                <div class="fp-stat-box">
                    <span class="fp-stat-value"><?php echo $summary['status'] === 'scanning' ? esc_html__('Live', 'foundation-pathless') : esc_html__('Ready', 'foundation-pathless'); ?></span>
                    <span class="fp-stat-label"><?php esc_html_e('Scanner status', 'foundation-pathless'); ?></span>
                </div>
            </div>
            <p class="description"><?php esc_html_e('Pathless flags vague anchor text such as “click here” or empty labels so accessibility issues are reviewed alongside broken links.', 'foundation-pathless'); ?></p>
        </div>
        <?php
        return ob_get_clean();
    }

    private static function get_analysis_markup(FP_List_Table $list_table) {
        ob_start();
        ?>
        <div class="fp-card">
            <h2 class="fp-card-title"><?php esc_html_e('Link analysis', 'foundation-pathless'); ?></h2>
            <form method="post">
                <?php wp_nonce_field('fp_bulk_action_nonce', 'fp_bulk_nonce'); ?>
                <input type="hidden" name="page" value="foundation-pathless-dashboard" />
                <?php $list_table->display(); ?>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }

    private static function get_settings_markup() {
        ob_start();
        ?>
        <div class="fp-card fp-settings">
            <h2 class="fp-card-title"><?php esc_html_e('Settings', 'foundation-pathless'); ?></h2>
            <form method="post" action="options.php">
                <?php settings_fields('fp_settings_group'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="fp_link_timeout"><?php esc_html_e('Link check timeout', 'foundation-pathless'); ?></label></th>
                        <td>
                            <input id="fp_link_timeout" type="number" min="1" name="fp_link_timeout" value="<?php echo esc_attr(get_option('fp_link_timeout', 20)); ?>" />
                            <p class="description"><?php esc_html_e('Seconds to wait for a response from a URL.', 'foundation-pathless'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="fp_a11y_blacklist"><?php esc_html_e('Accessibility blacklist', 'foundation-pathless'); ?></label></th>
                        <td>
                            <textarea id="fp_a11y_blacklist" name="fp_a11y_blacklist" rows="5" class="large-text"><?php echo esc_textarea(get_option('fp_a11y_blacklist', "click here\nlearn more\nread more")); ?></textarea>
                            <p class="description"><?php esc_html_e('One phrase per line. These phrases will be flagged as non-descriptive link text.', 'foundation-pathless'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Enable weekly scan', 'foundation-pathless'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="fp_enable_scheduled_scan" value="1" <?php checked(1, get_option('fp_enable_scheduled_scan'), true); ?> />
                                <?php esc_html_e('Run the link scanner automatically every week.', 'foundation-pathless'); ?>
                            </label>
                        </td>
                    </tr>
                </table>
                <?php submit_button(__('Save Pathless settings', 'foundation-pathless')); ?>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }

    public static function render_dashboard_page() {
        $list_table = new FP_List_Table();
        $list_table->prepare_items();
        $summary = self::get_dashboard_summary();
        ?>
        <div class="wrap foundation-admin-wrap">
            <div id="foundation-admin-app">
                <p><?php esc_html_e('Loading Foundation shell...', 'foundation-pathless'); ?></p>
            </div>
            <?php
            self::render_template('fp-pathless-operations', self::get_operations_markup($summary));
            self::render_template('fp-pathless-analysis', self::get_analysis_markup($list_table));
            self::render_template('fp-pathless-settings', self::get_settings_markup());
            ?>
        </div>
        <?php
    }
}
