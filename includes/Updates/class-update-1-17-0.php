<?php
/**
 * Installation and Update Handler for Holiday Location Feature
 * Add this to your installer class or create a separate update file
 */

/**
 * Create holiday location tables
 * Call this from your main installer or activation hook
 */
function erp_hr_create_holiday_location_tables() {
    global $wpdb;
    
    $charset_collate = $wpdb->get_charset_collate();
    $table_prefix = $wpdb->prefix;
    
    $sql = array();
    
    // Holiday locations table
    $sql[] = "CREATE TABLE IF NOT EXISTS `{$table_prefix}erp_hr_holiday_locations` (
        `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        `holiday_id` bigint(20) unsigned NOT NULL,
        `country` varchar(2) DEFAULT NULL COMMENT 'ISO 3166-1 alpha-2 country code',
        `state` varchar(100) DEFAULT NULL COMMENT 'State/Province name or code',
        `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `holiday_id` (`holiday_id`),
        KEY `country_state` (`country`, `state`)
    ) $charset_collate;";
    
    // Holiday companies table
    $sql[] = "CREATE TABLE IF NOT EXISTS `{$table_prefix}erp_hr_holiday_companies` (
        `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        `holiday_id` bigint(20) unsigned NOT NULL,
        `company_id` bigint(20) unsigned NOT NULL COMMENT 'From erp_company_locations table',
        `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `holiday_id` (`holiday_id`),
        KEY `company_id` (`company_id`),
        UNIQUE KEY `holiday_company` (`holiday_id`, `company_id`)
    ) $charset_collate;";
    
    // Add index to existing holiday table for better performance
    $sql[] = "ALTER TABLE `{$table_prefix}erp_hr_holiday` 
              ADD INDEX IF NOT EXISTS `date_range` (`start`, `end`);";
    
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    
    foreach ($sql as $query) {
        dbDelta($query);
    }
    
    // Set flag to indicate tables are created
    update_option('erp_hr_holiday_locations_version', '1.0.0');
}

/**
 * Upgrade existing data - fix work location issue
 * This function will set work location for all employees who have 0 or null
 */
function erp_hr_fix_employee_work_locations() {
    global $wpdb;
    
    // Get first company location
    $company_locations = erp_company_get_locations();
    if (empty($company_locations)) {
        return; // No company locations to assign
    }
    
    $first_location = reset($company_locations);
    $default_location = $first_location->id;
    
    // Find all employees with missing or zero work location
    $employees = $wpdb->get_col(
        "SELECT DISTINCT p.id 
         FROM {$wpdb->prefix}erp_peoples p
         INNER JOIN {$wpdb->prefix}erp_hr_employees e ON p.id = e.user_id
         LEFT JOIN {$wpdb->prefix}usermeta um ON p.user_id = um.user_id AND um.meta_key = '_erp_hr_work_location'
         WHERE p.type = 'employee' 
         AND (um.meta_value IS NULL OR um.meta_value = '0' OR um.meta_value = '')"
    );
    
    $updated = 0;
    foreach ($employees as $employee_id) {
        update_user_meta($employee_id, '_erp_hr_work_location', $default_location);
        $updated++;
    }
    
    if ($updated > 0) {
        error_log(sprintf('ERP: Fixed work location for %d employees', $updated));
    }
    
    // Set flag to indicate this update has run
    update_option('erp_hr_work_locations_fixed', '1.0.0');
}

/**
 * Main update function to run all necessary updates
 * Call this from your plugin update routine
 */
function erp_hr_run_holiday_location_updates() {
    // Check if we need to create tables
    if (!get_option('erp_hr_holiday_locations_version')) {
        erp_hr_create_holiday_location_tables();
    }
    
    // Check if we need to fix work locations
    if (!get_option('erp_hr_work_locations_fixed')) {
        erp_hr_fix_employee_work_locations();
    }
}

/**
 * Hook into plugin activation
 */
register_activation_hook(__FILE__, 'erp_hr_run_holiday_location_updates');

/**
 * Hook into admin init to run updates for existing installations
 */
add_action('admin_init', 'erp_hr_check_holiday_updates', 5);

function erp_hr_check_holiday_updates() {
    // Only run for admins
    if (!current_user_can('manage_options')) {
        return;
    }
    
    // Check if updates are needed
    erp_hr_run_holiday_location_updates();
}

/**
 * Add updater to main ERP updates queue
 * Hook this into your existing update system
 */
add_filter('erp_updates', 'erp_hr_add_holiday_location_update', 10, 1);

function erp_hr_add_holiday_location_update($updates) {
    // Add this update to the queue if not already done
    if (!get_option('erp_hr_holiday_locations_version')) {
        $updates[] = array(
            'version' => '1.17.0',
            'callback' => 'erp_hr_run_holiday_location_updates',
            'description' => __('Install holiday location tables and fix work location issues', 'erp')
        );
    }
    
    return $updates;
}

/**
 * Deactivation cleanup (optional)
 * Only use this if you want to remove data on plugin deactivation
 */
function erp_hr_holiday_location_deactivate() {
    // Uncomment if you want to remove tables on deactivation
    // global $wpdb;
    // $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}erp_hr_holiday_locations");
    // $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}erp_hr_holiday_companies");
    // delete_option('erp_hr_holiday_locations_version');
    // delete_option('erp_hr_work_locations_fixed');
}

// register_deactivation_hook(__FILE__, 'erp_hr_holiday_location_deactivate');

/**
 * Admin notice for successful update
 */
add_action('admin_notices', 'erp_hr_holiday_location_update_notice');

function erp_hr_holiday_location_update_notice() {
    $updated = get_transient('erp_hr_holiday_location_updated');
    
    if ($updated) {
        ?>
        <div class="notice notice-success is-dismissible">
            <p>
                <strong><?php _e('WP ERP Update:', 'erp'); ?></strong>
                <?php _e('Holiday location tables have been created and employee work locations have been fixed.', 'erp'); ?>
            </p>
        </div>
        <?php
        delete_transient('erp_hr_holiday_location_updated');
    }
}

/**
 * Set update notice flag
 */
add_action('erp_hr_run_holiday_location_updates', 'erp_hr_set_update_notice');

function erp_hr_set_update_notice() {
    set_transient('erp_hr_holiday_location_updated', true, DAY_IN_SECONDS);
}
