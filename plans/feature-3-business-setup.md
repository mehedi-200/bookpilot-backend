# Feature 3 — Business Setup — `feature/business-setup`

> Goal: everything the agent needs to answer questions and book correctly: business identity, services catalog, working hours, widget key. If this data is wrong, the AI is wrong — so the UX must make correct setup effortless.

## UX analysis — easier & friendlier
- **Onboarding checklist card** on the Dashboard (until complete): "1. Business info ✓ · 2. Working hours · 3. Add services · 4. Embed widget" — each step links straight to the right tab. New users always know the next step. (Card lives here conceptually; rendered in Feature 9's dashboard, driven by a `setup_state` in `/api/business`.)
- Working hours: **"Copy Monday to all weekdays"** button — nobody wants to enter 5 identical rows. Closed toggle per day disables the time inputs visually.
- Services: `active` **toggle right in the list row** (no need to open edit); duration as a select of common values (15/30/45/60/90/120 min) + custom.
- Widget tab: the embed snippet in a code box with **one-click Copy** button + "✓ Copied"; regenerating the key shows a ConfirmModal warning that existing embeds stop working.
- Timezone: searchable select, defaults to `Asia/Dhaka`-style guess from browser (`Intl.DateTimeFormat().resolvedOptions().timeZone`).

## Database design
```
businesses            (single row in v1)
  id            bigint PK
  name          varchar(120)
  slug          varchar(140) UNIQUE
  phone         varchar(30)  null
  email         varchar(150) null
  address       varchar(255) null
  timezone      varchar(64)  default 'UTC'
  widget_key    char(40)     UNIQUE INDEX   -- random, regenerable
  auto_confirm  boolean default false        -- AI bookings: true = Confirmed instantly, false = land as Pending
  timestamps

services
  id                bigint PK
  name              varchar(120)
  duration_minutes  smallint unsigned        -- slot size for availability
  price             decimal(10,2)
  active            boolean default true  INDEX
  sort_order        smallint default 0
  timestamps + softDeletes                    -- keep history on old bookings

working_hours         (exactly 7 rows, seeded)
  id            bigint PK
  day_of_week   tinyint UNIQUE  -- 0=Sun … 6=Sat
  open_time     time null
  close_time    time null
  is_closed     boolean default false
  timestamps

closed_dates
  id      bigint PK
  date    date UNIQUE
  reason  varchar(120) null
  timestamps
```
`auto_confirm` is the key product decision surfaced as a simple setting: cautious owners review AI bookings (Pending), confident ones let the agent confirm instantly.

## API contract
| Method | Route | Auth | Notes |
|---|---|---|---|
| GET | /api/business | sanctum | BusinessResource + `setup_state` (booleans: hours_set, has_services, widget_embedded¹) |
| PUT | /api/business | admin | UpdateBusinessRequest (incl. `auto_confirm`) |
| POST | /api/business/widget-key/regenerate | admin | returns new key |
| GET/POST/PUT/DELETE | /api/services | sanctum (write: admin) | paginated, `?active=`, soft delete |
| PATCH | /api/services/{id}/toggle-active | admin | |
| GET | /api/working-hours | sanctum | 7 rows + closed dates |
| PUT | /api/working-hours | admin | UpdateWorkingHoursRequest — validates close > open, full 7-day payload |
| POST/DELETE | /api/closed-dates | admin | date unique, future-or-today only |

¹ `widget_embedded` flips true after the first widget `bootstrap` call — honest signal, no manual checkbox.

## UI spec
- **Settings page = 3 tabs** (desktop: horizontal tabs under slim header; mobile: segmented control): **Business · Working hours · Widget**.
  - *Business:* one Card form (name, phone, email, address, timezone search-select) + "AI booking behaviour" Card with the `auto_confirm` switch explained in one sentence each state.
  - *Working hours:* 7 rows — day name, Closed toggle, open/close time inputs (disabled when closed), "Copy Mon → weekdays" link-button. Below: Closed dates Card (list + add date+reason inline, delete icon).
  - *Widget:* embed snippet code box + Copy button, widget key masked (`bp_live_••••1234`, Show/Regenerate), status line: "✓ Widget seen on your site" / "Not detected yet".
- **Services page:** DataList — Name, Duration (`45 min`), Price, Active switch, Edit. Toolbar: **+ Add service** left, search + Active filter right. Add/edit Modal: name, duration select+custom, price, active. Delete = ConfirmModal noting past bookings keep the service name.

## Build order
1. Migrations + models + seeders (business row, 7 working-hour rows).
2. Business endpoints → services CRUD → working hours + closed dates (tests alongside).
3. FE Services page → Settings tabs (Business → Hours → Widget).

## ✅ Check before closing the feature
- [ ] API tests: hours validation (close>open, 7 rows), service CRUD + toggle, key regeneration invalidates old key, staff blocked from writes
- [ ] Copy-to-weekdays works; closed day disables inputs; timezone select searchable
- [ ] Embed snippet copies correctly; regenerate shows warning modal
- [ ] Soft-deleted service still displays on historical bookings (verified again in Feature 5)
- [ ] 3-theme + 3-breakpoint sweep on Settings + Services
