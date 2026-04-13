<?php
if ( ! class_exists('WP_List_Table') ) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class FP_List_Table extends WP_List_Table
{
    public function __construct() {
        parent::__construct([
            'singular' => __('Link', 'foundation-pathless'),
            'plural'   => __('Links', 'foundation-pathless'),
            'ajax'     => false,
        ]);
    }

    /* ---------------------------------------------------------------------
     * Columns
     * ------------------------------------------------------------------ */

    public function get_columns() {
        return [
            'cb'      => '<input type="checkbox" />',
            'url'     => __('URL', 'foundation-pathless'),
            'status'  => __('Status', 'foundation-pathless'),
            'source'  => __('Source', 'foundation-pathless'),
            'checked' => __('Last Checked', 'foundation-pathless'),
        ];
    }

    public function get_sortable_columns() {
        // map display column to actual DB column
        return [
            'url'     => ['url', false],
            'status'  => ['http_code', false],
            'source'  => ['source', false],
            'checked' => ['last_checked', false],
        ];
    }

    protected function get_bulk_actions() {
        return [
            'bulk_dismiss' => __('Dismiss', 'foundation-pathless'),
        ];
    }

    /* ---------------------------------------------------------------------
     * Data
     * ------------------------------------------------------------------ */

    public function prepare_items() {
        global $wpdb;
        $table = $wpdb->prefix . 'fp_links';

        $per_page     = 20;
        $current_page = $this->get_pagenum();

        // Headers must be set before items
        $columns  = $this->get_columns();
        $hidden   = [];
        $sortable = $this->get_sortable_columns();
        $this->_column_headers = [ $columns, $hidden, $sortable ];

        // Handle bulk action first
        $this->process_bulk_action();

        // Whitelist ordering
        $orderby_allowed = ['id','url','http_code','link_status','source','last_checked'];
        $orderby = isset($_GET['orderby']) ? sanitize_key($_GET['orderby']) : 'id';
        if ($orderby === 'status') $orderby = 'http_code';
        if ( ! in_array($orderby, $orderby_allowed, true) ) $orderby = 'id';

        $order = (isset($_GET['order']) && strtolower($_GET['order']) === 'asc') ? 'ASC' : 'DESC';

        // Count total
        $total_items = (int) $wpdb->get_var("SELECT COUNT(id) FROM {$table} WHERE dismissed = 0");

        // Fetch page items
        $offset = ($current_page - 1) * $per_page;
        // NOTE: identifiers can’t be prepared; we whitelist them above
        $sql = $wpdb->prepare(
            "SELECT id, post_id, url, source, link_status, http_code, redirect_to, accessibility_issues, last_checked
             FROM {$table}
             WHERE dismissed = 0
             ORDER BY {$orderby} {$order}
             LIMIT %d OFFSET %d",
            $per_page, $offset
        );
        $this->items = $wpdb->get_results($sql, ARRAY_A);

        // Pagination
        $this->set_pagination_args([
            'total_items' => $total_items,
            'per_page'    => $per_page,
        ]);
    }

    /* ---------------------------------------------------------------------
     * Column renderers
     * ------------------------------------------------------------------ */

    public function column_cb($item) {
        return sprintf('<input type="checkbox" name="link_id[]" value="%d" />', (int) $item['id']);
    }

    public function column_url($item) {
        $url = (string) $item['url'];
        $display = urldecode($url);

        $actions = [
            'dismiss' => sprintf(
                '<a href="#" class="fp-action-link" data-action="dismiss" data-link-id="%d">%s</a>',
                (int) $item['id'],
                esc_html__('Dismiss', 'foundation-pathless')
            ),
        ];

        $a11y_html = '';
        if ( ! empty($item['accessibility_issues']) ) {
            $a11y_html = sprintf(
                ' <span class="fp-accessibility-issue-badge" title="%s">A11Y</span>',
                esc_attr($item['accessibility_issues'])
            );
        }

        $redirect = '';
        if ( ! empty($item['redirect_to']) ) {
            $redirect = sprintf(
                '<div><small>%s <code>%s</code></small></div>',
                esc_html__('Redirects to:', 'foundation-pathless'),
                esc_html($item['redirect_to'])
            );
        }

        return sprintf(
            '<div><a href="%s" target="_blank" rel="noopener noreferrer" title="%s">%s</a>%s %s</div>%s',
            esc_url($url),
            esc_attr($url),
            esc_html($display),
            $a11y_html,
            $this->row_actions($actions),
            $redirect
        );
    }

    public function column_status($item) {
        $code   = (int) $item['http_code'];
        $state  = $item['link_status'] ?: 'unknown';

        $cls = 'unknown';
        if ($state === 'ok')          $cls = 'ok';
        elseif ($state === 'broken')  $cls = 'broken';
        elseif ($state === 'restricted') $cls = 'restricted';

        $label = $code ? $code : strtoupper($state);
        $title = sprintf('%s (%s)', $state, $code ?: '—');

        return sprintf('<span class="fp-status-badge fp-status-%s" title="%s">%s</span>',
            esc_attr($cls),
            esc_attr($title),
            esc_html($label)
        );
    }

    public function column_source($item) {
        $post_id = (int) $item['post_id'];
        if ($post_id > 0) {
            $title = get_the_title($post_id);
            if (!$title) $title = __('(No Title)', 'foundation-pathless');
            $link  = get_edit_post_link($post_id);
            return sprintf('<a href="%s">%s</a><br><small>%s</small>',
                esc_url($link),
                esc_html($title),
                esc_html($item['source'] ?: 'post_content')
            );
        }
        return esc_html($item['source'] ?: __('Unknown', 'foundation-pathless'));
    }

    public function column_checked($item) {
        if (empty($item['last_checked']) || $item['last_checked'] === '0000-00-00 00:00:00') {
            return '—';
        }
        $t = strtotime($item['last_checked']);
        return esc_html( date_i18n( get_option('date_format') . ' ' . get_option('time_format'), $t ) );
    }

    public function column_default($item, $column_name) {
        return isset($item[$column_name]) ? esc_html( (string) $item[$column_name] ) : '—';
    }

    /* ---------------------------------------------------------------------
     * Bulk actions
     * ------------------------------------------------------------------ */

    public function process_bulk_action() {
        if ( 'bulk_dismiss' !== $this->current_action() ) return;

        // nonce check
        if ( ! isset($_POST['fp_bulk_nonce']) || ! wp_verify_nonce($_POST['fp_bulk_nonce'], 'fp_bulk_action_nonce') ) {
            wp_die(__('Security check failed.', 'foundation-pathless'));
        }

        $ids = isset($_POST['link_id']) ? array_map('absint', (array) $_POST['link_id']) : [];
        if (empty($ids)) return;

        self::bulk_dismiss_links($ids);
    }

    public static function bulk_dismiss_links(array $ids) {
        global $wpdb;
        $table = $wpdb->prefix . 'fp_links';
        $ids   = array_filter(array_map('absint', $ids));
        if (empty($ids)) return;

        // Build placeholders for IN()
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $sql = "UPDATE {$table} SET dismissed = 1 WHERE id IN ($placeholders)";
        $wpdb->query( $wpdb->prepare($sql, $ids) );
    }
}
