<?php
/**
 * Updated leave calculation functions that consider location-based holidays
 * These should replace or supplement existing leave calculation functions
 */

/**
 * Calculate working days between two dates for an employee
 * Excludes weekends and location-based holidays
 *
 * @param int $employee_id Employee ID
 * @param string $start_date Start date (Y-m-d format)
 * @param string $end_date End date (Y-m-d format)
 * @param array $policy Leave policy settings
 * @return float Number of working days
 */
function erp_hr_calculate_leave_days_with_holidays($employee_id, $start_date, $end_date, $policy = array()) {
    $start = new DateTime($start_date);
    $end = new DateTime($end_date);
    $end->modify('+1 day'); // Include end date
    
    $interval = new DateInterval('P1D');
    $period = new DatePeriod($start, $interval, $end);
    
    $working_days = 0;
    
    // Get employee's holidays for this period
    $holiday_dates = erp_hr_get_employee_holiday_dates($employee_id, $start_date, $end_date);
    
    // Get weekend days from policy or use default
    $weekend_days = array();
    if (isset($policy['weekends']) && is_array($policy['weekends'])) {
        $weekend_days = $policy['weekends'];
    } else {
        // Default weekends (Saturday and Sunday)
        $weekend_days = array('Sat', 'Sun');
    }
    
    foreach ($period as $date) {
        $day_name = $date->format('D');
        $date_string = $date->format('Y-m-d');
        
        // Skip weekends
        if (in_array($day_name, $weekend_days)) {
            continue;
        }
        
        // Skip holidays
        if (in_array($date_string, $holiday_dates)) {
            continue;
        }
        
        $working_days++;
    }
    
    return $working_days;
}

/**
 * Validate leave request considering location-based holidays
 *
 * @param array $args Leave request arguments
 * @return bool|WP_Error True if valid, WP_Error on failure
 */
function erp_hr_validate_leave_request_with_holidays($args) {
    $employee_id = isset($args['employee_id']) ? intval($args['employee_id']) : 0;
    $start_date = isset($args['start_date']) ? $args['start_date'] : '';
    $end_date = isset($args['end_date']) ? $args['end_date'] : '';
    $policy_id = isset($args['policy_id']) ? intval($args['policy_id']) : 0;
    
    // Validate employee
    if (!$employee_id) {
        return new WP_Error('invalid_employee', __('Invalid employee ID', 'erp'));
    }
    
    // Validate dates
    if (empty($start_date) || empty($end_date)) {
        return new WP_Error('invalid_dates', __('Start and end dates are required', 'erp'));
    }
    
    if (strtotime($end_date) < strtotime($start_date)) {
        return new WP_Error('invalid_date_range', __('End date must be after start date', 'erp'));
    }
    
    // Check if employee has work location set
    $work_location = erp_hr_get_employee_work_location($employee_id);
    if (!$work_location) {
        return new WP_Error('no_work_location', __('Employee work location is not set. Please update employee details.', 'erp'));
    }
    
    // Get leave policy
    $policy = erp_hr_leave_get_policy($policy_id);
    if (!$policy) {
        return new WP_Error('invalid_policy', __('Invalid leave policy', 'erp'));
    }
    
    // Calculate leave days excluding holidays
    $leave_days = erp_hr_calculate_leave_days_with_holidays($employee_id, $start_date, $end_date, $policy);
    
    if ($leave_days <= 0) {
        return new WP_Error('no_working_days', __('No working days found in the selected date range (all days are holidays or weekends)', 'erp'));
    }
    
    // Get employee's leave balance
    $entitlement = erp_hr_leave_get_balance($employee_id, $policy_id);
    $available = isset($entitlement['entitlement']) ? $entitlement['entitlement'] : 0;
    $scheduled = isset($entitlement['scheduled']) ? $entitlement['scheduled'] : 0;
    $balance = $available - $scheduled;
    
    if ($leave_days > $balance) {
        return new WP_Error(
            'insufficient_balance',
            sprintf(
                __('Insufficient leave balance. Requested: %s days, Available: %s days', 'erp'),
                $leave_days,
                $balance
            )
        );
    }
    
    return true;
}

/**
 * Hook into leave request creation to use new holiday calculation
 * Add this to your actions-filters.php or similar file
 */
add_filter('erp_hr_leave_request_duration', 'erp_hr_filter_leave_duration_with_holidays', 10, 3);

function erp_hr_filter_leave_duration_with_holidays($duration, $employee_id, $args) {
    if (!isset($args['start_date']) || !isset($args['end_date'])) {
        return $duration;
    }
    
    // Get policy settings if available
    $policy = array();
    if (isset($args['policy_id'])) {
        $policy_data = erp_hr_leave_get_policy($args['policy_id']);
        if ($policy_data) {
            $policy = $policy_data;
        }
    }
    
    // Calculate duration with holidays excluded
    return erp_hr_calculate_leave_days_with_holidays(
        $employee_id,
        $args['start_date'],
        $args['end_date'],
        $policy
    );
}

/**
 * Fix work location on employee save
 * Add this to your actions-filters.php
 */
add_action('erp_hr_employee_new', 'erp_hr_ensure_work_location', 10, 1);
add_action('erp_update_people', 'erp_hr_ensure_work_location', 10, 1);

function erp_hr_ensure_work_location($employee_id) {
    $work_location = get_user_meta($employee_id, '_erp_hr_work_location', true);
    
    // If work location is 0 or empty, set to first company location
    if (empty($work_location) || $work_location === '0' || $work_location === 0) {
        $company_locations = erp_company_get_locations();
        if (!empty($company_locations)) {
            $first_location = reset($company_locations);
            update_user_meta($employee_id, '_erp_hr_work_location', $first_location->id);
        }
    }
}

/**
 * Admin notice to warn about employees with missing work locations
 */
add_action('admin_notices', 'erp_hr_work_location_admin_notice');

function erp_hr_work_location_admin_notice() {
    // Only show on HR pages
    $screen = get_current_screen();
    if (!$screen || strpos($screen->id, 'erp-hr') === false) {
        return;
    }
    
    global $wpdb;
    
    // Count employees with missing or zero work location
    $count = $wpdb->get_var(
        "SELECT COUNT(DISTINCT p.id) 
         FROM {$wpdb->prefix}erp_peoples p
         INNER JOIN {$wpdb->prefix}erp_hr_employees e ON p.id = e.user_id
         LEFT JOIN {$wpdb->prefix}usermeta um ON p.user_id = um.user_id AND um.meta_key = '_erp_hr_work_location'
         WHERE p.type = 'employee' 
         AND e.status = 'active'
         AND (um.meta_value IS NULL OR um.meta_value = '0' OR um.meta_value = '')"
    );
    
    if ($count > 0) {
        ?>
        <div class="notice notice-warning is-dismissible">
            <p>
                <strong><?php _e('WP ERP Warning:', 'erp'); ?></strong>
                <?php printf(
                    _n(
                        '%s employee has no work location assigned. This may affect leave calculations and holiday tracking.',
                        '%s employees have no work location assigned. This may affect leave calculations and holiday tracking.',
                        $count,
                        'erp'
                    ),
                    '<strong>' . $count . '</strong>'
                ); ?>
                <a href="<?php echo admin_url('admin.php?page=erp-hr-employee'); ?>">
                    <?php _e('Review employees', 'erp'); ?>
                </a>
            </p>
        </div>
        <?php
    }
}
