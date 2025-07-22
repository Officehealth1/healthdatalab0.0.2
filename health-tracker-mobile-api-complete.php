<?php
/**
 * Health Tracker Mobile API - Complete Implementation
 * Main plugin file that initializes all components
 * 
 * Plugin Name: Health Tracker Mobile API Complete
 * Description: Complete REST API and AJAX integration for Health Tracker mobile app
 * Version: 1.0
 * Author: Health Data Lab
 */

// Security: Prevent direct access
if (!defined('ABSPATH')) {
    exit('Direct access forbidden.');
}

// Define plugin constants
define('HEALTH_TRACKER_PLUGIN_VERSION', '1.0');
define('HEALTH_TRACKER_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('HEALTH_TRACKER_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * Main plugin initialization
 */
class HealthTrackerMobileAPI {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_action('init', array($this, 'init'));
        add_action('rest_api_init', array($this, 'init_rest_api'));
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
    }
    
    /**
     * Initialize plugin
     */
    public function init() {
        // Load required files
        $this->load_dependencies();
        
        // Initialize database
        health_tracker_ensure_database_schema();
        
        // Setup AJAX handlers (existing functionality)
        $this->setup_ajax_handlers();
        
        // Add admin menu
        if (is_admin()) {
            add_action('admin_menu', array($this, 'add_admin_menu'));
        }
    }
    
    /**
     * Initialize REST API
     */
    public function init_rest_api() {
        // REST API routes are registered in health-tracker-rest-api.php
        // This is called automatically when rest_api_init action fires
    }
    
    /**
     * Load required files
     */
    private function load_dependencies() {
        // Core files
        require_once HEALTH_TRACKER_PLUGIN_PATH . 'api-config.php';
        require_once HEALTH_TRACKER_PLUGIN_PATH . 'api-security.php';
        require_once HEALTH_TRACKER_PLUGIN_PATH . 'mobile-auth.php';
        require_once HEALTH_TRACKER_PLUGIN_PATH . 'database-migration.php';
        require_once HEALTH_TRACKER_PLUGIN_PATH . 'health-tracker-rest-api.php';
        
        // SECURITY: Load secure authentication and API
        require_once HEALTH_TRACKER_PLUGIN_PATH . 'mobile-auth-secure.php';
        require_once HEALTH_TRACKER_PLUGIN_PATH . 'health-tracker-secure-api.php';
        
        // AJAX handlers (existing functionality)
        if (file_exists(HEALTH_TRACKER_PLUGIN_PATH . 'wordpress-ajax-handlers-safe.php')) {
            require_once HEALTH_TRACKER_PLUGIN_PATH . 'wordpress-ajax-handlers-safe.php';
        }
    }
    
    /**
     * Setup AJAX handlers (for backward compatibility)
     */
    private function setup_ajax_handlers() {
        // AJAX handlers are loaded from wordpress-ajax-handlers-safe.php
        // No additional setup needed as they register themselves
    }
    
    /**
     * Plugin activation
     */
    public function activate() {
        // Initialize database
        health_tracker_activate_database();
        
        // Create security tables
        health_tracker_create_security_tables();
        
        // Flush rewrite rules to ensure REST API endpoints work
        flush_rewrite_rules();
        
        // Log activation
        error_log('Health Tracker Mobile API: Plugin activated');
    }
    
    /**
     * Plugin deactivation
     */
    public function deactivate() {
        // Clear scheduled events
        wp_clear_scheduled_hook('health_tracker_token_cleanup');
        wp_clear_scheduled_hook('health_tracker_security_cleanup');
        wp_clear_scheduled_hook('health_tracker_daily_cleanup');
        
        // Flush rewrite rules
        flush_rewrite_rules();
        
        // Log deactivation
        error_log('Health Tracker Mobile API: Plugin deactivated');
    }
    
    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_options_page(
            'Health Tracker API',
            'Health Tracker API',
            'manage_options',
            'health-tracker-api',
            array($this, 'admin_page')
        );
    }
    
    /**
     * Admin page content
     */
    public function admin_page() {
        if (isset($_POST['action'])) {
            $this->handle_admin_actions();
        }
        
        $health_report = health_tracker_check_database_health();
        $api_config = health_tracker_get_api_config();
        
        ?>
        <div class="wrap">
            <h1>Health Tracker Mobile API</h1>
            
            <div class="notice notice-info">
                <p><strong>API Status:</strong> 
                    <?php echo $health_report['status'] === 'healthy' ? '✅ Healthy' : '❌ Issues Detected'; ?>
                </p>
            </div>
            
            <h2 class="nav-tab-wrapper">
                <a href="#general" class="nav-tab nav-tab-active">General</a>
                <a href="#database" class="nav-tab">Database</a>
                <a href="#security" class="nav-tab">Security</a>
                <a href="#endpoints" class="nav-tab">API Endpoints</a>
            </h2>
            
            <div id="general" class="tab-content">
                <h3>API Information</h3>
                <table class="form-table">
                    <tr>
                        <th>API Version</th>
                        <td><?php echo HEALTH_TRACKER_API_VERSION; ?></td>
                    </tr>
                    <tr>
                        <th>Plugin Version</th>
                        <td><?php echo HEALTH_TRACKER_PLUGIN_VERSION; ?></td>
                    </tr>
                    <tr>
                        <th>REST API Namespace</th>
                        <td><?php echo HEALTH_TRACKER_API_NAMESPACE; ?></td>
                    </tr>
                    <tr>
                        <th>REST API URL</th>
                        <td><?php echo get_rest_url(null, HEALTH_TRACKER_API_NAMESPACE); ?></td>
                    </tr>
                </table>
            </div>
            
            <div id="database" class="tab-content" style="display:none;">
                <h3>Database Status</h3>
                
                <?php if (!empty($health_report['issues'])): ?>
                <div class="notice notice-warning">
                    <p><strong>Issues Found:</strong></p>
                    <ul>
                        <?php foreach ($health_report['issues'] as $issue): ?>
                        <li><?php echo esc_html($issue); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                
                <form method="post">
                    <input type="hidden" name="action" value="repair_database">
                    <?php wp_nonce_field('health_tracker_admin'); ?>
                    <?php submit_button('Repair Database Issues', 'secondary'); ?>
                </form>
                <?php endif; ?>
                
                <table class="widefat">
                    <thead>
                        <tr>
                            <th>Table</th>
                            <th>Status</th>
                            <th>Rows</th>
                            <th>Size (MB)</th>
                            <th>Issues</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($health_report['tables'] as $table_key => $table_info): ?>
                        <tr>
                            <td><?php echo esc_html($table_key); ?></td>
                            <td><?php echo $table_info['exists'] ? '✅ Exists' : '❌ Missing'; ?></td>
                            <td><?php echo number_format($table_info['row_count']); ?></td>
                            <td><?php echo number_format($table_info['size_mb'], 2); ?></td>
                            <td><?php echo empty($table_info['issues']) ? '-' : implode(', ', $table_info['issues']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <div id="security" class="tab-content" style="display:none;">
                <h3>Security Configuration</h3>
                <table class="form-table">
                    <tr>
                        <th>Rate Limiting</th>
                        <td><?php echo HEALTH_TRACKER_RATE_LIMIT_REQUESTS; ?> requests per hour</td>
                    </tr>
                    <tr>
                        <th>Token Expiry</th>
                        <td><?php echo HEALTH_TRACKER_ACCESS_TOKEN_EXPIRY / 3600; ?> hours</td>
                    </tr>
                    <tr>
                        <th>HTTPS Required</th>
                        <td><?php echo $api_config['security']['require_https'] ? 'Yes' : 'No (Development)'; ?></td>
                    </tr>
                    <tr>
                        <th>Allowed Origins</th>
                        <td><?php echo implode(', ', $api_config['allowed_origins']); ?></td>
                    </tr>
                </table>
                
                <form method="post">
                    <input type="hidden" name="action" value="cleanup_security">
                    <?php wp_nonce_field('health_tracker_admin'); ?>
                    <?php submit_button('Clean Security Logs', 'secondary'); ?>
                </form>
            </div>
            
            <div id="endpoints" class="tab-content" style="display:none;">
                <h3>Available API Endpoints</h3>
                <p>Base URL: <code><?php echo get_rest_url(null, HEALTH_TRACKER_API_NAMESPACE); ?></code></p>
                
                <h4>Authentication</h4>
                <ul>
                    <li><strong>POST</strong> /auth/request-code - Request verification code</li>
                    <li><strong>POST</strong> /auth/verify-code - Verify code and authenticate</li>
                    <li><strong>POST</strong> /auth/refresh - Refresh access token</li>
                    <li><strong>POST</strong> /auth/logout - Logout user</li>
                </ul>
                
                <h4>User Management</h4>
                <ul>
                    <li><strong>GET</strong> /user/profile - Get user profile</li>
                    <li><strong>PUT</strong> /user/profile - Update user profile</li>
                </ul>
                
                <h4>Health Data</h4>
                <ul>
                    <li><strong>POST</strong> /health-data/submit - Submit health assessment</li>
                    <li><strong>GET</strong> /health-data/history - Get assessment history</li>
                    <li><strong>GET</strong> /health-data/latest - Get latest assessment</li>
                    <li><strong>POST</strong> /health-data/sync - Sync data between devices</li>
                </ul>
                
                <h4>System</h4>
                <ul>
                    <li><strong>GET</strong> /health - API health check</li>
                </ul>
            </div>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            $('.nav-tab').click(function(e) {
                e.preventDefault();
                var target = $(this).attr('href');
                $('.nav-tab').removeClass('nav-tab-active');
                $(this).addClass('nav-tab-active');
                $('.tab-content').hide();
                $(target).show();
            });
        });
        </script>
        
        <style>
        .tab-content {
            margin-top: 20px;
        }
        .widefat th, .widefat td {
            padding: 8px;
        }
        code {
            background: #f1f1f1;
            padding: 2px 6px;
            border-radius: 3px;
        }
        </style>
        <?php
    }
    
    /**
     * Handle admin actions
     */
    private function handle_admin_actions() {
        if (!wp_verify_nonce($_POST['_wpnonce'], 'health_tracker_admin')) {
            wp_die('Security check failed');
        }
        
        switch ($_POST['action']) {
            case 'repair_database':
                $repair_log = health_tracker_repair_database();
                if (!empty($repair_log)) {
                    echo '<div class="notice notice-success"><p>Database repaired: ' . implode(', ', $repair_log) . '</p></div>';
                } else {
                    echo '<div class="notice notice-info"><p>No repairs needed.</p></div>';
                }
                break;
                
            case 'cleanup_security':
                health_tracker_cleanup_security_logs();
                echo '<div class="notice notice-success"><p>Security logs cleaned up.</p></div>';
                break;
        }
    }
}

// Initialize plugin
HealthTrackerMobileAPI::get_instance();

/**
 * Utility functions for external use
 */

/**
 * Check if user is authenticated via API
 */
function health_tracker_is_api_user_authenticated($email) {
    global $wpdb;
    $sessions_table = health_tracker_get_table_names()['sessions'];
    $email_hash = hash('sha256', $email);
    
    $session = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$sessions_table} 
         WHERE email_hash = %s 
         AND is_active = 1 
         AND expires_at > NOW() 
         LIMIT 1",
        $email_hash
    ));
    
    return $session !== null;
}

/**
 * Get user's latest health data
 */
function health_tracker_get_user_latest_data($email) {
    global $wpdb;
    $submissions_table = health_tracker_get_table_names()['submissions'];
    
    return $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$submissions_table} 
         WHERE email_hash = %s 
         ORDER BY submission_date DESC 
         LIMIT 1",
        $email
    ));
}

/**
 * Send verification code via email
 */
function health_tracker_send_verification_email($email, $code) {
    $subject = 'Your Health Tracker Verification Code';
    $message = "Your verification code is: {$code}\n\nThis code will expire in 15 minutes.";
    
    $headers = array(
        'Content-Type: text/plain; charset=UTF-8',
        'From: ' . HEALTH_TRACKER_EMAIL_FROM_NAME . ' <' . HEALTH_TRACKER_EMAIL_FROM . '>'
    );
    
    return wp_mail($email, $subject, $message, $headers);
}