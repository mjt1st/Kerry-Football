<?php
/**
 * Kerry Football — Sports API Integration Service
 *
 * Handles communication with ESPN (schedules + scores) and The Odds API (odds + spreads).
 * ESPN is free/unlimited but unofficial. The Odds API has a 500-request/month free tier.
 *
 * @package Kerry_Football
 * @since   Sports API V1
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ============================================================
// ESPN Hidden API Functions (free, no key required)
// ============================================================

/**
 * Performs a GET against ESPN, rotating User-Agents until one is accepted.
 *
 * ESPN sits behind Akamai, which blocks by User-Agent and has repeatedly tightened what it
 * allows. Observed so far: WordPress's default ("WordPress/6.x; https://site.com") has always
 * been rejected; "PHP/<version>" and sending no User-Agent both worked in March 2026 and were
 * blocked by August 2026. Any single hardcoded string is therefore a future outage, and that
 * outage lands mid-season with no warning.
 *
 * So rather than pick another string, this tries a list. The first candidate that does not
 * come back 403 is remembered in an option and used first from then on, so the normal path is
 * still one request. Only a 403 rotates — other failures (500, timeout, rate limit) are
 * returned as-is, since those are not User-Agent problems.
 *
 * Do NOT add a browser User-Agent to the list. A "Mozilla/..." string is rejected precisely
 * because it is a lie: Akamai compares it against the TLS fingerprint of the PHP/cURL client,
 * sees a browser claim that cannot be true, and treats it as an impersonating bot. Every
 * candidate below is an honest identifier for an HTTP client library.
 *
 * @return string[] Candidate User-Agents, best guess first.
 */
function kf_espn_user_agents() {
    $agents = [];

    // A commissioner-supplied override goes first, so a future block can be worked around
    // from the API settings page without waiting on a plugin update.
    $custom = trim( (string) get_option( 'kf_espn_user_agent', '' ) );
    if ( $custom !== '' ) {
        $agents[] = $custom;
    }

    // Whichever candidate last succeeded, so the common case stays a single request.
    $known = trim( (string) get_option( 'kf_espn_working_user_agent', '' ) );
    if ( $known !== '' ) {
        $agents[] = $known;
    }

    // Verified against ESPN on 2026-08-15.
    $agents = array_merge( $agents, [
        'curl/8.4.0',
        'GuzzleHttp/7',
        'python-requests/2.31.0',
        'Go-http-client/1.1',
        'okhttp/4.12.0',
        'libwww-perl/6.67',
    ] );

    return array_values( array_unique( array_filter( $agents ) ) );
}

/**
 * ESPN hostnames to try, in order.
 *
 * site.api.espn.com is the well-known host and stays primary. site.web.api.espn.com serves
 * byte-identical payloads (verified 2026-08-24: same event IDs, same odds, same top-level
 * keys) but is not behind the same Akamai bot ruleset — it accepted every User-Agent tested,
 * including WordPress's own. It is the fallback rather than the default because it is the
 * less-documented of the two and could plausibly be brought under the same rules later.
 *
 * @return string[]
 */
function kf_espn_hosts() {
    return [ 'site.api.espn.com', 'site.web.api.espn.com' ];
}

/**
 * @param string $url     Full ESPN URL.
 * @param int    $timeout Request timeout in seconds.
 * @return array|WP_Error Response array or WP_Error, as wp_remote_get().
 */
function kf_espn_remote_get( $url, $timeout = 15 ) {
    $agents      = kf_espn_user_agents();
    $known_agent = trim( (string) get_option( 'kf_espn_working_user_agent', '' ) );
    $known_host  = trim( (string) get_option( 'kf_espn_working_host', '' ) );

    $hosts = kf_espn_hosts();
    if ( $known_host !== '' && in_array( $known_host, $hosts, true ) ) {
        array_unshift( $hosts, $known_host );
        $hosts = array_values( array_unique( $hosts ) );
    }

    $response = null;

    foreach ( $hosts as $host ) {
        $try_url = preg_replace( '#^https?://[^/]+#', 'https://' . $host, $url );

        foreach ( $agents as $agent ) {
            $response = wp_remote_get( $try_url, [
                'timeout'    => $timeout,
                'user-agent' => $agent,
                'headers'    => [ 'Accept' => 'application/json' ],
            ] );

            // Transport failure: the request never landed, so a different User-Agent
            // will not help. Return it rather than burning through the whole matrix.
            //
            // Logged, because this is the silent one: a timeout (the college scoreboard is
            // ~1.5 MB) returned an empty result that every caller read as "no games found",
            // so scores simply stopped updating with nothing anywhere to say why.
            if ( is_wp_error( $response ) ) {
                error_log( 'Kerry Football: ESPN request failed on ' . $host . ' (' . $agent . '): ' . $response->get_error_message() );
                return $response;
            }

            if ( wp_remote_retrieve_response_code( $response ) === 403 ) {
                error_log( 'Kerry Football: ESPN refused User-Agent "' . $agent . '" on ' . $host . ' (403), trying next.' );
                continue;
            }

            // Anything that is not a block is this request's answer, success or otherwise.
            if ( $agent !== $known_agent ) {
                update_option( 'kf_espn_working_user_agent', $agent, false );
            }
            if ( $host !== $known_host ) {
                update_option( 'kf_espn_working_host', $host, false );
            }
            return $response;
        }
    }

    // Every host/User-Agent combination was refused. Forget the remembered pair so the next
    // call starts fresh instead of leading with one now known to be blocked.
    delete_option( 'kf_espn_working_user_agent' );
    delete_option( 'kf_espn_working_host' );
    error_log( 'Kerry Football: every ESPN host and User-Agent combination was refused for ' . $url );

    return $response;
}

/**
 * Fetches the scoreboard (game schedule) from ESPN for a given sport and week/date range.
 *
 * @param string $sport   'nfl' or 'college-football'
 * @param array  $params  Optional. ['week' => int, 'seasontype' => int] for NFL,
 *                        ['dates' => 'YYYYMMDD'] for college football.
 * @return array|WP_Error Array of normalized game objects, or WP_Error on failure.
 */
/**
 * Builds the scoreboard URL for a sport, and with it the transient key.
 *
 * This exists because the key drifted: college fetches gained groups=80, which changes the
 * URL and therefore the cache key, while the code clearing that cache still deleted the key
 * for the bare URL. The cron dutifully cleared a cache nobody was reading and then read a
 * stale one. Anything that builds or clears this cache must go through here.
 *
 * @param string $sport
 * @param array  $params
 * @return string
 */
function kf_espn_scoreboard_url( $sport = 'nfl', $params = [] ) {
    $base  = "https://site.api.espn.com/apis/site/v2/sports/football/{$sport}/scoreboard";
    $query = [];

    if ( $sport === 'nfl' ) {
        if ( ! empty( $params['week'] ) ) {
            $query['week'] = intval( $params['week'] );
        }
        if ( ! empty( $params['seasontype'] ) ) {
            $query['seasontype'] = intval( $params['seasontype'] );
        }
    } else {
        if ( ! empty( $params['dates'] ) ) {
            $query['dates'] = sanitize_text_field( $params['dates'] );
        }
        if ( ! empty( $params['week'] ) ) {
            $query['week'] = intval( $params['week'] );
        }
        if ( ! empty( $params['groups'] ) ) {
            $query['groups'] = intval( $params['groups'] );
        }
        $query['limit'] = 200;
    }

    return add_query_arg( $query, $base );
}

/**
 * Clears every cached scoreboard variant for a sport, so a forced refresh really is forced.
 *
 * @param string $sport
 * @return void
 */
function kf_espn_clear_scoreboard_cache( $sport ) {
    $variants = [ [] ];
    if ( $sport === 'college-football' ) {
        $variants[] = [ 'groups' => 80 ];
    }
    foreach ( $variants as $variant ) {
        delete_transient( 'kf_espn_' . md5( kf_espn_scoreboard_url( $sport, $variant ) ) );
    }
}

function kf_espn_fetch_scoreboard( $sport = 'nfl', $params = [] ) {
    $url = kf_espn_scoreboard_url( $sport, $params );

    // Check transient cache (15-minute TTL)
    $cache_key = 'kf_espn_' . md5( $url );
    $cached    = get_transient( $cache_key );
    if ( $cached !== false ) {
        return $cached;
    }

    // 25s, not 15: the full FBS scoreboard is ~1.5 MB and a shared host can take a while over
    // a slow link. A timeout here looks exactly like "no games found" to every caller.
    $response = kf_espn_remote_get( $url, 25 );

    if ( is_wp_error( $response ) ) {
        return $response;
    }

    $code = wp_remote_retrieve_response_code( $response );
    if ( $code !== 200 ) {
        error_log( 'Kerry Football: ESPN API returned HTTP ' . $code . ' for URL: ' . $url );
        $message = ( $code === 403 )
            ? 'ESPN refused every User-Agent this plugin knows (HTTP 403). Their CDN has tightened again — set a working one under API Settings; the error log lists what was tried.'
            : "ESPN API returned status {$code}";
        return new WP_Error( 'espn_api_error', $message );
    }

    $body = json_decode( wp_remote_retrieve_body( $response ), true );
    if ( ! $body || empty( $body['events'] ) ) {
        return []; // No games found
    }

    $games = [];
    foreach ( $body['events'] as $event ) {
        $game = kf_normalize_espn_event( $event, $sport );
        if ( $game ) {
            $games[] = $game;
        }
    }

    // Cache for 15 minutes
    set_transient( $cache_key, $games, 15 * MINUTE_IN_SECONDS );

    return $games;
}

/**
 * Does an ESPN odds `details` string describe a posted pick'em (a line of zero)?
 *
 * A pick'em has no favourite and no number, so it can never match the "ABBR -X.X" parser, and
 * a miss leaves both spreads null: the week reads "No odds" and the picks export writes an
 * empty spread, which means "no line posted" — not "a line of zero". This used to be an exact,
 * case-sensitive comparison against the untrimmed string with two spellings, so "PK ", "Pick"
 * or "PICK" all fell through. Accepts the bare word or a team abbreviation before it
 * ("KC PK"), in any case, with surrounding space. "OFF" (line taken down) is not a pick'em.
 *
 * @param string $details ESPN competitions[0].odds[0].details.
 * @return bool
 */
function kf_espn_is_pickem( $details ) {
    $d = strtoupper( trim( (string) $details ) );
    if ( $d === '' ) {
        return false;
    }
    return (bool) preg_match( "/(?:^|\s)(?:PK|PICK|PICK\s*['\x{2019}]?\s*EM|PICK-EM|EVEN)$/u", $d );
}

/**
 * Normalizes a single ESPN event into a standard game array.
 *
 * @param array  $event  Raw ESPN event data.
 * @param string $sport  'nfl' or 'college-football'.
 * @return array|null
 */
function kf_normalize_espn_event( $event, $sport ) {
    if ( empty( $event['competitions'][0] ) ) {
        return null;
    }

    $competition = $event['competitions'][0];
    $competitors = $competition['competitors'] ?? [];

    $home = null;
    $away = null;
    foreach ( $competitors as $team ) {
        if ( ( $team['homeAway'] ?? '' ) === 'home' ) {
            $home = $team;
        } else {
            $away = $team;
        }
    }

    if ( ! $home || ! $away ) {
        return null;
    }

    // Determine game status
    $status_type = $competition['status']['type']['name'] ?? 'STATUS_SCHEDULED';
    $status_map  = [
        'STATUS_SCHEDULED'   => 'scheduled',
        'STATUS_IN_PROGRESS' => 'in_progress',
        'STATUS_HALFTIME'    => 'in_progress',
        'STATUS_END_PERIOD'  => 'in_progress',
        'STATUS_FINAL'       => 'final',
        'STATUS_POSTPONED'   => 'postponed',
        'STATUS_CANCELED'    => 'canceled',
    ];
    $game_status = $status_map[ $status_type ] ?? 'scheduled';

    // Status detail for display (e.g., "Q4 3:42", "Halftime", "Final")
    $status_detail = $competition['status']['type']['shortDetail'] ?? '';

    // ---- Extract ESPN built-in odds (saves Odds API credits) ----
    $spread_home    = null;
    $spread_away    = null;
    $over_under     = null;
    $moneyline_home = null;
    $moneyline_away = null;
    $spread_details = '';  // e.g. "KC -3.5"

    $espn_odds = ! empty( $competition['odds'] ) ? $competition['odds'][0] : null;
    if ( $espn_odds ) {
        $spread_details = $espn_odds['details'] ?? '';
        $over_under     = isset( $espn_odds['overUnder'] ) ? floatval( $espn_odds['overUnder'] ) : null;
        $moneyline_home = isset( $espn_odds['homeTeamOdds']['moneyLine'] ) ? intval( $espn_odds['homeTeamOdds']['moneyLine'] ) : null;
        $moneyline_away = isset( $espn_odds['awayTeamOdds']['moneyLine'] ) ? intval( $espn_odds['awayTeamOdds']['moneyLine'] ) : null;

        // Parse "ABBR -X.X" → determine which team is the spread favourite
        // The number's sign belongs to the referenced team (negative = they give points = favourite)
        if ( kf_espn_is_pickem( $spread_details ) ) {
            $spread_home = 0.0;
            $spread_away = 0.0;
        // ⚠️ Match the abbreviation as "everything before the trailing number", not as a
        // character class. It used to be ([A-Z0-9]+), which silently rejected every ESPN
        // abbreviation containing punctuation — "TA&M -14.5" (Texas A&M) being the one that
        // surfaced it. The card still showed a spread, because the badge renders the raw
        // details string, but spread_home/spread_away stayed null: the week profile reported
        // "No odds", the colour coding fell back to neutral, and nothing numeric reached the
        // matchups row for players to see. College abbreviations carry &, -, . and ( ).
        } elseif ( preg_match( '/^(.+?)\s+([-+]?\d+(?:\.\d+)?)$/', trim( $spread_details ), $m ) ) {
            $fav_abbr    = strtoupper( $m[1] );
            $fav_spread  = floatval( $m[2] ); // already negative for the favourite
            $home_abbr_u = strtoupper( $home['team']['abbreviation'] ?? '' );
            $away_abbr_u = strtoupper( $away['team']['abbreviation'] ?? '' );
            // Name the favourite only on an exact abbreviation match, for either side. The
            // capture above accepts any leading text, so the old "not home, therefore away"
            // fallback would hand an unrecognised string's line to the wrong team — and that
            // line drives the favourite, Auto-fill and the picks export. No match leaves both
            // spreads null; the game card still shows ESPN's raw details string.
            if ( $home_abbr_u !== '' && $fav_abbr === $home_abbr_u ) {
                $spread_home = $fav_spread;   // negative → home is favourite
                $spread_away = -$fav_spread;
            } elseif ( $away_abbr_u !== '' && $fav_abbr === $away_abbr_u ) {
                $spread_away = $fav_spread;   // negative → away is favourite
                $spread_home = -$fav_spread;
            }
        }
    }

    return [
        'espn_game_id'   => $event['id'] ?? '',
        'sport'          => $sport,
        'home_team'      => $home['team']['displayName'] ?? '',
        'away_team'      => $away['team']['displayName'] ?? '',
        'home_abbr'      => $home['team']['abbreviation'] ?? '',
        'away_abbr'      => $away['team']['abbreviation'] ?? '',
        'home_short'     => $home['team']['shortDisplayName'] ?? ( $home['team']['name'] ?? '' ),
        'away_short'     => $away['team']['shortDisplayName'] ?? ( $away['team']['name'] ?? '' ),
        'home_score'     => isset( $home['score'] ) ? intval( $home['score'] ) : null,
        'away_score'     => isset( $away['score'] ) ? intval( $away['score'] ) : null,
        'game_datetime'  => $event['date'] ?? '',
        'game_status'    => $game_status,
        'status_detail'  => $status_detail,
        'venue'          => $competition['venue']['fullName'] ?? '',
        'broadcast'      => kf_extract_espn_broadcast( $competition ),
        'conference'     => kf_extract_espn_conference( $home, $sport ),
        'spread_home'    => $spread_home,
        'spread_away'    => $spread_away,
        'over_under'     => $over_under,
        'moneyline_home' => $moneyline_home,
        'moneyline_away' => $moneyline_away,
        'spread_details' => $spread_details,
    ];
}

/**
 * Extracts the broadcast network from an ESPN competition.
 */
function kf_extract_espn_broadcast( $competition ) {
    if ( ! empty( $competition['broadcasts'] ) ) {
        foreach ( $competition['broadcasts'] as $broadcast ) {
            if ( ! empty( $broadcast['names'] ) ) {
                return implode( ', ', $broadcast['names'] );
            }
        }
    }
    return '';
}

/**
 * Extracts conference info for college football teams.
 */
function kf_extract_espn_conference( $team_data, $sport ) {
    if ( $sport !== 'college-football' ) {
        return 'NFL';
    }
    // ESPN nests conference under team.groups or team.conferenceId
    return $team_data['team']['conferenceId'] ?? '';
}

/**
 * Fetches current scores for specific ESPN game IDs.
 * Used by the cron job to check scores for games we're tracking.
 *
 * @param string $sport     'nfl' or 'college-football'
 * @param array  $event_ids Array of ESPN event IDs to look up.
 * @return array Associative array keyed by ESPN event ID.
 */
function kf_espn_fetch_scores( $sport, $event_ids = [] ) {
    if ( empty( $event_ids ) ) {
        return [];
    }

    // ESPN scoreboard returns all games for the current week/day.
    // We fetch the full scoreboard and filter by our event IDs.
    // This is efficient because it's a single free call.
    // College needs groups=80 (all FBS) or ESPN returns only its own default selection, so
    // most of a league's games are missed here and fall through to one request per game.
    $scoreboard_params = ( $sport === 'college-football' ) ? [ 'groups' => 80 ] : [];
    $scoreboard = kf_espn_fetch_scoreboard( $sport, $scoreboard_params );
    if ( is_wp_error( $scoreboard ) ) {
        return [];
    }

    $scores = [];
    foreach ( $scoreboard as $game ) {
        if ( in_array( $game['espn_game_id'], $event_ids, true ) ) {
            $scores[ $game['espn_game_id'] ] = $game;
        }
    }

    // For games not found in current scoreboard (maybe from a different week),
    // try individual event lookups.
    $missing = array_diff( $event_ids, array_keys( $scores ) );
    foreach ( $missing as $event_id ) {
        $game = kf_espn_fetch_single_event( $sport, $event_id );
        if ( $game ) {
            $scores[ $event_id ] = $game;
        }
    }

    return $scores;
}

/**
 * Fetches a single ESPN event by ID.
 *
 * @param string $sport    'nfl' or 'college-football'
 * @param string $event_id ESPN event ID.
 * @return array|null Normalized game array or null.
 */
function kf_espn_fetch_single_event( $sport, $event_id ) {
    // ESPN event ids are numeric. Validate before interpolating: this value reaches an
    // outbound URL and a transient key, and it arrives from POST (link handler) and from the
    // matchups table (cron), where a crafted hidden field could have stored anything.
    $event_id = (string) $event_id;
    if ( ! preg_match( '/^[0-9]{1,20}$/', $event_id ) ) {
        error_log( 'Kerry Football: refusing non-numeric ESPN event id: ' . $event_id );
        return null;
    }

    $url = "https://site.api.espn.com/apis/site/v2/sports/football/{$sport}/summary?event={$event_id}";

    $cache_key = 'kf_espn_evt_' . $event_id;
    $cached    = get_transient( $cache_key );
    if ( $cached !== false ) {
        return $cached;
    }

    $response = kf_espn_remote_get( $url, 10 );
    if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
        return null;
    }

    $body = json_decode( wp_remote_retrieve_body( $response ), true );
    if ( empty( $body['header']['competitions'][0] ) ) {
        return null;
    }

    // Build a pseudo-event structure that kf_normalize_espn_event can process
    $competition = $body['header']['competitions'][0];
    $pseudo_event = [
        'id'           => $event_id,
        // ESPN's summary payload has no header.gameDate — the kickoff lives on the competition.
        // Reading the wrong key silently stored an empty game_datetime for every game linked
        // through the linker panel, which is why no kickoff times appeared.
        'date'         => $competition['date'] ?? ( $body['header']['gameDate'] ?? '' ),
        'competitions' => [ $competition ],
    ];

    $game = kf_normalize_espn_event( $pseudo_event, $sport );

    // Short cache (5 min) for individual lookups during live games
    if ( $game ) {
        set_transient( $cache_key, $game, 5 * MINUTE_IN_SECONDS );
    }

    return $game;
}

/**
 * Fetches the NFL season calendar (weeks list) from ESPN.
 *
 * @return array|WP_Error Array of week objects with number, label, startDate, endDate.
 */
function kf_espn_fetch_nfl_weeks() {
    $cache_key = 'kf_espn_nfl_weeks';
    $cached    = get_transient( $cache_key );
    if ( $cached !== false ) {
        return $cached;
    }

    $url      = 'https://site.api.espn.com/apis/site/v2/sports/football/nfl/scoreboard';
    $response = kf_espn_remote_get( $url, 10 );

    if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
        return is_wp_error( $response ) ? $response : new WP_Error( 'espn_error', 'Failed to fetch NFL weeks' );
    }

    $body = json_decode( wp_remote_retrieve_body( $response ), true );

    $weeks = [];
    if ( ! empty( $body['leagues'][0]['calendar'] ) ) {
        foreach ( $body['leagues'][0]['calendar'] as $season_type ) {
            $type_label = $season_type['label'] ?? 'Unknown';
            $type_value = $season_type['value'] ?? '2';
            if ( ! empty( $season_type['entries'] ) ) {
                foreach ( $season_type['entries'] as $entry ) {
                    $weeks[] = [
                        'label'       => $entry['label'] ?? '',
                        'detail'      => $entry['detail'] ?? '',
                        'value'       => $entry['value'] ?? '',
                        'startDate'   => $entry['startDate'] ?? '',
                        'endDate'     => $entry['endDate'] ?? '',
                        'season_type' => $type_value,
                        'type_label'  => $type_label,
                    ];
                }
            }
        }
    }

    set_transient( $cache_key, $weeks, 24 * HOUR_IN_SECONDS );
    return $weeks;
}


// ============================================================
// The Odds API Functions (500 free requests/month)
// ============================================================

/**
 * Gets the stored Odds API key.
 *
 * @return string|false API key or false if not configured.
 */
function kf_get_odds_api_key() {
    $key = get_option( 'kf_odds_api_key', '' );
    return ! empty( $key ) ? $key : false;
}

/**
 * Returns the number of Odds API credits remaining this month.
 *
 * @return int Credits remaining (out of 500).
 */
function kf_get_odds_credits_remaining() {
    global $wpdb;
    $month_key = gmdate( 'Y-m' );
    $used      = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COALESCE(SUM(credits_used), 0) FROM {$wpdb->prefix}api_usage WHERE api_name = 'odds_api' AND month_key = %s",
        $month_key
    ) );
    return max( 0, 500 - $used );
}

/**
 * Returns credits used this month.
 *
 * @return int Credits used.
 */
function kf_get_odds_credits_used() {
    return 500 - kf_get_odds_credits_remaining();
}

/**
 * Logs an API usage event to the tracking table.
 *
 * @param string $api_name  'odds_api' or 'espn'
 * @param string $endpoint  Brief label for the endpoint called.
 * @param int    $credits   Number of credits consumed (typically 1 per market per region).
 */
function kf_log_api_usage( $api_name, $endpoint, $credits = 1 ) {
    global $wpdb;
    $wpdb->insert( $wpdb->prefix . 'api_usage', [
        'api_name'     => sanitize_text_field( $api_name ),
        'endpoint'     => sanitize_text_field( $endpoint ),
        'credits_used' => intval( $credits ),
        'requested_at' => current_time( 'mysql', true ),
        'month_key'    => gmdate( 'Y-m' ),
    ] );
}

/**
 * Fetches odds from The Odds API for a given sport.
 *
 * @param string $sport 'nfl' or 'college-football'
 * @return array|WP_Error Array of odds data keyed by a team composite key, or WP_Error.
 */
function kf_odds_api_fetch_odds( $sport = 'nfl' ) {
    $api_key = kf_get_odds_api_key();
    if ( ! $api_key ) {
        return new WP_Error( 'no_api_key', 'The Odds API key is not configured.' );
    }

    // Check credits before making the call
    $remaining = kf_get_odds_credits_remaining();
    if ( $remaining < 3 ) {
        return new WP_Error( 'credits_exhausted', 'Not enough Odds API credits remaining this month.' );
    }

    $sport_key = $sport === 'nfl' ? 'americanfootball_nfl' : 'americanfootball_ncaaf';
    $bookmaker = get_option( 'kf_preferred_bookmaker', 'fanduel' );

    $url = add_query_arg( [
        'apiKey'       => sanitize_text_field( $api_key ),
        'regions'      => 'us',
        'markets'      => 'h2h,spreads,totals',
        'oddsFormat'   => 'american',
        'bookmakers'   => $bookmaker,
    ], "https://api.the-odds-api.com/v4/sports/{$sport_key}/odds/" );

    $response = wp_remote_get( $url, [ 'timeout' => 15 ] );

    if ( is_wp_error( $response ) ) {
        return $response;
    }

    $code = wp_remote_retrieve_response_code( $response );
    if ( $code !== 200 ) {
        return new WP_Error( 'odds_api_error', "Odds API returned status {$code}" );
    }

    // Log usage: 1 credit per market (h2h + spreads + totals = 3)
    kf_log_api_usage( 'odds_api', "odds/{$sport_key}", 3 );

    $events = json_decode( wp_remote_retrieve_body( $response ), true );
    if ( ! is_array( $events ) ) {
        return [];
    }

    // Normalize into a lookup keyed by home_team+away_team
    $odds_data = [];
    foreach ( $events as $event ) {
        $parsed = kf_parse_odds_event( $event );
        if ( $parsed ) {
            // Key by a normalized team name pair for cross-referencing with ESPN
            $key = kf_odds_lookup_key( $parsed['home_team'], $parsed['away_team'] );
            $odds_data[ $key ]                    = $parsed;
            $odds_data[ $event['id'] ?? '' ]      = $parsed; // Also key by Odds API event ID
        }
    }

    return $odds_data;
}

/**
 * Parses a single Odds API event into a standard odds array.
 *
 * @param array $event Raw Odds API event.
 * @return array|null
 */
function kf_parse_odds_event( $event ) {
    $home_team = $event['home_team'] ?? '';
    $away_team = $event['away_team'] ?? '';

    if ( empty( $home_team ) || empty( $away_team ) ) {
        return null;
    }

    $odds = [
        'odds_api_event_id' => $event['id'] ?? '',
        'home_team'         => $home_team,
        'away_team'         => $away_team,
        'commence_time'     => $event['commence_time'] ?? '',
        'spread_home'       => null,
        'spread_away'       => null,
        'moneyline_home'    => null,
        'moneyline_away'    => null,
        'over_under'        => null,
    ];

    // Extract from the first bookmaker
    if ( ! empty( $event['bookmakers'][0]['markets'] ) ) {
        foreach ( $event['bookmakers'][0]['markets'] as $market ) {
            $key = $market['key'] ?? '';
            $outcomes = $market['outcomes'] ?? [];

            if ( $key === 'h2h' ) {
                // Moneyline
                foreach ( $outcomes as $o ) {
                    if ( $o['name'] === $home_team ) {
                        $odds['moneyline_home'] = intval( $o['price'] ?? 0 );
                    } elseif ( $o['name'] === $away_team ) {
                        $odds['moneyline_away'] = intval( $o['price'] ?? 0 );
                    }
                }
            } elseif ( $key === 'spreads' ) {
                foreach ( $outcomes as $o ) {
                    if ( $o['name'] === $home_team ) {
                        $odds['spread_home'] = floatval( $o['point'] ?? 0 );
                    } elseif ( $o['name'] === $away_team ) {
                        $odds['spread_away'] = floatval( $o['point'] ?? 0 );
                    }
                }
            } elseif ( $key === 'totals' ) {
                foreach ( $outcomes as $o ) {
                    if ( ( $o['name'] ?? '' ) === 'Over' ) {
                        $odds['over_under'] = floatval( $o['point'] ?? 0 );
                        break;
                    }
                }
            }
        }
    }

    return $odds;
}

/**
 * Creates a lookup key for cross-referencing ESPN and Odds API teams.
 * Normalizes team names by lowering case and stripping common suffixes.
 *
 * @param string $home Home team name.
 * @param string $away Away team name.
 * @return string Lookup key.
 */
function kf_odds_lookup_key( $home, $away ) {
    return strtolower( trim( $home ) ) . '|' . strtolower( trim( $away ) );
}

/**
 * Cross-references ESPN games with Odds API data to attach odds to each game.
 *
 * @param array $espn_games Normalized ESPN game arrays.
 * @param array $odds_data  Odds data from kf_odds_api_fetch_odds().
 * @return array ESPN games with odds fields populated.
 */
function kf_match_espn_to_odds( $espn_games, $odds_data ) {
    if ( empty( $odds_data ) || is_wp_error( $odds_data ) ) {
        return $espn_games;
    }

    foreach ( $espn_games as &$game ) {
        // Try exact name match first
        $key = kf_odds_lookup_key( $game['home_team'], $game['away_team'] );
        $match = $odds_data[ $key ] ?? null;

        // If no match, try fuzzy match by checking if ESPN team name contains Odds API team name
        if ( ! $match ) {
            foreach ( $odds_data as $odds_key => $odds_event ) {
                if ( ! is_array( $odds_event ) || empty( $odds_event['home_team'] ) ) {
                    continue;
                }
                if (
                    kf_fuzzy_team_match( $game['home_team'], $odds_event['home_team'] ) &&
                    kf_fuzzy_team_match( $game['away_team'], $odds_event['away_team'] )
                ) {
                    $match = $odds_event;
                    break;
                }
            }
        }

        if ( $match ) {
            $game['odds_api_event_id'] = $match['odds_api_event_id'];
            $game['spread_home']       = $match['spread_home'];
            $game['spread_away']       = $match['spread_away'];
            $game['moneyline_home']    = $match['moneyline_home'];
            $game['moneyline_away']    = $match['moneyline_away'];
            $game['over_under']        = $match['over_under'];
        }
    }

    return $espn_games;
}

/**
 * Fuzzy team name matching. Checks if two team names refer to the same team.
 * E.g., "New York Giants" matches "NY Giants", "Pittsburgh Steelers" matches "Pittsburgh".
 *
 * @param string $name1 First team name (ESPN).
 * @param string $name2 Second team name (Odds API).
 * @return bool True if likely the same team.
 */
function kf_fuzzy_team_match( $name1, $name2 ) {
    $n1 = strtolower( trim( $name1 ) );
    $n2 = strtolower( trim( $name2 ) );

    if ( $n1 === $n2 ) {
        return true;
    }

    // Check if one contains the other
    if ( strpos( $n1, $n2 ) !== false || strpos( $n2, $n1 ) !== false ) {
        return true;
    }

    // Check if the last word (mascot) matches: "Pittsburgh Steelers" → "steelers"
    $words1 = explode( ' ', $n1 );
    $words2 = explode( ' ', $n2 );
    $last1  = end( $words1 );
    $last2  = end( $words2 );

    if ( $last1 === $last2 && strlen( $last1 ) > 3 ) {
        return true;
    }

    return false;
}

/**
 * Fetches scores from The Odds API (fallback if ESPN is unavailable).
 *
 * @param string $sport 'nfl' or 'college-football'
 * @return array|WP_Error
 */
function kf_odds_api_fetch_scores( $sport = 'nfl' ) {
    $api_key = kf_get_odds_api_key();
    if ( ! $api_key ) {
        return new WP_Error( 'no_api_key', 'The Odds API key is not configured.' );
    }

    $remaining = kf_get_odds_credits_remaining();
    if ( $remaining < 1 ) {
        return new WP_Error( 'credits_exhausted', 'No Odds API credits remaining.' );
    }

    $sport_key = $sport === 'nfl' ? 'americanfootball_nfl' : 'americanfootball_ncaaf';
    $url       = add_query_arg( [
        'apiKey'   => sanitize_text_field( $api_key ),
        'daysFrom' => 3,
    ], "https://api.the-odds-api.com/v4/sports/{$sport_key}/scores/" );

    $response = wp_remote_get( $url, [ 'timeout' => 15 ] );
    if ( is_wp_error( $response ) ) {
        return $response;
    }

    kf_log_api_usage( 'odds_api', "scores/{$sport_key}", 1 );

    $events = json_decode( wp_remote_retrieve_body( $response ), true );
    if ( ! is_array( $events ) ) {
        return [];
    }

    $scores = [];
    foreach ( $events as $event ) {
        $home_score = null;
        $away_score = null;
        if ( ! empty( $event['scores'] ) ) {
            foreach ( $event['scores'] as $score ) {
                if ( $score['name'] === ( $event['home_team'] ?? '' ) ) {
                    $home_score = intval( $score['score'] );
                } else {
                    $away_score = intval( $score['score'] );
                }
            }
        }
        $scores[ $event['id'] ?? '' ] = [
            'home_team'  => $event['home_team'] ?? '',
            'away_team'  => $event['away_team'] ?? '',
            'home_score' => $home_score,
            'away_score' => $away_score,
            'completed'  => ! empty( $event['completed'] ),
        ];
    }

    return $scores;
}

/**
 * Tests The Odds API key by making a lightweight sports list call.
 *
 * @return array [ 'success' => bool, 'message' => string ]
 */
function kf_test_odds_api_connection() {
    $api_key = kf_get_odds_api_key();
    if ( ! $api_key ) {
        return [ 'success' => false, 'message' => 'No API key configured.' ];
    }

    $url      = add_query_arg( [ 'apiKey' => sanitize_text_field( $api_key ) ], 'https://api.the-odds-api.com/v4/sports/' );
    $response = wp_remote_get( $url, [ 'timeout' => 10 ] );

    if ( is_wp_error( $response ) ) {
        return [ 'success' => false, 'message' => $response->get_error_message() ];
    }

    $code = wp_remote_retrieve_response_code( $response );
    if ( $code === 200 ) {
        // Read remaining requests from response headers
        $remaining = wp_remote_retrieve_header( $response, 'x-requests-remaining' );
        $used      = wp_remote_retrieve_header( $response, 'x-requests-used' );
        return [
            'success'   => true,
            'message'   => 'Connection successful!',
            'remaining' => $remaining,
            'used'      => $used,
        ];
    } elseif ( $code === 401 ) {
        return [ 'success' => false, 'message' => 'Invalid API key.' ];
    } else {
        return [ 'success' => false, 'message' => "API returned status {$code}." ];
    }
}


/**
 * Scores how well a stored team name matches one side of an ESPN game.
 *
 * Mirrors the client-side scorer in kf-game-browser.js so suggestions are ranked identically
 * whichever path produced them. 0 means no relation; higher is better.
 *
 * @param string $typed Stored team name, e.g. "DAL Cowboys".
 * @param array  $game  Normalized ESPN game from kf_normalize_espn_event().
 * @param string $side  'home' or 'away'.
 * @return int
 */
/**
 * True when two single words plausibly name the same thing: identical, or one a prefix of
 * the other. Prefix is what makes ok/oklahoma, wash/washington and st/state line up.
 *
 * @param string $a
 * @param string $b
 * @return bool
 */
function kf_espn_word_match( $a, $b ) {
    if ( $a === '' || $b === '' ) {
        return false;
    }
    if ( $a === $b ) {
        return true;
    }
    return ( strlen( $a ) < strlen( $b ) )
        ? ( strpos( $b, $a ) === 0 )
        : ( strpos( $a, $b ) === 0 );
}

function kf_espn_name_score( $typed, $game, $side ) {
    $normalize = static function ( $value ) {
        $value = strtolower( (string) $value );
        $value = preg_replace( '/[^a-z0-9 ]/', '', $value );
        return trim( preg_replace( '/\s+/', ' ', $value ) );
    };

    $needle = $normalize( $typed );
    if ( $needle === '' ) {
        return 0;
    }

    $candidates = array_filter( [
        $normalize( $game[ $side . '_abbr' ]  ?? '' ),
        $normalize( $game[ $side . '_short' ] ?? '' ),
        $normalize( $game[ $side . '_team' ]  ?? '' ),
    ] );

    foreach ( $candidates as $candidate ) {
        if ( $candidate === $needle ) {
            return 4;
        }
    }
    foreach ( $candidates as $candidate ) {
        if ( strpos( $candidate, $needle ) !== false || strpos( $needle, $candidate ) !== false ) {
            return 2;
        }
    }

    // Word-prefix match, the tier that rescues abbreviated names. ESPN writes "Oklahoma St"
    // and "Washington St" where the league sheet says "OK STATE" and "WASH STATE" — same
    // teams, no whole word in common, so every earlier tier scores zero. Here a word matches
    // when one is a prefix of the other (ok/oklahoma, wash/washington, state/st), and the
    // name matches only when EVERY word in the stored name finds a partner.
    $needle_words = array_filter( explode( ' ', $needle ) );
    foreach ( $candidates as $candidate ) {
        $candidate_words = array_filter( explode( ' ', $candidate ) );
        if ( empty( $candidate_words ) || empty( $needle_words ) ) {
            continue;
        }
        $all_matched = true;
        foreach ( $needle_words as $needle_word ) {
            $matched = false;
            foreach ( $candidate_words as $candidate_word ) {
                if ( kf_espn_word_match( $needle_word, $candidate_word ) ) {
                    $matched = true;
                    break;
                }
            }
            if ( ! $matched ) {
                $all_matched = false;
                break;
            }
        }
        if ( $all_matched ) {
            return 1;
        }
    }

    // Mascot match: "pittsburgh steelers" vs "steelers".
    $needle_parts = explode( ' ', $needle );
    $needle_last  = end( $needle_parts );
    foreach ( $candidates as $candidate ) {
        $parts = explode( ' ', $candidate );
        $last  = end( $parts );
        if ( $needle_last && $last && $needle_last === $last && strlen( $needle_last ) > 3 ) {
            return 1;
        }
    }

    return 0;
}


/**
 * Renders the "Link games to ESPN" panel.
 *
 * Shared by Week Setup and Enter Results. Enter Results is the important one: Manage Weeks
 * only offers an "Edit" link for DRAFT weeks, so once a week is published there is no
 * navigation to Week Setup at all — which is precisely when linking matters, because that is
 * when picks exist and the normal save path is (correctly) blocked.
 *
 * The panel drives kf_espn_link_suggestions / kf_espn_apply_link, which UPDATE an existing
 * matchup row in place. Nothing here submits a form or rewrites matchups.
 *
 * @param int $week_id     Week being worked on.
 * @param int $week_number League week number, used as the default ESPN week.
 * @return void
 */
function kf_render_espn_linker( $week_id, $week_number = 0 ) {
    $week_id     = intval( $week_id );
    $week_number = intval( $week_number );
    if ( $week_id <= 0 ) {
        return;
    }
    ?>
    <div id="kf-espn-linker" class="kf-card" style="margin-top:1.25em;"
         data-week-id="<?php echo esc_attr( $week_id ); ?>"
         data-espn-week="<?php echo esc_attr( $week_number ); ?>">
        <h3 style="margin-top:0;">&#128279; Link games to ESPN</h3>
        <p class="kf-form-note" style="margin-top:0;">
            Attaches each matchup to the real ESPN game so scores and results update by themselves.
            This only adds the link &mdash; it never changes team names, never renumbers matchups and
            never touches picks, so it is safe to use on a week that is already live.
        </p>
        <div style="display:flex;align-items:center;gap:0.75em;flex-wrap:wrap;">
            <button type="button" id="kf-linker-find" class="kf-button">Find ESPN matches</button>
            <label class="kf-form-note" style="margin:0;">ESPN week
                <input type="number" id="kf-linker-week" min="1" max="25"
                       value="<?php echo esc_attr( $week_number > 0 ? $week_number : 1 ); ?>"
                       style="width:64px;margin-left:4px;">
            </label>
            <span id="kf-linker-status" class="kf-form-note" style="margin:0;"></span>
        </div>
        <div id="kf-linker-rows" style="margin-top:0.9em;"></div>
    </div>
    <?php
}

/**
 * Whether matchups.status_detail exists yet.
 *
 * The column arrives with schema 1.3. Until that migration runs, writing to it makes the
 * whole $wpdb->update() fail — which would take SCORES down with it, not just the clock.
 * Score updating must never depend on a migration having completed, so every write of this
 * field is gated on this check. Cached per request; the answer cannot change mid-request.
 *
 * @return bool
 */
function kf_matchups_have_status_detail() {
    static $has_column = null;
    if ( $has_column !== null ) {
        return $has_column;
    }
    global $wpdb;
    $columns    = $wpdb->get_col( "DESCRIBE {$wpdb->prefix}matchups", 0 );
    $has_column = is_array( $columns ) && in_array( 'status_detail', $columns, true );
    return $has_column;
}

// No closing PHP tag to prevent whitespace issues.
