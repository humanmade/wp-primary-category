<?php
/**
 * A "primary term only" option on core's Post Terms block.
 *
 * The explicit, opt-in replacement for the global `get_the_terms` filter this
 * plugin used to carry: a block asks for the primary term, and nothing else in
 * the request sees a changed term order.
 *
 * @package HM\Primary_Term
 */

namespace HM\Primary_Term;

/**
 * The block the option is added to.
 */
const POST_TERMS_BLOCK = 'core/post-terms';

/**
 * Markup core joins a term list with, and the optional tail after it.
 *
 * Matched rather than rebuilt: core owns these classes and the styles hung off
 * them, and a hand-rolled term list drifts from them at the next release.
 */
const TERMS_SEPARATOR = '<span class="wp-block-post-terms__separator">';
const TERMS_SUFFIX    = '<span class="wp-block-post-terms__suffix">';

/**
 * Declare the `hmPrimaryOnly` attribute on `core/post-terms`.
 *
 * Server side as well as in the editor: `WP_Block` drops any attribute the
 * registered block type does not know about, so without this the saved value
 * never reaches the render callback.
 *
 * @param array $metadata Parsed `block.json` contents.
 * @return array
 */
function register_primary_only_attribute( array $metadata ): array {
	if ( POST_TERMS_BLOCK !== ( $metadata['name'] ?? '' ) ) {
		return $metadata;
	}

	$metadata['attributes']['hmPrimaryOnly'] = [
		'type'    => 'boolean',
		'default' => false,
	];

	return $metadata;
}

/**
 * Wrap core's Post Terms render callback with the primary-term handling.
 *
 * Wrapping the callback, rather than pairing `render_block_data` to add the
 * ordering filter with `render_block` to remove it: `render_block_data` only
 * fires for top-level blocks, so the paired version would miss every Post Terms
 * block inside a Query Loop — which is most of them.
 *
 * @param array  $args Block type registration arguments.
 * @param string $name Block name.
 * @return array
 */
function wrap_post_terms_render( array $args, string $name ): array {
	if ( POST_TERMS_BLOCK !== $name || ! is_callable( $args['render_callback'] ?? null ) ) {
		return $args;
	}

	$render = $args['render_callback'];

	$args['render_callback'] = function ( $attributes, $content, $block ) use ( $render ) {
		return render_post_terms( $attributes, $content, $block, $render );
	};

	return $args;
}

/**
 * Render a Post Terms block, honouring `hmPrimaryOnly`.
 *
 * The ordering filter exists only for the duration of this one block's render,
 * and that scoping is the entire reason it replaced a global hook: a block that
 * did not ask for the primary term first never sees it.
 *
 * `get_primary_term()` works on any taxonomy, so a block pointing at one this
 * plugin has not enabled degrades to that taxonomy's first term rather than
 * erroring.
 *
 * @param array     $attributes Block attributes.
 * @param string    $content    Block content.
 * @param \WP_Block $block      The block instance.
 * @param callable  $render     Core's render callback.
 * @return string
 */
function render_post_terms( $attributes, $content, $block, callable $render ): string {
	$taxonomy = $attributes['term'] ?? '';
	$post_id  = (int) ( $block->context['postId'] ?? 0 );

	if ( empty( $attributes['hmPrimaryOnly'] ) || ! $taxonomy || ! $post_id ) {
		return (string) $render( $attributes, $content, $block );
	}

	$primary = get_primary_term( $post_id, $taxonomy );

	if ( ! $primary ) {
		return (string) $render( $attributes, $content, $block );
	}

	// Narrowed to this post and taxonomy: core's callback is not the only thing
	// reading terms while it runs.
	$sort_first = static function ( $terms, $object_id, $object_taxonomy ) use ( $post_id, $taxonomy, $primary ) {
		if ( (int) $object_id !== $post_id || $object_taxonomy !== $taxonomy || ! is_array( $terms ) ) {
			return $terms;
		}

		$rest = array_filter( $terms, fn( $term ) => $term->term_id !== $primary->term_id );

		return array_merge( [ $primary ], array_values( $rest ) );
	};

	add_filter( 'get_the_terms', $sort_first, 10, 3 );

	try {
		$rendered = (string) $render( $attributes, $content, $block );
	} finally {
		remove_filter( 'get_the_terms', $sort_first, 10 );
	}

	return first_term_only( $rendered );
}

/**
 * Cut a rendered term list down to its first term.
 *
 * Core joins terms with a single separator span and closes with an optional
 * suffix span, so the first separator marks the end of the first term and the
 * suffix — or failing that the wrapper's own closing tag — marks where the tail
 * resumes. A post with one term has no separator and comes back untouched.
 *
 * @param string $rendered Rendered block markup.
 * @return string
 */
function first_term_only( string $rendered ): string {
	$cut = strpos( $rendered, TERMS_SEPARATOR );

	if ( false === $cut ) {
		return $rendered;
	}

	$suffix = strrpos( $rendered, TERMS_SUFFIX );
	$tail   = ( false !== $suffix && $suffix > $cut ) ? $suffix : strrpos( $rendered, '</div>' );

	if ( false === $tail ) {
		return substr( $rendered, 0, $cut );
	}

	return substr( $rendered, 0, $cut ) . substr( $rendered, $tail );
}
