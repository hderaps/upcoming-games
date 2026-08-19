/**
 * ICS feed proxy for the Games Calendar plugin.
 *
 * Why this exists: esportsdesk.com's WAF appears to flag WP Engine's shared
 * outbound IP ranges, returning HTTP 202 with an empty body instead of the
 * real calendar feed. This worker runs on Cloudflare's network (different IP
 * reputation) and simply passes the request through.
 *
 * Deploy: paste into a new Worker in the Cloudflare dashboard (Workers & Pages
 * → Create → paste this as the only file), or `wrangler deploy` if you use
 * the CLI. Note the resulting *.workers.dev URL and set it as PROXY_BASE in
 * includes/class-gc-fetcher.php.
 */

const UPSTREAM = 'https://www.esportsdesk.com/webcalSched.cfm';
const CLIENT_ID = '6103';

// Only the league IDs this plugin actually uses — keeps this from becoming
// an open proxy for arbitrary esportsdesk clients/leagues.
const ALLOWED_LEAGUE_IDS = new Set([
	'31142', // U9
	'25408', // U11
	'26218', // U13
	'26219', // U15
	'26226', // U17
	'26227', // Women
	'28522', // Senior
]);

export default {
	async fetch(request) {
		const url = new URL(request.url);
		const leagueID = url.searchParams.get('leagueID');

		if (!leagueID || !ALLOWED_LEAGUE_IDS.has(leagueID)) {
			return new Response('Unknown or missing leagueID', { status: 400 });
		}

		const upstream = new URL(UPSTREAM);
		upstream.searchParams.set('clientID', CLIENT_ID);
		upstream.searchParams.set('leagueID', leagueID);
		upstream.searchParams.set('monthID', url.searchParams.get('monthID') ?? '0');
		upstream.searchParams.set('selectedTeamID', url.searchParams.get('selectedTeamID') ?? '');
		upstream.searchParams.set('gameType', url.searchParams.get('gameType') ?? '');
		upstream.searchParams.set('yearID', url.searchParams.get('yearID') ?? '');

		const resp = await fetch(upstream.toString(), {
			headers: {
				'User-Agent': 'Feedfetcher-Google; (+http://www.google.com/feedfetcher.html)',
				'Accept': 'text/calendar, text/plain, */*',
			},
		});

		const body = await resp.text();

		return new Response(body, {
			status: resp.status,
			headers: {
				'Content-Type': 'text/calendar; charset=UTF-8',
				'Cache-Control': 'no-store',
			},
		});
	},
};
