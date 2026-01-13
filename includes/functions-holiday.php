<?php
/**
 * Holiday location-based helper functions
 * Add these to includes/functions.php or create a new file includes/functions-holiday.php
 */

/**
 * Get applicable holidays for an employee based on their location
 *
 * @param int $employee_id Employee ID
 * @param string $start_date Start date (Y-m-d format)
 * @param string $end_date End date (Y-m-d format)
 * @return array Array of holiday dates
 */
function erp_hr_get_employee_holidays($employee_id, $start_date, $end_date) {
    global $wpdb;
    
    // Get employee's work location
    $work_location = erp_hr_get_employee_work_location($employee_id);
    
    if (!$work_location) {
        return array();
    }
    
    // Get location details
    $location = erp_company_get_location($work_location);
    
    if (!$location) {
        return array();
    }
    
    $country = isset($location->country) ? $location->country : '';
    $state = isset($location->state) ? $location->state : '';
    
    // Build query to get applicable holidays
    $query = "SELECT DISTINCT h.* 
              FROM {$wpdb->prefix}erp_hr_holiday h
              LEFT JOIN {$wpdb->prefix}erp_hr_holiday_locations hl ON h.id = hl.holiday_id
              LEFT JOIN {$wpdb->prefix}erp_hr_holiday_companies hc ON h.id = hc.holiday_id
              WHERE h.start <= %s 
              AND h.end >= %s
              AND (
                  -- Global holidays (no location restrictions)
                  (hl.id IS NULL AND hc.id IS NULL)
                  -- Country-wide holidays
                  OR (hl.country = %s AND hl.state IS NULL)
                  -- State-specific holidays
                  OR (hl.country = %s AND hl.state = %s)
                  -- Company-specific holidays
                  OR hc.company_id = %d
              )
              ORDER BY h.start ASC";
    
    $holidays = $wpdb->get_results(
        $wpdb->prepare(
            $query,
            $end_date,
            $start_date,
            $country,
            $country,
            $state,
            $work_location
        )
    );
    
    return $holidays ? $holidays : array();
}

/**
 * Get holiday dates within a date range for an employee
 *
 * @param int $employee_id Employee ID
 * @param string $start_date Start date (Y-m-d format)
 * @param string $end_date End date (Y-m-d format)
 * @return array Array of date strings
 */
function erp_hr_get_employee_holiday_dates($employee_id, $start_date, $end_date) {
    $holidays = erp_hr_get_employee_holidays($employee_id, $start_date, $end_date);
    $holiday_dates = array();
    
    foreach ($holidays as $holiday) {
        $current = strtotime($holiday->start);
        $end = strtotime($holiday->end);
        
        while ($current <= $end) {
            $date = date('Y-m-d', $current);
            if ($date >= $start_date && $date <= $end_date) {
                $holiday_dates[] = $date;
            }
            $current = strtotime('+1 day', $current);
        }
    }
    
    return array_unique($holiday_dates);
}

/**
 * Count holidays for an employee in a date range
 *
 * @param int $employee_id Employee ID
 * @param string $start_date Start date (Y-m-d format)
 * @param string $end_date End date (Y-m-d format)
 * @return int Number of holiday days
 */
function erp_hr_count_employee_holidays($employee_id, $start_date, $end_date) {
    $holiday_dates = erp_hr_get_employee_holiday_dates($employee_id, $start_date, $end_date);
    return count($holiday_dates);
}

/**
 * Add location to a holiday
 *
 * @param int $holiday_id Holiday ID
 * @param string $country Country code (ISO 3166-1 alpha-2)
 * @param string $state State/Province name (optional)
 * @return int|false Insert ID or false on failure
 */
function erp_hr_add_holiday_location($holiday_id, $country, $state = null) {
    global $wpdb;
    
    $data = array(
        'holiday_id' => $holiday_id,
        'country' => $country,
        'state' => $state,
    );
    
    $result = $wpdb->insert(
        $wpdb->prefix . 'erp_hr_holiday_locations',
        $data,
        array('%d', '%s', '%s')
    );
    
    return $result ? $wpdb->insert_id : false;
}

/**
 * Add company to a holiday
 *
 * @param int $holiday_id Holiday ID
 * @param int $company_id Company location ID
 * @return int|false Insert ID or false on failure
 */
function erp_hr_add_holiday_company($holiday_id, $company_id) {
    global $wpdb;
    
    $data = array(
        'holiday_id' => $holiday_id,
        'company_id' => $company_id,
    );
    
    $result = $wpdb->insert(
        $wpdb->prefix . 'erp_hr_holiday_companies',
        $data,
        array('%d', '%d')
    );
    
    return $result ? $wpdb->insert_id : false;
}

/**
 * Remove all locations from a holiday
 *
 * @param int $holiday_id Holiday ID
 * @return int|false Number of rows deleted or false on failure
 */
function erp_hr_remove_holiday_locations($holiday_id) {
    global $wpdb;
    
    return $wpdb->delete(
        $wpdb->prefix . 'erp_hr_holiday_locations',
        array('holiday_id' => $holiday_id),
        array('%d')
    );
}

/**
 * Remove all companies from a holiday
 *
 * @param int $holiday_id Holiday ID
 * @return int|false Number of rows deleted or false on failure
 */
function erp_hr_remove_holiday_companies($holiday_id) {
    global $wpdb;
    
    return $wpdb->delete(
        $wpdb->prefix . 'erp_hr_holiday_companies',
        array('holiday_id' => $holiday_id),
        array('%d')
    );
}

/**
 * Get holiday locations
 *
 * @param int $holiday_id Holiday ID
 * @return array Array of location objects
 */
function erp_hr_get_holiday_locations($holiday_id) {
    global $wpdb;
    
    return $wpdb->get_results(
        $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}erp_hr_holiday_locations WHERE holiday_id = %d",
            $holiday_id
        )
    );
}

/**
 * Get holiday companies
 *
 * @param int $holiday_id Holiday ID
 * @return array Array of company IDs
 */
function erp_hr_get_holiday_companies($holiday_id) {
    global $wpdb;
    
    return $wpdb->get_col(
        $wpdb->prepare(
            "SELECT company_id FROM {$wpdb->prefix}erp_hr_holiday_companies WHERE holiday_id = %d",
            $holiday_id
        )
    );
}

/**
 * Check if employee work location is set
 * Fix for company holding being set to 0
 *
 * @param int $employee_id Employee ID
 * @return int|false Work location ID or false if not set
 */
function erp_hr_get_employee_work_location($employee_id) {
    $work_location = get_user_meta($employee_id, '_erp_hr_work_location', true);
    
    // Fix: If work_location is 0 or empty, try to get from employee data
    if (empty($work_location) || $work_location === '0') {
        $employee = new \WeDevs\ERP\HRM\Employee($employee_id);
        $work_location = $employee->work_location;
        
        // If still empty, use the first company location as default
        if (empty($work_location) || $work_location === '0') {
            $company_locations = erp_company_get_locations();
            if (!empty($company_locations)) {
                $first_location = reset($company_locations);
                $work_location = $first_location->id;
                
                // Update the employee's work location
                update_user_meta($employee_id, '_erp_hr_work_location', $work_location);
            }
        }
    }
    
    return !empty($work_location) && $work_location !== '0' ? intval($work_location) : false;
}
