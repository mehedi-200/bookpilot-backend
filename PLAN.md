# BookPilot — Full Development Plan

> **BookPilot** — AI booking agent for small businesses. End-customers chat with a Claude-powered agent in an embeddable widget; the agent checks real availability and books appointments. Business owners manage everything from a dashboard. Confirmed bookings sync into GarageFlow as service jobs.
>
> **Stack:** Laravel 13 + Sanctum + MySQL + Claude API (tool-calling) · React 19 + Vite + TanStack Query + Tailwind CSS
> **Workflow:** `main` ← `develop` ← `feature/*`. One feature = one branch = one PR into `develop`.
> **Conventions in [CLAUDE.md](CLAUDE.md) apply to every part below — no exceptions.**

**Progress legend:** `[ ]` pending · `[x]` done

---

# 📋 PER-FEATURE PLANS (start here)

Each feature has a complete standalone plan — UX analysis, fixed UI, database design, API contract, build order, and a QA checklist.

**Working rule: one feature at a time.** Build it → run its ✅ checklist → fix what the checklist catches → only then open the next feature's plan.

| # | Feature | Plan file |
|---|---|---|
| 0 | Project scaffolding | [plans/feature-0-project-scaffolding.md](plans/feature-0-project-scaffolding.md) |
| 1 | App shell, themes & components | [plans/feature-1-app-shell.md](plans/feature-1-app-shell.md) |
| 2 | Authentication & accounts | [plans/feature-2-authentication.md](plans/feature-2-authentication.md) |
| 3 | Business setup | [plans/feature-3-business-setup.md](plans/feature-3-business-setup.md) |
| 4 | Customers | [plans/feature-4-customers.md](plans/feature-4-customers.md) |
| 5 | ⭐ Bookings & availability | [plans/feature-5-bookings-availability.md](plans/feature-5-bookings-availability.md) |
| 6 | ⭐ AI agent (Claude tool-calling) | [plans/feature-6-ai-agent.md](plans/feature-6-ai-agent.md) |
| 7 | Embeddable chat widget | [plans/feature-7-chat-widget.md](plans/feature-7-chat-widget.md) |
| 8 | GarageFlow integration | [plans/feature-8-garageflow-integration.md](plans/feature-8-garageflow-integration.md) |
| 9 | Dashboard, search & notifications | [plans/feature-9-dashboard-search-notifications.md](plans/feature-9-dashboard-search-notifications.md) |
| 10 | Quality & release | [plans/feature-10-quality-release.md](plans/feature-10-quality-release.md) |

**UX upgrades locked in by these plans:** onboarding checklist card · `auto_confirm` setting (AI books instantly vs owner reviews) · phone-as-identity with duplicate-proof `findOrCreateByPhone` · slot picker grouped Morning/Afternoon/Evening with 14-day strip · single next-action button on booking detail (no illegal status options) · reschedule without cancel-rebook · quick-start chips + tappable slot chips + confirmation card in the widget · "Needs attention" dashboard card · clickable stat cards → pre-filtered lists · `/` search shortcut · few-but-meaningful notifications with deep links · skeleton loaders + actionable EmptyStates everywhere.

---

# 🎨 FIXED UI SPECIFICATION

This UI is **decided and locked**. Every feature below must follow it exactly — no per-page improvisation.

## Design language
- **Accent:** indigo (same as GarageFlow). Status colors: Pending = amber, Confirmed = indigo, Completed = green, Cancelled = red, Handed-off = purple. All via theme tokens.
- **Themes:** `dark` (DEFAULT) · `light` · `reading` (sepia). CSS variables + root class, persisted in localStorage. No hardcoded colors ever.
- **Surfaces:** rounded-xl cards, subtle borders, soft elevation. Tight spacing: `p-4` cards, `p-3` page gap.

## Desktop layout (≥ lg)
```
┌────────────────────────────────────────────────────────┐
│ ☰  BookPilot   [ Master search…  ]        🔔  👤       │  ← thin header
├──────────┬─────────────────────────────────────────────┤
│ Dashboard│                                             │
│ Bookings │              page content                   │
│ Convers. │        (p-3 gap on all sides)               │
│ Customers│                                             │
│ Services │                                             │
│ Settings │                                             │
├──────────┴─────────────────────────────────────────────┤
│                    thin footer                          │
└────────────────────────────────────────────────────────┘
```
- Sidebar: **Dashboard · Bookings · Conversations · Customers · Services · Staff · Integrations · Settings** (Staff/Integrations/Settings = admin only). Collapsible to icons-only — toggled ONLY by the ☰ hamburger beside the logo; state persisted.
- List pages: **no title band** (`bare` page) — table starts at top with attached toolbar: primary action (Add …) LEFT, search/filters right-aligned (`md:ml-auto`).
- Detail pages: compact `← Title` back row, never a floating title.

## Mobile / tablet layout (< lg) — native-app experience
```
┌──────────────────────────┐
│  Page Title      🔍 🔔   │  ← app top bar (per page)
│                          │
│    full-screen content   │
│    (rounded cards)       │
│                          │
├──────────────────────────┤
│  🏠    📅    💬    ⋯     │  ← bottom nav
└──────────────────────────┘
```
- Bottom nav: **Dashboard · Bookings · Conversations · More** (More = full-screen sheet with remaining pages, filtered by role).
- 44×44px minimum touch targets, card lists instead of tables, full-screen sheets instead of dropdowns.

## Shared components (build once in Feature 1, reuse everywhere)
`Button` (primary/secondary/danger/icon) · `Input` `Select` `Textarea` (RHF-compatible) · `Card` · `Modal` (desktop) / bottom-sheet (mobile) · `DataList` (table on desktop, cards on mobile, `toolbar` prop) · `Pagination` (the exact approved design — rounded bar, circular indigo refresh, `Showing [N] entries` badge, `Show [size]`, `‹Previous · squares · Next›`; compact app variant on mobile) · `StatusChip` · `EmptyState` · `Spinner` · `PageHeader` · `ConfirmModal`.

## Page inventory (fixed UI per page)
| Page | Fixed UI |
|---|---|
| Login | Centered card (desktop) / full-screen app view (mobile), logo, email+password, error banner |
| Dashboard | 4 stat cards row (Bookings today · This week · Pending · Active conversations) → Upcoming bookings list → AI vs manual bookings breakdown |
| Bookings | DataList: Ref, Customer, Service, Date/time, Source (🤖/✋), StatusChip · toolbar: **+ New booking** left; status chips + service/date filters + search right · Pagination |
| Booking detail | `← Booking #ref` row · info card (customer, service, time, source, notes) · status advance button (only the single valid next step) · Cancel (ConfirmModal) · GarageFlow sync chip |
| New booking | Modal (desktop) / full page (mobile): service select → date → **slot chips grid** (from availability API) → customer fields |
| Conversations | DataList: Customer, Started, Last message preview, Bookings made, StatusChip (active/ended/handed-off) · filters + search · Pagination |
| Conversation detail | `←` row · chat transcript: user bubbles right, agent bubbles left, **tool calls as small collapsible system rows** (e.g. `🔧 check_availability`) · linked booking chips · handoff banner |
| Customers | DataList: Name, Phone, Email, Bookings count · **+ Add customer** · search · Pagination |
| Customer detail | `←` row · info card · booking history list · conversations list |
| Services | DataList: Name, Duration, Price, Active toggle · **+ Add service** · Pagination · add/edit in Modal |
| Staff (admin) | DataList of users with role badge · **+ Add staff** · Pagination |
| Settings | Tabs: **Business** (profile form + timezone) · **Working hours** (7-day grid, per-day open/close/closed toggle + closed-dates list) · **Widget** (embed snippet + copy button, widget key show/regenerate) |
| Integrations (admin) | GarageFlow card: URL + token form, **Test connection**, enable/disable switch, last-sync status |
| Profile | Info edit + password change + theme switcher (3 theme preview cards) |
| Notifications | Bell + unread badge → dropdown panel (desktop) / full-screen sheet (mobile), mark-as-read |
| Master search | Header bar (desktop) / 🔍 full-screen page (mobile): debounced grouped results — Bookings · Customers · Conversations |

## Chat widget UI (public, embeddable — fixed)
- Floating circular launcher bottom-right (business-configurable side later — v1 fixed right).
- Panel ~380×600 desktop, **full-screen sheet on mobile**. Header: business name + "AI booking assistant" + close. Isolated styles (Shadow DOM or prefixed reset) — never inherits host page CSS.
- Message list (agent left / user right), typing indicator (3 bouncing dots), **slot-picker quick-reply chips**, **booking confirmation card** (service · date/time · reference, green check).
- Widget themes: light + dark only, auto via `prefers-color-scheme`.

---

# FEATURES

## Feature 0 — Project Scaffolding — `feature/project-setup`

### 0.1 Backend scaffold
- [x] Laravel 13 new project, MySQL database `bookpilot`, `.env.example` committed
- [x] Sanctum installed + configured (SPA token auth), CORS for frontend origin
- [x] `ApiResponse` trait (`sendSuccess` / `sendError`) + base folder structure (`Services/`, `Requests/`, `Resources/`)
- [x] Global exception handler returns JSON via ApiResponse shape (404/422/500)

### 0.2 Frontend scaffold
- [x] Vite + React 19 + Tailwind CSS, path aliases, ESLint/Prettier
- [x] Axios instance in `services/api.js` (base URL from env, auth + 401 interceptors)
- [x] TanStack Query provider + React Router + React Hook Form installed
- [x] Folder structure per CLAUDE.md (`components/ pages/ widget/ services/ hooks/ utils/`)

---

## Feature 1 — App Shell, Themes & Component Library — `feature/app-shell` (frontend)

### 1.1 Theme engine
- [x] Design tokens as CSS variables in `index.css` (bg, surface, border, text, accent, status colors)
- [x] Three themes via root class: `dark` (DEFAULT) · `light` · `reading`
- [x] `useTheme` hook + localStorage persistence

### 1.2 Component library
- [x] Button, Input, Select, Textarea (RHF-compatible)
- [x] Card, Modal (+ mobile bottom-sheet render), ConfirmModal, EmptyState, Spinner, StatusChip, PageHeader
- [x] DataList — one component: table ≥lg / cards <lg, `toolbar` prop
- [x] Pagination — the exact fixed design (desktop bar + compact mobile variant)

### 1.3 Desktop chrome
- [x] Thin header: ☰ + logo, master search bar, bell, profile menu
- [x] Collapsible thin sidebar (icons-only collapsed, persisted), thin footer

### 1.4 Mobile chrome
- [x] Bottom nav (Dashboard · Bookings · Conversations · More) + More sheet
- [x] App-style top bar per page (title + contextual actions)

### 1.5 Routing skeleton
- [x] All routes with placeholder pages, `<AppLayout>` switching by breakpoint, `<ProtectedRoute>`

---

## Feature 2 — Authentication & Accounts — `feature/authentication`

### 2.1 API: auth
- [x] `users.role` (admin|staff) migration + first-admin seeder
- [x] `POST /api/login` · `POST /api/logout` — AuthController → LoginRequest → AuthService → UserResource
- [x] `role:admin` middleware for admin route groups

### 2.2 API: profile & staff
- [x] `GET/PUT /api/profile` (UpdateProfileRequest, optional password change)
- [x] Admin CRUD `/api/users` for staff accounts (StoreUserRequest/UpdateUserRequest, paginated)

### 2.3 Frontend: auth flow
- [x] Login page per fixed UI, `authService.js` + `useAuth`, token storage, 401 auto-logout
- [x] ProtectedRoute + role-based menu filtering (admin-only items hidden for staff)

### 2.4 Frontend: profile & staff pages
- [x] Profile page (edit, password, theme switcher cards)
- [x] Staff page (admin): DataList + add/edit/delete via Modal

---

## Feature 3 — Business Setup — `feature/business-setup`

### 3.1 API: business profile & widget key
- [x] `businesses` migration/model (name, slug, phone, email, address, timezone, widget_key)
- [x] `GET/PUT /api/business` → BusinessService → BusinessResource
- [x] `POST /api/business/widget-key/regenerate` (admin)

### 3.2 API: services catalog
- [x] `services` migration/model (name, duration_minutes, price, active, soft deletes)
- [x] `apiResource /api/services` + Store/Update Requests + paginated index + active filter

### 3.3 API: working hours & closed dates
- [x] `working_hours` (day_of_week, open, close, is_closed) + `closed_dates` (date, reason)
- [x] `GET/PUT /api/working-hours` + closed-dates CRUD via WorkingHourService

### 3.4 Frontend: settings pages
- [x] Settings → Business tab (form + timezone select)
- [x] Settings → Working hours tab (7-day grid + closed-dates list)
- [x] Settings → Widget tab (embed snippet, copy button, key regenerate with ConfirmModal)
- [x] Services page per fixed UI (DataList + Modal forms + active toggle)

---

## Feature 4 — Customers — `feature/customers`

### 4.1 API
- [x] `customers` migration/model (name, phone, email, notes, soft deletes) — unique phone per business
- [x] `apiResource /api/customers` + search `?q=` (name/phone) + paginated
- [x] `GET /api/customers/{id}` includes booking history + conversations (Resource nesting)

### 4.2 Frontend
- [x] Customers list per fixed UI + `customerService.js` + `useCustomers` hooks
- [x] Add/edit (Modal) + delete (ConfirmModal, soft delete)
- [x] Customer detail page (info card, bookings, conversations)

---

## Feature 5 — Bookings & Availability Engine — `feature/bookings` ⭐ core

### 5.1 API: availability engine
- [x] `AvailabilityService::slots(service_id, date)` = working hours − closed dates − existing bookings, slot size = service duration
- [x] `GET /api/availability?service_id&date` (used by dashboard AND agent tool)
- [x] Timezone-correct (business timezone), no past slots, configurable lead-time buffer

### 5.2 API: bookings CRUD + status machine
- [x] `bookings` migration (customer_id, service_id, starts_at, ends_at, status, source: widget|manual, reference, notes)
- [x] BookingController (thin) → BookingService → BookingResource; Store/Update Requests
- [x] Reference generator `BP-YYYY-NNNN`; double-booking check inside DB transaction ⇒ 422
- [x] `PATCH /api/bookings/{id}/status` — transitions ONLY: Pending → Confirmed → Completed; Cancelled from Pending/Confirmed; invalid ⇒ `sendError(422)`
- [x] Index filters: status, service, date range, source + search `?q=` + paginated

### 5.3 Frontend: bookings list & detail
- [x] Bookings page per fixed UI (status chips row, filters, search, Pagination, source icons)
- [x] Booking detail per fixed UI (status advance = single valid next step, cancel ConfirmModal)

### 5.4 Frontend: manual booking
- [x] New-booking flow per fixed UI: service → date → slot chips grid → customer (pick existing or create inline)
- [x] `bookingService.js` + `useBookings` / `useAvailability` hooks

---

## Feature 6 — AI Agent (Claude tool-calling) — `feature/ai-agent` ⭐ core

### 6.1 API: conversation storage
- [x] `conversations` (customer_name/phone captured, status: active|ended|handed_off, last_activity_at) + `messages` (role: user|assistant|tool, content, tool_name, tool_payload JSON)
- [x] Dashboard read endpoints: paginated index + detail with full transcript (ConversationResource)

### 6.2 API: agent core
- [x] `AgentService`: Claude API loop (model `claude-sonnet-5`) — history + tool defs → execute requested tool → feed result back → repeat until final text; max-iteration cap
- [x] Tools delegate to existing Services: `list_services` · `check_availability` · `create_booking` · `reschedule_booking` · `cancel_booking` · `handoff_to_human`
- [x] System prompt from business profile + hours; guardrails: booking topics only, never invent slots, always confirm before booking
- [x] `config/bookpilot.php` (API key from `.env`, model, max tokens); API errors ⇒ graceful fallback message + logged

### 6.3 API: public widget endpoints
- [x] `widget_key` auth middleware + per-conversation rate limiting (throttle)
- [x] `POST /api/widget/chat` (message + conversation token → agent reply), `GET /api/widget/bootstrap` (business name, services, hours)

### 6.4 Frontend: conversations pages
- [x] Conversations list per fixed UI + `conversationService.js` + hooks
- [x] Conversation detail: transcript with collapsible tool-call rows, linked booking chips, handoff banner

---

## Feature 7 — Embeddable Chat Widget — `feature/chat-widget`

### 7.1 Widget build & embed
- [x] Separate Vite entry `src/widget/` → single JS bundle; embed: `<script src=".../widget.js" data-widget-key="…">`
- [x] Style isolation (Shadow DOM/prefixed reset); light+dark via `prefers-color-scheme`

### 7.2 Widget UI & chat flow
- [x] Launcher + panel per fixed widget UI (full-screen sheet on mobile)
- [x] Message list, typing indicator, error retry state
- [x] Slot-picker quick-reply chips + booking confirmation card
- [x] Conversation token persisted (localStorage) so refresh resumes the chat

---

## Feature 8 — GarageFlow Integration — `feature/garageflow-integration`

### 8.1 API
- [x] `integrations` table (provider, base_url, api_token encrypted, enabled)
- [x] `GarageFlowService`: on booking → Confirmed: find/create customer + vehicle in GarageFlow → create service job; store remote IDs + `sync_status` on booking
- [x] `POST /api/integrations/garageflow/test` + retry-sync endpoint; failures logged, never block the booking

### 8.2 Frontend
- [x] Integrations page per fixed UI (form, test connection, enable switch, last-sync)
- [x] Booking detail: sync StatusChip + retry action

---

## Feature 9 — Dashboard, Search & Notifications — `feature/dashboard`

### 9.1 API: dashboard
- [x] `GET /api/dashboard` (single DashboardService): bookings today/week, pending count, active conversations, AI-booked % (month), upcoming bookings, bookings-by-status

### 9.2 API: master search
- [ ] `GET /api/search?q=` → grouped: bookings (ref/customer), customers, conversations — SearchService

### 9.3 API: notifications
- [ ] `notifications` table + list (paginated) / unread-count / mark-read endpoints
- [ ] Triggers in Services: new AI booking, booking cancelled, handoff to human, GarageFlow sync failed

### 9.4 Frontend
- [x] Dashboard page per fixed UI (stat cards, upcoming list, AI/manual breakdown); post-login landing
- [ ] Master search: debounced grouped dropdown (desktop) / full-screen page (mobile)
- [ ] Bell + badge, notification panel/sheet, mark-as-read, TanStack Query polling

---

## Feature 10 — Quality & Release — `refactor/*` → `main`

### 10.1 API tests
- [ ] Auth: login, role access
- [ ] Availability: hours/closed-dates/overlaps, double-booking rejected, timezone edges
- [ ] Booking status machine: every valid + invalid transition
- [ ] Agent: each tool executes correctly; loop tested with mocked Claude responses; guardrail (invented slot) rejected
- [ ] Widget endpoints: widget_key auth + rate limit

### 10.2 CI & seed data
- [ ] GitHub Actions: tests on push/PR + README badges
- [ ] Seeders: 1 admin, 2 staff, business + hours, 8 services, 15 customers, 40 bookings (all statuses/sources), 10 conversations with transcripts

### 10.3 Release
- [ ] README: screenshots (desktop + mobile, all 3 themes) + widget embed guide + GarageFlow setup guide
- [ ] Final review, merge `develop` → `main`, tag `v1.0.0`

---

## Out of scope for v1 (v2 — open as GitHub issues)
Multi-tenant SaaS · public registration · password-reset emails · SMS/WhatsApp/email reminders · voice channel · payments & deposits · staff-level calendars/resources · streaming agent responses · widget position/branding config · CSV import/export · websockets (real-time) · charts library · E2E tests
