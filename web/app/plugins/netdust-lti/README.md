# Netdust LTI

LTI 1.3 Tool Provider for LearnDash integration.

## Requirements

- PHP 8.1+
- WordPress 6.0+
- NTDST Core
- LearnDash
- TinCanny (optional)

## Installation

1. Upload to `/wp-content/plugins/netdust-lti/`
2. Run `composer install` in the plugin directory
3. Activate the plugin
4. Go to Settings → Netdust LTI

## Configuration

### Your Tool Endpoints

Configure these in your external LMS:

- **OIDC Login URL:** `https://yoursite.com/lti/login`
- **Launch URL:** `https://yoursite.com/lti/launch`
- **JWKS URL:** `https://yoursite.com/lti/jwks`
- **Deep Link URL:** `https://yoursite.com/lti/deep-link`

### Registering a Platform

1. Go to Settings → Netdust LTI
2. Click "Add Platform"
3. Enter the platform details from your LMS admin:
   - **Name:** Friendly name for the platform
   - **Platform ID (Issuer):** The issuer URL from the LMS
   - **Client ID:** The client ID assigned by the LMS
   - **Deployment ID:** (Optional) Specific deployment identifier
   - **Auth Endpoint:** The LMS's OIDC authentication URL
   - **Token Endpoint:** The LMS's OAuth2 token URL
   - **JWKS Endpoint:** The LMS's public key URL

### Grade Passback

Enable grade passback per course:

1. Edit a LearnDash course
2. In the "LTI Grade Passback" metabox, check desired triggers:
   - Course completed
   - Quiz completed
   - TinCanny module completed
3. Save the course

Grades will be automatically sent to the LMS gradebook when students complete the configured activities.

## LTI Launch Flow

1. Student clicks course link in external LMS
2. LMS sends OIDC login request to `/lti/login`
3. Plugin redirects to LMS authentication
4. LMS sends JWT to `/lti/launch`
5. Plugin validates JWT, creates/finds WordPress user
6. Plugin enrolls user in LearnDash course
7. Student is logged in and redirected to course

## Deep Linking

Instructors can add LearnDash courses to their LMS:

1. In the LMS, initiate "Add Resource" or similar
2. LMS sends deep linking request to plugin
3. Instructor selects a course from the picker
4. Course is added to LMS with proper configuration

## Supported Platforms

Tested with:
- 1EdTech Reference Implementation
- Moodle 4.x
- Canvas LMS

Should work with any LTI 1.3 / LTI Advantage compliant platform.

## Troubleshooting

### View Logs

Check logs at Settings → Netdust LTI → View Logs

- **Launches tab:** OIDC and JWT validation events
- **Grade Passbacks tab:** AGS score posting events

### Common Issues

**"NTDST Core is required"**
- Ensure the NTDST Core mu-plugin is active

**"Platform not found"**
- Verify the Platform ID and Client ID match your LMS configuration

**"Keys not configured"**
- Deactivate and reactivate the plugin to regenerate RSA keys

**Grades not posting**
- Ensure grade passback is enabled on the course
- Check the Grade Passbacks log for errors
- Verify the LMS supports Assignment and Grade Services (AGS)

## Development

### File Structure

```
netdust-lti/
├── src/
│   ├── Plugin.php              # Bootstrap
│   ├── Admin/                  # Admin UI
│   ├── Bridges/                # LearnDash/TinCanny hooks
│   ├── Database/               # Migrations
│   ├── DataConnector/          # celtic/lti storage
│   ├── Domain/                 # Value objects
│   ├── LTI/                    # LTI handlers
│   ├── Repositories/           # Data access
│   └── Services/               # Business logic
├── templates/                  # PHP templates
├── composer.json               # Dependencies
└── netdust-lti.php            # Plugin header
```

### Logging

The plugin logs to:
- `wp-content/logs/lti-YYYY-MM-DD.log` - Launch events
- `wp-content/logs/lti-grade-YYYY-MM-DD.log` - Grade events

Use `WP_DEBUG=true` for debug-level logging.

## License

Proprietary - Netdust
