/**
 * ResellingKori Fulfillment Connector - admin UI.
 * Vanilla JS. Remote data is always inserted with textContent (never as HTML).
 */
( function () {
	'use strict';

	var cfg = window.RKC || {};
	var t = cfg.i18n || {};

	/* ------------------------------------------------------------ helpers */

	function el( tag, attrs, kids ) {
		var node = document.createElement( tag );
		Object.keys( attrs || {} ).forEach( function ( k ) {
			var v = attrs[ k ];
			if ( k === 'class' ) {
				node.className = v;
			} else if ( k === 'text' ) {
				node.textContent = v;
			} else if ( k.indexOf( 'on' ) === 0 && typeof v === 'function' ) {
				node.addEventListener( k.slice( 2 ), v );
			} else if ( v !== null && v !== undefined && v !== false ) {
				node.setAttribute( k, v );
			}
		} );
		( kids || [] ).forEach( function ( c ) {
			if ( c === null || c === undefined || c === false ) {
				return;
			}
			node.appendChild( typeof c === 'string' ? document.createTextNode( c ) : c );
		} );
		return node;
	}

	function post( action, data ) {
		var fd = new FormData();
		fd.append( 'action', action );
		fd.append( 'nonce', cfg.nonce );
		Object.keys( data || {} ).forEach( function ( k ) {
			var v = data[ k ];
			if ( Array.isArray( v ) ) {
				v.forEach( function ( x ) {
					fd.append( k + '[]', x );
				} );
			} else {
				fd.append( k, v );
			}
		} );
		return fetch( cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd } )
			.then( function ( r ) {
				return r.json();
			} )
			.catch( function () {
				return { success: false, data: { message: t.network || 'Request failed.' } };
			} );
	}

	function errText( res ) {
		return ( res && res.data && res.data.message ) || t.network || 'Request failed.';
	}

	function fmt( str, args ) {
		return String( str ).replace( /%(\d)\$[ds]/g, function ( m, n ) {
			return args[ Number( n ) - 1 ];
		} );
	}

	function safeUrl( u ) {
		return typeof u === 'string' && /^https?:\/\//i.test( u ) ? u : '';
	}

	function errorBox( text ) {
		return el( 'div', { 'class': 'rkc-msg rkc-error', text: text } );
	}

	/* -------------------------------------------------------- mapping modal */

	var modal = null;

	function closeModal() {
		if ( modal && modal.parentNode ) {
			modal.parentNode.removeChild( modal );
		}
		modal = null;
		document.body.classList.remove( 'rkc-modal-open' );
	}

	document.addEventListener( 'keydown', function ( e ) {
		if ( e.key === 'Escape' && modal ) {
			closeModal();
		}
	} );

	function openModal( productId ) {
		closeModal();

		var body = el( 'div', { 'class': 'rkc-modal-body' }, [ el( 'p', { 'class': 'rkc-muted', text: t.loading } ) ] );
		modal = el( 'div', { 'class': 'rkc-overlay' }, [
			el( 'div', { 'class': 'rkc-modal', role: 'dialog', 'aria-modal': 'true' }, [
				el( 'div', { 'class': 'rkc-modal-head' }, [
					el( 'h2', { text: t.matchTitle } ),
					el( 'button', { type: 'button', 'class': 'rkc-modal-close', 'aria-label': t.close, text: '\u00d7', onclick: closeModal } )
				] ),
				body
			] )
		] );
		modal.addEventListener( 'mousedown', function ( e ) {
			if ( e.target === modal ) {
				closeModal();
			}
		} );
		document.body.appendChild( modal );
		document.body.classList.add( 'rkc-modal-open' );

		post( 'rkc_match', { product_id: productId, parts: 'all' } ).then( function ( res ) {
			body.textContent = '';
			if ( ! res.success ) {
				body.appendChild( errorBox( errText( res ) ) );
				return;
			}
			renderMatch( productId, body, res.data );
		} );
	}

	function renderMatch( pid, body, d ) {
		var p = d.product || {};

		body.appendChild(
			el( 'div', { 'class': 'rkc-prod' }, [
				safeUrl( p.image ) ? el( 'img', { src: p.image, alt: '', width: 48, height: 48 } ) : null,
				el( 'div', {}, [
					el( 'strong', { text: p.name || '' } ),
					el( 'div', { 'class': 'rkc-muted', text: [ p.label, p.sku ? 'SKU ' + p.sku : t.noSku ].filter( Boolean ).join( ' \u00b7 ' ) } )
				] )
			] )
		);

		var msg = el( 'div', { 'class': 'rkc-msg-slot' } );
		body.appendChild( msg );

		// Option 1: exact SKU match.
		body.appendChild( el( 'h3', { text: '1. ' + t.optSku } ) );
		body.appendChild( renderSku( pid, d.sku, msg ) );

		// Option 2: suggestions found by searching ResellingKori with product-name keywords.
		body.appendChild( el( 'h3', { text: '2. ' + t.optKeywords } ) );
		var kwInput = el( 'input', { type: 'text', 'class': 'regular-text', value: d.keywords || '', 'aria-label': t.keywords } );
		var kwBtn = el( 'button', { type: 'button', 'class': 'button', text: t.search } );
		var kwResults = el( 'div', { 'class': 'rkc-results' } );
		body.appendChild( el( 'div', { 'class': 'rkc-kw' }, [ el( 'label', { text: t.keywords + ': ' } ), kwInput, kwBtn ] ) );
		body.appendChild( kwResults );
		renderList( kwResults, pid, d.suggestions || [], 'keyword', msg );

		function research() {
			kwBtn.disabled = true;
			kwResults.textContent = '';
			kwResults.appendChild( el( 'p', { 'class': 'rkc-muted', text: t.loading } ) );
			post( 'rkc_match', { product_id: pid, parts: 'keywords', keywords: kwInput.value } ).then( function ( res ) {
				kwBtn.disabled = false;
				kwResults.textContent = '';
				if ( ! res.success ) {
					kwResults.appendChild( errorBox( errText( res ) ) );
					return;
				}
				kwInput.value = res.data.keywords || kwInput.value;
				renderList( kwResults, pid, res.data.suggestions || [], 'keyword', msg );
			} );
		}
		kwBtn.addEventListener( 'click', research );
		kwInput.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Enter' ) {
				e.preventDefault();
				research();
			}
		} );

		// Option 3: manual ID / SKU.
		body.appendChild( el( 'h3', { text: '3. ' + t.optManual } ) );
		var mInput = el( 'input', { type: 'text', 'class': 'regular-text', placeholder: t.manualHint } );
		var mBtn = el( 'button', { type: 'button', 'class': 'button', text: t.find } );
		var mResults = el( 'div', { 'class': 'rkc-results' } );
		body.appendChild( el( 'div', { 'class': 'rkc-kw' }, [ mInput, mBtn ] ) );
		body.appendChild( mResults );

		function lookup() {
			mBtn.disabled = true;
			mResults.textContent = '';
			post( 'rkc_lookup', { term: mInput.value } ).then( function ( res ) {
				mBtn.disabled = false;
				if ( ! res.success ) {
					mResults.appendChild( errorBox( errText( res ) ) );
					return;
				}
				renderList( mResults, pid, res.data.items || [], 'manual', msg );
			} );
		}
		mBtn.addEventListener( 'click', lookup );
		mInput.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Enter' ) {
				e.preventDefault();
				lookup();
			}
		} );
	}

	function renderSku( pid, sku, msg ) {
		var box = el( 'div', { 'class': 'rkc-sku' } );
		if ( ! sku || sku.state === 'no_sku' ) {
			box.appendChild( el( 'p', { 'class': 'rkc-muted', text: t.skuNoSku } ) );
		} else if ( sku.state === 'none' ) {
			box.appendChild( el( 'p', { 'class': 'rkc-muted', text: t.skuNone + ' ' + ( sku.sku || '' ) } ) );
		} else {
			box.appendChild( el( 'p', { 'class': sku.review ? 'rkc-warntext' : 'rkc-oktext', text: sku.review ? t.skuReview : t.skuExact } ) );
			box.appendChild( candidate( pid, sku.item, 'sku', msg ) );
		}
		return box;
	}

	function renderList( container, pid, items, source, msg ) {
		if ( ! items.length ) {
			container.appendChild( el( 'p', { 'class': 'rkc-muted', text: t.noResults } ) );
			return;
		}
		items.forEach( function ( item ) {
			container.appendChild( candidate( pid, item, source, msg ) );
		} );
	}

	function stockText( x ) {
		if ( x.stock_status !== 'instock' && x.stock_status !== 'onbackorder' ) {
			return t.outOfStock;
		}
		return typeof x.stock_quantity === 'number' ? t.inStock + ': ' + x.stock_quantity : t.inStock;
	}

	function confLabel( c ) {
		return c === 'high' ? t.confHigh : c === 'medium' ? t.confMedium : t.confLow;
	}

	function diffNode( item ) {
		var d = item.diff;
		if ( ! d ) {
			return null;
		}
		var parts = [];
		if ( d.missing && d.missing.length ) {
			parts.push( t.notInRk + ' ' + d.missing.join( ', ' ) );
		}
		if ( d.extra && d.extra.length ) {
			parts.push( t.onlyOnRk + ' ' + d.extra.join( ', ' ) );
		}
		return parts.length ? el( 'div', { 'class': 'rkc-diff', text: parts.join( '  \u00b7  ' ) } ) : null;
	}

	function candidate( pid, item, source, msg ) {
		var bits = [];
		if ( item.sku ) {
			bits.push( 'SKU ' + item.sku );
		}
		bits.push( '#' + item.id );
		if ( item.type !== 'variable' ) {
			bits.push( stockText( item ) );
		}

		var url = safeUrl( item.permalink );
		var card = el( 'div', { 'class': 'rkc-cand' } );
		card.appendChild(
			el( 'div', { 'class': 'rkc-cand-head' }, [
				safeUrl( item.image ) ? el( 'img', { src: item.image, alt: '', width: 48, height: 48 } ) : el( 'span', { 'class': 'rkc-noimg' } ),
				el( 'div', { 'class': 'rkc-cand-main' }, [
					url ? el( 'a', { href: url, target: '_blank', rel: 'noopener noreferrer', text: item.name } ) : el( 'strong', { text: item.name } ),
					el( 'div', { 'class': 'rkc-muted', text: bits.join( ' \u00b7 ' ) } ),
					diffNode( item )
				] ),
				item.confidence ? el( 'span', { 'class': 'rkc-badge rkc-' + item.confidence, text: confLabel( item.confidence ) } ) : null
			] )
		);

		if ( item.type === 'variable' && item.variations && item.variations.length ) {
			card.appendChild( el( 'div', { 'class': 'rkc-muted rkc-pick', text: t.pickVariation } ) );
			var list = el( 'ul', { 'class': 'rkc-vars' } );
			item.variations.forEach( function ( v ) {
				var isMatch = String( item.matched_variation_id || '' ) === String( v.id );
				list.appendChild(
					el( 'li', { 'class': isMatch ? 'is-match' : '' }, [
						el( 'span', { 'class': 'rkc-var-label', text: v.label || '#' + v.id } ),
						el( 'span', { 'class': 'rkc-muted', text: [ v.sku ? 'SKU ' + v.sku : '', stockText( v ) ].filter( Boolean ).join( ' \u00b7 ' ) } ),
						selectButton( pid, item, v.id, source, msg )
					] )
				);
			} );
			card.appendChild( list );
		} else {
			card.appendChild( el( 'div', { 'class': 'rkc-cand-actions' }, [ selectButton( pid, item, 0, source, msg ) ] ) );
		}
		return card;
	}

	function selectButton( pid, item, vid, source, msg ) {
		var label = vid ? t.select : t.link;
		var b = el( 'button', { type: 'button', 'class': 'button button-primary button-small', text: label } );
		b.addEventListener( 'click', function () {
			b.disabled = true;
			b.textContent = t.linking;
			post( 'rkc_link', { product_id: pid, rk_product_id: item.id, rk_variation_id: vid, source: source } ).then( function ( res ) {
				if ( res.success ) {
					closeModal();
					window.location.reload();
					return;
				}
				b.disabled = false;
				b.textContent = label;
				msg.textContent = '';
				msg.appendChild( errorBox( errText( res ) ) );
			} );
		} );
		return b;
	}

	/* ---------------------------------------------------- bulk SKU matching */

	function bulkSku( btn ) {
		var status = document.getElementById( 'rkc-bulk-status' );
		var totals = { linked: 0, review: 0, not_found: 0 };
		var skipped = 0;

		btn.disabled = true;
		status.textContent = t.bulkRunning;

		function step() {
			post( 'rkc_bulk_sku', { skipped: skipped } ).then( function ( res ) {
				if ( ! res.success ) {
					status.textContent = errText( res );
					btn.disabled = false;
					return;
				}
				var d = res.data;
				totals.linked += d.linked;
				totals.review += d.review;
				totals.not_found += d.not_found;
				skipped = d.skipped;
				status.textContent = t.bulkRunning + ' ' + totals.linked;
				if ( d.done ) {
					status.textContent = fmt( t.bulkDone, [ totals.linked, totals.review, totals.not_found ] );
					btn.disabled = false;
					if ( totals.linked > 0 ) {
						setTimeout( function () {
							window.location.reload();
						}, 1500 );
					}
				} else {
					step();
				}
			} );
		}
		step();
	}

	/* ------------------------------------------------------ order actions */

	function boxAction( btn, action ) {
		var box = btn.closest( '.rkc-box' );
		if ( ! box ) {
			return;
		}
		var label = btn.textContent;
		btn.disabled = true;
		btn.textContent = action === 'rkc_send_order' ? t.sending : t.refreshing;

		var data = { order_id: btn.getAttribute( 'data-order-id' ), compact: box.getAttribute( 'data-compact' ) ? 1 : 0 };
		if ( action === 'rkc_send_order' && box.querySelector( '.rkc-pick' ) ) {
			// Mixed order: send only the ticked ResellingKori items.
			data.item_ids = Array.prototype.map.call( box.querySelectorAll( '.rkc-pick:checked' ), function ( c ) {
				return c.value;
			} );
			var ex = box.querySelector( '.rkc-extra' );
			data.extra = ex ? ex.value : 0;
		}

		post( action, data ).then( function ( res ) {
			var d = res.data || {};
			if ( d.html ) {
				// Markup is built and escaped server-side by the plugin.
				box.innerHTML = d.html;
			} else {
				btn.disabled = false;
				btn.textContent = label;
			}
			box.insertBefore(
				el( 'div', { 'class': 'rkc-msg ' + ( res.success ? 'rkc-success' : 'rkc-error' ), text: d.message || t.network } ),
				box.firstChild
			);
		} );
	}

	/* ---------------------------------------- mixed-order amount preview */

	function updatePreview( box ) {
		var wrap = box ? box.querySelector( '.rkc-partial' ) : null;
		var out = box ? box.querySelector( '.rkc-cod-amount' ) : null;
		if ( ! wrap || ! out ) {
			return;
		}
		var sum = 0;
		Array.prototype.forEach.call( box.querySelectorAll( '.rkc-pick:checked' ), function ( c ) {
			sum += parseFloat( c.getAttribute( 'data-total' ) ) || 0;
		} );
		var ex = box.querySelector( '.rkc-extra' );
		sum += ex ? Math.max( 0, parseFloat( ex.value ) || 0 ) : 0;
		out.textContent = ( wrap.getAttribute( 'data-symbol' ) || '' ) + ' ' + sum.toFixed( 2 );
	}

	function onPickChange( e ) {
		if ( e.target && e.target.matches && e.target.matches( '.rkc-pick, .rkc-extra' ) ) {
			updatePreview( e.target.closest( '.rkc-box' ) );
		}
	}
	document.addEventListener( 'change', onPickChange );
	document.addEventListener( 'input', onPickChange );

	/* -------------------------------------------------------- event wiring */

	document.addEventListener( 'click', function ( e ) {
		var target = e.target;
		if ( ! target || ! target.closest ) {
			return;
		}

		var find = target.closest( '.rkc-find' );
		if ( find ) {
			e.preventDefault();
			openModal( find.getAttribute( 'data-product-id' ) );
			return;
		}

		var unlink = target.closest( '.rkc-unlink' );
		if ( unlink ) {
			e.preventDefault();
			if ( window.confirm( t.confirmUnmap ) ) {
				post( 'rkc_unlink', { product_id: unlink.getAttribute( 'data-product-id' ) } ).then( function () {
					window.location.reload();
				} );
			}
			return;
		}

		var bulk = target.closest( '#rkc-bulk-sku' );
		if ( bulk ) {
			e.preventDefault();
			bulkSku( bulk );
			return;
		}

		var send = target.closest( '.rkc-send' );
		if ( send ) {
			e.preventDefault();
			boxAction( send, 'rkc_send_order' );
			return;
		}

		var refresh = target.closest( '.rkc-refresh' );
		if ( refresh ) {
			e.preventDefault();
			boxAction( refresh, 'rkc_refresh_order' );
			return;
		}

		var test = target.closest( '#rkc-test' );
		if ( test ) {
			e.preventDefault();
			var out = document.getElementById( 'rkc-test-result' );
			out.className = 'rkc-test-result';
			out.textContent = t.testing;
			post( 'rkc_test' ).then( function ( res ) {
				out.className = 'rkc-test-result ' + ( res.success ? 'is-ok' : 'is-bad' );
				out.textContent = errText( res );
			} );
		}
	} );

	function autoOpen() {
		var id = new URLSearchParams( window.location.search ).get( 'rkc_open' );
		if ( id && document.getElementById( 'rkc-bulk-sku' ) ) {
			openModal( id );
		}
	}
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', autoOpen );
	} else {
		autoOpen();
	}
} )();
