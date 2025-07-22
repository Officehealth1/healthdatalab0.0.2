<?php
/**
 * Email Debug Helper for Health Tracker
 * Diagnoses and fixes email sending issues
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit('Direct access forbidden.');
}

class HealthTrackerEmailDebug {
    
    /**
     * Test WordPress email configuration
     */
    public static function test_wp_mail_config() {
        $results = array(
            'wp_mail_function' => function_exists('wp_mail'),
            'php_mail_function' => function_exists('mail'),
            'admin_email' => get_option('admin_email'),
            'blogname' => get_option('blogname'),
            'site_url' => get_site_url(),
            'smtp_config' => array(),
            'recent_errors' => array()
        );
        
        // Check if SMTP is configured
        if (defined('SMTP_HOST')) {
            $results['smtp_config']['host'] = SMTP_HOST;
        }
        if (defined('SMTP_PORT')) {
            $results['smtp_config']['port'] = SMTP_PORT;
        }
        if (defined('SMTP_USERNAME')) {
            $results['smtp_config']['username'] = SMTP_USERNAME;
        }
        
        // Check for common email plugins
        $email_plugins = array(
            'wp-mail-smtp/wp_mail_smtp.php',
            'easy-wp-smtp/easy-wp-smtp.php',
            'post-smtp/postman-smtp.php'
        );
        
        foreach ($email_plugins as $plugin) {
            if (is_plugin_active($plugin)) {
                $results['smtp_config']['plugin'] = $plugin;
                break;
            }
        }
        
        return $results;
    }
    
    /**
     * Send test email with detailed logging
     */
    public static function send_test_email($to_email, $test_code = null) {
        $test_code = $test_code ?: sprintf('%06d', mt_rand(100000, 999999));
        
        // Enhanced email content
        $subject = '[Health Tracker] Test Verification Code';
        $message = "This is a test email from Health Tracker.\n\n";
        $message .= "Your test verification code is: {$test_code}\n\n";
        $message .= "Sent at: " . current_time('mysql') . "\n";
        $message .= "Site: " . get_site_url() . "\n\n";
        $message .= "If you received this email, the email system is working correctly.";
        
        // Set headers
        $headers = array(
            'Content-Type: text/plain; charset=UTF-8',
            'From: ' . get_option('blogname') . ' <' . get_option('admin_email') . '>',
            'Reply-To: ' . get_option('admin_email')
        );
        
        // Log before sending
        error_log("Health Tracker Email Debug: Attempting to send test email to {$to_email}");
        error_log("Health Tracker Email Debug: Subject: {$subject}");
        error_log("Health Tracker Email Debug: Headers: " . print_r($headers, true));
        
        // Add email logging
        add_action('wp_mail_failed', array('HealthTrackerEmailDebug', 'log_email_error'));
        
        // Send email
        $sent = wp_mail($to_email, $subject, $message, $headers);
        
        // Log result
        if ($sent) {
            error_log("Health Tracker Email Debug: Test email sent successfully to {$to_email}");
            return array(
                'success' => true,
                'message' => 'Test email sent successfully',
                'test_code' => $test_code,
                'sent_at' => current_time('mysql')
            );
        } else {
            error_log("Health Tracker Email Debug: Failed to send test email to {$to_email}");
            return array(
                'success' => false,
                'message' => 'Failed to send test email',
                'config' => self::test_wp_mail_config()
            );
        }
    }
    
    /**
     * Log email errors
     */
    public static function log_email_error($wp_error) {
        error_log("Health Tracker Email Error: " . $wp_error->get_error_message());
        error_log("Health Tracker Email Error Data: " . print_r($wp_error->get_error_data(), true));
    }
    
    /**
     * Enhanced verification code email with fallback
     */
    public static function send_verification_code($email, $verification_code) {
        // Try multiple email methods
        $methods = array(
            'wp_mail' => array('HealthTrackerEmailDebug', 'send_via_wp_mail'),
            'php_mail' => array('HealthTrackerEmailDebug', 'send_via_php_mail'),
            'curl_smtp' => array('HealthTrackerEmailDebug', 'send_via_curl_smtp')
        );
        
        foreach ($methods as $method_name => $method_callback) {
            error_log("Health Tracker Email: Trying method {$method_name} for {$email}");
            
            $result = call_user_func($method_callback, $email, $verification_code);
            
            if ($result['success']) {
                error_log("Health Tracker Email: Successfully sent via {$method_name} to {$email}");
                return $result;
            } else {
                error_log("Health Tracker Email: Method {$method_name} failed: " . $result['error']);
            }
        }
        
        // All methods failed
        error_log("Health Tracker Email: All email methods failed for {$email}");
        return array(
            'success' => false,
            'error' => 'All email delivery methods failed',
            'methods_tried' => array_keys($methods)
        );
    }
    
    /**
     * Send via WordPress wp_mail
     */
    public static function send_via_wp_mail($email, $verification_code) {
        try {
            $subject = '[Health Tracker] Your Verification Code';
            $message = self::get_email_template($verification_code);
            
            $headers = array(
                'Content-Type: text/html; charset=UTF-8',
                'From: ' . get_option('blogname') . ' Health Tracker <' . get_option('admin_email') . '>'
            );
            
            $sent = wp_mail($email, $subject, $message, $headers);
            
            if ($sent) {
                return array('success' => true, 'method' => 'wp_mail');
            } else {
                return array('success' => false, 'error' => 'wp_mail returned false');
            }
        } catch (Exception $e) {
            return array('success' => false, 'error' => 'wp_mail exception: ' . $e->getMessage());
        }
    }
    
    /**
     * Send via PHP mail function
     */
    public static function send_via_php_mail($email, $verification_code) {
        try {
            if (!function_exists('mail')) {
                return array('success' => false, 'error' => 'PHP mail function not available');
            }
            
            $subject = '[Health Tracker] Your Verification Code';
            $message = self::get_email_template($verification_code);
            
            $headers = "MIME-Version: 1.0\r\n";
            $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
            $headers .= "From: " . get_option('blogname') . " Health Tracker <" . get_option('admin_email') . ">\r\n";
            $headers .= "Reply-To: " . get_option('admin_email') . "\r\n";
            
            $sent = mail($email, $subject, $message, $headers);
            
            if ($sent) {
                return array('success' => true, 'method' => 'php_mail');
            } else {
                return array('success' => false, 'error' => 'PHP mail function returned false');
            }
        } catch (Exception $e) {
            return array('success' => false, 'error' => 'PHP mail exception: ' . $e->getMessage());
        }
    }
    
    /**
     * Send via CURL SMTP (fallback)
     */
    public static function send_via_curl_smtp($email, $verification_code) {
        // This is a placeholder for external SMTP service
        // You can implement SendGrid, Mailgun, etc. here
        return array('success' => false, 'error' => 'External SMTP not configured');
    }
    
    /**
     * Get email template
     */
    private static function get_email_template($verification_code) {
        $site_name = get_option('blogname');
        $site_url = get_site_url();
        
        return "
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: #007AFF; color: white; padding: 20px; text-align: center; }
                .content { background: #f9f9f9; padding: 30px; }
                .code { background: #007AFF; color: white; font-size: 24px; font-weight: bold; padding: 15px; text-align: center; margin: 20px 0; border-radius: 5px; letter-spacing: 2px; }
                .footer { background: #eee; padding: 20px; text-align: center; font-size: 12px; color: #666; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>{$site_name}</h1>
                    <p>Health Tracker Verification</p>
                </div>
                <div class='content'>
                    <h2>Your Verification Code</h2>
                    <p>Please use the following code to complete your login:</p>
                    <div class='code'>{$verification_code}</div>
                    <p><strong>This code will expire in 15 minutes.</strong></p>
                    <p>If you didn't request this code, please ignore this email.</p>
                </div>
                <div class='footer'>
                    <p>Sent from {$site_name} | {$site_url}</p>
                    <p>Sent at " . current_time('mysql') . "</p>
                </div>
            </div>
        </body>
        </html>";
    }
}

// AJAX endpoint for email debugging
add_action('wp_ajax_mobile_health_debug_email', 'handle_mobile_health_debug_email');
add_action('wp_ajax_nopriv_mobile_health_debug_email', 'handle_mobile_health_debug_email');

function handle_mobile_health_debug_email() {
    if (empty($_POST['email'])) {
        wp_send_json_error('Email is required');
        return;
    }
    
    $email = sanitize_email($_POST['email']);
    $action = sanitize_text_field($_POST['debug_action'] ?? 'test');
    
    switch ($action) {
        case 'test':
            $result = HealthTrackerEmailDebug::send_test_email($email);
            break;
        case 'config':
            $result = HealthTrackerEmailDebug::test_wp_mail_config();
            break;
        case 'verify':
            $test_code = sprintf('%06d', mt_rand(100000, 999999));
            $result = HealthTrackerEmailDebug::send_verification_code($email, $test_code);
            break;
        default:
            wp_send_json_error('Invalid debug action');
            return;
    }
    
    wp_send_json_success($result);
}