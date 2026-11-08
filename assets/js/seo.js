/* Site Audit Report — SEO Inspector. All page data is inserted with textContent. */
( function () {
	'use strict';

	var cfg = window.sarSeo;
	if ( ! cfg || ! window.fetch ) {
		return;
	}

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
	function add( parent ) {
		for ( var i = 1; i < arguments.length; i++ ) {
			if ( arguments[ i ] ) {
				parent.appendChild( arguments[ i ] );
			}
		}
		return parent;
	}
	function safeHref( u ) {
		return /^https?:\/\//i.test( u ) ? u : '';
	}
	function link( href, text ) {
		var a = el( 'a', '', text || href );
		var h = safeHref( href );
		if ( h ) {
			a.href = h;
			a.target = '_blank';
			a.rel = 'noopener nofollow noreferrer';
		}
		return a;
	}
	function fmt( n ) {
		return Number( n || 0 ).toLocaleString();
	}
	function download( name, rows ) {
		var csv = rows.map( function ( r ) {
			return r.map( function ( c ) {
				return '"' + String( c === undefined || c === null ? '' : c ).replace( /"/g, '""' ) + '"';
			} ).join( ',' );
		} ).join( '\n' );
		var a = el( 'a' );
		a.href = URL.createObjectURL( new Blob( [ '﻿' + csv ], { type: 'text/csv' } ) );
		a.download = name;
		document.body.appendChild( a );
		a.click();
		setTimeout( function () {
			URL.revokeObjectURL( a.href );
			a.remove();
		}, 500 );
	}
	function copy( text, btn ) {
		var done = function () {
			var old = btn.textContent;
			btn.textContent = 'Copied';
			setTimeout( function () {
				btn.textContent = old;
			}, 1400 );
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
	}
	function miniBtn( label, onClick ) {
		var b = el( 'button', 'seo-mini', label );
		b.type = 'button';
		b.addEventListener( 'click', function () {
			onClick( b );
		} );
		return b;
	}
	function filters( options, onChange ) {
		var wrap = el( 'div', 'seo-tools' );
		var btns = [];
		options.forEach( function ( o, i ) {
			var b = el( 'button', 'seo-filter', o[ 1 ] );
			b.type = 'button';
			b.setAttribute( 'aria-pressed', i === 0 ? 'true' : 'false' );
			b.addEventListener( 'click', function () {
				btns.forEach( function ( x ) {
					x.setAttribute( 'aria-pressed', x === b ? 'true' : 'false' );
				} );
				onChange( o[ 0 ] );
			} );
			btns.push( b );
			wrap.appendChild( b );
		} );
		return wrap;
	}
	function card( title, tools ) {
		var c = el( 'div', 'seo-card' );
		var head = el( 'div', 'seo-card__head' );
		add( head, el( 'h3', 'bay-h3', title ), tools || null );
		c.appendChild( head );
		return c;
	}
	function stats( items, cls ) {
		var s = el( 'div', 'seo-stats' + ( cls ? ' ' + cls : '' ) );
		items.forEach( function ( it ) {
			var box = el( 'div', 'seo-stat' + ( it[ 2 ] ? ' ' + it[ 2 ] : '' ) + ( Number( it[ 1 ] ) === 0 && ! it[ 2 ] ? ' is-zero' : '' ) );
			add( box, el( 'span', '', it[ 0 ] ), el( 'b', '', it[ 1 ] ) );
			s.appendChild( box );
		} );
		return s;
	}
	function flag( text, tone ) {
		return el( 'span', 'seo-flag' + ( tone ? ' seo-flag--' + tone : '' ), text );
	}

	document.querySelectorAll( '.sar-seo' ).forEach( init );

	function init( root ) {
		var $ = function ( s ) {
			return root.querySelector( s );
		};
		var bind = function ( n ) {
			return root.querySelector( '[data-bind="' + n + '"]' );
		};
		var panel = function ( n ) {
			return root.querySelector( '[data-panel="' + n + '"]' );
		};
		var form = $( '.bay-plate' );
		var input = $( '.bay-plate__input' );
		var go = $( '.bay-plate__go' );
		var msg = $( '.bay-plate__msg' );

		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			run();
		} );
		root.querySelectorAll( '[data-try]' ).forEach( function ( chip ) {
			chip.addEventListener( 'click', function () {
				input.value = chip.getAttribute( 'data-try' ) === '__self' ? cfg.home : chip.getAttribute( 'data-try' );
				run();
			} );
		} );

		/* ---------- Tabs (arrow keys move between them) */
		var tabs = Array.prototype.slice.call( root.querySelectorAll( '.seo-tab' ) );
		function select( tab, focus ) {
			tabs.forEach( function ( t ) {
				var on = t === tab;
				t.setAttribute( 'aria-selected', on ? 'true' : 'false' );
				t.tabIndex = on ? 0 : -1;
				panel( t.getAttribute( 'data-tab' ) ).hidden = ! on;
			} );
			if ( focus ) {
				tab.focus();
			}
		}
		tabs.forEach( function ( t, i ) {
			t.addEventListener( 'click', function () {
				select( t );
			} );
			t.addEventListener( 'keydown', function ( e ) {
				var n = e.key === 'ArrowRight' ? i + 1 : e.key === 'ArrowLeft' ? i - 1 : e.key === 'Home' ? 0 : e.key === 'End' ? tabs.length - 1 : -2;
				if ( n !== -2 ) {
					e.preventDefault();
					select( tabs[ ( n + tabs.length ) % tabs.length ], true );
				}
			} );
		} );

		/* ---------- Run */
		/* ---------- Competitor box */
		var vsWrap = $( '.seo-vs' );
		var vsInput = $( '.seo-vs__input' );
		var vsToggle = $( '.seo-vs-toggle' );
		var reports = { you: null, them: null };
		function vsOn( focus ) {
			vsWrap.hidden = false;
			vsToggle.hidden = true;
			if ( focus ) {
				vsInput.focus();
			}
		}
		vsToggle.addEventListener( 'click', function () {
			vsOn( true );
		} );
		$( '[data-action="vs-off"]' ).addEventListener( 'click', function () {
			vsInput.value = '';
			vsWrap.hidden = true;
			vsToggle.hidden = false;
			vsToggle.focus();
		} );
		vsInput.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Enter' ) {
				e.preventDefault();
				run();
			}
		} );

		/* ---------- Fetch one report (retries once with a fresh nonce) */
		function fetchReport( url, retried ) {
			var data = new FormData();
			data.append( 'action', 'sar_seo_inspect' );
			data.append( 'nonce', cfg.nonce );
			data.append( 'url', url );
			return fetch( cfg.ajaxUrl, { method: 'POST', body: data, credentials: 'same-origin' } )
				.then( function ( r ) {
					return r.json();
				} )
				.then( function ( json ) {
					if ( json.success ) {
						return json.data;
					}
					if ( json.data && json.data.nonce ) {
						cfg.nonce = json.data.nonce;
						if ( json.data.code === 'sar_bad_nonce' && ! retried ) {
							return fetchReport( url, true );
						}
					}
					throw new Error( ( json.data && json.data.message ) || 'Something went wrong. Please try again.' );
				} );
		}

		/* ---------- Run */
		function run() {
			var url = input.value.trim();
			var vs = vsWrap.hidden ? '' : vsInput.value.trim();
			msg.textContent = '';
			if ( ! url ) {
				msg.textContent = 'Please enter a page address.';
				input.focus();
				return;
			}
			go.disabled = true;
			root.setAttribute( 'data-state', 'running' );
			bind( 'badge' ).textContent = 'Reading';
			bind( 'stage' ).textContent = vs ? 'Fetching both pages side by side…' : 'Fetching the page, robots.txt and sitemap…';
			bind( 'target' ).textContent = url.replace( /^https?:\/\//i, '' );
			bind( 'score' ).textContent = '…';

			var jobs = [ fetchReport( url ) ];
			if ( vs ) {
				// The competitor's failure should not hide your own report.
				jobs.push( fetchReport( vs ).catch( function ( err ) {
					return { error: err.message };
				} ) );
			}
			Promise.all( jobs )
				.then( function ( res ) {
					go.disabled = false;
					reports.you = res[ 0 ];
					reports.them = res[ 1 ] && ! res[ 1 ].error ? res[ 1 ] : null;
					render( reports.you );
					if ( vs && reports.them ) {
						renderCompare( reports.you, reports.them );
					} else {
						hideCompare();
						if ( vs ) {
							msg.textContent = 'Competitor: ' + res[ 1 ].error;
						}
					}
					if ( window.history && window.history.replaceState ) {
						var u = new URL( window.location.href );
						u.searchParams.set( cfg.param, reports.you.page.final_url );
						if ( reports.them ) {
							u.searchParams.set( 'vs', reports.them.page.final_url );
						} else {
							u.searchParams.delete( 'vs' );
						}
						window.history.replaceState( null, '', u.toString() );
					}
				} )
				.catch( function ( err ) {
					go.disabled = false;
					fail( err.message || 'Something went wrong. Please try again.' );
				} );
		}

		function fail( text ) {
			msg.textContent = text;
			root.setAttribute( 'data-state', 'idle' );
			bind( 'badge' ).textContent = 'Stopped';
			bind( 'stage' ).textContent = text;
			bind( 'score' ).textContent = '—';
		}

		/* ---------- Render */
		function render( d ) {
			root.setAttribute( 'data-state', 'done' );
			var p = d.page;
			bind( 'badge' ).textContent = 'Done';
			bind( 'target' ).textContent = p.final_url.replace( /^https?:\/\//i, '' ).replace( /\/$/, '' );
			bind( 'stage' ).textContent = 'Inspected ' + p.at + ( p.redirects ? ' · ' + p.redirects + ' redirect(s)' : '' );
			var ring = bind( 'ring' );
			ring.style.setProperty( '--p', d.score === null ? 0 : d.score );
			ring.setAttribute( 'data-tone', d.score >= 80 ? 'pass' : d.score >= 55 ? 'warn' : 'fail' );
			bind( 'score' ).textContent = d.score === null ? '—' : d.score;
			bind( 'nFail' ).textContent = d.counts.fail;
			bind( 'nWarn' ).textContent = d.counts.warn;
			bind( 'nPass' ).textContent = d.counts.pass;
			spec( 'status', String( p.status ) + ( p.https ? ' · HTTPS' : ' · HTTP' ) );
			spec( 'time', fmt( p.time_ms ) + ' ms' );
			spec( 'words', fmt( p.words ) );
			spec( 'heads', d.headings.counts.h1 + ' / ' + d.headings.counts.h2 + ' / ' + d.headings.counts.h3 );
			spec( 'images', d.images.total + ( d.images.missing_alt ? ' · ' + d.images.missing_alt + ' no alt' : '' ) );
			spec( 'links', d.links.internal + ' / ' + d.links.external );
			bind( 'toReport' ).hidden = false;
			if ( bind( 'queueFull' ) && cfg.newUrl ) {
				bind( 'queueFull' ).href = cfg.newUrl + encodeURIComponent( p.final_url );
				bind( 'queueFull' ).hidden = false;
			}

			bind( 'empty' ).hidden = true;
			bind( 'report' ).hidden = false;
			if ( bind( 'cta' ) ) {
				bind( 'cta' ).hidden = false;
			}

			renderTabs( d );
		}

		function renderTabs( d ) {
			count( 'insights', d.counts.fail + d.counts.warn, d.counts.fail > 0 );
			count( 'headers', d.headings.list.length, d.headings.counts.h1 !== 1 || d.headings.skips > 0 );
			count( 'images', d.images.total, d.images.missing_alt > 0 );
			count( 'links', d.links.total, false );
			count( 'schema', d.schema.types.length + d.schema.microdata.length, d.schema.invalid > 0 );
			if ( d.a11y ) {
				count( 'a11y', d.a11y.counts.fail + d.a11y.counts.warn, d.a11y.counts.fail > 0 );
			}

			renderInsights( d );
			renderMeta( d );
			renderHeaders( d );
			renderImages( d );
			renderLinks( d );
			renderSchema( d );
			renderA11y( d );
			select( tabs[ 0 ] );
		}

		function spec( key, value ) {
			var dd = root.querySelector( '[data-spec="' + key + '"]' );
			dd.textContent = value;
			dd.title = value;
		}
		function count( tab, n, bad ) {
			var c = root.querySelector( '[data-count="' + tab + '"]' );
			c.textContent = n ? String( n ) : '';
			c.classList.toggle( 'is-bad', !! bad );
		}

		var marks = { pass: '✓', warn: '!', fail: '✕', info: 'i' };
		var groups = { indexing: 'Indexing', meta: 'Meta tags', content: 'Content and headings', media: 'Images', links: 'Links', schema: 'Structured data', speed: 'Speed basics' };

		function renderInsights( d ) {
			var box = panel( 'insights' );
			box.textContent = '';
			var c = card( 'What to fix first' );
			var list = el( 'div' );
			var order = { fail: 0, warn: 1, info: 2, pass: 3 };
			function draw( filter ) {
				list.textContent = '';
				var shown = 0;
				Object.keys( groups ).forEach( function ( g ) {
					var items = d.insights.filter( function ( x ) {
						return x.group === g && ( filter === 'all' || x.status === filter );
					} ).sort( function ( a, b ) {
						return order[ a.status ] - order[ b.status ];
					} );
					if ( ! items.length ) {
						return;
					}
					var grp = el( 'div', 'seo-group' );
					grp.appendChild( el( 'p', 'seo-group__title', groups[ g ] ) );
					items.forEach( function ( x ) {
						shown++;
						add( grp, add( el( 'div', 'seo-check' ), el( 'span', 'bay-tick bay-tick--' + x.status, marks[ x.status ] ), el( 'strong', '', x.label ), el( 'p', '', x.detail ) ) );
					} );
					list.appendChild( grp );
				} );
				if ( ! shown ) {
					list.appendChild( el( 'p', 'seo-empty-note', 'Nothing in this filter.' ) );
				}
			}
			c.querySelector( '.seo-card__head' ).appendChild( filters( [ [ 'all', 'All ' + d.insights.length ], [ 'fail', 'Errors ' + d.counts.fail ], [ 'warn', 'Warnings ' + d.counts.warn ], [ 'pass', 'Passed ' + d.counts.pass ] ], draw ) );
			draw( 'all' );
			c.appendChild( list );
			box.appendChild( c );
		}

		function lenBar( n, lo, hi, max ) {
			var wrap = el( 'div', 'seo-len' + ( n === 0 ? ' is-fail' : n < lo || n > hi ? ' is-warn' : '' ) );
			var bar = el( 'div', 'seo-len__bar' );
			var fill = el( 'i' );
			fill.style.width = Math.min( 100, ( n / max ) * 100 ) + '%';
			bar.appendChild( fill );
			add( wrap, bar, el( 'span', '', n + ' chars · ideal ' + lo + '–' + hi ) );
			return wrap;
		}

		function kv( rows ) {
			var dl = el( 'dl', 'seo-kv' );
			rows.forEach( function ( r ) {
				var row = el( 'div' );
				var dd = el( 'dd' );
				if ( r[ 1 ] instanceof Node ) {
					dd.appendChild( r[ 1 ] );
				} else if ( r[ 1 ] === '' || r[ 1 ] === null || r[ 1 ] === undefined ) {
					dd.textContent = r[ 3 ] || 'Missing';
					dd.className = r[ 3 ] ? '' : 'is-missing';
				} else {
					dd.textContent = r[ 1 ];
				}
				if ( r[ 2 ] ) {
					dd.appendChild( r[ 2 ] instanceof Node ? r[ 2 ] : el( 'small', '', r[ 2 ] ) );
				}
				add( row, el( 'dt', '', r[ 0 ] ), dd );
				dl.appendChild( row );
			} );
			return dl;
		}

		function renderMeta( d ) {
			var m = d.meta;
			var box = panel( 'meta' );
			box.textContent = '';

			// Search preview.
			var serp = card( 'Google search preview' );
			var s = el( 'div', 'seo-serp' );
			var site = el( 'div', 'seo-serp__site' );
			var icon = el( 'span', 'seo-serp__icon' );
			if ( safeHref( m.favicon ) ) {
				var fi = el( 'img' );
				fi.src = m.favicon;
				fi.alt = '';
				fi.referrerPolicy = 'no-referrer';
				icon.appendChild( fi );
			}
			var names = el( 'div' );
			var crumb = d.page.final_url.replace( /^https?:\/\//, '' ).replace( /\/$/, '' ).split( '/' ).join( ' › ' );
			add( names, el( 'div', 'seo-serp__name', m.og[ 'og:site_name' ] || d.page.host ), el( 'div', 'seo-serp__crumb', 'https://' + crumb ) );
			add( site, icon, names );
			add( s, site, el( 'span', 'seo-serp__title', m.title || d.page.host ), el( 'p', 'seo-serp__desc', m.description || 'No meta description — Google will pick text from the page.' ) );
			serp.appendChild( s );

			// Share preview.
			var share = card( 'Social share preview' );
			var sh = el( 'div', 'seo-share' );
			var img = el( 'div', 'seo-share__img', m.og[ 'og:image' ] ? '' : 'No og:image' );
			if ( safeHref( m.og[ 'og:image' ] || '' ) ) {
				img.style.backgroundImage = 'url("' + encodeURI( m.og[ 'og:image' ] ).replace( /"/g, '%22' ) + '")';
			}
			var sb = el( 'div', 'seo-share__body' );
			add( sb, el( 'small', '', d.page.host ), el( 'strong', '', m.og[ 'og:title' ] || m.title || '—' ), el( 'p', '', m.og[ 'og:description' ] || m.description || '' ) );
			add( sh, img, sb );
			share.appendChild( sh );

			var grid = el( 'div', 'seo-grid2' );
			add( grid, serp, share );
			box.appendChild( grid );

			// Tags.
			var tags = card( 'Meta tags', miniBtn( 'Copy title + description', function ( b ) {
				copy( 'Title: ' + m.title + '\nDescription: ' + m.description, b );
			} ) );
			var titleNode = el( 'div' );
			add( titleNode, document.createTextNode( m.title || '' ), m.title ? lenBar( m.title_len, 30, 65, 80 ) : null );
			var descNode = el( 'div' );
			add( descNode, document.createTextNode( m.description || '' ), m.description ? lenBar( m.desc_len, 70, 160, 200 ) : null );
			var canon = m.canonical ? link( m.canonical ) : '';
			var robotsTxt = el( 'div' );
			add( robotsTxt, link( m.robots_txt.url, m.robots_txt.found ? ( m.robots_txt.allowed ? 'Found · allows this page' : 'Found · BLOCKS this page' ) : 'Not found' ) );
			var hreflang = m.hreflang.length ? el( 'div' ) : '';
			if ( hreflang ) {
				m.hreflang.forEach( function ( h ) {
					var line = el( 'div', 'seo-url' );
					add( line, el( 'b', '', h.lang + '  ' ), link( h.href ) );
					hreflang.appendChild( line );
				} );
			}
			tags.appendChild( kv( [
				[ 'Title', m.title ? titleNode : '' ],
				[ 'Description', m.description ? descNode : '' ],
				[ 'URL', link( m.url ), m.url_len + ' chars' ],
				[ 'Canonical', canon, m.canonical ? ( m.canonical_self ? 'Self-referencing' : 'Points elsewhere' ) : '' ],
				[ 'Meta robots', m.robots, '', 'Not set (index, follow)' ],
				[ 'X-Robots-Tag', m.x_robots, '', 'Not set' ],
				[ 'Language', m.lang ],
				[ 'Charset', m.charset, '', 'Not declared' ],
				[ 'Viewport', m.viewport ],
				[ 'Keywords', m.keywords, '', 'Not set (ignored by Google)' ],
				[ 'Author', m.author, '', 'Not set' ],
				[ 'Generator', m.publisher, '', 'Not set' ],
				[ 'robots.txt', robotsTxt ],
				[ 'Sitemap', m.sitemap.found ? link( m.sitemap.url ) : '' ],
				[ 'Hreflang', hreflang, '', 'None' ],
			] ) );
			box.appendChild( tags );

			// Social tags.
			var social = el( 'div', 'seo-grid2' );
			[ [ 'Open Graph', m.og, [ 'og:title', 'og:description', 'og:image', 'og:url', 'og:type', 'og:site_name' ] ], [ 'X / Twitter', m.twitter, [ 'twitter:card', 'twitter:title', 'twitter:description', 'twitter:image', 'twitter:site' ] ] ].forEach( function ( set ) {
				var c = card( set[ 0 ] );
				var keys = set[ 2 ].concat( Object.keys( set[ 1 ] ).filter( function ( k ) {
					return set[ 2 ].indexOf( k ) === -1;
				} ) );
				c.appendChild( kv( keys.map( function ( k ) {
					var v = set[ 1 ][ k ] || '';
					return [ k, /image|url/.test( k ) && safeHref( v ) ? link( v ) : v ];
				} ) ) );
				social.appendChild( c );
			} );
			box.appendChild( social );
		}

		var flagText = { long: [ 'Too long (>70 chars)', 'warn' ], empty: [ 'Empty heading', 'fail' ], multi_h1: [ 'More than one H1', 'warn' ] };

		function renderHeaders( d ) {
			var h = d.headings;
			var box = panel( 'headers' );
			box.textContent = '';
			box.appendChild( stats( [ 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ].map( function ( k ) {
				return [ k.toUpperCase(), h.counts[ k ], k === 'h1' && h.counts.h1 !== 1 ? 'is-bad' : '' ];
			} ), 'seo-hcounts' ) );

			var c = card( 'Heading outline', miniBtn( 'Copy structure', function ( b ) {
				copy( h.list.map( function ( x ) {
					return new Array( x.level ).join( '  ' ) + 'H' + x.level + ' ' + x.text;
				} ).join( '\n' ), b );
			} ) );
			if ( ! h.list.length ) {
				c.appendChild( el( 'p', 'seo-empty-note', 'No headings found on this page.' ) );
			} else {
				var ol = el( 'ol', 'seo-tree' );
				h.list.forEach( function ( x ) {
					var li = el( 'li' );
					li.setAttribute( 'data-level', x.level );
					li.style.cssText = '--d:' + ( x.level - 1 );
					add( li, el( 'span', 'seo-tree__lvl seo-tree__lvl--' + x.level, 'H' + x.level ), el( 'span', 'seo-tree__txt', x.text || '(empty)' ) );
					if ( x.flags.length ) {
						var fl = el( 'span', 'seo-tree__flags' );
						x.flags.forEach( function ( f ) {
							if ( f === 'skip' ) {
								fl.appendChild( flag( 'Skipped level (H' + x.from + ' → H' + x.level + ')', 'warn' ) );
							} else if ( flagText[ f ] ) {
								fl.appendChild( flag( flagText[ f ][ 0 ], flagText[ f ][ 1 ] ) );
							}
						} );
						li.appendChild( fl );
					}
					ol.appendChild( li );
				} );
				c.appendChild( ol );
			}
			box.appendChild( c );
		}

		function renderImages( d ) {
			var im = d.images;
			var box = panel( 'images' );
			box.textContent = '';
			box.appendChild( stats( [
				[ 'Images', im.total ],
				[ 'Unique', im.unique ],
				[ 'Missing alt', im.missing_alt, im.missing_alt ? 'is-bad' : '' ],
				[ 'Empty alt', im.empty_alt, im.empty_alt ? 'is-warn' : '' ],
				[ 'No size', im.no_dims, im.no_dims ? 'is-warn' : '' ],
				[ 'Lazy', im.lazy ],
			] ) );
			var grid = el( 'div', 'seo-imgs' );
			function draw( f ) {
				grid.textContent = '';
				var list = im.list.filter( function ( x ) {
					return f === 'all' || ( f === 'noalt' && ! x.has_alt ) || ( f === 'empty' && x.has_alt && ! x.alt ) || ( f === 'nodims' && ( ! x.width || ! x.height ) );
				} );
				if ( ! list.length ) {
					grid.appendChild( el( 'p', 'seo-empty-note', 'No images in this filter.' ) );
				}
				list.forEach( function ( x ) {
					var c = el( 'div', 'seo-img' + ( x.has_alt ? '' : ' is-bad' ) );
					var th = el( 'a', 'seo-img__thumb' );
					if ( safeHref( x.src ) ) {
						th.href = x.src;
						th.target = '_blank';
						th.rel = 'noopener noreferrer';
						var i = el( 'img' );
						i.src = x.src;
						i.alt = '';
						i.loading = 'lazy';
						i.referrerPolicy = 'no-referrer';
						th.appendChild( i );
					}
					var body = el( 'div', 'seo-img__body' );
					body.appendChild( el( 'p', 'seo-img__alt' + ( ! x.has_alt ? ' is-missing' : ! x.alt ? ' is-empty' : '' ), ! x.has_alt ? 'No alt attribute' : x.alt || 'alt="" (decorative)' ) );
					var meta = el( 'div', 'seo-img__meta' );
					add( meta, x.ext ? el( 'span', 'seo-tag', x.ext ) : null, el( 'span', 'seo-tag' + ( x.width && x.height ? '' : ' seo-tag--bad' ), x.width && x.height ? x.width + '×' + x.height : 'no size' ), x.lazy ? el( 'span', 'seo-tag seo-tag--ok', 'lazy' ) : null, x.dupe ? el( 'span', 'seo-tag', 'repeat' ) : null );
					add( body, meta, add( el( 'div', 'seo-url' ), link( x.src, x.src.split( '/' ).pop().split( '?' )[ 0 ] || x.src ) ) );
					add( c, th, body );
					grid.appendChild( c );
				} );
			}
			var tools = el( 'div', 'seo-tools' );
			add( tools, filters( [ [ 'all', 'All' ], [ 'noalt', 'Missing alt' ], [ 'empty', 'Empty alt' ], [ 'nodims', 'No size' ] ], draw ), miniBtn( 'Export CSV', function () {
				download( 'images-' + d.page.host + '.csv', [ [ 'src', 'alt', 'has_alt', 'width', 'height', 'lazy' ] ].concat( im.list.map( function ( x ) {
					return [ x.src, x.alt, x.has_alt ? 'yes' : 'no', x.width, x.height, x.lazy ? 'yes' : 'no' ];
				} ) ) );
			} ) );
			var c = card( 'All images', tools );
			draw( 'all' );
			c.appendChild( grid );
			if ( im.total > im.list.length ) {
				c.appendChild( el( 'p', 'seo-more', 'Showing the first ' + im.list.length + ' of ' + im.total + ' images.' ) );
			}
			if ( ! im.total ) {
				grid.textContent = '';
				grid.appendChild( el( 'p', 'seo-empty-note', 'No images found on this page.' ) );
			}
			box.appendChild( c );
		}

		function renderLinks( d ) {
			var l = d.links;
			var box = panel( 'links' );
			box.textContent = '';
			box.appendChild( stats( [
				[ 'Total', l.total ],
				[ 'Unique', l.unique ],
				[ 'Internal', l.internal ],
				[ 'External', l.external ],
				[ 'Nofollow', l.nofollow ],
				[ 'No text', l.no_text, l.no_text ? 'is-warn' : '' ],
			] ) );
			var state = { f: 'all', q: '' };
			var wrap = el( 'div', 'seo-table-wrap' );
			var note = el( 'p', 'seo-more' );
			function draw() {
				wrap.textContent = '';
				var q = state.q.toLowerCase();
				var rows = l.list.filter( function ( x ) {
					var okf = state.f === 'all' || x.type === state.f || ( state.f === 'nofollow' && x.nofollow );
					return okf && ( ! q || ( x.text + ' ' + x.href ).toLowerCase().indexOf( q ) !== -1 );
				} );
				var t = el( 'table', 'seo-table' );
				var head = el( 'tr' );
				[ 'Anchor text', 'URL', 'Type', 'Rel' ].forEach( function ( h ) {
					head.appendChild( el( 'th', '', h ) );
				} );
				add( t, add( el( 'thead' ), head ) );
				var tb = el( 'tbody' );
				rows.forEach( function ( x ) {
					var tr = el( 'tr' );
					add( tr, el( 'td', 'seo-anchor' + ( x.text ? '' : ' is-missing' ), x.text || 'No anchor text' ), add( el( 'td', 'seo-url' ), x.type === 'other' ? el( 'span', '', x.href ) : link( x.href ) ), add( el( 'td' ), el( 'span', 'seo-tag' + ( x.type === 'internal' ? ' seo-tag--ok' : '' ), x.type ) ), add( el( 'td' ), x.nofollow ? el( 'span', 'seo-tag seo-tag--bad', x.rel ) : el( 'span', 'seo-url', x.rel || 'follow' ) ) );
					tb.appendChild( tr );
				} );
				t.appendChild( tb );
				wrap.appendChild( rows.length ? t : el( 'p', 'seo-empty-note', 'No links match.' ) );
				note.textContent = 'Showing ' + rows.length + ' of ' + l.total + ' links' + ( l.total > l.list.length ? ' (first ' + l.list.length + ' read)' : '' ) + '.';
			}
			var search = el( 'input', 'seo-search' );
			search.type = 'search';
			search.placeholder = 'Filter by text or URL';
			search.setAttribute( 'aria-label', 'Filter links' );
			search.addEventListener( 'input', function () {
				state.q = search.value;
				draw();
			} );
			var tools = el( 'div', 'seo-tools' );
			add( tools, filters( [ [ 'all', 'All' ], [ 'internal', 'Internal' ], [ 'external', 'External' ], [ 'nofollow', 'Nofollow' ], [ 'other', 'Mail / tel' ] ], function ( f ) {
				state.f = f;
				draw();
			} ), search, miniBtn( 'Export CSV', function () {
				download( 'links-' + d.page.host + '.csv', [ [ 'anchor', 'url', 'type', 'rel' ] ].concat( l.list.map( function ( x ) {
					return [ x.text, x.href, x.type, x.rel ];
				} ) ) );
			} ) );
			var c = card( 'All links', tools );
			draw();
			add( c, wrap, note );
			box.appendChild( c );
		}

		function highlight( json ) {
			var pre = el( 'pre' );
			var re = /("(?:\\u[a-fA-F0-9]{4}|\\[^u]|[^\\"])*"(\s*:)?|\b(?:true|false|null)\b|-?\d+(?:\.\d*)?(?:[eE][+\-]?\d+)?)/g;
			var last = 0;
			var m;
			while ( ( m = re.exec( json ) ) ) {
				if ( m.index > last ) {
					pre.appendChild( document.createTextNode( json.slice( last, m.index ) ) );
				}
				pre.appendChild( el( 'span', m[ 2 ] ? 'k' : m[ 0 ].charAt( 0 ) === '"' ? 's' : 'n', m[ 0 ] ) );
				last = re.lastIndex;
			}
			pre.appendChild( document.createTextNode( json.slice( last ) ) );
			return pre;
		}

		function renderSchema( d ) {
			var s = d.schema;
			var box = panel( 'schema' );
			box.textContent = '';
			var tools = el( 'div', 'seo-tools' );
			var rr = el( 'a', 'seo-mini', 'Google Rich Results test ↗' );
			rr.href = 'https://search.google.com/test/rich-results?url=' + encodeURIComponent( d.page.final_url );
			rr.target = '_blank';
			rr.rel = 'noopener';
			var sv = el( 'a', 'seo-mini', 'Schema.org validator ↗' );
			sv.href = 'https://validator.schema.org/#url=' + encodeURIComponent( d.page.final_url );
			sv.target = '_blank';
			sv.rel = 'noopener';
			add( tools, rr, sv );

			var top = card( 'Types found', tools );
			var body = el( 'div', 'seo-card__body' );
			var types = el( 'div', 'seo-types' );
			s.types.forEach( function ( t ) {
				types.appendChild( el( 'span', 'seo-type', t ) );
			} );
			s.microdata.forEach( function ( t ) {
				types.appendChild( el( 'span', 'seo-type seo-type--micro', t + ' · microdata' ) );
			} );
			if ( ! s.types.length && ! s.microdata.length ) {
				types.appendChild( el( 'p', 'bay-body', 'No schema.org markup on this page. Organization, WebSite, BreadcrumbList and the type that fits the page (Article, Product, LocalBusiness, FAQPage…) are good places to start.' ) );
			}
			body.appendChild( types );
			if ( s.rdfa ) {
				body.appendChild( el( 'p', 'bay-body', s.rdfa + ' element(s) use RDFa (typeof).' ) );
			}
			top.appendChild( body );
			box.appendChild( top );

			if ( s.blocks.length ) {
				var c = card( 'JSON-LD blocks (' + s.blocks.length + ')' );
				s.blocks.forEach( function ( b, i ) {
					var det = el( 'details', 'seo-block' );
					if ( i === 0 ) {
						det.open = true;
					}
					var sum = el( 'summary' );
					var label = el( 'span', '', 'Block ' + ( i + 1 ) + ' · ' + ( b.types.slice( 0, 4 ).join( ', ' ) || 'no @type' ) );
					var right = el( 'span', 'seo-tools' );
					add( right, b.valid ? null : flag( 'Invalid JSON', 'fail' ), miniBtn( 'Copy', function ( btn ) {
						copy( b.json, btn );
					} ) );
					add( sum, label, right );
					add( det, sum, b.valid ? highlight( b.json ) : add( el( 'pre' ), document.createTextNode( b.json ) ) );
					c.appendChild( det );
				} );
				box.appendChild( c );
			}
		}

		function renderA11y( d ) {
			var box = panel( 'a11y' );
			box.textContent = '';
			var a = d.a11y;
			if ( ! a ) {
				return;
			}
			box.appendChild( stats( [
				[ 'Score', a.score === null ? '—' : a.score, a.score !== null && a.score < 60 ? 'is-bad' : a.score !== null && a.score < 85 ? 'is-warn' : '' ],
				[ 'Errors', a.counts.fail, a.counts.fail ? 'is-bad' : '' ],
				[ 'Warnings', a.counts.warn, a.counts.warn ? 'is-warn' : '' ],
				[ 'Passed', a.counts.pass ],
			], 'seo-stats--4' ) );
			var c = card( 'Accessibility quick check' );
			var list = el( 'div' );
			function draw( f ) {
				list.textContent = '';
				var rows = a.checks.filter( function ( x ) {
					return f === 'all' || ( f === 'issues' && ( x.status === 'fail' || x.status === 'warn' ) ) || x.status === f;
				} );
				if ( ! rows.length ) {
					list.appendChild( el( 'p', 'seo-empty-note', 'Nothing in this filter.' ) );
				}
				rows.forEach( function ( x ) {
					var item = el( 'div', 'seo-a11y' );
					var head = el( 'div', 'seo-check' );
					var label = el( 'strong' );
					add( label, document.createTextNode( x.label + ' ' ), el( 'span', 'seo-wcag', 'WCAG ' + x.wcag ) );
					add( head, el( 'span', 'bay-tick bay-tick--' + x.status, marks[ x.status ] ), label, el( 'p', '', x.detail ) );
					item.appendChild( head );
					if ( x.fix ) {
						item.appendChild( el( 'p', 'seo-a11y__fix', x.fix ) );
					}
					if ( x.samples && x.samples.length ) {
						var det = el( 'details', 'seo-a11y__samples' );
						det.appendChild( el( 'summary', '', 'Show ' + x.samples.length + ( x.count > x.samples.length ? ' of ' + x.count : '' ) + ' element' + ( x.samples.length === 1 ? '' : 's' ) ) );
						var ul = el( 'ul' );
						x.samples.forEach( function ( s ) {
							ul.appendChild( add( el( 'li' ), el( 'code', '', s ) ) );
						} );
						det.appendChild( ul );
						item.appendChild( det );
					}
					list.appendChild( item );
				} );
			}
			var nIssues = a.counts.fail + a.counts.warn;
			var opts = [ [ 'issues', 'Issues ' + nIssues ], [ 'all', 'All ' + a.checks.length ], [ 'pass', 'Passed ' + a.counts.pass ] ];
			if ( ! nIssues ) {
				opts = [ opts[ 1 ], opts[ 0 ], opts[ 2 ] ]; // Nothing to fix: open on the full list.
			}
			c.querySelector( '.seo-card__head' ).appendChild( filters( opts, draw ) );
			draw( nIssues ? 'issues' : 'all' );
			c.appendChild( list );
			c.appendChild( el( 'p', 'seo-more', a.limits ) );
			box.appendChild( c );
		}

		/* ---------- You vs. a competitor */
		var cmp = bind( 'compare' );
		function hideCompare() {
			cmp.hidden = true;
			cmp.textContent = '';
		}
		var genericTypes = [ 'WebPage', 'WebSite', 'ImageObject', 'ReadAction', 'SearchAction', 'EntryPoint', 'ListItem', 'PropertyValueSpecification', 'Person', 'Thing', 'CreativeWork' ];
		function schemaTypes( r ) {
			return r.schema.types.concat( r.schema.microdata ).filter( function ( t, i, all ) {
				return all.indexOf( t ) === i;
			} );
		}
		function inRange( n, lo, hi ) {
			return n === 0 ? 1000 : n < lo ? lo - n : n > hi ? n - hi : 0; // Distance from the ideal range.
		}
		function ogComplete( r ) {
			return !! ( r.meta.og[ 'og:title' ] && r.meta.og[ 'og:description' ] && r.meta.og[ 'og:image' ] );
		}
		function indexable( r ) {
			return ! /noindex/i.test( r.meta.robots + ' ' + r.meta.x_robots ) && r.meta.robots_txt.allowed;
		}
		// Each metric: label, value getter, how to show it, which way is better, and what to do if they win.
		var metrics = [
			[ 'SEO score', function ( r ) { return r.score || 0; }, String, 'high', 0, 'Their on-page basics score higher. Clear the errors on the Insights tab first.' ],
			[ 'Accessibility score', function ( r ) { return r.a11y ? r.a11y.score || 0 : 0; }, String, 'high', 0, 'Their page is easier to use with a screen reader or keyboard. Work through the Accessibility tab.' ],
			[ 'Errors to fix', function ( r ) { return r.counts.fail; }, String, 'low', 0, 'You have more outright errors. Each is listed with its fix on the Insights tab.' ],
			[ 'Title length', function ( r ) { return inRange( r.meta.title_len, 30, 65 ); }, function ( v, r ) { return r.meta.title_len ? r.meta.title_len + ' chars' : 'Missing'; }, 'low', 0, 'Their title fits Google\'s display better. Aim for 30–65 characters with the main keyword first.' ],
			[ 'Meta description', function ( r ) { return inRange( r.meta.desc_len, 70, 160 ); }, function ( v, r ) { return r.meta.desc_len ? r.meta.desc_len + ' chars' : 'Missing'; }, 'low', 0, 'Write a 70–160 character description that sells the click.' ],
			[ 'Single H1', function ( r ) { return r.headings.counts.h1 === 1 ? 1 : 0; }, function ( v, r ) { return r.headings.counts.h1 + ' H1'; }, 'high', 0, 'Use exactly one H1 that says what the page is about.' ],
			[ 'Sections (H2–H3)', function ( r ) { return r.headings.counts.h2 + r.headings.counts.h3; }, String, 'high', 0.15, 'They break their content into more sections. Add H2/H3 sections that answer what customers ask.' ],
			[ 'Words on page', function ( r ) { return r.page.words; }, function ( v ) { return fmt( v ); }, 'high', 0.1, 'They say more. Expand the page with useful detail: process, pricing, FAQs, proof.' ],
			[ 'Images without alt', function ( r ) { return r.images.missing_alt; }, String, 'low', 0, 'Add alt text to your images. It helps image search and screen-reader users.' ],
			[ 'Internal links', function ( r ) { return r.links.internal; }, String, 'high', 0.15, 'They link to more of their own pages. Link to related services, case studies and posts.' ],
			[ 'Structured data types', function ( r ) { return schemaTypes( r ).length; }, String, 'high', 0, '' ],
			[ 'Social sharing tags', function ( r ) { return ogComplete( r ) ? 1 : 0; }, function ( v ) { return v ? 'Complete' : 'Incomplete'; }, 'high', 0, 'Add og:title, og:description and og:image so shared links look good.' ],
			[ 'Server response', function ( r ) { return r.page.time_ms; }, function ( v ) { return fmt( v ) + ' ms'; }, 'low', 0.15, 'Their HTML arrives faster. Check hosting, page caching and a CDN.' ],
			[ 'HTML size', function ( r ) { return r.page.size_kb; }, function ( v ) { return v + ' KB'; }, 'low', 0.15, 'Their page is lighter. Remove unused plugins, builders\' extra markup and inline scripts.' ],
			[ 'HTTPS', function ( r ) { return r.page.https ? 1 : 0; }, function ( v ) { return v ? 'Yes' : 'No'; }, 'high', 0, 'Move the site to HTTPS.' ],
			[ 'Canonical link', function ( r ) { return r.meta.canonical ? 1 : 0; }, function ( v ) { return v ? 'Yes' : 'No'; }, 'high', 0, 'Add a canonical link so duplicates consolidate to this URL.' ],
			[ 'XML sitemap', function ( r ) { return r.meta.sitemap.found ? 1 : 0; }, function ( v ) { return v ? 'Found' : 'Missing'; }, 'high', 0, 'Publish an XML sitemap and list it in robots.txt.' ],
			[ 'Indexable', function ( r ) { return indexable( r ) ? 1 : 0; }, function ( v ) { return v ? 'Yes' : 'No'; }, 'high', 0, 'Your page is blocked from search engines. Remove noindex or the robots.txt rule.' ],
		];
		function winner( a, b, dir, tol ) {
			if ( a === b ) {
				return 0;
			}
			var base = Math.max( Math.abs( a ), Math.abs( b ), 1 );
			if ( tol && Math.abs( a - b ) / base <= tol ) {
				return 0;
			}
			return ( dir === 'high' ? a > b : a < b ) ? 1 : -1;
		}

		function renderCompare( you, them ) {
			cmp.textContent = '';
			cmp.hidden = false;
			var rows = [];
			var tally = { you: 0, them: 0, tie: 0 };
			var behind = [];
			var ahead = [];
			metrics.forEach( function ( m ) {
				var a = m[ 1 ]( you );
				var b = m[ 1 ]( them );
				var w = winner( a, b, m[ 3 ], m[ 4 ] );
				tally[ w > 0 ? 'you' : w < 0 ? 'them' : 'tie' ]++;
				var row = { label: m[ 0 ], a: m[ 2 ]( a, you ), b: m[ 2 ]( b, them ), w: w };
				rows.push( row );
				if ( w < 0 ) {
					var tip = m[ 5 ];
					if ( m[ 0 ] === 'Structured data types' ) {
						var gap = schemaTypes( them ).filter( function ( t ) {
							return schemaTypes( you ).indexOf( t ) === -1 && genericTypes.indexOf( t ) === -1;
						} );
						tip = gap.length ? 'They use structured data you don\'t: ' + gap.slice( 0, 6 ).join( ', ' ) + '. Adding it can earn rich results.' : 'They mark up more of the page with schema.org. Add the types that fit your page.';
					}
					behind.push( [ m[ 0 ], row.a, row.b, tip ] );
				} else if ( w > 0 ) {
					ahead.push( [ m[ 0 ], row.a, row.b ] );
				}
			} );
			var youHost = you.page.host.replace( /^www\./, '' );
			var themHost = them.page.host.replace( /^www\./, '' );

			// Scoreboard.
			var board = el( 'div', 'seo-vsboard' );
			function side( r, host, who, wins ) {
				var s = el( 'div', 'seo-vsboard__side seo-vsboard__side--' + who );
				add( s, el( 'span', 'seo-vsboard__who', who === 'you' ? 'You' : 'Competitor' ), el( 'strong', 'seo-vsboard__host', host ), add( el( 'div', 'seo-vsboard__score' ), el( 'b', '', r.score === null ? '—' : r.score ), el( 'span', '', 'SEO score' ) ), el( 'span', 'seo-vsboard__wins', wins + ' ' + ( wins === 1 ? 'win' : 'wins' ) ) );
				return s;
			}
			var verdict = tally.you > tally.them ? 'You lead on ' + tally.you + ' of ' + metrics.length + ' points.' : tally.you < tally.them ? 'They lead on ' + tally.them + ' of ' + metrics.length + ' points.' : 'Neck and neck: ' + tally.you + ' wins each.';
			var mid = el( 'div', 'seo-vsboard__mid' );
			add( mid, el( 'span', 'seo-vsboard__vs', 'VS' ), el( 'p', 'seo-vsboard__verdict', verdict ), el( 'p', 'seo-vsboard__tie', tally.tie + ' level' ) );
			add( board, side( you, youHost, 'you', tally.you ), mid, side( them, themHost, 'them', tally.them ) );
			cmp.appendChild( board );

			// Gap lists.
			var lists = el( 'div', 'seo-gaps' );
			var lose = el( 'div', 'seo-card seo-gap seo-gap--behind' );
			var loseHead = el( 'div', 'seo-card__head' );
			add( loseHead, el( 'h3', 'bay-h3', 'Where they beat you' ), el( 'span', 'seo-tab__n is-bad', behind.length ) );
			lose.appendChild( loseHead );
			if ( ! behind.length ) {
				lose.appendChild( el( 'p', 'seo-empty-note', 'Nowhere. You match or beat them on every point we measure.' ) );
			}
			behind.forEach( function ( g ) {
				var it = el( 'div', 'seo-gap__item' );
				add( it, el( 'strong', '', g[ 0 ] ), el( 'span', 'seo-gap__vals', 'You: ' + g[ 1 ] + ' · Them: ' + g[ 2 ] ), g[ 3 ] ? el( 'p', '', g[ 3 ] ) : null );
				lose.appendChild( it );
			} );
			var win = el( 'div', 'seo-card seo-gap seo-gap--ahead' );
			var winHead = el( 'div', 'seo-card__head' );
			add( winHead, el( 'h3', 'bay-h3', 'Where you lead' ), el( 'span', 'seo-tab__n', ahead.length ) );
			win.appendChild( winHead );
			if ( ! ahead.length ) {
				win.appendChild( el( 'p', 'seo-empty-note', 'Not yet. The list on the left is your plan.' ) );
			}
			ahead.forEach( function ( g ) {
				var it = el( 'div', 'seo-gap__item' );
				add( it, el( 'strong', '', g[ 0 ] ), el( 'span', 'seo-gap__vals', 'You: ' + g[ 1 ] + ' · Them: ' + g[ 2 ] ) );
				win.appendChild( it );
			} );
			add( lists, lose, win );
			cmp.appendChild( lists );

			// Full table.
			var det = el( 'details', 'seo-card seo-vstable' );
			det.appendChild( el( 'summary', 'seo-card__head', 'All ' + metrics.length + ' points side by side' ) );
			var wrap = el( 'div', 'seo-table-wrap' );
			var t = el( 'table', 'seo-table' );
			var hr = el( 'tr' );
			[ 'Point', 'You · ' + youHost, 'Them · ' + themHost ].forEach( function ( h ) {
				hr.appendChild( el( 'th', '', h ) );
			} );
			add( t, add( el( 'thead' ), hr ) );
			var tb = el( 'tbody' );
			rows.forEach( function ( r ) {
				var tr = el( 'tr' );
				add( tr, el( 'td', '', r.label ), el( 'td', r.w > 0 ? 'seo-win' : r.w < 0 ? 'seo-lose' : '', r.a ), el( 'td', r.w < 0 ? 'seo-win' : r.w > 0 ? 'seo-lose' : '', r.b ) );
				tb.appendChild( tr );
			} );
			t.appendChild( tb );
			wrap.appendChild( t );
			det.appendChild( wrap );
			cmp.appendChild( det );

			// Which site the tabs below describe.
			var sw = el( 'div', 'seo-switch' );
			sw.appendChild( el( 'span', 'bay-mono', 'Details below for:' ) );
			[ [ 'you', 'You · ' + youHost ], [ 'them', 'Competitor · ' + themHost ] ].forEach( function ( o, i ) {
				var b = el( 'button', 'seo-filter', o[ 1 ] );
				b.type = 'button';
				b.setAttribute( 'aria-pressed', i === 0 ? 'true' : 'false' );
				b.addEventListener( 'click', function () {
					sw.querySelectorAll( 'button' ).forEach( function ( x ) {
						x.setAttribute( 'aria-pressed', x === b ? 'true' : 'false' );
					} );
					renderTabs( reports[ o[ 0 ] ] );
				} );
				sw.appendChild( b );
			} );
			cmp.appendChild( sw );
			bind( 'reportSide' ).textContent = 'You against ' + themHost + ': the scoreboard, where they beat you (with what to do), where you lead, then the full detail for either site.';
		}

		/* ---------- Pre-filled from a shared link */
		if ( cfg.prefillVs ) {
			vsInput.value = cfg.prefillVs;
			vsOn( false );
		}
		if ( cfg.prefill ) {
			input.value = cfg.prefill;
			run();
		}
	}
} )();
