<?php
/**
 * Stride Settings — Alpine.js shell template.
 *
 * Loaded by StrideSettingsService::renderSettingsPage().
 * Localized data available as `window.strideSettings`.
 *
 * @package stride
 */

defined('ABSPATH') || exit;

$tabs = [
    'general'       => ['label' => 'Algemeen', 'icon' => 'dashicons-admin-generic'],
    'profile-types' => ['label' => 'Profieltypes', 'icon' => 'dashicons-groups'],
];

$templateDir = __DIR__ . '/settings';
?>

<div class="wrap" x-data="strideSettingsApp()" x-cloak>

    <h1>Stride Instellingen</h1>

    <!-- Status message bar -->
    <div x-show="message" x-transition.opacity
         :class="messageType === 'error' ? 'notice notice-error' : 'notice notice-success'"
         class="stride-settings__message"
         style="display: none;">
        <p x-text="message"></p>
    </div>

    <div class="stride-settings__layout">

        <!-- Left nav sidebar -->
        <nav class="stride-settings__nav">
            <?php foreach ($tabs as $tabKey => $tab): ?>
                <button type="button"
                        class="stride-settings__nav-item"
                        :class="{ 'is-active': activeTab === '<?php echo esc_attr($tabKey); ?>' }"
                        @click="switchTab('<?php echo esc_attr($tabKey); ?>')">
                    <span class="dashicons <?php echo esc_attr($tab['icon']); ?>"></span>
                    <?php echo esc_html($tab['label']); ?>
                </button>
            <?php endforeach; ?>
        </nav>

        <!-- Right content area -->
        <div class="stride-settings__content">

            <!-- Tab: Algemeen -->
            <div x-show="activeTab === 'general'" style="display: none;">
                <?php if (file_exists($templateDir . '/tab-general.php')): ?>
                    <?php include $templateDir . '/tab-general.php'; ?>
                <?php endif; ?>
            </div>

            <!-- Tab: Profieltypes -->
            <div x-show="activeTab === 'profile-types'" style="display: none;">
                <?php if (file_exists($templateDir . '/tab-profile-types.php')): ?>
                    <?php include $templateDir . '/tab-profile-types.php'; ?>
                <?php endif; ?>
            </div>

        </div>

    </div>

</div>
