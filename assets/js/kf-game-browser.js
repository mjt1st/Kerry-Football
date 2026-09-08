/**
 * Kerry Football — Game Browser (v3)
 *
 * Redesigned game browser with:
 *  - Game cards with spread badges and color coding
 *  - Sort by kickoff / biggest spread / closest games / O/U
 *  - NFL division filter (client-side, no re-fetch)
 *  - College conference filter (client-side, no re-fetch — full FBS fetched once)
 *  - Team search input (filters by team name, client-side)
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
    // COLLEGE CONFERENCE MAP (client-side filter — ESPN conferenceId per game)
    // The server always returns all FBS games (groups=80); we filter here.
    // Keys match the <option value="..."> in the conference dropdown.
    // Values are the ESPN conferenceId strings returned in game.conference.
    // =========================================================================
    var COLLEGE_CONF_MAP = {
        'sec':           '8',
        'big-ten':       '5',
        'big-12':        '4',
        'acc':           '1',
        'pac-12':        '9',
        'aac':           '151',
        'mountain-west': '17',
        'sun-belt':      '37',
        'mac':           '15',
        'cusa':          '12',
        // 'fbs' and '' mean "show all" — no filtering needed
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
        var searchInput   = document.getElementById('kf-game-search');
        var sortFilterBar = document.getElementById('kf-sort-filter-bar');
        var selectedCount = document.getElementById('kf-selected-count');
        var statusMsg     = document.getElementById('kf-browser-status');
        var gameStats     = document.getElementById('kf-game-stats');
        var browserToggle = document.getElementById('kf-browser-toggle');
        var browserSummary= document.getElementById('kf-browser-summary');

        // The week profile is started before the browser-panel guard on purpose. On a published
        // week the ESPN browser is not rendered, but the deadline is still editable there — so
        // the deadline-vs-kickoff warning has to keep working with no browser present.
        startWeekProfile();
        startEspnLinker();

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

        // ---- Fetch button state helpers ----
        function setFetchBtnReady() {
            // Primary state: data not yet loaded, action needed
            fetchBtn.textContent = 'Fetch Games';
            fetchBtn.classList.remove('kf-button-secondary');
            fetchBtn.title = '';
        }
        function setFetchBtnLoaded() {
            // Muted state: data already loaded, filters work without re-fetching
            fetchBtn.textContent = '\u21BA Re-fetch';
            fetchBtn.classList.add('kf-button-secondary');
            fetchBtn.title = 'Filters update instantly \u2014 only click to load a different sport or week';
        }

        // ---- Sport Change: swap division vs conference filter ----
        function updateSportControls() {
            var isCollege = sportSelect && sportSelect.value === 'college-football';
            if (confGroup) confGroup.style.display = isCollege ? 'block' : 'none';
            if (divGroup)  divGroup.style.display  = isCollege ? 'none'  : 'block';
            gamesList.innerHTML = '';
            fetchedGames = [];
            if (sortFilterBar) sortFilterBar.style.display = 'none';
            if (fetchBtn) setFetchBtnReady();
            updateSelectedCount();
        }
        if (sportSelect) {
            sportSelect.addEventListener('change', updateSportControls);
            updateSportControls();
        }

        // Week change also signals a new fetch is needed
        if (weekSelect) {
            weekSelect.addEventListener('change', function () {
                if (fetchedGames.length) {
                    gamesList.innerHTML = '';
                    fetchedGames = [];
                    if (sortFilterBar) sortFilterBar.style.display = 'none';
                    if (fetchBtn) setFetchBtnReady();
                    updateSelectedCount();
                }
            });
        }

        // ---- League week # → ESPN calendar week auto-sync + duplicate guard ----
        var leagueWeekInput = document.getElementById('week_number');
        var dupWarning      = document.getElementById('kf-week-dup-warning');
        var setupCard       = document.querySelector('[data-existing-weeks]');
        var existingWeeks   = setupCard
            ? setupCard.getAttribute('data-existing-weeks').split(',').map(Number).filter(Boolean)
            : [];

        function syncLeagueWeek() {
            if (!leagueWeekInput || !weekSelect) return;
            var n = parseInt(leagueWeekInput.value, 10);

            // Auto-fill ESPN week when value is a valid regular-season week
            if (n >= 1 && n <= 18) {
                weekSelect.value = String(n);
            }

            // Duplicate week warning
            var isDup = !isNaN(n) && existingWeeks.indexOf(n) !== -1;
            if (dupWarning) dupWarning.style.display = isDup ? 'block' : 'none';

            updateSelectedCount();
        }

        if (leagueWeekInput) {
            leagueWeekInput.addEventListener('input',  syncLeagueWeek);
            leagueWeekInput.addEventListener('change', syncLeagueWeek);
            syncLeagueWeek(); // Sync on page load
        }

        // Re-run counter when matchup count changes (so Y stays current)
        var matchupCountWatcher = document.getElementById('kf_matchup_count');
        if (matchupCountWatcher) {
            matchupCountWatcher.addEventListener('input',  updateSelectedCount);
            matchupCountWatcher.addEventListener('change', updateSelectedCount);
        }

        // ---- All filters: re-render existing results (no re-fetch) ----
        if (divFilter)    divFilter.addEventListener('change',   function () { if (fetchedGames.length) renderGames(getFilteredSorted()); });
        if (confFilter)   confFilter.addEventListener('change',  function () { if (fetchedGames.length) renderGames(getFilteredSorted()); });
        if (sortSelect)   sortSelect.addEventListener('change',  function () { if (fetchedGames.length) renderGames(getFilteredSorted()); });
        if (spreadFilter) spreadFilter.addEventListener('change',function () { if (fetchedGames.length) renderGames(getFilteredSorted()); });
        if (searchInput)  searchInput.addEventListener('input',  function () { if (fetchedGames.length) renderGames(getFilteredSorted()); });

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
            if (searchInput) searchInput.value = '';

            var fd = new FormData();
            fd.append('action', 'kf_fetch_games');
            fd.append('nonce',  kf_ajax_data.nonce);
            fd.append('sport',  sport);
            fd.append('week',   week);
            // Conference filter is now client-side — all FBS games are fetched in one request

            fetch(kf_ajax_data.ajax_url, { method: 'POST', body: fd })
                .then(function (r) { return r.json(); })
                .then(function (resp) {
                    fetchBtn.disabled = false;
                    if (resp.success && resp.data && resp.data.games) {
                        fetchedGames = resp.data.games;
                        if (sortFilterBar) sortFilterBar.style.display = 'block';
                        renderGames(getFilteredSorted());
                        showStatus(fetchedGames.length + ' game(s) loaded. Use filters below to narrow results \u2014 no need to re-fetch.', 'success');
                        setFetchBtnLoaded();
                    } else {
                        var msg = (resp.data && resp.data.message) ? resp.data.message : 'No games found.';
                        showStatus(msg, 'warning');
                        setFetchBtnReady();
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
            var sport      = sportSelect   ? sportSelect.value              : 'nfl';
            var divVal     = divFilter     ? divFilter.value                : '';
            var confVal    = confFilter    ? confFilter.value               : '';
            var sfVal      = spreadFilter  ? spreadFilter.value             : '';
            var sortVal    = sortSelect    ? sortSelect.value               : 'kickoff';
            var searchTerm = searchInput   ? searchInput.value.trim().toLowerCase() : '';

            var games = fetchedGames.slice();

            // NFL division filter (client-side)
            if (sport === 'nfl' && divVal && NFL_DIVISIONS[divVal]) {
                var allowed = NFL_DIVISIONS[divVal];
                games = games.filter(function (g) {
                    return allowed.indexOf(g.home_abbr) !== -1 || allowed.indexOf(g.away_abbr) !== -1;
                });
            }

            // College conference filter (client-side — full FBS list is fetched once)
            if (sport === 'college-football' && confVal && confVal !== 'fbs' && COLLEGE_CONF_MAP[confVal]) {
                var confId = COLLEGE_CONF_MAP[confVal];
                games = games.filter(function (g) {
                    // game.conference holds the ESPN conferenceId string from the home team
                    return String(g.conference) === confId;
                });
            }

            // Team search filter (matches away or home team name, abbreviation, or short name)
            if (searchTerm) {
                games = games.filter(function (g) {
                    var haystack = [
                        g.away_team  || '',
                        g.home_team  || '',
                        g.away_abbr  || '',
                        g.home_abbr  || '',
                        g.away_short || '',
                        g.home_short || '',
                    ].join(' ').toLowerCase();
                    return haystack.indexOf(searchTerm) !== -1;
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
            // Preserve checked selections across filter/sort re-renders
            var selectedIndices = {};
            gamesList.querySelectorAll('.kf-game-checkbox:checked').forEach(function (cb) {
                selectedIndices[cb.getAttribute('data-game-index')] = true;
            });

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

            // Restore checked state for games that were selected before the re-render
            if (Object.keys(selectedIndices).length) {
                gamesList.querySelectorAll('.kf-game-checkbox').forEach(function (cb) {
                    if (selectedIndices[cb.getAttribute('data-game-index')]) {
                        cb.checked = true;
                        var card = cb.closest('.kf-game-card');
                        if (card) card.classList.add('kf-card-selected');
                    }
                });
            }

            // Re-apply the Added state last: cards are rebuilt on every filter/sort change,
            // so this has to run after the restore above or added games would look selectable.
            refreshAddedState();

            // Newly fetched games are now candidates for any unlinked manual matchup.
            kfRenderMatchControls();

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
            var spreadLabel = 'No odds';
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
                    // Skip disabled boxes: clicking the card must not re-select a game that
                    // has already been added to the week.
                    if (cb && !cb.disabled) { cb.checked = !cb.checked; cb.dispatchEvent(new Event('change')); }
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

            // Skip anything already in the week. The cards are disabled once added, so this
            // only fires in edge cases (a stale card, or a game added by a previous batch),
            // but without it the same ESPN game could be inserted as two matchups - nothing
            // downstream deduplicates by espn_game_id.
            var alreadyAdded = getAddedGameIds();
            var toAdd = [];
            var skipped = 0;
            checkboxes.forEach(function (cb) {
                var g = fetchedGames[parseInt(cb.getAttribute('data-game-index'), 10)];
                if (!g) { return; }
                if (g.espn_game_id && alreadyAdded[g.espn_game_id]) { skipped++; return; }
                toAdd.push(g);
            });

            if (!toAdd.length) {
                showStatus('Those games are already in this week.', 'warning');
                checkboxes.forEach(function (cb) { cb.checked = false; });
                refreshAddedState();
                return;
            }

            // Hard cap at the declared "Games This Week". Without this the week could be saved
            // with more matchups than matchup_count, which the picks form and point values are
            // both sized from.
            var target    = countInp ? (parseInt(countInp.value, 10) || 0) : 0;
            var cappedOut = 0;
            if (target > 0) {
                // Measured against filled matchups, not fieldsets — blank placeholders are
                // open slots, not occupied ones.
                var room = target - countFilledMatchups();
                if (room <= 0) {
                    showStatus('This week already has all ' + target + ' games. Remove a matchup, or raise "Games This Week" first.', 'warning');
                    checkboxes.forEach(function (cb) { cb.checked = false; });
                    refreshAddedState();
                    return;
                }
                if (toAdd.length > room) {
                    cappedOut = toAdd.length - room;
                    toAdd = toAdd.slice(0, room);
                }
            }

            toAdd.forEach(function (game, i) {
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

            // NOTE: "Games This Week" is deliberately NOT written to here. It used to be set to
            // the number of fieldsets present, purely to stop syncMatchups() from removing the
            // ESPN fieldsets — but that silently overwrote the commissioner's declared game count
            // and persisted the wrong matchup_count on the week. The count is the target; the
            // read-only "Games Added" box below reports actual progress against it.
            updateGamesAddedBox();

            var msg = toAdd.length + ' game(s) added to week setup.';
            if (skipped > 0)     { msg += ' ' + skipped + ' already in this week, skipped.'; }
            if (cappedOut > 0)   { msg += ' ' + cappedOut + ' left out — week is full at ' + target + '.'; }
            showStatus(msg, cappedOut > 0 ? 'warning' : 'success');

            checkboxes.forEach(function (cb) { cb.checked = false; });

            // Deliberately does NOT scroll anywhere. Jumping to the Matchups list threw away
            // the commissioner's place in the game list mid-selection. Feedback comes from the
            // card's Added badge and the sticky header counters, both visible without moving.
            refreshAddedState();
        }

        // ---- Added-to-week state ----
        // The set of already-added games is derived from the hidden espn_game_id[] inputs
        // that live in #matchups-container, rather than tracked in a variable. That keeps it
        // self-correcting: if a matchup fieldset is removed by any means, its game becomes
        // selectable again with no extra bookkeeping.
        function getAddedGameIds() {
            var ids = {};
            var container = document.getElementById('matchups-container');
            if (!container) return ids;
            container.querySelectorAll('input[name="espn_game_id[]"]').forEach(function (inp) {
                if (inp.value) { ids[inp.value] = true; }
            });
            return ids;
        }

        // Paints the "Added" state onto every visible card. Safe to call repeatedly.
        function refreshAddedState() {
            var added = getAddedGameIds();

            gamesList.querySelectorAll('.kf-game-checkbox').forEach(function (cb) {
                var card = cb.closest('.kf-game-card');
                if (!card) return;

                var game    = fetchedGames[parseInt(cb.getAttribute('data-game-index'), 10)];
                var isAdded = !!(game && game.espn_game_id && added[game.espn_game_id]);

                cb.disabled = isAdded;
                card.classList.toggle('kf-game-added', isAdded);

                if (isAdded) {
                    cb.checked = false;
                    card.classList.remove('kf-card-selected');
                }

                var header = card.querySelector('.kf-game-card-header');
                var badge  = card.querySelector('.kf-added-badge');
                if (isAdded && !badge && header) {
                    var span = document.createElement('span');
                    span.className = 'kf-added-badge';
                    span.textContent = '✓ Added';
                    header.appendChild(span);
                } else if (!isAdded && badge) {
                    badge.parentNode.removeChild(badge);
                }
            });

            updateSelectedCount();
            updateBrowserSummary();
        }

        // A fieldset only counts as a game once BOTH teams are filled in. #matchups-container is
        // pre-populated with one blank placeholder fieldset per declared game, so counting
        // fieldsets reports a brand-new empty week as already full.
        function countFilledMatchups() {
            var container = document.getElementById('matchups-container');
            if (!container) return 0;

            var filled = 0;
            container.querySelectorAll('.matchup-fieldset').forEach(function (fs) {
                var a = fs.querySelector('input[name="team_a[]"]');
                var b = fs.querySelector('input[name="team_b[]"]');
                if (a && b && a.value.trim() !== '' && b.value.trim() !== '') { filled++; }
            });
            return filled;
        }

        // ---- "Games Added" counter ----
        // Mirrors the real number of matchup fieldsets against the declared target so the two
        // numbers are visibly separate. Nothing here writes to kf_matchup_count.
        function updateGamesAddedBox() {
            var box  = document.getElementById('kf_games_added');
            var note = document.getElementById('kf-games-added-note');
            if (!box) return;

            var container = document.getElementById('matchups-container');
            var countInput= document.getElementById('kf_matchup_count');
            if (!container) return;

            var added  = countFilledMatchups();
            var target = countInput ? (parseInt(countInput.value, 10) || 0) : 0;

            box.value = added;

            if (!note) return;
            if (target <= 0) {
                note.textContent = ' ';
                box.style.color  = '';
            } else if (added === target) {
                note.textContent = 'Week is complete.';
                note.style.color = '#166534';
                box.style.color  = '#166534';
            } else if (added > target) {
                note.textContent = added - target + ' over the target.';
                note.style.color = '#b45309';
                box.style.color  = '#b45309';
            } else {
                note.textContent = (target - added) + ' still to add.';
                note.style.color = '#2563eb';
                box.style.color  = '#2563eb';
            }
        }

        // ---- Week profile (running stats for the commissioner) ----
        // Reads the matchup fieldsets rather than fetchedGames, so it covers games added in an
        // earlier session (edit mode) and stays correct if a matchup is removed.
        function readWeekMatchups() {
            var container = document.getElementById('matchups-container');
            if (!container) return [];

            return Array.prototype.map.call(
                container.querySelectorAll('.matchup-fieldset'),
                function (fs) {
                    function val(name) {
                        var el = fs.querySelector('input[name="' + name + '[]"]');
                        return el ? el.value : '';
                    }
                    var away = fs.querySelector('input[name="team_b[]"]');
                    var home = fs.querySelector('input[name="team_a[]"]');
                    var sh   = val('spread_home');

                    return {
                        away:     away ? away.value.trim() : '',
                        home:     home ? home.value.trim() : '',
                        spread:   sh === '' ? null : Math.abs(parseFloat(sh)),
                        overUnder: parseFloat(val('over_under')) || null,
                        kickoff:  val('game_datetime') ? new Date(val('game_datetime')) : null
                    };
                }
            ).filter(function (m) { return m.away !== '' && m.home !== ''; });
        }

        function updateWeekProfile() {
            var body   = document.getElementById('kf-week-profile-body');
            var alerts = document.getElementById('kf-week-profile-alerts');
            if (!body) return;

            var games = readWeekMatchups();
            if (!games.length) {
                body.innerHTML = '<span class="kf-profile-empty">Add games to see the week profile.</span>';
                if (alerts) alerts.innerHTML = '';
                return;
            }

            var spreads  = games.map(function (g) { return g.spread; })
                                .filter(function (s) { return s !== null && !isNaN(s); });
            var noOdds   = games.length - spreads.length;
            var kickoffs = games.map(function (g) { return g.kickoff; })
                                .filter(function (k) { return k && !isNaN(k.getTime()); });

            var stats = [];
            var avg   = null;
            if (spreads.length) {
                var lo  = Math.min.apply(null, spreads);
                var hi  = Math.max.apply(null, spreads);
                avg = spreads.reduce(function (a, b) { return a + b; }, 0) / spreads.length;
                stats.push(statChip('Closest', lo.toFixed(1), '#166534', '#dcfce7'));
                stats.push(statChip('Average', avg.toFixed(1), '#1e40af', '#dbeafe'));
                stats.push(statChip('Biggest', hi.toFixed(1), '#991b1b', '#fee2e2'));

                // A week of blowouts is a week where point assignment barely matters.
                var close = spreads.filter(function (s) { return s <= 3.5; }).length;
                var blow  = spreads.filter(function (s) { return s >= 10; }).length;
                stats.push(statChip('Close/Blowout', close + ' / ' + blow, '#374151', '#f3f4f6'));
            }

            if (kickoffs.length) {
                var first = new Date(Math.min.apply(null, kickoffs.map(function (k) { return k.getTime(); })));
                stats.push(statChip('First kickoff', formatDateKeyShort(first) + ' ' + formatTime(first.toISOString()), '#374151', '#f3f4f6'));
            }

            if (noOdds > 0) {
                stats.push(statChip('No odds', String(noOdds), '#92400e', '#fef3c7'));
            }

            // ---- Comparison against the rest of the season ----
            // The season figures come from the DB via data attributes (set in PHP from the
            // saved matchups) and stay on the element even though innerHTML is replaced below.
            var seasonAvg   = parseFloat(body.getAttribute('data-season-avg'));
            var seasonWeeks = parseInt(body.getAttribute('data-season-weeks'), 10);
            if (!isNaN(seasonAvg) && avg !== null) {
                var diff = avg - seasonAvg;
                var verdict, fg, bg;
                if (Math.abs(diff) < 0.25) {
                    verdict = 'in line'; fg = '#374151'; bg = '#f3f4f6';
                } else if (diff < 0) {
                    verdict = Math.abs(diff).toFixed(1) + ' tighter'; fg = '#166534'; bg = '#dcfce7';
                } else {
                    verdict = diff.toFixed(1) + ' wider'; fg = '#92400e'; bg = '#fef3c7';
                }
                var seasonLabel = 'vs season' + (isNaN(seasonWeeks) ? '' : ' (' + seasonWeeks + 'w)');
                stats.push(statChip(seasonLabel, seasonAvg.toFixed(1) + ' avg · ' + verdict, fg, bg));
            }

            body.innerHTML = stats.join('');

            // ---- Alerts: things that would quietly ruin a week ----
            if (!alerts) return;
            var warnings = [];

            var deadlineInp = document.getElementById('deadline');
            if (deadlineInp && deadlineInp.value && kickoffs.length) {
                var deadline    = new Date(deadlineInp.value); // datetime-local parses as local time
                var firstKick   = new Date(Math.min.apply(null, kickoffs.map(function (k) { return k.getTime(); })));
                if (!isNaN(deadline.getTime()) && deadline.getTime() >= firstKick.getTime()) {
                    warnings.push('The picks deadline is at or after the first kickoff (' +
                        formatDateKeyShort(firstKick) + ' ' + formatTime(firstKick.toISOString()) +
                        '). Players could pick a game that has already started.');
                }
            }

            var started = kickoffs.filter(function (k) { return k.getTime() < Date.now(); }).length;
            if (started > 0) {
                warnings.push(started + ' game(s) in this week have already kicked off.');
            }

            alerts.innerHTML = warnings.map(function (w) {
                return '<div class="kf-profile-alert">⚠ ' + escHtml(w) + '</div>';
            }).join('');
        }

        function statChip(label, value, fg, bg) {
            return '<span class="kf-profile-stat" style="background:' + bg + ';color:' + fg + ';">' +
                       '<span class="kf-profile-stat-label">' + escHtml(label) + '</span>' +
                       '<strong>' + escHtml(value) + '</strong>' +
                   '</span>';
        }

        function formatDateKeyShort(d) {
            try {
                return d.toLocaleDateString(undefined, { weekday: 'short', month: 'numeric', day: 'numeric' });
            } catch (e) {
                return '';
            }
        }

        // Recompute whenever the matchup list changes by any route — games added, count
        // changed, fieldsets rebuilt — instead of hooking each mutation site individually.
        function startWeekProfile() {
            var container      = document.getElementById('matchups-container');
            var observerConfig = { childList: true, subtree: true };
            var weekObserver   = null;
            var refreshing     = false;

            // refreshAll writes INSIDE the observed container: kfRenderMatchControls() appends
            // a .kf-espn-link-row host to each fieldset and rewrites its innerHTML. Those are
            // childList mutations in the observed subtree, so an unguarded observer re-entered
            // its own callback forever the moment any matchup had both team names filled in —
            // freezing the browser's main thread with no console error and no way to reload.
            // The observer is detached for the duration of the render (disconnect() also drops
            // its pending records) and reattached after; the flag stops the delegated input
            // handler and the initial call re-entering the same way.
            function refreshAll() {
                if (refreshing) { return; }
                refreshing = true;
                if (weekObserver) { weekObserver.disconnect(); }
                try {
                    updateWeekProfile();
                    updateGamesAddedBox();
                    kfRenderMatchControls();
                } finally {
                    if (weekObserver && container) { weekObserver.observe(container, observerConfig); }
                    refreshing = false;
                }
            }

            if (container && window.MutationObserver) {
                weekObserver = new MutationObserver(refreshAll);
                weekObserver.observe(container, observerConfig);
            }
            // Typing a team name changes no DOM node, so the observer above never sees manual
            // entry. Delegated input events cover it.
            if (container) { container.addEventListener('input', refreshAll); }
            var deadlineInp = document.getElementById('deadline');
            if (deadlineInp) { deadlineInp.addEventListener('change', updateWeekProfile); }

            // The target can be retyped at any time; the counter has to follow it.
            var countInput = document.getElementById('kf_matchup_count');
            if (countInput) {
                countInput.addEventListener('change', updateGamesAddedBox);
                countInput.addEventListener('keyup',  updateGamesAddedBox);
            }
            refreshAll();
        }


        // ---- Linking manual matchups to real ESPN games ----
        // A matchup typed by hand has no espn_game_id, so the score cron can never find it and
        // that game silently never updates. This offers candidate ESPN games for an unlinked
        // matchup and fills the same hidden inputs the browser writes, so the existing save
        // path persists it - no new endpoint, no schema change.
        //
        // Candidates are SUGGESTED, never auto-applied. Team names are ambiguous (NY Giants vs
        // NY Jets; dozens of near-identical college names) and a wrong link would feed the
        // wrong score into a finalized week, which is worse than no link at all.

        function kfNormalizeTeam(name) {
            return String(name || '').toLowerCase().replace(/[^a-z0-9 ]/g, '').replace(/\s+/g, ' ').trim();
        }

        // Two words plausibly name the same thing when identical, or one is a prefix of the
        // other — ok/oklahoma, wash/washington, st/state.
        function kfWordMatch(a, b) {
            if (!a || !b) { return false; }
            if (a === b) { return true; }
            return a.length < b.length ? b.indexOf(a) === 0 : a.indexOf(b) === 0;
        }

        // 0 = no relation. Higher is a better match.
        function kfTeamScore(typed, game, side) {
            var t = kfNormalizeTeam(typed);
            if (!t) return 0;

            var candidates = [
                kfNormalizeTeam(game[side + '_abbr']),
                kfNormalizeTeam(game[side + '_short']),
                kfNormalizeTeam(game[side + '_team'])
            ].filter(Boolean);

            var i;
            for (i = 0; i < candidates.length; i++) {
                if (candidates[i] === t) { return 4; }
            }
            for (i = 0; i < candidates.length; i++) {
                if (candidates[i].indexOf(t) !== -1 || t.indexOf(candidates[i]) !== -1) { return 2; }
            }
            // Word-prefix match — the tier that rescues abbreviated names. ESPN writes
            // "Oklahoma St" where the sheet says "OK STATE": same team, no whole word in
            // common. A word matches when one is a prefix of the other, and the name matches
            // only when every word in the typed name finds a partner.
            var typedWords = t.split(' ').filter(Boolean);
            for (i = 0; i < candidates.length; i++) {
                var candWords = candidates[i].split(' ').filter(Boolean);
                if (!candWords.length || !typedWords.length) { continue; }
                var allMatched = true;
                for (var w = 0; w < typedWords.length; w++) {
                    var found = false;
                    for (var cw = 0; cw < candWords.length; cw++) {
                        if (kfWordMatch(typedWords[w], candWords[cw])) { found = true; break; }
                    }
                    if (!found) { allMatched = false; break; }
                }
                if (allMatched) { return 1; }
            }

            // Mascot match: "pittsburgh steelers" vs "steelers"
            var tLast = t.split(' ').pop();
            for (i = 0; i < candidates.length; i++) {
                var cLast = candidates[i].split(' ').pop();
                if (tLast && cLast && tLast === cLast && tLast.length > 3) { return 1; }
            }
            return 0;
        }

        function kfFindEspnCandidates(homeTyped, awayTyped) {
            if (!fetchedGames || !fetchedGames.length) { return []; }

            var alreadyLinked = getAddedGameIds();

            return fetchedGames.map(function (g, idx) {
                    var hs = kfTeamScore(homeTyped, g, 'home');
                    var as = kfTeamScore(awayTyped, g, 'away');
                    return { game: g, index: idx, score: (hs && as) ? hs + as : 0 };
                })
                .filter(function (c) {
                    // Both sides must match, and never offer a game already used in this week.
                    return c.score > 0 && !alreadyLinked[c.game.espn_game_id];
                })
                .sort(function (a, b) { return b.score - a.score; })
                .slice(0, 4);
        }

        function kfSetFieldsetGame(fs, game) {
            function put(name, value) {
                var el = fs.querySelector('input[name="' + name + '[]"]');
                if (el) { el.value = (value === null || value === undefined) ? '' : value; }
            }
            put('espn_game_id',      game ? game.espn_game_id : '');
            put('game_datetime',     game ? game.game_datetime : '');
            put('odds_api_event_id', game ? (game.odds_api_event_id || '') : '');
            put('spread_home',       game ? game.spread_home : '');
            put('spread_away',       game ? game.spread_away : '');
            put('moneyline_home',    game ? game.moneyline_home : '');
            put('moneyline_away',    game ? game.moneyline_away : '');
            put('over_under',        game ? game.over_under : '');
        }

        function kfRenderMatchControls() {
            var container = document.getElementById('matchups-container');
            if (!container) { return; }

            container.querySelectorAll('.matchup-fieldset').forEach(function (fs) {
                var homeInp = fs.querySelector('input[name="team_a[]"]');
                var awayInp = fs.querySelector('input[name="team_b[]"]');
                var idInp   = fs.querySelector('input[name="espn_game_id[]"]');
                if (!homeInp || !awayInp || !idInp) { return; }

                var host = fs.querySelector('.kf-espn-link-row');
                if (!host) {
                    host = document.createElement('div');
                    host.className = 'kf-espn-link-row';
                    fs.appendChild(host);
                }

                var linked   = idInp.value !== '';
                var haveBoth = homeInp.value.trim() !== '' && awayInp.value.trim() !== '';

                if (linked) {
                    host.innerHTML = '<span class="kf-espn-linked">&#10003; Linked to ESPN</span>' +
                                     '<button type="button" class="kf-espn-unlink kf-linkish">Unlink</button>';
                    host.querySelector('.kf-espn-unlink').addEventListener('click', function (e) {
                        e.preventDefault();
                        kfSetFieldsetGame(fs, null);
                        kfRenderMatchControls();
                        updateWeekProfile();
                    });
                    return;
                }

                if (!haveBoth) { host.innerHTML = ''; return; }

                if (!fetchedGames || !fetchedGames.length) {
                    host.innerHTML = '<span class="kf-espn-hint">Not linked &mdash; fetch games above to match this to a real ESPN game for live scores.</span>';
                    return;
                }

                var candidates = kfFindEspnCandidates(homeInp.value, awayInp.value);
                if (!candidates.length) {
                    host.innerHTML = '<span class="kf-espn-hint">Not linked &mdash; no fetched ESPN game matches these team names.</span>';
                    return;
                }

                host.innerHTML = '<span class="kf-espn-hint">Not linked. Match to:</span>' +
                    candidates.map(function (c, i) {
                        return '<button type="button" class="kf-espn-pick kf-linkish" data-i="' + i + '">' +
                               escHtml(c.game.away_abbr + ' @ ' + c.game.home_abbr) +
                               ' <span class="kf-espn-pick-when">' + escHtml(formatTime(c.game.game_datetime)) + '</span>' +
                               '</button>';
                    }).join('');

                host.querySelectorAll('.kf-espn-pick').forEach(function (btn) {
                    btn.addEventListener('click', function (e) {
                        e.preventDefault();
                        var chosen = candidates[parseInt(btn.getAttribute('data-i'), 10)];
                        if (!chosen) { return; }
                        kfSetFieldsetGame(fs, chosen.game);
                        kfRenderMatchControls();
                        refreshAddedState();
                        updateWeekProfile();
                    });
                });
            });
        }


        // ---- Link-only ESPN attachment (safe on live weeks) ----
        // Talks to kf_espn_link_suggestions / kf_espn_apply_link, which UPDATE an existing
        // matchup row in place. This never goes through the Save path that deletes and
        // re-inserts matchups, so it works on a week that already has picks — which is exactly
        // when the normal path is (correctly) blocked.
        function startEspnLinker() {
            var panel = document.getElementById('kf-espn-linker');
            if (!panel) { return; }

            var findBtn  = document.getElementById('kf-linker-find');
            var weekInp  = document.getElementById('kf-linker-week');
            var rowsEl   = document.getElementById('kf-linker-rows');
            var statusEl = document.getElementById('kf-linker-status');
            var weekId   = panel.getAttribute('data-week-id');

            function say(msg, colour) {
                if (!statusEl) { return; }
                statusEl.textContent = msg || '';
                statusEl.style.color = colour || '#6b7280';
            }

            function post(action, extra) {
                var fd = new FormData();
                fd.append('action', action);
                fd.append('nonce', kf_ajax_data.nonce);
                Object.keys(extra || {}).forEach(function (k) { fd.append(k, extra[k]); });
                return fetch(kf_ajax_data.ajax_url, { method: 'POST', body: fd })
                    .then(function (r) { return r.json(); });
            }

            function renderRows(rows) {
                if (!rows.length) {
                    rowsEl.innerHTML = '<p class="kf-form-note">This week has no matchups yet.</p>';
                    return;
                }

                rowsEl.innerHTML = rows.map(function (row, i) {
                    var teams = '<strong>' + escHtml(row.team_b) + '</strong> @ <strong>' + escHtml(row.team_a) + '</strong>';

                    if (row.linked) {
                        return '<div class="kf-linker-row"><span class="kf-linker-teams">' + teams + '</span>' +
                               '<span class="kf-espn-linked">&#10003; Linked</span>' +
                               '<button type="button" class="kf-linkish kf-linker-refresh" data-row="' + i + '">Refresh</button>' +
                               '<button type="button" class="kf-linkish kf-linker-unlink" data-row="' + i + '">Unlink</button></div>';
                    }
                    if (!row.candidates || !row.candidates.length) {
                        return '<div class="kf-linker-row"><span class="kf-linker-teams">' + teams + '</span>' +
                               '<span class="kf-espn-hint">No ESPN game matched these names &mdash; try a different ESPN week.</span></div>';
                    }

                    var buttons = row.candidates.map(function (c, j) {
                        return '<button type="button" class="kf-linkish kf-linker-apply" data-row="' + i + '" data-cand="' + j + '">' +
                               escHtml(c.label) +
                               ' <span class="kf-espn-pick-when">' + escHtml(formatTime(c.kickoff)) + '</span>' +
                               '</button>';
                    }).join('');

                    return '<div class="kf-linker-row"><span class="kf-linker-teams">' + teams + '</span>' + buttons + '</div>';
                }).join('');

                // Re-pull ESPN data for a game that is already linked. Repairs anything stored
                // wrong by an earlier version and refreshes kickoff, score and odds on demand.
                rowsEl.querySelectorAll('.kf-linker-refresh').forEach(function (btn) {
                    btn.addEventListener('click', function (e) {
                        e.preventDefault();
                        var row = rows[parseInt(btn.getAttribute('data-row'), 10)];
                        if (!row || !row.espn_game_id) { return; }
                        btn.disabled = true;
                        say('Refreshing from ESPN…');
                        post('kf_espn_apply_link', { matchup_id: row.matchup_id, espn_game_id: row.espn_game_id })
                            .then(function (res) {
                                btn.disabled = false;
                                if (res && res.success) {
                                    say('Refreshed. ' + res.data.message, '#166534');
                                } else {
                                    say((res && res.data && res.data.message) || 'Could not refresh.', '#b91c1c');
                                }
                            })
                            .catch(function () { btn.disabled = false; say('Network error.', '#b91c1c'); });
                    });
                });

                rowsEl.querySelectorAll('.kf-linker-unlink').forEach(function (btn) {
                    btn.addEventListener('click', function (e) {
                        e.preventDefault();
                        var row = rows[parseInt(btn.getAttribute('data-row'), 10)];
                        if (!row) { return; }
                        if (!window.confirm('Unlink this game from ESPN? It will stop updating automatically. Any score or result already recorded is kept.')) { return; }

                        btn.disabled = true;
                        say('Unlinking…');

                        post('kf_espn_unlink', { matchup_id: row.matchup_id }).then(function (res) {
                            if (res && res.success) {
                                row.linked = false;
                                row.candidates = row.candidates || [];
                                renderRows(rows);
                                say(res.data.message + ' Use Find ESPN matches to link a different game.', '#92400e');
                            } else {
                                btn.disabled = false;
                                say((res && res.data && res.data.message) || 'Could not unlink.', '#b91c1c');
                            }
                        }).catch(function () {
                            btn.disabled = false;
                            say('Network error while unlinking.', '#b91c1c');
                        });
                    });
                });

                rowsEl.querySelectorAll('.kf-linker-apply').forEach(function (btn) {
                    btn.addEventListener('click', function (e) {
                        e.preventDefault();
                        var row  = rows[parseInt(btn.getAttribute('data-row'), 10)];
                        var cand = row && row.candidates[parseInt(btn.getAttribute('data-cand'), 10)];
                        if (!row || !cand) { return; }

                        btn.disabled = true;
                        say('Linking…');

                        post('kf_espn_apply_link', {
                            matchup_id:   row.matchup_id,
                            espn_game_id: cand.espn_game_id
                        }).then(function (res) {
                            if (res && res.success) {
                                row.linked = true;
                                renderRows(rows);
                                say(res.data.message, '#166534');
                            } else {
                                btn.disabled = false;
                                say((res && res.data && res.data.message) || 'Could not link that game.', '#b91c1c');
                            }
                        }).catch(function () {
                            btn.disabled = false;
                            say('Network error while linking.', '#b91c1c');
                        });
                    });
                });
            }

            if (findBtn) {
                findBtn.addEventListener('click', function (e) {
                    e.preventDefault();
                    findBtn.disabled = true;
                    say('Asking ESPN…');
                    rowsEl.innerHTML = '';

                    post('kf_espn_link_suggestions', {
                        week_id:   weekId,
                        espn_week: weekInp ? weekInp.value : ''
                    }).then(function (res) {
                        findBtn.disabled = false;
                        if (res && res.success) {
                            var unlinked = res.data.rows.filter(function (r) { return !r.linked; }).length;
                            say(res.data.games_fetched + ' ESPN games checked · ' + unlinked + ' matchup(s) still unlinked');
                            renderRows(res.data.rows);
                        } else {
                            say((res && res.data && res.data.message) || 'Could not reach ESPN.', '#b91c1c');
                        }
                    }).catch(function () {
                        findBtn.disabled = false;
                        say('Network error contacting ESPN.', '#b91c1c');
                    });
                });
            }
        }

        // ---- Collapse / expand the browser panel ----
        // Collapsing is done with a class so each child keeps its own display logic
        // (conference/division groups are shown and hidden independently by sport).
        function setBrowserCollapsed(collapsed) {
            if (!browserPanel) return;
            browserPanel.classList.toggle('kf-browser-collapsed', collapsed);
            if (browserToggle) {
                browserToggle.textContent = collapsed ? 'Expand' : 'Minimize';
                browserToggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
            }
            updateBrowserSummary();
        }

        function updateBrowserSummary() {
            if (!browserSummary) return;
            var addedCount = Object.keys(getAddedGameIds()).length;
            var parts = [];
            if (displayedTotal)  { parts.push(displayedTotal + ' shown'); }
            if (addedCount)      { parts.push(addedCount + ' added'); }
            browserSummary.textContent = parts.length ? '(' + parts.join(' · ') + ')' : '';
        }

        if (browserToggle) {
            browserToggle.addEventListener('click', function (e) {
                e.preventDefault();
                setBrowserCollapsed(!browserPanel.classList.contains('kf-browser-collapsed'));
            });
        }

        // ---- Helpers ----
        function updateSelectedCount() {
            var n      = gamesList.querySelectorAll('.kf-game-checkbox:checked').length;
            var mcInp  = document.getElementById('kf_matchup_count');
            var target = mcInp ? (parseInt(mcInp.value, 10) || 0) : 0;

            // Count against the slots still open, not the whole week — games added earlier
            // already occupy part of the target.
            var already = countFilledMatchups();
            var needed  = target > 0 ? Math.max(0, target - already) : 0;

            var text, color, bold;
            if (needed > 0) {
                text  = n + ' of ' + needed + (already > 0 ? ' remaining' : '') + ' games selected';
                bold  = (n === needed);
                if      (n === 0)      { color = '#6b7280'; }   // grey  — nothing yet
                else if (n < needed)   { color = '#2563eb'; }   // blue  — picking
                else if (n === needed) { color = '#166534'; }   // green — exactly right
                else                   { color = '#d97706'; }   // amber — too many
            } else {
                text  = n + ' game(s) selected';
                color = '#6b7280';
                bold  = false;
            }

            document.querySelectorAll('.kf-selected-count-text').forEach(function (el) {
                el.textContent   = text;
                el.style.color   = color;
                el.style.fontWeight = bold ? 'bold' : '';
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
