( function () {
	'use strict';
	var button = document.getElementById( 'sar-logo-pick' );
	var input = document.getElementById( 'sar-logo_url' );
	if ( ! button || ! input || ! window.wp || ! window.wp.media ) {
		return;
	}
	var frame;
	button.addEventListener( 'click', function () {
		if ( ! frame ) {
			frame = window.wp.media( { library: { type: 'image' }, multiple: false } );
			frame.on( 'select', function () {
				input.value = frame.state().get( 'selection' ).first().get( 'url' );
			} );
		}
		frame.open();
	} );
} )();
