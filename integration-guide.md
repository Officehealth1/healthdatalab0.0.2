# Health Tracker Mobile API - Integration Guide

## Overview
This implementation provides a complete REST API system that integrates seamlessly with your existing AJAX-based authentication. Users can now access your Health Tracker through both the existing web interface and the new mobile REST API.

## Files Created

### 1. Core API Files
- `health-tracker-mobile-api-complete.php` - Main plugin file
- `health-tracker-rest-api.php` - REST API endpoints
- `api-config.php` - Configuration and constants
- `api-security.php` - Security utilities
- `mobile-auth.php` - Authentication integration
- `database-migration.php` - Database management

### 2. Existing Files (Enhanced)
- `wordpress-ajax-handlers-safe.php` - Your existing AJAX handlers (preserved)

## Key Features

### ✅ Dual Authentication System
- **AJAX System**: Your existing email verification continues to work
- **REST API**: New mobile-friendly endpoints with JWT-style tokens
- **Seamless Integration**: Both systems share the same database tables

### ✅ Database Compatibility
- All existing data preserved
- New columns added automatically
- Migration system handles schema updates
- Backward compatibility maintained

### ✅ Security Features
- Rate limiting per IP and per email
- JWT-style token validation
- CORS headers for cross-origin requests
- Security event logging
- Input validation and sanitization

### ✅ Mobile App Ready
- RESTful endpoints for React Native
- JSON responses
- Token-based authentication
- Offline sync capabilities

## API Endpoints

### Base URL
```
https://healthdatalab.net/wp-json/health-tracker/v1/
```

### Authentication Endpoints

#### Request Verification Code
```http
POST /auth/request-code
Content-Type: application/json

{
  "email": "user@example.com"
}
```

**Response:**
```json
{
  "success": true,
  "message": "Verification code sent successfully",
  "data": {
    "email": "user@example.com"
  }
}
```

#### Verify Code & Login
```http
POST /auth/verify-code
Content-Type: application/json

{
  "email": "user@example.com",
  "code": "123456"
}
```

**Response:**
```json
{
  "success": true,
  "message": "Authentication successful",
  "data": {
    "access_token": "abc123...",
    "refresh_token": "xyz789...",
    "user_hash": "hash...",
    "expires_at": "2024-01-01 12:00:00",
    "user_email": "user@example.com"
  }
}
```

### Protected Endpoints (Require Authentication)

#### Submit Health Data
```http
POST /health-data/submit
Authorization: Bearer {access_token}
X-User-Email: user@example.com
Content-Type: application/json

{
  "form_type": "health",
  "name": "John Doe",
  "age": 30,
  "gender": "male",
  "form_data": {
    "question1": "answer1",
    "question2": "answer2"
  },
  "calculated_metrics": {
    "score": 85,
    "category": "good"
  }
}
```

#### Get User History
```http
GET /health-data/history?limit=10&offset=0
Authorization: Bearer {access_token}
X-User-Email: user@example.com
```

#### Get Latest Assessment
```http
GET /health-data/latest
Authorization: Bearer {access_token}
X-User-Email: user@example.com
```

## Mobile App Integration

### React Native Service Update

Your `SyncService.js` can now use both systems. Here's the recommended approach:

```javascript
class SyncService {
  constructor() {
    this.baseURL = 'https://healthdatalab.net/wp-json/health-tracker/v1';
    this.accessToken = null;
    this.userEmail = null;
  }

  // Use REST API for authentication
  async requestVerificationCode(email) {
    const response = await fetch(`${this.baseURL}/auth/request-code`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
      },
      body: JSON.stringify({ email })
    });
    return response.json();
  }

  async verifyCode(email, code) {
    const response = await fetch(`${this.baseURL}/auth/verify-code`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
      },
      body: JSON.stringify({ email, code })
    });
    
    const result = await response.json();
    if (result.success) {
      this.accessToken = result.data.access_token;
      this.userEmail = result.data.user_email;
      // Store tokens securely
      await this.storeTokens(result.data);
    }
    return result;
  }

  // Use REST API for data submission
  async submitHealthData(data) {
    const response = await fetch(`${this.baseURL}/health-data/submit`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Authorization': `Bearer ${this.accessToken}`,
        'X-User-Email': this.userEmail
      },
      body: JSON.stringify(data)
    });
    return response.json();
  }

  async getUserHistory(limit = 50, offset = 0) {
    const response = await fetch(
      `${this.baseURL}/health-data/history?limit=${limit}&offset=${offset}`,
      {
        headers: {
          'Authorization': `Bearer ${this.accessToken}`,
          'X-User-Email': this.userEmail
        }
      }
    );
    return response.json();
  }

  async getLatestData() {
    const response = await fetch(`${this.baseURL}/health-data/latest`, {
      headers: {
        'Authorization': `Bearer ${this.accessToken}`,
        'X-User-Email': this.userEmail
      }
    });
    return response.json();
  }

  // Token management
  async storeTokens(tokenData) {
    await AsyncStorage.setItem('@health_tracker_access_token', tokenData.access_token);
    await AsyncStorage.setItem('@health_tracker_refresh_token', tokenData.refresh_token);
    await AsyncStorage.setItem('@health_tracker_user_email', tokenData.user_email);
    await AsyncStorage.setItem('@health_tracker_expires_at', tokenData.expires_at);
  }

  async loadTokens() {
    this.accessToken = await AsyncStorage.getItem('@health_tracker_access_token');
    this.userEmail = await AsyncStorage.getItem('@health_tracker_user_email');
    // Check if token is expired and refresh if needed
  }
}

export default SyncService;
```

## Installation Steps

### 1. Upload Files
Upload all the created files to your WordPress site:
```
/wp-content/plugins/health-tracker-mobile-api/
├── health-tracker-mobile-api-complete.php
├── health-tracker-rest-api.php
├── api-config.php
├── api-security.php
├── mobile-auth.php
├── database-migration.php
└── wordpress-ajax-handlers-safe.php (your existing file)
```

### 2. Activate Plugin
- Go to WordPress Admin → Plugins
- Find "Health Tracker Mobile API Complete"
- Click "Activate"

### 3. Verify Installation
- Go to Settings → Health Tracker API
- Check that all database tables show "✅ Exists"
- Verify API endpoints are accessible

### 4. Security Configuration (Production)
Add to your `wp-config.php`:
```php
// JWT Secret (generate a secure random string)
define('HEALTH_TRACKER_JWT_SECRET', 'your-secure-jwt-secret-key-here');

// Encryption Key (generate a secure random string)
define('HEALTH_TRACKER_ENCRYPTION_KEY', 'your-secure-encryption-key-here');

// Enable HTTPS requirement in production
define('HEALTH_TRACKER_REQUIRE_HTTPS', true);
```

## Testing the API

### 1. Health Check
```bash
curl https://healthdatalab.net/wp-json/health-tracker/v1/health
```

### 2. Request Code
```bash
curl -X POST https://healthdatalab.net/wp-json/health-tracker/v1/auth/request-code \
  -H "Content-Type: application/json" \
  -d '{"email":"test@example.com"}'
```

### 3. Verify Code
```bash
curl -X POST https://healthdatalab.net/wp-json/health-tracker/v1/auth/verify-code \
  -H "Content-Type: application/json" \
  -d '{"email":"test@example.com","code":"123456"}'
```

## Backward Compatibility

### ✅ Existing AJAX Calls Continue to Work
Your existing JavaScript/jQuery AJAX calls remain functional:
```javascript
// This still works exactly as before
jQuery.ajax({
    url: '/wp-admin/admin-ajax.php',
    type: 'POST',
    data: {
        action: 'mobile_health_request_code',
        email: email
    },
    success: function(response) {
        // Handle response
    }
});
```

### ✅ Database Schema Preserved
- All existing data remains untouched
- New columns added automatically
- No data migration required

## Monitoring & Maintenance

### Admin Dashboard
Access the admin panel at **Settings → Health Tracker API** to:
- Monitor database health
- View security logs
- Check API status
- Repair database issues
- Clean up old data

### Automatic Cleanup
The system automatically:
- Expires old verification codes (15 minutes)
- Deactivates expired sessions
- Cleans security logs (30 days)
- Removes old rate limit data (24 hours)

## Troubleshooting

### Common Issues

1. **404 Error on API Endpoints**
   - Go to Settings → Permalinks
   - Click "Save Changes" to flush rewrite rules

2. **CORS Errors**
   - Check allowed origins in `api-config.php`
   - Verify your domain is included

3. **Authentication Failures**
   - Check if tokens are being sent correctly
   - Verify email header is included
   - Check session expiry

4. **Database Issues**
   - Go to Settings → Health Tracker API → Database tab
   - Click "Repair Database Issues" if problems are found

### Support
- Check WordPress error logs
- Enable debug mode: `define('WP_DEBUG', true);`
- Review security logs in admin panel

## Migration Benefits

### ✅ Zero Downtime
- Existing system continues working
- New API available immediately
- Gradual migration possible

### ✅ Enhanced Security
- Rate limiting prevents abuse
- Token-based authentication
- Security event logging
- Input validation

### ✅ Scalability
- RESTful architecture
- Mobile-optimized
- Offline sync capabilities
- Efficient data transfer

## Next Steps

1. **Test the API** with the provided curl commands
2. **Update your mobile app** to use the new REST endpoints
3. **Monitor the admin dashboard** for any issues
4. **Configure production security** settings
5. **Gradually migrate** from AJAX to REST API calls

Your existing AJAX handlers will continue to work, so you can migrate at your own pace without breaking existing functionality.