# Feature 9 — Dashboard, Master Search & Notifications — `feature/dashboard`

> Goal: the owner opens BookPilot and in five seconds knows: what's happening today, what needs their attention, and how the AI is performing. Search and notifications make the whole app feel connected.

## UX analysis — easier & friendlier
- **Dashboard answers three questions in order:** ① What needs me now? (pending confirmations, failed syncs, handed-off chats — an **"Needs attention"** card, only rendered when non-empty) ② What's today? (today's bookings timeline) ③ How is it going? (week stats + AI share).
- **Onboarding checklist card** (from Feature 3's `setup_state`) sits on top until all four steps done, then disappears forever. First-run experience = guided, not empty.
- Stat cards are **clickable** — "Pending 4" jumps to the bookings list pre-filtered. Numbers you can act on, not decoration.
- **Search everywhere, one keystroke:** `/` focuses master search on desktop. Results grouped (Bookings · Customers · Conversations) with keyboard navigation, Enter opens. Mobile: 🔍 in top bar → full-screen search page, recent searches remembered.
- **Notifications are few and meaningful** (only: new AI booking, booking cancelled, handoff, sync failed) — no noise, so the badge means something. Clicking one deep-links to the exact record and marks it read. "Mark all read" available.

## Database design
```
notifications
  id          bigint PK
  user_id     FK → users INDEX
  type        varchar(40)            -- ai_booking | booking_cancelled | handoff | sync_failed
  title       varchar(140)
  body        varchar(300) null
  data        json null              -- { booking_id | conversation_id } for deep-linking
  read_at     datetime null
  timestamps
  COMPOSITE INDEX (user_id, read_at)
```
Created by `NotificationService::notify(users, type, payload)` — called from BookingService (AI booking created, cancelled), AgentService (handoff), GarageFlow sync job (failed). Fan-out: admins get everything; staff get ai_booking + handoff.

Dashboard/search need **no new tables** — DashboardService and SearchService are query classes over existing, already-indexed data (`bookings(starts_at,status)`, `customers.phone`, `conversations.status`).

## API contract
| Method | Route | Auth | Notes |
|---|---|---|---|
| GET | /api/dashboard | sanctum | one response: `needs_attention {pending, failed_syncs, handoffs}`, `today []`, `stats {today, week, pending, active_conversations, ai_share_month}`, `by_status {}`, `setup_state` |
| GET | /api/search?q= | sanctum | min 2 chars; grouped `{ bookings[≤5], customers[≤5], conversations[≤5] }` — reference/name/phone/preview matches |
| GET | /api/notifications | sanctum | paginated |
| GET | /api/notifications/unread-count | sanctum | `{ count }` (polled) |
| PATCH | /api/notifications/{id}/read · /api/notifications/read-all | sanctum | |

## UI spec
- **Dashboard:** (top→bottom) Onboarding card (conditional) → Needs-attention card (conditional, amber left border, rows deep-link) → 4 stat cards (Today · This week · Pending · Active chats; click-through; skeletons while loading) → **Today** card (timeline rows: time, customer, service, StatusChip, source icon; EmptyState "Nothing booked today") → **AI vs manual** slim bar (last 30 days, "🤖 62% of bookings were made by your AI"). Mobile: same order, single column.
- **Master search (desktop):** dropdown panel under the header input — grouped sections with icons, highlighted match text, footer hint "↑↓ to navigate · Enter to open". Debounced 250ms, skeleton rows while fetching, "No matches for 'x'" state.
- **Search (mobile):** full-screen page — input autofocused, recent searches (localStorage chips), grouped results as tappable cards.
- **Notifications:** bell + badge (unread count, 9+ cap). Desktop: 360px dropdown panel — unread rows tinted with accent dot, friendly time, "Mark all read" in the header. Mobile: full-screen sheet. Row click → deep-link + mark read. EmptyState: "You're all caught up 🎉". Poll unread-count every 30s (TanStack refetchInterval), list refetched on open.

## Build order
1. Notifications table + NotificationService + triggers in existing services + endpoints (tests: fan-out per role, dedupe on retry storms).
2. DashboardService + endpoint (tests: each stat with seeded fixtures, ai_share math).
3. SearchService + endpoint (tests: grouping, limits, min length, phone-normalized match).
4. FE Dashboard (cards in the fixed order) → master search (desktop dropdown + mobile page) → notifications (bell/panel/sheet + polling + deep links).

## ✅ Check before closing the feature
- [x] Dashboard = **one** API call; every number verified against seeded data; stat cards deep-link to correctly pre-filtered lists
- [x] Onboarding card reflects real setup_state and disappears when complete
- [ ] `/` shortcut focuses search; keyboard navigation works; searching a partial phone finds the customer
- [x] Each notification type fires exactly once per event; deep-links land on the right record; read state syncs across bell + list
- [x] Sync-failure retry storm doesn't spam duplicate notifications
- [ ] 3-theme + 3-breakpoint sweep (dashboard cards, search dropdown/page, notification panel/sheet)
