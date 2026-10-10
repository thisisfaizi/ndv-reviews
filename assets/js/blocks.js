/**
 * Rosette Reviews — Gutenberg block registration (no build step; plain JS).
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
		return numberControl( __( 'Product ID (0 = current product)', 'rosette-reviews' ), props.attributes, props.setAttributes, 'product_id' );
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

	simpleBlock( 'ndv-reviews/summary', __( 'Rosette Reviews: Summary', 'rosette-reviews' ), __( 'Source', 'rosette-reviews' ) );
	simpleBlock( 'ndv-reviews/stars', __( 'Rosette Reviews: Stars', 'rosette-reviews' ), __( 'Source', 'rosette-reviews' ) );
	simpleBlock( 'ndv-reviews/form', __( 'Rosette Reviews: Form', 'rosette-reviews' ), __( 'Form', 'rosette-reviews' ) );

	blocks.registerBlockType( 'ndv-reviews/reviews', {
		apiVersion: 2,
		title: __( 'Rosette Reviews: Reviews', 'rosette-reviews' ),
		icon: icon,
		category: 'widgets',
		attributes: {
			product_id: { type: 'number', default: 0 },
			per_page: { type: 'number', default: 10 },
			orderby: { type: 'string', default: 'recent' }
		},
		edit: editor( 'ndv-reviews/reviews', __( 'Reviews', 'rosette-reviews' ), function ( props ) {
			return [
				productControl( props ),
				el( RangeControl, {
					key: 'per_page',
					label: __( 'Reviews per page', 'rosette-reviews' ),
					value: props.attributes.per_page,
					min: 1,
					max: 50,
					onChange: function ( v ) { props.setAttributes( { per_page: v || 10 } ); }
				} ),
				el( SelectControl, {
					key: 'orderby',
					label: __( 'Order by', 'rosette-reviews' ),
					value: props.attributes.orderby,
					options: [
						{ label: __( 'Most recent', 'rosette-reviews' ), value: 'recent' },
						{ label: __( 'Most helpful', 'rosette-reviews' ), value: 'helpful' },
						{ label: __( 'Highest rated', 'rosette-reviews' ), value: 'highest' },
						{ label: __( 'Lowest rated', 'rosette-reviews' ), value: 'lowest' }
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
		title: __( 'Rosette Reviews: Reviews Marquee', 'rosette-reviews' ),
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
		edit: editor( 'ndv-reviews/marquee', __( 'Marquee', 'rosette-reviews' ), function ( props ) {
			var a = props.attributes;
			var set = props.setAttributes;
			return [
				el( SelectControl, {
					key: 'source',
					label: __( 'Source', 'rosette-reviews' ),
					value: a.source,
					options: [
						{ label: __( 'All products', 'rosette-reviews' ), value: 'all' },
						{ label: __( 'Specific product', 'rosette-reviews' ), value: 'product' },
						{ label: __( 'Category', 'rosette-reviews' ), value: 'category' }
					],
					onChange: function ( v ) { set( { source: v } ); }
				} ),
				a.source === 'product' ? numberControl( __( 'Product ID', 'rosette-reviews' ), a, set, 'product_id' ) : null,
				a.source === 'category' ? el( TextControl, {
					key: 'category',
					label: __( 'Category (slug or ID)', 'rosette-reviews' ),
					value: a.category,
					onChange: function ( v ) { set( { category: v } ); }
				} ) : null,
				el( RangeControl, {
					key: 'limit',
					label: __( 'Number of reviews', 'rosette-reviews' ),
					value: a.limit,
					min: 1, max: 50,
					onChange: function ( v ) { set( { limit: v || 20 } ); }
				} ),
				el( RangeControl, {
					key: 'min_rating',
					label: __( 'Minimum rating', 'rosette-reviews' ),
					value: a.min_rating,
					min: 0, max: 5,
					onChange: function ( v ) { set( { min_rating: v || 0 } ); }
				} ),
				el( SelectControl, {
					key: 'direction',
					label: __( 'Direction', 'rosette-reviews' ),
					value: directionValue( a.direction ),
					options: [
						{ label: __( 'Left', 'rosette-reviews' ), value: 'left' },
						{ label: __( 'Right', 'rosette-reviews' ), value: 'right' },
						{ label: __( 'Up', 'rosette-reviews' ), value: 'up' },
						{ label: __( 'Down', 'rosette-reviews' ), value: 'down' }
					],
					onChange: function ( v ) { set( { direction: v } ); }
				} ),
				el( SelectControl, {
					key: 'rows',
					label: __( 'Rows', 'rosette-reviews' ),
					value: String( a.rows ),
					options: [
						{ label: __( 'Single row', 'rosette-reviews' ), value: '1' },
						{ label: __( 'Two rows (opposite directions)', 'rosette-reviews' ), value: '2' }
					],
					onChange: function ( v ) { set( { rows: parseInt( v, 10 ) || 1 } ); }
				} ),
				el( RangeControl, {
					key: 'speed',
					label: __( 'Loop duration (seconds)', 'rosette-reviews' ),
					value: a.speed,
					min: 5, max: 120,
					onChange: function ( v ) { set( { speed: v || 40 } ); }
				} ),
				el( RangeControl, {
					key: 'gap',
					label: __( 'Gap between cards (px)', 'rosette-reviews' ),
					value: a.gap,
					min: 0, max: 60,
					onChange: function ( v ) { set( { gap: typeof v === 'number' ? v : 16 } ); }
				} ),
				el( ToggleControl, {
					key: 'pause',
					label: __( 'Pause on hover', 'rosette-reviews' ),
					checked: a.pause,
					onChange: function ( v ) { set( { pause: v } ); }
				} ),
				el( ToggleControl, {
					key: 'verified',
					label: __( 'Verified buyers only', 'rosette-reviews' ),
					checked: a.verified,
					onChange: function ( v ) { set( { verified: v } ); }
				} ),
				el( ToggleControl, {
					key: 'with_media',
					label: __( 'Reviews with photos only', 'rosette-reviews' ),
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
