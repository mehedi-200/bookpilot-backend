# bookpilot-backend

[![tests](https://github.com/mehedi-200/bookpilot-backend/actions/workflows/tests.yml/badge.svg)](https://github.com/mehedi-200/bookpilot-backend/actions/workflows/tests.yml)

BookPilot API — an AI booking agent for small businesses. Customers chat with a
Claude-powered assistant that checks real availability and books appointments;
owners manage everything from a dashboard. Confirmed bookings can sync into
[GarageFlow](https://github.com/mehedi-200/garageflow-api) as service jobs.

Frontend repo: https://github.com/mehedi-200/bookpilot-frontend
Plan: [PLAN.md](PLAN.md) · Conventions: [CLAUDE.md](CLAUDE.md)

## Requirements

- PHP 8.4+, Composer 2
- MySQL 5.7+ (MAMP works)
- An Anthropic API key for the booking agent

## Setup

```bash
composer install
cp .env.example .env          # fill in DB_* and ANTHROPIC_API_KEY
php artisan key:generate
mysql -u root -p -e "CREATE DATABASE bookpilot CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
php artisan migrate --seed    # business, working hours, first admin
php artisan serve             # http://localhost:8000
```

Check it works: `curl http://localhost:8000/api/ping`

**First login:** `admin@bookpilot.test` / `password`

### Demo data

For a populated environment — realistic services, customers, bookings across all
statuses, and chat transcripts including the agent's tool calls:

```bash
php artisan db:seed --class=DemoSeeder
```

Kept separate from the default seeder so demo customers can never end up in a
real installation.

### Queue worker

GarageFlow sync runs on the queue so confirming a booking never waits on another
system. In development:

```bash
php artisan queue:work
```

Without a worker, bookings still confirm normally — they just sit at
`sync_status: pending` until you retry from the booking page.

## Environment keys

| Key | Purpose |
|---|---|
| `DB_*` | MySQL connection |
| `FRONTEND_URL` | Dashboard SPA origin (dev: `http://localhost:5173`) |
| `ANTHROPIC_API_KEY` | Claude API key for the booking agent |
| `BOOKPILOT_MODEL` | Agent model (default `claude-sonnet-5`) |
| `BOOKPILOT_MAX_TOKENS` / `BOOKPILOT_MAX_ITERATIONS` | Agent loop limits |
| `BOOKPILOT_LEAD_TIME_MINUTES` | Minimum notice before a bookable slot (default 60) |
| `BOOKPILOT_BOOKING_WINDOW_DAYS` | How far ahead bookings are allowed |

Without `ANTHROPIC_API_KEY` the widget still works — the agent hands the
conversation to a human instead of erroring.

## API overview

All responses share one shape: `{ success, message, data }` on success and
`{ success: false, message, errors }` on failure.

| Area | Endpoints |
|---|---|
| Auth | `POST /login` (throttled), `POST /logout`, `GET/PUT /profile` |
| Users (admin) | `GET/POST/PUT /users`, `PATCH /users/{id}/toggle-active` |
| Business (admin writes) | `GET/PUT /business`, `POST /business/widget-key/regenerate` |
| Services | `GET /services`, admin: `POST/PUT/DELETE`, `PATCH /{id}/toggle-active` |
| Hours (admin) | `GET/PUT /working-hours`, `POST/DELETE /closed-dates` |
| Customers | `GET/POST/PUT /customers`, `GET /customers/lookup?phone=`, admin `DELETE` |
| Availability | `GET /availability?service_id&date` |
| Bookings | `GET/POST /bookings`, `PATCH /{id}/status`, `PATCH /{id}/reschedule`, `POST /{id}/sync` |
| Conversations | `GET /conversations`, `GET /conversations/{id}` |
| Dashboard & search | `GET /dashboard`, `GET /search?q=` |
| Notifications | `GET /notifications`, `/unread-count`, `PATCH /{id}/read`, `/read-all` |
| Integrations (admin) | `GET/PUT /integrations/garageflow`, `POST /test`, `GET /mechanics` |
| **Public widget** | `GET /widget/bootstrap`, `POST /widget/chat` — authenticated by `X-Widget-Key`, rate limited |

## How the agent is kept honest

The agent has six tools, each delegating to the same services the dashboard
uses, so it cannot diverge from the booking engine:

`list_services` · `check_availability` · `create_booking` ·
`reschedule_booking` · `cancel_booking` · `handoff_to_human`

- It can only offer times `check_availability` returned in that conversation,
  and books with the exact value it was given.
- `create_booking` goes through `BookingService`, so the slot is re-checked
  under a row lock — the agent cannot double-book any more than a human can.
- Reschedule and cancel are scoped to the phone number on the conversation, so a
  guessed reference can't touch a stranger's appointment.
- API failure or a stuck loop hands off to a human with the business phone
  number rather than showing the customer an error.

## Connecting GarageFlow

1. In GarageFlow, log in as an admin and create an API token.
2. In BookPilot, open **Integrations** and enter the GarageFlow URL (without
   `/api`) and that token.
3. Press **Test connection** — it names the account the token belongs to.
4. Choose a default mechanic. GarageFlow requires one on every service job.
5. Turn on the sync switch.

From then on, confirming a booking creates a GarageFlow service job: the
customer is found or created by phone, a placeholder vehicle is reused per
customer (BookPilot doesn't collect vehicle details), and the service name is
mapped onto GarageFlow's fixed service types. Failures are retried three times,
then flagged on the booking with a one-click retry.

## Tests

```bash
php artisan test        # 153 tests
vendor/bin/pint --test  # formatting
```

The suite runs against SQLite in memory, so it needs no database setup.
