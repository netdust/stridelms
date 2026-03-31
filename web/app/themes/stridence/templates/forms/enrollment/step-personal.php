<?php
/**
 * Enrollment Form — Step 1: Personal Info + Field Groups
 *
 * @var array $args {
 *     @type array $personal_groups  Field groups for the personal step
 * }
 */

$personal_groups = $args['personal_groups'] ?? [];
$form_type       = $args['form_type'] ?? 'default';
?>
<div x-show="currentStep === 1" x-transition>
    <h2 class="text-xl font-heading font-semibold mb-6">
        <span x-show="form.enrollment_type !== 'collega'">Persoonlijke gegevens</span>
        <span x-show="form.enrollment_type === 'collega'">Gegevens deelnemer</span>
    </h2>

    <div class="space-y-4">
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label class="input-label" for="first_name">Voornaam *</label>
                <input type="text" id="first_name" name="first_name" x-model="form.first_name"
                       class="input-text" required>
            </div>
            <div>
                <label class="input-label" for="last_name">Achternaam *</label>
                <input type="text" id="last_name" name="last_name" x-model="form.last_name"
                       class="input-text" required>
            </div>
        </div>

        <div>
            <label class="input-label" for="email">E-mailadres *</label>
            <input type="email" id="email" name="email" x-model="form.email"
                   class="input-text" required
                   :readonly="form.enrollment_type !== 'collega'"
                   :class="form.enrollment_type !== 'collega' && 'bg-surface-alt cursor-not-allowed'">
            <p x-show="form.enrollment_type !== 'collega'" class="input-hint">
                Dit is je account e-mailadres
            </p>
        </div>

        <div>
            <label class="input-label" for="phone">Telefoonnummer *</label>
            <input type="tel" id="phone" name="phone" x-model="form.phone"
                   class="input-text" required placeholder="+32 ...">
        </div>

        <?php if ($form_type !== 'minimal') : ?>
        <!-- Organisation fields for werknemer/collega -->
        <template x-if="form.enrollment_type !== 'prive'">
            <div class="space-y-4 pt-4 border-t border-border">
                <div>
                    <label class="input-label" for="organisation">Organisatie</label>
                    <input type="text" id="organisation" name="organisation" x-model="form.organisation"
                           class="input-text" placeholder="Naam van je werkgever">
                </div>
                <div>
                    <label class="input-label" for="department">Afdeling</label>
                    <input type="text" id="department" name="department" x-model="form.department"
                           class="input-text">
                </div>
            </div>
        </template>
        <?php endif; ?>

        <!-- Dynamic field groups for personal step -->
        <?php foreach ($personal_groups as $group) : ?>
            <?php
            stridence_template_part('templates/forms/fields/field-group', null, [
                'group' => $group,
            ]);
            ?>
        <?php endforeach; ?>

        <!-- Message field for interest mode -->
        <template x-if="mode === 'interest'">
            <div class="pt-4 border-t border-border">
                <label class="input-label" for="message">Opmerking (optioneel)</label>
                <textarea id="message" name="message" x-model="form.message" rows="3"
                          class="input-text"
                          placeholder="Laat ons weten wat je verwachting of vraag is..."></textarea>
            </div>
        </template>
    </div>

    <div class="mt-8 flex justify-between">
        <button type="button" @click="prevStep" class="btn-secondary"
                x-show="mode !== 'interest' && stepIndex > 0">Vorige</button>
        <span x-show="mode === 'interest' || stepIndex === 0"></span>
        <button type="button" @click="nextStep" class="btn-primary">Volgende</button>
    </div>
</div>
