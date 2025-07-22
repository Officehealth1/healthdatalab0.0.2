<?php
/**
 * Mobile Authentication System
 * Integrates with existing AJAX-based authentication
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit('Direct access forbidden.');
}

/**
 * Validate token for REST API requests
 * Uses existing session system from AJAX handlers
 */
function health_tracker_validate_token($request) {
    $auth_header = $request->get_header('Authorization');
    $user_email = $request->get_header('X-User-Email');
    
    if (empty($auth_header)) {
        return new WP_Error('no_auth', 'Authorization header missing', array('status' => 401));
    }
    
    if (empty($user_email)) {
        return new WP_Error('no_email', 'User email header missing', array('status' => 401));
    }
    
    // Extract token from "Bearer TOKEN" format
    $token = str_replace('Bearer ', '', $auth_header);
    
    // Validate token using existing session system
    if (!health_tracker_is_valid_session($token, $user_email)) {
        return new WP_Error('invalid_token', 'Invalid or expired token', array('status' => 401));
    }
    
    return true;
}

/**
 * Check if session token is valid using existing database
 */
function health_tracker_is_valid_session($access_token, $user_email) {
    global $wpdb;
    
    if (empty($access_token) || empty($user_email)) {
        return false;
    }
    
    $sessions_table = $wpdb->prefix . 'health_tracker_sessions';
    $email_hash = hash('sha256', $user_email);
    
    // Check if session exists and is active
    $session = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$sessions_table} 
         WHERE access_token = %s 
         AND email_hash = %s 
         AND is_active = 1 
         AND expires_at > NOW()",
        $access_token,
        $email_hash
    ));
    
    if ($session) {
        // Update last accessed time
        $wpdb->update(
            $sessions_table,
            array('last_accessed' => current_time('mysql')),
            array('id' => $session->id)
        );
        return true;
    }
    
    return false;
}

/**
 * Request verification code (integrates with existing AJAX)
 */
function health_tracker_request_code($request) {
    $email = $request->get_param('email');
    
    if (!is_email($email)) {
        return new WP_Error('invalid_email', 'Invalid email address', array('status' => 400));
    }
    
    // Use existing AJAX function internally
    $_POST['email'] = $email;
    ob_start();
    handle_mobile_health_request_code();
    $response = ob_get_clean();
    
    $data = json_decode($response, true);
    
    if ($data && $data['success']) {
        return new WP_REST_Response(array(
            'status' => 'success',
            'message' => 'Verification code sent successfully',
            'data' => $data['data']
        ), 200);
    } else {
        return new WP_Error('request_failed', $data['data'] ?? 'Failed to send verification code', array('status' => 500));
    }
}

/**
 * Verify code and create session (integrates with existing AJAX)
 */
function health_tracker_verify_code($request) {
    $email = $request->get_param('email');
    $code = $request->get_param('code');
    
    if (!is_email($email)) {
        return new WP_Error('invalid_email', 'Invalid email address', array('status' => 400));
    }
    
    if (empty($code)) {
        return new WP_Error('invalid_code', 'Verification code is required', array('status' => 400));
    }
    
    // Use existing AJAX function internally
    $_POST['email'] = $email;
    $_POST['code'] = $code;
    ob_start();
    handle_mobile_health_verify_code();
    $response = ob_get_clean();
    
    $data = json_decode($response, true);
    
    if ($data && $data['success']) {
        return new WP_REST_Response(array(
            'status' => 'success',
            'message' => 'Authentication successful',
            'data' => array(
                'access_token' => $data['data']['access_token'],
                'refresh_token' => $data['data']['refresh_token'],
                'user_hash' => $data['data']['user_hash'],
                'expires_at' => $data['data']['expires_at'],
                'user_email' => $email
            )
        ), 200);
    } else {
        return new WP_Error('verification_failed', $data['data'] ?? 'Verification failed', array('status' => 401));
    }
}

/**
 * Get user profile information
 */
function health_tracker_get_user_profile($user_email) {
    global $wpdb;
    
    $email_hash = hash('sha256', $user_email);
    
    // Get user data from both tables
    $users_table = $wpdb->prefix . 'health_tracker_users';
    $submissions_table = $wpdb->prefix . 'health_tracker_submissions';
    
    $profile_data = array(
        'email' => $user_email,
        'email_hash' => $email_hash,
        'registration_date' => null,
        'last_activity' => null,
        'total_assessments' => 0
    );
    
    // Check users table
    $user_record = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$users_table} WHERE email_hash = %s LIMIT 1",
        $user_email // Store actual email, not hash for now
    ));
    
    if ($user_record) {
        $profile_data['registration_date'] = $user_record->submission_date ?? $user_record->created_at ?? null;
    }
    
    // Get assessment count and last activity
    $assessment_stats = $wpdb->get_row($wpdb->prepare(
        "SELECT COUNT(*) as total, MAX(submission_date) as last_date 
         FROM {$submissions_table} 
         WHERE email_hash = %s",
        $user_email
    ));
    
    if ($assessment_stats) {
        $profile_data['total_assessments'] = (int)$assessment_stats->total;
        $profile_data['last_activity'] = $assessment_stats->last_date;
    }
    
    return $profile_data;
}

/**
 * Cleanup expired tokens
 */
function health_tracker_cleanup_expired_tokens() {
    global $wpdb;
    
    $sessions_table = $wpdb->prefix . 'health_tracker_sessions';
    
    // Mark expired sessions as inactive
    $wpdb->query(
        "UPDATE {$sessions_table} 
         SET is_active = 0 
         WHERE expires_at < NOW() AND is_active = 1"
    );
    
    // Delete very old sessions (older than 90 days)
    $wpdb->query(
        "DELETE FROM {$sessions_table} 
         WHERE created_date < DATE_SUB(NOW(), INTERVAL 90 DAY)"
    );
}

// Schedule token cleanup
if (!wp_next_scheduled('health_tracker_token_cleanup')) {
    wp_schedule_event(time(), 'daily', 'health_tracker_token_cleanup');
}
add_action('health_tracker_token_cleanup', 'health_tracker_cleanup_expired_tokens');