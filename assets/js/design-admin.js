/**
 * NDV Reviews — Design admin screen. Sync preset swatches to the color input.
 * Vanilla JS, no jQuery.
 */
( function () {
	'use strict';

	var input = document.getElementById( 'ndvr-accent' );
	if ( ! input ) {
		return;
	}

	Array.prototype.forEach.call( document.querySelectorAll( '.ndvr-swatch' ), function ( swatch ) {
		swatch.addEventListener( 'click', function () {
			input.value = swatch.getAttribute( 'data-color' );
		} );
	} );
}() );
