<?php defined('ABSPATH') || exit; ?>
<div class="wrap stride-guide">

<?php stride_tool_header(
    'Handleiding',
    'Welkom bij Stride. Deze handleiding legt uit hoe het systeem werkt, hoe de onderdelen samenhangen, en hoe je als beheerder het meeste uit het platform haalt.',
); ?>

<nav class="stride-guide-nav">
    <a href="#concepten">Concepten</a>
    <a href="#cursus-vs-editie">Cursus vs Editie</a>
    <a href="#klassikaal-vs-online">Klassikaal vs Online</a>
    <a href="#blended-sessies">Blended &amp; online sessies</a>
    <a href="#deelnemer-dashboard">Het deelnemer-dashboard</a>
    <a href="#trajecten">Trajecten</a>
    <a href="#inschrijvingen">Inschrijvingen</a>
    <a href="#offertes-vouchers">Offertes &amp; Vouchers</a>
    <a href="#veelgestelde-vragen">FAQ</a>
</nav>

<!-- ─── Concepten ─── -->
<section id="concepten" class="stride-guide-section">
    <h2>Hoe Stride is opgebouwd</h2>
    <p>
        Stride scheidt <strong>leerinhoud</strong> van <strong>operationele inhoud</strong>.
        De cursus bevat wat deelnemers leren (lessen, quizzen, naslagmateriaal).
        De editie bevat alles rond &eacute;&eacute;n specifieke uitvoering: planning, sessies, prijs, locatie, presentaties.
        Zo kun je dezelfde cursus meerdere keren per jaar aanbieden zonder inhoud te dupliceren.
    </p>
    <div class="stride-guide-diagram">
        <div class="stride-guide-card">
            <div class="stride-guide-card-icon dashicons dashicons-welcome-learn-more"></div>
            <h4>Cursus</h4>
            <p>Lessen, quizzen, naslagmateriaal<br><em>Leerinhoud — wat leren deelnemers?</em></p>
        </div>
        <div class="stride-guide-arrow">&rarr;</div>
        <div class="stride-guide-card">
            <div class="stride-guide-card-icon dashicons dashicons-calendar-alt"></div>
            <h4>Editie</h4>
            <p>Sessies, locatie, prijs, presentaties<br><em>Operationeel — wanneer, waar en hoe?</em></p>
        </div>
        <div class="stride-guide-arrow">&rarr;</div>
        <div class="stride-guide-card">
            <div class="stride-guide-card-icon dashicons dashicons-groups"></div>
            <h4>Inschrijving</h4>
            <p>Deelnemers, status, formulieren<br><em>Wie doet mee?</em></p>
        </div>
    </div>
</section>

<!-- ─── Cursus vs Editie ─── -->
<section id="cursus-vs-editie" class="stride-guide-section">
    <h2>Cursus vs Editie: wat is het verschil?</h2>

    <table class="widefat striped stride-guide-table">
        <thead>
            <tr>
                <th style="width:30%"></th>
                <th>Cursus <small>(LearnDash)</small></th>
                <th>Editie <small>(Stride)</small></th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><strong>Doel</strong></td>
                <td>Leerinhoud: wat deelnemers leren</td>
                <td>Operationeel: alles rond &eacute;&eacute;n specifieke uitvoering</td>
            </tr>
            <tr>
                <td><strong>Voorbeeld</strong></td>
                <td>"Basisvorming Verslaving"</td>
                <td>"Basisvorming Verslaving — maart 2026, Gent"</td>
            </tr>
            <tr>
                <td><strong>Maak je aan</strong></td>
                <td>Eenmalig per cursus</td>
                <td>Per keer dat je de cursus aanbiedt</td>
            </tr>
            <tr>
                <td><strong>Bevat</strong></td>
                <td>Lessen, quizzen, naslagmateriaal, certificaat</td>
                <td>Sessies, locatie, prijs, capaciteit, presentaties van die dag</td>
            </tr>
            <tr>
                <td><strong>Deelnemers</strong></td>
                <td>Krijgen automatisch toegang na inschrijving</td>
                <td>Schrijven zich in via de editie</td>
            </tr>
            <tr>
                <td><strong>Waar te vinden</strong></td>
                <td>LearnDash &rarr; Cursussen</td>
                <td>Stride &rarr; Edities</td>
            </tr>
        </tbody>
    </table>

    <div class="stride-guide-tip">
        <span class="dashicons dashicons-lightbulb"></span>
        <div>
            <strong>Vuistregel:</strong> De cursus is het <em>leerboek</em>, de editie is de <em>lesdag</em>.
            Moet je de inhoud wijzigen (lessen, quizzen, naslagmateriaal)? Ga naar de <strong>cursus</strong>.
            Moet je planning, prijs, locatie of sessiedocumenten wijzigen? Ga naar de <strong>editie</strong>.
        </div>
    </div>

    <h3>Workflow: een nieuwe vorming plannen</h3>
    <ol class="stride-guide-steps">
        <li>
            <strong>Cursus bestaat al?</strong>
            Ga direct naar stap 3. De cursusinhoud hoef je niet opnieuw aan te maken.
        </li>
        <li>
            <strong>Nieuwe cursus nodig?</strong>
            Ga naar <em>LearnDash &rarr; Cursussen &rarr; Nieuwe cursus</em>.
            Voeg lessen, quizzen en materialen toe.
        </li>
        <li>
            <strong>Maak een editie aan.</strong>
            Ga naar <em>Stride &rarr; Edities &rarr; Nieuwe editie</em>.
            Koppel de cursus, stel data/prijs/locatie in.
        </li>
        <li>
            <strong>Voeg sessies toe</strong> (klassikaal of blended).
            In de editie, onder "Sessies", plan de individuele momenten:
            lesdagen, webinars, online modules of opdrachten.
        </li>
        <li>
            <strong>Zet de status op "Open".</strong>
            Deelnemers kunnen zich nu inschrijven.
        </li>
    </ol>
</section>

<!-- ─── Klassikaal vs Online ─── -->
<section id="klassikaal-vs-online" class="stride-guide-section">
    <h2>Klassikaal vs Online</h2>
    <p>
        Het type cursus bepaalt welke opties je ziet in de editie.
        Het type wordt automatisch herkend op basis van de <strong>LearnDash cursuscategorie</strong>
        (Online, Webinar, of E-learning).
    </p>
    <p>
        Een klassikale editie kan ook <strong>blended</strong> zijn: naast fysieke lesdagen
        kun je webinars, online modules en opdrachten als sessies toevoegen.
        Elke editie kan een andere mix hebben — dat is het verschil met de cursus,
        die altijd dezelfde leerinhoud bevat.
    </p>

    <table class="widefat striped stride-guide-table">
        <thead>
            <tr>
                <th style="width:30%"></th>
                <th>Klassikaal</th>
                <th>Online</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><strong>Sessies</strong></td>
                <td>Ja — voeg lesdagen toe met tijden en locatie</td>
                <td>Nee — deelnemers werken op eigen tempo</td>
            </tr>
            <tr>
                <td><strong>Aanwezigheid</strong></td>
                <td>Ja — markeer per sessie aanwezig/afwezig</td>
                <td>Nee — voortgang via LearnDash</td>
            </tr>
            <tr>
                <td><strong>Datum &amp; Locatie</strong></td>
                <td>Verplicht — startdatum, einddatum, locatie</td>
                <td>Niet nodig — verborgen in het formulier</td>
            </tr>
            <tr>
                <td><strong>Deelnemers</strong></td>
                <td>Via Stride inschrijvingen</td>
                <td>Direct uit LearnDash (of via inschrijfformulier indien ingesteld)</td>
            </tr>
            <tr>
                <td><strong>Cursusinstellingen</strong></td>
                <td>Niet zichtbaar</td>
                <td>Alleen-lezen tab met LearnDash instellingen</td>
            </tr>
        </tbody>
    </table>

    <div class="stride-guide-tip">
        <span class="dashicons dashicons-info"></span>
        <div>
            Om een cursus als "online" te markeren, open de cursus in LearnDash en
            wijs de categorie <strong>Online</strong>, <strong>Webinar</strong> of <strong>E-learning</strong> toe
            onder <em>Cursuscategorie&euml;n</em>.
        </div>
    </div>
</section>

<!-- ─── Blended & online sessies ─── -->
<section id="blended-sessies" class="stride-guide-section">
    <h2>Blended &amp; online sessies</h2>
    <p>
        Een klassikale editie hoeft niet alleen fysieke lesdagen te bevatten.
        Onder <strong>Sessies</strong> kun je naast lesdagen ook
        <strong>webinars</strong>, <strong>online modules</strong> (e-learning) en
        <strong>opdrachten</strong> toevoegen. Zo bouw je een <strong>blended</strong>
        opleiding: deels klassikaal, deels online — gespreid in de tijd.
    </p>

    <h3>De twee lagen: cursus levert inhoud, editie plant inhoud</h3>
    <p>
        Online modules berusten op twee lagen die samenwerken. Het is belangrijk
        het verschil te begrijpen, want je stelt op <strong>twee plaatsen</strong>
        iets in.
    </p>
    <table class="widefat striped stride-guide-table">
        <thead>
            <tr>
                <th style="width:30%"></th>
                <th>Cursus <small>(LearnDash)</small></th>
                <th>Editie <small>(Stride)</small></th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><strong>Wat je hier doet</strong></td>
                <td>Lessen aanmaken (de inhoud van de module)</td>
                <td>Een online sessie maken die naar één les verwijst</td>
            </tr>
            <tr>
                <td><strong>Datuminstelling</strong></td>
                <td>Optionele <em>beschikbaarheid</em> per les (drip-feed)</td>
                <td><em>Sessiedatum</em> ("Beschikbaar vanaf …")</td>
            </tr>
            <tr>
                <td><strong>Effect</strong></td>
                <td><strong>Vergrendelt</strong> de les tot de datum (harde toegang)</td>
                <td><strong>Toont &amp; nudget</strong> de les op het dashboard (zacht)</td>
            </tr>
        </tbody>
    </table>

    <h3>Stap voor stap: een gespreide online module opzetten</h3>
    <ol class="stride-guide-steps">
        <li>
            <strong>Maak de lessen aan in de cursus.</strong>
            Ga naar <em>LearnDash &rarr; Cursussen</em>, open de cursus en voeg de
            lessen toe (bijv. "Module 1", "Module 2"). De cursus is de bron van de
            inhoud — je maakt elke les hier maar één keer aan.
        </li>
        <li>
            <strong>(Optioneel) Stel de beschikbaarheid per les in.</strong>
            Wil je dat een les pas écht toegankelijk is vanaf een bepaald moment?
            Open de les in LearnDash en zet onder <em>Lesinstellingen &rarr;
            Beschikbaarheid</em> de drip-feed: "beschikbaar X dagen na inschrijving"
            of "beschikbaar op een specifieke datum". Zonder deze instelling is de
            les toegankelijk zodra de inschrijving bevestigd is.
        </li>
        <li>
            <strong>Voeg per les een online sessie toe in de editie.</strong>
            Open de editie, ga naar <em>Sessies &rarr; Sessie toevoegen</em>, kies
            type <strong>Online</strong>, en selecteer in het dropdownmenu
            <em>"Les"</em> de juiste les. Het dropdownmenu toont automatisch alle
            lessen van de gekoppelde cursus.
        </li>
        <li>
            <strong>Geef elke online sessie een datum.</strong>
            Die datum verschijnt bij de deelnemer als "Beschikbaar vanaf …" en stuurt
            wanneer de module als actie op het dashboard naar voren komt. Spreid zo
            de modules: Module 1 op 30 juni, Module 2 op 6 augustus.
        </li>
    </ol>

    <div class="stride-guide-tip">
        <span class="dashicons dashicons-info"></span>
        <div>
            <strong>Waarom kies ik per sessie één les en niet de hele cursus?</strong>
            Een sessie is één moment in de planning. Door per sessie één les te
            koppelen, kun je de lessen van een cursus <em>spreiden</em> in de tijd —
            elk met een eigen datum. De cursus levert de inhoud; de editie bepaalt
            wanneer elke les aan bod komt.
        </div>
    </div>

    <h3 id="twee-datums">Belangrijk: sessiedatum vergrendelt niet</h3>
    <p>
        Dit is het meest verwarrende punt, dus expliciet:
        <strong>de sessiedatum in de editie en de beschikbaarheidsdatum in LearnDash
        zijn twee aparte instellingen.</strong>
    </p>
    <table class="widefat striped stride-guide-table">
        <tbody>
            <tr>
                <td style="width:42%"><strong>Sessiedatum</strong> (Stride, in de editie)</td>
                <td>
                    Toont "Beschikbaar vanaf &lt;datum&gt;" en bepaalt wanneer de
                    module als actie op het dashboard verschijnt. <strong>Blokkeert
                    de toegang niet</strong> — een ingeschreven deelnemer kan de les
                    technisch al openen.
                </td>
            </tr>
            <tr>
                <td><strong>Beschikbaarheid / drip-feed</strong> (LearnDash, in de les)</td>
                <td>
                    <strong>Vergrendelt</strong> de les écht tot de ingestelde datum.
                    Pas als deze datum verstreken is, verschijnt de module als
                    actie én is ze te openen.
                </td>
            </tr>
        </tbody>
    </table>
    <div class="stride-guide-tip">
        <span class="dashicons dashicons-lightbulb"></span>
        <div>
            <strong>Vuistregel:</strong> wil je alleen <em>plannen en aankondigen</em>
            ("deze module komt eraan op 6 augustus"), dan volstaat de
            <strong>sessiedatum</strong>. Wil je de les ook echt
            <em>vergrendelen</em> tot die dag, zet dan <strong>dezelfde datum ook als
            drip-feed in LearnDash</strong>. Stel je alleen de sessiedatum in, dan
            staat er wel "Beschikbaar vanaf …", maar kan de deelnemer de les al openen.
        </div>
    </div>
</section>

<!-- ─── Het deelnemer-dashboard ─── -->
<section id="deelnemer-dashboard" class="stride-guide-section">
    <h2>Het deelnemer-dashboard</h2>
    <p>
        Na inschrijving landt de deelnemer op <em>Mijn account</em>. Belangrijk om te
        weten als beheerder: wat jij in de editie plant (sessies, online modules,
        afrondingstaken) bepaalt wat de deelnemer hier als <strong>actie</strong> ziet.
    </p>

    <table class="widefat striped stride-guide-table">
        <thead>
            <tr>
                <th style="width:28%">Onderdeel</th>
                <th>Wat het toont</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><strong>"Actie vereist"</strong> <small>(groene balk)</small></td>
                <td>
                    De <strong>één</strong> belangrijkste volgende stap. Prioriteit:
                    eerst een sessie van vandaag/morgen, anders de eerstvolgende
                    openstaande taak. Er staat altijd maar één item in deze balk.
                </td>
            </tr>
            <tr>
                <td><strong>"Acties nodig"</strong> <small>(lijst)</small></td>
                <td>
                    <strong>Alle</strong> openstaande taken samen — afrondingstaken,
                    sessiekeuzes én elke nog niet afgeronde online module. Heeft een
                    editie twee online modules, dan staan beide hier (elk met "Ga
                    verder"). De groene balk licht er telkens de bovenste van uit.
                </td>
            </tr>
            <tr>
                <td><strong>"Binnenkort"</strong></td>
                <td>De eerstvolgende geplande sessies (datum, tijd, locatie), met export naar agenda.</td>
            </tr>
            <tr>
                <td><strong>Opleidingen / online programma</strong></td>
                <td>
                    De cursussen waarvoor de deelnemer is ingeschreven, met voortgang
                    per les. Toekomstige modules staan hier vermeld met hun
                    beschikbaarheidsdatum.
                </td>
            </tr>
        </tbody>
    </table>

    <div class="stride-guide-tip">
        <span class="dashicons dashicons-lightbulb"></span>
        <div>
            <strong>Waarom zie ik dezelfde module twee keer?</strong> Dat klopt: een
            online module verschijnt zowel in de groene balk (als het de bovenste
            actie is) als in de volledige lijst "Acties nodig". Het is één taak die op
            twee plaatsen getoond wordt — de balk is de snelkoppeling naar de
            belangrijkste, de lijst is het volledige overzicht.
        </div>
    </div>

    <h3>Waar komt een deelnemer uit na het klikken?</h3>
    <p>
        "Ga verder" op een online module opent de les in LearnDash. De
        <strong>"Terug"</strong>-knop daar brengt de deelnemer terug naar de
        <strong>editie</strong> waar de module bij hoort (niet naar de kale cursus),
        zodat de context van die uitvoering behouden blijft. Een afrondingstaak opent
        de afrondingspagina van de editie (<em>evaluatie, documenten</em>).
    </p>
</section>

<!-- ─── Trajecten ─── -->
<section id="trajecten" class="stride-guide-section">
    <h2>Trajecten</h2>
    <p>
        Een traject is een <strong>leerpad</strong> dat meerdere cursussen combineert tot een samenhangend programma.
        Deelnemers schrijven zich in voor het hele traject en volgen de cursussen in de juiste volgorde.
    </p>

    <div class="stride-guide-diagram">
        <div class="stride-guide-card" style="flex: 2;">
            <div class="stride-guide-card-icon dashicons dashicons-networking"></div>
            <h4>Traject</h4>
            <p>"Basisopleiding Verslavingszorg 2026"</p>
            <div style="display:flex; gap:8px; margin-top:8px; justify-content:center;">
                <span class="stride-guide-badge">Cursus A (verplicht)</span>
                <span class="stride-guide-badge">Cursus B (verplicht)</span>
                <span class="stride-guide-badge stride-guide-badge--alt">Cursus C of D (keuze)</span>
            </div>
        </div>
    </div>

    <h3>Twee modi</h3>
    <table class="widefat striped stride-guide-table">
        <thead>
            <tr>
                <th style="width:30%"></th>
                <th>Cohort</th>
                <th>Zelfstandig</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><strong>Hoe het werkt</strong></td>
                <td>Vaste groep volgt samen hetzelfde programma</td>
                <td>Deelnemer kiest zelf edities en tempo</td>
            </tr>
            <tr>
                <td><strong>Edities</strong></td>
                <td>Vooraf gekoppeld aan het traject</td>
                <td>Deelnemer kiest uit beschikbare edities</td>
            </tr>
            <tr>
                <td><strong>Deadline</strong></td>
                <td>Vaste start- en einddatum</td>
                <td>Optioneel — maximaal aantal maanden</td>
            </tr>
        </tbody>
    </table>
</section>

<!-- ─── Inschrijvingen ─── -->
<section id="inschrijvingen" class="stride-guide-section">
    <h2>Inschrijvingen</h2>
    <p>
        Deelnemers schrijven zich in voor een <strong>editie</strong> (niet voor een cursus).
        Na inschrijving krijgen ze automatisch toegang tot de gekoppelde LearnDash cursus.
    </p>

    <h3>Inschrijfstatus</h3>
    <table class="widefat striped stride-guide-table">
        <tbody>
            <tr>
                <td><span class="stride-guide-status pending">In afwachting</span></td>
                <td>Inschrijving ontvangen, wacht op goedkeuring (als "Goedkeuring vereist" aanstaat)</td>
            </tr>
            <tr>
                <td><span class="stride-guide-status confirmed">Bevestigd</span></td>
                <td>Deelnemer is ingeschreven en heeft toegang tot de cursus</td>
            </tr>
            <tr>
                <td><span class="stride-guide-status completed">Afgerond</span></td>
                <td>Deelnemer heeft de editie succesvol afgerond</td>
            </tr>
            <tr>
                <td><span class="stride-guide-status cancelled">Geannuleerd</span></td>
                <td>Inschrijving is geannuleerd, cursustoegang ingetrokken</td>
            </tr>
        </tbody>
    </table>

    <h3>Opties per editie</h3>
    <ul>
        <li><strong>Goedkeuring vereist</strong> — inschrijvingen wachten op goedkeuring door een beheerder</li>
        <li><strong>Inschrijfformulier</strong> — deelnemers vullen een formulier in bij inschrijving (met vragenlijst, documenten, etc.)</li>
        <li><strong>Sessiekeuze</strong> — deelnemers kiezen zelf welke sessies ze bijwonen</li>
    </ul>

    <h3>Deadlines &amp; herinneringen</h3>
    <p>
        Per editie kun je een <strong>deadline</strong> zetten voor de taken die een deelnemer
        na inschrijving moet afronden. Er zijn drie afzonderlijke deadlines, elk gekoppeld aan
        de bijhorende taak:
    </p>
    <ul>
        <li><strong>Sessiekeuze</strong> — bestaande deadline; verschijnt bij de sessiekeuze-instellingen.</li>
        <li><strong>Vragenlijst &amp; documenten (bij inschrijving)</strong> — één gedeelde datum. Verschijnt zodra je "Vragenlijst invullen" of "Documenten uploaden" aanzet.</li>
        <li><strong>Evaluatie &amp; documenten (na de cursus)</strong> — één gedeelde datum voor de afrondingstaken.</li>
    </ul>
    <p>
        <strong>Wat gebeurt er bij de deadline?</strong> Niets automatisch — de deelnemer kan
        de taak ook ná de deadline nog afronden. Een verlopen deadline wordt enkel
        <strong>gemarkeerd</strong>: bij <em>Acties nodig</em> zie je per inschrijving hoeveel
        dagen er nog resten ("nog 3 dagen") of hoeveel dagen te laat ("2 dagen te laat"). Jij
        beslist per geval — Stride annuleert nooit vanzelf.
    </p>
    <p>
        <strong>De drie automatische e-mails.</strong> Zodra een deadline is ingesteld, krijgt
        de deelnemer:
    </p>
    <ul>
        <li>bij inschrijving (of bij het afronden van de cursus, voor de afrondingstaken) een mail "je moet nog…";</li>
        <li>een <strong>herinnering</strong> een aantal dagen na inschrijving als het nog niet klaar is;</li>
        <li>een laatste mail <strong>de dag vóór de deadline</strong>.</li>
    </ul>
    <p>
        Het aantal dagen voor de herinnering stel je in onder <em>Stride → Instellingen →
        Meldingen</em>, bij "Herinnering na inschrijving" (standaard 7 dagen, met een
        aan/uit-schakelaar).
    </p>

    <h3>Formuliervelden — systeemvelden</h3>
    <p>
        Onder <em>Stride → Formuliervelden</em> beheer je de extra velden die op de
        inschrijf-, intake- en evaluatieformulieren verschijnen. Een veld dat je
        daar maakt, wordt standaard alleen bij die ene inschrijving bewaard.
    </p>
    <p>
        <strong>Sommige veldnamen zijn echter "systeemvelden":</strong> als je een
        veld exact één van deze namen geeft, wordt de waarde automatisch
        opgeslagen op het profiel van de gebruiker. Dat is handig voor stabiele
        gegevens die de deelnemer maar één keer hoort in te vullen (bv.
        rijksregisternummer of geboortedatum).
    </p>
    <table class="stride-handleiding-table">
        <thead>
            <tr>
                <th>Veldnaam</th>
                <th>Slaat op als</th>
                <th>Wanneer gebruiken</th>
            </tr>
        </thead>
        <tbody>
            <tr><td><code>phone</code></td><td>Telefoonnummer</td><td>Standaard contactgegeven</td></tr>
            <tr><td><code>organisation</code></td><td>Organisatie / werkgever</td><td>Werkrelaties, rapportering per organisatie</td></tr>
            <tr><td><code>department</code></td><td>Afdeling</td><td>Detail binnen organisatie</td></tr>
            <tr><td><code>national_id</code></td><td>Rijksregisternummer</td><td>Wettelijke retentie (7-10 jaar) voor opleidingsrecords</td></tr>
            <tr><td><code>date_of_birth</code></td><td>Geboortedatum</td><td>Accreditatie, leeftijdscriteria</td></tr>
            <tr><td><code>professional_license_number</code></td><td>Erkenningsnummer / RIZIV</td><td>Zorg- en welzijnsprofessionals</td></tr>
            <tr><td><code>company</code></td><td>Bedrijfsnaam (factuur)</td><td>Verschillend van werkgever bij externe facturatie</td></tr>
            <tr><td><code>vat_number</code></td><td>BTW-nummer</td><td>Belgische BTW</td></tr>
            <tr><td><code>address</code> / <code>postal_code</code> / <code>city</code></td><td>Factuuradres</td><td>Verzending van offertes en facturen</td></tr>
            <tr><td><code>invoice_email</code></td><td>Factuur-emailadres</td><td>Apart van het login-emailadres</td></tr>
            <tr><td><code>gln_number</code></td><td>GLN-nummer</td><td>Ziekenhuizen / grote organisaties</td></tr>
        </tbody>
    </table>
    <p class="stride-tip">
        💡 Een veld met een andere naam (bv. <code>opmerking</code> of
        <code>diet</code>) wordt enkel bij de inschrijving bewaard, niet op het
        gebruikersprofiel. Dat is meestal precies wat je wilt voor
        cursus-specifieke vragen.
    </p>
</section>

<!-- ─── Offertes & Vouchers ─── -->
<section id="offertes-vouchers" class="stride-guide-section">
    <h2>Offertes &amp; Vouchers</h2>

    <h3>Offertes</h3>
    <p>
        Bij elke inschrijving wordt automatisch een offerte aangemaakt.
        Offertes bevatten de cursus, prijs, eventuele korting en facturatiegegevens.
        Na verzending worden ze vergrendeld en kunnen ze ge&euml;xporteerd worden naar Exact Online.
    </p>
    <div class="stride-guide-flow">
        <span class="stride-guide-flow-step">Concept</span>
        <span class="stride-guide-flow-arrow">&rarr;</span>
        <span class="stride-guide-flow-step">Verzonden</span>
        <span class="stride-guide-flow-arrow">&rarr;</span>
        <span class="stride-guide-flow-step">Ge&euml;xporteerd</span>
    </div>

    <h3>Vouchers</h3>
    <p>
        Kortingscodes die deelnemers kunnen invoeren bij inschrijving.
    </p>
    <ul>
        <li><strong>Gratis</strong> — 100% korting</li>
        <li><strong>Vast bedrag</strong> — bijv. &euro; 25 korting</li>
        <li><strong>Percentage</strong> — bijv. 20% korting</li>
    </ul>
    <p>
        Vouchers kunnen beperkt worden tot een specifieke editie, een geldigheidsperiode,
        en een maximaal aantal keer gebruik.
    </p>
</section>

<!-- ─── FAQ ─── -->
<section id="veelgestelde-vragen" class="stride-guide-section">
    <h2>Veelgestelde vragen</h2>

    <div class="stride-guide-faq">
        <details>
            <summary>Waarom moet ik apart een cursus en een editie aanmaken?</summary>
            <p>
                De cursus is je <strong>leerinhoud</strong> (lessen, quizzen, naslagmateriaal) — die maak je &eacute;&eacute;n keer aan.
                De editie is de <strong>operationele inhoud</strong> (planning, prijs, sessies, presentaties van die dag).
                Zo kun je dezelfde cursus meerdere keren per jaar aanbieden zonder inhoud te kopi&euml;ren,
                terwijl elke editie zijn eigen praktische details heeft.
            </p>
        </details>

        <details>
            <summary>Waar zet ik documenten: bij de cursus of bij de editie?</summary>
            <p>
                <strong>Cursus:</strong> naslagmateriaal dat altijd geldig is, ongeacht de editie
                (bijv. handboek, richtlijnen, achtergrondliteratuur).<br>
                <strong>Editie:</strong> documenten die specifiek zijn voor die uitvoering
                (bijv. presentaties van die dag, hand-outs van de trainer, foto's van het whiteboard).
            </p>
        </details>

        <details>
            <summary>Hoe maak ik een cursus "online"?</summary>
            <p>
                Open de cursus in LearnDash en wijs de categorie <strong>Online</strong>,
                <strong>Webinar</strong> of <strong>E-learning</strong> toe.
                De editie herkent dit automatisch en past het formulier aan.
            </p>
        </details>

        <details>
            <summary>Kan ik de prijs per editie anders instellen?</summary>
            <p>
                Ja. Elke editie heeft eigen prijzen (leden/niet-leden). De prijs in LearnDash
                wordt alleen gebruikt als er geen editie-prijs is ingesteld.
            </p>
        </details>

        <details>
            <summary>Wat gebeurt er als een editie vol zit?</summary>
            <p>
                Als de capaciteit bereikt is, verandert de status automatisch naar "Vol".
                Nieuwe inschrijvingen worden dan geblokkeerd op het inschrijfformulier.
            </p>
        </details>

        <details>
            <summary>Hoe werkt goedkeuring van inschrijvingen?</summary>
            <p>
                Als "Goedkeuring vereist" aanstaat voor een editie, komen nieuwe inschrijvingen
                binnen als "In afwachting". Een beheerder kan ze goedkeuren of afwijzen
                in het Deelnemers-paneel van de editie.
            </p>
        </details>

        <details>
            <summary>Hoe verspreid ik online modules over de tijd binnen één editie?</summary>
            <p>
                Voeg per module een <strong>online sessie</strong> toe onder "Sessies"
                in de editie, koppel er de juiste les aan en geef elke sessie een
                eigen <strong>datum</strong>. De deelnemer ziet elke module rond zijn
                datum als actie op het dashboard ("Beschikbaar vanaf …"). Zie
                <a href="#blended-sessies">Blended &amp; online sessies</a> voor de
                stappen.
            </p>
        </details>

        <details>
            <summary>Er staat "Beschikbaar vanaf …", maar de deelnemer kan de les nu al openen. Klopt dat?</summary>
            <p>
                Ja, dat kan kloppen. De <strong>sessiedatum</strong> (in de editie)
                stuurt enkel de weergave en de nudge op het dashboard — ze
                <strong>vergrendelt de les niet</strong>. Een ingeschreven deelnemer
                heeft via LearnDash al toegang tot de cursus. Wil je de les écht
                blokkeren tot die datum, stel dan dezelfde datum óók in als
                <strong>drip-feed in de LearnDash-lesinstellingen</strong>. Het zijn
                twee aparte instellingen — zie
                <a href="#twee-datums">Sessiedatum vergrendelt niet</a>.
            </p>
        </details>

        <details>
            <summary>Waarom staat een online module twee keer op het dashboard van de deelnemer?</summary>
            <p>
                De groene <strong>"Actie vereist"</strong>-balk toont altijd één item:
                de belangrijkste volgende stap. De lijst <strong>"Acties nodig"</strong>
                toont álle openstaande taken. Een module die bovenaan staat,
                verschijnt dus in beide — het is één taak op twee plaatsen, geen
                dubbele inschrijving.
            </p>
        </details>

        <details>
            <summary>Wat is het verschil tussen een cohort- en zelfstandig traject?</summary>
            <p>
                Bij een <strong>cohort</strong> volgt een vaste groep samen dezelfde edities.
                Bij <strong>zelfstandig</strong> kiest elke deelnemer zelf welke edities
                en in welk tempo.
            </p>
        </details>

        <details>
            <summary>Krijgt een deelnemer automatisch een herinnering als hij zijn taken niet afrondt?</summary>
            <p>
                Ja — als je een deadline op de editie zet. Hij krijgt een mail bij inschrijving,
                een herinnering (aantal dagen instelbaar onder Instellingen → Meldingen) en een
                mail de dag vóór de deadline. De inschrijving wordt nooit automatisch
                geannuleerd; verlopen deadlines zie je bij <em>Acties nodig</em>.
            </p>
        </details>
    </div>
</section>

</div>

<style>
.stride-guide {
    max-width: 860px;
    font-size: 14px;
    line-height: 1.7;
}
.stride-guide h1 {
    font-size: 28px;
    font-weight: 600;
    margin-bottom: 4px;
}
.stride-guide-intro {
    color: #646970;
    font-size: 15px;
    margin-bottom: 24px;
}
.stride-guide-nav {
    display: flex;
    flex-wrap: wrap;
    gap: 0;
    border-bottom: 1px solid #c3c4c7;
    margin-bottom: 32px;
}
.stride-guide-nav a {
    padding: 10px 16px;
    text-decoration: none;
    font-size: 13px;
    font-weight: 500;
    color: #50575e;
    border-bottom: 2px solid transparent;
    transition: all 0.15s ease;
}
.stride-guide-nav a:hover {
    color: #2271b1;
    border-bottom-color: #2271b1;
}
.stride-guide-section {
    margin-bottom: 40px;
    padding-bottom: 32px;
    border-bottom: 1px solid #e0e0e0;
}
.stride-guide-section:last-child {
    border-bottom: none;
}
.stride-guide-section h2 {
    font-size: 20px;
    font-weight: 600;
    margin-bottom: 12px;
    color: #1d2327;
}
.stride-guide-section h3 {
    font-size: 15px;
    font-weight: 600;
    margin: 20px 0 8px;
    color: #1d2327;
}

/* Diagram */
.stride-guide-diagram {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 16px;
    margin: 24px 0;
    flex-wrap: wrap;
}
.stride-guide-card {
    background: #f6f7f7;
    border: 1px solid #c3c4c7;
    border-radius: 6px;
    padding: 20px 24px;
    text-align: center;
    flex: 1;
    min-width: 160px;
}
.stride-guide-card h4 {
    margin: 8px 0 4px;
    font-size: 15px;
    font-weight: 600;
}
.stride-guide-card p {
    margin: 0;
    font-size: 12px;
    color: #646970;
}
.stride-guide-card-icon {
    font-size: 28px;
    width: 28px;
    height: 28px;
    color: #2271b1;
}
.stride-guide-arrow {
    font-size: 24px;
    color: #c3c4c7;
    font-weight: bold;
}

/* Tables */
.stride-guide-table {
    margin: 12px 0 16px;
}
.stride-guide-table th {
    font-weight: 600;
    font-size: 13px;
}
.stride-guide-table td {
    font-size: 13px;
    vertical-align: top;
}

/* Tip box */
.stride-guide-tip {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    background: #f0f6fc;
    border: 1px solid #c3d8e8;
    border-left: 3px solid #2271b1;
    border-radius: 4px;
    padding: 12px 16px;
    margin: 16px 0;
}
.stride-guide-tip .dashicons {
    color: #2271b1;
    margin-top: 2px;
}
.stride-guide-tip div {
    font-size: 13px;
}

/* Steps */
.stride-guide-steps {
    counter-reset: step;
    list-style: none;
    padding: 0;
    margin: 12px 0;
}
.stride-guide-steps li {
    counter-increment: step;
    padding: 10px 12px 10px 44px;
    position: relative;
    margin-bottom: 4px;
    border-radius: 4px;
    font-size: 13px;
}
.stride-guide-steps li:nth-child(odd) {
    background: #f6f7f7;
}
.stride-guide-steps li::before {
    content: counter(step);
    position: absolute;
    left: 12px;
    top: 10px;
    width: 22px;
    height: 22px;
    background: #2271b1;
    color: #fff;
    border-radius: 50%;
    font-size: 12px;
    font-weight: 600;
    display: flex;
    align-items: center;
    justify-content: center;
}

/* Status badges */
.stride-guide-status {
    display: inline-block;
    padding: 2px 10px;
    border-radius: 10px;
    font-size: 12px;
    font-weight: 500;
    white-space: nowrap;
}
.stride-guide-status.pending { background: #fcf0e3; color: #9a6700; }
.stride-guide-status.confirmed { background: #e6f4ea; color: #1a7431; }
.stride-guide-status.completed { background: #e8f0fe; color: #1a56db; }
.stride-guide-status.cancelled { background: #fce8e8; color: #9a1c1c; }

/* Badges in diagram */
.stride-guide-badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 3px;
    font-size: 11px;
    background: #e8f0fe;
    color: #1a56db;
}
.stride-guide-badge--alt {
    background: #fcf0e3;
    color: #9a6700;
}

/* Flow diagram */
.stride-guide-flow {
    display: flex;
    align-items: center;
    gap: 8px;
    margin: 12px 0;
}
.stride-guide-flow-step {
    padding: 6px 16px;
    border-radius: 4px;
    background: #f6f7f7;
    border: 1px solid #c3c4c7;
    font-size: 13px;
    font-weight: 500;
}
.stride-guide-flow-arrow {
    color: #c3c4c7;
    font-size: 18px;
}

/* FAQ */
.stride-guide-faq details {
    border: 1px solid #e0e0e0;
    border-radius: 4px;
    margin-bottom: 8px;
}
.stride-guide-faq summary {
    padding: 12px 16px;
    cursor: pointer;
    font-weight: 500;
    font-size: 13px;
    list-style: none;
}
.stride-guide-faq summary::-webkit-details-marker {
    display: none;
}
.stride-guide-faq summary::before {
    content: "\f345";
    font-family: dashicons;
    margin-right: 8px;
    color: #646970;
    transition: transform 0.2s;
    display: inline-block;
}
.stride-guide-faq details[open] summary::before {
    transform: rotate(90deg);
}
.stride-guide-faq details[open] summary {
    border-bottom: 1px solid #e0e0e0;
}
.stride-guide-faq p {
    padding: 12px 16px 12px 40px;
    margin: 0;
    font-size: 13px;
    color: #50575e;
}

/* Responsive */
@media (max-width: 782px) {
    .stride-guide-diagram {
        flex-direction: column;
    }
    .stride-guide-arrow {
        transform: rotate(90deg);
    }
    .stride-guide-nav {
        flex-direction: column;
    }
}
</style>
