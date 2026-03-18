<?php
/**
 * PowerPlanner Team View
 * Frontend view showing entire team schedule
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

$db = new PowerPlanner_Database();
$scheduler = new PowerPlanner_Scheduler();

// Ensure we get the actual current week, not cached
$actual_current_week = intval(date('W'));
$actual_current_year = intval(date('Y'));

// FIX: We gebruiken 'pp_week' en 'pp_year' om conflicten met WordPress archieven te voorkomen
$current_week = isset($_GET['pp_week']) ? intval($_GET['pp_week']) : (isset($_GET['week']) ? intval($_GET['week']) : $actual_current_week);
$current_year = isset($_GET['pp_year']) ? intval($_GET['pp_year']) : (isset($_GET['year']) ? intval($_GET['year']) : $actual_current_year);

function get_week_dates($week, $year) {
    $dto = new DateTime();
    $dto->setISODate($year, $week);
    $monday = $dto->format('d-m');
    $dto->modify('+6 days');
    $sunday = $dto->format('d-m');
    return "$monday t/m $sunday";
}

// Get all employees and shifts
$employees = $db->get_employees();
$shifts = $db->get_week_shifts($current_week, $current_year);
$dagstart = $scheduler->get_dagstart_rotation($current_week, $current_year);

// Debug: Log week parameters
error_log("PowerPlanner Team View: Week {$current_week}, Year {$current_year}, Shifts found: " . count($shifts));

// Calculate team availability
$today_dutch = array(
    'monday' => 'maandag',
    'tuesday' => 'dinsdag',
    'wednesday' => 'woensdag',
    'thursday' => 'donderdag',
    'friday' => 'vrijdag',
    'saturday' => 'zaterdag',
    'sunday' => 'zondag'
);
$today = $today_dutch[strtolower(date('l'))];
$current_hour = date('H:i');

$available_now = array();
$on_break = array();
$unavailable = array();

// Pre-group shifts by employee for better performance
$shifts_by_employee = array();
foreach ($shifts as $shift) {
    $shifts_by_employee[$shift['employee_id']][] = $shift;
}

foreach ($employees as $employee) {
    $employee_shifts = $shifts_by_employee[$employee['id']] ?? array();
    $today_shifts = array_filter($employee_shifts, function($s) use ($today) {
        return $s['day_of_week'] == $today;
    });
    
    $is_available = false;
    $is_on_break = false;
    
    foreach ($today_shifts as $shift) {
        if (in_array($shift['shift_type'], ['Verlof', 'Vakantie', 'Verzuim', 'Tandarts'])) {
            $unavailable[] = $employee;
            break;
        } elseif (!$shift['full_day'] && $shift['start_time'] <= $current_hour . ':00' && $shift['end_time'] >= $current_hour . ':00') {
            $is_available = true;
            $employee['current_task'] = $shift['shift_type'];
        }
    }
    
    if ($is_available) {
        $available_now[] = $employee;
    } elseif (!in_array($employee, $unavailable) && empty($today_shifts)) {
        $on_break[] = $employee;
    }
}
?>
    
    <div class="pp-week-nav-team">
        <?php
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
        
        // Base URL constructie zonder query parameters
        $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http");
        $host = $_SERVER['HTTP_HOST'];
        $path = strtok($_SERVER["REQUEST_URI"], '?');
        $base_url = $protocol . "://" . $host . $path;
        ?>
        
        <a href="<?php echo add_query_arg(array('pp_week' => $prev_week, 'pp_year' => $prev_year), $base_url); ?>" class="nav-prev">← Vorige</a>
        
        <span class="current-week">
            Week <?php echo $current_week; ?> - 
            <form method="GET" action="<?php echo esc_url($base_url); ?>" style="display:inline-block;">
                <select name="pp_year" onchange="this.form.submit()" style="background:transparent; border:none; font-weight:bold; font-size:inherit; color:inherit; padding:0; cursor:pointer;">
                    <?php 
                    $start_year = 2023;
                    $end_year = 2027; 
                    for ($y = $start_year; $y <= $end_year; $y++): ?>
                        <option value="<?php echo $y; ?>" <?php selected($current_year, $y); ?>><?php echo $y; ?></option>
                    <?php endfor; ?>
                </select>
                <input type="hidden" name="pp_week" value="<?php echo $current_week; ?>">
                <?php if(isset($_GET['employee'])): ?>
                    <input type="hidden" name="employee" value="<?php echo intval($_GET['employee']); ?>">
                <?php endif; ?>
            </form>
            (<?php echo get_week_dates($current_week, $current_year); ?>)
        </span>
        
        <a href="<?php echo add_query_arg(array('pp_week' => $next_week, 'pp_year' => $next_year), $base_url); ?>" class="nav-next">Volgende →</a>
    </div>
    
    
    <div class="pp-team-schedule">
        <h3>Team Planning</h3>
        
        <div class="team-grid-container">
            <table class="team-grid">
                <thead>
                    <tr>
                        <th>Medewerker</th>
                        <th>Ma</th>
                        <th>Di</th>
                        <th>Wo</th>
                        <th>Do</th>
                        <th>Vr</th>
                    </tr>
                    <tr class="dagstart-row">
                        <td class="dagstart-label"><strong>📅 Dagstart Voorzitter:</strong></td>
                        <td class="dagstart-person"><?php echo esc_html($dagstart['maandag'] ?? '-'); ?></td>
                        <td class="dagstart-person"><?php echo esc_html($dagstart['dinsdag'] ?? '-'); ?></td>
                        <td class="dagstart-person"><?php echo esc_html($dagstart['woensdag'] ?? '-'); ?></td>
                        <td class="dagstart-person"><?php echo esc_html($dagstart['donderdag'] ?? '-'); ?></td>
                        <td class="dagstart-person"><?php echo esc_html($dagstart['vrijdag'] ?? '-'); ?></td>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($employees as $employee): ?>
                    <tr>
                        <td class="employee-name"><?php echo esc_html($employee['name']); ?></td>
                        
                        <?php 
                        $days_short = array('maandag' => 'Ma', 'dinsdag' => 'Di', 'woensdag' => 'Wo', 'donderdag' => 'Do', 'vrijdag' => 'Vr');
                        foreach (array_keys($days_short) as $day): 
                            $day_shifts = array_filter($shifts, function($s) use ($employee, $day) {
                                return $s['employee_id'] == $employee['id'] && $s['day_of_week'] == $day;
                            });
                        ?>
                        <td class="day-cell">
                            <?php if (!empty($day_shifts)): ?>
                                <?php foreach ($day_shifts as $shift): ?>
                                    <div class="full-shift shift-<?php echo strtolower(str_replace(' ', '-', $shift['shift_type'])); ?>">
                                        <div class="shift-type"><?php echo esc_html($shift['shift_type']); ?></div>
                                        <?php if (!$shift['full_day']): ?>
                                            <div class="shift-time"><?php echo substr($shift['start_time'], 0, 5); ?>-<?php echo substr($shift['end_time'], 0, 5); ?></div>
                                        <?php endif; ?>
                                        <?php if ($shift['buddy']): ?>
                                            <div class="shift-buddy">& <?php echo esc_html($shift['buddy']); ?></div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="no-shift">Geen dienst</div>
                            <?php endif; ?>
                        </td>
                        <?php endforeach; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>