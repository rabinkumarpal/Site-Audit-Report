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
			run( false );
		} );
		root.querySelectorAll( '[data-try]' ).forEach( function ( chip ) {
			chip.addEventListener( 'click', function () {
				input.value = chip.getAttribute( 'data-try' ) === '__self' ? cfg.home : chip.getAttribute( 'data-try' );
				run( false );
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
		function run( retried ) {
			var url = input.value.trim();
			msg.textContent = '';
			if ( ! url ) {
				msg.textContent = 'Please enter a page address.';
				input.focus();
				return;
			}
			go.disabled = true;
			root.setAttribute( 'data-state', 'running' );
			bind( 'badge' ).textContent = 'Reading';
			bind( 'stage' ).textContent = 'Fetching the page, robots.txt and sitemap…';
			bind( 'target' ).textContent = url.replace( /^https?:\/\//i, '' );
			bind( 'score' ).textContent = '…';

			var data = new FormData();
			data.append( 'action', 'sar_seo_inspect' );
			data.append( 'nonce', cfg.nonce );
			data.append( 'url', url );
			fetch( cfg.ajaxUrl, { method: 'POST', body: data, credentials: 'same-origin' } )
				.then( function ( r ) {
					return r.json();
				} )
				.then( function ( json ) {
					go.disabled = false;
					if ( json.success ) {
						render( json.data );
						if ( window.history && window.history.replaceState ) {
							var u = new URL( window.location.href );
							u.searchParams.set( cfg.param, json.data.page.final_url );
							window.history.replaceState( null, '', u.toString() );
						}
						return;
					}
					if ( json.data && json.data.nonce ) {
						cfg.nonce = json.data.nonce;
						if ( json.data.code === 'sar_bad_nonce' && ! retried ) {
							return run( true );
						}
					}
					fail( ( json.data && json.data.message ) || 'Something went wrong. Please try again.' );
				} )
				.catch( function () {
					go.disabled = false;
					fail( 'Something went wrong. Please try again.' );
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

			bind( 'empty' ).hidden = true;
			bind( 'report' ).hidden = false;
			if ( bind( 'cta' ) ) {
				bind( 'cta' ).hidden = false;
			}

			count( 'insights', d.counts.fail + d.counts.warn, d.counts.fail > 0 );
			count( 'headers', d.headings.list.length, d.headings.counts.h1 !== 1 || d.headings.skips > 0 );
			count( 'images', d.images.total, d.images.missing_alt > 0 );
			count( 'links', d.links.total, false );
			count( 'schema', d.schema.types.length + d.schema.microdata.length, d.schema.invalid > 0 );

			renderInsights( d );
			renderMeta( d );
			renderHeaders( d );
			renderImages( d );
			renderLinks( d );
			renderSchema( d );
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

		/* ---------- Pre-filled from a shared link */
		if ( cfg.prefill ) {
			input.value = cfg.prefill;
			run( false );
		}
	}
} )();
