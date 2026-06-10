/* global sfwp, jQuery */
jQuery( function ( $ ) {

	// --- Meta box: Markup generieren ---

	$( '#sfwp-generate-btn' ).on( 'click', function () {
		var $btn     = $( this );
		var $spinner = $( '#sfwp-generate-spinner' );
		var $result  = $( '#sfwp-generate-result' );

		$btn.prop( 'disabled', true );
		$spinner.css( 'display', 'inline-block' );
		$result.hide().removeClass( 'sfwp-success sfwp-error' );

		$.post( sfwp.ajax_url, {
			action:  'schemaforge_wp_generate',
			nonce:   sfwp.nonce,
			post_id: sfwp.post_id,
		} )
		.done( function ( resp ) {
			if ( resp.success ) {
				var data  = resp.data;
				var score = Math.round( ( data.coverageScore || 0 ) * 100 );
				$result
					.addClass( 'sfwp-success' )
					.text( '✓ Generiert · Coverage: ' + score + '%' )
					.show();
				$( '.sfwp-score-fill' ).css( 'width', score + '%' );
				$( '.sfwp-score span:last-child' ).text( score + '%' );
				$( '.sfwp-status' )
					.removeClass( 'sfwp-status--pending sfwp-status--error sfwp-status--done' )
					.addClass( 'sfwp-status--done' )
					.text( '✓ Markup vorhanden' );
				var usedMode  = data.usedMode || 'deterministic';
				var badgeText = usedMode === 'llm' ? '✦ LLM' : '⚙ Deterministisch';
				$( '.sfwp-mode-badge' )
					.removeClass( 'sfwp-mode-badge--llm sfwp-mode-badge--deterministic' )
					.addClass( 'sfwp-mode-badge--' + usedMode )
					.text( badgeText );
				$( '.sfwp-generated-at' ).text( 'Generiert: ' + ( data.generatedAt || '' ) + ' · manual' );
			} else {
				$result
					.addClass( 'sfwp-error' )
					.text( '✗ ' + ( resp.data || 'Fehler' ) )
					.show();
			}
		} )
		.fail( function () {
			$result.addClass( 'sfwp-error' ).text( '✗ Verbindungsfehler' ).show();
		} )
		.always( function () {
			$btn.prop( 'disabled', false );
			$spinner.hide();
		} );
	} );

	// --- Meta box: JSON-LD Vorschau ---

	function updateEntitySummary( jsonldStr ) {
		try {
			var data = JSON.parse( jsonldStr );
			var graph = data['@graph'] || ( Array.isArray( data ) ? data : [ data ] );
			var types = graph
				.map( function ( n ) { return n['@type']; } )
				.filter( Boolean )
				.map( function ( t ) { return Array.isArray( t ) ? t[0] : t; } );
			var unique = types.filter( function ( v, i, a ) { return a.indexOf( v ) === i; } );
			$( '#sfwp-entity-summary' ).text( types.length + ' ' + ( types.length === 1 ? 'Entity' : 'Entities' ) + ': ' + unique.join( ', ' ) );
		} catch ( e ) {
			$( '#sfwp-entity-summary' ).text( '' );
		}
	}

	$( '#sfwp-preview-toggle' ).on( 'click', function ( e ) {
		e.preventDefault();
		var $panel = $( '#sfwp-preview-panel' );

		if ( $panel.is( ':visible' ) ) {
			$panel.slideUp();
			return;
		}

		$.post( sfwp.ajax_url, {
			action:  'schemaforge_wp_preview',
			nonce:   sfwp.nonce,
			post_id: sfwp.post_id,
		} )
		.done( function ( resp ) {
			if ( resp.success ) {
				var jsonld = resp.data.jsonld || '';
				$( '#sfwp-preview-content' ).val( jsonld );
				updateEntitySummary( jsonld );
			} else {
				$( '#sfwp-preview-content' ).val( resp.data || 'Kein Markup vorhanden.' );
				$( '#sfwp-entity-summary' ).text( '' );
			}
			$panel.slideDown();
		} )
		.fail( function () {
			$( '#sfwp-preview-content' ).val( 'Verbindungsfehler' );
			$( '#sfwp-entity-summary' ).text( '' );
			$panel.slideDown();
		} );
	} );

	// --- Meta box: JSON-LD kopieren ---

	$( '#sfwp-copy-btn' ).on( 'click', function () {
		var $btn     = $( this );
		var content  = $( '#sfwp-preview-content' ).val();
		if ( ! content ) return;
		navigator.clipboard.writeText( content ).then( function () {
			$btn.text( '✓ Kopiert' );
			setTimeout( function () { $btn.text( 'Kopieren' ); }, 1500 );
		} ).catch( function () {
			// Fallback for older browsers.
			$( '#sfwp-preview-content' ).select();
			document.execCommand( 'copy' );
			$btn.text( '✓ Kopiert' );
			setTimeout( function () { $btn.text( 'Kopieren' ); }, 1500 );
		} );
	} );

	// --- Settings page: Verbindung testen ---

	$( '#sfwp-test-connection' ).on( 'click', function () {
		var $btn    = $( this );
		var $result = $( '#sfwp-test-result' );

		$btn.prop( 'disabled', true );
		$result.text( '…' ).removeClass( 'sfwp-success sfwp-error' );

		$.post( sfwp.ajax_url, {
			action: 'schemaforge_wp_test_connection',
			nonce:  sfwp.nonce,
		} )
		.done( function ( resp ) {
			if ( resp.success && resp.data && resp.data.ok ) {
				var data = resp.data;
				var msg  = '✓ Server erreichbar';
				if ( data.provider ) {
					msg += ' · Provider: ' + data.provider;
				}
				if ( data.auth === 'ok' ) {
					msg += ' · Premium-Zugangsdaten gültig';
				} else if ( data.key_format === 'ok' ) {
					msg += ' · ' + ( data.provider || 'Key' ) + '-Key-Format gültig';
				}
				$result.addClass( 'sfwp-success' ).text( msg );
			} else {
				var errMsg = typeof resp.data === 'string' ? resp.data : 'Verbindung fehlgeschlagen';
				$result.addClass( 'sfwp-error' ).text( '✗ ' + errMsg );
			}
		} )
		.fail( function () {
			$result.addClass( 'sfwp-error' ).text( '✗ Verbindungsfehler' );
		} )
		.always( function () {
			$btn.prop( 'disabled', false );
		} );
	} );

	// --- Settings page: Auth-Typ und Modus umschalten ---

	function updateAuthFields() {
		var authType = $( 'input[name="schemaforge_wp_auth_type"]:checked' ).val();
		$( '#sfwp-auth-server' ).toggle( authType === 'server' );
		$( '#sfwp-auth-own-key' ).toggle( authType === 'own-key' );

		// Sync is-checked class for CSS fallback (browsers without :has support).
		$( 'input[name="schemaforge_wp_auth_type"]' ).each( function () {
			$( this ).closest( '.sfwp-mode-card' ).toggleClass( 'is-checked', $( this ).is( ':checked' ) );
		} );
		$( 'input[name="schemaforge_wp_mode"]' ).each( function () {
			$( this ).closest( '.sfwp-mode-card' ).toggleClass( 'is-checked', $( this ).is( ':checked' ) );
		} );
	}

	$( 'input[name="schemaforge_wp_auth_type"], input[name="schemaforge_wp_mode"]' ).on( 'change', updateAuthFields );
	updateAuthFields();

	// --- Settings page: Strategie-Beschreibung umschalten ---

	function updateStrategyDesc() {
		var val = $( '#sfwp-strategy' ).val();
		$( '#sfwp-strategy-desc [data-strategy]' ).hide();
		$( '#sfwp-strategy-desc [data-strategy="' + val + '"]' ).show();
	}

	$( '#sfwp-strategy' ).on( 'change', updateStrategyDesc );
} );
