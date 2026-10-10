/**
 * Rosette Reviews — marquee accessibility + speed-normalization helper.
 * The scroll animation itself is pure CSS; this adds keyboard-pause on focus,
 * the visible pause/play button, and normalizes px/s across instances (see normalizeSpeed below). Vanilla
 * JS, no jQuery.
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

	// ── Pause / play button (WCAG 2.2.2) ────────────────────────────
	// Hover/focus pausing is not enough: keyboard and touch users need a
	// persistent way to stop the motion. One button controls every track in
	// its wrapper (both rows of a rows=2 marquee).
	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest ? e.target.closest( '.ndvr-marquee-toggle' ) : null;
		if ( ! btn ) {
			return;
		}
		var wrap = btn.closest( '.ndvr-marquee-wrap' );
		if ( ! wrap ) {
			return;
		}
		var paused = wrap.classList.toggle( 'is-paused' );
		var label = btn.getAttribute( paused ? 'data-label-play' : 'data-label-pause' ) || '';
		var text = btn.querySelector( '.ndvr-marquee-toggle-text' );
		if ( text ) {
			text.textContent = label;
		}
	} );

	// ── Speed (px/s) normalization ──────────────────────────────────────
	// The `speed` setting is authored in seconds against a REFERENCE_SIZE-px
	// group (roughly what a typical marquee's rendered width/height looked
	// like when that default was chosen). Without this step, --ndvr-duration
	// is a flat number of seconds regardless of how many cards are actually
	// in the group, so a review set with many cards (a much wider/taller
	// group) visually scrolls far faster (more px/s) than a sparse one at the
	// same duration. Scaling duration by the group's real rendered size keeps
	// perceived scroll speed constant across instances and reviews counts.
	var REFERENCE_SIZE = 1200;

	function normalizeSpeed( marquee ) {
		var group = marquee.querySelector( '.ndvr-marquee-group' );
		if ( ! group ) {
			return;
		}
		var vertical = marquee.classList.contains( 'ndvr-marquee-vertical' );
		var size     = vertical ? group.offsetHeight : group.offsetWidth;
		if ( ! size ) {
			return;
		}
		var authored = parseFloat( getComputedStyle( marquee ).getPropertyValue( '--ndvr-duration' ) ) || 40;
		if ( ! marquee.dataset.ndvrBaseDuration ) {
			// Only capture the PHP-authored duration once — recomputing from an
			// already-normalized value on a later resize would compound the scale.
			marquee.dataset.ndvrBaseDuration = String( authored );
		}
		var base       = parseFloat( marquee.dataset.ndvrBaseDuration );
		var normalized = base * ( size / REFERENCE_SIZE );
		marquee.style.setProperty( '--ndvr-duration', Math.max( 5, normalized ).toFixed( 2 ) + 's' );
	}

	var allMarquees = document.querySelectorAll( '.ndvr-marquee' );
	Array.prototype.forEach.call( allMarquees, normalizeSpeed );

	if ( allMarquees.length ) {
		var resizeTimer;
		window.addEventListener( 'resize', function () {
			clearTimeout( resizeTimer );
			resizeTimer = setTimeout( function () {
				Array.prototype.forEach.call( allMarquees, normalizeSpeed );
			}, 200 );
		} );
	}
}() );
