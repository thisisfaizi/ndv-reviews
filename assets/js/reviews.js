/**
 * NDV Reviews — front-end review form submission + UI enhancements.
 * Vanilla JS, no jQuery. Intercepts the WooCommerce review form and submits
 * it over AJAX so there is no full page reload.
 */
( function () {
	'use strict';

	var cfg = window.ndvrReviews || {};
	var form = document.getElementById( 'ndvr-review-form' );

	if ( ! form ) {
		return;
	}

	var messageEl = form.querySelector( '.ndvr-form-message' );

	// ── Recommend pills ── JS fallback for browsers without CSS :has() ──────
	var recommendLabels = form.querySelectorAll( '.ndvr-field-recommend label' );
	function syncRecommendPills() {
		recommendLabels.forEach( function ( label ) {
			var radio = label.querySelector( 'input[type="radio"]' );
			if ( radio ) {
				label.classList.toggle( 'is-checked', radio.checked );
			}
		} );
	}
	recommendLabels.forEach( function ( label ) {
		label.addEventListener( 'click', function () {
			// Let the browser process the click first, then sync
			setTimeout( syncRecommendPills, 0 );
		} );
	} );
	syncRecommendPills(); // mark the pre-checked "Neutral" on load

	// ── Upload zone enhancements ─────────────────────────────────────────────
	var uploadWrappers = form.querySelectorAll( '.ndvr-upload-wrapper' );
	uploadWrappers.forEach( function ( wrapper ) {
		var fileInput = wrapper.querySelector( 'input[type="file"]' );
		var countEl   = wrapper.querySelector( '.ndvr-upload-count' );

		if ( ! fileInput ) return;

		function updateCount() {
			if ( ! countEl ) return;
			var n = fileInput.files ? fileInput.files.length : 0;
			if ( n === 0 ) {
				countEl.textContent = '';
			} else {
				countEl.textContent = n === 1
					? '1 photo selected'
					: n + ' photos selected';
			}
		}

		fileInput.addEventListener( 'change', updateCount );

		// Drag-over visual feedback
		wrapper.addEventListener( 'dragenter', function ( e ) {
			e.preventDefault();
			wrapper.classList.add( 'is-dragging' );
		} );
		wrapper.addEventListener( 'dragover', function ( e ) {
			e.preventDefault();
		} );
		wrapper.addEventListener( 'dragleave', function ( e ) {
			if ( ! wrapper.contains( e.relatedTarget ) ) {
				wrapper.classList.remove( 'is-dragging' );
			}
		} );
		wrapper.addEventListener( 'drop', function () {
			wrapper.classList.remove( 'is-dragging' );
			setTimeout( updateCount, 0 );
		} );
	} );

	// ── Form submission ──────────────────────────────────────────────────────
	if ( ! cfg.ajaxUrl ) {
		return;
	}

	function setMessage( text, type ) {
		if ( ! messageEl ) {
			return;
		}
		messageEl.textContent = text;
		messageEl.className = 'ndvr-form-message' + ( type ? ' is-' + type : '' );
	}

	function withRecaptcha( callback ) {
		var tokenInput = form.querySelector( 'input[name="ndvr_recaptcha_token"]' );
		if ( tokenInput && tokenInput.getAttribute( 'data-recaptcha' ) === '1' && window.grecaptcha && cfg.siteKey !== false ) {
			try {
				window.grecaptcha.ready( function () {
					window.grecaptcha.execute( ( window.ndvrReviews && window.ndvrReviews.siteKey ) || '', { action: 'review' } ).then( function ( token ) {
						tokenInput.value = token;
						callback();
					} ).catch( callback );
				} );
				return;
			} catch ( e ) {
				callback();
				return;
			}
		}
		callback();
	}

	function submit() {
		var data = new FormData( form );
		data.append( 'action', cfg.action );

		// Ensure the product id is present (WooCommerce uses comment_post_ID).
		if ( ! data.get( 'product_id' ) && data.get( 'comment_post_ID' ) ) {
			data.append( 'product_id', data.get( 'comment_post_ID' ) );
		}

		form.classList.add( 'is-submitting' );
		setMessage( ( cfg.i18n && cfg.i18n.submitting ) || 'Submitting…', '' );

		fetch( cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: data
		} )
			.then( function ( r ) { return r.json(); } )
			.then( function ( res ) {
				form.classList.remove( 'is-submitting' );
				if ( res && res.success ) {
					setMessage( ( res.data && res.data.message ) || ( cfg.i18n && cfg.i18n.thanks ), 'success' );
					form.reset();
					// Reset upload count displays
					uploadWrappers.forEach( function ( wrapper ) {
						var c = wrapper.querySelector( '.ndvr-upload-count' );
						if ( c ) c.textContent = '';
					} );
					// Reset recommend pills
					syncRecommendPills();
				} else {
					setMessage( ( res && res.data && res.data.message ) || ( cfg.i18n && cfg.i18n.error ), 'error' );
				}
			} )
			.catch( function () {
				form.classList.remove( 'is-submitting' );
				setMessage( ( cfg.i18n && cfg.i18n.error ) || 'Something went wrong.', 'error' );
			} );
	}

	form.addEventListener( 'submit', function ( e ) {
		e.preventDefault();
		withRecaptcha( submit );
	} );
}() );
