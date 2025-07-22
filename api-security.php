<?php
/**
 * API Security Functions
 * Security utilities for Health Tracker REST API
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit('Direct access forbidden.');
}

/**
 * Generate secure random token
 */
function health_tracker_generate_token($length = 32) {
    return bin2hex(random_bytes($length));
}

/**
 * Generate JWT-style token (simplified for this use case)
 */
function health_tracker_generate_jwt_token($user_email, $session_id) {
    $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);
    $payload = json_encode([
        'email' => $user_email,
        'session_id' => $session_id,
        'iat' => time(),
        'exp' => time() + (24 * 60 * 60) // 24 hours
    ]);
    
    $base64Header = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($header));
    $base64Payload = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($payload));
    
    $signature = hash_hmac('sha256', $base64Header . "." . $base64Payload, HEALTH_TRACKER_JWT_SECRET, true);
    $base64Signature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));
    
    return $base64Header . "." . $base64Payload . "." . $base64Signature;
}

/**
 * Verify JWT token
 */
function health_tracker_verify_jwt_token($token) {
    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        return false;
    }
    
    list($header, $payload, $signature) = $parts;
    
    // Verify signature
    $expected_signature = hash_hmac('sha256', $header . "." . $payload, HEALTH_TRACKER_JWT_SECRET, true);
    $expected_base64 = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($expected_signature));
    
    if (!hash_equals($expected_base64, $signature)) {
        return false;
    }
    
    // Decode payload
    $payload_data = json_decode(base64_decode(str_replace(['-', '_'], ['+', '/'], $payload)), true);
    
    if (!$payload_data || !isset($payload_data['exp']) || $payload_data['exp'] < time()) {
        return false;
    }
    
    return $payload_data;
}

/**
 * Sanitize API input data
 */
function health_tracker_sanitize_api_data($data) {
    if (is_array($data)) {
        return array_map('health_tracker_sanitize_api_data', $data);
    }
    
    if (is_string($data)) {
        return sanitize_text_field($data);
    }
    
    return $data;
}

/**
 * Validate email format for API
 */
function health_tracker_validate_email($email) {
    return is_email($email) && filter_var($email, FILTER_VALIDATE_EMAIL);
}

/**
 * Rate limiting check
 */
function health_tracker_check_rate_limit($identifier, $limit = 60, $window = 3600) {
    global $wpdb;
    
    $rate_limit_table = $wpdb->prefix . 'health_tracker_rate_limits';
    
    // Clean old entries
    $wpdb->query($wpdb->prepare(
        "DELETE FROM {$rate_limit_table} WHERE created_at < %s",
        date('Y-m-d H:i:s', time() - $window)
    ));
    
    // Count recent requests
    $count = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$rate_limit_table} 
         WHERE identifier = %s AND created_at > %s",
        $identifier,
        date('Y-m-d H:i:s', time() - $window)
    ));
    
    if ($count >= $limit) {
        return false;
    }
    
    // Record this request
    $wpdb->insert(
        $rate_limit_table,
        array(
            'identifier' => $identifier,
            'created_at' => current_time('mysql')
        )
    );
    
    return true;
}

/**
 * Log security events
 */
function health_tracker_log_security_event($event_type, $details = array()) {
    global $wpdb;
    
    $log_table = $wpdb->prefix . 'health_tracker_security_log';
    
    $wpdb->insert(
        $log_table,
        array(
            'event_type' => $event_type,
            'details' => json_encode($details),
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            'created_at' => current_time('mysql')
        )
    );
}

/**
 * Validate request headers
 */
function health_tracker_validate_headers($request) {
    $required_headers = array('User-Agent', 'Accept');
    
    foreach ($required_headers as $header) {
        if (empty($request->get_header($header))) {
            return new WP_Error('missing_header', "Missing required header: {$header}", array('status' => 400));
        }
    }
    
    // Check for suspicious patterns
    $user_agent = $request->get_header('User-Agent');
    $suspicious_patterns = array('bot', 'crawler', 'spider', 'scraper');
    
    foreach ($suspicious_patterns as $pattern) {
        if (stripos($user_agent, $pattern) !== false) {
            health_tracker_log_security_event('suspicious_user_agent', array('user_agent' => $user_agent));
            return new WP_Error('suspicious_request', 'Suspicious request detected', array('status' => 403));
        }
    }
    
    return true;
}

/**
 * Encrypt sensitive data
 */
function health_tracker_encrypt_data($data) {
    if (!defined('HEALTH_TRACKER_ENCRYPTION_KEY')) {
        return $data; // Fallback to plain text if no key defined
    }
    
    $key = HEALTH_TRACKER_ENCRYPTION_KEY;
    $iv = random_bytes(16);
    $encrypted = openssl_encrypt($data, 'AES-256-CBC', $key, 0, $iv);
    
    return base64_encode($iv . $encrypted);
}

/**
 * Decrypt sensitive data
 */
function health_tracker_decrypt_data($encrypted_data) {
    if (!defined('HEALTH_TRACKER_ENCRYPTION_KEY')) {
        return $encrypted_data; // Fallback to assuming plain text
    }
    
    $key = HEALTH_TRACKER_ENCRYPTION_KEY;
    $data = base64_decode($encrypted_data);
    $iv = substr($data, 0, 16);
    $encrypted = substr($data, 16);
    
    return openssl_decrypt($encrypted, 'AES-256-CBC', $key, 0, $iv);
}

/**
 * Create database tables for security features
 */
function health_tracker_create_security_tables() {
    global $wpdb;
    
    $charset_collate = $wpdb->get_charset_collate();
    
    // Rate limiting table
    $rate_limit_table = $wpdb->prefix . 'health_tracker_rate_limits';
    $sql1 = "CREATE TABLE IF NOT EXISTS {$rate_limit_table} (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        identifier varchar(255) NOT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY identifier_time (identifier, created_at)
    ) {$charset_collate};";
    
    // Security log table
    $log_table = $wpdb->prefix . 'health_tracker_security_log';
    $sql2 = "CREATE TABLE IF NOT EXISTS {$log_table} (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        event_type varchar(50) NOT NULL,
        details text,
        ip_address varchar(45),
        user_agent text,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY event_time (event_type, created_at)
    ) {$charset_collate};";
    
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql1);
    dbDelta($sql2);
}

/**
 * Clean up old security logs
 */
function health_tracker_cleanup_security_logs() {
    global $wpdb;
    
    $log_table = $wpdb->prefix . 'health_tracker_security_log';
    $rate_limit_table = $wpdb->prefix . 'health_tracker_rate_limits';
    
    // Keep logs for 30 days
    $wpdb->query(
        "DELETE FROM {$log_table} WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)"
    );
    
    // Keep rate limit data for 24 hours
    $wpdb->query(
        "DELETE FROM {$rate_limit_table} WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 DAY)"
    );
}

// Initialize security tables on activation
add_action('init', 'health_tracker_create_security_tables');

// Schedule cleanup
if (!wp_next_scheduled('health_tracker_security_cleanup')) {
    wp_schedule_event(time(), 'daily', 'health_tracker_security_cleanup');
}
add_action('health_tracker_security_cleanup', 'health_tracker_cleanup_security_logs');