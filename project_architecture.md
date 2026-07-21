# Project Architecture

## Scope

This document captures architecture decisions, HLD, and LLD for the Secretlab version-controlled key-value API.

## Key Decisions

- API is versioned with context path `/api/v1`.
- Write model is append-only for immutable history.
- Each update increments a monotonic `version` per key.
- Multi-key POST writes are atomic using DB transactions.
- Optional idempotency support is implemented with `Idempotency-Key`.
- Time-travel reads use floor lookup (`timestamp <= requested`) with deterministic tie-break (`version DESC`, then `id DESC`).
- `get_all_records` uses keyset pagination (`limit`, `after_key`) for scalability.
- Composite index `(key, timestamp, version)` is the primary query index; redundant single-column key index is removed.
- Structured logs are emitted for store request/response and idempotency conflicts.
- Docker startup runs Liquibase migrations before app boot (MySQL health-gated).

## HLD

```mermaid
flowchart LR
    C[Client Apps] --> G[HTTP API Gateway Layer\nLaravel Router /api/v1]
    G --> O[ObjectController]
    G --> D[OpenApiController]

    O --> V[StoreObjectRequest Validation]
    O --> I[IdempotencyKey Store]
    O --> K[KeyValueEntry Model]

    K --> DB[(MySQL/PostgreSQL/SQLite)]
    I --> DB

    DB -. startup dependency .-> LB[Liquibase Service]
    LB -. completes before app start .-> G

    O --> L[Structured Logging]
    D --> S[OpenAPI JSON + Swagger UI]
```

## LLD: POST /api/v1/object

```mermaid
sequenceDiagram
    autonumber
    participant Client
    participant Router
    participant RequestValidator as StoreObjectRequest
    participant Controller as ObjectController
    participant DB
    participant Idem as idempotency_keys

    Client->>Router: POST /api/v1/object + JSON body + optional Idempotency-Key
    Router->>RequestValidator: Validate JSON object + key constraints
    RequestValidator-->>Controller: Validated payload

    Controller->>DB: BEGIN TRANSACTION
    alt Idempotency-Key provided
        Controller->>Idem: SELECT ... FOR UPDATE by key
        alt Existing key with same payload hash
            Idem-->>Controller: existing response
            Controller->>DB: COMMIT
            Controller-->>Client: 201 + Idempotent-Replayed:true
        else Existing key with different hash
            Controller->>DB: COMMIT
            Controller-->>Client: 409 Conflict
        else No existing key
            Controller->>DB: SELECT MAX(version) FOR UPDATE by key
            Controller->>DB: INSERT key_value_entries (append-only, version = max+1)
            Controller->>Idem: INSERT idempotency record
            Controller->>DB: COMMIT
            Controller-->>Client: 201 Created
        end
    else No Idempotency-Key
        Controller->>DB: SELECT MAX(version) FOR UPDATE by key
        Controller->>DB: INSERT key_value_entries (append-only, version = max+1)
        Controller->>DB: COMMIT
        Controller-->>Client: 201 Created
    end
```

## LLD: GET /api/v1/object/get_all_records

```mermaid
flowchart TD
    A[Receive request] --> B{Validate limit 1..1000?}
    B -- no --> E[Return 422]
    B -- yes --> C[Build latest-per-key subquery\nROW_NUMBER PARTITION BY key]
    C --> D{after_key provided?}
    D -- yes --> F[Apply key > after_key]
    D -- no --> G[No cursor filter]
    F --> H[Apply ORDER BY key + LIMIT]
    G --> H
    H --> I[Return JSON array]
    I --> J{count == limit?}
    J -- yes --> K[Set X-Next-After-Key header]
    J -- no --> L[No pagination header]
```

## Data Model

### key_value_entries

- `id` bigint PK
- `key` varchar(255)
- `version` unsigned bigint (monotonic per key)
- `value` json
- `timestamp` unsigned bigint (UTC UNIX seconds)
- `created_at`, `updated_at`
- Index: `(key, timestamp, version)`

### idempotency_keys

- `id` bigint PK
- `key` varchar(255) UNIQUE
- `request_hash` char(64)
- `status_code` unsigned smallint
- `response_body` json
- `created_at`, `updated_at`
- Index: `created_at`

### liquibase metadata tables (managed by Liquibase)

- `DATABASECHANGELOG`
- `DATABASECHANGELOGLOCK`

## Non-Functional Alignment

- Thread safety: transactional writes + row-level lock (`SELECT ... FOR UPDATE`) on idempotency key.
- Logging: request-level and idempotency conflict logs with correlation ids.
- Scalability: keyset pagination and bounded page size.
- Idempotency: safe retriable writes when clients provide idempotency keys.
- Reliability: comprehensive feature/unit tests with high coverage.

## Startup Migration Orchestration (Docker)

```mermaid
flowchart TD
    A[docker compose up] --> B[Start mysql service]
    B --> C{mysql healthy?}
    C -- no --> B
    C -- yes --> D[Run liquibase update]
    D --> E{Liquibase success?}
    E -- no --> F[Stop app startup and fail deployment]
    E -- yes --> G[Start Laravel app service]
    G --> H[Serve /api/v1 endpoints]
```

## Runtime Flows

### Flow A: Insert or Update via POST /api/v1/object

Notes:

- "Insert" means first write for a key (version starts at 1).
- "Update" means write same key again (version increments by 1).
- Both paths use the same endpoint and same transaction flow.

```mermaid
sequenceDiagram
    autonumber
    participant Client
    participant Router
    participant Validator as StoreObjectRequest
    participant Controller as ObjectController
    participant Idem as idempotency_keys
    participant KV as key_value_entries
    participant DB
    participant Logs

    Client->>Router: POST /api/v1/object
    Router->>Validator: Validate JSON object + key constraints
    alt Validation fails
        Validator-->>Client: 422
    else Validation passes
        Validator-->>Controller: payload
        Controller->>Logs: kvstore.store.request
        Controller->>DB: BEGIN TRANSACTION

        alt Idempotency-Key provided
            Controller->>Idem: SELECT by key FOR UPDATE
            alt Existing key + different payload hash
                Controller->>Logs: kvstore.store.idempotency_conflict
                Controller->>DB: COMMIT
                Controller-->>Client: 409 Conflict
            else Existing key + same payload hash
                Controller->>DB: COMMIT
                Controller-->>Client: 201 + Idempotent-Replayed:true
            else No existing idempotency row
                loop For each key in payload
                    Controller->>KV: SELECT MAX(version) FOR UPDATE by key
                    Controller->>KV: INSERT row with version=max+1
                end
                Controller->>Idem: INSERT response snapshot
                Controller->>DB: COMMIT
                Controller->>Logs: kvstore.store.response
                Controller-->>Client: 201 + Idempotent-Replayed:false
            end
        else No Idempotency-Key
            loop For each key in payload
                Controller->>KV: SELECT MAX(version) FOR UPDATE by key
                Controller->>KV: INSERT row with version=max+1
            end
            Controller->>DB: COMMIT
            Controller->>Logs: kvstore.store.response
            Controller-->>Client: 201
        end
    end
```

### Flow B: Get Latest via GET /api/v1/object/{key}

```mermaid
sequenceDiagram
    autonumber
    participant Client
    participant Router
    participant Controller as ObjectController
    participant Model as KeyValueEntry
    participant DB

    Client->>Router: GET /api/v1/object/{key}
    Router->>Controller: show(key)
    Controller->>Model: latest(key)
    Model->>DB: WHERE key=? ORDER BY version DESC, timestamp DESC, id DESC LIMIT 1
    alt Row found
        DB-->>Controller: latest row
        Controller-->>Client: 200 {key, version, value, timestamp}
    else Not found
        DB-->>Controller: null
        Controller-->>Client: 404
    end
```

### Flow C: Time-Travel via GET /api/v1/object/{key}?timestamp=T

```mermaid
sequenceDiagram
    autonumber
    participant Client
    participant Router
    participant Controller as ObjectController
    participant Model as KeyValueEntry
    participant DB

    Client->>Router: GET /api/v1/object/{key}?timestamp=T
    Router->>Controller: show(key, timestamp)
    alt timestamp invalid
        Controller-->>Client: 422
    else timestamp valid
        Controller->>Model: atTimestamp(key, T)
        Model->>DB: WHERE key=? AND timestamp<=T ORDER BY timestamp DESC, version DESC, id DESC LIMIT 1
        alt Row found
            DB-->>Controller: historical row
            Controller-->>Client: 200 {key, version, value, timestamp}
        else Not found
            DB-->>Controller: null
            Controller-->>Client: 404
        end
    end
```

### Flow D: Get Latest for All Keys via GET /api/v1/object/get_all_records

```mermaid
sequenceDiagram
    autonumber
    participant Client
    participant Router
    participant Controller as ObjectController
    participant Model as KeyValueEntry
    participant DB

    Client->>Router: GET /api/v1/object/get_all_records?limit=&after_key=
    Router->>Controller: getAllRecords()
    alt limit/after_key invalid
        Controller-->>Client: 422
    else valid inputs
        Controller->>Model: allLatest(limit, after_key)
        Model->>DB: window query row_number() over(partition by key order by version desc, timestamp desc, id desc)
        Model->>DB: filter rn=1, apply cursor and limit
        DB-->>Controller: latest rows per key page
        alt page size equals limit
            Controller-->>Client: 200 + X-Next-After-Key
        else final page
            Controller-->>Client: 200
        end
    end
```
