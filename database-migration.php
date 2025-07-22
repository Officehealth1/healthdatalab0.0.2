<?php
/**
 * Database Migration and Compatibility Functions
 * Ensures database schema compatibility between AJAX and REST API systems
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit('Direct access forbidden.');
}

/**
 * Check and create all required tables
 */
function health_tracker_ensure_database_schema() {
    global $wpdb;
    
    $charset_collate = $wpdb->get_charset_collate();
    $tables = health_tracker_get_table_names();
    
    // Check each table and create if missing
    foreach ($tables as $table_key => $table_name) {
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name;
        
        if (!$table_exists) {
            health_tracker_create_table($table_key, $table_name, $charset_collate);
        } else {
            health_tracker_update_table_schema($table_key, $table_name);
        }
    }
    
    // Update database version
    update_option('health_tracker_db_version', '1.0');
}

/**
 * Create individual table based on type
 */
function health_tracker_create_table($table_key, $table_name, $charset_collate) {
    global $wpdb;
    
    $sql = '';
    
    switch ($table_key) {
        case 'submissions':
            $sql = "CREATE TABLE {$table_name} (
                id mediumint(9) NOT NULL AUTO_INCREMENT,
                email_hash varchar(64) NOT NULL,
                name_hash varchar(64),
                form_type varchar(20) NOT NULL,
                name varchar(100),
                age int(3),
                gender varchar(10),
                form_data longtext NOT NULL,
                calculated_metrics longtext,
                submission_date datetime DEFAULT CURRENT_TIMESTAMP,
                ip_hash varchar(64),
                user_agent text,
                sync_status varchar(20) DEFAULT 'synced',
                created_at datetime DEFAULT CURRENT_TIMESTAMP,
                updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY email_hash (email_hash),
                KEY submission_date (submission_date),
                KEY form_type (form_type),
                KEY sync_status (sync_status)
            ) {$charset_collate};";
            break;
            
        case 'sessions':
            $sql = "CREATE TABLE {$table_name} (
                id mediumint(9) NOT NULL AUTO_INCREMENT,
                user_hash varchar(64) NOT NULL,
                email_hash varchar(64) NOT NULL,
                access_token varchar(255) NOT NULL,
                refresh_token varchar(255),
                expires_at datetime NOT NULL,
                is_active tinyint(1) DEFAULT 1,
                created_date datetime DEFAULT CURRENT_TIMESTAMP,
                last_accessed datetime DEFAULT CURRENT_TIMESTAMP,
                ip_address varchar(45),
                user_agent text,
                PRIMARY KEY (id),
                UNIQUE KEY user_hash (user_hash),
                UNIQUE KEY access_token (access_token),
                KEY email_hash (email_hash),
                KEY expires_at (expires_at),
                KEY is_active (is_active)
            ) {$charset_collate};";
            break;
            
        case 'users':
            $sql = "CREATE TABLE {$table_name} (
                id mediumint(9) NOT NULL AUTO_INCREMENT,
                email_hash varchar(64) NOT NULL,
                user_timezone varchar(50),
                user_agent varchar(500),
                submission_date datetime DEFAULT CURRENT_TIMESTAMP,
                created_at datetime DEFAULT CURRENT_TIMESTAMP,
                updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY email_hash (email_hash),
                KEY submission_date (submission_date)
            ) {$charset_collate};";
            break;
            
        case 'verification_codes':
            $sql = "CREATE TABLE {$table_name} (
                id mediumint(9) NOT NULL AUTO_INCREMENT,
                email_hash varchar(64) NOT NULL,
                verification_code varchar(10) NOT NULL,
                expires_at datetime NOT NULL,
                is_used tinyint(1) DEFAULT 0,
                created_at datetime DEFAULT CURRENT_TIMESTAMP,
                ip_address varchar(45),
                attempts int(2) DEFAULT 0,
                PRIMARY KEY (id),
                KEY email_hash (email_hash),
                KEY expires_at (expires_at),
                KEY is_used (is_used)
            ) {$charset_collate};";
            break;
            
        case 'rate_limits':
            $sql = "CREATE TABLE {$table_name} (
                id mediumint(9) NOT NULL AUTO_INCREMENT,
                identifier varchar(255) NOT NULL,
                created_at datetime DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY identifier_time (identifier, created_at)
            ) {$charset_collate};";
            break;
            
        case 'security_log':
            $sql = "CREATE TABLE {$table_name} (
                id mediumint(9) NOT NULL AUTO_INCREMENT,
                event_type varchar(50) NOT NULL,
                details text,
                ip_address varchar(45),
                user_agent text,
                created_at datetime DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY event_time (event_type, created_at)
            ) {$charset_collate};";
            break;
    }
    
    if (!empty($sql)) {
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        error_log("Health Tracker: Created table {$table_name}");
    }
}

/**
 * Update existing table schema to match current requirements
 */
function health_tracker_update_table_schema($table_key, $table_name) {
    global $wpdb;
    
    // Get current table structure
    $columns = $wpdb->get_results("DESCRIBE {$table_name}");
    $existing_columns = array();
    foreach ($columns as $column) {
        $existing_columns[] = $column->Field;
    }
    
    $updates = array();
    
    switch ($table_key) {
        case 'submissions':
            // Check for missing columns and add them
            if (!in_array('sync_status', $existing_columns)) {
                $updates[] = "ADD COLUMN sync_status varchar(20) DEFAULT 'synced'";
            }
            if (!in_array('created_at', $existing_columns)) {
                $updates[] = "ADD COLUMN created_at datetime DEFAULT CURRENT_TIMESTAMP";
            }
            if (!in_array('updated_at', $existing_columns)) {
                $updates[] = "ADD COLUMN updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP";
            }
            if (!in_array('ip_hash', $existing_columns)) {
                $updates[] = "ADD COLUMN ip_hash varchar(64)";
            }
            if (!in_array('user_agent', $existing_columns)) {
                $updates[] = "ADD COLUMN user_agent text";
            }
            break;
            
        case 'sessions':
            // Check for missing columns
            if (!in_array('refresh_token', $existing_columns)) {
                $updates[] = "ADD COLUMN refresh_token varchar(255)";
            }
            if (!in_array('last_accessed', $existing_columns)) {
                $updates[] = "ADD COLUMN last_accessed datetime DEFAULT CURRENT_TIMESTAMP";
            }
            if (!in_array('ip_address', $existing_columns)) {
                $updates[] = "ADD COLUMN ip_address varchar(45)";
            }
            if (!in_array('user_agent', $existing_columns)) {
                $updates[] = "ADD COLUMN user_agent text";
            }
            break;
            
        case 'users':
            if (!in_array('created_at', $existing_columns)) {
                $updates[] = "ADD COLUMN created_at datetime DEFAULT CURRENT_TIMESTAMP";
            }
            if (!in_array('updated_at', $existing_columns)) {
                $updates[] = "ADD COLUMN updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP";
            }
            break;
            
        case 'verification_codes':
            if (!in_array('attempts', $existing_columns)) {
                $updates[] = "ADD COLUMN attempts int(2) DEFAULT 0";
            }
            if (!in_array('ip_address', $existing_columns)) {
                $updates[] = "ADD COLUMN ip_address varchar(45)";
            }
            break;
    }
    
    // Apply updates
    if (!empty($updates)) {
        foreach ($updates as $update) {
            $sql = "ALTER TABLE {$table_name} {$update}";
            $result = $wpdb->query($sql);
            if ($result === false) {
                error_log("Health Tracker: Failed to update table {$table_name}: {$update}");
            } else {
                error_log("Health Tracker: Updated table {$table_name}: {$update}");
            }
        }
    }
}

/**
 * Migrate data between old and new schema versions
 */
function health_tracker_migrate_data() {
    global $wpdb;
    
    $current_version = get_option('health_tracker_db_version', '0.0');
    
    if (version_compare($current_version, '1.0', '<')) {
        // Migrate from version 0.x to 1.0
        health_tracker_migrate_to_v1_0();
        update_option('health_tracker_db_version', '1.0');
    }
}

/**
 * Migration to version 1.0
 */
function health_tracker_migrate_to_v1_0() {
    global $wpdb;
    
    $submissions_table = health_tracker_get_table_names()['submissions'];
    
    // Update sync_status for existing records
    $wpdb->query("UPDATE {$submissions_table} SET sync_status = 'synced' WHERE sync_status IS NULL");
    
    // Update created_at for existing records
    $wpdb->query("UPDATE {$submissions_table} SET created_at = submission_date WHERE created_at IS NULL");
    
    // Update updated_at for existing records  
    $wpdb->query("UPDATE {$submissions_table} SET updated_at = submission_date WHERE updated_at IS NULL");
    
    error_log("Health Tracker: Completed migration to v1.0");
}

/**
 * Check database health and integrity
 */
function health_tracker_check_database_health() {
    global $wpdb;
    
    $health_report = array(
        'status' => 'healthy',
        'issues' => array(),
        'tables' => array()
    );
    
    $tables = health_tracker_get_table_names();
    
    foreach ($tables as $table_key => $table_name) {
        $table_status = array(
            'exists' => false,
            'row_count' => 0,
            'size_mb' => 0,
            'issues' => array()
        );
        
        // Check if table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name;
        $table_status['exists'] = $table_exists;
        
        if ($table_exists) {
            // Get row count
            $row_count = $wpdb->get_var("SELECT COUNT(*) FROM {$table_name}");
            $table_status['row_count'] = (int)$row_count;
            
            // Get table size
            $size_query = $wpdb->prepare(
                "SELECT ROUND(((data_length + index_length) / 1024 / 1024), 2) as size_mb 
                 FROM information_schema.TABLES 
                 WHERE table_schema = %s AND table_name = %s",
                DB_NAME,
                $table_name
            );
            $size = $wpdb->get_var($size_query);
            $table_status['size_mb'] = (float)$size;
            
            // Check for orphaned records in sessions table
            if ($table_key === 'sessions') {
                $expired_sessions = $wpdb->get_var(
                    "SELECT COUNT(*) FROM {$table_name} WHERE expires_at < NOW() AND is_active = 1"
                );
                if ($expired_sessions > 0) {
                    $table_status['issues'][] = "Found {$expired_sessions} expired but active sessions";
                }
            }
            
            // Check for old verification codes
            if ($table_key === 'verification_codes') {
                $old_codes = $wpdb->get_var(
                    "SELECT COUNT(*) FROM {$table_name} WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 DAY)"
                );
                if ($old_codes > 100) {
                    $table_status['issues'][] = "Found {$old_codes} old verification codes that should be cleaned up";
                }
            }
        } else {
            $table_status['issues'][] = "Table does not exist";
            $health_report['status'] = 'unhealthy';
        }
        
        $health_report['tables'][$table_key] = $table_status;
        
        if (!empty($table_status['issues'])) {
            $health_report['issues'] = array_merge($health_report['issues'], $table_status['issues']);
        }
    }
    
    return $health_report;
}

/**
 * Repair database issues
 */
function health_tracker_repair_database() {
    global $wpdb;
    
    $repair_log = array();
    
    // Clean up expired sessions
    $sessions_table = health_tracker_get_table_names()['sessions'];
    $updated = $wpdb->query(
        "UPDATE {$sessions_table} SET is_active = 0 WHERE expires_at < NOW() AND is_active = 1"
    );
    if ($updated > 0) {
        $repair_log[] = "Deactivated {$updated} expired sessions";
    }
    
    // Clean up old verification codes
    $codes_table = health_tracker_get_table_names()['verification_codes'];
    $deleted = $wpdb->query(
        "DELETE FROM {$codes_table} WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 DAY)"
    );
    if ($deleted > 0) {
        $repair_log[] = "Deleted {$deleted} old verification codes";
    }
    
    // Clean up rate limits
    $rate_table = health_tracker_get_table_names()['rate_limits'];
    $deleted = $wpdb->query(
        "DELETE FROM {$rate_table} WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 DAY)"
    );
    if ($deleted > 0) {
        $repair_log[] = "Deleted {$deleted} old rate limit records";
    }
    
    // Clean up old security logs
    $log_table = health_tracker_get_table_names()['security_log'];
    $deleted = $wpdb->query(
        "DELETE FROM {$log_table} WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)"
    );
    if ($deleted > 0) {
        $repair_log[] = "Deleted {$deleted} old security log entries";
    }
    
    return $repair_log;
}

/**
 * Initialize database on activation
 */
function health_tracker_activate_database() {
    health_tracker_ensure_database_schema();
    health_tracker_migrate_data();
    
    // Schedule daily cleanup
    if (!wp_next_scheduled('health_tracker_daily_cleanup')) {
        wp_schedule_event(time(), 'daily', 'health_tracker_daily_cleanup');
    }
}

/**
 * Daily cleanup task
 */
function health_tracker_daily_cleanup() {
    health_tracker_repair_database();
    health_tracker_cleanup_expired_tokens();
    health_tracker_cleanup_security_logs();
}

// Hook into WordPress
add_action('health_tracker_daily_cleanup', 'health_tracker_daily_cleanup');

// Initialize on plugin activation
register_activation_hook(__FILE__, 'health_tracker_activate_database');