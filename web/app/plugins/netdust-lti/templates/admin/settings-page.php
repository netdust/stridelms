<div class="wrap">
    <h1>Netdust LTI Settings</h1>

    <?php settings_errors('netdust_lti'); ?>

    <h2>Tool Provider Endpoints</h2>
    <p>Provide these URLs when registering your tool with an LMS platform:</p>
    <table class="form-table">
        <?php
        $adminPage = ntdst_get(\NetdustLTI\Admin\AdminPage::class);
        $endpoints = $adminPage->getToolEndpoints();
        foreach ($endpoints as $name => $url): ?>
            <tr>
                <th><?php echo esc_html(ucwords(str_replace('_', ' ', $name))); ?></th>
                <td>
                    <code id="lti-url-<?php echo esc_attr($name); ?>"><?php echo esc_html($url); ?></code>
                    <button type="button" class="button button-small lti-copy-btn" data-target="lti-url-<?php echo esc_attr($name); ?>">Copy</button>
                </td>
            </tr>
        <?php endforeach; ?>
    </table>

    <h2>Configuration</h2>
    <p>
        <a href="<?php echo esc_url(admin_url('edit.php?post_type=lti_platform')); ?>" class="button button-primary">Manage Platforms</a>
        <a href="<?php echo esc_url(admin_url('edit.php?post_type=lti_tool')); ?>" class="button">Manage Tools</a>
        <a href="<?php echo esc_url(admin_url('options-general.php?page=lti-launch-test')); ?>" class="button">Launch Test</a>
    </p>

    <h2>Logs</h2>
    <p>
        <a href="<?php echo esc_url(admin_url('options-general.php?page=netdust-lti&action=logs')); ?>" class="button">View Logs</a>
    </p>
</div>

<script>
document.querySelectorAll('.lti-copy-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var target = document.getElementById(this.dataset.target);
        if (target && navigator.clipboard) {
            navigator.clipboard.writeText(target.textContent).then(function() {
                btn.textContent = 'Copied!';
                setTimeout(function() { btn.textContent = 'Copy'; }, 2000);
            });
        }
    });
});
</script>
