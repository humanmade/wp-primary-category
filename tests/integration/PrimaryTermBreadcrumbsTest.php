<?php
/**
 * Integration tests for the primary term in core's Breadcrumbs block.
 *
 * Assertions are made on a rendered `core/breadcrumbs` block rather than on the
 * filter's return value: what matters is the trail a visitor sees, and core is
 * free to disregard a setting this plugin supplies.
 *
 * One exception, at the bottom: the drift canaries detach the plugin's filter
 * first. Everything else here renders with it attached, and because it names
 * the taxonomy, core obeys — so those tests can only ever see the mirror's own
 * answer reflected back. They cannot tell whether it is still core's answer.
 */

declare( strict_types=1 );

use function HM\Primary_Term\breadcrumbs_taxonomy;
use function HM\Primary_Term\meta_key;

/**
 * @covers \HM\Primary_Term\filter_breadcrumbs_settings
 * @covers \HM\Primary_Term\breadcrumbs_taxonomy
 */
class Primary_Term_Breadcrumbs_Test extends WP_UnitTestCase {

	/**
	 * Read by whoever this fires for, years from now, with none of the context.
	 */
	private const DRIFT = <<<'TXT'
		Core no longer chooses the taxonomy breadcrumbs_taxonomy() predicts.

		That function mirrors block_core_breadcrumbs_get_terms_breadcrumbs() in
		wp-includes/blocks/breadcrumbs.php. Re-read core's version and bring the
		mirror back into line. Until you do, this plugin names a taxonomy core
		would not have chosen — and because it names one, core obeys, so the
		wrong taxonomy will be rendered on every post with a primary term.
		TXT;

	public function set_up(): void {
		parent::set_up();

		if ( ! WP_Block_Type_Registry::get_instance()->is_registered( 'core/breadcrumbs' ) ) {
			$this->markTestSkipped( 'The core/breadcrumbs block requires WordPress 7.0 or later.' );
		}
	}

	public function tear_down(): void {
		foreach ( $this->registered as $taxonomy ) {
			unregister_taxonomy( $taxonomy );
		}

		$this->registered = [];

		parent::tear_down();
	}

	/**
	 * Taxonomies a test registered, so they can be taken away again. A leaked
	 * one would change which taxonomy core walks to in every later test.
	 *
	 * @var string[]
	 */
	private array $registered = [];

	/**
	 * Register a taxonomy on `post` for the duration of one test.
	 *
	 * @param string $taxonomy Taxonomy name.
	 * @param array  $args     Registration arguments, over public and in REST.
	 */
	private function register_taxonomy_for_test( string $taxonomy, array $args = [] ): void {
		register_taxonomy( $taxonomy, 'post', [ 'public' => true, 'show_in_rest' => true, ...$args ] );

		$this->registered[] = $taxonomy;
	}

	public function test_it_shows_the_chosen_primary_rather_than_the_first_term(): void {
		[ $post, , , $cinema ] = $this->post_with_categories();

		update_post_meta( $post, meta_key( 'category' ), $cinema );

		$trail = $this->trail( $post );

		$this->assertContains( 'Cinema', $trail );
		$this->assertNotContains( 'Advertising', $trail );
	}

	/**
	 * Without a choice there is nothing to say, and core's own first-term
	 * behaviour has to survive the filter untouched.
	 */
	public function test_it_leaves_core_s_first_term_alone_without_a_choice(): void {
		[ $post ] = $this->post_with_categories();

		$trail = $this->trail( $post );

		$this->assertContains( 'Advertising', $trail );
		$this->assertNotContains( 'Cinema', $trail );
	}

	/**
	 * `post_tag` is not enabled by default, so the stored meta below is not a
	 * choice this plugin made and it must not act on it.
	 */
	public function test_it_stays_out_of_a_taxonomy_it_does_not_cover(): void {
		[ $post, , $brands ] = $this->post_with_tags();

		update_post_meta( $post, meta_key( 'post_tag' ), $brands );

		$trail = $this->trail( $post );

		$this->assertContains( 'Agencies', $trail );
		$this->assertNotContains( 'Brands', $trail );
	}

	public function test_it_works_for_a_taxonomy_other_than_category(): void {
		add_filter( 'hm_primary_term_taxonomies', fn( $taxonomies ) => [ ...$taxonomies, 'post_tag' ] );

		[ $post, , $brands ] = $this->post_with_tags();

		update_post_meta( $post, meta_key( 'post_tag' ), $brands );

		$trail = $this->trail( $post );

		$this->assertContains( 'Brands', $trail );
		$this->assertNotContains( 'Agencies', $trail );
	}

	/**
	 * The plugin follows core to a taxonomy; it never picks one.
	 *
	 * Both taxonomies are enabled and both have a stored primary, so the only
	 * thing that can separate them is core's own walk — which reaches
	 * `category` first. The tag primary below is set deliberately and must be
	 * ignored deliberately.
	 */
	public function test_it_follows_core_s_taxonomy_rather_than_choosing_one(): void {
		add_filter( 'hm_primary_term_taxonomies', fn( $taxonomies ) => [ ...$taxonomies, 'post_tag' ] );

		[ $post, , , $cinema ] = $this->post_with_categories();

		$agencies = self::factory()->tag->create( [ 'name' => 'Agencies' ] );
		$brands   = self::factory()->tag->create( [ 'name' => 'Brands' ] );
		wp_set_object_terms( $post, [ $agencies, $brands ], 'post_tag' );

		update_post_meta( $post, meta_key( 'category' ), $cinema );
		update_post_meta( $post, meta_key( 'post_tag' ), $brands );

		$trail = $this->trail( $post );

		$this->assertContains( 'Cinema', $trail );
		$this->assertNotContains( 'Brands', $trail );
	}

	/**
	 * Consistent with `get_primary_term()`: the choice is honoured only while
	 * the post still has the term.
	 */
	public function test_unticking_the_chosen_term_falls_back_to_the_first(): void {
		[ $post, $advertising, $broadcast, $cinema ] = $this->post_with_categories();

		update_post_meta( $post, meta_key( 'category' ), $cinema );
		wp_set_object_terms( $post, [ $advertising, $broadcast ], 'category' );

		$trail = $this->trail( $post );

		$this->assertContains( 'Advertising', $trail );
		$this->assertNotContains( 'Cinema', $trail );
	}

	/**
	 * A filter that already named a term asked more specifically than this one.
	 */
	public function test_a_term_named_by_another_filter_wins(): void {
		[ $post, $advertising, , $cinema ] = $this->post_with_categories();

		update_post_meta( $post, meta_key( 'category' ), $cinema );

		add_filter(
			'block_core_breadcrumbs_post_type_settings',
			function ( $settings ) use ( $advertising ) {
				$settings['term'] = get_term( $advertising )->slug;

				return $settings;
			},
			5
		);

		$trail = $this->trail( $post );

		$this->assertContains( 'Advertising', $trail );
		$this->assertNotContains( 'Cinema', $trail );
	}

	/**
	 * A slug alone is ambiguous, which is why the taxonomy travels with it.
	 *
	 * `wp_unique_term_slug()` has only de-duplicated within a taxonomy since
	 * WordPress 4.1, so two taxonomies can hold the same slug — asserted below
	 * rather than assumed, because the whole test rests on it. The caller has
	 * asked for `sector`; resolving `media` anywhere else pins a term nobody
	 * chose, and core would otherwise settle on `category`.
	 */
	public function test_it_pins_the_right_term_when_a_slug_spans_two_taxonomies(): void {
		$this->register_taxonomy_for_test( 'sector' );

		add_filter( 'hm_primary_term_taxonomies', fn() => [ 'sector' ] );
		$this->name_taxonomy( 'sector' );

		$week = self::factory()->category->create( [ 'name' => 'Media Week', 'slug' => 'media' ] );
		$ads  = self::factory()->category->create( [ 'name' => 'Advertising' ] );

		$sector = self::factory()->term->create( [ 'taxonomy' => 'sector', 'name' => 'Media Sector', 'slug' => 'media' ] );
		$other  = self::factory()->term->create( [ 'taxonomy' => 'sector', 'name' => 'Agencies Sector' ] );

		$this->assertSame( 'media', get_term( $week )->slug );
		$this->assertSame( 'media', get_term( $sector )->slug, 'Core no longer allows a slug in two taxonomies.' );

		$post = self::factory()->post->create();
		wp_set_object_terms( $post, [ $ads, $week ], 'category' );
		wp_set_object_terms( $post, [ $other, $sector ], 'sector' );
		update_post_meta( $post, meta_key( 'sector' ), $sector );

		$trail = $this->trail( $post );

		$this->assertContains( 'Media Sector', $trail );
		$this->assertNotContains( 'Media Week', $trail );
	}

	/**
	 * An enabled taxonomy core would not render cannot take the answer with it.
	 *
	 * Asking for a taxonomy that is not publicly queryable asks for something
	 * core will not render, so core's own choice has to stand — and the
	 * colliding slug below is what that costs if the eligibility test inside
	 * the mirror is ever dropped.
	 */
	public function test_it_never_names_a_taxonomy_core_would_not_render(): void {
		$this->register_taxonomy_for_test( 'internal', [ 'public' => false, 'publicly_queryable' => false, 'show_in_rest' => false ] );

		add_filter( 'hm_primary_term_taxonomies', fn() => [ 'internal' ] );
		$this->name_taxonomy( 'internal' );

		[ $post, , $brands ] = $this->post_with_tags();

		// Deliberately the second tag's slug: if the eligibility test goes, this
		// is the term core matches instead, in a taxonomy nobody asked it for.
		$desk = self::factory()->term->create( [ 'taxonomy' => 'internal', 'name' => 'Brands Desk', 'slug' => get_term( $brands )->slug ] );
		wp_set_object_terms( $post, [ $desk ], 'internal' );
		update_post_meta( $post, meta_key( 'internal' ), $desk );

		$this->assertSame( get_term( $brands )->slug, get_term( $desk )->slug );

		$trail = $this->trail( $post );

		$this->assertContains( 'Agencies', $trail );
		$this->assertNotContains( 'Brands Desk', $trail );
	}

	/**
	 * The contract a site leans on to choose the taxonomy itself.
	 *
	 * Core would walk to `category`, which is registered first and has terms.
	 * The caller asks for `sector` instead, and gets that taxonomy's *primary*
	 * term rather than its first — the taxonomy is the site's decision and the
	 * term is this plugin's.
	 */
	public function test_it_resolves_the_primary_within_a_taxonomy_the_caller_names(): void {
		$this->register_taxonomy_for_test( 'sector' );

		add_filter( 'hm_primary_term_taxonomies', fn( $taxonomies ) => [ ...$taxonomies, 'sector' ] );
		$this->name_taxonomy( 'sector' );

		[ $post ] = $this->post_with_categories();

		$broadcast = self::factory()->term->create( [ 'taxonomy' => 'sector', 'name' => 'Broadcast Sector' ] );
		$cinema    = self::factory()->term->create( [ 'taxonomy' => 'sector', 'name' => 'Cinema Sector' ] );
		wp_set_object_terms( $post, [ $broadcast, $cinema ], 'sector' );
		update_post_meta( $post, meta_key( 'sector' ), $cinema );

		$trail = $this->trail( $post );

		$this->assertContains( 'Cinema Sector', $trail );
		$this->assertNotContains( 'Broadcast Sector', $trail );
		$this->assertNotContains( 'Advertising', $trail );
	}

	/**
	 * A named taxonomy the post has no terms in falls back to core's walk,
	 * because core falls back too — honouring it regardless would answer for a
	 * taxonomy that cannot appear in the trail, and so answer not at all.
	 */
	public function test_a_named_taxonomy_with_no_terms_falls_back_to_core_s_walk(): void {
		$this->register_taxonomy_for_test( 'sector' );
		$this->name_taxonomy( 'sector' );

		[ $post, , , $cinema ] = $this->post_with_categories();

		update_post_meta( $post, meta_key( 'category' ), $cinema );

		$trail = $this->trail( $post );

		$this->assertContains( 'Cinema', $trail );
		$this->assertNotContains( 'Advertising', $trail );
	}

	/**
	 * Stand in for a site naming the taxonomy in its own filter.
	 *
	 * Priority 5, so it lands before the plugin's own at 10.
	 *
	 * @param string $taxonomy Taxonomy name.
	 */
	private function name_taxonomy( string $taxonomy ): void {
		add_filter(
			'block_core_breadcrumbs_post_type_settings',
			function ( $settings ) use ( $taxonomy ) {
				$settings['taxonomy'] = $taxonomy;

				return $settings;
			},
			5
		);
	}

	/**
	 * Drift canary: core still picks the taxonomy the mirror predicts.
	 *
	 * Every other test here renders with the plugin's filter attached, which
	 * names the taxonomy — so core obeys and the trail reflects the mirror's
	 * own choice. None of them can notice the mirror going stale. This one
	 * detaches the filter and compares core's unaided choice with the
	 * prediction directly.
	 */
	public function test_the_mirror_still_matches_core_s_taxonomy_choice(): void {
		$this->register_taxonomy_for_test( 'sector' );

		[ $post, $labels ] = $this->post_with_labelled_terms( [ 'category', 'post_tag', 'sector' ] );

		$this->assertSame(
			$this->taxonomy_core_shows( $post, $labels ),
			breadcrumbs_taxonomy( '', 'post', $post ),
			self::DRIFT
		);
	}

	/**
	 * The same canary for the preferred-taxonomy arm: a named taxonomy the post
	 * has no terms in, where core falls back to its own walk.
	 */
	public function test_the_mirror_still_matches_core_s_fallback_from_a_named_taxonomy(): void {
		$this->register_taxonomy_for_test( 'sector' );
		$this->name_taxonomy( 'sector' );

		// Deliberately no sector terms, so the named taxonomy cannot be used.
		[ $post, $labels ] = $this->post_with_labelled_terms( [ 'category', 'post_tag' ] );

		$this->assertSame(
			$this->taxonomy_core_shows( $post, $labels ),
			breadcrumbs_taxonomy( 'sector', 'post', $post ),
			self::DRIFT
		);
	}

	/**
	 * Which taxonomy core puts in the trail when left to itself.
	 *
	 * @param int      $post_id Post ID.
	 * @param string[] $labels  Term label to taxonomy, from the fixture.
	 * @return string Taxonomy name.
	 */
	private function taxonomy_core_shows( int $post_id, array $labels ): string {
		$filter   = 'block_core_breadcrumbs_post_type_settings';
		$callback = 'HM\\Primary_Term\\filter_breadcrumbs_settings';

		// Also the proof that the string below matches what `bootstrap()`
		// registered: `remove_filter()` fails silently when it does not.
		$this->assertSame( 10, has_filter( $filter, $callback ), 'The plugin filter is not attached under the name this test detaches.' );

		remove_filter( $filter, $callback );

		try {
			$shown = array_values( array_intersect( $this->trail( $post_id ), array_keys( $labels ) ) );
		} finally {
			add_filter( $filter, $callback, 10, 3 );
		}

		$this->assertCount( 1, $shown, 'Core rendered no term this fixture recognises. The fixture is wrong, not the plugin.' );

		return $labels[ $shown[0] ];
	}

	/**
	 * A post with two terms in each of several taxonomies, each term labelled
	 * with the taxonomy it belongs to so the rendered trail is unambiguous.
	 *
	 * @param string[] $taxonomies Taxonomies to give the post terms in.
	 * @return array [ post_id, [ term label => taxonomy ] ]
	 */
	private function post_with_labelled_terms( array $taxonomies ): array {
		$post   = self::factory()->post->create();
		$labels = [];

		wp_set_object_terms( $post, [], 'category' );

		foreach ( $taxonomies as $taxonomy ) {
			foreach ( [ 'Advertising', 'Broadcast' ] as $name ) {
				$label = sprintf( '%s (%s)', $name, $taxonomy );
				$term  = self::factory()->term->create( [ 'taxonomy' => $taxonomy, 'name' => $label ] );

				wp_set_object_terms( $post, [ $term ], $taxonomy, true );

				$labels[ $label ] = $taxonomy;
			}
		}

		return [ $post, $labels ];
	}

	/**
	 * The labels of a rendered breadcrumb trail, in order.
	 *
	 * The query is set to the post first: the block only builds a term trail on
	 * a singular view, and bails out of everything else before it reaches the
	 * filter under test.
	 *
	 * @param int $post_id Post ID.
	 * @return string[]
	 */
	private function trail( int $post_id ): array {
		$this->go_to( get_permalink( $post_id ) );

		$block = new WP_Block(
			[
				'blockName' => 'core/breadcrumbs',
				// Terms, not post ancestors — the hierarchical trail never
				// reaches the filter.
				'attrs' => [ 'prefersTaxonomy' => true ],
				'innerBlocks' => [],
				'innerHTML' => '',
				'innerContent' => [],
			],
			[
				'postId' => $post_id,
				'postType' => get_post_type( $post_id ),
			]
		);

		preg_match_all( '#<li>(?:<a[^>]*>|<span[^>]*>)(.*?)</(?:a|span)></li>#s', $block->render(), $matches );

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

	/**
	 * A post with tags and no categories, returned as [ post_id, ...term_ids ].
	 *
	 * No categories, so core falls past `category` to `post_tag` — which is how
	 * the breadcrumb trail ends up on a taxonomy other than the first one
	 * registered for the post type.
	 *
	 * @return int[]
	 */
	private function post_with_tags(): array {
		$agencies = self::factory()->tag->create( [ 'name' => 'Agencies' ] );
		$brands   = self::factory()->tag->create( [ 'name' => 'Brands' ] );

		$post = self::factory()->post->create();
		wp_set_object_terms( $post, [], 'category' );
		wp_set_object_terms( $post, [ $agencies, $brands ], 'post_tag' );

		return [ $post, $agencies, $brands ];
	}
}
