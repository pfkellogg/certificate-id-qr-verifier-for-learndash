=== Certificate ID & QR Verifier for LearnDash ===
Contributors: pfkellogg
Tags: learndash, certificate, qr code, verification, lms
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Puts a unique ID and a scannable QR code on LearnDash certificates. Anyone can scan it to see that the certificate is genuine.

== Description ==

A certificate is only worth something if people can trust it. This plugin adds two things to every LearnDash certificate:

* **A QR code.** Scan it with any phone camera and it opens a public page on your site that says **Certificate Verified**, with the learner's name, the course (or quiz, or group), and when it was completed.
* **A unique certificate ID**, such as `FSB-1795464733-6-1`, printed on the certificate and shown on the verification page, so the two can be compared.

The link in the QR code is signed with your site's secret keys, so it can't be forged: change one character, or point it at another learner, and the page says "Certificate could not be verified". If the learner's course data is deleted, the certificate no longer verifies. Nothing extra is stored in the database.

The QR code is made by the plugin itself, on your server. It doesn't use an outside QR service.

= Where things go =

With no setup, every certificate gets the QR code in the bottom-right corner, labelled "Scan to verify". To move it, or add the ID, put these shortcodes in your LearnDash certificate (positions are in millimetres from the top-left corner of the page):

* `[cp_ld_certificate qr_x="240" qr_y="155" qr_size="36" show_url="1"]` — where the QR code goes, its size, and (with `show_url="1"`) the verification link printed under it.
* `[cp_ld_certificate_id prefix="FSB" size="5"]` — the certificate ID, with your own prefix (CERT if you leave it out). The verification page shows the same ID.

= Optional: a learner photo =

Under **Settings → Certificate Verifier** you can also require a photo of the learner: certificates then show it, the verification page shows it too, and learners without a photo are asked to upload one (with the **Certificate Photo Upload** block) before they can download a certificate. The photo is frozen on each certificate when it's first issued, and photo changes are logged. It's off unless you switch it on.

= Privacy =

The verification page is public, by design: anyone who sees a certificate can scan it. It shows the learner's name, the course and completion details, and (if photos are switched on) their photo. It does not show their email address. Mention certificate verification in your site's privacy policy.

When the photo is switched on and a learner hasn't uploaded one, their Gravatar is used if they have one: the plugin then loads it from Gravatar (gravatar.com, by Automattic; see https://automattic.com/privacy/). With the photo off (the default) the plugin contacts no outside service.

LearnDash is a trademark of its owner. This plugin is independent: it is not made or endorsed by LearnDash.

== Installation ==

1. Install and activate **LearnDash**, and set up a certificate for a course, quiz or group.
2. Install **Certificate ID & QR Verifier for LearnDash** from **Plugins → Add New**, or upload the zip, and activate it.
3. That's all: certificates now carry a QR code. Optionally add the shortcodes above to place the code and print the ID.

== Frequently Asked Questions ==

= Does it work with LearnDash's Certificate Builder add-on? =

It works with LearnDash's built-in certificates (the PDF certificates you edit as a certificate post). Support for the Certificate Builder add-on is planned.

= What does the verification page show? =

The site name, the learner's name, the certificate ID, "Certificate Verified", the course (or quiz or group) and when it was completed. With photos on, the learner's photo too.

= Can someone fake a verification link? =

No: the link carries a signature made with your site's secret keys. Any change to it fails verification.

= Can learners see other learners' certificates? =

No. Only administrators, and group leaders for their own groups' learners, can look at another learner's certificate.

= I use the FastDash / SlashDash Toolkit's Certificate Verifier module =

Use one or the other: with both active, certificates would get two QR codes. The plugin warns you on the admin screens.

== Screenshots ==

1. A certificate with its ID and QR code.
2. The verification page after scanning: Certificate Verified.
3. A forged or changed link: Certificate could not be verified.

== Changelog ==

= 1.2.0 =
* New name for the WordPress.org directory: Certificate ID & QR Verifier for LearnDash (was Certificate Photo Verifier For LearnDash).
* Fixed: the QR codes could not be scanned (several errors in the code generator). Every link length now scans.
* The certificate ID is the same on the certificate and on the verification page, with your prefix.
* The learner photo is optional, and off by default; no Gravatar is loaded while it's off.
* The verification page no longer shows the learner's email address.
* Only administrators and group leaders can view another learner's certificate.
* The verification link under the QR code fits on the page.
* All text can be translated; settings are removed when the plugin is deleted.

= 1.1.0 =
* Certificate ID on the verification page; show_url option.

= 1.0.0 =
* First version.

== Upgrade Notice ==

= 1.2.0 =
Fixes QR codes that could not be scanned. The learner photo is now optional (off by default).
