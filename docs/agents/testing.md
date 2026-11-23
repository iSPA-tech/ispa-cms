# Testing

Read before running or writing tests. Run only the files you changed or added, then their folder if needed. A full
run of every suite is slow and rarely needed for one change.

## Run

```bash
./vendor/bin/pest cms/Content/tests/Unit/ContentDependentsDeclareTest.php     # one file
./vendor/bin/pest cms/Core/tests/Feature/Modules --filter=ContentContract       # one folder, filtered
./vendor/bin/pest tests/Unit                                                    # root unit tests
php -d memory_limit=256M vendor/bin/pest cms/Widget/tests/Unit                  # Widget needs more memory
pnpm --dir admin exec vitest run src/__tests__/components/i-box.spec.ts         # one admin spec
```

Suites: `tests/` (root), `cms/Core/tests`, `cms/Content/tests`, `cms/Widget/tests`, and the admin specs
(`admin/src/__tests__/`, `admin/tests/`). The root `phpunit.xml` lists only `tests/Unit` and `tests/Feature`; pass a
path to run module tests.

## Test environment

- `phpunit.xml`: `APP_ENV=testing`, SQLite in memory, `MODULES_ACTIVATOR=file`, array cache, mail and session, `sync`
  queue, fixed test keys for `ISPA_API_AUTH_KEY` and `ISPA_ADMIN_AUTH_KEY`.
- No .env needed: `tests/CreatesApplication.php` loads `tests/Fixtures/testing.env` when .env is missing.
- Each test process works on its own copy of `modules_statuses.json` (under storage/framework/testing), so tests
  may enable or disable modules without touching the tracked file.
- Other databases: `./vendor/bin/pest -c phpunit.mysql.xml <path>` or `-c phpunit.pgsql.xml` (local servers, see the
  `DB_*` / `PTG_*` values in those files).

## Writing tests

| Where | Base class |
|---|---|
| `tests/` | `Tests\TestCase` (`tests/TestCase.php`) |
| `cms/Core/tests/` | `Cms\Core\Tests\TestCase`, applied by `cms/Core/tests/Pest.php` to `Feature`, `Unit`, `Integration` |
| `cms/Content/tests/`, `cms/Widget/tests/` | Declare it in the file: `uses(Tests\TestCase::class, RefreshDatabase::class);` or `extends TestCase` |

- A fix comes with a test that fails without it.
- Admin API tests: copy `cms/Core/resources/module-template/tests/Feature/SkeletonThingAdminApiTest.php` (signed-in
  admin, permission checks, uuid routes).
- Test a public module page on a project that does not declare it in its own routes.

## Traps that waste time

| Symptom | Cause | Fix |
|---|---|---|
| Run exits non-zero and prints changed translation files although every test passed | `tests/Support/TranslationFilesTripwire.php` hashes tracked translation files before and after the run | Something rewrote `lang/` or seeder payloads (often `cms:translation-generate` without `--public-only`). Restore the files from git; write test files under storage/framework/testing |
| Widget preview tests are skipped | They need a throwaway Redis on `127.0.0.1:6399` (databases 13 to 15, flushed) | See `cms/Widget/README.md`; never point that port at a real Redis |
| A test needing Redis fails on a machine without it | Missing skip guard | Call `Tests\Support\RedisReachable::orSkip()` first |
| Passes alone, fails in its folder | A process-wide `static` cache keeps state between tests | Scope the cache to the app instance (for example a `WeakMap` keyed by the app) |
| Facade mock answers a translation call | Translator resolved after the mock | `tests/CreatesApplication.php` resolves the translator first; do not change that order |
| "Lazy relation load" warnings in the log | `Model::preventLazyLoading()` outside production | Eager-load the relation |
| `ContentContractTest` or `ContentApiSnapshotTest` fails | Core or Content public surface changed | Regenerate and bump versions as described in `cms/Core/AGENTS.md` and `cms/Content/AGENTS.md` |
| `ModuleVersionCalVerTest` fails | A `module.json` version is not `YYYY.MM.NN` | Fix the version |
| Test writes into the repo | Code under test writes real files | Point it at a temp folder or storage/framework/testing |
