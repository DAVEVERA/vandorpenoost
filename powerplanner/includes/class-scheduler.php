<?php
/**
 * PowerPlanner Scheduler Class
 * Handles business logic, patterns, and rules
 */

class PowerPlanner_Scheduler
{

    private $db;
    private $settings;

    public function __construct()
    {
        $this->db = new PowerPlanner_Database();
        $this->settings = get_option('powerplanner_settings', array());
    }

    /**
     * Apply business rules to shifts
     */
    public function apply_business_rules($week_number, $year)
    {
        $conflicts = array();

        // Rule 1: Monday morning 8:00-9:30 everyone on inbound
        if ($this->settings['monday_morning_rule']) {
            $conflicts = array_merge($conflicts, $this->enforce_monday_morning_rule($week_number, $year));
        }

        // Rule 2: Everyone needs admin day
        if ($this->settings['admin_day_required']) {
            $conflicts = array_merge($conflicts, $this->check_admin_days($week_number, $year));
        }

        // Rule 3: Check meeting intervals
        $conflicts = array_merge($conflicts, $this->check_meeting_intervals());

        // Rule 4: Peak hour coverage
        $conflicts = array_merge($conflicts, $this->check_peak_coverage($week_number, $year));

        // Log conflicts to database
        foreach ($conflicts as $conflict) {
            $this->db->log_conflict($conflict);
        }

        return $conflicts;
    }

    /**
     * Enforce Monday morning rule
     */
    private function enforce_monday_morning_rule($week_number, $year)
    {
        $conflicts = array();
        $employees = $this->db->get_employees();
        $monday_shifts = $this->db->get_week_shifts($week_number, $year);

        foreach ($employees as $employee) {
            $has_monday_morning = false;

            foreach ($monday_shifts as $shift) {
                if (
                    $shift['employee_id'] == $employee['id'] &&
                    $shift['day_of_week'] == 'maandag' &&
                    $shift['shift_type'] == 'Inbound' &&
                    $shift['start_time'] <= '08:00:00' &&
                    $shift['end_time'] >= '09:30:00'
                ) {
                    $has_monday_morning = true;
                    break;
                }
            }

            if (!$has_monday_morning) {
                // Auto-create Monday morning shift
                $this->db->save_shift(array(
                    'employee_id' => $employee['id'],
                    'employee_name' => $employee['name'],
                    'week_number' => $week_number,
                    'year' => $year,
                    'day_of_week' => 'maandag',
                    'shift_type' => 'Inbound',
                    'start_time' => '08:00:00',
                    'end_time' => '09:30:00',
                    'note' => 'Auto: Maandag ochtend regel'
                ));

                $conflicts[] = array(
                    'week_number' => $week_number,
                    'year' => $year,
                    'conflict_type' => 'monday_morning_auto',
                    'employee_id' => $employee['id'],
                    'day_of_week' => 'maandag',
                    'description' => "Automatisch maandag ochtend inbound toegevoegd voor {$employee['name']}",
                    'severity' => 'low'
                );
            }
        }

        return $conflicts;
    }

    /**
     * Check if everyone has admin day
     */
    private function check_admin_days($week_number, $year)
    {
        $conflicts = array();
        $employees = $this->db->get_employees();
        $week_shifts = $this->db->get_week_shifts($week_number, $year);

        foreach ($employees as $employee) {
            $admin_hours = 0;
            $total_hours = 0;

            foreach ($week_shifts as $shift) {
                if ($shift['employee_id'] == $employee['id']) {
                    $hours = $this->calculate_shift_hours($shift);
                    $total_hours += $hours;

                    if (in_array($shift['shift_type'], array('Backoffice & Chat', 'Outbound & Chat', 'Training'))) {
                        $admin_hours += $hours;
                    }
                }
            }

            // Need at least 4 hours admin time per week
            if ($total_hours > 0 && $admin_hours < 4) {
                $conflicts[] = array(
                    'week_number' => $week_number,
                    'year' => $year,
                    'conflict_type' => 'insufficient_admin_time',
                    'employee_id' => $employee['id'],
                    'description' => "{$employee['name']} heeft slechts {$admin_hours} uur admin tijd (minimum 4 uur nodig)",
                    'severity' => 'medium'
                );
            }
        }

        return $conflicts;
    }

    /**
     * Check meeting intervals
     */
    private function check_meeting_intervals()
    {
        $conflicts = array();
        $employees = $this->db->get_employees();
        $interval_weeks = $this->settings['meeting_interval_weeks'] ?? 2;

        foreach ($employees as $employee) {
            if ($employee['last_meeting_date']) {
                $last_meeting = new DateTime($employee['last_meeting_date']);
                $now = new DateTime();
                $diff = $now->diff($last_meeting);
                $weeks_passed = floor($diff->days / 7);

                if ($weeks_passed >= $interval_weeks) {
                    $conflicts[] = array(
                        'week_number' => date('W'),
                        'year' => date('Y'),
                        'conflict_type' => 'overdue_meeting',
                        'employee_id' => $employee['id'],
                        'description' => "{$employee['name']} heeft al {$weeks_passed} weken geen 1-op-1 meeting gehad",
                        'severity' => 'high'
                    );
                }
            }
        }

        return $conflicts;
    }

    /**
     * Check peak hour coverage
     */
    private function check_peak_coverage($week_number, $year)
    {
        $conflicts = array();
        $peak_hours = $this->settings['peak_hours'] ?? array('08:00-09:30', '16:00-17:00');
        $week_shifts = $this->db->get_week_shifts($week_number, $year);

        $days = array('maandag', 'dinsdag', 'woensdag', 'donderdag', 'vrijdag');

        foreach ($days as $day) {
            foreach ($peak_hours as $peak) {
                list($peak_start, $peak_end) = explode('-', $peak);
                $coverage_count = 0;

                foreach ($week_shifts as $shift) {
                    if (
                        $shift['day_of_week'] == $day &&
                        $shift['shift_type'] == 'Inbound' &&
                        $shift['start_time'] <= $peak_start . ':00' &&
                        $shift['end_time'] >= $peak_end . ':00'
                    ) {
                        $coverage_count++;
                    }
                }

                // Need at least 5 people during peak hours
                if ($coverage_count < 5) {
                    $conflicts[] = array(
                        'week_number' => $week_number,
                        'year' => $year,
                        'conflict_type' => 'insufficient_peak_coverage',
                        'day_of_week' => $day,
                        'description' => "Slechts {$coverage_count} mensen op inbound tijdens piekuren {$peak} op {$day}",
                        'severity' => 'high'
                    );
                }
            }
        }

        return $conflicts;
    }

    /**
     * Calculate shift hours
     */
    private function calculate_shift_hours($shift)
    {
        if ($shift['full_day']) {
            return 8;
        }

        try {
            $start = new DateTime($shift['start_time']);
            $end = new DateTime($shift['end_time']);
            $diff = $end->diff($start);

            return $diff->h + ($diff->i / 60);
        } catch (Exception $e) {
            error_log('PowerPlanner: Invalid date format in shift calculation: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Detect patterns from historical data
     */
    public function detect_patterns()
    {
        $employees = $this->db->get_employees();
        $detected_patterns = array();

        foreach ($employees as $employee) {
            // Get last 4 weeks of shifts
            $current_week = date('W');
            $current_year = date('Y');
            $pattern_data = array();

            for ($i = 1; $i <= 4; $i++) {
                $week = $current_week - $i;
                $year = $current_year;

                if ($week < 1) {
                    $week += 52;
                    $year--;
                }

                $shifts = $this->db->get_employee_shifts($employee['id'], $week, $year);
                foreach ($shifts as $shift) {
                    $key = $shift['day_of_week'] . '_' . $shift['shift_type'] . '_' . $shift['start_time'];
                    if (!isset($pattern_data[$key])) {
                        $pattern_data[$key] = array(
                            'count' => 0,
                            'data' => $shift
                        );
                    }
                    $pattern_data[$key]['count']++;
                }
            }

            // Patterns that appear in 3+ of 4 weeks are considered valid
            foreach ($pattern_data as $pattern) {
                if ($pattern['count'] >= 3) {
                    $confidence = $pattern['count'] / 4;
                    $detected_patterns[] = array(
                        'employee_id' => $employee['id'],
                        'employee_name' => $employee['name'],
                        'day_of_week' => $pattern['data']['day_of_week'],
                        'shift_type' => $pattern['data']['shift_type'],
                        'start_time' => $pattern['data']['start_time'],
                        'end_time' => $pattern['data']['end_time'],
                        'confidence_score' => $confidence,
                        'detected_from_weeks' => json_encode(range($current_week - 4, $current_week - 1))
                    );
                }
            }
        }

        // Save detected patterns
        foreach ($detected_patterns as $pattern) {
            $this->db->save_pattern($pattern);
        }

        return $detected_patterns;
    }

    /**
     * Auto-populate week from patterns
     */
    public function populate_from_patterns($week_number, $year)
    {
        $employees = $this->db->get_employees();
        $created_shifts = array();

        foreach ($employees as $employee) {
            $patterns = $this->db->get_employee_patterns($employee['id']);

            foreach ($patterns as $pattern) {
                // Check if shift already exists
                $existing = $this->db->get_week_shifts($week_number, $year);
                $exists = false;

                foreach ($existing as $shift) {
                    if (
                        $shift['employee_id'] == $employee['id'] &&
                        $shift['day_of_week'] == $pattern['day_of_week'] &&
                        $shift['shift_type'] == $pattern['shift_type']
                    ) {
                        $exists = true;
                        break;
                    }
                }

                if (!$exists) {
                    $shift_data = array(
                        'employee_id' => $employee['id'],
                        'employee_name' => $employee['name'],
                        'week_number' => $week_number,
                        'year' => $year,
                        'day_of_week' => $pattern['day_of_week'],
                        'shift_type' => $pattern['shift_type'],
                        'start_time' => $pattern['start_time'],
                        'end_time' => $pattern['end_time'],
                        'note' => 'Auto: Patroon'
                    );

                    $result = $this->db->save_shift($shift_data);
                    if (!is_wp_error($result)) {
                        $created_shifts[] = $shift_data;
                    }
                }
            }
        }

        return $created_shifts;
    }

    /**
     * Get dagstart rotation for week
     */
    public function get_dagstart_rotation($week_number, $year)
    {
        // Get week-specific assignments first
        $weekly_assignments = $this->db->get_dagstart_assignments($week_number, $year);

        if (!empty($weekly_assignments)) {
            return $weekly_assignments;
        }

        // Fallback to global settings
        $settings = get_option('powerplanner_settings', array());
        $dagstart_assignments = $settings['dagstart_assignments'] ?? array();

        $rotation = array(
            'maandag' => $dagstart_assignments['maandag'] ?? 'Dave',
            'dinsdag' => $dagstart_assignments['dinsdag'] ?? 'Kelly',
            'woensdag' => $dagstart_assignments['woensdag'] ?? 'Joost',
            'donderdag' => $dagstart_assignments['donderdag'] ?? 'Shelly',
            'vrijdag' => $dagstart_assignments['vrijdag'] ?? 'Chayenne'
        );

        return $rotation;
    }

    /**
     * Schedule next 1-on-1 meeting
     */
    public function schedule_next_meeting($employee_id)
    {
        $employee = $this->db->get_employees();
        $manager_hours = $this->settings['manager_meeting_hours'] ?? array('10:00-16:00');

        // Find next available slot
        $date = new DateTime();
        $date->modify('+1 day');

        for ($i = 0; $i < 14; $i++) {
            $day_of_week = strtolower($date->format('l'));

            // Skip Monday and weekends
            if ($day_of_week == 'monday' || $day_of_week == 'saturday' || $day_of_week == 'sunday') {
                $date->modify('+1 day');
                continue;
            }

            // Check for available time slot
            list($start_hour, $end_hour) = explode('-', $manager_hours[0]);
            $meeting_time = $start_hour . ':00';

            $meeting_data = array(
                'employee_id' => $employee_id,
                'manager_id' => get_current_user_id(),
                'scheduled_date' => $date->format('Y-m-d'),
                'scheduled_time' => $meeting_time,
                'duration_minutes' => 30
            );

            $result = $this->db->schedule_meeting($meeting_data);

            if (!is_wp_error($result)) {
                return $meeting_data;
            }

            $date->modify('+1 day');
        }

        return false;
    }

    /**
     * Get recent updates for real-time sync
     */
    public function get_recent_updates($since = null)
    {
        global $wpdb;

        if (!$since) {
            $since = date('Y-m-d H:i:s', strtotime('-5 seconds'));
        }

        $shifts_table = $wpdb->prefix . 'powerplanner_shifts';

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$shifts_table} 
             WHERE updated_at > %s 
             ORDER BY updated_at DESC",
            $since
        ), ARRAY_A);
    }

    /**
     * Get workload statistics for an employee
     */
    public function get_employee_workload($employee_id, $week_number, $year)
    {
        $shifts = $this->db->get_employee_shifts($employee_id, $week_number, $year);
        $total_hours = 0;
        $shift_types = array();

        foreach ($shifts as $shift) {
            $hours = $this->calculate_shift_hours($shift);
            $total_hours += $hours;

            if (!isset($shift_types[$shift['shift_type']])) {
                $shift_types[$shift['shift_type']] = 0;
            }
            $shift_types[$shift['shift_type']] += $hours;
        }

        return array(
            'total_hours' => $total_hours,
            'shift_types' => $shift_types,
            'shift_count' => count($shifts)
        );
    }

    /**
     * Get coverage analysis for a week
     */
    public function get_coverage_analysis($week_number, $year)
    {
        $shifts = $this->db->get_week_shifts($week_number, $year);
        $coverage = array();

        $days = array('maandag', 'dinsdag', 'woensdag', 'donderdag', 'vrijdag');
        $hours = array('08:00', '09:00', '10:00', '11:00', '12:00', '13:00', '14:00', '15:00', '16:00', '17:00');

        foreach ($days as $day) {
            $coverage[$day] = array();
            foreach ($hours as $hour) {
                $coverage[$day][$hour] = array(
                    'inbound' => 0,
                    'outbound' => 0,
                    'backoffice' => 0,
                    'total' => 0
                );
            }
        }

        foreach ($shifts as $shift) {
            $day = $shift['day_of_week'];
            $start = substr($shift['start_time'], 0, 5);
            $end = substr($shift['end_time'], 0, 5);

            foreach ($hours as $hour) {
                if ($hour >= $start && $hour < $end) {
                    $coverage[$day][$hour]['total']++;
                    if (isset($coverage[$day][$hour][strtolower($shift['shift_type'])])) {
                        $coverage[$day][$hour][strtolower($shift['shift_type'])]++;
                    }
                }
            }
        }

        return $coverage;
    }

    /**
     * Validate shift against business rules
     */
    public function validate_shift($shift_data)
    {
        $errors = array();

        // Check time logic
        if (!$shift_data['full_day'] && $shift_data['start_time'] >= $shift_data['end_time']) {
            $errors[] = 'Eindtijd moet na starttijd zijn';
        }

        // Check for conflicts
        $conflicts = $this->db->check_shift_conflict($shift_data);
        if ($conflicts) {
            $errors[] = 'Er is al een dienst gepland op dit tijdstip';
        }

        // Check workload limits
        $workload = $this->get_employee_workload($shift_data['employee_id'], $shift_data['week_number'], $shift_data['year']);
        if ($workload['total_hours'] > 40) {
            $errors[] = 'Medewerker heeft al te veel uren deze week';
        }

        return $errors;
    }
}