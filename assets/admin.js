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
				// Update score bar if present.
				$( '.sfwp-score-fill' ).css( 'width', score + '%' );
				$( '.sfwp-score span:last-child' ).text( score + '%' );
				// Update status badge.
				$( '.sfwp-status' )
					.removeClass( 'sfwp-status--pending sfwp-status--error sfwp-status--done' )
					.addClass( 'sfwp-status--done' )
					.text( '✓ Markup vorhanden' );
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
				$( '#sfwp-preview-content' ).val( resp.data.jsonld || '' );
			} else {
				$( '#sfwp-preview-content' ).val( resp.data || 'Kein Markup vorhanden.' );
			}
			$panel.slideDown();
		} )
		.fail( function () {
			$( '#sfwp-preview-content' ).val( 'Verbindungsfehler' );
			$panel.slideDown();
		} );
	} );

	// --- Settings page: Verbindung testen ---

	$( '#sfwp-test-connection' ).on( 'click', function () {
		var $btn    = $( this );
		var $result = $( '#sfwp-test-result' );

		$btn.prop( 'disabled', true );
		$result.text( '…' ).css( 'color', 'inherit' );

		$.post( sfwp.ajax_url, {
			action: 'schemaforge_wp_test_connection',
			nonce:  sfwp.nonce,
		} )
		.done( function ( resp ) {
			if ( resp.success && resp.data && resp.data.ok ) {
				$result.css( 'color', 'green' )
					.text( '✓ Verbunden · Provider: ' + ( resp.data.provider || 'unbekannt' ) );
			} else {
				$result.css( 'color', 'red' )
					.text( '✗ ' + ( resp.data || 'Verbindung fehlgeschlagen' ) );
			}
		} )
		.fail( function () {
			$result.css( 'color', 'red' ).text( '✗ Verbindungsfehler' );
		} )
		.always( function () {
			$btn.prop( 'disabled', false );
		} );
	} );

	// --- Settings page: Auth-Modus umschalten ---

	function updateAuthFields() {
		var mode = $( 'input[name="schemaforge_wp_auth_mode"]:checked' ).val();
		$( '#sfwp-auth-server' ).toggle( mode === 'server' );
		$( '#sfwp-auth-own-key' ).toggle( mode === 'own-key' );
	}

	$( 'input[name="schemaforge_wp_auth_mode"]' ).on( 'change', updateAuthFields );
	updateAuthFields();
} );
