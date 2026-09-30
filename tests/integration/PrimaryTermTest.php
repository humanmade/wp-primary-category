<?php
/**
 * Integration tests for the primary term API, meta registration and the
 * permalink filter.
 */

declare( strict_types=1 );

use function HM\Primary_Term\get_primary_term;
use function HM\Primary_Term\get_primary_term_id;
use function HM\Primary_Term\meta_key;
use function HM\Primary_Term\register_meta_fields;
use function HM\Primary_Term\set_primary_term;
use function HM\Primary_Term\taxonomies;

/**
 * @covers \HM\Primary_Term
 */
class Primary_Term_Test extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		// WP_UnitTestCase empties $wp_meta_keys in tear_down.
		register_meta_fields();
	}

	public function test_primary_term_is_the_editors_choice(): void {
		[ $post, $advertising, $broadcast ] = $this->post_with_categories();

		update_post_meta( $post, meta_key( 'category' ), $broadcast );

		$this->assertSame( $broadcast, get_primary_term( $post )->term_id );
		$this->assertSame( $broadcast, get_primary_term_id( $post ) );
		$this->assertNotSame( $advertising, get_primary_term_id( $post ) );
	}

	public function test_primary_term_falls_back_to_the_first_term(): void {
		[ $post, $advertising ] = $this->post_with_categories();

		$this->assertSame( $advertising, get_primary_term( $post )->term_id );
	}

	public function test_primary_term_ignores_a_term_the_post_no_longer_has(): void {
		[ $post, $advertising ] = $this->post_with_categories();

		$dropped = self::factory()->category->create( [ 'name' => 'Cinema' ] );
		update_post_meta( $post, meta_key( 'category' ), $dropped );

		$this->assertSame( $advertising, get_primary_term( $post )->term_id );
	}

	public function test_primary_term_is_null_without_any_terms(): void {
		$post = self::factory()->post->create();
		wp_set_object_terms( $post, [], 'category' );

		$this->assertNull( get_primary_term( $post ) );
		$this->assertSame( 0, get_primary_term_id( $post ) );
	}

	/**
	 * 0.2.0 removed the global `get_the_terms` filter. This guards against it
	 * creeping back: a post's term order is core's, choice or no choice.
	 */
	public function test_a_post_s_term_order_is_untouched(): void {
		[ $post, $advertising, $broadcast ] = $this->post_with_categories();

		$cinema = self::factory()->category->create( [ 'name' => 'Cinema' ] );
		wp_set_object_terms( $post, [ $advertising, $broadcast, $cinema ], 'category' );
		update_post_meta( $post, meta_key( 'category' ), $cinema );

		$expected = [ $advertising, $broadcast, $cinema ];

		$this->assertSame( $expected, wp_list_pluck( get_the_terms( $post, 'category' ), 'term_id' ) );
		$this->assertSame( $expected, wp_list_pluck( get_the_category( $post ), 'term_id' ) );

		// The choice is still readable; only the global ordering has gone.
		$this->assertSame( $cinema, get_primary_term( $post )->term_id );
	}

	public function test_core_taxonomies_are_enabled_by_default(): void {
		$this->assertSame( [ 'category', 'post_tag' ], taxonomies() );
	}

	public function test_a_taxonomy_that_is_not_enabled_has_no_picker(): void {
		$this->assertNotContains( 'post_format', taxonomies() );
		$this->assertArrayNotHasKey( meta_key( 'post_format' ), get_registered_meta_keys( 'post', 'post' ) );
	}

	public function test_the_filter_can_enable_another_taxonomy(): void {
		register_taxonomy( 'genre', 'post' );

		$post = self::factory()->post->create();

		$first  = self::factory()->term->create( [ 'taxonomy' => 'genre', 'name' => 'Analysis' ] );
		$second = self::factory()->term->create( [ 'taxonomy' => 'genre', 'name' => 'Briefing' ] );
		wp_set_object_terms( $post, [ $first, $second ], 'genre' );
		update_post_meta( $post, meta_key( 'genre' ), $second );

		add_filter( 'hm_primary_term_taxonomies', fn( $taxonomies ) => [ ...$taxonomies, 'genre' ] );

		$this->assertContains( 'genre', taxonomies() );
		$this->assertSame( $second, get_primary_term( $post, 'genre' )->term_id );
	}

	public function test_the_filter_can_disable_a_default_taxonomy(): void {
		add_filter( 'hm_primary_term_taxonomies', fn( $taxonomies ) => array_values( array_diff( $taxonomies, [ 'post_tag' ] ) ) );

		$this->assertSame( [ 'category' ], taxonomies() );
	}

	public function test_the_filter_can_disable_everything(): void {
		add_filter( 'hm_primary_term_taxonomies', fn() => [] );

		$this->assertSame( [], taxonomies() );
	}

	public function test_the_filter_drops_anything_that_is_not_a_registered_taxonomy(): void {
		add_filter( 'hm_primary_term_taxonomies', fn() => [ 'category', 'catgeory', 42, null ] );

		$this->assertSame( [ 'category' ], taxonomies() );
	}

	public function test_set_primary_term_stores_and_clears_a_valid_choice(): void {
		[ $post, $advertising, $broadcast ] = $this->post_with_categories();

		$this->assertTrue( set_primary_term( $post, $broadcast ) );
		$this->assertSame( $broadcast, get_primary_term_id( $post ) );

		// Storing the same value again is still a success, not a no-op failure.
		$this->assertTrue( set_primary_term( $post, $broadcast ) );

		$this->assertTrue( set_primary_term( $post, 0 ) );
		$this->assertSame( $advertising, get_primary_term_id( $post ) );
	}

	public function test_set_primary_term_rejects_a_term_the_post_does_not_have(): void {
		[ $post, $advertising ] = $this->post_with_categories();

		$unassigned = self::factory()->category->create( [ 'name' => 'Cinema' ] );

		$this->assertFalse( set_primary_term( $post, $unassigned ) );
		$this->assertSame( $advertising, get_primary_term_id( $post ) );
	}

	public function test_set_primary_term_rejects_a_term_from_another_taxonomy(): void {
		[ $post, $advertising ] = $this->post_with_categories();

		$tag = self::factory()->tag->create( [ 'name' => 'Agencies' ] );
		wp_set_object_terms( $post, [ $tag ], 'post_tag' );

		$this->assertFalse( set_primary_term( $post, $tag ) );
		$this->assertSame( $advertising, get_primary_term_id( $post ) );
	}

	public function test_the_meta_is_registered_for_every_attached_post_type_and_exposed_in_rest(): void {
		register_taxonomy_for_object_type( 'category', 'page' );
		register_meta_fields();

		try {
			foreach ( [ 'post', 'page' ] as $post_type ) {
				$registered = get_registered_meta_keys( 'post', $post_type );

				$this->assertArrayHasKey( meta_key( 'category' ), $registered, $post_type );
				$this->assertTrue( $registered[ meta_key( 'category' ) ]['show_in_rest'], $post_type );
				$this->assertTrue( $registered[ meta_key( 'category' ) ]['single'], $post_type );
			}
		} finally {
			unregister_taxonomy_for_object_type( 'category', 'page' );
		}
	}

	public function test_the_auth_callback_follows_edit_post(): void {
		$author = self::factory()->user->create( [ 'role' => 'author' ] );
		$post   = self::factory()->post->create( [ 'post_author' => $author ] );

		$subscriber = self::factory()->user->create( [ 'role' => 'subscriber' ] );

		$key  = meta_key( 'category' );
		$auth = get_registered_meta_keys( 'post', 'post' )[ $key ]['auth_callback'];

		$this->assertIsCallable( $auth );

		wp_set_current_user( $subscriber );
		$this->assertFalse( (bool) $auth( false, $key, $post, $subscriber, 'edit_post_meta', [] ) );

		wp_set_current_user( $author );
		$this->assertTrue( (bool) $auth( false, $key, $post, $author, 'edit_post_meta', [] ) );
	}

	public function test_the_permalink_uses_the_primary_category(): void {
		[ $post, $advertising, $broadcast ] = $this->post_with_categories();

		update_post_meta( $post, meta_key( 'category' ), $broadcast );

		$categories = get_the_category( $post );
		$chosen     = apply_filters( 'post_link_category', $categories[0], $categories, get_post( $post ) );

		$this->assertSame( $broadcast, $chosen->term_id );
	}

	public function test_the_permalink_is_left_alone_without_a_primary_category(): void {
		[ , $advertising, $broadcast ] = $this->post_with_categories();

		$uncategorised = self::factory()->post->create();
		wp_set_object_terms( $uncategorised, [], 'category' );

		$categories = [ get_term( $advertising ), get_term( $broadcast ) ];
		$chosen     = apply_filters( 'post_link_category', $categories[0], $categories, get_post( $uncategorised ) );

		$this->assertSame( $advertising, $chosen->term_id );
	}

	public function test_the_permalink_is_left_alone_when_category_is_not_enabled(): void {
		[ $post, $advertising, $broadcast ] = $this->post_with_categories();

		update_post_meta( $post, meta_key( 'category' ), $broadcast );

		add_filter( 'hm_primary_term_taxonomies', fn() => [] );

		$categories = get_the_category( $post );
		$chosen     = apply_filters( 'post_link_category', $categories[0], $categories, get_post( $post ) );

		$this->assertSame( $advertising, $chosen->term_id );
	}

	/**
	 * A post in two categories, returned as [ post_id, first, second ].
	 *
	 * Named so `get_the_terms()`' default alphabetical order is predictable:
	 * Advertising before Broadcast.
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
}
