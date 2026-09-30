<?php
/**
 * Integration tests for the editor picker's PHP side: the data handed to the
 * script, and the screens it is enqueued on.
 *
 * The script itself needs a browser and is verified separately.
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
	 * The script handle under test.
	 */
	private const HANDLE = 'hm-primary-term-editor';

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

	public function test_the_script_carries_the_data_on_a_post_screen(): void {
		set_current_screen( 'post' );
		enqueue_editor_assets();

		$this->assertTrue( wp_script_is( self::HANDLE, 'enqueued' ) );

		$inline = implode( '', (array) wp_scripts()->get_data( self::HANDLE, 'before' ) );

		$this->assertStringContainsString( 'window.hmPrimaryTerm =', $inline );
		$this->assertStringContainsString( '"restBase":"categories"', $inline );
	}

	public function test_nothing_is_enqueued_when_no_taxonomy_is_enabled(): void {
		add_filter( 'hm_primary_term_taxonomies', '__return_empty_array' );

		set_current_screen( 'post' );
		enqueue_editor_assets();

		$this->assertSame( [], editor_data() );
		$this->assertFalse( wp_script_is( self::HANDLE, 'enqueued' ) );
	}

	public function test_nothing_is_enqueued_for_a_post_type_the_taxonomy_is_not_attached_to(): void {
		set_current_screen( 'page' );
		enqueue_editor_assets();

		$this->assertFalse( wp_script_is( self::HANDLE, 'enqueued' ) );
	}
}
