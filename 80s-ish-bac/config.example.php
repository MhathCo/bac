<?php
// ===== BAC — 80s-ish band edition: settings =====
// Change SECRET to a long random string before going live.
// Changing it later logs everyone out.

const BAND_NAME = '80s-ish';
const SECRET    = 'CHANGE-ME-to-a-long-random-string-0f9a8b7c6d5e';
const ADMIN     = 'Math';

const DATA_FILE     = __DIR__ . '/data/band.json';
const THROTTLE_FILE = __DIR__ . '/data/throttle.json';
const PINS_FILE     = __DIR__ . '/data/pins.json';    // members' own PINs (hashed)
const SUBS_FILE     = __DIR__ . '/data/subs.json';    // phones signed up for notifications
const VAPID_FILE    = __DIR__ . '/data/vapid.json';   // notification keys, made automatically

// Who notifications say they're from. A web address is fine.
const VAPID_SUBJECT = 'https://mhath.com';

// ---- Text messages for "Forgot PIN?" (ClickSend) ----
// APP_URL: the full web address of the calendar, ending in /  e.g. 'https://mhath.com/bac-band/'
// ClickSend username + API key: ClickSend dashboard → API Credentials.
const APP_URL            = '';
const CLICKSEND_USERNAME = '';
const CLICKSEND_API_KEY  = '';
const SMS_FROM           = '80sish';   // name texts come from: max 11 letters/numbers, no spaces
const PHONES_FILE = __DIR__ . '/data/phones.json';   // members' mobile numbers
const RESETS_FILE = __DIR__ . '/data/resets.json';   // one-time reset links
const SMS_LOG     = __DIR__ . '/data/sms.json';      // for limits + yearly keep-alive

// Each member logs in with their name + PIN.
// These are DEFAULT PINs: everyone must choose their own PIN the first time they log in.
// Order matters: it sets each person's slice in the triangles. Exactly five members.
const MEMBERS = [
    ['name' => 'Math',  'color' => '#a6ff3b', 'pin' => '1111'],
    ['name' => 'Sarah', 'color' => '#ff8a1f', 'pin' => '2222'],
    ['name' => 'Bobs',  'color' => '#ff2e88', 'pin' => '3333'],
    ['name' => 'Simon', 'color' => '#fff35c', 'pin' => '4444'],
    ['name' => 'Mike',  'color' => '#8fd3ff', 'pin' => '5555'],
];

// Hidden view-only users. They never appear in the band list.
// To log in: don't tap a name, just type the PIN and press Start.
const VIEWERS = [
    ['name' => 'Guest', 'pin' => '6666'],
];
