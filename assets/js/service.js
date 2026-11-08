/* Site Audit Report — Security Check. All remote text is set with textContent. */
( function () {
	'use strict';

	var cfg = window.sarBay;
	if ( ! cfg || ! window.fetch ) {
		return;
	}
	var t = cfg.i18n;
	var POLL_MS = 1500;
	var MAX_MS = 10 * 60 * 1000;

	function el( tag, cls, text ) {
		var n = document.createElement( tag );
		if ( cls ) {
			n.className = cls;
		}
		if ( text !== undefined && text !== null ) {
			n.textContent = String( text );
		}
		return n;
	}
	function money( n ) {
		return cfg.currency + Number( n || 0 ).toLocaleString();
	}
	function post( action, fields ) {
		var data = new FormData();
		data.append( 'action', action );
		Object.keys( fields ).forEach( function ( k ) {
			data.append( k, fields[ k ] );
		} );
		return fetch( cfg.ajaxUrl, { method: 'POST', body: data, credentials: 'same-origin' } ).then( function ( r ) {
			return r.json();
		} );
	}

	document.querySelectorAll( '.sar-bay' ).forEach( init );

	function init( root ) {
		var $ = function ( sel ) {
			return root.querySelector( sel );
		};
		var bind = function ( name ) {
			return root.querySelector( '[data-bind="' + name + '"]' );
		};

		var state = { token: '', data: null, picked: {}, recommended: {}, touched: false, timer: 0, started: 0 };
		var plate = $( '.bay-plate' );
		var input = $( '.bay-plate__input' );
		var go = $( '.bay-plate__go' );
		var msg = $( '.bay-plate__msg' );

		/* ---------- Scan */
		plate.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			start( false );
		} );
		root.querySelectorAll( '[data-try]' ).forEach( function ( chip ) {
			chip.addEventListener( 'click', function () {
				input.value = chip.getAttribute( 'data-try' ) === '__self' ? cfg.home : chip.getAttribute( 'data-try' );
				start( false );
			} );
		} );

		function start( retried ) {
			var url = input.value.trim();
			msg.textContent = '';
			if ( ! url ) {
				msg.textContent = t.error;
				input.focus();
				return;
			}
			go.disabled = true;
			resetConsole( url );
			post( 'sar_service_start', { nonce: cfg.nonce, url: url, sar_company_site: root.querySelector( '.bay-plate .bay-hp' ).value } )
				.then( function ( json ) {
					if ( json.success ) {
						follow( json.data.token, true );
						return;
					}
					if ( json.data && json.data.nonce ) {
						cfg.nonce = json.data.nonce;
						if ( json.data.code === 'sar_bad_nonce' && ! retried ) {
							return start( true );
						}
					}
					fail( ( json.data && json.data.message ) || t.error );
				} )
				.catch( function () {
					fail( t.error );
				} );
		}

		function fail( text ) {
			go.disabled = false;
			msg.textContent = text;
			root.setAttribute( 'data-state', 'idle' );
			bind( 'badge' ).textContent = 'Idle';
			bind( 'stage' ).textContent = text;
		}

		function resetConsole( url ) {
			root.setAttribute( 'data-state', 'running' );
			bind( 'domain' ).textContent = url.replace( /^https?:\/\//i, '' ).replace( /\/.*$/, '' );
			bind( 'badge' ).textContent = 'Live';
			setMeter( 4 );
			bind( 'stage' ).textContent = 'Connecting…';
			root.querySelectorAll( '[data-spec]' ).forEach( function ( dd ) {
				dd.textContent = '—';
				dd.classList.remove( 'is-bad' );
			} );
			bind( 'toReport' ).hidden = true;
			drawBoard( [] );
		}

		function setMeter( pct ) {
			bind( 'meter' ).style.width = pct + '%';
			$( '.bay-meter' ).setAttribute( 'aria-valuenow', String( pct ) );
		}

		function follow( token, push ) {
			state.token = token;
			state.started = Date.now();
			if ( push && window.history && window.history.replaceState ) {
				var u = new URL( window.location.href );
				u.searchParams.set( cfg.param, token );
				u.hash = '';
				window.history.replaceState( null, '', u.toString() );
			}
			poll();
		}

		function poll() {
			clearTimeout( state.timer );
			post( 'sar_service_status', { token: state.token } )
				.then( function ( json ) {
					if ( ! json.success ) {
						fail( ( json.data && json.data.message ) || t.error );
						return;
					}
					render( json.data );
					if ( json.data.status === 'queued' || json.data.status === 'running' ) {
						if ( Date.now() - state.started > MAX_MS ) {
							bind( 'stage' ).textContent = t.slow;
							return;
						}
						state.timer = setTimeout( poll, POLL_MS );
					}
				} )
				.catch( function () {
					if ( Date.now() - state.started < MAX_MS ) {
						state.timer = setTimeout( poll, POLL_MS * 2 );
					}
				} );
		}

		/* ---------- Console */
		function render( d ) {
			state.data = d;
			var done = d.status === 'complete';
			var failed = d.status === 'failed';
			bind( 'domain' ).textContent = d.site.domain;
			if ( input.value.trim() === '' ) {
				input.value = d.site.domain;
			}
			setMeter( done ? 100 : Math.max( 4, d.progress ) );
			bind( 'stage' ).textContent = failed ? ( d.error || t.error ) : done ? 'Inspection complete · ' + ( d.site.at || '' ) : ( d.stage || 'Waiting for a free bay…' ) + '…';
			bind( 'badge' ).textContent = failed ? 'Stopped' : done ? 'Done' : 'Live';
			root.setAttribute( 'data-state', done ? 'done' : failed ? 'failed' : 'running' );

			var ins = d.inspection;
			spec( 'server', ins.server || ( done ? t.hidden : '' ) );
			spec( 'cdn', ins.cdn || ( done ? t.none : '' ) );
			spec( 'core', ins.isWp === false ? t.notWp : ins.core || ( ins.isWp ? t.hidden : '' ) );
			spec( 'theme', ins.theme || ( done && ins.isWp ? t.hidden : done ? '—' : '' ) );
			spec( 'plugins', ins.plugins === null ? '' : String( ins.plugins ) );
			spec( 'https', ins.https ? ( ins.https === 'pass' ? 'Valid' : ins.https === 'fail' ? 'Broken' : 'Check' ) : '', ins.https === 'fail' || ins.https === 'warn' );
			drawBoard( d.board );

			if ( done || failed ) {
				go.disabled = false;
			}
			if ( done ) {
				bind( 'toReport' ).hidden = false;
				drawReport( d );
				applyRecommendations( d.recommend || {} );
				var web = root.querySelector( '.bay-form [name=website]' );
				if ( web && ! web.value ) {
					web.value = d.site.url;
				}
				markSteps( 3 );
			}
		}

		function spec( key, value, bad ) {
			var dd = root.querySelector( '[data-spec="' + key + '"]' );
			if ( ! value ) {
				return;
			}
			if ( dd.textContent !== value ) {
				dd.textContent = value;
				dd.title = value;
				dd.classList.remove( 'is-new' );
				void dd.offsetWidth;
				dd.classList.add( 'is-new' );
			}
			dd.classList.toggle( 'is-bad', !! bad );
		}

		var marks = { pass: '✓', warn: '!', fail: '✕', info: 'i', not_checked: '–', pending: '' };
		function drawBoard( board ) {
			var list = bind( 'board' );
			list.textContent = '';
			var seen = 0;
			board.forEach( function ( item ) {
				var li = el( 'li' );
				var status = item.status || 'pending';
				if ( status !== 'pending' ) {
					seen++;
				}
				li.appendChild( el( 'span', 'bay-tick bay-tick--' + status, marks[ status ] || '' ) );
				li.appendChild( el( 'span', '', item.label ) );
				li.title = item.evidence || item.label;
				list.appendChild( li );
			} );
			bind( 'boardCount' ).textContent = board.length ? 'Passive · ' + seen + '/' + board.length : 'Passive';
		}

		/* ---------- Report */
		var verdicts = {
			critical: [ 'Critical', 'Fix this <em>today.</em>' ],
			high: [ 'High risk', 'Holes worth <em>closing now.</em>' ],
			medium: [ 'Moderate risk', 'A few things <em>to tidy.</em>' ],
			low: [ 'Low risk', 'Solid, with <em>small gaps.</em>' ],
			clean: [ 'All clear', 'Nothing known. <em>Keep it so.</em>' ],
			unknown: [ 'No reading', 'We could not <em>see in.</em>' ],
		};

		function drawReport( d ) {
			bind( 'reportEmpty' ).hidden = true;
			bind( 'report' ).hidden = false;
			var v = d.verdict;
			var box = bind( 'verdict' );
			box.setAttribute( 'data-grade', v.grade );
			var copy = verdicts[ v.grade ] || verdicts.unknown;
			bind( 'verdictKicker' ).textContent = '[ ' + copy[ 0 ] + ' · ' + d.site.domain + ' ]';
			var title = bind( 'verdictTitle' );
			title.textContent = '';
			var parts = copy[ 1 ].split( /<\/?em>/ );
			title.appendChild( document.createTextNode( parts[ 0 ] ) );
			if ( parts[ 1 ] ) {
				title.appendChild( el( 'em', '', parts[ 1 ] ) );
			}
			bind( 'verdictText' ).textContent = verdictText( d );

			var tiles = bind( 'tiles' );
			tiles.textContent = '';
			[
				[ 'critical', v.counts.critical, 'Critical' ],
				[ 'high', v.counts.high, 'High' ],
				[ 'medium', v.counts.medium + v.counts.unknown, 'Medium' ],
				[ 'low', v.counts.low, 'Low' ],
				[ 'kev', v.kev, 'Exploited (CISA)' ],
				[ 'days', v.oldest, 'Oldest, days public' ],
			].forEach( function ( row ) {
				var tile = el( 'div', 'bay-tile bay-tile--' + row[ 0 ] + ( row[ 1 ] ? '' : ' is-zero' ) );
				tile.appendChild( el( 'b', '', row[ 1 ] ) );
				tile.appendChild( el( 'span', '', row[ 2 ] ) );
				tiles.appendChild( tile );
			} );

			drawRows( d );
			drawIssues( d );

			var u = new URL( window.location.href );
			u.searchParams.set( cfg.param, d.token );
			u.hash = 'bay-report';
			bind( 'shareUrl' ).textContent = u.toString();
		}

		function verdictText( d ) {
			var v = d.verdict;
			if ( ! d.readable ) {
				return d.unreadable || 'The site did not let our scanner read it.';
			}
			if ( d.inspection.isWp === false ) {
				return 'This site does not look like WordPress, so the plugin checks do not apply. The HTTPS checks below still do.';
			}
			var bits = [];
			if ( v.vulnerable ) {
				bits.push( v.vulnerable + ( v.vulnerable === 1 ? ' item matches' : ' items match' ) + ' a published vulnerability for the version seen' );
			}
			if ( v.kev ) {
				bits.push( v.kev + ' of those ' + ( v.kev === 1 ? 'is' : 'are' ) + ' on the CISA list of holes attackers are using now' );
			}
			if ( v.oldest ) {
				bits.push( 'the oldest has been public for ' + v.oldest + ' days' );
			}
			if ( v.outdated ) {
				bits.push( v.outdated + ' more ' + ( v.outdated === 1 ? 'is' : 'are' ) + ' behind the latest release' );
			}
			if ( v.issues ) {
				bits.push( v.issues + ' hardening ' + ( v.issues === 1 ? 'gap' : 'gaps' ) + ' found' );
			}
			if ( ! bits.length ) {
				return 'No known vulnerabilities in the versions your site shows, and the hardening checks passed.' + ( v.hidden ? ' ' + v.hidden + ' item(s) hide their version, so could not be matched.' : '' );
			}
			var s = bits.join( '; ' ) + '.';
			return s.charAt( 0 ).toUpperCase() + s.slice( 1 );
		}

		function drawRows( d ) {
			var wrap = bind( 'rows' );
			wrap.textContent = '';
			var rows = d.components || [];
			bind( 'sheetMeta' ).textContent = rows.length + ' seen';
			if ( ! rows.length ) {
				var none = el( 'p', 'bay-detail__note', d.inspection.isWp === false ? 'Not a WordPress site.' : 'No core, theme or plugin versions are visible from outside.' );
				none.style.padding = '24px';
				wrap.appendChild( none );
				return;
			}
			rows.forEach( function ( r, i ) {
				var det = el( 'details' );
				if ( i === 0 && r.count ) {
					det.open = true;
				}
				var sum = el( 'summary', 'bay-row' );
				sum.appendChild( el( 'span', 'bay-row__icon', r.type === 'core' ? 'WP' : r.type === 'theme' ? 'TH' : 'PL' ) );
				var name = el( 'span', 'bay-row__name' );
				name.appendChild( el( 'strong', '', r.name ) );
				name.appendChild( el( 'small', '', r.type + ' · ' + r.slug + ( r.found_by && r.found_by.indexOf( 'page' ) === -1 && r.found_by.indexOf( 'rest' ) !== -1 ? ' · found via REST API' : '' ) ) );
				sum.appendChild( name );
				var ver = el( 'span', 'bay-row__ver', r.version || t.hidden );
				ver.appendChild( el( 'small', '', r.version ? t.versionSrc + ' ' + r.source : 'not matched' ) );
				sum.appendChild( ver );
				var sevs = el( 'span', 'bay-sevs' );
				if ( r.kev ) {
					sevs.appendChild( el( 'span', 'bay-sev bay-sev--kev', 'Exploited' ) );
				}
				if ( r.count ) {
					sevs.appendChild( el( 'span', 'bay-sev bay-sev--' + r.worst, r.count + ' × ' + r.worst ) );
				} else if ( r.outdated ) {
					sevs.appendChild( el( 'span', 'bay-sev bay-sev--update', t.update ) );
				} else {
					sevs.appendChild( el( 'span', 'bay-sev', r.version ? t.upToDate : '—' ) );
				}
				sum.appendChild( sevs );
				sum.appendChild( el( 'span', 'bay-row__arrow', '+' ) );
				det.appendChild( sum );

				var body = el( 'div', 'bay-detail' );
				if ( r.count ) {
					( r.matches || [] ).forEach( function ( m ) {
						var row = el( 'div', 'bay-vuln' );
						row.appendChild( el( 'span', 'bay-vuln__name', m.name ) );
						row.appendChild( el( 'span', 'bay-sev bay-sev--' + m.severity, ( m.score ? m.score.toFixed( 1 ) + ' ' : '' ) + m.severity ) );
						var meta = el( 'div', 'bay-vuln__meta' );
						if ( m.range ) {
							meta.appendChild( el( 'span', '', m.range ) );
						}
						meta.appendChild( el( 'span', '', m.fixed ? t.fixedIn + ' ' + m.fixed : t.noFix ) );
						if ( m.days !== null && m.days !== undefined ) {
							meta.appendChild( el( 'span', '', m.days + ' ' + t.daysPublic ) );
						}
						if ( m.kev ) {
							meta.appendChild( el( 'span', '', '⚠ ' + t.exploited ) );
						}
						( m.cves || [] ).slice( 0, 3 ).forEach( function ( cve ) {
							var a = el( 'a', '', cve );
							a.href = 'https://www.cve.org/CVERecord?id=' + encodeURIComponent( cve );
							a.target = '_blank';
							a.rel = 'noopener';
							meta.appendChild( a );
						} );
						row.appendChild( meta );
						body.appendChild( row );
					} );
					if ( r.count > ( r.matches || [] ).length ) {
						body.appendChild( el( 'p', 'bay-detail__note', '+ ' + ( r.count - r.matches.length ) + ' ' + t.more ) );
					}
				} else if ( r.outdated ) {
					body.appendChild( el( 'p', 'bay-detail__note', 'No known holes in ' + r.version + ', but ' + r.latest + ' is out. Updating now keeps you ahead of the next disclosure.' ) );
				} else if ( ! r.version ) {
					body.appendChild( el( 'p', 'bay-detail__note', 'This item hides its version, which is good practice, but it means we cannot match it. We check it by hand when you book.' ) );
				} else {
					body.appendChild( el( 'p', 'bay-detail__note', 'No published vulnerabilities for ' + r.version + ( r.latest ? ' (latest ' + r.latest + ').' : '.' ) ) );
				}
				det.appendChild( body );
				wrap.appendChild( det );
			} );
		}

		function drawIssues( d ) {
			var wrap = bind( 'issues' );
			wrap.textContent = '';
			var items = d.issues || [];
			bind( 'issuesMeta' ).textContent = items.length ? items.length + ' to fix' : 'All passed';
			if ( ! items.length ) {
				var ok = el( 'div', 'bay-issue' );
				ok.appendChild( el( 'span', 'bay-tick bay-tick--pass', '✓' ) );
				var txt = el( 'div' );
				txt.appendChild( el( 'h4', '', 'No hardening gaps found' ) );
				txt.appendChild( el( 'p', '', 'Usernames, XML-RPC, debug logs, folder listings and HTTPS all look right from outside.' ) );
				ok.appendChild( txt );
				wrap.appendChild( ok );
				return;
			}
			items.forEach( function ( item ) {
				var box = el( 'div', 'bay-issue' );
				box.appendChild( el( 'span', 'bay-tick bay-tick--' + item.status, marks[ item.status ] ) );
				var txt = el( 'div' );
				txt.appendChild( el( 'h4', '', item.label ) );
				txt.appendChild( el( 'p', '', item.evidence + ( item.items && item.items.length ? ' (' + item.items.join( ', ' ) + ')' : '' ) ) );
				if ( item.fix ) {
					txt.appendChild( el( 'p', '', item.fix ) );
				}
				box.appendChild( txt );
				wrap.appendChild( box );
			} );
		}

		root.querySelector( '[data-action="copy"]' ).addEventListener( 'click', function ( e ) {
			var btn = e.currentTarget;
			var text = bind( 'shareUrl' ).textContent;
			var done = function () {
				var old = btn.textContent;
				btn.textContent = t.copied;
				setTimeout( function () {
					btn.textContent = old;
				}, 1600 );
			};
			if ( navigator.clipboard ) {
				navigator.clipboard.writeText( text ).then( done );
			} else {
				var ta = el( 'textarea' );
				ta.value = text;
				document.body.appendChild( ta );
				ta.select();
				document.execCommand( 'copy' );
				ta.remove();
				done();
			}
		} );

		/* ---------- Tray */
		var tray = bind( 'tray' );
		var toolEls = {};
		cfg.tools.forEach( function ( tool ) {
			var card = el( 'button', 'bay-tool' );
			card.type = 'button';
			card.setAttribute( 'aria-pressed', 'false' );
			var top = el( 'div', 'bay-tool__top' );
			top.appendChild( el( 'h3', 'bay-tool__name', tool.name ) );
			top.appendChild( el( 'span', 'bay-tool__switch' ) );
			card.appendChild( top );
			card.appendChild( el( 'p', 'bay-tool__desc', tool.desc ) );
			var foot = el( 'div', 'bay-tool__foot' );
			var price = el( 'span', 'bay-tool__price', money( tool.price ) + ' ' );
			price.appendChild( el( 'small', '', '/ ' + cfg.period ) );
			foot.appendChild( price );
			var why = el( 'span', 'bay-tool__why' );
			why.hidden = true;
			foot.appendChild( why );
			card.appendChild( foot );
			card.addEventListener( 'click', function () {
				state.touched = true;
				toggle( tool.id, ! state.picked[ tool.id ] );
			} );
			tray.appendChild( card );
			toolEls[ tool.id ] = { card: card, why: why, tool: tool };
		} );

		function toggle( id, on ) {
			state.picked[ id ] = !! on;
			toolEls[ id ].card.setAttribute( 'aria-pressed', on ? 'true' : 'false' );
			drawTicket();
		}

		function applyRecommendations( rec ) {
			var key = JSON.stringify( rec );
			if ( state.recKey === key ) {
				return;
			}
			state.recKey = key;
			Object.keys( toolEls ).forEach( function ( id ) {
				var reason = rec[ id ];
				toolEls[ id ].why.hidden = ! reason;
				toolEls[ id ].why.textContent = reason ? t.recommend + ': ' + reason : '';
				if ( ! state.touched ) {
					toggle( id, !! reason );
				}
			} );
			var n = Object.keys( rec ).length;
			bind( 'planIntro' ).textContent = n
				? 'Your report asks for ' + n + ' ' + ( n === 1 ? t.tool : t.tools ) + ', already switched on. Switch one off if you would rather take the risk, or add another. The price follows.'
				: 'Your report did not ask for anything urgent. Add the care you want; the price follows.';
		}

		function drawTicket() {
			var lines = bind( 'ticketLines' );
			lines.textContent = '';
			var total = 0;
			var names = [];
			cfg.tools.forEach( function ( tool ) {
				if ( state.picked[ tool.id ] ) {
					total += Number( tool.price ) || 0;
					names.push( tool.name );
					var li = el( 'li' );
					li.appendChild( el( 'span', '', tool.name ) );
					li.appendChild( el( 'b', '', money( tool.price ) ) );
					lines.appendChild( li );
				}
			} );
			if ( ! names.length ) {
				lines.appendChild( el( 'li', 'is-empty', 'Nothing in the plan yet.' ) );
			}
			bind( 'ticketTotal' ).textContent = money( total );
			bind( 'ticketCount' ).textContent = names.length + ' ' + ( names.length === 1 ? t.tool : t.tools );
			bind( 'attached' ).textContent = names.length ? names.join( ', ' ) + ' · ' + money( total ) + ' ' + t.perPeriod + ' ' + cfg.period : 'No services picked yet';
		}
		drawTicket();

		/* ---------- Booking */
		var form = $( '.bay-form' );
		var formMsg = $( '.bay-form__msg' );
		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			formMsg.textContent = '';
			root.querySelectorAll( '.bay-field.is-error' ).forEach( function ( f ) {
				f.classList.remove( 'is-error' );
			} );
			var btn = form.querySelector( 'button[type=submit]' );
			var val = function ( n ) {
				return form.querySelector( '[name=' + n + ']' ).value;
			};
			btn.disabled = true;
			var fields = {
				nonce: cfg.nonce,
				token: state.token,
				name: val( 'name' ),
				email: val( 'email' ),
				phone: val( 'phone' ),
				website: val( 'website' ),
				notes: val( 'notes' ),
				consent: form.querySelector( '[name=consent]' ).checked ? '1' : '',
				tools: Object.keys( state.picked ).filter( function ( k ) {
					return state.picked[ k ];
				} ).join( ',' ),
				sar_company_site: form.querySelector( '.bay-hp' ).value,
			};
			post( 'sar_service_book', fields )
				.then( function ( json ) {
					btn.disabled = false;
					if ( ! json.success ) {
						formMsg.textContent = ( json.data && json.data.message ) || t.error;
						var bad = json.data && json.data.fields;
						if ( bad ) {
							Object.keys( bad ).forEach( function ( k ) {
								var f = form.querySelector( '[name=' + k + ']' );
								if ( f && f.closest( '.bay-field' ) ) {
									f.closest( '.bay-field' ).classList.add( 'is-error' );
								}
							} );
							var first = form.querySelector( '[name=' + Object.keys( bad )[ 0 ] + ']' );
							if ( first ) {
								first.focus();
							}
						}
						return;
					}
					form.hidden = true;
					var done = $( '.bay-booked' );
					done.hidden = false;
					bind( 'bookedText' ).textContent = 'Thanks, ' + json.data.name + '. A senior engineer will read your report and get back to you within one working day' + ( json.data.tools.length ? ' about:' : '.' );
					var list = bind( 'bookedList' );
					list.textContent = '';
					json.data.tools.forEach( function ( n ) {
						list.appendChild( el( 'li', '', n ) );
					} );
					if ( json.data.tools.length ) {
						list.appendChild( el( 'li', '', money( json.data.total ) + ' ' + t.perPeriod + ' ' + cfg.period ) );
					}
					if ( cfg.bookUrl ) {
						var cal = bind( 'bookedCal' );
						cal.href = cfg.bookUrl;
						cal.hidden = false;
					}
					done.focus();
					markSteps( 6 );
				} )
				.catch( function () {
					btn.disabled = false;
					formMsg.textContent = t.error;
				} );
		} );

		/* ---------- Step rail */
		var steps = root.querySelectorAll( '.bay-rail__step' );
		function markSteps( upto ) {
			steps.forEach( function ( s ) {
				s.classList.toggle( 'is-done', Number( s.getAttribute( 'data-step' ) ) < upto );
			} );
		}
		if ( 'IntersectionObserver' in window ) {
			var map = { 'bay-scan': 1, 'bay-report': 3, 'bay-plan': 4, 'bay-book': 5 };
			var io = new IntersectionObserver( function ( entries ) {
				entries.forEach( function ( en ) {
					if ( en.isIntersecting ) {
						var n = map[ en.target.id ];
						if ( n === 1 && root.getAttribute( 'data-state' ) !== 'idle' ) {
							n = 2;
						}
						steps.forEach( function ( s ) {
							s.classList.toggle( 'is-active', Number( s.getAttribute( 'data-step' ) ) === n );
						} );
					}
				} );
			}, { rootMargin: '-45% 0px -50% 0px' } );
			Object.keys( map ).forEach( function ( id ) {
				var sec = root.querySelector( '#' + id );
				if ( sec ) {
					io.observe( sec );
				}
			} );
		}

		/* ---------- Resume from a shared link */
		if ( cfg.token ) {
			bind( 'badge' ).textContent = 'Live';
			follow( cfg.token, false );
		}
	}
} )();
