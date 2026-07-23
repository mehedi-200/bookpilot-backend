# Feature 7 — Embeddable Chat Widget — `feature/chat-widget`

> Goal: a widget a non-technical owner installs by pasting one line, and an end-customer books through in under a minute — on any website, any phone, without the widget ever fighting the host page.

## UX analysis — easier & friendlier
- **Zero-config embed:** one `<script>` tag with `data-widget-key`. No init call, no CSS import. The script injects the launcher itself.
- **Opening = instant warmth:** greeting from bootstrap ("Hi! I'm the booking assistant for *Rahim's Garage*. I can book you in — what do you need?") + **3 quick-start chips**: "📅 Book an appointment" · "💰 Services & prices" · "🕐 Opening hours". Most users never have to compose a first message.
- **Slot chips in-chat:** when the agent offers times, the reply renders tappable chips (grouped Morning/Afternoon/Evening) — tap instead of typing "10:30". Tapping sends the time as the user message.
- **Confirmation card** when a booking is created: green check, service, friendly date/time, reference `BP-2026-0042`, "Save this reference." Unmissable success.
- **Refresh-proof:** conversation token in localStorage → reopening resumes the same chat.
- Typing indicator (3 dots) while the agent thinks; failed send shows a retry chip, never silent loss.
- Mobile: panel = full-screen sheet, input pinned above keyboard, 16px font (prevents iOS zoom).

## Technical design
- **Separate Vite build entry** `src/widget/main.jsx` → `dist/widget.js` (single IIFE bundle, own package of styles). Embed:
  ```html
  <script src="https://app.bookpilot.test/widget.js" data-widget-key="bp_live_xxx" defer></script>
  ```
- **Style isolation via Shadow DOM:** launcher + panel render inside a shadow root with the widget's own reset + tokens. Host CSS cannot leak in; ours cannot leak out.
- **Theme:** light + dark tokens only, auto-selected via `prefers-color-scheme` (no toggle — it's a guest surface).
- **State:** tiny local store (React state + localStorage token). API: fetch wrapper (not the dashboard Axios) — the widget bundle stays lean; no TanStack Query.
- Booking payload: when `/api/widget/chat` returns `booking`, render the confirmation card; when the reply contains structured `slots` (agent tool result passthrough), render chips.

## UI spec (fixed)
- **Launcher:** 56px circular button, bottom-right 20px, accent bg, chat icon; subtle badge dot when there's an unread agent reply while closed.
- **Panel (desktop):** 380×600, rounded-2xl, elevation shadow, opens with 150ms scale/fade. Header: business name, "AI booking assistant" subtitle, ✕. Footer: input + send button.
- **Panel (mobile):** full-screen sheet, slide-up, header with ✕.
- **Bubbles:** agent left (surface bg), user right (accent bg), max-width 80%, timestamps on tap.
- **Chips:** quick-starts + slot chips — pill buttons, wrap in rows; disabled state after selection.
- **Confirmation card:** bordered Card in the message flow — ✓ icon, service name, "Tomorrow, 10:30 AM", reference, muted footnote.

## Build order
1. Widget Vite entry + Shadow DOM mount + launcher/panel shell.
2. Bootstrap call → header/greeting/quick-chips.
3. Chat loop: send → typing indicator → reply; token persistence; error/retry.
4. Slot chips + confirmation card rendering.
5. Embed test page (`public/embed-test.html`) with intentionally hostile host CSS (`* { all: unset }`, huge fonts) to prove isolation.
6. Bundle budget check + minify (`npm run build:widget`).

## ✅ Check before closing the feature
- [ ] Works pasted into a plain HTML page, a Bootstrap page, and the hostile-CSS test page — pixel-identical
- [ ] Full booking flow on a real phone (iOS Safari + Android Chrome): keyboard, scroll, sheet, chips all correct
- [ ] Refresh mid-conversation resumes; new key = new conversation
- [ ] Dark/light auto-switch follows OS setting
- [ ] Bundle ≤ ~150KB gzipped; no console errors on host page; nothing global leaked (`window` clean except one namespaced entry)
- [ ] Invalid/revoked widget key → widget silently doesn't render (no scary errors on the owner's site)
