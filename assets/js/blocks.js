/**
 * NDV Reviews — Gutenberg block registration (no build step; plain JS).
 * Blocks are server-rendered; the editor previews them with ServerSideRender.
 */
( function ( blocks, element, blockEditor, components, serverSideRender, i18n ) {
	'use strict';

	var el = element.createElement;
	var __ = i18n.__;
	var InspectorControls = blockEditor.InspectorControls;
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

	function numberControl( label, attributes, setAttributes, key ) {
		return el( TextControl, {
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

	blocks.registerBlockType( 'ndv-reviews/summary', {
		title: __( 'NDV Reviews: Summary', 'ndv-reviews' ),
		icon: icon,
		category: 'widgets',
		attributes: { product_id: { type: 'number', default: 0 } },
		edit: function ( props ) {
			return [
				el( InspectorControls, { key: 'i' },
					el( PanelBody, { title: __( 'Source', 'ndv-reviews' ) },
						numberControl( __( 'Product ID (0 = current)', 'ndv-reviews' ), props.attributes, props.setAttributes, 'product_id' )
					)
				),
				preview( 'ndv-reviews/summary', props.attributes )
			];
		},
		save: function () { return null; }
	} );

	blocks.registerBlockType( 'ndv-reviews/stars', {
		title: __( 'NDV Reviews: Stars', 'ndv-reviews' ),
		icon: icon,
		category: 'widgets',
		attributes: { product_id: { type: 'number', default: 0 } },
		edit: function ( props ) {
			return [
				el( InspectorControls, { key: 'i' },
					el( PanelBody, { title: __( 'Source', 'ndv-reviews' ) },
						numberControl( __( 'Product ID (0 = current)', 'ndv-reviews' ), props.attributes, props.setAttributes, 'product_id' )
					)
				),
				preview( 'ndv-reviews/stars', props.attributes )
			];
		},
		save: function () { return null; }
	} );

	blocks.registerBlockType( 'ndv-reviews/reviews', {
		title: __( 'NDV Reviews: Reviews', 'ndv-reviews' ),
		icon: icon,
		category: 'widgets',
		attributes: {
			product_id: { type: 'number', default: 0 },
			per_page: { type: 'number', default: 10 },
			orderby: { type: 'string', default: 'recent' }
		},
		edit: function ( props ) {
			return [
				el( InspectorControls, { key: 'i' },
					el( PanelBody, { title: __( 'Reviews', 'ndv-reviews' ) },
						numberControl( __( 'Product ID (0 = current)', 'ndv-reviews' ), props.attributes, props.setAttributes, 'product_id' ),
						numberControl( __( 'Per page', 'ndv-reviews' ), props.attributes, props.setAttributes, 'per_page' ),
						el( SelectControl, {
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
					)
				),
				preview( 'ndv-reviews/reviews', props.attributes )
			];
		},
		save: function () { return null; }
	} );

	blocks.registerBlockType( 'ndv-reviews/marquee', {
		title: __( 'NDV Reviews: Reviews Marquee', 'ndv-reviews' ),
		icon: icon,
		category: 'widgets',
		attributes: {
			source: { type: 'string', default: 'all' },
			product_id: { type: 'number', default: 0 },
			min_rating: { type: 'number', default: 0 },
			speed: { type: 'number', default: 40 },
			direction: { type: 'string', default: 'horizontal' },
			limit: { type: 'number', default: 20 },
			verified: { type: 'boolean', default: false }
		},
		edit: function ( props ) {
			return [
				el( InspectorControls, { key: 'i' },
					el( PanelBody, { title: __( 'Marquee', 'ndv-reviews' ) },
						el( SelectControl, {
							label: __( 'Source', 'ndv-reviews' ),
							value: props.attributes.source,
							options: [
								{ label: __( 'All products', 'ndv-reviews' ), value: 'all' },
								{ label: __( 'Specific product', 'ndv-reviews' ), value: 'product' }
							],
							onChange: function ( v ) { props.setAttributes( { source: v } ); }
						} ),
						numberControl( __( 'Product ID', 'ndv-reviews' ), props.attributes, props.setAttributes, 'product_id' ),
						el( RangeControl, {
							label: __( 'Minimum rating', 'ndv-reviews' ),
							value: props.attributes.min_rating,
							min: 0, max: 5,
							onChange: function ( v ) { props.setAttributes( { min_rating: v } ); }
						} ),
						el( RangeControl, {
							label: __( 'Speed (seconds)', 'ndv-reviews' ),
							value: props.attributes.speed,
							min: 5, max: 120,
							onChange: function ( v ) { props.setAttributes( { speed: v } ); }
						} ),
						el( SelectControl, {
							label: __( 'Direction', 'ndv-reviews' ),
							value: props.attributes.direction,
							options: [
								{ label: __( 'Horizontal', 'ndv-reviews' ), value: 'horizontal' },
								{ label: __( 'Vertical', 'ndv-reviews' ), value: 'vertical' }
							],
							onChange: function ( v ) { props.setAttributes( { direction: v } ); }
						} ),
						el( ToggleControl, {
							label: __( 'Verified buyers only', 'ndv-reviews' ),
							checked: props.attributes.verified,
							onChange: function ( v ) { props.setAttributes( { verified: v } ); }
						} )
					)
				),
				preview( 'ndv-reviews/marquee', props.attributes )
			];
		},
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
