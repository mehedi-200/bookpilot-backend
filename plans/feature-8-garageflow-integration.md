# Feature 8 — GarageFlow Integration — `feature/garageflow-integration`

> Goal: a confirmed BookPilot booking becomes a GarageFlow service job automatically — and when it can't, the failure is visible and retryable, never silent, and never blocks the booking itself.

## UX analysis — easier & friendlier
- **Connect flow = 3 fields + proof:** GarageFlow URL, API token, **Test connection** button that calls GarageFlow's ping/login and shows "✓ Connected as *admin@garage*" before you can enable. No guessing whether the token works.
- **Sync is visible where you look:** every booking detail shows a sync chip — `⏳ Syncing` / `✓ In GarageFlow` (with job link-out if URL known) / `⚠ Sync failed · Retry`. The bookings list shows a small ⚠ marker on failed rows.
- **Failures never block:** the booking confirms for the customer regardless; sync happens after (queued), retries automatically ×3 with backoff, then marks `failed` + notification (F9).
- Disable switch pauses new syncs but keeps history; token stored encrypted, displayed masked.

## Database design
```
integrations
  id             bigint PK
  provider       varchar(30) UNIQUE     -- 'garageflow' (v1 single row)
  base_url       varchar(255)
  api_token      text                    -- encrypted cast
  enabled        boolean default false
  last_ok_at     datetime null           -- last successful call
  last_error     varchar(500) null
  timestamps
```
On `bookings` (columns already added in F5): `garageflow_job_id`, `sync_status enum('pending','synced','failed') null` (+ add `sync_attempts tinyint default 0`, `synced_at datetime null` in this feature's migration).

## Sync design (`app/Services/GarageFlowService`)
Trigger: booking transitions to **Confirmed** (event fired from BookingService) and integration enabled → dispatch `SyncBookingToGarageFlow` job (queue: database driver).
```
Job flow:
 1. find-or-create GarageFlow customer (by phone)     POST /api/customers (search first)
 2. find-or-create vehicle for that customer          — v1: placeholder vehicle "Via BookPilot"
                                                        (BookPilot doesn't collect vehicles; agent may pass
                                                         free-text vehicle info from chat into job description)
 3. create service job (type from booking service name, expected date = booking date, description = notes + reference)
 4. save garageflow_job_id, sync_status=synced, synced_at
Failure: increment sync_attempts, backoff retry (1m/5m/15m); after 3 → sync_status=failed, last_error stored, notification.
```
HTTP client: Laravel `Http::withToken()->baseUrl()`, 10s timeout, responses logged (`storage/logs/garageflow.log` channel).

## API contract
| Method | Route | Auth | Notes |
|---|---|---|---|
| GET | /api/integrations/garageflow | admin | masked token, enabled, last_ok_at, last_error |
| PUT | /api/integrations/garageflow | admin | UpdateIntegrationRequest (url, token, enabled) |
| POST | /api/integrations/garageflow/test | admin | live call → success + remote user, or sendError with reason |
| POST | /api/bookings/{id}/sync | sanctum | manual retry (allowed when failed) |

## UI spec
- **Integrations page (admin):** one GarageFlow Card — logo/name, status line (`✓ Connected · last OK 2h ago` / `⚠ Error: 401 Unauthorized` / `Not configured`), form (URL, token masked with Show), **Test connection** button (spinner → inline result), Enable switch (disabled until a test has passed). Muted explainer: "Confirmed bookings are created as service jobs in your GarageFlow."
- **Booking detail:** sync StatusChip in the info card + Retry button when failed (calls manual sync, chip goes ⏳).
- **Bookings list:** ⚠ dot on rows with sync_status=failed.

## Build order
1. Migration (integrations + booking sync columns) + encrypted cast + config.
2. GarageFlowClient (HTTP wrapper) + test-connection endpoint.
3. Sync job + event wiring from BookingService + retry/backoff + manual retry endpoint. Tests with `Http::fake()` for every step + failure modes.
4. FE Integrations page → booking detail chip/retry → list markers.

## ✅ Check before closing the feature
- [ ] `Http::fake()` tests: full happy path, existing customer found (no duplicate), 401/timeout/500 at each step → correct retry then failed state, disabled integration = no job dispatched
- [ ] Booking confirm is never delayed by sync (job is queued, transition returns immediately)
- [ ] Live test against local GarageFlow install: booking → job appears with correct customer/type/date
- [ ] Token never appears in logs, responses, or the DB in plaintext
- [ ] Test-connection UX: wrong URL and wrong token give distinct, human error messages
- [ ] Retry from UI works and updates the chip without page reload (query invalidation)
