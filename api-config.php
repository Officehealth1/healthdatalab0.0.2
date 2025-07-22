<?php
/**
 * API Configuration and Constants
 * Configuration settings for Health Tracker REST API
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit('Direct access forbidden.');
}

// API Version
define('HEALTH_TRACKER_API_VERSION', '1.0');

// API Namespace
define('HEALTH_TRACKER_API_NAMESPACE', 'health-tracker/v1');

// JWT Secret (should be moved to wp-config.php in production)
if (!defined('HEALTH_TRACKER_JWT_SECRET')) {
    define('HEALTH_TRACKER_JWT_SECRET', 'your-jwt-secret-key-change-this-in-production');
}

// Encryption key (should be moved to wp-config.php in production)
if (!defined('HEALTH_TRACKER_ENCRYPTION_KEY')) {
    define('HEALTH_TRACKER_ENCRYPTION_KEY', 'your-encryption-key-change-this-in-production');
}

// Token expiration times (in seconds)
define('HEALTH_TRACKER_ACCESS_TOKEN_EXPIRY', 24 * 60 * 60); // 24 hours
define('HEALTH_TRACKER_REFRESH_TOKEN_EXPIRY', 30 * 24 * 60 * 60); // 30 days
define('HEALTH_TRACKER_VERIFICATION_CODE_EXPIRY', 15 * 60); // 15 minutes

// Rate limiting configuration
define('HEALTH_TRACKER_RATE_LIMIT_REQUESTS', 60); // requests per window
define('HEALTH_TRACKER_RATE_LIMIT_WINDOW', 3600); // 1 hour in seconds

// Email configuration
define('HEALTH_TRACKER_EMAIL_FROM', get_option('admin_email', 'noreply@' . $_SERVER['HTTP_HOST']));
define('HEALTH_TRACKER_EMAIL_FROM_NAME', get_bloginfo('name') . ' Health Tracker');

// API endpoints configuration
$health_tracker_api_config = array(
    'endpoints' => array(
        'auth' => array(
            'request_code' => array(
                'method' => 'POST',
                'path' => '/auth/request-code',
                'auth_required' => false,
                'rate_limit' => 5 // requests per hour per email
            ),
            'verify_code' => array(
                'method' => 'POST', 
                'path' => '/auth/verify-code',
                'auth_required' => false,
                'rate_limit' => 10
            ),
            'refresh_token' => array(
                'method' => 'POST',
                'path' => '/auth/refresh',
                'auth_required' => true,
                'rate_limit' => 20
            ),
            'logout' => array(
                'method' => 'POST',
                'path' => '/auth/logout',
                'auth_required' => true,
                'rate_limit' => 100
            )
        ),
        'user' => array(
            'profile' => array(
                'method' => 'GET',
                'path' => '/user/profile',
                'auth_required' => true,
                'rate_limit' => 60
            ),
            'update_profile' => array(
                'method' => 'PUT',
                'path' => '/user/profile',
                'auth_required' => true,
                'rate_limit' => 20
            )
        ),
        'health_data' => array(
            'submit' => array(
                'method' => 'POST',
                'path' => '/health-data/submit',
                'auth_required' => true,
                'rate_limit' => 10
            ),
            'history' => array(
                'method' => 'GET',
                'path' => '/health-data/history',
                'auth_required' => true,
                'rate_limit' => 60
            ),
            'latest' => array(
                'method' => 'GET',
                'path' => '/health-data/latest',
                'auth_required' => true,
                'rate_limit' => 60
            ),
            'sync' => array(
                'method' => 'POST',
                'path' => '/health-data/sync',
                'auth_required' => true,
                'rate_limit' => 30
            )
        )
    ),
    
    'allowed_origins' => array(
        'localhost:8081', // Expo development
        'localhost:19006', // Expo web
        'exp://192.168.1.100:8081', // Expo mobile development
        'healthdatalab.net',
        'www.healthdatalab.net'
    ),
    
    'cors_headers' => array(
        'Access-Control-Allow-Origin',
        'Access-Control-Allow-Methods', 
        'Access-Control-Allow-Headers',
        'Access-Control-Allow-Credentials'
    ),
    
    'security' => array(
        'require_https' => false, // Set to true in production
        'max_request_size' => 1024 * 1024, // 1MB
        'allowed_file_types' => array('json'),
        'blocked_user_agents' => array('bot', 'crawler', 'spider', 'scraper'),
        'suspicious_keywords' => array('union', 'select', 'drop', 'delete', 'insert', 'update', 'script')
    )
);

/**
 * Get API configuration
 */
function health_tracker_get_api_config($key = null) {
    global $health_tracker_api_config;
    
    if ($key === null) {
        return $health_tracker_api_config;
    }
    
    return $health_tracker_api_config[$key] ?? null;
}

/**
 * Get endpoint configuration
 */
function health_tracker_get_endpoint_config($endpoint_group, $endpoint_name) {
    $config = health_tracker_get_api_config('endpoints');
    return $config[$endpoint_group][$endpoint_name] ?? null;
}

/**
 * Check if origin is allowed
 */
function health_tracker_is_origin_allowed($origin) {
    $allowed_origins = health_tracker_get_api_config('allowed_origins');
    
    // Remove protocol from origin for comparison
    $clean_origin = preg_replace('/^https?:\/\//', '', $origin);
    
    return in_array($clean_origin, $allowed_origins);
}

/**
 * Get CORS headers
 */
function health_tracker_get_cors_headers($origin = null) {
    $headers = array();
    
    if ($origin && health_tracker_is_origin_allowed($origin)) {
        $headers['Access-Control-Allow-Origin'] = $origin;
    } else {
        $headers['Access-Control-Allow-Origin'] = '*';
    }
    
    $headers['Access-Control-Allow-Methods'] = 'GET, POST, PUT, DELETE, OPTIONS';
    $headers['Access-Control-Allow-Headers'] = 'Content-Type, Authorization, X-User-Email, X-Requested-With';
    $headers['Access-Control-Allow-Credentials'] = 'true';
    $headers['Access-Control-Max-Age'] = '86400'; // 24 hours
    
    return $headers;
}

/**
 * Apply CORS headers to response
 */
function health_tracker_apply_cors_headers($response, $origin = null) {
    $cors_headers = health_tracker_get_cors_headers($origin);
    
    foreach ($cors_headers as $header => $value) {
        $response->header($header, $value);
    }
    
    return $response;
}

/**
 * Database table names
 */
function health_tracker_get_table_names() {
    global $wpdb;
    
    return array(
        'submissions' => $wpdb->prefix . 'health_tracker_submissions',
        'sessions' => $wpdb->prefix . 'health_tracker_sessions', 
        'users' => $wpdb->prefix . 'health_tracker_users',
        'verification_codes' => $wpdb->prefix . 'health_tracker_verification_codes',
        'rate_limits' => $wpdb->prefix . 'health_tracker_rate_limits',
        'security_log' => $wpdb->prefix . 'health_tracker_security_log'
    );
}

/**
 * Validation rules for API data
 */
function health_tracker_get_validation_rules() {
    return array(
        'email' => array(
            'required' => true,
            'type' => 'email',
            'max_length' => 254
        ),
        'verification_code' => array(
            'required' => true,
            'type' => 'numeric',
            'length' => 6
        ),
        'name' => array(
            'required' => false,
            'type' => 'string',
            'max_length' => 100
        ),
        'age' => array(
            'required' => false,
            'type' => 'integer',
            'min' => 0,
            'max' => 150
        ),
        'gender' => array(
            'required' => false,
            'type' => 'string',
            'allowed_values' => array('male', 'female', 'other')
        ),
        'form_type' => array(
            'required' => true,
            'type' => 'string',
            'allowed_values' => array('health', 'longevity')
        ),
        'form_data' => array(
            'required' => true,
            'type' => 'json'
        ),
        'calculated_metrics' => array(
            'required' => false,
            'type' => 'json'
        )
    );
}

/**
 * Error codes and messages
 */
function health_tracker_get_error_codes() {
    return array(
        'invalid_email' => array(
            'code' => 'INVALID_EMAIL',
            'message' => 'Invalid email address format',
            'http_status' => 400
        ),
        'invalid_code' => array(
            'code' => 'INVALID_CODE', 
            'message' => 'Invalid verification code',
            'http_status' => 401
        ),
        'expired_code' => array(
            'code' => 'EXPIRED_CODE',
            'message' => 'Verification code has expired',
            'http_status' => 401
        ),
        'rate_limit_exceeded' => array(
            'code' => 'RATE_LIMIT_EXCEEDED',
            'message' => 'Too many requests. Please try again later.',
            'http_status' => 429
        ),
        'unauthorized' => array(
            'code' => 'UNAUTHORIZED',
            'message' => 'Authentication required',
            'http_status' => 401
        ),
        'forbidden' => array(
            'code' => 'FORBIDDEN',
            'message' => 'Access denied',
            'http_status' => 403
        ),
        'not_found' => array(
            'code' => 'NOT_FOUND',
            'message' => 'Resource not found',
            'http_status' => 404
        ),
        'internal_error' => array(
            'code' => 'INTERNAL_ERROR',
            'message' => 'Internal server error',
            'http_status' => 500
        )
    );
}

/**
 * Success response format
 */
function health_tracker_success_response($data = null, $message = 'Success', $status_code = 200) {
    $response = array(
        'success' => true,
        'message' => $message,
        'timestamp' => current_time('mysql'),
        'api_version' => HEALTH_TRACKER_API_VERSION
    );
    
    if ($data !== null) {
        $response['data'] = $data;
    }
    
    return new WP_REST_Response($response, $status_code);
}

/**
 * Error response format
 */
function health_tracker_error_response($error_code, $details = null, $status_code = null) {
    $error_codes = health_tracker_get_error_codes();
    $error_info = $error_codes[$error_code] ?? $error_codes['internal_error'];
    
    $response = array(
        'success' => false,
        'error' => array(
            'code' => $error_info['code'],
            'message' => $error_info['message']
        ),
        'timestamp' => current_time('mysql'),
        'api_version' => HEALTH_TRACKER_API_VERSION
    );
    
    if ($details !== null) {
        $response['error']['details'] = $details;
    }
    
    $http_status = $status_code ?? $error_info['http_status'];
    
    return new WP_REST_Response($response, $http_status);
}