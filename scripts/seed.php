<?php
/**
 * Stride LMS - Development Seed Script
 *
 * Run with: ddev exec wp eval-file scripts/seed.php
 *
 * Creates comprehensive test data for frontend testing:
 * - 10 courses (2 online, 8 in-person)
 * - Various edition configurations (single/multiple editions, all statuses)
 * - Sessions with different types (in_person, online, webinar, assignment)
 * - 2 trajectories (cohort and self-paced) with courses
 * - Registrations, quotes and vouchers
 */

if (!defined('ABSPATH')) {
    echo "This script must be run via WP-CLI: ddev exec wp eval-file scripts/seed.php\n";
    exit(1);
}

// Prevent accidental runs in production
if (defined('WP_ENV') && WP_ENV === 'production') {
    echo "ERROR: Cannot run seed script in production!\n";
    exit(1);
}

use Stride\Domain\RegistrationStatus;
use Stride\Domain\SessionType;
use Stride\Domain\TrajectoryMode;
use Stride\Domain\TrajectoryStatus;
use Stride\Modules\Edition\EditionService;
use Stride\Modules\Edition\EditionRepository;
use Stride\Modules\Edition\SessionService;
use Stride\Modules\Enrollment\RegistrationRepository;
use Stride\Modules\Trajectory\TrajectoryService;

// Session type constants
const SESSION_TYPE_IN_PERSON = 'in_person';
const SESSION_TYPE_ONLINE = 'online';
const SESSION_TYPE_WEBINAR = 'webinar';
const SESSION_TYPE_ASSIGNMENT = 'assignment';

/**
 * Comprehensive Seed Data Generator
 */
class StrideSeedData {

    private array $created = [
        'users' => [],
        'courses' => [],
        'editions' => [],
        'sessions' => [],
        'registrations' => [],
        'trajectories' => [],
        'vouchers' => [],
        'quotes' => [],
    ];

    private const SEED_META_KEY = '_stride_seed_data';

    private ?EditionService $editionService = null;
    private ?EditionRepository $editionRepository = null;
    private ?SessionService $sessionService = null;
    private ?RegistrationRepository $regRepo = null;
    private ?TrajectoryService $trajectoryService = null;

    // Current admin user to subscribe
    private int $adminUserId = 1;

    public function run(): void {
        echo "\n=== Stride LMS Comprehensive Seed ===\n\n";

        $this->initServices();
        $this->createUsers();
        $this->createCourses();
        $this->createTrajectories();
        $this->createVouchers();
        $this->createEnrollmentsAndQuotes();
        $this->createTrajectoryTestData();

        $this->saveSeedManifest();
        $this->printSummary();
    }

    private function initServices(): void {
        if (function_exists('ntdst_get')) {
            $this->editionService = ntdst_get(EditionService::class);
            $this->editionRepository = ntdst_get(EditionRepository::class);
            $this->sessionService = ntdst_get(SessionService::class);
            $this->regRepo = ntdst_get(RegistrationRepository::class);
            $this->trajectoryService = ntdst_get(TrajectoryService::class);
        }
    }

    private function createUsers(): void {
        echo "Creating users...\n";

        $users = [
            ['login' => 'seed_admin', 'email' => 'seed_admin@seed.test', 'role' => 'administrator', 'first' => 'Admin', 'last' => 'Seed'],
            ['login' => 'seed_instructor', 'email' => 'instructor@seed.test', 'role' => 'group_leader', 'first' => 'Jan', 'last' => 'De Trainer'],
            ['login' => 'seed_partner', 'email' => 'seed_partner@seed.test', 'role' => 'partner', 'first' => 'Partner', 'last' => 'Test', 'company_id' => 1],
            ['login' => 'seed_student1', 'email' => 'student1@seed.test', 'role' => 'subscriber', 'first' => 'Pieter', 'last' => 'Janssen', 'is_member' => true, 'company_id' => 1],
            ['login' => 'seed_student2', 'email' => 'student2@seed.test', 'role' => 'subscriber', 'first' => 'Anna', 'last' => 'De Vries', 'is_member' => true, 'company_id' => 1],
            ['login' => 'seed_student3', 'email' => 'student3@seed.test', 'role' => 'subscriber', 'first' => 'Thomas', 'last' => 'Bakker', 'is_member' => false, 'company_id' => 1],
        ];

        foreach ($users as $userData) {
            $existing = get_user_by('login', $userData['login']);
            if ($existing) {
                $this->created['users'][] = $existing->ID;
                // Ensure existing seed users are activated
                if (!get_user_meta($existing->ID, 'ntdst_auth_activated', true)) {
                    update_user_meta($existing->ID, 'ntdst_auth_activated', true);
                    update_user_meta($existing->ID, 'ntdst_auth_activated_at', time());
                    echo "  - User {$userData['login']} exists (ID: {$existing->ID}) - activated\n";
                } else {
                    echo "  - User {$userData['login']} exists (ID: {$existing->ID})\n";
                }
                // Ensure company_id is set for existing users
                if (isset($userData['company_id'])) {
                    update_user_meta($existing->ID, '_stride_company_id', $userData['company_id']);
                }
                continue;
            }

            $userId = wp_insert_user([
                'user_login' => $userData['login'],
                'user_email' => $userData['email'],
                'user_pass' => 'seedpass123',
                'first_name' => $userData['first'],
                'last_name' => $userData['last'],
                'display_name' => $userData['first'] . ' ' . $userData['last'],
                'role' => $userData['role'],
            ]);

            if (is_wp_error($userId)) {
                echo "  ! Failed: {$userId->get_error_message()}\n";
                continue;
            }

            update_user_meta($userId, self::SEED_META_KEY, true);
            if (isset($userData['is_member'])) {
                update_user_meta($userId, 'is_vad_member', $userData['is_member']);
            }
            if (isset($userData['company_id'])) {
                update_user_meta($userId, '_stride_company_id', $userData['company_id']);
            }

            // Activate users for auth (ntdst-auth requires activation)
            update_user_meta($userId, 'ntdst_auth_activated', true);
            update_user_meta($userId, 'ntdst_auth_activated_at', time());

            $this->created['users'][] = $userId;
            echo "  + Created: {$userData['login']} (ID: {$userId})\n";
        }
    }

    private function createCourses(): void {
        echo "\nCreating courses with editions and sessions...\n";

        if (!defined('LEARNDASH_VERSION')) {
            echo "  ! LearnDash not active, skipping courses\n";
            return;
        }

        // ===== COURSE DEFINITIONS =====
        // Organized by type: Online (self-paced), In-Person (with editions), Hybrid, Webinar
        $courses = [

            // =========================================================================
            // ONLINE COURSES (Self-paced e-learning - NO editions, always available)
            // =========================================================================

            // === ONLINE 1: Comprehensive introduction course ===
            [
                'title' => 'E-learning: Basiskennis Verslavingszorg',
                'description' => 'Deze uitgebreide online cursus biedt een stevige basis in de verslavingszorg. Je leert over de verschillende soorten verslavingen, de biologische en psychologische mechanismen, en de belangrijkste behandelmethoden. Ideaal voor nieuwe medewerkers of als opfrisser voor ervaren professionals. Na afronding ontvang je een officieel VAD-certificaat.',
                'type' => 'online',
                'editions' => [], // No editions - always available
                'lessons' => [
                    [
                        'title' => 'Module 1: Wat is verslaving?',
                        'content' => '<h3>Definitie en Kenmerken</h3>
<p>In deze eerste module verkennen we wat verslaving precies inhoudt. We bekijken de DSM-5 criteria voor stoornis in middelengebruik en gedragsverslavingen.</p>

<h4>Leerdoelen</h4>
<ul>
<li>Je kunt verslaving definiëren volgens de huidige wetenschappelijke inzichten</li>
<li>Je herkent de 11 criteria van DSM-5 voor stoornissen in middelengebruik</li>
<li>Je begrijpt het verschil tussen gebruik, misbruik en afhankelijkheid</li>
</ul>

<h4>Kernbegrippen</h4>
<p><strong>Tolerantie:</strong> Het lichaam went aan de stof, waardoor steeds meer nodig is voor hetzelfde effect.</p>
<p><strong>Onthoudingsverschijnselen:</strong> Lichamelijke en psychische klachten die optreden bij stoppen of minderen.</p>
<p><strong>Craving:</strong> Een intense drang of verlangen naar de stof of het gedrag.</p>',
                    ],
                    [
                        'title' => 'Module 2: Het verslaafde brein',
                        'content' => '<h3>Neurobiologie van Verslaving</h3>
<p>Verslaving is een hersenziekte. In deze module leer je hoe verslavende stoffen en gedragingen het beloningssysteem van de hersenen beïnvloeden.</p>

<h4>Het Dopamine Systeem</h4>
<p>Dopamine speelt een centrale rol bij verslaving. We bekijken hoe drugs en verslavend gedrag leiden tot een abnormale dopamine-afgifte en hoe dit het brein verandert.</p>

<h4>De Prefrontale Cortex</h4>
<p>Dit deel van de hersenen is verantwoordelijk voor planning, impulscontrole en besluitvorming. Bij verslaving raakt dit gebied aangetast, wat verklaart waarom mensen blijven gebruiken ondanks negatieve gevolgen.</p>',
                    ],
                    [
                        'title' => 'Module 3: Soorten verslavingen',
                        'content' => '<h3>Middelen en Gedragsverslavingen</h3>

<h4>Middelenverslavingen</h4>
<ul>
<li><strong>Alcohol:</strong> De meest voorkomende verslaving in België en Nederland</li>
<li><strong>Cannabis:</strong> Vooral problematisch bij jongeren vanwege hersenontwikkeling</li>
<li><strong>Opiaten:</strong> Heroïne en pijnstillers - hoog risico op overdosis</li>
<li><strong>Stimulantia:</strong> Cocaïne, amfetaminen, MDMA</li>
<li><strong>Benzodiazepines:</strong> Vaak onderschat maar zeer verslavend</li>
</ul>

<h4>Gedragsverslavingen</h4>
<ul>
<li><strong>Gokken:</strong> Officieel erkend als verslaving in DSM-5</li>
<li><strong>Gaming:</strong> Internet Gaming Disorder</li>
<li><strong>Social media:</strong> Groeiend probleem, vooral bij jongeren</li>
</ul>',
                    ],
                    [
                        'title' => 'Module 4: Behandelmethoden overzicht',
                        'content' => '<h3>Evidence-Based Behandelingen</h3>

<h4>Psychosociale Behandelingen</h4>
<p><strong>Cognitieve Gedragstherapie (CGT):</strong> Helpt cliënten hun gedachten en gedrag te veranderen. Zeer effectief bij diverse verslavingen.</p>
<p><strong>Motiverende Gespreksvoering (MGV):</strong> Versterkt de eigen motivatie van de cliënt om te veranderen. Niet-confronterend en cliëntgericht.</p>
<p><strong>Community Reinforcement Approach (CRA):</strong> Richt zich op het opbouwen van een bevredigend leven zonder middelen.</p>

<h4>Farmacologische Behandelingen</h4>
<p>Bij sommige verslavingen kunnen medicijnen helpen: methadon/buprenorfine bij opiaatverslaving, naltrexon bij alcohol, varenicline bij nicotine.</p>',
                    ],
                    [
                        'title' => 'Module 5: Eindtoets en certificaat',
                        'content' => '<h3>Toets je Kennis</h3>
<p>Je hebt alle modules doorgewerkt. Nu is het tijd om je kennis te toetsen met de eindtoets.</p>

<h4>Instructies</h4>
<ul>
<li>De toets bestaat uit 25 meerkeuzevragen</li>
<li>Je hebt 45 minuten de tijd</li>
<li>Je moet minimaal 70% scoren om te slagen</li>
<li>Bij voldoende resultaat ontvang je direct je certificaat</li>
</ul>

<p><strong>Succes!</strong></p>',
                    ],
                ],
            ],

            // === ONLINE 2: Alcohol specific course ===
            [
                'title' => 'E-learning: Alcohol en Gezondheid',
                'description' => 'Verdiepende online module over alle aspecten van alcohol. Van de werking op het lichaam tot de sociale impact, van vroege signalering tot behandeling. Met interactieve cases, video-interviews met ervaringsdeskundigen, en praktische tools voor gesprekken over alcohol.',
                'type' => 'online',
                'editions' => [],
                'lessons' => [
                    [
                        'title' => 'Hoofdstuk 1: Alcohol in cijfers',
                        'content' => '<h3>De Nederlandse en Belgische Situatie</h3>

<h4>Alcoholgebruik in België</h4>
<p>België staat in de Europese top als het gaat om alcoholconsumptie. Gemiddeld drinkt een Belg 12,1 liter pure alcohol per jaar.</p>

<h4>Problematisch gebruik</h4>
<ul>
<li>Ongeveer 10% van de bevolking heeft een riskant drinkpatroon</li>
<li>Mannen drinken gemiddeld meer dan vrouwen</li>
<li>Bingedrinken is vooral een probleem bij jongvolwassenen (18-24 jaar)</li>
<li>De kosten voor de maatschappij worden geschat op 4,2 miljard euro per jaar</li>
</ul>

<h4>Wat is een standaardglas?</h4>
<p>Een standaardglas bevat 10 gram pure alcohol. Dit komt overeen met: een glas bier (25cl), een glas wijn (10cl), of een borrel (3,5cl).</p>',
                    ],
                    [
                        'title' => 'Hoofdstuk 2: Effecten op lichaam en geest',
                        'content' => '<h3>Hoe Alcohol Werkt</h3>

<h4>Korte termijn effecten</h4>
<p>Alcohol werkt als een remmer op het centrale zenuwstelsel. Het verhoogt de activiteit van GABA (remmende neurotransmitter) en verlaagt de activiteit van glutamaat (stimulerende neurotransmitter).</p>

<h4>Effecten per BAC niveau</h4>
<ul>
<li><strong>0.2-0.5‰:</strong> Ontspanning, lichte euforie, verminderde remmingen</li>
<li><strong>0.5-1.0‰:</strong> Verminderde coördinatie, trager reactievermogen</li>
<li><strong>1.0-2.0‰:</strong> Onduidelijke spraak, evenwichtsproblemen, emotionele instabiliteit</li>
<li><strong>2.0-3.0‰:</strong> Ernstige motorische problemen, black-outs mogelijk</li>
<li><strong>>3.0‰:</strong> Levensgevaarlijk, risico op coma</li>
</ul>

<h4>Lange termijn gevolgen</h4>
<p>Chronisch overmatig alcoholgebruik kan leiden tot levercirrose, hersenkrimp, hart- en vaatziekten, kanker, en psychische stoornissen.</p>',
                    ],
                    [
                        'title' => 'Hoofdstuk 3: Herkennen van problematisch gebruik',
                        'content' => '<h3>Vroege Signalering</h3>

<h4>Signalen bij de persoon zelf</h4>
<ul>
<li>Regelmatig meer drinken dan gepland</li>
<li>Tolerantieontwikkeling: steeds meer nodig voor effect</li>
<li>Onthoudingsklachten bij niet drinken (trillen, zweten, angst)</li>
<li>Verwaarlozing van andere activiteiten</li>
<li>Blijven drinken ondanks problemen</li>
</ul>

<h4>De CAGE vragenlijst</h4>
<p>Een snelle screening tool met 4 vragen:</p>
<ol>
<li><strong>C</strong>ut down: Heeft u ooit het gevoel gehad dat u moest minderen?</li>
<li><strong>A</strong>nnoyed: Ergert u zich aan kritiek van anderen op uw drinkgedrag?</li>
<li><strong>G</strong>uilty: Heeft u zich wel eens schuldig gevoeld over uw drinkgedrag?</li>
<li><strong>E</strong>ye-opener: Drinkt u wel eens alcohol in de ochtend?</li>
</ol>
<p>2 of meer "ja" antwoorden wijzen op mogelijk problematisch gebruik.</p>',
                    ],
                    [
                        'title' => 'Hoofdstuk 4: Gespreksvoering over alcohol',
                        'content' => '<h3>Het Gesprek Aangaan</h3>

<h4>Barrières overwinnen</h4>
<p>Veel hulpverleners vinden het lastig om alcohol ter sprake te brengen. Veelgehoorde redenen:</p>
<ul>
<li>"Het is privé"</li>
<li>"De cliënt zal boos worden"</li>
<li>"Ik drink zelf ook, wie ben ik om te oordelen?"</li>
</ul>
<p>Onderzoek toont echter dat de meeste mensen korte interventies over alcohol waarderen.</p>

<h4>De 5 A\'s</h4>
<ol>
<li><strong>Ask:</strong> Vraag naar alcoholgebruik</li>
<li><strong>Assess:</strong> Bepaal het risiconiveau</li>
<li><strong>Advise:</strong> Geef helder advies</li>
<li><strong>Assist:</strong> Help bij verandering</li>
<li><strong>Arrange:</strong> Regel follow-up</li>
</ol>',
                    ],
                    [
                        'title' => 'Hoofdstuk 5: Behandelmogelijkheden',
                        'content' => '<h3>Van Vroeginterventie tot Intensieve Behandeling</h3>

<h4>Korte interventies</h4>
<p>Voor mensen met riskant maar niet afhankelijk drinkgedrag kan een korte interventie (5-15 minuten) al effect hebben. Dit omvat feedback, advies en het stellen van doelen.</p>

<h4>Ambulante behandeling</h4>
<p>Bij alcoholafhankelijkheid is vaak intensievere hulp nodig. Dit kan bestaan uit:</p>
<ul>
<li>Individuele therapie (CGT, MGV)</li>
<li>Groepstherapie</li>
<li>Medicamenteuze ondersteuning (disulfiram, acamprosaat, naltrexon)</li>
</ul>

<h4>Klinische behandeling</h4>
<p>Bij ernstige afhankelijkheid of comorbiditeit kan opname nodig zijn voor detoxificatie en intensieve behandeling.</p>

<h4>Zelfhulpgroepen</h4>
<p>AA (Anonieme Alcoholisten) en SMART Recovery bieden peer support en zijn een waardevolle aanvulling op professionele behandeling.</p>',
                    ],
                ],
            ],

            // === ONLINE 3: Cannabis course ===
            [
                'title' => 'E-learning: Cannabis - Feiten en Fabels',
                'description' => 'Alles wat je moet weten over cannabis: van de werkzame stoffen tot de effecten op de hersenen, van recreatief gebruik tot medicinale toepassingen. Speciaal ontwikkeld voor professionals die werken met jongeren en jongvolwassenen.',
                'type' => 'online',
                'editions' => [],
                'lessons' => [
                    [
                        'title' => 'Les 1: Cannabis 101 - De Basis',
                        'content' => '<h3>Wat is Cannabis?</h3>
<p>Cannabis is een plant die meer dan 100 verschillende cannabinoïden bevat. De twee belangrijkste zijn THC (tetrahydrocannabinol) en CBD (cannabidiol).</p>

<h4>THC vs CBD</h4>
<p><strong>THC</strong> is de psychoactieve stof die zorgt voor de "high". Het bindt aan CB1-receptoren in de hersenen.</p>
<p><strong>CBD</strong> is niet psychoactief en heeft mogelijk medicinale eigenschappen. Het kan zelfs de effecten van THC temperen.</p>

<h4>Vormen van cannabis</h4>
<ul>
<li><strong>Wiet/marihuana:</strong> Gedroogde bloemen van de cannabisplant</li>
<li><strong>Hasj:</strong> Geperste hars van de plant</li>
<li><strong>Cannabis olie:</strong> Geconcentreerd extract</li>
<li><strong>Edibles:</strong> Eetbare producten met cannabis</li>
</ul>',
                    ],
                    [
                        'title' => 'Les 2: Effecten en risico\'s',
                        'content' => '<h3>Wat Doet Cannabis?</h3>

<h4>Gewenste effecten</h4>
<ul>
<li>Ontspanning en euforie</li>
<li>Verhoogde zintuiglijke waarneming</li>
<li>Creativiteit en associatief denken</li>
<li>Eetlust stimulatie ("munchies")</li>
</ul>

<h4>Ongewenste effecten</h4>
<ul>
<li>Angst en paranoia</li>
<li>Verstoord korte termijn geheugen</li>
<li>Verminderde concentratie en reactiesnelheid</li>
<li>Bij hoge doses: hallucinaties, psychose</li>
</ul>

<h4>Risico\'s bij jongeren</h4>
<p>De hersenen zijn pas rond het 25e levensjaar volgroeid. Cannabisgebruik tijdens de adolescentie kan leiden tot:</p>
<ul>
<li>Verstoring van de hersenontwikkeling</li>
<li>Verhoogd risico op psychose (vooral bij genetische aanleg)</li>
<li>Cognitieve achteruitgang</li>
<li>Amotivatie syndroom</li>
</ul>',
                    ],
                    [
                        'title' => 'Les 3: Cannabis en psychose',
                        'content' => '<h3>De Relatie Tussen Cannabis en Psychose</h3>

<h4>Wat zegt het onderzoek?</h4>
<p>Er is een duidelijke statistische relatie tussen cannabisgebruik en psychose. Hoe vroeger iemand begint en hoe meer hij/zij gebruikt, hoe groter het risico.</p>

<h4>Risicofactoren</h4>
<ul>
<li><strong>Genetische kwetsbaarheid:</strong> Mensen met psychose in de familie hebben een verhoogd risico</li>
<li><strong>Leeftijd van eerste gebruik:</strong> Gebruik vóór 15 jaar vergroot het risico aanzienlijk</li>
<li><strong>THC-gehalte:</strong> Hoog-potente cannabis is riskanter</li>
<li><strong>Frequentie van gebruik:</strong> Dagelijks gebruik verhoogt het risico 5x</li>
</ul>

<h4>Praktische implicaties</h4>
<p>Screen bij elke jongere met psychotische symptomen op cannabisgebruik. Bespreek de risico\'s openlijk, zonder te moraliseren.</p>',
                    ],
                ],
            ],

            // =========================================================================
            // IN-PERSON COURSES (With editions and sessions)
            // =========================================================================

            // === IN-PERSON 1: Single day course ===
            [
                'title' => 'Motiverende Gespreksvoering - Basistraining',
                'description' => 'Motiverende Gespreksvoering (MGV) is een evidence-based gespreksmethodiek die de intrinsieke motivatie van cliënten versterkt. In deze intensieve basistraining leer je de vier processen van MGV en oefen je uitgebreid met de technieken. Je leert reflectief luisteren, open vragen stellen, en omgaan met weerstand. Deze cursus is erkend voor SKJ/NVO-registratie.',
                'type' => 'in-person',
                'lessons' => [
                    ['title' => 'Theorie: De geest van MGV', 'content' => 'Achtergrond en principes van motiverende gespreksvoering.'],
                    ['title' => 'Praktijk: ORBS-technieken', 'content' => 'Open vragen, Reflecteren, Bevestigen, Samenvatten.'],
                ],
                'editions' => [
                    [
                        'start_date' => date('Y-m-d', strtotime('+2 weeks')),
                        'price' => 295.00,
                        'price_non_member' => 345.00,
                        'capacity' => 16,
                        'venue' => 'VAD Opleidingscentrum Brussel',
                        'speakers' => 'Dr. Marie De Vries',
                        'status' => 'open',
                        'sessions' => [
                            ['date_offset' => 0, 'start' => '09:30', 'end' => '16:30', 'type' => SESSION_TYPE_IN_PERSON, 'title' => 'Basistraining MGV'],
                        ],
                    ],
                    [
                        'start_date' => date('Y-m-d', strtotime('+6 weeks')),
                        'price' => 295.00,
                        'price_non_member' => 345.00,
                        'capacity' => 16,
                        'venue' => 'VAD Locatie Antwerpen',
                        'speakers' => 'Drs. Peter Willems',
                        'status' => 'open',
                        'sessions' => [
                            ['date_offset' => 0, 'start' => '09:30', 'end' => '16:30', 'type' => SESSION_TYPE_IN_PERSON, 'title' => 'Basistraining MGV'],
                        ],
                    ],
                ],
            ],

            // === IN-PERSON 2: Multi-day intensive course ===
            [
                'title' => 'Cognitieve Gedragstherapie bij Verslaving',
                'description' => 'Deze driedaagse intensieve training geeft je een gedegen basis in CGT specifiek voor de verslavingszorg. Je leert functionele analyses maken, gedachten uitdagen, en terugvalpreventieplannen opstellen. De training combineert theorie met veel praktijkoefeningen, rollenspel en casusbespreking. Inclusief werkboek en online naslagmateriaal.',
                'type' => 'in-person',
                'lessons' => [
                    ['title' => 'CGT Dag 1: Functieanalyse', 'content' => 'Leer het gedragsmodel en het maken van een functionele analyse.'],
                    ['title' => 'CGT Dag 2: Gedachten uitdagen', 'content' => 'Cognitieve technieken voor het werken met automatische gedachten.'],
                    ['title' => 'CGT Dag 3: Terugvalpreventie', 'content' => 'Het opstellen en implementeren van een terugvalpreventieplan.'],
                ],
                'editions' => [
                    [
                        'start_date' => date('Y-m-d', strtotime('+3 weeks')),
                        'price' => 695.00,
                        'price_non_member' => 795.00,
                        'capacity' => 12,
                        'venue' => 'Conferentiecentrum De Factorij, Antwerpen',
                        'speakers' => 'Prof. dr. Jan Janssen, Dr. Els Peters',
                        'status' => 'open',
                        'sessions' => [
                            ['date_offset' => 0, 'start' => '09:00', 'end' => '17:00', 'type' => SESSION_TYPE_IN_PERSON, 'title' => 'Dag 1: Gedragsanalyse en functieanalyse'],
                            ['date_offset' => 7, 'start' => '09:00', 'end' => '17:00', 'type' => SESSION_TYPE_IN_PERSON, 'title' => 'Dag 2: Cognitieve interventies'],
                            ['date_offset' => 14, 'start' => '09:00', 'end' => '17:00', 'type' => SESSION_TYPE_IN_PERSON, 'title' => 'Dag 3: Terugvalpreventie en integratie'],
                        ],
                    ],
                    // Second edition - almost full
                    [
                        'start_date' => date('Y-m-d', strtotime('+2 months')),
                        'price' => 695.00,
                        'price_non_member' => 795.00,
                        'capacity' => 12,
                        'registered' => 10,
                        'venue' => 'VAD Opleidingscentrum Gent',
                        'speakers' => 'Prof. dr. Jan Janssen',
                        'status' => 'open',
                        'sessions' => [
                            ['date_offset' => 0, 'start' => '09:00', 'end' => '17:00', 'type' => SESSION_TYPE_IN_PERSON, 'title' => 'Dag 1: Gedragsanalyse'],
                            ['date_offset' => 7, 'start' => '09:00', 'end' => '17:00', 'type' => SESSION_TYPE_IN_PERSON, 'title' => 'Dag 2: Cognitieve interventies'],
                            ['date_offset' => 14, 'start' => '09:00', 'end' => '17:00', 'type' => SESSION_TYPE_IN_PERSON, 'title' => 'Dag 3: Terugvalpreventie'],
                        ],
                    ],
                ],
            ],

            // === IN-PERSON 3: Masterclass (sold out + future date) ===
            [
                'title' => 'Masterclass Crisisinterventie bij Verslaving',
                'description' => 'Deze exclusieve masterclass is bedoeld voor ervaren professionals die regelmatig te maken hebben met crisissituaties. Je leert de-escalatietechnieken, crisisassessment, en veilig handelen bij intoxicatie en onthoudingssyndroom. Maximum 8 deelnemers voor optimale interactie. Zeer populair - vaak snel volgeboekt!',
                'type' => 'in-person',
                'lessons' => [
                    ['title' => 'Crisisinterventie module', 'content' => 'Theorie en praktijk van crisisinterventie in de verslavingszorg.'],
                ],
                'editions' => [
                    [
                        'start_date' => date('Y-m-d', strtotime('+3 weeks')),
                        'price' => 450.00,
                        'price_non_member' => 525.00,
                        'capacity' => 8,
                        'registered' => 8, // FULL
                        'venue' => 'VAD Opleidingscentrum Brussel',
                        'speakers' => 'Dr. Paul Verhaeghe',
                        'status' => 'open',
                        'sessions' => [
                            ['date_offset' => 0, 'start' => '09:00', 'end' => '17:00', 'type' => SESSION_TYPE_IN_PERSON, 'title' => 'Masterclass Crisisinterventie'],
                        ],
                    ],
                    [
                        'start_date' => date('Y-m-d', strtotime('+3 months')),
                        'price' => 450.00,
                        'price_non_member' => 525.00,
                        'capacity' => 8,
                        'venue' => 'VAD Opleidingscentrum Brussel',
                        'speakers' => 'Dr. Paul Verhaeghe',
                        'status' => 'open',
                        'sessions' => [
                            ['date_offset' => 0, 'start' => '09:00', 'end' => '17:00', 'type' => SESSION_TYPE_IN_PERSON, 'title' => 'Masterclass Crisisinterventie'],
                        ],
                    ],
                ],
            ],

            // === IN-PERSON 4: Cancelled + rescheduled ===
            [
                'title' => 'Workshop Mindfulness in de Verslavingszorg',
                'description' => 'Praktische workshop over het integreren van mindfulness-technieken in de behandeling van verslaving. Je leert eenvoudige oefeningen die je direct kunt toepassen met cliënten. Geschikt voor alle hulpverleners, geen meditatie-ervaring vereist.',
                'type' => 'in-person',
                'lessons' => [
                    ['title' => 'Mindfulness workshop', 'content' => 'Praktische introductie in mindfulness voor de behandelpraktijk.'],
                ],
                'editions' => [
                    [
                        'start_date' => date('Y-m-d', strtotime('+2 weeks')),
                        'price' => 195.00,
                        'price_non_member' => 225.00,
                        'capacity' => 15,
                        'venue' => 'Yogacentrum Leuven',
                        'speakers' => 'Leen Smits, MBSR-trainer',
                        'status' => 'cancelled',
                        'sessions' => [
                            ['date_offset' => 0, 'start' => '13:00', 'end' => '17:00', 'type' => SESSION_TYPE_IN_PERSON, 'title' => 'Workshop Mindfulness'],
                        ],
                    ],
                    [
                        'start_date' => date('Y-m-d', strtotime('+6 weeks')),
                        'price' => 195.00,
                        'price_non_member' => 225.00,
                        'capacity' => 15,
                        'venue' => 'Yogacentrum Leuven',
                        'speakers' => 'Leen Smits, MBSR-trainer',
                        'status' => 'open',
                        'sessions' => [
                            ['date_offset' => 0, 'start' => '13:00', 'end' => '17:00', 'type' => SESSION_TYPE_IN_PERSON, 'title' => 'Workshop Mindfulness (nieuwe datum)'],
                        ],
                    ],
                ],
            ],

            // === IN-PERSON 5: Course with optional sessions ===
            [
                'title' => 'Preventie in de Praktijk',
                'description' => 'Complete tweedaagse cursus over preventieve interventies in verschillende settings. Na de basistraining kun je kiezen uit optionele verdiepingsmodules voor specifieke doelgroepen: jongeren of ouderen. Praktijkgericht met veel tools en materialen om direct mee aan de slag te gaan.',
                'type' => 'in-person',
                'lessons' => [
                    ['title' => 'Preventie basis', 'content' => 'Basisprincipes van verslavingspreventie.'],
                    ['title' => 'Preventie praktijk', 'content' => 'Praktische toepassing van preventieve interventies.'],
                ],
                'editions' => [
                    [
                        'start_date' => date('Y-m-d', strtotime('+5 weeks')),
                        'price' => 395.00,
                        'price_non_member' => 450.00,
                        'capacity' => 20,
                        'venue' => 'VAD Opleidingscentrum Brussel',
                        'speakers' => 'Team VAD Preventie',
                        'status' => 'open',
                        'sessions' => [
                            ['date_offset' => 0, 'start' => '09:00', 'end' => '17:00', 'type' => SESSION_TYPE_IN_PERSON, 'title' => 'Dag 1: Basisprincipes preventie', 'optional' => false],
                            ['date_offset' => 1, 'start' => '09:00', 'end' => '17:00', 'type' => SESSION_TYPE_IN_PERSON, 'title' => 'Dag 2: Praktijk en interventies', 'optional' => false],
                            ['date_offset' => 7, 'start' => '09:00', 'end' => '12:00', 'type' => SESSION_TYPE_IN_PERSON, 'title' => 'Verdieping: Jongeren en social media', 'optional' => true],
                            ['date_offset' => 7, 'start' => '13:00', 'end' => '16:00', 'type' => SESSION_TYPE_IN_PERSON, 'title' => 'Verdieping: Ouderen en medicatie', 'optional' => true],
                        ],
                    ],
                ],
            ],

            // === IN-PERSON 6: Free introductory course ===
            [
                'title' => 'Gratis Introductie: Werken bij VAD',
                'description' => 'Gratis kennismakingsochtend voor nieuwe medewerkers en stagiairs. Je maakt kennis met de werkwijze, methodieken en het aanbod van VAD. Inclusief rondleiding en ontmoeting met collega\'s uit verschillende teams.',
                'type' => 'in-person',
                'lessons' => [
                    ['title' => 'Introductie VAD', 'content' => 'Kennismaking met VAD als organisatie.'],
                ],
                'editions' => [
                    [
                        'start_date' => date('Y-m-d', strtotime('+1 week')),
                        'price' => 0.00,
                        'price_non_member' => 0.00,
                        'capacity' => 30,
                        'venue' => 'VAD Hoofdkantoor Brussel',
                        'speakers' => 'Diverse VAD medewerkers',
                        'status' => 'open',
                        'sessions' => [
                            ['date_offset' => 0, 'start' => '09:30', 'end' => '12:30', 'type' => SESSION_TYPE_IN_PERSON, 'title' => 'Introductie en rondleiding VAD'],
                        ],
                    ],
                ],
            ],

            // =========================================================================
            // HYBRID COURSES (Combination of in-person and online)
            // =========================================================================

            // === HYBRID 1: Blended learning course ===
            [
                'title' => 'Dual Diagnose: Verslaving en Psychiatrie',
                'description' => 'Hybride leertraject over de behandeling van cliënten met zowel een verslaving als een psychiatrische stoornis. Je start met een klassikale dag, vervolgens twee weken e-learning met een live webinar, en sluit af met een praktijkdag. Zo combineer je kennisoverdracht met diepgang en praktische toepassing.',
                'type' => 'hybrid',
                'lessons' => [
                    ['title' => 'E-learning Dual Diagnose Module 1', 'content' => 'Online module over comorbiditeit.'],
                    ['title' => 'E-learning Dual Diagnose Module 2', 'content' => 'Online module over geïntegreerde behandeling.'],
                ],
                'editions' => [
                    [
                        'start_date' => date('Y-m-d', strtotime('+4 weeks')),
                        'price' => 550.00,
                        'price_non_member' => 650.00,
                        'capacity' => 20,
                        'venue' => 'VAD Brussel + Online',
                        'speakers' => 'Dr. Katrien Maes, psychiater',
                        'status' => 'open',
                        'sessions' => [
                            ['date_offset' => 0, 'start' => '09:00', 'end' => '17:00', 'type' => SESSION_TYPE_IN_PERSON, 'location' => 'VAD Opleidingscentrum Brussel', 'title' => 'Introductie dual diagnose'],
                            ['date_offset' => 3, 'start' => '00:00', 'end' => '23:59', 'type' => SESSION_TYPE_ONLINE, 'title' => 'E-learning: Psychiatrische stoornissen'],
                            ['date_offset' => 7, 'start' => '14:00', 'end' => '16:00', 'type' => SESSION_TYPE_WEBINAR, 'title' => 'Live Q&A: Casusbespreking'],
                            ['date_offset' => 10, 'start' => '00:00', 'end' => '23:59', 'type' => SESSION_TYPE_ONLINE, 'title' => 'E-learning: Geïntegreerde behandeling'],
                            ['date_offset' => 14, 'start' => '09:00', 'end' => '17:00', 'type' => SESSION_TYPE_IN_PERSON, 'location' => 'VAD Opleidingscentrum Brussel', 'title' => 'Praktijkdag: Integratie en toepassing'],
                        ],
                    ],
                ],
            ],

            // =========================================================================
            // WEBINAR COURSES
            // =========================================================================

            // === WEBINAR 1: Series ===
            [
                'title' => 'Webinarreeks: Actuele Thema\'s in Verslavingszorg',
                'description' => 'Een reeks van 4 interactieve webinars over actuele onderwerpen in de verslavingszorg. Elke week een nieuw thema met een expert spreker. Je kunt live deelnemen en vragen stellen, of de opname achteraf bekijken. Inclusief handouts en extra leesmateriaal.',
                'type' => 'webinar',
                'lessons' => [
                    ['title' => 'Webinar introductie', 'content' => 'Algemene informatie over de webinarreeks.'],
                ],
                'editions' => [
                    [
                        'start_date' => date('Y-m-d', strtotime('+1 week')),
                        'price' => 150.00,
                        'price_non_member' => 180.00,
                        'capacity' => 100,
                        'venue' => 'Online (Zoom)',
                        'speakers' => 'Diverse experts',
                        'status' => 'open',
                        'sessions' => [
                            ['date_offset' => 0, 'start' => '19:00', 'end' => '20:30', 'type' => SESSION_TYPE_WEBINAR, 'title' => 'Webinar 1: Gaming en sociale media verslaving'],
                            ['date_offset' => 7, 'start' => '19:00', 'end' => '20:30', 'type' => SESSION_TYPE_WEBINAR, 'title' => 'Webinar 2: Jongeren en online gokken'],
                            ['date_offset' => 14, 'start' => '19:00', 'end' => '20:30', 'type' => SESSION_TYPE_WEBINAR, 'title' => 'Webinar 3: Medicatie-ondersteunde behandeling'],
                            ['date_offset' => 21, 'start' => '19:00', 'end' => '20:30', 'type' => SESSION_TYPE_WEBINAR, 'title' => 'Webinar 4: Herstelondersteunende zorg'],
                        ],
                    ],
                ],
            ],

            // === WEBINAR 2: Single webinar ===
            [
                'title' => 'Gratis Webinar: Lachgas - De Nieuwe Trend',
                'description' => 'Gratis informatief webinar over het toenemende lachgasgebruik onder jongeren. Wat zijn de risico\'s? Hoe herken je gebruik? En hoe ga je het gesprek aan? Speciaal voor professionals in jeugdzorg, onderwijs en preventie.',
                'type' => 'webinar',
                'lessons' => [
                    ['title' => 'Lachgas info', 'content' => 'Informatie over lachgas en de effecten.'],
                ],
                'editions' => [
                    [
                        'start_date' => date('Y-m-d', strtotime('+10 days')),
                        'price' => 0.00,
                        'price_non_member' => 0.00,
                        'capacity' => 200,
                        'venue' => 'Online (Teams)',
                        'speakers' => 'Dr. Sarah Janssen, toxicoloog',
                        'status' => 'open',
                        'sessions' => [
                            ['date_offset' => 0, 'start' => '12:00', 'end' => '13:00', 'type' => SESSION_TYPE_WEBINAR, 'title' => 'Lunchwebinar: Lachgas - feiten en risico\'s'],
                        ],
                    ],
                ],
            ],

            // =========================================================================
            // TRAJECTORY COURSES (used in trajectories)
            // =========================================================================

            // === TRAJECTORY COURSE 1: Required foundation ===
            [
                'title' => 'Verslavingskunde: Fundament',
                'description' => 'De basiscursus voor iedereen die professioneel met verslaving werkt. Dit is een verplicht onderdeel van het traject Verslavingsspecialist. Je leert de kernconcepten, hebt contact met ervaringsdeskundigen, en oefent met basis gespreksvaardigheden.',
                'type' => 'in-person',
                'trajectory_role' => 'required', // For reference when building trajectories
                'lessons' => [
                    ['title' => 'Fundament module 1', 'content' => 'Basiskennis verslavingskunde.'],
                    ['title' => 'Fundament module 2', 'content' => 'Introductie gespreksvoering.'],
                ],
                'editions' => [
                    [
                        'start_date' => date('Y-m-d', strtotime('+2 weeks')),
                        'price' => 395.00,
                        'price_non_member' => 495.00,
                        'capacity' => 16,
                        'venue' => 'VAD Opleidingscentrum Brussel',
                        'speakers' => 'Team VAD Academie',
                        'status' => 'open',
                        'sessions' => [
                            ['date_offset' => 0, 'start' => '09:00', 'end' => '17:00', 'type' => SESSION_TYPE_IN_PERSON, 'title' => 'Dag 1: Basiskennis'],
                            ['date_offset' => 1, 'start' => '09:00', 'end' => '17:00', 'type' => SESSION_TYPE_IN_PERSON, 'title' => 'Dag 2: Gespreksvaardigheden'],
                        ],
                    ],
                ],
            ],

            // === TRAJECTORY COURSE 2: Required advanced ===
            [
                'title' => 'Verslavingskunde: Assessment en Diagnostiek',
                'description' => 'Leer gestructureerd een intake afnemen, screeningsinstrumenten gebruiken en een behandelplan opstellen. Verplicht onderdeel van het traject Verslavingsspecialist.',
                'type' => 'in-person',
                'trajectory_role' => 'required',
                'lessons' => [
                    ['title' => 'Assessment theorie', 'content' => 'Screeningsinstrumenten en intake.'],
                    ['title' => 'Diagnostiek praktijk', 'content' => 'Behandelplanning.'],
                ],
                'editions' => [
                    [
                        'start_date' => date('Y-m-d', strtotime('+4 weeks')),
                        'price' => 395.00,
                        'price_non_member' => 495.00,
                        'capacity' => 14,
                        'venue' => 'VAD Opleidingscentrum Brussel',
                        'speakers' => 'Dr. Tom Decorte',
                        'status' => 'open',
                        'sessions' => [
                            ['date_offset' => 0, 'start' => '09:00', 'end' => '17:00', 'type' => SESSION_TYPE_IN_PERSON, 'title' => 'Assessment en screeningsinstrumenten'],
                            ['date_offset' => 14, 'start' => '09:00', 'end' => '17:00', 'type' => SESSION_TYPE_IN_PERSON, 'title' => 'Behandelplanning en doelen stellen'],
                        ],
                    ],
                ],
            ],

            // === TRAJECTORY COURSE 3: Optional elective 1 ===
            [
                'title' => 'Keuzecursus: Familie en Systeem',
                'description' => 'Optionele verdieping over het betrekken van het systeem bij de behandeling. Hoe werk je met partners, ouders en kinderen van mensen met een verslaving? Keuzevak voor het traject Verslavingsspecialist.',
                'type' => 'in-person',
                'trajectory_role' => 'elective',
                'lessons' => [
                    ['title' => 'Systeemwerk module', 'content' => 'Werken met het systeem rondom de cliënt.'],
                ],
                'editions' => [
                    [
                        'start_date' => date('Y-m-d', strtotime('+8 weeks')),
                        'price' => 245.00,
                        'price_non_member' => 295.00,
                        'capacity' => 16,
                        'venue' => 'VAD Locatie Antwerpen',
                        'speakers' => 'Drs. Lies Verhoeven, systeemtherapeut',
                        'status' => 'open',
                        'sessions' => [
                            ['date_offset' => 0, 'start' => '09:00', 'end' => '17:00', 'type' => SESSION_TYPE_IN_PERSON, 'title' => 'Familie en systeemwerk'],
                        ],
                    ],
                ],
            ],

            // === TRAJECTORY COURSE 4: Optional elective 2 ===
            [
                'title' => 'Keuzecursus: Groepsbehandeling',
                'description' => 'Optionele verdieping in het geven van groepstherapie bij verslaving. Je leert groepsdynamica begrijpen, sessies structureren en omgaan met weerstand in de groep. Keuzevak voor het traject Verslavingsspecialist.',
                'type' => 'in-person',
                'trajectory_role' => 'elective',
                'lessons' => [
                    ['title' => 'Groepsdynamica', 'content' => 'Theorie en praktijk van groepstherapie.'],
                ],
                'editions' => [
                    [
                        'start_date' => date('Y-m-d', strtotime('+9 weeks')),
                        'price' => 245.00,
                        'price_non_member' => 295.00,
                        'capacity' => 12,
                        'venue' => 'VAD Opleidingscentrum Gent',
                        'speakers' => 'Dr. Kris Goethals',
                        'status' => 'open',
                        'sessions' => [
                            ['date_offset' => 0, 'start' => '09:00', 'end' => '17:00', 'type' => SESSION_TYPE_IN_PERSON, 'title' => 'Groepsbehandeling bij verslaving'],
                        ],
                    ],
                ],
            ],

            // === TRAJECTORY COURSE 5: Optional elective 3 ===
            [
                'title' => 'Keuzecursus: Harm Reduction',
                'description' => 'Verdieping in harm reduction benaderingen. Van spuitenruil tot drugscheck, van gebruikersruimtes tot naloxon-distributie. Keuzevak geschikt voor preventiewerkers en behandelaars.',
                'type' => 'in-person',
                'trajectory_role' => 'elective',
                'lessons' => [
                    ['title' => 'Harm reduction module', 'content' => 'Principes en praktijk van schadebeperking.'],
                ],
                'editions' => [
                    [
                        'start_date' => date('Y-m-d', strtotime('+10 weeks')),
                        'price' => 195.00,
                        'price_non_member' => 245.00,
                        'capacity' => 20,
                        'venue' => 'VAD Hoofdkantoor Brussel',
                        'speakers' => 'Peter Vander Laenen',
                        'status' => 'open',
                        'sessions' => [
                            ['date_offset' => 0, 'start' => '09:30', 'end' => '16:30', 'type' => SESSION_TYPE_IN_PERSON, 'title' => 'Harm reduction: theorie en praktijk'],
                        ],
                    ],
                ],
            ],
        ];

        foreach ($courses as $courseData) {
            $this->createCourseWithEditions($courseData);
        }
    }

    private function createCourseWithEditions(array $courseData): void {
        // Check if exists
        $existing = get_page_by_title($courseData['title'], OBJECT, 'sfwd-courses');
        if ($existing) {
            echo "  - Course '{$courseData['title']}' exists\n";
            $this->created['courses'][] = $existing->ID;
            return;
        }

        // Create course
        $courseId = wp_insert_post([
            'post_title' => $courseData['title'],
            'post_type' => 'sfwd-courses',
            'post_status' => 'publish',
            'post_content' => $courseData['description'],
        ]);

        if (is_wp_error($courseId)) {
            echo "  ! Failed: {$courseId->get_error_message()}\n";
            return;
        }

        update_post_meta($courseId, self::SEED_META_KEY, true);
        update_post_meta($courseId, '_sfwd-courses', ['course_price_type' => 'open']);
        update_post_meta($courseId, '_course_type', $courseData['type']);

        // Assign course category based on type
        $category = ($courseData['type'] === 'online') ? 'online' : 'in-person';
        wp_set_object_terms($courseId, $category, 'ld_course_category');

        $this->created['courses'][] = $courseId;
        echo "  + Course: {$courseData['title']} (ID: {$courseId}) [{$category}]\n";

        // Create lessons (use provided lessons or generate defaults)
        $lessonIds = $this->createLessonsForCourse($courseId, $courseData['title'], $courseData['lessons'] ?? []);

        // Create editions
        foreach ($courseData['editions'] as $editionData) {
            $this->createEdition($courseId, $courseData['title'], $editionData);
        }
    }

    private function createEdition(int $courseId, string $courseTitle, array $data): void {
        if (!$this->editionRepository) return;

        $postTitle = $courseTitle . ' - ' . date('j M Y', strtotime($data['start_date']));
        $endDate = $data['end_date'] ?? null;
        if (!$endDate && !empty($data['sessions'])) {
            $maxOffset = max(array_column($data['sessions'], 'date_offset'));
            $endDate = date('Y-m-d', strtotime($data['start_date'] . " +{$maxOffset} days"));
        }
        $endDate = $endDate ?? $data['start_date'];

        $result = $this->editionRepository->create([
            'title' => $postTitle,
            'post_status' => 'publish',
            'course_id' => $courseId,
            'start_date' => $data['start_date'],
            'end_date' => $endDate,
            'price' => $data['price'],
            'price_non_member' => $data['price_non_member'],
            'capacity' => $data['capacity'],
            'venue' => $data['venue'],
            'speakers' => $data['speakers'] ?? '',
            'status' => $data['status'],
        ]);

        if (is_wp_error($result)) {
            echo "    ! Edition failed: {$result->get_error_message()}\n";
            return;
        }

        $editionId = $result->ID;
        update_post_meta($editionId, self::SEED_META_KEY, true);

        // Simulate registrations if specified
        if (!empty($data['registered']) && $this->regRepo) {
            for ($i = 0; $i < $data['registered']; $i++) {
                // Create dummy registrations to fill capacity
                global $wpdb;
                $wpdb->insert(
                    $wpdb->prefix . 'vad_registrations',
                    [
                        'user_id' => 0, // dummy
                        'edition_id' => $editionId,
                        'status' => 'confirmed',
                        'enrollment_path' => 'individual',
                        'notes' => 'Seed placeholder',
                        'registered_at' => current_time('mysql'),
                    ]
                );
            }
        }

        $this->created['editions'][] = $editionId;
        echo "    + Edition: {$data['start_date']} at {$data['venue']} (ID: {$editionId})\n";

        // Create sessions
        foreach ($data['sessions'] as $sessionData) {
            $this->createSession($editionId, $data, $sessionData);
        }
    }

    private function createSession(int $editionId, array $editionData, array $sessionData): void {
        if (!$this->sessionService) return;

        $sessionDate = date('Y-m-d', strtotime($editionData['start_date'] . " +{$sessionData['date_offset']} days"));

        $createData = [
            'edition_id' => $editionId,
            'date' => $sessionDate,
            'start_time' => $sessionData['start'],
            'end_time' => $sessionData['end'],
            'type' => $sessionData['type'],
            'location' => $sessionData['location'] ?? $editionData['venue'],
            'optional' => $sessionData['optional'] ?? false,
        ];

        if (!empty($sessionData['title'])) {
            $createData['title'] = $sessionData['title'];
        }

        $sessionId = $this->sessionService->createSession($createData);

        if (is_wp_error($sessionId)) {
            echo "      ! Session failed: {$sessionId->get_error_message()}\n";
            return;
        }

        update_post_meta($sessionId, self::SEED_META_KEY, true);
        $this->created['sessions'][] = $sessionId;

        $typeEmoji = match($sessionData['type']) {
            SESSION_TYPE_ONLINE => '💻',
            SESSION_TYPE_WEBINAR => '📹',
            SESSION_TYPE_ASSIGNMENT => '📝',
            default => '🏢',
        };
        $title = $sessionData['title'] ?? $sessionData['type'];
        echo "      {$typeEmoji} {$sessionDate} {$sessionData['start']}-{$sessionData['end']}: {$title}\n";
    }

    private function createLessonsForCourse(int $courseId, string $courseTitle, array $lessonData = []): array {
        $lessonIds = [];

        // Use provided lessons or generate defaults
        if (empty($lessonData)) {
            $lessonData = [
                ['title' => 'Introductie', 'content' => "Welkom bij deze cursus. In deze module maak je kennis met de leerdoelen en opzet van {$courseTitle}."],
                ['title' => 'Theorie', 'content' => "In deze module behandelen we de theoretische basis van {$courseTitle}."],
                ['title' => 'Praktijk', 'content' => "Tijd om de theorie in praktijk te brengen met oefeningen en casussen."],
                ['title' => 'Evaluatie', 'content' => "Toets je kennis en rond de cursus af met een certificaat."],
            ];
        }

        foreach ($lessonData as $index => $lesson) {
            $lessonId = wp_insert_post([
                'post_title' => $lesson['title'],
                'post_type' => 'sfwd-lessons',
                'post_status' => 'publish',
                'post_content' => $lesson['content'],
                'menu_order' => $index + 1,
            ]);

            if (!is_wp_error($lessonId)) {
                update_post_meta($lessonId, self::SEED_META_KEY, true);
                update_post_meta($lessonId, 'course_id', $courseId);
                update_post_meta($lessonId, '_sfwd-lessons', ['sfwd-lessons_course' => $courseId]);
                $lessonIds[] = $lessonId;
                echo "      📖 Lesson: {$lesson['title']}\n";
            }
        }

        // Link lessons to course using LearnDash's expected structure
        if (!empty($lessonIds) && class_exists('LDLMS_Factory_Post')) {
            $courseStepsObj = LDLMS_Factory_Post::course_steps($courseId);
            if ($courseStepsObj) {
                // LearnDash expects steps grouped by post type
                $steps = ['sfwd-lessons' => []];
                foreach ($lessonIds as $lessonId) {
                    $steps['sfwd-lessons'][$lessonId] = [];
                }
                $courseStepsObj->set_steps($steps);
            }
        }

        return $lessonIds;
    }

    private function createTrajectories(): void {
        echo "\nCreating trajectories with opt-in courses...\n";

        if (!$this->trajectoryService) {
            echo "  ! TrajectoryService not available\n";
            return;
        }

        if (count($this->created['courses']) < 12) {
            echo "  ! Not enough courses for trajectories (need 12, have " . count($this->created['courses']) . ")\n";
            return;
        }

        $courseIds = $this->created['courses'];

        // Find courses by their trajectory role (matching indices to course definitions)
        // Indices in order of course definitions:
        // 0-2: Online courses (E-learning Basiskennis, Alcohol, Cannabis)
        // 3: Motiverende Gespreksvoering
        // 4: CGT bij Verslaving
        // 5: Masterclass Crisisinterventie
        // 6: Workshop Mindfulness
        // 7: Preventie in de Praktijk
        // 8: Gratis Introductie
        // 9: Dual Diagnose (hybrid)
        // 10: Webinarreeks
        // 11: Gratis Webinar Lachgas
        // 12: Verslavingskunde Fundament (trajectory required)
        // 13: Assessment en Diagnostiek (trajectory required)
        // 14: Keuzecursus Familie en Systeem (trajectory elective)
        // 15: Keuzecursus Groepsbehandeling (trajectory elective)
        // 16: Keuzecursus Harm Reduction (trajectory elective)

        // === TRAJECTORY 1: COHORT - Verslavingsspecialist (full professional training) ===
        $cohortData = [
            'title' => 'Traject Verslavingsspecialist',
            'post_content' => '<p>Word een gecertificeerd verslavingsspecialist met dit intensieve cohort-traject. Je volgt de opleiding samen met een vaste groep van maximaal 15 deelnemers, wat zorgt voor een hecht leernetwerk.</p>

<h3>Programma</h3>
<p>Het traject bestaat uit <strong>4 verplichte cursussen</strong> die de basis vormen, plus <strong>2 keuzecursussen</strong> waarin je je kunt specialiseren naar eigen interesse.</p>

<h4>Verplichte cursussen</h4>
<ul>
<li>Verslavingskunde: Fundament (2 dagen)</li>
<li>Assessment en Diagnostiek (2 dagen)</li>
<li>Motiverende Gespreksvoering (1 dag)</li>
<li>CGT bij Verslaving (3 dagen)</li>
</ul>

<h4>Keuzecursussen (kies 2 uit 3)</h4>
<ul>
<li>Familie en Systeem</li>
<li>Groepsbehandeling</li>
<li>Harm Reduction</li>
</ul>

<h3>Certificering</h3>
<p>Na afronding van alle verplichte cursussen en 2 keuzecursussen ontvang je het certificaat "Verslavingsspecialist VAD".</p>',
            'mode' => TrajectoryMode::Cohort->value,
            'status' => TrajectoryStatus::Open->value,
            'enrollment_deadline' => date('Y-m-d', strtotime('+1 month')),
            'choice_available_date' => date('Y-m-d', strtotime('+2 weeks')),
            'choice_deadline' => date('Y-m-d', strtotime('+3 weeks')),
            'capacity' => 15,
            'price' => 1695.00,
            'price_non_member' => 1995.00,
            'courses' => json_encode([
                // Required foundation courses
                ['course_id' => $courseIds[12], 'required' => true, 'order' => 1],
                ['course_id' => $courseIds[13], 'required' => true, 'order' => 2],
                ['course_id' => $courseIds[3], 'required' => true, 'order' => 3], // MGV
                ['course_id' => $courseIds[4], 'required' => true, 'order' => 4], // CGT
                // Elective courses (choose 2 of 3)
                ['course_id' => $courseIds[14], 'required' => false, 'group' => 'Keuzecursussen (kies 2)', 'min_choices' => 2],
                ['course_id' => $courseIds[15], 'required' => false, 'group' => 'Keuzecursussen (kies 2)', 'min_choices' => 2],
                ['course_id' => $courseIds[16], 'required' => false, 'group' => 'Keuzecursussen (kies 2)', 'min_choices' => 2],
            ]),
        ];

        $cohortId = $this->trajectoryService->createTrajectory($cohortData);
        if (!is_wp_error($cohortId)) {
            update_post_meta($cohortId, self::SEED_META_KEY, true);
            $this->created['trajectories'][] = $cohortId;
            echo "  + Cohort trajectory: Traject Verslavingsspecialist (ID: {$cohortId})\n";
            echo "      Required: Fundament, Assessment, MGV, CGT\n";
            echo "      Electives (choose 2): Familie, Groepsbehandeling, Harm Reduction\n";
        } else {
            echo "  ! Cohort trajectory failed: {$cohortId->get_error_message()}\n";
        }

        // === TRAJECTORY 2: SELF-PACED - Preventiewerker (flexible online-first) ===
        $selfPacedData = [
            'title' => 'Traject Preventiewerker',
            'post_content' => '<p>Flexibel traject voor (toekomstige) preventiewerkers. Combineer online cursussen met praktijkdagen, in je eigen tempo.</p>

<h3>Waarom dit traject?</h3>
<p>Dit traject is ideaal voor mensen die:</p>
<ul>
<li>Willen starten in de preventie</li>
<li>Hun preventievaardigheden willen versterken</li>
<li>Flexibel willen leren naast hun werk</li>
</ul>

<h3>Programma</h3>
<h4>Verplichte onderdelen</h4>
<ul>
<li>E-learning: Basiskennis Verslavingszorg (online, eigen tempo)</li>
<li>E-learning: Cannabis - Feiten en Fabels (online, eigen tempo)</li>
<li>Preventie in de Praktijk (2 dagen klassikaal)</li>
</ul>

<h4>Optionele verdieping</h4>
<p>Na de verplichte onderdelen kun je optioneel de volgende cursussen volgen voor verdere verdieping:</p>
<ul>
<li>E-learning: Alcohol en Gezondheid</li>
<li>Webinarreeks: Actuele Thema\'s</li>
</ul>

<h3>Certificering</h3>
<p>Na afronding van de verplichte onderdelen ontvang je het certificaat "Preventiewerker VAD Basis".</p>',
            'mode' => TrajectoryMode::SelfPaced->value,
            'status' => TrajectoryStatus::Open->value,
            'enrollment_deadline' => '', // no deadline for self-paced
            'capacity' => 0, // unlimited
            'price' => 595.00,
            'price_non_member' => 745.00,
            'courses' => json_encode([
                // Required courses
                ['course_id' => $courseIds[0], 'required' => true, 'order' => 1], // E-learning Basiskennis
                ['course_id' => $courseIds[2], 'required' => true, 'order' => 2], // E-learning Cannabis
                ['course_id' => $courseIds[7], 'required' => true, 'order' => 3], // Preventie in de Praktijk
                // Optional additions
                ['course_id' => $courseIds[1], 'required' => false, 'group' => 'Optionele verdieping'], // E-learning Alcohol
                ['course_id' => $courseIds[10], 'required' => false, 'group' => 'Optionele verdieping'], // Webinarreeks
            ]),
        ];

        $selfPacedId = $this->trajectoryService->createTrajectory($selfPacedData);
        if (!is_wp_error($selfPacedId)) {
            update_post_meta($selfPacedId, self::SEED_META_KEY, true);
            $this->created['trajectories'][] = $selfPacedId;
            echo "  + Self-paced trajectory: Traject Preventiewerker (ID: {$selfPacedId})\n";
            echo "      Required: Basiskennis (online), Cannabis (online), Preventie (klassikaal)\n";
            echo "      Optional: Alcohol (online), Webinarreeks\n";
        } else {
            echo "  ! Self-paced trajectory failed: {$selfPacedId->get_error_message()}\n";
        }

        // === TRAJECTORY 3: BLENDED - Kennismakingstraject (entry-level) ===
        $blendedData = [
            'title' => 'Kennismakingstraject Verslavingszorg',
            'post_content' => '<p>Laagdrempelig kennismakingstraject voor mensen die willen ontdekken of de verslavingszorg iets voor hen is. Combinatie van gratis en betaalde onderdelen.</p>

<h3>Voor wie?</h3>
<ul>
<li>Studenten die stage overwegen</li>
<li>Professionals uit aanverwante sectoren</li>
<li>Vrijwilligers en ervaringsdeskundigen</li>
</ul>

<h3>Programma</h3>
<h4>Startonderdelen (gratis)</h4>
<ul>
<li>Gratis Introductie: Werken bij VAD</li>
<li>Gratis Webinar: Lachgas - De Nieuwe Trend</li>
</ul>

<h4>Verdieping (optioneel, betaald)</h4>
<p>Na de gratis introductie kun je ervoor kiezen om door te gaan met:</p>
<ul>
<li>E-learning: Basiskennis Verslavingszorg</li>
<li>Motiverende Gespreksvoering</li>
</ul>

<h3>Doorstromen</h3>
<p>Na dit kennismakingstraject kun je doorstromen naar het volledige Traject Verslavingsspecialist of Traject Preventiewerker.</p>',
            'mode' => TrajectoryMode::SelfPaced->value,
            'status' => TrajectoryStatus::Open->value,
            'enrollment_deadline' => '',
            'capacity' => 0,
            'price' => 0.00, // Free entry
            'price_non_member' => 0.00,
            'courses' => json_encode([
                // Free required start
                ['course_id' => $courseIds[8], 'required' => true, 'order' => 1], // Gratis Introductie
                ['course_id' => $courseIds[11], 'required' => true, 'order' => 2], // Gratis Webinar Lachgas
                // Optional paid expansion
                ['course_id' => $courseIds[0], 'required' => false, 'group' => 'Optionele verdieping (betaald)'], // Basiskennis
                ['course_id' => $courseIds[3], 'required' => false, 'group' => 'Optionele verdieping (betaald)'], // MGV
            ]),
        ];

        $blendedId = $this->trajectoryService->createTrajectory($blendedData);
        if (!is_wp_error($blendedId)) {
            update_post_meta($blendedId, self::SEED_META_KEY, true);
            $this->created['trajectories'][] = $blendedId;
            echo "  + Entry trajectory: Kennismakingstraject Verslavingszorg (ID: {$blendedId})\n";
            echo "      Required (free): Introductie, Lachgas Webinar\n";
            echo "      Optional (paid): Basiskennis, MGV\n";
        } else {
            echo "  ! Entry trajectory failed: {$blendedId->get_error_message()}\n";
        }
    }

    private function createVouchers(): void {
        echo "\nCreating vouchers...\n";

        $voucherService = null;
        if (function_exists('ntdst_get') && class_exists(\Stride\Modules\Invoicing\VoucherService::class)) {
            $voucherService = ntdst_get(\Stride\Modules\Invoicing\VoucherService::class);
        }

        $vouchers = [
            ['code' => 'WELKOM2026', 'discount_type' => 'percentage', 'discount_value' => 10, 'usage_limit' => 50],
            ['code' => 'VAD-MEMBER', 'discount_type' => 'percentage', 'discount_value' => 15, 'usage_limit' => 100],
            ['code' => 'GRATIS-INTRO', 'discount_type' => 'full', 'discount_value' => 0, 'usage_limit' => 5],
            ['code' => 'KORTING50', 'discount_type' => 'fixed', 'discount_value' => 5000, 'usage_limit' => 20],
            ['code' => 'SEEDVOUCHER10', 'discount_type' => 'percentage', 'discount_value' => 10, 'usage_limit' => 100],
        ];

        foreach ($vouchers as $data) {
            if ($voucherService) {
                $existing = $voucherService->getVoucherByCode($data['code']);
                if ($existing) {
                    $this->created['vouchers'][] = $existing['id'];
                    echo "  - Voucher {$data['code']} exists\n";
                    continue;
                }

                $result = $voucherService->createVoucher($data);
                if (!is_wp_error($result)) {
                    update_post_meta($result, self::SEED_META_KEY, true);
                    $this->created['vouchers'][] = $result;
                    echo "  + Voucher: {$data['code']} (ID: {$result})\n";
                }
            } else {
                $voucherId = wp_insert_post([
                    'post_title' => $data['code'],
                    'post_type' => 'vad_voucher',
                    'post_status' => 'publish',
                ]);

                if (!is_wp_error($voucherId)) {
                    update_post_meta($voucherId, self::SEED_META_KEY, true);
                    update_post_meta($voucherId, 'code', $data['code']);
                    update_post_meta($voucherId, 'discount_type', $data['discount_type']);
                    update_post_meta($voucherId, 'discount_value', $data['discount_value']);
                    update_post_meta($voucherId, 'usage_limit', $data['usage_limit']);
                    update_post_meta($voucherId, 'used_count', 0);
                    update_post_meta($voucherId, 'status', 'active');
                    $this->created['vouchers'][] = $voucherId;
                    echo "  + Voucher: {$data['code']} (ID: {$voucherId})\n";
                }
            }
        }
    }

    private function createEnrollmentsAndQuotes(): void {
        echo "\nCreating enrollments and quotes for admin...\n";

        if (empty($this->created['editions'])) {
            echo "  ! No editions available\n";
            return;
        }

        // Get open editions (skip cancelled) - meta uses _ntdst_ prefix
        $openEditions = [];
        foreach ($this->created['editions'] as $editionId) {
            $status = get_post_meta($editionId, '_ntdst_status', true);
            if ($status === 'open') {
                $openEditions[] = $editionId;
            }
        }

        if (empty($openEditions)) {
            echo "  ! No open editions\n";
            return;
        }

        // Enroll admin in first 3 editions
        $enrollEditions = array_slice($openEditions, 0, 3);

        foreach ($enrollEditions as $editionId) {
            // Create registration
            if ($this->regRepo) {
                $regId = $this->regRepo->create([
                    'user_id' => $this->adminUserId,
                    'edition_id' => $editionId,
                    'status' => RegistrationStatus::Confirmed->value,
                    'enrollment_path' => RegistrationRepository::PATH_INDIVIDUAL,
                    'notes' => 'Seeded for admin testing',
                ]);

                if (!is_wp_error($regId)) {
                    $this->created['registrations'][] = $regId;
                    echo "  + Registration for edition {$editionId}\n";

                    // Grant LearnDash access
                    if ($this->editionService && function_exists('ld_update_course_access')) {
                        $courseId = $this->editionService->getCourseId($editionId);
                        if ($courseId) {
                            ld_update_course_access($this->adminUserId, $courseId, false);
                        }
                    }
                }
            }

            // Create quote
            $this->createQuoteForEdition($editionId, $this->adminUserId);
        }

        // Also enroll seed students
        foreach ($this->created['users'] as $userId) {
            $user = get_user_by('ID', $userId);
            if (!$user || strpos($user->user_login, 'seed_student') === false) {
                continue;
            }

            // Enroll in 1-2 random editions
            $randomEditions = array_rand(array_flip($openEditions), min(2, count($openEditions)));
            if (!is_array($randomEditions)) $randomEditions = [$randomEditions];

            foreach ($randomEditions as $editionId) {
                if ($this->regRepo) {
                    $regId = $this->regRepo->create([
                        'user_id' => $userId,
                        'edition_id' => $editionId,
                        'status' => RegistrationStatus::Confirmed->value,
                        'enrollment_path' => RegistrationRepository::PATH_INDIVIDUAL,
                    ]);

                    if (!is_wp_error($regId)) {
                        $this->created['registrations'][] = $regId;
                        echo "  + Registration: {$user->user_login} -> edition {$editionId}\n";
                    }
                }

                // Create quote for student
                $this->createQuoteForEdition($editionId, $userId);
            }
        }
    }

    private function createQuoteForEdition(int $editionId, int $userId): void {
        $user = get_user_by('ID', $userId);
        $price = (float) (get_post_meta($editionId, 'price', true) ?: 299.00);
        $tax = round($price * 0.21, 2);

        $quoteNumber = sprintf('Q-%04d', rand(1000, 9999));
        $statuses = ['draft', 'sent', 'exported'];
        $status = $statuses[array_rand($statuses)];

        $quoteId = wp_insert_post([
            'post_title' => "Quote {$quoteNumber} - {$user->display_name}",
            'post_type' => 'vad_quote',
            'post_status' => 'publish',
        ]);

        if (is_wp_error($quoteId)) {
            return;
        }

        update_post_meta($quoteId, self::SEED_META_KEY, true);
        update_post_meta($quoteId, 'quote_number', $quoteNumber);
        update_post_meta($quoteId, 'user_id', $userId);
        update_post_meta($quoteId, 'edition_id', $editionId);
        update_post_meta($quoteId, 'status', $status);
        update_post_meta($quoteId, 'subtotal', (int) ($price * 100));
        update_post_meta($quoteId, 'tax', (int) ($tax * 100));
        update_post_meta($quoteId, 'total', (int) (($price + $tax) * 100));
        update_post_meta($quoteId, 'valid_until', date('Y-m-d', strtotime('+30 days')));

        $items = [[
            'type' => 'edition',
            'id' => $editionId,
            'title' => get_the_title($editionId),
            'unit_price' => (int) ($price * 100),
            'quantity' => 1,
            'total' => (int) ($price * 100),
        ]];
        update_post_meta($quoteId, 'items', wp_json_encode($items));

        $billing = [
            'name' => $user->display_name,
            'email' => $user->user_email,
        ];
        update_post_meta($quoteId, 'billing', wp_json_encode($billing));

        if ($status !== 'draft') {
            update_post_meta($quoteId, 'sent_at', current_time('mysql'));
        }
        if ($status === 'exported') {
            update_post_meta($quoteId, 'locked', '1');
        }

        $this->created['quotes'][] = $quoteId;
        echo "  + Quote: {$quoteNumber} ({$status})\n";
    }

    /**
     * Create trajectory-specific test data for E2E tests.
     *
     * Creates:
     * - test-trajectory (cohort mode, open status)
     * - seed_enrolled_user (enrolled in test-trajectory)
     * - seed_completed_user (completed the trajectory)
     */
    private function createTrajectoryTestData(): void {
        echo "\nCreating trajectory test data for E2E tests...\n";

        // Get or create test trajectory
        $testTrajectory = get_page_by_path('test-trajectory', OBJECT, 'vad_trajectory');
        if (!$testTrajectory) {
            $testTrajectoryId = wp_insert_post([
                'post_type' => 'vad_trajectory',
                'post_title' => 'Test Trajectory',
                'post_name' => 'test-trajectory',
                'post_status' => 'publish',
                'post_content' => 'A test trajectory for E2E testing. This trajectory is used to verify enrollment flows.',
            ]);

            if (is_wp_error($testTrajectoryId)) {
                echo "  ! Failed to create test-trajectory: {$testTrajectoryId->get_error_message()}\n";
                return;
            }

            // Set trajectory meta using Data Manager if available
            if (function_exists('ntdst_data')) {
                $trajectoryModel = ntdst_data()->get('vad_trajectory');
                if ($trajectoryModel) {
                    $trajectoryModel->updateMetaBatch($testTrajectoryId, [
                        'mode' => TrajectoryMode::Cohort->value,
                        'status' => TrajectoryStatus::Open->value,
                        'price' => 500,
                        'price_non_member' => 600,
                        'enrollment_deadline' => date('Y-m-d', strtotime('+30 days')),
                        'capacity' => 20,
                    ]);
                }
            } else {
                // Fallback to direct meta
                update_post_meta($testTrajectoryId, '_ntdst_mode', TrajectoryMode::Cohort->value);
                update_post_meta($testTrajectoryId, '_ntdst_status', TrajectoryStatus::Open->value);
                update_post_meta($testTrajectoryId, '_ntdst_price', 500);
                update_post_meta($testTrajectoryId, '_ntdst_price_non_member', 600);
                update_post_meta($testTrajectoryId, '_ntdst_enrollment_deadline', date('Y-m-d', strtotime('+30 days')));
                update_post_meta($testTrajectoryId, '_ntdst_capacity', 20);
            }

            update_post_meta($testTrajectoryId, self::SEED_META_KEY, true);
            $this->created['trajectories'][] = $testTrajectoryId;
            echo "  + Created test-trajectory (ID: {$testTrajectoryId})\n";
        } else {
            $testTrajectoryId = $testTrajectory->ID;
            if (!in_array($testTrajectoryId, $this->created['trajectories'], true)) {
                $this->created['trajectories'][] = $testTrajectoryId;
            }
            echo "  - test-trajectory already exists (ID: {$testTrajectoryId})\n";
        }

        // Create enrolled test user
        $enrolledUser = get_user_by('email', 'seed_enrolled_user@seed.test');
        if (!$enrolledUser) {
            $enrolledUserId = wp_insert_user([
                'user_login' => 'seed_enrolled_user',
                'user_email' => 'seed_enrolled_user@seed.test',
                'user_pass' => 'seedpass123',
                'first_name' => 'Enrolled',
                'last_name' => 'User',
                'display_name' => 'Enrolled User',
                'role' => 'subscriber',
            ]);

            if (is_wp_error($enrolledUserId)) {
                echo "  ! Failed to create seed_enrolled_user: {$enrolledUserId->get_error_message()}\n";
            } else {
                update_user_meta($enrolledUserId, self::SEED_META_KEY, true);
                // Activate for auth
                update_user_meta($enrolledUserId, 'ntdst_auth_activated', true);
                update_user_meta($enrolledUserId, 'ntdst_auth_activated_at', time());
                $this->created['users'][] = $enrolledUserId;
                echo "  + Created seed_enrolled_user@seed.test (ID: {$enrolledUserId})\n";
            }
        } else {
            $enrolledUserId = $enrolledUser->ID;
            if (!in_array($enrolledUserId, $this->created['users'], true)) {
                $this->created['users'][] = $enrolledUserId;
            }
            // Ensure existing user is activated
            if (!get_user_meta($enrolledUserId, 'ntdst_auth_activated', true)) {
                update_user_meta($enrolledUserId, 'ntdst_auth_activated', true);
                update_user_meta($enrolledUserId, 'ntdst_auth_activated_at', time());
            }
            echo "  - seed_enrolled_user@seed.test already exists (ID: {$enrolledUserId})\n";
        }

        // Create completed test user
        $completedUser = get_user_by('email', 'seed_completed_user@seed.test');
        if (!$completedUser) {
            $completedUserId = wp_insert_user([
                'user_login' => 'seed_completed_user',
                'user_email' => 'seed_completed_user@seed.test',
                'user_pass' => 'seedpass123',
                'first_name' => 'Completed',
                'last_name' => 'User',
                'display_name' => 'Completed User',
                'role' => 'subscriber',
            ]);

            if (is_wp_error($completedUserId)) {
                echo "  ! Failed to create seed_completed_user: {$completedUserId->get_error_message()}\n";
            } else {
                update_user_meta($completedUserId, self::SEED_META_KEY, true);
                // Activate for auth
                update_user_meta($completedUserId, 'ntdst_auth_activated', true);
                update_user_meta($completedUserId, 'ntdst_auth_activated_at', time());
                $this->created['users'][] = $completedUserId;
                echo "  + Created seed_completed_user@seed.test (ID: {$completedUserId})\n";
            }
        } else {
            $completedUserId = $completedUser->ID;
            if (!in_array($completedUserId, $this->created['users'], true)) {
                $this->created['users'][] = $completedUserId;
            }
            // Ensure existing user is activated
            if (!get_user_meta($completedUserId, 'ntdst_auth_activated', true)) {
                update_user_meta($completedUserId, 'ntdst_auth_activated', true);
                update_user_meta($completedUserId, 'ntdst_auth_activated_at', time());
            }
            echo "  - seed_completed_user@seed.test already exists (ID: {$completedUserId})\n";
        }

        echo "Trajectory test data complete.\n";
    }

    private function saveSeedManifest(): void {
        update_option('stride_seed_manifest', $this->created);
        update_option('stride_seed_timestamp', current_time('mysql'));
    }

    private function printSummary(): void {
        echo "\n=== Seed Complete ===\n\n";
        echo "Created:\n";
        echo "  - Users: " . count($this->created['users']) . "\n";
        echo "  - Courses: " . count($this->created['courses']) . "\n";
        echo "  - Editions: " . count($this->created['editions']) . "\n";
        echo "  - Sessions: " . count($this->created['sessions']) . "\n";
        echo "  - Trajectories: " . count($this->created['trajectories']) . "\n";
        echo "  - Registrations: " . count($this->created['registrations']) . "\n";
        echo "  - Vouchers: " . count($this->created['vouchers']) . "\n";
        echo "  - Quotes: " . count($this->created['quotes']) . "\n";
        echo "\n";

        echo "=== Course Types ===\n";
        echo "  Online (self-paced, no editions):\n";
        echo "    - E-learning: Basiskennis Verslavingszorg (5 lessons)\n";
        echo "    - E-learning: Alcohol en Gezondheid (5 lessons)\n";
        echo "    - E-learning: Cannabis - Feiten en Fabels (3 lessons)\n";
        echo "\n";
        echo "  In-Person (with editions):\n";
        echo "    - Motiverende Gespreksvoering (2 editions: Brussel, Antwerpen)\n";
        echo "    - CGT bij Verslaving (2 editions, 3-day intensive)\n";
        echo "    - Masterclass Crisisinterventie (1 full, 1 available)\n";
        echo "    - Workshop Mindfulness (1 cancelled, 1 rescheduled)\n";
        echo "    - Preventie in de Praktijk (optional sessions)\n";
        echo "    - Gratis Introductie (free course)\n";
        echo "\n";
        echo "  Hybrid (in-person + online):\n";
        echo "    - Dual Diagnose (klassikaal + e-learning + webinar)\n";
        echo "\n";
        echo "  Webinar:\n";
        echo "    - Webinarreeks: Actuele Thema's (4 sessions)\n";
        echo "    - Gratis Webinar: Lachgas (free, single session)\n";
        echo "\n";

        echo "=== Trajectories ===\n";
        echo "  1. Traject Verslavingsspecialist (cohort)\n";
        echo "     Required: 4 courses | Electives: choose 2 of 3\n";
        echo "  2. Traject Preventiewerker (self-paced)\n";
        echo "     Required: 3 courses | Optional: 2 courses\n";
        echo "  3. Kennismakingstraject (free entry)\n";
        echo "     Required: 2 free courses | Optional: 2 paid courses\n";
        echo "\n";

        echo "=== Test Credentials ===\n";
        echo "  Password for all seed users: seedpass123\n";
        echo "  - seed_admin@seed.test (admin)\n";
        echo "  - instructor@seed.test (group_leader)\n";
        echo "  - student1@seed.test, student2@seed.test, student3@seed.test\n";
        echo "\n";

        echo "=== Voucher Codes ===\n";
        echo "  - WELKOM2026 (10% off)\n";
        echo "  - VAD-MEMBER (15% off)\n";
        echo "  - GRATIS-INTRO (100% off)\n";
        echo "  - KORTING50 (€50 off)\n";
        echo "  - SEEDVOUCHER10 (10% off)\n";
        echo "\n";

        echo "To cleanup: ddev exec bash -c 'FORCE_UNSEED=1 wp eval-file scripts/unseed.php'\n";
    }
}

// Run
$seeder = new StrideSeedData();
$seeder->run();
