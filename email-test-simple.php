<?php
/**
 * Simple email test for Health Tracker
 * Upload this file to your WordPress site and visit it directly
 */

// Load WordPress
require_once('../../../wp-load.php');

// Test email configuration
echo "<h2>WordPress Email Configuration Test</h2>";

// Test 1: Basic WordPress email settings
echo "<h3>1. WordPress Email Settings</h3>";
echo "Admin Email: " . get_option('admin_email') . "<br>";
echo "Site Name: " . get_option('blogname') . "<br>";
echo "Site URL: " . get_site_url() . "<br>";

// Test 2: PHP mail function
echo "<h3>2. PHP Mail Function</h3>";
echo "PHP mail() function: " . (function_exists('mail') ? 'Available' : 'Not available') . "<br>";

// Test 3: wp_mail function
echo "<h3>3. WordPress wp_mail Function</h3>";
echo "wp_mail() function: " . (function_exists('wp_mail') ? 'Available' : 'Not available') . "<br>";

// Test 4: Send test email
echo "<h3>4. Send Test Email</h3>";
$test_email = 'gemmier21@gmail.com';
$subject = 'Health Tracker Email Test';
$message = 'This is a test email from your Health Tracker WordPress site.';

echo "Attempting to send test email to: {$test_email}<br>";

$result = wp_mail($test_email, $subject, $message);

if ($result) {
    echo "<div style='color: green;'>✓ Email sent successfully!</div>";
} else {
    echo "<div style='color: red;'>✗ Email failed to send.</div>";
    
    // Check for errors
    global $wp_mail_error;
    if (isset($wp_mail_error)) {
        echo "<div style='color: red;'>Error: " . $wp_mail_error . "</div>";
    }
}

// Test 5: Direct PHP mail test
echo "<h3>5. Direct PHP Mail Test</h3>";
if (function_exists('mail')) {
    $php_result = mail($test_email, 'Health Tracker PHP Mail Test', 'This is a test using PHP mail() function.');
    if ($php_result) {
        echo "<div style='color: green;'>✓ PHP mail sent successfully!</div>";
    } else {
        echo "<div style='color: red;'>✗ PHP mail failed to send.</div>";
    }
} else {
    echo "<div style='color: red;'>PHP mail() function not available.</div>";
}

// Test 6: Check email plugins
echo "<h3>6. Email Plugin Check</h3>";
$email_plugins = [
    'wp-mail-smtp/wp_mail_smtp.php',
    'easy-wp-smtp/easy-wp-smtp.php',
    'post-smtp/postman-smtp.php'
];

foreach ($email_plugins as $plugin) {
    if (is_plugin_active($plugin)) {
        echo "<div style='color: green;'>✓ Email plugin active: {$plugin}</div>";
    }
}

// Test 7: Server information
echo "<h3>7. Server Information</h3>";
echo "Server: " . $_SERVER['SERVER_SOFTWARE'] . "<br>";
echo "PHP Version: " . PHP_VERSION . "<br>";
echo "WordPress Version: " . get_bloginfo('version') . "<br>";

// Test 8: Check for common email issues
echo "<h3>8. Common Email Issues</h3>";
if (ini_get('sendmail_path')) {
    echo "Sendmail path: " . ini_get('sendmail_path') . "<br>";
} else {
    echo "<div style='color: orange;'>⚠ Sendmail path not configured</div>";
}

if (ini_get('SMTP')) {
    echo "SMTP server: " . ini_get('SMTP') . "<br>";
} else {
    echo "<div style='color: orange;'>⚠ SMTP not configured in PHP</div>";
}

echo "<h3>Recommendations:</h3>";
echo "<ul>";
echo "<li>If emails are not being sent, install and configure the 'WP Mail SMTP' plugin</li>";
echo "<li>Configure proper SMTP settings with your email provider</li>";
echo "<li>Check your hosting provider's email sending policies</li>";
echo "<li>Verify that your domain has proper SPF/DKIM records</li>";
echo "</ul>";
?>