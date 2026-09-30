<?php
/**
 * Moving primary-term data between Yoast SEO's meta keys and this plugin's.
 *
 * Yoast stores a term ID under `_yoast_wpseo_primary_{$taxonomy}`; this plugin
 * stores the same integer under `_hm_primary_{$taxonomy}`. Identical shape, so
 * the migration is a key copy, not a data transform.
 *
 * The decision for one post lives here, in a single function with no WP-CLI in
 * sight, so the rules are unit-testable and `inc/cli.php` stays a loop with
 * counters.
 *
 * @package HM\Primary_Term
 */

namespace HM\Primary_Term;

/**
 * Yoast SEO's primary term meta key prefix.
 */
const YOAST_META_PREFIX = '_yoast_wpseo_primary_';

/**
 * The two ends of a migration, usable as either `from` or `to`.
 */
const MIGRATE_SIDES = [ 'yoast', 'hm' ];

/**
 * Every status `migrate_post()` can return, in reporting order.
 */
const MIGRATE_STATUSES = [ 'copied', 'already', 'conflict', 'orphaned', 'empty' ];

/**
 * The meta key one end of a migration uses for a taxonomy.
 *
 * @param string $side     One of `MIGRATE_SIDES`.
 * @param string $taxonomy Taxonomy name.
 * @return string
 */
function migrate_meta_key( string $side, string $taxonomy ): string {
	return ( 'yoast' === $side ? YOAST_META_PREFIX : META_PREFIX ) . $taxonomy;
}

/**
 * Fill in the defaults `migrate_post()` runs with.
 *
 * Defaults are Yoast to this plugin, and the cautious end of every switch: no
 * write happens without the caller asking for it.
 *
 * @param array<string, mixed> $args Partial arguments.
 * @return array<string, mixed>
 */
function migrate_args( array $args = [] ): array {
	return array_merge(
		[
			'from'      => 'yoast',
			'to'        => 'hm',
			'dry_run'   => false,
			'overwrite' => false,
			'cleanup'   => false,
		],
		$args
	);
}

/**
 * Decide and apply what should happen to one post's primary term for one
 * taxonomy, and report which of `MIGRATE_STATUSES` it was.
 *
 * The source value is validated before the destination is even looked at. A
 * term the post no longer has is `orphaned` whatever the destination holds:
 * `get_primary_term()` would refuse to return it, so calling it `already` or
 * copying it would only spread a value that is known bad. That mirrors
 * `set_primary_term()`, which makes the same `is_object_in_term()` check.
 *
 * A destination holding a *different* term is a `conflict` and is left alone
 * unless `overwrite` is set: the destination value is somebody's explicit
 * choice, and the caller did not ask for it to be thrown away.
 *
 * @param int                  $post_id  Post ID.
 * @param string               $taxonomy Taxonomy name.
 * @param array<string, mixed> $args     See `migrate_args()`.
 * @return string One of `MIGRATE_STATUSES`.
 */
function migrate_post( int $post_id, string $taxonomy, array $args = [] ): string {
	$args = migrate_args( $args );

	$source_key = migrate_meta_key( $args['from'], $taxonomy );
	$target_key = migrate_meta_key( $args['to'], $taxonomy );

	$source = (int) get_post_meta( $post_id, $source_key, true );

	if ( $source <= 0 ) {
		return 'empty';
	}

	if ( true !== is_object_in_term( $post_id, $taxonomy, $source ) ) {
		return 'orphaned';
	}

	$target = (int) get_post_meta( $post_id, $target_key, true );

	if ( $target === $source ) {
		return 'already';
	}

	if ( $target > 0 && ! $args['overwrite'] ) {
		return 'conflict';
	}

	if ( $args['dry_run'] ) {
		return 'copied';
	}

	update_post_meta( $post_id, $target_key, $source );

	// Only ever reached on a real, successful copy — never on a skip.
	if ( $args['cleanup'] ) {
		delete_post_meta( $post_id, $source_key );
	}

	return 'copied';
}

/**
 * A batch of IDs of posts that have the source meta key.
 *
 * Queried straight from `postmeta` rather than by walking posts: on a large
 * site the rows to migrate are a small fraction of the table, and this is the
 * only index that finds them.
 *
 * Paged by last seen ID rather than `OFFSET`, because `--cleanup` deletes the
 * rows being paged over and an offset would step past whole batches.
 *
 * @param string   $meta_key   Source meta key.
 * @param string[] $post_types Post types to include.
 * @param int      $after_id   Return posts with an ID greater than this.
 * @param int      $batch_size Maximum rows to return.
 * @return int[]
 */
function migrate_post_ids( string $meta_key, array $post_types, int $after_id, int $batch_size ): array {
	global $wpdb;

	if ( empty( $post_types ) ) {
		return [];
	}

	$placeholders = implode( ', ', array_fill( 0, count( $post_types ), '%s' ) );

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Table names and a generated placeholder list the sniff cannot count.
	$sql = $wpdb->prepare(
		"SELECT DISTINCT p.ID
		FROM {$wpdb->postmeta} pm
		INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
		WHERE pm.meta_key = %s
		AND p.post_type IN ( {$placeholders} )
		AND p.post_status != 'auto-draft'
		AND p.ID > %d
		ORDER BY p.ID ASC
		LIMIT %d",
		array_merge( [ $meta_key ], $post_types, [ $after_id, $batch_size ] )
	);
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Prepared above; a one-off admin command with nothing to cache.
	return array_map( 'intval', $wpdb->get_col( $sql ) );
}

/**
 * How many posts the migration will visit, for the progress bar.
 *
 * @param string   $meta_key   Source meta key.
 * @param string[] $post_types Post types to include.
 * @return int
 */
function migrate_post_count( string $meta_key, array $post_types ): int {
	global $wpdb;

	if ( empty( $post_types ) ) {
		return 0;
	}

	$placeholders = implode( ', ', array_fill( 0, count( $post_types ), '%s' ) );

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Table names and a generated placeholder list the sniff cannot count.
	$sql = $wpdb->prepare(
		"SELECT COUNT( DISTINCT p.ID )
		FROM {$wpdb->postmeta} pm
		INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
		WHERE pm.meta_key = %s
		AND p.post_type IN ( {$placeholders} )
		AND p.post_status != 'auto-draft'",
		array_merge( [ $meta_key ], $post_types )
	);
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Prepared above; a one-off admin command with nothing to cache.
	return (int) $wpdb->get_var( $sql );
}
