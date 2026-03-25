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

        saveButton.addEventListener('click', () => {
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
});