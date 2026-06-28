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
			} )
			.catch( function () { listWrap.classList.remove( 'is-loading' ); } );
	}

	if ( pills ) {
		pills.addEventListener( 'click', function ( e ) {
			var btn = e.target.closest( '.ndvr-topic' );
			if ( ! btn ) {
				return;
			}
			Array.prototype.forEach.call( pills.querySelectorAll( '.ndvr-topic' ), function ( b ) {
				b.classList.remove( 'is-current' );
			} );
			btn.classList.add( 'is-current' );
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
			} );
			btn.classList.add( 'is-current' );
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

	// Pagination (delegated, survives list replacement).
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
		}
	} );

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
