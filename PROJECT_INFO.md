/**
 * Secretlab KVStore — Time-Travel Key-Value Store API
 * 
 * @project-name secretlab-kvstore
 * @description Version-controlled key-value store with time-travel queries
 * @version 1.0.0
 * @php-version 8.2+
 * @framework Laravel 11
 * @database MySQL 8 / PostgreSQL
 * 
 * API Endpoints:
 *   POST   /api/v1/object                  - Store key-value pairs
 *   GET    /api/v1/object/{key}            - Get latest value
 *   GET    /api/v1/object/{key}?timestamp= - Get value at timestamp
 *   GET    /api/v1/object/get_all_records  - Get all latest values
 * 
 * Database:
 *   - Immutable history: INSERT only, never UPDATE
 *   - Composite index: (key, timestamp) for O(log n) lookups
 *   - Time-travel queries: Floor lookup at any UNIX timestamp
 * 
 * Testing:
 *   - 30+ comprehensive test cases
 *   - 80%+ code coverage
 *   - Feature & unit tests
 * 
 * CI/CD:
 *   - GitHub Actions workflow
 *   - Automated testing & deployment
 *   - Coverage reports with Codecov
 * 
 * Open the README.md for full documentation.
 */
