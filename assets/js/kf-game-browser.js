/**
 * Kerry Football — Game Browser (v2)
 *
 * Redesigned game browser with:
 *  - Game cards with spread badges and color coding
 *  - Sort by kickoff / biggest spread / closest games / O/U
 *  - NFL division filter (client-side, no re-fetch)
 *  - College conference filter (server-side, triggers re-fetch)
 *  - Spread range filter
 *  - Team abbreviation dictionary (abbreviated names stored in DB)
 *  - Quick stats summary after fetch
 *
 * @package Kerry_Football
 */

(function () {
    'use strict';

    // =========================================================================
    // TEAM ABBREVIATION DICTIONARY
    // Stored names use abbreviated forms to keep pick sheets narrow.
    // Format for NFL: "ABBR Nickname" e.g. "KC Chiefs"
    // Format for college: shortened school name
    // =========================================================================
    var ABBREV = {
        // NFL
        'Arizona Cardinals':        'ARI Cardinals',
        'Atlanta Falcons':          'ATL Falcons',
        'Baltimore Ravens':         'BAL Ravens',
        'Buffalo Bills':            'BUF Bills',
        'Carolina Panthers':        'CAR Panthers',
        'Chicago Bears':            'CHI Bears',
        'Cincinnati Bengals':       'CIN Bengals',
        'Cleveland Browns':         'CLE Browns',
        'Dallas Cowboys':           'DAL Cowboys',
        'Denver Broncos':           'DEN Broncos',
        'Detroit Lions':            'DET Lions',
        'Green Bay Packers':        'GB Packers',
        'Houston Texans':           'HOU Texans',
        'Indianapolis Colts':       'IND Colts',
        'Jacksonville Jaguars':     'JAX Jaguars',
        'Kansas City Chiefs':       'KC Chiefs',
        'Las Vegas Raiders':        'LV Raiders',
        'Los Angeles Chargers':     'LAC Chargers',
        'Los Angeles Rams':         'LAR Rams',
        'Miami Dolphins':           'MIA Dolphins',
        'Minnesota Vikings':        'MIN Vikings',
        'New England Patriots':     'NE Patriots',
        'New Orleans Saints':       'NO Saints',
        'New York Giants':          'NYG Giants',
        'New York Jets':            'NYJ Jets',
        'Philadelphia Eagles':      'PHI Eagles',
        'Pittsburgh Steelers':      'PIT Steelers',
        'San Francisco 49ers':      'SF 49ers',
        'Seattle Seahawks':         'SEA Seahawks',
        'Tampa Bay Buccaneers':     'TB Bucs',
        'Tennessee Titans':         'TEN Titans',
        'Washington Commanders':    'WAS Commanders',
        // College — common long names to shorter form
        'Alabama Crimson Tide':         'Alabama',
        'Georgia Bulldogs':             'Georgia',
        'Ohio State Buckeyes':          'Ohio State',
        'Michigan Wolverines':          'Michigan',
        'Clemson Tigers':               'Clemson',
        'Oklahoma Sooners':             'Oklahoma',
        'Texas Longhorns':              'Texas',
        'LSU Tigers':                   'LSU',
        'Penn State Nittany Lions':     'Penn State',
        'Notre Dame Fighting Irish':    'Notre Dame',
        'Florida State Seminoles':      'Florida State',
        'USC Trojans':                  'USC',
        'Oregon Ducks':                 'Oregon',
        'Texas A&M Aggies':             'Texas A&M',
        'Tennessee Volunteers':         'Tennessee',
        'Auburn Tigers':                'Auburn',
        'Arkansas Razorbacks':          'Arkansas',
        'Ole Miss Rebels':              'Ole Miss',
        'Mississippi State Bulldogs':   'Miss State',
        'Kentucky Wildcats':            'Kentucky',
        'Vanderbilt Commodores':        'Vanderbilt',
        'Missouri Tigers':              'Missouri',
        'South Carolina Gamecocks':     'South Carolina',
        'Iowa Hawkeyes':                'Iowa',
        'Wisconsin Badgers':            'Wisconsin',
        'Minnesota Golden Gophers':     'Minnesota',
        'Illinois Fighting Illini':     'Illinois',
        'Northwestern Wildcats':        'Northwestern',
        'Purdue Boilermakers':          'Purdue',
        'Indiana Hoosiers':             'Indiana',
        'Maryland Terrapins':           'Maryland',
        'Rutgers Scarlet Knights':      'Rutgers',
        'Nebraska Cornhuskers':         'Nebraska',
        'Iowa State Cyclones':          'Iowa State',
        'Kansas State Wildcats':        'K-State',
        'Baylor Bears':                 'Baylor',
        'TCU Horned Frogs':             'TCU',
        'Oklahoma State Cowboys':       'Oklahoma St',
        'West Virginia Mountaineers':   'West Virginia',
        'Cincinnati Bearcats':          'Cincinnati',
        'Houston Cougars':              'Houston',
        'UCF Knights':                  'UCF',
        'Miami Hurricanes':             'Miami (FL)',
        'North Carolina Tar Heels':     'North Carolina',
        'NC State Wolfpack':            'NC State',
        'Virginia Tech Hokies':         'Virginia Tech',
        'Pittsburgh Panthers':          'Pittsburgh',
        'Duke Blue Devils':             'Duke',
        'Wake Forest Demon Deacons':    'Wake Forest',
        'Boston College Eagles':        'Boston College',
        'Syracuse Orange':              'Syracuse',
        'Louisville Cardinals':         'Louisville',
        'Georgia Tech Yellow Jackets':  'Georgia Tech',
        'Washington Huskies':           'Washington',
        'UCLA Bruins':                  'UCLA',
        'Utah Utes':                    'Utah',
        'Colorado Buffaloes':           'Colorado',
        'Arizona Wildcats':             'Arizona',
        'Arizona State Sun Devils':     'Arizona State',
        'Florida Gators':               'Florida',
        'Michigan State Spartans':      'Michigan State',
        'SMU Mustangs':                 'SMU',
        'Army Black Knights':           'Army',
        'Navy Midshipmen':              'Navy',
        'Air Force Falcons':            'Air Force',
        'Liberty Flames':               'Liberty',
        'BYU Cougars':                  'BYU',
        'Boise State Broncos':          'Boise State',
        'Fresno State Bulldogs':        'Fresno State',
    };

    function abbrevTeam(fullName) {
        return ABBREV[fullName] || fullName;
    }

    // =========================================================================
    // NFL DIVISION MAP (client-side filter — no re-fetch needed)
    // =========================================================================
    var NFL_DIVISIONS = {
        'afc-east':  ['BUF', 'MIA', 'NE',  'NYJ'],
        'afc-north': ['BAL', 'CIN', 'CLE', 'PIT'],
        'afc-south': ['HOU', 'IND', 'JAX', 'TEN'],
        'afc-west':  ['DEN', 'KC',  'LV',  'LAC'],
        'nfc-east':  ['DAL', 'NYG', 'PHI', 'WAS'],
        'nfc-north': ['CHI', 'DET', 'GB',  'MIN'],
        'nfc-south': ['ATL', 'CAR', 'NO',  'TB'],
        'nfc-west':  ['ARI', 'LAR', 'SEA', 'SF'],
    };

    // =========================================================================
    // DOM READY
    // =========================================================================
    document.addEventListener('DOMContentLoaded', function () {
        var browserPanel  = document.getElementById('kf-game-browser');
        var modeToggle    = document.querySelectorAll('.kf-mode-toggle-btn');
        var fetchBtn      = document.getElementById('kf-fetch-games-btn');
        var gamesList     = document.getElementById('kf-games-list');
        var addBtn        = document.getElementById('kf-add-selected-btn');
        var sportSelect   = document.getElementById('kf-sport-select');
        var weekSelect    = document.getElementById('kf-week-select');
        var confFilter    = document.getElementById('kf-conference-filter');
        var confGroup     = document.getElementById('kf-conference-group');
        var divFilter     = document.getElementById('kf-division-filter');
        var divGroup      = document.getElementById('kf-division-group');
        var sortSelect    = document.getElementById('kf-sort-select');
        var spreadFilter  = document.getElementById('kf-spread-filter');
        var sortFilterBar = document.getElementById('kf-sort-filter-bar');
        var selectedCount = document.getElementById('kf-selected-count');
        var statusMsg     = document.getElementById('kf-browser-status');
        var gameStats     = document.getElementById('kf-game-stats');

        if (!browserPanel) return;

        var fetchedGames    = [];
        var displayedTotal  = 0;  // count of games currently shown (after filters)

        // ---- Mode Toggle ----
        modeToggle.forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                setMode(this.getAttribute('data-mode'));
            });
        });

        function setMode(mode) {
            modeToggle.forEach(function (btn) {
                btn.classList.toggle('kf-mode-active', btn.getAttribute('data-mode') === mode);
            });
            browserPanel.style.display = mode === 'api' ? 'block' : 'none';
            if (mode === 'manual') {
                gamesList.innerHTML = '';
                fetchedGames = [];
                if (sortFilterBar) sortFilterBar.style.display = 'none';
                updateSelectedCount();
            }
        }

        // ---- Sport Change: swap division vs conference filter ----
        function updateSportControls() {
            var isCollege = sportSelect && sportSelect.value === 'college-football';
            if (confGroup) confGroup.style.display = isCollege ? 'block' : 'none';
            if (divGroup)  divGroup.style.display  = isCollege ? 'none'  : 'block';
            gamesList.innerHTML = '';
            fetchedGames = [];
            if (sortFilterBar) sortFilterBar.style.display = 'none';
            updateSelectedCount();
        }
        if (sportSelect) {
            sportSelect.addEventListener('change', updateSportControls);
            updateSportControls();
        }

        // ---- Division / sort / spread filters: re-render existing results ----
        if (divFilter)   divFilter.addEventListener('change',   function () { if (fetchedGames.length) renderGames(getFilteredSorted()); });
        if (sortSelect)  sortSelect.addEventListener('change',  function () { if (fetchedGames.length) renderGames(getFilteredSorted()); });
        if (spreadFilter) spreadFilter.addEventListener('change', function () { if (fetchedGames.length) renderGames(getFilteredSorted()); });

        // ---- Fetch Games ----
        if (fetchBtn) {
            fetchBtn.addEventListener('click', function (e) {
                e.preventDefault();
                fetchGames();
            });
        }

        function fetchGames() {
            var sport = sportSelect ? sportSelect.value : 'nfl';
            var week  = weekSelect  ? weekSelect.value  : '';
            if (!week) { showStatus('Please select a week.', 'warning'); return; }

            showStatus('Fetching games from ESPN\u2026', 'info');
            fetchBtn.disabled = true;
            gamesList.innerHTML = '';
            fetchedGames = [];
            if (sortFilterBar) sortFilterBar.style.display = 'none';

            var fd = new FormData();
            fd.append('action', 'kf_fetch_games');
            fd.append('nonce',  kf_ajax_data.nonce);
            fd.append('sport',  sport);
            fd.append('week',   week);
            if (confFilter && sport === 'college-football' && confFilter.value) {
                fd.append('conference', confFilter.value);
            }

            fetch(kf_ajax_data.ajax_url, { method: 'POST', body: fd })
                .then(function (r) { return r.json(); })
                .then(function (resp) {
                    fetchBtn.disabled = false;
                    if (resp.success && resp.data && resp.data.games) {
                        fetchedGames = resp.data.games;
                        if (sortFilterBar) sortFilterBar.style.display = 'block';
                        renderGames(getFilteredSorted());
                        showStatus(fetchedGames.length + ' game(s) loaded.', 'success');
                    } else {
                        var msg = (resp.data && resp.data.message) ? resp.data.message : 'No games found.';
                        showStatus(msg, 'warning');
                    }
                })
                .catch(function (err) {
                    fetchBtn.disabled = false;
                    showStatus('Error fetching games. Please try again.', 'error');
                    console.error('KF Game Browser:', err);
                });
        }

        // ---- Filter + Sort pipeline ----
        function getFilteredSorted() {
            var sport   = sportSelect   ? sportSelect.value   : 'nfl';
            var divVal  = divFilter     ? divFilter.value     : '';
            var sfVal   = spreadFilter  ? spreadFilter.value  : '';
            var sortVal = sortSelect    ? sortSelect.value    : 'kickoff';

            var games = fetchedGames.slice();

            // NFL division filter (client-side)
            if (sport === 'nfl' && divVal && NFL_DIVISIONS[divVal]) {
                var allowed = NFL_DIVISIONS[divVal];
                games = games.filter(function (g) {
                    return allowed.indexOf(g.home_abbr) !== -1 || allowed.indexOf(g.away_abbr) !== -1;
                });
            }

            // Spread range filter
            if (sfVal) {
                games = games.filter(function (g) {
                    var abs = (g.spread_home !== null && g.spread_home !== undefined)
                              ? Math.abs(parseFloat(g.spread_home)) : null;
                    if (sfVal === 'has-odds')  return abs !== null;
                    if (abs === null)           return false;
                    if (sfVal === 'close')      return abs <= 3.5;
                    if (sfVal === 'moderate')   return abs > 3.5 && abs < 10;
                    if (sfVal === 'big')        return abs >= 10;
                    return true;
                });
            }

            // Sort
            games.sort(function (a, b) {
                var aAbs  = (a.spread_home !== null && a.spread_home !== undefined) ? Math.abs(parseFloat(a.spread_home)) : null;
                var bAbs  = (b.spread_home !== null && b.spread_home !== undefined) ? Math.abs(parseFloat(b.spread_home)) : null;
                var aOU   = a.over_under ? parseFloat(a.over_under) : 0;
                var bOU   = b.over_under ? parseFloat(b.over_under) : 0;
                var aTime = a.game_datetime ? new Date(a.game_datetime).getTime() : 0;
                var bTime = b.game_datetime ? new Date(b.game_datetime).getTime() : 0;

                if (sortVal === 'kickoff')         return aTime - bTime;
                if (sortVal === 'spread-biggest')  { if (aAbs === null && bAbs === null) return aTime - bTime; if (aAbs === null) return 1; if (bAbs === null) return -1; return bAbs - aAbs; }
                if (sortVal === 'spread-closest')  { if (aAbs === null && bAbs === null) return aTime - bTime; if (aAbs === null) return 1; if (bAbs === null) return -1; return aAbs - bAbs; }
                if (sortVal === 'over-under')       return bOU - aOU;
                return aTime - bTime;
            });

            return games;
        }

        // ---- Render Game Cards ----
        function renderGames(games) {
            gamesList.innerHTML = '';
            updateGameStats(games);

            displayedTotal = games ? games.length : 0;

            if (!games || games.length === 0) {
                gamesList.innerHTML = '<p class="kf-form-note" style="padding:1em 0;">No games match the current filters.</p>';
                updateSelectedCount();
                return;
            }

            // Group by date
            var grouped = {}, groupOrder = [];
            games.forEach(function (g) {
                var dk = formatDateKey(g.game_datetime);
                if (!grouped[dk]) { grouped[dk] = []; groupOrder.push(dk); }
                grouped[dk].push(g);
            });

            groupOrder.forEach(function (dk) {
                var h = document.createElement('h4');
                h.className = 'kf-game-date-header';
                h.textContent = dk;
                gamesList.appendChild(h);
                grouped[dk].forEach(function (g) { gamesList.appendChild(buildGameCard(g)); });
            });

            updateSelectedCount();
        }

        function buildGameCard(game) {
            var idx       = fetchedGames.indexOf(game);
            var sh        = (game.spread_home !== null && game.spread_home !== undefined) ? parseFloat(game.spread_home) : null;
            var spreadAbs = sh !== null ? Math.abs(sh) : null;

            // Which team is favoured?
            var favAbbr = null;
            if (sh !== null) {
                if      (sh < 0) favAbbr = game.home_abbr;
                else if (sh > 0) favAbbr = game.away_abbr;
            }

            // Spread badge label
            var spreadLabel = '—';
            if (game.spread_details && game.spread_details !== '') {
                spreadLabel = game.spread_details;
            } else if (sh !== null) {
                spreadLabel = sh === 0 ? 'PK' : (favAbbr || '?') + ' ' + (sh < 0 ? sh : '+' + sh);
            }

            // Badge colour class
            var badgeClass = 'kf-spread-none';
            if (spreadAbs !== null) {
                if      (spreadAbs === 0)    badgeClass = 'kf-spread-pk';
                else if (spreadAbs <= 3)     badgeClass = 'kf-spread-close';
                else if (spreadAbs <= 6.5)   badgeClass = 'kf-spread-moderate';
                else if (spreadAbs <= 13.5)  badgeClass = 'kf-spread-big';
                else                          badgeClass = 'kf-spread-blowout';
            }

            // Moneyline string
            var mlLabel = '';
            if (game.moneyline_home !== null && game.moneyline_away !== null &&
                game.moneyline_home !== undefined && game.moneyline_away !== undefined) {
                var mlH = parseInt(game.moneyline_home, 10);
                var mlA = parseInt(game.moneyline_away, 10);
                mlLabel = game.away_abbr + ' ' + (mlA > 0 ? '+' : '') + mlA +
                          ' / ' + game.home_abbr + ' ' + (mlH > 0 ? '+' : '') + mlH;
            }

            // Live / final status badge
            var statusHtml = '';
            if (game.game_status && game.game_status !== 'scheduled') {
                var sc = game.game_status === 'final' ? 'kf-gb-status-final' : 'kf-gb-status-live';
                statusHtml = '<span class="kf-gb-status ' + sc + '">' + escHtml(game.status_detail || game.game_status) + '</span>';
            }

            // Use inline styles for layout-critical properties so WordPress theme CSS can't break them
            var card = document.createElement('div');
            card.className = 'kf-game-card';
            card.style.cssText = 'display:block!important;border:1px solid #e5e7eb;border-radius:8px;' +
                                  'padding:10px 14px;margin-bottom:8px;cursor:pointer;background:#fff;' +
                                  'transition:border-color 0.15s,background 0.15s;';

            // Click anywhere on the card (except the checkbox itself) toggles selection
            card.addEventListener('click', function (e) {
                if (e.target.type !== 'checkbox') {
                    var cb = card.querySelector('.kf-game-checkbox');
                    if (cb) { cb.checked = !cb.checked; cb.dispatchEvent(new Event('change')); }
                }
            });

            // Spread badge inline colours (fallback in case CSS classes aren't loaded)
            var badgeStyle = 'margin-left:auto;font-size:0.78em;font-weight:700;padding:3px 10px;border-radius:99px;white-space:nowrap;flex-shrink:0;';
            if      (badgeClass === 'kf-spread-close')    badgeStyle += 'background:#dcfce7;color:#166534;';
            else if (badgeClass === 'kf-spread-moderate') badgeStyle += 'background:#dbeafe;color:#1e40af;';
            else if (badgeClass === 'kf-spread-big')      badgeStyle += 'background:#fef3c7;color:#92400e;';
            else if (badgeClass === 'kf-spread-blowout')  badgeStyle += 'background:#fee2e2;color:#991b1b;';
            else                                           badgeStyle += 'background:#f3f4f6;color:#9ca3af;';

            card.innerHTML =
                // Header: checkbox · time · network · live badge · spread badge
                '<div class="kf-game-card-header" style="display:flex!important;align-items:center;gap:8px;margin-bottom:8px;flex-wrap:nowrap;">' +
                    '<input type="checkbox" class="kf-game-checkbox" data-game-index="' + idx + '" style="flex-shrink:0;cursor:pointer;transform:scale(1.2);accent-color:#2563eb;">' +
                    '<span class="kf-game-time" style="font-size:0.82em;color:#6b7280;white-space:nowrap;flex-shrink:0;">' + formatTime(game.game_datetime) + '</span>' +
                    (game.broadcast ? '<span class="kf-game-broadcast" style="font-size:0.76em;color:#9ca3af;font-style:italic;white-space:nowrap;">' + escHtml(game.broadcast) + '</span>' : '') +
                    statusHtml +
                    '<span class="kf-spread-badge ' + badgeClass + '" style="' + badgeStyle + '">' + escHtml(spreadLabel) + '</span>' +
                '</div>' +
                // Teams: ABBR Name  @  ABBR Name
                '<div class="kf-game-card-teams" style="display:flex!important;align-items:center;gap:6px;font-size:0.95em;overflow:hidden;">' +
                    '<span style="display:flex;align-items:baseline;gap:4px;flex:1;min-width:0;">' +
                        '<strong style="font-weight:700;color:#111;white-space:nowrap;">' + escHtml(game.away_abbr || '') + '</strong>' +
                        '<span style="color:#374151;font-size:0.88em;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' + escHtml(game.away_short || game.away_team) + '</span>' +
                    '</span>' +
                    '<span style="color:#9ca3af;font-weight:700;font-size:0.8em;flex-shrink:0;">@</span>' +
                    '<span style="display:flex;align-items:baseline;gap:4px;flex:1;min-width:0;">' +
                        '<strong style="font-weight:700;color:#111;white-space:nowrap;">' + escHtml(game.home_abbr || '') + '</strong>' +
                        '<span style="color:#374151;font-size:0.88em;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' + escHtml(game.home_short || game.home_team) + '</span>' +
                    '</span>' +
                    ((game.game_status === 'final' || game.game_status === 'in_progress')
                        ? '<span style="font-weight:700;color:#1d4ed8;font-size:0.88em;white-space:nowrap;flex-shrink:0;">' + (game.away_score || 0) + '\u2013' + (game.home_score || 0) + '</span>'
                        : '') +
                '</div>' +
                // Odds row (O/U + ML)
                ((game.over_under || mlLabel)
                    ? '<div class="kf-game-card-odds" style="display:flex!important;flex-wrap:wrap;gap:1em;margin-top:6px;font-size:0.78em;color:#6b7280;border-top:1px solid #f3f4f6;padding-top:5px;">' +
                          (game.over_under ? '<span>O/U <strong style="color:#374151;">' + game.over_under + '</strong></span>' : '') +
                          (mlLabel ? '<span style="color:#9ca3af;">ML: ' + escHtml(mlLabel) + '</span>' : '') +
                      '</div>'
                    : '');

            var cb = card.querySelector('.kf-game-checkbox');
            if (cb) {
                cb.addEventListener('change', function () {
                    card.classList.toggle('kf-card-selected', cb.checked);
                    updateSelectedCount();
                });
            }
            return card;
        }

        // ---- Quick stats line ----
        function updateGameStats(games) {
            if (!gameStats) return;
            if (!games || games.length === 0) { gameStats.textContent = ''; return; }
            var withOdds = games.filter(function (g) { return g.spread_home !== null && g.spread_home !== undefined; }).length;
            var close    = games.filter(function (g) { return g.spread_home !== null && Math.abs(parseFloat(g.spread_home)) <= 3.5; }).length;
            var blowouts = games.filter(function (g) { return g.spread_home !== null && Math.abs(parseFloat(g.spread_home)) >= 10; }).length;
            var parts    = [games.length + ' games'];
            if (withOdds > 0) { parts.push(close + ' close (\u22643.5)'); parts.push(blowouts + ' blowout (10+)'); }
            gameStats.textContent = parts.join(' \u00B7 ');
        }

        // ---- Add Selected to Form ----
        if (addBtn) {
            addBtn.addEventListener('click', function (e) { e.preventDefault(); addSelectedGames(); });
        }

        function addSelectedGames() {
            var checkboxes = gamesList.querySelectorAll('.kf-game-checkbox:checked');
            if (!checkboxes.length) { showStatus('Please select at least one game.', 'warning'); return; }

            var container = document.getElementById('matchups-container');
            var countInp  = document.getElementById('kf_matchup_count');

            // Clear only if no existing data
            var hasData = false;
            container.querySelectorAll('.matchup-fieldset input[type="text"]').forEach(function (inp) {
                if (inp.value.trim()) hasData = true;
            });
            if (!hasData) container.innerHTML = '';

            var startIdx = container.querySelectorAll('.matchup-fieldset').length;

            checkboxes.forEach(function (cb, i) {
                var game = fetchedGames[parseInt(cb.getAttribute('data-game-index'), 10)];
                if (!game) return;

                var storedAway = abbrevTeam(game.away_team);
                var storedHome = abbrevTeam(game.home_team);
                var idx        = startIdx + i;

                var metaParts = [formatGameDate(game.game_datetime) + ' ' + formatTime(game.game_datetime)];
                if (game.spread_details) metaParts.push('Spread: ' + game.spread_details);
                if (game.over_under)     metaParts.push('O/U: ' + game.over_under);

                var fs = document.createElement('fieldset');
                fs.className = 'matchup-fieldset';
                fs.style.cssText = 'margin-bottom:16px;padding:12px;border:1px solid #ccc;border-radius:4px;';
                fs.innerHTML =
                    '<legend>Matchup ' + (idx + 1) + ' <span class="kf-api-badge">ESPN</span></legend>' +
                    '<div class="kf-form-group"><label>Away Team: <input type="text" name="team_b[]" value="' + escAttr(storedAway) + '" readonly class="kf-api-locked"></label></div>' +
                    '<div class="kf-form-group"><label>Home Team: <input type="text" name="team_a[]" value="' + escAttr(storedHome) + '" readonly class="kf-api-locked"></label></div>' +
                    '<div class="kf-form-group"><label><input type="radio" name="tiebreaker_marker" value="' + idx + '" ' + (idx === 0 ? 'checked' : '') + ' required> Mark as Tiebreaker</label></div>' +
                    '<input type="hidden" name="espn_game_id[]"     value="' + escAttr(game.espn_game_id    || '') + '">' +
                    '<input type="hidden" name="game_datetime[]"     value="' + escAttr(game.game_datetime   || '') + '">' +
                    '<input type="hidden" name="odds_api_event_id[]" value="' + escAttr(game.odds_api_event_id || '') + '">' +
                    '<input type="hidden" name="spread_home[]"       value="' + escAttr(game.spread_home !== null && game.spread_home !== undefined ? game.spread_home : '') + '">' +
                    '<input type="hidden" name="spread_away[]"       value="' + escAttr(game.spread_away !== null && game.spread_away !== undefined ? game.spread_away : '') + '">' +
                    '<input type="hidden" name="moneyline_home[]"    value="' + escAttr(game.moneyline_home  || '') + '">' +
                    '<input type="hidden" name="moneyline_away[]"    value="' + escAttr(game.moneyline_away  || '') + '">' +
                    '<input type="hidden" name="over_under[]"        value="' + escAttr(game.over_under      || '') + '">' +
                    '<div class="kf-game-meta-display">' + metaParts.join(' &nbsp;|&nbsp; ') + '</div>';

                container.appendChild(fs);
            });

            var total = container.querySelectorAll('.matchup-fieldset').length;
            if (countInp) { countInp.value = total; countInp.dispatchEvent(new Event('change')); }

            showStatus(checkboxes.length + ' game(s) added to week setup.', 'success');
            checkboxes.forEach(function (cb) { cb.checked = false; });
            updateSelectedCount();
        }

        // ---- Helpers ----
        function updateSelectedCount() {
            var n    = gamesList.querySelectorAll('.kf-game-checkbox:checked').length;
            var text = n + ' of ' + displayedTotal + ' games selected';
            // Update all counter elements (top bar + bottom footer)
            document.querySelectorAll('.kf-selected-count-text').forEach(function (el) {
                el.textContent = text;
            });
            if (addBtn) addBtn.disabled = n === 0;
        }

        function showStatus(msg, type) {
            if (!statusMsg) return;
            statusMsg.textContent = msg;
            statusMsg.className   = 'kf-browser-status kf-status-' + type;
            statusMsg.style.display = 'block';
        }

        function formatDateKey(s) {
            if (!s) return 'TBD';
            try { return new Date(s).toLocaleDateString('en-US', { weekday: 'long', month: 'short', day: 'numeric' }); }
            catch (e) { return s; }
        }
        function formatGameDate(s) {
            if (!s) return '';
            try { return new Date(s).toLocaleDateString('en-US', { month: 'short', day: 'numeric' }); }
            catch (e) { return ''; }
        }
        function formatTime(s) {
            if (!s) return 'TBD';
            try { return new Date(s).toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' }); }
            catch (e) { return ''; }
        }
        function escHtml(str) {
            if (str === null || str === undefined) return '';
            var d = document.createElement('div'); d.textContent = String(str); return d.innerHTML;
        }
        function escAttr(str) {
            if (str === null || str === undefined) return '';
            return String(str).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/'/g,'&#39;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
        }
    });
})();
