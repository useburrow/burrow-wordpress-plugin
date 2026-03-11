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
- Forms providers (Phase 1):
  - Gravity Forms
  - Ninja Forms
  - Contact Form 7
  - Fluent Forms
- Ecommerce provider (Phase 1):
  - WooCommerce (`ecommerce.order.placed`, `ecommerce.item.purchased`)
- System events:
  - `system.heartbeat.ping` (hourly)
  - `system.stack.snapshot` (daily)
- Durable local outbox:
  - enqueue-first delivery (never block user submission flow)
  - retry on retryable failures with exponential backoff
  - terminal fail after max attempts

## Configuration Model

Settings are persisted in `burrow_settings` and include:

- API config: `api_key` (encrypted-at-rest), `base_url`
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

Event `source` is provider-specific for forms/ecommerce and `wordpress-plugin` for system events.

- `gravity-forms` -> Gravity Forms events
- `fluent-forms` -> Fluent Forms events
- `contact-form-7` -> Contact Form 7 events
- `ninja-forms` -> Ninja Forms events
- `woocommerce` -> WooCommerce order/item events
- `wordpress-plugin` -> system heartbeat/stack events

## Admin Workflow

1. Go to **Settings > Burrow**
2. Save `base_url` and `api_key`
3. Run **Discover** action
4. Run **Link** action with selected IDs
5. Edit contract JSON in **Forms Contract Mapping Editor**
6. Run **Sync Forms Contract**

## Cron Jobs

- `burrow_outbox_worker` (minute)
- `burrow_system_heartbeat` (hourly)
- `burrow_system_stack_snapshot` (daily)
- `burrow_outbox_cleanup` (daily)

## Development

### Install test dependencies

```bash
composer install
```

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

- Settings save validates and persists API key/base URL.
- Discover/link actions store routing IDs.
- Forms contract sync writes contract metadata (`syncedAt/hash/version`).
- Form submissions enqueue `forms.submission.received` using contract-approved fields only.
- WooCommerce checkout enqueues order + line-item events with deterministic keys.
- During simulated Burrow outage, events move to retrying and later sent when endpoint recovers.
- Queue metrics in admin show pending/retrying/failed and WooCommerce event counts.
- API key is never exposed on frontend pages.
