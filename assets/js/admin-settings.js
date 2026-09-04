/**
 * Settings → R2C page behaviour: connect flow (with polling), manual API
 * key fallback, widget settings form, disconnect. Loaded only on this one
 * admin screen (R2C_Settings_Page::enqueue_assets).
 *
 * No build step, no framework — plain fetch() against admin-ajax.php.
 */
( function () {
	'use strict';

	if ( typeof window.r2cAdmin === 'undefined' ) {
		return;
	}

	var POLL_INTERVAL_MS = 3000;
	var TERMINAL_STATUSES = [ 'connected', 'issued_without_key', 'expired', 'failed' ];

	function post( action, fields ) {
		var body = 'action=' + encodeURIComponent( action ) + '&nonce=' + encodeURIComponent( window.r2cAdmin.nonce );
		for ( var key in fields ) {
			if ( Object.prototype.hasOwnProperty.call( fields, key ) ) {
				body += '&' + encodeURIComponent( key ) + '=' + encodeURIComponent( fields[ key ] );
			}
		}
		return fetch( window.r2cAdmin.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body,
		} ).then( function ( res ) {
			return res.json();
		} );
	}

	function setStatusText( el, text ) {
		if ( el ) {
			el.textContent = text || '';
		}
	}

	// -----------------------------------------------------------------
	// 接続フォーム + ポーリング
	// -----------------------------------------------------------------

	function initConnectForm() {
		var form = document.getElementById( 'r2c-connect-form' );
		if ( ! form ) {
			return;
		}
		var button = document.getElementById( 'r2c-connect-button' );
		var status = document.getElementById( 'r2c-connect-status' );

		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			button.disabled = true;
			setStatusText( status, window.r2cAdmin.i18n.connecting );

			var email = document.getElementById( 'r2c-email' ).value;
			var consent = document.getElementById( 'r2c-consent' ).checked ? '1' : '0';

			post( 'r2c_connect', { email: email, consent: consent } ).then( function ( json ) {
				if ( ! json.success ) {
					button.disabled = false;
					setStatusText( status, ( json.data && json.data.message ) || '' );
					return;
				}
				poll();
			} ).catch( function () {
				button.disabled = false;
				setStatusText( status, window.r2cAdmin.i18n.connecting );
			} );
		} );

		function poll() {
			post( 'r2c_poll', {} ).then( function ( json ) {
				if ( ! json.success ) {
					button.disabled = false;
					setStatusText( status, ( json.data && json.data.message ) || '' );
					return;
				}
				var data = json.data || {};
				setStatusText( status, data.message || '' );

				if ( 'connected' === data.status ) {
					window.location.reload();
					return;
				}
				if ( TERMINAL_STATUSES.indexOf( data.status ) !== -1 ) {
					button.disabled = false;
					return;
				}
				// pending — keep going.
				window.setTimeout( poll, POLL_INTERVAL_MS );
			} ).catch( function () {
				// ネットワーク断でもポーリングは止めない(要件書 §12.3 の
				// 「到達不能はまだ終端にしない」と同じ方針)。
				window.setTimeout( poll, POLL_INTERVAL_MS );
			} );
		}
	}

	// -----------------------------------------------------------------
	// APIキー手動入力(FR-05)
	// -----------------------------------------------------------------

	function initManualForm() {
		var form = document.getElementById( 'r2c-manual-form' );
		if ( ! form ) {
			return;
		}
		var button = document.getElementById( 'r2c-manual-button' );
		var status = document.getElementById( 'r2c-manual-status' );

		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			button.disabled = true;
			var apiKey = document.getElementById( 'r2c-manual-key' ).value;

			post( 'r2c_connect_manual', { api_key: apiKey } ).then( function ( json ) {
				if ( ! json.success ) {
					button.disabled = false;
					setStatusText( status, ( json.data && json.data.message ) || '' );
					return;
				}
				window.location.reload();
			} ).catch( function () {
				button.disabled = false;
			} );
		} );
	}

	// -----------------------------------------------------------------
	// 切断(ネイティブ confirm() は使わず、インライン二段確認にする)
	// -----------------------------------------------------------------

	function initDisconnectButton() {
		var button = document.getElementById( 'r2c-disconnect-button' );
		if ( ! button ) {
			return;
		}
		var status = document.getElementById( 'r2c-disconnect-status' );
		var confirming = false;

		button.addEventListener( 'click', function () {
			if ( ! confirming ) {
				confirming = true;
				button.textContent = window.r2cAdmin.i18n.confirmDisconnect;
				return;
			}

			button.disabled = true;
			post( 'r2c_disconnect', {} ).then( function ( json ) {
				var data = ( json && json.data ) || {};
				if ( data.warning ) {
					setStatusText( status, data.warning );
					window.setTimeout( function () {
						window.location.reload();
					}, 4000 );
					return;
				}
				window.location.reload();
			} ).catch( function () {
				button.disabled = false;
				confirming = false;
			} );
		} );
	}

	// -----------------------------------------------------------------
	// ウィジェット表示設定
	// -----------------------------------------------------------------

	function initSettingsForm() {
		var form = document.getElementById( 'r2c-settings-form' );
		if ( ! form ) {
			return;
		}
		var button = document.getElementById( 'r2c-settings-save' );
		var status = document.getElementById( 'r2c-settings-status' );

		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			button.disabled = true;
			setStatusText( status, window.r2cAdmin.i18n.saving );

			var fields = {
				position: document.getElementById( 'r2c-position' ).value,
				offset_x: document.getElementById( 'r2c-offset-x' ).value,
				offset_y: document.getElementById( 'r2c-offset-y' ).value,
				primary_color: document.getElementById( 'r2c-primary-color' ).value,
			};

			post( 'r2c_save_settings', fields ).then( function ( json ) {
				button.disabled = false;
				if ( ! json.success ) {
					setStatusText( status, ( json.data && json.data.message ) || '' );
					return;
				}
				setStatusText( status, window.r2cAdmin.i18n.saved );
			} ).catch( function () {
				button.disabled = false;
			} );
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		initConnectForm();
		initManualForm();
		initDisconnectButton();
		initSettingsForm();
	} );
} )();
