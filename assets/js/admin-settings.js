/**
 * Settings → R2C page behaviour: connect flow (with polling), manual API
 * key fallback, widget settings form, disconnect. Loaded only on this one
 * admin screen (R2C_AI_Concierge_Settings_Page::enqueue_assets).
 *
 * No build step, no framework — plain fetch() against admin-ajax.php.
 */
( function () {
	'use strict';

	if ( typeof window.r2cAiConciergeAdmin === 'undefined' ) {
		return;
	}

	var POLL_INTERVAL_MS = 3000;
	var TERMINAL_STATUSES = [ 'connected', 'issued_without_key', 'expired', 'failed' ];

	function post( action, fields ) {
		var body = 'action=' + encodeURIComponent( action ) + '&nonce=' + encodeURIComponent( window.r2cAiConciergeAdmin.nonce );
		for ( var key in fields ) {
			if ( Object.prototype.hasOwnProperty.call( fields, key ) ) {
				body += '&' + encodeURIComponent( key ) + '=' + encodeURIComponent( fields[ key ] );
			}
		}
		return fetch( window.r2cAiConciergeAdmin.ajaxUrl, {
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
			setStatusText( status, window.r2cAiConciergeAdmin.i18n.connecting );

			var email = document.getElementById( 'r2c-email' ).value;
			var consent = document.getElementById( 'r2c-consent' ).checked ? '1' : '0';

			post( 'r2c_ai_concierge_connect', { email: email, consent: consent } ).then( function ( json ) {
				if ( ! json.success ) {
					button.disabled = false;
					setStatusText( status, ( json.data && json.data.message ) || '' );
					return;
				}
				poll();
			} ).catch( function () {
				// fetch() itself failed (offline, CORS, etc.) — distinct from
				// the poll loop's catch below, which deliberately keeps
				// retrying. This is a one-shot action; leaving the earlier
				// "Connecting…" text in place would look like it's still in
				// progress rather than having failed.
				button.disabled = false;
				setStatusText( status, window.r2cAiConciergeAdmin.i18n.networkError );
			} );
		} );

		function poll() {
			post( 'r2c_ai_concierge_poll', {} ).then( function ( json ) {
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

			post( 'r2c_ai_concierge_connect_manual', { api_key: apiKey } ).then( function ( json ) {
				if ( ! json.success ) {
					button.disabled = false;
					setStatusText( status, ( json.data && json.data.message ) || '' );
					return;
				}
				window.location.reload();
			} ).catch( function () {
				button.disabled = false;
				setStatusText( status, window.r2cAiConciergeAdmin.i18n.networkError );
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
				button.textContent = window.r2cAiConciergeAdmin.i18n.confirmDisconnect;
				return;
			}

			button.disabled = true;
			post( 'r2c_ai_concierge_disconnect', {} ).then( function ( json ) {
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
				setStatusText( status, window.r2cAiConciergeAdmin.i18n.networkError );
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
			setStatusText( status, window.r2cAiConciergeAdmin.i18n.saving );

			var excludedPatterns = document.getElementById( 'r2c-excluded-patterns' );

			var fields = {
				position: document.getElementById( 'r2c-position' ).value,
				offset_x: document.getElementById( 'r2c-offset-x' ).value,
				offset_y: document.getElementById( 'r2c-offset-y' ).value,
				primary_color: document.getElementById( 'r2c-primary-color' ).value,
				excluded_page_patterns: excludedPatterns ? excludedPatterns.value : '',
			};

			post( 'r2c_ai_concierge_save_settings', fields ).then( function ( json ) {
				button.disabled = false;
				if ( ! json.success ) {
					setStatusText( status, ( json.data && json.data.message ) || '' );
					return;
				}
				setStatusText( status, window.r2cAiConciergeAdmin.i18n.saved );
			} ).catch( function () {
				button.disabled = false;
				setStatusText( status, window.r2cAiConciergeAdmin.i18n.networkError );
			} );
		} );
	}

	// -----------------------------------------------------------------
	// 非表示ページ: 投稿タイプ / 固定ページ選択からテキストエリアへの追加(FR-10)
	// -----------------------------------------------------------------

	function appendExcludedPattern( pattern ) {
		var textarea = document.getElementById( 'r2c-excluded-patterns' );
		if ( ! textarea || ! pattern ) {
			return;
		}
		var lines = textarea.value.split( '\n' ).map( function ( line ) {
			return line.trim();
		} ).filter( function ( line ) {
			return line !== '';
		} );
		if ( lines.indexOf( pattern ) !== -1 ) {
			return;
		}
		lines.push( pattern );
		textarea.value = lines.join( '\n' );
	}

	function initExcludedPagesHelpers() {
		var postTypeSelect = document.getElementById( 'r2c-excluded-post-type' );
		var postTypeAdd = document.getElementById( 'r2c-excluded-post-type-add' );
		if ( postTypeSelect && postTypeAdd ) {
			postTypeAdd.addEventListener( 'click', function () {
				appendExcludedPattern( postTypeSelect.value );
				postTypeSelect.value = '';
			} );
		}

		var pagePicker = document.getElementById( 'r2c-excluded-page-picker' );
		var pageAdd = document.getElementById( 'r2c-excluded-page-add' );
		if ( pagePicker && pageAdd ) {
			pageAdd.addEventListener( 'click', function () {
				var pagePaths = window.r2cAiConciergeAdmin.pagePaths || {};
				appendExcludedPattern( pagePaths[ pagePicker.value ] );
				pagePicker.value = '';
			} );
		}
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		initConnectForm();
		initManualForm();
		initDisconnectButton();
		initSettingsForm();
		initExcludedPagesHelpers();
	} );
} )();
