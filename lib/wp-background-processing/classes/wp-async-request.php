<?php
/**
 * WP Async Request (Safe Wrapped)
 *
 * @package WP-Background-Processing
 */

// Only declare if not already loaded by another plugin.
if ( ! class_exists( 'WP_Async_Request', false ) ) {

	/**
	 * Abstract WP_Async_Request class.
	 *
	 * Base class for handling async AJAX requests.
	 */
	abstract class WP_Async_Request {

		/**
		 * Prefix
		 *
		 * @var string
		 */
		protected $prefix = 'wp';

		/**
		 * Action
		 *
		 * @var string
		 */
		protected $action = 'async_request';

		/**
		 * Identifier
		 *
		 * @var string
		 */
		protected $identifier;

		/**
		 * Data
		 *
		 * @var array
		 */
		protected $data = array();

		/**
		 * Initiate new async request.
		 */
		public function __construct() {
			$this->identifier = $this->prefix . '_' . $this->action;

			add_action( 'wp_ajax_' . $this->identifier, array( $this, 'maybe_handle' ) );
			add_action( 'wp_ajax_nopriv_' . $this->identifier, array( $this, 'maybe_handle' ) );
		}

		/**
		 * Set data used during the request.
		 *
		 * @param array $data Data.
		 * @return $this
		 */
		public function data( $data ) {
			$this->data = $data;
			return $this;
		}

		/**
		 * Dispatch the async request.
		 *
		 * @return array|WP_Error|false HTTP Response array, WP_Error on failure, or false if not attempted.
		 */
		public function dispatch() {
			$url  = add_query_arg( $this->get_query_args(), $this->get_query_url() );
			$args = $this->get_post_args();

			return wp_remote_post( esc_url_raw( $url ), $args );
		}

		/**
		 * Get query args.
		 *
		 * @return array
		 */
		protected function get_query_args() {
			if ( property_exists( $this, 'query_args' ) ) {
				return $this->query_args;
			}

			$args = array(
				'action' => $this->identifier,
				'nonce'  => wp_create_nonce( $this->identifier ),
			);

			return apply_filters( $this->identifier . '_query_args', $args );
		}

		/**
		 * Get query URL.
		 *
		 * @return string
		 */
		protected function get_query_url() {
			if ( property_exists( $this, 'query_url' ) ) {
				return $this->query_url;
			}

			$url = admin_url( 'admin-ajax.php' );
			return apply_filters( $this->identifier . '_query_url', $url );
		}

		/**
		 * Get post args.
		 *
		 * @return array
		 */
		protected function get_post_args() {
			if ( property_exists( $this, 'post_args' ) ) {
				return $this->post_args;
			}

			$args = array(
				'timeout'   => 5,
				'blocking'  => false,
				'body'      => $this->data,
				// Usually not needed for background link scans. Override if required.
				//'cookies'   => $_COOKIE,
				'sslverify' => apply_filters( 'https_local_ssl_verify', true ), // Secure default.
			);

			return apply_filters( $this->identifier . '_post_args', $args );
		}

		/**
		 * Maybe handle a dispatched request.
		 *
		 * @return void|mixed
		 */
		public function maybe_handle() {
			// Don't lock up other requests while processing.
			if ( function_exists( 'session_write_close' ) && session_status() === PHP_SESSION_ACTIVE ) {
				session_write_close();
			}

			check_ajax_referer( $this->identifier, 'nonce' );

			$this->handle();

			return $this->maybe_wp_die();
		}

		/**
		 * Should the process exit with wp_die?
		 *
		 * @param mixed $return What to return if filter says don't die.
		 * @return void|mixed
		 */
		protected function maybe_wp_die( $return = null ) {
			if ( apply_filters( $this->identifier . '_wp_die', true ) ) {
				wp_die();
			}
			return $return;
		}

		/**
		 * Handle a dispatched request.
		 *
		 * Override this method to perform any actions required.
		 */
		abstract protected function handle();
	}
}
