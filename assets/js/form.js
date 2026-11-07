( function () {
	'use strict';

	if ( typeof window.sarForm === 'undefined' || ! window.fetch ) {
		return; // The form still posts normally.
	}

	var config = window.sarForm;
	var t = config.i18n;
	var POLL_MS = 3000;
	var MAX_POLL_MS = 12 * 60 * 1000;

	function post( action, data ) {
		data.append( 'action', action );
		return fetch( config.ajaxUrl, {
			method: 'POST',
			body: data,
			credentials: 'same-origin',
		} ).then( function ( response ) {
			return response.json();
		} );
	}

	function make( tag, className, text ) {
		var node = document.createElement( tag );
		if ( className ) {
			node.className = className;
		}
		if ( text !== undefined ) {
			node.textContent = text;
		}
		return node;
	}

	function submit( form, retried ) {
		var message = form.querySelector( '.sar-form__message' );
		var button = form.querySelector( 'button[type=submit]' );
		message.textContent = '';
		button.disabled = true;

		var data = new FormData( form );
		data.delete( 'action' );

		post( 'sar_submit', data )
			.then( function ( json ) {
				if ( json.success ) {
					watch( form, json.data );
					return;
				}
				var code = json.data && json.data.code;
				if ( code === 'sar_bad_nonce' && ! retried ) {
					// The page may be cached, so fetch a fresh nonce and retry once.
					return post( 'sar_nonce', new FormData() ).then( function ( fresh ) {
						if ( fresh.success ) {
							form.querySelector( '[name=sar_nonce]' ).value = fresh.data.nonce;
							submit( form, true );
						} else {
							throw new Error();
						}
					} );
				}
				message.textContent = ( json.data && json.data.message ) || t.error;
				button.disabled = false;
				focusFirstInvalid( form, code );
			} )
			.catch( function () {
				message.textContent = t.error;
				button.disabled = false;
			} );
	}

	function focusFirstInvalid( form, code ) {
		var map = {
			sar_business: 'sar_business',
			sar_email: 'sar_email',
			sar_consent: 'sar_consent',
			sar_url_empty: 'sar_url',
			sar_url_invalid: 'sar_url',
			sar_url_scheme: 'sar_url',
			sar_url_blocked: 'sar_url',
			sar_dns_failed: 'sar_url',
		};
		var field = map[ code ] && form.querySelector( '[name=' + map[ code ] + ']' );
		if ( field ) {
			field.focus();
		}
	}

	function watch( form, submission ) {
		var result = form.parentNode.querySelector( '.sar-result' );
		form.hidden = true;
		result.hidden = false;

		var view = buildView( result, submission.reportUrl );
		result.focus( { preventScroll: true } );
		result.scrollIntoView( { block: 'center' } );

		var started = Date.now();

		function poll() {
			var data = new FormData();
			data.append( 'token', submission.token );
			post( 'sar_status', data )
				.then( function ( json ) {
					if ( ! json.success ) {
						throw new Error();
					}
					render( view, json.data );
					if ( json.data.status === 'queued' || json.data.status === 'running' ) {
						if ( Date.now() - started > MAX_POLL_MS ) {
							view.note.textContent = t.slow;
							return;
						}
						setTimeout( poll, POLL_MS );
					}
				} )
				.catch( function () {
					if ( Date.now() - started <= MAX_POLL_MS ) {
						setTimeout( poll, POLL_MS * 2 );
					}
				} );
		}
		poll();
	}

	function buildView( result, reportUrl ) {
		result.textContent = '';
		var view = {
			stage: make( 'p', 'sar-result__stage', t.working ),
			bar: make( 'progress' ),
			score: make( 'div', 'sar-result__score' ),
			scoreLabel: make( 'p', 'sar-result__label' ),
			categories: make( 'ul', 'sar-result__cats' ),
			fixesTitle: make( 'h3', 'sar-result__fixes-title', t.topFixes ),
			fixes: make( 'ol', 'sar-result__fixes' ),
			note: make( 'p', 'sar-result__note', t.stillGoing ),
			link: make( 'a', 'sar-button', t.open ),
			live: make( 'p', 'sar-sr' ),
			wasFinished: false,
		};
		view.bar.max = 100;
		view.bar.value = 0;
		view.bar.setAttribute( 'aria-label', t.working );
		view.score.hidden = true;
		view.scoreLabel.hidden = true;
		view.fixesTitle.hidden = true;
		view.link.href = reportUrl;
		view.live.setAttribute( 'role', 'status' );

		[
			view.live,
			view.stage,
			view.bar,
			view.scoreLabel,
			view.score,
			view.categories,
			view.fixesTitle,
			view.fixes,
			view.note,
			view.link,
		].forEach( function ( node ) {
			result.appendChild( node );
		} );
		return view;
	}

	function render( view, status ) {
		var finished = status.status === 'complete';
		var failed = status.status === 'failed';

		view.bar.value = status.progress;
		view.stage.textContent = status.stage;

		if ( status.score !== null ) {
			view.score.hidden = false;
			view.scoreLabel.hidden = false;
			view.scoreLabel.textContent = finished ? t.final : t.early;
			view.score.textContent = status.score + ' ';
			view.score.appendChild( make( 'small', '', t.outOf ) );
		}

		view.categories.textContent = '';
		status.categories.forEach( function ( category ) {
			var item = make( 'li' );
			item.appendChild( make( 'span', '', category.label ) );
			item.appendChild( make( 'strong', '', category.score === null ? t.notChecked : String( category.score ) ) );
			view.categories.appendChild( item );
		} );

		view.fixes.textContent = '';
		view.fixesTitle.hidden = ! status.fixes.length;
		status.fixes.forEach( function ( fix ) {
			view.fixes.appendChild( make( 'li', '', fix ) );
		} );

		if ( finished || failed ) {
			view.stage.hidden = true;
			view.bar.hidden = true;
			if ( failed ) {
				view.note.textContent = status.error || t.failed;
				view.link.hidden = true;
			} else {
				view.note.textContent = status.score === null ? t.noScore + ' ' + t.ready : t.ready;
			}
			if ( ! view.wasFinished ) {
				view.wasFinished = true;
				view.live.textContent = view.note.textContent;
			}
		}
	}

	document.addEventListener( 'submit', function ( event ) {
		var form = event.target;
		if ( form.classList && form.classList.contains( 'sar-form' ) ) {
			event.preventDefault();
			submit( form, false );
		}
	} );
} )();
