/**
 * NDV Reviews — front-end review form submission.
 * Vanilla JS, no jQuery. Intercepts the WooCommerce review form and submits
 * it over AJAX so there is no full page reload.
 */
( function () {
	'use strict';

	var cfg = window.ndvrReviews || {};
	var form = document.getElementById( 'ndvr-review-form' );

	if ( ! form || ! cfg.ajaxUrl ) {
		return;
	}

	var messageEl = form.querySelector( '.ndvr-form-message' );

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
