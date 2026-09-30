<?php
/**
 * WordPress test-suite configuration for the Playground-based integration run.
 *
 * ABSPATH points at the vanilla WordPress core that `@wp-playground/cli`'s `php`
 * subcommand boots — a real, already-installed, SQLite-backed WordPress. (The
 * repo itself is mounted alongside it at /repo, deliberately *not* over
 * /wordpress, which would shadow core.) The DB_* constants below are
 * placeholders required by the WP core test bootstrap's code path; Playground's
 * own SQLite drop-in intercepts all `$wpdb` access before they are ever used.
 *
 * See bootstrap-playground.php for why `WP_TESTS_SKIP_INSTALL` makes this safe.
 */

declare( strict_types=1 );

define( 'ABSPATH', '/wordpress/' );

define( 'DB_NAME', 'placeholder' );
define( 'DB_USER', 'placeholder' );
define( 'DB_PASSWORD', 'placeholder' );
define( 'DB_HOST', 'placeholder' );
define( 'DB_CHARSET', 'utf8' );
define( 'DB_COLLATE', '' );

// Must match the WordPress that Playground actually booted, which uses the
// default prefix.
// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Required for the WP test configuration.
$table_prefix = 'wp_';

/*
 * WP_TESTS_DOMAIN becomes $_SERVER['HTTP_HOST'], so it must match the
 * --site-url that the npm script boots Playground with.
 */
define( 'WP_TESTS_DOMAIN', 'wp-primary-category.test' );
define( 'WP_TESTS_EMAIL', 'admin@wp-primary-category.test' );
define( 'WP_TESTS_TITLE', 'HM Primary Category Tests' );
define( 'WP_PHP_BINARY', PHP_BINARY );
define( 'WP_DEFAULT_THEME', 'default' );
