<?php
/**
 * Integration tests for the Yoast SEO migration rules.
 *
 * `migrate_post()` is exercised directly rather than through WP-CLI: the CLI
 * command is a loop with counters, and every decision worth pinning down is in
 * here.
 */

declare( strict_types=1 );

use function HM\Primary_Term\meta_key;
use function HM\Primary_Term\migrate_meta_key;
use function HM\Primary_Term\migrate_post;
use function HM\Primary_Term\register_meta_fields;

/**
 * @covers \HM\Primary_Term\migrate_post
 */
class Primary_Term_Migrate_Test extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		// WP_UnitTestCase empties $wp_meta_keys in tear_down.
		register_meta_fields();
	}

	public function test_the_two_ends_use_yoasts_and_this_plugins_key_shapes(): void {
		$this->assertSame( '_yoast_wpseo_primary_category', migrate_meta_key( 'yoast', 'category' ) );
		$this->assertSame( '_hm_primary_category', migrate_meta_key( 'hm', 'category' ) );
		$this->assertSame( meta_key( 'post_tag' ), migrate_meta_key( 'hm', 'post_tag' ) );
	}

	public function test_an_empty_destination_is_copied_into(): void {
		[ $post, , $broadcast ] = $this->post_with_categories();

		$this->set_source( $post, $broadcast );

		$this->assertSame( 'copied', migrate_post( $post, 'category' ) );
		$this->assertSame( $broadcast, $this->destination( $post ) );

		// Without --cleanup the source is left exactly as it was.
		$this->assertSame( $broadcast, $this->source( $post ) );
	}

	public function test_a_destination_holding_the_same_term_is_left_alone(): void {
		[ $post, , $broadcast ] = $this->post_with_categories();

		$this->set_source( $post, $broadcast );
		$this->set_destination( $post, $broadcast );

		$this->assertSame( 'already', migrate_post( $post, 'category' ) );
		$this->assertSame( $broadcast, $this->destination( $post ) );
	}

	public function test_a_destination_holding_a_different_term_is_a_conflict_and_is_not_overwritten(): void {
		[ $post, $advertising, $broadcast ] = $this->post_with_categories();

		$this->set_source( $post, $broadcast );
		$this->set_destination( $post, $advertising );

		$this->assertSame( 'conflict', migrate_post( $post, 'category' ) );
		$this->assertSame( $advertising, $this->destination( $post ) );
	}

	public function test_overwrite_resolves_a_conflict_and_counts_as_a_copy(): void {
		[ $post, $advertising, $broadcast ] = $this->post_with_categories();

		$this->set_source( $post, $broadcast );
		$this->set_destination( $post, $advertising );

		$this->assertSame( 'copied', migrate_post( $post, 'category', [ 'overwrite' => true ] ) );
		$this->assertSame( $broadcast, $this->destination( $post ) );
	}

	public function test_a_term_the_post_no_longer_has_is_orphaned(): void {
		[ $post ] = $this->post_with_categories();

		$unassigned = self::factory()->category->create( [ 'name' => 'Cinema' ] );
		$this->set_source( $post, $unassigned );

		$this->assertSame( 'orphaned', migrate_post( $post, 'category' ) );
		$this->assertSame( 0, $this->destination( $post ) );
	}

	public function test_a_term_that_no_longer_exists_is_orphaned(): void {
		[ $post, $advertising ] = $this->post_with_categories();

		$deleted = self::factory()->category->create( [ 'name' => 'Cinema' ] );
		wp_set_object_terms( $post, [ $advertising, $deleted ], 'category' );
		$this->set_source( $post, $deleted );

		wp_delete_term( $deleted, 'category' );

		$this->assertSame( 'orphaned', migrate_post( $post, 'category' ) );
		$this->assertSame( 0, $this->destination( $post ) );
	}

	public function test_an_orphaned_source_stays_orphaned_even_with_overwrite(): void {
		[ $post, $advertising ] = $this->post_with_categories();

		$unassigned = self::factory()->category->create( [ 'name' => 'Cinema' ] );
		$this->set_source( $post, $unassigned );
		$this->set_destination( $post, $advertising );

		$this->assertSame( 'orphaned', migrate_post( $post, 'category', [ 'overwrite' => true ] ) );
		$this->assertSame( $advertising, $this->destination( $post ) );
	}

	public function test_no_source_value_is_empty(): void {
		[ $post ] = $this->post_with_categories();

		$this->assertSame( 'empty', migrate_post( $post, 'category' ) );

		// A zero is a cleared choice, not a term ID.
		$this->set_source( $post, 0 );

		$this->assertSame( 'empty', migrate_post( $post, 'category' ) );
		$this->assertSame( 0, $this->destination( $post ) );
	}

	public function test_cleanup_removes_the_source_after_a_copy(): void {
		[ $post, , $broadcast ] = $this->post_with_categories();

		$this->set_source( $post, $broadcast );

		$this->assertSame( 'copied', migrate_post( $post, 'category', [ 'cleanup' => true ] ) );
		$this->assertSame( $broadcast, $this->destination( $post ) );
		$this->assertFalse( metadata_exists( 'post', $post, migrate_meta_key( 'yoast', 'category' ) ) );
	}

	/**
	 * @dataProvider data_statuses_that_keep_the_source
	 *
	 * @param string $expected Status `migrate_post()` should return.
	 * @param bool   $conflict Whether to seed a conflicting destination.
	 * @param bool   $orphan   Whether the source should name an unassigned term.
	 */
	public function test_cleanup_never_removes_the_source_on_a_skip( string $expected, bool $conflict, bool $orphan ): void {
		[ $post, $advertising, $broadcast ] = $this->post_with_categories();

		$source = $orphan ? self::factory()->category->create( [ 'name' => 'Cinema' ] ) : $broadcast;
		$this->set_source( $post, $source );

		if ( $conflict ) {
			$this->set_destination( $post, $advertising );
		} elseif ( ! $orphan ) {
			$this->set_destination( $post, $broadcast );
		}

		$this->assertSame( $expected, migrate_post( $post, 'category', [ 'cleanup' => true ] ) );
		$this->assertSame( $source, $this->source( $post ) );
	}

	/**
	 * @return array<string, array{0: string, 1: bool, 2: bool}>
	 */
	public function data_statuses_that_keep_the_source(): array {
		return [
			'already'  => [ 'already', false, false ],
			'conflict' => [ 'conflict', true, false ],
			'orphaned' => [ 'orphaned', false, true ],
		];
	}

	public function test_a_dry_run_writes_nothing(): void {
		[ $post, , $broadcast ] = $this->post_with_categories();

		$this->set_source( $post, $broadcast );

		$args = [
			'dry_run' => true,
			'cleanup' => true,
		];

		$this->assertSame( 'copied', migrate_post( $post, 'category', $args ) );
		$this->assertFalse( metadata_exists( 'post', $post, meta_key( 'category' ) ) );
		$this->assertSame( $broadcast, $this->source( $post ) );
	}

	public function test_a_dry_run_leaves_a_resolved_conflict_untouched(): void {
		[ $post, $advertising, $broadcast ] = $this->post_with_categories();

		$this->set_source( $post, $broadcast );
		$this->set_destination( $post, $advertising );

		$args = [
			'dry_run'   => true,
			'overwrite' => true,
			'cleanup'   => true,
		];

		$this->assertSame( 'copied', migrate_post( $post, 'category', $args ) );
		$this->assertSame( $advertising, $this->destination( $post ) );
		$this->assertSame( $broadcast, $this->source( $post ) );
	}

	public function test_it_syncs_back_to_yoast_in_the_other_direction(): void {
		[ $post, , $broadcast ] = $this->post_with_categories();

		update_post_meta( $post, meta_key( 'category' ), $broadcast );

		$args = [
			'from'    => 'hm',
			'to'      => 'yoast',
			'cleanup' => true,
		];

		$this->assertSame( 'copied', migrate_post( $post, 'category', $args ) );
		$this->assertSame( $broadcast, (int) get_post_meta( $post, migrate_meta_key( 'yoast', 'category' ), true ) );
		$this->assertFalse( metadata_exists( 'post', $post, meta_key( 'category' ) ) );
	}

	/**
	 * A post in two categories, returned as [ post_id, first, second ].
	 *
	 * @return int[]
	 */
	private function post_with_categories(): array {
		$advertising = self::factory()->category->create( [ 'name' => 'Advertising' ] );
		$broadcast   = self::factory()->category->create( [ 'name' => 'Broadcast' ] );

		$post = self::factory()->post->create();
		wp_set_object_terms( $post, [ $advertising, $broadcast ], 'category' );

		return [ $post, $advertising, $broadcast ];
	}

	private function set_source( int $post_id, int $term_id ): void {
		update_post_meta( $post_id, migrate_meta_key( 'yoast', 'category' ), $term_id );
	}

	private function set_destination( int $post_id, int $term_id ): void {
		update_post_meta( $post_id, meta_key( 'category' ), $term_id );
	}

	private function source( int $post_id ): int {
		return (int) get_post_meta( $post_id, migrate_meta_key( 'yoast', 'category' ), true );
	}

	private function destination( int $post_id ): int {
		return (int) get_post_meta( $post_id, meta_key( 'category' ), true );
	}
}
