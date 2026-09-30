<?php
/**
 * The editor scripts: a "Primary <term>" control inside the core taxonomy
 * panel, and a "Primary term only" toggle on core's Post Terms block.
 *
 * The choice is about the post's terms, so its control belongs beside them
 * rather than in a panel of its own — the same place Yoast SEO puts its primary
 * category control. Everything both scripts need is handed to them from here,
 * so one enabled-taxonomy list serves any taxonomy the filter enables.
 *
 * @package HM\Primary_Term
 */

namespace HM\Primary_Term;

/**
 * Handle of the taxonomy picker script.
 */
const EDITOR_HANDLE = 'hm-primary-term-editor';

/**
 * Handle of the block extensions script.
 */
const BLOCKS_HANDLE = 'hm-primary-term-blocks';

/**
 * What the editor script needs to render a control per enabled taxonomy, keyed
 * by taxonomy name.
 *
 * Labels are built here rather than in JS: the plugin has no build step, so it
 * ships no JS translations, and a string assembled in PHP is translated like
 * every other one.
 *
 * @return array<string, array<string, mixed>>
 */
function editor_data(): array {
	$data = [];

	foreach ( taxonomies() as $taxonomy ) {
		$object = get_taxonomy( $taxonomy );

		if ( ! $object ) {
			continue;
		}

		$singular = $object->labels->singular_name ?? $taxonomy;

		$data[ $taxonomy ] = [
			'slug' => $taxonomy,

			/*
			 * The REST base, not the taxonomy name: a post's assigned terms
			 * arrive from the editor store under `categories` and `tags`, not
			 * `category` and `post_tag`. Get this wrong and the control
			 * silently sees no terms.
			 */
			'restBase' => $object->rest_base ?: $taxonomy,
			'metaKey' => meta_key( $taxonomy ),
			'label' => sprintf(
				/* translators: %s: taxonomy singular name, e.g. "Category". */
				__( 'Primary %s', 'hm-primary-category' ),
				$singular
			),
			'placeholder' => sprintf(
				/* translators: %s: taxonomy singular name, e.g. "Category". */
				__( '— Choose a primary %s —', 'hm-primary-category' ),
				$singular
			),

			// The post types the meta is registered for, so the script can bail
			// on any other screen the taxonomy panel appears on.
			'postTypes' => array_values( $object->object_type ),
		];
	}

	return $data;
}

/**
 * Enqueue the editor scripts.
 *
 * The block toggle goes on every block editor screen: a Post Terms block is
 * usually edited in a template rather than in a post, where there is no post
 * type to test against. The picker is narrower — the post-type test mirrors
 * `register_meta_fields()`, so the picker is offered on exactly the screens the
 * meta exists for and the two cannot disagree.
 */
function enqueue_editor_assets(): void {
	$data = editor_data();

	if ( empty( $data ) ) {
		return;
	}

	wp_enqueue_script(
		BLOCKS_HANDLE,
		plugins_url( 'blocks.js', __FILE__ ),
		[ 'wp-block-editor', 'wp-blocks', 'wp-components', 'wp-compose', 'wp-element', 'wp-hooks', 'wp-i18n' ],
		filemtime( __DIR__ . '/blocks.js' ),
		true
	);

	// Both scripts read this, so it hangs off the handle that is always
	// enqueued and the picker declares a dependency on that handle.
	wp_add_inline_script(
		BLOCKS_HANDLE,
		'window.hmPrimaryTerm = ' . wp_json_encode( $data ) . ';',
		'before'
	);

	$screen = get_current_screen();

	if ( ! $screen || ! $screen->post_type ) {
		return;
	}

	$for_this_post_type = array_filter(
		$data,
		fn( array $taxonomy ): bool => in_array( $screen->post_type, $taxonomy['postTypes'], true )
	);

	if ( empty( $for_this_post_type ) ) {
		return;
	}

	wp_enqueue_script(
		EDITOR_HANDLE,
		plugins_url( 'editor.js', __FILE__ ),
		[ BLOCKS_HANDLE, 'wp-components', 'wp-compose', 'wp-data', 'wp-editor', 'wp-element', 'wp-hooks', 'wp-i18n' ],
		filemtime( __DIR__ . '/editor.js' ),
		true
	);
}
