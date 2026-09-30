<?php
/**
 * The public API: read and write a post's primary term.
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
 * WordPress's own two by default. Both are ordinary term lists with no
 * inherent winner, which is the problem this solves, and a site that wants
 * only one of them says so with the filter.
 *
 * Filtered on every call rather than cached, so a consumer can hook this from
 * anywhere in the load order — including turning the feature off entirely by
 * returning an empty array.
 *
 * One timing caveat: the meta registration runs once, on `init` at priority 20.
 * A taxonomy added to the list after that has no registered meta — and so no
 * REST field, no editor picker and no block toggle. Hook before then.
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
	 * Hook this before `init` priority 20 for the taxonomy to get its post meta
	 * registered, and with it the REST field, the editor picker and the block
	 * toggle. Hooked any later it does nothing.
	 *
	 * @param string[] $taxonomies Taxonomy names.
	 */
	$taxonomies = apply_filters( 'hm_primary_term_taxonomies', [ 'category', 'post_tag' ] );

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
