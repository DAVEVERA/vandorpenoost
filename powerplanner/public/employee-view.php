<?php
/**
 * PowerPlanner Employee View
 * Frontend view for individual employees
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

$db = new PowerPlanner_Database();
$scheduler = new PowerPlanner_Scheduler();

// Get selected employee or show first active employee
$selected_employee_id = isset($_GET['employee']) ? intval($_GET['employee']) : null;
global $wpdb;
$employees_table = $wpdb->prefix . 'powerplanner_employees';

if ($selected_employee_id) {
    $employee = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$employees_table} WHERE id = %d AND is_active = 1",
        $selected_employee_id
    ), ARRAY_A);
}

if (!isset($employee) || !$employee) {
    $employee = $wpdb->get_row(
        "SELECT * FROM {$employees_table} WHERE is_active = 1 ORDER BY name ASC LIMIT 1",
        ARRAY_A
    );
}

if (!$employee) {
    echo '<p>Geen actieve medewerkers gevonden.</p>';
    return;
}

// Get all employees for selector
$all_employees = $wpdb->get_results(
    "SELECT * FROM {$employees_table} WHERE is_active = 1 ORDER BY name ASC",
    ARRAY_A
);

// Get week from URL parameters or use current week
$actual_current_week = intval(date('W'));
$actual_current_year = intval(date('Y'));

$current_week = isset($_GET['week']) ? intval($_GET['week']) : $actual_current_week;
$current_year = isset($_GET['year']) ? intval($_GET['year']) : $actual_current_year;

function get_week_dates($week, $year)
{
    $dto = new DateTime();
    $dto->setISODate($year, $week);
    $monday = $dto->format('d-m');
    $dto->modify('+6 days');
    $sunday = $dto->format('d-m');
    return "$monday t/m $sunday";
}

// Get this week's shifts
$my_shifts = $db->get_employee_shifts($employee['id'], $current_week, $current_year);

// Debug: Log week parameters
error_log("PowerPlanner Employee View: Week {$current_week}, Year {$current_year}, Employee {$employee['id']}, Shifts found: " . count($my_shifts));

// Get today's shift
$today = strtolower(date('l'));
$today_dutch = array(
    'monday' => 'maandag',
    'tuesday' => 'dinsdag',
    'wednesday' => 'woensdag',
    'thursday' => 'donderdag',
    'friday' => 'vrijdag',
    'saturday' => 'zaterdag',
    'sunday' => 'zondag'
);
$today_dutch = $today_dutch[$today];

$today_shift = array_filter($my_shifts, function ($s) use ($today_dutch) {
    return $s['day_of_week'] == $today_dutch;
});

// Get manager status (find first user with manager role)
$manager_users = get_users(array('role' => 'administrator', 'number' => 1));
$manager_id = !empty($manager_users) ? $manager_users[0]->ID : 1;
$manager_status = get_user_meta($manager_id, 'manager_status', true) ?: 'available';
$manager_location = get_user_meta($manager_id, 'manager_location', true) ?: 'office';
?>

<div class="pp-employee-view">
    <!-- Employee Selector -->
    <div class="pp-employee-selector">
        <label for="employee-select">Bekijk planning van:</label>
        <select id="employee-select" onchange="changeEmployee(this.value)">
            <?php foreach ($all_employees as $emp): ?>
                <option value="<?php echo $emp['id']; ?>" <?php selected($emp['id'], $employee['id']); ?>>
                    <?php echo esc_html($emp['name']); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <!-- Today's Overview -->
    <div class="pp-today-card">
        <h2><?php echo esc_html($employee['name']); ?> - Vandaag (<?php echo ucfirst($today_dutch); ?>)</h2>

        <?php if (!empty($today_shift)): ?>
            <?php foreach ($today_shift as $shift): ?>
                <div class="today-shift shift-<?php echo strtolower(str_replace(' ', '-', $shift['shift_type'])); ?>">
                    <div class="shift-type"><?php echo esc_html($shift['shift_type']); ?></div>
                    <?php if (!$shift['full_day']): ?>
                        <div class="shift-time">
                            <?php echo substr($shift['start_time'], 0, 5); ?> -
                            <?php echo substr($shift['end_time'], 0, 5); ?>
                        </div>
                    <?php else: ?>
                        <div class="shift-time">Hele dag</div>
                    <?php endif; ?>
                    <?php if ($shift['buddy']): ?>
                        <div class="shift-buddy">Met: <?php echo esc_html($shift['buddy']); ?></div>
                    <?php endif; ?>
                    <?php if ($shift['note']): ?>
                        <div class="shift-note"><?php echo esc_html($shift['note']); ?></div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Statistics -->
    <div class="pp-employee-stats">
        <h3>Statistieken - <?php echo esc_html($employee['name']); ?></h3>
        <div class="stats-grid">
            <?php
            $total_hours = 0;
            $admin_hours = 0;
            $inbound_hours = 0;

            foreach ($my_shifts as $shift) {
                if (!$shift['full_day'] && $shift['start_time'] && $shift['end_time']) {
                    $start = new DateTime($shift['start_time']);
                    $end = new DateTime($shift['end_time']);
                    $diff = $end->diff($start);
                    $hours = $diff->h + ($diff->i / 60);
                    $total_hours += $hours;

                    if ($shift['shift_type'] == 'Inbound') {
                        $inbound_hours += $hours;
                    } elseif (in_array($shift['shift_type'], ['Backoffice & Chat', 'Outbound & Chat', 'Training'])) {
                        $admin_hours += $hours;
                    }
                } elseif ($shift['full_day']) {
                    $total_hours += 8;
                }
            }
            ?>
            <div class="stat-card">
                <div class="stat-value"><?php echo round($total_hours); ?>u</div>
                <div class="stat-label">Deze week</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo round($inbound_hours); ?>u</div>
                <div class="stat-label">Inbound</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo round($admin_hours); ?>u</div>
                <div class="stat-label">Admin</div>
            </div>
        </div>
    </div>

    <!-- Next Meeting -->
    <?php
    $meetings_table = $wpdb->prefix . 'powerplanner_meetings';
    $next_meeting = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$meetings_table} 
         WHERE employee_id = %d 
         AND scheduled_date >= CURDATE() 
         AND status = 'scheduled'
         ORDER BY scheduled_date ASC, scheduled_time ASC
         LIMIT 1",
        $employee['id']
    ), ARRAY_A);

    if ($next_meeting):
        ?>
        <div class="pp-next-meeting">
            <h3>Volgende 1-op-1 Meeting</h3>
            <div class="meeting-info">
                <div class="meeting-date">
                    <?php echo date_i18n('l j F', strtotime($next_meeting['scheduled_date'])); ?>
                </div>
                <div class="meeting-time">
                    <?php echo substr($next_meeting['scheduled_time'], 0, 5); ?>
                </div>
                <?php if ($next_meeting['notes']): ?>
                    <div class="meeting-notes"><?php echo esc_html($next_meeting['notes']); ?></div>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="no-shift">
                <p>Je hebt vandaag geen dienst ingepland.</p>
            </div>
        <?php endif; ?>
    </div>

    <!-- Manager Status -->
    <div class="pp-manager-status">
        <h3>Manager Status</h3>
        <div class="manager-status-indicator status-<?php echo $manager_status; ?>">
            <?php
            $status_icons = array(
                'available' => '🟢',
                'busy' => '🟡',
                'meeting' => '🔴',
                'external' => '🚗',
                'unavailable' => '⛔'
            );
            $status_labels = array(
                'available' => 'Beschikbaar',
                'busy' => 'Bezig',
                'meeting' => 'In vergadering',
                'external' => 'Extern',
                'unavailable' => 'Niet beschikbaar'
            );
            ?>
            <span class="status-icon"><?php echo $status_icons[$manager_status]; ?></span>
            <span class="status-label"><?php echo $status_labels[$manager_status]; ?></span>
            <?php if ($manager_location && $manager_location != 'office'): ?>
                <span class="status-location">(<?php echo esc_html($manager_location); ?>)</span>
            <?php endif; ?>
        </div>
    </div>

    <!-- AANGEPAST: Week Navigatie toegevoegd voor Employee View -->
    <div class="pp-week-nav-employee"
        style="margin-top: 30px; margin-bottom: 15px; display: flex; justify-content: space-between; align-items: center; background: #f9f9f9; padding: 10px; border-radius: 5px;">
        <?php
        // Bereken volgende/vorige week
        $prev_week = $current_week - 1;
        $prev_year = $current_year;
        if ($prev_week < 1) {
            $prev_week = 52;
            $prev_year--;
        }

        $next_week = $current_week + 1;
        $next_year = $current_year;
        if ($next_week > 52) {
            $next_week = 1;
            $next_year++;
        }

        // Base URL logic
        $current_url = strtok($_SERVER["REQUEST_URI"], '?');
        $base_url = home_url($current_url);
        ?>
        <a href="<?php echo add_query_arg(array('week' => $prev_week, 'year' => $prev_year, 'employee' => $employee['id']), $base_url); ?>"
            class="nav-prev" style="text-decoration: none; font-weight: bold;">← Vorige</a>

        <span class="current-week">
            Week <?php echo $current_week; ?> -
            <form method="GET" action="<?php echo esc_url($base_url); ?>" style="display:inline-block;">
                <select name="year" onchange="this.form.submit()"
                    style="background:transparent; border:none; font-weight:bold; font-size:inherit; color:inherit; padding:0; cursor:pointer;">
                    <?php
                    $start_year = 2023;
                    $end_year = 2027; // Range 2023-2027
                    for ($y = $start_year; $y <= $end_year; $y++): ?>
                        <option value="<?php echo $y; ?>" <?php selected($current_year, $y); ?>><?php echo $y; ?></option>
                    <?php endfor; ?>
                </select>
                <input type="hidden" name="week" value="<?php echo $current_week; ?>">
                <input type="hidden" name="employee" value="<?php echo $employee['id']; ?>">
            </form>
        </span>

        <a href="<?php echo add_query_arg(array('week' => $next_week, 'year' => $next_year, 'employee' => $employee['id']), $base_url); ?>"
            class="nav-next" style="text-decoration: none; font-weight: bold;">Volgende →</a>
    </div>

    <!-- This Week Overview -->
    <div class="pp-week-overview">
        <h3><?php echo esc_html($employee['name']); ?> - Week <?php echo $current_week; ?>
            (<?php echo get_week_dates($current_week, $current_year); ?>)</h3>

        <div class="week-grid">
            <?php
            $days = array('maandag', 'dinsdag', 'woensdag', 'donderdag', 'vrijdag');
            foreach ($days as $day):
                $day_shifts = array_filter($my_shifts, function ($s) use ($day) {
                    return $s['day_of_week'] == $day;
                });
                ?>
                <div class="week-day <?php echo $day == $today_dutch ? 'current-day' : ''; ?>">
                    <div class="day-header"><?php echo ucfirst($day); ?></div>
                    <div class="day-shift">
                        <?php if (!empty($day_shifts)): ?>
                            <?php foreach ($day_shifts as $shift): ?>
                                <div
                                    class="shift-item shift-<?php echo strtolower(str_replace(' ', '-', $shift['shift_type'])); ?>">
                                    <div class="shift-type"><?php echo esc_html($shift['shift_type']); ?></div>
                                    <?php if (!$shift['full_day']): ?>
                                        <div class="shift-time">
                                            <?php echo substr($shift['start_time'], 0, 5); ?> -
                                            <?php echo substr($shift['end_time'], 0, 5); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <span class="no-shift">-</span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<script>
    // Global function for employee selector
    if (typeof window.changeEmployee === 'undefined') {
        window.changeEmployee = function (employeeId) {
            const url = new URL(window.location);
            url.searchParams.set('employee', employeeId);
            window.location.href = url.toString();
        };
    }

    // Global function for week navigation
    if (typeof window.changeWeek === 'undefined') {
        window.changeWeek = function (week, year) {
            const url = new URL(window.location);
            url.searchParams.set('week', week);
            url.searchParams.set('year', year);
            window.location.href = url.toString();
        };
    }
</script>