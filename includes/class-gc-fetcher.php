<?php
defined( 'ABSPATH' ) || exit;

class GC_Fetcher {

	// esportsdesk's WAF blocks WP Engine's shared IPs (returns HTTP 202, empty body),
	// so requests are routed through a Cloudflare Worker proxy instead.
	// See cloudflare-worker/ics-proxy.js for the worker source.
	private const ICS_BASE  = 'https://odd-frog-771c.hugh-ba1.workers.dev/';
	private const CLIENT_ID = '6103';
	private const TIMEZONE  = 'Australia/Sydney';

	// wp_options keys (autoload=false — only loaded when needed)
	private const OPT_ALL     = 'gc_games_all';     // all games, unsplit — filtered at read time
	private const OPT_UPDATED = 'gc_games_updated'; // Unix timestamp of last successful refresh

	private static array $leagues = [
		31142 => [ 'name' => 'U9',      'teams' => [ 'Atom' ] ],
		25408 => [ 'name' => 'U11',     'teams' => [ 'Wolves' ] ],
		26218 => [ 'name' => 'U13',     'teams' => [ 'Wolves' ] ],
		26219 => [ 'name' => 'U15',     'teams' => [ 'Wolves' ] ],
		26226 => [ 'name' => 'U17',     'teams' => [ 'Polar Bears' ] ],
		26227 => [ 'name' => 'Women',   'teams' => [ 'Arctic Foxes' ] ],
		28522 => [ 'name' => 'Senior',  'teams' => [ 'Moose', 'Hippos', 'Apes', 'Tigers' ] ],
	];

	private static array $venues = [
		'IZOO' => 'Ice Zoo',
		'LCC'  => 'Liverpool Catholic Club',
		'HISS' => 'Hunter Ice Skating Stadium',
		'MAC'  => 'Macquarie Ice Rink',
		'EIA'  => 'Erina Ice Arena',
		'PISC' => 'Phillip Ice Skating Centre',
		'OIA'  => "O'Brien Icehouse",
	];

	/* ─ Public API ──────────────────────────────────────────────────── */

	public function get_upcoming_games(): array {
		$all = $this->get_all();
		$today = ( new DateTime( 'now', new DateTimeZone( self::TIMEZONE ) ) )->format( 'Y-m-d' );
		$games = array_values( array_filter( $all, fn( $g ) => $g['date'] >= $today ) );
		usort( $games, fn( $a, $b ) => strcmp( $a['date'], $b['date'] ) );
		return $games;
	}

	public function get_past_results(): array {
		$all = $this->get_all();
		$today = ( new DateTime( 'now', new DateTimeZone( self::TIMEZONE ) ) )->format( 'Y-m-d' );
		$games = array_values( array_filter( $all, fn( $g ) => $g['date'] < $today ) );
		usort( $games, fn( $a, $b ) => strcmp( $b['date'], $a['date'] ) );
		return $games;
	}

	private function get_all(): array {
		$data = get_option( self::OPT_ALL );
		if ( false === $data ) {
			$this->refresh_all();
			$data = get_option( self::OPT_ALL, [] );
		}
		return $data;
	}

	/**
	 * Fetch all ICS feeds, split into upcoming/past, and persist to wp_options.
	 * Returns a report array: [ 'upcoming' => N, 'past' => N, 'leagues' => [...] ]
	 * 'leagues' has one entry per league: [ 'name', 'games', 'error' ]
	 */
	public function refresh_all(): array {
		$all    = [];
		$report = [];

		foreach ( self::$leagues as $id => $league ) {
			$resp = wp_remote_get( $this->ics_url( $id ), $this->request_args() );

			if ( is_wp_error( $resp ) ) {
				$report[] = [ 'name' => $league['name'], 'games' => 0, 'error' => $resp->get_error_message() ];
				continue;
			}

			$code = (int) wp_remote_retrieve_response_code( $resp );
			if ( $code < 200 || $code >= 300 ) {
				$report[] = [ 'name' => $league['name'], 'games' => 0, 'error' => "HTTP $code" ];
				continue;
			}

			$games  = $this->parse_ics( wp_remote_retrieve_body( $resp ), $league );
			$games  = array_map( fn( $g ) => array_merge( $g, [ 'league_id' => $id ] ), $games );
			$all    = array_merge( $all, $games );
			$report[] = [ 'name' => $league['name'], 'games' => count( $games ), 'error' => null ];
		}

		// If every league failed (e.g. this server's outbound requests are blocked —
		// see the push endpoint in games-calendar.php), don't wipe out whatever the
		// automated push already has stored just because this direct fetch failed.
		$all_failed = ! empty( array_filter( $report, fn( $l ) => null !== $l['error'] ) )
			&& empty( array_filter( $report, fn( $l ) => null === $l['error'] ) );
		if ( $all_failed && ! empty( get_option( self::OPT_ALL, [] ) ) ) {
			return array_merge( $this->counts( get_option( self::OPT_ALL, [] ) ), [ 'leagues' => $report ] );
		}

		return array_merge( $this->finalize( $all ), [ 'leagues' => $report ] );
	}

	/**
	 * Ingest raw ICS text fetched elsewhere (used by the REST push endpoint), since
	 * this server's own outbound requests to esportsdesk are blocked — see
	 * cloudflare-worker/ics-proxy.js and the "push" setup in games-calendar.php.
	 * $ics_by_league: [ leagueID => raw ICS string ]
	 * Returns the same report shape as refresh_all().
	 */
	public function ingest_from_ics_map( array $ics_by_league ): array {
		$all    = [];
		$report = [];

		foreach ( self::$leagues as $id => $league ) {
			if ( ! array_key_exists( $id, $ics_by_league ) ) {
				$report[] = [ 'name' => $league['name'], 'games' => 0, 'error' => 'Not included in push' ];
				continue;
			}

			$games  = $this->parse_ics( (string) $ics_by_league[ $id ], $league );
			$games  = array_map( fn( $g ) => array_merge( $g, [ 'league_id' => $id ] ), $games );
			$all    = array_merge( $all, $games );
			$report[] = [ 'name' => $league['name'], 'games' => count( $games ), 'error' => null ];
		}

		return array_merge( $this->finalize( $all ), [ 'leagues' => $report ] );
	}

	/** Sort, strip helper fields, persist to wp_options, and return upcoming/past counts. */
	private function finalize( array $all ): array {
		usort( $all, fn( $a, $b ) => strcmp( $a['date'] . $a['_sort'], $b['date'] . $b['_sort'] ) );
		array_walk( $all, function ( &$g ) { unset( $g['_sort'] ); } );

		update_option( self::OPT_ALL,     $all,   false );
		update_option( self::OPT_UPDATED, time(), false );

		return $this->counts( $all );
	}

	/** Split a games list into upcoming/past counts using today's date (Sydney time). */
	private function counts( array $all ): array {
		$today = ( new DateTime( 'now', new DateTimeZone( self::TIMEZONE ) ) )->format( 'Y-m-d' );

		return [
			'upcoming' => count( array_filter( $all, fn( $g ) => $g['date'] >= $today ) ),
			'past'     => count( array_filter( $all, fn( $g ) => $g['date'] <  $today ) ),
		];
	}

	public function last_updated(): ?int {
		$ts = get_option( self::OPT_UPDATED );
		return false !== $ts ? (int) $ts : null;
	}

	/** Wipe stored data (forces a fresh fetch on next page load or cron run). */
	public function clear_cache(): void {
		delete_option( self::OPT_ALL );
		delete_option( self::OPT_UPDATED );
		// Clean up options and transients from previous versions
		delete_option( 'gc_games_upcoming' );
		delete_option( 'gc_games_past' );
		delete_transient( 'gc_all_v1' );
		delete_transient( 'gc_upcoming_v4' );
		delete_transient( 'gc_past_v1' );
		delete_transient( 'gc_past_v2' );
	}

	public function diagnose(): array {
		$report  = [];
		$samples = [
			26218 => self::$leagues[26218], // U13 – Wolves
			26226 => self::$leagues[26226], // U17 – Polar Bears
			28522 => self::$leagues[28522], // Senior
		];

		foreach ( $samples as $id => $league ) {
			$url  = $this->ics_url( $id );
			$resp = wp_remote_get( $url, $this->request_args() );

			if ( is_wp_error( $resp ) ) {
				$report[ $id ] = [
					'league'       => $league['name'],
					'url'          => $url,
					'status'       => 'WP_Error: ' . $resp->get_error_message(),
					'vevent_count' => 0,
					'games_found'  => 0,
					'sample'       => '',
				];
				continue;
			}

			$code  = (int) wp_remote_retrieve_response_code( $resp );
			$body  = wp_remote_retrieve_body( $resp );
			preg_match_all( '/BEGIN:VEVENT/', $body, $m );

			$report[ $id ] = [
				'league'       => $league['name'],
				'url'          => $url,
				'status'       => $code,
				'vevent_count' => count( $m[0] ),
				'games_found'  => count( $this->parse_ics( $body, $league ) ),
				'sample'       => substr( $body, 0, 600 ),
			];
		}

		// Control test: hits a totally unrelated third-party URL to check whether
		// outbound wp_remote_get() calls are being intercepted/stubbed site-wide,
		// independent of esportsdesk or the Cloudflare Worker.
		$control_url  = 'https://httpbin.org/get';
		$control_resp = wp_remote_get( $control_url, $this->request_args() );
		if ( is_wp_error( $control_resp ) ) {
			$report['control'] = [
				'league'       => 'CONTROL (httpbin.org)',
				'url'          => $control_url,
				'status'       => 'WP_Error: ' . $control_resp->get_error_message(),
				'vevent_count' => 0,
				'games_found'  => 0,
				'sample'       => '',
			];
		} else {
			$control_body = wp_remote_retrieve_body( $control_resp );
			$report['control'] = [
				'league'       => 'CONTROL (httpbin.org)',
				'url'          => $control_url,
				'status'       => (int) wp_remote_retrieve_response_code( $control_resp ),
				'vevent_count' => 0,
				'games_found'  => 0,
				'sample'       => substr( $control_body, 0, 600 ),
			];
		}

		return $report;
	}

	/* ─ Fetching ────────────────────────────────────────────────────── */

	private function fetch_league( int $id, array $league ): array {
		$resp = wp_remote_get( $this->ics_url( $id ), $this->request_args() );

		if ( is_wp_error( $resp ) ) {
			return [];
		}

		$code = (int) wp_remote_retrieve_response_code( $resp );
		if ( $code < 200 || $code >= 300 ) {
			return [];
		}

		$games = $this->parse_ics( wp_remote_retrieve_body( $resp ), $league );

		return array_map( fn( $g ) => array_merge( $g, [ 'league_id' => $id ] ), $games );
	}

	private function ics_url( int $league_id ): string {
		return add_query_arg( [
			'clientID'       => self::CLIENT_ID,
			'leagueID'       => $league_id,
			'monthID'        => 0,
			'selectedTeamID' => '',
			'gameType'       => '',
			'yearID'         => '',
		], self::ICS_BASE );
	}

	private function request_args(): array {
		return [
			'timeout'    => 20,
			// Identify as a calendar-subscription fetcher (like Google Calendar's), since
			// this URL is esportsdesk's public webcal export link, not an internal API.
			'user-agent' => 'Feedfetcher-Google; (+http://www.google.com/feedfetcher.html)',
			'headers'    => [
				'Accept'          => 'text/calendar, text/plain, */*',
				'Accept-Language' => 'en-US,en;q=0.9',
			],
			'sslverify'  => false,
		];
	}

	/* ─ ICS Parsing ─────────────────────────────────────────────────── */

	private function parse_ics( string $ics, array $league ): array {
		$games = [];

		// Unfold long lines (RFC 5545: continuation lines start with a space/tab)
		$ics = preg_replace( '/\r?\n[ \t]/', '', $ics );

		preg_match_all( '/BEGIN:VEVENT(.*?)END:VEVENT/s', $ics, $matches );

		foreach ( $matches[1] as $vevent ) {
			$game = $this->parse_vevent( $vevent, $league );
			if ( $game ) {
				$games[] = $game;
			}
		}

		return $games;
	}

	private function parse_vevent( string $vevent, array $league ): ?array {
		$summary  = $this->ics_field( $vevent, 'SUMMARY' );
		$dtstart  = $this->ics_field( $vevent, 'DTSTART' );
		$location = trim( $this->ics_field( $vevent, 'LOCATION' ) );

		if ( ! $summary || ! $dtstart ) {
			return null;
		}

		// SUMMARY format: "GAME - Away Team at Home Team - Division Name"
		if ( ! preg_match( '/^GAME\s*-\s*(.+?)\s+at\s+(.+?)\s*-\s*.+$/i', $summary, $m ) ) {
			return null;
		}

		$away = trim( $m[1] );
		$home = trim( $m[2] );

		// Extract scores embedded in team names for completed games: "Hippos (0)" → "Hippos", score=0
		$away_score = null;
		$home_score = null;
		if ( preg_match( '/^(.+?)\s*\((\d+)\)$/', $away, $sm ) ) {
			$away       = trim( $sm[1] );
			$away_score = (int) $sm[2];
		}
		if ( preg_match( '/^(.+?)\s*\((\d+)\)$/', $home, $sm ) ) {
			$home       = trim( $sm[1] );
			$home_score = (int) $sm[2];
		}

		// Check if one of our teams is playing
		$our_team    = null;
		$teams_lower = strtolower( $away . ' ' . $home );
		foreach ( $league['teams'] as $t ) {
			if ( false !== strpos( $teams_lower, strtolower( $t ) ) ) {
				$our_team = $t;
				break;
			}
		}
		if ( ! $our_team ) {
			return null;
		}

		// Parse UTC datetime and convert to Sydney local time
		// DTSTART;TZID=UTC:20260418T213000  or  DTSTART:20260418T213000Z
		$raw_dt = rtrim( preg_replace( '/^[^:]+:/', '', $dtstart ), 'Z' );
		$dt     = DateTime::createFromFormat( 'Ymd\THis', $raw_dt, new DateTimeZone( 'UTC' ) );
		if ( ! $dt ) {
			return null;
		}
		$dt->setTimezone( new DateTimeZone( self::TIMEZONE ) );

		$date = $dt->format( 'Y-m-d' );
		$time = $dt->format( 'g:i A' );
		$sort = $dt->format( 'His' );

		// Expand venue abbreviation
		$venue_key = strtoupper( $location );
		$venue     = self::$venues[ $venue_key ] ?? ( $location ?: 'TBD' );

		// Is our team the home side?
		$is_home = false;
		foreach ( $league['teams'] as $t ) {
			if ( false !== stripos( $home, $t ) ) {
				$is_home = true;
				break;
			}
		}

		return [
			'date'       => $date,
			'time'       => $time,
			'_sort'      => $sort,
			'away_team'  => $away,
			'home_team'  => $home,
			'away_score' => $away_score,
			'home_score' => $home_score,
			'venue'      => $venue,
			'division'   => $league['name'],
			'our_team'   => $our_team,
			'is_home'    => $is_home,
		];
	}

	/**
	 * Extract a single field value from a VEVENT block.
	 * Handles parameterised keys like DTSTART;TZID=UTC:value
	 */
	private function ics_field( string $vevent, string $field ): string {
		if ( preg_match( '/^' . preg_quote( $field, '/' ) . '(?:;[^:]+)?:(.+)$/m', $vevent, $m ) ) {
			return trim( $m[1] );
		}
		return '';
	}
}
