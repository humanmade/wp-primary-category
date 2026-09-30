<?php
/**
 * The `wp primary-term` WP-CLI command.
 *
 * A thin loop over `migrate_post()`: this file owns argument parsing, batching
 * and reporting, and none of the rules. See `inc/migrate.php` for those.
 *
 * @package HM\Primary_Term
 */

namespace HM\Primary_Term;

use WP_CLI;
use WP_CLI\Utils;

/**
 * Copy primary term data between Yoast SEO's meta keys and this plugin's.
 *
 * Yoast stores a term ID in `_yoast_wpseo_primary_{$taxonomy}` and this plugin
 * stores the same integer in `_hm_primary_{$taxonomy}`, so this copies keys
 * rather than transforming data.
 *
 * Reversing `--from` and `--to` syncs choices back to Yoast. That is a one-off
 * command and deliberately not a runtime hook, so nothing in this plugin
 * depends on Yoast being installed.
 *
 * A source value naming a term the post no longer has is reported as orphaned
 * and skipped: `get_primary_term()` would refuse to return it, so copying it
 * would only spread a value that is already broken.
 *
 * ## OPTIONS
 *
 * [--from=<source>]
 * : Where to read each post's primary term from.
 * ---
 * default: yoast
 * options:
 *   - yoast
 *   - hm
 * ---
 *
 * [--to=<destination>]
 * : Where to write it. Must differ from --from.
 * ---
 * default: hm
 * options:
 *   - yoast
 *   - hm
 * ---
 *
 * [--taxonomy=<taxonomy>]
 * : Comma-separated taxonomies to migrate. Defaults to every taxonomy that has
 * a primary term enabled, i.e. whatever `hm_primary_term_taxonomies` allows.
 *
 * [--post-type=<post-types>]
 * : Comma-separated post types to limit the migration to. Defaults to every
 * post type each taxonomy is attached to.
 *
 * [--batch-size=<number>]
 * : How many posts to load per batch. Lower it on a memory-constrained host.
 * ---
 * default: 500
 * ---
 *
 * [--dry-run]
 * : Report what would happen and write nothing at all, --cleanup included.
 *
 * [--overwrite]
 * : Replace a destination that already holds a different term. Without this a
 * conflict is reported and skipped, leaving the existing choice alone.
 *
 * [--cleanup]
 * : Delete the source meta once a post has been copied successfully. Never
 * deletes on a skip, an unresolved conflict or a dry run.
 *
 * ## EXAMPLES
 *
 *     # See what migrating off Yoast would do, without touching anything.
 *     $ wp primary-term migrate --dry-run
 *
 *     # Do it.
 *     $ wp primary-term migrate
 *
 *     # Do it for two taxonomies, replacing any choice already recorded here.
 *     $ wp primary-term migrate --taxonomy=category,post_tag --overwrite
 *
 *     # Once you are happy, drop Yoast's copy of the data.
 *     $ wp primary-term migrate --cleanup
 *
 *     # Sync choices back to Yoast, e.g. before rolling this plugin back.
 *     $ wp primary-term migrate --from=hm --to=yoast --overwrite
 *
 * @when after_wp_load
 *
 * @param string[]              $args       Positional arguments. Unused.
 * @param array<string, string> $assoc_args Associative arguments.
 */
function migrate_command( $args, $assoc_args ): void { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable, Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Fixed WP-CLI callback signature.
	$from = (string) Utils\get_flag_value( $assoc_args, 'from', 'yoast' );
	$to   = (string) Utils\get_flag_value( $assoc_args, 'to', 'hm' );

	$sides = [
		'from' => $from,
		'to'   => $to,
	];

	foreach ( $sides as $flag => $side ) {
		if ( ! in_array( $side, MIGRATE_SIDES, true ) ) {
			WP_CLI::error( sprintf( '--%s must be one of: %s.', $flag, implode( ', ', MIGRATE_SIDES ) ) );
		}
	}

	if ( $from === $to ) {
		WP_CLI::error( 'Nothing to do: --from and --to are the same.' );
	}

	$migrate_args = migrate_args(
		[
			'from'      => $from,
			'to'        => $to,
			'dry_run'   => (bool) Utils\get_flag_value( $assoc_args, 'dry-run', false ),
			'overwrite' => (bool) Utils\get_flag_value( $assoc_args, 'overwrite', false ),
			'cleanup'   => (bool) Utils\get_flag_value( $assoc_args, 'cleanup', false ),
		]
	);

	$batch_size = max( 1, (int) Utils\get_flag_value( $assoc_args, 'batch-size', 500 ) );
	$taxonomies = migrate_command_taxonomies( $assoc_args );
	$post_types = migrate_command_post_types( $assoc_args );

	if ( $migrate_args['dry_run'] ) {
		WP_CLI::log( 'Dry run: nothing will be written.' );
	}

	$rows   = [];
	$totals = array_fill_keys( MIGRATE_STATUSES, 0 );

	foreach ( $taxonomies as $taxonomy ) {
		$taxonomy_post_types = $post_types
			? array_values( array_intersect( $post_types, get_taxonomy( $taxonomy )->object_type ) )
			: array_values( get_taxonomy( $taxonomy )->object_type );

		if ( empty( $taxonomy_post_types ) ) {
			WP_CLI::warning( sprintf( 'Skipping `%s`: it is not attached to any of the requested post types.', $taxonomy ) );
			continue;
		}

		$counts = migrate_command_taxonomy( $taxonomy, $taxonomy_post_types, $batch_size, $migrate_args );

		foreach ( $counts as $status => $count ) {
			$totals[ $status ] += $count;
		}

		$rows[] = array_merge( [ 'taxonomy' => $taxonomy ], $counts );
	}

	if ( empty( $rows ) ) {
		WP_CLI::error( 'No taxonomies left to migrate.' );
	}

	Utils\format_items( 'table', $rows, array_merge( [ 'taxonomy' ], MIGRATE_STATUSES ) );

	$summary = sprintf(
		'%s %d copied, %d already set, %d conflicts, %d orphaned, %d with no source value.',
		$migrate_args['dry_run'] ? 'Dry run:' : 'Migrated:',
		$totals['copied'],
		$totals['already'],
		$totals['conflict'],
		$totals['orphaned'],
		$totals['empty']
	);

	// Conflicts and orphans both mean data was left behind, so say so on stderr
	// rather than reporting a clean success a scripted run would ignore.
	if ( $totals['conflict'] > 0 || $totals['orphaned'] > 0 ) {
		WP_CLI::warning( $summary . ' Re-run with --overwrite to replace the conflicting values.' );
		return;
	}

	WP_CLI::success( $summary );
}

/**
 * Migrate one taxonomy, batch by batch, and return its per-status counts.
 *
 * @param string               $taxonomy     Taxonomy name.
 * @param string[]             $post_types   Post types to include.
 * @param int                  $batch_size   Posts per batch.
 * @param array<string, mixed> $migrate_args See `migrate_args()`.
 * @return array<string, int>
 */
function migrate_command_taxonomy( string $taxonomy, array $post_types, int $batch_size, array $migrate_args ): array {
	$counts     = array_fill_keys( MIGRATE_STATUSES, 0 );
	$source_key = migrate_meta_key( $migrate_args['from'], $taxonomy );

	$progress = Utils\make_progress_bar(
		sprintf( 'Migrating %s', $taxonomy ),
		migrate_post_count( $source_key, $post_types )
	);

	$after_id = 0;

	while ( true ) {
		$post_ids = migrate_post_ids( $source_key, $post_types, $after_id, $batch_size );

		if ( empty( $post_ids ) ) {
			break;
		}

		foreach ( $post_ids as $post_id ) {
			$status = migrate_post( $post_id, $taxonomy, $migrate_args );
			++$counts[ $status ];

			if ( 'conflict' === $status || 'orphaned' === $status ) {
				WP_CLI::log( sprintf( '  %s: post %d (%s)', ucfirst( $status ), $post_id, $taxonomy ) );
			}

			$progress->tick();
		}

		$after_id = (int) end( $post_ids );

		Utils\wp_clear_object_cache();
	}

	$progress->finish();

	return $counts;
}

/**
 * The taxonomies a run covers, validated.
 *
 * @param array<string, string> $assoc_args Associative arguments.
 * @return string[]
 */
function migrate_command_taxonomies( array $assoc_args ): array {
	$requested = Utils\get_flag_value( $assoc_args, 'taxonomy' );

	if ( ! $requested ) {
		$taxonomies = taxonomies();

		if ( empty( $taxonomies ) ) {
			WP_CLI::error( 'No taxonomies have a primary term enabled. Pass --taxonomy to migrate one anyway.' );
		}

		return $taxonomies;
	}

	$taxonomies = array_filter( array_map( 'trim', explode( ',', (string) $requested ) ) );

	foreach ( $taxonomies as $taxonomy ) {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			WP_CLI::error( sprintf( 'Unknown taxonomy: `%s`.', $taxonomy ) );
		}
	}

	return array_values( $taxonomies );
}

/**
 * The post types a run is limited to, validated. An empty array means "every
 * post type the taxonomy is attached to".
 *
 * @param array<string, string> $assoc_args Associative arguments.
 * @return string[]
 */
function migrate_command_post_types( array $assoc_args ): array {
	$requested = Utils\get_flag_value( $assoc_args, 'post-type' );

	if ( ! $requested ) {
		return [];
	}

	$post_types = array_filter( array_map( 'trim', explode( ',', (string) $requested ) ) );

	foreach ( $post_types as $post_type ) {
		if ( ! post_type_exists( $post_type ) ) {
			WP_CLI::error( sprintf( 'Unknown post type: `%s`.', $post_type ) );
		}
	}

	return array_values( $post_types );
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::add_command( 'primary-term migrate', __NAMESPACE__ . '\\migrate_command' );
}
