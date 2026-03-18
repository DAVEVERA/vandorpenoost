<?php
/**
 * PowerPlanner Database Class
 * Handles all database operations
 */

class PowerPlanner_Database {
    
    private $wpdb;
    private $shifts_table;
    private $patterns_table;
    private $employees_table;
    private $conflicts_table;
    private $meetings_table;
    
    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        
        $this->shifts_table = $wpdb->prefix . 'powerplanner_shifts';
        $this->patterns_table = $wpdb->prefix . 'powerplanner_patterns';
        $this->employees_table = $wpdb->prefix . 'powerplanner_employees';
        $this->conflicts_table = $wpdb->prefix . 'powerplanner_conflicts';
        $this->meetings_table = $wpdb->prefix . 'powerplanner_meetings';
    }
    
    /**
     * Create database tables
     */
    public function create_tables() {
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        
        $charset_collate = $this->wpdb->get_charset_collate();
        
        // Shifts table
        $sql_shifts = "CREATE TABLE {$this->shifts_table} (
            id int(11) NOT NULL AUTO_INCREMENT,
            employee_id int(11) NOT NULL,
            employee_name varchar(100) NOT NULL,
            week_number int(11) NOT NULL,
            year int(11) NOT NULL,
            day_of_week varchar(20) NOT NULL,
            shift_type varchar(50) NOT NULL,
            start_time time DEFAULT NULL,
            end_time time DEFAULT NULL,
            full_day tinyint(1) DEFAULT 0,
            buddy varchar(100) DEFAULT NULL,
            note text DEFAULT NULL,
            created_by int(11) NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_employee_week (employee_id, week_number, year),
            KEY idx_week_day (week_number, year, day_of_week),
            KEY idx_shift_type (shift_type)
        ) $charset_collate;";
        
        // Patterns table
        $sql_patterns = "CREATE TABLE {$this->patterns_table} (
            id int(11) NOT NULL AUTO_INCREMENT,
            employee_id int(11) NOT NULL,
            employee_name varchar(100) NOT NULL,
            day_of_week varchar(20) NOT NULL,
            shift_type varchar(50) NOT NULL,
            start_time time DEFAULT NULL,
            end_time time DEFAULT NULL,
            confidence_score float DEFAULT 0,
            is_approved tinyint(1) DEFAULT 0,
            is_active tinyint(1) DEFAULT 1,
            detected_from_weeks varchar(255) DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            approved_by int(11) DEFAULT NULL,
            approved_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_employee_pattern (employee_id, day_of_week),
            KEY idx_approved (is_approved, is_active)
        ) $charset_collate;";
        
        // Employees table
        $sql_employees = "CREATE TABLE {$this->employees_table} (
            id int(11) NOT NULL AUTO_INCREMENT,
            user_id int(11) DEFAULT NULL,
            name varchar(100) NOT NULL,
            email varchar(100) DEFAULT NULL,
            phone varchar(20) DEFAULT NULL,
            contract_hours float DEFAULT 0,
            is_active tinyint(1) DEFAULT 1,
            admin_day_preference varchar(20) DEFAULT NULL,
            last_meeting_date date DEFAULT NULL,
            next_meeting_date date DEFAULT NULL,
            dagstart_days text DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY idx_user_id (user_id),
            KEY idx_active (is_active)
        ) $charset_collate;";
        
        // Conflicts table
        $sql_conflicts = "CREATE TABLE {$this->conflicts_table} (
            id int(11) NOT NULL AUTO_INCREMENT,
            week_number int(11) NOT NULL,
            year int(11) NOT NULL,
            conflict_type varchar(50) NOT NULL,
            employee_id int(11) DEFAULT NULL,
            day_of_week varchar(20) DEFAULT NULL,
            description text NOT NULL,
            severity enum('low','medium','high','critical') DEFAULT 'medium',
            is_resolved tinyint(1) DEFAULT 0,
            resolved_by int(11) DEFAULT NULL,
            resolved_at datetime DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_week_conflicts (week_number, year, is_resolved),
            KEY idx_employee_conflicts (employee_id, is_resolved)
        ) $charset_collate;";
        
        // Meetings table
        $sql_meetings = "CREATE TABLE {$this->meetings_table} (
            id int(11) NOT NULL AUTO_INCREMENT,
            employee_id int(11) NOT NULL,
            manager_id int(11) NOT NULL,
            scheduled_date date NOT NULL,
            scheduled_time time NOT NULL,
            duration_minutes int(11) DEFAULT 30,
            status enum('scheduled','completed','cancelled','rescheduled') DEFAULT 'scheduled',
            notes text DEFAULT NULL,
            completed_at datetime DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_employee_meetings (employee_id, scheduled_date),
            KEY idx_manager_meetings (manager_id, scheduled_date),
            KEY idx_status (status, scheduled_date)
        ) $charset_collate;";
        
        // Dagstart table for weekly assignments
        $dagstart_table = $wpdb->prefix . 'powerplanner_dagstart';
        $sql_dagstart = "CREATE TABLE {$dagstart_table} (
            id int(11) NOT NULL AUTO_INCREMENT,
            week_number int(11) NOT NULL,
            year int(11) NOT NULL,
            day_of_week varchar(20) NOT NULL,
            employee_name varchar(100) NOT NULL,
            created_by int(11) NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY idx_week_day (week_number, year, day_of_week),
            KEY idx_week (week_number, year)
        ) $charset_collate;";
        
        dbDelta($sql_shifts);
        dbDelta($sql_patterns);
        dbDelta($sql_employees);
        dbDelta($sql_conflicts);
        dbDelta($sql_meetings);
        dbDelta($sql_dagstart);
        
        // Insert default employees if not exists
        $this->insert_default_employees();
    }
    
    /**
     * Insert default employees
     */
    private function insert_default_employees() {
        $employees = array(
            'Dave', 'Annebel', 'Audrey', 'Bianca', 'Carola',
            'Chayenne', 'Dewi', 'Dianne', 'Joost', 'Karin',
            'Kelly', 'Nancy', 'Nikita', 'Shelly', 'Wilma'
        );
        
        foreach ($employees as $name) {
            $exists = $this->wpdb->get_var($this->wpdb->prepare(
                "SELECT id FROM {$this->employees_table} WHERE name = %s",
                $name
            ));
            
            if (!$exists) {
                $this->wpdb->insert(
                    $this->employees_table,
                    array(
                        'name' => $name,
                        'is_active' => 1,
                        'contract_hours' => 24
                    ),
                    array('%s', '%d', '%f')
                );
            }
        }
    }
    
    /**
     * Get shifts for a specific week
     */
    public function get_week_shifts($week_number, $year) {
        return $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT s.*, e.name as employee_name 
             FROM {$this->shifts_table} s
             LEFT JOIN {$this->employees_table} e ON s.employee_id = e.id
             WHERE s.week_number = %d AND s.year = %d 
             ORDER BY 
                FIELD(s.day_of_week, 'maandag', 'dinsdag', 'woensdag', 'donderdag', 'vrijdag'),
                e.name, s.start_time",
            $week_number,
            $year
        ), ARRAY_A);
    }
    
    /**
     * Get employee shifts
     */
    public function get_employee_shifts($employee_id, $week_number = null, $year = null) {
        $where = "employee_id = %d";
        $params = array($employee_id);
        
        if ($week_number && $year) {
            $where .= " AND week_number = %d AND year = %d";
            $params[] = $week_number;
            $params[] = $year;
        }
        
        return $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT * FROM {$this->shifts_table} WHERE $where ORDER BY year DESC, week_number DESC, 
             FIELD(day_of_week, 'maandag', 'dinsdag', 'woensdag', 'donderdag', 'vrijdag'), start_time",
            ...$params
        ), ARRAY_A);
    }
    
    /**
     * Insert or update shift
     */
    public function save_shift($data) {
        // Validate no time conflicts
        $conflict = $this->check_shift_conflict($data);
        if ($conflict) {
            return new WP_Error('shift_conflict', 'Er is al een dienst gepland op dit tijdstip');
        }
        
        // Validate time logic
        if (!$data['full_day'] && $data['start_time'] >= $data['end_time']) {
            return new WP_Error('invalid_time', 'Eindtijd moet na starttijd zijn');
        }
        
        if (isset($data['id']) && $data['id']) {
            // Update existing
            $result = $this->wpdb->update(
                $this->shifts_table,
                $data,
                array('id' => $data['id']),
                null,
                array('%d')
            );
        } else {
            // Insert new
            unset($data['id']);
            $data['created_by'] = get_current_user_id();
            $result = $this->wpdb->insert($this->shifts_table, $data);
            
            if ($result) {
                $data['id'] = $this->wpdb->insert_id;
            }
        }
        
        return $result !== false ? $data : new WP_Error('db_error', 'Database fout');
    }
    
    /**
     * Check for shift conflicts
     */
    public function check_shift_conflict($data) {
        // Skip conflict check for full day items
        if ($data['full_day']) {
            return false;
        }
        
        $query = "SELECT id FROM {$this->shifts_table} 
                  WHERE employee_id = %d 
                  AND week_number = %d 
                  AND year = %d 
                  AND day_of_week = %s 
                  AND full_day = 0
                  AND ((start_time < %s AND end_time > %s) 
                       OR (start_time < %s AND end_time > %s)
                       OR (start_time >= %s AND end_time <= %s))";
        
        $params = array(
            $data['employee_id'],
            $data['week_number'],
            $data['year'],
            $data['day_of_week'],
            $data['end_time'],
            $data['start_time'],
            $data['end_time'],
            $data['end_time'],
            $data['start_time'],
            $data['end_time']
        );
        
        if (isset($data['id']) && $data['id']) {
            $query .= " AND id != %d";
            $params[] = $data['id'];
        }
        
        return $this->wpdb->get_var($this->wpdb->prepare($query, ...$params));
    }
    
    /**
     * Delete shift
     */
    public function delete_shift($id) {
        return $this->wpdb->delete(
            $this->shifts_table,
            array('id' => $id),
            array('%d')
        );
    }
    
    /**
     * Get approved patterns for an employee
     */
    public function get_employee_patterns($employee_id) {
        return $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT * FROM {$this->patterns_table} 
             WHERE employee_id = %d AND is_approved = 1 AND is_active = 1
             ORDER BY FIELD(day_of_week, 'maandag', 'dinsdag', 'woensdag', 'donderdag', 'vrijdag')",
            $employee_id
        ), ARRAY_A);
    }
    
    /**
     * Save detected pattern
     */
    public function save_pattern($data) {
        // Check if pattern already exists
        $exists = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT id FROM {$this->patterns_table} 
             WHERE employee_id = %d AND day_of_week = %s AND shift_type = %s AND start_time = %s",
            $data['employee_id'],
            $data['day_of_week'],
            $data['shift_type'],
            $data['start_time']
        ));
        
        if ($exists) {
            // Update confidence score
            return $this->wpdb->update(
                $this->patterns_table,
                array('confidence_score' => $data['confidence_score']),
                array('id' => $exists),
                array('%f'),
                array('%d')
            );
        } else {
            // Insert new pattern
            return $this->wpdb->insert($this->patterns_table, $data);
        }
    }
    
    /**
     * Get conflicts for a week
     */
    public function get_week_conflicts($week_number, $year) {
        return $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT * FROM {$this->conflicts_table} 
             WHERE week_number = %d AND year = %d AND is_resolved = 0
             ORDER BY severity DESC, created_at ASC",
            $week_number,
            $year
        ), ARRAY_A);
    }
    
    /**
     * Log a conflict
     */
    public function log_conflict($data) {
        return $this->wpdb->insert($this->conflicts_table, $data);
    }
    
    /**
     * Get upcoming meetings
     */
    public function get_upcoming_meetings($limit = 10) {
        return $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT m.*, e.name as employee_name 
             FROM {$this->meetings_table} m
             JOIN {$this->employees_table} e ON m.employee_id = e.id
             WHERE m.scheduled_date >= CURDATE() 
             AND m.status = 'scheduled'
             ORDER BY m.scheduled_date ASC, m.scheduled_time ASC
             LIMIT %d",
            $limit
        ), ARRAY_A);
    }
    
    /**
     * Schedule a meeting
     */
    public function schedule_meeting($data) {
        // Check for conflicts
        $conflict = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT id FROM {$this->meetings_table} 
             WHERE scheduled_date = %s 
             AND scheduled_time = %s 
             AND (employee_id = %d OR manager_id = %d)
             AND status = 'scheduled'",
            $data['scheduled_date'],
            $data['scheduled_time'],
            $data['employee_id'],
            $data['manager_id']
        ));
        
        if ($conflict) {
            return new WP_Error('meeting_conflict', 'Er is al een meeting gepland op dit tijdstip');
        }
        
        return $this->wpdb->insert($this->meetings_table, $data);
    }
    
    /**
     * Get all active employees
     */
    public function get_employees() {
        return $this->wpdb->get_results(
            "SELECT * FROM {$this->employees_table} WHERE is_active = 1 ORDER BY name ASC",
            ARRAY_A
        );
    }
    
    /**
     * Bulk update shift
     */
    public function bulk_update_shift($shift_id, $data) {
        return $this->wpdb->update(
            $this->shifts_table,
            $data,
            array('id' => $shift_id),
            null,
            array('%d')
        );
    }
    
    /**
     * Clear all shifts for a week
     */
    public function clear_week_shifts($week_number, $year) {
        return $this->wpdb->delete(
            $this->shifts_table,
            array(
                'week_number' => $week_number,
                'year' => $year
            ),
            array('%d', '%d')
        );
    }
    
    /**
     * Get employee by ID
     */
    public function get_employee($employee_id) {
        return $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT * FROM {$this->employees_table} WHERE id = %d",
            $employee_id
        ), ARRAY_A);
    }
    
    /**
     * Update employee
     */
    public function update_employee($employee_id, $data) {
        return $this->wpdb->update(
            $this->employees_table,
            $data,
            array('id' => $employee_id),
            null,
            array('%d')
        );
    }
    
    /**
     * Get all patterns
     */
    public function get_all_patterns() {
        return $this->wpdb->get_results(
            "SELECT * FROM {$this->patterns_table} ORDER BY confidence_score DESC",
            ARRAY_A
        );
    }
    
    /**
     * Delete pattern
     */
    public function delete_pattern($pattern_id) {
        return $this->wpdb->delete(
            $this->patterns_table,
            array('id' => $pattern_id),
            array('%d')
        );
    }
    
    /**
     * Save dagstart assignment for a week
     */
    public function save_dagstart_assignment($week_number, $year, $day_of_week, $employee_name) {
        $dagstart_table = $this->wpdb->prefix . 'powerplanner_dagstart';
        
        return $this->wpdb->replace(
            $dagstart_table,
            array(
                'week_number' => $week_number,
                'year' => $year,
                'day_of_week' => $day_of_week,
                'employee_name' => $employee_name,
                'created_by' => get_current_user_id()
            ),
            array('%d', '%d', '%s', '%s', '%d')
        );
    }
    
    /**
     * Get dagstart assignments for a week
     */
    public function get_dagstart_assignments($week_number, $year) {
        $dagstart_table = $this->wpdb->prefix . 'powerplanner_dagstart';
        
        $results = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT * FROM {$dagstart_table} WHERE week_number = %d AND year = %d",
            $week_number, $year
        ), ARRAY_A);
        
        $assignments = array();
        foreach ($results as $row) {
            $assignments[$row['day_of_week']] = $row['employee_name'];
        }
        
        return $assignments;
    }
    
    /**
     * Add new employee
     */
    public function add_employee($data) {
        return $this->wpdb->insert(
            $this->employees_table,
            $data,
            array('%s', '%s', '%f', '%d')
        );
    }
    
    /**
     * Get all employees (including inactive)
     */
    public function get_all_employees() {
        return $this->wpdb->get_results(
            "SELECT * FROM {$this->employees_table} ORDER BY is_active DESC, name ASC",
            ARRAY_A
        );
    }
}