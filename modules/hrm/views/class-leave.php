<?php
/**
 * Enhanced Holiday Admin UI with location support
 * This should be integrated into the existing holiday management page
 */

/**
 * Add location fields to holiday form
 * Hook this into the holiday form rendering
 */
add_action('erp_hr_holiday_form_fields', 'erp_hr_add_holiday_location_fields', 10, 1);

function erp_hr_add_holiday_location_fields($holiday_id = 0) {
    $locations = array();
    $companies = array();
    
    if ($holiday_id) {
        $locations = erp_hr_get_holiday_locations($holiday_id);
        $companies = erp_hr_get_holiday_companies($holiday_id);
    }
    
    $company_locations = erp_company_get_locations();
    $countries = erp_get_country_list();
    ?>
    
    <div class="erp-form-group">
        <label><?php _e('Applicability', 'erp'); ?></label>
        <div class="erp-form-field">
            <select name="holiday_applicability" id="holiday-applicability" class="erp-select2">
                <option value="global"><?php _e('Global (All Locations)', 'erp'); ?></option>
                <option value="location" <?php selected(!empty($locations)); ?>>
                    <?php _e('Specific Country/State', 'erp'); ?>
                </option>
                <option value="company" <?php selected(!empty($companies)); ?>>
                    <?php _e('Specific Company Locations', 'erp'); ?>
                </option>
            </select>
            <span class="description">
                <?php _e('Select who this holiday applies to', 'erp'); ?>
            </span>
        </div>
    </div>
    
    <!-- Location-based fields -->
    <div id="holiday-location-fields" style="display: <?php echo !empty($locations) ? 'block' : 'none'; ?>;">
        <div class="erp-form-group">
            <label><?php _e('Country', 'erp'); ?></label>
            <div class="erp-form-field">
                <select name="holiday_country" id="holiday-country" class="erp-select2">
                    <option value=""><?php _e('Select Country', 'erp'); ?></option>
                    <?php foreach ($countries as $code => $country_name): ?>
                        <option value="<?php echo esc_attr($code); ?>" 
                            <?php selected(!empty($locations) && $locations[0]->country === $code); ?>>
                            <?php echo esc_html($country_name); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        
        <div class="erp-form-group">
            <label><?php _e('State/Province (Optional)', 'erp'); ?></label>
            <div class="erp-form-field">
                <input type="text" 
                       name="holiday_state" 
                       id="holiday-state" 
                       class="erp-input" 
                       value="<?php echo !empty($locations) ? esc_attr($locations[0]->state) : ''; ?>"
                       placeholder="<?php esc_attr_e('Leave empty for country-wide holiday', 'erp'); ?>">
                <span class="description">
                    <?php _e('Leave empty to apply to entire country', 'erp'); ?>
                </span>
            </div>
        </div>
    </div>
    
    <!-- Company-based fields -->
    <div id="holiday-company-fields" style="display: <?php echo !empty($companies) ? 'block' : 'none'; ?>;">
        <div class="erp-form-group">
            <label><?php _e('Company Locations', 'erp'); ?></label>
            <div class="erp-form-field">
                <select name="holiday_companies[]" 
                        id="holiday-companies" 
                        class="erp-select2" 
                        multiple="multiple" 
                        style="width: 100%;">
                    <?php foreach ($company_locations as $location): ?>
                        <option value="<?php echo esc_attr($location->id); ?>"
                            <?php selected(in_array($location->id, $companies)); ?>>
                            <?php echo esc_html($location->name); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <span class="description">
                    <?php _e('Select one or more company locations', 'erp'); ?>
                </span>
            </div>
        </div>
    </div>
    
    <script type="text/javascript">
    jQuery(document).ready(function($) {
        $('#holiday-applicability').on('change', function() {
            var value = $(this).val();
            
            $('#holiday-location-fields').hide();
            $('#holiday-company-fields').hide();
            
            if (value === 'location') {
                $('#holiday-location-fields').show();
            } else if (value === 'company') {
                $('#holiday-company-fields').show();
            }
        });
        
        // Initialize Select2
        $('.erp-select2').select2({
            placeholder: '<?php esc_attr_e('Select...', 'erp'); ?>',
            allowClear: true
        });
    });
    </script>
    
    <style>
    .erp-form-group {
        margin-bottom: 20px;
    }
    .erp-form-group label {
        display: block;
        font-weight: 600;
        margin-bottom: 5px;
    }
    .erp-form-field {
        display: block;
    }
    .erp-form-field .description {
        display: block;
        margin-top: 5px;
        font-style: italic;
        color: #666;
        font-size: 12px;
    }
    .erp-input,
    .erp-select2 {
        width: 100%;
        max-width: 400px;
    }
    </style>
    <?php
}

/**
 * Save holiday location data
 * Hook this into the holiday save action
 */
add_action('erp_hr_holiday_saved', 'erp_hr_save_holiday_location_data', 10, 2);

function erp_hr_save_holiday_location_data($holiday_id, $data) {
    if (!$holiday_id) {
        return;
    }
    
    // Remove existing locations and companies
    erp_hr_remove_holiday_locations($holiday_id);
    erp_hr_remove_holiday_companies($holiday_id);
    
    $applicability = isset($_POST['holiday_applicability']) ? sanitize_text_field($_POST['holiday_applicability']) : 'global';
    
    if ($applicability === 'location') {
        $country = isset($_POST['holiday_country']) ? sanitize_text_field($_POST['holiday_country']) : '';
        $state = isset($_POST['holiday_state']) ? sanitize_text_field($_POST['holiday_state']) : null;
        
        if (!empty($country)) {
            erp_hr_add_holiday_location($holiday_id, $country, $state);
        }
    } elseif ($applicability === 'company') {
        $companies = isset($_POST['holiday_companies']) ? (array)$_POST['holiday_companies'] : array();
        
        foreach ($companies as $company_id) {
            $company_id = intval($company_id);
            if ($company_id > 0) {
                erp_hr_add_holiday_company($holiday_id, $company_id);
            }
        }
    }
    // If 'global', we don't save any locations or companies (default behavior)
}

/**
 * Display holiday scope in list table
 * Hook this into the holiday list table columns
 */
add_filter('erp_hr_holiday_list_columns', 'erp_hr_add_holiday_scope_column', 10, 1);

function erp_hr_add_holiday_scope_column($columns) {
    $new_columns = array();
    
    foreach ($columns as $key => $value) {
        $new_columns[$key] = $value;
        
        // Add scope column after title
        if ($key === 'title') {
            $new_columns['scope'] = __('Applies To', 'erp');
        }
    }
    
    return $new_columns;
}

add_filter('erp_hr_holiday_list_column_scope', 'erp_hr_display_holiday_scope', 10, 2);

function erp_hr_display_holiday_scope($value, $holiday) {
    $locations = erp_hr_get_holiday_locations($holiday->id);
    $companies = erp_hr_get_holiday_companies($holiday->id);
    
    if (!empty($locations)) {
        $location = $locations[0];
        $countries = erp_get_country_list();
        $country_name = isset($countries[$location->country]) ? $countries[$location->country] : $location->country;
        
        if (!empty($location->state)) {
            return sprintf('%s - %s', esc_html($country_name), esc_html($location->state));
        } else {
            return esc_html($country_name);
        }
    } elseif (!empty($companies)) {
        $company_locations = erp_company_get_locations();
        $names = array();
        
        foreach ($company_locations as $loc) {
            if (in_array($loc->id, $companies)) {
                $names[] = $loc->name;
            }
        }
        
        if (count($names) > 2) {
            return sprintf(
                '%s +%d %s',
                esc_html($names[0]),
                count($names) - 1,
                _n('more', 'more', count($names) - 1, 'erp')
            );
        }
        
        return esc_html(implode(', ', $names));
    }
    
    return '<em>' . __('All Locations', 'erp') . '</em>';
}

/**
 * AJAX handler to get states for a country
 */
add_action('wp_ajax_erp_hr_get_states_for_holiday', 'erp_hr_ajax_get_states_for_holiday');

function erp_hr_ajax_get_states_for_holiday() {
    check_ajax_referer('wp-erp-hr-nonce');
    
    $country = isset($_POST['country']) ? sanitize_text_field($_POST['country']) : '';
    
    if (empty($country)) {
        wp_send_json_error(array('message' => __('Invalid country', 'erp')));
    }
    
    $states = erp_get_states($country);
    
    wp_send_json_success(array('states' => $states));
}
