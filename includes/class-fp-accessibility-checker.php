<?php
if (!defined('ABSPATH')) exit;

class FP_Accessibility_Checker
{
    /**
     * Check link text for common accessibility issues.
     *
     * @param string $text   Visible link text (HTML allowed; will be stripped).
     * @param array  $ctx    Optional context (e.g., ['aria_label' => '...'])
     * @return string        Comma-separated issues or empty string if none.
     */
    public static function check_link($text, array $ctx = [])
    {
        $issues = [];

        // 1) Normalize text
        $clean = wp_strip_all_tags((string) $text, true);
        $clean = html_entity_decode($clean, ENT_QUOTES | ENT_HTML5, get_bloginfo('charset') ?: 'UTF-8');
        $clean = preg_replace('/\s+/u', ' ', $clean);
        $clean = trim($clean);

        $lower = mb_strtolower($clean);

        // 2) Pull blacklist from settings
        $raw = (string) get_option('fp_a11y_blacklist', "click here\nlearn more\nread more");
        $list = array_filter(array_map('trim', preg_split('/\R/u', mb_strtolower($raw))));
        $list = array_unique($list);

        // Helper: word-boundary contains
        $contains_phrase = function(string $haystack, string $needle): bool {
            if ($needle === '') return false;
            // Escape regex chars and add word boundaries around words
            $pattern = '/\b' . preg_quote($needle, '/') . '\b/u';
            return (bool) preg_match($pattern, $haystack);
        };

        // 3) Empty text
        if ($lower === '') {
            $issues[] = 'empty_link_text';
        }

        // 4) “Raw URL” as text (e.g., https://example.com)
        if ($lower !== '' && preg_match('#^https?://#i', $clean)) {
            $issues[] = 'url_used_as_link_text';
        }

        // 5) Only numbers / punctuation (e.g., “>>”, “123”)
        if ($lower !== '' && !preg_match('/[a-z]/iu', $lower) && preg_match('/[0-9\p{P}\s]+/u', $lower)) {
            $issues[] = 'non_descriptive_symbols_or_numbers';
        }

        // 6) Very short text (e.g., “Go”, “Here”)
        if ($lower !== '' && mb_strlen($lower) < 3) {
            $issues[] = 'very_short_link_text';
        }

        // 7) Blacklist exact match OR contains (word-boundary)
        foreach ($list as $phrase) {
            if ($phrase === '') continue;
            if ($lower === $phrase || $contains_phrase($lower, $phrase)) {
                $issues[] = 'non_descriptive_text:' . $phrase;
            }
        }

        // 8) Generic terms commonly problematic
        $generic = ['here','more','this','link','click','read','learn'];
        foreach ($generic as $g) {
            if ($contains_phrase($lower, $g)) {
                $issues[] = 'generic_word_present:' . $g;
            }
        }

        // 9) Consider aria-label/title as mitigation (optional context)
        $aria = isset($ctx['aria_label']) ? trim((string) $ctx['aria_label']) : '';
        $title = isset($ctx['title']) ? trim((string) $ctx['title']) : '';
        if (!empty($aria) || !empty($title)) {
            // If we flagged only generic/short issues but ARIA/Title exists, downgrade by removing "very_short_link_text"
            if (!empty($issues)) {
                $issues = array_values(array_filter($issues, function($i){ return $i !== 'very_short_link_text'; }));
            }
        }

        // Return a simple string for storage; UI can translate tokens
        return implode(', ', array_unique($issues));
    }
}
