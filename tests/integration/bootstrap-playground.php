<?php
/**
 * PHPUnit integration-test bootstrap — WordPress Playground variant.
 *
 * Runs the same `WP_UnitTestCase` suite as `bootstrap.php`, but against the
 * ephemeral, SQLite-backed WordPress that `@wp-playground/cli`'s `php`
 * subcommand boots on every run — no local MySQL, no `install-wp-tests.sh`.
 *
 * Why this file exists (don't remove `WP_TESTS_SKIP_INSTALL`):
 * The standard WP core test bootstrap installs WordPress by spawning a child
 * process (`system( WP_PHP_BINARY . ' install.php' )`). Under the Playground
 * CLI's `php` subcommand that nested install currently fails — mounted files
 * are invisible to the spawned child, and the install can deadlock on
 * Playground's file-lock manager. See
 * https://github.com/WordPress/wordpress-playground/issues/3783 (open) and
 * the fix in progress at
 * https://github.com/WordPress/wordpress-playground/pull/3786.
 *
 * The workaround is to skip that redundant install entirely: Playground has
 * *already* installed a clean WordPress before handing control to this script,
 * so `WP_TESTS_SKIP_INSTALL=1` (an official WP core test-suite flag) tells the
 * bootstrap to `require` `wp-settings.php` in-process against that already-booted
 * site instead of reinstalling.
 */

declare( strict_types=1 );

putenv( 'WP_TESTS_SKIP_INSTALL=1' );

$vendor_dir = dirname( __DIR__, 2 ) . '/vendor';

putenv( 'WP_TESTS_DIR=' . $vendor_dir . '/wp-phpunit/wp-phpunit' );
putenv( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH=' . $vendor_dir . '/yoast/phpunit-polyfills' );
define( 'WP_TESTS_CONFIG_FILE_PATH', __DIR__ . '/wp-tests-config-playground.php' );

require_once $vendor_dir . '/wp-phpunit/wp-phpunit/includes/functions.php';

// Avoid a block-theme _doing_it_wrong() notice when no theme is active yet.
tests_add_filter( 'pre_option_stylesheet', fn() => 'default' );
tests_add_filter( 'pre_option_template', fn() => 'default' );

require __DIR__ . '/bootstrap.php';
