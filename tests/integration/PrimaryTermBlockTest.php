<?php
/**
 * Integration tests for the `hmPrimaryOnly` option on `core/post-terms`.
 *
 * Blocks are built as `WP_Block` instances rather than parsed from markup so
 * the post ID can be handed in as block context — which is how a Post Terms
 * block gets one in a template or a Query Loop.
 */

declare( strict_types=1 );

use function HM\Primary_Term\meta_key;

/**
 * @covers \HM\Primary_Term\register_primary_only_attribute
 * @covers \HM\Primary_Term\wrap_post_terms_render
 * @covers \HM\Primary_Term\render_post_terms
 * @covers \HM\Primary_Term\first_term_only
 */
class Primary_Term_Block_Test extends WP_UnitTestCase {

	public function test_the_attribute_is_registered_server_side(): void {
		$block = WP_Block_Type_Registry::get_instance()->get_registered( 'core/post-terms' );

		$this->assertArrayHasKey( 'hmPrimaryOnly', $block->attributes );
		$this->assertSame( 'boolean', $block->attributes['hmPrimaryOnly']['type'] );
		$this->assertFalse( $block->attributes['hmPrimaryOnly']['default'] );
	}

	public function test_a_block_without_the_attribute_renders_every_term_in_core_s_order(): void {
		[ $post, $advertising, $broadcast, $cinema ] = $this->post_with_categories();

		update_post_meta( $post, meta_key( 'category' ), $cinema );

		$rendered = $this->render( $post, [ 'term' => 'category' ] );

		$this->assertSame( [ 'Advertising', 'Broadcast', 'Cinema' ], $this->term_names( $rendered ) );
		$this->assertStringContainsString( 'wp-block-post-terms__separator', $rendered );

		// Left alone by both names: `$advertising` and `$broadcast` are asserted
		// on above by name, this ties them to the IDs the post actually has.
		$this->assertSame( [ $advertising, $broadcast, $cinema ], wp_list_pluck( get_the_category( $post ), 'term_id' ) );
	}

	public function test_primary_only_renders_the_primary_term_alone(): void {
		[ $post, , , $cinema ] = $this->post_with_categories();

		update_post_meta( $post, meta_key( 'category' ), $cinema );

		$rendered = $this->render( $post, [ 'term' => 'category', 'hmPrimaryOnly' => true ] );

		// Cinema sorts last, so a block that has not scoped its ordering
		// correctly shows Advertising here.
		$this->assertSame( [ 'Cinema' ], $this->term_names( $rendered ) );
		$this->assertStringNotContainsString( 'wp-block-post-terms__separator', $rendered );
		$this->assertStringContainsString( 'wp-block-post-terms', $rendered );
		$this->assertStringContainsString( 'taxonomy-category', $rendered );
	}

	public function test_primary_only_works_for_a_flat_taxonomy(): void {
		add_filter( 'hm_primary_term_taxonomies', fn( $taxonomies ) => [ ...$taxonomies, 'post_tag' ] );

		$post = self::factory()->post->create();

		$agencies = self::factory()->tag->create( [ 'name' => 'Agencies' ] );
		$brands   = self::factory()->tag->create( [ 'name' => 'Brands' ] );
		wp_set_object_terms( $post, [ $agencies, $brands ], 'post_tag' );
		update_post_meta( $post, meta_key( 'post_tag' ), $brands );

		$rendered = $this->render( $post, [ 'term' => 'post_tag', 'hmPrimaryOnly' => true ] );

		$this->assertSame( [ 'Brands' ], $this->term_names( $rendered ) );
	}

	public function test_primary_only_keeps_the_prefix_and_suffix(): void {
		[ $post, , , $cinema ] = $this->post_with_categories();

		update_post_meta( $post, meta_key( 'category' ), $cinema );

		$rendered = $this->render(
			$post,
			[
				'term' => 'category',
				'hmPrimaryOnly' => true,
				'prefix' => 'Filed in ',
				'suffix' => ' today',
			]
		);

		$this->assertSame( [ 'Cinema' ], $this->term_names( $rendered ) );
		$this->assertStringContainsString( 'Filed in ', $rendered );
		$this->assertStringContainsString( ' today', $rendered );
		$this->assertStringEndsWith( '</div>', trim( $rendered ) );
	}

	public function test_primary_only_falls_back_to_the_first_term_without_a_choice(): void {
		[ $post ] = $this->post_with_categories();

		$rendered = $this->render( $post, [ 'term' => 'category', 'hmPrimaryOnly' => true ] );

		$this->assertSame( [ 'Advertising' ], $this->term_names( $rendered ) );
	}

	public function test_a_post_with_one_term_is_returned_unchanged(): void {
		$post   = self::factory()->post->create();
		$cinema = self::factory()->category->create( [ 'name' => 'Cinema' ] );
		wp_set_object_terms( $post, [ $cinema ], 'category' );

		$plain   = $this->render( $post, [ 'term' => 'category' ] );
		$trimmed = $this->render( $post, [ 'term' => 'category', 'hmPrimaryOnly' => true ] );

		$this->assertSame( $plain, $trimmed );
	}

	/**
	 * The one that matters: the ordering filter must not outlive the block that
	 * asked for it.
	 */
	public function test_the_ordering_filter_does_not_leak_past_the_render(): void {
		[ $post, $advertising, $broadcast, $cinema ] = $this->post_with_categories();

		update_post_meta( $post, meta_key( 'category' ), $cinema );

		$this->render( $post, [ 'term' => 'category', 'hmPrimaryOnly' => true ] );

		$this->assertSame( [ $advertising, $broadcast, $cinema ], wp_list_pluck( get_the_terms( $post, 'category' ), 'term_id' ) );
		$this->assertSame( [ $advertising, $broadcast, $cinema ], wp_list_pluck( get_the_category( $post ), 'term_id' ) );
		$this->assertFalse( has_filter( 'get_the_terms' ) );
	}

	/**
	 * Render a `core/post-terms` block against a post.
	 *
	 * @param int   $post_id    Post ID, handed in as block context.
	 * @param array $attributes Block attributes.
	 * @return string
	 */
	private function render( int $post_id, array $attributes ): string {
		$block = new WP_Block(
			[
				'blockName' => 'core/post-terms',
				'attrs' => $attributes,
				'innerBlocks' => [],
				'innerHTML' => '',
				'innerContent' => [],
			],
			[
				'postId' => $post_id,
				'postType' => 'post',
			]
		);

		return $block->render();
	}

	/**
	 * The term names linked in a rendered block, in the order they appear.
	 *
	 * @param string $rendered Rendered block markup.
	 * @return string[]
	 */
	private function term_names( string $rendered ): array {
		preg_match_all( '#<a [^>]*>([^<]*)</a>#', $rendered, $matches );

		return $matches[1];
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
