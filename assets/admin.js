( function () {
	'use strict';
	window.addEventListener( 'load', function () {
		var notice = document.querySelector( '.dpdr-result' );
		if ( notice ) { window.setTimeout( function () { notice.focus(); }, 100 ); }
	} );
	document.querySelectorAll( '.dpdr-review-form' ).forEach( function ( form ) {
		var date = form.querySelector( 'input[name="due"]' );
		var unscheduled = form.querySelector( 'input[name="unschedule"]' );
		if ( ! date || ! unscheduled ) { return; }
		date.addEventListener( 'input', function () {
			if ( date.value ) { unscheduled.checked = false; }
		} );
		unscheduled.addEventListener( 'change', function () {
			if ( unscheduled.checked ) { date.value = ''; }
		} );
	} );
} )();
