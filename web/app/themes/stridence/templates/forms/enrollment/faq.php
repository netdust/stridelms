<?php
/**
 * Enrollment Form — FAQ Section
 *
 * Repeating two-column sections: topic title/description left, accordion right.
 */
?>
<?php
$faq_topics = [
    [
        'title'       => 'Betaling & facturatie',
        'description' => 'Alles over offertes, facturen, betaalmethodes en kortingscodes.',
        'items'       => [
            [
                'question' => 'Hoe verloopt de betaling?',
                'answer'   => 'Na je inschrijving ontvang je een offerte per e-mail. Na goedkeuring van de offerte wordt deze omgezet naar een factuur. Betaling gebeurt via overschrijving binnen 30 dagen na factuurdatum. Voor particulieren is betaling mogelijk via Bancontact of kredietkaart.',
            ],
            [
                'question' => 'Welke gegevens heb ik nodig voor facturatie via Peppol?',
                'answer'   => 'Voor Peppol-facturatie heb je het GLN-nummer van je organisatie nodig. Dit is een 13-cijferig nummer dat je bij je financiële afdeling kunt opvragen. Vul dit in bij het veld "GLN-nummer (Peppol)" en wij zorgen dat de factuur via het Peppol-netwerk wordt verstuurd.',
            ],
            [
                'question' => 'Hoe werkt een kortingscode?',
                'answer'   => 'Voer je kortingscode in bij de facturatiegegevens en klik op "Controleren". Als de code geldig is, zie je direct de korting verschijnen. De korting wordt automatisch verrekend op je offerte en factuur. Kortingscodes zijn niet combineerbaar met andere acties.',
            ],
        ],
    ],
    [
        'title'       => 'Inschrijving & annulering',
        'description' => 'Wat je moet weten over annuleren, overdragen en collega\'s inschrijven.',
        'items'       => [
            [
                'question' => 'Kan ik mijn inschrijving annuleren?',
                'answer'   => 'Annuleren is kosteloos tot 14 dagen voor de startdatum. Bij annulering tussen 14 en 7 dagen voor aanvang wordt 50% van het inschrijfgeld in rekening gebracht. Bij annulering binnen 7 dagen of no-show is het volledige bedrag verschuldigd. Je kunt je inschrijving wel overdragen aan een collega.',
            ],
            [
                'question' => 'Wat als ik een collega wil inschrijven?',
                'answer'   => 'Selecteer bij stap 1 de optie "Collega". Je vult dan de gegevens van je collega in als deelnemer. De factuur wordt naar jouw organisatie gestuurd. Je collega ontvangt een eigen account en bevestigingsmail voor toegang tot de cursus.',
            ],
        ],
    ],
    [
        'title'       => 'Na inschrijving',
        'description' => 'Wat je kunt verwachten na het afronden van je inschrijving.',
        'items'       => [
            [
                'question' => 'Wat ontvang ik na mijn inschrijving?',
                'answer'   => 'Direct na je inschrijving ontvang je een bevestigingsmail met je inschrijvingsgegevens. Een week voor aanvang ontvang je praktische informatie over locatie, parkeren en programma. Na afronding van de opleiding ontvang je je certificaat digitaal in je account.',
            ],
        ],
    ],
];
?>

<section class="mt-12 lg:mt-16">
    <h2 class="font-heading text-2xl font-bold text-text mb-8">Veelgestelde vragen</h2>

    <div class="space-y-12 lg:space-y-16" x-data="{ openFaq: null }">
        <?php foreach ($faq_topics as $t => $topic) : ?>
            <div class="grid lg:grid-cols-3 gap-6 lg:gap-10">
                <!-- Topic title & description -->
                <div class="lg:col-span-1">
                    <h3 class="font-heading text-lg font-semibold text-text mb-1"><?= esc_html($topic['title']) ?></h3>
                    <p class="text-sm text-text-muted"><?= esc_html($topic['description']) ?></p>
                </div>

                <!-- FAQ accordion -->
                <div class="lg:col-span-2 space-y-3">
                    <?php foreach ($topic['items'] as $i => $faq) :
                        $faq_id = $t . '_' . $i;
                        ?>
                        <div class="card-bordered">
                            <button type="button"
                                    class="w-full p-4 text-left flex items-center justify-between"
                                    @click="openFaq = openFaq === '<?= $faq_id ?>' ? null : '<?= $faq_id ?>'">
                                <span class="font-medium"><?= esc_html($faq['question']) ?></span>
                                <span class="transform transition-transform shrink-0 ml-3" :class="openFaq === '<?= $faq_id ?>' && 'rotate-180'">
                                    <?= stridence_icon('chevron-down', 'w-5 h-5 text-text-muted') ?>
                                </span>
                            </button>
                            <div x-show="openFaq === '<?= $faq_id ?>'" x-collapse class="px-4 pb-4">
                                <p class="text-text-muted text-sm"><?= esc_html($faq['answer']) ?></p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>


</section>
