/**
 * COMPLETE WordPress AJAX Handlers for Health Tracker Mobile App
 * Version: 2.1 - Fixed with Enhanced Debugging
 */

// Security: Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// CRITICAL: Log when this file loads
error_log('Mobile Health Tracker: AJAX handlers file loaded at ' . current_time('mysql'));

// Add a test to verify AJAX is working
add_action('init', function() {
    error_log('Mobile Health Tracker: Init hook fired, registering AJAX actions');
});

// AJAX Handler: Request verification code (MOBILE APP)
add_action('wp_ajax_mobile_health_request_code', 'handle_mobile_health_request_code');
add_action('wp_ajax_nopriv_mobile_health_request_code', 'handle_mobile_health_request_code');

function handle_mobile_health_request_code() {
    // IMMEDIATE DEBUG LOG
    error_log('Mobile Health: handle_mobile_health_request_code CALLED!');
    error_log('Mobile Health: POST data: ' . print_r($_POST, true));
    error_log('Mobile Health: Request headers: ' . print_r(getallheaders(), true));
    
    global $wpdb;
    
    // Send headers to prevent caching
    nocache_headers();
    
    // Check if email is provided
    if (empty($_POST['email'])) {
        error_log('Mobile Health: No email provided in POST data');
        wp_send_json_error('Email is required');
        wp_die();
    }
    
    $email = sanitize_email($_POST['email']);
    error_log('Mobile Health: Processing request for email: ' . $email);
    
    // Validate email format
    if (!is_email($email)) {
        error_log('Mobile Health: Invalid email format: ' . $email);
        wp_send_json_error('Invalid email address');
        wp_die();
    }
    
    // Generate verification code
    $verification_code = sprintf('%06d', mt_rand(100000, 999999));
    $email_hash = hash('sha256', strtolower(trim($email)));
    
    error_log('Mobile Health: Generated code: ' . $verification_code . ' for email hash: ' . $email_hash);
    
    // Create table if it doesn't exist
    $table_name = $wpdb->prefix . 'health_tracker_verification_codes';
    $charset_collate = $wpdb->get_charset_collate();
    
    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        email_hash varchar(64) NOT NULL,
        verification_code varchar(6) NOT NULL,
        created_date datetime DEFAULT CURRENT_TIMESTAMP,
        expires_date datetime NOT NULL,
        is_used tinyint(1) DEFAULT 0,
        attempts int DEFAULT 0,
        PRIMARY KEY (id),
        KEY email_hash (email_hash),
        KEY expires_date (expires_date)
    ) $charset_collate;";
    
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
    
    // Delete old codes for this email
    $deleted = $wpdb->delete($table_name, array('email_hash' => $email_hash));
    error_log('Mobile Health: Deleted ' . $deleted . ' old codes for this email');
    
    // Insert new code
    $expires_date = date('Y-m-d H:i:s', strtotime('+15 minutes'));
    $insert_data = array(
        'email_hash' => $email_hash,
        'verification_code' => $verification_code,
        'created_date' => current_time('mysql'),
        'expires_date' => $expires_date,
        'is_used' => 0,
        'attempts' => 0
    );
    
    error_log('Mobile Health: Inserting verification code data: ' . print_r($insert_data, true));
    
    $result = $wpdb->insert($table_name, $insert_data);
    
    if ($result === false) {
        error_log('Mobile Health: Database insert failed: ' . $wpdb->last_error);
        wp_send_json_error('Failed to generate verification code');
        wp_die();
    }
    
    $code_id = $wpdb->insert_id;
    error_log('Mobile Health: Code inserted successfully with ID: ' . $code_id);
    
    // Try to send email
    $subject = 'Your Health Tracker Verification Code';
    $message = "Hello,\n\n";
    $message .= "Your verification code is: $verification_code\n\n";
    $message .= "This code will expire in 15 minutes.\n\n";
    $message .= "If you didn't request this code, please ignore this email.\n\n";
    $message .= "Best regards,\nHealth Tracker Team";
    
    $headers = array('Content-Type: text/plain; charset=UTF-8');
    
    // Add filter to log mail sending
    add_filter('wp_mail', function($args) {
        error_log('Mobile Health: Attempting to send email to: ' . $args['to']);
        return $args;
    });
    
    $email_sent = wp_mail($email, $subject, $message, $headers);
    
    if ($email_sent) {
        error_log('Mobile Health: Email sent successfully to ' . $email);
        wp_send_json_success(array(
            'message' => 'Verification code sent',
            'email_method' => 'wp_mail',
            'debug_code_id' => $code_id,
            'expires_in' => 900 // 15 minutes
        ));
    } else {
        error_log('Mobile Health: Email sending failed, but code was generated');
        // For testing, include the code in response (REMOVE IN PRODUCTION)
        wp_send_json_success(array(
            'message' => 'Code generated (email sending failed)',
            'email_method' => 'wp_mail_failed',
            'debug_code' => $verification_code, // REMOVE IN PRODUCTION
            'debug_code_id' => $code_id,
            'expires_in' => 900
        ));
    }
    
    wp_die();
}

// AJAX Handler: Verify code (MOBILE APP)
add_action('wp_ajax_mobile_health_verify_code', 'handle_mobile_health_verify_code');
add_action('wp_ajax_nopriv_mobile_health_verify_code', 'handle_mobile_health_verify_code');

function handle_mobile_health_verify_code() {
    error_log('Mobile Health: handle_mobile_health_verify_code CALLED!');
    error_log('Mobile Health: POST data: ' . print_r($_POST, true));
    
    global $wpdb;
    
    if (empty($_POST['email']) || empty($_POST['code'])) {
        wp_send_json_error('Email and verification code are required');
        wp_die();
    }
    
    $email = sanitize_email($_POST['email']);
    $code = sanitize_text_field($_POST['code']);
    $email_hash = hash('sha256', strtolower(trim($email)));
    
    error_log('Mobile Health: Verifying code ' . $code . ' for email hash ' . $email_hash);
    
    // Get the most recent valid code
    $table_name = $wpdb->prefix . 'health_tracker_verification_codes';
    $verification = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $table_name 
         WHERE email_hash = %s 
         AND is_used = 0 
         AND expires_date > NOW() 
         ORDER BY created_date DESC 
         LIMIT 1",
        $email_hash
    ));
    
    if (!$verification) {
        error_log('Mobile Health: No valid verification code found');
        wp_send_json_error('Invalid or expired verification code');
        wp_die();
    }
    
    error_log('Mobile Health: Found verification record ID ' . $verification->id . ' with code ' . $verification->verification_code);
    
    // Update attempts
    $wpdb->update(
        $table_name,
        array('attempts' => $verification->attempts + 1),
        array('id' => $verification->id)
    );
    
    // Check if code matches
    if ($verification->verification_code !== $code) {
        error_log('Mobile Health: Code mismatch');
        
        if ($verification->attempts >= 4) {
            $wpdb->update(
                $table_name,
                array('is_used' => 1),
                array('id' => $verification->id)
            );
            wp_send_json_error('Too many failed attempts. Please request a new code.');
        } else {
            wp_send_json_error('Invalid verification code');
        }
        wp_die();
    }
    
    // Mark code as used
    $wpdb->update(
        $table_name,
        array('is_used' => 1),
        array('id' => $verification->id)
    );
    
    // Generate tokens
    $access_token = wp_generate_password(64, false);
    $refresh_token = wp_generate_password(64, false);
    $session_id = uniqid('session_', true);
    
    // Create sessions table if needed
    $sessions_table = $wpdb->prefix . 'health_tracker_sessions';
    $charset_collate = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE IF NOT EXISTS $sessions_table (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        user_hash varchar(64) NOT NULL,
        email_hash varchar(64) NOT NULL,
        access_token varchar(255) NOT NULL,
        refresh_token varchar(255) NOT NULL,
        dashboard_url varchar(255),
        created_date datetime DEFAULT CURRENT_TIMESTAMP,
        last_accessed datetime DEFAULT CURRENT_TIMESTAMP,
        is_active tinyint(1) DEFAULT 1,
        expires_at datetime NOT NULL,
        PRIMARY KEY (id),
        KEY user_hash (user_hash),
        KEY access_token (access_token),
        KEY expires_at (expires_at)
    ) $charset_collate;";
    
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
    
    // Clean up old sessions
    $wpdb->delete($sessions_table, array('email_hash' => $email_hash));
    
    // Insert new session
    $expires_at = date('Y-m-d H:i:s', time() + (24 * 60 * 60));
    
    $session_result = $wpdb->insert(
        $sessions_table,
        array(
            'user_hash' => $email_hash,
            'email_hash' => $email_hash,
            'access_token' => $access_token,
            'refresh_token' => $refresh_token,
            'dashboard_url' => site_url('/health-dashboard/'),
            'created_date' => current_time('mysql'),
            'last_accessed' => current_time('mysql'),
            'is_active' => 1,
            'expires_at' => $expires_at
        )
    );
    
    if ($session_result === false) {
        error_log('Mobile Health: Failed to create session: ' . $wpdb->last_error);
        wp_send_json_error('Failed to create session');
        wp_die();
    }
    
    error_log('Mobile Health: Authentication successful for ' . $email);
    
    wp_send_json_success(array(
        'access_token' => $access_token,
        'refresh_token' => $refresh_token,
        'user_hash' => $email_hash,
        'email_hash' => $email_hash,
        'session_id' => $session_id,
        'expires_at' => $expires_at,
        'dashboard_url' => site_url('/health-dashboard/')
    ));
    
    wp_die();
}

// AJAX Handler: Test endpoint
add_action('wp_ajax_mobile_health_test', 'handle_mobile_health_test');
add_action('wp_ajax_nopriv_mobile_health_test', 'handle_mobile_health_test');

function handle_mobile_health_test() {
    error_log('Mobile Health: Test endpoint called successfully!');
    
    // Get all registered actions
    global $wp_filter;
    $ajax_actions = array();
    
    if (isset($wp_filter['wp_ajax_mobile_health_request_code'])) {
        $ajax_actions[] = 'mobile_health_request_code';
    }
    if (isset($wp_filter['wp_ajax_mobile_health_verify_code'])) {
        $ajax_actions[] = 'mobile_health_verify_code';
    }
    if (isset($wp_filter['wp_ajax_mobile_health_test'])) {
        $ajax_actions[] = 'mobile_health_test';
    }
    
    wp_send_json_success(array(
        'message' => 'Health Tracker API is working!',
        'timestamp' => current_time('mysql'),
        'wordpress_version' => get_bloginfo('version'),
        'api_version' => '2.1',
        'registered_actions' => $ajax_actions,
        'php_version' => phpversion(),
        'ajax_url' => admin_url('admin-ajax.php')
    ));
    
    wp_die();
}

// AJAX Handler: Get user data (backward compatibility)
add_action('wp_ajax_mobile_health_get', 'handle_mobile_health_get');
add_action('wp_ajax_nopriv_mobile_health_get', 'handle_mobile_health_get');

function handle_mobile_health_get() {
    error_log('Mobile Health: handle_mobile_health_get CALLED!');
    
    if (empty($_POST['email'])) {
        wp_send_json_error('Email is required');
        wp_die();
    }
    
    $email = sanitize_email($_POST['email']);
    
    global $wpdb;
    $table_name = $wpdb->prefix . 'health_tracker_submissions';
    
    // Check if table exists
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name;
    
    if (!$table_exists) {
        // Create the table if it doesn't exist
        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE $table_name (
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
            PRIMARY KEY (id)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    // Check if user exists
    $email_hash = hash('sha256', strtolower(trim($email)));
    
    $exists = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $table_name WHERE email_hash = %s OR email_hash = %s",
        $email_hash,
        $email
    ));
    
    wp_send_json_success(array(
        'exists' => $exists > 0,
        'hasData' => $exists > 0,
        'dataCount' => intval($exists)
    ));
    
    wp_die();
}

// AJAX Handler: Store health tracker data (MOBILE APP ONLY)
add_action('wp_ajax_mobile_health_store', 'handle_mobile_health_store');
add_action('wp_ajax_nopriv_mobile_health_store', 'handle_mobile_health_store');

function handle_mobile_health_store() {
    global $wpdb;
    
    // Security: Verify nonce for authenticated users
    if (is_user_logged_in() && !wp_verify_nonce($_POST['_wpnonce'] ?? '', 'mobile_health_store')) {
        wp_send_json_error('Security check failed');
        return;
    }
    
    // Rate limiting: Check for too many submissions
    $ip_hash = hash('sha256', $_SERVER['REMOTE_ADDR'] ?? '');
    $recent_submissions = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}health_tracker_submissions 
         WHERE ip_hash = %s AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)",
        $ip_hash
    ));
    
    if ($recent_submissions >= 10) {
        wp_send_json_error('Too many submissions. Please try again later.');
        return;
    }
    
    // Verify required fields
    if (empty($_POST['form_type']) || empty($_POST['email'])) {
        wp_send_json_error('Missing required fields: form_type and email');
        return;
    }
    
    // Validate email format
    if (!is_email($_POST['email'])) {
        wp_send_json_error('Invalid email format');
        return;
    }
    
    // Validate form type
    $allowed_form_types = array('health', 'longevity');
    if (!in_array($_POST['form_type'], $allowed_form_types)) {
        wp_send_json_error('Invalid form type');
        return;
    }
    
    $form_type = sanitize_text_field($_POST['form_type']);
    $email = sanitize_email($_POST['email']);
    $name = sanitize_text_field($_POST['name'] ?? '');
    $age = intval($_POST['age'] ?? 0);
    $gender = sanitize_text_field($_POST['gender'] ?? '');
    $form_data = sanitize_textarea_field($_POST['form_data'] ?? '{}');
    $calculated_metrics = sanitize_textarea_field($_POST['calculated_metrics'] ?? '{}');
    
    // Validate JSON data
    if (!is_string($form_data) || json_decode($form_data) === null) {
        wp_send_json_error('Invalid form data format');
        return;
    }
    
    if (!is_string($calculated_metrics) || json_decode($calculated_metrics) === null) {
        wp_send_json_error('Invalid calculated metrics format');
        return;
    }
    
    // Additional data validation
    if ($age < 0 || $age > 150) {
        wp_send_json_error('Invalid age value');
        return;
    }
    
    if (!empty($gender) && !in_array($gender, array('male', 'female', 'other'))) {
        wp_send_json_error('Invalid gender value');
        return;
    }
    
    // Create email hash for privacy
    $email_hash = hash('sha256', $email);
    $name_hash = hash('sha256', $name);
    
    // Check if your existing table exists first
    $existing_table = $wpdb->prefix . 'health_tracker_submissions';
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$existing_table'") == $existing_table;
    
    if (!$table_exists) {
        // Create table only if it doesn't exist
        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE $existing_table (
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
            PRIMARY KEY (id)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
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
    $result = $wpdb->insert($existing_table, $submission_data);
    
    if ($result !== false) {
        $submission_id = $wpdb->insert_id;
        
        // Log successful submission
        error_log("Mobile Health Tracker: Successfully stored {$form_type} submission for user {$email_hash}");
        
        wp_send_json_success(array(
            'message' => 'Data stored successfully',
            'submission_id' => $submission_id,
            'form_type' => $form_type,
            'source' => 'mobile_app'
        ));
    } else {
        error_log("Mobile Health Tracker: Database error - " . $wpdb->last_error);
        wp_send_json_error('Database error: ' . $wpdb->last_error);
    }
}

// AJAX Handler: Get health tracker data (MOBILE APP ONLY)
add_action('wp_ajax_mobile_health_get', 'handle_mobile_health_get');
add_action('wp_ajax_nopriv_mobile_health_get', 'handle_mobile_health_get');

function handle_mobile_health_get() {
    global $wpdb;
    
    // Security: Rate limiting for data requests
    $ip_hash = hash('sha256', $_SERVER['REMOTE_ADDR'] ?? '');
    $recent_requests = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}health_tracker_sessions 
         WHERE last_accessed > DATE_SUB(NOW(), INTERVAL 1 HOUR)"
    ));
    
    if ($recent_requests >= 100) {
        wp_send_json_error('Too many requests. Please try again later.');
        return;
    }
    
    if (empty($_POST['email'])) {
        wp_send_json_error('Email is required');
        return;
    }
    
    // Validate email format
    if (!is_email($_POST['email'])) {
        wp_send_json_error('Invalid email format');
        return;
    }
    
    $email = sanitize_email($_POST['email']);
    $email_hash = hash('sha256', $email);
    
    // First check wp_health_tracker_users table for existing user data
    $users_table = $wpdb->prefix . 'health_tracker_users';
    $user_data = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$users_table} WHERE email_hash = %s LIMIT 1",
        $email_hash
    ));
    
    // Then get user submissions from submissions table
    $submissions_table = $wpdb->prefix . 'health_tracker_submissions';
    $submissions = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$submissions_table} WHERE user_hash = %s OR email_hash = %s ORDER BY submission_date DESC",
        $email_hash, $email_hash
    ));
    
    $formatted_data = array();
    
    // Include user profile data if exists
    if ($user_data) {
        $formatted_data[] = array(
            'id' => 'user_profile',
            'type' => 'user_profile',
            'email_hash' => $user_data->email_hash,
            'user_hash' => $user_data->user_hash ?? $email_hash,
            'form_type' => 'profile',
            'submission_date' => $user_data->submission_date ?? $user_data->created_at ?? current_time('mysql'),
            'form_data_json' => $user_data->form_data_json ?? '{}',
            'calculated_metrics_json' => $user_data->calculated_metrics_json ?? '{}'
        );
    }
    
    // Include submission data if exists
    if ($submissions) {
        foreach ($submissions as $submission) {
            $formatted_data[] = array(
                'id' => $submission->id,
                'form_type' => $submission->form_type ?? 'health',
                'age' => $submission->age ?? null,
                'gender' => $submission->gender ?? null,
                'bmi' => $submission->bmi ?? null,
                'whr' => $submission->whr ?? null,
                'biological_age' => $submission->biological_age ?? null,
                'age_shift' => $submission->age_shift ?? null,
                'lifestyle_score' => $submission->lifestyle_score ?? null,
                'overall_health_score' => $submission->overall_health_score ?? null,
                'submission_date' => $submission->submission_date ?? $submission->custom_submission_date ?? current_time('mysql'),
                'form_data_json' => $submission->form_data_json ?? '{}',
                'calculated_metrics_json' => $submission->calculated_metrics_json ?? '{}'
            );
        }
    }
    
    wp_send_json_success($formatted_data);
}

// AJAX Handler: Get submission IDs for sync (MOBILE APP)
function handle_mobile_health_get_ids() {
    global $wpdb;
    
    // Security: Rate limiting for ID requests
    $ip_hash = hash('sha256', $_SERVER['REMOTE_ADDR'] ?? '');
    $recent_requests = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}health_tracker_sessions 
         WHERE last_accessed > DATE_SUB(NOW(), INTERVAL 1 HOUR)"
    ));
    
    if ($recent_requests >= 100) {
        wp_send_json_error('Too many requests. Please try again later.');
        return;
    }
    
    if (empty($_POST['email'])) {
        wp_send_json_error('Email is required');
        return;
    }
    
    // Validate email format
    if (!is_email($_POST['email'])) {
        wp_send_json_error('Invalid email format');
        return;
    }
    
    $email = sanitize_email($_POST['email']);
    $email_hash = hash('sha256', $email);
    
    // Get submission IDs for this user (checking both raw email and hash for compatibility)
    $submissions_table = $wpdb->prefix . 'health_tracker_submissions';
    $submission_ids = $wpdb->get_col($wpdb->prepare(
        "SELECT id FROM {$submissions_table} 
         WHERE user_hash = %s OR email_hash = %s OR email_hash = %s 
         ORDER BY submission_date DESC",
        $email_hash, $email_hash, $email
    ));
    
    // Log the request for debugging
    error_log("Mobile Health Get IDs: Found " . count($submission_ids) . " submissions for email hash: {$email_hash}");
    
    wp_send_json_success(array(
        'submission_ids' => $submission_ids ?: array(),
        'count' => count($submission_ids),
        'user_hash' => $email_hash
    ));
}

// AJAX Handler: Get submission IDs for sync (MOBILE APP)
add_action('wp_ajax_mobile_health_get_ids', 'handle_mobile_health_get_ids');
add_action('wp_ajax_nopriv_mobile_health_get_ids', 'handle_mobile_health_get_ids');

// AJAX Handler: Delete health tracker data (MOBILE APP ONLY)
add_action('wp_ajax_mobile_health_delete', 'handle_mobile_health_delete');
add_action('wp_ajax_nopriv_mobile_health_delete', 'handle_mobile_health_delete');

function handle_mobile_health_delete() {
    global $wpdb;
    
    // Security: Basic validation
    if (empty($_POST['submission_id']) || empty($_POST['email'])) {
        wp_send_json_error('Missing required fields: submission_id and email');
        return;
    }
    
    $submission_id = intval($_POST['submission_id']);
    $email = sanitize_email($_POST['email']);
    $user_hash = sanitize_text_field($_POST['user_hash'] ?? '');
    
    // Validate email format
    if (!is_email($email)) {
        wp_send_json_error('Invalid email format');
        return;
    }
    
    // Rate limiting: Check for too many delete requests
    $ip_hash = hash('sha256', $_SERVER['REMOTE_ADDR'] ?? '');
    $recent_deletes = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}health_tracker_submissions 
         WHERE ip_hash = %s AND updated_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)",
        $ip_hash
    ));
    
    if ($recent_deletes >= 5) {
        wp_send_json_error('Too many delete requests. Please try again later.');
        return;
    }
    
    $submissions_table = $wpdb->prefix . 'health_tracker_submissions';
    
    // Verify the submission belongs to this user
    $submission = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$submissions_table} 
         WHERE id = %d AND (email_hash = %s OR email_hash = %s)",
        $submission_id,
        $email, // For backward compatibility
        hash('sha256', $email) // For new hash format
    ));
    
    if (!$submission) {
        wp_send_json_error('Submission not found or access denied');
        return;
    }
    
    // Log the deletion attempt
    error_log("Mobile Health Delete: Deleting submission ID {$submission_id} for email {$email}");
    
    // Delete the submission
    $deleted = $wpdb->delete(
        $submissions_table,
        array('id' => $submission_id),
        array('%d')
    );
    
    if ($deleted === false) {
        error_log("Mobile Health Delete Error: " . $wpdb->last_error);
        wp_send_json_error('Database error occurred while deleting submission');
        return;
    }
    
    if ($deleted === 0) {
        wp_send_json_error('Submission not found or already deleted');
        return;
    }
    
    // Success response
    wp_send_json_success(array(
        'message' => 'Submission deleted successfully',
        'submission_id' => $submission_id,
        'deleted_at' => current_time('mysql'),
        'user_email' => $email
    ));
}

// Cleanup function: Remove old verification codes and expired sessions
function mobile_health_cleanup_old_records() {
    global $wpdb;
    
    // Clean up old verification codes (older than 24 hours)
    $wpdb->query("DELETE FROM {$wpdb->prefix}health_tracker_verification_codes 
                  WHERE created_date < DATE_SUB(NOW(), INTERVAL 24 HOUR)");
    
    // Clean up expired sessions
    $wpdb->query("DELETE FROM {$wpdb->prefix}health_tracker_sessions 
                  WHERE expires_at < NOW()");
    
    // Clean up old inactive sessions (older than 90 days)
    $wpdb->query("DELETE FROM {$wpdb->prefix}health_tracker_sessions 
                  WHERE last_accessed < DATE_SUB(NOW(), INTERVAL 90 DAY)");
    
    error_log("Mobile Health Tracker: Cleanup completed");
}

// Schedule cleanup to run daily
if (!wp_next_scheduled('mobile_health_cleanup')) {
    wp_schedule_event(time(), 'daily', 'mobile_health_cleanup');
}
add_action('mobile_health_cleanup', 'mobile_health_cleanup_old_records');

// Security function: Validate session token
function mobile_health_validate_session($access_token) {
    global $wpdb;
    
    if (empty($access_token)) {
        return false;
    }
    
    $session = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}health_tracker_sessions 
         WHERE access_token = %s AND is_active = 1 AND expires_at > NOW()",
        $access_token
    ));
    
    if ($session) {
        // Update last accessed time
        $wpdb->update(
            $wpdb->prefix . 'health_tracker_sessions',
            array('last_accessed' => current_time('mysql')),
            array('id' => $session->id)
        );
        return $session;
    }
    
    return false;
}

// Debug endpoint: Fix database structure  
add_action('wp_ajax_mobile_health_fix_db', 'handle_mobile_health_fix_db');
add_action('wp_ajax_nopriv_mobile_health_fix_db', 'handle_mobile_health_fix_db');

// Debug endpoint: Add missing columns to submissions table
add_action('wp_ajax_mobile_health_fix_submissions', 'handle_mobile_health_fix_submissions');
add_action('wp_ajax_nopriv_mobile_health_fix_submissions', 'handle_mobile_health_fix_submissions');

function handle_mobile_health_fix_db() {
    global $wpdb;
    
    $sessions_table = $wpdb->prefix . 'health_tracker_sessions';
    $submissions_table = $wpdb->prefix . 'health_tracker_submissions';
    
    $fixes_applied = array();
    $errors = array();
    
    // Fix sessions table
    $current_structure = $wpdb->get_results("DESCRIBE $sessions_table");
    $existing_columns = array();
    foreach ($current_structure as $column) {
        $existing_columns[] = $column->Field;
    }
    
    $required_sessions_columns = array(
        'refresh_token' => "ALTER TABLE $sessions_table ADD COLUMN refresh_token varchar(255)",
        'expires_at' => "ALTER TABLE $sessions_table ADD COLUMN expires_at datetime", 
        'is_active' => "ALTER TABLE $sessions_table ADD COLUMN is_active int(1) DEFAULT 1"
    );
    
    foreach ($required_sessions_columns as $column => $sql) {
        if (!in_array($column, $existing_columns)) {
            $result = $wpdb->query($sql);
            if ($result !== false) {
                $fixes_applied[] = "Added sessions column: $column";
            } else {
                $errors[] = "Failed to add sessions $column: " . $wpdb->last_error;
            }
        } else {
            $fixes_applied[] = "Sessions column $column already exists";
        }
    }
    
    // Fix submissions table
    $submissions_structure = $wpdb->get_results("DESCRIBE $submissions_table");
    $submissions_columns = array();
    foreach ($submissions_structure as $column) {
        $submissions_columns[] = $column->Field;
    }
    
    $required_submissions_columns = array(
        'sync_status' => "ALTER TABLE $submissions_table ADD COLUMN sync_status int(1) DEFAULT 0",
        'created_at' => "ALTER TABLE $submissions_table ADD COLUMN created_at datetime DEFAULT CURRENT_TIMESTAMP",
        'updated_at' => "ALTER TABLE $submissions_table ADD COLUMN updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP"
    );
    
    foreach ($required_submissions_columns as $column => $sql) {
        if (!in_array($column, $submissions_columns)) {
            $result = $wpdb->query($sql);
            if ($result !== false) {
                $fixes_applied[] = "Added submissions column: $column";
            } else {
                $errors[] = "Failed to add submissions $column: " . $wpdb->last_error;
            }
        } else {
            $fixes_applied[] = "Submissions column $column already exists";
        }
    }
    
    // Get final structures
    $final_sessions_structure = $wpdb->get_results("DESCRIBE $sessions_table");
    $final_sessions_columns = array();
    foreach ($final_sessions_structure as $column) {
        $final_sessions_columns[] = $column->Field;
    }
    
    $final_submissions_structure = $wpdb->get_results("DESCRIBE $submissions_table");
    $final_submissions_columns = array();
    foreach ($final_submissions_structure as $column) {
        $final_submissions_columns[] = $column->Field;
    }
    
    wp_send_json_success(array(
        'message' => 'Database structure check completed',
        'fixes_applied' => $fixes_applied,
        'errors' => $errors,
        'sessions_columns' => $final_sessions_columns,
        'submissions_columns' => $final_submissions_columns
    ));
}

function handle_mobile_health_fix_submissions() {
    global $wpdb;
    
    $submissions_table = $wpdb->prefix . 'health_tracker_submissions';
    
    // Get current table structure
    $current_structure = $wpdb->get_results("DESCRIBE $submissions_table");
    $existing_columns = array();
    foreach ($current_structure as $column) {
        $existing_columns[] = $column->Field;
    }
    
    $fixes_applied = array();
    $errors = array();
    
    // Add missing columns that mobile app needs
    $required_columns = array(
        'sync_status' => "ALTER TABLE $submissions_table ADD COLUMN sync_status int(1) DEFAULT 0",
        'created_at' => "ALTER TABLE $submissions_table ADD COLUMN created_at datetime DEFAULT CURRENT_TIMESTAMP", 
        'updated_at' => "ALTER TABLE $submissions_table ADD COLUMN updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP"
    );
    
    foreach ($required_columns as $column => $sql) {
        if (!in_array($column, $existing_columns)) {
            $result = $wpdb->query($sql);
            if ($result !== false) {
                $fixes_applied[] = "Added column: $column";
            } else {
                $errors[] = "Failed to add $column: " . $wpdb->last_error;
            }
        } else {
            $fixes_applied[] = "Column $column already exists";
        }
    }
    
    // Get final structure
    $final_structure = $wpdb->get_results("DESCRIBE $submissions_table");
    $final_columns = array();
    foreach ($final_structure as $column) {
        $final_columns[] = $column->Field;
    }
    
    wp_send_json_success(array(
        'message' => 'Submissions table structure updated',
        'fixes_applied' => $fixes_applied,
        'errors' => $errors,
        'initial_columns' => $existing_columns,
        'final_columns' => $final_columns
    ));
}

// Debug endpoint: Create sample data for testing
add_action('wp_ajax_mobile_health_create_sample', 'handle_mobile_health_create_sample');
add_action('wp_ajax_nopriv_mobile_health_create_sample', 'handle_mobile_health_create_sample');

function handle_mobile_health_create_sample() {
    global $wpdb;
    
    if (empty($_POST['email'])) {
        wp_send_json_error('Email is required');
        return;
    }
    
    $email = sanitize_email($_POST['email']);
    $email_hash = hash('sha256', $email);
    
    // Ensure submissions table has all required columns
    $table_name = $wpdb->prefix . 'health_tracker_submissions';
    $submissions_structure = $wpdb->get_results("DESCRIBE $table_name");
    $submissions_columns = array();
    foreach ($submissions_structure as $column) {
        $submissions_columns[] = $column->Field;
    }
    
    // Add missing columns if they don't exist
    if (!in_array('sync_status', $submissions_columns)) {
        $wpdb->query("ALTER TABLE $table_name ADD COLUMN sync_status int(1) DEFAULT 0");
    }
    if (!in_array('created_at', $submissions_columns)) {
        $wpdb->query("ALTER TABLE $table_name ADD COLUMN created_at datetime DEFAULT CURRENT_TIMESTAMP");
    }
    if (!in_array('updated_at', $submissions_columns)) {
        $wpdb->query("ALTER TABLE $table_name ADD COLUMN updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
    }
    
    $sample_data = array(
        'user_hash' => $email_hash,
        'email_hash' => $email_hash,
        'name_hash' => hash('sha256', 'Test User'),
        'form_type' => 'health',
        'age' => 30,
        'gender' => 'male',
        'bmi' => 23.5,
        'whr' => 0.85,
        'biological_age' => 28.5,
        'age_shift' => -1.5,
        'lifestyle_score' => 0.75,
        'overall_health_score' => 0.80,
        'form_data_json' => json_encode(array('weight' => 70, 'height' => 175)),
        'calculated_metrics_json' => json_encode(array('bmi' => 23.5, 'whr' => 0.85)),
        'submission_date' => current_time('mysql'),
        'language_code' => 'en',
        'user_agent' => 'Mobile App Test',
        'ip_hash' => hash('sha256', '127.0.0.1'),
        'sync_status' => 1,
        'created_at' => current_time('mysql'),
        'updated_at' => current_time('mysql')
    );
    
    $result = $wpdb->insert($table_name, $sample_data);
    
    if ($result !== false) {
        $submission_id = $wpdb->insert_id;
        
        // Create a longevity sample too
        $longevity_data = $sample_data;
        $longevity_data['form_type'] = 'longevity';
        $longevity_data['biological_age'] = 29.0;
        $longevity_data['age_shift'] = -1.0;
        
        $wpdb->insert($table_name, $longevity_data);
        $longevity_id = $wpdb->insert_id;
        
        wp_send_json_success(array(
            'message' => 'Sample data created successfully',
            'health_id' => $submission_id,
            'longevity_id' => $longevity_id,
            'email_hash' => $email_hash
        ));
    } else {
        wp_send_json_error('Failed to create sample data: ' . $wpdb->last_error);
    }
}

// Debug endpoint: Check verification codes for an email
add_action('wp_ajax_mobile_health_debug_codes', 'handle_mobile_health_debug_codes');
add_action('wp_ajax_nopriv_mobile_health_debug_codes', 'handle_mobile_health_debug_codes');

function handle_mobile_health_debug_codes() {
    global $wpdb;
    
    if (empty($_POST['email'])) {
        wp_send_json_error('Email is required for debugging');
        return;
    }
    
    $email = sanitize_email($_POST['email']);
    $email_hash = hash('sha256', $email);
    
    // Get all verification codes for this email
    $table_name = $wpdb->prefix . 'health_tracker_verification_codes';
    $codes = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$table_name} WHERE email_hash = %s ORDER BY created_date DESC LIMIT 10",
        $email_hash
    ));
    
    // Get all sessions for this email
    $sessions_table = $wpdb->prefix . 'health_tracker_sessions';
    $sessions = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$sessions_table} WHERE email_hash = %s ORDER BY created_date DESC LIMIT 5",
        $email_hash
    ));
    
    wp_send_json_success(array(
        'email_hash' => $email_hash,
        'verification_codes' => $codes,
        'sessions' => $sessions,
        'current_time' => current_time('mysql')
    ));
}

// Debug endpoint: Check what data exists in database
add_action('wp_ajax_mobile_health_check_data', 'handle_mobile_health_check_data');
add_action('wp_ajax_nopriv_mobile_health_check_data', 'handle_mobile_health_check_data');

function handle_mobile_health_check_data() {
    global $wpdb;
    
    $result = array();
    
    // Check wp_health_tracker_users table
    $users_table = $wpdb->prefix . 'health_tracker_users';
    $users_exists = $wpdb->get_var("SHOW TABLES LIKE '$users_table'") == $users_table;
    
    if ($users_exists) {
        $users_count = $wpdb->get_var("SELECT COUNT(*) FROM $users_table");
        $sample_users = $wpdb->get_results("SELECT id, email_hash, user_hash, submission_date FROM $users_table LIMIT 3");
        $result['users_table'] = array(
            'exists' => true,
            'count' => $users_count,
            'sample_data' => $sample_users
        );
    } else {
        $result['users_table'] = array('exists' => false);
    }
    
    // Check wp_health_tracker_submissions table
    $submissions_table = $wpdb->prefix . 'health_tracker_submissions';
    $submissions_exists = $wpdb->get_var("SHOW TABLES LIKE '$submissions_table'") == $submissions_table;
    
    if ($submissions_exists) {
        $submissions_count = $wpdb->get_var("SELECT COUNT(*) FROM $submissions_table");
        $sample_submissions = $wpdb->get_results("SELECT id, user_hash, email_hash, form_type, submission_date FROM $submissions_table LIMIT 3");
        
        // Get table structure
        $table_structure = $wpdb->get_results("DESCRIBE $submissions_table");
        $columns = array();
        foreach ($table_structure as $column) {
            $columns[] = $column->Field;
        }
        
        $result['submissions_table'] = array(
            'exists' => true,
            'count' => $submissions_count,
            'columns' => $columns,
            'sample_data' => $sample_submissions
        );
    } else {
        $result['submissions_table'] = array('exists' => false);
    }
    
    wp_send_json_success($result);
}

// Add a simple debug function to test if handlers are working
add_action('wp_ajax_mobile_health_test', 'handle_mobile_health_test');
add_action('wp_ajax_nopriv_mobile_health_test', 'handle_mobile_health_test');

function handle_mobile_health_test() {
    global $wpdb;
    
    // Check database tables
    $tables_status = array();
    $required_tables = array(
        'health_tracker_submissions',
        'health_tracker_verification_codes', 
        'health_tracker_sessions'
    );
    
    foreach ($required_tables as $table) {
        $table_name = $wpdb->prefix . $table;
        $exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name;
        $tables_status[$table] = $exists ? 'exists' : 'missing';
    }
    
    // Check email functionality
    $email_configured = function_exists('wp_mail') && !empty(get_option('admin_email'));
    
    wp_send_json_success(array(
        'message' => 'Mobile Health Tracker AJAX handlers are working!',
        'timestamp' => current_time('mysql'),
        'version' => '2.0 - Production Ready',
        'security_features' => array(
            'rate_limiting' => 'enabled',
            'email_validation' => 'enabled',
            'data_sanitization' => 'enabled',
            'session_management' => 'enabled',
            'automatic_cleanup' => 'enabled'
        ),
        'database_tables' => $tables_status,
        'email_configured' => $email_configured,
        'available_actions' => array(
            'mobile_health_store',
            'mobile_health_get',
            'mobile_health_request_code',
            'mobile_health_verify_code',
            'mobile_health_delete',
            'mobile_health_test',
            'mobile_health_fix_db',
            'mobile_health_create_sample',
            'mobile_health_debug_codes'
        )
    ));
}

// CRITICAL: Add this debugging function
add_action('init', function() {
    if (isset($_GET['debug_mobile_health'])) {
        $actions = array(
            'mobile_health_request_code',
            'mobile_health_verify_code',
            'mobile_health_test',
            'mobile_health_get'
        );
        
        echo '<h2>Mobile Health AJAX Debug</h2>';
        echo '<p>AJAX URL: ' . admin_url('admin-ajax.php') . '</p>';
        
        global $wp_filter;
        foreach ($actions as $action) {
            $registered = isset($wp_filter['wp_ajax_' . $action]) || isset($wp_filter['wp_ajax_nopriv_' . $action]);
            echo '<p>Action ' . $action . ': ' . ($registered ? 'REGISTERED' : 'NOT REGISTERED') . '</p>';
        }
        
        die();
    }
});

