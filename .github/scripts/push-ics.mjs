// Fetches the club's 7 esportsdesk ICS calendar feeds and pushes them to the
// Games Calendar plugin's REST endpoint. Runs on a schedule via
// .github/workflows/push-ics.yml — see includes/class-gc-fetcher.php for why
// this exists (the WordPress host's own outbound requests to esportsdesk are
// blocked, so this runs on GitHub's runners instead and pushes the data in).

const LEAGUE_IDS = ['31142', '25408', '26218', '26219', '26226', '26227', '28522'];
const SECRET = process.env.GC_PUSH_SECRET;
const ENDPOINT = process.env.GC_PUSH_ENDPOINT ?? 'https://www.icezoohockeyclub.com.au/wp-json/games-calendar/v1/push';

if (!SECRET) {
	console.error('Missing GC_PUSH_SECRET environment variable');
	process.exit(1);
}

async function fetchIcs(leagueId) {
	const url = `https://www.esportsdesk.com/webcalSched.cfm?clientID=6103&leagueID=${leagueId}&monthID=0&selectedTeamID=&gameType=&yearID=`;
	const resp = await fetch(url, {
		headers: {
			'User-Agent': 'Feedfetcher-Google; (+http://www.google.com/feedfetcher.html)',
			'Accept': 'text/calendar, text/plain, */*',
		},
	});
	if (!resp.ok) {
		throw new Error(`leagueID=${leagueId} returned HTTP ${resp.status}`);
	}
	return resp.text();
}

const leagues = {};
const failures = [];
for (const id of LEAGUE_IDS) {
	try {
		leagues[id] = await fetchIcs(id);
		console.log(`fetched leagueID=${id} (${leagues[id].length} bytes)`);
	} catch (err) {
		failures.push(`${id}: ${err.message}`);
		console.error(`FAILED leagueID=${id}: ${err.message}`);
	}
}

if (Object.keys(leagues).length === 0) {
	console.error('All league fetches failed, not pushing.');
	process.exit(1);
}

const resp = await fetch(ENDPOINT, {
	method: 'POST',
	headers: { 'Content-Type': 'application/json' },
	body: JSON.stringify({ secret: SECRET, leagues }),
});

const text = await resp.text();
console.log(`push response: HTTP ${resp.status}`);
console.log(text);

if (!resp.ok) {
	process.exit(1);
}
if (failures.length > 0) {
	console.error(`Completed with ${failures.length} league fetch failure(s): ${failures.join('; ')}`);
}
