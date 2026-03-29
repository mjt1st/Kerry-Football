<?php
/**
 * Kerry Football — Sports API Settings Page
 *
 * Commissioner-only settings page for configuring The Odds API key,
 * preferred bookmaker, auto-score updates, and viewing API credit usage.
 *
 * @package Kerry_Football
 * @since   Sports API V1
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Shortcode: [kf_api_settings]
 * Renders the Sports API settings form for the commissioner.
 */
function kf_api_settings_shortcode() {
    if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
        return '<div class="kf-container"><p>You do not have access to this page.</p></div>';
    }

    global $wpdb;

    $saved_message = '';

    // --- Handle form submission ---
    if ( $_SERVER['REQUEST_METHOD'] === 'POST'
        && isset( $_POST['kf_api_settings_nonce'] )
        && wp_verify_nonce( $_POST['kf_api_settings_nonce'], 'kf_save_api_settings' )
    ) {
        // Save API key
        if ( isset( $_POST['kf_odds_api_key'] ) ) {
            update_option( 'kf_odds_api_key', sanitize_text_field( $_POST['kf_odds_api_key'] ) );
        }

        // Save bookmaker preference
        $allowed_bookmakers = [ 'fanduel', 'draftkings', 'betmgm', 'consensus' ];
        $bookmaker          = sanitize_text_field( $_POST['kf_preferred_bookmaker'] ?? 'fanduel' );
        if ( in_array( $bookmaker, $allowed_bookmakers, true ) ) {
            update_option( 'kf_preferred_bookmaker', $bookmaker );
        }

        // Save default sport
        $sport = sanitize_text_field( $_POST['kf_default_sport'] ?? 'nfl' );
        if ( in_array( $sport, [ 'nfl', 'college-football' ], true ) ) {
            update_option( 'kf_default_sport', $sport );
        }

        // Save auto-score toggle
        $auto_score = isset( $_POST['kf_auto_score_enabled'] ) ? '1' : '0';
        update_option( 'kf_auto_score_enabled', $auto_score );

        $saved_message = '<div class="notice notice-success is-dismissible" style="margin-bottom:1em;"><p>Settings saved successfully!</p></div>';
    }

    // --- Handle test connection ---
    $test_result = null;
    if ( $_SERVER['REQUEST_METHOD'] === 'POST'
        && isset( $_POST['kf_test_connection'] )
        && isset( $_POST['kf_api_settings_nonce'] )
        && wp_verify_nonce( $_POST['kf_api_settings_nonce'], 'kf_save_api_settings' )
    ) {
        if ( function_exists( 'kf_test_odds_api_connection' ) ) {
            $test_result = kf_test_odds_api_connection();
        }
    }

    // --- Load current settings ---
    $current_key       = get_option( 'kf_odds_api_key', '' );
    $current_bookmaker = get_option( 'kf_preferred_bookmaker', 'fanduel' );
    $current_sport     = get_option( 'kf_default_sport', 'nfl' );
    $auto_score_on     = get_option( 'kf_auto_score_enabled', '1' ) === '1';

    // --- Usage stats ---
    $credits_used      = function_exists( 'kf_get_odds_credits_used' ) ? kf_get_odds_credits_used() : 0;
    $credits_remaining = function_exists( 'kf_get_odds_credits_remaining' ) ? kf_get_odds_credits_remaining() : 500;
    $usage_pct         = round( ( $credits_used / 500 ) * 100 );

    ob_start(); ?>
    <div class="kf-container">
        <h1>Sports API Settings</h1>
        <a href="<?php echo esc_url( site_url( '/commissioner-dashboard/' ) ); ?>">&larr; Back to Commissioner Dashboard</a>

        <?php echo $saved_message; ?>

        <?php if ( $test_result ) : ?>
            <div class="notice <?php echo $test_result['success'] ? 'notice-success' : 'notice-error'; ?>" style="margin:1em 0;">
                <p><strong><?php echo $test_result['success'] ? '&#10003;' : '&#10007;'; ?></strong>
                    <?php echo esc_html( $test_result['message'] ); ?>
                    <?php if ( ! empty( $test_result['remaining'] ) ) : ?>
                        (Remaining this month: <?php echo esc_html( $test_result['remaining'] ); ?>)
                    <?php endif; ?>
                </p>
            </div>
        <?php endif; ?>

        <!-- ============================================================ -->
        <!-- API REFERENCE CARD                                          -->
        <!-- ============================================================ -->
        <div class="kf-api-info-card">
            <h3>&#9432; API Reference &amp; Limits</h3>
            <div class="kf-api-info-grid">

                <div class="kf-api-info-block">
                    <div class="kf-api-info-title">ESPN Scoreboard API &mdash; Primary Source</div>
                    <ul class="kf-api-info-list">
                        <li><strong>Cost:</strong> Free &mdash; no key required</li>
                        <li><strong>Rate limit:</strong> None published. Unofficial API; ESPN doesn&rsquo;t enforce strict limits for low-volume use.</li>
                        <li><strong>Caching:</strong> Results cached for <strong>15 minutes</strong>. Repeated fetches within that window hit the cache, not ESPN.</li>
                        <li><strong>Score updates:</strong> Cron runs every <strong>15 minutes</strong> during game windows. Scores may lag real-time by up to 15 min.</li>
                        <li><strong>Odds from ESPN:</strong> Spread, O/U, and moneyline are pulled directly from ESPN when the commissioner selects games in the Game Browser. Odds are stored at that moment and displayed to players with a retrieval date.</li>
                        <li><strong>Odds freshness:</strong> Odds reflect the lines at the time the commissioner fetched games &mdash; not live. Players can see the retrieval date on their picks form.</li>
                        <li><strong>Failure mode:</strong> If ESPN is down or slow, the game browser will show an error. You can always fall back to Manual Entry for that week.</li>
                    </ul>
                </div>

                <div class="kf-api-info-block">
                    <div class="kf-api-info-title">The Odds API &mdash; Optional</div>
                    <ul class="kf-api-info-list">
                        <li><strong>Cost:</strong> Free tier &mdash; <strong>500 requests / month</strong>. Resets on the 1st of each month.</li>
                        <li><strong>Currently:</strong> Not used. ESPN provides spread, O/U, and moneyline for all selected games at no cost.</li>
                        <li><strong>Future use:</strong> An API key here would enable bookmaker-specific lines (FanDuel, DraftKings, etc.) if richer odds data is ever needed.</li>
                        <li><strong>Credits per week:</strong> ~3 credits to fetch odds. At 17 weeks that&rsquo;s ~51 credits &mdash; well within the free tier if activated.</li>
                        <li><strong>Bottom line:</strong> Leave this blank. The plugin works fully without it &mdash; ESPN covers everything the league needs.</li>
                    </ul>
                </div>

            </div>
        </div>

        <form method="POST" class="kf-card" style="margin-top:1.5em;">
            <?php wp_nonce_field( 'kf_save_api_settings', 'kf_api_settings_nonce' ); ?>

            <fieldset>
                <legend>The Odds API</legend>
                <p class="kf-form-note" style="margin-bottom:1em;">
                    Provides bookmaker-specific odds lines (FanDuel, DraftKings, etc.) to supplement ESPN&rsquo;s built-in spread data.
                    <a href="https://the-odds-api.com/" target="_blank" rel="noopener">Get a free API key</a> (500 requests/month).
                </p>

                <div class="kf-form-group">
                    <label for="kf_odds_api_key">API Key</label>
                    <div style="display:flex;gap:8px;align-items:center;">
                        <input type="password" id="kf_odds_api_key" name="kf_odds_api_key"
                               value="<?php echo esc_attr( $current_key ); ?>"
                               style="flex:1;max-width:400px;" placeholder="Enter your Odds API key">
                        <button type="submit" name="kf_test_connection" value="1" class="kf-button" style="white-space:nowrap;">Test Connection</button>
                    </div>
                </div>

                <div class="kf-form-group">
                    <label for="kf_preferred_bookmaker">Preferred Bookmaker</label>
                    <select id="kf_preferred_bookmaker" name="kf_preferred_bookmaker" style="max-width:250px;">
                        <option value="fanduel" <?php selected( $current_bookmaker, 'fanduel' ); ?>>FanDuel</option>
                        <option value="draftkings" <?php selected( $current_bookmaker, 'draftkings' ); ?>>DraftKings</option>
                        <option value="betmgm" <?php selected( $current_bookmaker, 'betmgm' ); ?>>BetMGM</option>
                    </select>
                    <p class="kf-form-note">Which sportsbook's odds to display to players.</p>
                </div>
            </fieldset>

            <fieldset>
                <legend>General Settings</legend>

                <div class="kf-form-group">
                    <label for="kf_default_sport">Fallback Sport</label>
                    <select id="kf_default_sport" name="kf_default_sport" style="max-width:250px;">
                        <option value="nfl" <?php selected( $current_sport, 'nfl' ); ?>>NFL (Pro Football)</option>
                        <option value="college-football" <?php selected( $current_sport, 'college-football' ); ?>>College Football (NCAAF)</option>
                    </select>
                    <p class="kf-form-note">Used only if a season does not have a sport type set. Each season's sport type is configured in <strong>Create Season</strong> or <strong>Edit Season</strong>.</p>
                </div>

                <div class="kf-form-group">
                    <label style="font-weight:bold;cursor:pointer;">
                        <input type="checkbox" name="kf_auto_score_enabled" value="1" <?php checked( $auto_score_on ); ?> style="transform:scale(1.2);margin-right:8px;">
                        Enable Automatic Score Updates
                    </label>
                    <p class="kf-form-note">When enabled, scores are checked every 15 minutes for games added via the game browser. Results are auto-filled but the commissioner must still manually finalize each week.</p>
                </div>
            </fieldset>

            <fieldset>
                <legend>API Usage This Month</legend>
                <div style="margin-bottom:12px;">
                    <div style="display:flex;justify-content:space-between;margin-bottom:4px;">
                        <span><strong><?php echo intval( $credits_used ); ?></strong> credits used</span>
                        <span><strong><?php echo intval( $credits_remaining ); ?></strong> remaining</span>
                    </div>
                    <div class="kf-usage-bar" style="background:#e0e0e0;border-radius:8px;height:20px;overflow:hidden;">
                        <div style="background:<?php echo $usage_pct > 80 ? '#dc3545' : ( $usage_pct > 50 ? '#ffc107' : '#28a745' ); ?>;
                                    height:100%;width:<?php echo intval( $usage_pct ); ?>%;border-radius:8px;transition:width 0.3s;"></div>
                    </div>
                    <p class="kf-form-note" style="margin-top:4px;">
                        <?php echo intval( $usage_pct ); ?>% of 500 monthly credits used.
                        <?php if ( $usage_pct > 80 ) : ?>
                            <strong style="color:#dc3545;">Credits are running low!</strong>
                        <?php endif; ?>
                    </p>
                </div>
            </fieldset>

            <div class="kf-form-actions">
                <button type="submit" class="kf-button kf-button-action">Save Settings</button>
            </div>
        </form>
    </div>
    <?php
    return ob_get_clean();
}
