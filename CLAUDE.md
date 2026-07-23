# BookPilot API — Project Conventions

AI booking agent for small businesses. Laravel 13 + Sanctum + MySQL REST API + Claude API tool-calling. Integrated with GarageFlow.
Frontend repo: https://github.com/mehedi-200/bookpilot-frontend

## Architecture (MANDATORY — follow for every feature)

```
Route → Middleware → Controller → FormRequest (validation) → Service (business logic) → Model
                                                     ↓
                                Resource (response filtering) → ApiResponse trait
```

## Rules

1. **NO business logic in controllers.** Controllers only receive the request, call a Service method, and return a response. All business logic lives in `app/Services/` (e.g. `app/Services/BookingService.php`).

2. **NO inline validation in controllers.** Always create a FormRequest in `app/Http/Requests/` (e.g. `StoreBookingRequest`, `UpdateBookingRequest`) and type-hint it in the controller method.

3. **Always use API Resources** (`app/Http/Resources/`) to filter/shape every response. Never return models or arrays directly.

4. **All responses go through the `ApiResponse` trait** (`app/Traits/ApiResponse.php`). It has exactly two methods:
   - `sendSuccess($data, string $message = '...', int $code = 200)`
   - `sendError(string $message, int $code, $errors = null)`
   Controllers use the trait; never hand-build `response()->json()` elsewhere.

5. **Use middleware properly.** Protect routes with `auth:sanctum`; use route groups for shared middleware; role checks go in middleware, not controllers.

6. **Routes: always import controllers with `use` at the top** of the route file, then reference the class. Never inline fully-qualified class paths in the route definition.

   ```php
   use App\Http\Controllers\BookingController;   // ✅ at top

   Route::apiResource('bookings', BookingController::class);
   ```
   This applies everywhere: any class path used in any file goes in a `use` statement at the top.

## Folder Structure

```
app/
├── Http/
│   ├── Controllers/    // thin controllers only
│   ├── Requests/       // FormRequest validation classes
│   ├── Resources/      // API Resources
│   └── Middleware/
├── Models/
├── Services/           // ALL business logic (incl. AgentService for Claude tool-calling)
└── Traits/
    └── ApiResponse.php // sendSuccess / sendError
```

## Domain

Businesses → Services (offerings) + Working Hours → Availability Slots → Bookings (status: Pending → Confirmed → Completed / Cancelled, transitions enforced in Service class). Conversations → Messages (chat widget ↔ Claude agent; the agent books via tool-calling: check availability, create/reschedule/cancel bookings). GarageFlow integration syncs bookings into GarageFlow service jobs. Roles: admin, staff.

## Git

`main` ← `develop` ← `feature/*` branches, meaningful commit messages, PRs into develop.
