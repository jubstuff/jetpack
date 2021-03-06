<?php

require_once dirname( __FILE__ ) .  '/class.jetpack-sync-functions.php';

class Jetpack_Sync_Module_Callables extends Jetpack_Sync_Module {
	const CALLABLES_CHECKSUM_OPTION_NAME = 'jetpack_callables_sync_checksum';
	const CALLABLES_AWAIT_TRANSIENT_NAME = 'jetpack_sync_callables_await';

	private $callable_whitelist;

	public function name() {
		return "callables";
	}

	public function set_defaults() {
		if ( is_multisite() ) {
			$this->callable_whitelist = array_merge( Jetpack_Sync_Defaults::$default_callable_whitelist, Jetpack_Sync_Defaults::$default_multisite_callable_whitelist );
		} else {
			$this->callable_whitelist = Jetpack_Sync_Defaults::$default_callable_whitelist;
		}
	}

	public function init_listeners( $callable ) {
		add_action( 'jetpack_sync_callable', $callable, 10, 2 );

		// full sync
		add_action( 'jetpack_full_sync_callables', $callable );

		// get_plugins and wp_version
		// gets fired when new code gets installed, updates etc.
		add_action( 'upgrader_process_complete', array( $this, 'force_sync_callables' ) );
	}

	public function init_before_send() {
		add_action( 'jetpack_sync_before_send', array( $this, 'maybe_sync_callables' ) );
	}

	public function reset_data() {
		delete_option( self::CALLABLES_CHECKSUM_OPTION_NAME );
		delete_transient( self::CALLABLES_AWAIT_TRANSIENT_NAME );
	}

	function set_callable_whitelist( $callables ) {
		$this->callable_whitelist = $callables;
	}

	function get_callable_whitelist() {
		return $this->callable_whitelist;
	}

	public function get_all_callables() {
		// get_all_callables should run as the master user always.
		$current_user_id = get_current_user_id();
		wp_set_current_user( Jetpack_Options::get_option( 'master_user' ) );
		$callables = array_combine(
			array_keys( $this->callable_whitelist ),
			array_map( array( $this, 'get_callable' ), array_values( $this->callable_whitelist ) )
		);
		wp_set_current_user( $current_user_id );
		return $callables;
	}

	private function get_callable( $callable ) {
		return call_user_func( $callable );
	}

	public function full_sync() {
		/**
		 * Tells the client to sync all callables to the server
		 *
		 * @since 4.2
		 *
		 * @param boolean Whether to expand callables (should always be true)
		 */
		do_action( 'jetpack_full_sync_callables', true );
		return 1; // The number of actions enqueued
	}

	public function force_sync_callables() {
		delete_option( self::CALLABLES_CHECKSUM_OPTION_NAME );
		delete_transient( self::CALLABLES_AWAIT_TRANSIENT_NAME );
		$this->maybe_sync_callables();
	}

	public function maybe_sync_callables() {
		if ( get_transient( self::CALLABLES_AWAIT_TRANSIENT_NAME ) ) {
			return;
		}
		$callables = $this->get_all_callables();
		
		if ( empty( $callables ) ) {
			return;
		}

		set_transient( self::CALLABLES_AWAIT_TRANSIENT_NAME, microtime( true ), Jetpack_Sync_Defaults::$default_sync_callables_wait_time );

		$callable_checksums = (array) get_option( self::CALLABLES_CHECKSUM_OPTION_NAME , array() );

		// only send the callables that have changed
		foreach ( $callables as $name => $value ) {
			$checksum = $this->get_check_sum( $value );
			// explicitly not using Identical comparison as get_option returns a string
			if ( ! $this->still_valid_checksum( $callable_checksums, $name, $checksum ) && ! is_null( $value ) ) {
				/**
				 * Tells the client to sync a callable (aka function) to the server
				 *
				 * @since 4.2.0
				 *
				 * @param string The name of the callable
				 * @param mixed The value of the callable
				 */
				do_action( 'jetpack_sync_callable', $name, $value );
				$callable_checksums[ $name ] = $checksum;
			} else {
				$callable_checksums[ $name ] = $checksum;
			}
		}
		update_option( self::CALLABLES_CHECKSUM_OPTION_NAME , $callable_checksums );
	}
}