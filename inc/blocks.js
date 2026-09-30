/**
 * A "Primary term only" toggle on core's Post Terms block.
 *
 * The server honours `hmPrimaryOnly` whether or not this file loads; this is
 * only what stops the option being reachable by hand-editing markup. A separate
 * file from the taxonomy picker because the two load on different screens: the
 * picker belongs to a post edit screen, Post Terms blocks mostly live in
 * templates edited in the site editor.
 *
 * Plain JS against WordPress's own global scripts: the plugin has no build step
 * to compile JSX.
 */

( function ( wp, taxonomies ) {
	var BLOCK = 'core/post-terms';

	var addFilter = wp.hooks.addFilter;
	var createHigherOrderComponent = wp.compose.createHigherOrderComponent;
	var createElement = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var PanelBody = wp.components.PanelBody;
	var ToggleControl = wp.components.ToggleControl;
	var __ = wp.i18n.__;

	addFilter(
		'blocks.registerBlockType',
		'hm-primary-category/post-terms-attribute',
		function ( settings, name ) {
			if ( name !== BLOCK ) {
				return settings;
			}

			// The PHP side registers the same attribute, but the editor keeps
			// its own copy of the schema and drops anything missing from it.
			// Copied rather than mutated: another filter may hold this object.
			return Object.assign( {}, settings, {
				attributes: Object.assign( {}, settings.attributes, {
					hmPrimaryOnly: { type: 'boolean', default: false },
				} ),
			} );
		}
	);

	addFilter(
		'editor.BlockEdit',
		'hm-primary-category/post-terms-toggle',
		createHigherOrderComponent( function ( BlockEdit ) {
			return function ( props ) {
				// The taxonomy map is the same list the PHP side enables, so the
				// toggle cannot offer a taxonomy that has no primary term to
				// show. No hooks are used below, so these early returns cannot
				// vary the hook count between renders.
				if (
					props.name !== BLOCK ||
					! taxonomies[ props.attributes.term ]
				) {
					return createElement( BlockEdit, props );
				}

				return createElement(
					Fragment,
					null,
					createElement( BlockEdit, props ),
					createElement(
						InspectorControls,
						null,
						createElement(
							PanelBody,
							{
								title: __(
									'Primary term',
									'hm-primary-category'
								),
							},
							createElement( ToggleControl, {
								__nextHasNoMarginBottom: true,
								label: __(
									'Primary term only',
									'hm-primary-category'
								),
								help: __(
									'Show just this post’s primary term for that taxonomy, instead of every term.',
									'hm-primary-category'
								),
								checked: !! props.attributes.hmPrimaryOnly,
								onChange: function ( value ) {
									props.setAttributes( {
										hmPrimaryOnly: value,
									} );
								},
							} )
						)
					)
				);
			};
		}, 'withPrimaryTermOnly' )
	);
} )( window.wp, window.hmPrimaryTerm || {} );
