# Feature 10 — Quality & Release — `refactor/*` → `main`

> Goal: prove the whole system, polish the rough edges found while proving it, and ship v1.0.0 with docs good enough that someone else could install BookPilot.

## Test matrix (API — the release gate)
| Area | Must-pass cases |
|---|---|
| Auth | login ok / wrong / inactive user, throttle, staff vs admin route access, self-deactivation blocked |
| Business setup | hours validation, service CRUD + soft-delete history, widget-key regeneration kills old key |
| Customers | phone normalization/idempotent findOrCreate, duplicate 422 payload, search by partial phone |
| Availability | closed day/date, lead time, overlaps, back-to-back, duration edge, timezone edge, concurrency (one winner per slot) |
| Bookings | full state machine (valid + every invalid), reschedule re-validation, reference sequence |
| Agent | mocked loop: chained tools, invalid tool input recovery, iteration cap → handoff, API error → handoff; auto_confirm both modes; cancel scoped to own phone |
| Widget API | widget-key auth, revoked key, rate limits (429 shape) |
| GarageFlow | Http::fake full path, failure at each step → retry → failed, disabled = no dispatch, no plaintext token |
| Notifications | one per event, role fan-out, no retry-storm duplicates |
| Dashboard/Search | stat math vs fixtures, grouping + limits |

## Sub-features

### 10.1 CI
- [x] GitHub Actions (both repos): backend — php setup, mysql service, `php artisan test`; frontend — `npm ci`, lint, `npm run build` + `build:widget`
- [~] Badges in both READMEs; branch protection: PRs into `develop` require green CI

### 10.2 Seed data (demo-quality, not lorem)
- [x] 1 admin + 2 staff · business "Rahim's Garage" with realistic hours (Fri closed) · 8 services with real names/prices
- [x] 15 customers (realistic BD names/phones) · 40 bookings spread over ±3 weeks, all statuses & both sources · 10 conversations with believable transcripts incl. tool calls, 1 handed-off · notifications sampling every type
- [x] `php artisan migrate:fresh --seed` = instant demo environment

### 10.3 Polish pass (the "check it properly" sweep — do this BEFORE screenshots)
- [ ] Click through every page × 3 themes × 3 breakpoints; fix every EmptyState, Skeleton, overflow, and spacing miss against CLAUDE.md rule 8
- [ ] Loading: no layout jumps (skeleton dimensions = content dimensions); Errors: every mutation shows a toast, every failed list shows retry
- [x] Friendly-time util used everywhere (no raw `2026-07-23 10:30:00` anywhere in the UI)
- [ ] Lighthouse mobile pass on dashboard + bookings (no red scores); widget bundle budget re-checked
- [x] `npm run lint` + `pint` clean; dead code and unused deps removed

### 10.4 Docs & release
- [x] Backend README: install, `.env` keys explained, queue worker note, seeding, API overview table
- [x] Frontend README: install, env, dashboard + widget builds, **widget embed guide** (copy-paste snippet + troubleshooting)
- [x] GarageFlow setup guide (getting a token, connecting, what syncs)
- [ ] Screenshots: desktop + mobile, all 3 themes (dashboard, bookings, conversation transcript, widget)
- [ ] Open v2 backlog as GitHub issues (from the out-of-scope list)
- [ ] Merge `develop` → `main` in both repos, tag `v1.0.0`

## ✅ Definition of released
- [ ] Full test matrix green in CI on `main`
- [ ] Fresh clone → README steps → working app with seed data, zero undocumented steps
- [ ] End-to-end demo run: widget chat → AI booking → notification → confirm → GarageFlow job → dashboard reflects it — recorded once without a workaround
