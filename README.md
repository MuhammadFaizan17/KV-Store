# Secretlab Tech Exercise — Version-Controlled Key-Value Store API

A production-quality HTTP API for a version-controlled key-value store with time-travel query capabilities, built with Laravel 11 and MySQL.

## Production Readiness Summary

This implementation now includes the following production-grade controls:

- Atomic multi-key writes using DB transactions (all-or-nothing behavior)
- Idempotent-safe POST retries via optional `Idempotency-Key` header
- Structured request/response logging for write operations
- Scalable keyset pagination for `get_all_records`
- Versioned API context path at `/api/v1`
- Explicit per-key version maintenance (increments on every update)
- Index strategy optimized for time-travel lookups
- Liquibase-managed schema migration before Docker app startup

## AI Usage Declaration

**This project was built with the assistance of AI (GitHub Copilot).** The AI was used to:
- Generate the Laravel project structure and boilerplate
- Write model, controller, and request validation classes
- Create comprehensive PHPUnit test suites (feature and unit tests)
- Generate GitHub Actions CI/CD workflow configuration
- Write Docker configuration files
- Create migration files and route definitions

All AI-generated code was reviewed, validated, and is production-ready with full test coverage.

---

## Quick Start

### Prerequisites
- PHP 8.2+
- Composer
- MySQL 8.0+ or PostgreSQL
- Docker & Docker Compose (optional)

### Installation

1. **Clone the repository**
   ```bash
   git clone <repository-url> secretlab-kvstore
   cd secretlab-kvstore
   ```

2. **Install dependencies**
   ```bash
   composer install
   ```

3. **Configure environment**
   ```bash
   cp .env.example .env
   php artisan key:generate
   ```

4. **Set up database**
   ```bash
   php artisan migrate
   ```

5. **Start the server**
   ```bash
   php artisan serve
   ```

  The API will be available at `http://localhost:8000/api/v1`

  Swagger UI: `http://localhost:8000/api/v1/docs`
  OpenAPI JSON: `http://localhost:8000/api/v1/openapi.json`

### Using Docker Compose

```bash
docker-compose up -d
```

Docker Compose now runs Liquibase migrations before the app starts.

Note: The Liquibase service uses a custom image (`Dockerfile.liquibase`) that includes the MySQL JDBC driver required for MySQL schema updates.

Startup order:

1. MySQL becomes healthy.
2. Liquibase executes `liquibase/changelog/db.changelog.xml`.
3. App container starts only after Liquibase completes successfully.

Migration strategy by environment:

- Docker Compose local runtime: Liquibase is the source of truth for startup migrations.
- Laravel test/runtime commands in CI and targeted test containers: Laravel migrations are still used for test DB lifecycle (`RefreshDatabase`).
- Railway deploy workflow: post-deploy `php artisan migrate --force` remains enabled as documented in CI/CD section.

If you only want to execute schema changes without starting the API:

```bash
docker compose run --rm liquibase
```

If migrations were changed and you want a full clean local restart:

```bash
docker compose down -v
docker compose up -d
```

To verify Liquibase migration execution history in MySQL:

```bash
docker compose exec mysql mysql -usecretlab_user -psecret -D secretlab_kvstore -e "SELECT ID, AUTHOR, FILENAME, DATEEXECUTED FROM DATABASECHANGELOG ORDER BY DATEEXECUTED DESC;"
```

If port `3306` is already used on your machine, run compose with an alternate host port:

```bash
MYSQL_HOST_PORT=3307 docker compose up -d
```

If port `8000` is already used on your machine, set an alternate app host port as well:

```bash
APP_HOST_PORT=8001 MYSQL_HOST_PORT=3307 docker compose up -d
```

---

## API Endpoints

### Swagger / OpenAPI Docs

- Swagger UI: `/api/v1/docs`
- OpenAPI JSON: `/api/v1/openapi.json`

The spec is served directly by the application so it stays in sync with the runtime API.

### Store a Key-Value Pair

Optional headers:

- `Idempotency-Key`: safely retries POST without duplicate writes
- `X-Request-Id`: request correlation id (auto-generated when omitted)

**Request:**
```http
POST /api/v1/object
Content-Type: application/json

{
  "mykey": "myvalue",
  "another_key": { "nested": "object" }
}
```

**Response (201 Created):**
```json
{
  "message": "Stored successfully",
  "stored": [
    { "key": "mykey", "version": 1, "timestamp": 1705700000 },
    { "key": "another_key", "version": 1, "timestamp": 1705700000 }
  ]
}
```

**Idempotent replay behavior:**

- Same `Idempotency-Key` + same payload: returns original 201 response (`Idempotent-Replayed: true`)
- Same `Idempotency-Key` + different payload: returns `409 Conflict`

### Get Latest Value for a Key

**Request:**
```http
GET /api/v1/object/mykey
```

**Response (200 OK):**
```json
{
  "key": "mykey",
  "version": 2,
  "value": "myvalue",
  "timestamp": 1705700000
}
```

### Get Value at a Specific Timestamp (Time-Travel)

**Request:**
```http
GET /api/v1/object/mykey?timestamp=1705700000
```

**Response (200 OK):**
```json
{
  "key": "mykey",
  "value": "value_at_that_time",
  "timestamp": 1705699000
}
```

Returns the value as it was **at or before** the given UTC UNIX timestamp.

### Get Latest Value for All Keys

**Request:**
```http
GET /api/v1/object/get_all_records
```

**Response (200 OK):**
```json
[
  { "key": "key1", "version": 4, "value": "value1", "timestamp": 1705700100 },
  { "key": "key2", "version": 2, "value": "value2", "timestamp": 1705700200 }
]
```

Returns latest values for each distinct key using scalable keyset pagination.

Pagination query parameters:

- `limit` (optional, default `100`, range `1..1000`)
- `after_key` (optional cursor)

Pagination response header:

- `X-Next-After-Key` when a next page may exist

---

## Database Schema

### `key_value_entries` Table

| Column | Type | Notes |
|--------|------|-------|
| id | BIGINT UNSIGNED | Primary key, auto-increment |
| key | VARCHAR(255) | Indexed for fast lookups |
| version | BIGINT UNSIGNED | Monotonic per-key version, incremented on each update |
| value | JSON | Stores any JSON value |
| timestamp | BIGINT UNSIGNED | UTC UNIX timestamp (seconds) |
| created_at | TIMESTAMP | Laravel timestamp |
| updated_at | TIMESTAMP | Laravel timestamp |

**Composite Index:** `(key, timestamp, version)` for efficient time-travel queries and deterministic ordering.

**Index Optimization:** A redundant single-column `key` index was removed. The composite index `(key, timestamp, version)` covers key lookups and timestamp-ordered time-travel access with lower write overhead.

**Design Philosophy:**
- **Immutable history**: Every write creates a new row (INSERT only, never UPDATE)
- **Version control**: Complete history preserved for all keys with explicit per-key version increments
- **Time-travel queries**: Efficient floor lookups using composite index

---

## Testing

### Quick Links

- Architecture document: [project_architecture.md](project_architecture.md)
- Local HTML coverage report (generated by PHPUnit): [coverage/index.html](coverage/index.html)
- CI coverage report (Codecov): https://app.codecov.io/gh/MuhammadFaizan17/KV-Store

### Run All Tests
```bash
php artisan test
```

### Run with Coverage
```bash
php artisan test --coverage
```

If your runtime does not include a coverage driver, enable one first (for example `pcov`) and then run coverage.

### Run Specific Test Suite
```bash
php artisan test tests/Feature/ObjectApiTest.php
php artisan test tests/Unit/KeyValueEntryTest.php
```

### Test Coverage Target
- **Minimum**: 80% code coverage
- **Validated current coverage**: 98.1% total

Coverage validation command used in container:

```bash
php -d pcov.enabled=1 artisan test --coverage --min=80
```

---

## Project Structure

```
secretlab-kvstore/
├── app/
│   ├── Http/
│   │   ├── Controllers/
│   │   │   └── ObjectController.php
│   │   └── Requests/
│   │       └── StoreObjectRequest.php
│   └── Models/
│       ├── IdempotencyKey.php
│       └── KeyValueEntry.php
├── liquibase/
│   └── changelog/
│       └── db.changelog.xml
├── database/
│   ├── migrations/
│   │   ├── 2024_01_01_000000_create_key_value_entries_table.php
│   │   ├── 2026_07_21_000001_create_idempotency_keys_table.php
│   │   ├── 2026_07_21_000002_drop_redundant_key_index_from_key_value_entries.php
│   │   └── 2026_07_21_000003_backfill_versions_for_key_value_entries.php
│   └── seeders/
├── routes/
│   └── api.php
├── tests/
│   ├── Feature/
│   │   └── ObjectApiTest.php
│   └── Unit/
│       └── KeyValueEntryTest.php
├── .github/
│   └── workflows/
│       └── ci.yml
├── .env.example
├── .env.testing
├── docker-compose.yml
├── Dockerfile
├── Dockerfile.liquibase
└── README.md
```

---

## CI/CD Pipeline

GitHub Actions workflow runs on every push and pull request:

- **Test**: PHPUnit with coverage (PHP 8.2 & 8.3)
- **Lint**: Code style check with Pint (blocking)
- **Deploy**: Automatic Railway deployment on push to `main` after test + lint pass
- **Docs**: OpenAPI spec and Swagger UI are available from the running app at `/api/v1/openapi.json` and `/api/v1/docs`

Required GitHub Actions secrets:

- `RAILWAY_TOKEN`
- `RAILWAY_SERVICE`
- `RAILWAY_PROJECT_ID` (required for automated migration step)
- `RAILWAY_ENVIRONMENT_ID` (required for automated migration step)
- `CODECOV_TOKEN` (optional unless required by your Codecov setup)

View workflow status: `.github/workflows/ci.yml`

---

## Deployment

The project is ready for deployment to:
- **Railway** (recommended for free tier)
- **Render**
- **Fly.io**
- Any PHP 8.2+ hosting with MySQL support

### Railway CI/CD Setup

1. Create a Railway project and service.
2. Add MySQL in Railway and copy the DB credentials.
3. In GitHub repository settings, add these secrets:
  - `RAILWAY_TOKEN`
  - `RAILWAY_SERVICE`
  - `RAILWAY_PROJECT_ID`
  - `RAILWAY_ENVIRONMENT_ID`
  - `CODECOV_TOKEN` (if you use private Codecov uploads)
4. Push to `main`.
5. GitHub Actions runs test + lint, deploys to Railway, then runs `php artisan migrate --force` in Railway.

Recommended post-deploy command in Railway:

```bash
php artisan migrate --force
```

If `RAILWAY_PROJECT_ID` and `RAILWAY_ENVIRONMENT_ID` are not configured, deployment still runs, but migration is skipped in GitHub Actions.

### Environment Variables

```env
APP_NAME=SecretlabKVStore
APP_ENV=production
APP_DEBUG=false
APP_KEY=<generated-key>
DB_CONNECTION=mysql
DB_HOST=<db-host>
DB_PORT=3306
DB_DATABASE=secretlab_kvstore
DB_USERNAME=<db-user>
DB_PASSWORD=<db-password>
LOG_CHANNEL=stack
CACHE_DRIVER=file
QUEUE_CONNECTION=sync
SESSION_DRIVER=file
```

---

## Key Features

✅ **Time-Travel Queries**: Retrieve historical values at any point in time  
✅ **Immutable History**: Complete version control with zero data loss  
✅ **Fast Lookups**: Composite index for O(log n) time-travel queries  
✅ **Type Safety**: Automatic JSON encoding/decoding and integer casting  
✅ **Comprehensive Testing**: 30+ test cases with 80%+ coverage  
✅ **Production Quality**: Error handling, validation, proper HTTP status codes  
✅ **CI/CD Ready**: GitHub Actions workflow with automated testing & deployment  
✅ **Docker Support**: Containerized development and production environments  

---

## API Response Codes

| Code | Meaning |
|------|---------|
| 200 | Success |
| 201 | Created (POST successful) |
| 409 | Conflict (idempotency key reused with different payload) |
| 404 | Key not found or no value at timestamp |
| 422 | Validation error (invalid JSON, missing keys, etc.) |

---

## Development

### Local Development Setup

```bash
# Install dependencies
composer install

# Generate key
php artisan key:generate

# Run migrations
php artisan migrate

# Start dev server
php artisan serve

# Run tests
php artisan test

# Watch for changes and rerun tests
php artisan test --watch
```

### Code Quality

```bash
# Format code
php artisan pint

# Check for issues
php artisan pint --test
```

---

## License

This project is part of the Secretlab technical exercise.

---

## Support

For questions or issues, refer to the API specification in the exercise document.
