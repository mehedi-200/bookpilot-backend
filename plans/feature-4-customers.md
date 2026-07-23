# Feature 4 — Customers — `feature/customers`

> Goal: one clean customer record per real person, whether they arrived via the AI widget or were typed in manually. Phone number is the identity key.

## UX analysis — easier & friendlier
- **Phone = identity.** The agent captures name+phone in chat; `CustomerService::findOrCreateByPhone()` prevents duplicates automatically. Manual booking uses the same path — type a phone, existing customer autofills.
- Customer detail is a **timeline page**: everything about this person (bookings + conversations) in one place — owners answer "who is this calling me?" in 5 seconds.
- Inline create: from the New Booking form you can add a customer without leaving the flow (Modal-in-flow).
- Phone input: normalized on save (strip spaces/dashes); display formatted.
- Delete is soft + guarded: ConfirmModal explains bookings/conversations are kept and unlinked names remain on records.

## Database design
```
customers
  id        bigint PK
  name      varchar(120)
  phone     varchar(30)  UNIQUE INDEX     -- normalized digits (+country optional)
  email     varchar(150) null
  notes     text null                      -- owner's private notes ("prefers mornings")
  timestamps + softDeletes
```
- Relations: `hasMany bookings`, `hasMany conversations`.
- Uniqueness enforced on normalized phone at the service layer (single business in v1, plain unique index is enough).

## API contract
| Method | Route | Auth | Notes |
|---|---|---|---|
| GET | /api/customers | sanctum | paginated, `?q=` name/phone (normalized match) |
| POST | /api/customers | sanctum | StoreCustomerRequest; 422 with existing customer info if phone taken (frontend offers "Use existing") |
| GET | /api/customers/{id} | sanctum | CustomerResource + bookings (latest 10) + conversations (latest 5) |
| PUT | /api/customers/{id} | sanctum | UpdateCustomerRequest |
| DELETE | /api/customers/{id} | admin | soft delete |
| GET | /api/customers/lookup?phone= | sanctum | exact-match helper for booking form autofill |

`CustomerService`: `findOrCreateByPhone(name, phone, email?)` — reused by BookingService (manual) and the agent's `create_booking` tool. Normalization lives in one place (`PhoneNumber` util / cast).

## UI spec
- **Customers list:** DataList — Name (initials avatar), Phone, Email, Bookings count chip, Last seen. Toolbar: **+ Add customer** left; search right. Pagination. EmptyState: "No customers yet — they'll appear automatically when the AI books for them."
- **Add/edit:** Modal — name, phone, email, notes. Duplicate-phone 422 renders as inline option: "This phone belongs to *Rahim Uddin* → [Open profile]".
- **Customer detail:** `← Customer` row · header Card (avatar, name, phone `tel:` link, email, editable notes inline) · **Bookings** section (mini list rows: service, date, StatusChip → booking detail) · **Conversations** section (started, preview, → transcript). Mobile: same stacked cards.

## Build order
1. Migration + model + `PhoneNumber` normalization + factory.
2. CustomerService (findOrCreateByPhone first — it's the contract other features use) + endpoints + tests.
3. FE: list → add/edit modal (with duplicate handling) → detail timeline.

## ✅ Check before closing the feature
- [x] API tests: CRUD, `?q=` matches partial name AND partial phone, duplicate phone 422 payload, findOrCreateByPhone idempotent under normalization ("+880 17..." == "017...")
- [x] Duplicate-phone UX: creating an existing phone offers "Open profile" instead of a dead error
- [x] Lookup autofill responds < 300ms feel (debounced)
- [x] Detail page loads one request (nested resource), skeletons while loading
- [ ] 3-theme + 3-breakpoint sweep
