/**
 * A "Primary <term>" control for the post editor.
 *
 * Which of a post's terms is the primary one is an editorial judgement the term
 * list itself can't express — it has no order. The choice is about those terms,
 * so it is injected into the core taxonomy panel rather than standing alone
 * elsewhere in the sidebar. `editor.PostTaxonomyType` wraps both the flat and
 * the hierarchical term selector, so one filter covers every taxonomy.
 *
 * Plain JS against WordPress's own global scripts: the plugin has no build step
 * to compile JSX.
 */

( function ( wp, taxonomies ) {
	// Shared empties. A fresh [] or {} built inside useSelect is a new reference
	// every call, so the editor treats each one as a state change and re-renders
	// the panel we are rendering into.
	var NO_IDS = [];
	var NO_META = {};

	// Every term, not just the assigned ones. Narrowing with `include` would
	// change the query key each time a box is ticked, leaving the control
	// rendering nothing for 350-500ms while the new key resolves — a visible
	// hole in the panel being clicked. The selector above has already fetched
	// these, so this costs no extra request.
	var ALL_TERMS = { per_page: -1 };

	var addFilter = wp.hooks.addFilter;
	var createHigherOrderComponent = wp.compose.createHigherOrderComponent;
	var SelectControl = wp.components.SelectControl;
	var useSelect = wp.data.useSelect;
	var useDispatch = wp.data.useDispatch;
	var __ = wp.i18n.__;
	var createElement = wp.element.createElement;

	/**
	 * The control. Its own component so that the filter's early return for a
	 * taxonomy we don't handle can never vary the hook count between renders.
	 *
	 * @param {Object} props           Component props.
	 * @param {Object} props.taxonomy  One entry of the localised taxonomy map.
	 */
	function PrimaryTermControl( props ) {
		var taxonomy = props.taxonomy;

		var data = useSelect(
			function ( select ) {
				var editor = select( 'core/editor' );

				// Undefined until the post is loaded, and this runs before any
				// early return below: an unguarded read here throws inside the
				// component wrapping core's term selector and takes the whole
				// panel down.
				var meta = editor.getEditedPostAttribute( 'meta' ) || NO_META;

				return {
					postType: editor.getCurrentPostType(),
					ids:
						editor.getEditedPostAttribute( taxonomy.restBase ) ||
						NO_IDS,
					terms: select( 'core' ).getEntityRecords(
						'taxonomy',
						taxonomy.slug,
						ALL_TERMS
					),
					primary: meta[ taxonomy.metaKey ] || 0,
				};
			},
			[ taxonomy ]
		);

		var editPost = useDispatch( 'core/editor' ).editPost;

		// The meta is only registered for the post types the taxonomy is
		// attached to. With no terms assigned there is nothing to choose
		// between, and the selector directly above already says so.
		if (
			taxonomy.postTypes.indexOf( data.postType ) === -1 ||
			! data.ids.length
		) {
			return null;
		}

		// Records are null until resolved; show nothing rather than an empty list.
		if ( ! data.terms ) {
			return null;
		}

		var options = data.terms
			.filter( function ( term ) {
				return data.ids.indexOf( term.id ) !== -1;
			} )
			.map( function ( term ) {
				return { label: term.name, value: String( term.id ) };
			} )
			.sort( function ( a, b ) {
				return a.label.localeCompare( b.label );
			} );

		// A choice the post no longer has selects nothing, but is deliberately
		// left in the meta: get_primary_term() ignores a stale value, so
		// re-ticking that term restores the editor's intent.
		var selected =
			data.ids.indexOf( data.primary ) !== -1
				? String( data.primary )
				: '';

		// Without this a select would imply the first term is already chosen.
		if ( ! selected ) {
			options.unshift( {
				label: taxonomy.placeholder,
				value: '',
				disabled: true,
			} );
		}

		return createElement( SelectControl, {
			__nextHasNoMarginBottom: true,
			__next40pxDefaultSize: true,
			label: taxonomy.label,
			help: __(
				'Listed first wherever this post’s terms appear.',
				'hm-primary-category'
			),
			options: options,
			value: selected,
			onChange: function ( value ) {
				if ( ! value ) {
					return;
				}

				var meta = {};
				meta[ taxonomy.metaKey ] = parseInt( value, 10 );

				editPost( { meta: meta } );
			},
		} );
	}

	addFilter(
		'editor.PostTaxonomyType',
		'hm-primary-category/primary-term',
		createHigherOrderComponent( function ( OriginalComponent ) {
			return function ( props ) {
				var taxonomy = taxonomies[ props.slug ];

				// The filter wraps every taxonomy selector, so most calls are
				// not ours. The original is always rendered, never replaced: if
				// this filter ever changes shape the failure must be our control
				// disappearing, not the site's term selector.
				if ( ! taxonomy ) {
					return createElement( OriginalComponent, props );
				}

				// The plugin ships no editor stylesheet, so the column spacing
				// the panel uses between its own controls is set here.
				return createElement(
					'div',
					{
						style: {
							display: 'flex',
							flexDirection: 'column',
							gap: '16px',
						},
					},
					createElement( OriginalComponent, props ),
					createElement( PrimaryTermControl, { taxonomy: taxonomy } )
				);
			};
		}, 'withPrimaryTerm' )
	);
} )( window.wp, window.hmPrimaryTerm || {} );
