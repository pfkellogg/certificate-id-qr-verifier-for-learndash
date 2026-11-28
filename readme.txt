=== AIKellogg Certificate ID & QR Verifier for LearnDash ===
Contributors: pfkellogg
Tags: learndash, certificate, qr code, verification, lms
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.2.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Puts a unique ID and a scannable QR code on LearnDash certificates. Anyone can scan it to see that the certificate is genuine.

== Description ==

**The original.** Patrick Kellogg created the first certificate tracker and verifier for LearnDash. He founded eLearning Complete, where he built it (he is no longer affiliated with eLearning Complete), and this free plugin is its successor.

A certificate is only worth something if people can trust it. This plugin adds two things to every LearnDash certificate:

* **A QR code.** Scan it with any phone camera and it opens a public page on your site that says **Certificate Verified**, with the learner's name, the course (or quiz, or group), and when it was completed.
* **A unique certificate ID**, such as `FSB-1795464733-6-1`, printed on the certificate and shown on the verification page, so the two can be compared.

The link in the QR code is signed with your site's secret keys, so it can't be forged: change one character, or point it at another learner, and the page says "Certificate could not be verified". If the learner's course data is deleted, the certificate no longer verifies. Nothing extra is stored in the database.

The QR code is made by the plugin itself, on your server. It doesn't use an outside QR service.

= Where things go =

With no setup, every certificate gets the QR code in the bottom-right corner, labelled "Scan to verify". To move it, or add the ID, put these shortcodes in your LearnDash certificate (positions are in millimetres from the top-left corner of the page):

* `[cp_ld_certificate qr_x="240" qr_y="155" qr_size="36" show_url="1"]` — where the QR code goes, its size, and (with `show_url="1"`) the verification link printed under it.
* `[cp_ld_certificate_id prefix="FSB" size="5"]` — the certificate ID, with your own prefix (CERT if you leave it out). The verification page shows the same ID.

= Approval numbers by date (state boards, CE credits) =

Courses approved by a state board or an accrediting body often get a new approval number every year. A certificate must show the number that was valid when the learner completed it, so learners who finished last year keep last year's number.

On each course (or quiz, or group) fill in **Approval numbers by date**: one row per period, with From, To and the number. Then put `[cp_ld_approval_number before="Approval #: "]` in the certificate. The verification page shows the number as well. When a new number arrives, add a row. Certificates already issued keep theirs. If no period covers the completion date, nothing is printed (or `default="..."`).

= Expiration dates =

`[cp_ld_certificate_expires years="1"]` (or `days="365"`, and `format="d-m-Y"`) prints when the certificate expires, counted from the completion date.

= Valid, expired or not active: a low-maintenance status =

`[cp_ld_certificate_status start="..." end="..."]` in a certificate prints one of three texts, depending on when the learner completed:

* completed before `start`: **Expired** (or your text in `expired="..."`)
* from `start` up to (not including) `end`: **Valid** (or `valid="..."`)
* at `end` or later: **Not active** (or `not_active="..."`)

Once `end` has passed, a certificate completed in the period says **Expired** when it's viewed, and its verification page says **Certificate Expired** ("A genuine certificate, valid until ..."). While the period runs, the verification page says **Certificate Verified** with "Valid until ...". The verification page takes the dates from this shortcode in the course's certificate template, so you only change them in one place.

`start` and `end` take a Unix timestamp (`1767225600`) or a date and time (`2026-12-31 23:59:59`), read in your site's time zone (Settings → General). When a period ends, only the two dates change. Leave out `end` for "no end yet". Add `step="123"` to use the completion of lesson or topic 123 instead of the course's.

Example: `[cp_ld_certificate_status start="2026-01-01" end="2027-01-01" expired="Expired" valid="Valid through Dec 31, 2026" not_active="Not yet active"]`

= A verification page with a search box =

Put `[cp_ld_certificate_search]` on a page: visitors type a certificate ID and go to its verification page.

= Replacing eLearning Complete's Certificate Tracker and Certificate Verifier =

This plugin replaces the older pair **Certificate Tracker for LearnDash** and **Certificate Verifier for LearnDash** (eLearning Complete). Switch them off and everything they made keeps working:

* Every certificate ID they issued still verifies. It is read from the learner's saved record (the `elc_certificate_ids` user meta), with its original completion date, even if the course was reset and taken again later.
* `[elc_certificate_id prefix="..."]` in your certificate templates prints the same IDs as before.
* QR codes printed with `[su_qrcode]` (Shortcodes Ultimate) still carry the certificate ID, and the old codes on certificates already issued still work: your page with `[elc_ssc_front_search]` becomes the search box and sends them to the verification page.
* Where a template has that old QR code, this plugin doesn't add a second one.

The saved record is never deleted, not even when this plugin is deleted.

= A free certificate background =

The plugin's GitHub page has a landscape certificate background (US Letter, navy and gold, with a seal) that you can use as the certificate's featured image: https://github.com/pfkellogg/certificate-id-qr-verifier-for-learndash

= Optional: a learner photo =

Under **Settings → Certificate Verifier** you can also require a photo of the learner: certificates then show it, the verification page shows it too, and learners without a photo are asked to upload one (with the **Certificate Photo Upload** block) before they can download a certificate. The photo is frozen on each certificate when it's first issued, and photo changes are logged. It's off unless you switch it on.

= Privacy =

The verification page is public, by design: anyone who sees a certificate can scan it. It shows the learner's name, the course and completion details, and (if photos are switched on) their photo. It does not show their email address. Mention certificate verification in your site's privacy policy.

When the photo is switched on and a learner hasn't uploaded one, their Gravatar is used if they have one: the plugin then loads it from Gravatar (gravatar.com, by Automattic; see https://automattic.com/privacy/). With the photo off (the default) the plugin contacts no outside service.

LearnDash is a trademark of its owner. This plugin is independent: it is not made or endorsed by LearnDash, nor by eLearning Complete.

== Installation ==

1. Install and activate **LearnDash**, and set up a certificate for a course, quiz or group.
2. Install **AIKellogg Certificate ID & QR Verifier for LearnDash** from **Plugins → Add New**, or upload the zip, and activate it.
3. That's all: certificates now carry a QR code. Optionally add the shortcodes above to place the code and print the ID.

== Frequently Asked Questions ==

= Does it work with LearnDash's Certificate Builder add-on? =

It works with LearnDash's built-in certificates (the PDF certificates you edit as a certificate post). Support for the Certificate Builder add-on is planned.

= What does the verification page show? =

The site name, the learner's name, the certificate ID, "Certificate Verified", the course (or quiz or group) and when it was completed. With photos on, the learner's photo too.

= Are the approval numbers removed when the plugin is deleted? =

No. They belong to your courses, and they're the record of what each certificate was approved under. They stay in case you reinstall.

= Can someone fake a verification link? =

No: the link carries a signature made with your site's secret keys. Any change to it fails verification.

= Can learners see other learners' certificates? =

No. Only administrators, and group leaders for their own groups' learners, can look at another learner's certificate.

= I used eLearning Complete's Certificate Tracker and Verifier =

Deactivate them and keep your templates and verification page as they are: see "Replacing eLearning Complete's Certificate Tracker and Certificate Verifier" above.

= I use the FastDash / SlashDash Toolkit's Certificate Verifier module =

Use one or the other: with both active, certificates would get two QR codes. The plugin warns you on the admin screens.

== Screenshots ==

1. A landscape certificate with its ID, approval number, status and QR code (the sample background from the plugin's GitHub page).
2. The verification page after scanning: Certificate Verified, valid until the end of its period.
3. The same certificate after its period ended: Certificate Expired, and still marked as genuine.
4. A forged or changed link: Certificate could not be verified.
5. The search box, [cp_ld_certificate_search]: type an ID to check it.
6. Approval numbers by date, on the course edit screen.

== Changelog ==

= 1.2.1 =
* New name for the WordPress.org directory: AIKellogg Certificate ID & QR Verifier for LearnDash (text domain aikellogg-certificate-id-qr-verifier-for-learndash). Nothing else changes: your settings, certificate IDs and photos stay as they are.

= 1.2.0 =
* New name for the WordPress.org directory: Certificate ID & QR Verifier for LearnDash (was Certificate Photo Verifier For LearnDash).
* Fixed: the QR codes could not be scanned (several errors in the code generator). Every link length now scans.
* The certificate ID is the same on the certificate and on the verification page, with your prefix.
* The learner photo is optional, and off by default; no Gravatar is loaded while it's off.
* The verification page no longer shows the learner's email address.
* Only administrators and group leaders can view another learner's certificate.
* The verification link under the QR code fits on the page.
* All text can be translated; settings are removed when the plugin is deleted.
* Replaces eLearning Complete's Certificate Tracker and Certificate Verifier for LearnDash: their saved IDs, shortcodes and QR codes keep working.
* New: `[cp_ld_certificate_search]`, a search box for certificate IDs.
* New: approval numbers by date on courses, quizzes and groups, with `[cp_ld_approval_number]`, also shown on the verification page.
* New: `[cp_ld_certificate_expires]`.
* New: `[cp_ld_certificate_status start end]`: Expired, Valid or Not active (or your own texts), from the completion time of the course, quiz, group, lesson or topic.

= 1.1.0 =
* Certificate ID on the verification page; show_url option.

= 1.0.0 =
* First version.

== Upgrade Notice ==

= 1.2.1 =
A new name for the WordPress.org directory; nothing else changes.

= 1.2.0 =
Fixes QR codes that could not be scanned. The learner photo is now optional (off by default).
