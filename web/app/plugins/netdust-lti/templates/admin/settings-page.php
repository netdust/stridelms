<div class="wrap">
    <h1>Netdust LTI Settings</h1>

    <?php if (isset($_GET['saved'])): ?>
        <div class="notice notice-success"><p>Platform saved successfully.</p></div>
    <?php endif; ?>

    <?php if (isset($_GET['deleted'])): ?>
        <div class="notice notice-success"><p>Platform deleted successfully.</p></div>
    <?php endif; ?>

    <h2>Your Tool Endpoints</h2>
    <p>Provide these URLs when registering your tool with an LMS platform:</p>
    <table class="form-table">
        <?php
        $adminPage = ntdst_get(\NetdustLTI\Admin\AdminPage::class);
        $endpoints = $adminPage->getToolEndpoints();
        foreach ($endpoints as $name => $url): ?>
            <tr>
                <th><?php echo esc_html(ucwords(str_replace('_', ' ', $name))); ?></th>
                <td><code><?php echo esc_html($url); ?></code></td>
            </tr>
        <?php endforeach; ?>
    </table>

    <h2>Registered Platforms</h2>
    <p><a href="<?php echo esc_url(admin_url('options-general.php?page=netdust-lti&action=add')); ?>" class="button button-primary">Add Platform</a></p>

    <?php $listTable->display(); ?>
</div>
