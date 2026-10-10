/**
 * Rosette Reviews — Design admin screen: preset swatches, live preview of the
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

	// WCAG contrast of a colour against white (the cards' background).
	function contrastOnWhite( hex ) {
		var h = hex.replace( '#', '' );
		if ( h.length !== 6 ) {
			return 21;
		}
		var c = [ 0, 2, 4 ].map( function ( i ) {
			var v = parseInt( h.substr( i, 2 ), 16 ) / 255;
			return v <= 0.03928 ? v / 12.92 : Math.pow( ( v + 0.055 ) / 1.055, 2.4 );
		} );
		return 1.05 / ( 0.2126 * c[ 0 ] + 0.7152 * c[ 1 ] + 0.0722 * c[ 2 ] + 0.05 );
	}

	// Optional colours (rating icon, bars): the value only when "Use a custom color" is ticked.
	var colorFields = document.querySelectorAll( '[data-ndvr-color]' );

	function customColor( key ) {
		var box = form.querySelector( 'input[name="' + key + '_custom"]' );
		var picker = form.querySelector( 'input[name="' + key + '"]' );
		return box && picker && box.checked ? picker.value : '';
	}

	function syncColorFields() {
		Array.prototype.forEach.call( colorFields, function ( field ) {
			var key = field.getAttribute( 'data-ndvr-color' );
			var picker = field.querySelector( 'input[type="color"]' );
			var warn = field.querySelector( '.ndvr-contrast-warning' );
			var value = customColor( key );
			field.classList.toggle( 'is-default', ! value );
			if ( warn ) {
				var ratio = value ? contrastOnWhite( value ) : 21;
				warn.hidden = ratio >= 3;
				var out = warn.querySelector( '.ndvr-contrast-ratio' );
				if ( out ) {
					out.textContent = ratio.toFixed( 1 );
				}
			}
			if ( ! value && picker ) {
				picker.value = picker.getAttribute( 'data-default' );
			}
		} );
		// Rating icon option cards show the chosen colour too.
		var rating = customColor( 'design_rating_color' );
		Array.prototype.forEach.call( form.querySelectorAll( '.ndvr-glyph-star, .ndvr-glyph-heart' ), function ( g ) {
			g.style.color = rating;
		} );
	}

	Array.prototype.forEach.call( colorFields, function ( field ) {
		var key = field.getAttribute( 'data-ndvr-color' );
		var picker = field.querySelector( 'input[type="color"]' );
		var box = form.querySelector( 'input[name="' + key + '_custom"]' );
		if ( picker && box ) {
			// Picking a colour means the merchant wants it: tick the box for them.
			picker.addEventListener( 'input', function () {
				box.checked = true;
				syncColorFields();
				update();
			} );
		}
	} );

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
		var rating = customColor( 'design_rating_color' );
		if ( rating ) {
			vars += '--ndvr-gold:' + rating + ';--ndvr-heart:' + rating + ';';
		}
		var bar = customColor( 'design_bar_color' );
		if ( bar ) {
			vars += '--ndvr-bar:' + bar + ';';
		}
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
		syncColorFields();
		update();
	} );
	syncColorFields();
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
