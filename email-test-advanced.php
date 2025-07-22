<?php
/**
 * Advanced email test for Health Tracker
 * Tests the HealthTrackerEmailDebug class directly
 */

// Load WordPress
require_once('../../../wp-load.php');

// Include the email debug helper
require_once('./email-debug-helper.php');

echo "<h2>Health Tracker Email Debug Test</h2>";

// Test 1: Email configuration
echo "<h3>1. Email Configuration</h3>";
$config = HealthTrackerEmailDebug::test_wp_mail_config();
echo "<pre>" . print_r($config, true) . "</pre>";

// Test 2: Send test email
echo "<h3>2. Send Test Email</h3>";
$test_email = 'gemmier21@gmail.com';
$test_result = HealthTrackerEmailDebug::send_test_email($test_email);
echo "<pre>" . print_r($test_result, true) . "</pre>";

// Test 3: Send verification code
echo "<h3>3. Send Verification Code</h3>";
$verification_code = sprintf('%06d', mt_rand(100000, 999999));
$verify_result = HealthTrackerEmailDebug::send_verification_code($test_email, $verification_code);
echo "<pre>" . print_r($verify_result, true) . "</pre>";

// Test 4: WordPress debug log
echo "<h3>4. Recent WordPress Debug Log</h3>";
$log_file = WP_CONTENT_DIR . '/debug.log';
if (file_exists($log_file)) {
    $log_content = file_get_contents($log_file);
    $log_lines = explode("\n", $log_content);
    $recent_lines = array_slice($log_lines, -20); // Last 20 lines
    echo "<pre>" . implode("\n", $recent_lines) . "</pre>";
} else {
    echo "<div style='color: orange;'>Debug log not found or not enabled</div>";
}

// Test 5: Check PHP error log
echo "<h3>5. PHP Error Log</h3>";
$php_error_log = ini_get('error_log');
if ($php_error_log && file_exists($php_error_log)) {
    $php_log_content = file_get_contents($php_error_log);
    $php_log_lines = explode("\n", $php_log_content);
    $php_recent_lines = array_slice($php_log_lines, -10); // Last 10 lines
    echo "<pre>" . implode("\n", $php_recent_lines) . "</pre>";
} else {
    echo "<div style='color: orange;'>PHP error log not found or not configured</div>";
}

// Test 6: Manual wp_mail test with error handling
echo "<h3>6. Manual wp_mail Test with Error Handling</h3>";

// Enable error handling
add_action('wp_mail_failed', function($wp_error) {
    echo "<div style='color: red;'>wp_mail failed: " . $wp_error->get_error_message() . "</div>";
    echo "<div style='color: red;'>Error data: " . print_r($wp_error->get_error_data(), true) . "</div>";
});

$subject = '[Health Tracker] Manual Test Email';
$message = 'This is a manual test email with error handling.';
$headers = [
    'Content-Type: text/html; charset=UTF-8',
    'From: ' . get_option('blogname') . ' <' . get_option('admin_email') . '>'
];

echo "Sending manual test email...<br>";
$manual_result = wp_mail($test_email, $subject, $message, $headers);

if ($manual_result) {
    echo "<div style='color: green;'>✓ Manual wp_mail sent successfully!</div>";
} else {
    echo "<div style='color: red;'>✗ Manual wp_mail failed!</div>";
}

// Test 7: Check if SMTP is configured
echo "<h3>7. SMTP Configuration Check</h3>";
$smtp_options = [
    'SMTP' => ini_get('SMTP'),
    'smtp_port' => ini_get('smtp_port'),
    'sendmail_from' => ini_get('sendmail_from'),
    'sendmail_path' => ini_get('sendmail_path')
];

foreach ($smtp_options as $key => $value) {
    if ($value) {
        echo "<div style='color: green;'>{$key}: {$value}</div>";
    } else {
        echo "<div style='color: orange;'>{$key}: Not configured</div>";
    }
}

echo "<h3>Next Steps:</h3>";
echo "<ul>";
echo "<li>Check the debug logs above for specific error messages</li>";
echo "<li>If 'wp_mail failed' appears, install WP Mail SMTP plugin</li>";
echo "<li>Configure SMTP settings with your email provider (Gmail, SendGrid, etc.)</li>";
echo "<li>Test with different email addresses to rule out spam filters</li>";
echo "</ul>";
?>