<?php
/**
 * The public API: read, write and order a post's primary term.
 *
 * @package HM\Primary_Term
 */

namespace HM\Primary_Term;

/**
 * Meta key prefix.
 *
 * Mirrors Yoast SEO's `_yoast_wpseo_primary_{$taxonomy}` shape so migrating a
 * site off Yoast is a straight key rename rather than a data transform.
 */
const META_PREFIX = '_hm_primary_';

/**
 * The taxonomies a primary term can be chosen for.
 *
 * Filtered on every call rather than cached, so a consumer can hook this from
 * anywhere in the load order — including turning the feature off entirely by
 * returning an empty array.
 *
 * Anything that is not a registered taxonomy is dropped: a typo in the filter
 * should degrade to "no picker" rather than fatal somewhere downstream.
 *
 * @return string[]
 */
function taxonomies(): array {
	/**
	 * Filter the taxonomies that support a primary term.
	 *
	 * @param string[] $taxonomies Taxonomy names.
	 */
	$taxonomies = apply_filters( 'hm_primary_term_taxonomies', [ 'category' ] );

	if ( ! is_array( $taxonomies ) ) {
		return [];
	}

	return array_values(
		array_filter(
			$taxonomies,
			fn( $taxonomy ) => is_string( $taxonomy ) && taxonomy_exists( $taxonomy )
		)
	);
}

/**
 * The post meta key holding a taxonomy's chosen term ID.
 *
 * @param string $taxonomy Taxonomy name.
 * @return string
 */
function meta_key( string $taxonomy ): string {
	return META_PREFIX . $taxonomy;
}

/**
 * A post's primary term: the one the editor chose, or its first term if they
 * haven't chosen one.
 *
 * The choice is checked against the post's own terms rather than looked up with
 * `get_term()`, so unticking the chosen term simply falls the post back to its
 * first one — no meta to clean up, and re-ticking restores the editor's intent.
 *
 * Works on any taxonomy, enabled or not; a taxonomy with no picker simply has
 * no meta, so this returns its first term.
 *
 * @param int    $post_id  Post ID.
 * @param string $taxonomy Taxonomy name.
 * @return \WP_Term|null
 */
function get_primary_term( int $post_id, string $taxonomy = 'category' ): ?\WP_Term {
	$terms = get_the_terms( $post_id, $taxonomy );

	if ( is_wp_error( $terms ) || empty( $terms ) ) {
		return null;
	}

	$chosen = (int) get_post_meta( $post_id, meta_key( $taxonomy ), true );

	foreach ( $terms as $term ) {
		if ( $chosen === $term->term_id ) {
			return $term;
		}
	}

	return reset( $terms );
}

/**
 * The primary term's ID, or 0 if the post has no terms in the taxonomy.
 *
 * @param int    $post_id  Post ID.
 * @param string $taxonomy Taxonomy name.
 * @return int
 */
function get_primary_term_id( int $post_id, string $taxonomy = 'category' ): int {
	$term = get_primary_term( $post_id, $taxonomy );

	return $term ? $term->term_id : 0;
}

/**
 * Record an editor's choice of primary term.
 *
 * Refuses to store a term the post does not actually have, so the stored value
 * is always one `get_primary_term()` can return.
 *
 * @param int    $post_id  Post ID.
 * @param int    $term_id  Term ID, or 0 to clear the choice.
 * @param string $taxonomy Taxonomy name.
 * @return bool Whether the choice is now stored.
 */
function set_primary_term( int $post_id, int $term_id, string $taxonomy = 'category' ): bool {
	$key = meta_key( $taxonomy );

	if ( 0 === $term_id ) {
		delete_post_meta( $post_id, $key );

		return true;
	}

	$term = get_term( $term_id, $taxonomy );

	if ( ! $term instanceof \WP_Term || true !== is_object_in_term( $post_id, $taxonomy, $term_id ) ) {
		return false;
	}

	// `update_post_meta()` reports false for an unchanged value, which is a
	// success here, not a failure.
	if ( (int) get_post_meta( $post_id, $key, true ) === $term_id ) {
		return true;
	}

	return (bool) update_post_meta( $post_id, $key, $term_id );
}

/**
 * Put the primary term at the front of a post's term list.
 *
 * `get_the_category()` is a thin wrapper over `get_the_terms()`, so this one
 * filter makes the chosen term appear at index 0 for every reader of a post's
 * terms — core blocks, feeds, archive listings — with none of them opting in.
 * That reach is the point, but it is also invisible at the call site: reach for
 * `get_primary_term()` when you want the intent to be explicit.
 *
 * Reads the meta directly and deliberately. `get_primary_term()`,
 * `get_the_terms()` and `get_the_category()` all route back through this
 * filter; calling any of them from here recurses.
 *
 * @param \WP_Term[]|\WP_Error $terms    The post's terms.
 * @param int                  $post_id  Post ID.
 * @param string               $taxonomy Taxonomy name.
 * @return \WP_Term[]|\WP_Error
 */
function sort_primary_term_first( $terms, $post_id, $taxonomy ) {
	if ( ! in_array( $taxonomy, taxonomies(), true ) ) {
		return $terms;
	}

	if ( is_wp_error( $terms ) || ! is_array( $terms ) || count( $terms ) < 2 ) {
		return $terms;
	}

	$chosen = (int) get_post_meta( $post_id, meta_key( $taxonomy ), true );

	if ( ! $chosen ) {
		return $terms;
	}

	$primary = [];
	$rest    = [];

	foreach ( $terms as $term ) {
		if ( $chosen === $term->term_id ) {
			$primary[] = $term;
		} else {
			$rest[] = $term;
		}
	}

	return array_values( array_merge( $primary, $rest ) );
}
