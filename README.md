# LOT / LIVE — Real-Time Auction Platform

LOT / LIVE is a real-time auction platform built for concurrent bidding.
It contains:

- A Laravel 13 API
- Laravel Sanctum token authentication
- MySQL persistence
- A React and TypeScript frontend
- Transaction-safe proxy bidding
- Auction scheduling and automatic closing
- Anti-sniping extensions
- Bid history and audit events

The backend is authoritative. The browser only displays auction state and
sends requests; it never decides whether a bid is valid or who wins.

## Technical evaluation guide

This section maps the assignment evaluation criteria to the implementation.

| Evaluation area | How this project achieves it |
| --- | --- |
| Correctness of auction logic | `AuctionService` validates lifecycle, bid amount, dynamic increment, reserve, anti-sniping, closing, and winner selection in one server-side domain service. |
| Concurrency and race conditions | MySQL transactions plus `lockForUpdate()` lock the auction and proxy rows before recalculating price and leader. |
| Automatic bidding algorithm | Private proxy maximums are stored in `proxy_bids`; the public price is only the minimum needed to beat the second-highest maximum and is capped at the leader's maximum. |
| Database design and consistency | Foreign keys, decimal money columns, unique auction/idempotency keys, immutable bid records, proxy records, and audit events are defined by migrations. |
| Backend architecture | Controllers handle HTTP validation/authentication; `AuctionService` owns auction rules; Eloquent models own relationships/casts; the scheduler handles lifecycle jobs. |
| Real-time implementation | The React client polls the selected auction every three seconds and refreshes the lot list every five seconds. Countdown updates locally every second. |
| React application structure | `App.tsx` contains authentication, auction list/detail state, bid flow, admin management, polling, countdown, and toast notifications; `App.css` contains responsive UI styles. |
| Error handling | Validation errors, authorization failures, invalid lifecycle actions, rejected bids, duplicate requests, and network failures return useful HTTP responses and appear as dismissible toasts. |
| Automated testing | Unit, feature, and true parallel HTTP/MySQL concurrency tests cover the core rules. Commands are listed in the testing section. |
| Security | Sanctum bearer tokens, role checks on every admin endpoint, backend bid validation, private proxy maximums, masked public bidder names, idempotency keys, and mass-assignment protection are used. |
| Code quality | Domain rules are centralized, migrations are versioned, setup is reproducible, and architecture/database diagrams are included in `docs`. |

Supporting diagrams:

- [Database ER diagram](docs/database-er.md)
- [Architecture diagram](docs/architecture.md)

## Project structure

```text
C:\auction-platform
├── backend                 Laravel API and business logic
│   ├── app\Models          Eloquent models
│   ├── app\Services        Auction and bidding rules
│   ├── database\migrations Database schema
│   ├── routes\api.php      HTTP API routes
│   └── routes\console.php  Auction scheduler
└── frontend                React and TypeScript application
    └── src\App.tsx         Main auction workflow
```

## Requirements

Install the following before starting:

- PHP 8.3 or newer
- Composer
- PHP `pdo_mysql` extension
- MySQL 8 or compatible MariaDB
- Node.js and npm

Check the PHP MySQL extension on Windows:

```powershell
php -m | Select-String pdo_mysql
```

The command must print `pdo_mysql`.

## Configuration: `.env` and `.env.example`

Laravel reads configuration from `backend/.env`.

| File | Purpose | Commit? |
| --- | --- | --- |
| `backend/.env.example` | Safe template showing required variable names and development defaults. | Yes |
| `backend/.env` | Machine-specific configuration, database credentials, and generated encryption key. | No |

Never commit `backend/.env`, `APP_KEY`, database passwords, bearer tokens, or
other secrets. Copy the example file to create the local file:

```powershell
cd C:\auction-platform\backend
Copy-Item .env.example .env
php artisan key:generate
```

The default local timezone is `Asia/Kolkata`. Keep `APP_TIMEZONE` aligned with
the timezone used when entering `datetime-local` values in the browser:

```dotenv
APP_TIMEZONE=Asia/Kolkata
```

## Backend setup

### 1. Create the MySQL database

```sql
CREATE DATABASE auction_platform
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
```

### 2. Configure `backend/.env`

Edit `C:\auction-platform\backend\.env`:

```dotenv
APP_NAME="LOT / LIVE"
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost:8000

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=auction_platform
DB_USERNAME=root
DB_PASSWORD=your_mysql_password
```

If the local MySQL user has no password, use:

```dotenv
DB_PASSWORD=
```

The application also uses the database for sessions, cache, and queues in the
default local configuration. Run migrations before starting the scheduler.

### 3. Install dependencies, configure, and migrate

```powershell
cd C:\auction-platform\backend
composer install
php artisan config:clear
php artisan migrate --seed
```

`migrate --seed` is the normal first-time setup command and preserves existing
tables/data. To completely reset a disposable local database, use
`php artisan migrate:fresh --seed`; it drops all tables, so never run it
against a shared or production database.

The seed creates these demo users:

| Role | Email | Password |
| --- | --- | --- |
| Admin | `admin@example.com` | `password` |
| Bidder | `bidder@example.com` | `password` |

These credentials are for local development only.

### 4. Start the API

```powershell
cd C:\auction-platform\backend
php artisan serve
```

The API is available at:

```text
http://localhost:8000
```

### 5. Start the scheduler

Open a second terminal:

```powershell
cd C:\auction-platform\backend
php artisan schedule:work
```

The scheduler runs every minute and:

1. Changes due `scheduled` auctions to `live`.
2. Finds live auctions whose ending time has passed.
3. Closes each expired auction.
4. Selects a winner only when the reserve price is met.
5. Stores the final result and audit events.

Without `schedule:work`, an auction can still be inspected and bids are still
validated by the API, but scheduled starts and automatic closes will not run.

## Frontend setup

```powershell
cd C:\auction-platform\frontend
npm install
npm run dev
```

Open the URL printed by Vite, normally:

```text
http://localhost:5173
```

The frontend defaults to:

```text
http://localhost:8000/api
```

If the API runs at another URL, create `frontend/.env.local`:

```dotenv
VITE_API_URL=http://127.0.0.1:8000/api
```

Restart Vite after changing it.

### Correct startup order

Use three PowerShell terminals:

```powershell
# Terminal 1: API
cd C:\auction-platform\backend
php artisan serve --host=127.0.0.1 --port=8000

# Terminal 2: lifecycle scheduler
cd C:\auction-platform\backend
php artisan schedule:work

# Terminal 3: React
cd C:\auction-platform\frontend
npm run dev
```

The scheduler is required for automatic scheduled starts and normal automatic
closing. The API also synchronizes due/expired state when auction endpoints
are accessed, but it should not replace the scheduler in a real deployment.


## Complete user workflows

### Admin workflow

1. Open the frontend.
2. Log in with `admin@example.com` and `password`.
3. Expand **Admin · Create auction**.
4. Enter:
   - Title
   - Description
   - Starting price
   - Reserve price
   - Minimum increment
   - Start date and time
   - End date and time
5. Submit **Create live lot**.
6. The backend creates the auction as:
   - `live` when the start time is now or in the past
   - `scheduled` when the start time is in the future
7. Keep `php artisan schedule:work` running for scheduled starts and closing.

The admin can select a lot to open **Admin · Manage auction**. Draft and
scheduled lots can be edited. Any non-completed lot can be cancelled,
including a live lot; cancellation is irreversible, changes the lot to
`cancelled`, records an audit event, and prevents bidding. Completed and
cancelled lots cannot be edited or cancelled.

### Bidder workflow

1. Register a new account or log in with:
   `bidder@example.com` / `password`.
2. Select an auction from **Active lots**.
3. Check the status:
   - `scheduled`: bidding is not open yet
   - `live`: bidding is open
   - `completed`: bidding is closed
4. For a live auction, enter the maximum amount you are willing to pay.
5. Select **Place maximum bid**.
6. The server validates the request and returns the accepted bid price.
7. The page refreshes auction details and bid history automatically every
   three seconds.

The maximum amount is not exposed to other bidders. They see the accepted
price, not the private maximum.

### What the countdown means

The countdown uses the auction's current `ends_at` value. A valid bid inside
the anti-sniping window can extend that ending time. When the API returns an
extension, the frontend receives the new time on its next refresh.

## Auction lifecycle

```text
DRAFT → SCHEDULED → LIVE → COMPLETED
                         ├── SOLD
                         └── RESERVE_NOT_MET

Any non-completed auction can also be CANCELLED by an operational workflow.
```

Current API-created auctions are created as `scheduled` or `live`; the
database supports the broader lifecycle for future admin workflow screens.

### Starting an auction

The scheduler changes a scheduled auction to live when:

```text
starts_at <= current server time
```

A bid request also promotes a due scheduled auction to live before validating
the bid. This protects correctness if the scheduler is briefly delayed.

### Closing an auction

Closing is performed inside a transaction with an auction row lock:

1. Reload and lock the auction.
2. Return immediately if it is already completed or cancelled.
3. Verify the server time is at or after `ends_at`.
4. Verify the reserve price against the leading bidder's private maximum
   amount. A proxy bidder can therefore meet a reserve without exposing their
   maximum or unnecessarily raising the public current price.
5. Set `winner_id` and `result`.
6. Mark the auction completed.
7. Record `AUCTION_ENDED`.
8. Record `WINNER_SELECTED` when there is a winner.

Calling close more than once is safe and does not create a second result.

## Bidding rules

### Normal bid validation

The backend calculates:

```text
minimum acceptable bid =
current price + applicable increment
```

A bid below this amount is rejected with HTTP `422`.
Bids submitted before the start or after the end are rejected with HTTP `409`.

The frontend displays the minimum information for convenience, but the backend
recalculates it inside the transaction.

### Proxy bidding

When a bidder submits a maximum amount, the server stores it privately in
`proxy_bids`.

Example:

```text
Bidder A maximum: Rs. 20,000
Bidder B maximum: Rs. 15,000
Increment:       Rs.    500
```

The resulting public price is:

```text
Rs. 15,500
```

Bidder A leads, but their `Rs. 20,000` maximum is not exposed.

When multiple automatic bidders compete:

1. Maximum values are ordered highest first.
2. The earliest maximum wins ties.
3. The leader's public price is the second-highest maximum plus the
   applicable increment.
4. The public price is capped at the leader's maximum.

### Dynamic increments

Auctions support optional `increment_rules` JSON. Example:

```json
[
  { "from": 0, "to": 10000, "increment": 100 },
  { "from": 10001, "to": 50000, "increment": 500 },
  { "from": 50001, "to": 100000, "increment": 1000 },
  { "from": 100001, "to": null, "increment": 2500 }
]
```

When no matching rule exists, `minimum_bid_increment` is used.

## Concurrency and duplicate requests

Every bid is processed by `AuctionService::placeBid` in one database
transaction:

1. The auction row is locked with `SELECT ... FOR UPDATE`.
2. The bidder's proxy row is locked or created.
3. All proxy rows for the auction are locked.
4. The winner and public price are recalculated.
5. The bid, auction update, and audit events are committed together.

This prevents two simultaneous requests from overwriting the current price,
leader, or bid history.

Clients should send an idempotency key:

```text
Idempotency-Key: 4f9c6e9e-2f85-4a9b-9d7e-example
```

The API also accepts `idempotency_key` in the JSON body. Retrying the same
key for the same auction returns the original bid rather than processing a
second bid.

## Anti-sniping

Each auction has:

- `anti_sniping_seconds`
- `extension_seconds`
- `max_extensions`
- `extensions_count`

If a valid bid arrives within the anti-sniping window and the extension limit
has not been reached:

```text
ends_at = ends_at + extension_seconds
extensions_count = extensions_count + 1
```

The server performs this inside the same bid transaction and records an
`AUCTION_EXTENDED` event. Once `extensions_count` reaches
`max_extensions`, no further extensions occur.

## Reserve prices and results

The reserve price is private from bidders.

At closing:

- If the highest private proxy maximum reaches the reserve:
  - `result = sold`
  - `winner_id` is set
- Otherwise:
  - `result = reserve_not_met`
  - `winner_id` remains null

The public current price can be lower than the reserve when the winning
bidder's private maximum meets the reserve. This is intentional proxy-bidding
behavior: a maximum of Rs. 8,000 does not automatically publish Rs. 8,000.
The closing transaction recomputes the highest proxy maximum instead of
trusting a stale cached leader.

## Authentication and authorization

Registration and login return a Sanctum bearer token:

```http
Authorization: Bearer <sanctum-token>
```

Admin-only actions:

- Create an auction
- Edit draft or scheduled auctions
- Cancel non-completed auctions
- Manually close an auction

Bidder actions:

- View auctions
- View auction detail and public bid history
- Place proxy bids

The backend checks the authenticated user's role. Frontend visibility is only
a usability feature and is not a security boundary.

## API reference

### Public endpoints

```http
POST /api/register
POST /api/login
GET  /api/auctions
GET  /api/auctions/{auction}
```

Register request:

```json
{
  "name": "Example Bidder",
  "email": "bidder2@example.com",
  "password": "password123"
}
```

Login request:

```json
{
  "email": "bidder@example.com",
  "password": "password"
}
```

### Authenticated endpoints

```http
POST /api/logout
POST /api/auctions
PATCH /api/auctions/{auction}
POST /api/auctions/{auction}/bids
POST /api/auctions/{auction}/cancel
POST /api/auctions/{auction}/close
POST /api/auctions/{auction}/close
```

Create auction request:

```json
{
  "title": "Vintage watch",
  "description": "Mechanical wristwatch in excellent condition.",
  "starting_price": 10000,
  "reserve_price": 15000,
  "minimum_bid_increment": 500,
  "starts_at": "2026-10-13T10:00:00+05:30",
  "ends_at": "2026-10-13T18:00:00+05:30",
  "anti_sniping_seconds": 120,
  "extension_seconds": 120,
  "max_extensions": 3
}
```

Place bid request:

```json
{
  "maximum_amount": 20000,
  "idempotency_key": "unique-client-request-id"
}
```

Important responses:

| Status | Meaning |
| --- | --- |
| `200` | Request succeeded |
| `201` | User or auction created |
| `401` | Missing or invalid bearer token |
| `403` | Authenticated user lacks the required role |
| `404` | Auction does not exist |
| `409` | Auction is not currently bid-able or has not reached close time |
| `422` | Validation failure or bid below the minimum |

## Database model

The source diagrams are available in [docs/database-er.md](docs/database-er.md)
and [docs/architecture.md](docs/architecture.md).

```text
users
  ├── auctions.leading_bidder_id
  ├── auctions.winner_id
  ├── bids.user_id
  └── proxy_bids.user_id

auctions
  ├── bids.auction_id
  ├── proxy_bids.auction_id
  └── auction_events.auction_id
```

Main tables:

- `users`: accounts and roles (`admin`, `bidder`)
- `auctions`: lifecycle, prices, timing, leader, winner, and result
- `bids`: immutable public accepted bid records
- `proxy_bids`: private bidder maximums
- `auction_events`: audit trail of important auction actions

## Audit events

The backend records events including:

- `AUCTION_STARTED`
- `BID_PLACED`
- `BID_REJECTED`
- `AUTO_BID_PLACED`
- `LEADER_CHANGED`
- `AUCTION_EXTENDED`
- `AUCTION_ENDED`
- `WINNER_SELECTED`

The event table is intended to explain how an auction reached its final state.
Bidder names are masked in the public bid history.

## Real-time updates

The current frontend uses polling instead of requiring a separate WebSocket
server:

1. The selected auction is loaded from `GET /api/auctions/{id}`.
2. The frontend refreshes it every three seconds.
3. The current price, leader state, ending time, status, and bid history update.
4. A one-second local timer updates the countdown display.

This keeps local setup simple while preserving a server-authoritative design.
Laravel broadcasting or WebSockets can be added later using the existing
auction events without changing the bidding transaction.

## Testing and validation

### Test matrix

| Test area | Location | What it verifies |
| --- | --- | --- |
| Increment calculation | `tests/Unit/AuctionServiceTest.php` | Price-tier selection and fallback increment |
| Proxy bidding | `tests/Feature/AuctionBiddingTest.php` | Private maximums, competing bidders, public price, reserve, winner, idempotency, closing, and anti-sniping |
| HTTP concurrency | `tests/Concurrency/ConcurrentBiddingHttpTest.php` | Three simultaneous real HTTP requests against MySQL, deterministic leader, accepted/rejected count, and row consistency |

Run the non-database unit suite:

```powershell
cd C:\auction-platform\backend
php artisan test --testsuite=Unit
```

The feature tests use `RefreshDatabase` and require a database driver. The
default `phpunit.xml` is configured for an in-memory SQLite database, so
install/enable `pdo_sqlite`, or create a separate PHPUnit configuration that
points to an isolated MySQL test database. Never point `RefreshDatabase` tests
at a shared or production database.

Run frontend checks:

```powershell
cd C:\auction-platform\frontend
npm run lint
npm run build
```

For a clean local database:

```powershell
cd C:\auction-platform\backend
php artisan migrate:fresh --seed
```

Warning: `migrate:fresh` deletes all tables and data in the configured
database. Use it only with a local development database.

Integration and concurrency tests should use an isolated MySQL database.
SQLite is not suitable for validating MySQL row-lock behavior.

### True parallel HTTP concurrency test (MySQL)

The repository includes `tests/Concurrency/ConcurrentBiddingHttpTest.php`.
Unlike an in-process unit test, it starts three authenticated HTTP requests at
the same time with `curl_multi_*` while Laravel uses MySQL transactions and
row locks.

1. Create a separate empty MySQL database (for example
   `auction_platform_concurrency`) and configure the normal backend `.env`
   with its host, port, username, password, and database name.
2. Start the API from `C:\auction-platform\backend`:

   ```powershell
   php artisan migrate:fresh --seed
   php artisan serve --host=127.0.0.1 --port=8000
   ```

3. In another PowerShell window, run the dedicated suite. The test runner
   migrates the same MySQL database and expects the running API to use it:

   ```powershell
   cd C:\auction-platform\backend
   $env:CONCURRENCY_TEST_URL = "http://127.0.0.1:8000"
   php artisan test --configuration phpunit.mysql.xml
   ```

The test submits maximum bids of Rs. 51,000, Rs. 51,500, and Rs. 51,000
against a Rs. 50,000 auction. It verifies that exactly two requests are
accepted, one is rejected by the authoritative minimum, the final price is
Rs. 51,500, there is one deterministic leader, and only two proxy/bid rows
exist. Do not run this suite against a shared or production database because
the test uses `RefreshDatabase`.

## Security checklist

For local setup:

- Keep `backend/.env` out of version control.
- Generate a unique `APP_KEY` with `php artisan key:generate`.
- Use a dedicated MySQL user/database instead of a production account.
- Use demo credentials only for local development.

At runtime:

- Login/register issue Laravel Sanctum tokens.
- Protected routes use `auth:sanctum`.
- Admin actions verify `users.role = admin` on the backend.
- Bid correctness never depends on React validation.
- Proxy maximums are not returned in public auction or bid-history responses.
- Public bid history masks bidder names.
- Duplicate requests are controlled by a unique auction/idempotency key.
- Money is stored as fixed-precision decimal values, not floating-point
  database columns.
- Production deployments should set `APP_DEBUG=false`, use HTTPS, rotate
  secrets, restrict CORS, and use a least-privilege database account.

## Code quality and maintainability

- Keep auction rules in `backend/app/Services/AuctionService.php`; controllers
  should remain HTTP/authentication adapters.
- Add or update a focused test whenever a bidding rule changes.
- Run `php -l` on changed PHP files, backend tests, `npm run lint`, and
  `npm run build` before submitting changes.
- Keep migrations backward-compatible for deployed databases and do not edit
  an already-applied migration to change production data.
- Use audit events to explain state transitions instead of relying only on the
  current auction row.

## Troubleshooting

### `could not find driver`

Check `.env` first. For this project it must contain:

```dotenv
DB_CONNECTION=mysql
```

Then verify:

```powershell
php -m | Select-String pdo_mysql
```

Clear cached configuration:

```powershell
php artisan config:clear
```

### `Call to undefined method User::createToken()`

The `User` model must use Sanctum's `HasApiTokens` trait. This is already
configured in `backend/app/Models/User.php`.

### Admin cannot create an auction

Log in with `admin@example.com`. The backend checks `users.role = admin`;
changing only the frontend is not sufficient.

### Bid button says `Bidding opens soon`

The auction is `scheduled`. Wait until `starts_at`, keep
`php artisan schedule:work` running, and refresh the auction.

### Auction does not close automatically

Make sure the scheduler is running:

```powershell
php artisan schedule:work
```

Also verify that the server clock and `starts_at` / `ends_at` timezone values
are correct. The default application timezone is `Asia/Kolkata`, configured
with `APP_TIMEZONE` in `backend/.env`. The frontend `datetime-local` fields
use this local timezone; do not append `Z` to those values. After changing
`APP_TIMEZONE`, run `php artisan config:clear` and restart the Laravel server.
#   l i v e - a u c t i o n - p l a t f o r m  
 