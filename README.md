# Burrow WordPress Plugin

Production-oriented WordPress plugin integration for Burrow onboarding, contract-based form ingestion, ecommerce event emission, system health events, and durable outbox delivery.

## Features

- Onboarding actions:
  - `POST /api/v1/plugin-onboarding/discover`
  - `POST /api/v1/plugin-onboarding/link`
  - `POST /api/v1/plugin-onboarding/forms/contracts`
- Runtime ingestion:
  - `POST /api/v1/events` with `x-api-key` header
  - Success statuses accepted by worker: `200` and `207`
- Forms providers:
  - Gravity Forms, Contact Form 7, Ninja Forms, Fluent Forms, WPForms, Formidable Forms
  - Selective form picker sorted by 120-day submission volume (Craft UX parity)
- Ecommerce provider:
  - WooCommerce (`order.placed`, `item.purchased`, lifecycle + optional funnel)
- System events:
  - `system.heartbeat.ping` (hourly)
  - `system.stack.snapshot` (weekly), including WordPress core update availability
- Durable local outbox:
  - enqueue-first delivery (never block user submission flow)
  - retry on retryable failures with exponential backoff
  - bulk retry for failed rows
  - terminal fail after max attempts

## Configuration Model

Settings are persisted in `burrow_settings` and include:

- API config: `base_url` (default `https://app.useburrow.com`; override with `BURROW_BASE_URL`)
- Ingestion key state (after link): `ingestion_key.key`, `ingestion_key.projectId`, `ingestion_key.keyPrefix`
- Burrow project deep-link metadata: `burrow_project.path`, `burrow_project.url`
- Routing config:
  - `organizationId`
  - `clientId`
  - `projectId`
  - `projectSourceId`
  - `sourceIds.forms|ecommerce|system`
- Forms contract metadata:
  - selected forms and mapping definitions
  - `fieldMappings` (`canonicalKey`, `target`, etc.)
  - optional per-contract `icon` override (Lucide icon key)
- Contract sync metadata:
  - `version`, `hash`, `syncedAt`
- Reliability config:
  - `max_attempts`
  - `queue_cap`
- Uninstall policy:
  - `cleanup_on_uninstall`

## Base URL resolution

Effective API host order:

1. `BURROW_BASE_URL` environment variable
2. Saved `base_url` setting
3. Default `https://app.useburrow.com`

Project deep-links still rewrite an `api.` host to `app.` for the browser UI.

## Outbox Table

Activation migration creates `{prefix}burrow_outbox` with:

- event identity: `event_key` (unique)
- routing metadata: `channel`, `event_name`
- payload: `payload_json`
- state: `status`, `attempt_count`, `max_attempts`, `last_error`, `next_attempt_at`
- timestamps: `created_at`, `updated_at`, `sent_at`

## Deterministic Event Keys

- Forms: `forms:<formId>:<submissionId>`
- Ecommerce order: `ecommerce:order:<orderId>`
- Ecommerce line item: `ecommerce:item:<orderId>:<lineItemId>`

## Event Source Slugs

Event `source` is provider-specific. System snapshots/heartbeats use `snapshot`.

- `gravity-forms` / `fluent-forms` / `contact-form-7` / `ninja-forms` / `wpforms` / `formidable-forms`
- `woocommerce` -> WooCommerce order/item events
- `snapshot` -> system heartbeat/stack events

## Scoped Event Dispatch

After onboarding link succeeds, the plugin stores Burrow `ingestionKey` data and uses that project-scoped key for event dispatch (`/events` + backfill). Org API keys are used only during setup and are not persisted.

## Admin Workflow

1. Activate the plugin (redirects to **Burrow → Setup**)
2. Enter base URL (defaults to `https://app.useburrow.com`) and API key
3. Select a Burrow project and link
4. Choose integrations and configure forms via the selective picker
5. Sync contracts, then optionally queue a historical backfill (defaults to **Two years**)
6. After onboarding, use **Burrow → Settings** for Overview / Integrations / provider config / Connection relink (saves auto-sync contracts)

## Cron Jobs

- `burrow_outbox_worker` (minute)
- `burrow_backfill_worker` (minute / single-event kicks)
- `burrow_system_heartbeat` (hourly)
- `burrow_system_stack_snapshot` (weekly)
- `burrow_outbox_cleanup` (daily)

## Development

### Install test dependencies

```bash
composer install
```

Requires the PHP SDK path repo at `../burrow-sdk/php` (useburrow/sdk-php `^0.9.9`).

### Run tests

```bash
composer test
```

### Build release zip (includes `vendor`)

`vendor` is ignored in source control. To produce a WordPress-installable artifact with dependencies included:

```bash
./scripts/build-release.sh
```

This writes:

- `dist/burrow-wordpress-plugin.zip`

### Icon keys

- Event icons use Lucide icon key strings.
- Source: [https://lucide.dev/icons](https://lucide.dev/icons)

## Manual QA Checklist

- Fresh connect uses `app.useburrow.com` without manual URL edits.
- `BURROW_BASE_URL` overrides the saved base URL for API calls.
- Discover/link actions store routing IDs and ingestion key.
- WPForms / Formidable Forms appear in the wizard when installed and selected.
- Form picker sorts by 120-day volume, gates save until ≥1 form is added.
- Forms contract sync writes contract metadata (`syncedAt/hash/version`).
- Form submissions enqueue `forms.submission.received` using contract-approved fields only.
- WooCommerce checkout enqueues order + line-item events with deterministic keys.
- During simulated Burrow outage, events move to retrying and later sent when endpoint recovers.
- Dashboard/Outbox bulk retry requeues all failed rows.
- Stack snapshots report WordPress core `updateAvailable` when a core upgrade exists.
- Settings saves sync contracts without re-running full onboarding.
