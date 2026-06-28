/**
 * NDV Reviews — marquee accessibility helper.
 * The animation itself is pure CSS; this only adds keyboard-pause on focus and
 * respects users who toggle reduced-motion at runtime. Vanilla JS, no jQuery.
 */
( function () {
	'use strict';

	var marquees = document.querySelectorAll( '.ndvr-marquee-pause' );
	Array.prototype.forEach.call( marquees, function ( marquee ) {
		var track = marquee.querySelector( '.ndvr-marquee-track' );
		if ( ! track ) {
			return;
		}
		marquee.addEventListener( 'focusin', function () { track.style.animationPlayState = 'paused'; } );
		marquee.addEventListener( 'focusout', function () { track.style.animationPlayState = ''; } );
	} );
}() );
