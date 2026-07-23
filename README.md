# bookpilot-backend

BookPilot API — AI booking agent for small businesses. Laravel backend with Claude API tool-calling, integrated with GarageFlow.

Frontend repo: https://github.com/mehedi-200/bookpilot-frontend
Plan: [PLAN.md](PLAN.md) · Conventions: [CLAUDE.md](CLAUDE.md)

## Requirements

- PHP 8.4+, Composer 2
- MySQL 5.7+ (MAMP works)
- Node 20+ (for Vite assets, optional in API-only use)

## Setup

```bash
composer install
cp .env.example .env          # then fill DB_* credentials and ANTHROPIC_API_KEY
php artisan key:generate
mysql -u root -p -e "CREATE DATABASE bookpilot CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
php artisan migrate
php artisan serve             # http://localhost:8000
```

Check it works: `curl http://localhost:8000/api/ping`

## Environment keys

| Key | Purpose |
|---|---|
| `DB_*` | MySQL connection |
| `FRONTEND_URL` | Dashboard SPA origin (dev: `http://localhost:5173`) |
| `ANTHROPIC_API_KEY` | Claude API key for the booking agent |
| `BOOKPILOT_MODEL` | Agent model (default `claude-sonnet-5`) |
| `BOOKPILOT_MAX_TOKENS` / `BOOKPILOT_MAX_ITERATIONS` | Agent loop limits |
| `BOOKPILOT_LEAD_TIME_MINUTES` | Minimum notice before a bookable slot |

## Tests

```bash
php artisan test
```
