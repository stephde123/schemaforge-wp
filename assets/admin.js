/* global sfwp, jQuery */
jQuery( function ( $ ) {

	// ── Metabox: Markup generieren ────────────────────────────────────────

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
				var data     = resp.data;
				var score    = Math.round( ( data.coverageScore || 0 ) * 100 );
				$result.addClass( 'sfwp-success' ).text( '✓ Generiert · Coverage: ' + score + '%' ).show();
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
				$result.addClass( 'sfwp-error' ).text( '✗ ' + ( resp.data || 'Fehler' ) ).show();
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

	// ── Metabox: JSON-LD Vorschau ─────────────────────────────────────────

	function updateEntitySummary( jsonldStr ) {
		try {
			var data  = JSON.parse( jsonldStr );
			var graph = data['@graph'] || ( Array.isArray( data ) ? data : [ data ] );
			var types = graph
				.map( function ( n ) { return n['@type']; } )
				.filter( Boolean )
				.map( function ( t ) { return Array.isArray( t ) ? t[0] : t; } );
			var unique = types.filter( function ( v, i, a ) { return a.indexOf( v ) === i; } );
			$( '#sfwp-entity-summary' ).text(
				types.length + ' ' + ( types.length === 1 ? 'Entity' : 'Entities' ) + ': ' + unique.join( ', ' )
			);
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

	// ── Metabox: JSON-LD kopieren ─────────────────────────────────────────

	$( '#sfwp-copy-btn' ).on( 'click', function () {
		var $btn    = $( this );
		var content = $( '#sfwp-preview-content' ).val();
		if ( ! content ) return;
		navigator.clipboard.writeText( content ).then( function () {
			$btn.text( '✓ Kopiert' );
			setTimeout( function () { $btn.text( 'Kopieren' ); }, 1500 );
		} ).catch( function () {
			$( '#sfwp-preview-content' ).select();
			document.execCommand( 'copy' );
			$btn.text( '✓ Kopiert' );
			setTimeout( function () { $btn.text( 'Kopieren' ); }, 1500 );
		} );
	} );

	// ── Settings: Modus-Verfügbarkeit basierend auf Auth ──────────────────

	function updateModeAvailability() {
		var authType  = $( 'input[name="schemaforge_wp_auth_type"]:checked' ).val();
		var hasAuth   = authType === 'server' || authType === 'own-key';
		var $autoCard = $( '#sfwp-mode-auto-card' );

		if ( ! $autoCard.length ) return;

		$autoCard.toggleClass( 'is-locked', ! hasAuth );

		// If auto is selected but auth is now removed, force deterministic.
		if ( ! hasAuth && $( 'input[name="schemaforge_wp_mode"]:checked' ).val() === 'auto' ) {
			$( 'input[name="schemaforge_wp_mode"][value="deterministic"]' ).prop( 'checked', true );
			syncModeCards();
		}
	}

	function syncModeCards() {
		$( 'input[name="schemaforge_wp_mode"]' ).each( function () {
			$( this ).closest( '.sfwp-mode-card' ).toggleClass( 'is-checked', $( this ).is( ':checked' ) );
		} );
	}

	function updateAuthFields() {
		var authType = $( 'input[name="schemaforge_wp_auth_type"]:checked' ).val();
		$( '#sfwp-auth-server' ).toggle( authType === 'server' );
		$( '#sfwp-auth-own-key' ).toggle( authType === 'own-key' );

		// Sync is-checked for CSS :has() fallback.
		$( 'input[name="schemaforge_wp_auth_type"]' ).each( function () {
			$( this ).closest( '.sfwp-mode-card' ).toggleClass( 'is-checked', $( this ).is( ':checked' ) );
		} );
		syncModeCards();
		updateModeAvailability();
	}

	$( 'input[name="schemaforge_wp_auth_type"], input[name="schemaforge_wp_mode"]' ).on( 'change', updateAuthFields );
	updateAuthFields();

	// ── Settings: Verbindung testen mit Live-Formularwerten ───────────────

	function getLiveFormData() {
		return {
			live_auth_type:    $( 'input[name="schemaforge_wp_auth_type"]:checked' ).val() || 'none',
			live_username:     $( 'input[name="schemaforge_wp_username"]' ).val()           || '',
			live_password:     $( 'input[name="schemaforge_wp_password"]' ).val()           || '',
			live_own_provider: $( 'select[name="schemaforge_wp_own_provider"]' ).val()      || 'anthropic',
			live_own_key:      $( 'input[name="schemaforge_wp_own_key"]' ).val()            || '',
		};
	}

	function setTriRow( id, state, detail ) {
		var $tri  = $( '#sfwp-tri-' + id );
		var icons = { ok: '✓', err: '✗', skip: '—', pending: '…' };
		$tri.removeClass( 'is-ok is-err is-skip' )
			.addClass( state === 'ok' ? 'is-ok' : ( state === 'err' ? 'is-err' : 'is-skip' ) );
		$tri.find( '.sfwp-tri__icon' ).text( icons[ state ] || '—' );
		$tri.find( '.sfwp-tri__detail' ).text( detail || '' );
	}

	$( '#sfwp-test-connection' ).on( 'click', function () {
		var $btn     = $( this );
		var $spinner = $( '#sfwp-test-spinner' );
		var $results = $( '#sfwp-test-results' );

		$btn.prop( 'disabled', true );
		$spinner.show();
		$results.show();
		setTriRow( 'server', 'pending', '…' );
		setTriRow( 'auth',   'skip',    '' );
		setTriRow( 'llm',    'skip',    '' );

		$.post( sfwp.ajax_url, $.extend( {
			action: 'schemaforge_wp_test_connection',
			nonce:  sfwp.nonce,
		}, getLiveFormData() ) )
		.done( function ( resp ) {
			if ( ! resp.success ) {
				setTriRow( 'server', 'err', resp.data || 'Fehler' );
				return;
			}
			var d = resp.data;

			if ( d.server && d.server.ok ) {
				var s = 'Erreichbar';
				if ( d.server.version )  s += ' · v' + d.server.version;
				if ( d.server.provider ) s += ' · ' + d.server.provider;
				setTriRow( 'server', 'ok', s );
			} else {
				setTriRow( 'server', 'err', ( d.server && d.server.message ) || 'Nicht erreichbar' );
			}

			if ( d.auth ) {
				setTriRow( 'auth', d.auth.ok ? 'ok' : 'err', d.auth.message || '' );
			} else {
				setTriRow( 'auth', 'skip', 'Kein Server-Zugang konfiguriert' );
			}

			if ( d.llm ) {
				setTriRow( 'llm', d.llm.ok ? 'ok' : 'err', d.llm.message || '' );
			} else {
				setTriRow( 'llm', 'skip', 'Kein eigener API-Key konfiguriert' );
			}
		} )
		.fail( function () {
			setTriRow( 'server', 'err', 'Verbindungsfehler' );
		} )
		.always( function () {
			$btn.prop( 'disabled', false );
			$spinner.hide();
		} );
	} );

	// ── Settings: Strategie-Beschreibung ─────────────────────────────────

	function updateStrategyDesc() {
		var val = $( '#sfwp-strategy' ).val();
		$( '#sfwp-strategy-desc [data-strategy]' ).hide();
		$( '#sfwp-strategy-desc [data-strategy="' + val + '"]' ).show();
	}

	$( '#sfwp-strategy' ).on( 'change', updateStrategyDesc );
} );
