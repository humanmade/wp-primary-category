<?php
/**
 * PHPUnit integration-test bootstrap.
 *
 * Loads the WordPress core PHPUnit test library (`wp-phpunit/wp-phpunit`) so
 * tests can extend `WP_UnitTestCase` against a real, booted WordPress. Usable
 * against a local WordPress + MySQL test database, or wrapped by
 * `bootstrap-playground.php` for the SQLite-backed Playground run that
 * `npm test` uses.
 */

declare( strict_types=1 );

$autoload = dirname( __DIR__, 2 ) . '/vendor/autoload.php';

if ( ! file_exists( $autoload ) ) {
	fwrite( STDERR, "Could not locate vendor/autoload.php. Run `composer install` first.\n" );
	exit( 1 );
}

require_once $autoload;

$_tests_dir = getenv( 'WP_TESTS_DIR' ) ?: dirname( __DIR__, 2 ) . '/vendor/wp-phpunit/wp-phpunit';

require_once $_tests_dir . '/includes/functions.php';

/*
 * Load the plugin under test. The repo is mounted alongside WordPress rather
 * than inside wp-content/plugins, so it is required here rather than activated.
 */
tests_add_filter(
	'muplugins_loaded',
	function () {
		require_once dirname( __DIR__, 2 ) . '/plugin.php';
	}
);

require $_tests_dir . '/includes/bootstrap.php';
