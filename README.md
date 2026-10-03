# OTP Verification & SMS Notifications for Gravity Forms

[![License: GPL v2 or later](https://img.shields.io/badge/License-GPL%20v2%20or%20later-blue.svg)](LICENSE)

Firebase phone OTP verification (checked on the server) plus SMS (MSG91, Twilio) and Slack notifications for [Gravity Forms](https://www.gravityforms.com/). Built on the Gravity Forms Add-On Framework.

- **Author:** [Cindova Technologies](https://www.cindova.com)
- **Requires:** WordPress 6.5+, PHP 7.4+, Gravity Forms 2.5+ (tested with 3.1)
- **License:** GPLv2 or later

> Gravity Forms is a separate commercial plugin that is not included here. This project is not affiliated with or endorsed by Rocketgenius (Gravity Forms), Google or Firebase.

## Features

- Phone verification with Firebase Authentication. The visitor gets an SMS code; the server verifies the resulting Firebase ID token during form validation, so it cannot be bypassed from the browser. Tokens are single-use and tied to the submitted phone number.
- SMS notifications through MSG91 (Flow API v5 or legacy API) or Twilio.
- Slack notifications to public or private channels.
- Notifications are feeds: any number per form, each with its own message and conditional logic. Gravity Forms merge tags and `{{field_ID}}` placeholders work in messages.
- "Send test" button on the feed screen.
- Optional Firebase App Check (off by default).
- Works with AJAX forms, multi-page forms and several OTP forms on one page.
- Firebase and reCAPTCHA are contacted only after the visitor clicks "Send code". The Firebase SDK is bundled, not loaded from a CDN.
- Settings migrate automatically from the earlier "Gravity Forms OTP and SMS Notifications" 2.x plugin.

## Installation

1. Install and activate Gravity Forms.
2. Download the release zip (or build it, see below) and install it under Plugins > Add New > Upload.
3. Go to Forms > Settings > Phone OTP and paste your Firebase web config JSON. Phone authentication in Firebase needs the Blaze plan, the Phone provider enabled, your domain in Authorized domains, and an SMS region policy.
4. Go to Forms > Settings > SMS & Slack for provider credentials.
5. Per form: Settings > Phone OTP (choose the phone and OTP fields) and Settings > SMS & Slack (add notification feeds).

Full setup instructions, FAQ and the list of external services are in [readme.txt](readme.txt).

## Project layout

| Path | Purpose |
|------|---------|
| `otp-verification-sms-for-gravity-forms.php` | Plugin header and bootstrap |
| `includes/class-cindova-gfotp-addon.php` | `GFAddOn` "Phone OTP": Firebase/App Check settings, per-form OTP tab, OTP hooks |
| `includes/class-cindova-gfotp-notifications-addon.php` | `GFFeedAddOn` "SMS & Slack": provider settings, feeds, "Send test" |
| `includes/otp.php`, `includes/token-verifier.php` | OTP validation and Firebase ID token verification |
| `includes/providers/` | MSG91, Twilio and Slack senders |
| `includes/migration.php` | Import from 2.x settings |
| `assets/js/`, `assets/css/` | Front-end and feed-screen scripts, styles |
| `assets/vendor/firebase/` | Bundled Firebase JS SDK 12.19.0 (compat builds), Apache-2.0, see its README |
| `readme.txt` | WordPress.org readme |
| `uninstall.php` | Data clean-up |

## Build the release zip

    bin/build.sh

Creates `dist/otp-verification-sms-for-gravity-forms.zip` with the top folder `otp-verification-sms-for-gravity-forms/`, leaving out the files listed in `.distignore`.

## Releasing to WordPress.org

- [ ] Version matches in the plugin header, `CINDOVA_GFOTP_VERSION` and `Stable tag` in `readme.txt`
- [ ] `Contributors:` in `readme.txt` is a real WordPress.org username
- [ ] Run [Plugin Check](https://wordpress.org/plugins/plugin-check/) and fix all errors
- [ ] `bin/build.sh`, then test-install the zip
- [ ] Banner, icon and screenshots go in the WordPress.org SVN `/assets` folder (not in the zip)
- [ ] Tag the release in SVN `/tags`

## License

GPL-2.0-or-later. See [LICENSE](LICENSE). The bundled Firebase SDK is Apache-2.0 (see `assets/vendor/firebase/LICENSE`).
