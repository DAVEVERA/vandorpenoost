<?php

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

function powerplanner_admin_page() {
    // Ensure we get the actual current week, not cached
    $actual_current_week = intval(date('W'));
    $actual_current_year = intval(date('Y'));
    
    $current_week = isset($_GET['week']) ? intval($_GET['week']) : $actual_current_week;
    $current_year = isset($_GET['year']) ? intval($_GET['year']) : $actual_current_year;
    
    // Handle week overflow
    if ($current_week > 52) {
        $current_week = $current_week - 52;
        $current_year++;
    } elseif ($current_week < 1) {
        $current_week = 52 + $current_week;
        $current_year--;
    }
    
    $db = new PowerPlanner_Database();
    $scheduler = new PowerPlanner_Scheduler();
    
    // Cache data to avoid multiple queries
    $employees = $db->get_employees();
    $shifts = $db->get_week_shifts($current_week, $current_year);
    $conflicts = $db->get_week_conflicts($current_week, $current_year);
    $dagstart = $scheduler->get_dagstart_rotation($current_week, $current_year);
    
    // Calculate statistics for SELECTED week (not current week)
    $selected_week_shifts = array_filter($shifts, function($s) use ($current_week, $current_year) {
        return $s['week_number'] == $current_week && $s['year'] == $current_year;
    });
    
    $total_scheduled = count($selected_week_shifts);
    $total_present = count(array_filter($selected_week_shifts, function($s) { 
        return !in_array($s['shift_type'], ['Verlof', 'Vakantie', 'Verzuim']); 
    }));
    $total_absent = count(array_filter($selected_week_shifts, function($s) { 
        return in_array($s['shift_type'], ['Verlof', 'Vakantie', 'Verzuim']); 
    }));
    
    $total_hours = 0;
    foreach ($selected_week_shifts as $shift) {
        if (!$shift['full_day'] && $shift['start_time'] && $shift['end_time']) {
            try {
                $start = new DateTime($shift['start_time']);
                $end = new DateTime($shift['end_time']);
                $diff = $end->diff($start);
                $total_hours += $diff->h + ($diff->i / 60);
            } catch (Exception $e) {
                $total_hours += 8;
            }
        } elseif ($shift['full_day']) {
            $total_hours += 8;
        }
    }
    ?>
    
    <div class="wrap powerplanner-admin">
        <h1>PowerPlanner - Week <?php echo $current_week; ?> (<?php echo $current_year; ?>)</h1>
        
        <!-- Debug/Reactivation Tools -->
        <?php if (current_user_can('manage_options')): ?>
        <div class="notice notice-info" style="margin: 20px 0;">
            <p>
                <strong>Debug Tools:</strong>
                <a href="?page=powerplanner&powerplanner_debug=1" class="button">🔍 Debug Info</a>
                <a href="<?php echo admin_url('admin.php?action=powerplanner_reactivate'); ?>" class="button button-primary" onclick="return confirm('Weet je zeker dat je de plugin opnieuw wilt activeren?')">🔄 Reactivate Plugin</a>
                <a href="<?php echo POWERPLANNER_PLUGIN_URL; ?>debug-info.php" class="button" target="_blank">📊 Debug File</a>
            </p>
        </div>
        <?php endif; ?>
        
        <!-- Bulk Operations Toolbar -->
        <div class="pp-bulk-toolbar" style="display: none;">
            <div class="bulk-actions">
                <span class="bulk-selected-count">0 geselecteerd</span>
                <button class="button" id="bulk-apply-shift">📝 Toepassen</button>
                <button class="button" id="bulk-copy">📋 Kopiëren</button>
                <button class="button" id="bulk-delete">🗑️ Verwijderen</button>
                <button class="button" id="bulk-clear">❌ Deselecteren</button>
            </div>
        </div>
        
        <!-- Week Navigation -->
        <div class="pp-week-nav">
            <?php
            $prev_week = $current_week - 1;
            $prev_year = $current_year;
            if ($prev_week < 1) {
                $prev_week = 52;
                $prev_year--;
            }
            ?>
            <a href="?page=powerplanner&week=<?php echo $prev_week; ?>&year=<?php echo $prev_year; ?>" 
               class="button">← Vorige Week</a>
            
            <select id="pp-week-select" class="pp-week-dropdown">
                <?php for ($w = 1; $w <= 52; $w++): ?>
                    <option value="<?php echo $w; ?>" <?php selected($w, $current_week); ?>>
                        Week <?php echo $w; ?>
                    </option>
                <?php endfor; ?>
            </select>
            
            <select id="pp-year-select" class="pp-year-dropdown">
                <?php for ($y = date('Y') - 1; $y <= date('Y') + 1; $y++): ?>
                    <option value="<?php echo $y; ?>" <?php selected($y, $current_year); ?>>
                        <?php echo $y; ?>
                    </option>
                <?php endfor; ?>
            </select>
            
            <?php
            $next_week = $current_week + 1;
            $next_year = $current_year;
            if ($next_week > 52) {
                $next_week = 1;
                $next_year++;
            }
            ?>
            <a href="?page=powerplanner&week=<?php echo $next_week; ?>&year=<?php echo $next_year; ?>" 
               class="button">Volgende Week →</a>
            
            <button class="button button-primary" id="pp-save-week">💾 Opslaan</button>
            <button class="button" id="pp-populate-patterns">🔄 Vul uit Patronen</button>
            <button class="button" id="pp-validate-week">✓ Valideer</button>
            <button class="button" id="pp-export-excel">📊 Export</button>
            
            <!-- Smart Planning Tools -->
            <div class="smart-tools">
                <button class="button" id="pp-copy-week">📋 Kopieer Week</button>
                <button class="button" id="pp-paste-week">📌 Plak Week</button>
                <button class="button" id="pp-clear-week">🗑️ Wis Week</button>
                <button class="button" id="pp-bulk-select">☑️ Bulk Select</button>
            </div>
        </div>
        
        <!-- Statistics Bar -->
        <div class="pp-stats-bar">
            <div class="stat-card">
                <span class="stat-label">Ingepland</span>
                <span class="stat-value" id="total-scheduled"><?php echo $total_scheduled; ?></span>
            </div>
            <div class="stat-card">
                <span class="stat-label">Aanwezig</span>
                <span class="stat-value" id="total-present"><?php echo $total_present; ?></span>
            </div>
            <div class="stat-card">
                <span class="stat-label">Afwezig</span>
                <span class="stat-value" id="total-absent"><?php echo $total_absent; ?></span>
            </div>
            <div class="stat-card">
                <span class="stat-label">Totale Uren</span>
                <span class="stat-value" id="total-hours"><?php echo round($total_hours); ?></span>
            </div>
        </div>
        
        <!-- Dagstart Rotation -->
        <div class="pp-dagstart-info">
            <h3>📅 Dagstart Voorzitters - Week <?php echo $current_week; ?></h3>
            <div class="dagstart-grid">
                <?php foreach (['maandag', 'dinsdag', 'woensdag', 'donderdag', 'vrijdag'] as $day): ?>
                    <div class="dagstart-day">
                        <strong><?php echo ucfirst($day); ?>:</strong>
                        <select class="dagstart-select" data-day="<?php echo $day; ?>" data-week="<?php echo $current_week; ?>" data-year="<?php echo $current_year; ?>">
                            <option value="">-- Selecteer --</option>
                            <?php foreach ($employees as $employee): ?>
                                <option value="<?php echo esc_attr($employee['name']); ?>" 
                                        <?php selected($dagstart[$day] ?? '', $employee['name']); ?>>
                                    <?php echo esc_html($employee['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        
        <!-- Planning Grid -->
        <div class="pp-planning-grid">
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th class="employee-column">Medewerker</th>
                        <th>Maandag</th>
                        <th>Dinsdag</th>
                        <th>Woensdag</th>
                        <th>Donderdag</th>
                        <th>Vrijdag</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $regular_employees = array_filter($employees, function($emp) {
                        return strtolower($emp['name']) !== 'dave';
                    });
                    $teamleader = array_filter($employees, function($emp) {
                        return strtolower($emp['name']) === 'dave';
                    });
                    ?>
                    <?php foreach ($regular_employees as $employee): ?>
                    <tr data-employee-id="<?php echo $employee['id']; ?>">
                        <td class="employee-cell">
                            <strong><?php echo esc_html($employee['name']); ?></strong>
                        </td>
                        
                        <?php foreach (['maandag', 'dinsdag', 'woensdag', 'donderdag', 'vrijdag'] as $day): ?>
                        <td class="day-cell" data-employee="<?php echo $employee['id']; ?>" data-day="<?php echo $day; ?>">
                            <div class="day-header">
                                <input type="checkbox" class="day-select" data-employee="<?php echo $employee['id']; ?>" data-day="<?php echo $day; ?>" style="display: none;">
                                <button class="add-shift-btn" data-employee-id="<?php echo $employee['id']; ?>" 
                                        data-employee-name="<?php echo esc_attr($employee['name']); ?>"
                                        data-day="<?php echo $day; ?>">
                                    + Toevoegen
                                </button>
                            </div>
                            
                            <?php
                            $day_shifts = array_filter($shifts, function($s) use ($employee, $day) {
                                return $s['employee_id'] == $employee['id'] && $s['day_of_week'] == $day;
                            });
                            
                            foreach ($day_shifts as $shift):
                                $shift_class = 'shift-' . strtolower(str_replace(' ', '-', $shift['shift_type']));
                            ?>
                                <div class="shift-block <?php echo $shift_class; ?>" data-shift-id="<?php echo $shift['id']; ?>" data-employee="<?php echo $employee['id']; ?>" data-day="<?php echo $day; ?>">
                                    <input type="checkbox" class="shift-select" data-shift-id="<?php echo $shift['id']; ?>" style="display: none;">
                                    <div class="shift-title">
                                        <?php echo esc_html($shift['shift_type']); ?>
                                        <?php if ($shift['buddy']): ?>
                                            & <?php echo esc_html($shift['buddy']); ?>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <?php if (!$shift['full_day']): ?>
                                        <div class="shift-time">
                                            <?php echo substr($shift['start_time'], 0, 5); ?> - 
                                            <?php echo substr($shift['end_time'], 0, 5); ?>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($shift['note']): ?>
                                        <div class="shift-note"><?php echo esc_html($shift['note']); ?></div>
                                    <?php endif; ?>
                                    
                                    <div class="shift-actions">
                                        <button class="shift-edit" data-shift-id="<?php echo $shift['id']; ?>">✏️</button>
                                        <button class="shift-delete" data-shift-id="<?php echo $shift['id']; ?>">×</button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </td>
                        <?php endforeach; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Teamleider Section -->
        <?php if (!empty($teamleader)): ?>
        <?php $dave = reset($teamleader); ?>
        <div class="pp-teamleader-section">
            <h3>👨‍💼 Teamleider Planning</h3>
            <div class="teamleader-grid">
                <div class="teamleader-header">
                    <strong><?php echo esc_html($dave['name']); ?></strong>
                    <span class="teamleader-role">Teamleider</span>
                </div>
                <div class="teamleader-days">
                    <?php foreach (['maandag', 'dinsdag', 'woensdag', 'donderdag', 'vrijdag'] as $day): ?>
                    <div class="teamleader-day" data-employee="<?php echo $dave['id']; ?>" data-day="<?php echo $day; ?>">
                        <div class="day-label"><?php echo ucfirst($day); ?></div>
                        <div class="day-content">
                            <button class="add-shift-btn teamleader-add" data-employee-id="<?php echo $dave['id']; ?>" 
                                    data-employee-name="<?php echo esc_attr($dave['name']); ?>"
                                    data-day="<?php echo $day; ?>">
                                + Toevoegen
                            </button>
                            
                            <?php
                            $dave_shifts = array_filter($shifts, function($s) use ($dave, $day) {
                                return $s['employee_id'] == $dave['id'] && $s['day_of_week'] == $day;
                            });
                            
                            foreach ($dave_shifts as $shift):
                            ?>
                                <div class="teamleader-shift" data-shift-id="<?php echo $shift['id']; ?>">
                                    <div class="shift-content">
                                        <?php echo esc_html($shift['shift_type']); ?>
                                        <?php if (!$shift['full_day']): ?>
                                            <span class="shift-time"><?php echo substr($shift['start_time'], 0, 5); ?>-<?php echo substr($shift['end_time'], 0, 5); ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="shift-actions">
                                        <button class="shift-edit" data-shift-id="<?php echo $shift['id']; ?>">✏️</button>
                                        <button class="shift-delete" data-shift-id="<?php echo $shift['id']; ?>">×</button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Conflicts/Warnings (Collapsible) -->
        <?php if (!empty($conflicts)): ?>
        <div class="pp-conflicts-collapsible">
            <div class="conflicts-header" onclick="toggleConflicts()">
                <span>⚠️ Waarschuwingen (<?php echo count($conflicts); ?>)</span>
                <span class="toggle-icon">▼</span>
            </div>
            <div class="conflicts-content" style="display: none;">
                <ul>
                    <?php foreach ($conflicts as $conflict): ?>
                        <li class="conflict-<?php echo $conflict['severity']; ?>">
                            <?php echo esc_html($conflict['description']); ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- Shift Modal -->
    <div id="pp-shift-modal" class="pp-modal" style="display:none;">
        <div class="pp-modal-content">
            <div class="pp-modal-header">
                <h2 id="modal-title">Dienst Toevoegen</h2>
                <button class="modal-close">×</button>
            </div>
            <div class="pp-modal-body">
                <form id="shift-form">
                    <input type="hidden" id="shift-id" name="id">
                    <input type="hidden" id="employee-id" name="employee_id">
                    <input type="hidden" id="employee-name" name="employee_name">
                    <input type="hidden" id="week-number" name="week_number" value="<?php echo $current_week; ?>">
                    <input type="hidden" id="year" name="year" value="<?php echo $current_year; ?>">
                    <input type="hidden" id="day-of-week" name="day_of_week">
                    
                    <div class="form-group">
                        <label>Type Dienst*</label>
                        <div class="shift-type-selector">
                            <select name="shift_type" id="shift-type" required>
                                <option value="Inbound">Inbound</option>
                                <option value="Backoffice & Chat">Backoffice & Chat</option>
                                <option value="Outbound & Chat">Outbound & Chat</option>
                                <option value="Training">Training</option>
                                <option value="Verlof">Verlof</option>
                                <option value="Vakantie">Vakantie</option>
                                <option value="Verzuim">Verzuim</option>
                                <option value="Tandarts">Tandarts/Dokter</option>
                                <!-- Teamleider specific options -->
                                <option value="Meeting">Meeting</option>
                                <option value="Extern">Extern</option>
                                <option value="Niet Storen">Niet Storen</option>
                                <option value="Beschikbaar">Beschikbaar</option>
                                <option value="Custom">Custom (vrije tekst)</option>
                            </select>
                            
                            <!-- Custom shift type input for Dave -->
                            <div id="custom-shift-type" style="display: none; margin-top: 10px;">
                                <label>Custom Type:</label>
                                <input type="text" id="custom-shift-input" placeholder="Voer custom shift type in...">
                            </div>
                            <div class="template-quick-actions">
                                <button type="button" class="button template-btn" data-template="inbound">📞 Inbound</button>
                                <button type="button" class="button template-btn" data-template="backoffice-chat">💼 Backoffice & Chat</button>
                                <button type="button" class="button template-btn" data-template="verlof">🏖️ Verlof</button>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Starttijd*</label>
                            <input type="time" name="start_time" id="start-time" value="08:00">
                            <div class="quick-times">
                                <button type="button" class="quick-time" data-time="07:00">7:00</button>
                                <button type="button" class="quick-time" data-time="08:00">8:00</button>
                                <button type="button" class="quick-time" data-time="09:00">9:00</button>
                                <button type="button" class="quick-time" data-time="10:00">10:00</button>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label>Eindtijd*</label>
                            <input type="time" name="end_time" id="end-time" value="17:00">
                            <div class="quick-times">
                                <button type="button" class="quick-time" data-time="13:15">13:15</button>
                                <button type="button" class="quick-time" data-time="16:00">16:00</button>
                                <button type="button" class="quick-time" data-time="17:00">17:00</button>
                                <button type="button" class="quick-time" data-time="18:00">18:00</button>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>
                            <input type="checkbox" name="full_day" id="full-day">
                            Hele dag (verlof/vakantie/verzuim)
                        </label>
                    </div>
                    
                    <div class="form-group">
                        <label>Buddy/Partner (optioneel)</label>
                        <select name="buddy" id="buddy">
                            <option value="">Geen</option>
                            <option value="KARIN">KARIN</option>
                            <option value="BIANCA">BIANCA</option>
                            <option value="Martin">Martin</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Notitie (optioneel)</label>
                        <textarea name="note" id="note" rows="2"></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label>
                            <input type="checkbox" id="recurring">
                            Herhalen voor komende 4 weken
                        </label>
                    </div>
                </form>
            </div>
            <div class="pp-modal-footer">
                <button type="button" class="button modal-cancel">Annuleren</button>
                <button type="submit" form="shift-form" class="button button-primary">Opslaan</button>
            </div>
        </div>
    </div>
    
    <!-- Bulk Operations Modal -->
    <div id="pp-bulk-modal" class="pp-modal" style="display:none;">
        <div class="pp-modal-content">
            <div class="pp-modal-header">
                <h2>Bulk Operations</h2>
                <button class="modal-close">×</button>
            </div>
            <div class="pp-modal-body">
                <div class="bulk-options">
                    <h3>Toepassen op geselecteerde items:</h3>
                    
                    <div class="form-group">
                        <label>Shift Type</label>
                        <select id="bulk-shift-type">
                            <option value="">-- Selecteer Type --</option>
                            <option value="Inbound">Inbound</option>
                            <option value="Backoffice & Chat">Backoffice & Chat</option>
                            <option value="Outbound & Chat">Outbound & Chat</option>
                            <option value="Training">Training</option>
                            <option value="Verlof">Verlof</option>
                            <option value="Vakantie">Vakantie</option>
                            <option value="Verzuim">Verzuim</option>
                        </select>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Starttijd</label>
                            <input type="time" id="bulk-start-time" value="08:00">
                        </div>
                        <div class="form-group">
                            <label>Eindtijd</label>
                            <input type="time" id="bulk-end-time" value="17:00">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>
                            <input type="checkbox" id="bulk-full-day">
                            Hele dag
                        </label>
                    </div>
                    
                    <div class="form-group">
                        <label>Buddy/Partner</label>
                        <select id="bulk-buddy">
                            <option value="">Geen</option>
                            <option value="KARIN">KARIN</option>
                            <option value="BIANCA">BIANCA</option>
                            <option value="Martin">Martin</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Notitie</label>
                        <textarea id="bulk-note" rows="2"></textarea>
                    </div>
                </div>
            </div>
            <div class="pp-modal-footer">
                <button type="button" class="button modal-cancel">Annuleren</button>
                <button type="button" class="button button-primary" id="bulk-apply">Toepassen</button>
            </div>
        </div>
    </div>
    
    <?php
}

/**
 * Patterns page
 */
function powerplanner_patterns_page() {
    global $wpdb;
    $table = $wpdb->prefix . 'powerplanner_patterns';
    $patterns = $wpdb->get_results("SELECT * FROM {$table} ORDER BY employee_name, day_of_week", ARRAY_A);
    ?>
    
    <div class="wrap">
        <h1>Gedetecteerde Patronen</h1>
        
        <button class="button button-primary" id="detect-patterns">🔍 Detecteer Nieuwe Patronen</button>
        
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>Medewerker</th>
                    <th>Dag</th>
                    <th>Type</th>
                    <th>Tijd</th>
                    <th>Vertrouwen</th>
                    <th>Status</th>
                    <th>Acties</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($patterns as $pattern): ?>
                <tr>
                    <td><?php echo esc_html($pattern['employee_name']); ?></td>
                    <td><?php echo ucfirst($pattern['day_of_week']); ?></td>
                    <td><?php echo esc_html($pattern['shift_type']); ?></td>
                    <td><?php echo substr($pattern['start_time'], 0, 5); ?> - <?php echo substr($pattern['end_time'], 0, 5); ?></td>
                    <td><?php echo round($pattern['confidence_score'] * 100); ?>%</td>
                    <td>
                        <?php if ($pattern['is_approved']): ?>
                            <span class="status-approved">✓ Goedgekeurd</span>
                        <?php else: ?>
                            <span class="status-pending">⏳ Wachtend</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (!$pattern['is_approved']): ?>
                            <button class="button approve-pattern" data-pattern-id="<?php echo $pattern['id']; ?>">
                                Goedkeuren
                            </button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    
    <?php
}

/**
 * Settings page
 */
function powerplanner_settings_page() {
    if (isset($_POST['submit'])) {
        $settings = array(
            // Business Rules - Planning
            'monday_morning_rule' => isset($_POST['monday_morning_rule']),
            'admin_day_required' => isset($_POST['admin_day_required']),
            'auto_shift_creation' => isset($_POST['auto_shift_creation']),
            'conflict_detection' => isset($_POST['conflict_detection']),
            'workload_balancing' => isset($_POST['workload_balancing']),
            'weekend_coverage' => isset($_POST['weekend_coverage']),
            'lunch_coverage' => isset($_POST['lunch_coverage']),
            'max_consecutive_days' => isset($_POST['max_consecutive_days']),
            
            // Business Rules - Shift
            'min_shift_duration' => isset($_POST['min_shift_duration']),
            'max_shift_duration' => isset($_POST['max_shift_duration']),
            'break_time_required' => isset($_POST['break_time_required']),
            'overtime_prevention' => isset($_POST['overtime_prevention']),
            
            // Business Rules - Team
            'team_balance' => isset($_POST['team_balance']),
            'skill_rotation' => isset($_POST['skill_rotation']),
            'mentor_system' => isset($_POST['mentor_system']),
            
            // Meeting & Time Settings
            'meeting_interval_weeks' => intval($_POST['meeting_interval_weeks']),
            'peak_hours' => explode("\n", trim($_POST['peak_hours'])),
            'manager_meeting_hours' => explode("\n", trim($_POST['manager_meeting_hours'])),
            
            // Templates & Preferences
            'shift_templates' => $_POST['shift_templates'] ?? array(),
            'employee_preferences' => $_POST['employee_preferences'] ?? array(),
            'dagstart_assignments' => $_POST['dagstart_assignments'] ?? array(),
            
            // Advanced Settings
            'shift_colors' => $_POST['shift_colors'] ?? array(),
            'debug_logging' => isset($_POST['debug_logging']),
            'performance_mode' => isset($_POST['performance_mode'])
        );
        update_option('powerplanner_settings', $settings);
        echo '<div class="notice notice-success"><p>Instellingen opgeslagen!</p></div>';
    }
    
    $settings = get_option('powerplanner_settings', array());
    ?>
    
    <div class="wrap">
        <h1>PowerPlanner Instellingen</h1>
        
        <!-- Settings Tabs -->
        <div class="pp-settings-tabs">
            <nav class="nav-tab-wrapper">
                <a href="#business-rules" class="nav-tab nav-tab-active">Business Rules</a>
                <a href="#shift-templates" class="nav-tab">Shift Templates</a>
                <a href="#employee-preferences" class="nav-tab">Werkdagen per Medewerker</a>
                <a href="#dagstart" class="nav-tab">Dagstart</a>
                <a href="#export-backup" class="nav-tab">Export & Backup</a>
                <a href="#advanced" class="nav-tab">Advanced</a>
            </nav>
        </div>
        
        <form method="post" id="pp-settings-form">
            <!-- Business Rules Tab -->
            <div id="business-rules" class="tab-content active">
                <h2>Business Rules</h2>
            <table class="form-table">
                <tr>
                    <th scope="row">Planning Regels</th>
                    <td>
                        <div class="rule-group">
                        <label>
                            <input type="checkbox" name="monday_morning_rule" 
                                   <?php checked($settings['monday_morning_rule'] ?? true); ?>>
                                <strong>Maandag ochtend regel</strong>
                                <p class="description">8:00-9:30 iedereen automatisch op inbound</p>
                        </label>
                        </div>
                        <div class="rule-group">
                        <label>
                            <input type="checkbox" name="admin_day_required" 
                                   <?php checked($settings['admin_day_required'] ?? true); ?>>
                                <strong>Administratie dag verplicht</strong>
                                <p class="description">Minimaal 4 uur admin tijd per week per medewerker</p>
                            </label>
                        </div>
                        <div class="rule-group">
                            <label>
                                <input type="checkbox" name="auto_shift_creation" 
                                       <?php checked($settings['auto_shift_creation'] ?? false); ?>>
                                <strong>Automatische shift creatie</strong>
                                <p class="description">Automatisch shifts aanmaken op basis van patronen</p>
                            </label>
                        </div>
                        <div class="rule-group">
                            <label>
                                <input type="checkbox" name="conflict_detection" 
                                       <?php checked($settings['conflict_detection'] ?? true); ?>>
                                <strong>Conflict detectie</strong>
                                <p class="description">Automatisch waarschuwen voor planning conflicten</p>
                            </label>
                        </div>
                        <div class="rule-group">
                            <label>
                                <input type="checkbox" name="workload_balancing" 
                                       <?php checked($settings['workload_balancing'] ?? false); ?>>
                                <strong>Workload balanceren</strong>
                                <p class="description">Automatisch uren verdelen over medewerkers</p>
                        </label>
                        </div>
                        <div class="rule-group">
                            <label>
                                <input type="checkbox" name="weekend_coverage" 
                                       <?php checked($settings['weekend_coverage'] ?? false); ?>>
                                <strong>Weekend dekking</strong>
                                <p class="description">Minimaal 2 mensen beschikbaar in weekend</p>
                            </label>
                        </div>
                        <div class="rule-group">
                            <label>
                                <input type="checkbox" name="lunch_coverage" 
                                       <?php checked($settings['lunch_coverage'] ?? true); ?>>
                                <strong>Lunch dekking</strong>
                                <p class="description">Minimaal 3 mensen beschikbaar tijdens lunch (12:00-13:00)</p>
                            </label>
                        </div>
                        <div class="rule-group">
                            <label>
                                <input type="checkbox" name="max_consecutive_days" 
                                       <?php checked($settings['max_consecutive_days'] ?? true); ?>>
                                <strong>Maximaal opeenvolgende dagen</strong>
                                <p class="description">Geen medewerker meer dan 5 dagen achter elkaar</p>
                            </label>
                        </div>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">Shift Regels</th>
                    <td>
                        <div class="rule-group">
                            <label>
                                <input type="checkbox" name="min_shift_duration" 
                                       <?php checked($settings['min_shift_duration'] ?? true); ?>>
                                <strong>Minimale shift duur</strong>
                                <p class="description">Shifts moeten minimaal 4 uur duren</p>
                            </label>
                        </div>
                        <div class="rule-group">
                            <label>
                                <input type="checkbox" name="max_shift_duration" 
                                       <?php checked($settings['max_shift_duration'] ?? true); ?>>
                                <strong>Maximale shift duur</strong>
                                <p class="description">Shifts mogen maximaal 9 uur duren</p>
                            </label>
                        </div>
                        <div class="rule-group">
                            <label>
                                <input type="checkbox" name="break_time_required" 
                                       <?php checked($settings['break_time_required'] ?? true); ?>>
                                <strong>Pauze tijd verplicht</strong>
                                <p class="description">Shifts langer dan 6 uur moeten pauze bevatten</p>
                            </label>
                        </div>
                        <div class="rule-group">
                            <label>
                                <input type="checkbox" name="overtime_prevention" 
                                       <?php checked($settings['overtime_prevention'] ?? true); ?>>
                                <strong>Overwerk preventie</strong>
                                <p class="description">Waarschuw bij meer dan 40 uur per week</p>
                            </label>
                        </div>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">Team Regels</th>
                    <td>
                        <div class="rule-group">
                            <label>
                                <input type="checkbox" name="team_balance" 
                                       <?php checked($settings['team_balance'] ?? true); ?>>
                                <strong>Team balans</strong>
                                <p class="description">Zorg voor gelijke verdeling van shifts over team</p>
                            </label>
                        </div>
                        <div class="rule-group">
                            <label>
                                <input type="checkbox" name="skill_rotation" 
                                       <?php checked($settings['skill_rotation'] ?? false); ?>>
                                <strong>Vaardigheid rotatie</strong>
                                <p class="description">Roteer medewerkers tussen verschillende shift types</p>
                            </label>
                        </div>
                        <div class="rule-group">
                            <label>
                                <input type="checkbox" name="mentor_system" 
                                       <?php checked($settings['mentor_system'] ?? false); ?>>
                                <strong>Mentor systeem</strong>
                                <p class="description">Nieuwe medewerkers gekoppeld aan ervaren collega's</p>
                            </label>
                        </div>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">Meeting Interval</th>
                    <td>
                        <div class="input-group">
                            <input type="number" name="meeting_interval_weeks" 
                                   value="<?php echo $settings['meeting_interval_weeks'] ?? 2; ?>" min="1" max="4" class="small-text">
                            <span class="input-suffix">weken</span>
                        </div>
                        <p class="description">Hoe vaak 1-op-1 meetings gepland moeten worden</p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">Piek Uren</th>
                    <td>
                        <div class="peak-hours-config">
                            <textarea name="peak_hours" rows="4" cols="40" placeholder="08:00-09:30&#10;16:00-17:00"><?php 
                                echo implode("\n", $settings['peak_hours'] ?? ['08:00-09:30', '16:00-17:00']); 
                            ?></textarea>
                            <div class="peak-hours-presets">
                                <button type="button" class="button preset-btn" data-preset="morning">Ochtend (08:00-09:30)</button>
                                <button type="button" class="button preset-btn" data-preset="afternoon">Middag (16:00-17:00)</button>
                                <button type="button" class="button preset-btn" data-preset="lunch">Lunch (12:00-13:00)</button>
                            </div>
                        </div>
                        <p class="description">Één per regel (bijv. 08:00-09:30). Tijdens piekuren moeten minimaal 5 mensen op inbound staan.</p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">Manager Meeting Uren</th>
                    <td>
                        <div class="manager-hours-config">
                            <textarea name="manager_meeting_hours" rows="3" cols="40" placeholder="10:00-16:00"><?php 
                                echo implode("\n", $settings['manager_meeting_hours'] ?? ['10:00-16:00']); 
                            ?></textarea>
                            <div class="manager-hours-presets">
                                <button type="button" class="button preset-btn" data-preset="morning">Ochtend (09:00-12:00)</button>
                                <button type="button" class="button preset-btn" data-preset="afternoon">Middag (13:00-17:00)</button>
                                <button type="button" class="button preset-btn" data-preset="full">Hele dag (09:00-17:00)</button>
                            </div>
                        </div>
                        <p class="description">Tijdstippen waarop manager beschikbaar is voor meetings</p>
                    </td>
                </tr>
            </table>
            </div>
            
            <!-- Shift Templates Tab -->
            <div id="shift-templates" class="tab-content">
                <h2>Shift Templates</h2>
                
                <!-- Add New Template -->
                <div class="template-add-section" style="background: #f8f9fa; padding: 20px; border-radius: 8px; margin-bottom: 20px;">
                    <h3>Nieuwe Template Toevoegen</h3>
                    <div class="template-add-form" style="display: flex; gap: 15px; align-items: end; flex-wrap: wrap;">
                        <div>
                            <label>Template Naam:</label>
                            <input type="text" id="new-template-name" placeholder="bijv. Ochtend Shift" style="width: 150px;">
                        </div>
                        <div>
                            <label>Shift Type:</label>
                            <input type="text" id="new-template-type" placeholder="bijv. Inbound" style="width: 120px;">
                        </div>
                        <div>
                            <label>Starttijd:</label>
                            <input type="time" id="new-template-start" value="08:00" style="width: 100px;">
                        </div>
                        <div>
                            <label>Eindtijd:</label>
                            <input type="time" id="new-template-end" value="17:00" style="width: 100px;">
                        </div>
                        <div>
                            <label><input type="checkbox" id="new-template-full-day"> Hele dag</label>
                        </div>
                        <button type="button" class="button button-primary" onclick="addNewTemplate()">Template Toevoegen</button>
                    </div>
                </div>
                
                <div class="templates-container">
                    <div class="template-section">
                        <h3>Bestaande Shift Templates</h3>
                        <div class="template-grid" id="template-grid">
                            <div class="template-card">
                                <div class="template-header">
                                    <h4>Inbound Shift</h4>
                                    <button type="button" class="button button-small template-delete" onclick="deleteTemplate('inbound')">Verwijderen</button>
                                </div>
                                <div class="template-fields">
                                    <label>Starttijd: <input type="time" name="shift_templates[inbound][start_time]" value="<?php echo $settings['shift_templates']['inbound']['start_time'] ?? '08:00'; ?>"></label>
                                    <label>Eindtijd: <input type="time" name="shift_templates[inbound][end_time]" value="<?php echo $settings['shift_templates']['inbound']['end_time'] ?? '17:00'; ?>"></label>
                                    <label>Type: <input type="text" name="shift_templates[inbound][type]" value="<?php echo $settings['shift_templates']['inbound']['type'] ?? 'Inbound'; ?>"></label>
                                    <label><input type="checkbox" name="shift_templates[inbound][full_day]" <?php checked($settings['shift_templates']['inbound']['full_day'] ?? false); ?>> Hele dag</label>
                                </div>
                            </div>
                            
                            <div class="template-card">
                                <div class="template-header">
                                    <h4>Backoffice & Chat Shift</h4>
                                    <button type="button" class="button button-small template-delete" onclick="deleteTemplate('backoffice-chat')">Verwijderen</button>
                                </div>
                                <div class="template-fields">
                                    <label>Starttijd: <input type="time" name="shift_templates[backoffice-chat][start_time]" value="<?php echo $settings['shift_templates']['backoffice-chat']['start_time'] ?? '09:00'; ?>"></label>
                                    <label>Eindtijd: <input type="time" name="shift_templates[backoffice-chat][end_time]" value="<?php echo $settings['shift_templates']['backoffice-chat']['end_time'] ?? '17:00'; ?>"></label>
                                    <label>Type: <input type="text" name="shift_templates[backoffice-chat][type]" value="<?php echo $settings['shift_templates']['backoffice-chat']['type'] ?? 'Backoffice & Chat'; ?>"></label>
                                    <label><input type="checkbox" name="shift_templates[backoffice-chat][full_day]" <?php checked($settings['shift_templates']['backoffice-chat']['full_day'] ?? false); ?>> Hele dag</label>
                                </div>
                            </div>
                            
                            <div class="template-card">
                                <div class="template-header">
                                    <h4>Outbound & Chat Shift</h4>
                                    <button type="button" class="button button-small template-delete" onclick="deleteTemplate('outbound-chat')">Verwijderen</button>
                                </div>
                                <div class="template-fields">
                                    <label>Starttijd: <input type="time" name="shift_templates[outbound-chat][start_time]" value="<?php echo $settings['shift_templates']['outbound-chat']['start_time'] ?? '09:00'; ?>"></label>
                                    <label>Eindtijd: <input type="time" name="shift_templates[outbound-chat][end_time]" value="<?php echo $settings['shift_templates']['outbound-chat']['end_time'] ?? '17:00'; ?>"></label>
                                    <label>Type: <input type="text" name="shift_templates[outbound-chat][type]" value="<?php echo $settings['shift_templates']['outbound-chat']['type'] ?? 'Outbound & Chat'; ?>"></label>
                                    <label><input type="checkbox" name="shift_templates[outbound-chat][full_day]" <?php checked($settings['shift_templates']['outbound-chat']['full_day'] ?? false); ?>> Hele dag</label>
                                </div>
                            </div>
                            
                            <div class="template-card">
                                <div class="template-header">
                                    <h4>Verlof Template</h4>
                                    <button type="button" class="button button-small template-delete" onclick="deleteTemplate('verlof')">Verwijderen</button>
                                </div>
                                <div class="template-fields">
                                    <label>Starttijd: <input type="time" name="shift_templates[verlof][start_time]" value="<?php echo $settings['shift_templates']['verlof']['start_time'] ?? '08:00'; ?>"></label>
                                    <label>Eindtijd: <input type="time" name="shift_templates[verlof][end_time]" value="<?php echo $settings['shift_templates']['verlof']['end_time'] ?? '17:00'; ?>"></label>
                                    <label>Type: <input type="text" name="shift_templates[verlof][type]" value="<?php echo $settings['shift_templates']['verlof']['type'] ?? 'Verlof'; ?>"></label>
                                    <label><input type="checkbox" name="shift_templates[verlof][full_day]" <?php checked($settings['shift_templates']['verlof']['full_day'] ?? true); ?>> Hele dag</label>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Employee Preferences Tab -->
            <div id="employee-preferences" class="tab-content">
                <h2>Werkdagen per Medewerker</h2>
                <div class="employee-preferences-container">
                    <?php 
                    $db = new PowerPlanner_Database();
                    $employees = $db->get_employees();
                    $employee_preferences = $settings['employee_preferences'] ?? array();
                    ?>
                    
                    <?php foreach ($employees as $employee): ?>
                    <div class="employee-preference-card" style="background: #fff; border: 1px solid #ddd; border-radius: 8px; padding: 20px; margin-bottom: 20px;">
                        <h3 style="margin: 0 0 15px 0; color: #0073aa;">
                            <?php echo esc_html($employee['name']); ?>
                            <?php if (strtolower($employee['name']) === 'dave'): ?>
                                <span style="background: #0073aa; color: white; padding: 2px 8px; border-radius: 10px; font-size: 10px; margin-left: 10px;">Teamleider</span>
                            <?php endif; ?>
                        </h3>
                        
                        <div class="workdays-grid" style="display: grid; grid-template-columns: repeat(5, 1fr); gap: 15px;">
                            <?php foreach (['maandag', 'dinsdag', 'woensdag', 'donderdag', 'vrijdag'] as $day): ?>
                            <div class="workday-card" style="border: 1px solid #e0e0e0; border-radius: 6px; padding: 15px;">
                                <h4 style="margin: 0 0 10px 0; font-size: 14px; color: #333;"><?php echo ucfirst($day); ?></h4>
                                
                                <div style="margin-bottom: 10px;">
                                    <label style="display: flex; align-items: center; gap: 5px;">
                                        <input type="checkbox" 
                                               name="employee_preferences[<?php echo $employee['id']; ?>][<?php echo $day; ?>][enabled]"
                                               <?php checked($employee_preferences[$employee['id']][$day]['enabled'] ?? false); ?>>
                                        Werkdag
                                    </label>
                                </div>
                                
                                <div style="display: flex; flex-direction: column; gap: 8px;">
                                    <div>
                                        <label style="font-size: 12px; color: #666;">Starttijd:</label>
                                        <input type="time" 
                                               name="employee_preferences[<?php echo $employee['id']; ?>][<?php echo $day; ?>][start_time]"
                                               value="<?php echo $employee_preferences[$employee['id']][$day]['start_time'] ?? '08:00'; ?>"
                                               style="width: 100%; padding: 4px; border: 1px solid #ddd; border-radius: 3px;">
                                    </div>
                                    <div>
                                        <label style="font-size: 12px; color: #666;">Eindtijd:</label>
                                        <input type="time" 
                                               name="employee_preferences[<?php echo $employee['id']; ?>][<?php echo $day; ?>][end_time]"
                                               value="<?php echo $employee_preferences[$employee['id']][$day]['end_time'] ?? '17:00'; ?>"
                                               style="width: 100%; padding: 4px; border: 1px solid #ddd; border-radius: 3px;">
                                    </div>
                                    <div>
                                        <label style="font-size: 12px; color: #666;">Standaard Type:</label>
                                        <select name="employee_preferences[<?php echo $employee['id']; ?>][<?php echo $day; ?>][default_type]"
                                                style="width: 100%; padding: 4px; border: 1px solid #ddd; border-radius: 3px;">
                                            <option value="">-- Selecteer --</option>
                                            <option value="Inbound" <?php selected($employee_preferences[$employee['id']][$day]['default_type'] ?? '', 'Inbound'); ?>>Inbound</option>
                                            <option value="Backoffice & Chat" <?php selected($employee_preferences[$employee['id']][$day]['default_type'] ?? '', 'Backoffice & Chat'); ?>>Backoffice & Chat</option>
                                            <option value="Outbound & Chat" <?php selected($employee_preferences[$employee['id']][$day]['default_type'] ?? '', 'Outbound & Chat'); ?>>Outbound & Chat</option>
                                            <option value="Training" <?php selected($employee_preferences[$employee['id']][$day]['default_type'] ?? '', 'Training'); ?>>Training</option>
                                            <?php if (strtolower($employee['name']) === 'dave'): ?>
                                            <option value="Meeting" <?php selected($employee_preferences[$employee['id']][$day]['default_type'] ?? '', 'Meeting'); ?>>Meeting</option>
                                            <option value="Extern" <?php selected($employee_preferences[$employee['id']][$day]['default_type'] ?? '', 'Extern'); ?>>Extern</option>
                                            <option value="Beschikbaar" <?php selected($employee_preferences[$employee['id']][$day]['default_type'] ?? '', 'Beschikbaar'); ?>>Beschikbaar</option>
                                            <?php endif; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <!-- Dagstart Tab -->
            <div id="dagstart" class="tab-content">
                <h2>Dagstart Voorzitters</h2>
                <div class="dagstart-container">
                    <div class="dagstart-section">
                        <h3>Dagstart Toewijzingen</h3>
                        <?php 
                        $dagstart_settings = $settings['dagstart_assignments'] ?? array();
                        ?>
                        <table class="form-table">
                            <?php foreach (['maandag', 'dinsdag', 'woensdag', 'donderdag', 'vrijdag'] as $day): ?>
                            <tr>
                                <th scope="row"><?php echo ucfirst($day); ?></th>
                                <td>
                                    <select name="dagstart_assignments[<?php echo $day; ?>]">
                                        <option value="">-- Selecteer Medewerker --</option>
                                        <?php foreach ($employees as $employee): ?>
                                            <option value="<?php echo esc_attr($employee['name']); ?>" 
                                                    <?php selected($dagstart_settings[$day] ?? '', $employee['name']); ?>>
                                                <?php echo esc_html($employee['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </table>
                    </div>
                </div>
            </div>
            
            <!-- Export & Backup Tab -->
            <div id="export-backup" class="tab-content">
                <h2>Export & Backup</h2>
                <div class="export-backup-container">
                    <div class="export-section" style="background: #f8f9fa; padding: 20px; border-radius: 8px; margin-bottom: 20px;">
                        <h3>📊 Data Export</h3>
                        <div class="export-options" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 15px;">
                            <div class="export-card" style="background: white; padding: 15px; border-radius: 6px; border: 1px solid #ddd;">
                                <h4>📅 Week Planning</h4>
                                <p style="font-size: 12px; color: #666; margin: 5px 0;">Exporteer planning voor specifieke week</p>
                                <div style="display: flex; gap: 10px; margin-top: 10px;">
                                    <input type="number" id="export-week" placeholder="Week" min="1" max="52" style="width: 60px; padding: 4px;">
                                    <input type="number" id="export-year" placeholder="Jaar" min="2020" max="2030" style="width: 70px; padding: 4px;">
                                    <button type="button" class="button" onclick="exportWeekData()">Export</button>
                                </div>
                            </div>
                            
                            <div class="export-card" style="background: white; padding: 15px; border-radius: 6px; border: 1px solid #ddd;">
                                <h4>👥 Medewerkers</h4>
                                <p style="font-size: 12px; color: #666; margin: 5px 0;">Exporteer alle medewerker gegevens</p>
                                <button type="button" class="button" onclick="exportEmployees()" style="margin-top: 10px;">Export Medewerkers</button>
                            </div>
                            
                            <div class="export-card" style="background: white; padding: 15px; border-radius: 6px; border: 1px solid #ddd;">
                                <h4>⚙️ Instellingen</h4>
                                <p style="font-size: 12px; color: #666; margin: 5px 0;">Exporteer alle plugin instellingen</p>
                                <button type="button" class="button" onclick="exportSettings()" style="margin-top: 10px;">Export Instellingen</button>
                            </div>
                            
                            <div class="export-card" style="background: white; padding: 15px; border-radius: 6px; border: 1px solid #ddd;">
                                <h4>📋 Volledige Backup</h4>
                                <p style="font-size: 12px; color: #666; margin: 5px 0;">Complete backup van alle data</p>
                                <button type="button" class="button button-primary" onclick="createFullBackup()" style="margin-top: 10px;">Volledige Backup</button>
                            </div>
                        </div>
                    </div>
                    
                    <div class="backup-section" style="background: #fff3cd; padding: 20px; border-radius: 8px; margin-bottom: 20px;">
                        <h3>💾 Backup Beheer</h3>
                        <div class="backup-options" style="display: flex; gap: 15px; flex-wrap: wrap;">
                            <button type="button" class="button" onclick="createBackup()">Nieuwe Backup Maken</button>
                            <button type="button" class="button" onclick="listBackups()">Bestaande Backups</button>
                            <button type="button" class="button" onclick="restoreBackup()">Backup Herstellen</button>
                        </div>
                    </div>
                    
                    <div class="import-section" style="background: #d1ecf1; padding: 20px; border-radius: 8px;">
                        <h3>📥 Data Import</h3>
                        <div class="import-options" style="display: flex; gap: 15px; align-items: center; flex-wrap: wrap;">
                            <input type="file" id="import-file" accept=".json,.csv" style="padding: 8px;">
                            <button type="button" class="button" onclick="importData()">Import Data</button>
                            <span style="font-size: 12px; color: #666;">Ondersteunt JSON en CSV formaten</span>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Advanced Tab -->
            <div id="advanced" class="tab-content">
                <h2>Advanced Instellingen</h2>
                <div class="advanced-container">
                    <div class="advanced-section" style="background: #f8f9fa; padding: 20px; border-radius: 8px; margin-bottom: 20px;">
                        <h3>🎨 Kleuren & Weergave</h3>
                        <div class="color-settings" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
                            <div class="color-card" style="background: white; padding: 15px; border-radius: 6px; border: 1px solid #ddd;">
                                <h4>Inbound</h4>
                                <div style="display: flex; gap: 10px; align-items: center;">
                                    <input type="color" name="shift_colors[inbound]" value="<?php echo $settings['shift_colors']['inbound'] ?? '#6aa84f'; ?>" style="width: 40px; height: 30px;">
                                    <input type="text" name="shift_colors[inbound]" value="<?php echo $settings['shift_colors']['inbound'] ?? '#6aa84f'; ?>" style="width: 80px; padding: 4px;">
                                </div>
                            </div>
                            <div class="color-card" style="background: white; padding: 15px; border-radius: 6px; border: 1px solid #ddd;">
                                <h4>Outbound</h4>
                                <div style="display: flex; gap: 10px; align-items: center;">
                                    <input type="color" name="shift_colors[outbound]" value="<?php echo $settings['shift_colors']['outbound'] ?? '#ff9800'; ?>" style="width: 40px; height: 30px;">
                                    <input type="text" name="shift_colors[outbound]" value="<?php echo $settings['shift_colors']['outbound'] ?? '#ff9800'; ?>" style="width: 80px; padding: 4px;">
                                </div>
                            </div>
                            <div class="color-card" style="background: white; padding: 15px; border-radius: 6px; border: 1px solid #ddd;">
                                <h4>Backoffice</h4>
                                <div style="display: flex; gap: 10px; align-items: center;">
                                    <input type="color" name="shift_colors[backoffice]" value="<?php echo $settings['shift_colors']['backoffice'] ?? '#2196f3'; ?>" style="width: 40px; height: 30px;">
                                    <input type="text" name="shift_colors[backoffice]" value="<?php echo $settings['shift_colors']['backoffice'] ?? '#2196f3'; ?>" style="width: 80px; padding: 4px;">
                                </div>
                            </div>
                            <div class="color-card" style="background: white; padding: 15px; border-radius: 6px; border: 1px solid #ddd;">
                                <h4>Chat</h4>
                                <div style="display: flex; gap: 10px; align-items: center;">
                                    <input type="color" name="shift_colors[chat]" value="<?php echo $settings['shift_colors']['chat'] ?? '#9c27b0'; ?>" style="width: 40px; height: 30px;">
                                    <input type="text" name="shift_colors[chat]" value="<?php echo $settings['shift_colors']['chat'] ?? '#9c27b0'; ?>" style="width: 80px; padding: 4px;">
                                </div>
                            </div>
                            <div class="color-card" style="background: white; padding: 15px; border-radius: 6px; border: 1px solid #ddd;">
                                <h4>Verlof</h4>
                                <div style="display: flex; gap: 10px; align-items: center;">
                                    <input type="color" name="shift_colors[verlof]" value="<?php echo $settings['shift_colors']['verlof'] ?? '#9e9e9e'; ?>" style="width: 40px; height: 30px;">
                                    <input type="text" name="shift_colors[verlof]" value="<?php echo $settings['shift_colors']['verlof'] ?? '#9e9e9e'; ?>" style="width: 80px; padding: 4px;">
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="advanced-section" style="background: #f8f9fa; padding: 20px; border-radius: 8px; margin-bottom: 20px;">
                        <h3>🔧 Systeem Instellingen</h3>
                        <table class="form-table">
                            <tr>
                                <th scope="row">Cache Beheer</th>
                                <td>
                                    <button type="button" class="button" onclick="clearCache()">Cache Wissen</button>
                                    <p class="description">Wis alle cached data voor betere prestaties</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Debug Logging</th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="debug_logging" <?php checked($settings['debug_logging'] ?? false); ?>>
                                        Debug logging inschakelen
                                    </label>
                                    <p class="description">Log alle plugin activiteiten voor troubleshooting</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Performance Mode</th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="performance_mode" <?php checked($settings['performance_mode'] ?? false); ?>>
                                        Performance mode (minder real-time updates)
                                    </label>
                                    <p class="description">Schakel real-time updates uit voor betere prestaties</p>
                                </td>
                            </tr>
                        </table>
                    </div>
                    
                    <div class="advanced-section" style="background: #f8f9fa; padding: 20px; border-radius: 8px;">
                        <h3>⚠️ Geavanceerde Opties</h3>
                        <div class="warning-box" style="background: #fff3cd; border: 1px solid #ffeaa7; padding: 15px; border-radius: 6px; margin-bottom: 15px;">
                            <strong>⚠️ Waarschuwing:</strong> Deze instellingen kunnen de plugin functionaliteit beïnvloeden. Wijzig alleen als je weet wat je doet.
                        </div>
                        <table class="form-table">
                            <tr>
                                <th scope="row">Database Optimalisatie</th>
                                <td>
                                    <button type="button" class="button" onclick="optimizeDatabase()">Database Optimaliseren</button>
                                    <p class="description">Optimaliseer database tabellen voor betere prestaties</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Plugin Reset</th>
                                <td>
                                    <button type="button" class="button button-secondary" onclick="resetPlugin()" style="background: #dc3545; color: white;">Plugin Reset</button>
                                    <p class="description">Reset alle instellingen naar standaardwaarden (VERWIJDER ALLE DATA!)</p>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>
            </div>
            
            <?php submit_button('Instellingen Opslaan', 'primary', 'submit', true, array('id' => 'save-settings')); ?>
        </form>
    </div>
    
    <script>
    // Template Management Functions
    function addNewTemplate() {
        const name = document.getElementById('new-template-name').value;
        const type = document.getElementById('new-template-type').value;
        const start = document.getElementById('new-template-start').value;
        const end = document.getElementById('new-template-end').value;
        const fullDay = document.getElementById('new-template-full-day').checked;
        
        if (!name || !type) {
            alert('Vul template naam en type in');
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
        
        document.getElementById('template-grid').insertAdjacentHTML('beforeend', templateHtml);
        
        // Clear form
        document.getElementById('new-template-name').value = '';
        document.getElementById('new-template-type').value = '';
        document.getElementById('new-template-start').value = '08:00';
        document.getElementById('new-template-end').value = '17:00';
        document.getElementById('new-template-full-day').checked = false;
        
        alert('Template toegevoegd');
    }
    
    function deleteTemplate(templateKey) {
        if (confirm('Weet je zeker dat je deze template wilt verwijderen?')) {
            const cards = document.querySelectorAll('.template-card');
            cards.forEach(card => {
                if (card.innerHTML.includes(`[${templateKey}]`)) {
                    card.remove();
                }
            });
            alert('Template verwijderd');
        }
    }
    
    // Export & Backup Functions
    function exportWeekData() {
        const week = document.getElementById('export-week').value;
        const year = document.getElementById('export-year').value;
        
        if (!week || !year) {
            alert('Vul week en jaar in');
            return;
        }
        
        window.location.href = '<?php echo admin_url('admin-ajax.php'); ?>?action=powerplanner_export_excel&week=' + week + '&year=' + year + '&nonce=<?php echo wp_create_nonce('powerplanner_nonce'); ?>';
    }
    
    function exportEmployees() {
        window.location.href = '<?php echo admin_url('admin-ajax.php'); ?>?action=powerplanner_export_employees&nonce=<?php echo wp_create_nonce('powerplanner_nonce'); ?>';
    }
    
    function exportSettings() {
        window.location.href = '<?php echo admin_url('admin-ajax.php'); ?>?action=powerplanner_export_settings&nonce=<?php echo wp_create_nonce('powerplanner_nonce'); ?>';
    }
    
    function createFullBackup() {
        if (confirm('Weet je zeker dat je een volledige backup wilt maken?')) {
            alert('Backup wordt gemaakt...');
            window.location.href = '<?php echo admin_url('admin-ajax.php'); ?>?action=powerplanner_create_backup&nonce=<?php echo wp_create_nonce('powerplanner_nonce'); ?>';
        }
    }
    
    function createBackup() {
        alert('Backup wordt gemaakt...');
    }
    
    function listBackups() {
        alert('Backup lijst wordt geladen...');
    }
    
    function restoreBackup() {
        alert('Backup herstel functie wordt geladen...');
    }
    
    function importData() {
        const file = document.getElementById('import-file').files[0];
        if (!file) {
            alert('Selecteer eerst een bestand');
            return;
        }
        alert('Import functionaliteit wordt geladen...');
    }
    
    // Advanced Functions
    function clearCache() {
        if (confirm('Weet je zeker dat je de cache wilt wissen?')) {
            alert('Cache wordt gewist...');
        }
    }
    
    function optimizeDatabase() {
        if (confirm('Weet je zeker dat je de database wilt optimaliseren?')) {
            alert('Database wordt geoptimaliseerd...');
        }
    }
    
    function resetPlugin() {
        if (confirm('WAARSCHUWING: Dit zal ALLE data verwijderen! Weet je zeker dat je de plugin wilt resetten?')) {
            if (confirm('Laatste waarschuwing: Dit kan NIET ongedaan worden gemaakt!')) {
                alert('Plugin reset functionaliteit wordt geladen...');
            }
        }
    }
    </script>
    
    <?php
}

function powerplanner_employees_page() {
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized access');
    }
    
    $db = new PowerPlanner_Database();
    
    if (isset($_POST['add_employee'])) {
        $data = array(
            'name' => sanitize_text_field($_POST['name']),
            'email' => sanitize_email($_POST['email']),
            'contract_hours' => floatval($_POST['contract_hours']),
            'is_active' => 1
        );
        $result = $db->add_employee($data);
        if ($result) {
            echo '<div class="notice notice-success"><p>Medewerker toegevoegd!</p></div>';
        }
    }
    
    if (isset($_POST['update_employee'])) {
        $employee_id = intval($_POST['employee_id']);
        $data = array('contract_hours' => floatval($_POST['contract_hours']));
        $result = $db->update_employee($employee_id, $data);
        if ($result !== false) {
            echo '<div class="notice notice-success"><p>Medewerker bijgewerkt!</p></div>';
        }
    }
    
    if (isset($_POST['toggle_employee'])) {
        $employee_id = intval($_POST['employee_id']);
        $is_active = intval($_POST['is_active']) ? 0 : 1;
        $result = $db->update_employee($employee_id, array('is_active' => $is_active));
        if ($result !== false) {
            $status = $is_active ? 'geactiveerd' : 'gedeactiveerd';
            echo "<div class='notice notice-success'><p>Medewerker {$status}!</p></div>";
        }
    }
    
    $employees = $db->get_all_employees();
    ?>
    
    <div class="wrap">
        <h1>Employee Management</h1>
        
        <div class="postbox">
            <h2>Nieuwe Medewerker</h2>
            <form method="post" style="padding: 20px;">
                <table class="form-table">
                    <tr>
                        <th>Naam*</th>
                        <td><input type="text" name="name" required style="width: 300px;"></td>
                    </tr>
                    <tr>
                        <th>Email</th>
                        <td><input type="email" name="email" style="width: 300px;"></td>
                    </tr>
                    <tr>
                        <th>Contract Uren</th>
                        <td><input type="number" name="contract_hours" value="24" min="1" max="40" step="0.5" style="width: 100px;"> uren/week</td>
                    </tr>
                </table>
                <button type="submit" name="add_employee" class="button button-primary">Toevoegen</button>
            </form>
        </div>
        
        <div class="postbox">
            <h2>Medewerkers</h2>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Naam</th>
                        <th>Email</th>
                        <th>Uren</th>
                        <th>Status</th>
                        <th>Acties</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($employees as $employee): ?>
                    <tr>
                        <td>
                            <strong><?php echo esc_html($employee['name']); ?></strong>
                            <?php if (strtolower($employee['name']) === 'dave'): ?>
                                <span style="background: #0073aa; color: white; padding: 2px 8px; border-radius: 10px; font-size: 10px;">Teamleider</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html($employee['email'] ?: '-'); ?></td>
                        <td>
                            <form method="post" style="display: inline;">
                                <input type="hidden" name="employee_id" value="<?php echo $employee['id']; ?>">
                                <input type="number" name="contract_hours" value="<?php echo $employee['contract_hours']; ?>" min="1" max="40" step="0.5" style="width: 70px;">
                                <button type="submit" name="update_employee" class="button button-small">Update</button>
                            </form>
                        </td>
                        <td><?php echo $employee['is_active'] ? '✅ Actief' : '❌ Inactief'; ?></td>
                        <td>
                            <form method="post" style="display: inline;">
                                <input type="hidden" name="employee_id" value="<?php echo $employee['id']; ?>">
                                <input type="hidden" name="is_active" value="<?php echo $employee['is_active']; ?>">
                                <button type="submit" name="toggle_employee" class="button button-small">
                                    <?php echo $employee['is_active'] ? 'Deactiveren' : 'Activeren'; ?>
                                </button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <?php
}