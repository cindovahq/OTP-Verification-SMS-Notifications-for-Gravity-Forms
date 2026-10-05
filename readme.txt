=== Cindova Phone OTP & SMS for Gravity Forms ===
Contributors: cindova
Tags: gravity forms, otp, sms, phone verification, firebase
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Verify phone numbers with a Firebase one-time code in Gravity Forms, and send SMS and Slack notifications after submission.

== Description ==

Confirm that visitors own the phone number they enter, and send SMS or Slack notifications when a form is submitted.

**Requires Gravity Forms 2.5 or later** (tested with Gravity Forms 3.1). Gravity Forms is a separate commercial plugin that is not available on WordPress.org and is not included with this plugin. You must install and license it yourself.

This plugin is not affiliated with, endorsed by, or sponsored by Rocketgenius (Gravity Forms), Google, or Firebase.

= Features =

* Phone OTP verification with Firebase Authentication. The visitor receives an SMS code, and your server verifies the resulting Firebase ID token during form validation, so the check cannot be bypassed from the browser.
* SMS notifications after submission through MSG91 (Flow API v5 or the legacy API) or Twilio, with `{{field_X}}` placeholders and Gravity Forms merge tags in the message.
* Optional Firebase App Check (off by default) to help stop SMS abuse.
* Slack notifications to a public or private channel.
* Built on the Gravity Forms Add-On Framework: settings live under Forms > Settings (Phone OTP, SMS & Slack) and per form under the form's Settings tab, like other Gravity Forms add-ons.
* Notification feeds: add as many SMS and Slack notifications per form as you need, each with its own message and its own conditional logic.
* Send a test SMS or Slack message from the feed screen, using the values you have typed (no need to save first).
* Entry notes record each notification, and failures are logged to the entry and to the Gravity Forms logs.
* Works with AJAX and multi-page forms, and with several OTP forms on one page.
* Translation ready.

== Installation ==

1. Make sure Gravity Forms 2.5 or later is installed and active.
2. Upload the plugin folder to `/wp-content/plugins/`, or install it from the Plugins screen, then activate it.
3. Set up Firebase:
   1. Create a project in the Firebase console.
   2. Upgrade the project to the Blaze (pay as you go) plan. Phone authentication SMS requires it.
   3. Under Authentication, enable the Phone sign-in provider.
   4. Under Authentication > Settings > Authorized domains, add your site's domain.
   5. Configure the SMS region policy to allow the countries you need.
   6. In Project settings, register a web app and copy its web config JSON.
4. Go to Forms > Settings > Phone OTP and paste the Firebase web config JSON.
5. For notifications, go to Forms > Settings > SMS & Slack and choose the SMS provider (MSG91 or Twilio), enter its credentials, and optionally the Slack bot token and default channel.
6. Edit your form and add a Phone field (set its Phone Format to "International") and a Single Line Text field for the one-time code.
7. Open the form's Settings > Phone OTP tab, turn on "Enable phone verification" and select the phone field and the OTP code field.
8. To send notifications, open the form's Settings > SMS & Slack tab and click Add New. Choose SMS or Slack, write the message, and optionally set conditional logic so the notification is sent only when the entry matches. Add one feed per notification.

Visitors must enter phone numbers in international format, for example +14155552671.

== Frequently Asked Questions ==

= Does it work without Gravity Forms? =

No. Gravity Forms 2.5 or later is required.

= Is Firebase free? =

Firebase Authentication has a free tier, but sending SMS for phone sign-in requires the Blaze (pay as you go) plan. Check Google's current pricing.

= Can users bypass the OTP? =

Not from the browser. The server verifies the Firebase ID token during Gravity Forms validation, including on the final page of multi-page forms. If the token is missing, invalid, expired, already used or for a different phone number, the form shows a field error and is not submitted. The check is enforced even if the OTP field is hidden by conditional logic.

= Does it work with multi-page and AJAX forms? =

Yes.

= Why can't visitors type a + in the phone field? =

The Gravity Forms Phone field's "Standard" format only accepts US numbers in (###) ###-#### form. Change the field's Phone Format to "International" so visitors can enter numbers such as +14155552671.

= Can I use conditional logic on the OTP field? =

Yes. The OTP field itself can be hidden or shown by conditional logic without weakening verification: the server always requires a valid token whenever the phone field is visible. If the phone field is hidden by conditional logic (including by a hidden page or section), no phone number is collected, so verification is skipped.

= Where are my API keys and tokens stored? =

Gravity Forms stores plugin settings in the WordPress options table as plain text. To encrypt them at rest, define the `GF_ENCRYPTION_KEY` constant in `wp-config.php` (see the Gravity Forms documentation); Gravity Forms then encrypts the stored settings. The settings screens never print a saved secret: a saved Auth Key or token shows only a "A value is saved" note, leaving the field blank keeps it, and the "Remove the saved value" checkbox clears it.

= Can I send different messages or use conditional logic per notification? =

Yes. Each SMS or Slack notification is a feed, and each feed has its own message and its own conditional logic ("Send this notification if ..."). Feeds are skipped for spam entries. Failed notifications are written to the entry notes and, if you turn on Gravity Forms logging (Forms > Settings > Logging), to the Gravity Forms log for this plugin.

= Which placeholders can I use in messages? =

`{{field_ID}}` and `{{field_ID.SUBID}}`, where ID is the Gravity Forms field ID and SUBID is the input ID for multi-input fields such as Name. You can also use Gravity Forms merge tags such as `{Name:1}`, `{Phone:3}`, `{entry_id}` or `{all_fields}`. Merge tags are not replaced in the test message, because a test has no entry; they are sent as written.

= How do I reduce SMS fraud? =

Three things help: (1) set the SMS region policy in the Firebase console (Authentication → Settings → SMS region policy) to allow only the countries you serve; (2) optionally enable Firebase App Check by entering a reCAPTCHA site key under App Check on the Forms > Settings > Phone OTP page (register the key in the Firebase console first, and test with enforcement in monitor mode, because a wrong key with enforcement on rejects every OTP request); (3) keep the Firebase SMS quotas and billing budget alerts at low values.

= I used the earlier "Gravity Forms OTP and SMS Notifications" plugin by Cindova. Can I switch? =

Yes. Install and activate this plugin, then deactivate and delete the earlier one. Your Firebase, SMS and Slack settings, the phone and OTP fields of each form, and your SMS and Slack messages are copied automatically into Forms > Settings > Phone OTP, Forms > Settings > SMS & Slack and into notification feeds. The earlier plugin's data is left untouched, so you can switch back if you need to.

= Which Slack permissions are needed? =

Create a Slack app with a bot token that has chat:write, channels:read and groups:read (for private channels), and invite the bot to the channel.

== External services ==

This plugin connects to the third-party services below. Each one is used only after you configure it under Forms > Settings and in your forms (you need your own account with that service). Nothing is sent to the plugin author.

= Google Firebase Authentication =

* Service: https://firebase.google.com/products/auth
* What it is used for: sending the one-time SMS code to the visitor and confirming it.
* When and what data is sent: only on forms where you set a Phone field and OTP field, and only after the visitor clicks "Send code". The Firebase SDK is bundled with the plugin and served from your own site; it does not contact Google until that click. It then sends the phone number to Google (identitytoolkit.googleapis.com and securetoken.googleapis.com, plus your Firebase project's auth domain, e.g. your-project.firebaseapp.com). When the visitor clicks "Verify code", the code they typed is sent to check it. Google sends the SMS and creates a phone-number user in your Firebase project.
* Terms of service: https://firebase.google.com/terms
* Privacy policy: https://policies.google.com/privacy

= Google reCAPTCHA =

* Service: https://www.google.com/recaptcha/about/
* What it is used for: Firebase Authentication requires an invisible reCAPTCHA check to prevent abuse of SMS sending.
* When and what data is sent: loaded in the visitor's browser by Firebase when they click "Send code". Google receives the browser and interaction data it uses for reCAPTCHA.
* Terms of service: https://policies.google.com/terms
* Privacy policy: https://policies.google.com/privacy

= Google public key endpoint (Firebase token verification) =

* Service: https://firebase.google.com/docs/auth/admin/verify-id-tokens
* What it is used for: your server downloads Google's public certificates from https://www.googleapis.com/robot/v1/metadata/x509/securetoken@system.gserviceaccount.com to check that a submitted verification token is genuine.
* When and what data is sent: when an OTP-enabled form is submitted and the cached certificates have expired. No personal data is sent; it is a plain download.
* Terms of service: https://policies.google.com/terms
* Privacy policy: https://policies.google.com/privacy

= Google reCAPTCHA v3 / Enterprise for Firebase App Check (optional) =

* Service: https://firebase.google.com/docs/app-check
* What it is used for: proving to Firebase that OTP requests come from your genuine website, to help prevent SMS abuse.
* When and what data is sent: only if you enter an App Check site key on the settings page. The App Check script is bundled and loaded in the visitor's browser when they click "Send code"; it then loads Google reCAPTCHA and sends the browser and interaction data Google uses for reCAPTCHA, and App Check tokens, to Google. Nothing is sent if the site key is blank.
* Terms of service: https://policies.google.com/terms
* Privacy policy: https://policies.google.com/privacy

= MSG91 =

* Service: https://msg91.com/
* What it is used for: sending SMS notifications.
* When and what data is sent: only if MSG91 is the selected SMS provider and a form has an SMS notification feed. Your server sends your MSG91 auth key, the recipient phone number and the Flow template ID with its variable values (Flow API v5), or your auth key, sender ID, the recipient phone number and the message text (legacy API), including any submitted field values you placed in them, to control.msg91.com after the form is submitted, or when you click "Send test" on a feed screen.
* Terms of service: https://msg91.com/terms-of-use
* Privacy policy: https://msg91.com/privacy-policy

= Twilio =

* Service: https://www.twilio.com/
* What it is used for: sending SMS notifications.
* When and what data is sent: only if Twilio is the selected SMS provider and a form has an SMS notification feed. Your server sends your Twilio account SID and auth token, your Twilio number, the recipient phone number and the message text (including any submitted field values you placed in it) to api.twilio.com after the form is submitted, or when you click "Send test" on a feed screen.
* Terms of service: https://www.twilio.com/en-us/legal/tos
* Privacy policy: https://www.twilio.com/en-us/legal/privacy

= Slack =

* Service: https://slack.com/
* What it is used for: posting a notification to a Slack channel.
* When and what data is sent: when you save a new bot token (or ask for a refresh), your server asks slack.com for the list of channels. For each Slack notification feed, your server sends the configured message (including any submitted field values you placed in it) to the chosen channel after the form is submitted, or when you click "Send test" on the feed screen.
* Terms of service: https://slack.com/terms-of-service
* Privacy policy: https://slack.com/trust/privacy/privacy-policy

= Third-party libraries =

This plugin bundles the Firebase JS SDK 12.19.0 compat builds (app, auth and app-check) (`assets/vendor/firebase/`), licensed under Apache-2.0 (protobuf portions BSD-3-Clause); the license text is included in that folder. The files are the official minified builds. The human-readable source is at https://github.com/firebase/firebase-js-sdk (tag `firebase@12.19.0`) and on npm at https://www.npmjs.com/package/firebase .

== Screenshots ==

1. Forms > Settings > Phone OTP (Firebase and App Check).
2. Forms > Settings > SMS & Slack (provider and Slack settings).
3. A notification feed with conditional logic and the Send test button.
4. OTP verification on the front end.

== Changelog ==

= 1.0.0 =
* Initial release.
* Phone number verification with a Firebase one-time code, verified on the server.
* SMS notifications through MSG91 (Flow API v5 or legacy API) or Twilio.
* Slack notifications to public and private channels.
* Notification feeds per form, each with its own message and conditional logic.
* Gravity Forms merge tags and {{field_ID}} placeholders in messages.
* Send a test SMS or Slack message from the feed screen.
* Optional Firebase App Check.
* Supports AJAX forms, multi-page forms and several OTP forms on one page.
* Suggested privacy policy text and clean-up of plugin data on uninstall.
