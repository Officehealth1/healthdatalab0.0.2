<?php
/**
 * Health Tracker Secure REST API
 * User-specific data access with comprehensive security measures
 * Version: 2.0 - Production Security Grade
 */

// Security: Prevent direct access
if (!defined('ABSPATH')) {
    exit('Direct access forbidden.');
}

// Include secure authentication
require_once plugin_dir_path(__FILE__) . 'mobile-auth-secure.php';

/**
 * Initialize secure REST API routes
 */
add_action('rest_api_init', 'health_tracker_register_secure_api_routes');

function health_tracker_register_secure_api_routes() {
    $namespace = 'health-tracker-mobile/v1';
    
    // Authentication endpoints (public)
    register_rest_route($namespace, '/auth/request-code', array(
        'methods' => 'POST',
        'callback' => 'health_tracker_secure_request_code',
        'permission_callback' => '__return_true',
        'args' => array(
            'email' => array(
                'required' => true,
                'type' => 'string',
                'format' => 'email',
                'sanitize_callback' => 'sanitize_email',
                'validate_callback' => 'is_email'
            )
        )
    ));
    
    register_rest_route($namespace, '/auth/verify-code', array(
        'methods' => 'POST',
        'callback' => 'health_tracker_secure_verify_code',
        'permission_callback' => '__return_true',
        'args' => array(
            'email' => array(
                'required' => true,
                'type' => 'string',
                'format' => 'email',
                'sanitize_callback' => 'sanitize_email'
            ),
            'code' => array(
                'required' => true,
                'type' => 'string',
                'pattern' => '^\d{6}$',
                'sanitize_callback' => 'sanitize_text_field'
            )
        )
    ));
    
    // Secure user data endpoints (require authentication)
    register_rest_route($namespace, '/user/sync', array(
        'methods' => 'GET',
        'callback' => 'health_tracker_secure_sync_user_data',
        'permission_callback' => 'health_tracker_validate_token_secure',
        'args' => array(
            'since' => array(
                'type' => 'string',
                'format' => 'date',
                'default' => date('Y-m-d', strtotime('-1 year')),
                'sanitize_callback' => 'sanitize_text_field'
            ),
            'types' => array(
                'type' => 'array',
                'items' => array(
                    'type' => 'string',
                    'enum' => array('health', 'longevity')
                ),
                'default' => array('health', 'longevity')
            ),
            'limit' => array(
                'type' => 'integer',
                'default' => 100,
                'minimum' => 1,
                'maximum' => 200
            )
        )
    ));
    
    register_rest_route($namespace, '/user/profile', array(
        'methods' => 'GET',
        'callback' => 'health_tracker_secure_get_user_profile',
        'permission_callback' => 'health_tracker_validate_token_secure'
    ));
    
    register_rest_route($namespace, '/assessments', array(
        'methods' => 'GET',
        'callback' => 'health_tracker_secure_get_assessments',
        'permission_callback' => 'health_tracker_validate_token_secure',
        'args' => array(
            'page' => array(
                'type' => 'integer',
                'default' => 1,
                'minimum' => 1
            ),
            'per_page' => array(
                'type' => 'integer',
                'default' => 20,
                'minimum' => 1,
                'maximum' => 100
            ),
            'type' => array(
                'type' => 'string',
                'enum' => array('health', 'longevity'),
                'sanitize_callback' => 'sanitize_text_field'
            )
        )
    ));
    
    register_rest_route($namespace, '/assessments/(?P<id>\d+)', array(
        'methods' => 'GET',
        'callback' => 'health_tracker_secure_get_assessment',
        'permission_callback' => 'health_tracker_validate_token_secure',
        'args' => array(
            'id' => array(
                'required' => true,
                'type' => 'integer',
                'minimum' => 1
            )
        )
    ));
    
    register_rest_route($namespace, '/assessments/(?P<id>\d+)', array(
        'methods' => 'DELETE',
        'callback' => 'health_tracker_secure_delete_assessment',
        'permission_callback' => 'health_tracker_validate_token_secure',
        'args' => array(
            'id' => array(
                'required' => true,
                'type' => 'integer',
                'minimum' => 1
            )
        )
    ));
    
    register_rest_route($namespace, '/auth/logout', array(
        'methods' => 'POST',
        'callback' => 'health_tracker_secure_logout',
        'permission_callback' => 'health_tracker_validate_token_secure'
    ));
    
    register_rest_route($namespace, '/health', array(
        'methods' => 'GET',
        'callback' => 'health_tracker_secure_health_check',
        'permission_callback' => '__return_true'
    ));
}

/**
 * Secure request verification code
 */
function health_tracker_secure_request_code($request) {
    $email = $request->get_param('email');
    
    // Use existing AJAX handler internally
    $_POST['email'] = $email;
    ob_start();
    handle_mobile_health_request_code();
    $response = ob_get_clean();
    
    $data = json_decode($response, true);
    
    if ($data && $data['success']) {
        return new WP_REST_Response(array(
            'status' => 'success',
            'message' => 'Verification code sent successfully',
            'data' => array(
                'email' => $email,
                'expires_in' => 15 * 60
            )
        ), 200);
    } else {
        return new WP_REST_Response(array(
            'status' => 'error',
            'message' => $data['data'] ?? 'Failed to send verification code'
        ), 400);
    }
}

/**
 * Secure verify code and generate JWT tokens
 */
function health_tracker_secure_verify_code($request) {
    $email = $request->get_param('email');
    $code = $request->get_param('code');
    
    // Use existing AJAX handler internally
    $_POST['email'] = $email;
    $_POST['code'] = $code;
    ob_start();
    handle_mobile_health_verify_code();
    $response = ob_get_clean();
    
    $data = json_decode($response, true);
    
    if ($data && $data['success']) {
        return new WP_REST_Response(array(
            'status' => 'success',
            'message' => 'Authentication successful',
            'data' => $data['data']
        ), 200);
    } else {
        return new WP_REST_Response(array(
            'status' => 'error',
            'message' => $data['data'] ?? 'Verification failed'
        ), 401);
    }
}

/**
 * SECURITY CRITICAL: Secure user data sync - Only user's own data
 */
function health_tracker_secure_sync_user_data($request) {
    $user_email = $request->get_header('X-User-Email');
    
    if (!$user_email || !is_email($user_email)) {
        return new WP_REST_Response(array(
            'status' => 'error',
            'message' => 'Invalid user authentication'
        ), 401);
    }
    
    global $wpdb;
    $auth = new HealthTrackerMobileAuth();
    
    // Get parameters
    $since_date = $request->get_param('since');
    $form_types = $request->get_param('types');
    $limit = $request->get_param('limit');
    
    // Ensure form_types is an array
    if (!is_array($form_types)) {
        $form_types = array($form_types);
    }
    
    // Validate form types
    $allowed_types = array('health', 'longevity');
    $form_types = array_intersect($form_types, $allowed_types);
    
    if (empty($form_types)) {
        $form_types = $allowed_types;
    }
    
    $submissions_table = $wpdb->prefix . 'health_tracker_submissions';
    
    // Build secure query - ONLY for authenticated user
    $placeholders = array_fill(0, count($form_types), '%s');
    $where_types = implode(',', $placeholders);
    
    $query_params = array($user_email);
    $query_params = array_merge($query_params, $form_types);
    $query_params[] = $since_date;
    $query_params[] = $limit;
    
    // SECURITY: Get ONLY user's own assessments
    $assessments = $wpdb->get_results($wpdb->prepare(
        "SELECT 
            id,
            form_type,
            submission_date,
            overall_health_score,
            bmi,
            whr,
            biological_age,
            age_shift,
            lifestyle_score,
            form_data_json,
            calculated_metrics_json,
            submission_timestamp,
            created_at,
            updated_at
        FROM $submissions_table 
        WHERE email_hash = %s 
        AND form_type IN ($where_types)
        AND submission_date > %s
        ORDER BY submission_date DESC
        LIMIT %d",
        $query_params
    ));
    
    // Process data securely
    $sync_data = array(
        'assessments' => array(),
        'last_sync' => current_time('c'),
        'count' => 0,
        'user_email' => $user_email
    );
    
    foreach ($assessments as $assessment) {
        // SECURITY: Double-check each assessment belongs to authenticated user
        if ($assessment->id) {
            $owner_check = $wpdb->get_var($wpdb->prepare(
                "SELECT email_hash FROM $submissions_table WHERE id = %d",
                $assessment->id
            ));
            
            if ($owner_check !== $user_email) {
                // Log security violation
                $auth->log_data_access($user_email, 'security_violation', 'assessment', $assessment->id, 'Attempted access to foreign data');
                continue; // Skip this assessment
            }
        }
        
        $form_data = json_decode($assessment->form_data_json, true);
        $calculated_metrics = json_decode($assessment->calculated_metrics_json, true);
        
        // Remove sensitive data before sending
        if (isset($form_data['practitionersEmail'])) {
            unset($form_data['practitionersEmail']);
        }
        if (isset($form_data['personalInfo']['email'])) {
            unset($form_data['personalInfo']['email']);
        }
        
        $sync_data['assessments'][] = array(
            'id' => $assessment->id,
            'type' => $assessment->form_type,
            'date' => $assessment->submission_date,
            'created_at' => $assessment->created_at,
            'updated_at' => $assessment->updated_at,
            'scores' => array(
                'overall' => $assessment->overall_health_score,
                'bmi' => $assessment->bmi,
                'whr' => $assessment->whr,
                'biological_age' => $assessment->biological_age,
                'age_shift' => $assessment->age_shift,
                'lifestyle' => $assessment->lifestyle_score
            ),
            'data' => $form_data,
            'metrics' => $calculated_metrics
        );
    }
    
    $sync_data['count'] = count($sync_data['assessments']);
    
    // Log access
    $auth->log_data_access($user_email, 'data_sync', 'assessments', null, array('count' => $sync_data['count']));
    
    return new WP_REST_Response(array(
        'status' => 'success',
        'data' => $sync_data
    ), 200);
}

/**
 * SECURITY CRITICAL: Get user profile - Only authenticated user's profile
 */
function health_tracker_secure_get_user_profile($request) {
    $user_email = $request->get_header('X-User-Email');
    
    if (!$user_email || !is_email($user_email)) {
        return new WP_REST_Response(array(
            'status' => 'error',
            'message' => 'Invalid user authentication'
        ), 401);
    }
    
    global $wpdb;
    $auth = new HealthTrackerMobileAuth();
    
    $submissions_table = $wpdb->prefix . 'health_tracker_submissions';
    
    // SECURITY: Get statistics for authenticated user ONLY
    $stats = $wpdb->get_row($wpdb->prepare(
        "SELECT 
            COUNT(*) as total_assessments,
            COUNT(CASE WHEN form_type = 'health' THEN 1 END) as health_count,
            COUNT(CASE WHEN form_type = 'longevity' THEN 1 END) as longevity_count,
            MAX(submission_date) as last_assessment,
            AVG(overall_health_score) as avg_health_score,
            MIN(submission_date) as first_assessment
        FROM $submissions_table 
        WHERE email_hash = %s",
        $user_email
    ));
    
    // Log access
    $auth->log_data_access($user_email, 'profile_access', 'user_profile', null, array('stats_retrieved' => true));
    
    return new WP_REST_Response(array(
        'status' => 'success',
        'data' => array(
            'email' => $user_email,
            'stats' => array(
                'total_assessments' => (int)$stats->total_assessments,
                'health_assessments' => (int)$stats->health_count,
                'longevity_assessments' => (int)$stats->longevity_count,
                'last_assessment' => $stats->last_assessment,
                'first_assessment' => $stats->first_assessment,
                'average_health_score' => round($stats->avg_health_score, 1)
            )
        )
    ), 200);
}

/**
 * SECURITY CRITICAL: Get assessments with pagination - Only user's own data
 */
function health_tracker_secure_get_assessments($request) {
    $user_email = $request->get_header('X-User-Email');
    
    if (!$user_email || !is_email($user_email)) {
        return new WP_REST_Response(array(
            'status' => 'error',
            'message' => 'Invalid user authentication'
        ), 401);
    }
    
    global $wpdb;
    $auth = new HealthTrackerMobileAuth();
    
    $page = $request->get_param('page');
    $per_page = $request->get_param('per_page');
    $type_filter = $request->get_param('type');
    $offset = ($page - 1) * $per_page;
    
    $submissions_table = $wpdb->prefix . 'health_tracker_submissions';
    
    // Build WHERE clause
    $where_conditions = array("email_hash = %s");
    $query_params = array($user_email);
    
    if ($type_filter) {
        $where_conditions[] = "form_type = %s";
        $query_params[] = $type_filter;
    }
    
    $where_clause = implode(' AND ', $where_conditions);
    
    // Get total count for pagination
    $total_params = $query_params;
    $total = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $submissions_table WHERE $where_clause",
        $total_params
    ));
    
    // Get assessments
    $query_params[] = $per_page;
    $query_params[] = $offset;
    
    $assessments = $wpdb->get_results($wpdb->prepare(
        "SELECT 
            id, 
            form_type, 
            submission_date, 
            overall_health_score,
            bmi,
            whr,
            biological_age,
            age_shift,
            lifestyle_score,
            created_at,
            updated_at
        FROM $submissions_table 
        WHERE $where_clause
        ORDER BY submission_date DESC 
        LIMIT %d OFFSET %d",
        $query_params
    ));
    
    // Process assessments
    $processed_assessments = array();
    foreach ($assessments as $assessment) {
        $processed_assessments[] = array(
            'id' => $assessment->id,
            'form_type' => $assessment->form_type,
            'submission_date' => $assessment->submission_date,
            'created_at' => $assessment->created_at,
            'updated_at' => $assessment->updated_at,
            'scores' => array(
                'overall' => $assessment->overall_health_score,
                'bmi' => $assessment->bmi,
                'whr' => $assessment->whr,
                'biological_age' => $assessment->biological_age,
                'age_shift' => $assessment->age_shift,
                'lifestyle' => $assessment->lifestyle_score
            )
        );
    }
    
    // Log access
    $auth->log_data_access($user_email, 'assessments_list', 'assessments', null, array('count' => count($processed_assessments), 'page' => $page));
    
    return new WP_REST_Response(array(
        'status' => 'success',
        'data' => array(
            'assessments' => $processed_assessments,
            'pagination' => array(
                'total' => (int) $total,
                'pages' => ceil($total / $per_page),
                'current_page' => $page,
                'per_page' => $per_page
            )
        )
    ), 200);
}

/**
 * SECURITY CRITICAL: Get single assessment with ownership verification
 */
function health_tracker_secure_get_assessment($request) {
    $id = $request->get_param('id');
    $user_email = $request->get_header('X-User-Email');
    
    if (!$user_email || !is_email($user_email)) {
        return new WP_REST_Response(array(
            'status' => 'error',
            'message' => 'Invalid user authentication'
        ), 401);
    }
    
    global $wpdb;
    $auth = new HealthTrackerMobileAuth();
    
    $submissions_table = $wpdb->prefix . 'health_tracker_submissions';
    
    // SECURITY: Verify ownership before returning data
    $assessment = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $submissions_table 
        WHERE id = %d 
        AND email_hash = %s",
        $id,
        $user_email
    ));
    
    if (!$assessment) {
        // Log unauthorized access attempt
        $auth->log_data_access($user_email, 'unauthorized_access', 'assessment', $id, 'Attempted access to non-owned assessment');
        
        return new WP_REST_Response(array(
            'status' => 'error',
            'message' => 'Assessment not found'
        ), 404);
    }
    
    $form_data = json_decode($assessment->form_data_json, true);
    $calculated_metrics = json_decode($assessment->calculated_metrics_json, true);
    
    // Remove sensitive fields
    if (isset($form_data['practitionersEmail'])) {
        unset($form_data['practitionersEmail']);
    }
    if (isset($form_data['personalInfo']['email'])) {
        unset($form_data['personalInfo']['email']);
    }
    
    // Log access
    $auth->log_data_access($user_email, 'assessment_view', 'assessment', $id, null);
    
    return new WP_REST_Response(array(
        'status' => 'success',
        'data' => array(
            'id' => $assessment->id,
            'form_type' => $assessment->form_type,
            'submission_date' => $assessment->submission_date,
            'created_at' => $assessment->created_at,
            'updated_at' => $assessment->updated_at,
            'form_data' => $form_data,
            'results' => $calculated_metrics,
            'scores' => array(
                'overall_health' => $assessment->overall_health_score,
                'bmi' => $assessment->bmi,
                'whr' => $assessment->whr,
                'biological_age' => $assessment->biological_age,
                'age_shift' => $assessment->age_shift,
                'lifestyle_score' => $assessment->lifestyle_score
            )
        )
    ), 200);
}

/**
 * SECURITY CRITICAL: Delete assessment with ownership verification
 */
function health_tracker_secure_delete_assessment($request) {
    $id = $request->get_param('id');
    $user_email = $request->get_header('X-User-Email');
    
    if (!$user_email || !is_email($user_email)) {
        return new WP_REST_Response(array(
            'status' => 'error',
            'message' => 'Invalid user authentication'
        ), 401);
    }
    
    global $wpdb;
    $auth = new HealthTrackerMobileAuth();
    
    $submissions_table = $wpdb->prefix . 'health_tracker_submissions';
    
    // SECURITY: Verify ownership before deletion
    $assessment = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $submissions_table 
        WHERE id = %d 
        AND email_hash = %s",
        $id,
        $user_email
    ));
    
    if (!$assessment) {
        // Log unauthorized deletion attempt
        $auth->log_data_access($user_email, 'unauthorized_delete', 'assessment', $id, 'Attempted deletion of non-owned assessment');
        
        return new WP_REST_Response(array(
            'status' => 'error',
            'message' => 'Assessment not found'
        ), 404);
    }
    
    // Delete the assessment
    $deleted = $wpdb->delete(
        $submissions_table,
        array('id' => $id),
        array('%d')
    );
    
    if ($deleted === false) {
        return new WP_REST_Response(array(
            'status' => 'error',
            'message' => 'Failed to delete assessment'
        ), 500);
    }
    
    // Log deletion
    $auth->log_data_access($user_email, 'assessment_deleted', 'assessment', $id, array('form_type' => $assessment->form_type));
    
    return new WP_REST_Response(array(
        'status' => 'success',
        'message' => 'Assessment deleted successfully',
        'data' => array(
            'id' => $id,
            'deleted_at' => current_time('mysql')
        )
    ), 200);
}

/**
 * Secure logout
 */
function health_tracker_secure_logout($request) {
    $user_email = $request->get_header('X-User-Email');
    
    if (!$user_email || !is_email($user_email)) {
        return new WP_REST_Response(array(
            'status' => 'error',
            'message' => 'Invalid user authentication'
        ), 401);
    }
    
    $auth = new HealthTrackerMobileAuth();
    $auth->revoke_tokens($user_email);
    
    return new WP_REST_Response(array(
        'status' => 'success',
        'message' => 'Logged out successfully'
    ), 200);
}

/**
 * API health check
 */
function health_tracker_secure_health_check($request) {
    global $wpdb;
    
    $status = array(
        'status' => 'healthy',
        'timestamp' => current_time('mysql'),
        'api_version' => '2.0',
        'database' => 'connected',
        'authentication' => 'jwt_secure'
    );
    
    // Check database connection
    try {
        $wpdb->get_var("SELECT 1");
    } catch (Exception $e) {
        $status['status'] = 'unhealthy';
        $status['database'] = 'error';
    }
    
    return new WP_REST_Response(array(
        'status' => 'success',
        'data' => $status
    ), 200);
}

/**
 * Add CORS headers for mobile app
 */
add_filter('rest_pre_serve_request', 'health_tracker_secure_add_cors_headers', 15, 4);

function health_tracker_secure_add_cors_headers($served, $result, $request, $server) {
    $origin = $request->get_header('Origin');
    
    if (strpos($request->get_route(), '/health-tracker-mobile/v1/') === 0) {
        $allowed_origins = array(
            'http://localhost:8081',
            'http://localhost:19006',
            'https://healthdatalab.net',
            'https://www.healthdatalab.net'
        );
        
        if (in_array($origin, $allowed_origins)) {
            header("Access-Control-Allow-Origin: {$origin}");
        } else {
            header("Access-Control-Allow-Origin: *");
        }
        
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-User-Email, X-Requested-With');
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Max-Age: 86400');
        
        // Handle preflight requests
        if ($request->get_method() === 'OPTIONS') {
            exit;
        }
    }
    
    return $served;
}