( function () {
	'use strict';

	var data = window.StealthGPTData || {};

	function api( path, options ) {
		options = options || {};
		options.headers = Object.assign(
			{ 'Content-Type': 'application/json', 'X-WP-Nonce': data.nonce },
			options.headers || {}
		);
		return fetch( data.restUrl + path, options ).then( function ( res ) {
			return res.json().then( function ( body ) {
				if ( ! res.ok ) {
					var msg = ( body && body.message ) || 'Request failed';
					throw new Error( msg );
				}
				return body;
			} );
		} );
	}

	function setStatus( app, html ) {
		var el = app.querySelector( '.stealthgpt-status' );
		if ( el ) {
			el.innerHTML = html;
		}
	}

	function pollStatus( app, postId, editUrl ) {
		var attempts = 0;
		var maxAttempts = 240;

		function tick() {
			attempts++;
			api( '/status/' + postId, { method: 'GET' } )
				.then( function ( body ) {
					if ( body.status === 'completed' ) {
						setStatus(
							app,
							'<span class="sg-ok">' + escapeHtml( data.i18n.completed ) + '</span> ' +
								link( body.editUrl || editUrl, data.i18n.openDraft )
						);
						return;
					}
					if ( body.status === 'failed' || body.status === 'cancelled' ) {
						setStatus(
							app,
							'<span class="sg-err">' + escapeHtml( data.i18n.failed ) +
								( body.error ? ': ' + escapeHtml( body.error ) : '' ) + '</span> ' +
								link( body.editUrl || editUrl, data.i18n.openDraft )
						);
						return;
					}
					if ( attempts < maxAttempts ) {
						setTimeout( tick, attempts < 12 ? 5000 : 15000 );
					}
				} )
				.catch( function () {
					if ( attempts < maxAttempts ) {
						setTimeout( tick, 15000 );
					}
				} );
		}

		setTimeout( tick, 5000 );
	}

	function start( app ) {
		var mode = app.querySelector( 'input[name^="stealthgpt_mode_"]:checked' ).value;
		var payload = { mode: mode };

		if ( app.dataset.context === 'metabox' && data.currentType ) {
			payload.post_type = data.currentType;
		}

		if ( mode === 'generate' ) {
			payload.preset = app.querySelector( '.stealthgpt-preset' ).value;
			payload.prompt = app.querySelector( '.stealthgpt-prompt' ).value;
			payload.enable_fact_check = app.querySelector( '.stealthgpt-factcheck' ).checked;
			payload.enable_image_generation = app.querySelector( '.stealthgpt-images' ).checked;
			if ( payload.preset === 'social' ) {
				payload.platform = app.querySelector( '.stealthgpt-platform' ).value;
			}
		} else {
			var useCurrent = app.querySelector( '.stealthgpt-use-current' );
			payload.quality_mode = app.querySelector( '.stealthgpt-quality' ).value;
			payload.model = app.querySelector( '.stealthgpt-model' ).value;
			payload.text = app.querySelector( '.stealthgpt-text' ).value;
			if ( useCurrent && useCurrent.checked && data.currentId ) {
				payload.source_post_id = data.currentId;
			}
		}

		var btn = app.querySelector( '.stealthgpt-start' );
		btn.disabled = true;
		setStatus( app, escapeHtml( data.i18n.starting ) );

		api( '/start', { method: 'POST', body: JSON.stringify( payload ) } )
			.then( function ( body ) {
				setStatus(
					app,
					escapeHtml( data.i18n.generating ) + ' ' + link( body.editUrl, data.i18n.openDraft )
				);
				pollStatus( app, body.postId, body.editUrl );
			} )
			.catch( function ( err ) {
				setStatus( app, '<span class="sg-err">' + escapeHtml( err.message ) + '</span>' );
			} )
			.finally( function () {
				btn.disabled = false;
			} );
	}

	function link( href, text ) {
		if ( ! href ) {
			return '';
		}
		return '<a href="' + encodeURI( href ) + '" target="_blank" rel="noopener">' + escapeHtml( text ) + '</a>';
	}

	function escapeHtml( str ) {
		return String( str ).replace( /[&<>"']/g, function ( c ) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ];
		} );
	}

	function initApp( app ) {
		var radios = app.querySelectorAll( 'input[name^="stealthgpt_mode_"]' );
		var genFields = app.querySelector( '.stealthgpt-fields-generate' );
		var humFields = app.querySelector( '.stealthgpt-fields-humanize' );
		var presetSel = app.querySelector( '.stealthgpt-preset' );
		var platformRow = app.querySelector( '.stealthgpt-platform-row' );

		function syncMode() {
			var mode = app.querySelector( 'input[name^="stealthgpt_mode_"]:checked' ).value;
			genFields.style.display = mode === 'generate' ? '' : 'none';
			humFields.style.display = mode === 'humanize' ? '' : 'none';
		}
		function syncPreset() {
			platformRow.style.display = presetSel.value === 'social' ? '' : 'none';
		}

		radios.forEach( function ( r ) {
			r.addEventListener( 'change', syncMode );
		} );
		presetSel.addEventListener( 'change', syncPreset );

		var startBtn = app.querySelector( '.stealthgpt-start' );
		startBtn.addEventListener( 'click', function () {
			if ( ! data.hasToken ) {
				setStatus( app, '<span class="sg-err">' + escapeHtml( data.i18n.noToken ) + '</span>' );
				return;
			}
			start( app );
		} );

		syncMode();
		syncPreset();
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		document.querySelectorAll( '.stealthgpt-app' ).forEach( initApp );
	} );
} )();
