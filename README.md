# Certificate Photo Verifier For LearnDash

Requires a photo to view LearnDash certificates and adds a scannable QR code for verification. Photo management is provided by a Gutenberg block. Supports Gravatar fallback, photo change logging, and snapshot-based verification pages.

## Requirements

- WordPress 6.0+
- LearnDash 4.x+
- PHP 7.4+

## Setup

1. Activate the plugin.
2. Create a WordPress page and add the **Certificate Photo Upload** block (`cp-ld/photo-upload`). This is where learners are redirected when they need a photo.
3. In your LearnDash certificate template, add the shortcode below to control where the photo and QR code appear on the PDF.

## Shortcode

Add this shortcode to your LearnDash certificate template. It places both the learner's photo and a QR verification code on the certificate PDF.

```
[cp_ld_certificate photo_x="10" photo_y="170" photo_size="24" qr_x="240" qr_y="155" qr_size="36"]
```

| Attribute    | Description                                          | Default             |
| ------------ | ---------------------------------------------------- | ------------------- |
| `photo_x`    | Photo horizontal position in mm from the left edge   | Bottom-left corner  |
| `photo_y`    | Photo vertical position in mm from the top edge      | Bottom-left corner  |
| `photo_size` | Width and height of the photo in mm                  | `24`                |
| `qr_x`       | QR horizontal position in mm from the left edge      | Bottom-right corner |
| `qr_y`       | QR vertical position in mm from the top edge         | Bottom-right corner |
| `qr_size`    | Width and height of the QR code in mm                | `32`                |
| `label`      | Show "Scan to verify" text below the QR (`1` or `0`) | `1`                 |

If no attributes are provided, the photo and QR code are automatically positioned in opposite bottom corners with a 10 mm margin.

## Gutenberg Block

**Certificate Photo Upload** (`cp-ld/photo-upload`)

Add this block to any page. It renders a photo upload form for logged-in learners. The plugin automatically finds the published page containing this block and redirects learners there when they try to access a certificate without a photo on file.

If no page with the block is found, learners are sent to their WordPress profile page instead.

## How It Works

### Photo Requirement

A learner must have a photo on file before they can view any course, quiz, or group certificate. There is no toggle -- it is always enforced. Photos can come from two sources:

- **Gravatar** -- used automatically if the learner's email has one
- **Custom upload** -- uploaded via the **Certificate Photo Upload** Gutenberg block or the admin profile

Learners change their photo through the Gutenberg block placed on a page. If the learner has a Gravatar, a dropdown in the block lets them choose between Gravatar and a custom upload. Photos cannot be deleted outright -- they can only be replaced by a new upload or reverted to Gravatar (if available).

### Photo Snapshots

When a certificate PDF is generated, the learner's photo is copied to a snapshot directory (`wp-content/uploads/cp-ld-cert-photos/`). The QR verification page shows this snapshot rather than the learner's current photo, so the verification page always matches the PDF.

### Photo Change Log

Every photo change (upload, revert to Gravatar) is logged as user meta. The log is visible to admins on the user profile under **Certificate Photo** and records:

- Date and time
- Action (Uploaded, Reverted to Gravatar)
- Thumbnail of the previous and new photo
- Who made the change (self or admin)
- IP address

Photo snapshots for the log are stored in `wp-content/uploads/cp-ld-photo-log/` so thumbnails remain visible even after the original attachment is replaced.

### QR Verification

Each certificate PDF includes a QR code that links to a public verification page. When scanned, the page displays:

- The learner's snapshot photo (matching the PDF), first and last name, and email
- The course, quiz, or group title
- Completion details (date, progress, score, pass/fail, etc.)

The verification URL uses an HMAC-SHA256 signature derived from the WordPress auth salt. No tokens are stored in the database.

### PDF Rendering

The plugin hooks into LearnDash's TCPDF certificate generation to draw the learner photo and QR code directly onto the PDF at the positions specified by the shortcode attributes.

### Admin Profile Field

Admins can manage a learner's certificate photo from the user profile screen under the **Certificate Photo** section. If the learner has a Gravatar, a source dropdown allows switching between Gravatar and a custom upload. The photo change log is displayed below.

## Accepted Photo Formats

- JPEG, PNG, WebP
- Maximum file size: 5 MB

## File Structure

```
certificate-photo-verifier-ld/
  certificate-photo-verifier-ld.php   Main plugin file
  includes/
    class-admin.php                User profile photo field, change log, frontend notice
    class-certificate-gate.php     Photo gating, PDF injection, shortcode, snapshots
    class-photo-block.php          Gutenberg block registration
    class-photo-manager.php        Photo upload/storage/retrieval, AJAX, change log
    class-qrcode.php               Pure PHP QR code generator
    class-verification.php         Public verification page
  templates/
    upload-form.php                Frontend upload form template
    verify.php                     Verification page template
  assets/
    css/
      frontend.css                 Upload form styles
    js/
      frontend.js                  Vanilla JS upload/source handler
      photo-upload-editor.js       Block editor placeholder
```

# certificate-photo-verifier-ld

git add README.md
git commit -m "first commit"
git branch -M main
git remote add origin https://github.com/pfkellogg/certificate-photo-verifier-ld.git
