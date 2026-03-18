<?php
/**
 * PowerPlanner API Class
 * REST API endpoints for real-time updates
 */

class PowerPlanner_API {
    
    private $namespace = 'powerplanner/v1';
    private $db;
    private $scheduler;
    
    public function __construct() {
        $this->db = new PowerPlanner_Database();
        $this->scheduler = new PowerPlanner_Scheduler();
    }
    
    /**
     * Initialize API routes
     */
    public function init() {
        add_action('rest_api_init', array($this, 'register_routes'));
    }
    
    /**
     * Register REST API routes
     */
    public function register_routes() {
        // Shifts endpoints
        register_rest_route($this->namespace, '/shifts', array(
            array(
                'methods' => 'GET',
                'callback' => array($this, 'get_shifts'),
                'permission_callback' => array($this, 'check_read_permission')
            ),
            array(
                'methods' => 'POST',
                'callback' => array($this, 'create_shift'),
                'permission_callback' => array($this, 'check_write_permission')
            )
        ));
        
        register_rest_route($this->namespace, '/shifts/(?P<id>\d+)', array(
            array(
                'methods' => 'PUT',
                'callback' => array($this, 'update_shift'),
                'permission_callback' => array($this, 'check_write_permission')
            ),
            array(
                'methods' => 'DELETE',
                'callback' => array($this, 'delete_shift'),
                'permission_callback' => array($this, 'check_write_permission')
            )
        ));
        
        // Patterns endpoints
        register_rest_route($this->namespace, '/patterns', array(
            array(
                'methods' => 'GET',
                'callback' => array($this, 'get_patterns'),
                'permission_callback' => array($this, 'check_read_permission')
            ),
            array(
                'methods' => 'POST',
                'callback' => array($this, 'approve_pattern'),
                'permission_callback' => array($this, 'check_admin_permission')
            )
        ));
        
        register_rest_route($this->namespace, '/patterns/detect', array(
            'methods' => 'POST',
            'callback' => array($this, 'detect_patterns'),
            'permission_callback' => array($this, 'check_admin_permission')
        ));
        
        // Validation endpoint
        register_rest_route($this->namespace, '/validate', array(
            'methods' => 'POST',
            'callback' => array($this, 'validate_week'),
            'permission_callback' => array($this, 'check_write_permission')
        ));
        
        // Sync endpoint
        register_rest_route($this->namespace, '/sync', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_updates'),
            'permission_callback' => array($this, 'check_read_permission')
        ));
        
        // Populate from patterns
        register_rest_route($this->namespace, '/populate', array(
            'methods' => 'POST',
            'callback' => array($this, 'populate_week'),
            'permission_callback' => array($this, 'check_write_permission')
        ));
        
        // Meetings endpoints
        register_rest_route($this->namespace, '/meetings', array(
            array(
                'methods' => 'GET',
                'callback' => array($this, 'get_meetings'),
                'permission_callback' => array($this, 'check_read_permission')
            ),
            array(
                'methods' => 'POST',
                'callback' => array($this, 'schedule_meeting'),
                'permission_callback' => array($this, 'check_write_permission')
            )
        ));
        
        // Manager status
        register_rest_route($this->namespace, '/manager-status', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_manager_status'),
            'permission_callback' => '__return_true'
        ));
    }
    
    /**
     * Get shifts
     */
    public function get_shifts($request) {
        $week = $request->get_param('week');
        $year = $request->get_param('year');
        $employee_id = $request->get_param('employee_id');
        
        if ($employee_id) {
            $shifts = $this->db->get_employee_shifts($employee_id, $week, $year);
        } else {
            $shifts = $this->db->get_week_shifts($week, $year);
        }
        
        return new WP_REST_Response($shifts, 200);
    }
    
    /**
     * Create shift
     */
    public function create_shift($request) {
        $data = $request->get_json_params();
        
        // Validate required fields
        $required = array('employee_id', 'employee_name', 'week_number', 'year', 'day_of_week', 'shift_type');
        foreach ($required as $field) {
            if (empty($data[$field])) {
                return new WP_Error('missing_field', "Field {$field} is required", array('status' => 400));
            }
        }
        
        // Apply business rules
        $this->apply_shift_rules($data);
        
        $result = $this->db->save_shift($data);
        
        if (is_wp_error($result)) {
            return $result;
        }
        
        // Trigger validation
        $this->scheduler->apply_business_rules($data['week_number'], $data['year']);
        
        return new WP_REST_Response($result, 201);
    }
    
    /**
     * Update shift
     */
    public function update_shift($request) {
        $id = $request->get_param('id');
        $data = $request->get_json_params();
        $data['id'] = $id;
        
        $result = $this->db->save_shift($data);
        
        if (is_wp_error($result)) {
            return $result;
        }
        
        return new WP_REST_Response($result, 200);
    }
    
    /**
     * Delete shift
     */
    public function delete_shift($request) {
        $id = $request->get_param('id');
        $result = $this->db->delete_shift($id);
        
        if ($result === false) {
            return new WP_Error('delete_failed', 'Could not delete shift', array('status' => 500));
        }
        
        return new WP_REST_Response(array('deleted' => true), 200);
    }
    
    /**
     * Get patterns
     */
    public function get_patterns($request) {
        $employee_id = $request->get_param('employee_id');
        
        if ($employee_id) {
            $patterns = $this->db->get_employee_patterns($employee_id);
        } else {
            global $wpdb;
            $table = $wpdb->prefix . 'powerplanner_patterns';
            $patterns = $wpdb->get_results("SELECT * FROM {$table} ORDER BY confidence_score DESC", ARRAY_A);
        }
        
        return new WP_REST_Response($patterns, 200);
    }
    
    /**
     * Detect patterns
     */
    public function detect_patterns($request) {
        $patterns = $this->scheduler->detect_patterns();
        return new WP_REST_Response($patterns, 200);
    }
    
    /**
     * Approve pattern
     */
    public function approve_pattern($request) {
        global $wpdb;
        $pattern_id = $request->get_param('pattern_id');
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
        
        return new WP_REST_Response(array('approved' => $result !== false), 200);
    }
    
    /**
     * Validate week
     */
    public function validate_week($request) {
        $week = $request->get_param('week');
        $year = $request->get_param('year');
        
        $conflicts = $this->scheduler->apply_business_rules($week, $year);
        
        return new WP_REST_Response(array(
            'valid' => empty($conflicts),
            'conflicts' => $conflicts
        ), 200);
    }
    
    /**
     * Get updates for sync
     */
    public function get_updates($request) {
        $since = $request->get_param('since');
        $updates = $this->scheduler->get_recent_updates($since);
        
        return new WP_REST_Response($updates, 200);
    }
    
    /**
     * Populate week from patterns
     */
    public function populate_week($request) {
        $week = $request->get_param('week');
        $year = $request->get_param('year');
        
        $created = $this->scheduler->populate_from_patterns($week, $year);
        
        return new WP_REST_Response(array(
            'created' => count($created),
            'shifts' => $created
        ), 200);
    }
    
    /**
     * Get meetings
     */
    public function get_meetings($request) {
        $meetings = $this->db->get_upcoming_meetings();
        return new WP_REST_Response($meetings, 200);
    }
    
    /**
     * Schedule meeting
     */
    public function schedule_meeting($request) {
        $data = $request->get_json_params();
        $result = $this->db->schedule_meeting($data);
        
        if (is_wp_error($result)) {
            return $result;
        }
        
        return new WP_REST_Response(array('scheduled' => true), 201);
    }
    
    /**
     * Get manager status
     */
    public function get_manager_status($request) {
        // Find first user with manager role
        $manager_users = get_users(array('role' => 'administrator', 'number' => 1));
        $manager_id = !empty($manager_users) ? $manager_users[0]->ID : 1;
        
        $status = get_user_meta($manager_id, 'manager_status', true) ?: 'available';
        $location = get_user_meta($manager_id, 'manager_location', true) ?: 'office';
        
        return new WP_REST_Response(array(
            'status' => $status,
            'location' => $location,
            'updated' => get_user_meta($manager_id, 'manager_status_updated', true)
        ), 200);
    }
    
    /**
     * Apply business rules to shift
     */
    private function apply_shift_rules(&$data) {
        // Monday morning override
        if ($data['day_of_week'] == 'maandag' && 
            $data['start_time'] >= '08:00' && 
            $data['start_time'] <= '09:30') {
            $data['shift_type'] = 'Inbound';
            $data['note'] = (isset($data['note']) ? $data['note'] . ' | ' : '') . 'Maandag ochtend regel';
        }
    }
    
    /**
     * Check read permission
     */
    public function check_read_permission() {
        return current_user_can('read');
    }
    
    /**
     * Check write permission
     */
    public function check_write_permission() {
        return current_user_can('powerplanner_manage') || current_user_can('manage_options');
    }
    
    /**
     * Check admin permission
     */
    public function check_admin_permission() {
        return current_user_can('manage_options');
    }
}