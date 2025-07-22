<?php
/**
 * Health Tracker Mobile Authentication - SECURE VERSION
 * Enhanced with JWT authentication and email embedding to prevent data leakage
 * Version: 2.0 - Production Security Grade
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit('Direct access forbidden.');
}

class HealthTrackerMobileAuth {
    
    private $jwt_secret;
    private $token_expiry = 86400; // 24 hours
    private $refresh_expiry = 2592000; // 30 days
    
    public function __construct() {
        $this->jwt_secret = defined('HEALTH_TRACKER_JWT_SECRET') 
            ? HEALTH_TRACKER_JWT_SECRET 
            : get_option('health_tracker_jwt_secret', wp_generate_password(64, true, true));
            
        if (!defined('HEALTH_TRACKER_JWT_SECRET') && !get_option('health_tracker_jwt_secret')) {
            update_option('health_tracker_jwt_secret', $this->jwt_secret);
        }
        
        // Create required tables
        $this->create_security_tables();
    }
    
    /**
     * Generate JWT token with user email embedded (SECURITY CRITICAL)
     */
    public function generate_jwt($payload) {
        // SECURITY: Always include email in token
        if (!isset($payload['email'])) {
            throw new Exception('Email must be included in JWT payload');
        }
        
        // Validate email format
        if (!is_email($payload['email'])) {
            throw new Exception('Invalid email format in JWT payload');
        }
        
        // Header
        $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);
        
        // Add security fields to payload
        $payload['iat'] = time();
        $payload['exp'] = time() + $this->token_expiry;
        $payload['jti'] = wp_generate_password(32, false); // Unique token ID
        $payload['iss'] = get_site_url(); // Issuer
        $payload_json = json_encode($payload);
        
        // Base64 encode
        $base64_header = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($header));
        $base64_payload = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($payload_json));
        
        // Create signature
        $signature = hash_hmac('sha256', $base64_header . "." . $base64_payload, $this->jwt_secret, true);
        $base64_signature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));
        
        return $base64_header . "." . $base64_payload . "." . $base64_signature;
    }
    
    /**
     * Verify JWT token and extract email securely (SECURITY CRITICAL)
     */
    public function verify_jwt($token) {
        $token_parts = explode('.', $token);
        
        if (count($token_parts) != 3) {
            return new WP_Error('invalid_token', 'Invalid token format');
        }
        
        list($header, $payload, $signature) = $token_parts;
        
        // Recreate signature
        $valid_signature = hash_hmac('sha256', $header . "." . $payload, $this->jwt_secret, true);
        $valid_base64_signature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($valid_signature));
        
        // Verify signature
        if (!hash_equals($valid_base64_signature, $signature)) {
            return new WP_Error('invalid_signature', 'Invalid token signature');
        }
        
        // Decode payload
        $payload_json = base64_decode(str_replace(['-', '_'], ['+', '/'], $payload));
        $payload_data = json_decode($payload_json, true);
        
        if (!$payload_data) {
            return new WP_Error('invalid_payload', 'Invalid token payload');
        }
        
        // Check expiration
        if ($payload_data['exp'] < time()) {
            return new WP_Error('token_expired', 'Token has expired');
        }
        
        // SECURITY: Ensure email is present and valid
        if (!isset($payload_data['email']) || !is_email($payload_data['email'])) {
            return new WP_Error('invalid_token', 'Token missing valid email');
        }
        
        // Verify issuer
        if (!isset($payload_data['iss']) || $payload_data['iss'] !== get_site_url()) {
            return new WP_Error('invalid_issuer', 'Token issuer mismatch');
        }
        
        return $payload_data;
    }
    
    /**
     * Generate tokens with email verification (SECURITY CRITICAL)
     */
    public function generate_tokens($email) {
        // Validate email
        if (!is_email($email)) {
            return new WP_Error('invalid_email', 'Invalid email format');
        }
        
        // Check if user exists in WordPress users or health tracker users
        $user_exists = $this->verify_user_exists($email);
        if (!$user_exists) {
            return new WP_Error('user_not_found', 'User not found in system');
        }
        
        // Access token payload
        $access_payload = array(
            'email' => $email,
            'type' => 'access',
            'scope' => 'read:own_data write:own_data',
            'user_hash' => hash('sha256', $email)
        );
        
        // Refresh token payload
        $refresh_payload = array(
            'email' => $email,
            'type' => 'refresh',
            'exp' => time() + $this->refresh_expiry,
            'user_hash' => hash('sha256', $email)
        );
        
        $access_token = $this->generate_jwt($access_payload);
        $refresh_token = $this->generate_jwt($refresh_payload);
        
        // Store tokens in database
        $this->store_tokens($email, $access_token, $refresh_token);
        
        // Log authentication for security audit
        $this->log_authentication($email, 'login_success');
        
        return array(
            'access_token' => $access_token,
            'refresh_token' => $refresh_token,
            'user_hash' => hash('sha256', $email),
            'expires_at' => date('Y-m-d H:i:s', time() + $this->token_expiry)
        );
    }
    
    /**
     * Verify user exists in system
     */
    private function verify_user_exists($email) {
        global $wpdb;
        
        // Check in health tracker users table
        $health_user = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}health_tracker_users WHERE email_hash = %s",
            $email
        ));
        
        // Check in health tracker submissions table
        $submission_user = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}health_tracker_submissions WHERE email_hash = %s",
            $email
        ));
        
        // Check in WordPress users table
        $wp_user = get_user_by('email', $email);
        
        return ($health_user > 0 || $submission_user > 0 || $wp_user !== false);
    }
    
    /**
     * Store tokens securely with enhanced tracking
     */
    private function store_tokens($email, $access_token, $refresh_token) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'health_tracker_api_tokens';
        
        // Remove old tokens for this email
        $wpdb->delete($table_name, array('user_email' => $email), array('%s'));
        
        // Store new tokens with enhanced security data
        $wpdb->insert(
            $table_name,
            array(
                'user_email' => $email,
                'access_token' => $access_token,
                'refresh_token' => $refresh_token,
                'token_hash' => hash('sha256', $access_token),
                'user_hash' => hash('sha256', $email),
                'expires_at' => date('Y-m-d H:i:s', time() + $this->token_expiry),
                'refresh_expires_at' => date('Y-m-d H:i:s', time() + $this->refresh_expiry),
                'created_at' => current_time('mysql'),
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Mobile App',
                'is_active' => 1
            ),
            array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d')
        );
    }
    
    /**
     * Create security tables
     */
    private function create_security_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        // API tokens table
        $tokens_table = $wpdb->prefix . 'health_tracker_api_tokens';
        $sql1 = "CREATE TABLE IF NOT EXISTS $tokens_table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            user_email varchar(100) NOT NULL,
            access_token text NOT NULL,
            refresh_token text NOT NULL,
            token_hash varchar(64) NOT NULL,
            user_hash varchar(64) NOT NULL,
            expires_at datetime DEFAULT NULL,
            refresh_expires_at datetime DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            ip_address varchar(45) DEFAULT NULL,
            user_agent varchar(255) DEFAULT NULL,
            is_active tinyint(1) DEFAULT 1,
            PRIMARY KEY (id),
            UNIQUE KEY user_email (user_email),
            KEY token_hash (token_hash),
            KEY user_hash (user_hash),
            KEY expires_at (expires_at),
            KEY is_active (is_active)
        ) $charset_collate;";
        
        // Authentication log table
        $auth_log_table = $wpdb->prefix . 'health_tracker_auth_log';
        $sql2 = "CREATE TABLE IF NOT EXISTS $auth_log_table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            user_email varchar(100) NOT NULL,
            event_type varchar(50) NOT NULL,
            ip_address varchar(45) DEFAULT NULL,
            user_agent varchar(255) DEFAULT NULL,
            success tinyint(1) DEFAULT 1,
            error_message text DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_email (user_email),
            KEY event_type (event_type),
            KEY created_at (created_at),
            KEY success (success)
        ) $charset_collate;";
        
        // Data access log table
        $access_log_table = $wpdb->prefix . 'health_tracker_access_log';
        $sql3 = "CREATE TABLE IF NOT EXISTS $access_log_table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            user_email varchar(100) NOT NULL,
            action varchar(50) NOT NULL,
            resource_type varchar(50) DEFAULT NULL,
            resource_id varchar(50) DEFAULT NULL,
            details text DEFAULT NULL,
            ip_address varchar(45) DEFAULT NULL,
            user_agent varchar(255) DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_email (user_email),
            KEY action (action),
            KEY resource_type (resource_type),
            KEY created_at (created_at)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql1);
        dbDelta($sql2);
        dbDelta($sql3);
    }
    
    /**
     * Log authentication events for security audit
     */
    private function log_authentication($email, $event_type, $success = true, $error_message = null) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'health_tracker_auth_log';
        
        $wpdb->insert(
            $table_name,
            array(
                'user_email' => $email,
                'event_type' => $event_type,
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Mobile App',
                'success' => $success ? 1 : 0,
                'error_message' => $error_message,
                'created_at' => current_time('mysql')
            ),
            array('%s', '%s', '%s', '%s', '%d', '%s', '%s')
        );
    }
    
    /**
     * Log data access for security audit
     */
    public function log_data_access($email, $action, $resource_type = null, $resource_id = null, $details = null) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'health_tracker_access_log';
        
        $wpdb->insert(
            $table_name,
            array(
                'user_email' => $email,
                'action' => $action,
                'resource_type' => $resource_type,
                'resource_id' => $resource_id,
                'details' => is_array($details) ? json_encode($details) : $details,
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Mobile App',
                'created_at' => current_time('mysql')
            ),
            array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s')
        );
    }
    
    /**
     * Revoke user tokens (for logout)
     */
    public function revoke_tokens($email) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'health_tracker_api_tokens';
        
        $wpdb->update(
            $table_name,
            array('is_active' => 0),
            array('user_email' => $email),
            array('%d'),
            array('%s')
        );
        
        $this->log_authentication($email, 'logout_success');
    }
    
    /**
     * Cleanup expired tokens
     */
    public function cleanup_expired_tokens() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'health_tracker_api_tokens';
        
        // Delete expired tokens
        $wpdb->query(
            "DELETE FROM $table_name WHERE expires_at < NOW() OR refresh_expires_at < NOW()"
        );
        
        // Clean old logs (keep 30 days)
        $auth_log_table = $wpdb->prefix . 'health_tracker_auth_log';
        $wpdb->query(
            "DELETE FROM $auth_log_table WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)"
        );
        
        $access_log_table = $wpdb->prefix . 'health_tracker_access_log';
        $wpdb->query(
            "DELETE FROM $access_log_table WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)"
        );
    }
}

/**
 * SECURITY CRITICAL: Enhanced permission callback with strict email verification
 */
function health_tracker_validate_token_secure($request) {
    // Get token from Authorization header
    $auth_header = $request->get_header('Authorization');
    
    if (!$auth_header) {
        return new WP_Error('missing_auth', 'Authorization header missing', array('status' => 401));
    }
    
    // Extract token
    if (strpos($auth_header, 'Bearer ') !== 0) {
        return new WP_Error('invalid_auth', 'Invalid authorization header format', array('status' => 401));
    }
    
    $token = substr($auth_header, 7);
    
    // Validate token
    $auth = new HealthTrackerMobileAuth();
    $payload = $auth->verify_jwt($token);
    
    if (is_wp_error($payload)) {
        // Log failed authentication
        $auth->log_authentication('unknown', 'auth_failed', false, $payload->get_error_message());
        return new WP_Error('invalid_token', $payload->get_error_message(), array('status' => 401));
    }
    
    // SECURITY: Double-check email exists in payload
    if (!isset($payload['email']) || !is_email($payload['email'])) {
        return new WP_Error('invalid_token', 'Invalid token payload', array('status' => 401));
    }
    
    // Verify token is still active in database
    global $wpdb;
    $table_name = $wpdb->prefix . 'health_tracker_api_tokens';
    
    $token_record = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $table_name 
        WHERE user_email = %s 
        AND is_active = 1
        AND expires_at > NOW()",
        $payload['email']
    ));
    
    if (!$token_record) {
        return new WP_Error('token_revoked', 'Token has been revoked or expired', array('status' => 401));
    }
    
    // Add user context to request for use in callbacks
    $request->set_header('X-User-Email', $payload['email']);
    $request->set_header('X-User-Hash', $payload['user_hash']);
    $request->set_header('X-Token-ID', $payload['jti'] ?? '');
    
    // Log successful authentication
    $auth->log_data_access($payload['email'], 'api_access', 'authentication', $request->get_route());
    
    return true;
}

/**
 * Integration with existing AJAX handlers
 */
function health_tracker_generate_secure_tokens($email) {
    $auth = new HealthTrackerMobileAuth();
    return $auth->generate_tokens($email);
}

/**
 * Schedule token cleanup
 */
if (!wp_next_scheduled('health_tracker_token_cleanup_secure')) {
    wp_schedule_event(time(), 'daily', 'health_tracker_token_cleanup_secure');
}

add_action('health_tracker_token_cleanup_secure', function() {
    $auth = new HealthTrackerMobileAuth();
    $auth->cleanup_expired_tokens();
});

// Initialize the authentication system
new HealthTrackerMobileAuth();