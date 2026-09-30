<?php
/**
 * Integration tests for the editor scripts' PHP side: the data handed to them,
 * and the screens each is enqueued on.
 *
 * The scripts themselves need a browser and are verified separately.
 */

declare( strict_types=1 );

use function HM\Primary_Term\editor_data;
use function HM\Primary_Term\enqueue_editor_assets;
use function HM\Primary_Term\meta_key;

/**
 * @covers \HM\Primary_Term\editor_data
 * @covers \HM\Primary_Term\enqueue_editor_assets
 */
class Primary_Term_Editor_Test extends WP_UnitTestCase {

	/**
	 * The taxonomy picker's script handle.
	 */
	private const HANDLE = 'hm-primary-term-editor';

	/**
	 * The block extensions' script handle, which also carries the shared data.
	 */
	private const BLOCKS_HANDLE = 'hm-primary-term-blocks';

	public function set_up(): void {
		parent::set_up();

		// `get_current_screen()` and `WP_Screen` are admin-only, and the
		// enqueue is an admin hook.
		require_once ABSPATH . 'wp-admin/includes/screen.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';

		// Each test starts with an empty queue, so "not enqueued" means this
		// test rather than a leftover from the last one.
		$GLOBALS['wp_scripts'] = null;
	}

	public function tear_down(): void {
		$GLOBALS['wp_scripts'] = null;

		parent::tear_down();
	}

	public function test_the_data_describes_category(): void {
		$data = editor_data();

		$this->assertArrayHasKey( 'category', $data );

		$category = $data['category'];

		$this->assertSame( 'category', $category['slug'] );
		$this->assertSame( meta_key( 'category' ), $category['metaKey'] );
		$this->assertSame( 'Primary Category', $category['label'] );
		$this->assertContains( 'post', $category['postTypes'] );

		// The REST base, not the taxonomy name: the editor store keys a post's
		// assigned terms as `categories`.
		$this->assertSame( 'categories', $category['restBase'] );
	}

	public function test_an_enabled_taxonomy_is_reported_under_its_own_rest_base(): void {
		add_filter( 'hm_primary_term_taxonomies', fn( $taxonomies ) => [ ...$taxonomies, 'post_tag' ] );

		$data = editor_data();

		$this->assertArrayHasKey( 'post_tag', $data );
		$this->assertSame( 'tags', $data['post_tag']['restBase'] );
		$this->assertSame( 'Primary Tag', $data['post_tag']['label'] );
	}

	public function test_a_custom_rest_base_is_used_in_place_of_the_taxonomy_name(): void {
		register_taxonomy(
			'hm_sector',
			'post',
			[
				'show_in_rest' => true,
				'rest_base' => 'sectors',
				'labels' => [ 'singular_name' => 'Sector' ],
			]
		);

		add_filter( 'hm_primary_term_taxonomies', fn( $taxonomies ) => [ ...$taxonomies, 'hm_sector' ] );

		try {
			$data = editor_data();

			$this->assertSame( 'sectors', $data['hm_sector']['restBase'] );
			$this->assertSame( 'hm_sector', $data['hm_sector']['slug'] );
			$this->assertSame( 'Primary Sector', $data['hm_sector']['label'] );
		} finally {
			unregister_taxonomy( 'hm_sector' );
		}
	}

	public function test_both_scripts_load_on_a_post_screen(): void {
		set_current_screen( 'post' );
		enqueue_editor_assets();

		$this->assertTrue( wp_script_is( self::HANDLE, 'enqueued' ) );
		$this->assertTrue( wp_script_is( self::BLOCKS_HANDLE, 'enqueued' ) );

		// The picker depends on the handle the data hangs off, so it cannot run
		// before `window.hmPrimaryTerm` is defined.
		$this->assertContains( self::BLOCKS_HANDLE, wp_scripts()->registered[ self::HANDLE ]->deps );
	}

	public function test_the_data_is_carried_by_the_always_enqueued_handle(): void {
		set_current_screen( 'post' );
		enqueue_editor_assets();

		$inline = implode( '', (array) wp_scripts()->get_data( self::BLOCKS_HANDLE, 'before' ) );

		$this->assertStringContainsString( 'window.hmPrimaryTerm =', $inline );
		$this->assertStringContainsString( '"restBase":"categories"', $inline );
	}

	public function test_nothing_is_enqueued_when_no_taxonomy_is_enabled(): void {
		add_filter( 'hm_primary_term_taxonomies', '__return_empty_array' );

		set_current_screen( 'post' );
		enqueue_editor_assets();

		$this->assertSame( [], editor_data() );
		$this->assertFalse( wp_script_is( self::HANDLE, 'enqueued' ) );
		$this->assertFalse( wp_script_is( self::BLOCKS_HANDLE, 'enqueued' ) );
	}

	public function test_the_picker_is_skipped_for_a_post_type_the_taxonomy_is_not_attached_to(): void {
		set_current_screen( 'page' );
		enqueue_editor_assets();

		$this->assertFalse( wp_script_is( self::HANDLE, 'enqueued' ) );

		// The block toggle still loads: a Post Terms block on a page's template
		// still shows a post's terms.
		$this->assertTrue( wp_script_is( self::BLOCKS_HANDLE, 'enqueued' ) );
	}
}
