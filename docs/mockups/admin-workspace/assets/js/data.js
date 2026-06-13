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
};

function icon(name, cls) {
  const path = ICONS[name] || '';
  return '<svg class="' + (cls || '') + '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + path + '</svg>';
}

/* ---- Status maps (verbatim from the enums) ---- */
const REG_STATUS = {
  confirmed: { label: 'Bevestigd',      cls: 'confirmed' },
  completed: { label: 'Afgerond',       cls: 'completed' },
  cancelled: { label: 'Geannuleerd',    cls: 'cancelled' },
  waitlist:  { label: 'Wachtlijst',     cls: 'waitlist'  },
  interest:  { label: 'Interesse',      cls: 'interest'  },
  pending:   { label: 'In afwachting',  cls: 'pending'   },
};

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

/* ---- Editions (VAD-style) ---- */
const EDITIONS = {
  e1:  { title: 'Motiverende gespreksvoering',                cohort: 'Najaar 2026 · Brussel',    capacity: 16, seatsOpen: 0 },
  e2:  { title: 'Vroeginterventie bij cannabisgebruik',       cohort: 'Reeks 4 · Gent',           capacity: 14, seatsOpen: 3 },
  e3:  { title: 'Omgaan met agressie in de hulpverlening',    cohort: 'Voorjaar 2026 · Antwerpen',capacity: 20, seatsOpen: 0 },
  e4:  { title: 'Basisopleiding tabakoloog',                  cohort: 'Module A · Leuven',        capacity: 24, seatsOpen: 0 },
  e5:  { title: 'Kortdurende interventies bij alcohol',       cohort: 'Reeks 2 · online',         capacity: 30, seatsOpen: 6 },
  e6:  { title: 'Werken met gezinnen rond middelengebruik',   cohort: 'Najaar 2026 · Hasselt',    capacity: 16, seatsOpen: 0 },
  e7:  { title: 'Herstelondersteunende zorg',                 cohort: 'Reeks 1 · Brugge',         capacity: 18, seatsOpen: 2 },
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

/* ---- Registrations (20 realistic rows; row = one registration) ----
   path: individual | company | trajectory ; att = attendance % (or null when n/a) */
function reg(id, name, email, ed, status, offerte, att, comp, path, extra) {
  return Object.assign({
    id, name, email,
    edition: ed, status, offerte, attendance: att, company: comp, path,
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
];

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
        interest:            { submitted_at: '2026-05-18 14:22', submitted_by: 'Imane El Amrani', data: { 'Motivatie': 'Wil vaardigheden aanscherpen voor jongerenwerking', 'Voorkennis': 'Beperkt' } },
        enrollment_personal: { submitted_at: '2026-05-20 09:41', submitted_by: 'Imane El Amrani', data: { 'Voornaam': 'Imane', 'Achternaam': 'El Amrani', 'Organisatie': 'OCMW Gent', 'Afdeling': 'Team Verslavingszorg', 'Functie': 'Maatschappelijk werker', 'Telefoon': '+32 9 266 50 11' } },
        enrollment_billing:  { submitted_at: '2026-05-20 09:43', submitted_by: 'Imane El Amrani', data: { 'Facturatiebedrijf': 'OCMW Gent', 'BTW-nummer': 'BE 0212.171.213', 'Adres': 'Onderbergen 86, 9000 Gent', 'Factuur-e-mail': 'facturen@ocmwgent.be', 'GLN-nummer': '5400000000016' } },
        intake:              { submitted_at: '2026-05-22 16:08', submitted_by: 'Imane El Amrani', data: { 'Dieetwensen': 'Vegetarisch', 'Toegankelijkheid': 'Geen bijzonderheden', 'Verwachtingen': 'Concrete gespreksmethodieken' } },
        evaluation:          null, // not yet submitted → empty-state demo
      },
      questionnaire: [
        { q: 'Hoeveel jaar ervaring heb je in de hulpverlening?', a: '6 jaar' },
        { q: 'Werk je rechtstreeks met cliënten rond cannabisgebruik?', a: 'Ja, wekelijks' },
        { q: 'Welk thema wil je zeker behandeld zien?', a: 'Omgaan met ambivalentie bij jongeren' },
      ],
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
      timeline: [
        { dot: 'primary', title: 'Interesse geregistreerd',            actor: 'Imane El Amrani', when: '18 mei 2026 · 14:22' },
        { dot: 'default', title: 'Persoonsgegevens ingediend',         actor: 'Imane El Amrani', when: '20 mei 2026 · 09:41' },
        { dot: 'default', title: 'Facturatiegegevens ingediend',       actor: 'Imane El Amrani', when: '20 mei 2026 · 09:43' },
        { dot: 'success', title: 'Inschrijving goedgekeurd → bevestigd', actor: 'Sofie (coördinator)', when: '20 mei 2026 · 11:05' },
        { dot: 'default', title: 'Intake-formulier ingediend',         actor: 'Imane El Amrani', when: '22 mei 2026 · 16:08' },
        { dot: 'warning', title: 'Offerte OFF-2026-0418 aangemaakt (in behandeling)', actor: 'Systeem', when: '23 mei 2026 · 08:30' },
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
        interest:            { submitted_at: '2024-01-10 10:00', submitted_by: 'Imane El Amrani', data: { 'Motivatie': 'Basis MI onder de knie krijgen' } },
        enrollment_personal: { submitted_at: '2024-01-14 13:20', submitted_by: 'Imane El Amrani', data: { 'Voornaam': 'Imane', 'Organisatie': 'OCMW Gent' } },
        enrollment_billing:  { submitted_at: '2024-01-14 13:22', submitted_by: 'Imane El Amrani', data: { 'Facturatiebedrijf': 'OCMW Gent', 'BTW-nummer': 'BE 0212.171.213' } },
        intake:              { submitted_at: '2024-02-01 09:00', submitted_by: 'Imane El Amrani', data: { 'Dieetwensen': 'Vegetarisch' } },
        evaluation:          { submitted_at: '2024-04-30 17:30', submitted_by: 'Imane El Amrani', data: { 'Tevredenheid': '5/5', 'Aanbeveling': 'Zeker aanbevolen', 'Opmerking': 'Sterke begeleiding, praktijkgericht' } },
      },
      questionnaire: [
        { q: 'Ervaring in de hulpverlening?', a: '4 jaar (in 2024)' },
      ],
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
      timeline: [
        { dot: 'primary', title: 'Interesse geregistreerd',  actor: 'Imane El Amrani', when: '10 jan 2024' },
        { dot: 'success', title: 'Inschrijving bevestigd',   actor: 'Tom (coördinator)', when: '14 jan 2024' },
        { dot: 'success', title: 'Cursus afgerond',          actor: 'Systeem', when: '29 mrt 2024' },
        { dot: 'success', title: 'Certificaat uitgereikt',   actor: 'Systeem', when: '2 mei 2024' },
        { dot: 'default', title: 'Eindevaluatie ingediend',  actor: 'Imane El Amrani', when: '30 apr 2024' },
      ],
    },
  ],
};

/* human-readable stage names */
const STAGE_META = {
  interest:            { name: 'Interesse',            icon: 'sparkle' },
  waitlist:            { name: 'Wachtlijst',           icon: 'seat' },
  enrollment_personal: { name: 'Inschrijving — persoonsgegevens', icon: 'user' },
  enrollment_billing:  { name: 'Inschrijving — facturatie',       icon: 'receipt' },
  intake:              { name: 'Intake',               icon: 'fileText' },
  evaluation:          { name: 'Eindevaluatie',        icon: 'award' },
  initial_selection:   { name: 'Initiële keuze',       icon: 'route' },
};

/* ---- expose ---- */
window.WS = {
  icon, ICONS,
  REG_STATUS, OFFERTE_STATUS, SMART_ACTIONS, actionsForState, actionsForStates,
  avatarColor, initials,
  EDITIONS, COMPANIES, REGISTRATIONS, QUEUES, QUEUE_FILTER, ACTION_QUEUE, STATS,
  DOSSIER, STAGE_META,
};
})();
