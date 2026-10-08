/**
 * NDV Reviews — Design admin screen: preset swatches, live preview of the
 * unsaved choices, reset confirmation. Vanilla JS, no jQuery.
 */
( function () {
	'use strict';

	var form = document.getElementById( 'ndvr-design-form' );
	var input = document.getElementById( 'ndvr-accent' );
	var frame = document.querySelector( '.ndvr-design-preview-frame' );
	if ( ! form || ! input ) {
		return;
	}

	var swatches = document.querySelectorAll( '.ndvr-swatch' );
	var fonts = {};
	var scales = {};
	try {
		fonts = JSON.parse( frame.getAttribute( 'data-fonts' ) ) || {};
		scales = JSON.parse( frame.getAttribute( 'data-scales' ) ) || {};
	} catch ( e ) {}

	function syncSwatches() {
		Array.prototype.forEach.call( swatches, function ( s ) {
			s.setAttribute( 'aria-pressed', s.getAttribute( 'data-color' ).toLowerCase() === input.value.toLowerCase() ? 'true' : 'false' );
		} );
	}

	Array.prototype.forEach.call( swatches, function ( swatch ) {
		swatch.addEventListener( 'click', function () {
			input.value = swatch.getAttribute( 'data-color' );
			syncSwatches();
			update();
		} );
	} );

	// Same rule as Display\Design::ink_for(): white or ink text, whichever
	// contrasts more with the accent.
	function inkFor( hex ) {
		var h = hex.replace( '#', '' );
		if ( h.length !== 6 ) {
			return '#ffffff';
		}
		var c = [ 0, 2, 4 ].map( function ( i ) {
			var v = parseInt( h.substr( i, 2 ), 16 ) / 255;
			return v <= 0.03928 ? v / 12.92 : Math.pow( ( v + 0.055 ) / 1.055, 2.4 );
		} );
		var lum = 0.2126 * c[ 0 ] + 0.7152 * c[ 1 ] + 0.0722 * c[ 2 ];
		return ( 1.05 / ( lum + 0.05 ) ) >= ( ( lum + 0.05 ) / 0.0603 ) ? '#ffffff' : '#181a1f';
	}

	function checked( name ) {
		var el = form.querySelector( 'input[name="' + name + '"]:checked' );
		return el ? el.value : '';
	}

	function update() {
		var doc = frame && frame.contentDocument;
		if ( ! doc || ! doc.body ) {
			return;
		}
		var root = doc.querySelector( '.ndvr-preview-root' );
		if ( ! root ) {
			return;
		}

		root.className = [
			'ndvr-reviews-wrap',
			'ndvr-preview-root',
			'ndvr-template-' + checked( 'design_template' ),
			'ndvr-summary-' + checked( 'design_summary' ),
			'ndvr-card-' + checked( 'design_card' )
		].join( ' ' );
		doc.body.className = 'ndvr-rating-' + checked( 'design_rating' );

		var font = form.querySelector( '[name="design_font"]' ).value;
		var scale = form.querySelector( '[name="design_scale"]' ).value;
		var vars = '--ndvr-accent:' + input.value + ';--ndvr-accent-ink:' + inkFor( input.value ) + ';';
		if ( fonts[ font ] ) {
			vars += '--ndvr-font:' + fonts[ font ] + ';';
		}
		if ( scales[ scale ] ) {
			vars += '--ndvr-text:' + scales[ scale ] + ';';
		}
		var style = doc.getElementById( 'ndvr-preview-vars' );
		if ( style ) {
			style.textContent = ':root{' + vars + '}';
		}

		resize();
	}

	function resize() {
		var doc = frame && frame.contentDocument;
		if ( doc && doc.documentElement ) {
			frame.style.height = Math.ceil( doc.body.getBoundingClientRect().height ) + 'px';
		}
	}

	if ( frame ) {
		frame.addEventListener( 'load', function () {
			update();
			// Bars animate in; measure again once layout settles.
			setTimeout( resize, 400 );
		} );
		if ( frame.contentDocument && frame.contentDocument.readyState === 'complete' ) {
			update();
		}
		window.addEventListener( 'resize', resize );
	}

	form.addEventListener( 'change', function () {
		syncSwatches();
		update();
	} );
	input.addEventListener( 'input', function () {
		syncSwatches();
		update();
	} );

	var reset = form.querySelector( '.ndvr-design-reset' );
	if ( reset ) {
		reset.addEventListener( 'click', function ( e ) {
			if ( ! window.confirm( reset.getAttribute( 'data-confirm' ) ) ) {
				e.preventDefault();
			}
		} );
	}
}() );
