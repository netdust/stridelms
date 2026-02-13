<?php

namespace ntdst\Stride\invoicing\Admin;

defined('ABSPATH') || exit;

use ntdst\Stride\invoicing\QuoteService;

/**
 * Quote Notes Metabox
 *
 * Renders the notes timeline with add/delete functionality.
 *
 * @package stride\services\invoicing\Admin
 */
class QuoteNotesMetabox
{
    private QuoteService $quoteService;

    /**
     * Constructor
     */
    public function __construct(QuoteService $quoteService)
    {
        $this->quoteService = $quoteService;
    }

    /**
     * Render the metabox
     */
    public function render(\WP_Post $post): void
    {
        $quote = $this->quoteService->getQuote($post->ID);

        if (!$quote) {
            echo '<p class="description">' . esc_html__('Sla de offerte eerst op om notities toe te voegen.', 'stride') . '</p>';
            return;
        }

        $notes = $quote['notes'] ?? [];
        $currentUser = wp_get_current_user();

        // Sort notes by date descending (newest first)
        usort($notes, fn($a, $b) => strtotime($b['date'] ?? 0) - strtotime($a['date'] ?? 0));
        ?>

        <!-- Notes Timeline -->
        <div class="stride-notes-timeline" id="stride-notes-list">
            <?php if (empty($notes)): ?>
                <div class="stride-empty-notes">
                    <?php esc_html_e('Nog geen notities toegevoegd.', 'stride'); ?>
                </div>
            <?php else: ?>
                <?php foreach ($notes as $index => $note):
                    $isCustomer = ($note['type'] ?? '') === QuoteService::NOTE_TYPE_CUSTOMER;
                    $typeClass = $isCustomer ? 'customer' : 'admin';
                    $typeLabel = $isCustomer ? __('Klant', 'stride') : __('Intern', 'stride');
                    $icon = $isCustomer ? 'format-quote' : 'shield';
                ?>
                    <div class="stride-note-item" data-index="<?php echo esc_attr($index); ?>">
                        <div class="stride-note-icon <?php echo esc_attr($typeClass); ?>">
                            <span class="dashicons dashicons-<?php echo esc_attr($icon); ?>"></span>
                        </div>
                        <div class="stride-note-body">
                            <div class="stride-note-meta">
                                <span class="author"><?php echo esc_html($note['author'] ?? 'Onbekend'); ?></span>
                                <span class="type-badge <?php echo esc_attr($typeClass); ?>"><?php echo esc_html($typeLabel); ?></span>
                                <span class="date"><?php echo esc_html(date_i18n('d M Y H:i', strtotime($note['date'] ?? ''))); ?></span>
                            </div>
                            <div class="stride-note-content"><?php echo esc_html($note['content'] ?? ''); ?></div>
                        </div>
                        <span class="stride-note-delete dashicons dashicons-no-alt" title="<?php esc_attr_e('Verwijderen', 'stride'); ?>"></span>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Add Note Form -->
        <div class="stride-add-note-form">
            <textarea id="stride-note-content" placeholder="<?php esc_attr_e('Notitie toevoegen...', 'stride'); ?>"></textarea>

            <div class="form-row">
                <div class="type-selector">
                    <label>
                        <input type="radio" name="stride_note_type" value="admin" checked>
                        <span class="type-icon admin">
                            <span class="dashicons dashicons-shield"></span>
                        </span>
                        <?php esc_html_e('Intern', 'stride'); ?>
                    </label>
                    <label>
                        <input type="radio" name="stride_note_type" value="customer">
                        <span class="type-icon customer">
                            <span class="dashicons dashicons-format-quote"></span>
                        </span>
                        <?php esc_html_e('Klant', 'stride'); ?>
                    </label>
                </div>

                <button type="button" class="button" id="stride-add-note">
                    <span class="dashicons dashicons-plus-alt2"></span>
                    <?php esc_html_e('Toevoegen', 'stride'); ?>
                </button>
            </div>
        </div>

        <!-- Hidden field to store notes data -->
        <input type="hidden" name="stride_notes_data" id="stride_notes_data" value="<?php echo esc_attr(json_encode($notes)); ?>">
        <?php
    }
}
