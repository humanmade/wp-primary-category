<?php
/**
 * Registration: hooks and post meta.
 *
 * @package HM\Primary_Term
 */

namespace HM\Primary_Term;

/**
 * Hook the feature up.
 */
function bootstrap(): void {
	// Priority 20 so taxonomies registered on `init` at the default 10 exist by
	// the time `taxonomies()` filters the list down to the registered ones.
	add_action( 'init', __NAMESPACE__ . '\\register_meta_fields', 20 );
	add_filter( 'get_the_terms', __NAMESPACE__ . '\\sort_primary_term_first', 10, 3 );
}

/**
 * Register the primary term meta for every post type each enabled taxonomy is
 * attached to.
 *
 * A function of its own, rather than inline in `bootstrap()`, because the WP
 * test harness clears `$wp_meta_keys` between tests and has to put the fields
 * back.
 */
function register_meta_fields(): void {
	foreach ( taxonomies() as $taxonomy ) {
		$object = get_taxonomy( $taxonomy );

		if ( ! $object ) {
			continue;
		}

		foreach ( $object->object_type as $post_type ) {
			register_post_meta(
				$post_type,
				meta_key( $taxonomy ),
				[
					'type'              => 'integer',
					'single'            => true,
					'default'           => 0,
					'sanitize_callback' => 'absint',
					'show_in_rest'      => true,
					'description'       => sprintf(
						/* translators: %s: taxonomy name, e.g. "category". */
						__( 'The `%s` term ID marked as this post\'s primary one.', 'hm-primary-category' ),
						$taxonomy
					),

					/*
					 * Required, not optional: the key is underscore-prefixed, so
					 * WordPress treats it as protected and rejects every REST
					 * write without an auth callback of its own.
					 */
					'auth_callback'     => __NAMESPACE__ . '\\can_edit_primary_term',
				]
			);
		}
	}
}

/**
 * Whether the current user may write a post's primary term meta.
 *
 * @param bool     $allowed  Whether the user can add the meta. Unused.
 * @param string   $meta_key The meta key. Unused.
 * @param int      $post_id  Post ID.
 * @param int      $user_id  User ID. Unused.
 * @param string   $cap      Capability being checked. Unused.
 * @param string[] $caps     Primitive capabilities required. Unused.
 * @return bool
 */
function can_edit_primary_term( $allowed, $meta_key, $post_id, $user_id, $cap, $caps ): bool { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable, Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Fixed `auth_callback` signature.
	return current_user_can( 'edit_post', $post_id );
}
