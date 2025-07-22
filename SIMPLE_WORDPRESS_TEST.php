<?php
/**
 * SIMPLE WordPress Test Handler
 * Copy this EXACT code to WordPress Code Snippets
 * Title: Mobile Health Test
 * Run everywhere: YES
 */

// Test if file loads
error_log('MOBILE HEALTH TEST: PHP file loaded at ' . current_time('mysql'));

// Test AJAX endpoint
add_action('wp_ajax_mobile_health_test', 'simple_mobile_health_test');
add_action('wp_ajax_nopriv_mobile_health_test', 'simple_mobile_health_test');

function simple_mobile_health_test() {
    error_log('MOBILE HEALTH TEST: Test endpoint called successfully!');
    
    wp_send_json_success(array(
        'message' => 'WordPress AJAX is working!',
        'timestamp' => current_time('mysql'),
        'version' => '1.0',
        'post_data' => $_POST,
        'server_info' => array(
            'php_version' => phpversion(),
            'wp_version' => get_bloginfo('version')
        )
    ));
}

// Request verification code
add_action('wp_ajax_mobile_health_request_code', 'simple_request_code');
add_action('wp_ajax_nopriv_mobile_health_request_code', 'simple_request_code');

function simple_request_code() {
    error_log('MOBILE HEALTH: REQUEST CODE CALLED!');
    error_log('MOBILE HEALTH: POST DATA: ' . print_r($_POST, true));
    
    $email = sanitize_email($_POST['email'] ?? '');
    
    if (empty($email)) {
        error_log('MOBILE HEALTH: No email provided');
        wp_send_json_error('Email required');
        return;
    }
    
    // Generate simple code
    $code = sprintf('%06d', mt_rand(100000, 999999));
    
    error_log('MOBILE HEALTH: Generated code ' . $code . ' for email ' . $email);
    
    // Try to send email
    $subject = 'Your Health Tracker Code: ' . $code;
    $message = "Your verification code is: $code\n\nValid for 15 minutes.";
    
    $sent = wp_mail($email, $subject, $message);
    
    error_log('MOBILE HEALTH: Email sent result: ' . ($sent ? 'SUCCESS' : 'FAILED'));
    
    wp_send_json_success(array(
        'message' => 'Code sent',
        'email' => $email,
        'code_for_testing' => $code, // REMOVE IN PRODUCTION
        'email_sent' => $sent,
        'timestamp' => current_time('mysql')
    ));
}

// Log when actions are registered
add_action('init', function() {
    error_log('MOBILE HEALTH: Init action fired, actions should be registered now');
});

// Add debug URL
add_action('init', function() {
    if (isset($_GET['mobile_debug'])) {
        global $wp_filter;
        echo '<h1>Mobile Health Debug</h1>';
        echo '<p>Timestamp: ' . current_time('mysql') . '</p>';
        echo '<p>AJAX URL: ' . admin_url('admin-ajax.php') . '</p>';
        
        $actions = ['mobile_health_test', 'mobile_health_request_code'];
        foreach ($actions as $action) {
            $ajax_registered = isset($wp_filter['wp_ajax_' . $action]);
            $nopriv_registered = isset($wp_filter['wp_ajax_nopriv_' . $action]);
            
            echo "<p>Action: $action</p>";
            echo "<p>&nbsp;&nbsp;wp_ajax_$action: " . ($ajax_registered ? 'REGISTERED' : 'NOT REGISTERED') . "</p>";
            echo "<p>&nbsp;&nbsp;wp_ajax_nopriv_$action: " . ($nopriv_registered ? 'REGISTERED' : 'NOT REGISTERED') . "</p>";
        }
        
        echo '<h2>Test Links</h2>';
        echo '<p><a href="' . admin_url('admin-ajax.php') . '?action=mobile_health_test">Test Endpoint</a></p>';
        
        die();
    }
});

error_log('MOBILE HEALTH TEST: File loaded completely, all actions registered');
?>