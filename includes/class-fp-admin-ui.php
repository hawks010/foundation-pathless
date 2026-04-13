<?php
class FP_Admin_UI
{
    public static function init()
    {
        add_action('admin_menu', [self::class, 'add_admin_menu']);
        add_action('admin_init', [self::class, 'register_settings']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_admin_assets']);
    }

    public static function enqueue_admin_assets($hook)
    {
        $our_pages = [
            'foundation_page_foundation-pathless-dashboard',
            'toplevel_page_foundation-by-inkfire',
        ];
        if (!in_array($hook, $our_pages, true)) return;

        wp_enqueue_script('jquery');
        wp_localize_script('jquery', 'fp_ajax_object', [
            'ajax_url'  => admin_url('admin-ajax.php'),
            'rest_root' => esc_url_raw( rest_url( FP_Core::REST_NS . '/' ) ),
            'rest_nonce'=> wp_create_nonce('wp_rest'),
            'ajax_nonce'=> wp_create_nonce('fp_ajax_nonce'),
        ]);

        add_action('admin_head', [self::class, 'render_inline_admin_css']);
        add_action('admin_footer', [self::class, 'render_inline_admin_js']);
    }

    public static function render_inline_admin_css()
    {
        $plugin_url = defined('FP_PATHLESS_PLUGIN_URL') ? FP_PATHLESS_PLUGIN_URL : (defined('FP_PLUGIN_URL') ? FP_PLUGIN_URL : plugin_dir_url(__FILE__));
        ?>
        <style id="fp-admin-inline-css">
        #fp-dashboard-wrapper { --fp-bg:#fff; --fp-surface:#f6f7f7; --fp-border:#dcdcde; --fp-text:#1d2327; --fp-muted:#50575e; --fp-brand:#DF157C; --fp-accent:#179AD6; --fp-warn:#cc4d29; --fp-radius:14px; --fp-gap:16px; }
        #fp-dashboard-wrapper.fp-dark-mode { --fp-bg:#1f1f1f; --fp-surface:#2a2a2a; --fp-border:#3a3a3a; --fp-text:#f1f5f9; --fp-muted:#b1b5bb; }
        #fp-dashboard-wrapper, #fp-dashboard-wrapper h1, #fp-dashboard-wrapper h2 { all: revert; }
        #fp-dashboard-wrapper { background:var(--fp-bg); margin-top:12px; padding:12px; border:1px solid var(--fp-border); border-radius:var(--fp-radius); color:var(--fp-text); }
        #fp-dashboard-columns{display:grid;grid-template-columns:1fr 340px;gap:var(--fp-gap);}
        @media (max-width:1100px){#fp-dashboard-columns{grid-template-columns:1fr;}#fp-column-side{order:-1;}}
        .fp-card{background:var(--fp-surface);border:1px solid var(--fp-border);border-radius:var(--fp-radius);padding:14px;}
        .fp-card + .fp-card{margin-top:var(--fp-gap);}
        .fp-card-title{font-size:14px;font-weight:600;margin:0 0 10px;}
        .fp-stats-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:10px;margin-bottom:12px;}
        .fp-stat-box{background:var(--fp-bg);border:1px solid var(--fp-border);border-radius:10px;padding:10px;display:grid;gap:4px;}
        .fp-stat-value{font-size:18px;font-weight:700;color:var(--fp-text);}
        .fp-stat-broken{color:var(--fp-warn);}
        .fp-stat-a11y{color:var(--fp-accent);}
        .fp-scan-progress-bar-container{display:none;height:10px;background:var(--fp-bg);border:1px solid var(--fp-border);border-radius:999px;overflow:hidden;margin-top:10px;}
        .fp-scan-progress-bar{height:100%;width:0%;background:linear-gradient(90deg,var(--fp-brand),var(--fp-accent));transition:width .25s ease;}
        .fp-scan-status-text{margin-top:8px;font-size:12px;color:var(--fp-muted);}
        .fp-settings .form-table th{width:220px;}
        .fp-settings .form-table td .description{color:var(--fp-muted);}
        #fp-dashboard-wrapper .fp-header-logo{width:36px;height:36px;object-fit:contain;border-radius:8px;background:var(--fp-surface);border:1px solid var(--fp-border);content:url('<?php echo esc_url($plugin_url . 'assets/Foundation Logo-01.png'); ?>');}
        </style>
        <?php
    }

    public static function render_inline_admin_js() {
        ?>
        <script id="fp-inline-admin-js">
        jQuery(function($){
            // REST helpers
            function restGet(path, params){
                const url = fp_ajax_object.rest_root + path + (params ? ('?' + new URLSearchParams(params)) : '');
                return $.ajax({
                    url: url,
                    method: 'GET',
                    dataType: 'json',
                    beforeSend: function(xhr){ xhr.setRequestHeader('X-WP-Nonce', fp_ajax_object.rest_nonce); }
                });
            }

            // Fallback AJAX GET (likely blocked; kept for completeness)
            function ajaxGet(params){
                const base = fp_ajax_object.ajax_url;
                const qs = new URLSearchParams(Object.assign({}, params, {_wpnonce: fp_ajax_object.ajax_nonce})).toString();
                return $.ajax({ url: base + '?' + qs, method: 'GET', dataType: 'json' });
            }

            // UI elements
            const scanButton = $('#fp-trigger-scan');
            const barWrap = $('.fp-scan-progress-bar-container');
            const bar = $('#fp-scan-progress-bar');
            const status = $('#fp-scan-status-text');
            let timer;

            function showProgress(d){
                if(d.status === 'scanning'){
                    scanButton.prop('disabled', true).text('Scan in Progress…');
                    barWrap.show();
                    const p = Math.max(0, Math.min(100, parseInt(d.progress||0,10)));
                    bar.css('width', p+'%').text(p+'%');
                    status.text('Scanned '+(d.scanned||0)+' of '+(d.total||0)+' items.');
                } else {
                    bar.css('width','100%').text('100%');
                    status.text('Scan complete. Reloading…');
                    clearInterval(timer);
                    setTimeout(function(){ location.reload(); }, 900);
                }
            }

            function start(){ // try REST, then AJAX fallback
                scanButton.prop('disabled', true).text('Initiating Scan…');
                status.text('');
                restGet('start').done(function(r){
                    if(r && (r.ok || r.status || r.progress !== undefined)) { barWrap.show(); poll(); timer=setInterval(poll, 5000); }
                    else { status.text('Failed to start scan (REST).'); scanButton.prop('disabled', false).text('Start New Scan'); }
                }).fail(function(){
                    ajaxGet({action:'fp_start_scan'}).done(function(r){
                        if(r && (r.ok || r.status || r.progress !== undefined)) { barWrap.show(); poll(); timer=setInterval(poll, 5000); }
                        else { status.text('Failed to start scan (AJAX).'); scanButton.prop('disabled', false).text('Start New Scan'); }
                    }).fail(function(xhr){ status.text('Network error starting scan: '+xhr.status+' '+xhr.statusText); scanButton.prop('disabled', false).text('Start New Scan'); });
                });
            }

            function poll(){
                restGet('status').done(function(r){ if(r) showProgress(r); else { status.text('Error checking status (REST).'); clearInterval(timer); scanButton.prop('disabled', false).text('Start New Scan'); }})
                .fail(function(){
                    ajaxGet({action:'fp_check_scan_status'}).done(function(r){ if(r && r.data) showProgress(r.data); else { status.text('Error checking status (AJAX).'); clearInterval(timer); scanButton.prop('disabled', false).text('Start New Scan'); }})
                    .fail(function(xhr){ status.text('Network error checking status: '+xhr.status+' '+xhr.statusText); clearInterval(timer); scanButton.prop('disabled', false).text('Start New Scan'); });
                });
            }

            if (scanButton.length && scanButton.is(':disabled')) { poll(); timer=setInterval(poll, 5000); }
            scanButton.on('click', function(e){ e.preventDefault(); start(); });

            // Dismiss row via REST (fallback AJAX)
            $('#fp-column-main').on('click','.fp-action-link[data-action="dismiss"]',function(e){
                e.preventDefault();
                const $btn=$(this).css('opacity',.5), id=$btn.data('link-id'), $row=$btn.closest('tr');
                restGet('dismiss', {id:id}).done(function(r){ if(r && r.dismissed) $row.fadeOut(200,()=>$(this).remove()); else $btn.css('opacity',1); })
                .fail(function(){ ajaxGet({action:'fp_dismiss_link',link_id:id}).done(function(r){ if(r&&r.success) $row.fadeOut(200,()=>$(this).remove()); else $btn.css('opacity',1); }).fail(function(){ $btn.css('opacity',1); }); });
            });

            // theme toggle
            const theme=document.getElementById('fp-dashboard-theme-switch');
            const wrap=document.getElementById('fp-dashboard-wrapper');
            if(theme&&wrap){
                const key='fp-theme-preference';
                const saved=(()=>{try{return localStorage.getItem(key);}catch(e){return null;}})();
                const prefers=window.matchMedia&&window.matchMedia('(prefers-color-scheme: dark)').matches;
                const apply=(d)=>{wrap.classList.toggle('fp-dark-mode',!!d); theme.checked=!!d;};
                apply(saved ? saved==='dark' : prefers);
                theme.addEventListener('change',()=>{apply(theme.checked); try{localStorage.setItem(key, theme.checked?'dark':'light');}catch(e){}});
            }
        });
        </script>
        <?php
    }

    public static function add_admin_menu()
    {
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

        // Settings are embedded; no separate submenu.
        remove_submenu_page($parent_slug, 'fp-settings');
    }

    public static function register_settings() {
        register_setting('fp_settings_group', 'fp_link_timeout');
        register_setting('fp_settings_group', 'fp_a11y_blacklist');
        register_setting('fp_settings_group', 'fp_enable_scheduled_scan', [
            'type' => 'boolean',
            'sanitize_callback' => 'rest_sanitize_boolean'
        ]);
    }

    public static function render_dashboard_page()
    {
        $list_table = new FP_List_Table();
        $list_table->prepare_items();

        global $wpdb;
        $table   = $wpdb->prefix . 'fp_links';
        $total   = (int) $wpdb->get_var("SELECT COUNT(id) FROM $table WHERE dismissed = 0");
        $broken  = (int) $wpdb->get_var("SELECT COUNT(id) FROM $table WHERE dismissed = 0 AND link_status = 'broken'");
        $a11y    = (int) $wpdb->get_var("SELECT COUNT(id) FROM $table WHERE dismissed = 0 AND accessibility_issues != ''");
        $status  = get_option('fp_scan_status', 'idle');
        ?>
        <div class="wrap" id="fp-dashboard-wrapper" aria-live="polite">
            <div class="fp-header">
                <img class="fp-header-logo" alt="<?php esc_attr_e('Foundation Logo', 'foundation-pathless'); ?>">
                <div class="fp-branding-text">
                    <h1 class="fp-card-title" style="font-size:18px"><?php esc_html_e('Foundation: Pathless', 'foundation-pathless'); ?></h1>
                    <p class="fp-byline"><?php esc_html_e('A Foundation Plugin by Inkfire Limited. Make every connection count.', 'foundation-pathless'); ?></p>
                    <div class="fp-theme-toggle" style="margin-top:6px">
                        <label for="fp-dashboard-theme-switch"><?php esc_html_e('Dark Mode', 'foundation-pathless'); ?></label>
                        <label class="fp-switch"><input type="checkbox" id="fp-dashboard-theme-switch" aria-label="<?php esc_attr_e('Toggle Dark Mode', 'foundation-pathless'); ?>" /><span class="fp-toggle-slider"></span></label>
                    </div>
                </div>
            </div>

            <div id="fp-dashboard-columns">
                <div id="fp-column-side">
                    <div class="fp-card">
                        <h2 class="fp-card-title"><?php esc_html_e('Overall Health', 'foundation-pathless'); ?></h2>
                        <div class="fp-stats-grid">
                            <div class="fp-stat-box"><span class="fp-stat-value"><?php echo number_format_i18n($total); ?></span><span class="fp-stat-label"><?php esc_html_e('Links Monitored', 'foundation-pathless'); ?></span></div>
                            <div class="fp-stat-box"><span class="fp-stat-value fp-stat-broken"><?php echo number_format_i18n($broken); ?></span><span class="fp-stat-label"><?php esc_html_e('Broken Links', 'foundation-pathless'); ?></span></div>
                        </div>
                        <button id="fp-trigger-scan" class="button button-primary button-large" <?php disabled($status, 'scanning'); ?>>
                            <?php echo $status === 'scanning' ? esc_html__('Scan in Progress…', 'foundation-pathless') : esc_html__('Start New Scan', 'foundation-pathless'); ?>
                        </button>
                        <div class="fp-scan-progress-bar-container"><div id="fp-scan-progress-bar" class="fp-scan-progress-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"></div></div>
                        <div id="fp-scan-status-text" class="fp-scan-status-text"></div>
                    </div>

                    <div class="fp-card">
                        <h2 class="fp-card-title"><?php esc_html_e('Accessibility', 'foundation-pathless'); ?></h2>
                        <div class="fp-stats-grid">
                            <div class="fp-stat-box"><span class="fp-stat-value fp-stat-a11y"><?php echo number_format_i18n($a11y); ?></span><span class="fp-stat-label"><?php esc_html_e('Accessibility Issues', 'foundation-pathless'); ?></span></div>
                        </div>
                        <p class="description"><?php esc_html_e('Finds links with vague text like “click here” or empty link text that are difficult for screen readers.', 'foundation-pathless'); ?></p>
                    </div>
                </div>

                <div id="fp-column-main">
                    <div class="fp-card">
                        <h2 class="fp-card-title"><?php esc_html_e('Link Analysis', 'foundation-pathless'); ?></h2>
                        <form method="post">
                            <?php wp_nonce_field('fp_bulk_action_nonce', 'fp_bulk_nonce'); ?>
                            <input type="hidden" name="page" value="foundation-pathless-dashboard" />
                            <?php $list_table->display(); ?>
                        </form>
                    </div>

                    <div class="fp-card fp-settings">
                        <h2 class="fp-card-title"><?php esc_html_e('Settings', 'foundation-pathless'); ?></h2>
                        <form method="post" action="options.php">
                            <?php settings_fields('fp_settings_group'); ?>
                            <table class="form-table" role="presentation">
                                <tr>
                                    <th scope="row"><label for="fp_link_timeout"><?php esc_html_e('Link Check Timeout', 'foundation-pathless'); ?></label></th>
                                    <td>
                                        <input id="fp_link_timeout" type="number" min="1" name="fp_link_timeout" value="<?php echo esc_attr(get_option('fp_link_timeout', 20)); ?>" />
                                        <p class="description"><?php esc_html_e('Seconds to wait for a response from a URL.', 'foundation-pathless'); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="fp_a11y_blacklist"><?php esc_html_e('Accessibility Blacklist', 'foundation-pathless'); ?></label></th>
                                    <td>
                                        <textarea id="fp_a11y_blacklist" name="fp_a11y_blacklist" rows="5" class="large-text"><?php echo esc_textarea(get_option('fp_a11y_blacklist', "click here\nlearn more\nread more")); ?></textarea>
                                        <p class="description"><?php esc_html_e('One phrase per line. These phrases will be flagged as non-descriptive link text.', 'foundation-pathless'); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php esc_html_e('Enable Weekly Scan', 'foundation-pathless'); ?></th>
                                    <td>
                                        <label>
                                            <input type="checkbox" name="fp_enable_scheduled_scan" value="1" <?php checked(1, get_option('fp_enable_scheduled_scan'), true); ?> />
                                            <?php esc_html_e('Run the link scanner automatically every week.', 'foundation-pathless'); ?>
                                        </label>
                                    </td>
                                </tr>
                            </table>
                            <?php submit_button(); ?>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
}
