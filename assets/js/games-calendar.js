( function () {
	// ── Tab switching ────────────────────────────────────────────────────
	var tabs      = document.querySelectorAll( '.gc-tab' );
	var panelUp   = document.getElementById( 'gc-panel-upcoming' );
	var panelRes  = document.getElementById( 'gc-panel-results' );

	if ( tabs.length && panelUp && panelRes ) {
		tabs.forEach( function ( tab ) {
			tab.addEventListener( 'click', function () {
				tabs.forEach( function ( t ) { t.classList.remove( 'active' ); } );
				this.classList.add( 'active' );

				if ( this.dataset.panel === 'upcoming' ) {
					panelUp.style.display  = '';
					panelRes.style.display = 'none';
				} else {
					panelUp.style.display  = 'none';
					panelRes.style.display = '';
				}
			} );
		} );
	}

	// ── Division filter pills (scoped per panel) ────────────────────────
	document.querySelectorAll( '.gc-filter' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			var panel = this.closest( '#gc-panel-upcoming, #gc-panel-results' );
			if ( ! panel ) return;

			// Deactivate sibling pills, activate this one
			panel.querySelectorAll( '.gc-filter' ).forEach( function ( b ) {
				b.classList.remove( 'active' );
			} );
			this.classList.add( 'active' );

			var division = this.dataset.division;

			panel.querySelectorAll( '.gc-game' ).forEach( function ( game ) {
				game.style.display = ( division === 'all' || game.dataset.division === division ) ? '' : 'none';
			} );

			// Hide date groups that have no visible games
			panel.querySelectorAll( '.gc-date-group' ).forEach( function ( group ) {
				var hasVisible = Array.from( group.querySelectorAll( '.gc-game' ) )
					.some( function ( g ) { return g.style.display !== 'none'; } );
				group.style.display = hasVisible ? '' : 'none';
			} );
		} );
	} );
} )();
