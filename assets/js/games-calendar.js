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

	// ── Division filter pills (upcoming panel only) ──────────────────────
	const filters = document.querySelectorAll( '.gc-filter' );
	const games   = document.querySelectorAll( '.gc-game' );
	const groups  = document.querySelectorAll( '.gc-date-group' );

	if ( ! filters.length ) return;

	filters.forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			filters.forEach( function ( b ) { b.classList.remove( 'active' ); } );
			this.classList.add( 'active' );

			var division = this.dataset.division;

			games.forEach( function ( game ) {
				var show = division === 'all' || game.dataset.division === division;
				game.style.display = show ? '' : 'none';
			} );

			// Hide date groups that have no visible games
			groups.forEach( function ( group ) {
				var hasVisible = Array.from( group.querySelectorAll( '.gc-game' ) )
					.some( function ( g ) { return g.style.display !== 'none'; } );
				group.style.display = hasVisible ? '' : 'none';
			} );
		} );
	} );
} )();
