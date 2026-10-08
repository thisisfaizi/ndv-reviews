/**
 * NDV Reviews — Gutenberg block registration (no build step; plain JS).
 * Blocks are server-rendered; the editor previews them with ServerSideRender
 * inside the block wrapper from useBlockProps (required by apiVersion 2).
 */
( function ( blocks, element, blockEditor, components, serverSideRender, i18n ) {
	'use strict';

	var el = element.createElement;
	var __ = i18n.__;
	var InspectorControls = blockEditor.InspectorControls;
	var useBlockProps = blockEditor.useBlockProps;
	var PanelBody = components.PanelBody;
	var TextControl = components.TextControl;
	var RangeControl = components.RangeControl;
	var SelectControl = components.SelectControl;
	var ToggleControl = components.ToggleControl;
	var SSR = serverSideRender;

	var icon = 'star-filled';

	function preview( name, attributes ) {
		return el( SSR, { block: name, attributes: attributes } );
	}

	// Edit wrapper: inspector panel + the server-rendered preview inside the
	// block's own wrapper element.
	function editor( name, panelTitle, controls ) {
		return function ( props ) {
			var blockProps = useBlockProps();
			return el( element.Fragment, null,
				el( InspectorControls, null,
					el( PanelBody, { title: panelTitle }, controls( props ) )
				),
				el( 'div', blockProps, preview( name, props.attributes ) )
			);
		};
	}

	function numberControl( label, attributes, setAttributes, key ) {
		return el( TextControl, {
			key: key,
			label: label,
			type: 'number',
			value: attributes[ key ],
			onChange: function ( v ) {
				var o = {};
				o[ key ] = parseInt( v, 10 ) || 0;
				setAttributes( o );
			}
		} );
	}

	function productControl( props ) {
		return numberControl( __( 'Product ID (0 = current product)', 'ndv-reviews' ), props.attributes, props.setAttributes, 'product_id' );
	}

	function simpleBlock( name, title, panelTitle ) {
		blocks.registerBlockType( name, {
			apiVersion: 2,
			title: title,
			icon: icon,
			category: 'widgets',
			attributes: { product_id: { type: 'number', default: 0 } },
			edit: editor( name, panelTitle, productControl ),
			save: function () { return null; }
		} );
	}

	simpleBlock( 'ndv-reviews/summary', __( 'NDV Reviews: Summary', 'ndv-reviews' ), __( 'Source', 'ndv-reviews' ) );
	simpleBlock( 'ndv-reviews/stars', __( 'NDV Reviews: Stars', 'ndv-reviews' ), __( 'Source', 'ndv-reviews' ) );
	simpleBlock( 'ndv-reviews/form', __( 'NDV Reviews: Form', 'ndv-reviews' ), __( 'Form', 'ndv-reviews' ) );

	blocks.registerBlockType( 'ndv-reviews/reviews', {
		apiVersion: 2,
		title: __( 'NDV Reviews: Reviews', 'ndv-reviews' ),
		icon: icon,
		category: 'widgets',
		attributes: {
			product_id: { type: 'number', default: 0 },
			per_page: { type: 'number', default: 10 },
			orderby: { type: 'string', default: 'recent' }
		},
		edit: editor( 'ndv-reviews/reviews', __( 'Reviews', 'ndv-reviews' ), function ( props ) {
			return [
				productControl( props ),
				el( RangeControl, {
					key: 'per_page',
					label: __( 'Reviews per page', 'ndv-reviews' ),
					value: props.attributes.per_page,
					min: 1,
					max: 50,
					onChange: function ( v ) { props.setAttributes( { per_page: v || 10 } ); }
				} ),
				el( SelectControl, {
					key: 'orderby',
					label: __( 'Order by', 'ndv-reviews' ),
					value: props.attributes.orderby,
					options: [
						{ label: __( 'Most recent', 'ndv-reviews' ), value: 'recent' },
						{ label: __( 'Most helpful', 'ndv-reviews' ), value: 'helpful' },
						{ label: __( 'Highest rated', 'ndv-reviews' ), value: 'highest' },
						{ label: __( 'Lowest rated', 'ndv-reviews' ), value: 'lowest' }
					],
					onChange: function ( v ) { props.setAttributes( { orderby: v } ); }
				} )
			];
		} ),
		save: function () { return null; }
	} );

	// Saved blocks may hold the legacy horizontal/vertical values; show them as
	// their left/up equivalents (the server accepts both).
	function directionValue( v ) {
		if ( v === 'horizontal' ) {
			return 'left';
		}
		if ( v === 'vertical' ) {
			return 'up';
		}
		return v || 'left';
	}

	blocks.registerBlockType( 'ndv-reviews/marquee', {
		apiVersion: 2,
		title: __( 'NDV Reviews: Reviews Marquee', 'ndv-reviews' ),
		icon: icon,
		category: 'widgets',
		attributes: {
			source: { type: 'string', default: 'all' },
			product_id: { type: 'number', default: 0 },
			category: { type: 'string', default: '' },
			min_rating: { type: 'number', default: 0 },
			speed: { type: 'number', default: 40 },
			direction: { type: 'string', default: 'horizontal' },
			limit: { type: 'number', default: 20 },
			verified: { type: 'boolean', default: false },
			with_media: { type: 'boolean', default: false },
			gap: { type: 'number', default: 16 },
			pause: { type: 'boolean', default: true },
			rows: { type: 'number', default: 1 }
		},
		edit: editor( 'ndv-reviews/marquee', __( 'Marquee', 'ndv-reviews' ), function ( props ) {
			var a = props.attributes;
			var set = props.setAttributes;
			return [
				el( SelectControl, {
					key: 'source',
					label: __( 'Source', 'ndv-reviews' ),
					value: a.source,
					options: [
						{ label: __( 'All products', 'ndv-reviews' ), value: 'all' },
						{ label: __( 'Specific product', 'ndv-reviews' ), value: 'product' },
						{ label: __( 'Category', 'ndv-reviews' ), value: 'category' }
					],
					onChange: function ( v ) { set( { source: v } ); }
				} ),
				a.source === 'product' ? numberControl( __( 'Product ID', 'ndv-reviews' ), a, set, 'product_id' ) : null,
				a.source === 'category' ? el( TextControl, {
					key: 'category',
					label: __( 'Category (slug or ID)', 'ndv-reviews' ),
					value: a.category,
					onChange: function ( v ) { set( { category: v } ); }
				} ) : null,
				el( RangeControl, {
					key: 'limit',
					label: __( 'Number of reviews', 'ndv-reviews' ),
					value: a.limit,
					min: 1, max: 50,
					onChange: function ( v ) { set( { limit: v || 20 } ); }
				} ),
				el( RangeControl, {
					key: 'min_rating',
					label: __( 'Minimum rating', 'ndv-reviews' ),
					value: a.min_rating,
					min: 0, max: 5,
					onChange: function ( v ) { set( { min_rating: v || 0 } ); }
				} ),
				el( SelectControl, {
					key: 'direction',
					label: __( 'Direction', 'ndv-reviews' ),
					value: directionValue( a.direction ),
					options: [
						{ label: __( 'Left', 'ndv-reviews' ), value: 'left' },
						{ label: __( 'Right', 'ndv-reviews' ), value: 'right' },
						{ label: __( 'Up', 'ndv-reviews' ), value: 'up' },
						{ label: __( 'Down', 'ndv-reviews' ), value: 'down' }
					],
					onChange: function ( v ) { set( { direction: v } ); }
				} ),
				el( SelectControl, {
					key: 'rows',
					label: __( 'Rows', 'ndv-reviews' ),
					value: String( a.rows ),
					options: [
						{ label: __( 'Single row', 'ndv-reviews' ), value: '1' },
						{ label: __( 'Two rows (opposite directions)', 'ndv-reviews' ), value: '2' }
					],
					onChange: function ( v ) { set( { rows: parseInt( v, 10 ) || 1 } ); }
				} ),
				el( RangeControl, {
					key: 'speed',
					label: __( 'Loop duration (seconds)', 'ndv-reviews' ),
					value: a.speed,
					min: 5, max: 120,
					onChange: function ( v ) { set( { speed: v || 40 } ); }
				} ),
				el( RangeControl, {
					key: 'gap',
					label: __( 'Gap between cards (px)', 'ndv-reviews' ),
					value: a.gap,
					min: 0, max: 60,
					onChange: function ( v ) { set( { gap: typeof v === 'number' ? v : 16 } ); }
				} ),
				el( ToggleControl, {
					key: 'pause',
					label: __( 'Pause on hover', 'ndv-reviews' ),
					checked: a.pause,
					onChange: function ( v ) { set( { pause: v } ); }
				} ),
				el( ToggleControl, {
					key: 'verified',
					label: __( 'Verified buyers only', 'ndv-reviews' ),
					checked: a.verified,
					onChange: function ( v ) { set( { verified: v } ); }
				} ),
				el( ToggleControl, {
					key: 'with_media',
					label: __( 'Reviews with photos only', 'ndv-reviews' ),
					checked: a.with_media,
					onChange: function ( v ) { set( { with_media: v } ); }
				} )
			];
		} ),
		save: function () { return null; }
	} );
}(
	window.wp.blocks,
	window.wp.element,
	window.wp.blockEditor,
	window.wp.components,
	window.wp.serverSideRender,
	window.wp.i18n
) );
