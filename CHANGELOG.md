# Changelog

All notable changes to this app are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
The Nextcloud App Store reads the section matching each published version, so
every release must add one here.

## 0.2.1 - 2026-08-03

### Changed

- Support for Nextcloud 31. The declared range is now `>=27 <=31`, verified on a
  real 31.0.14 instance: migrations, the three `signdocs:*` system tags, both
  background jobs, and both Files actions (`signdocs-sign`, `signdocs-status`)
  register with no page errors.
- The integration workflow now smoke-tests Nextcloud 27 through 31, so the
  declared range stays backed by a run on every supported major.

### Added

- This changelog. Earlier releases shipped without one, which is why they appear
  on the App Store with no description of what changed.

## 0.2.0 - 2026-08-03

### Added

- **Review before sending.** The signing dialog shows a confirmation step listing
  every signer, their CPF/CNPJ, the signature mode (PAdES/CAdES) and the signing
  order, so invites are never dispatched on a mistyped recipient.
- **Cancellation from the GUI.** Signature requests can be cancelled inside
  Nextcloud, including multi-signer envelopes. Failures are reported inline on
  the row with a retry.
- **Signature status where the documents are.** A *Status da assinatura* entry in
  the Files context menu, plus a scrollable request list on the app page with
  back navigation to the four main actions.
- **Save-back for non-PDF documents.** Signing a DOCX (or any non-PDF) saves the
  detached ICP-Brasil signature alongside the document as
  `<name>-assinado.<ext>` + `.p7s`, verifiable with `openssl smime -verify`.

### Changed

- Sequential signing order and non-PDF documents are bound to ICP-Brasil digital
  certificate signing, enforced in the UI and mirrored server-side by sniffing
  file content rather than trusting the extension.

### Fixed

- A file no longer accumulates several status tags at once. A repair step
  corrects files already carrying duplicates, applying
  `pendente > assinado > cancelado` precedence.
- A 404 while polling now marks the session expired and stops, instead of
  retrying forever. A single aged-out session previously produced roughly 288
  wasted requests a day, permanently.

## 0.1.2 - 2026-07-24

### Fixed

- **Autoloader (critical).** The app bundles its Composer autoloader at
  `composer/autoload.php` so Nextcloud loads the SignDocs SDK. 0.1.0 and 0.1.1
  shipped `vendor/` and the SDK never loaded on real installs.
- Webhook correlation for transaction and envelope events
  (`ALL_SIGNED`/`CANCELLED`/`EXPIRED`); previously every completion was rejected
  with HTTP 400.
- The polling job reconciles envelope status via `envelopes->get`, since
  `getStatus` rejects envelope ids.

### Added

- Signed-document save-back to the user's signed folder (default `/Assinados`).
- The Nextcloud user is passed as the request owner, so SignDocs dispatches
  signer invites and completion notifications.
- Connection test in the admin settings.

## 0.1.1 - 2026-07-23

### Fixed

- Webhook transaction/envelope correlation.

## 0.1.0 - 2026-05-22

### Added

- Initial release: sign documents from the Files app via SignDocs Brasil,
  with status mirrored through `signdocs:*` system tags, a webhook endpoint,
  and a polling fallback for firewalled instances.
