<?php
/**
 * Class FP_Scanner
 *
 * Link checker with redirect capture and resilient timeouts.
 */
if (!defined('ABSPATH')) exit;

class FP_Scanner
{
    /**
     * Check a URL and return normalized result.
     *
     * @param string $url
     * @return array{status:string,http_code:int,redirect_to:string,message:string}
     */
    public static function check(string $url): array
    {
        $timeout = (int) get_option('fp_link_timeout', 20);
        $ua      = 'Foundation-Pathless/' . (defined('FP_VERSION') ? FP_VERSION : '1.0');

        $args = [
            'timeout'    => $timeout,
            'redirection'=> 5,
            'user-agent' => $ua,
            'sslverify'  => true,
        ];

        // Prefer HEAD for speed; fall back to GET if blocked.
        $resp = wp_remote_head($url, $args);
        if (is_wp_error($resp) || (int) wp_remote_retrieve_response_code($resp) === 405) {
            $resp = wp_remote_get($url, $args);
        }

        if (is_wp_error($resp)) {
            return [
                'status'      => 'broken',
                'http_code'   => 0,
                'redirect_to' => '',
                'message'     => $resp->get_error_message(),
            ];
        }

        $code = (int) wp_remote_retrieve_response_code($resp);
        $loc  = '';
        // Try to capture effective final location if present
        $headers = wp_remote_retrieve_headers($resp);
        if (!empty($headers['location'])) {
            $loc = is_array($headers['location']) ? end($headers['location']) : (string) $headers['location'];
        }

        $status = 'broken';
        if ($code >= 200 && $code < 300)       $status = 'ok';
        elseif ($code >= 300 && $code < 400)   $status = 'redirect';

        return [
            'status'      => $status,
            'http_code'   => $code,
            'redirect_to' => $loc,
            'message'     => wp_remote_retrieve_response_message($resp) ?: '',
        ];
    }
}
