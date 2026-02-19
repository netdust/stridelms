# Online Course User Flows - Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Complete frontend user flows for online courses - quote management, certificates, and profile editing.

**Architecture:** Enhance existing templates with new UI elements. Backend handlers already exist for most operations (QuoteUpdateHandler, ProfileHandler). LearnDashAdapter provides certificate links.

**Tech Stack:** PHP templates, UIkit 3, AJAX handlers, LearnDash integration

---

## Task 1: Add Cancel Quote Handler

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Handlers/QuoteUpdateHandler.php`

**Step 1: Add AJAX action registration**

In the `init()` method, add the cancel action:

```php
add_action('wp_ajax_stride_cancel_quote', [$this, 'ajaxCancelQuote']);
```

**Step 2: Add the cancel handler method**

Add after `ajaxApplyVoucher()`:

```php
/**
 * AJAX: Cancel quote.
 */
public function ajaxCancelQuote(): void
{
    if (!wp_verify_nonce($_POST['nonce'] ?? '', 'stride_quote_update')) {
        wp_send_json_error(['message' => __('Ongeldige beveiligingstoken.', 'stride')]);
    }

    $userId = get_current_user_id();
    if (!$userId) {
        wp_send_json_error(['message' => __('Je moet ingelogd zijn.', 'stride')]);
    }

    $quoteId = absint($_POST['quote_id'] ?? 0);
    if (!$quoteId) {
        wp_send_json_error(['message' => __('Geen offerte opgegeven.', 'stride')]);
    }

    $validation = $this->validateQuoteAccess($quoteId, $userId);
    if (is_wp_error($validation)) {
        wp_send_json_error(['message' => $validation->get_error_message()]);
    }

    $quotes = ntdst_get(QuoteService::class);
    $result = $quotes->cancel($quoteId);

    if (is_wp_error($result)) {
        wp_send_json_error(['message' => $result->get_error_message()]);
    }

    wp_send_json_success([
        'message' => __('Inschrijving geannuleerd.', 'stride'),
        'redirect_url' => home_url('/mijn-account/offertes/'),
    ]);
}
```

**Step 3: Commit**

```bash
git add web/app/mu-plugins/stride-core/Handlers/QuoteUpdateHandler.php
git commit -m "feat(quote): add cancel quote AJAX handler"
```

---

## Task 2: Enhance Quote Detail - Add Voucher, Cancel, PDF Buttons

**Files:**
- Modify: `web/app/themes/stride/templates/quote/detail.php`

**Step 1: Add voucher input section**

After the line items table (after `</table>` around line 218), add voucher section for editable quotes:

```php
<?php if ($isEditable): ?>
    <?php $existingVoucher = $quote['voucher_code'] ?? ''; ?>
    <div class="uk-margin-top">
        <h4 class="uk-margin-small-bottom"><?php esc_html_e('Kortingscode', 'stride'); ?></h4>
        <?php if ($existingVoucher): ?>
            <div class="uk-alert uk-alert-success uk-margin-small">
                <span uk-icon="icon: tag"></span>
                <?php printf(esc_html__('Voucher "%s" toegepast', 'stride'), esc_html($existingVoucher)); ?>
            </div>
        <?php else: ?>
            <div class="uk-grid uk-grid-small" uk-grid>
                <div class="uk-width-expand">
                    <input type="text" id="voucher_code" class="uk-input"
                           placeholder="<?php esc_attr_e('Voer kortingscode in', 'stride'); ?>">
                </div>
                <div class="uk-width-auto">
                    <button type="button" id="apply-voucher-btn" class="uk-button uk-button-default">
                        <?php esc_html_e('Toepassen', 'stride'); ?>
                    </button>
                </div>
            </div>
            <div id="voucher-result" class="uk-margin-small-top" style="display: none;"></div>
        <?php endif; ?>
    </div>
<?php endif; ?>
```

**Step 2: Update action buttons section**

Replace the existing actions div (around line 296-308) with:

```php
<!-- Actions -->
<div class="stride-card uk-margin-top">
    <div class="uk-padding">
        <div class="uk-grid uk-grid-small uk-flex-middle" uk-grid>
            <div class="uk-width-expand">
                <a href="<?php echo esc_url(home_url('/mijn-account/offertes/')); ?>" class="uk-button uk-button-default">
                    <span uk-icon="icon: arrow-left"></span>
                    <?php esc_html_e('Terug', 'stride'); ?>
                </a>
            </div>
            <div class="uk-width-auto">
                <div class="uk-button-group">
                    <!-- Download PDF (placeholder) -->
                    <button type="button" class="uk-button uk-button-default" id="download-pdf-btn">
                        <span uk-icon="icon: download"></span>
                        <?php esc_html_e('Download PDF', 'stride'); ?>
                    </button>

                    <?php if ($isEditable): ?>
                        <!-- Cancel -->
                        <button type="button" class="uk-button uk-button-danger" id="cancel-quote-btn">
                            <span uk-icon="icon: ban"></span>
                            <?php esc_html_e('Annuleren', 'stride'); ?>
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
```

**Step 3: Add JavaScript for voucher, cancel, and PDF**

Before the closing `<?php endif; ?>` for `$isEditable` (end of file), replace/extend the script section:

```php
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Voucher application
    const applyVoucherBtn = document.getElementById('apply-voucher-btn');
    const voucherInput = document.getElementById('voucher_code');
    const voucherResult = document.getElementById('voucher-result');

    if (applyVoucherBtn && voucherInput) {
        applyVoucherBtn.addEventListener('click', function() {
            const code = voucherInput.value.trim();
            if (!code) return;

            applyVoucherBtn.disabled = true;
            applyVoucherBtn.innerHTML = '<span uk-spinner="ratio: 0.5"></span>';

            fetch(strideConfig.ajaxUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    action: 'stride_apply_quote_voucher',
                    nonce: '<?php echo wp_create_nonce('stride_quote_update'); ?>',
                    quote_id: <?php echo $quoteId; ?>,
                    voucher_code: code
                })
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    UIkit.notification({message: data.data.message, status: 'success', pos: 'top-center'});
                    setTimeout(() => window.location.reload(), 1000);
                } else {
                    voucherResult.style.display = 'block';
                    voucherResult.innerHTML = '<div class="uk-alert uk-alert-danger uk-margin-remove">' + data.data.message + '</div>';
                    applyVoucherBtn.disabled = false;
                    applyVoucherBtn.textContent = '<?php esc_html_e('Toepassen', 'stride'); ?>';
                }
            })
            .catch(() => {
                applyVoucherBtn.disabled = false;
                applyVoucherBtn.textContent = '<?php esc_html_e('Toepassen', 'stride'); ?>';
            });
        });
    }

    // Cancel quote
    const cancelBtn = document.getElementById('cancel-quote-btn');
    if (cancelBtn) {
        cancelBtn.addEventListener('click', function() {
            UIkit.modal.confirm('<?php esc_html_e('Weet je zeker dat je deze inschrijving wilt annuleren? Dit kan niet ongedaan worden gemaakt.', 'stride'); ?>').then(function() {
                cancelBtn.disabled = true;
                cancelBtn.innerHTML = '<span uk-spinner="ratio: 0.5"></span>';

                fetch(strideConfig.ajaxUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({
                        action: 'stride_cancel_quote',
                        nonce: '<?php echo wp_create_nonce('stride_quote_update'); ?>',
                        quote_id: <?php echo $quoteId; ?>
                    })
                })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        UIkit.notification({message: data.data.message, status: 'success', pos: 'top-center'});
                        setTimeout(() => window.location.href = data.data.redirect_url, 1000);
                    } else {
                        UIkit.notification({message: data.data.message, status: 'danger', pos: 'top-center'});
                        cancelBtn.disabled = false;
                        cancelBtn.innerHTML = '<span uk-icon="icon: ban"></span> <?php esc_html_e('Annuleren', 'stride'); ?>';
                    }
                });
            }, function() {});
        });
    }

    // PDF download (placeholder)
    const pdfBtn = document.getElementById('download-pdf-btn');
    if (pdfBtn) {
        pdfBtn.addEventListener('click', function() {
            UIkit.notification({
                message: '<?php esc_html_e('PDF download wordt binnenkort beschikbaar.', 'stride'); ?>',
                status: 'warning',
                pos: 'top-center'
            });
        });
    }

    // Billing form (existing)
    const form = document.getElementById('edit-billing-form');
    const saveBtn = document.getElementById('save-billing-btn');

    if (form) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();

            saveBtn.disabled = true;
            saveBtn.innerHTML = '<span uk-spinner="ratio: 0.5"></span>';

            const formData = new FormData(form);

            fetch(strideConfig.ajaxUrl, {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    UIkit.notification({message: '<?php esc_html_e('Gegevens opgeslagen', 'stride'); ?>', status: 'success', pos: 'top-center'});
                    UIkit.modal('#edit-billing-modal').hide();
                    setTimeout(() => window.location.reload(), 1000);
                } else {
                    UIkit.notification({message: data.data.message || '<?php esc_html_e('Er is een fout opgetreden', 'stride'); ?>', status: 'danger', pos: 'top-center'});
                    saveBtn.disabled = false;
                    saveBtn.textContent = '<?php esc_html_e('Opslaan', 'stride'); ?>';
                }
            })
            .catch(() => {
                UIkit.notification({message: '<?php esc_html_e('Er is een fout opgetreden', 'stride'); ?>', status: 'danger', pos: 'top-center'});
                saveBtn.disabled = false;
                saveBtn.textContent = '<?php esc_html_e('Opslaan', 'stride'); ?>';
            });
        });
    }
});
</script>
```

**Step 4: Remove old script section**

Delete the old `<script>` block that was only handling the billing form (lines 375-425 approximately).

**Step 5: Commit**

```bash
git add web/app/themes/stride/templates/quote/detail.php
git commit -m "feat(quote): add voucher input, cancel button, PDF placeholder"
```

---

## Task 3: Add Certificate Download to Course Cards

**Files:**
- Modify: `web/app/themes/stride/templates/dashboard/courses.php`

**Step 1: Add LearnDashAdapter import and get certificate links**

At the top after the services, add:

```php
use Stride\Adapters\LearnDashAdapter;

// Get LMS adapter for certificates
$lmsAdapter = ntdst_get(LearnDashAdapter::class);
```

**Step 2: Add certificate_url to course data**

Inside the foreach loop building `$courseData` (around line 77-94), add after `'registration_date'`:

```php
'certificate_url' => ($isComplete && $courseId)
    ? $lmsAdapter->getCertificateLink($userId, $courseId)
    : null,
```

**Step 3: Update stride_render_course_card function**

In the `stride_render_course_card` function, update the footer section (around lines 312-324) to include certificate button:

```php
<?php if ($course['url']) : ?>
    <div class="stride-course-card__footer">
        <?php if ($course['is_complete']) : ?>
            <div class="uk-button-group">
                <a href="<?php echo esc_url($course['url']); ?>" class="uk-button uk-button-default uk-button-small">
                    <?php esc_html_e('Bekijken', 'stride'); ?>
                </a>
                <?php if (!empty($course['certificate_url'])) : ?>
                    <a href="<?php echo esc_url($course['certificate_url']); ?>"
                       class="uk-button uk-button-primary uk-button-small"
                       target="_blank"
                       title="<?php esc_attr_e('Download certificaat', 'stride'); ?>">
                        <span uk-icon="icon: download; ratio: 0.8"></span>
                        <?php esc_html_e('Certificaat', 'stride'); ?>
                    </a>
                <?php endif; ?>
            </div>
        <?php else : ?>
            <a href="<?php echo esc_url($course['url']); ?>" class="uk-button uk-button-primary uk-button-small">
                <?php esc_html_e('Doorgaan', 'stride'); ?>
            </a>
        <?php endif; ?>
    </div>
<?php endif; ?>
```

**Step 4: Commit**

```bash
git add web/app/themes/stride/templates/dashboard/courses.php
git commit -m "feat(courses): add certificate download button on completed courses"
```

---

## Task 4: Convert Profile to Editable Form

**Files:**
- Modify: `web/app/themes/stride/templates/dashboard/profile.php`

**Step 1: Add additional user meta retrieval**

After the existing user meta (around line 26-27), add:

```php
// Billing meta
$billingCompany = get_user_meta($userId, 'invoice_organization_name', true) ?: get_user_meta($userId, 'company', true);
$vatNumber = get_user_meta($userId, 'vat_number', true);
$glnNumber = get_user_meta($userId, 'gln_number', true);
$billingAddress = get_user_meta($userId, 'invoice_address', true) ?: get_user_meta($userId, 'address_line_1', true);
$billingPostal = get_user_meta($userId, 'invoice_postal_code', true) ?: get_user_meta($userId, 'postal_code', true);
$billingCity = get_user_meta($userId, 'invoice_city', true) ?: get_user_meta($userId, 'city', true);
$billingEmail = get_user_meta($userId, 'invoice_email', true) ?: $email;

// Notification preferences
$notifyReminders = get_user_meta($userId, 'stride_notify_reminders', true) ?: 'yes';
$notifyNewCourses = get_user_meta($userId, 'stride_notify_new_courses', true) ?: 'yes';
$notifyNewsletter = get_user_meta($userId, 'stride_notify_newsletter', true) ?: 'no';

// Language preference
$language = get_user_meta($userId, 'stride_language', true) ?: 'nl';
```

**Step 2: Replace the profile card body with tabbed form**

Replace the entire `<!-- Profile Card -->` section (lines 81-139) with:

```php
<!-- Profile Card with Tabs -->
<div class="uk-card uk-card-default stride-profile-card">
    <div class="stride-profile-card__header">
        <div class="stride-profile-card__avatar">
            <?php if ($avatarUrl && !str_contains($avatarUrl, 'd=blank')) : ?>
                <img src="<?php echo esc_url($avatarUrl); ?>" alt="<?php echo esc_attr($displayName); ?>">
            <?php else : ?>
                <span class="stride-profile-card__initials"><?php echo esc_html($initials); ?></span>
            <?php endif; ?>
        </div>
        <div class="stride-profile-card__info">
            <h2 class="stride-profile-card__name"><?php echo esc_html($displayName); ?></h2>
            <p class="stride-profile-card__email"><?php echo esc_html($email); ?></p>
            <p class="stride-profile-card__meta">
                <span uk-icon="icon: calendar; ratio: 0.8"></span>
                <?php printf(esc_html__('Lid sinds %s', 'stride'), esc_html($memberSince)); ?>
            </p>
        </div>
    </div>

    <!-- Tabs -->
    <ul uk-tab class="uk-margin-remove-bottom">
        <li class="uk-active"><a href="#"><?php esc_html_e('Persoonlijk', 'stride'); ?></a></li>
        <li><a href="#"><?php esc_html_e('Facturatie', 'stride'); ?></a></li>
        <li><a href="#"><?php esc_html_e('Voorkeuren', 'stride'); ?></a></li>
    </ul>

    <ul class="uk-switcher">
        <!-- Personal Tab -->
        <li>
            <form id="personal-form" class="uk-padding">
                <?php wp_nonce_field('stride_profile', 'nonce'); ?>
                <input type="hidden" name="action" value="stride_update_profile">
                <input type="hidden" name="form_type" value="personal">

                <div class="uk-grid uk-grid-small uk-child-width-1-2@s" uk-grid>
                    <div>
                        <label class="uk-form-label"><?php esc_html_e('Voornaam', 'stride'); ?></label>
                        <input type="text" name="first_name" class="uk-input" value="<?php echo esc_attr($firstName); ?>">
                    </div>
                    <div>
                        <label class="uk-form-label"><?php esc_html_e('Achternaam', 'stride'); ?></label>
                        <input type="text" name="last_name" class="uk-input" value="<?php echo esc_attr($lastName); ?>">
                    </div>
                    <div class="uk-width-1-1">
                        <label class="uk-form-label"><?php esc_html_e('E-mailadres', 'stride'); ?></label>
                        <input type="email" class="uk-input" value="<?php echo esc_attr($email); ?>" disabled>
                        <p class="uk-text-small uk-text-muted uk-margin-small-top">
                            <?php esc_html_e('Neem contact op om je e-mailadres te wijzigen.', 'stride'); ?>
                        </p>
                    </div>
                    <div class="uk-width-1-1">
                        <label class="uk-form-label"><?php esc_html_e('Telefoonnummer', 'stride'); ?></label>
                        <input type="tel" name="phone" class="uk-input" value="<?php echo esc_attr($phone); ?>">
                    </div>
                </div>

                <div class="uk-margin-top">
                    <button type="submit" class="uk-button uk-button-primary save-btn">
                        <?php esc_html_e('Opslaan', 'stride'); ?>
                    </button>
                </div>
            </form>
        </li>

        <!-- Billing Tab -->
        <li>
            <form id="billing-form" class="uk-padding">
                <?php wp_nonce_field('stride_profile', 'nonce'); ?>
                <input type="hidden" name="action" value="stride_update_profile">
                <input type="hidden" name="form_type" value="billing">

                <div class="uk-grid uk-grid-small uk-child-width-1-2@s" uk-grid>
                    <div class="uk-width-1-1">
                        <label class="uk-form-label"><?php esc_html_e('Organisatie', 'stride'); ?></label>
                        <input type="text" name="billing_company" class="uk-input" value="<?php echo esc_attr($billingCompany); ?>">
                    </div>
                    <div>
                        <label class="uk-form-label"><?php esc_html_e('BTW-nummer', 'stride'); ?></label>
                        <input type="text" name="billing_vat" class="uk-input" value="<?php echo esc_attr($vatNumber); ?>" placeholder="BE0123456789">
                    </div>
                    <div>
                        <label class="uk-form-label"><?php esc_html_e('GLN-nummer', 'stride'); ?></label>
                        <input type="text" name="billing_gln" class="uk-input" value="<?php echo esc_attr($glnNumber); ?>">
                    </div>
                    <div class="uk-width-1-1">
                        <label class="uk-form-label"><?php esc_html_e('Facturatie e-mail', 'stride'); ?></label>
                        <input type="email" name="billing_email" class="uk-input" value="<?php echo esc_attr($billingEmail); ?>">
                    </div>
                    <div class="uk-width-1-1">
                        <label class="uk-form-label"><?php esc_html_e('Adres', 'stride'); ?></label>
                        <input type="text" name="billing_address" class="uk-input" value="<?php echo esc_attr($billingAddress); ?>">
                    </div>
                    <div>
                        <label class="uk-form-label"><?php esc_html_e('Postcode', 'stride'); ?></label>
                        <input type="text" name="billing_postal_code" class="uk-input" value="<?php echo esc_attr($billingPostal); ?>">
                    </div>
                    <div>
                        <label class="uk-form-label"><?php esc_html_e('Plaats', 'stride'); ?></label>
                        <input type="text" name="billing_city" class="uk-input" value="<?php echo esc_attr($billingCity); ?>">
                    </div>
                </div>

                <div class="uk-margin-top">
                    <button type="submit" class="uk-button uk-button-primary save-btn">
                        <?php esc_html_e('Opslaan', 'stride'); ?>
                    </button>
                </div>
            </form>
        </li>

        <!-- Preferences Tab -->
        <li>
            <form id="notifications-form" class="uk-padding">
                <?php wp_nonce_field('stride_profile', 'nonce'); ?>
                <input type="hidden" name="action" value="stride_update_profile">
                <input type="hidden" name="form_type" value="notifications">

                <h4 class="uk-margin-small-bottom"><?php esc_html_e('Meldingen', 'stride'); ?></h4>

                <div class="uk-margin">
                    <label>
                        <input type="checkbox" name="notify_reminders" class="uk-checkbox" <?php checked($notifyReminders, 'yes'); ?>>
                        <?php esc_html_e('Herinneringen voor sessies', 'stride'); ?>
                    </label>
                </div>
                <div class="uk-margin">
                    <label>
                        <input type="checkbox" name="notify_new_courses" class="uk-checkbox" <?php checked($notifyNewCourses, 'yes'); ?>>
                        <?php esc_html_e('Nieuwe cursussen en updates', 'stride'); ?>
                    </label>
                </div>
                <div class="uk-margin">
                    <label>
                        <input type="checkbox" name="notify_newsletter" class="uk-checkbox" <?php checked($notifyNewsletter, 'yes'); ?>>
                        <?php esc_html_e('Nieuwsbrief', 'stride'); ?>
                    </label>
                </div>

                <hr>

                <h4 class="uk-margin-small-bottom"><?php esc_html_e('Taal', 'stride'); ?></h4>
                <div class="uk-margin">
                    <select name="language" class="uk-select uk-form-width-medium">
                        <option value="nl" <?php selected($language, 'nl'); ?>>Nederlands</option>
                        <option value="fr" <?php selected($language, 'fr'); ?>>Français</option>
                        <option value="en" <?php selected($language, 'en'); ?>>English</option>
                    </select>
                </div>

                <div class="uk-margin-top">
                    <button type="submit" class="uk-button uk-button-primary save-btn">
                        <?php esc_html_e('Opslaan', 'stride'); ?>
                    </button>
                </div>
            </form>
        </li>
    </ul>
</div>
```

**Step 3: Add JavaScript for form submissions**

At the end of the file, add:

```php
<script>
document.addEventListener('DOMContentLoaded', function() {
    const forms = document.querySelectorAll('#personal-form, #billing-form, #notifications-form');

    forms.forEach(form => {
        form.addEventListener('submit', function(e) {
            e.preventDefault();

            const saveBtn = form.querySelector('.save-btn');
            const originalText = saveBtn.textContent;
            saveBtn.disabled = true;
            saveBtn.innerHTML = '<span uk-spinner="ratio: 0.5"></span> <?php esc_html_e('Opslaan...', 'stride'); ?>';

            const formData = new FormData(form);

            fetch(strideConfig.ajaxUrl, {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    UIkit.notification({
                        message: data.data.message,
                        status: 'success',
                        pos: 'top-center'
                    });
                } else {
                    UIkit.notification({
                        message: data.data.message || '<?php esc_html_e('Er is een fout opgetreden', 'stride'); ?>',
                        status: 'danger',
                        pos: 'top-center'
                    });
                }
            })
            .catch(() => {
                UIkit.notification({
                    message: '<?php esc_html_e('Er is een fout opgetreden', 'stride'); ?>',
                    status: 'danger',
                    pos: 'top-center'
                });
            })
            .finally(() => {
                saveBtn.disabled = false;
                saveBtn.textContent = originalText;
            });
        });
    });
});
</script>
```

**Step 4: Commit**

```bash
git add web/app/themes/stride/templates/dashboard/profile.php
git commit -m "feat(profile): convert to editable form with personal, billing, preferences tabs"
```

---

## Task 5: Add Language Preference to ProfileHandler

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Handlers/ProfileHandler.php`

**Step 1: Update handleUpdateNotifications to include language**

In `handleUpdateNotifications()`, add language handling after the notification preferences:

```php
// Language preference
$language = sanitize_text_field($params['language'] ?? 'nl');
if (in_array($language, ['nl', 'fr', 'en'], true)) {
    update_user_meta($userId, 'stride_language', $language);
}
```

**Step 2: Commit**

```bash
git add web/app/mu-plugins/stride-core/Handlers/ProfileHandler.php
git commit -m "feat(profile): add language preference to notifications handler"
```

---

## Task 6: Test All Flows

**Step 1: Test quote detail page**

1. Navigate to `/mijn-account/offertes/`
2. Click on a draft quote
3. Verify voucher input appears
4. Verify cancel button appears
5. Verify PDF button shows "coming soon" message
6. Test editing billing info via modal
7. Test applying a voucher code
8. Test cancelling the quote

**Step 2: Test certificate download**

1. Navigate to `/mijn-account/cursussen/`
2. Find a completed course
3. Verify "Certificaat" download button appears
4. Click to verify it opens LearnDash certificate

**Step 3: Test profile editing**

1. Navigate to `/mijn-account/profiel/` (or via profile link)
2. Verify three tabs appear: Persoonlijk, Facturatie, Voorkeuren
3. Edit and save personal info
4. Edit and save billing info
5. Change notification preferences and language
6. Verify all changes persist after refresh

**Step 4: Final commit**

```bash
git add -A
git commit -m "test: verify online course user flows complete"
```
