# Feature 5 — Bookings & Availability Engine — `feature/bookings` ⭐ core

> Goal: the truth about time. One availability engine that the dashboard, the manual form, and the AI agent all trust; a booking lifecycle that can never double-book or skip a state.

## UX analysis — easier & friendlier
- **Slot picker grouped by Morning / Afternoon / Evening** — scanning 40 flat time chips is painful; three labeled groups read instantly. Disabled (taken) slots hidden, not greyed (less noise).
- **Manual booking = 3 steps in one view:** Service → Day strip (next 14 days, horizontal scroll chips with day name + date; closed days dimmed) → slot chips. Then phone field with autofill (Feature 4 lookup). Fewest possible fields.
- **One next-action button** on booking detail: Pending shows only **Confirm** (+ Cancel link), Confirmed shows only **Mark completed**. Users never see a status dropdown with illegal options.
- Source is always visible: 🤖 "Booked by AI" / ✋ "Manual" chip — owners quickly learn to trust the agent.
- Reschedule = pick a new slot with the same picker (keeps history: `rescheduled_from` timestamps on the record) — cancel-and-rebook is hostile UX.
- Human-friendly times everywhere: "Tomorrow, 10:30 AM", "Today 4:00 PM" (util used app-wide).

## Database design
```
bookings
  id                bigint PK
  reference         char(12) UNIQUE            -- BP-2026-0001
  customer_id       FK → customers  INDEX
  service_id        FK → services   INDEX
  starts_at         datetime  INDEX            -- stored UTC, business TZ at edges
  ends_at           datetime                   -- starts_at + service duration
  status            enum('pending','confirmed','completed','cancelled') INDEX
  source            enum('widget','manual') default 'manual'
  conversation_id   FK → conversations null    -- which chat created it (wired in F6)
  notes             varchar(500) null
  rescheduled_from  datetime null
  cancelled_at      datetime null
  cancel_reason     varchar(200) null
  garageflow_job_id varchar(40) null           -- filled by Feature 8
  sync_status       enum('pending','synced','failed') null
  timestamps
  COMPOSITE INDEX (starts_at, status)          -- availability + calendar queries
```
**Integrity rules (service layer, inside DB transaction with `lockForUpdate` on the overlap check):**
- Overlap check: same time range, status IN (pending, confirmed) ⇒ reject 422 "slot no longer available".
- State machine in `BookingService::transition()`: `pending→confirmed→completed`; `cancelled` allowed from pending/confirmed; anything else ⇒ 422. Completed/cancelled are terminal.
- Reference: `BP-<year>-<zero-padded sequence>` generated in the same transaction.

## Availability engine (`AvailabilityService`)
`slots(service_id, date)`:
1. Working hours for that weekday (closed / closed_date ⇒ `[]`).
2. Generate slots stepping by the **service duration** from open to close−duration.
3. Subtract overlapping pending+confirmed bookings.
4. Drop past slots (business timezone) + lead-time buffer (config `bookpilot.lead_time_minutes`, default 60).
Return grouped: `{ morning: [...], afternoon: [...], evening: [...] }` — the API shapes it so widget, dashboard, and agent tool all present identically.

## API contract
| Method | Route | Auth | Notes |
|---|---|---|---|
| GET | /api/availability?service_id&date | sanctum | grouped slots |
| GET | /api/bookings | sanctum | paginated; filters: `status, service_id, source, from, to`, `q` (reference/customer name/phone) |
| POST | /api/bookings | sanctum | StoreBookingRequest: service, starts_at, customer (id OR name+phone → findOrCreateByPhone) |
| GET | /api/bookings/{id} | sanctum | full resource (customer, service, conversation link, sync state) |
| PATCH | /api/bookings/{id}/status | sanctum | `{ status, cancel_reason? }` — state machine enforced |
| PATCH | /api/bookings/{id}/reschedule | sanctum | `{ starts_at }` — re-validated against availability |

## UI spec
- **Bookings list:** status chip filter row above-right in toolbar (All · Pending · Confirmed · Completed · Cancelled with counts), service/date/source filters, search. Columns: Ref, Customer, Service, When (friendly), Source icon, StatusChip. Row → detail. EmptyState per filter ("No pending bookings 🎉").
- **Booking detail:** `← BP-2026-0042` row · Info Card (customer link, service, when, source, notes) · action zone: single next-step Button + Cancel link (ConfirmModal with reason input) + Reschedule (opens slot picker Modal) · GarageFlow sync chip placeholder (activated in F8).
- **New booking (Modal desktop / page mobile):** service select → 14-day strip → grouped slot chips → phone (autofill) + name + notes → Create. Success toast + go to detail.

## Build order
1. Migration + model + factory + reference generator.
2. AvailabilityService + unit tests (the test matrix below) — **before** any endpoint.
3. BookingService (create w/ lock, transition, reschedule) + endpoints + feature tests.
4. FE list → detail → new-booking flow → reschedule.

## ✅ Check before closing the feature
- [x] Availability unit tests: normal day, closed day, closed_date, lead-time cutoff, spanning bookings, back-to-back (no gap loss), duration change, timezone edge (23:00 slot)
- [ ] Concurrency test: two simultaneous creates for the same slot → exactly one succeeds
- [x] Every valid + invalid status transition tested (incl. terminal states)
- [ ] Manual booking end-to-end < 30 seconds by hand — count the clicks; trim any step that isn't needed
- [ ] Friendly-time util snapshot tests (Today/Tomorrow/date forms)
- [ ] 3-theme + 3-breakpoint sweep (slot chips must be thumb-friendly on mobile)
