<?php
/**
 * A `hm/primary-term` Block Bindings source.
 *
 * The Post Terms option renders the primary term as a term list, which is the
 * right answer for a byline and the wrong one for a heading, a button URL or a
 * sentence. This binds the bare value — name, slug or archive URL — into any
 * block attribute that accepts one, so a consumer stops writing PHP for it.
 *
 * Deliberately no `format` or template argument. A translatable string parked
 * in block markup is out of reach of the site's own translations, and the point
 * of a binding is that it carries a value, not presentation.
 *
 * @package HM\Primary_Term
 */

namespace HM\Primary_Term;

/**
 * The registered source name.
 */
const BINDING_SOURCE = 'hm/primary-term';

/**
 * Register the binding source.
 *
 * Guarded rather than required: Block Bindings arrived in WordPress 6.5, and
 * the rest of the plugin has no opinion about them. On anything older every
 * bound block simply keeps its own fallback content.
 */
function register_binding_source(): void {
	if ( ! function_exists( 'register_block_bindings_source' ) ) {
		return;
	}

	register_block_bindings_source(
		BINDING_SOURCE,
		[
			'label'              => __( 'Primary term', 'hm-primary-category' ),
			'get_value_callback' => __NAMESPACE__ . '\\get_binding_value',
			'uses_context'       => [ 'postId', 'postType' ],
		]
	);
}

/**
 * Resolve a bound attribute to the post's primary term.
 *
 * Returning null is a supported answer, not a failure: core leaves the block's
 * own saved content in place, so an uncategorised post falls back to whatever
 * the editor typed rather than rendering an empty heading or a dead link.
 *
 * `url` goes through `get_term_link()`, which handles any taxonomy — there is
 * no reason to special-case `category`.
 *
 * @param array     $source_args    Arguments from the block's binding: `taxonomy`
 *                                  (default `category`) and `key`, one of `name`,
 *                                  `url` or `slug`.
 * @param \WP_Block $block          The bound block, for its `postId` context.
 * @param string    $attribute_name The attribute being bound. Unused: the value
 *                                  depends on `key`, not on where it lands.
 * @return string|null
 */
function get_binding_value( array $source_args, $block, string $attribute_name ): ?string { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable, Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Fixed `get_value_callback` signature.
	$post_id = (int) ( $block->context['postId'] ?? 0 );

	if ( ! $post_id ) {
		return null;
	}

	$taxonomy = $source_args['taxonomy'] ?? 'category';
	$key      = $source_args['key'] ?? '';

	if ( ! is_string( $taxonomy ) || ! is_string( $key ) ) {
		return null;
	}

	$term = get_primary_term( $post_id, $taxonomy );

	if ( ! $term ) {
		return null;
	}

	switch ( $key ) {
		case 'name':
			return $term->name;

		case 'slug':
			return $term->slug;

		case 'url':
			$link = get_term_link( $term );

			return is_wp_error( $link ) ? null : $link;
	}

	return null;
}
