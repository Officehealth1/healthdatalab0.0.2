<?php
/**
 * Health Tracker Mobile App AJAX Handler
 * INSTALL THIS IN WORDPRESS VIA CODE SNIPPETS PLUGIN
 * Version: 3.0 - Consolidated and Simplified
 */

// CRITICAL: This must run early to register actions
add_action('init', function() {
    error_log('Health Tracker Mobile: Registering AJAX actions at init');
});

// Test endpoint - ALWAYS register this first
add_action('wp_ajax_mobile_health_test', 'mobile_health_test');
add_action('wp_ajax_nopriv_mobile_health_test', 'mobile_health_test');

function mobile_health_test() {
    error_log('Mobile Health Test: Endpoint called successfully');
    wp_send_json_success(array(
        'message' => 'Mobile Health API Working!',
        'version' => '3.0',
        'timestamp' => current_time('mysql'),
        'ajax_url' => admin_url('admin-ajax.php')
    ));
}

// Request verification code
add_action('wp_ajax_mobile_health_request_code', 'mobile_health_request_code');
add_action('wp_ajax_nopriv_mobile_health_request_code', 'mobile_health_request_code');

function mobile_health_request_code() {
    error_log('Mobile Health: Request code called for email: ' . ($_POST['email'] ?? 'no email'));
    
    global $wpdb;
    
    $email = sanitize_email($_POST['email'] ?? '');
    
    if (empty($email) || !is_email($email)) {
        wp_send_json_error('Invalid email address');
        return;
    }
    
    // Generate code
    $code = sprintf('%06d', mt_rand(100000, 999999));
    $email_hash = hash('sha256', strtolower(trim($email)));
    
    // Create table if needed
    $table = $wpdb->prefix . 'health_tracker_verification_codes';
    $wpdb->query("CREATE TABLE IF NOT EXISTS $table (
        id int AUTO_INCREMENT PRIMARY KEY,
        email_hash varchar(64),
        verification_code varchar(6),
        created_date datetime DEFAULT CURRENT_TIMESTAMP,
        expires_date datetime,
        is_used tinyint(1) DEFAULT 0,
        attempts int DEFAULT 0,
        KEY email_hash (email_hash)
    )");
    
    // Delete old codes
    $wpdb->delete($table, array('email_hash' => $email_hash));
    
    // Insert new code
    $wpdb->insert($table, array(
        'email_hash' => $email_hash,
        'verification_code' => $code,
        'expires_date' => date('Y-m-d H:i:s', time() + 900)
    ));
    
    // Try to send email
    $subject = 'Your Health Tracker Code: ' . $code;
    $message = "Your verification code is: $code\n\nThis code expires in 15 minutes.";
    $sent = wp_mail($email, $subject, $message);
    
    error_log('Mobile Health: Code ' . $code . ' generated for ' . $email . ', email sent: ' . ($sent ? 'yes' : 'no'));
    
    wp_send_json_success(array(
        'message' => 'Code sent',
        'debug_code' => $code, // Remove in production
        'email_sent' => $sent
    ));
}

// Verify code
add_action('wp_ajax_mobile_health_verify_code', 'mobile_health_verify_code');
add_action('wp_ajax_nopriv_mobile_health_verify_code', 'mobile_health_verify_code');

function mobile_health_verify_code() {
    error_log('Mobile Health: Verify code called');
    
    global $wpdb;
    
    $email = sanitize_email($_POST['email'] ?? '');
    $code = sanitize_text_field($_POST['code'] ?? '');
    
    if (empty($email) || empty($code)) {
        wp_send_json_error('Email and code required');
        return;
    }
    
    $email_hash = hash('sha256', strtolower(trim($email)));
    $table = $wpdb->prefix . 'health_tracker_verification_codes';
    
    // Find valid code
    $verification = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $table 
         WHERE email_hash = %s AND verification_code = %s 
         AND is_used = 0 AND expires_date > NOW()",
        $email_hash, $code
    ));
    
    if (!$verification) {
        wp_send_json_error('Invalid or expired code');
        return;
    }
    
    // Mark as used
    $wpdb->update($table, array('is_used' => 1), array('id' => $verification->id));
    
    // Generate tokens
    $access_token = wp_generate_password(64, false);
    $refresh_token = wp_generate_password(64, false);
    
    // Create session
    $sessions_table = $wpdb->prefix . 'health_tracker_sessions';
    $wpdb->query("CREATE TABLE IF NOT EXISTS $sessions_table (
        id int AUTO_INCREMENT PRIMARY KEY,
        email_hash varchar(64),
        user_hash varchar(64),
        access_token varchar(255),
        refresh_token varchar(255),
        created_date datetime DEFAULT CURRENT_TIMESTAMP,
        expires_at datetime,
        is_active tinyint(1) DEFAULT 1
    )");
    
    // Clean old sessions
    $wpdb->delete($sessions_table, array('email_hash' => $email_hash));
    
    // Insert new session
    $wpdb->insert($sessions_table, array(
        'email_hash' => $email_hash,
        'user_hash' => $email_hash,
        'access_token' => $access_token,
        'refresh_token' => $refresh_token,
        'expires_at' => date('Y-m-d H:i:s', time() + 86400)
    ));
    
    error_log('Mobile Health: Authentication successful for ' . $email);
    
    wp_send_json_success(array(
        'access_token' => $access_token,
        'refresh_token' => $refresh_token,
        'user_hash' => $email_hash,
        'email_hash' => $email_hash,
        'expires_at' => date('Y-m-d H:i:s', time() + 86400)
    ));
}

// Get user data
add_action('wp_ajax_mobile_health_get', 'mobile_health_get');
add_action('wp_ajax_nopriv_mobile_health_get', 'mobile_health_get');

function mobile_health_get() {
    $email = sanitize_email($_POST['email'] ?? '');
    
    if (empty($email)) {
        wp_send_json_error('Email required');
        return;
    }
    
    global $wpdb;
    $email_hash = hash('sha256', strtolower(trim($email)));
    $table_name = $wpdb->prefix . 'health_tracker_submissions';
    
    // Get all records for the user, ordered by most recent
    $results = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$table_name} WHERE email_hash = %s ORDER BY submission_date DESC",
        $email_hash
    ));

    // Ensure numeric values are cast correctly before sending to the app
    foreach ($results as $row) {
        $numeric_fields = ['id', 'age', 'biological_age', 'age_shift', 'whr', 'lifestyle_score', 'overall_health_score', 'bmi', 'data_completeness_score'];
        foreach ($numeric_fields as $field) {
            if (isset($row->$field)) {
                $row->$field = (float) $row->$field;
            }
        }
    }

    wp_send_json_success($results);
}

// Store data
add_action('wp_ajax_mobile_health_store', 'mobile_health_store');
add_action('wp_ajax_nopriv_mobile_health_store', 'mobile_health_store');

function mobile_health_store() {
    global $wpdb;
    
    $email = sanitize_email($_POST['email'] ?? '');
    $form_type = sanitize_text_field($_POST['form_type'] ?? 'health');
    $form_data = $_POST['form_data'] ?? '{}';
    $calculated_metrics = $_POST['calculated_metrics'] ?? '{}';
    
    if (empty($email)) {
        wp_send_json_error('Email required');
        return;
    }
    
    $email_hash = hash('sha256', strtolower(trim($email)));
    $table = $wpdb->prefix . 'health_tracker_submissions';
    
    $result = $wpdb->insert($table, array(
        'email_hash' => $email_hash,
        'user_hash' => $email_hash,
        'form_type' => $form_type,
        'form_data_json' => $form_data,
        'calculated_metrics_json' => $calculated_metrics,
        'submission_date' => current_time('mysql')
    ));
    
    if ($result) {
        wp_send_json_success(array(
            'message' => 'Data stored',
            'submission_id' => $wpdb->insert_id
        ));
    } else {
        wp_send_json_error('Failed to store data');
    }
}

// Get IDs
add_action('wp_ajax_mobile_health_get_ids', 'mobile_health_get_ids');
add_action('wp_ajax_nopriv_mobile_health_get_ids', 'mobile_health_get_ids');

function mobile_health_get_ids() {
    $email = sanitize_email($_POST['email'] ?? '');
    
    if (empty($email)) {
        wp_send_json_error('Email required');
        return;
    }
    
    global $wpdb;
    $email_hash = hash('sha256', strtolower(trim($email)));
    $table = $wpdb->prefix . 'health_tracker_submissions';
    
    $ids = $wpdb->get_col($wpdb->prepare(
        "SELECT id FROM $table WHERE email_hash = %s",
        $email_hash
    ));
    
    wp_send_json_success(array(
        'submission_ids' => array_map('intval', $ids)
    ));
}

// Delete
add_action('wp_ajax_mobile_health_delete', 'mobile_health_delete');
add_action('wp_ajax_nopriv_mobile_health_delete', 'mobile_health_delete');

function mobile_health_delete() {
    $email = sanitize_email($_POST['email'] ?? '');
    $id = intval($_POST['submission_id'] ?? 0);
    
    if (empty($email) || !$id) {
        wp_send_json_error('Email and ID required');
        return;
    }
    
    global $wpdb;
    $email_hash = hash('sha256', strtolower(trim($email)));
    $table = $wpdb->prefix . 'health_tracker_submissions';
    
    $deleted = $wpdb->delete($table, array(
        'id' => $id,
        'email_hash' => $email_hash
    ));
    
    if ($deleted) {
        wp_send_json_success(array('message' => 'Deleted'));
    } else {
        wp_send_json_error('Failed to delete');
    }
}

// Debug helper
add_action('init', function() {
    if (isset($_GET['mobile_health_debug'])) {
        global $wp_filter;
        $actions = ['mobile_health_test', 'mobile_health_request_code', 'mobile_health_verify_code'];
        echo '<h2>Mobile Health Debug</h2>';
        foreach ($actions as $action) {
            $registered = isset($wp_filter['wp_ajax_' . $action]);
            echo "<p>$action: " . ($registered ? 'REGISTERED' : 'NOT REGISTERED') . "</p>";
        }
        die();
    }
});