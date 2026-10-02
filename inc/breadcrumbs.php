<?php
/**
 * The primary term in core's Breadcrumbs block.
 *
 * `core/breadcrumbs` ends a single post's trail with one of its terms, and
 * picks that term with `reset()` — whichever the post's term list happens to
 * start with. On a post in three sectors that is an accident of naming, not an
 * editorial decision, and it is exactly the decision this plugin already stores.
 *
 * Scoped to the term, deliberately. Which taxonomy a breadcrumb trail should
 * follow is a question about a site's information architecture, not about who
 * has a primary-term picker, so it stays with the site: set `taxonomy` in your
 * own `block_core_breadcrumbs_post_type_settings` filter and this resolves the
 * primary within it.
 *
 * The block arrived in WordPress 7.0. On anything older the filter below never
 * fires, so there is nothing to guard.
 *
 * @package HM\Primary_Term
 */

namespace HM\Primary_Term;

/**
 * Name the primary term for core's Breadcrumbs block.
 *
 * Two branches, and neither of them decides which taxonomy to show. A caller
 * that has named a taxonomy gets the primary term of that one; a caller that
 * has not gets the primary term of the taxonomy core would have reached for
 * anyway. The plugin answers "which term" and nothing else.
 *
 * The taxonomy is written back alongside the term even in the first branch,
 * where it is only an echo of what the caller asked for. Core matches `term` as
 * a slug, and a slug does not carry its taxonomy with it — since WordPress 4.1
 * `wp_unique_term_slug()` only de-duplicates inside a taxonomy, so a sector
 * `media` and a topic `media` coexist by design. Sending the pair together is
 * what guarantees the slug is read in the taxonomy it was taken from.
 *
 * Silent unless the resolved taxonomy is one of `taxonomies()`: a taxonomy with
 * no picker has no stored choice, so its primary term already is its first
 * term and core needs no help. Silent too when another filter has named a term,
 * which is a more specific request than this one.
 *
 * @param array  $settings  Breadcrumb settings: `taxonomy` name and `term` slug.
 * @param string $post_type The post type slug.
 * @param int    $post_id   The post ID.
 * @return array
 */
function filter_breadcrumbs_settings( $settings, $post_type, $post_id ): array {
	$settings = is_array( $settings ) ? $settings : [];

	if ( ! empty( $settings['term'] ) ) {
		return $settings;
	}

	$taxonomy = breadcrumbs_taxonomy( (string) ( $settings['taxonomy'] ?? '' ), (string) $post_type, (int) $post_id );

	if ( '' === $taxonomy || ! in_array( $taxonomy, taxonomies(), true ) ) {
		return $settings;
	}

	$term = get_primary_term( (int) $post_id, $taxonomy );

	if ( ! $term ) {
		return $settings;
	}

	$settings['taxonomy'] = $taxonomy;
	$settings['term']     = $term->slug;

	return $settings;
}

/**
 * The taxonomy core's Breadcrumbs block would show this post.
 *
 * A mirror of `block_core_breadcrumbs_get_terms_breadcrumbs()`: the preferred
 * taxonomy if the post has terms in it, otherwise the first publicly queryable,
 * REST-exposed taxonomy on the post type that does. The eligibility test is
 * part of that mirror rather than a rule of this plugin's own — core will not
 * render anything else, so neither will this name it.
 *
 * Repeating core's walk is what lets the plugin pin a term without moving the
 * taxonomy. The cost, honestly: that rule is private, so a change to it in a
 * minor release would have this naming a taxonomy core would not have. The
 * alternative was to pick a taxonomy on the site's behalf, which is a decision
 * no plugin should be making.
 *
 * @param string $preferred Taxonomy another filter asked for, if any.
 * @param string $post_type The post type slug.
 * @param int    $post_id   The post ID.
 * @return string Taxonomy name, or an empty string when the post has no terms.
 */
function breadcrumbs_taxonomy( string $preferred, string $post_type, int $post_id ): string {
	$candidates = array_values(
		wp_list_pluck(
			wp_filter_object_list(
				get_object_taxonomies( $post_type, 'objects' ),
				[
					'publicly_queryable' => true,
					'show_in_rest'       => true,
				]
			),
			'name'
		)
	);

	// Tried first, not exclusively: core falls back to its own walk when the
	// preferred taxonomy has no terms on the post, so this has to as well.
	if ( '' !== $preferred && in_array( $preferred, $candidates, true ) ) {
		array_unshift( $candidates, $preferred );
	}

	foreach ( $candidates as $taxonomy ) {
		$terms = get_the_terms( $post_id, $taxonomy );

		if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
			return $taxonomy;
		}
	}

	return '';
}
