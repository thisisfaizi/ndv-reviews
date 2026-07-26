/**
 * NDV Reviews — front-end display interactions (filter/sort/paginate + voting).
 * Vanilla JS, no jQuery.
 */
( function () {
	'use strict';

	var cfg = window.ndvrDisplay || {};
	var wrap = document.getElementById( 'ndvr-reviews' );
	if ( ! wrap || ! cfg.ajaxUrl ) {
		return;
	}

	var listWrap = document.getElementById( 'ndvr-review-list' );
	var filterBar = wrap.querySelector( '.ndvr-filter-bar' );
	var productId = wrap.getAttribute( 'data-product' );

	var state = { star: 0, verified: false, with_media: false, orderby: 'recent', tag: '', page: 1 };
	var pills = wrap.querySelector( '.ndvr-topic-pills' );

	function fetchList() {
		if ( ! listWrap ) {
			return;
		}
		listWrap.classList.add( 'is-loading' );
		listWrap.setAttribute( 'aria-busy', 'true' );

		var body = new FormData();
		body.append( 'action', cfg.action );
		body.append( 'nonce', cfg.nonce );
		body.append( 'product_id', productId );
		body.append( 'star', state.star );
		body.append( 'verified', state.verified ? '1' : '' );
		body.append( 'with_media', state.with_media ? '1' : '' );
		body.append( 'orderby', state.orderby );
		body.append( 'tag', state.tag );
		body.append( 'page', state.page );

		fetch( cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( res ) {
				listWrap.classList.remove( 'is-loading' );
				if ( res && res.success && res.data && typeof res.data.html === 'string' ) {
					listWrap.innerHTML = res.data.html;
					wrap.scrollIntoView( { behavior: 'smooth', block: 'start' } );
				}
				listWrap.setAttribute( 'aria-busy', 'false' );
			} )
			.catch( function () {
				listWrap.classList.remove( 'is-loading' );
				listWrap.setAttribute( 'aria-busy', 'false' );
			} );
	}

	if ( pills ) {
		pills.addEventListener( 'click', function ( e ) {
			var btn = e.target.closest( '.ndvr-topic' );
			if ( ! btn ) {
				return;
			}
			Array.prototype.forEach.call( pills.querySelectorAll( '.ndvr-topic' ), function ( b ) {
				b.classList.remove( 'is-current' );
				b.setAttribute( 'aria-pressed', 'false' );
			} );
			btn.classList.add( 'is-current' );
			btn.setAttribute( 'aria-pressed', 'true' );
			state.tag = btn.getAttribute( 'data-value' ) || '';
			state.page = 1;
			fetchList();
		} );
	}

	if ( filterBar ) {
		filterBar.addEventListener( 'click', function ( e ) {
			var btn = e.target.closest( '.ndvr-filter[data-filter="star"]' );
			if ( ! btn ) {
				return;
			}
			Array.prototype.forEach.call( filterBar.querySelectorAll( '.ndvr-filter[data-filter="star"]' ), function ( b ) {
				b.classList.remove( 'is-current' );
				b.setAttribute( 'aria-pressed', 'false' );
			} );
			btn.classList.add( 'is-current' );
			btn.setAttribute( 'aria-pressed', 'true' );
			state.star = parseInt( btn.getAttribute( 'data-value' ), 10 ) || 0;
			state.page = 1;
			fetchList();
		} );

		filterBar.addEventListener( 'change', function ( e ) {
			var el = e.target;
			var filter = el.getAttribute( 'data-filter' );
			if ( filter === 'verified' ) {
				state.verified = el.checked;
			} else if ( filter === 'with_media' ) {
				state.with_media = el.checked;
			} else if ( filter === 'orderby' ) {
				state.orderby = el.value;
			} else {
				return;
			}
			state.page = 1;
			fetchList();
		} );
	}

	// Pagination + photo lightbox (delegated, survives list replacement).
	wrap.addEventListener( 'click', function ( e ) {
		var page = e.target.closest( '.ndvr-page' );
		if ( page ) {
			state.page = parseInt( page.getAttribute( 'data-page' ), 10 ) || 1;
			fetchList();
			return;
		}

		var helpful = e.target.closest( '.ndvr-helpful' );
		if ( helpful && ! helpful.disabled ) {
			vote( helpful );
			return;
		}

		var photo = e.target.closest( '.ndvr-review-photo' );
		if ( photo ) {
			e.preventDefault();
			openLightbox( photo );
		}
	} );

	// ── Photo lightbox ──────────────────────────────────────────────
	// Keyboard-operable modal: Escape/overlay/close-button dismiss, Left/Right
	// arrows step through the same review's photos, focus moves into the
	// dialog on open and returns to the trigger link on close.
	var lightbox = null;
	var lbGroup = [];
	var lbIndex = 0;
	var lbReturnFocus = null;

	function buildLightbox() {
		if ( lightbox ) {
			return lightbox;
		}
		lightbox = document.createElement( 'div' );
		lightbox.className = 'ndvr-lightbox';
		lightbox.hidden = true;
		var i18n = cfg.i18n || {};
		lightbox.innerHTML =
			'<div class="ndvr-lightbox-overlay" data-ndvr-close></div>' +
			'<div class="ndvr-lightbox-dialog" role="dialog" aria-modal="true" aria-label="' + ( i18n.photo || 'Customer photo' ) + '">' +
			'<button type="button" class="ndvr-lightbox-close" data-ndvr-close aria-label="' + ( i18n.close || 'Close' ) + '">&times;</button>' +
			'<button type="button" class="ndvr-lightbox-prev" aria-label="' + ( i18n.prev || 'Previous photo' ) + '">&lsaquo;</button>' +
			'<img class="ndvr-lightbox-img" src="" alt="" />' +
			'<button type="button" class="ndvr-lightbox-next" aria-label="' + ( i18n.next || 'Next photo' ) + '">&rsaquo;</button>' +
			'</div>';
		document.body.appendChild( lightbox );

		lightbox.addEventListener( 'click', function ( e ) {
			if ( e.target.closest( '[data-ndvr-close]' ) ) {
				closeLightbox();
			} else if ( e.target.closest( '.ndvr-lightbox-prev' ) ) {
				stepLightbox( -1 );
			} else if ( e.target.closest( '.ndvr-lightbox-next' ) ) {
				stepLightbox( 1 );
			}
		} );

		lightbox.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Escape' ) {
				closeLightbox();
			} else if ( e.key === 'ArrowLeft' ) {
				stepLightbox( -1 );
			} else if ( e.key === 'ArrowRight' ) {
				stepLightbox( 1 );
			} else if ( e.key === 'Tab' ) {
				// Simple focus trap: only the dialog's own buttons are focusable.
				var focusable = lightbox.querySelectorAll( 'button' );
				var first = focusable[ 0 ];
				var last = focusable[ focusable.length - 1 ];
				if ( e.shiftKey && document.activeElement === first ) {
					e.preventDefault();
					last.focus();
				} else if ( ! e.shiftKey && document.activeElement === last ) {
					e.preventDefault();
					first.focus();
				}
			}
		} );

		return lightbox;
	}

	function showLightboxImage() {
		var link = lbGroup[ lbIndex ];
		var img = lightbox.querySelector( '.ndvr-lightbox-img' );
		img.src = link.getAttribute( 'href' );
		img.alt = link.querySelector( 'img' ) ? link.querySelector( 'img' ).alt : '';
		var multi = lbGroup.length > 1;
		lightbox.querySelector( '.ndvr-lightbox-prev' ).hidden = ! multi;
		lightbox.querySelector( '.ndvr-lightbox-next' ).hidden = ! multi;
	}

	function stepLightbox( delta ) {
		lbIndex = ( lbIndex + delta + lbGroup.length ) % lbGroup.length;
		showLightboxImage();
	}

	function openLightbox( trigger ) {
		var media = trigger.closest( '.ndvr-review-media' );
		lbGroup = media ? Array.prototype.slice.call( media.querySelectorAll( '.ndvr-review-photo' ) ) : [ trigger ];
		lbIndex = lbGroup.indexOf( trigger );
		if ( lbIndex < 0 ) {
			lbIndex = 0;
		}
		lbReturnFocus = trigger;

		buildLightbox();
		showLightboxImage();
		lightbox.hidden = false;
		lightbox.querySelector( '.ndvr-lightbox-close' ).focus();
		document.addEventListener( 'keydown', trapEscapeAtDocument, true );
	}

	function closeLightbox() {
		if ( ! lightbox || lightbox.hidden ) {
			return;
		}
		lightbox.hidden = true;
		document.removeEventListener( 'keydown', trapEscapeAtDocument, true );
		if ( lbReturnFocus ) {
			lbReturnFocus.focus();
		}
	}

	// Belt-and-braces: Escape closes even if focus somehow left the dialog.
	function trapEscapeAtDocument( e ) {
		if ( e.key === 'Escape' ) {
			closeLightbox();
		}
	}

	function vote( btn ) {
		var body = new FormData();
		body.append( 'action', cfg.voteAction );
		body.append( 'nonce', btn.getAttribute( 'data-nonce' ) );
		body.append( 'comment_id', btn.getAttribute( 'data-comment-id' ) );

		btn.disabled = true;

		fetch( cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( res ) {
				if ( res && res.success && res.data && typeof res.data.count !== 'undefined' ) {
					var c = btn.querySelector( '.ndvr-helpful-count' );
					if ( c ) {
						c.textContent = '(' + res.data.count + ')';
					}
					btn.classList.add( 'is-voted' );
				} else {
					btn.disabled = false;
				}
			} )
			.catch( function () { btn.disabled = false; } );
	}
}() );
