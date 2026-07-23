# Feature 1 — App Shell, Themes & Component Library — `feature/app-shell` (frontend)

> Goal: the complete visual foundation. After this feature, every page is assembled from these pieces only — no new one-off UI later.

## UX analysis — what makes it easier & nicer
- **Dark default + instant theme switch** (no flash): theme class set on `<html>` before React mounts (inline script reading localStorage).
- **Sidebar remembers itself** (collapsed state persisted) — users hate re-collapsing every visit.
- **One DataList, one Pagination** — users learn the list pattern once; every page then feels familiar.
- **EmptyState always says what to do next** (icon + one line + action button), never a blank table. This is the single biggest "feels friendly" win.
- **Skeleton loaders** (not spinners) for lists and stat cards — the app feels fast even when it isn't.
- **Toast system** (top-right desktop / top mobile): success = quiet, errors = sticky with retry when possible. One `useToast` hook, used by every mutation.
- Bottom nav shows **active tab with filled icon + accent dot** — instant orientation on mobile.

## UI spec (locks the fixed spec into components)
- **Tokens** (`index.css`): `--bg --surface --surface-2 --border --text --text-muted --accent --accent-contrast` + status tokens `--ok --warn --danger --info`. Themes = `:root.dark`, `:root.light`, `:root.reading` overriding the same variables.
- **Header (desktop):** 48px tall. Left: ☰ (the ONLY sidebar toggle) + logo "BookPilot". Center-left: master search input (placeholder "Search bookings, customers…"). Right: 🔔 bell with badge, avatar menu.
- **Sidebar:** 232px / 64px collapsed (icons + tooltip). Items with lucide icons: Dashboard, Bookings, Conversations, Customers, Services, Staff, Integrations, Settings. Active item = accent left bar + tinted bg.
- **Footer:** 32px, muted `BookPilot v1 · © business name`.
- **Mobile chrome:** top bar 56px (title left, contextual actions right); bottom nav 64px, 4 items (Dashboard · Bookings · Conversations · More), safe-area padding.
- **Modal:** desktop = centered, max-w-lg; mobile = bottom sheet sliding up, drag-handle bar. Same component, breakpoint-switched.
- **DataList:** `columns` + `renderCard` + `toolbar` + `pagination` props; toolbar is INSIDE the top border of the table container.
- **Pagination:** exact approved design (see CLAUDE.md rule 7). Never rebuilt.

## Component checklist (all in `src/components/`)
Button · IconButton · Input · Select · Textarea · Card · Modal/Sheet · ConfirmModal · DataList · Pagination · StatusChip · EmptyState · Skeleton (list + card variants) · Spinner · PageHeader (slim, back-row variant) · Toast/useToast · SearchInput (debounced).

## Build order
1. Tokens + 3 themes + `useTheme` + pre-mount theme script.
2. Primitives: Button, Input, Select, Textarea, Card, StatusChip, Spinner, Skeleton.
3. Modal/Sheet + ConfirmModal + Toast.
4. DataList + Pagination + EmptyState + SearchInput.
5. AppLayout: desktop chrome (header/sidebar/footer) + mobile chrome (top bar/bottom nav/More sheet).
6. Router: all placeholder pages + ProtectedRoute stub (real auth in Feature 2).

## ✅ Check before closing the feature
- [ ] Every component renders correctly in all 3 themes (manual sweep with theme switcher)
- [ ] No hardcoded color anywhere (`grep` for `#`, `rgb(`, Tailwind palette classes in components)
- [ ] Sidebar collapse persists across reload; theme persists; no theme flash on load
- [ ] All routes reachable on mobile via bottom nav + More sheet; touch targets ≥ 44px
- [ ] Pagination pixel-checked against the approved reference on desktop AND compact on mobile
