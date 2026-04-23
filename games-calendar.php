<?php
/**
 * Plugin Name: Ice Zoo Games Calendar
 * Description: Displays upcoming Ice Zoo Hockey Club games fetched from the IHNSW results website.
 * Version:     1.0.0
 * Author:      Ice Zoo Hockey Club
 * License:     GPL-2.0+
 */

defined( 'ABSPATH' ) || exit;

define( 'GC_VERSION', '1.0.0' );
define( 'GC_DIR',     plugin_dir_path( __FILE__ ) );
define( 'GC_URL',     plugin_dir_url( __FILE__ ) );

require_once GC_DIR . 'includes/class-gc-fetcher.php';

/* ── Scheduled cache refresh (daily at midnight Sydney time) ─────────── */
register_activation_hook( __FILE__, 'gc_schedule_midnight_refresh' );
register_deactivation_hook( __FILE__, 'gc_unschedule_midnight_refresh' );

// Also schedule on wp_loaded in case the plugin was already active before this code was added
add_action( 'wp_loaded', function () {
	if ( ! wp_next_scheduled( 'gc_midnight_refresh' ) ) {
		gc_schedule_midnight_refresh();
	}
} );

function gc_schedule_midnight_refresh(): void {
	$tz       = new DateTimeZone( 'Australia/Sydney' );
	$midnight = new DateTime( 'tomorrow midnight', $tz );
	wp_schedule_event( $midnight->getTimestamp(), 'daily', 'gc_midnight_refresh' );
}

function gc_unschedule_midnight_refresh(): void {
	$timestamp = wp_next_scheduled( 'gc_midnight_refresh' );
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, 'gc_midnight_refresh' );
	}
}

add_action( 'gc_midnight_refresh', function () {
	$fetcher = new GC_Fetcher();
	$fetcher->clear_cache();
	$fetcher->get_upcoming_games( true );
	$fetcher->get_past_results( true );
} );

/* ── Styles & scripts (only on our template page) ────────────────────── */
add_action( 'wp_enqueue_scripts', function () {
	if ( is_page() && 'templates/page-games-calendar.php' === get_post_meta( get_the_ID(), '_wp_page_template', true ) ) {
		wp_enqueue_style( 'gc-styles', GC_URL . 'assets/css/games-calendar.css', [], GC_VERSION );
		wp_enqueue_script( 'gc-script', GC_URL . 'assets/js/games-calendar.js', [], GC_VERSION, true );
	}
} );

/* ── Page template registration ──────────────────────────────────────── */
add_filter( 'theme_page_templates', function ( $templates ) {
	$templates['templates/page-games-calendar.php'] = 'Ice Zoo Games Calendar';
	return $templates;
} );

add_filter( 'template_include', function ( $template ) {
	if ( is_page() && 'templates/page-games-calendar.php' === get_post_meta( get_the_ID(), '_wp_page_template', true ) ) {
		$plugin_tpl = GC_DIR . 'templates/page-games-calendar.php';
		if ( file_exists( $plugin_tpl ) ) {
			return $plugin_tpl;
		}
	}
	return $template;
} );

/* ── Top-level renderer (tabs + both panels) ────────────────────────── */
function gc_render_all( array $upcoming, array $past ): void {
	?>
	<div class="gc-tabs">
		<button class="gc-tab active" data-panel="upcoming">Upcoming Games</button>
		<button class="gc-tab" data-panel="results">Results</button>
	</div>

	<div id="gc-panel-upcoming">
		<?php if ( empty( $upcoming ) ) : ?>
			<p class="gc-empty">No upcoming games scheduled at the moment.</p>
		<?php else : ?>
			<?php gc_render_games( $upcoming ); ?>
		<?php endif; ?>
	</div>

	<div id="gc-panel-results" style="display:none">
		<?php if ( empty( $past ) ) : ?>
			<p class="gc-empty">No past results found yet.</p>
		<?php else : ?>
			<?php gc_render_results( $past ); ?>
		<?php endif; ?>
	</div>
	<?php
}

/* ── Render helpers ──────────────────────────────────────────────────── */
function gc_render_games( array $games ): void {
	// Build ordered list of unique divisions present in the games
	$division_order = [ 'U9', 'U11', 'U13', 'U15', 'U17', 'Women', 'Senior' ];
	$divisions_present = [];
	foreach ( $games as $game ) {
		$divisions_present[ $game['division'] ] = true;
	}
	$divisions = array_filter( $division_order, fn( $d ) => isset( $divisions_present[ $d ] ) );

	// Filter bar
	echo '<div class="gc-filters">';
	echo '<button class="gc-filter active" data-division="all">All</button>';
	foreach ( $divisions as $div ) {
		printf(
			'<button class="gc-filter" data-division="%s">%s</button>',
			esc_attr( $div ),
			esc_html( $div )
		);
	}
	echo '</div>';

	echo '<div class="gc-wrap">';

	$last_date = null;
	foreach ( $games as $game ) {
		if ( $game['date'] !== $last_date ) {
			if ( null !== $last_date ) {
				echo '</div>'; // close previous date-group
			}
			$last_date = $game['date'];
			printf(
				'<div class="gc-date-group"><div class="gc-date-header">%s</div>',
				esc_html( date( 'l, j F Y', strtotime( $game['date'] ) ) )
			);
		}
		gc_render_game( $game );
	}

	if ( null !== $last_date ) {
		echo '</div>'; // close last date-group
	}
	echo '</div>'; // .gc-wrap
}

/**
 * Returns the URL of a team's logo, or empty string if none found.
 * Strips trailing numbers and Home/Away suffixes before lookup.
 */
function gc_team_logo( string $team ): string {
	static $map = [
		// Ice Zoo teams
		'atom'         => 'atom',
		'wolves'       => 'wolves',
		'polar bears'  => 'polar-bears',
		'arctic foxes' => 'arctic-foxes',
		'moose'        => 'moose',
		'hippos'       => 'hippos',
		'apes'         => 'apes',
		'tigers'       => 'tigers',
		// Opposition with own logos
		'bears'        => 'bears',
		'eagles'       => 'eagles',
		'emperors'     => 'emperors',
		'northstars'   => 'northstars',
		'phantoms'     => 'phantoms',
		'pirates'      => 'pirates',
		'saints'       => 'saints',
		'stingrays'    => 'stingrays',
		'penguins'     => 'penguins',
		// Aliases → saints
		'knights'      => 'saints',
		'cardinals'    => 'saints',
		'crusaders'    => 'saints',
		'angels'       => 'saints',
		'valkyries'    => 'saints',
		// Aliases → bears
		'kodiaks'      => 'bears',
		'grizzlies'    => 'bears',
	];

	// Normalise: lowercase, strip trailing " Home", " Away", or numbers
	$key = strtolower( trim( $team ) );
	$key = preg_replace( '/\s+(home|away)$/i', '', $key );
	$key = preg_replace( '/\s+\d+$/', '', $key );

	if ( isset( $map[ $key ] ) ) {
		return GC_URL . 'assets/logos/' . $map[ $key ] . '.jpg';
	}

	return '';
}

function gc_render_game( array $game ): void {
	$is_home   = $game['is_home'];
	$div_slug  = sanitize_html_class( strtolower( str_replace( [ "'", ' ' ], [ '', '-' ], $game['division'] ) ) );
	$away_logo = gc_team_logo( $game['away_team'] );
	$home_logo = gc_team_logo( $game['home_team'] );
	?>
	<div class="gc-game" data-division="<?php echo esc_attr( $game['division'] ); ?>">
		<div class="gc-game-meta">
			<span class="gc-badge gc-badge-<?php echo esc_attr( $div_slug ); ?>">
				<?php echo esc_html( $game['division'] ); ?>
			</span>
			<span class="<?php echo $is_home ? 'gc-ha-home' : 'gc-ha-away'; ?>">
				<?php echo $is_home ? 'HOME' : 'AWAY'; ?>
			</span>
		</div>
		<div class="gc-game-body">
			<div class="gc-team gc-team-away<?php echo ! $is_home ? ' gc-our-team' : ''; ?>">
				<?php if ( $away_logo ) : ?>
					<img src="<?php echo esc_url( $away_logo ); ?>"
					     alt="<?php echo esc_attr( $game['away_team'] ); ?>"
					     class="gc-logo">
				<?php endif; ?>
				<span class="gc-team-name"><?php echo esc_html( $game['away_team'] ); ?></span>
			</div>
			<div class="gc-mid">
				<div class="gc-game-date"><?php echo esc_html( date( 'D j M', strtotime( $game['date'] ) ) ); ?></div>
				<div class="gc-time"><?php echo esc_html( $game['time'] ); ?></div>
				<div class="gc-vs-label">VS</div>
			</div>
			<div class="gc-team gc-team-home<?php echo $is_home ? ' gc-our-team' : ''; ?>">
				<?php if ( $home_logo ) : ?>
					<img src="<?php echo esc_url( $home_logo ); ?>"
					     alt="<?php echo esc_attr( $game['home_team'] ); ?>"
					     class="gc-logo">
				<?php endif; ?>
				<span class="gc-team-name"><?php echo esc_html( $game['home_team'] ); ?></span>
			</div>
		</div>
		<div class="gc-venue">&#128205; <?php echo esc_html( $game['venue'] ); ?></div>
	</div>
	<?php
}

function gc_render_results( array $games ): void {
	echo '<div class="gc-wrap">';

	$last_date = null;
	foreach ( $games as $game ) {
		if ( $game['date'] !== $last_date ) {
			if ( null !== $last_date ) {
				echo '</div>'; // close previous date-group
			}
			$last_date = $game['date'];
			printf(
				'<div class="gc-date-group"><div class="gc-date-header">%s</div>',
				esc_html( date( 'l, j F Y', strtotime( $game['date'] ) ) )
			);
		}
		gc_render_result( $game );
	}

	if ( null !== $last_date ) {
		echo '</div>'; // close last date-group
	}
	echo '</div>'; // .gc-wrap
}

function gc_render_result( array $game ): void {
	$is_home    = $game['is_home'];
	$div_slug   = sanitize_html_class( strtolower( str_replace( [ "'", ' ' ], [ '', '-' ], $game['division'] ) ) );
	$away_logo  = gc_team_logo( $game['away_team'] );
	$home_logo  = gc_team_logo( $game['home_team'] );
	$away_score = $game['away_score'] ?? null;
	$home_score = $game['home_score'] ?? null;
	$has_score  = null !== $away_score && null !== $home_score;

	// Did our team win?
	$we_won = $has_score && (
		$is_home ? $home_score > $away_score : $away_score > $home_score
	);
	?>
	<div class="gc-game gc-result<?php echo $we_won ? ' gc-win' : ''; ?>">
		<div class="gc-game-meta">
			<span class="gc-badge gc-badge-<?php echo esc_attr( $div_slug ); ?>">
				<?php echo esc_html( $game['division'] ); ?>
			</span>
			<span class="gc-final-badge">FINAL</span>
			<span class="<?php echo $is_home ? 'gc-ha-home' : 'gc-ha-away'; ?>">
				<?php echo $is_home ? 'HOME' : 'AWAY'; ?>
			</span>
		</div>
		<div class="gc-game-body">
			<div class="gc-team gc-team-away<?php echo ! $is_home ? ' gc-our-team' : ''; ?>">
				<?php if ( $away_logo ) : ?>
					<img src="<?php echo esc_url( $away_logo ); ?>"
					     alt="<?php echo esc_attr( $game['away_team'] ); ?>"
					     class="gc-logo">
				<?php endif; ?>
				<span class="gc-team-name"><?php echo esc_html( $game['away_team'] ); ?></span>
			</div>
			<div class="gc-mid">
				<div class="gc-game-date"><?php echo esc_html( date( 'D j M', strtotime( $game['date'] ) ) ); ?></div>
				<?php if ( $has_score ) : ?>
					<div class="gc-score">
						<?php echo esc_html( $away_score . ' – ' . $home_score ); ?>
					</div>
				<?php else : ?>
					<div class="gc-vs-label">VS</div>
				<?php endif; ?>
			</div>
			<div class="gc-team gc-team-home<?php echo $is_home ? ' gc-our-team' : ''; ?>">
				<?php if ( $home_logo ) : ?>
					<img src="<?php echo esc_url( $home_logo ); ?>"
					     alt="<?php echo esc_attr( $game['home_team'] ); ?>"
					     class="gc-logo">
				<?php endif; ?>
				<span class="gc-team-name"><?php echo esc_html( $game['home_team'] ); ?></span>
			</div>
		</div>
		<div class="gc-venue">&#128205; <?php echo esc_html( $game['venue'] ); ?></div>
	</div>
	<?php
}

/* ── Admin page (Settings › Games Calendar) ─────────────────────────── */
add_action( 'admin_menu', function () {
	add_options_page(
		'Games Calendar',
		'Games Calendar',
		'manage_options',
		'gc-settings',
		'gc_admin_page'
	);
} );

function gc_admin_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	if ( isset( $_POST['gc_refresh'] ) && check_admin_referer( 'gc_refresh_nonce' ) ) {
		$fetcher = new GC_Fetcher();
		$fetcher->clear_cache();
		$fetcher->get_upcoming_games( true );
		echo '<div class="notice notice-success"><p>Cache refreshed successfully.</p></div>';
	}

	$fetcher = new GC_Fetcher();
	$games   = $fetcher->get_upcoming_games();
	?>
	<div class="wrap">
		<h1>Ice Zoo Games Calendar</h1>

		<h2>How to use</h2>
		<ol>
			<li>Create a new <strong>Page</strong> in WordPress (e.g. "Upcoming Games").</li>
			<li>In the Page editor, open the <strong>Page Attributes</strong> panel and set <strong>Template</strong> to <em>Ice Zoo Games Calendar</em>.</li>
			<li>Publish the page — the full schedule will appear automatically.</li>
		</ol>

		<h2>Cache</h2>
		<form method="post">
			<?php wp_nonce_field( 'gc_refresh_nonce' ); ?>
			<p>
				<button type="submit" name="gc_refresh" class="button button-primary">
					Refresh Cache Now
				</button>
				<span class="description">&nbsp; Cache auto-expires every hour.</span>
			</p>
		</form>

		<h2>Diagnostics</h2>
		<form method="post">
			<?php wp_nonce_field( 'gc_refresh_nonce' ); ?>
			<p>
				<button type="submit" name="gc_diagnose" class="button button-secondary">
					Run Diagnostics
				</button>
				<span class="description">&nbsp; Tests each league URL and shows exactly what the server returns.</span>
			</p>
		</form>
		<?php if ( isset( $_POST['gc_diagnose'] ) && check_admin_referer( 'gc_refresh_nonce' ) ) : ?>
			<?php $diag = $fetcher->diagnose(); ?>
			<table class="widefat striped" style="max-width:960px;margin-top:12px">
				<thead>
					<tr>
						<th>League</th>
						<th>HTTP Status</th>
						<th>Events in feed</th>
						<th>Our games parsed</th>
						<th>ICS sample</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $diag as $id => $d ) : ?>
						<tr>
							<td><strong><?php echo esc_html( $d['league'] ); ?></strong><br><small style="color:#999">leagueID=<?php echo esc_html( $id ); ?></small></td>
							<td><?php echo esc_html( $d['status'] ); ?></td>
							<td><?php echo esc_html( $d['vevent_count'] ); ?></td>
							<td><strong><?php echo esc_html( $d['games_found'] ); ?></strong></td>
							<td><pre style="font-size:10px;margin:0;white-space:pre-wrap;max-width:380px"><?php echo esc_html( $d['sample'] ); ?></pre></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<h2>Upcoming Games <span style="font-weight:normal;font-size:0.9em">(<?php echo count( $games ); ?> found)</span></h2>
		<?php if ( empty( $games ) ) : ?>
			<p>No games found. Try refreshing the cache above.</p>
		<?php else : ?>
			<table class="widefat striped" style="max-width:960px">
				<thead>
					<tr>
						<th>Date</th>
						<th>Time</th>
						<th>Division</th>
						<th>Away Team</th>
						<th>Home Team</th>
						<th>Venue</th>
						<th>H/A</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $games as $g ) : ?>
						<tr>
							<td><?php echo esc_html( date( 'D j M Y', strtotime( $g['date'] ) ) ); ?></td>
							<td><?php echo esc_html( $g['time'] ); ?></td>
							<td><?php echo esc_html( $g['division'] ); ?></td>
							<td><?php echo esc_html( $g['away_team'] ); ?></td>
							<td><?php echo esc_html( $g['home_team'] ); ?></td>
							<td><?php echo esc_html( $g['venue'] ); ?></td>
							<td><?php echo $g['is_home'] ? '&#127968; Home' : '&#9992;&#65039; Away'; ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>
	<?php
}
