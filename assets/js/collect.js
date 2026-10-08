/**
 * NDV Reviews — collection forms (tokenized landing page + [ndvr-testimonial]).
 * Submits each product review independently. Vanilla JS, no jQuery.
 *
 * reCAPTCHA v3: when the form container carries data-recaptcha-key (the site
 * key, output only when reCAPTCHA is enabled), the Google script is loaded on
 * first submit and its token is sent as ndvr_recaptcha_token. The tokenized
 * landing page never sets it — the review link authenticates the customer.
 */
( function () {
	'use strict';

	var roots = document.querySelectorAll( '.ndvr-collect' );
	if ( ! roots.length ) {
		return;
	}

	var recaptchaLoading = null;

	function loadRecaptcha( siteKey ) {
		if ( window.grecaptcha && window.grecaptcha.execute ) {
			return Promise.resolve();
		}
		if ( ! recaptchaLoading ) {
			recaptchaLoading = new Promise( function ( resolve, reject ) {
				var s = document.createElement( 'script' );
				s.src = 'https://www.google.com/recaptcha/api.js?render=' + encodeURIComponent( siteKey );
				s.async = true;
				s.onload = function () { resolve(); };
				s.onerror = function () { recaptchaLoading = null; reject(); };
				document.head.appendChild( s );
			} );
		}
		return recaptchaLoading;
	}

	function recaptchaToken( siteKey ) {
		if ( ! siteKey ) {
			return Promise.resolve( '' );
		}
		return loadRecaptcha( siteKey ).then( function () {
			return new Promise( function ( resolve ) {
				window.grecaptcha.ready( function () {
					window.grecaptcha.execute( siteKey, { action: 'review' } ).then( resolve, function () { resolve( '' ); } );
				} );
			} );
		} ).catch( function () {
			// Script blocked or offline: the server reports the captcha failure.
			return '';
		} );
	}

	function submitForm( root, form ) {
		var ajaxUrl = root.getAttribute( 'data-ajax-url' );
		var action = root.getAttribute( 'data-action' );
		var siteKey = root.getAttribute( 'data-recaptcha-key' ) || '';
		var msg = form.querySelector( '.ndvr-form-message' );
		var btn = form.querySelector( '.ndvr-collect-submit' );

		if ( btn ) {
			btn.disabled = true;
		}
		if ( msg ) {
			msg.textContent = '';
			msg.className = 'ndvr-form-message';
		}

		function fail( text ) {
			if ( msg ) {
				msg.textContent = text;
				msg.className = 'ndvr-form-message is-error';
			}
			if ( btn ) {
				btn.disabled = false;
			}
		}

		return recaptchaToken( siteKey ).then( function ( token ) {
			var body = new FormData( form );
			body.append( 'action', action );
			if ( token ) {
				body.append( 'ndvr_recaptcha_token', token );
			}

			return fetch( ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
				.then( function ( r ) { return r.json(); } )
				.then( function ( res ) {
					if ( res && res.success ) {
						if ( msg ) {
							msg.textContent = ( res.data && res.data.message ) || 'Thank you.';
							msg.className = 'ndvr-form-message is-success';
						}
						form.classList.add( 'is-done' );
						var fields = form.querySelector( '.ndvr-fields' );
						if ( fields ) {
							// Keep the status message visible: move it out before hiding the fields.
							if ( msg && fields.contains( msg ) ) {
								fields.parentNode.insertBefore( msg, fields );
							}
							fields.style.display = 'none';
						}
						return true;
					}
					fail( ( res && res.data && res.data.message ) || 'Something went wrong.' );
					return false;
				} )
				.catch( function () {
					fail( 'Network error. Please try again.' );
					return false;
				} );
		} );
	}

	Array.prototype.forEach.call( roots, function ( root ) {
		Array.prototype.forEach.call( root.querySelectorAll( '.ndvr-collect-form' ), function ( form ) {
			form.addEventListener( 'submit', function ( e ) {
				e.preventDefault();
				submitForm( root, form );
			} );
		} );
	} );
}() );
