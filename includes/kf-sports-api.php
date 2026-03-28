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
 * Fetches the scoreboard (game schedule) from ESPN for a given sport and week/date range.
 *
 * @param string $sport   'nfl' or 'college-football'
 * @param array  $params  Optional. ['week' => int, 'seasontype' => int] for NFL,
 *                        ['dates' => 'YYYYMMDD'] for college football.
 * @return array|WP_Error Array of normalized game objects, or WP_Error on failure.
 */
function kf_espn_fetch_scoreboard( $sport = 'nfl', $params = [] ) {
    $base_url = "https://site.api.espn.com/apis/site/v2/sports/football/{$sport}/scoreboard";

    // Build query params
    $query = [];
    if ( $sport === 'nfl' ) {
        if ( ! empty( $params['week'] ) ) {
            $query['week'] = intval( $params['week'] );
        }
        if ( ! empty( $params['seasontype'] ) ) {
            $query['seasontype'] = intval( $params['seasontype'] );
        }
    } else {
        // College football: filter by date or week
        if ( ! empty( $params['dates'] ) ) {
            $query['dates'] = sanitize_text_field( $params['dates'] );
        }
        if ( ! empty( $params['week'] ) ) {
            $query['week'] = intval( $params['week'] );
        }
        if ( ! empty( $params['groups'] ) ) {
            $query['groups'] = intval( $params['groups'] ); // Conference group ID
        }
        $query['limit'] = 200; // College has many games
    }

    $url = add_query_arg( $query, $base_url );

    // Check transient cache (15-minute TTL)
    $cache_key = 'kf_espn_' . md5( $url );
    $cached    = get_transient( $cache_key );
    if ( $cached !== false ) {
        return $cached;
    }

    $response = wp_remote_get( $url, [
        'timeout' => 15,
        'headers' => [ 'Accept' => 'application/json' ],
    ] );

    if ( is_wp_error( $response ) ) {
        return $response;
    }

    $code = wp_remote_retrieve_response_code( $response );
    if ( $code !== 200 ) {
        return new WP_Error( 'espn_api_error', "ESPN API returned status {$code}" );
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

    return [
        'espn_game_id'   => $event['id'] ?? '',
        'sport'          => $sport,
        'home_team'      => $home['team']['displayName'] ?? '',
        'away_team'      => $away['team']['displayName'] ?? '',
        'home_abbr'      => $home['team']['abbreviation'] ?? '',
        'away_abbr'      => $away['team']['abbreviation'] ?? '',
        'home_score'     => isset( $home['score'] ) ? intval( $home['score'] ) : null,
        'away_score'     => isset( $away['score'] ) ? intval( $away['score'] ) : null,
        'game_datetime'  => $event['date'] ?? '',
        'game_status'    => $game_status,
        'status_detail'  => $status_detail,
        'venue'          => $competition['venue']['fullName'] ?? '',
        'broadcast'      => kf_extract_espn_broadcast( $competition ),
        'conference'     => kf_extract_espn_conference( $home, $sport ),
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
    $scoreboard = kf_espn_fetch_scoreboard( $sport );
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
    $url = "https://site.api.espn.com/apis/site/v2/sports/football/{$sport}/summary?event={$event_id}";

    $cache_key = 'kf_espn_evt_' . $event_id;
    $cached    = get_transient( $cache_key );
    if ( $cached !== false ) {
        return $cached;
    }

    $response = wp_remote_get( $url, [ 'timeout' => 10 ] );
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
        'date'         => $body['header']['gameDate'] ?? '',
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
    $response = wp_remote_get( $url, [ 'timeout' => 10 ] );

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
        'apiKey'       => $api_key,
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
        'apiKey'   => $api_key,
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

    $url      = add_query_arg( [ 'apiKey' => $api_key ], 'https://api.the-odds-api.com/v4/sports/' );
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

// No closing PHP tag to prevent whitespace issues.
