<?php

declare(strict_types=1);

namespace Stride\Modules\User;

/**
 * Renders the per-profile-type enrollment-rules UI + the exclude_from_catalog
 * checkbox shared by the Edition and Trajectory admin detail metaboxes (concern 3
 * / plan §7 T9). One row per known profile type (ProfileTypeService::getTypes()),
 * each posting under ntdst_fields[profiletype_rules][<slug>][…] so the shared
 * ProfiletypeRulesSanitizer reads them back. All output is escaped.
 *
 * The RED contract tests POST these field names directly and do not render this
 * UI — it is verified at shake-out — but an admin needs it to set the rules.
 */
final class ProfiletypeRulesMetaboxFields
{
    /**
     * @param array<string, mixed> $rules   the stored profiletype_rules map
     * @param bool                  $exclude the stored exclude_from_catalog flag
     */
    public static function render(array $rules, bool $exclude): void
    {
        /** @var ProfileTypeService $service */
        $service = ntdst_get(ProfileTypeService::class);
        $types = $service->getTypes();
        ?>
        <div class="stride-profiletype-rules">
            <h4><?php esc_html_e('Inschrijvingsregels per profieltype', 'stride'); ?></h4>
            <p class="description">
                <?php esc_html_e('Bepaal per profieltype of het mag inschrijven, het beknopte formulier ziet, en of er automatisch een voucher wordt toegepast. Leeg = iedereen mag inschrijven met het volledige formulier.', 'stride'); ?>
            </p>

            <?php if (empty($types)): ?>
                <p><em><?php esc_html_e('Er zijn nog geen profieltypes gedefinieerd.', 'stride'); ?></em></p>
            <?php else: ?>
                <table class="widefat striped" style="margin-top: 8px;">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Profieltype', 'stride'); ?></th>
                            <th style="width: 90px;"><?php esc_html_e('Blokkeren', 'stride'); ?></th>
                            <th style="width: 130px;"><?php esc_html_e('Beknopt formulier', 'stride'); ?></th>
                            <th><?php esc_html_e('Voucher (code)', 'stride'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($types as $type): ?>
                            <?php
                            $slug = is_array($type) ? (string) ($type['slug'] ?? '') : '';
                            if ($slug === '') {
                                continue;
                            }
                            $label   = is_array($type) ? (string) ($type['label'] ?? $slug) : $slug;
                            $rule    = is_array($rules[$slug] ?? null) ? $rules[$slug] : [];
                            $block   = !empty($rule['block']);
                            $minimal = !empty($rule['minimal']);
                            $voucher = (string) ($rule['voucher'] ?? '');
                            $base    = 'ntdst_fields[profiletype_rules][' . $slug . ']';
                            ?>
                            <tr>
                                <td><strong><?php echo esc_html($label); ?></strong></td>
                                <td style="text-align: center;">
                                    <input type="checkbox"
                                        name="<?php echo esc_attr($base . '[block]'); ?>"
                                        value="1" <?php checked($block); ?> />
                                </td>
                                <td style="text-align: center;">
                                    <input type="checkbox"
                                        name="<?php echo esc_attr($base . '[minimal]'); ?>"
                                        value="1" <?php checked($minimal); ?> />
                                </td>
                                <td>
                                    <input type="text" class="regular-text"
                                        name="<?php echo esc_attr($base . '[voucher]'); ?>"
                                        value="<?php echo esc_attr($voucher); ?>"
                                        placeholder="<?php esc_attr_e('bv. VRIJ10', 'stride'); ?>" />
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <p style="margin-top: 16px;">
                <label>
                    <input type="checkbox"
                        name="ntdst_fields[exclude_from_catalog]"
                        value="1" <?php checked($exclude); ?> />
                    <?php esc_html_e('Verberg uit de publieke catalogus (blijft bereikbaar via directe link)', 'stride'); ?>
                </label>
            </p>
        </div>
        <?php
    }
}
