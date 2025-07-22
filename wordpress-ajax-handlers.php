<?php
/**
 * WordPress AJAX Handlers for Health Tracker Mobile App
 * Add this code to your theme's functions.php file or create a custom plugin
 */

// AJAX Handler: Store health tracker data
add_action('wp_ajax_health_tracker_store', 'handle_health_tracker_store');
add_action('wp_ajax_nopriv_health_tracker_store', 'handle_health_tracker_store');

function handle_health_tracker_store() {
    global $wpdb;
    
    // Verify required fields
    if (empty($_POST['form_type']) || empty($_POST['email'])) {
        wp_send_json_error('Missing required fields: form_type and email');
        return;
    }
    
    $form_type = sanitize_text_field($_POST['form_type']);
    $email = sanitize_email($_POST['email']);
    $name = sanitize_text_field($_POST['name'] ?? '');
    $age = intval($_POST['age'] ?? 0);
    $gender = sanitize_text_field($_POST['gender'] ?? '');
    $form_data = $_POST['form_data'] ?? '{}';
    $calculated_metrics = $_POST['calculated_metrics'] ?? '{}';
    
    // Create email hash for privacy
    $email_hash = hash('sha256', $email);
    $name_hash = hash('sha256', $name);
    
    // Prepare data for database
    $submission_data = array(
        'user_hash' => $email_hash,
        'email_hash' => $email_hash,
        'name_hash' => $name_hash,
        'form_type' => $form_type,
        'age' => $age,
        'gender' => $gender,
        'form_data_json' => $form_data,
        'calculated_metrics_json' => $calculated_metrics,
        'submission_date' => current_time('mysql'),
        'language_code' => 'en',
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
        'ip_hash' => hash('sha256', $_SERVER['REMOTE_ADDR'] ?? ''),
        'created_at' => current_time('mysql'),
        'updated_at' => current_time('mysql')
    );
    
    // Parse calculated metrics to extract individual values
    $metrics = json_decode($calculated_metrics, true);
    if ($metrics) {
        if (isset($metrics['bmi'])) $submission_data['bmi'] = floatval($metrics['bmi']);
        if (isset($metrics['whr'])) $submission_data['whr'] = floatval($metrics['whr']);
        if (isset($metrics['biological_age'])) $submission_data['biological_age'] = floatval($metrics['biological_age']);
        if (isset($metrics['age_shift'])) $submission_data['age_shift'] = floatval($metrics['age_shift']);
        if (isset($metrics['lifestyle_score'])) $submission_data['lifestyle_score'] = floatval($metrics['lifestyle_score']);
        if (isset($metrics['overall_health_score'])) $submission_data['overall_health_score'] = floatval($metrics['overall_health_score']);
    }
    
    // Insert into database
    $table_name = $wpdb->prefix . 'health_tracker_submissions';
    $result = $wpdb->insert($table_name, $submission_data);
    
    if ($result !== false) {
        $submission_id = $wpdb->insert_id;
        
        // Log successful submission
        error_log("Health Tracker: Successfully stored {$form_type} submission for user {$email_hash}");
        
        wp_send_json_success(array(
            'message' => 'Data stored successfully',
            'submission_id' => $submission_id,
            'form_type' => $form_type
        ));
    } else {
        error_log("Health Tracker: Database error - " . $wpdb->last_error);
        wp_send_json_error('Database error: ' . $wpdb->last_error);
    }
}

// AJAX Handler: Get health tracker data
add_action('wp_ajax_health_tracker_get_data', 'handle_health_tracker_get_data');
add_action('wp_ajax_nopriv_health_tracker_get_data', 'handle_health_tracker_get_data');

function handle_health_tracker_get_data() {
    global $wpdb;
    
    if (empty($_POST['email'])) {
        wp_send_json_error('Email is required');
        return;
    }
    
    $email = sanitize_email($_POST['email']);
    $email_hash = hash('sha256', $email);
    
    // Get user submissions
    $table_name = $wpdb->prefix . 'health_tracker_submissions';
    $submissions = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$table_name} WHERE email_hash = %s ORDER BY submission_date DESC",
        $email_hash
    ));
    
    if ($submissions) {
        // Format data for mobile app
        $formatted_data = array();
        foreach ($submissions as $submission) {
            $formatted_data[] = array(
                'id' => $submission->id,
                'form_type' => $submission->form_type,
                'age' => $submission->age,
                'gender' => $submission->gender,
                'bmi' => $submission->bmi,
                'whr' => $submission->whr,
                'biological_age' => $submission->biological_age,
                'age_shift' => $submission->age_shift,
                'lifestyle_score' => $submission->lifestyle_score,
                'overall_health_score' => $submission->overall_health_score,
                'submission_date' => $submission->submission_date,
                'form_data_json' => $submission->form_data_json,
                'calculated_metrics_json' => $submission->calculated_metrics_json
            );
        }
        
        wp_send_json_success($formatted_data);
    } else {
        wp_send_json_success(array()); // Return empty array if no data
    }
}

// Create database tables if they don't exist
add_action('after_setup_theme', 'create_health_tracker_tables');

function create_health_tracker_tables() {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'health_tracker_submissions';
    
    $charset_collate = $wpdb->get_charset_collate();
    
    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        user_hash varchar(64),
        session_token varchar(255),
        form_type varchar(20) NOT NULL,
        email_hash varchar(64),
        name_hash varchar(64),
        age int(3),
        gender varchar(10),
        biological_age decimal(5,2),
        age_shift decimal(5,2),
        bmi decimal(5,2),
        whr decimal(4,3),
        lifestyle_score decimal(3,2),
        overall_health_score decimal(3,2),
        form_data_json longtext,
        calculated_metrics_json text,
        submission_date datetime,
        language_code varchar(5),
        user_agent text,
        ip_hash varchar(64),
        sync_status int(1) DEFAULT 0,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY email_hash (email_hash),
        KEY form_type (form_type),
        KEY submission_date (submission_date)
    ) $charset_collate;";
    
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}

// Optional: Add admin menu for viewing health tracker data
add_action('admin_menu', 'health_tracker_admin_menu');

function health_tracker_admin_menu() {
    add_menu_page(
        'Health Tracker',
        'Health Tracker',
        'manage_options',
        'health-tracker',
        'health_tracker_admin_page',
        'dashicons-heart',
        30
    );
}

function health_tracker_admin_page() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'health_tracker_submissions';
    $submissions = $wpdb->get_results("SELECT * FROM {$table_name} ORDER BY submission_date DESC LIMIT 50");
    
    echo '<div class="wrap">';
    echo '<h1>Health Tracker Submissions</h1>';
    echo '<table class="wp-list-table widefat fixed striped">';
    echo '<thead><tr><th>ID</th><th>Form Type</th><th>Age</th><th>Gender</th><th>BMI</th><th>Date</th></tr></thead>';
    echo '<tbody>';
    
    foreach ($submissions as $submission) {
        echo '<tr>';
        echo '<td>' . $submission->id . '</td>';
        echo '<td>' . $submission->form_type . '</td>';
        echo '<td>' . $submission->age . '</td>';
        echo '<td>' . $submission->gender . '</td>';
        echo '<td>' . number_format($submission->bmi, 1) . '</td>';
        echo '<td>' . $submission->submission_date . '</td>';
        echo '</tr>';
    }
    
    echo '</tbody></table>';
    echo '</div>';
}

?>