# BookPilot — Development Plan

> AI booking agent for small businesses. Laravel 13 API (Claude tool-calling) + React 19 SPA (chat widget + dashboard).
> Workflow: `main` ← `develop` ← `feature/*`. One feature = one branch = one PR.
> UI rules in [CLAUDE.md](CLAUDE.md) apply to every part below — no exceptions.

**Progress legend:** `[ ]` pending · `[x]` done

---

## Feature 0 — App Shell & Theme System — `feature/app-shell` (frontend)

### Part 0A — Theme engine
- [ ] CSS variable design tokens (colors, surfaces, borders, text) in `index.css`
- [ ] Three themes: `dark` (DEFAULT), `light`, `reading` via root class
- [ ] `useTheme` hook + localStorage persistence
- [ ] No hardcoded colors anywhere — tokens only

### Part 0B — Core component library (`src/components/`)
- [ ] Button (variants: primary / secondary / danger / icon)
- [ ] Input, Select, Textarea (React Hook Form compatible)
- [ ] Card, Modal, EmptyState, Spinner, StatusChip
- [ ] Table (desktop) / ListView cards (mobile) — one data-list component, two renders
- [ ] **Pagination** — exact approved design: rounded bar, circular indigo refresh button, `Showing [N] entries` badge, `Show [size]` input, `‹Previous · pages (active = solid indigo square) · Next›`; compact app-style variant on mobile

### Part 0C — Desktop layout
- [ ] Thin header: master search bar + notification bell + profile menu
- [ ] Thin collapsible sidebar (icons-only when collapsed, state persisted)
- [ ] Thin footer

### Part 0D — Mobile / tablet native-app layout
- [ ] Bottom navigation bar (Dashboard, Bookings, Conversations, Services, More)
- [ ] App-style top bar per page (title + contextual actions)
- [ ] Full-screen card-based pages, 44px touch targets

### Part 0E — Routing skeleton
- [ ] React Router routes for all pages (placeholder pages)
- [ ] `<AppLayout>` switching chrome by breakpoint
- [ ] `<ProtectedRoute>` wrapper (redirects to /login)

---

## Feature 1 — Authentication & Profile — `feature/authentication`

### Part 1A — API: auth endpoints
- [ ] `users` table: add `role` (admin|staff), seeder for first admin
- [ ] `POST /api/login`, `POST /api/logout` — `AuthController` → `LoginRequest` → `AuthService` → `UserResource` → `ApiResponse`
- [ ] Role middleware (`admin` only routes)

### Part 1B — API: profile & staff accounts
- [ ] `GET/PUT /api/profile` (`UpdateProfileRequest`, password change)
- [ ] Admin CRUD for staff accounts (`StoreUserRequest`)

### Part 1C — Frontend: auth flow
- [ ] Login page (app-style on mobile)
- [ ] `authService.js` + `useAuth` hook, token storage, 401 auto-logout (Axios interceptor)
- [ ] ProtectedRoute wired to real auth state

### Part 1D — Frontend: profile & settings
- [ ] Profile page (view/edit, password change)
- [ ] Profile dropdown in header: profile / theme switcher / logout
- [ ] Staff management page (admin only)

---

## Feature 2 — Business Setup — `feature/business-setup`

### Part 2A — API: business profile
- [ ] Migration + `Business` model (name, slug, phone, email, address, timezone, widget_key)
- [ ] `GET/PUT /api/business` — thin controller → `BusinessService` → `BusinessResource`
- [ ] Widget key generator + regenerate endpoint (admin only)

### Part 2B — API: services & working hours
- [ ] Migration + `Service` model (name, duration_minutes, price, active, soft deletes) — `apiResource /api/services` + Form Requests + paginated index
- [ ] Migration + `WorkingHour` model (day_of_week, open/close, closed flag) + `closed_dates` (holidays)
- [ ] `GET/PUT /api/working-hours` via `WorkingHourService`

### Part 2C — Frontend: settings pages
- [ ] Business profile page (edit form + widget key display/copy/regenerate)
- [ ] Services page: Table/cards + add/edit/delete (confirm Modal, soft delete) + shared Pagination
- [ ] Working hours editor (per-day open/close/closed) + closed dates list

---

## Feature 3 — Bookings & Availability — `feature/bookings`

### Part 3A — API: bookings CRUD
- [ ] Migrations: `customers` (name, phone, email) + `bookings` (customer_id, service_id, starts_at, ends_at, status, source: widget|manual, notes)
- [ ] `BookingController` (thin) → `BookingService` → `BookingResource` + Form Requests
- [ ] Filters: status, service, date range; search (`?q=` customer name/phone); paginated index

### Part 3B — API: availability engine + status machine
- [ ] `GET /api/availability?service_id&date` — free slots computed in `AvailabilityService` from working hours − existing bookings − closed dates
- [ ] `PATCH /api/bookings/{id}/status` — transitions enforced in `BookingService`: Pending → Confirmed → Completed only; Cancelled allowed from Pending/Confirmed
- [ ] Invalid transition or double-booked slot ⇒ `sendError(422)`

### Part 3C — Frontend: bookings list
- [ ] Bookings page: status filter chips + service/date filters + search + Pagination
- [ ] StatusChip colors per status (theme tokens)
- [ ] Day/week calendar strip view (upcoming bookings)

### Part 3D — Frontend: create booking + detail
- [ ] Manual booking form: service select → available slots load → customer info
- [ ] Booking detail page: info, status advance button (only valid next step shown), cancel with confirm Modal

---

## Feature 4 — AI Agent (Claude tool-calling) — `feature/ai-agent` ⭐ core

### Part 4A — API: conversation storage
- [ ] Migrations: `conversations` (customer info captured, channel, status: active|ended|handed_off) + `messages` (conversation_id, role: user|assistant|tool, content, tool payload JSON)
- [ ] `ConversationController` (thin, dashboard read-only) → `ConversationService` → Resources; paginated index + detail with messages

### Part 4B — API: Claude agent loop
- [ ] `AgentService`: Claude API (model `claude-sonnet-5`) tool-use loop — send history + tools, execute requested tool, return result, repeat until final text
- [ ] Tools (each delegates to existing Services): `list_services`, `check_availability`, `create_booking`, `reschedule_booking`, `cancel_booking`, `handoff_to_human`
- [ ] System prompt built from business profile + working hours (name, tone, guardrails: booking topics only, never invent slots)
- [ ] Config: API key in `.env` / `config/bookpilot.php`; token + error handling ⇒ graceful `sendError`

### Part 4C — API: public chat endpoints (widget)
- [ ] `POST /api/widget/chat` (public, widget_key auth middleware + rate limiting) — message in → agent reply out, conversation persisted
- [ ] `GET /api/widget/bootstrap` — business name, services, hours for widget header

### Part 4D — Frontend: conversations in dashboard
- [ ] Conversations page: list (status filter + search + Pagination)
- [ ] Conversation detail: chat transcript view (user/assistant/tool events), linked booking chips, handoff badge

---

## Feature 5 — Chat Widget — `feature/chat-widget`

### Part 5A — Widget build
- [ ] Separate Vite entry `src/widget/` → single embeddable JS bundle + `<script>` snippet with `data-widget-key`
- [ ] Floating launcher button → chat panel (mobile: full-screen sheet)

### Part 5B — Widget chat UI
- [ ] Message list, typing indicator, quick-reply chips for slot picking
- [ ] Booking confirmation card in-chat (service, time, reference)
- [ ] Widget theme: self-contained tokens (light + dark), isolated styles — never leaks into or inherits from the host page

---

## Feature 6 — GarageFlow Integration — `feature/garageflow-integration`

### Part 6A — API
- [ ] `integrations` table (provider: garageflow, base_url, api_token, enabled)
- [ ] `GarageFlowService`: on booking Confirmed → find/create GarageFlow customer + vehicle → create service job; store remote IDs on booking
- [ ] Connection test endpoint + sync failure handling (booking keeps `sync_status`, retry endpoint)

### Part 6B — Frontend
- [ ] Integration settings page: connect form (URL + token), test connection, enable/disable
- [ ] Booking detail: GarageFlow sync status chip + link/retry action

---

## Feature 7 — Dashboard — `feature/dashboard`

### Part 7A — API
- [ ] `GET /api/dashboard` — totals: bookings today / this week, pending confirmations, active conversations, AI-booked % (month) — single `DashboardService`
- [ ] Upcoming bookings + bookings-by-status counts in same response

### Part 7B — Frontend
- [ ] Stat cards grid (responsive, theme-aware)
- [ ] Upcoming bookings list + status breakdown
- [ ] Dashboard is the post-login landing page

---

## Feature 8 — Master Search — `feature/dashboard` (same branch)

### Part 8A — API
- [ ] `GET /api/search?q=` → grouped results (bookings, customers, conversations) via `SearchService`

### Part 8B — Frontend
- [ ] Header search bar: debounced dropdown with grouped results → navigate to detail
- [ ] Mobile: search icon in top bar → full-screen app-style search page

---

## Feature 9 — Notifications — `feature/dashboard` (same branch)

### Part 9A — API
- [ ] `notifications` table + endpoints: list (paginated), unread count, mark read
- [ ] Triggers in Services: new AI booking (→ admin/staff), booking cancelled, conversation handed off to human, GarageFlow sync failed

### Part 9B — Frontend
- [ ] Bell + unread badge in header / app top bar
- [ ] Notification panel (dropdown desktop / full-screen sheet mobile), mark-as-read
- [ ] Poll with TanStack Query refetch interval

---

## Feature 10 — Quality & Release — `refactor/*` → `main`

### Part 10A — Tests (API)
- [ ] Auth: login, role access
- [ ] Availability engine: hours, overlaps, closed dates, double-booking rejection
- [ ] Booking status machine: every valid + invalid transition
- [ ] Agent tools: each tool executes + agent loop with mocked Claude responses
- [ ] Widget endpoints: widget_key auth + rate limiting

### Part 10B — CI & data
- [ ] GitHub Actions: run tests on push/PR + README badges
- [ ] Seeders: 1 admin, 2 staff, business + hours, ~8 services, ~15 customers, ~40 bookings (all statuses), sample conversations

### Part 10C — Release
- [ ] README screenshots (desktop + mobile, all 3 themes) + widget embed guide
- [ ] Final review, merge `develop` → `main`, tag `v1.0.0`

---

## Out of scope for v1 (planned v2 — open as GitHub issues)
Multi-business/multi-tenant SaaS · public registration · password-reset emails · SMS/email reminders to customers · voice channel · WhatsApp/Messenger channels · payments & deposits · staff-level calendars/resources · streaming agent responses · CSV import/export · real-time websockets · charts library · E2E tests
