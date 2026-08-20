# Changelog

All notable changes to this app are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
The Nextcloud App Store reads the section matching each published version, so
every release must add one here.

## 0.3.0 - 2026-08-20

### Changed

- **Signing links are no longer copyable when the policy is a simple click.**
  Under simple electronic signing the policy is one click, so the link is a
  bearer credential: whoever holds it signs as the named signer. Offering the
  sender a copy button next to it invited signing on the other party's behalf.
  Those links now leave only as the invitation email SignDocs sends — the URL is
  not stored, not returned to the browser, and neither the link nor the copy
  button is rendered. Links under click + OTP and ICP-Brasil digital certificate
  are unaffected: the URL alone does not satisfy either second factor.
- **Exception for signing your own document.** SignDocs skips the invitation
  when a signer's address is the sender's own, so that link is still shown —
  it is the signer's own link, and withholding it would leave them no way to
  sign.
- Simple electronic signing now requires an email address on your Nextcloud
  profile. Without one the API emails nobody, which combined with the above
  would create a document that can never be signed. The option is withdrawn from
  the dialog, and the server refuses the combination.

### Added

- **"Assinar" on your own requests.** When you are a signer on your own send,
  SignDocs dispatches no invitation — the addresses match — so the link only
  ever appeared in the dialog that showed it. Rows carrying a signature of your
  own now offer to mint a fresh link and open the signing page directly. The URL
  is never displayed and never stored; a new one is issued each time.
  Requires `signdocs-brasil/signdocs-brasil-php` ^1.11, the release that first
  exposes the endpoint this calls.

### Fixed

- **Single-signer sends now show the signing link.** The link was discarded on
  creation, so a document you sent to yourself produced no invitation email and
  no link — it could never be signed, despite the dialog promising a link.
- **Sending twice no longer sends twice.** Every attempt at one submission —
  a double-clicked confirm, or a retry after the request timed out — created a
  fresh envelope, spent quota again and invited every signer a second time.
  The dialog now stamps one request id per submission and the server derives an
  idempotency key per call from it, so those attempts return the first result
  instead of repeating it. Signers are keyed apart from one another, because the
  response for each carries the only copy of that signer's credential. Opening
  the dialog again mints a new id, so sending the same document twice on purpose
  still works.

### Changed (dependencies)

- `signdocs-brasil/signdocs-brasil-php` 1.9.0 → 1.11.0. Besides the endpoint
  behind "Assinar", 1.10.0 is the release that lets a caller pass an idempotency
  key on `addSession`, which the fix above depends on.

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
