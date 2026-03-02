<?php
/**
 * LTI Dynamic Registration Confirmation
 *
 * @var array  $platform   Platform info (name, platformId, clientId)
 * @var string $confirmUrl URL to confirm registration
 */

if (!defined('ABSPATH')) {
    exit;
}
?>
<!DOCTYPE html>
<html>
<head>
    <title><?php esc_html_e('Confirm LTI Platform Registration', 'netdust-lti'); ?></title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; padding: 40px; max-width: 600px; margin: 0 auto; background: #f0f0f1; }
        .card { background: #fff; padding: 30px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        h1 { font-size: 22px; margin: 0 0 20px; color: #1d2327; }
        .info-table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        .info-table th { text-align: left; padding: 8px 12px; color: #646970; font-weight: 500; width: 40%; }
        .info-table td { padding: 8px 12px; color: #1d2327; word-break: break-all; }
        .info-table tr { border-bottom: 1px solid #f0f0f1; }
        .actions { margin-top: 24px; display: flex; gap: 12px; }
        .btn { padding: 10px 20px; border-radius: 4px; font-size: 14px; cursor: pointer; text-decoration: none; display: inline-block; border: none; }
        .btn-primary { background: #2271b1; color: #fff; }
        .btn-primary:hover { background: #135e96; }
        .btn-secondary { background: #f0f0f1; color: #50575e; border: 1px solid #c3c4c7; }
        .btn-secondary:hover { background: #e0e0e0; }
        .warning { background: #fcf9e8; border-left: 4px solid #dba617; padding: 12px 16px; margin: 16px 0; font-size: 13px; }
    </style>
</head>
<body>
    <div class="card">
        <h1><?php esc_html_e('Confirm Platform Registration', 'netdust-lti'); ?></h1>

        <p><?php esc_html_e('An LTI platform wants to register with this tool. Review the details below:', 'netdust-lti'); ?></p>

        <table class="info-table">
            <tr>
                <th><?php esc_html_e('Platform Name', 'netdust-lti'); ?></th>
                <td><?php echo esc_html($platform['name']); ?></td>
            </tr>
            <tr>
                <th><?php esc_html_e('Platform ID (Issuer)', 'netdust-lti'); ?></th>
                <td><?php echo esc_html($platform['platformId']); ?></td>
            </tr>
            <tr>
                <th><?php esc_html_e('Client ID', 'netdust-lti'); ?></th>
                <td><?php echo esc_html($platform['clientId']); ?></td>
            </tr>
        </table>

        <div class="warning">
            <?php esc_html_e('Only approve registrations from platforms you trust. This will allow the platform to launch LTI content on your site.', 'netdust-lti'); ?>
        </div>

        <div class="actions">
            <form method="post" action="<?php echo esc_url($confirmUrl); ?>">
                <?php wp_nonce_field('netdust_lti_register', '_lti_reg_nonce'); ?>
                <button type="submit" class="btn btn-primary">
                    <?php esc_html_e('Approve Registration', 'netdust-lti'); ?>
                </button>
            </form>
            <a href="<?php echo esc_url(admin_url('options-general.php?page=netdust-lti')); ?>" class="btn btn-secondary">
                <?php esc_html_e('Cancel', 'netdust-lti'); ?>
            </a>
        </div>
    </div>
</body>
</html>
