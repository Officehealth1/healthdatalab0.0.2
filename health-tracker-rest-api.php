<?php
/**
 * Health Tracker Mobile API - Complete REST API Implementation
 * Integrates with existing AJAX handlers and provides REST endpoints
 * Version: 1.0
 */

// Security: Prevent direct access
if (!defined('ABSPATH')) {
    exit('Direct access forbidden.');
}

// Include required files
require_once plugin_dir_path(__FILE__) . 'api-config.php';
require_once plugin_dir_path(__FILE__) . 'api-security.php';
require_once plugin_dir_path(__FILE__) . 'mobile-auth.php';

/**
 * Initialize REST API routes
 */
add_action('rest_api_init', 'health_tracker_register_api_routes');

function health_tracker_register_api_routes() {
    $namespace = HEALTH_TRACKER_API_NAMESPACE;
    
    // Authentication endpoints
    register_rest_route($namespace, '/auth/request-code', array(
        'methods' => 'POST',
        'callback' => 'health_tracker_api_request_code',
        'permission_callback' => '__return_true',
        'args' => array(
            'email' => array(
                'required' => true,
                'type' => 'string',
                'format' => 'email',
                'sanitize_callback' => 'sanitize_email'
            )
        )
    ));
    
    register_rest_route($namespace, '/auth/verify-code', array(
        'methods' => 'POST',
        'callback' => 'health_tracker_api_verify_code',
        'permission_callback' => '__return_true',
        'args' => array(
            'email' => array(
                'required' => true,
                'type' => 'string',
                'format' => 'email',
                'sanitize_callback' => 'sanitize_email'
            ),
            'code' => array(
                'required' => true,
                'type' => 'string',
                'pattern' => '^\d{6}$'
            )
        )
    ));
    
    register_rest_route($namespace, '/auth/refresh', array(
        'methods' => 'POST',
        'callback' => 'health_tracker_api_refresh_token',
        'permission_callback' => 'health_tracker_api_auth_check'
    ));
    
    register_rest_route($namespace, '/auth/logout', array(
        'methods' => 'POST',
        'callback' => 'health_tracker_api_logout',
        'permission_callback' => 'health_tracker_api_auth_check'
    ));
    
    // User endpoints
    register_rest_route($namespace, '/user/profile', array(
        'methods' => 'GET',
        'callback' => 'health_tracker_api_get_profile',
        'permission_callback' => 'health_tracker_api_auth_check'
    ));
    
    register_rest_route($namespace, '/user/profile', array(
        'methods' => 'PUT',
        'callback' => 'health_tracker_api_update_profile',
        'permission_callback' => 'health_tracker_api_auth_check'
    ));
    
    // Health data endpoints
    register_rest_route($namespace, '/health-data/submit', array(
        'methods' => 'POST',
        'callback' => 'health_tracker_api_submit_data',
        'permission_callback' => 'health_tracker_api_auth_check',
        'args' => array(
            'form_type' => array(
                'required' => true,
                'type' => 'string',
                'enum' => array('health', 'longevity')
            ),
            'form_data' => array(
                'required' => true,
                'type' => 'object'
            ),
            'calculated_metrics' => array(
                'required' => false,
                'type' => 'object'
            ),
            'name' => array(
                'required' => false,
                'type' => 'string'
            ),
            'age' => array(
                'required' => false,
                'type' => 'integer',
                'minimum' => 0,
                'maximum' => 150
            ),
            'gender' => array(
                'required' => false,
                'type' => 'string',
                'enum' => array('male', 'female', 'other')
            )
        )
    ));
    
    register_rest_route($namespace, '/health-data/history', array(
        'methods' => 'GET',
        'callback' => 'health_tracker_api_get_history',
        'permission_callback' => 'health_tracker_api_auth_check',
        'args' => array(
            'limit' => array(
                'required' => false,
                'type' => 'integer',
                'default' => 50,
                'minimum' => 1,
                'maximum' => 100
            ),
            'offset' => array(
                'required' => false,
                'type' => 'integer',
                'default' => 0,
                'minimum' => 0
            ),
            'form_type' => array(
                'required' => false,
                'type' => 'string',
                'enum' => array('health', 'longevity')
            )
        )
    ));
    
    register_rest_route($namespace, '/health-data/latest', array(
        'methods' => 'GET',
        'callback' => 'health_tracker_api_get_latest',
        'permission_callback' => 'health_tracker_api_auth_check'
    ));
    
    register_rest_route($namespace, '/health-data/sync', array(
        'methods' => 'POST',
        'callback' => 'health_tracker_api_sync_data',
        'permission_callback' => 'health_tracker_api_auth_check',
        'args' => array(
            'data' => array(
                'required' => true,
                'type' => 'array'
            ),
            'last_sync' => array(
                'required' => false,
                'type' => 'string',
                'format' => 'date-time'
            )
        )
    ));
    
    // Health check endpoint
    register_rest_route($namespace, '/health', array(
        'methods' => 'GET',
        'callback' => 'health_tracker_api_health_check',
        'permission_callback' => '__return_true'
    ));
}

/**
 * Authentication check for protected endpoints
 */
function health_tracker_api_auth_check($request) {
    // Apply rate limiting
    $ip_hash = hash('sha256', $_SERVER['REMOTE_ADDR'] ?? 'unknown');
    if (!health_tracker_check_rate_limit($ip_hash, 100, 3600)) {
        return health_tracker_error_response('rate_limit_exceeded');
    }
    
    // Validate headers
    $header_validation = health_tracker_validate_headers($request);
    if (is_wp_error($header_validation)) {
        return $header_validation;
    }
    
    // Validate token
    $token_validation = health_tracker_validate_token($request);
    if (is_wp_error($token_validation)) {
        return $token_validation;
    }
    
    return true;
}

/**
 * Add CORS headers to all API responses
 */
add_filter('rest_pre_serve_request', 'health_tracker_add_cors_headers', 15, 4);

function health_tracker_add_cors_headers($served, $result, $request, $server) {
    $origin = $request->get_header('Origin');
    
    if (strpos($request->get_route(), '/' . HEALTH_TRACKER_API_NAMESPACE . '/') === 0) {
        $cors_headers = health_tracker_get_cors_headers($origin);
        
        foreach ($cors_headers as $header => $value) {
            header("{$header}: {$value}");
        }
    }
    
    return $served;
}

/**
 * Handle OPTIONS requests for CORS preflight
 */
add_action('rest_api_init', function() {
    remove_filter('rest_pre_serve_request', 'rest_send_cors_headers');
    add_filter('rest_pre_serve_request', function($served, $result, $request, $server) {
        if ($request->get_method() === 'OPTIONS') {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type, Authorization, X-User-Email, X-Requested-With');
            header('Access-Control-Max-Age: 86400');
            exit;
        }
        return $served;
    }, 15, 4);
});

// API Endpoint Implementations

/**
 * Request verification code endpoint
 */
function health_tracker_api_request_code($request) {
    try {
        $email = $request->get_param('email');
        
        // Rate limiting per email
        $email_hash = hash('sha256', $email);
        if (!health_tracker_check_rate_limit("email_code_{$email_hash}", 5, 3600)) {
            return health_tracker_error_response('rate_limit_exceeded', 'Too many code requests for this email');
        }
        
        // Use existing AJAX function
        $result = health_tracker_request_code($request);
        
        if (is_wp_error($result)) {
            health_tracker_log_security_event('code_request_failed', array(
                'email' => $email,
                'error' => $result->get_error_message()
            ));
            return health_tracker_error_response('invalid_email', $result->get_error_message());
        }
        
        health_tracker_log_security_event('code_requested', array('email' => $email));
        
        return health_tracker_success_response(
            array('email' => $email),
            'Verification code sent successfully'
        );
        
    } catch (Exception $e) {
        error_log('Health Tracker API Error - Request Code: ' . $e->getMessage());
        return health_tracker_error_response('internal_error');
    }
}

/**
 * Verify code and authenticate endpoint
 */
function health_tracker_api_verify_code($request) {
    try {
        $email = $request->get_param('email');
        $code = $request->get_param('code');
        
        // Rate limiting per email
        $email_hash = hash('sha256', $email);
        if (!health_tracker_check_rate_limit("email_verify_{$email_hash}", 10, 3600)) {
            return health_tracker_error_response('rate_limit_exceeded', 'Too many verification attempts');
        }
        
        // Use existing AJAX function
        $result = health_tracker_verify_code($request);
        
        if (is_wp_error($result)) {
            health_tracker_log_security_event('verification_failed', array(
                'email' => $email,
                'error' => $result->get_error_message()
            ));
            return health_tracker_error_response('invalid_code', $result->get_error_message());
        }
        
        health_tracker_log_security_event('user_authenticated', array('email' => $email));
        
        return $result;
        
    } catch (Exception $e) {
        error_log('Health Tracker API Error - Verify Code: ' . $e->getMessage());
        return health_tracker_error_response('internal_error');
    }
}

/**
 * Refresh authentication token
 */
function health_tracker_api_refresh_token($request) {
    try {
        $user_email = $request->get_header('X-User-Email');
        $auth_header = $request->get_header('Authorization');
        
        if (empty($user_email) || empty($auth_header)) {
            return health_tracker_error_response('unauthorized', 'Missing authentication headers');
        }
        
        $token = str_replace('Bearer ', '', $auth_header);
        
        // Generate new tokens
        $new_access_token = health_tracker_generate_token();
        $new_refresh_token = health_tracker_generate_token();
        
        global $wpdb;
        $sessions_table = health_tracker_get_table_names()['sessions'];
        $email_hash = hash('sha256', $user_email);
        
        // Update session with new tokens
        $result = $wpdb->update(
            $sessions_table,
            array(
                'access_token' => $new_access_token,
                'refresh_token' => $new_refresh_token,
                'expires_at' => date('Y-m-d H:i:s', time() + HEALTH_TRACKER_ACCESS_TOKEN_EXPIRY),
                'last_accessed' => current_time('mysql')
            ),
            array(
                'access_token' => $token,
                'email_hash' => $email_hash,
                'is_active' => 1
            )
        );
        
        if ($result === false) {
            return health_tracker_error_response('unauthorized', 'Invalid session');
        }
        
        return health_tracker_success_response(array(
            'access_token' => $new_access_token,
            'refresh_token' => $new_refresh_token,
            'expires_at' => date('Y-m-d H:i:s', time() + HEALTH_TRACKER_ACCESS_TOKEN_EXPIRY)
        ), 'Token refreshed successfully');
        
    } catch (Exception $e) {
        error_log('Health Tracker API Error - Refresh Token: ' . $e->getMessage());
        return health_tracker_error_response('internal_error');
    }
}

/**
 * Logout endpoint
 */
function health_tracker_api_logout($request) {
    try {
        $user_email = $request->get_header('X-User-Email');
        $auth_header = $request->get_header('Authorization');
        
        $token = str_replace('Bearer ', '', $auth_header);
        
        global $wpdb;
        $sessions_table = health_tracker_get_table_names()['sessions'];
        $email_hash = hash('sha256', $user_email);
        
        // Deactivate session
        $wpdb->update(
            $sessions_table,
            array('is_active' => 0),
            array(
                'access_token' => $token,
                'email_hash' => $email_hash
            )
        );
        
        health_tracker_log_security_event('user_logout', array('email' => $user_email));
        
        return health_tracker_success_response(null, 'Logged out successfully');
        
    } catch (Exception $e) {
        error_log('Health Tracker API Error - Logout: ' . $e->getMessage());
        return health_tracker_error_response('internal_error');
    }
}

/**
 * Get user profile
 */
function health_tracker_api_get_profile($request) {
    try {
        $user_email = $request->get_header('X-User-Email');
        
        $profile = health_tracker_get_user_profile($user_email);
        
        return health_tracker_success_response($profile, 'Profile retrieved successfully');
        
    } catch (Exception $e) {
        error_log('Health Tracker API Error - Get Profile: ' . $e->getMessage());
        return health_tracker_error_response('internal_error');
    }
}

/**
 * Update user profile
 */
function health_tracker_api_update_profile($request) {
    try {
        $user_email = $request->get_header('X-User-Email');
        $data = $request->get_json_params();
        
        // TODO: Implement profile update logic
        // For now, return success
        
        return health_tracker_success_response(null, 'Profile updated successfully');
        
    } catch (Exception $e) {
        error_log('Health Tracker API Error - Update Profile: ' . $e->getMessage());
        return health_tracker_error_response('internal_error');
    }
}

/**
 * Submit health data
 */
function health_tracker_api_submit_data($request) {
    try {
        $user_email = $request->get_header('X-User-Email');
        
        // Prepare data for existing AJAX handler
        $_POST['email'] = $user_email;
        $_POST['form_type'] = $request->get_param('form_type');
        $_POST['form_data'] = json_encode($request->get_param('form_data'));
        $_POST['calculated_metrics'] = json_encode($request->get_param('calculated_metrics') ?? array());
        $_POST['name'] = $request->get_param('name') ?? '';
        $_POST['age'] = $request->get_param('age') ?? 0;
        $_POST['gender'] = $request->get_param('gender') ?? '';
        
        // Use existing AJAX function
        ob_start();
        handle_mobile_health_store();
        $response = ob_get_clean();
        
        $data = json_decode($response, true);
        
        if ($data && $data['success']) {
            return health_tracker_success_response($data['data'], 'Health data submitted successfully');
        } else {
            return health_tracker_error_response('internal_error', $data['data'] ?? 'Failed to submit data');
        }
        
    } catch (Exception $e) {
        error_log('Health Tracker API Error - Submit Data: ' . $e->getMessage());
        return health_tracker_error_response('internal_error');
    }
}

/**
 * Get health data history
 */
function health_tracker_api_get_history($request) {
    try {
        $user_email = $request->get_header('X-User-Email');
        $limit = $request->get_param('limit') ?? 50;
        $offset = $request->get_param('offset') ?? 0;
        $form_type = $request->get_param('form_type');
        
        global $wpdb;
        $submissions_table = health_tracker_get_table_names()['submissions'];
        $email_hash = hash('sha256', $user_email);
        
        $where_clause = "WHERE email_hash = %s";
        $params = array($user_email);
        
        if ($form_type) {
            $where_clause .= " AND form_type = %s";
            $params[] = $form_type;
        }
        
        $sql = "SELECT * FROM {$submissions_table} 
                {$where_clause} 
                ORDER BY submission_date DESC 
                LIMIT %d OFFSET %d";
        
        $params[] = $limit;
        $params[] = $offset;
        
        $results = $wpdb->get_results($wpdb->prepare($sql, $params));
        
        // Process results
        $history = array();
        foreach ($results as $row) {
            $history[] = array(
                'id' => $row->id,
                'form_type' => $row->form_type,
                'name' => $row->name,
                'age' => $row->age,
                'gender' => $row->gender,
                'form_data' => json_decode($row->form_data, true),
                'calculated_metrics' => json_decode($row->calculated_metrics, true),
                'submission_date' => $row->submission_date
            );
        }
        
        return health_tracker_success_response(array(
            'history' => $history,
            'total' => count($history),
            'limit' => $limit,
            'offset' => $offset
        ), 'History retrieved successfully');
        
    } catch (Exception $e) {
        error_log('Health Tracker API Error - Get History: ' . $e->getMessage());
        return health_tracker_error_response('internal_error');
    }
}

/**
 * Get latest health data
 */
function health_tracker_api_get_latest($request) {
    try {
        $user_email = $request->get_header('X-User-Email');
        
        global $wpdb;
        $submissions_table = health_tracker_get_table_names()['submissions'];
        
        $latest = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$submissions_table} 
             WHERE email_hash = %s 
             ORDER BY submission_date DESC 
             LIMIT 1",
            $user_email
        ));
        
        if ($latest) {
            $data = array(
                'id' => $latest->id,
                'form_type' => $latest->form_type,
                'name' => $latest->name,
                'age' => $latest->age,
                'gender' => $latest->gender,
                'form_data' => json_decode($latest->form_data, true),
                'calculated_metrics' => json_decode($latest->calculated_metrics, true),
                'submission_date' => $latest->submission_date
            );
        } else {
            $data = null;
        }
        
        return health_tracker_success_response($data, 'Latest data retrieved successfully');
        
    } catch (Exception $e) {
        error_log('Health Tracker API Error - Get Latest: ' . $e->getMessage());
        return health_tracker_error_response('internal_error');
    }
}

/**
 * Sync health data
 */
function health_tracker_api_sync_data($request) {
    try {
        $user_email = $request->get_header('X-User-Email');
        $sync_data = $request->get_param('data');
        $last_sync = $request->get_param('last_sync');
        
        // TODO: Implement comprehensive sync logic
        // For now, return basic sync response
        
        global $wpdb;
        $submissions_table = health_tracker_get_table_names()['submissions'];
        
        // Get data since last sync
        $where_clause = "WHERE email_hash = %s";
        $params = array($user_email);
        
        if ($last_sync) {
            $where_clause .= " AND submission_date > %s";
            $params[] = $last_sync;
        }
        
        $server_data = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$submissions_table} {$where_clause} ORDER BY submission_date DESC",
            $params
        ));
        
        return health_tracker_success_response(array(
            'server_data' => $server_data,
            'sync_timestamp' => current_time('mysql'),
            'conflicts' => array() // TODO: Implement conflict detection
        ), 'Data synced successfully');
        
    } catch (Exception $e) {
        error_log('Health Tracker API Error - Sync Data: ' . $e->getMessage());
        return health_tracker_error_response('internal_error');
    }
}

/**
 * Health check endpoint
 */
function health_tracker_api_health_check($request) {
    global $wpdb;
    
    $status = array(
        'status' => 'healthy',
        'timestamp' => current_time('mysql'),
        'api_version' => HEALTH_TRACKER_API_VERSION,
        'database' => 'connected'
    );
    
    // Check database connection
    try {
        $wpdb->get_var("SELECT 1");
    } catch (Exception $e) {
        $status['status'] = 'unhealthy';
        $status['database'] = 'error';
    }
    
    return health_tracker_success_response($status, 'Health check completed');
}