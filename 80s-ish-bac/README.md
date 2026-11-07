# BAC — 80s-ish band edition

## Files
- `index.html` — the calendar
- `api.php` — saves and loads data, logins, alerts
- `push.php` — sends phone alerts (no extra installs needed)
- `sms.php` — sends "Forgot PIN?" texts through ClickSend
- `config.php` — band name, members, colours, starting PINs, admin, secret
- `sw.js`, `manifest.json`, `icon-192.png`, `icon-512.png`, `apple-touch-icon.png` — let it work as a home-screen app with alerts
- `data/` — everything saved lives here (must be writable by the web server)

## Server needs
- **HTTPS** (alerts and home-screen install won't work without it)
- PHP 7.4 or newer, with the **openssl** and **curl** extensions (both are standard)

Check from the server's command line: `php -m | grep -E "openssl|curl"` should list both.

## Before uploading
1. In `config.php`, change `SECRET` to a long random string.
2. The PINs in `config.php` are **starting PINs**. Share them with the band. Everyone is made to choose their own PIN the first time they log in, and the starting PIN stops working for them after that.

## On the server
1. Upload the folder, e.g. to `/var/www/bac-band/`.
2. Make `data/` writable: `sudo chown -R www-data:www-data /var/www/bac-band/data`
3. Block public access to `data/`:
   - Apache: the included `data/.htaccess` does it.
   - Nginx: inside the server block add `location ^~ /bac-band/data/ { deny all; }`

## Alerts (phone notifications)
- Sent to every band member who has turned them on, the moment the fifth person marks a day as available. Once per date.
- **iPhone:** open the site in Safari → Share → **Add to Home Screen** → open 80s-ish from the home screen → log in → **Turn on alerts**. (iOS 16.4 or newer. Apple only allows alerts from home-screen apps.)
- **Android:** open in Chrome → log in → **Turn on alerts**. Adding to the home screen is optional.
- "Send me a test" in the Alerts box checks it works on that phone.
- The alert keys are created automatically in `data/vapid.json` on first use. Don't delete that file, or everyone has to turn alerts on again.

## Forgot PIN? (text message reset — no admin needed)
Everyone adds their mobile number when they choose their first PIN (anyone who already has a PIN is asked for it at their next login).
"Forgot PIN?" on the login screen: they tap their name, type their number, and get a text with a link to choose a new PIN. The link works once, for 15 minutes.

**One-time setup (ClickSend):**
1. Sign up at clicksend.com and top up the minimum ($20).
2. Dashboard → **API Credentials**: copy your username and API key.
3. In `config.php` fill in `CLICKSEND_USERNAME`, `CLICKSEND_API_KEY`, and `APP_URL` (the calendar's full web address ending in `/`, e.g. `https://mhath.com/bac-band/`).
4. `SMS_FROM` is the name texts come from (`80sish`, max 11 letters/numbers). If texts don't arrive, set it to `''` to use ClickSend's own number instead.

**Built-in safety:** the same reply whether or not the number matches (so numbers can't be guessed), max 3 texts per person per hour, max 20 texts a day in total, and the usual 6-attempts-per-10-minutes limit.

**Keeping the credit alive:** ClickSend credit expires after 12 months with no texts or top-ups. Once every ~11 months the calendar texts Math a one-line "yearly check-in" to keep it active (about 4p). This needs Math's number to be saved and the calendar to be opened by someone at least once in that time.

Each text costs about 4p.

## How it works
- Tap a day → **Mark me available**. Tap again to take it back.
- **Quick mark** lets you tap days on and off without opening them.
- Each day is a triangle split into six: one slice per person (faded until they're available) plus the date.
- When all five are available, the day's colours spin and it appears under "Everyone Free".
- Past days fade out unless something was booked on them.
- Admin (Math) can add Rehearsals, Gigs and Other entries, and delete them.
- **Just looking** (no PIN) shows gigs only. The hidden viewer (`VIEWERS` in `config.php`) sees everything but can't change anything.

Members must stay at exactly five, in the order given in `config.php` (the order sets each person's slice).
