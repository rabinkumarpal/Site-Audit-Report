( function () {
	'use strict';
	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest && e.target.closest( '[data-copy]' );
		if ( ! btn ) {
			return;
		}
		var text = btn.getAttribute( 'data-copy' );
		var done = function () {
			var old = btn.textContent;
			btn.textContent = '✓';
			setTimeout( function () {
				btn.textContent = old;
			}, 1200 );
		};
		if ( navigator.clipboard ) {
			navigator.clipboard.writeText( text ).then( done );
		}
	} );
} )();
