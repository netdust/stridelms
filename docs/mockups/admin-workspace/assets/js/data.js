/* ==========================================================================
   Stride Admin Workspace — shared mockup data + helpers
   --------------------------------------------------------------------------
   STATIC. Wires to nothing. All "data" is hardcoded JS. The transition map
   mirrors spec §2.1; the status labels mirror Domain/RegistrationStatus.php
   + Domain/QuoteStatus.php exactly.
   ========================================================================== */
(function () {
'use strict';

/* ---- Inline SVG icon set (Lucide-style, 24x24, stroke=currentColor) ---- */
const ICONS = {
  layers:    '<path d="m12.83 2.18a2 2 0 0 0-1.66 0L2.6 6.08a1 1 0 0 0 0 1.83l8.58 3.91a2 2 0 0 0 1.66 0l8.58-3.9a1 1 0 0 0 0-1.83Z"/><path d="m22 17.65-9.17 4.16a2 2 0 0 1-1.66 0L2 17.65"/><path d="m22 12.65-9.17 4.16a2 2 0 0 1-1.66 0L2 12.65"/>',
  grid:      '<rect width="7" height="7" x="3" y="3" rx="1"/><rect width="7" height="7" x="14" y="3" rx="1"/><rect width="7" height="7" x="14" y="14" rx="1"/><rect width="7" height="7" x="3" y="14" rx="1"/>',
  sun:       '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41"/>',
  folder:    '<path d="M20 20a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.9a2 2 0 0 1-1.69-.9L9.6 3.9A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2Z"/>',
  user:      '<path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
  users:     '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>',
  check:     '<path d="M20 6 9 17l-5-5"/>',
  checkCircle:'<path d="M21.8 10A10 10 0 1 1 17 3.34"/><path d="m9 11 3 3L22 4"/>',
  x:         '<path d="M18 6 6 18M6 6l12 12"/>',
  xCircle:   '<circle cx="12" cy="12" r="10"/><path d="m15 9-6 6M9 9l6 6"/>',
  clock:     '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
  arrowRight:'<path d="M5 12h14M12 5l7 7-7 7"/>',
  arrowUp:   '<path d="m5 12 7-7 7 7M12 19V5"/>',
  chevDown:  '<path d="m6 9 6 6 6-6"/>',
  chevRight: '<path d="m9 18 6-6-6-6"/>',
  search:    '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
  send:      '<path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/>',
  mail:      '<rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/>',
  building:  '<rect width="16" height="20" x="4" y="2" rx="2"/><path d="M9 22v-4h6v4M9 6h.01M15 6h.01M9 10h.01M15 10h.01M9 14h.01M15 14h.01"/>',
  ticket:    '<path d="M2 9a3 3 0 0 1 0 6v2a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-2a3 3 0 0 1 0-6V7a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2Z"/><path d="M13 5v2M13 17v2M13 11v2"/>',
  award:     '<path d="m15.477 12.89 1.515 8.526a.5.5 0 0 1-.81.47l-3.58-2.687a1 1 0 0 0-1.197 0l-3.586 2.686a.5.5 0 0 1-.81-.469l1.514-8.526"/><circle cx="12" cy="8" r="6"/>',
  history:   '<path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5M12 7v5l4 2"/>',
  more:      '<circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/><circle cx="5" cy="12" r="1"/>',
  filter:    '<path d="M3 6h18M7 12h10M10 18h4"/>',
  alert:     '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4M12 17h.01"/>',
  bell:      '<path d="M10.27 21a1.94 1.94 0 0 0 3.46 0M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/>',
  hourglass: '<path d="M5 22h14M5 2h14M17 22v-4.17a2 2 0 0 0-.59-1.42L12 12l-4.41 4.41A2 2 0 0 0 7 17.83V22M7 2v4.17a2 2 0 0 0 .59 1.42L12 12l4.41-4.41A2 2 0 0 0 17 6.17V2"/>',
  seat:      '<path d="M19 9V6a2 2 0 0 0-2-2H7a2 2 0 0 0-2 2v3M4 11v5a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-5a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2ZM6 18v2M18 18v2"/>',
  archive:   '<rect width="20" height="5" x="2" y="3" rx="1"/><path d="M4 8v11a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8M10 12h4"/>',
  fileText:  '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v5h5M16 13H8M16 17H8M10 9H8"/>',
  receipt:   '<path d="M4 2v20l2-1 2 1 2-1 2 1 2-1 2 1 2-1 2 1V2l-2 1-2-1-2 1-2-1-2 1-2-1-2 1Z"/><path d="M8 7h8M8 11h8M8 15h5"/>',
  download:  '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"/>',
  edit:      '<path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.12 2.12 0 0 1 3 3L12 15l-4 1 1-4Z"/>',
  tag:       '<path d="M12.586 2.586A2 2 0 0 0 11.172 2H4a2 2 0 0 0-2 2v7.172a2 2 0 0 0 .586 1.414l8.704 8.704a2.426 2.426 0 0 0 3.42 0l6.58-6.58a2.426 2.426 0 0 0 0-3.42z"/><circle cx="7.5" cy="7.5" r=".5"/>',
  swap:      '<path d="M16 3h5v5M21 3l-7 7M8 21H3v-5M3 21l7-7"/>',
  phone:     '<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.91.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92Z"/>',
  mapPin:    '<path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/>',
  calendar:  '<rect width="18" height="18" x="3" y="4" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
  route:     '<circle cx="6" cy="19" r="3"/><path d="M9 19h8.5a3.5 3.5 0 0 0 0-7h-11a3.5 3.5 0 0 1 0-7H15"/><circle cx="18" cy="5" r="3"/>',
  inbox:     '<path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/>',
  sparkle:   '<path d="M9.937 15.5A2 2 0 0 0 8.5 14.063l-6.135-1.582a.5.5 0 0 1 0-.962L8.5 9.936A2 2 0 0 0 9.937 8.5l1.582-6.135a.5.5 0 0 1 .962 0L14.063 8.5A2 2 0 0 0 15.5 9.937l6.135 1.581a.5.5 0 0 1 0 .964L15.5 14.063a2 2 0 0 0-1.437 1.437l-1.582 6.135a.5.5 0 0 1-.962 0z"/>',
  info:      '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/>',
  refresh:   '<path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8M21 3v5h-5M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16M3 21v-5h5"/>',
  briefcase: '<path d="M16 20V4a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16M4 7h16a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V9a2 2 0 0 1 2-2Z"/>',
  book:      '<path d="M4 19.5v-15A2.5 2.5 0 0 1 6.5 2H19a1 1 0 0 1 1 1v18a1 1 0 0 1-1 1H6.5a1 1 0 0 1 0-5H20"/>',
  hash:      '<path d="M4 9h16M4 15h16M10 3 8 21M16 3l-2 18"/>',
  userPlus:  '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M19 8v6M22 11h-6"/>',
  userCheck: '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="m16 11 2 2 4-4"/>',
  slash:     '<circle cx="12" cy="12" r="10"/><path d="m4.9 4.9 14.2 14.2"/>',
};

function icon(name, cls) {
  const path = ICONS[name] || '';
  return '<svg class="' + (cls || '') + '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + path + '</svg>';
}

/* ---- Status maps (verbatim from the enums) ----
   `label` = the short badge text (still the bare enum label used on rows/badges).
   The enrollment-pipeline filter (inschrijvingen.html) uses the richer fields:
   `pipe`  = self-explanatory pipeline microcopy ("where this person is")
   `hint`  = tooltip nuance shown on hover
   `step`  = 1..5 lifecycle order; cancelled has no step (it's the exit/dead-end)
   `exit`  = true → rendered separated from the funnel (a dead-end, not a stage) */
const REG_STATUS = {
  confirmed: { label: 'Bevestigd',      cls: 'confirmed', step: 4,
               pipe: 'Bevestigd / ingeschreven',
               hint: 'Inschrijving goedgekeurd en bevestigd — de persoon neemt deel.' },
  completed: { label: 'Afgerond',       cls: 'completed', step: 5,
               pipe: 'Afgerond',
               hint: 'De cursus is afgerond.' },
  cancelled: { label: 'Geannuleerd',    cls: 'cancelled', exit: true,
               pipe: 'Geannuleerd',
               hint: 'Uitgestapt — een eindstatus buiten de funnel. Heropnemen is een nieuwe inschrijving.' },
  waitlist:  { label: 'Wachtlijst',     cls: 'waitlist',  step: 2,
               pipe: 'Op wachtlijst',
               hint: 'Aangemeld maar nog geen plaats — wacht tot er een plek vrijkomt.' },
  interest:  { label: 'Interesse',      cls: 'interest',  step: 1,
               pipe: 'Interesse getoond',
               hint: 'Heeft interesse getoond maar is nog niet ingeschreven.' },
  pending:   { label: 'In afwachting',  cls: 'pending',   step: 3,
               pipe: 'Wacht op goedkeuring',
               hint: 'Wacht op gebruiker of op goedkeuring — ofwel moet de gebruiker nog taken afronden (intake, sessiekeuze, documenten), ofwel is alles klaar en wacht het op goedkeuring door de beheerder.' },
};

/* the enrollment pipeline, in lifecycle order, with the exit separated.
   Drives the funnel/stepper filter at the top of the grid (spec §1, Fix 1). */
const STATUS_PIPELINE = ['interest', 'waitlist', 'pending', 'confirmed', 'completed'];
const STATUS_EXIT = 'cancelled';

const OFFERTE_STATUS = {
  none:     { label: 'Geen offerte',   cls: 'none'     },
  draft:    { label: 'In behandeling', cls: 'draft'    },
  sent:     { label: 'Verzonden',      cls: 'sent'     },
  exported: { label: 'Verwerkt',       cls: 'exported' },
};

/* ---- Transition map — mirror of spec §2.1 ----
   Each smart action knows: id, dutch label, icon, danger flag, the states it
   applies to. The grid derives the bulk bar from the intersection of the
   selected rows' states. */
const SMART_ACTIONS = [
  { id: 'stride_bulk_approve',           label: 'Goedkeuren',            icon: 'checkCircle', states: ['pending', 'interest'] },
  { id: 'stride_bulk_promote_waitlist',  label: 'Promoveer van wachtlijst', icon: 'arrowUp',  states: ['waitlist'] },
  { id: 'stride_bulk_quote_sent',        label: 'Offerte verzonden',     icon: 'send',        states: ['confirmed'] },
  { id: 'stride_bulk_quote_exported',    label: 'Offerte verwerkt',      icon: 'checkCircle', states: ['confirmed'] },
  { id: 'stride_bulk_approve_post_course', label: 'Goedkeuren na cursus',icon: 'award',       states: ['confirmed', 'completed'] },
  { id: 'stride_bulk_message',           label: 'Bericht sturen',        icon: 'mail',        states: ['confirmed', 'completed', 'interest', 'pending', 'waitlist'] },
  { id: 'stride_bulk_generate_doc',      label: 'Document genereren',    icon: 'fileText',    states: ['completed'] },
  { id: 'stride_bulk_cancel',            label: 'Annuleren',             icon: 'xCircle',     states: ['pending', 'interest', 'confirmed', 'waitlist'], danger: true },
];

/* valid transitions for a single state (used for case-view buttons) */
function actionsForState(state) {
  return SMART_ACTIONS.filter(a => a.states.includes(state));
}
/* the safe intersection across a set of states (used for the bulk bar) */
function actionsForStates(states) {
  const uniq = [...new Set(states)];
  return SMART_ACTIONS.filter(a => uniq.every(s => a.states.includes(s)));
}

/* ---- avatar color from a name (stable hash) ---- */
const AVA = ['#3b82f6','#0ea5e9','#6366f1','#8b5cf6','#ec4899','#f43f5e','#f97316','#eab308','#22c55e','#14b8a6','#06b6d4'];
function avatarColor(name) {
  let h = 0; for (let i = 0; i < name.length; i++) h = (h * 31 + name.charCodeAt(i)) >>> 0;
  return AVA[h % AVA.length];
}
function initials(name) {
  const p = name.trim().split(/\s+/);
  return ((p[0]?.[0] || '') + (p[p.length - 1]?.[0] || '')).toUpperCase();
}

/* ---- Editions (VAD-style) ----
   e1–e7 are stand-alone offerings; e8–e13 are the course-editions that make up
   the two trajectories below (a trajectory's child registration rows point at
   these). Keeping them as ordinary editions is the whole point of the model:
   a trajectory is just a bundle of edition participations + a parent row. */
const EDITIONS = {
  e1:  { title: 'Motiverende gespreksvoering',                cohort: 'Najaar 2026 · Brussel',    capacity: 16, seatsOpen: 0 },
  e2:  { title: 'Vroeginterventie bij cannabisgebruik',       cohort: 'Reeks 4 · Gent',           capacity: 14, seatsOpen: 3 },
  e3:  { title: 'Omgaan met agressie in de hulpverlening',    cohort: 'Voorjaar 2026 · Antwerpen',capacity: 20, seatsOpen: 0 },
  e4:  { title: 'Basisopleiding tabakoloog',                  cohort: 'Module A · Leuven',        capacity: 24, seatsOpen: 0 },
  e5:  { title: 'Kortdurende interventies bij alcohol',       cohort: 'Reeks 2 · online',         capacity: 30, seatsOpen: 6 },
  e6:  { title: 'Werken met gezinnen rond middelengebruik',   cohort: 'Najaar 2026 · Hasselt',    capacity: 16, seatsOpen: 0 },
  e7:  { title: 'Herstelondersteunende zorg',                 cohort: 'Reeks 1 · Brugge',         capacity: 18, seatsOpen: 2 },
  /* — Basisopleiding Tabakoloog (T1) course-editions — */
  e8:  { title: 'Tabakologie — basismodule',                 cohort: 'Module 1 · Leuven',        capacity: 24, seatsOpen: 4 },
  e9:  { title: 'Rookstopbegeleiding in de praktijk',        cohort: 'Module 2 · Leuven',        capacity: 24, seatsOpen: 6 },
  e10: { title: 'Tabak & specifieke doelgroepen',            cohort: 'Keuzemodule · online',     capacity: 30, seatsOpen: 12 },
  e11: { title: 'Tabak & geestelijke gezondheid',            cohort: 'Keuzemodule · Gent',       capacity: 18, seatsOpen: 5 },
  /* — Postgraduaat Verslavingszorg (T2) course-editions — */
  e12: { title: 'Verslaving — neurobiologie & beleid',       cohort: 'Blok 1 · Antwerpen',       capacity: 20, seatsOpen: 2 },
  e13: { title: 'Casusbespreking & supervisie',             cohort: 'Blok 3 · Antwerpen',       capacity: 16, seatsOpen: 3 },
};

const COMPANIES = {
  c1: 'CGG Brussel',
  c2: 'OCMW Gent',
  c3: 'CAW Antwerpen',
  c4: 'VAD vzw',
  c5: 'CGG Vlaams-Brabant',
  c6: 'De Sleutel',
  c7: 'Free Clinic vzw',
  c8: null, // particulier / geen organisatie
};

/* ---- Registrations (one row = one registration) ----
   path: individual | company | trajectory ; att = attendance % (or null when n/a)

   TRAJECTORY MODEL (spec §11.1) — a trajectory enrolment is NOT a flat set of
   rows. It is:
     • ONE parent row   — trajectory_id = T, edition_id = null,
                          parent_registration_id = null, path = 'trajectory'.
                          ("this person is in trajectory T")  → lives in TRAJ_PARENTS,
                          NOT in the grid corpus (the grid is edition-grained).
     • N child rows     — one per course-edition (mandatory + each chosen elective),
                          edition_id = E, parent_registration_id = <parent.id>,
                          trajectory = T. These carry attendance/quote/status and
                          ARE the rows the grid shows when you filter by trajectory.
   A child row therefore has a `trajectory` field (the trajectory id) AND a
   `parentId` (the parent registration). A naïve "WHERE trajectory_id = T" would
   catch only the parent and miss the course rows — the grid joins parent→child
   via WS.childRegsByTrajectory() instead. */
function reg(id, name, email, ed, status, offerte, att, comp, path, extra) {
  return Object.assign({
    id, name, email,
    edition: ed, status, offerte, attendance: att, company: comp, path,
    trajectory: null, parentId: null,
  }, extra || {});
}

const REGISTRATIONS = [
  reg(101, 'Lotte Vandenberghe', 'lotte.vandenberghe@cgg-brussel.be', 'e1', 'pending',  'none',     null, 'c1', 'company',    { registered: '2026-06-09', age: '4d' }),
  reg(102, 'Sander De Smet',      'sander.desmet@telenet.be',        'e1', 'pending',  'none',     null, 'c8', 'individual', { registered: '2026-06-11', age: '2d' }),
  reg(103, 'Imane El Amrani',     'imane.elamrani@ocmwgent.be',      'e2', 'confirmed','draft',     85, 'c2', 'company',    { registered: '2026-05-20' }),
  reg(104, 'Wout Verhoeven',      'wout.verhoeven@caw-antwerpen.be', 'e3', 'confirmed','sent',      67, 'c3', 'company',    { registered: '2026-05-18' }),
  reg(105, 'Fatima Bensalah',     'f.bensalah@desleutel.be',         'e2', 'waitlist', 'none',     null, 'c6', 'company',    { registered: '2026-06-02' }),
  reg(106, 'Jonas Pauwels',       'jonas.pauwels@gmail.com',         'e5', 'confirmed','exported',  92, 'c8', 'individual', { registered: '2026-04-30' }),
  reg(107, 'Camille Dubois',      'camille.dubois@vad.be',           'e4', 'completed','exported', 100, 'c4', 'company',    { registered: '2026-02-14', completedAt: '2026-05-28', cert: false }),
  reg(108, 'Robbe Maes',          'robbe.maes@cgg-vlbrabant.be',     'e4', 'completed','exported',  88, 'c5', 'company',    { registered: '2026-02-14', completedAt: '2026-05-28', cert: true }),
  reg(109, 'Noor Janssens',       'noor.janssens@freeclinic.be',     'e1', 'interest', 'none',     null, 'c7', 'individual', { registered: '2026-01-08', age: '156d' }),
  reg(110, 'Thibault Lemmens',    'thibault.lemmens@caw-antwerpen.be','e3','confirmed','sent',      45, 'c3', 'company',    { registered: '2026-05-18' }),
  reg(111, 'Hanne Coppens',       'hanne.coppens@ocmwgent.be',       'e2', 'confirmed','draft',     78, 'c2', 'company',    { registered: '2026-05-21' }),
  reg(112, 'Mehdi Ouali',         'mehdi.ouali@gmail.com',           'e5', 'waitlist', 'none',     null, 'c8', 'individual', { registered: '2026-06-05' }),
  reg(113, 'Elise Vermeersch',    'elise.vermeersch@desleutel.be',   'e6', 'pending',  'none',     null, 'c6', 'company',    { registered: '2026-06-10', age: '3d' }),
  reg(114, 'Arne De Backer',      'arne.debacker@cgg-brussel.be',    'e1', 'confirmed','none',     null, 'c1', 'company',    { registered: '2026-06-01' }),
  reg(115, 'Yasmine Haddad',      'yasmine.haddad@vad.be',           'e4', 'completed','exported',  95, 'c4', 'company',    { registered: '2026-02-14', completedAt: '2026-05-28', cert: false }),
  reg(116, 'Lars Wuyts',          'lars.wuyts@hotmail.com',          'e7', 'confirmed','sent',      71, 'c8', 'individual', { registered: '2026-05-12' }),
  reg(117, 'Lien Goossens',       'lien.goossens@cgg-vlbrabant.be',  'e3', 'cancelled','none',     null, 'c5', 'company',    { registered: '2026-04-22', cancelledAt: '2026-05-02' }),
  reg(118, 'Bram Peeters',        'bram.peeters@caw-antwerpen.be',   'e6', 'confirmed','draft',     60, 'c3', 'company',    { registered: '2026-05-25' }),
  reg(119, 'Sofie Claes',         'sofie.claes@gmail.com',           'e5', 'interest', 'none',     null, 'c8', 'individual', { registered: '2026-01-15', age: '149d' }),
  reg(120, 'Karim Belkacem',      'karim.belkacem@freeclinic.be',    'e7', 'confirmed','exported',  83, 'c7', 'company',    { registered: '2026-05-10' }),

  /* ---- TRAJECTORY CHILD ROWS (edition-grained course participations) ----
     These are the rows the grid surfaces when you filter by a trajectory.
     Each carries `trajectory` (the trajectory id) + `parentId` (the parent
     registration in TRAJ_PARENTS). Note Camille (107) and Robbe (108) ALSO have
     a stand-alone e4 row above — a child row is a separate registration. */

  // T1 — Basisopleiding Tabakoloog · Camille Dubois (parent 301)
  reg(401, 'Camille Dubois', 'camille.dubois@vad.be',          'e8',  'completed','exported', 100, 'c4', 'trajectory', { registered: '2026-02-10', parentId: 301, trajectory: 't1', completedAt: '2026-04-02', cert: true }),
  reg(402, 'Camille Dubois', 'camille.dubois@vad.be',          'e9',  'confirmed','exported',  88, 'c4', 'trajectory', { registered: '2026-02-10', parentId: 301, trajectory: 't1' }),
  reg(403, 'Camille Dubois', 'camille.dubois@vad.be',          'e10', 'confirmed','exported',  null, 'c4', 'trajectory', { registered: '2026-02-10', parentId: 301, trajectory: 't1' }),
  // T1 · Robbe Maes (parent 302)
  reg(404, 'Robbe Maes',     'robbe.maes@cgg-vlbrabant.be',    'e8',  'completed','exported',  92, 'c5', 'trajectory', { registered: '2026-02-12', parentId: 302, trajectory: 't1', completedAt: '2026-04-02', cert: true }),
  reg(405, 'Robbe Maes',     'robbe.maes@cgg-vlbrabant.be',    'e9',  'confirmed','sent',       76, 'c5', 'trajectory', { registered: '2026-02-12', parentId: 302, trajectory: 't1' }),
  reg(406, 'Robbe Maes',     'robbe.maes@cgg-vlbrabant.be',    'e11', 'pending',  'none',      null, 'c5', 'trajectory', { registered: '2026-02-12', parentId: 302, trajectory: 't1', age: '2d' }),

  // T2 — Postgraduaat Verslavingszorg · Imane El Amrani (parent 303 — the dossier person)
  reg(407, 'Imane El Amrani','imane.elamrani@ocmwgent.be',     'e12', 'completed','exported', 100, 'c2', 'trajectory', { registered: '2025-09-15', parentId: 303, trajectory: 't2', completedAt: '2026-01-20', cert: true }),
  reg(408, 'Imane El Amrani','imane.elamrani@ocmwgent.be',     'e2',  'confirmed','draft',      85, 'c2', 'trajectory', { registered: '2025-09-15', parentId: 303, trajectory: 't2' }),
  reg(409, 'Imane El Amrani','imane.elamrani@ocmwgent.be',     'e13', 'confirmed','sent',       null, 'c2', 'trajectory', { registered: '2025-09-15', parentId: 303, trajectory: 't2' }),
];

/* ---- Trajectory PARENT rows (the "person is in trajectory T" rows) ----
   edition_id = null, parent_registration_id = null, path = 'trajectory'.
   Deliberately kept OUT of REGISTRATIONS so the grid stays edition-grained:
   filtering by a trajectory shows its CHILD edition-rows, never these. */
const TRAJ_PARENTS = [
  { id: 301, user: 'Camille Dubois', email: 'camille.dubois@vad.be',       company: 'c4', trajectory: 't1', status: 'in_progress', registered: '2026-02-10' },
  { id: 302, user: 'Robbe Maes',     email: 'robbe.maes@cgg-vlbrabant.be', company: 'c5', trajectory: 't1', status: 'in_progress', registered: '2026-02-12' },
  { id: 303, user: 'Imane El Amrani',email: 'imane.elamrani@ocmwgent.be',  company: 'c2', trajectory: 't2', status: 'in_progress', registered: '2025-09-15' },
];

/* the parent→child join (spec §11.2): "give me the child edition-rows of
   trajectory T". This is the correct semantics — NOT a bare column filter. */
function childRegsByTrajectory(trajectoryId) {
  return REGISTRATIONS.filter(r => r.trajectory === trajectoryId);
}

/* ---- Worklist queues (spec §1, exact labels) ---- */
const QUEUES = [
  { key: 'pending',   label: 'Wacht op goedkeuring',           def: 'status = in afwachting',
    count: 7,  accent: '#d97706', icon: 'hourglass',
    action: 'Goedkeuren', actionIcon: 'checkCircle' },
  { key: 'waitlist',  label: 'Wachtlijst — plaatsen vrij',     def: 'wachtlijst + editie heeft vrije plaatsen',
    count: 3,  accent: '#8b5cf6', icon: 'seat',
    action: 'Promoveer van wachtlijst', actionIcon: 'arrowUp' },
  { key: 'offerte',   label: 'Offerte-opvolging',              def: 'bevestigd + offerte nog niet verwerkt',
    count: 12, accent: '#2563eb', icon: 'receipt',
    action: 'Markeer verzonden / verwerkt', actionIcon: 'send' },
  { key: 'nocert',    label: 'Afgerond zonder certificaat',    def: 'afgerond + voltooid + geen LD-certificaat',
    count: 2,  accent: '#16a34a', icon: 'award',
    action: 'Bericht sturen', actionIcon: 'mail' },
  { key: 'oldinterest', label: 'Oude interesse',               def: 'interesse + ouder dan 90 dagen',
    count: 0,  accent: '#64748b', icon: 'clock',
    action: 'Bericht sturen / archiveren', actionIcon: 'archive' },
];

/* which queue maps to which grid filter (the ?queue= → chip simulation) */
const QUEUE_FILTER = {
  pending:     { status: 'pending',   armAction: 'stride_bulk_approve',          chip: 'In afwachting' },
  waitlist:    { status: 'waitlist',  armAction: 'stride_bulk_promote_waitlist', chip: 'Wachtlijst' },
  offerte:     { status: 'confirmed', offerteNot: 'exported', armAction: 'stride_bulk_quote_sent', chip: 'Bevestigd · offerte open' },
  nocert:      { status: 'completed', noCert: true, armAction: 'stride_bulk_message', chip: 'Afgerond · geen certificaat' },
  oldinterest: { status: 'interest',  armAction: 'stride_bulk_message', chip: 'Oude interesse' },
};

/* ---- Acties-nodig promoted panel (3 sub-queues) ---- */
const ACTION_QUEUE = {
  mij: [
    { name: 'Lotte Vandenberghe', meta: 'Motiverende gespreksvoering · wacht op goedkeuring', age: 'sinds 4d', regId: 101 },
    { name: 'Sander De Smet',     meta: 'Motiverende gespreksvoering · wacht op goedkeuring', age: 'sinds 2d', regId: 102 },
    { name: 'Elise Vermeersch',   meta: 'Werken met gezinnen · wacht op goedkeuring',         age: 'sinds 3d', regId: 113 },
  ],
  gebruiker: [
    { name: 'Imane El Amrani',    meta: 'Offerte verstuurd, wacht op bevestiging klant', age: 'sinds 9d', regId: 103 },
    { name: 'Lars Wuyts',         meta: 'Intake-formulier nog niet ingevuld',            age: 'sinds 12d', regId: 116 },
  ],
  meldingen: [
    { name: 'Camille Dubois',     meta: 'Afgerond — certificaat nog niet uitgereikt', age: 'gisteren', regId: 107 },
    { name: 'Yasmine Haddad',     meta: 'Afgerond — certificaat nog niet uitgereikt', age: '2d geleden', regId: 115 },
  ],
};

/* ---- Vandaag stat strip ---- */
const STATS = [
  { label: 'Komende edities',       num: 7,   delta: '+2 deze maand', kind: 'up',   icon: 'layers' },
  { label: 'Actieve inschrijvingen',num: 247, delta: '+18 deze week',  kind: 'up',   icon: 'users' },
  { label: 'Openstaande offertes',  num: 12,  delta: 'ongewijzigd',    kind: 'flat', icon: 'receipt' },
  { label: 'Sessies vandaag',       num: 3,   delta: 'Brussel · Gent', kind: 'flat', icon: 'calendar' },
];

/* ==========================================================================
   DOSSIER fixture — one person, multiple registrations, full join
   ========================================================================== */
const DOSSIER = {
  person: {
    name: 'Imane El Amrani',
    org: 'OCMW Gent',
    department: 'Team Verslavingszorg',
    email: 'imane.elamrani@ocmwgent.be',
    phone: '+32 9 266 50 11',
    city: 'Gent',
    role: 'Maatschappelijk werker',
  },
  registrations: [
    {
      id: 103, open: true,
      edition: 'Vroeginterventie bij cannabisgebruik',
      cohort: 'Reeks 4 · Gent',
      status: 'confirmed',
      offerte: 'draft',
      path: 'Via organisatie (OCMW Gent)',
      registered: '20 mei 2026',
      startDate: '12 sep 2026',
      quote: { ref: 'OFF-2026-0418', amount: '€ 540,00', status: 'draft' },
      stages: {
        interest:            { submitted_at: '18 mei 2026 · 14:22', submitted_by: 'Imane El Amrani', data: { 'Motivatie': 'Wil vaardigheden aanscherpen voor jongerenwerking', 'Voorkennis': 'Beperkt' } },
        enrollment_personal: { submitted_at: '20 mei 2026 · 09:41', submitted_by: 'Imane El Amrani', data: { 'Voornaam': 'Imane', 'Achternaam': 'El Amrani', 'Organisatie': 'OCMW Gent', 'Afdeling': 'Team Verslavingszorg', 'Functie': 'Maatschappelijk werker', 'Telefoon': '+32 9 266 50 11' } },
        enrollment_billing:  { submitted_at: '20 mei 2026 · 09:43', submitted_by: 'Imane El Amrani', data: { 'Facturatiebedrijf': 'OCMW Gent', 'BTW-nummer': 'BE 0212.171.213', 'Adres': 'Onderbergen 86, 9000 Gent', 'Factuur-e-mail': 'facturen@ocmwgent.be', 'GLN-nummer': '5400000000016' } },
        initial_selection:   { submitted_at: '20 mei 2026 · 09:44', submitted_by: 'Imane El Amrani', data: { 'Sessie 1 — Kader & wettelijke context': 'Gekozen (verplicht)', 'Sessie 2 — Vroegdetectie': 'Gekozen (verplicht)', 'Keuzemodule fase 1': 'Jongeren & cannabis' } },
        // intake = the questionnaire answers submitted after confirmation.
        // These ARE the "Intakevragen"; there is no separate Vragenlijst dataset.
        intake:              { submitted_at: '22 mei 2026 · 16:08', submitted_by: 'Imane El Amrani', data: {
          'Jaren ervaring in de hulpverlening': '6 jaar',
          'Werkt rechtstreeks met cliënten rond cannabisgebruik': 'Ja, wekelijks',
          'Welk thema zeker behandeld zien': 'Omgaan met ambivalentie bij jongeren',
          'Dieetwensen': 'Vegetarisch',
          'Toegankelijkheid': 'Geen bijzonderheden',
        } },
        evaluation:          null, // not yet submitted → "hidden when empty" demo (Fix 5)
      },
      attendance: [
        { title: 'Sessie 1 — Kader & wettelijke context', date: '12 sep 2026', state: 'upcoming' },
        { title: 'Sessie 2 — Vroegdetectie',              date: '19 sep 2026', state: 'upcoming' },
        { title: 'Sessie 3 — Gespreksmethodieken',        date: '26 sep 2026', state: 'upcoming' },
      ],
      selections: ['Sessie 1 (verplicht)', 'Sessie 2 (verplicht)', 'Keuzemodule: Jongeren & cannabis'],
      completion: [
        { label: 'Goedkeuring inschrijving', done: true },
        { label: 'Intake ingevuld',          done: true },
        { label: 'Aanwezigheid ≥ 80%',       done: false },
        { label: 'Eindevaluatie',            done: false },
      ],
      notes: [
        { text: 'Belde op 21/05 om te vragen of de keuzemodule jongeren ook online te volgen is — bevestigd dat dit kan.', meta: 'Notitie door Sofie (coördinator) · 21 mei 2026' },
      ],
      // newest first — the home of every per-write event for this registration
      timeline: [
        { dot: 'primary', icon: 'route',       title: 'Keuze gemaakt: Specialisatie → Kortdurende interventies bij alcohol', actor: 'Imane El Amrani', when: '28 mei 2026 · 11:40' },
        { dot: 'primary', icon: 'mail',        title: 'Herinnering verstuurd: intake afronden',     actor: 'Sofie (coördinator)', when: '24 mei 2026 · 10:12' },
        { dot: 'warning', icon: 'send',        title: 'Offerte OFF-2026-0418 verzonden naar klant', actor: 'Sofie (coördinator)', when: '23 mei 2026 · 09:15' },
        { dot: 'warning', icon: 'receipt',     title: 'Offerte OFF-2026-0418 aangemaakt (in behandeling)', actor: 'Systeem', when: '23 mei 2026 · 08:30' },
        { dot: 'default', icon: 'fileText',    title: 'Intakevragenlijst ingediend',                actor: 'Imane El Amrani', when: '22 mei 2026 · 16:08' },
        { dot: 'success', icon: 'userCheck',   title: 'Inschrijving goedgekeurd → bevestigd',       actor: 'Sofie (coördinator)', when: '20 mei 2026 · 11:05' },
        { dot: 'primary', icon: 'route',       title: 'Sessies gekozen: Sessie 1 (vm), Sessie 2 · keuzemodule "Jongeren & cannabis"', actor: 'Imane El Amrani', when: '20 mei 2026 · 09:44' },
        { dot: 'default', icon: 'receipt',     title: 'Facturatiegegevens ingediend',               actor: 'Imane El Amrani', when: '20 mei 2026 · 09:43' },
        { dot: 'default', icon: 'user',        title: 'Persoonsgegevens ingediend',                 actor: 'Imane El Amrani', when: '20 mei 2026 · 09:41' },
        { dot: 'primary', icon: 'userPlus',    title: 'Inschrijving aangemaakt (via organisatie)',  actor: 'Imane El Amrani', when: '20 mei 2026 · 09:40' },
        { dot: 'success', icon: 'route',       title: 'Ingeschreven voor traject: Postgraduaat Verslavingszorg', actor: 'Imane El Amrani', when: '15 sep 2025 · 10:05' },
        { dot: 'primary', icon: 'sparkle',     title: 'Interesse geregistreerd',                    actor: 'Imane El Amrani', when: '18 mei 2026 · 14:22' },
      ],
    },
    {
      id: 211, open: false,
      edition: 'Motiverende gespreksvoering',
      cohort: 'Voorjaar 2024 · Gent',
      status: 'completed',
      offerte: 'exported',
      path: 'Via organisatie (OCMW Gent)',
      registered: '14 jan 2024',
      startDate: '8 mrt 2024',
      quote: { ref: 'OFF-2024-0091', amount: '€ 495,00', status: 'exported' },
      stages: {
        interest:            { submitted_at: '10 jan 2024 · 10:00', submitted_by: 'Imane El Amrani', data: { 'Motivatie': 'Basis MI onder de knie krijgen' } },
        enrollment_personal: { submitted_at: '14 jan 2024 · 13:20', submitted_by: 'Imane El Amrani', data: { 'Voornaam': 'Imane', 'Organisatie': 'OCMW Gent' } },
        enrollment_billing:  { submitted_at: '14 jan 2024 · 13:22', submitted_by: 'Imane El Amrani', data: { 'Facturatiebedrijf': 'OCMW Gent', 'BTW-nummer': 'BE 0212.171.213' } },
        initial_selection:   { submitted_at: '14 jan 2024 · 13:24', submitted_by: 'Imane El Amrani', data: { 'Reeks': 'Volledige reeks (4 sessies)' } },
        intake:              { submitted_at: '1 feb 2024 · 09:00', submitted_by: 'Imane El Amrani', data: { 'Jaren ervaring in de hulpverlening': '4 jaar (in 2024)', 'Dieetwensen': 'Vegetarisch' } },
        evaluation:          { submitted_at: '30 apr 2024 · 17:30', submitted_by: 'Imane El Amrani', data: { 'Tevredenheid': '5/5', 'Aanbeveling': 'Zeker aanbevolen', 'Opmerking': 'Sterke begeleiding, praktijkgericht' } },
      },
      attendance: [
        { title: 'Sessie 1 — Geest van MI',     date: '8 mrt 2024',  state: 'present' },
        { title: 'Sessie 2 — Reflectief luisteren', date: '15 mrt 2024', state: 'present' },
        { title: 'Sessie 3 — Omgaan met weerstand', date: '22 mrt 2024', state: 'excused' },
        { title: 'Sessie 4 — Versterken van verandertaal', date: '29 mrt 2024', state: 'present' },
      ],
      selections: ['Volledige reeks (4 sessies)'],
      completion: [
        { label: 'Goedkeuring inschrijving', done: true },
        { label: 'Aanwezigheid ≥ 80%',       done: true },
        { label: 'Eindevaluatie',            done: true },
        { label: 'Certificaat uitgereikt',   done: true },
      ],
      notes: [],
      // newest first — full lifecycle incl. per-session attendance + selection
      timeline: [
        { dot: 'success', icon: 'award',     title: 'Certificaat uitgereikt',                       actor: 'Systeem', when: '2 mei 2024 · 09:00' },
        { dot: 'success', icon: 'checkCircle',title: 'Cursus afgerond (alle voltooiingstaken behaald)', actor: 'Systeem', when: '29 mrt 2024 · 17:30' },
        { dot: 'default', icon: 'fileText',  title: 'Evaluatie (na afloop) ingediend',              actor: 'Imane El Amrani', when: '30 apr 2024 · 17:30' },
        { dot: 'success', icon: 'check',     title: 'Aanwezig gemarkeerd — Sessie 4 (Versterken van verandertaal)', actor: 'Tom (coördinator)', when: '29 mrt 2024 · 12:30' },
        { dot: 'warning', icon: 'slash',     title: 'Verontschuldigd gemarkeerd — Sessie 3 (Omgaan met weerstand)', actor: 'Tom (coördinator)', when: '22 mrt 2024 · 12:30' },
        { dot: 'success', icon: 'check',     title: 'Aanwezig gemarkeerd — Sessie 2 (Reflectief luisteren)', actor: 'Tom (coördinator)', when: '15 mrt 2024 · 12:30' },
        { dot: 'success', icon: 'check',     title: 'Aanwezig gemarkeerd — Sessie 1 (Geest van MI)', actor: 'Tom (coördinator)', when: '8 mrt 2024 · 12:30' },
        { dot: 'primary', icon: 'route',     title: 'Sessies gekozen: Volledige reeks (4 sessies)', actor: 'Imane El Amrani', when: '14 jan 2024 · 13:24' },
        { dot: 'success', icon: 'userCheck', title: 'Inschrijving bevestigd',                       actor: 'Tom (coördinator)', when: '14 jan 2024 · 13:30' },
        { dot: 'primary', icon: 'userPlus',  title: 'Inschrijving aangemaakt (via organisatie)',    actor: 'Imane El Amrani', when: '14 jan 2024 · 13:20' },
        { dot: 'primary', icon: 'sparkle',   title: 'Interesse geregistreerd',                      actor: 'Imane El Amrani', when: '10 jan 2024 · 10:00' },
      ],
    },
    {
      // PENDING registration — demonstrates the "Inschrijvingsstatus" pending
      // hint (Fix 4) and "hidden when empty" stages (Fix 5: intake + evaluation
      // not yet submitted, so those panels never render).
      id: 318, open: false,
      edition: 'Omgaan met agressie in de hulpverlening',
      cohort: 'Voorjaar 2026 · Antwerpen',
      status: 'pending',
      offerte: 'none',
      path: 'Via organisatie (OCMW Gent)',
      registered: '9 jun 2026',
      startDate: '3 okt 2026',
      quote: { ref: '—', amount: 'Nog geen offerte', status: 'none' },
      stages: {
        interest:            null, // direct ingeschreven, geen interesse-stap → hidden
        enrollment_personal: { submitted_at: '9 jun 2026 · 11:02', submitted_by: 'Imane El Amrani', data: { 'Voornaam': 'Imane', 'Achternaam': 'El Amrani', 'Organisatie': 'OCMW Gent', 'Functie': 'Maatschappelijk werker' } },
        enrollment_billing:  { submitted_at: '9 jun 2026 · 11:04', submitted_by: 'Imane El Amrani', data: { 'Facturatiebedrijf': 'OCMW Gent', 'BTW-nummer': 'BE 0212.171.213' } },
        initial_selection:   null, // sessiekeuze nog niet gemaakt → hidden
        intake:              null, // intake nog niet ingevuld → hidden (Fix 5 demo)
        evaluation:          null, // n.v.t. → hidden
      },
      attendance: [
        { title: 'Sessie 1 — Veiligheid & de-escalatie', date: '3 okt 2026',  state: 'upcoming' },
        { title: 'Sessie 2 — Grenzen stellen',           date: '10 okt 2026', state: 'upcoming' },
      ],
      selections: [],
      completion: [
        { label: 'Persoonsgegevens', done: true },
        { label: 'Sessiekeuze',      done: false },
        { label: 'Intakevragen',     done: false },
        { label: 'Goedkeuring inschrijving', done: false },
      ],
      notes: [],
      timeline: [
        { dot: 'primary', icon: 'receipt',  title: 'Facturatiegegevens ingediend',              actor: 'Imane El Amrani', when: '9 jun 2026 · 11:04' },
        { dot: 'default', icon: 'user',     title: 'Persoonsgegevens ingediend',                actor: 'Imane El Amrani', when: '9 jun 2026 · 11:02' },
        { dot: 'primary', icon: 'userPlus', title: 'Inschrijving aangemaakt (via organisatie)', actor: 'Imane El Amrani', when: '9 jun 2026 · 11:00' },
      ],
    },
  ],

  /* ---- TRAJECTORY PROGRESS (spec §11.4 — the case-view section, F8) ----
     One block per trajectory the user is in. Shape mirrors what
     TrajectoryDashboardService::getProgressData() returns + the per-item state
     the live template (tab-voortgang.php) derives:
       trajectory          { id, title, status, mode }
       total_required      int   (required courses + Σ each group's `required`)
       completed_count     int
       in_progress_count   int
       required_courses[]  { title, cohort, edition, state }   // state derived:
                           //   id ∈ completed_courses → 'afgerond'
                           //   id ∈ in_progress_courses → 'bezig'
                           //   else → 'nog te volgen'
       elective_groups[]   { name, required, total, countChosen, isChosen, chosen[] }
                           // chosen-vs-required via getSelectedCourseIds /
                           //   isGroupChosen / countChosenInGroup (INV-6b)
     A non-trajectory user simply has trajectories: [] → the section is absent. */
  trajectories: [
    {
      trajectory: { id: 't2', title: 'Postgraduaat Verslavingszorg', status: 'open', mode: 'cohort' },
      total_required: 6,
      completed_count: 1,
      in_progress_count: 2,
      registered: '15 sep 2025',
      required_courses: [
        { title: 'Verslaving — neurobiologie & beleid', cohort: 'Blok 1 · Antwerpen', edition: 'e12', state: 'afgerond',     completedAt: '20 jan 2026' },
        { title: 'Vroeginterventie bij cannabisgebruik', cohort: 'Reeks 4 · Gent',    edition: 'e2',  state: 'bezig' },
        { title: 'Casusbespreking & supervisie',         cohort: 'Blok 3 · Antwerpen', edition: 'e13', state: 'bezig' },
      ],
      elective_groups: [
        // fully chosen: kies 1 uit 2 → 1 gekozen, confirmed
        { name: 'Specialisatie', required: 1, total: 2, countChosen: 1, isChosen: true,
          chosen: [ { title: 'Kortdurende interventies bij alcohol', edition: 'e5' } ] },
        // partially chosen: kies 2 uit 3 → 1 gekozen, NOT confirmed yet
        { name: 'Vrije verdieping', required: 2, total: 3, countChosen: 1, isChosen: false,
          chosen: [ { title: 'Werken met gezinnen rond middelengebruik', edition: 'e6' } ] },
      ],
    },
  ],
};

/* human-readable stage names + a one-line "what this stage is".
   `intake` and `evaluation` are the two real questionnaire stages — intake is
   the questionnaire submitted AFTER confirmation (same thing the completion
   task "Intakevragen" tracks), evaluation is the post-course questionnaire.
   There is no separate "Vragenlijst" dataset — the answers ARE the intake stage. */
const STAGE_META = {
  interest:            { name: 'Interesse',                       icon: 'sparkle',  desc: 'Eerste interesse, vóór inschrijving.' },
  waitlist:            { name: 'Wachtlijst',                      icon: 'seat',     desc: 'Aangemeld op de wachtlijst.' },
  enrollment_personal: { name: 'Inschrijving — persoonsgegevens', icon: 'user',    desc: 'Persoonsgegevens ingevuld bij inschrijving.' },
  enrollment_billing:  { name: 'Inschrijving — facturatie',       icon: 'receipt', desc: 'Facturatiegegevens ingevuld bij inschrijving.' },
  initial_selection:   { name: 'Initiële sessiekeuze',            icon: 'route',    desc: 'Keuze van sessies/keuzemodules bij inschrijving.' },
  intake:              { name: 'Intakevragenlijst',               icon: 'fileText', desc: 'Vragenlijst ingevuld na bevestiging (de "Intakevragen"-taak).' },
  evaluation:          { name: 'Evaluatie (na afloop)',           icon: 'award',    desc: 'Eindevaluatie ingevuld na de cursus.' },
};

/* ==========================================================================
   TRAJECTORIES (Surface 4) — the trajectory ENTITIES
   --------------------------------------------------------------------------
   Mirrors the vad_trajectory CPT: title + status + mode + capacity, and a
   `courses` config that the repo partitions into required courses (config
   `required:true`) and elective groups (`required:false`, grouped by `group`,
   with `min_choices` → the resolved group's `required` int). Here each course
   maps to an edition (so the Traject column/jump-to-grid line up); a real
   trajectory could also carry pure-LD courses with no edition.
   Status labels per §1 Surface 4: Concept / Open / Volzet / Afgesloten / Gearchiveerd.
   ========================================================================== */
const TRAJ_STATUS = {
  draft:       { label: 'Concept',       cls: 'completed' },  // slate
  open:        { label: 'Open',          cls: 'confirmed' },  // green
  full:        { label: 'Volzet',        cls: 'waitlist'  },  // purple
  closed:      { label: 'Afgesloten',    cls: 'pending'   },  // amber
  archived:    { label: 'Gearchiveerd',  cls: 'cancelled' },  // red/grey
  // per-USER trajectory status (the roster badge) reuses the reg-status hues:
  in_progress: { label: 'Bezig',         cls: 'interest'  },
  completed:   { label: 'Afgerond',      cls: 'completed' },
};
const TRAJ_MODE = { cohort: 'Cohorte', self_paced: 'Zelfstandig tempo' };

/* a tiny course helper so trajectory course-lists can show a title + cohort
   from the edition map (course === backing edition in this mockup). */
function trajCourse(editionKey, extra) {
  const e = EDITIONS[editionKey] || { title: editionKey, cohort: '' };
  return Object.assign({ edition: editionKey, title: e.title, cohort: e.cohort }, extra || {});
}

const TRAJECTORIES = {
  t1: {
    id: 't1',
    title: 'Basisopleiding Tabakoloog',
    status: 'open',
    mode: 'cohort',
    capacity: 24,
    description: 'Erkende basisopleiding tot tabakoloog — twee verplichte modules plus een verdiepende keuzemodule.',
    // required courses (config required:true)
    required: [
      trajCourse('e8'),
      trajCourse('e9'),
    ],
    // elective groups (resolved shape: { name, required:int, courses:[...] })
    electiveGroups: [
      { name: 'Verdiepingsmodule', required: 1, courses: [ trajCourse('e10'), trajCourse('e11') ] },
    ],
    // enrolled users (the roster — parent rows resolved to a per-user view)
    users: [
      { name: 'Camille Dubois', email: 'camille.dubois@vad.be',       company: 'c4', status: 'in_progress', completed: 2, total: 3, parentId: 301 },
      { name: 'Robbe Maes',     email: 'robbe.maes@cgg-vlbrabant.be', company: 'c5', status: 'in_progress', completed: 1, total: 3, parentId: 302 },
    ],
  },
  t2: {
    id: 't2',
    title: 'Postgraduaat Verslavingszorg',
    status: 'open',
    mode: 'cohort',
    capacity: 20,
    description: 'Postgraduaat voor hulpverleners — drie verplichte blokken en een specialisatiekeuze.',
    required: [
      trajCourse('e12'),
      trajCourse('e2'),
      trajCourse('e13'),
    ],
    electiveGroups: [
      { name: 'Specialisatie', required: 1, courses: [ trajCourse('e5'), trajCourse('e7') ] },
      { name: 'Vrije verdieping', required: 2, courses: [ trajCourse('e1'), trajCourse('e6'), trajCourse('e7') ] },
    ],
    users: [
      { name: 'Imane El Amrani', email: 'imane.elamrani@ocmwgent.be', company: 'c2', status: 'in_progress', completed: 1, total: 6, parentId: 303 },
    ],
  },
  t3: {
    // EMPTY trajectory — 0 enrollments (F7 empty edge) + Concept + self-paced.
    id: 't3',
    title: 'Train-de-trainer Vroeginterventie',
    status: 'draft',
    mode: 'self_paced',
    capacity: 0,                 // 0 = onbeperkt
    description: 'Opleiding tot trainer vroeginterventie — nog in voorbereiding, nog geen inschrijvingen.',
    required: [
      trajCourse('e3'),
    ],
    electiveGroups: [
      { name: 'Didactiek', required: 1, courses: [ trajCourse('e1'), trajCourse('e6') ] },
    ],
    users: [],
  },
  t4: {
    // CLOSED trajectory — hidden by the default "Actieve trajecten" scope, so the
    // scope pill earns its keep: clearing it (scope=all) reveals this one.
    id: 't4',
    title: 'Basisopleiding Tabakoloog — reeks 2024',
    status: 'closed',
    mode: 'cohort',
    capacity: 24,
    description: 'Afgesloten reeks 2024 — gearchiveerd voor naslag.',
    required: [
      trajCourse('e8'),
      trajCourse('e9'),
    ],
    electiveGroups: [
      { name: 'Verdiepingsmodule', required: 1, courses: [ trajCourse('e10'), trajCourse('e11') ] },
    ],
    users: [
      { name: 'Lien Goossens', email: 'lien.goossens@cgg-vlbrabant.be', company: 'c5', status: 'completed', completed: 3, total: 3, parentId: 304 },
    ],
  },
};

/* the trajectory typeahead source (mirrors GET /admin/trajectories/options) —
   {id, title, status} only, scoped to "active" by default like the editions picker. */
const TRAJ_OPTIONS = Object.values(TRAJECTORIES).map(t => ({ id: t.id, title: t.title, status: t.status }));
/* "active" = not terminal: not closed/archived (mirrors §10 active scope posture) */
function trajIsActive(t) { return t.status !== 'closed' && t.status !== 'archived'; }
function trajCourseCount(t) {
  return t.required.length + t.electiveGroups.reduce((n, g) => n + g.required, 0);
}

/* ---- expose ---- */
window.WS = {
  icon, ICONS,
  REG_STATUS, OFFERTE_STATUS, STATUS_PIPELINE, STATUS_EXIT, SMART_ACTIONS, actionsForState, actionsForStates,
  avatarColor, initials,
  EDITIONS, COMPANIES, REGISTRATIONS, QUEUES, QUEUE_FILTER, ACTION_QUEUE, STATS,
  DOSSIER, STAGE_META,
  TRAJECTORIES, TRAJ_STATUS, TRAJ_MODE, TRAJ_PARENTS, TRAJ_OPTIONS,
  childRegsByTrajectory, trajIsActive, trajCourseCount,
};
})();
