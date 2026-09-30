<?php
/**
 * Integration tests for the `hm/primary-term` Block Bindings source.
 *
 * Values are resolved through the registered source rather than by calling the
 * callback directly, so the registration and the resolution are covered by the
 * same assertions core's own render path would exercise.
 */

declare( strict_types=1 );

use function HM\Primary_Term\meta_key;

/**
 * @covers \HM\Primary_Term\register_binding_source
 * @covers \HM\Primary_Term\get_binding_value
 */
class Primary_Term_Bindings_Test extends WP_UnitTestCase {

	/**
	 * Fallback content, stood in the bound block so an unbound render is
	 * distinguishable from one that resolved to an empty string.
	 */
	private const NO_VALUE = 'NO VALUE';

	public function test_the_source_is_registered(): void {
		$source = WP_Block_Bindings_Registry::get_instance()->get_registered( 'hm/primary-term' );

		$this->assertNotNull( $source );
		$this->assertSame( [ 'postId', 'postType' ], $source->uses_context );
	}

	public function test_it_resolves_the_name_slug_and_url_of_a_chosen_primary(): void {
		[ $post, , , $cinema ] = $this->post_with_categories();

		update_post_meta( $post, meta_key( 'category' ), $cinema );

		$this->assertSame( 'Cinema', $this->value( $post, [ 'key' => 'name' ] ) );
		$this->assertSame( get_term( $cinema )->slug, $this->value( $post, [ 'key' => 'slug' ] ) );
		$this->assertSame( get_term_link( $cinema ), $this->value( $post, [ 'key' => 'url' ] ) );
	}

	public function test_it_falls_back_to_the_first_term_without_a_choice(): void {
		[ $post ] = $this->post_with_categories();

		$this->assertSame( 'Advertising', $this->value( $post, [ 'key' => 'name' ] ) );
	}

	public function test_it_defaults_to_the_category_taxonomy(): void {
		[ $post, , , $cinema ] = $this->post_with_categories();

		update_post_meta( $post, meta_key( 'category' ), $cinema );

		$this->assertSame( 'Cinema', $this->value( $post, [ 'key' => 'name', 'taxonomy' => 'category' ] ) );
		$this->assertSame( 'Cinema', $this->value( $post, [ 'key' => 'name' ] ) );
	}

	public function test_it_returns_null_for_a_post_with_no_terms_in_the_taxonomy(): void {
		[ $post ] = $this->post_with_categories();

		$this->assertNull( $this->value( $post, [ 'key' => 'name', 'taxonomy' => 'post_tag' ] ) );
		$this->assertNull( $this->value( $post, [ 'key' => 'url', 'taxonomy' => 'post_tag' ] ) );
	}

	public function test_it_returns_null_for_an_unrecognised_key(): void {
		[ $post ] = $this->post_with_categories();

		$this->assertNull( $this->value( $post, [ 'key' => 'description' ] ) );
		$this->assertNull( $this->value( $post, [] ) );
	}

	public function test_it_works_for_a_taxonomy_other_than_category(): void {
		add_filter( 'hm_primary_term_taxonomies', fn( $taxonomies ) => [ ...$taxonomies, 'post_tag' ] );

		$post = self::factory()->post->create();

		$agencies = self::factory()->tag->create( [ 'name' => 'Agencies' ] );
		$brands   = self::factory()->tag->create( [ 'name' => 'Brands' ] );
		wp_set_object_terms( $post, [ $agencies, $brands ], 'post_tag' );
		update_post_meta( $post, meta_key( 'post_tag' ), $brands );

		$args = [ 'taxonomy' => 'post_tag', 'key' => 'name' ];

		$this->assertSame( 'Brands', $this->value( $post, $args ) );
		$this->assertSame( get_term_link( $brands ), $this->value( $post, [ ...$args, 'key' => 'url' ] ) );
	}

	/**
	 * A null value leaves the block's own content alone, which is the whole
	 * reason the callback returns null rather than an empty string.
	 */
	public function test_a_null_value_leaves_the_block_s_fallback_content_in_place(): void {
		$post = self::factory()->post->create();
		wp_set_object_terms( $post, [], 'category' );

		$this->assertNull( $this->value( $post, [ 'key' => 'name' ] ) );
	}

	/**
	 * Resolve a bound value by rendering a bound block.
	 *
	 * Not by calling the callback directly: core merges the source's
	 * `uses_context` into the block during `process_block_bindings()`, so a
	 * hand-built block has no `postId` until it renders. Going through a render
	 * covers the registration, the context wiring and the value in one go.
	 *
	 * A null value leaves the block's saved content alone, so the sentinel
	 * coming back out is how "returned null" is observed.
	 *
	 * @param int   $post_id     Post ID, handed in as block context.
	 * @param array $source_args Binding arguments.
	 * @return string|null
	 */
	private function value( int $post_id, array $source_args ): ?string {
		$block = new WP_Block(
			[
				'blockName' => 'core/paragraph',
				'attrs' => [
					'metadata' => [
						'bindings' => [
							'content' => [
								'source' => 'hm/primary-term',
								'args' => $source_args,
							],
						],
					],
				],
				'innerBlocks' => [],
				'innerHTML' => '<p>' . self::NO_VALUE . '</p>',
				'innerContent' => [ '<p>' . self::NO_VALUE . '</p>' ],
			],
			[
				'postId' => $post_id,
				'postType' => 'post',
			]
		);

		preg_match( '#<p[^>]*>(.*)</p>#s', $block->render(), $matches );

		$value = $matches[1] ?? '';

		return self::NO_VALUE === $value ? null : $value;
	}

	/**
	 * A post in three categories, returned as [ post_id, ...term_ids ].
	 *
	 * Named so `get_the_terms()`' default alphabetical order is predictable, and
	 * so the interesting primary — Cinema — is the one core would put last.
	 *
	 * @return int[]
	 */
	private function post_with_categories(): array {
		$advertising = self::factory()->category->create( [ 'name' => 'Advertising' ] );
		$broadcast   = self::factory()->category->create( [ 'name' => 'Broadcast' ] );
		$cinema      = self::factory()->category->create( [ 'name' => 'Cinema' ] );

		$post = self::factory()->post->create();
		wp_set_object_terms( $post, [ $advertising, $broadcast, $cinema ], 'category' );

		return [ $post, $advertising, $broadcast, $cinema ];
	}
}
