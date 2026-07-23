# Feature 2 — Authentication & Accounts — `feature/authentication`

> Goal: secure login for admin + staff, profile self-service, admin manages staff. Small feature — do it cleanly and move on.

## UX analysis — easier & friendlier
- Login: **email remembered** (localStorage), password visibility toggle, single clear error banner ("Email or password is incorrect" — never field-level hints that leak which was wrong), button shows spinner + disables while submitting.
- After login → **Dashboard**, never a blank page; deep-linked URL is preserved (`redirect` back to where the 401 happened).
- Profile: password change needs current password; success = toast, not a page reload.
- Staff page: admin can't delete themselves; deactivate instead of hard delete (keeps history intact) — **`active` flag, not destruction**.
- Role visibility: staff simply never see admin menu items (filtered menus from Feature 1) — no "access denied" dead ends.

## Database design
```
users
  id            bigint PK
  name          varchar(100)
  email         varchar(150) UNIQUE
  password      varchar(255) (hashed)
  role          enum('admin','staff') default 'staff'   INDEX
  active        boolean default true
  timestamps
```
- Seeder: first admin (`admin@bookpilot.test` / password from env or sensible default, forced change later is v2).
- No soft deletes — `active=false` is the deactivation path; login blocked for inactive users.

## API contract
| Method | Route | Auth | Notes |
|---|---|---|---|
| POST | /api/login | public (throttle 5/min) | LoginRequest → AuthService → `{ user: UserResource, token }`; rejects inactive |
| POST | /api/logout | sanctum | revoke current token |
| GET | /api/profile | sanctum | UserResource |
| PUT | /api/profile | sanctum | UpdateProfileRequest (name, email, optional current+new password) |
| GET | /api/users | admin | paginated, `?q=` name/email, `?role=` filter |
| POST | /api/users | admin | StoreUserRequest (role required) |
| PUT | /api/users/{id} | admin | UpdateUserRequest; cannot demote/deactivate self |
| PATCH | /api/users/{id}/toggle-active | admin | not for self |

All through Controller → FormRequest → `AuthService`/`UserService` → `UserResource` → ApiResponse. `role:admin` middleware on the users group.

## UI spec
- **Login:** centered Card (desktop) / full-screen (mobile). Logo, "Welcome back" line, email, password (eye toggle), primary button. Error banner above form.
- **Profile:** two Cards — "Profile" (name/email form) and "Security" (current + new password). Theme switcher = 3 mini preview cards (dark/light/reading) with active ring.
- **Staff (admin):** DataList — Name (avatar initials), Email, Role badge (admin=indigo, staff=neutral), Active dot, Created. Toolbar: **+ Add staff** left, search right. Add/edit in Modal; deactivate via ConfirmModal ("They can no longer log in. Their history is kept.").

## Build order
1. API: migration (role, active) + seeder → login/logout → profile → users CRUD (tests alongside).
2. FE: `authService.js` + `useAuth` (user, token, `can('admin')`), token storage, 401 interceptor wired to real logout.
3. FE: Login page → ProtectedRoute real → menu role-filtering.
4. FE: Profile page → Staff page.

## ✅ Check before closing the feature
- [x] API tests: login ok/wrong/inactive, throttle, logout revokes, staff blocked from /api/users, self-deactivation blocked
- [ ] Deep link → login → returns to the deep link
- [ ] Wrong password shows friendly banner, button re-enables
- [ ] Staff user sees no admin menu items anywhere (sidebar, bottom nav, More sheet)
- [ ] All pages pass the 3-theme + 3-breakpoint sweep
