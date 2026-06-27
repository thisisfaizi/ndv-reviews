/**
 * NDV Reviews — tokenized collection landing page.
 * Submits each product review independently (and supports a "submit all" pass).
 * Vanilla JS, no jQuery.
 */
( function () {
	'use strict';

	var root = document.querySelector( '.ndvr-collect' );
	if ( ! root ) {
		return;
	}

	var ajaxUrl = root.getAttribute( 'data-ajax-url' );
	var action = root.getAttribute( 'data-action' );

	function submitForm( form ) {
		return new Promise( function ( resolve ) {
			var msg = form.querySelector( '.ndvr-form-message' );
			var btn = form.querySelector( '.ndvr-collect-submit' );
			var body = new FormData( form );
			body.append( 'action', action );

			if ( btn ) {
				btn.disabled = true;
			}
			if ( msg ) {
				msg.textContent = '';
				msg.className = 'ndvr-form-message';
			}

			fetch( ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
				.then( function ( r ) { return r.json(); } )
				.then( function ( res ) {
					if ( res && res.success ) {
						if ( msg ) {
							msg.textContent = ( res.data && res.data.message ) || 'Thank you!';
							msg.className = 'ndvr-form-message is-success';
						}
						form.classList.add( 'is-done' );
						var fields = form.querySelector( '.ndvr-fields' );
						if ( fields ) {
							fields.style.display = 'none';
						}
						resolve( true );
					} else {
						if ( msg ) {
							msg.textContent = ( res && res.data && res.data.message ) || 'Something went wrong.';
							msg.className = 'ndvr-form-message is-error';
						}
						if ( btn ) {
							btn.disabled = false;
						}
						resolve( false );
					}
				} )
				.catch( function () {
					if ( msg ) {
						msg.textContent = 'Network error. Please try again.';
						msg.className = 'ndvr-form-message is-error';
					}
					if ( btn ) {
						btn.disabled = false;
					}
					resolve( false );
				} );
		} );
	}

	Array.prototype.forEach.call( root.querySelectorAll( '.ndvr-collect-form' ), function ( form ) {
		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			submitForm( form );
		} );
	} );
}() );
