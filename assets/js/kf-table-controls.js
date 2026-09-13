/**
 * JavaScript for handling table controls and season switcher.
 *
 * @package Kerry_Football
 * - MODIFICATION: The AJAX call now sends the intended redirect URL to the server, making the navigation behavior robust and eliminating the race condition.
 * - Handles zoom, scroll, and season switcher interactions.
 * - Updated to prioritize data.redirect_url from AJAX response for season switching.
 */
document.addEventListener('DOMContentLoaded', function() {

    /**
     * =================================================
     * Universal Season Context Handler
     * =================================================
     */
    function handleSeasonSwitch(seasonId, redirectUrl = '') {
        if (!window.kf_ajax_data || !window.kf_ajax_data.nonce || !seasonId) {
            showKFCustomAlert('A required security token is missing. Please refresh the page and try again.');
            return;
        }

        const formData = new FormData();
        formData.append('action', 'kf_set_active_season');
        formData.append('season_id', seasonId);
        formData.append('nonce', kf_ajax_data.nonce);
        // NEW: Pass the intended redirect URL to the server so it can be returned reliably.
        formData.append('redirect_url', redirectUrl);

        document.body.style.cursor = 'wait';

        fetch(kf_ajax_data.ajax_url, {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            document.body.style.cursor = 'default';
            if (data.success) {
                // The server now sends back the correct URL.
                const destinationUrl = data.data.redirect_url || '/season-summary/';
                window.location.href = destinationUrl;
            } else {
                showKFCustomAlert('Error: ' + (data.data.message || 'Unknown error.'));
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showKFCustomAlert('An unexpected error occurred while switching seasons.');
            document.body.style.cursor = 'default';
        });
    }

    // --- Season Switcher Logic (in main menu) ---
    document.body.addEventListener('click', function(e) {
        const switcherLink = e.target.closest('.kf-season-switcher a, .kf-season-switcher-item a');
        if (!switcherLink) { return; }
        e.preventDefault();
        
        const seasonId = switcherLink.dataset.seasonId;
        if (seasonId) {
            switcherLink.innerHTML = 'Loading...';
            // The default redirect for the main menu switcher is always the season summary.
            handleSeasonSwitch(seasonId, '/season-summary/');
        }
    });

    // --- Dashboard & Homepage Button Logic ---
    const hubButtons = document.querySelectorAll('.kf-season-select-and-go');
    hubButtons.forEach(function(button) {
        button.addEventListener('click', function(e) {
            e.preventDefault();
            const seasonId = this.dataset.seasonId;
            const redirectUrl = this.dataset.redirectUrl;
            button.innerHTML = 'Loading...';
            // Pass the specific redirect URL from the button's data attribute.
            handleSeasonSwitch(seasonId, redirectUrl);
        });
    });

    /**
     * =================================================
     * "Unsaved Changes" Detector
     * =================================================
     */
    const trackedForms = document.querySelectorAll('.kf-tracked-form');
    if (trackedForms.length > 0) {
        window.kerryFootballFormDirty = false;
        trackedForms.forEach(form => {
            form.addEventListener('change', () => { window.kerryFootballFormDirty = true; });
            form.addEventListener('submit', () => { window.kerryFootballFormDirty = false; });
        });
    }

    window.addEventListener('beforeunload', (event) => {
        if (window.kerryFootballFormDirty === true) {
            event.preventDefault();
            event.returnValue = '';
        }
    });

    /**
     * =================================================
     * Table View Controls
     * =================================================
     */
    const tableWrapper = document.querySelector('.kf-table-wrapper');
    if (tableWrapper) {
        const zoomContainer = tableWrapper.querySelector('.kf-zoom-container');
        const table = tableWrapper.querySelector('.kf-table');

        if (table && zoomContainer) {
            const zoomInBtn = document.getElementById('kf-zoom-in');
            const zoomOutBtn = document.getElementById('kf-zoom-out');
            const fitBtn = document.getElementById('kf-fit-view');
            const resetBtn = document.getElementById('kf-reset-view');
            const scrollLeftBtn = document.getElementById('kf-scroll-left');
            const scrollRightBtn = document.getElementById('kf-scroll-right');
            
            let currentScale = 1.0;
            const initialScale = 1.0;
            const scaleStep = 0.1;

            function applyScale() {
                zoomContainer.style.transform = 'scale(' + currentScale + ')';
            }

            if (zoomInBtn) { zoomInBtn.addEventListener('click', () => { currentScale += scaleStep; applyScale(); }); }
            if (zoomOutBtn) { zoomOutBtn.addEventListener('click', () => { if (currentScale - scaleStep > 0.1) { currentScale -= scaleStep; applyScale(); } }); }
            
            if (fitBtn) {
                fitBtn.addEventListener('click', () => {
                    const containerWidth = tableWrapper.clientWidth;
                    const tableWidth = table.scrollWidth;
            
                    if (tableWidth > containerWidth) {
                        currentScale = containerWidth / tableWidth;
                    } else {
                        currentScale = 1.0;
                    }
                    applyScale();
                });
            }

            if (resetBtn) {
                resetBtn.addEventListener('click', () => {
                    currentScale = initialScale;
                    zoomContainer.style.transform = 'none';
                });
            }

            if (scrollLeftBtn && scrollRightBtn) {
                const scrollAmount = 300; 

                scrollLeftBtn.addEventListener('click', () => {
                    tableWrapper.scrollBy({
                        left: -scrollAmount,
                        behavior: 'smooth'
                    });
                });

                scrollRightBtn.addEventListener('click', () => {
                    tableWrapper.scrollBy({
                        left: scrollAmount,
                        behavior: 'smooth'
                    });
                });
            }
        }
    }
    
    /**
     * =================================================
     * Finalize & Reverse Week Actions
     * =================================================
     */
    const finalizeForm = document.getElementById('kf-finalize-form');
    if (finalizeForm) {
        finalizeForm.addEventListener('submit', function (event) {
            const confirmation = confirm('Are you sure you want to finalize this week? This will calculate all scores and cannot be easily undone.');
            if (!confirmation) {
                event.preventDefault();
            }
        });
    }

    const reverseButton = document.getElementById('kf-reverse-finalize-btn');
    if (reverseButton) {
        reverseButton.addEventListener('click', function() {
            const confirmation = confirm('Are you sure you want to reverse this week\'s finalization? This will delete all scores for this week and revert its status to Published.');
            if (confirmation) {
                const weekId = this.dataset.weekId;
                const nonce = document.getElementById('kf_reverse_nonce_field').value;
                
                const formData = new FormData();
                formData.append('action', 'kf_reverse_week');
                formData.append('week_id', weekId);
                formData.append('nonce', nonce);

                document.body.style.cursor = 'wait';

                fetch(kf_ajax_data.ajax_url, {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        window.location.reload();
                    } else {
                        showKFCustomAlert('Error: ' + (data.data.message || 'Unknown error.'));
                        document.body.style.cursor = 'default';
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showKFCustomAlert('An unexpected error occurred while reversing the week.');
                    document.body.style.cursor = 'default';
                });
            }
        });
    }

    /**
     * =================================================
     * Player Order Drag-and-Drop
     * =================================================
     */
    const sortableList = document.getElementById('kf-sortable-players');
    if (sortableList) {
        const saveButton = document.getElementById('kf-save-player-order');
        const statusSpinner = document.getElementById('kf-order-status');
        let draggedItem = null;

        sortableList.addEventListener('dragstart', (e) => {
            draggedItem = e.target;
            setTimeout(() => { e.target.style.opacity = '0.5'; }, 0);
        });

        sortableList.addEventListener('dragend', (e) => {
            setTimeout(() => {
                if(draggedItem) {
                    draggedItem.style.opacity = '1';
                }
                draggedItem = null;
            }, 0);
        });

        sortableList.addEventListener('dragover', (e) => {
            e.preventDefault();
            const afterElement = getDragAfterElement(sortableList, e.clientY);
            if (afterElement == null) {
                sortableList.appendChild(draggedItem);
            } else {
                sortableList.insertBefore(draggedItem, afterElement);
            }
            saveButton.style.display = 'inline-block';
        });

        function getDragAfterElement(container, y) {
            const draggableElements = [...container.querySelectorAll('.kf-player-sort-item:not(.dragging)')];
            return draggableElements.reduce((closest, child) => {
                const box = child.getBoundingClientRect();
                const offset = y - box.top - box.height / 2;
                if (offset < 0 && offset > closest.offset) {
                    return { offset: offset, element: child };
                } else {
                    return closest;
                }
            }, { offset: Number.NEGATIVE_INFINITY }).element;
        }

        let isSavingOrder = false; // Guard against double-submit
        saveButton.addEventListener('click', () => {
            if (isSavingOrder) return;
            isSavingOrder = true;
            saveButton.style.display = 'none';
            statusSpinner.style.display = 'inline-block';

            const orderedUserIds = [];
            const playerItems = sortableList.querySelectorAll('.kf-player-sort-item');
            playerItems.forEach(item => {
                orderedUserIds.push(item.dataset.userId);
            });

            const formData = new FormData();
            formData.append('action', 'kf_save_player_order');
            formData.append('nonce', window.kf_ajax_data.nonce);
            formData.append('season_id', window.kf_ajax_data.active_season_id);
            formData.append('player_order', JSON.stringify(orderedUserIds));

            fetch(window.kf_ajax_data.ajax_url, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                isSavingOrder = false;
                if (data.success) {
                    statusSpinner.textContent = 'Order Saved!';
                    setTimeout(() => {
                        statusSpinner.style.display = 'none';
                        statusSpinner.textContent = 'Saving...';
                    }, 2000);
                } else {
                    showKFCustomAlert('Error saving order: ' + data.data.message);
                    statusSpinner.style.display = 'none';
                    saveButton.style.display = 'inline-block';
                }
            })
            .catch(() => {
                isSavingOrder = false;
                statusSpinner.style.display = 'none';
                saveButton.style.display = 'inline-block';
                showKFCustomAlert('Network error saving order. Please try again.');
            });
        });
    }

    /**
     * =================================================
     * Custom Alert Modal (replaces standard alert())
     * =================================================
     */
    function showKFCustomAlert(message) {
        let modal = document.getElementById('kf-custom-alert');
        if (!modal) {
            modal = document.createElement('div');
            modal.id = 'kf-custom-alert';
            modal.style.cssText = 'position: fixed; z-index: 9999; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.4); display: flex; align-items: center; justify-content: center;';
            
            const modalContent = document.createElement('div');
            modalContent.style.cssText = 'background-color: #fefefe; margin: auto; padding: 20px; border: 1px solid #888; width: 80%; max-width: 400px; text-align: center; border-radius: 8px; box-shadow: 0 4px 8px 0 rgba(0,0,0,0.2);';
            
            const messageP = document.createElement('p');
            messageP.id = 'kf-alert-message';
            messageP.style.marginBottom = '20px';
            
            const closeButton = document.createElement('button');
            closeButton.textContent = 'OK';
            closeButton.className = 'kf-button';
            closeButton.onclick = function() {
                modal.style.display = 'none';
            };
            
            modalContent.appendChild(messageP);
            modalContent.appendChild(closeButton);
            modal.appendChild(modalContent);
            document.body.appendChild(modal);
        }

        document.getElementById('kf-alert-message').textContent = message;
        modal.style.display = 'flex';
    }

    // Optional: You can override the default window.alert if you want to use this modal everywhere.
    // window.alert = showKFCustomAlert;

    /**
     * =================================================
     * Live Deadline Countdown
     * Scans for [data-deadline] elements and updates
     * a .kf-deadline-countdown span inside them.
     * =================================================
     */
    function initDeadlineCountdown() {
        var deadlineEls = document.querySelectorAll('[data-deadline]');
        if (!deadlineEls.length) return;

        function updateCountdowns() {
            var now = new Date();
            deadlineEls.forEach(function(el) {
                var dl = el.getAttribute('data-deadline');
                if (!dl) return;
                var target = new Date(dl);
                var chip = el.querySelector('.kf-deadline-countdown');
                if (!chip) return;

                var diff = target - now;
                if (diff <= 0) {
                    chip.textContent = 'Deadline passed';
                    chip.className = 'kf-deadline-countdown kf-countdown-critical';
                    return;
                }
                var totalMin = Math.floor(diff / 60000);
                var days  = Math.floor(totalMin / 1440);
                var hours = Math.floor((totalMin % 1440) / 60);
                var mins  = totalMin % 60;

                var text = '';
                if (days > 0)       text = days + 'd ' + hours + 'h remaining';
                else if (hours > 0) text = hours + 'h ' + mins + 'm remaining';
                else                text = mins + 'm remaining';

                chip.textContent = text;
                chip.className = 'kf-deadline-countdown' +
                    (days === 0 && hours < 2 && hours > 0 ? ' kf-countdown-urgent' : '') +
                    (days === 0 && hours === 0 ? ' kf-countdown-critical' : '');
            });
        }
        updateCountdowns();
        setInterval(updateCountdowns, 60000);
    }
    initDeadlineCountdown();

    /**
     * =================================================
     * Frozen table header
     * =================================================
     * The week summary header is two rows deep — player names, then Pick/Points.
     * Row one pins at top:0; row two has to pin at row one's height, and that
     * height is not a constant: it carries live subtotals, award badges and the
     * DD badge, and Compact mode changes it outright. So measure it and publish
     * it as --kf-thead-row1-h; the CSS reads it for row two's offset.
     *
     * Deliberately event-driven, not observed. An observer here would be watching
     * the same subtree it writes into, which is the trap that froze Week Setup.
     */
    const frozenWrapper = document.querySelector('.kf-table-wrapper.kf-table-frozen');
    if (frozenWrapper) {
        const syncHeaderOffset = () => {
            const firstRow = frozenWrapper.querySelector('.kf-table thead tr:first-child');
            if (!firstRow) return;
            const h = Math.round(firstRow.getBoundingClientRect().height);
            if (h > 0) frozenWrapper.style.setProperty('--kf-thead-row1-h', h + 'px');
        };

        syncHeaderOffset();
        // Web fonts and the zoom transform both land after first paint.
        window.addEventListener('load', syncHeaderOffset);

        let resizeTimer = null;
        window.addEventListener('resize', () => {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(syncHeaderOffset, 150);
        });

        // Hide points / Game detail / Compact and the zoom buttons all change the
        // header's height. Re-measure after the class or transform has been applied.
        document.querySelectorAll(
            '#kf-view-hide-points, #kf-view-detail, #kf-view-compact'
        ).forEach(el => el.addEventListener('change', () => setTimeout(syncHeaderOffset, 0)));

        ['kf-zoom-in', 'kf-zoom-out', 'kf-fit-view', 'kf-reset-view'].forEach(id => {
            const btn = document.getElementById(id);
            if (btn) btn.addEventListener('click', () => setTimeout(syncHeaderOffset, 0));
        });
    }

    /**
     * =================================================
     * Finalize Week Guard
     * Prevent finalizing if any result is still missing.
     * The PHP renders data-results-complete="1|0" on the
     * finalize form wrapper div.
     * =================================================
     */
    const finalizeWrapper = document.getElementById('kf-finalize-wrapper');
    if (finalizeWrapper) {
        var complete = finalizeWrapper.getAttribute('data-results-complete');
        if (complete !== '1') {
            var finalizeBtn = finalizeWrapper.querySelector('button[name="action"]');
            if (finalizeBtn) {
                finalizeBtn.disabled = true;
                finalizeBtn.title = 'Enter all game results before finalizing.';
                finalizeBtn.style.opacity = '0.5';
                finalizeBtn.style.cursor = 'not-allowed';
                // Insert a small note next to the button
                var note = document.createElement('span');
                note.style.cssText = 'font-size:0.82em;color:#b45309;margin-left:10px;';
                note.textContent = 'Enter all results first';
                finalizeBtn.insertAdjacentElement('afterend', note);
            }
        }
    }
});
/* =========================================================================
 * Week Summary — Pick Compare
 *
 * Highlights where players disagreed, either against one chosen player or
 * against the field. Entirely presentational: it reads data-kf-pick /
 * data-kf-player attributes already rendered on the cells, writes only CSS
 * classes, and sends nothing to the server.
 *
 * The highlight works in OUTLINE and OPACITY, never background colour. The
 * summary table already uses background to mean win, loss, tie and live —
 * a second background layer would destroy that reading.
 * ========================================================================= */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var bar   = document.getElementById('kf-compare-bar');
        var table = document.getElementById('kf-summary-table');
        if (!bar || !table) { return; }

        var legendEl  = document.getElementById('kf-compare-legend');
        var targetSel = document.getElementById('kf-compare-target');
        var splitOnly = document.getElementById('kf-compare-split-only');
        var buttons   = bar.querySelectorAll('.kf-compare-btn');

        var mode   = 'off';
        var target = targetSel ? targetSel.value : bar.getAttribute('data-current-player');

        // Only rows carrying pick cells take part. The tiebreaker row is rendered separately
        // and has none, so it is excluded without needing a special case.
        function comparableRows() {
            return Array.prototype.filter.call(
                table.querySelectorAll('tbody tr'),
                function (tr) { return tr.querySelector('td.kf-pick-cell[data-kf-pick]'); }
            );
        }

        function pickCells(tr) {
            // BPOW columns are a second pick set for one player and are not comparable
            // against everyone else's single set, so they stay out of it.
            return Array.prototype.filter.call(
                tr.querySelectorAll('td.kf-pick-cell[data-kf-pick]'),
                function (td) { return !td.classList.contains('kf-bpow-column'); }
            );
        }

        function clear() {
            table.querySelectorAll('.kf-cmp-diff, .kf-cmp-same, .kf-cmp-minority, .kf-cmp-majority')
                .forEach(function (el) {
                    el.classList.remove('kf-cmp-diff', 'kf-cmp-same', 'kf-cmp-minority', 'kf-cmp-majority');
                });
            table.querySelectorAll('.kf-cmp-meter').forEach(function (el) { el.remove(); });
            comparableRows().forEach(function (tr) {
                tr.classList.remove('kf-cmp-unanimous', 'kf-cmp-hidden');
            });
        }

        function pointsCellFor(td) {
            var next = td.nextElementSibling;
            return (next && next.classList.contains('kf-points-cell')) ? next : null;
        }

        function apply() {
            clear();
            table.setAttribute('data-kf-compare', mode);
            if (mode === 'off') { renderLegend(); return; }

            comparableRows().forEach(function (tr) {
                var cells = pickCells(tr);
                if (!cells.length) { return; }

                var counts = {};
                cells.forEach(function (td) {
                    var v = td.getAttribute('data-kf-pick');
                    if (v) { counts[v] = (counts[v] || 0) + 1; }
                });

                var teams = Object.keys(counts);
                var unanimous = teams.length <= 1;
                if (unanimous) { tr.classList.add('kf-cmp-unanimous'); }

                var topCount = 0;
                teams.forEach(function (t) { if (counts[t] > topCount) { topCount = counts[t]; } });

                var reference = null;
                if (mode === 'vsyou') {
                    for (var i = 0; i < cells.length; i++) {
                        if (cells[i].getAttribute('data-kf-player') === String(target)) {
                            reference = cells[i].getAttribute('data-kf-pick');
                            break;
                        }
                    }
                }

                cells.forEach(function (td) {
                    var value = td.getAttribute('data-kf-pick');
                    var pts   = pointsCellFor(td);
                    var isRef = td.getAttribute('data-kf-player') === String(target);

                    if (mode === 'vsyou') {
                        if (!reference || isRef || !value) { return; }
                        if (value === reference) {
                            td.classList.add('kf-cmp-same');
                            if (pts) { pts.classList.add('kf-cmp-same'); }
                        } else {
                            td.classList.add('kf-cmp-diff');
                        }
                    } else {
                        if (!value) { return; }
                        if (unanimous || counts[value] === topCount) {
                            td.classList.add('kf-cmp-majority');
                            if (pts) { pts.classList.add('kf-cmp-majority'); }
                        } else {
                            td.classList.add('kf-cmp-minority');
                        }
                    }
                });

                if (mode === 'consensus') {
                    var firstCell = tr.querySelector('td');
                    if (firstCell && !firstCell.querySelector('.kf-cmp-meter')) {
                        var meter = document.createElement('div');
                        meter.className = 'kf-cmp-meter';

                        var bar2 = document.createElement('div');
                        bar2.className = 'kf-cmp-bar';
                        var fill = document.createElement('i');
                        fill.style.width = Math.round((topCount / cells.length) * 100) + '%';
                        bar2.appendChild(fill);

                        var txt = document.createElement('span');
                        txt.className = 'kf-cmp-split';
                        if (unanimous) {
                            txt.textContent = 'unanimous';
                            txt.classList.add('kf-cmp-split-flat');
                        } else {
                            txt.textContent = teams.map(function (t) { return counts[t]; })
                                                   .sort(function (a, b) { return b - a; })
                                                   .join('–') + ' split';
                        }

                        meter.appendChild(bar2);
                        meter.appendChild(txt);
                        firstCell.appendChild(meter);
                    }
                }
            });

            applySplitFilter();
            renderLegend();
        }

        function applySplitFilter() {
            var on = splitOnly && splitOnly.checked && mode !== 'off';
            comparableRows().forEach(function (tr) {
                tr.classList.toggle('kf-cmp-hidden', on && tr.classList.contains('kf-cmp-unanimous'));
            });
        }

        function renderLegend() {
            if (!legendEl) { return; }
            if (mode === 'off') { legendEl.textContent = ''; return; }
            var diffLabel = mode === 'vsyou' ? 'Picked differently' : 'Minority pick';
            var sameLabel = mode === 'vsyou' ? 'Same as you' : 'With the majority';
            legendEl.innerHTML =
                '<span class="kf-cmp-key"><i class="kf-cmp-sw kf-cmp-sw-diff"></i>' + diffLabel + '</span>' +
                '<span class="kf-cmp-key"><i class="kf-cmp-sw kf-cmp-sw-same"></i>' + sameLabel + '</span>';
        }

        buttons.forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                mode = btn.getAttribute('data-kf-mode');
                buttons.forEach(function (b) {
                    b.setAttribute('aria-pressed', b === btn ? 'true' : 'false');
                });
                apply();
            });
        });

        if (targetSel) {
            targetSel.addEventListener('change', function () {
                target = targetSel.value;
                if (mode !== 'off') { apply(); }
            });
        }

        if (splitOnly) {
            splitOnly.addEventListener('change', applySplitFilter);
        }
    });
})();

/* =========================================================================
 * Week Summary — view controls
 *
 * Three levers on a table that gets very wide with ten players:
 *   Hide points  — drops the Points column for every player, roughly halving
 *                  the width. The single biggest win available.
 *   Game detail  — kickoff time / live score beside each game.
 *   Compact      — tighter padding and smaller type.
 *
 * Choices persist per browser in localStorage, wrapped in try/catch because
 * private windows and locked-down browsers throw on access.
 * ========================================================================= */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var table = document.getElementById('kf-summary-table');
        if (!table) { return; }

        var hidePoints = document.getElementById('kf-view-hide-points');
        var showDetail = document.getElementById('kf-view-detail');
        var compact    = document.getElementById('kf-view-compact');
        if (!hidePoints && !showDetail && !compact) { return; }

        var KEY = 'kfWeekSummaryView';

        function load() {
            try {
                var raw = window.localStorage.getItem(KEY);
                return raw ? JSON.parse(raw) : {};
            } catch (e) { return {}; }
        }

        function save(state) {
            try { window.localStorage.setItem(KEY, JSON.stringify(state)); } catch (e) { /* not fatal */ }
        }

        function apply() {
            var state = {
                hidePoints: !!(hidePoints && hidePoints.checked),
                detail:     !!(showDetail && showDetail.checked),
                compact:    !!(compact && compact.checked)
            };
            table.classList.toggle('kf-hide-points', state.hidePoints);
            table.classList.toggle('kf-hide-detail', !state.detail);
            table.classList.toggle('kf-compact', state.compact);

            // The player header spans Pick + Points; with Points hidden it spans one.
            //
            // Drive this from the colspan the markup shipped with, remembered on first run.
            // The old selector was th[colspan="2"], so the moment Hide points set them to 1 it
            // matched nothing and unticking could never put them back: the header cells stayed
            // half width for the life of the page while the data columns went back to full
            // width, so the names drifted further left across the row — the misaligned header
            // people were seeing. The setting is remembered per browser, so the page could also
            // come back in that state after a reload.
            //
            // Every cell that spans two columns is a player's Pick + Points: the header, the footer
            // totals, and the "Hidden" cells shown before the deadline. Only the header was
            // rewritten until 1.8.20, so with Hide points on each total sat one player to the right
            // of its name. The footer labels used to span two columns as well; they are single
            // cells now, which is what makes "colspan 2" mean "a player" without exception.
            table.querySelectorAll('th[colspan], td[colspan]').forEach(function (cell) {
                if (!cell.hasAttribute('data-kf-colspan')) {
                    cell.setAttribute('data-kf-colspan', cell.getAttribute('colspan') || '1');
                }
                if (cell.getAttribute('data-kf-colspan') === '2') {
                    cell.setAttribute('colspan', state.hidePoints ? '1' : '2');
                }
            });

            save(state);
        }

        var saved = load();
        if (hidePoints && typeof saved.hidePoints === 'boolean') { hidePoints.checked = saved.hidePoints; }
        if (showDetail && typeof saved.detail === 'boolean')     { showDetail.checked = saved.detail; }
        if (compact && typeof saved.compact === 'boolean')       { compact.checked = saved.compact; }

        [hidePoints, showDetail, compact].forEach(function (input) {
            if (input) { input.addEventListener('change', apply); }
        });

        apply();
    });
})();

/* =========================================================================
 * Week Summary — score change notice
 *
 * Scores land in the database from the cron; the page a viewer is looking at
 * is static HTML and knows nothing about it. This polls a cheap fingerprint
 * endpoint and, when the week's results actually change, offers a Reload.
 *
 * It deliberately does NOT re-render the table itself. Win/loss tinting, live
 * subtotals, the compare overlay and the scenario tool are all derived from
 * the same results; patching some of them and not others produces a page that
 * quietly disagrees with itself. A reload is honest and cheap.
 *
 * Polling pauses while the tab is hidden, so a forgotten tab costs nothing.
 * ========================================================================= */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var host = document.getElementById('kf-week-live-watch');
        if (!host || typeof kf_ajax_data === 'undefined') { return; }

        var weekId = host.getAttribute('data-week-id');
        if (!weekId) { return; }

        var INTERVAL = 60000;   // once a minute is plenty against a 15-minute cron
        var known = null;
        var timer = null;

        function showNotice(info) {
            if (document.getElementById('kf-live-notice')) { return; }
            var bar = document.createElement('div');
            bar.id = 'kf-live-notice';
            bar.className = 'kf-live-notice';
            bar.innerHTML =
                '<span>Scores have changed &mdash; ' + parseInt(info.resolved, 10) + ' of ' +
                parseInt(info.total, 10) + ' games now have a result.</span>' +
                '<button type="button" class="kf-button kf-button-action" id="kf-live-reload">Reload</button>';
            host.appendChild(bar);
            document.getElementById('kf-live-reload').addEventListener('click', function () {
                window.location.reload();
            });
        }

        function poll() {
            if (document.hidden) { return; }

            var fd = new FormData();
            fd.append('action', 'kf_week_state');
            fd.append('nonce', kf_ajax_data.nonce);
            fd.append('week_id', weekId);

            fetch(kf_ajax_data.ajax_url, { method: 'POST', body: fd })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (!res || !res.success) { return; }
                    if (known === null) { known = res.data.fingerprint; return; }
                    if (res.data.fingerprint !== known) {
                        known = res.data.fingerprint;
                        showNotice(res.data);
                        stop();   // one notice is enough; the reload picks up everything
                    }
                })
                .catch(function () { /* transient network trouble is not worth surfacing */ });
        }

        function start() {
            if (timer) { return; }
            timer = window.setInterval(poll, INTERVAL);
            poll();
        }

        function stop() {
            if (timer) { window.clearInterval(timer); timer = null; }
        }

        document.addEventListener('visibilitychange', function () {
            if (document.hidden) { stop(); } else { start(); }
        });

        start();
    });
})();
