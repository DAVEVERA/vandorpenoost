<?php
/**
 * PowerPlanner Admin AJAX Handlers
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Save shift via AJAX
add_action('wp_ajax_powerplanner_save_shift', 'powerplanner_ajax_save_shift');
function powerplanner_ajax_save_shift() {
    check_ajax_referer('powerplanner_nonce', 'nonce');
    
    if (!current_user_can('powerplanner_manage')) {
        wp_die('Unauthorized');
    }
    
    $db = new PowerPlanner_Database();
    $data = $_POST;
    
    // Clean up data
    unset($data['action'], $data['nonce']);
    
    // Handle full_day checkbox
    $data['full_day'] = isset($data['full_day']) && $data['full_day'] === 'true' ? 1 : 0;
    
    // If recurring is checked, create multiple shifts
    $recurring = isset($data['recurring']) && $data['recurring'] === 'true';
    unset($data['recurring']);
    
    $result = $db->save_shift($data);
    
    if (is_wp_error($result)) {
        wp_send_json_error($result->get_error_message());
        return;
    }
    
    // Handle recurring shifts
    if ($recurring && !isset($data['id'])) {
        $weeks_to_add = array();
        $current_week = intval($data['week_number']);
        $current_year = intval($data['year']);
        
        for ($i = 1; $i <= 4; $i++) {
            $week = $current_week + $i;
            $year = $current_year;
            
            if ($week > 52) {
                $week -= 52;
                $year++;
            }
            
            $recurring_data = $data;
            $recurring_data['week_number'] = $week;
            $recurring_data['year'] = $year;
            unset($recurring_data['id']);
            
            $db->save_shift($recurring_data);
        }
    }
    
    wp_send_json_success($result);
}

// Delete shift via AJAX
add_action('wp_ajax_powerplanner_delete_shift', 'powerplanner_ajax_delete_shift');
function powerplanner_ajax_delete_shift() {
    check_ajax_referer('powerplanner_nonce', 'nonce');
    
    if (!current_user_can('powerplanner_manage')) {
        wp_die('Unauthorized');
    }
    
    $shift_id = intval($_POST['shift_id']);
    $db = new PowerPlanner_Database();
    
    $result = $db->delete_shift($shift_id);
    
    if ($result === false) {
        wp_send_json_error('Could not delete shift');
    }
    
    wp_send_json_success();
}

// Get shift details via AJAX
add_action('wp_ajax_powerplanner_get_shift', 'powerplanner_ajax_get_shift');
function powerplanner_ajax_get_shift() {
    check_ajax_referer('powerplanner_nonce', 'nonce');
    
    if (!current_user_can('read')) {
        wp_die('Unauthorized');
    }
    
    $shift_id = intval($_POST['shift_id']);
    
    global $wpdb;
    $table = $wpdb->prefix . 'powerplanner_shifts';
    $shift = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$table} WHERE id = %d",
        $shift_id
    ), ARRAY_A);
    
    if (!$shift) {
        wp_send_json_error('Shift not found');
    }
    
    wp_send_json_success($shift);
}

// Populate week from patterns via AJAX
add_action('wp_ajax_powerplanner_populate_patterns', 'powerplanner_ajax_populate_patterns');
function powerplanner_ajax_populate_patterns() {
    check_ajax_referer('powerplanner_nonce', 'nonce');
    
    if (!current_user_can('powerplanner_manage')) {
        wp_die('Unauthorized');
    }
    
    $week = intval($_POST['week']);
    $year = intval($_POST['year']);
    
    $scheduler = new PowerPlanner_Scheduler();
    $created = $scheduler->populate_from_patterns($week, $year);
    
    wp_send_json_success(array(
        'created' => count($created),
        'message' => sprintf('%d diensten toegevoegd uit patronen', count($created))
    ));
}

// Validate week via AJAX
add_action('wp_ajax_powerplanner_validate_week', 'powerplanner_ajax_validate_week');
function powerplanner_ajax_validate_week() {
    check_ajax_referer('powerplanner_nonce', 'nonce');
    
    if (!current_user_can('read')) {
        wp_die('Unauthorized');
    }
    
    $week = intval($_POST['week']);
    $year = intval($_POST['year']);
    
    $scheduler = new PowerPlanner_Scheduler();
    $conflicts = $scheduler->apply_business_rules($week, $year);
    
    wp_send_json_success(array(
        'valid' => empty($conflicts),
        'conflicts' => $conflicts
    ));
}

// Export week to Excel via AJAX
add_action('wp_ajax_powerplanner_export_excel', 'powerplanner_ajax_export_excel');
function powerplanner_ajax_export_excel() {
    check_ajax_referer('powerplanner_nonce', 'nonce');
    
    if (!current_user_can('read')) {
        wp_die('Unauthorized');
    }
    
    $week = intval($_POST['week']);
    $year = intval($_POST['year']);
    
    $db = new PowerPlanner_Database();
    $employees = $db->get_employees();
    $shifts = $db->get_week_shifts($week, $year);
    
    // Create CSV content
    $csv = "Medewerker,Maandag,Dinsdag,Woensdag,Donderdag,Vrijdag\n";
    
    foreach ($employees as $employee) {
        $row = array($employee['name']);
        
        foreach (['maandag', 'dinsdag', 'woensdag', 'donderdag', 'vrijdag'] as $day) {
            $day_shifts = array_filter($shifts, function($s) use ($employee, $day) {
                return $s['employee_id'] == $employee['id'] && $s['day_of_week'] == $day;
            });
            
            $shift_texts = array();
            foreach ($day_shifts as $shift) {
                $text = $shift['shift_type'];
                if (!$shift['full_day']) {
                    $text .= sprintf(' (%s-%s)', 
                        substr($shift['start_time'], 0, 5),
                        substr($shift['end_time'], 0, 5)
                    );
                }
                $shift_texts[] = $text;
            }
            
            $row[] = implode('; ', $shift_texts) ?: '-';
        }
        
        $csv .= '"' . implode('","', $row) . "\"\n";
    }
    
    wp_send_json_success(array(
        'csv' => $csv,
        'filename' => sprintf('rooster_week_%d_%d.csv', $week, $year)
    ));
}

// Detect patterns via AJAX
add_action('wp_ajax_powerplanner_detect_patterns', 'powerplanner_ajax_detect_patterns');
function powerplanner_ajax_detect_patterns() {
    check_ajax_referer('powerplanner_nonce', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized');
    }
    
    $scheduler = new PowerPlanner_Scheduler();
    $patterns = $scheduler->detect_patterns();
    
    wp_send_json_success(array(
        'detected' => count($patterns),
        'message' => sprintf('%d patronen gedetecteerd', count($patterns))
    ));
}

// Approve pattern via AJAX
add_action('wp_ajax_powerplanner_approve_pattern', 'powerplanner_ajax_approve_pattern');
function powerplanner_ajax_approve_pattern() {
    check_ajax_referer('powerplanner_nonce', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized');
    }
    
    $pattern_id = intval($_POST['pattern_id']);
    
    global $wpdb;
    $table = $wpdb->prefix . 'powerplanner_patterns';
    
    $result = $wpdb->update(
        $table,
        array(
            'is_approved' => 1,
            'approved_by' => get_current_user_id(),
            'approved_at' => current_time('mysql')
        ),
        array('id' => $pattern_id),
        array('%d', '%d', '%s'),
        array('%d')
    );
    
    if ($result === false) {
        wp_send_json_error('Could not approve pattern');
    }
    
    wp_send_json_success();
}

// Get real-time updates via AJAX (for polling fallback)
add_action('wp_ajax_powerplanner_get_updates', 'powerplanner_ajax_get_updates');
add_action('wp_ajax_nopriv_powerplanner_get_updates', 'powerplanner_ajax_get_updates');
function powerplanner_ajax_get_updates() {
    // Basic security check for public endpoint
    if (!wp_verify_nonce($_POST['nonce'] ?? '', 'powerplanner_nonce') && !current_user_can('read')) {
        wp_die('Unauthorized');
    }
    
    $since = isset($_POST['since']) ? $_POST['since'] : date('Y-m-d H:i:s', strtotime('-5 seconds'));
    
    $scheduler = new PowerPlanner_Scheduler();
    $updates = $scheduler->get_recent_updates($since);
    
    wp_send_json_success($updates);
}

// Update manager status
add_action('wp_ajax_powerplanner_update_manager_status', 'powerplanner_ajax_update_manager_status');
function powerplanner_ajax_update_manager_status() {
    check_ajax_referer('powerplanner_nonce', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized');
    }
    
    $status = sanitize_text_field($_POST['status']);
    $location = sanitize_text_field($_POST['location']);
    
    update_user_meta(get_current_user_id(), 'manager_status', $status);
    update_user_meta(get_current_user_id(), 'manager_location', $location);
    update_user_meta(get_current_user_id(), 'manager_status_updated', current_time('mysql'));
    
    wp_send_json_success();
}

// Bulk update shift
add_action('wp_ajax_powerplanner_bulk_update_shift', 'powerplanner_ajax_bulk_update_shift');
function powerplanner_ajax_bulk_update_shift() {
    check_ajax_referer('powerplanner_nonce', 'nonce');
    
    if (!current_user_can('powerplanner_manage')) {
        wp_die('Unauthorized');
    }
    
    $shift_id = intval($_POST['shift_id']);
    $data = array(
        'shift_type' => sanitize_text_field($_POST['shift_type']),
        'start_time' => sanitize_text_field($_POST['start_time']),
        'end_time' => sanitize_text_field($_POST['end_time']),
        'full_day' => isset($_POST['full_day']) && $_POST['full_day'] === 'true' ? 1 : 0,
        'buddy' => sanitize_text_field($_POST['buddy']),
        'note' => sanitize_textarea_field($_POST['note'])
    );
    
    $db = new PowerPlanner_Database();
    $result = $db->bulk_update_shift($shift_id, $data);
    
    if (is_wp_error($result)) {
        wp_send_json_error($result->get_error_message());
    }
    
    wp_send_json_success($result);
}

// Bulk create shift
add_action('wp_ajax_powerplanner_bulk_create_shift', 'powerplanner_ajax_bulk_create_shift');
function powerplanner_ajax_bulk_create_shift() {
    check_ajax_referer('powerplanner_nonce', 'nonce');
    
    if (!current_user_can('powerplanner_manage')) {
        wp_die('Unauthorized');
    }
    
    $data = $_POST;
    unset($data['action'], $data['nonce']);
    
    // Get employee name
    $db = new PowerPlanner_Database();
    $employees = $db->get_employees();
    $employee_name = '';
    foreach ($employees as $emp) {
        if ($emp['id'] == $data['employee_id']) {
            $employee_name = $emp['name'];
            break;
        }
    }
    $data['employee_name'] = $employee_name;
    $data['created_by'] = get_current_user_id();
    
    $result = $db->save_shift($data);
    
    if (is_wp_error($result)) {
        wp_send_json_error($result->get_error_message());
    }
    
    wp_send_json_success($result);
}

// Copy week
add_action('wp_ajax_powerplanner_copy_week', 'powerplanner_ajax_copy_week');
function powerplanner_ajax_copy_week() {
    check_ajax_referer('powerplanner_nonce', 'nonce');
    
    if (!current_user_can('powerplanner_manage')) {
        wp_die('Unauthorized');
    }
    
    $week = intval($_POST['week']);
    $year = intval($_POST['year']);
    
    $db = new PowerPlanner_Database();
    $shifts = $db->get_week_shifts($week, $year);
    
    wp_send_json_success($shifts);
}

// Paste week
add_action('wp_ajax_powerplanner_paste_week', 'powerplanner_ajax_paste_week');
function powerplanner_ajax_paste_week() {
    check_ajax_referer('powerplanner_nonce', 'nonce');
    
    if (!current_user_can('powerplanner_manage')) {
        wp_die('Unauthorized');
    }
    
    $week = intval($_POST['week']);
    $year = intval($_POST['year']);
    $week_data_json = $_POST['week_data'];
    
    error_log("PowerPlanner Paste: Received data: " . $week_data_json);
    
    if (empty($week_data_json)) {
        wp_send_json_error('Geen week data ontvangen');
        return;
    }
    
    // Week data should already be an array from AJAX
    $week_data = $week_data_json;
    
    if (!is_array($week_data)) {
        error_log("PowerPlanner Paste: Week data is not array, type: " . gettype($week_data));
        wp_send_json_error('Week data format incorrect');
        return;
    }
    
    error_log("PowerPlanner Paste: Processing " . count($week_data) . " shifts");
    
    $db = new PowerPlanner_Database();
    
    // Clear existing shifts for this week
    $cleared = $db->clear_week_shifts($week, $year);
    
    // Insert new shifts
    $created = 0;
    $errors = array();
    
    foreach ($week_data as $shift_data) {
        $shift_data['week_number'] = $week;
        $shift_data['year'] = $year;
        $shift_data['created_by'] = get_current_user_id();
        unset($shift_data['id']);
        
        $result = $db->save_shift($shift_data);
        if (!is_wp_error($result)) {
            $created++;
        } else {
            $errors[] = $result->get_error_message();
        }
    }
    
    wp_send_json_success(array(
        'created' => $created,
        'cleared' => $cleared,
        'errors' => $errors
    ));
}

// Clear week
add_action('wp_ajax_powerplanner_clear_week', 'powerplanner_ajax_clear_week');
function powerplanner_ajax_clear_week() {
    check_ajax_referer('powerplanner_nonce', 'nonce');
    
    if (!current_user_can('powerplanner_manage')) {
        wp_die('Unauthorized');
    }
    
    $week = intval($_POST['week']);
    $year = intval($_POST['year']);
    
    $db = new PowerPlanner_Database();
    $result = $db->clear_week_shifts($week, $year);
    
    wp_send_json_success(array('cleared' => $result));
}

// Save dagstart assignment
add_action('wp_ajax_powerplanner_save_dagstart', 'powerplanner_ajax_save_dagstart');
function powerplanner_ajax_save_dagstart() {
    check_ajax_referer('powerplanner_nonce', 'nonce');
    
    if (!current_user_can('powerplanner_manage')) {
        wp_die('Unauthorized');
    }
    
    $week = intval($_POST['week']);
    $year = intval($_POST['year']);
    $day = sanitize_text_field($_POST['day']);
    $employee = sanitize_text_field($_POST['employee']);
    
    $db = new PowerPlanner_Database();
    $result = $db->save_dagstart_assignment($week, $year, $day, $employee);
    
    if ($result === false) {
        wp_send_json_error('Kon dagstart toewijzing niet opslaan');
    }
    
    wp_send_json_success();
}

// Export employees
add_action('wp_ajax_powerplanner_export_employees', 'powerplanner_ajax_export_employees');
function powerplanner_ajax_export_employees() {
    check_ajax_referer('powerplanner_nonce', 'nonce');
    
    if (!current_user_can('read')) {
        wp_die('Unauthorized');
    }
    
    $db = new PowerPlanner_Database();
    $employees = $db->get_all_employees();
    
    $csv = "ID,Naam,Email,Contract Uren,Status\n";
    foreach ($employees as $employee) {
        $csv .= sprintf("%d,%s,%s,%.1f,%s\n", 
            $employee['id'],
            $employee['name'],
            $employee['email'] ?: '',
            $employee['contract_hours'],
            $employee['is_active'] ? 'Actief' : 'Inactief'
        );
    }
    
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="medewerkers_' . date('Y-m-d') . '.csv"');
    echo $csv;
    exit;
}

// Export settings
add_action('wp_ajax_powerplanner_export_settings', 'powerplanner_ajax_export_settings');
function powerplanner_ajax_export_settings() {
    check_ajax_referer('powerplanner_nonce', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized');
    }
    
    $settings = get_option('powerplanner_settings', array());
    $export_data = array(
        'export_date' => current_time('mysql'),
        'plugin_version' => POWERPLANNER_VERSION,
        'settings' => $settings
    );
    
    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="powerplanner_settings_' . date('Y-m-d') . '.json"');
    echo json_encode($export_data, JSON_PRETTY_PRINT);
    exit;
}

// Create backup
add_action('wp_ajax_powerplanner_create_backup', 'powerplanner_ajax_create_backup');
function powerplanner_ajax_create_backup() {
    check_ajax_referer('powerplanner_nonce', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized');
    }
    
    $db = new PowerPlanner_Database();
    
    $backup_data = array(
        'backup_date' => current_time('mysql'),
        'plugin_version' => POWERPLANNER_VERSION,
        'employees' => $db->get_all_employees(),
        'settings' => get_option('powerplanner_settings', array()),
        'news' => get_option('powerplanner_news', array())
    );
    
    // Get all shifts from last 12 weeks
    $current_week = date('W');
    $current_year = date('Y');
    $shifts = array();
    
    for ($i = 0; $i < 12; $i++) {
        $week = $current_week - $i;
        $year = $current_year;
        if ($week <= 0) {
            $week += 52;
            $year--;
        }
        $week_shifts = $db->get_week_shifts($week, $year);
        $shifts = array_merge($shifts, $week_shifts);
    }
    
    $backup_data['shifts'] = $shifts;
    
    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="powerplanner_backup_' . date('Y-m-d_H-i-s') . '.json"');
    echo json_encode($backup_data, JSON_PRETTY_PRINT);
    exit;
}

// Clear cache
add_action('wp_ajax_powerplanner_clear_cache', 'powerplanner_ajax_clear_cache');
function powerplanner_ajax_clear_cache() {
    check_ajax_referer('powerplanner_nonce', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized');
    }
    
    wp_cache_flush();
    delete_transient('powerplanner_employees');
    delete_transient('powerplanner_settings');
    
    wp_send_json_success('Cache gewist');
}

// Optimize database
add_action('wp_ajax_powerplanner_optimize_database', 'powerplanner_ajax_optimize_database');
function powerplanner_ajax_optimize_database() {
    check_ajax_referer('powerplanner_nonce', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized');
    }
    
    global $wpdb;
    
    $tables = array(
        $wpdb->prefix . 'powerplanner_shifts',
        $wpdb->prefix . 'powerplanner_patterns',
        $wpdb->prefix . 'powerplanner_employees',
        $wpdb->prefix . 'powerplanner_conflicts',
        $wpdb->prefix . 'powerplanner_meetings',
        $wpdb->prefix . 'powerplanner_dagstart'
    );
    
    foreach ($tables as $table) {
        $wpdb->query("OPTIMIZE TABLE {$table}");
    }
    
    wp_send_json_success('Database geoptimaliseerd');
}

// Reset plugin
add_action('wp_ajax_powerplanner_reset_plugin', 'powerplanner_ajax_reset_plugin');
function powerplanner_ajax_reset_plugin() {
    check_ajax_referer('powerplanner_nonce', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized');
    }
    
    global $wpdb;
    
    // Drop all plugin tables
    $tables = array(
        $wpdb->prefix . 'powerplanner_shifts',
        $wpdb->prefix . 'powerplanner_patterns',
        $wpdb->prefix . 'powerplanner_employees',
        $wpdb->prefix . 'powerplanner_conflicts',
        $wpdb->prefix . 'powerplanner_meetings',
        $wpdb->prefix . 'powerplanner_dagstart'
    );
    
    foreach ($tables as $table) {
        $wpdb->query("DROP TABLE IF EXISTS {$table}");
    }
    
    // Delete all plugin options
    delete_option('powerplanner_settings');
    delete_option('powerplanner_news');
    
    wp_send_json_success('Plugin gereset');
}