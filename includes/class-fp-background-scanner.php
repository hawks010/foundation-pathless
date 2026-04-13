<?php
if (!defined('ABSPATH')) exit;

/**
 * Foundation Pathless — Background Scanner
 *
 * - Queues all public posts/pages (you can extend post types).
 * - Extracts <a> links from post_content.
 * - Uses FP_Scanner and FP_Accessibility_Checker.
 * - Writes to wp_fp_links (schema used elsewhere in the plugin).
 * - Maintains fp_scan_* options for UI progress.
 */
if ( ! class_exists('FP_Background_Scanner') ):

class FP_Background_Scanner extends WP_Background_Process
{
    /** Identifier prefix used by the vendor WP_Background_Process */
    protected $prefix = 'fp';

    /** Unique action id for AJAX hook */
    protected $action = 'pathless_scan';

    /** Throttle between batch items (seconds) – keep zero for speed on hosts/WAFs */
    protected $cron_interval = 1; // healthcheck interval minutes

    /** Batch size for queueing posts */
    const POSTS_PER_BATCH = 25;

    /** Post types to scan */
    protected $post_types = ['post','page'];

    /**
     * Public entrypoint used by FP_Core.
     */
    public function queue_full_site_scan()
    {
        // 1) Gather all published posts/pages (IDs only)
        $ids = $this->get_all_post_ids($this->post_types);

        // Save total for progress UI
        update_option('fp_scan_total', count($ids), false);
        update_option('fp_scan_scanned', 0, false);
        update_option('fp_scan_progress', 0, false);
        update_option('fp_scan_status', 'scanning', false);

        // 2) Chunk and push to queue
        foreach (array_chunk($ids, self::POSTS_PER_BATCH) as $chunk) {
            $this->push_to_queue([
                'type' => 'posts',
                'ids'  => $chunk,
            ]);
        }

        // Persist batches
        $this->save();

        // 3) Kick it off (may be blocked by WAF; FP_Core also nudges via status polling)
        $this->dispatch();
    }

    /**
     * Used by FP_Core polling path when async loopbacks are restricted.
     * Allows “tick” processing on each status check.
     */
    public function process_one_batch()
    {
        // Emulate a single handle() pass over (part of) a batch without AJAX dispatch.
        $batch = $this->get_batch();
        if (empty($batch) || empty($batch->data)) {
            // Nothing to do — if queue empty, mark complete
            if ($this->is_queue_empty()) $this->complete_scan();
            return;
        }

        // Process at most one post per poll tick to keep it snappy
        $item = reset($batch->data);
        $key  = key($batch->data);

        $result = $this->task($item);

        if (false !== $result) {
            $batch->data[$key] = $result;
        } else {
            unset($batch->data[$key]);
        }

        // Update or delete batch
        if (!empty($batch->data)) {
            $this->update($batch->key, $batch->data);
        } else {
            $this->delete($batch->key);
        }

        if ($this->is_queue_empty()) {
            $this->complete_scan();
        }
    }

    /**
     * Perform work for each queued item.
     * An item is an array: ['type' => 'posts', 'ids' => [..]]
     */
    protected function task($item)
    {
        if (!is_array($item) || empty($item['type'])) {
            return false; // drop invalid
        }

        if ($item['type'] === 'posts' && !empty($item['ids']) && is_array($item['ids'])) {
            // Process one post ID per call (keeps memory low; UI polls every few seconds)
            $post_id = array_shift($item['ids']);
            if ($post_id) {
                $this->scan_single_post((int)$post_id);
                $this->bump_progress();
            }

            // If there are more IDs left in this batch item, return it to continue later
            if (!empty($item['ids'])) {
                return $item;
            }
        }

        // Batch item fully processed
        return false;
    }

    /**
     * Called by the vendor base when all batches complete (async path).
     */
    protected function complete()
    {
        parent::complete();
        $this->complete_scan();
    }

    /* ---------------------------------------------------------------------
     * Internals
     * ------------------------------------------------------------------ */

    private function get_all_post_ids(array $types): array
    {
        $q = new WP_Query([
            'post_type'      => $types,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'orderby'        => 'ID',
            'order'          => 'DESC',
        ]);
        return $q->posts ? array_map('intval', $q->posts) : [];
    }

    private function scan_single_post(int $post_id): void
    {
        $post = get_post($post_id);
        if (!$post) return;

        $html   = (string) $post->post_content;
        $links  = $this->extract_links_from_html($html);

        if (empty($links)) {
            // Still mark as scanned
            $this->mark_post_scanned_meta($post_id, 0);
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'fp_links';

        foreach ($links as $lnk) {
            $url  = $lnk['url'];
            $text = $lnk['text'];
            $src  = $lnk['source'];

            // Run checks
            $scan   = FP_Scanner::check($url);
            $a11y   = FP_Accessibility_Checker::check_link($text);

            // Upsert-ish: delete any previous row for this post+url to avoid dupes
            $wpdb->query(
                $wpdb->prepare("DELETE FROM {$table} WHERE post_id = %d AND url = %s", $post_id, $url)
            );

            $wpdb->insert(
                $table,
                [
                    'post_id'             => $post_id,
                    'url'                 => $url,
                    'source'              => $src,
                    'link_status'         => $scan['status'],     // ok|broken|restricted
                    'http_code'           => (int) $scan['http_code'],
                    'redirect_to'         => $scan['redirect_to'],
                    'accessibility_issues'=> $a11y,
                    'dismissed'           => 0,
                    'last_checked'        => current_time('mysql'),
                ],
                [
                    '%d','%s','%s','%s','%d','%s','%s','%d','%s'
                ]
            );
        }

        $this->mark_post_scanned_meta($post_id, count($links));
    }

    private function extract_links_from_html(string $html): array
    {
        $out = [];

        if (trim($html) === '') return $out;

        // Fast path: use DOMDocument but be defensive with malformed HTML
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $loaded = $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();

        if ($loaded) {
            $anchors = $dom->getElementsByTagName('a');
            foreach ($anchors as $a) {
                $href = $a->getAttribute('href');
                if (!$href) continue;
                // Only http/https links
                if (!preg_match('#^https?://#i', $href)) continue;

                $text = trim($a->textContent);
                $out[] = [
                    'url'    => $href,
                    'text'   => $text,
                    'source' => 'post_content',
                ];
            }
        }

        return $out;
    }

    private function bump_progress(): void
    {
        $scanned = (int) get_option('fp_scan_scanned', 0) + 1;
        $total   = (int) get_option('fp_scan_total', 0);
        $progress = $total > 0 ? (int) floor(($scanned / $total) * 100) : 0;

        update_option('fp_scan_scanned', $scanned, false);
        update_option('fp_scan_progress', min(100, max(0, $progress)), false);
    }

    private function complete_scan(): void
    {
        update_option('fp_scan_progress', 100, false);
        update_option('fp_scan_status', 'idle', false);
    }

    private function mark_post_scanned_meta(int $post_id, int $count): void
    {
        // Optional: mark per‑post last scanned for debugging/audits
        update_post_meta($post_id, '_fp_last_link_count', $count);
        update_post_meta($post_id, '_fp_last_scanned', time());
    }
}

endif;
