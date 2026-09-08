/**
 * Dismiss handler for the single unconnected-state admin notice
 * (R2C_AI_Concierge_Notice). Deliberately not using WordPress core's `is-dismissible`
 * auto-injected button — see class-r2c-ai-concierge-notice.php for why.
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		var notice = document.querySelector( '.r2c-connect-notice' );
		if ( ! notice ) {
			return;
		}
		var button = notice.querySelector( '.r2c-notice-dismiss' );
		if ( ! button ) {
			return;
		}

		button.addEventListener( 'click', function () {
			var nonce = button.getAttribute( 'data-nonce' ) || '';
			// Fire-and-forget: dismissal is low-stakes UI state. If this
			// request fails, the notice simply reappears next page load —
			// not worth blocking the click on a network round trip.
			fetch( window.r2cAiConciergeNotice.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: 'action=r2c_ai_concierge_dismiss_notice&nonce=' + encodeURIComponent( nonce ),
			} ).catch( function () {} );
			notice.remove();
		} );
	} );
} )();
