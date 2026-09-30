<?php
/**
 * The editor picker: a "Primary <term>" control inside the core taxonomy panel.
 *
 * The choice is about the post's terms, so it belongs beside them rather than
 * in a panel of its own — the same place Yoast SEO puts its primary category
 * control. Everything the script needs is handed to it from here, so one script
 * serves any taxonomy the filter enables.
 *
 * @package HM\Primary_Term
 */

namespace HM\Primary_Term;

/**
 * Handle of the editor script.
 */
const EDITOR_HANDLE = 'hm-primary-term-editor';

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
 * Enqueue the picker on block editor screens that can use it.
 *
 * The post-type test mirrors `register_meta_fields()`: the meta exists for the
 * post types each enabled taxonomy is attached to, so the picker is offered on
 * exactly those screens and the two cannot disagree.
 */
function enqueue_editor_assets(): void {
	$screen = get_current_screen();

	if ( ! $screen || ! $screen->post_type ) {
		return;
	}

	$data = editor_data();

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
		[ 'wp-components', 'wp-compose', 'wp-data', 'wp-editor', 'wp-element', 'wp-hooks', 'wp-i18n' ],
		filemtime( __DIR__ . '/editor.js' ),
		true
	);

	// The whole map, not just this post type's share: the script filters on
	// `postTypes` itself, and the panel is the only thing that has to match.
	wp_add_inline_script(
		EDITOR_HANDLE,
		'window.hmPrimaryTerm = ' . wp_json_encode( $data ) . ';',
		'before'
	);
}
