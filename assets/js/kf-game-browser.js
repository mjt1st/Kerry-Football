/**
 * Kerry Football — Game Browser
 *
 * Handles the "Browse Live Games" panel on the Week Setup page.
 * Lets the commissioner search ESPN for real NFL/NCAAF games,
 * select which ones to include, and auto-populate the matchup form.
 *
 * @package Kerry_Football
 * @since   Sports API V1
 */

(function() {
    'use strict';

    // Wait for DOM
    document.addEventListener('DOMContentLoaded', function() {
        const browserPanel  = document.getElementById('kf-game-browser');
        const manualPanel   = document.getElementById('matchups-container');
        const modeToggle    = document.querySelectorAll('.kf-mode-toggle-btn');
        const fetchBtn      = document.getElementById('kf-fetch-games-btn');
        const gamesList     = document.getElementById('kf-games-list');
        const addBtn        = document.getElementById('kf-add-selected-btn');
        const sportSelect   = document.getElementById('kf-sport-select');
        const weekSelect    = document.getElementById('kf-week-select');
        const confFilter    = document.getElementById('kf-conference-filter');
        const confGroup     = document.getElementById('kf-conference-group');
        const selectedCount = document.getElementById('kf-selected-count');
        const statusMsg     = document.getElementById('kf-browser-status');

        if (!browserPanel) return; // Not on the week-setup page

        let fetchedGames = [];
        let currentMode = 'manual'; // 'api' or 'manual'

        // ---- Mode Toggle ----
        modeToggle.forEach(function(btn) {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                const mode = this.getAttribute('data-mode');
                setMode(mode);
            });
        });

        function setMode(mode) {
            currentMode = mode;
            modeToggle.forEach(function(btn) {
                btn.classList.toggle('kf-mode-active', btn.getAttribute('data-mode') === mode);
            });
            browserPanel.style.display = mode === 'api' ? 'block' : 'none';

            // In API mode, the matchup container still shows (populated by "Add Selected")
            // In manual mode, everything works as before
            if (mode === 'manual') {
                // Clear API-populated data
                gamesList.innerHTML = '';
                fetchedGames = [];
                updateSelectedCount();
            }
        }

        // ---- Sport Change ----
        if (sportSelect) {
            sportSelect.addEventListener('change', function() {
                const isCollege = this.value === 'college-football';
                if (confGroup) {
                    confGroup.style.display = isCollege ? 'block' : 'none';
                }
                gamesList.innerHTML = '';
                fetchedGames = [];
                updateSelectedCount();
            });
        }

        // ---- Fetch Games ----
        if (fetchBtn) {
            fetchBtn.addEventListener('click', function(e) {
                e.preventDefault();
                fetchGames();
            });
        }

        function fetchGames() {
            const sport = sportSelect ? sportSelect.value : 'nfl';
            const week  = weekSelect ? weekSelect.value : '';

            if (!week) {
                showStatus('Please select a week.', 'warning');
                return;
            }

            showStatus('Fetching games from ESPN...', 'info');
            fetchBtn.disabled = true;
            gamesList.innerHTML = '';
            fetchedGames = [];

            const formData = new FormData();
            formData.append('action', 'kf_fetch_games');
            formData.append('nonce', kf_ajax_data.nonce);
            formData.append('sport', sport);
            formData.append('week', week);

            if (confFilter && sport === 'college-football') {
                formData.append('conference', confFilter.value);
            }

            fetch(kf_ajax_data.ajax_url, {
                method: 'POST',
                body: formData
            })
            .then(function(res) { return res.json(); })
            .then(function(response) {
                fetchBtn.disabled = false;
                if (response.success && response.data && response.data.games) {
                    fetchedGames = response.data.games;
                    renderGames(fetchedGames);
                    showStatus(fetchedGames.length + ' game(s) found.', 'success');
                } else {
                    showStatus(response.data ? response.data.message : 'No games found for this week.', 'warning');
                }
            })
            .catch(function(err) {
                fetchBtn.disabled = false;
                showStatus('Error fetching games. Please try again.', 'error');
                console.error('KF Game Browser Error:', err);
            });
        }

        // ---- Render Games List ----
        function renderGames(games) {
            gamesList.innerHTML = '';

            if (!games || games.length === 0) {
                gamesList.innerHTML = '<p class="kf-form-note">No games found for the selected criteria.</p>';
                return;
            }

            // Group by date
            var grouped = {};
            games.forEach(function(game) {
                var dateKey = formatGameDate(game.game_datetime);
                if (!grouped[dateKey]) {
                    grouped[dateKey] = [];
                }
                grouped[dateKey].push(game);
            });

            Object.keys(grouped).forEach(function(dateKey) {
                var dateHeader = document.createElement('h4');
                dateHeader.className = 'kf-game-date-header';
                dateHeader.textContent = dateKey;
                gamesList.appendChild(dateHeader);

                grouped[dateKey].forEach(function(game, idx) {
                    var row = document.createElement('label');
                    row.className = 'kf-game-row';
                    row.innerHTML =
                        '<input type="checkbox" class="kf-game-checkbox" data-game-index="' + games.indexOf(game) + '">' +
                        '<span class="kf-game-time">' + formatGameTime(game.game_datetime) + '</span>' +
                        '<span class="kf-game-teams">' + escHtml(game.away_team) + '  @  ' + escHtml(game.home_team) + '</span>' +
                        (game.broadcast ? '<span class="kf-game-broadcast">' + escHtml(game.broadcast) + '</span>' : '') +
                        (game.game_status !== 'scheduled' ? '<span class="kf-game-status kf-status-' + game.game_status + '">' + escHtml(game.status_detail || game.game_status) + '</span>' : '');

                    row.querySelector('.kf-game-checkbox').addEventListener('change', updateSelectedCount);
                    gamesList.appendChild(row);
                });
            });

            updateSelectedCount();
        }

        // ---- Add Selected Games to Matchup Form ----
        if (addBtn) {
            addBtn.addEventListener('click', function(e) {
                e.preventDefault();
                addSelectedGames();
            });
        }

        function addSelectedGames() {
            var checkboxes = gamesList.querySelectorAll('.kf-game-checkbox:checked');
            if (checkboxes.length === 0) {
                showStatus('Please select at least one game.', 'warning');
                return;
            }

            var container = document.getElementById('matchups-container');
            var matchupCountInput = document.getElementById('kf_matchup_count');

            // Clear existing matchups if they're empty (new week)
            var existingFieldsets = container.querySelectorAll('.matchup-fieldset');
            var hasExistingData = false;
            existingFieldsets.forEach(function(fs) {
                var inputs = fs.querySelectorAll('input[type="text"]');
                inputs.forEach(function(inp) {
                    if (inp.value.trim()) hasExistingData = true;
                });
            });

            if (!hasExistingData) {
                container.innerHTML = '';
            }

            var startIndex = container.querySelectorAll('.matchup-fieldset').length;

            checkboxes.forEach(function(cb, i) {
                var gameIndex = parseInt(cb.getAttribute('data-game-index'), 10);
                var game = fetchedGames[gameIndex];
                if (!game) return;

                var idx = startIndex + i;
                var fieldset = document.createElement('fieldset');
                fieldset.className = 'matchup-fieldset';
                fieldset.style.cssText = 'margin-bottom:16px;padding:12px;border:1px solid #ccc;border-radius:4px;';

                fieldset.innerHTML =
                    '<legend>Matchup ' + (idx + 1) + ' <span class="kf-api-badge">ESPN</span></legend>' +
                    '<div class="kf-form-group"><label>Away Team (Team B): <input type="text" name="team_b[]" value="' + escAttr(game.away_team) + '" readonly class="kf-api-locked"></label></div>' +
                    '<div class="kf-form-group"><label>Home Team (Team A): <input type="text" name="team_a[]" value="' + escAttr(game.home_team) + '" readonly class="kf-api-locked"></label></div>' +
                    '<div class="kf-form-group"><label><input type="radio" name="tiebreaker_marker" value="' + idx + '" ' + (idx === 0 ? 'checked' : '') + ' required> Mark as Tiebreaker</label></div>' +
                    '<input type="hidden" name="espn_game_id[]" value="' + escAttr(game.espn_game_id || '') + '">' +
                    '<input type="hidden" name="game_datetime[]" value="' + escAttr(game.game_datetime || '') + '">' +
                    '<input type="hidden" name="odds_api_event_id[]" value="' + escAttr(game.odds_api_event_id || '') + '">' +
                    '<input type="hidden" name="spread_home[]" value="' + escAttr(game.spread_home || '') + '">' +
                    '<input type="hidden" name="spread_away[]" value="' + escAttr(game.spread_away || '') + '">' +
                    '<input type="hidden" name="moneyline_home[]" value="' + escAttr(game.moneyline_home || '') + '">' +
                    '<input type="hidden" name="moneyline_away[]" value="' + escAttr(game.moneyline_away || '') + '">' +
                    '<input type="hidden" name="over_under[]" value="' + escAttr(game.over_under || '') + '">' +
                    '<div class="kf-game-meta-display">' +
                        '<span>' + formatGameDate(game.game_datetime) + ' ' + formatGameTime(game.game_datetime) + '</span>' +
                        (game.spread_home ? ' | <span>Spread: ' + escHtml(game.home_abbr || game.home_team) + ' ' + formatSpread(game.spread_home) + '</span>' : '') +
                        (game.over_under ? ' | <span>O/U: ' + escHtml(game.over_under) + '</span>' : '') +
                    '</div>';

                container.appendChild(fieldset);
            });

            // Update matchup count
            var totalMatchups = container.querySelectorAll('.matchup-fieldset').length;
            if (matchupCountInput) {
                matchupCountInput.value = totalMatchups;
                matchupCountInput.dispatchEvent(new Event('change'));
            }

            showStatus(checkboxes.length + ' game(s) added to matchups.', 'success');

            // Uncheck all
            checkboxes.forEach(function(cb) { cb.checked = false; });
            updateSelectedCount();
        }

        // ---- Helpers ----
        function updateSelectedCount() {
            var checked = gamesList.querySelectorAll('.kf-game-checkbox:checked').length;
            if (selectedCount) {
                selectedCount.textContent = checked;
            }
            if (addBtn) {
                addBtn.disabled = checked === 0;
            }
        }

        function showStatus(msg, type) {
            if (!statusMsg) return;
            statusMsg.textContent = msg;
            statusMsg.className = 'kf-browser-status kf-status-' + type;
            statusMsg.style.display = 'block';
        }

        function formatGameDate(dateStr) {
            if (!dateStr) return '';
            try {
                var d = new Date(dateStr);
                return d.toLocaleDateString('en-US', { weekday: 'long', month: 'short', day: 'numeric' });
            } catch (e) {
                return dateStr;
            }
        }

        function formatGameTime(dateStr) {
            if (!dateStr) return '';
            try {
                var d = new Date(dateStr);
                return d.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
            } catch (e) {
                return '';
            }
        }

        function formatSpread(val) {
            if (val === null || val === undefined || val === '') return '';
            var num = parseFloat(val);
            return num > 0 ? '+' + num : '' + num;
        }

        function escHtml(str) {
            if (str === null || str === undefined) return '';
            var div = document.createElement('div');
            div.textContent = String(str);
            return div.innerHTML;
        }

        function escAttr(str) {
            if (str === null || str === undefined) return '';
            return String(str).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/'/g, '&#39;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
        }
    });
})();
