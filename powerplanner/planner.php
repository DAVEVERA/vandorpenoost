<?php
/**
 * Plugin Name: PowerPlanner
 * Plugin URI: https://davevera.nl/powerplanner
 * Description: Professional employee scheduling system for contact center management
 * Version: 1.0.1
 * Author: MNRV
 * Author URI: https://davevera.nl
 * License: GPL v2 or later
 * Text Domain: powerplanner
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('POWERPLANNER_VERSION', '1.0.1');
define('POWERPLANNER_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('POWERPLANNER_PLUGIN_URL', plugin_dir_url(__FILE__));
define('POWERPLANNER_PLUGIN_BASENAME', plugin_basename(__FILE__));

// Debug: Log plugin constants
error_log('PowerPlanner Constants:');
error_log('POWERPLANNER_PLUGIN_DIR: ' . POWERPLANNER_PLUGIN_DIR);
error_log('POWERPLANNER_PLUGIN_URL: ' . POWERPLANNER_PLUGIN_URL);
error_log('POWERPLANNER_PLUGIN_BASENAME: ' . POWERPLANNER_PLUGIN_BASENAME);

// Activation hook
register_activation_hook(__FILE__, 'powerplanner_activate');
function powerplanner_activate()
{
    try {
        // Check if required files exist
        if (!file_exists(POWERPLANNER_PLUGIN_DIR . 'includes/class-database.php')) {
            wp_die('PowerPlanner: Required database class file not found');
        }

        require_once POWERPLANNER_PLUGIN_DIR . 'includes/class-database.php';

        // Check if class exists
        if (!class_exists('PowerPlanner_Database')) {
            wp_die('PowerPlanner: Database class not found');
        }

        $database = new PowerPlanner_Database();
        $database->create_tables();

        // Set default options
        add_option('powerplanner_settings', array(
            'monday_morning_rule' => true,
            'admin_day_required' => true,
            'meeting_interval_weeks' => 2,
            'peak_hours' => array('08:00-09:30', '16:00-17:00'),
            'manager_meeting_hours' => array('10:00-16:00'),
            'auto_shift_creation' => false,
            'conflict_detection' => true,
            'workload_balancing' => false,
            'skill_management' => false,
            'shift_templates' => array(
                'inbound' => array('start_time' => '08:00', 'end_time' => '17:00', 'type' => 'Inbound'),
                'backoffice-chat' => array('start_time' => '09:00', 'end_time' => '17:00', 'type' => 'Backoffice & Chat'),
                'outbound-chat' => array('start_time' => '09:00', 'end_time' => '17:00', 'type' => 'Outbound & Chat'),
                'training' => array('start_time' => '09:00', 'end_time' => '17:00', 'type' => 'Training'),
                'verlof' => array('start_time' => '08:00', 'end_time' => '17:00', 'type' => 'Verlof', 'full_day' => true)
            ),
            'employee_preferences' => array(
                'default_contract_hours' => 24,
                'max_hours_per_day' => 8,
                'min_rest_time' => 11
            ),
            'coverage_requirements' => array(
                'inbound_min' => 5,
                'backoffice_chat_min' => 2
            ),
            'notification_settings' => array(
                'email_planning_changes' => true,
                'email_conflicts' => true,
                'email_meetings' => true
            ),
            'export_formats' => array(
                'csv_enabled' => true,
                'excel_enabled' => true
            ),
            'backup_settings' => array(
                'auto_backup' => false,
                'backup_frequency' => 'daily'
            ),
            'advanced' => array(
                'cache_duration' => 3600,
                'max_shifts_per_week' => 50
            )
        ));

        // Schedule cron jobs
        if (!wp_next_scheduled('powerplanner_pattern_detection')) {
            wp_schedule_event(time(), 'daily', 'powerplanner_pattern_detection');
        }

        flush_rewrite_rules();

    } catch (Exception $e) {
        // Log the error
        error_log('PowerPlanner Activation Error: ' . $e->getMessage());
        wp_die('PowerPlanner activation failed: ' . $e->getMessage());
    }
}

// Deactivation hook
register_deactivation_hook(__FILE__, 'powerplanner_deactivate');
function powerplanner_deactivate()
{
    try {
        wp_clear_scheduled_hook('powerplanner_pattern_detection');
        flush_rewrite_rules();
        error_log('PowerPlanner: Plugin deactivated successfully');
    } catch (Exception $e) {
        error_log('PowerPlanner Deactivation Error: ' . $e->getMessage());
    }
}

// Add admin notice for debugging
add_action('admin_notices', 'powerplanner_debug_notice');
function powerplanner_debug_notice()
{
    if (current_user_can('manage_options') && isset($_GET['powerplanner_debug'])) {
        echo '<div class="notice notice-info"><p>';
        echo '<strong>PowerPlanner Debug Info:</strong><br>';
        echo 'Plugin Dir: ' . POWERPLANNER_PLUGIN_DIR . '<br>';
        echo 'Plugin URL: ' . POWERPLANNER_PLUGIN_URL . '<br>';
        echo 'Plugin Basename: ' . POWERPLANNER_PLUGIN_BASENAME . '<br>';
        echo 'CSS File Exists: ' . (file_exists(POWERPLANNER_PLUGIN_DIR . 'assets/css/style.css') ? 'Yes' : 'No') . '<br>';
        echo 'JS File Exists: ' . (file_exists(POWERPLANNER_PLUGIN_DIR . 'assets/js/planner.js') ? 'Yes' : 'No') . '<br>';
        echo '</p></div>';
    }
}

// Add plugin reactivation action
add_action('admin_action_powerplanner_reactivate', 'powerplanner_reactivate_plugin');
function powerplanner_reactivate_plugin()
{
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized');
    }

    try {
        // Deactivate plugin
        deactivate_plugins(POWERPLANNER_PLUGIN_BASENAME);

        // Clear any caches
        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
        }

        // Clear WordPress object cache
        if (function_exists('wp_cache_delete')) {
            wp_cache_delete('powerplanner_settings', 'options');
        }

        // Clear any plugin-specific caches
        delete_transient('powerplanner_cache');
        delete_transient('powerplanner_assets_cache');

        // Reactivate plugin
        activate_plugin(POWERPLANNER_PLUGIN_BASENAME);

        // Redirect back with success message
        wp_redirect(admin_url('admin.php?page=powerplanner&powerplanner_reactivated=1'));
        exit;

    } catch (Exception $e) {
        error_log('PowerPlanner Reactivation Error: ' . $e->getMessage());
        wp_redirect(admin_url('admin.php?page=powerplanner&powerplanner_error=1'));
        exit;
    }
}

// Add reactivation success notice
add_action('admin_notices', 'powerplanner_reactivation_notice');
function powerplanner_reactivation_notice()
{
    if (current_user_can('manage_options') && isset($_GET['powerplanner_reactivated'])) {
        echo '<div class="notice notice-success"><p><strong>PowerPlanner:</strong> Plugin succesvol opnieuw geactiveerd!</p></div>';
    }
    if (current_user_can('manage_options') && isset($_GET['powerplanner_error'])) {
        echo '<div class="notice notice-error"><p><strong>PowerPlanner:</strong> Fout bij opnieuw activeren van plugin. Check error logs.</p></div>';
    }
}

// Load plugin classes
add_action('plugins_loaded', 'powerplanner_load_classes');
function powerplanner_load_classes()
{
    try {
        // Check if files exist before requiring
        $required_files = array(
            'includes/class-database.php',
            'includes/class-scheduler.php',
            'includes/class-api.php'
        );

        foreach ($required_files as $file) {
            $file_path = POWERPLANNER_PLUGIN_DIR . $file;
            if (!file_exists($file_path)) {
                error_log("PowerPlanner: Missing file {$file}");
                return;
            }
            require_once $file_path;
        }

        // Check if classes exist
        if (
            !class_exists('PowerPlanner_Database') ||
            !class_exists('PowerPlanner_Scheduler') ||
            !class_exists('PowerPlanner_API')
        ) {
            error_log('PowerPlanner: Required classes not found');
            return;
        }

        // Initialize API
        $api = new PowerPlanner_API();
        $api->init();

    } catch (Exception $e) {
        error_log('PowerPlanner Class Loading Error: ' . $e->getMessage());
    }
}

// Load admin interface
if (is_admin()) {
    try {
        $admin_files = array(
            'admin/admin-interface.php',
            'admin/admin-ajax.php'
        );

        foreach ($admin_files as $file) {
            $file_path = POWERPLANNER_PLUGIN_DIR . $file;
            if (file_exists($file_path)) {
                require_once $file_path;
            } else {
                error_log("PowerPlanner: Missing admin file {$file}");
            }
        }
    } catch (Exception $e) {
        error_log('PowerPlanner Admin Loading Error: ' . $e->getMessage());
    }

    add_action('admin_menu', 'powerplanner_admin_menu');
    function powerplanner_admin_menu()
    {
        try {
            add_menu_page(
                'PowerPlanner',
                'PowerPlanner',
                'manage_options',
                'powerplanner',
                'powerplanner_admin_page',
                'dashicons-calendar-alt',
                30
            );

            add_submenu_page(
                'powerplanner',
                'Planning',
                'Planning',
                'manage_options',
                'powerplanner',
                'powerplanner_admin_page'
            );

            add_submenu_page(
                'powerplanner',
                'Patterns',
                'Patterns',
                'manage_options',
                'powerplanner-patterns',
                'powerplanner_patterns_page'
            );

            add_submenu_page(
                'powerplanner',
                'Employees',
                'Employees',
                'manage_options',
                'powerplanner-employees',
                'powerplanner_employees_page'
            );

            add_submenu_page(
                'powerplanner',
                'News',
                'News',
                'manage_options',
                'powerplanner-news',
                'powerplanner_news_page'
            );

            add_submenu_page(
                'powerplanner',
                'Settings',
                'Settings',
                'manage_options',
                'powerplanner-settings',
                'powerplanner_settings_page'
            );

        } catch (Exception $e) {
            error_log('PowerPlanner Admin Menu Error: ' . $e->getMessage());
        }
    }

    // Enqueue admin scripts and styles
    add_action('admin_enqueue_scripts', 'powerplanner_admin_assets');
    function powerplanner_admin_assets($hook)
    {
        try {
            if (strpos($hook, 'powerplanner') === false) {
                return;
            }

            // Check if asset files exist
            $css_files = array(
                'assets/css/style.css',
                'assets/css/mobile.css'
            );

            foreach ($css_files as $css_file) {
                $css_path = POWERPLANNER_PLUGIN_DIR . $css_file;
                if (file_exists($css_path)) {
                    $handle = 'powerplanner-admin-' . str_replace(array('assets/css/', '.css'), '', $css_file) . '-' . POWERPLANNER_VERSION;
                    $css_url = POWERPLANNER_PLUGIN_URL . $css_file;
                    wp_enqueue_style($handle, $css_url, array(), POWERPLANNER_VERSION);
                    error_log("PowerPlanner: Enqueuing CSS: {$css_url} with handle: {$handle}");
                } else {
                    error_log("PowerPlanner: Missing CSS file {$css_file}");
                }
            }

            // Check if JS file exists
            $js_file = 'assets/js/planner.js';
            $js_path = POWERPLANNER_PLUGIN_DIR . $js_file;
            if (file_exists($js_path)) {
                $js_url = POWERPLANNER_PLUGIN_URL . $js_file;
                $js_handle = 'powerplanner-admin-js-' . POWERPLANNER_VERSION;
                wp_enqueue_script($js_handle, $js_url, array('jquery'), POWERPLANNER_VERSION, true);
                error_log("PowerPlanner: Enqueuing JS: {$js_url} with handle: {$js_handle}");
            } else {
                error_log("PowerPlanner: Missing JS file {$js_file}");
            }

            // Localize script for AJAX
            wp_localize_script($js_handle, 'powerplanner', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('powerplanner_nonce'),
                'api_url' => home_url('/wp-json/powerplanner/v1/'),
                'strings' => array(
                    'confirm_delete' => __('Weet je zeker dat je deze dienst wilt verwijderen?', 'powerplanner'),
                    'saving' => __('Opslaan...', 'powerplanner'),
                    'saved' => __('Opgeslagen!', 'powerplanner'),
                    'error' => __('Er is een fout opgetreden', 'powerplanner')
                )
            ));

        } catch (Exception $e) {
            error_log('PowerPlanner Admin Assets Error: ' . $e->getMessage());
        }
    }
}

// Frontend shortcodes
add_shortcode('powerplanner_employee_view', 'powerplanner_employee_view_shortcode');
function powerplanner_employee_view_shortcode($atts)
{
    try {
        ob_start();
        $file_path = POWERPLANNER_PLUGIN_DIR . 'public/employee-view.php';
        if (file_exists($file_path)) {
            require_once $file_path;
        } else {
            echo '<p>PowerPlanner: Employee view file not found</p>';
        }
        return ob_get_clean();
    } catch (Exception $e) {
        error_log('PowerPlanner Employee View Error: ' . $e->getMessage());
        return '<p>PowerPlanner: Error loading employee view</p>';
    }
}

add_shortcode('powerplanner_team_view', 'powerplanner_team_view_shortcode');
function powerplanner_team_view_shortcode($atts)
{
    try {
        ob_start();
        $file_path = POWERPLANNER_PLUGIN_DIR . 'public/team-view.php';
        if (file_exists($file_path)) {
            require_once $file_path;
        } else {
            echo '<p>PowerPlanner: Team view file not found</p>';
        }
        return ob_get_clean();
    } catch (Exception $e) {
        error_log('PowerPlanner Team View Error: ' . $e->getMessage());
        return '<p>PowerPlanner: Error loading team view</p>';
    }
}

// Frontend assets
add_action('wp_enqueue_scripts', 'powerplanner_frontend_assets');
function powerplanner_frontend_assets()
{
    try {
        if (
            is_page() && (has_shortcode(get_post()->post_content, 'powerplanner_employee_view') ||
                has_shortcode(get_post()->post_content, 'powerplanner_team_view'))
        ) {

            // Check if CSS files exist
            $css_files = array(
                'assets/css/style.css',
                'assets/css/mobile.css'
            );

            foreach ($css_files as $css_file) {
                $css_path = POWERPLANNER_PLUGIN_DIR . $css_file;
                if (file_exists($css_path)) {
                    $handle = 'powerplanner-frontend-' . str_replace(array('assets/css/', '.css'), '', $css_file) . '-' . POWERPLANNER_VERSION;
                    $css_url = POWERPLANNER_PLUGIN_URL . $css_file;
                    wp_enqueue_style($handle, $css_url, array(), POWERPLANNER_VERSION);
                    error_log("PowerPlanner Frontend: Enqueuing CSS: {$css_url} with handle: {$handle}");
                }
            }

            // Check if JS file exists
            $js_file = 'assets/js/planner.js';
            $js_path = POWERPLANNER_PLUGIN_DIR . $js_file;
            if (file_exists($js_path)) {
                $js_url = POWERPLANNER_PLUGIN_URL . $js_file;
                $js_handle = 'powerplanner-frontend-js-' . POWERPLANNER_VERSION;
                wp_enqueue_script($js_handle, $js_url, array('jquery'), POWERPLANNER_VERSION, true);
                error_log("PowerPlanner Frontend: Enqueuing JS: {$js_url} with handle: {$js_handle}");

                wp_localize_script($js_handle, 'powerplanner', array(
                    'api_url' => home_url('/wp-json/powerplanner/v1/'),
                    'user_id' => get_current_user_id()
                ));
            }
        }
    } catch (Exception $e) {
        error_log('PowerPlanner Frontend Assets Error: ' . $e->getMessage());
    }
}

// WebSocket support for real-time updates
add_action('init', 'powerplanner_websocket_support');
function powerplanner_websocket_support()
{
    try {
        if (isset($_GET['powerplanner_ws']) && current_user_can('read')) {
            header('Content-Type: text/event-stream');
            header('Cache-Control: no-cache');
            header('Connection: keep-alive');

            // Send updates every 3 seconds
            while (true) {
                if (class_exists('PowerPlanner_Scheduler')) {
                    $scheduler = new PowerPlanner_Scheduler();
                    $updates = $scheduler->get_recent_updates();

                    if (!empty($updates)) {
                        echo "data: " . json_encode($updates) . "\n\n";
                        ob_flush();
                        flush();
                    }
                }

                sleep(3);

                if (connection_aborted()) {
                    break;
                }
            }
            exit;
        }
    } catch (Exception $e) {
        error_log('PowerPlanner WebSocket Error: ' . $e->getMessage());
    }
}

// Add custom capabilities
add_action('admin_init', 'powerplanner_add_capabilities');
function powerplanner_add_capabilities()
{
    try {
        $role = get_role('administrator');
        if ($role) {
            $role->add_cap('powerplanner_manage');
            $role->add_cap('powerplanner_view_all');
        }

        // Add employee role
        if (!get_role('employee')) {
            add_role('employee', 'Employee', array(
                'read' => true,
                'powerplanner_view_own' => true,
                'powerplanner_view_team' => true
            ));
        }
    } catch (Exception $e) {
        error_log('PowerPlanner Capabilities Error: ' . $e->getMessage());
    }
}