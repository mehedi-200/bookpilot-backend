# Feature 6 — AI Agent (Claude tool-calling) — `feature/ai-agent` ⭐ core

> Goal: a trustworthy booking clerk. It only speaks about this business, only offers slots the engine returned, always confirms before acting, and hands off gracefully when stuck.

## UX analysis — easier & friendlier
- **The agent never invents anything:** every slot it offers came from `check_availability` in the same conversation; every price/duration from `list_services`. System prompt forbids answering outside booking/business topics — it politely redirects.
- **Always confirm before booking:** "So that's a Full Service on Tue 10:30 AM under 01712-345678 — shall I book it?" One explicit yes → `create_booking`. No surprise bookings.
- **Name+phone captured conversationally,** once, early ("So I can hold your booking, what's your name and phone number?") — not a form.
- **Graceful failure:** Claude API down / max iterations → friendly message with the business phone number, conversation marked `handed_off`, owner notified (F9). The customer is never stranded.
- Dashboard transparency: owners can read every transcript **including tool calls** — trust comes from visibility.
- `auto_confirm` (Feature 3) decides whether agent bookings land Confirmed or Pending — the agent's wording adapts ("You're booked!" vs "Request sent — the shop will confirm shortly").

## Database design
```
conversations
  id                bigint PK
  token             char(40) UNIQUE INDEX      -- widget resume token
  customer_id       FK → customers null        -- linked once phone captured
  guest_name        varchar(120) null          -- before linking
  guest_phone       varchar(30) null
  status            enum('active','ended','handed_off') INDEX
  last_activity_at  datetime INDEX
  timestamps

messages
  id               bigint PK
  conversation_id  FK INDEX
  role             enum('user','assistant','tool')
  content          text null                   -- user/assistant text
  tool_name        varchar(60) null
  tool_input       json null
  tool_result      json null
  input_tokens     int null                    -- cost visibility
  output_tokens    int null
  timestamps
```
Booking ↔ conversation: `bookings.conversation_id` (added in F5) now gets populated by the `create_booking` tool.

## Agent architecture (`app/Services/Agent/`)
```
AgentService::handle(conversation, userMessage): string
  1. persist user message
  2. build system prompt (business profile, services summary, hours, today+timezone, guardrails)
  3. loop (max 8 iterations):
       Claude API (model config('bookpilot.model') = claude-sonnet-5, max_tokens ~1024)
       ├─ text response → persist assistant message → return
       └─ tool_use → ToolRegistry::execute(name, input) → persist tool message → continue
  4. loop/API failure → fallback message + status=handed_off
```
- `ToolRegistry` maps tool names → small invokable classes, each **delegating to the real Services** (no duplicate logic): `ListServicesTool` → ServiceCatalog, `CheckAvailabilityTool` → AvailabilityService, `CreateBookingTool` → BookingService (source=widget, respects auto_confirm), `RescheduleBookingTool`, `CancelBookingTool` (only bookings belonging to this conversation's phone), `HandoffToHumanTool`.
- Tool JSON schemas defined once next to each tool class; strict validation of Claude's inputs (bad input → tool returns error message, loop continues).
- History window: last ~30 messages; token counts persisted per call.
- Config `config/bookpilot.php`: `anthropic_key, model, max_tokens, max_iterations, lead_time_minutes`.

## API contract (public widget endpoints)
| Method | Route | Auth | Notes |
|---|---|---|---|
| GET | /api/widget/bootstrap | widget-key middleware | business name, services (name/duration/price), hours, greeting |
| POST | /api/widget/chat | widget-key + throttle (10/min per conversation, 30/min per IP) | `{ message, conversation_token? }` → `{ reply, conversation_token, booking? }` |

Dashboard endpoints (sanctum): `GET /api/conversations` (paginated, `?status=`, `?q=` name/phone), `GET /api/conversations/{id}` (full transcript).

## UI spec (dashboard side — widget itself is Feature 7)
- **Conversations list:** DataList — Customer (or "Guest"), Started (friendly), Last message preview (truncated, muted), Bookings-made count chip, StatusChip (active=indigo pulse dot, ended=neutral, handed-off=purple). Filters: status chips + search. EmptyState: "Conversations appear here when customers use your chat widget."
- **Conversation detail:** `← Conversation` row · context strip (customer link/guest info, started, status) · transcript: user right-aligned bubbles, agent left, **tool calls = small collapsible muted rows** (`🔧 check_availability · Tue 24 Jun` → expands to pretty-printed input/result) · booking chips inline where created (→ booking detail) · handed-off banner at the point of handoff. Read-only in v1.

## Build order
1. Migrations + models + config + `.env.example` keys.
2. ToolRegistry + the 6 tools with unit tests (mocked services where needed).
3. AgentService loop with **mocked Claude client** + tests (multi-tool sequence, bad tool input, max iterations, API failure → handoff).
4. Widget endpoints + middleware + throttling + tests.
5. Live smoke test against real Claude API (happy booking path).
6. FE conversations list + transcript view.

## ✅ Check before closing the feature
- [ ] Mocked-loop tests: text-only, single tool, chained tools (availability→confirm→create), invalid tool input recovers, iteration cap → handoff, API error → handoff + friendly reply
- [ ] create_booking respects auto_confirm both ways; cancel tool cannot touch other phones' bookings
- [ ] Rate limits return the standard ApiResponse 429 shape
- [ ] Real-API smoke: book, reschedule, cancel, off-topic question politely redirected, no invented slots (spot-check transcripts)
- [ ] Transcript UI: tool rows collapsed by default, expand cleanly, booking chips navigate
- [ ] Token usage visible per conversation (sum in detail header) — cost sanity
