<?php
/**
 * Quick Email Fix for Health Tracker
 * This file provides quick solutions for email delivery issues
 */

// Load WordPress
require_once('../../../wp-load.php');

// Include email debug helper
require_once('./email-debug-helper.php');

/**
 * Enhanced email sending with multiple fallback methods
 */
class HealthTrackerEmailQuickFix {
    
    public static function send_verification_code_with_fallback($email, $verification_code) {
        $methods = [
            'wp_mail_basic' => 'Basic WordPress wp_mail',
            'wp_mail_smtp' => 'WordPress wp_mail with SMTP headers',
            'php_mail' => 'PHP mail() function',
            'curl_gmail' => 'cURL with Gmail API (requires setup)',
            'sendgrid' => 'SendGrid API (requires API key)'
        ];
        
        $errors = [];
        
        foreach ($methods as $method => $description) {
            echo "<h3>Trying method: {$description}</h3>";
            
            try {
                $result = self::{"send_via_{$method}"}($email, $verification_code);
                
                if ($result['success']) {
                    echo "<div style='color: green;'>✓ SUCCESS: {$description}</div>";
                    return $result;
                } else {
                    echo "<div style='color: red;'>✗ FAILED: {$description} - {$result['error']}</div>";
                    $errors[] = "{$description}: {$result['error']}";
                }
            } catch (Exception $e) {
                echo "<div style='color: red;'>✗ ERROR: {$description} - {$e->getMessage()}</div>";
                $errors[] = "{$description}: {$e->getMessage()}";
            }
        }
        
        return [
            'success' => false,
            'error' => 'All email methods failed',
            'errors' => $errors
        ];
    }
    
    private static function send_via_wp_mail_basic($email, $verification_code) {
        $subject = '[Health Tracker] Your Verification Code';
        $message = "Your verification code is: {$verification_code}";
        
        $sent = wp_mail($email, $subject, $message);
        
        return [
            'success' => $sent,
            'method' => 'wp_mail_basic',
            'error' => $sent ? null : 'wp_mail returned false'
        ];
    }
    
    private static function send_via_wp_mail_smtp($email, $verification_code) {
        $subject = '[Health Tracker] Your Verification Code';
        $message = "Your verification code is: {$verification_code}";
        
        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . get_option('blogname') . ' <' . get_option('admin_email') . '>',
            'Reply-To: ' . get_option('admin_email')
        ];
        
        $sent = wp_mail($email, $subject, $message, $headers);
        
        return [
            'success' => $sent,
            'method' => 'wp_mail_smtp',
            'error' => $sent ? null : 'wp_mail with SMTP headers returned false'
        ];
    }
    
    private static function send_via_php_mail($email, $verification_code) {
        if (!function_exists('mail')) {
            return [
                'success' => false,
                'error' => 'PHP mail() function not available'
            ];
        }
        
        $subject = '[Health Tracker] Your Verification Code';
        $message = "Your verification code is: {$verification_code}";
        
        $headers = "From: " . get_option('blogname') . " <" . get_option('admin_email') . ">\r\n";
        $headers .= "Reply-To: " . get_option('admin_email') . "\r\n";
        $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
        
        $sent = mail($email, $subject, $message, $headers);
        
        return [
            'success' => $sent,
            'method' => 'php_mail',
            'error' => $sent ? null : 'PHP mail() returned false'
        ];
    }
    
    private static function send_via_curl_gmail($email, $verification_code) {
        // This would require Gmail API setup
        return [
            'success' => false,
            'error' => 'Gmail API not configured - requires OAuth2 setup'
        ];
    }
    
    private static function send_via_sendgrid($email, $verification_code) {
        // This would require SendGrid API key
        return [
            'success' => false,
            'error' => 'SendGrid API not configured - requires API key'
        ];
    }
    
    /**
     * Install WP Mail SMTP plugin programmatically
     */
    public static function install_wp_mail_smtp() {
        if (!current_user_can('install_plugins')) {
            return ['success' => false, 'error' => 'Insufficient permissions'];
        }
        
        include_once(ABSPATH . 'wp-admin/includes/plugin-install.php');
        include_once(ABSPATH . 'wp-admin/includes/file.php');
        include_once(ABSPATH . 'wp-admin/includes/misc.php');
        include_once(ABSPATH . 'wp-admin/includes/class-wp-upgrader.php');
        
        $plugin_slug = 'wp-mail-smtp';
        $plugin_zip = 'wp-mail-smtp.zip';
        
        $api = plugins_api('plugin_information', ['slug' => $plugin_slug]);
        
        if (is_wp_error($api)) {
            return ['success' => false, 'error' => $api->get_error_message()];
        }
        
        $upgrader = new Plugin_Upgrader(new WP_Ajax_Upgrader_Skin());
        $result = $upgrader->install($api->download_link);
        
        if ($result) {
            $activate = activate_plugin('wp-mail-smtp/wp_mail_smtp.php');
            if (!is_wp_error($activate)) {
                return ['success' => true, 'message' => 'WP Mail SMTP installed and activated'];
            }
        }
        
        return ['success' => false, 'error' => 'Failed to install WP Mail SMTP'];
    }
}

// Test the email sending
if (isset($_GET['test'])) {
    $test_email = $_GET['email'] ?? 'gemmier21@gmail.com';
    $test_code = sprintf('%06d', mt_rand(100000, 999999));
    
    echo "<h2>Testing Email Delivery to: {$test_email}</h2>";
    echo "<h3>Verification Code: {$test_code}</h3>";
    
    $result = HealthTrackerEmailQuickFix::send_verification_code_with_fallback($test_email, $test_code);
    
    echo "<h3>Final Result:</h3>";
    echo "<pre>" . print_r($result, true) . "</pre>";
    
    if ($result['success']) {
        echo "<div style='color: green; font-size: 18px; font-weight: bold;'>✓ EMAIL SENT SUCCESSFULLY!</div>";
    } else {
        echo "<div style='color: red; font-size: 18px; font-weight: bold;'>✗ ALL EMAIL METHODS FAILED</div>";
        echo "<h3>Recommended Actions:</h3>";
        echo "<ul>";
        echo "<li>Install WP Mail SMTP plugin (button below)</li>";
        echo "<li>Configure SMTP settings with your email provider</li>";
        echo "<li>Contact your hosting provider about email sending restrictions</li>";
        echo "</ul>";
    }
} else {
    echo "<h2>Health Tracker Email Quick Fix</h2>";
    echo "<p>This tool helps diagnose and fix email delivery issues.</p>";
    echo "<p><a href='?test=1' style='background: #007AFF; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>Test Email Delivery</a></p>";
    echo "<p><a href='?test=1&email=youremail@example.com' style='background: #FF9500; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>Test with Different Email</a></p>";
}

// Install WP Mail SMTP button
if (isset($_GET['install_smtp'])) {
    $install_result = HealthTrackerEmailQuickFix::install_wp_mail_smtp();
    echo "<h3>WP Mail SMTP Installation Result:</h3>";
    echo "<pre>" . print_r($install_result, true) . "</pre>";
}

if (!is_plugin_active('wp-mail-smtp/wp_mail_smtp.php')) {
    echo "<p><a href='?install_smtp=1' style='background: #28A745; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>Install WP Mail SMTP Plugin</a></p>";
} else {
    echo "<p style='color: green;'>✓ WP Mail SMTP plugin is active</p>";
    echo "<p><a href='/wp-admin/admin.php?page=wp-mail-smtp' style='background: #007AFF; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>Configure WP Mail SMTP</a></p>";
}
?>