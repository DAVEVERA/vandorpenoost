(function($) {
    'use strict';
    
    // Global variables
    let currentShiftId = null;
    let bulkMode = false;
    let selectedShifts = [];
    let selectedDays = [];
    let clipboardData = null;
    
    // Initialize on document ready
    $(document).ready(function() {
        initializeEventHandlers();
        initializeShortcuts();
        initializeMobileHandlers();
        initializePerformanceOptimizations();
        initializeAdvancedFeatures();
        
        // Initial status check
        updateManagerStatus();
    });
    
    function initializeEventHandlers() {
        // Add shift button
        $(document).on('click', '.add-shift-btn', function(e) {
            e.preventDefault();
            const employeeId = $(this).data('employee-id');
            const employeeName = $(this).data('employee-name');
            const day = $(this).data('day');
            openShiftModal(null, employeeId, employeeName, day);
        });
        
        // Edit shift button
        $(document).on('click', '.shift-edit', function(e) {
            e.preventDefault();
            e.stopPropagation();
            const shiftId = $(this).data('shift-id');
            editShift(shiftId);
        });
        
        // Delete shift button
        $(document).on('click', '.shift-delete', function(e) {
            e.preventDefault();
            e.stopPropagation();
            const shiftId = $(this).data('shift-id');
            deleteShift(shiftId);
        });
        
        // Modal close buttons
        $(document).on('click', '.modal-close, .modal-cancel', function() {
            closeModal();
        });
        
        // Modal background click
        $(document).on('click', '.pp-modal', function(e) {
            if (e.target === this) {
                closeModal();
            }
        });
        
        // Shift form submission
        $(document).on('submit', '#shift-form', function(e) {
            e.preventDefault();
            saveShift();
        });
        
        // Full day checkbox
        $(document).on('change', '#full-day', function() {
            const isFullDay = $(this).is(':checked');
            $('#start-time, #end-time').prop('disabled', isFullDay);
            if (isFullDay) {
                $('#start-time').val('08:00');
                $('#end-time').val('17:00');
            }
        });
        
        // Quick time buttons
        $(document).on('click', '.quick-time', function() {
            const time = $(this).data('time');
            const input = $(this).closest('.form-group').find('input[type="time"]');
            input.val(time);
        });
        
        // Shift type change
        $(document).on('change', '#shift-type', function() {
            const type = $(this).val();
            updateTimePresets(type);
            
            // Show custom input for Dave when Custom is selected
            if (type === 'Custom') {
                $('#custom-shift-type').show();
            } else {
                $('#custom-shift-type').hide();
            }
        });
        
        // Week/Year dropdowns - URL FIX
        $(document).on('change', '#pp-week-select, #pp-year-select', function() {
            const week = $('#pp-week-select').val();
            const year = $('#pp-year-select').val();
            
            const currentUrl = new URL(window.location.href);
            currentUrl.searchParams.set('week', week);
            currentUrl.searchParams.set('year', year);
            
            // FIX: Voeg 'page' parameter ALLEEN toe als we in de WP-Admin zitten
            if (window.location.href.includes('admin.php') && !currentUrl.searchParams.get('page')) {
                 currentUrl.searchParams.set('page', 'powerplanner');
            }
            
            window.location.href = currentUrl.toString();
        });
        
        // Populate from patterns
        $(document).on('click', '#pp-populate-patterns', function() {
            populateFromPatterns();
        });
        
        // Validate week
        $(document).on('click', '#pp-validate-week', function() {
            validateWeek();
        });
        
        // Export to Excel
        $(document).on('click', '#pp-export-excel', function() {
            exportToExcel();
        });
        
        // Detect patterns
        $(document).on('click', '#detect-patterns', function() {
            detectPatterns();
        });
        
        // Approve pattern
        $(document).on('click', '.approve-pattern', function() {
            const patternId = $(this).data('pattern-id');
            approvePattern(patternId);
        });
        
        // Save week button
        $(document).on('click', '#pp-save-week', function() {
            saveWeek();
        });
        
        // Bulk Operations
        $(document).on('click', '#pp-bulk-select', function() {
            toggleBulkMode();
        });
        
        $(document).on('click', '#bulk-apply-shift', function() {
            openBulkModal();
        });
        
        $(document).on('click', '#bulk-copy', function() {
            copySelectedShifts();
        });
        
        $(document).on('click', '#bulk-delete', function() {
            deleteSelectedShifts();
        });
        
        $(document).on('click', '#bulk-clear', function() {
            clearSelection();
        });
        
        $(document).on('click', '#bulk-apply', function() {
            applyBulkChanges();
        });
        
        // Smart Planning Tools
        $(document).on('click', '#pp-copy-week', function() {
            copyWeek();
        });
        
        $(document).on('click', '#pp-paste-week', function() {
            pasteWeek();
        });
        
        $(document).on('click', '#pp-clear-week', function() {
            clearWeek();
        });
        
        // Selection handlers
        $(document).on('change', '.shift-select, .day-select', function() {
            updateSelection();
        });
        
        // Settings Tab Navigation
        $(document).on('click', '.nav-tab', function(e) {
            e.preventDefault();
            const target = $(this).attr('href');
            switchTab(target);
        });
        
        // Settings Preset Buttons
        $(document).on('click', '.preset-btn', function() {
            const preset = $(this).data('preset');
            applyPreset(preset);
        });
        
        // Template Quick Actions
        $(document).on('click', '.template-btn', function() {
            const template = $(this).data('template');
            applyTemplate(template);
        });
        
        // Dagstart Selection
        $(document).on('change', '.dagstart-select', function() {
            const day = $(this).data('day');
            const week = $(this).data('week');
            const year = $(this).data('year');
            const employee = $(this).val();
            saveDagstartAssignment(week, year, day, employee);
        });
    }
    
    /**
     * Open shift modal
     */
    function openShiftModal(shiftId, employeeId, employeeName, day) {
        currentShiftId = shiftId;
        
        // Completely reset form and clear all values
        $('#shift-form')[0].reset();
        $('#shift-id').val(shiftId || '');
        $('#employee-id').val(employeeId);
        $('#employee-name').val(employeeName);
        $('#day-of-week').val(day);
        
        // Clear all form fields explicitly
        $('#shift-type').val('Inbound');
        $('#start-time').val('08:00');
        $('#end-time').val('17:00');
        $('#full-day').prop('checked', false);
        $('#buddy').val('');
        $('#note').val('');
        $('#recurring').prop('checked', false);
        
        // Update modal title
        const title = shiftId ? 
            `Dienst Bewerken - ${employeeName} - ${capitalizeFirst(day)}` : 
            `Dienst Toevoegen - ${employeeName} - ${capitalizeFirst(day)}`;
        $('#modal-title').text(title);
        
        // Load shift data if editing
        if (shiftId) {
            loadShiftData(shiftId);
        }
        
        // Show modal
        $('#pp-shift-modal').fadeIn(200);
    }
    
    /**
     * Close modal
     */
    function closeModal() {
        $('#pp-shift-modal').fadeOut(200);
        currentShiftId = null;
        
        // Clear all form data completely
        $('#shift-form')[0].reset();
        $('#shift-id').val('');
        $('#employee-id').val('');
        $('#employee-name').val('');
        $('#day-of-week').val('');
        $('#shift-type').val('Inbound');
        $('#start-time').val('08:00');
        $('#end-time').val('17:00');
        $('#full-day').prop('checked', false);
        $('#buddy').val('');
        $('#note').val('');
        $('#recurring').prop('checked', false);
    }
    
    /**
     * Load shift data for editing
     */
    function loadShiftData(shiftId) {
        $.ajax({
            url: powerplanner.ajax_url,
            type: 'POST',
            data: {
                action: 'powerplanner_get_shift',
                nonce: powerplanner.nonce,
                shift_id: shiftId
            },
            success: function(response) {
                if (response.success && response.data) {
                    const shift = response.data;
                    $('#shift-type').val(shift.shift_type);
                    $('#start-time').val(shift.start_time ? shift.start_time.substring(0, 5) : '08:00');
                    $('#end-time').val(shift.end_time ? shift.end_time.substring(0, 5) : '17:00');
                    $('#full-day').prop('checked', shift.full_day == 1);
                    $('#buddy').val(shift.buddy || '');
                    $('#note').val(shift.note || '');
                    
                    if (shift.full_day == 1) {
                        $('#start-time, #end-time').prop('disabled', true);
                    }
                }
            },
            error: function() {
                showNotification('Fout bij laden van dienst', 'error');
            }
        });
    }
    
    /**
     * Save shift
     */
    function saveShift() {
        const formData = $('#shift-form').serializeArray();
        const data = {
            action: 'powerplanner_save_shift',
            nonce: powerplanner.nonce
        };
        
        // Convert form data to object
        formData.forEach(function(item) {
            data[item.name] = item.value;
        });
        
        // Handle custom shift type for Dave
        if (data.shift_type === 'Custom') {
            const customType = $('#custom-shift-input').val().trim();
            if (customType) {
                data.shift_type = customType;
            } else {
                showNotification('Voer een custom shift type in', 'warning');
                return;
            }
        }
        
        // Handle checkboxes
        data.full_day = $('#full-day').is(':checked');
        data.recurring = $('#recurring').is(':checked');
        
        // Show loading
        const $submitBtn = $('#shift-form button[type="submit"]');
        const originalText = $submitBtn.text();
        $submitBtn.text(powerplanner.strings?.saving || 'Opslaan...').prop('disabled', true);
        
        $.ajax({
            url: powerplanner.ajax_url,
            type: 'POST',
            data: data,
            success: function(response) {
                if (response.success) {
                    closeModal();
                    showNotification(powerplanner.strings?.saved || 'Opgeslagen!', 'success');
                    // Update the UI without reloading the page
                    updateShiftInGrid(response.data);
                } else {
                    showNotification(response.data || powerplanner.strings?.error || 'Er is een fout opgetreden', 'error');
                }
            },
            error: function() {
                showNotification(powerplanner.strings?.error || 'Er is een fout opgetreden', 'error');
            },
            complete: function() {
                $submitBtn.text(originalText).prop('disabled', false);
            }
        });
    }
    
    /**
     * Edit shift
     */
    function editShift(shiftId) {
        const $shiftBlock = $(`.shift-block[data-shift-id="${shiftId}"]`);
        const $dayCell = $shiftBlock.closest('.day-cell');
        const employeeId = $dayCell.data('employee');
        const employeeName = $dayCell.closest('tr').find('.employee-cell strong').text();
        const day = $dayCell.data('day');
        
        openShiftModal(shiftId, employeeId, employeeName, day);
    }
    
    /**
     * Delete shift
     */
    function deleteShift(shiftId) {
        if (!confirm(powerplanner.strings?.confirm_delete || 'Weet je zeker dat je deze dienst wilt verwijderen?')) {
            return;
        }
        
        $.ajax({
            url: powerplanner.ajax_url,
            type: 'POST',
            data: {
                action: 'powerplanner_delete_shift',
                nonce: powerplanner.nonce,
                shift_id: shiftId
            },
            success: function(response) {
                if (response.success) {
                    $(`.shift-block[data-shift-id="${shiftId}"]`).fadeOut(300, function() {
                        $(this).remove();
                    });
                    showNotification('Dienst verwijderd', 'success');
                } else {
                    showNotification(powerplanner.strings?.error || 'Er is een fout opgetreden', 'error');
                }
            },
            error: function() {
                showNotification(powerplanner.strings?.error || 'Er is een fout opgetreden', 'error');
            }
        });
    }
    
    /**
     * Update shift in grid without page reload
     */
    function updateShiftInGrid(shiftData) {
        if (!shiftData) return;
        
        const employeeId = shiftData.employee_id;
        const day = shiftData.day_of_week;
        const $dayCell = $(`.day-cell[data-employee="${employeeId}"][data-day="${day}"]`);
        
        if (shiftData.id) {
            // Update existing shift
            const $existingShift = $(`.shift-block[data-shift-id="${shiftData.id}"]`);
            if ($existingShift.length) {
                $existingShift.find('.shift-title').text(shiftData.shift_type);
                if (!shiftData.full_day && shiftData.start_time && shiftData.end_time) {
                    $existingShift.find('.shift-time').text(`${shiftData.start_time} - ${shiftData.end_time}`);
                } else {
                    $existingShift.find('.shift-time').text('Hele dag');
                }
                if (shiftData.buddy) {
                    $existingShift.find('.shift-title').append(` & ${shiftData.buddy}`);
                }
                if (shiftData.note) {
                    $existingShift.find('.shift-note').text(shiftData.note);
                }
            }
        } else {
            // Add new shift
            const shiftClass = 'shift-' + shiftData.shift_type.toLowerCase().replace(' ', '-');
            const timeText = shiftData.full_day ? 'Hele dag' : `${shiftData.start_time} - ${shiftData.end_time}`;
            const buddyText = shiftData.buddy ? ` & ${shiftData.buddy}` : '';
            const noteText = shiftData.note ? `<div class="shift-note">${shiftData.note}</div>` : '';
            
            const shiftHtml = `
                <div class="shift-block ${shiftClass}" data-shift-id="${shiftData.id}">
                    <div class="shift-title">${shiftData.shift_type}${buddyText}</div>
                    <div class="shift-time">${timeText}</div>
                    ${noteText}
                    <div class="shift-actions">
                        <button class="shift-edit" data-shift-id="${shiftData.id}">✏️</button>
                        <button class="shift-delete" data-shift-id="${shiftData.id}">×</button>
                    </div>
                </div>
            `;
            
            $dayCell.find('.add-shift-btn').after(shiftHtml);
        }
        
        // Update statistics
        updateStatistics();
    }
    
    /**
     * Update statistics without page reload
     */
    function updateStatistics() {
        const totalShifts = $('.shift-block').length;
        const presentShifts = $('.shift-block').not('.shift-verlof, .shift-vakantie, .shift-verzuim').length;
        const absentShifts = $('.shift-block.shift-verlof, .shift-block.shift-vakantie, .shift-block.shift-verzuim').length;
        
        $('#total-scheduled').text(totalShifts);
        $('#total-present').text(presentShifts);
        $('#total-absent').text(absentShifts);
        
        // Calculate total hours
        let totalHours = 0;
        $('.shift-block').each(function() {
            const $this = $(this);
            if ($this.hasClass('shift-verlof') || $this.hasClass('shift-vakantie') || $this.hasClass('shift-verzuim')) {
                totalHours += 8; // Full day
            } else {
                const timeText = $this.find('.shift-time').text();
                if (timeText && timeText !== 'Hele dag') {
                    const times = timeText.split(' - ');
                    if (times.length === 2) {
                        const start = new Date('2000-01-01 ' + times[0]);
                        const end = new Date('2000-01-01 ' + times[1]);
                        const diff = (end - start) / (1000 * 60 * 60);
                        totalHours += diff;
                    }
                }
            }
        });
        
        $('#total-hours').text(Math.round(totalHours));
    }
    
    /**
     * Update time presets based on shift type
     */
    function updateTimePresets(type) {
        const presets = {
            'Verlof': { start: '08:00', end: '17:00', fullDay: true },
            'Vakantie': { start: '08:00', end: '17:00', fullDay: true },
            'Verzuim': { start: '08:00', end: '17:00', fullDay: true },
            'Tandarts': { start: '10:00', end: '11:00', fullDay: false },
            'Training': { start: '09:00', end: '13:00', fullDay: false }
        };
        
        if (presets[type]) {
            $('#start-time').val(presets[type].start);
            $('#end-time').val(presets[type].end);
            $('#full-day').prop('checked', presets[type].fullDay);
            $('#start-time, #end-time').prop('disabled', presets[type].fullDay);
        }
    }
    
    /**
     * Populate week from patterns
     */
    function populateFromPatterns() {
        const week = $('#pp-week-select').val() || new URLSearchParams(window.location.search).get('week');
        const year = $('#pp-year-select').val() || new URLSearchParams(window.location.search).get('year');
        
        $.ajax({
            url: powerplanner.ajax_url,
            type: 'POST',
            data: {
                action: 'powerplanner_populate_patterns',
                nonce: powerplanner.nonce,
                week: week,
                year: year
            },
            success: function(response) {
                if (response.success) {
                    showNotification(response.data.message, 'success');
                }
            },
            error: function() {
                showNotification('Fout bij vullen uit patronen', 'error');
            }
        });
    }
    
    /**
     * Validate week
     */
    function validateWeek() {
        const week = $('#pp-week-select').val() || new URLSearchParams(window.location.search).get('week');
        const year = $('#pp-year-select').val() || new URLSearchParams(window.location.search).get('year');
        
        $.ajax({
            url: powerplanner.ajax_url,
            type: 'POST',
            data: {
                action: 'powerplanner_validate_week',
                nonce: powerplanner.nonce,
                week: week,
                year: year
            },
            success: function(response) {
                if (response.success) {
                    if (response.data.valid) {
                        showNotification('✓ Planning is geldig', 'success');
                    } else {
                        const message = `⚠️ ${response.data.conflicts.length} waarschuwingen gevonden`;
                        showNotification(message, 'warning');
                    }
                }
            },
            error: function() {
                showNotification('Fout bij valideren', 'error');
            }
        });
    }
    
    /**
     * Export to Excel
     */
    function exportToExcel() {
        const week = $('#pp-week-select').val() || new URLSearchParams(window.location.search).get('week');
        const year = $('#pp-year-select').val() || new URLSearchParams(window.location.search).get('year');
        
        $.ajax({
            url: powerplanner.ajax_url,
            type: 'POST',
            data: {
                action: 'powerplanner_export_excel',
                nonce: powerplanner.nonce,
                week: week,
                year: year
            },
            success: function(response) {
                if (response.success) {
                    downloadCSV(response.data.csv, response.data.filename);
                }
            },
            error: function() {
                showNotification('Fout bij exporteren', 'error');
            }
        });
    }
    
    /**
     * Save week
     */
    function saveWeek() {
        showNotification('Week opgeslagen', 'success');
    }
    
    /**
     * Detect patterns
     */
    function detectPatterns() {
        $.ajax({
            url: powerplanner.ajax_url,
            type: 'POST',
            data: {
                action: 'powerplanner_detect_patterns',
                nonce: powerplanner.nonce
            },
            success: function(response) {
                if (response.success) {
                    showNotification(response.data.message, 'success');
                }
            },
            error: function() {
                showNotification('Fout bij detecteren patronen', 'error');
            }
        });
    }
    
    /**
     * Approve pattern
     */
    function approvePattern(patternId) {
        $.ajax({
            url: powerplanner.ajax_url,
            type: 'POST',
            data: {
                action: 'powerplanner_approve_pattern',
                nonce: powerplanner.nonce,
                pattern_id: patternId
            },
            success: function(response) {
                if (response.success) {
                    showNotification('Patroon goedgekeurd', 'success');
                }
            },
            error: function() {
                showNotification('Fout bij goedkeuren patroon', 'error');
            }
        });
    }
    
    /**
     * Show notification
     */
    function showNotification(message, type) {
        // Remove existing notifications
        $('.pp-notification').remove();
        
        const typeClasses = {
            'success': 'notice-success',
            'error': 'notice-error',
            'warning': 'notice-warning',
            'info': 'notice-info'
        };
        
        const $notification = $('<div class="notice pp-notification ' + typeClasses[type] + '" style="position: fixed; top: 50px; right: 20px; z-index: 10000; padding: 12px; min-width: 250px; box-shadow: 0 3px 10px rgba(0,0,0,0.2);"><p>' + message + '</p></div>');
        
        $('body').append($notification);
        
        setTimeout(function() {
            $notification.fadeOut(300, function() {
                $(this).remove();
            });
        }, 3000);
    }
    
    /**
     * Download CSV file
     */
    function downloadCSV(csv, filename) {
        const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
        const link = document.createElement('a');
        
        if (navigator.msSaveBlob) {
            // IE 10+
            navigator.msSaveBlob(blob, filename);
        } else {
            link.href = URL.createObjectURL(blob);
            link.download = filename;
            link.style.display = 'none';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }
    }
    
    /**
     * Capitalize first letter
     */
    function capitalizeFirst(string) {
        return string.charAt(0).toUpperCase() + string.slice(1);
    }
    
    /**
     * Debounce function for performance
     */
    function debounce(func, wait) {
        let timeout;
        return function executedFunction(...args) {
            const later = () => {
                clearTimeout(timeout);
                func(...args);
            };
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
        };
    }
    
    /**
     * Initialize keyboard shortcuts
     */
    function initializeShortcuts() {
        $(document).on('keydown', function(e) {
            // ESC to close modal
            if (e.keyCode === 27 && $('#pp-shift-modal').is(':visible')) {
                closeModal();
            }
            
            // Ctrl+S to save
            if (e.ctrlKey && e.keyCode === 83 && $('#pp-shift-modal').is(':visible')) {
                e.preventDefault();
                saveShift();
            }
        });
    }
    
    /**
     * Initialize mobile handlers
     */
    function initializeMobileHandlers() {
        // Detect touch device
        if ('ontouchstart' in window) {
            // Long press to edit shift on mobile
            let pressTimer;
            
            $(document).on('touchstart', '.shift-block', function() {
                const $this = $(this);
                pressTimer = setTimeout(function() {
                    const shiftId = $this.data('shift-id');
                    if (shiftId) {
                        editShift(shiftId);
                    }
                    // Vibrate for feedback if available
                    if (navigator.vibrate) {
                        navigator.vibrate(50);
                    }
                }, 600);
            });
            
            $(document).on('touchend touchcancel', '.shift-block', function() {
                clearTimeout(pressTimer);
            });
            
            // Swipe to delete on mobile
            let touchStartX = 0;
            let touchEndX = 0;
            
            $(document).on('touchstart', '.shift-block', function(e) {
                touchStartX = e.changedTouches[0].screenX;
            });
            
            $(document).on('touchend', '.shift-block', function(e) {
                touchEndX = e.changedTouches[0].screenX;
                handleSwipe($(this));
            });
            
            function handleSwipe($element) {
                const swipeDistance = touchStartX - touchEndX;
                if (swipeDistance > 100) {
                    // Swipe left - show delete option
                    const shiftId = $element.data('shift-id');
                    if (confirm('Deze dienst verwijderen?')) {
                        deleteShift(shiftId);
                    }
                }
            }
        }
    }
    
    /**
     * Initialize performance optimizations
     */
    function initializePerformanceOptimizations() {
        // Cache jQuery selectors
        const $window = $(window);
        
        // Optimize scroll performance
        let scrollTimeout;
        $window.on('scroll', function() {
            if (scrollTimeout) {
                clearTimeout(scrollTimeout);
            }
            scrollTimeout = setTimeout(function() {
                // Handle scroll events
            }, 100);
        });
        
        // Optimize resize performance
        const handleResize = debounce(function() {
            // Handle resize events
            const width = $window.width();
            if (width < 768) {
                // Mobile adjustments
                $('.pp-planning-grid').addClass('mobile-view');
            } else {
                $('.pp-planning-grid').removeClass('mobile-view');
            }
        }, 250);
        
        $window.on('resize', handleResize);
    }
    
    /**
     * Update manager status
     */
    function updateManagerStatus() {
        if (!powerplanner.api_url) return;
        
        $.ajax({
            url: powerplanner.api_url + 'manager-status',
            type: 'GET',
            success: function(response) {
                if (response.status) {
                    $('.manager-status-indicator')
                        .removeClass('status-available status-busy status-meeting status-external status-unavailable')
                        .addClass('status-' + response.status);
                    
                    const statusLabels = {
                        'available': 'Beschikbaar',
                        'busy': 'Bezig',
                        'meeting': 'In vergadering',
                        'external': 'Extern',
                        'unavailable': 'Niet beschikbaar'
                    };
                    
                    $('.status-label').text(statusLabels[response.status] || response.status);
                    
                    if (response.location) {
                        $('.status-location').text('(' + response.location + ')');
                    }
                }
            }
        });
    }
    
    /**
     * Drag and drop functionality for shifts
     */
    function initializeDragAndDrop() {
        if (typeof $.fn.sortable !== 'undefined') {
            $('.day-cell').sortable({
                connectWith: '.day-cell',
                placeholder: 'shift-placeholder',
                tolerance: 'pointer',
                update: function(event, ui) {
                    const shiftId = ui.item.data('shift-id');
                    const newDay = $(this).data('day');
                    const newEmployee = $(this).data('employee');
                    
                    if (shiftId) {
                        updateShiftDay(shiftId, newDay, newEmployee);
                    }
                }
            });
        }
    }
    
    /**
     * Update shift day via AJAX
     */
    function updateShiftDay(shiftId, newDay, newEmployee) {
        $.ajax({
            url: powerplanner.ajax_url,
            type: 'POST',
            data: {
                action: 'powerplanner_update_shift_day',
                nonce: powerplanner.nonce,
                shift_id: shiftId,
                day_of_week: newDay,
                employee_id: newEmployee
            },
            success: function(response) {
                if (response.success) {
                    showNotification('Dienst verplaatst', 'success');
                } else {
                    showNotification('Fout bij verplaatsen', 'error');
                    location.reload(); // Reload to reset state
                }
            },
            error: function() {
                showNotification('Fout bij verplaatsen', 'error');
                location.reload(); // Reload to reset state
            }
        });
    }
    
    /**
     * Initialize advanced features
     */
    function initializeAdvancedFeatures() {
        // Initialize drag and drop if available
        initializeDragAndDrop();
        
        // Initialize custom tooltips (no jQuery UI required)
        initializeCustomTooltips();
        
        // Initialize date pickers if available
        if (typeof $.fn.datepicker !== 'undefined') {
            $('.date-picker').datepicker({
                dateFormat: 'dd-mm-yy',
                firstDay: 1,
                showWeek: true,
                weekHeader: 'W'
            });
        }
    }
    
    /**
     * Initialize custom tooltips without jQuery UI
     */
    function initializeCustomTooltips() {
        // Create tooltip element
        const $tooltip = $('<div class="pp-tooltip"></div>').appendTo('body');
        
        // Show tooltip on hover
        $(document).on('mouseenter', '[title]', function(e) {
            const $this = $(this);
            const title = $this.attr('title');
            
            if (title) {
                $this.attr('data-original-title', title);
                $this.removeAttr('title');
                
                $tooltip.text(title).show();
                
                const offset = $this.offset();
                $tooltip.css({
                    top: offset.top - $tooltip.outerHeight() - 5,
                    left: offset.left + ($this.outerWidth() / 2) - ($tooltip.outerWidth() / 2)
                });
            }
        });
        
        // Hide tooltip on mouse leave
        $(document).on('mouseleave', '[data-original-title]', function() {
            const $this = $(this);
            const originalTitle = $this.attr('data-original-title');
            
            if (originalTitle) {
                $this.attr('title', originalTitle);
                $this.removeAttr('data-original-title');
            }
            
            $tooltip.hide();
        });
    }
    
    /**
     * Toggle bulk selection mode
     */
    function toggleBulkMode() {
        bulkMode = !bulkMode;
        
        if (bulkMode) {
            $('.shift-select, .day-select').show();
            $('.pp-bulk-toolbar').show();
            $('#pp-bulk-select').text('❌ Exit Bulk Mode').addClass('button-primary');
            $('.shift-block, .day-cell').addClass('selectable');
        } else {
            $('.shift-select, .day-select').hide().prop('checked', false);
            $('.pp-bulk-toolbar').hide();
            $('#pp-bulk-select').text('☑️ Bulk Select').removeClass('button-primary');
            $('.shift-block, .day-cell').removeClass('selectable selected');
            clearSelection();
        }
    }
    
    /**
     * Update selection count and UI
     */
    function updateSelection() {
        selectedShifts = [];
        selectedDays = [];
        
        $('.shift-select:checked').each(function() {
            const shiftId = $(this).data('shift-id');
            selectedShifts.push(shiftId);
            $(this).closest('.shift-block').addClass('selected');
        });
        
        $('.day-select:checked').each(function() {
            const employeeId = $(this).data('employee');
            const day = $(this).data('day');
            selectedDays.push({employee: employeeId, day: day});
            $(this).closest('.day-cell').addClass('selected');
        });
        
        const totalSelected = selectedShifts.length + selectedDays.length;
        $('.bulk-selected-count').text(`${totalSelected} geselecteerd`);
        
        // Update button states
        const hasSelection = totalSelected > 0;
        $('#bulk-apply-shift, #bulk-copy, #bulk-delete').prop('disabled', !hasSelection);
    }
    
    /**
     * Clear all selections
     */
    function clearSelection() {
        $('.shift-select, .day-select').prop('checked', false);
        $('.shift-block, .day-cell').removeClass('selected');
        selectedShifts = [];
        selectedDays = [];
        updateSelection();
    }
    
    /**
     * Open bulk operations modal
     */
    function openBulkModal() {
        if (selectedShifts.length === 0 && selectedDays.length === 0) {
            showNotification('Selecteer eerst items om bulk operaties uit te voeren', 'warning');
            return;
        }
        
        $('#pp-bulk-modal').fadeIn(200);
    }
    
    /**
     * Apply bulk changes to selected items
     */
    function applyBulkChanges() {
        const shiftType = $('#bulk-shift-type').val();
        const startTime = $('#bulk-start-time').val();
        const endTime = $('#bulk-end-time').val();
        const fullDay = $('#bulk-full-day').is(':checked');
        const buddy = $('#bulk-buddy').val();
        const note = $('#bulk-note').val();
        
        if (!shiftType) {
            showNotification('Selecteer een shift type', 'warning');
            return;
        }
        
        let processed = 0;
        const total = selectedShifts.length + selectedDays.length;
        
        // Process selected shifts
        selectedShifts.forEach(function(shiftId) {
            $.ajax({
                url: powerplanner.ajax_url,
                type: 'POST',
                data: {
                    action: 'powerplanner_bulk_update_shift',
                    nonce: powerplanner.nonce,
                    shift_id: shiftId,
                    shift_type: shiftType,
                    start_time: startTime,
                    end_time: endTime,
                    full_day: fullDay,
                    buddy: buddy,
                    note: note
                },
                success: function(response) {
                    processed++;
                    if (processed === total) {
                        showNotification('Bulk operatie voltooid', 'success');
                        $('#pp-bulk-modal').fadeOut(200);
                        clearSelection();
                        location.reload();
                    }
                },
                error: function() {
                    processed++;
                    if (processed === total) {
                        showNotification('Bulk operatie voltooid met enkele fouten', 'warning');
                    }
                }
            });
        });
        
        // Process selected days (create new shifts)
        selectedDays.forEach(function(dayData) {
            $.ajax({
                url: powerplanner.ajax_url,
                type: 'POST',
                data: {
                    action: 'powerplanner_bulk_create_shift',
                    nonce: powerplanner.nonce,
                    employee_id: dayData.employee,
                    day_of_week: dayData.day,
                    shift_type: shiftType,
                    start_time: startTime,
                    end_time: endTime,
                    full_day: fullDay,
                    buddy: buddy,
                    note: note,
                    week_number: $('#pp-week-select').val(),
                    year: $('#pp-year-select').val()
                },
                success: function(response) {
                    processed++;
                    if (processed === total) {
                        showNotification('Bulk operatie voltooid', 'success');
                        $('#pp-bulk-modal').fadeOut(200);
                        clearSelection();
                        location.reload();
                    }
                },
                error: function() {
                    processed++;
                    if (processed === total) {
                        showNotification('Bulk operatie voltooid met enkele fouten', 'warning');
                    }
                }
            });
        });
    }
    
    /**
     * Copy selected shifts to clipboard
     */
    function copySelectedShifts() {
        if (selectedShifts.length === 0) {
            showNotification('Selecteer eerst shifts om te kopiëren', 'warning');
            return;
        }
        
        clipboardData = {
            shifts: selectedShifts,
            type: 'shifts'
        };
        
        showNotification(`${selectedShifts.length} shifts gekopieerd naar clipboard`, 'success');
    }
    
    /**
     * Delete selected shifts
     */
    function deleteSelectedShifts() {
        if (selectedShifts.length === 0) {
            showNotification('Selecteer eerst shifts om te verwijderen', 'warning');
            return;
        }
        
        if (!confirm(`Weet je zeker dat je ${selectedShifts.length} shifts wilt verwijderen?`)) {
            return;
        }
        
        let processed = 0;
        selectedShifts.forEach(function(shiftId) {
            $.ajax({
                url: powerplanner.ajax_url,
                type: 'POST',
                data: {
                    action: 'powerplanner_delete_shift',
                    nonce: powerplanner.nonce,
                    shift_id: shiftId
                },
                success: function(response) {
                    processed++;
                    if (processed === selectedShifts.length) {
                        showNotification(`${selectedShifts.length} shifts verwijderd`, 'success');
                        clearSelection();
                        location.reload();
                    }
                }
            });
        });
    }
    
    /**
     * Copy entire week to clipboard
     */
    function copyWeek() {
        const week = $('#pp-week-select').val();
        const year = $('#pp-year-select').val();
        
        showNotification('Week wordt gekopieerd...', 'info');
        
        $.ajax({
            url: powerplanner.ajax_url,
            type: 'POST',
            data: {
                action: 'powerplanner_copy_week',
                nonce: powerplanner.nonce,
                week: week,
                year: year
            },
            success: function(response) {
                if (response.success) {
                    clipboardData = {
                        week_data: response.data,
                        source_week: week,
                        source_year: year,
                        type: 'week'
                    };
                    localStorage.setItem('powerplanner_clipboard', JSON.stringify(clipboardData));
                    showNotification(`Week ${week}-${year} gekopieerd (${response.data.length} shifts)`, 'success');
                    $('#pp-paste-week').prop('disabled', false).removeClass('button-disabled');
                } else {
                    showNotification('Geen shifts gevonden om te kopiëren', 'warning');
                }
            },
            error: function() {
                showNotification('Fout bij kopiëren van week', 'error');
            }
        });
    }
    
    /**
     * Paste week from clipboard
     */
    function pasteWeek() {
        // Check localStorage first
        const storedData = localStorage.getItem('powerplanner_clipboard');
        if (storedData) {
            clipboardData = JSON.parse(storedData);
        }
        
        if (!clipboardData || clipboardData.type !== 'week') {
            showNotification('Geen week data in clipboard', 'warning');
            return;
        }
        
        const currentWeek = $('#pp-week-select').val();
        const currentYear = $('#pp-year-select').val();
        const sourceInfo = `week ${clipboardData.source_week}-${clipboardData.source_year}`;
        
        if (!confirm(`Weet je zeker dat je ${sourceInfo} wilt plakken naar week ${currentWeek}-${currentYear}? Dit overschrijft de huidige planning.`)) {
            return;
        }
        
        showNotification('Week wordt geplakt...', 'info');
        
        $.ajax({
            url: powerplanner.ajax_url,
            type: 'POST',
            data: {
                action: 'powerplanner_paste_week',
                nonce: powerplanner.nonce,
                week: currentWeek,
                year: currentYear,
                week_data: clipboardData.week_data
            },
            success: function(response) {
                if (response.success) {
                    showNotification(`Week geplakt: ${response.data.created} shifts toegevoegd`, 'success');
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showNotification('Fout bij plakken: ' + (response.data || 'Onbekende fout'), 'error');
                }
            },
            error: function() {
                showNotification('Fout bij plakken van week', 'error');
            }
        });
    }
    
    /**
     * Clear entire week
     */
    function clearWeek() {
        if (!confirm('Weet je zeker dat je de hele week wilt wissen? Dit kan niet ongedaan worden gemaakt.')) {
            return;
        }
        
        const week = $('#pp-week-select').val();
        const year = $('#pp-year-select').val();
        
        $.ajax({
            url: powerplanner.ajax_url,
            type: 'POST',
            data: {
                action: 'powerplanner_clear_week',
                nonce: powerplanner.nonce,
                week: week,
                year: year
            },
            success: function(response) {
                if (response.success) {
                    showNotification('Week gewist', 'success');
                    location.reload();
                }
            },
            error: function() {
                showNotification('Fout bij wissen van week', 'error');
            }
        });
    }
    
    /**
     * Switch between settings tabs
     */
    function switchTab(target) {
        // Remove active class from all tabs and content
        $('.nav-tab').removeClass('nav-tab-active');
        $('.tab-content').removeClass('active');
        
        // Add active class to clicked tab and corresponding content
        $(`a[href="${target}"]`).addClass('nav-tab-active');
        $(target).addClass('active');
    }
    
    /**
     * Apply preset values to form fields
     */
    function applyPreset(preset) {
        const presets = {
            'morning': '08:00-09:30',
            'afternoon': '16:00-17:00',
            'lunch': '12:00-13:00'
        };
        
        if (presets[preset]) {
            const $textarea = $(this).closest('.peak-hours-config, .manager-hours-config').find('textarea');
            const currentValue = $textarea.val();
            const newValue = currentValue ? `${currentValue}\n${presets[preset]}` : presets[preset];
            $textarea.val(newValue);
        }
    }
    
    /**
     * Apply template to shift form
     */
    function applyTemplate(template) {
        const templates = {
            'inbound': {
                type: 'Inbound',
                start_time: '08:00',
                end_time: '17:00',
                full_day: false
            },
            'outbound': {
                type: 'Outbound',
                start_time: '09:00',
                end_time: '17:00',
                full_day: false
            },
            'backoffice': {
                type: 'Backoffice',
                start_time: '09:00',
                end_time: '17:00',
                full_day: false
            },
            'verlof': {
                type: 'Verlof',
                start_time: '08:00',
                end_time: '17:00',
                full_day: true
            }
        };
        
        if (templates[template]) {
            const t = templates[template];
            $('#shift-type').val(t.type);
            $('#start-time').val(t.start_time);
            $('#end-time').val(t.end_time);
            $('#full-day').prop('checked', t.full_day);
            
            if (t.full_day) {
                $('#start-time, #end-time').prop('disabled', true);
            } else {
                $('#start-time, #end-time').prop('disabled', false);
            }
            
            showNotification(`Template "${t.type}" toegepast`, 'success');
        }
    }
    
    function saveDagstartAssignment(week, year, day, employee) {
        $.ajax({
            url: powerplanner.ajax_url,
            type: 'POST',
            data: {
                action: 'powerplanner_save_dagstart',
                nonce: powerplanner.nonce,
                week: week,
                year: year,
                day: day,
                employee: employee
            },
            success: function(response) {
                if (response.success) {
                    showNotification(`Dagstart voorzitter ${day} opgeslagen`, 'success');
                } else {
                    showNotification('Fout bij opslaan dagstart', 'error');
                }
            },
            error: function() {
                showNotification('Fout bij opslaan dagstart', 'error');
            }
        });
    }
    
    // Template Management Functions
    function addNewTemplate() {
        const name = $('#new-template-name').val();
        const type = $('#new-template-type').val();
        const start = $('#new-template-start').val();
        const end = $('#new-template-end').val();
        const fullDay = $('#new-template-full-day').is(':checked');
        
        if (!name || !type) {
            showNotification('Vul template naam en type in', 'warning');
            return;
        }
        
        const templateKey = name.toLowerCase().replace(/\s+/g, '_');
        const templateHtml = `
            <div class="template-card">
                <div class="template-header">
                    <h4>${name}</h4>
                    <button type="button" class="button button-small template-delete" onclick="deleteTemplate('${templateKey}')">Verwijderen</button>
                </div>
                <div class="template-fields">
                    <label>Starttijd: <input type="time" name="shift_templates[${templateKey}][start_time]" value="${start}"></label>
                    <label>Eindtijd: <input type="time" name="shift_templates[${templateKey}][end_time]" value="${end}"></label>
                    <label>Type: <input type="text" name="shift_templates[${templateKey}][type]" value="${type}"></label>
                    <label><input type="checkbox" name="shift_templates[${templateKey}][full_day]" ${fullDay ? 'checked' : ''}> Hele dag</label>
                </div>
            </div>
        `;
        
        $('#template-grid').append(templateHtml);
        
        // Clear form
        $('#new-template-name').val('');
        $('#new-template-type').val('');
        $('#new-template-start').val('08:00');
        $('#new-template-end').val('17:00');
        $('#new-template-full-day').prop('checked', false);
        
        showNotification('Template toegevoegd', 'success');
    }
    
    function deleteTemplate(templateKey) {
        if (confirm('Weet je zeker dat je deze template wilt verwijderen?')) {
            $(`.template-card:has(input[name*="[${templateKey}]"])`).remove();
            showNotification('Template verwijderd', 'success');
        }
    }
    
    // Export & Backup Functions
    function exportWeekData() {
        const week = $('#export-week').val();
        const year = $('#export-year').val();
        
        if (!week || !year) {
            showNotification('Vul week en jaar in', 'warning');
            return;
        }
        
        window.location.href = powerplanner.ajax_url + '?action=powerplanner_export_excel&week=' + week + '&year=' + year + '&nonce=' + powerplanner.nonce;
    }
    
    function exportEmployees() {
        window.location.href = powerplanner.ajax_url + '?action=powerplanner_export_employees&nonce=' + powerplanner.nonce;
    }
    
    function exportSettings() {
        window.location.href = powerplanner.ajax_url + '?action=powerplanner_export_settings&nonce=' + powerplanner.nonce;
    }
    
    function createFullBackup() {
        if (confirm('Weet je zeker dat je een volledige backup wilt maken?')) {
            showNotification('Backup wordt gemaakt...', 'info');
            window.location.href = powerplanner.ajax_url + '?action=powerplanner_create_backup&nonce=' + powerplanner.nonce;
        }
    }
    
    function createBackup() {
        showNotification('Backup wordt gemaakt...', 'info');
    }
    
    function listBackups() {
        showNotification('Backup lijst wordt geladen...', 'info');
    }
    
    function restoreBackup() {
        showNotification('Backup herstel functie wordt geladen...', 'info');
    }
    
    function importData() {
        const file = $('#import-file')[0].files[0];
        if (!file) {
            showNotification('Selecteer eerst een bestand', 'warning');
            return;
        }
        
        const formData = new FormData();
        formData.append('action', 'powerplanner_import_data');
        formData.append('nonce', powerplanner.nonce);
        formData.append('file', file);
        
        $.ajax({
            url: powerplanner.ajax_url,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(response) {
                if (response.success) {
                    showNotification('Data succesvol geïmporteerd', 'success');
                } else {
                    showNotification('Fout bij importeren: ' + response.data, 'error');
                }
            }
        });
    }
    
    // Advanced Functions
    function clearCache() {
        if (confirm('Weet je zeker dat je de cache wilt wissen?')) {
            $.ajax({
                url: powerplanner.ajax_url,
                type: 'POST',
                data: {
                    action: 'powerplanner_clear_cache',
                    nonce: powerplanner.nonce
                },
                success: function(response) {
                    if (response.success) {
                        showNotification('Cache gewist', 'success');
                    } else {
                        showNotification('Fout bij wissen cache', 'error');
                    }
                }
            });
        }
    }
    
    function optimizeDatabase() {
        if (confirm('Weet je zeker dat je de database wilt optimaliseren?')) {
            showNotification('Database wordt geoptimaliseerd...', 'info');
            $.ajax({
                url: powerplanner.ajax_url,
                type: 'POST',
                data: {
                    action: 'powerplanner_optimize_database',
                    nonce: powerplanner.nonce
                },
                success: function(response) {
                    if (response.success) {
                        showNotification('Database geoptimaliseerd', 'success');
                    } else {
                        showNotification('Fout bij optimaliseren database', 'error');
                    }
                }
            });
        }
    }
    
    function resetPlugin() {
        if (confirm('WAARSCHUWING: Dit zal ALLE data verwijderen! Weet je zeker dat je de plugin wilt resetten?')) {
            if (confirm('Laatste waarschuwing: Dit kan NIET ongedaan worden gemaakt!')) {
                $.ajax({
                    url: powerplanner.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'powerplanner_reset_plugin',
                        nonce: powerplanner.nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            showNotification('Plugin gereset - pagina wordt herladen', 'success');
                            setTimeout(() => location.reload(), 2000);
                        } else {
                            showNotification('Fout bij resetten plugin', 'error');
                        }
                    }
                });
            }
        }
    }
    
    window.toggleConflicts = function() {
        const content = document.querySelector('.conflicts-content');
        const icon = document.querySelector('.toggle-icon');
        
        if (content.style.display === 'none') {
            content.style.display = 'block';
            icon.textContent = '▲';
        } else {
            content.style.display = 'none';
            icon.textContent = '▼';
        }
    }
    
    // Global function for employee selector
    window.changeEmployee = function(employeeId) {
        const url = new URL(window.location);
        url.searchParams.set('employee', employeeId);
        window.location.href = url.toString();
    }
    
    // Global function for week navigation
    window.changeWeek = function(week, year) {
        const url = new URL(window.location.href);
        url.searchParams.set('week', week);
        url.searchParams.set('year', year);
        window.location.href = url.toString();
    }
    
})(jQuery);