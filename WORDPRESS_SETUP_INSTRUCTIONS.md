# WordPress Setup Instructions

## Issue: AJAX endpoints returning "0" instead of JSON

The mobile app is getting "0" responses instead of proper JSON from WordPress AJAX endpoints. This is a common WordPress issue that occurs when AJAX handlers aren't properly loaded.

## Root Cause
The file `wordpress-ajax-handlers-safe.php` contains all the AJAX handlers but needs to be properly integrated into your WordPress installation.

## Solutions

### Option 1: Add to WordPress Theme (Recommended)
1. Copy the contents of `wordpress-ajax-handlers-safe.php`
2. Add it to your active theme's `functions.php` file
3. Or create a new file in your theme: `wp-content/themes/your-theme/health-tracker-handlers.php`
4. Include it in functions.php with: `require_once 'health-tracker-handlers.php';`

### Option 2: Create a Plugin
1. Create a new directory: `wp-content/plugins/health-tracker-mobile/`
2. Create `health-tracker-mobile.php` with this header:
```php
<?php
/*
Plugin Name: Health Tracker Mobile API
Description: AJAX handlers for Health Tracker mobile app
Version: 1.0
*/

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Include the handlers
require_once plugin_dir_path(__FILE__) . 'handlers.php';
```
3. Copy `wordpress-ajax-handlers-safe.php` to `handlers.php` in the plugin folder
4. Activate the plugin in WordPress admin

### Option 3: Quick Test
Upload `wordpress-ajax-handlers-safe.php` to your WordPress root directory and add this line to `wp-config.php`:
```php
require_once ABSPATH . 'wordpress-ajax-handlers-safe.php';
```

## Testing
After implementing one of the solutions:

1. Use the "Test Connectivity" button in the mobile app
2. Check the console logs for detailed results
3. The POST request should return JSON instead of "0"

## Expected Response
A successful test should return:
```json
{
  "success": true,
  "data": {
    "message": "Mobile Health Tracker AJAX handlers are working!",
    "database_tables": {...},
    "available_actions": [...]
  }
}
```

## Current Status
- ✅ Mobile app has enhanced error handling
- ✅ Connectivity testing implemented
- ✅ WordPress handlers are complete
- ❌ Handlers need to be integrated into WordPress installation

## Next Steps
1. Choose one of the integration options above
2. Implement the chosen solution
3. Test using the mobile app's "Test Connectivity" button
4. Once working, test the full authentication flow