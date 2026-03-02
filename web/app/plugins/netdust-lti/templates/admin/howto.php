<?php defined('ABSPATH') || exit; ?>

<h2>Quick Start</h2>

<ol>
    <li>
        <strong>Verify Keys</strong> — Go to the <em>Dashboard</em> tab and check the RSA Keys status card.
        It should show "Active". If it shows "Missing", deactivate and reactivate the plugin to regenerate keys.
    </li>
    <li>
        <strong>Register a Platform</strong> — Go to the <em>Platforms</em> tab, click "Add Platform",
        and enter the credentials from your LMS: issuer URL, client ID, auth endpoint, token endpoint, and JWKS URL.
    </li>
    <li>
        <strong>Test a Launch</strong> — Have the external LMS initiate a launch to your OIDC Login URL.
        Check the <em>Logs</em> tab for launch events and any errors.
    </li>
</ol>

<div class="lti-callout lti-callout-tip">
    <p><strong>Tip:</strong> Use the JSON or XML config URLs to auto-configure compatible LMS platforms.
    Most modern LMS platforms support JSON auto-configuration.</p>
</div>

<h2>Registering as a Tool Provider</h2>

<p>
    When an external LMS (Canvas, Moodle, Brightspace, etc.) needs to launch content hosted on this site,
    it needs your Tool Provider endpoint URLs. Share the URLs below with the LMS administrator, or use
    the auto-configuration URLs for platforms that support it.
</p>

<table>
    <thead>
        <tr>
            <th>Endpoint</th>
            <th>URL</th>
            <th>Description</th>
            <th></th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td><strong>OIDC Login</strong></td>
            <td><code class="lti-endpoint-url" x-text="LtiConfig.toolEndpoints.oidc_login"></code></td>
            <td>Initial authentication redirect</td>
            <td>
                <button class="lti-copy-btn" :class="{ 'lti-copied': copied === 'doc_oidc' }"
                        @click="copyToClipboard(LtiConfig.toolEndpoints.oidc_login, 'doc_oidc')"
                        x-text="copied === 'doc_oidc' ? 'Copied!' : 'Copy'"></button>
            </td>
        </tr>
        <tr>
            <td><strong>Launch</strong></td>
            <td><code class="lti-endpoint-url" x-text="LtiConfig.toolEndpoints.launch"></code></td>
            <td>Resource link launch handler</td>
            <td>
                <button class="lti-copy-btn" :class="{ 'lti-copied': copied === 'doc_launch' }"
                        @click="copyToClipboard(LtiConfig.toolEndpoints.launch, 'doc_launch')"
                        x-text="copied === 'doc_launch' ? 'Copied!' : 'Copy'"></button>
            </td>
        </tr>
        <tr>
            <td><strong>JWKS</strong></td>
            <td><code class="lti-endpoint-url" x-text="LtiConfig.toolEndpoints.jwks"></code></td>
            <td>Public key set for signature verification</td>
            <td>
                <button class="lti-copy-btn" :class="{ 'lti-copied': copied === 'doc_jwks' }"
                        @click="copyToClipboard(LtiConfig.toolEndpoints.jwks, 'doc_jwks')"
                        x-text="copied === 'doc_jwks' ? 'Copied!' : 'Copy'"></button>
            </td>
        </tr>
        <tr>
            <td><strong>Deep Link</strong></td>
            <td><code class="lti-endpoint-url" x-text="LtiConfig.toolEndpoints.deep_link"></code></td>
            <td>Content item selection flow</td>
            <td>
                <button class="lti-copy-btn" :class="{ 'lti-copied': copied === 'doc_deeplink' }"
                        @click="copyToClipboard(LtiConfig.toolEndpoints.deep_link, 'doc_deeplink')"
                        x-text="copied === 'doc_deeplink' ? 'Copied!' : 'Copy'"></button>
            </td>
        </tr>
        <tr>
            <td><strong>JSON Config</strong></td>
            <td><code class="lti-endpoint-url" x-text="LtiConfig.toolEndpoints.json_config"></code></td>
            <td>Auto-configuration document (IMS spec)</td>
            <td>
                <button class="lti-copy-btn" :class="{ 'lti-copied': copied === 'doc_json' }"
                        @click="copyToClipboard(LtiConfig.toolEndpoints.json_config, 'doc_json')"
                        x-text="copied === 'doc_json' ? 'Copied!' : 'Copy'"></button>
            </td>
        </tr>
        <tr>
            <td><strong>XML Config</strong></td>
            <td><code class="lti-endpoint-url" x-text="LtiConfig.toolEndpoints.xml_config"></code></td>
            <td>XML configuration document</td>
            <td>
                <button class="lti-copy-btn" :class="{ 'lti-copied': copied === 'doc_xml' }"
                        @click="copyToClipboard(LtiConfig.toolEndpoints.xml_config, 'doc_xml')"
                        x-text="copied === 'doc_xml' ? 'Copied!' : 'Copy'"></button>
            </td>
        </tr>
        <tr>
            <td><strong>Dynamic Registration</strong></td>
            <td><code class="lti-endpoint-url" x-text="LtiConfig.toolEndpoints.dynamic_registration"></code></td>
            <td>Auto-registration flow (IMS spec)</td>
            <td>
                <button class="lti-copy-btn" :class="{ 'lti-copied': copied === 'doc_dynreg' }"
                        @click="copyToClipboard(LtiConfig.toolEndpoints.dynamic_registration, 'doc_dynreg')"
                        x-text="copied === 'doc_dynreg' ? 'Copied!' : 'Copy'"></button>
            </td>
        </tr>
    </tbody>
</table>

<h2>Adding External Tools</h2>

<p>
    When this site needs to launch content from an external tool provider (e.g., Articulate Storyline hosting,
    SCORM Cloud, H5P), configure it in the Tools tab.
</p>

<ol>
    <li>Get the tool's <strong>OIDC URL</strong>, <strong>Launch URL</strong>, <strong>JWKS URL</strong>, and <strong>Client ID</strong> from the tool provider.</li>
    <li>Go to the <em>Tools</em> tab and click <strong>"Add Tool"</strong>.</li>
    <li>Fill in the credentials and endpoint URLs.</li>
    <li>After saving, use the <strong>"Test Launch"</strong> button on the tool row to verify the connection.</li>
</ol>

<div class="lti-callout lti-callout-info">
    <p><strong>Note:</strong> After adding a tool, create a <em>Resource</em> on the Resources tab to link the tool
    to a specific LearnDash course. Resources define what content gets launched and which course receives completion data.</p>
</div>

<h2>Endpoint Reference</h2>

<h3>As Tool Provider</h3>

<p>Use these endpoints when an external LMS launches content hosted on this WordPress site.</p>

<table>
    <thead>
        <tr><th>Endpoint</th><th>Path</th><th>Description</th></tr>
    </thead>
    <tbody>
        <tr><td>OIDC Login</td><td><code>/lti/login</code></td><td>Receives the OIDC initiation request from the platform and redirects to the platform's auth endpoint.</td></tr>
        <tr><td>Launch</td><td><code>/lti/launch</code></td><td>Validates the ID token, provisions the user, enrolls them in the course, and redirects to the content.</td></tr>
        <tr><td>JWKS</td><td><code>/lti/jwks</code></td><td>Returns the public key set (JSON Web Key Set) for the platform to verify our signatures.</td></tr>
        <tr><td>Deep Link</td><td><code>/lti/deep-link</code></td><td>Handles LtiDeepLinkingRequest messages and presents a content picker to the instructor.</td></tr>
        <tr><td>JSON Config</td><td><code>/lti/configure-json</code></td><td>Returns an IMS-spec JSON configuration document for auto-registering with a platform.</td></tr>
        <tr><td>XML Config</td><td><code>/lti/configure-xml</code></td><td>Returns an XML configuration document for platforms that require the older format.</td></tr>
        <tr><td>Dynamic Reg.</td><td><code>/lti/register</code></td><td>Handles the IMS Dynamic Registration flow. The platform sends its OpenID config and receives tool registration.</td></tr>
    </tbody>
</table>

<h3>As Platform</h3>

<p>Use these endpoints when this WordPress site launches content from an external tool.</p>

<table>
    <thead>
        <tr><th>Endpoint</th><th>Path</th><th>Description</th></tr>
    </thead>
    <tbody>
        <tr><td>Issuer</td><td><code>/</code> (site URL)</td><td>The issuer identifier for this platform (your site URL).</td></tr>
        <tr><td>Auth Endpoint</td><td><code>/lti/platform/auth</code></td><td>The tool redirects learners here for OIDC authentication.</td></tr>
        <tr><td>JWKS URL</td><td><code>/lti/jwks</code></td><td>Shared key set — tools verify our launch tokens against these keys.</td></tr>
        <tr><td>AGS Endpoint</td><td><code>/lti/platform/grades</code></td><td>Assignment and Grade Services endpoint. Tools post scores back here.</td></tr>
        <tr><td>Deep Link Return</td><td><code>/lti/platform/deep-link-return</code></td><td>Receives the deep link response JWT from the tool after content selection.</td></tr>
    </tbody>
</table>

<h2>Grade Passback</h2>

<p>
    Grade passback sends scores from this WordPress site back to the launching LMS via the
    LTI Assignment and Grade Services (AGS) specification. This happens automatically when
    a learner completes a configured trigger.
</p>

<p>To enable grade passback for a course:</p>

<ol>
    <li>Edit a <strong>LearnDash course</strong> in the WordPress admin.</li>
    <li>Look for the <strong>"LTI Grade Passback"</strong> metabox in the sidebar.</li>
    <li>Enable the triggers you want: <strong>Course Completion</strong>, <strong>Quiz Score</strong>, or <strong>TinCanny Completion</strong>.</li>
    <li>When a learner completes the trigger, scores are automatically sent back to the launching LMS.</li>
</ol>

<div class="lti-callout lti-callout-warning">
    <p><strong>Requirement:</strong> The launching LMS must support AGS (Assignment and Grade Services).
    Grade passback only works for learners who entered via an LTI launch — it needs the
    platform's AGS endpoint URL from the original launch claim.</p>
</div>

<h2>Troubleshooting</h2>

<h3>"Invalid OIDC state" errors</h3>
<p>
    Nonces expire after 10 minutes. Ensure the server clock is synchronized (NTP).
    Also verify that cookies and sessions are working properly — the OIDC state is stored in the session
    and must survive the redirect back from the platform.
</p>

<h3>JWKS verification fails</h3>
<p>
    The platform's JWKS URL must be accessible from this server. Check firewall rules
    and DNS resolution. Try pasting the JWKS URL directly in a browser to verify it returns valid JSON.
    If the URL works in a browser but not from the server, check outbound HTTP rules.
</p>

<h3>Token endpoint errors</h3>
<p>
    The platform's token endpoint must accept our client credentials. Verify that the
    <strong>Client ID</strong> in the platform configuration matches exactly what the platform expects.
    Check the Logs tab for the full error response.
</p>

<h3>Users created with wrong roles</h3>
<p>
    Check the <strong>Role Mapping</strong> fields in the platform configuration (Platforms tab &rarr; Edit).
    The defaults are "administrator" for LTI Instructor and "subscriber" for LTI Learner.
    Adjust these to match your WordPress role structure.
</p>

<h3>Grade passback not working</h3>
<p>
    Verify the <strong>LTI Grade Passback</strong> metabox is enabled on the LearnDash course.
    Check the Logs tab (switch to the "Grade Passbacks" channel) for error details.
    The launching LMS must support AGS and include the AGS claim in the launch token.
</p>

<h3>Deep linking returns empty</h3>
<p>
    The tool must support <code>LtiDeepLinkingRequest</code> message types. Verify the tool's
    OIDC URL accepts this message type. Check the tool provider's documentation for
    supported LTI message types.
</p>
