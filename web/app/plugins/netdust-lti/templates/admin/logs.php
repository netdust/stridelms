<div class="wrap">
    <h1>LTI Logs</h1>

    <h2 class="nav-tab-wrapper">
        <a href="?page=netdust-lti&action=logs&tab=launches"
           class="nav-tab <?php echo $tab === 'launches' ? 'nav-tab-active' : ''; ?>">
            Launches
        </a>
        <a href="?page=netdust-lti&action=logs&tab=grades"
           class="nav-tab <?php echo $tab === 'grades' ? 'nav-tab-active' : ''; ?>">
            Grade Passbacks
        </a>
    </h2>

    <p style="margin-top: 15px;">
        <a href="<?php echo esc_url(admin_url('options-general.php?page=netdust-lti')); ?>" class="button">&larr; Back to Settings</a>
    </p>

    <?php if (empty($logs)): ?>
        <p>No log entries found for today.</p>
    <?php else: ?>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th>Time</th>
                    <th>Level</th>
                    <th>Message</th>
                    <th>Context</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($logs as $log): ?>
                    <tr>
                        <td><?php echo esc_html($log['time'] ?? ''); ?></td>
                        <td><?php echo esc_html(strtoupper($log['level'] ?? '')); ?></td>
                        <td><?php echo esc_html($log['message'] ?? ''); ?></td>
                        <td><pre style="margin:0;font-size:11px;"><?php echo esc_html(json_encode($log['context'] ?? [], JSON_PRETTY_PRINT)); ?></pre></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <p><em>Logs are stored in: <?php echo esc_html(WP_CONTENT_DIR . '/logs/'); ?></em></p>
</div>
