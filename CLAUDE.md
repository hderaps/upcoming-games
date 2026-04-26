# Ice Zoo Games Calendar — Plugin Context

## What this plugin does
Fetches upcoming and past Ice Zoo Hockey Club games from the esportsdesk.com ICS feeds and displays them on a WordPress page template. Shows upcoming fixtures with division filters and a Results tab with scores and win highlights.

## File layout
```
games-calendar/
├── games-calendar.php          # Main plugin: hooks, render functions, admin page
├── includes/
│   └── class-gc-fetcher.php   # ICS fetching, parsing, caching
├── templates/
│   └── page-games-calendar.php # WordPress page template
├── assets/
│   ├── css/games-calendar.css  # Dark ice-hockey theme (user edits this directly)
│   ├── js/games-calendar.js    # Tab switching + division filter pills
│   └── logos/                  # Team logo JPGs (80×80px square)
└── CLAUDE.md
```

## Data source
ICS feeds from `https://www.esportsdesk.com/webcalSched.cfm`
- `clientID` is always `6103`
- Each division has its own `leagueID`

### League IDs
| leagueID | Division | Ice Zoo team(s) |
|----------|----------|-----------------|
| 31142    | U9       | Atom            |
| 25408    | U11      | Wolves          |
| 26218    | U13      | Wolves          |
| 26219    | U15      | Wolves          |
| 26226    | U17      | Polar Bears     |
| 26227    | Women    | Arctic Foxes    |
| 28522    | Senior   | Moose, Hippos, Apes, Tigers |

### ICS SUMMARY format
```
GAME - {Away Team} at {Home Team} - {Division Name}
```
For completed games the team names include the score:
```
GAME - Hippos (0) at Apes (3) - Senior
```
`parse_vevent()` strips the score into `away_score`/`home_score` fields and stores clean team names.

### Timezone
DTSTART is stored as UTC in the feed. The fetcher converts to `Australia/Sydney`.

## Data storage
Games are stored permanently in `wp_options` (not transients — no expiry, always fast):
- `gc_games_all` — all games unsorted, no upcoming/past split
- `gc_games_updated` — Unix timestamp of last successful refresh

The upcoming/past split is done at **read time** (not write time) using today's date in Australia/Sydney timezone. This means yesterday's games automatically move to Results without needing a refresh.

Page loads always read from the DB via `get_option()` — never from the live feed.
The live ICS feeds are only hit by:
1. `GC_Fetcher::refresh_all()` — called by the daily cron and the admin "Refresh Now" button
2. First-ever page load if no data is stored yet (e.g. fresh install)

`GC_Fetcher::clear_cache()` deletes both options plus any old transients/options from previous versions.
Admin page at **Settings → Games Calendar** shows the last-fetched timestamp and a manual "Refresh Now" button.
WP-Cron job `gc_midnight_refresh` calls `refresh_all()` daily at midnight Sydney time.

## Team logos
Stored in `assets/logos/{slug}.jpg`. All 80×80px square.

Logo map lives in `gc_team_logo()` in `games-calendar.php`. Key normalisation:
1. Lowercase + trim
2. Strip trailing ` Home` / ` Away`
3. Strip trailing ` (N)` score suffix (e.g. `Hippos (0)` → `hippos`)
4. Strip trailing ` N` number (e.g. `Bears 2` → `bears`)

### Current logo slugs
`atom`, `wolves`, `polar-bears`, `arctic-foxes`, `moose`, `hippos`, `apes`, `tigers`, `bears`, `eagles`, `emperors`, `northstars`, `penguins`, `phantoms`, `pirates`, `saints`, `stingrays`

### Aliases
- Saints logo: Knights, Cardinals, Crusaders, Angels, Valkyries
- Bears logo: Kodiaks, Grizzlies

To add a new logo: drop `{slug}.jpg` into `assets/logos/` and add the entry to `$map` in `gc_team_logo()`.

## Venue abbreviations
| Code | Full name |
|------|-----------|
| IZOO | Ice Zoo |
| LCC  | Liverpool Catholic Club |
| HISS | Hunter Ice Skating Stadium |
| MAC  | Macquarie Ice Rink |
| EIA  | Erina Ice Arena |
| PISC | Phillip Ice Skating Centre |
| OIA  | O'Brien Icehouse |

## Render flow
```
page-games-calendar.php
  → gc_render_all($upcoming, $past)
      → gc_render_games($upcoming)   # filter pills + date-grouped upcoming cards
          → gc_render_game($game)    # single upcoming card (time + VS)
      → gc_render_results($past)     # date-grouped result cards
          → gc_render_result($game)  # single result card (score + win highlight)
```

## Win highlight
`gc_render_result()` compares `away_score` vs `home_score` and adds `.gc-win` class (green border glow) to the card when Ice Zoo's team has the higher score.

## CSS notes
- Dark ice-hockey theme — background `#0f1e2e`, accent `rgb(2, 146, 199)`
- The user edits `games-calendar.css` directly in the IDE; don't overwrite their changes without checking
- Division badge colour classes: `.gc-badge-u9`, `.gc-badge-u11`, `.gc-badge-u13`, `.gc-badge-u15`, `.gc-badge-u17`, `.gc-badge-women`, `.gc-badge-senior`

## Admin diagnostics
Settings → Games Calendar → "Run Diagnostics" tests a sample of league ICS URLs and shows HTTP status, VEVENT count, games parsed, and a raw ICS sample. Useful when games go missing.

## Common gotchas
- `sslverify => false` in `request_args()` — needed for local dev (Windows can't verify esportsdesk SSL); leave it in, it's harmless in production
- WP-Cron fires on page visits, not on a real system clock — the midnight refresh may be a few minutes late on low-traffic nights
- Women's division CSS slug is `women` (not `womens`) — both classes exist for backward compatibility
