# Feature 0 — Project Scaffolding — `feature/project-setup`

> Goal: both repos boot, talk to each other, and enforce the conventions from day one, so every later feature only adds domain code.

## Analysis & decisions
- Get the **ApiResponse shape locked before any endpoint exists** — every error (404, 422, 500, throttle) must already return `{ success, message, data|errors }` JSON so the frontend error handling is written once.
- CORS + Sanctum config are the #1 time-wasters if left for later — do them now with the exact local URLs.
- Frontend gets the Axios + TanStack Query plumbing first so Feature 1 components are pure UI work.

## Backend build order
1. `laravel new` (Laravel 13), MySQL db `bookpilot`, commit `.env.example` with every key the app will ever need (`ANTHROPIC_API_KEY`, `BOOKPILOT_MODEL`, `FRONTEND_URL`) so setup is copy-rename-fill.
2. Sanctum: install, `auth:sanctum` group ready, token abilities not needed (single business).
3. CORS: open origins, credentials false — auth is Bearer-token (no cookies) and the F7 widget is embedded on arbitrary customer sites, so `api/*` must answer any origin.
4. `app/Traits/ApiResponse.php` — exactly `sendSuccess($data, $message='Success', $code=200)` and `sendError($message, $code, $errors=null)`.
5. Exception handler: force JSON for `/api/*` — 404 model/route, 422 validation (errors bag), 401, 403, 429, 500 — all through the ApiResponse shape.
6. Empty base folders committed: `app/Services/`, `app/Http/Requests/`, `app/Http/Resources/`.
7. Health route `GET /api/ping` → `sendSuccess(['pong' => true])` (used by frontend setup check + GarageFlow-style connection tests later).

## Frontend build order
1. Vite + React 19 + Tailwind; `@` path alias; ESLint + Prettier.
2. `src/services/api.js`: Axios instance — `baseURL` from `VITE_API_URL`, request interceptor attaches Bearer token, response interceptor: 401 → clear auth + redirect `/login`, network error → toast.
3. Providers in `main.jsx`: QueryClientProvider (sane defaults: `staleTime 30s`, `retry 1`), RouterProvider.
4. Folder skeleton committed with `.gitkeep`: `components/ pages/ widget/ services/ hooks/ utils/ store/`.
5. `.env.example` with `VITE_API_URL=http://localhost:8000/api`.

## Database design
No domain tables yet — only Laravel defaults (`users`, `cache`, `jobs`, `personal_access_tokens`). Domain tables come with their features (each feature file owns its migrations).

## ✅ Check before closing the feature
- [x] `php artisan test` green, `npm run build` green
- [x] Frontend fetches `/api/ping` successfully through the Axios instance (CORS proven)
- [x] Hitting an unknown `/api/*` route returns the JSON ApiResponse 404 shape (not HTML)
- [x] Validation error returns 422 with `errors` bag in the standard shape
- [x] Fresh-clone setup works from README steps alone (copy `.env.example`, migrate, run)
