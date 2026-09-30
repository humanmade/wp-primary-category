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
	add_action( 'enqueue_block_editor_assets', __NAMESPACE__ . '\\enqueue_editor_assets' );
	add_filter( 'block_type_metadata', __NAMESPACE__ . '\\register_primary_only_attribute' );
	add_filter( 'register_block_type_args', __NAMESPACE__ . '\\wrap_post_terms_render', 10, 2 );
	add_filter( 'post_link_category', __NAMESPACE__ . '\\filter_permalink_category', 10, 3 );
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

/**
 * Use the primary category in a `%category%` permalink.
 *
 * Core picks whichever of a post's categories sorts first, which is an accident
 * of naming rather than an editorial decision. Anything this cannot vouch for —
 * no primary, or one core did not offer — is left to core.
 *
 * @param \WP_Term   $category   The category core chose.
 * @param \WP_Term[] $categories The post's categories.
 * @param \WP_Post   $post       The post.
 * @return \WP_Term
 */
function filter_permalink_category( $category, $categories, $post ) {
	/**
	 * Whether the primary category should appear in `%category%` permalinks.
	 *
	 * On by default, matching Yoast SEO. Worth turning off on a site that
	 * already has `%category%` URLs in the wild: changing which term appears
	 * changes the URL, and old ones stop resolving.
	 *
	 * @param bool $enabled Whether to rewrite the permalink's category.
	 */
	if ( ! apply_filters( 'hm_primary_term_filter_permalinks', true ) ) {
		return $category;
	}

	if ( ! in_array( 'category', taxonomies(), true ) ) {
		return $category;
	}

	$primary = get_primary_term( (int) $post->ID, 'category' );

	if ( ! $primary ) {
		return $category;
	}

	foreach ( (array) $categories as $candidate ) {
		if ( $candidate instanceof \WP_Term && $candidate->term_id === $primary->term_id ) {
			return $primary;
		}
	}

	return $category;
}
