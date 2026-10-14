# Public `/play/` Deployment Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Publish the Blazor PWA and private PHP/MySQL backend at `https://blood.trickypig.com/play/` without changing or endangering the existing static site.

**Architecture:** Build the PWA for `/play/`, expose one PHP gateway beneath `/play/api/`, and keep API source, migrations, scenarios, and `.env` outside the document root. A dry-run-first PowerShell deployment stages and validates both halves, runs explicit MySQL migrations, switches the release, verifies it over HTTPS, and retains a code rollback target.

**Tech Stack:** .NET 10 Blazor WebAssembly PWA, dependency-free PHP 8.1+ (PHP 8.4 on IONOS), PDO/MySQL, Apache `.htaccess`, PowerShell, WSL `rsync`/SSH.

**Spec:** `docs/superpowers/specs/2026-10-13-public-play-deployment-design.md`

## Global Constraints

- Preserve every existing static page and asset; the game owns only `blood/public/play/` and `blood/server/api/`.
- Keep `blood/.env` outside the document root with mode `600`; never upload, overwrite, download, or print it.
- Use `php8.4-cli` remotely because the host's unversioned `php` command is PHP 4.4.
- Keep PHP dependency-free.
- Use `/play/` for the PWA base, manifest scope, service-worker scope, and default API base.
- Preserve the application route contract `/api/v1/...`; normalize only the public `/play` prefix.
- Make deployment a dry run unless `-Push` is explicitly supplied.
- Protect remote `/play/` from legacy `deploy.ps1`, including prune operations.
- Do not change the MAUI endpoint in this plan.
- Do not add application-level rate limiting, email verification, password recovery, or automated retention jobs in this deployment.
- Stop after the first production dry run and obtain explicit user authorization before changing `.env` permissions or pushing.

## Review Focus

- `.env` values containing `=`, `#`, spaces, or matching quotes must round-trip exactly; Task 1 adds parser tests.
- Missing configuration, invalid drivers, short signing keys, and PDO failures must fail closed without exposing secrets; Tasks 1 and 4 test this.
- `/play/api/...` must route before the SPA fallback, while `/playground/api/...` must not be normalized; Tasks 2 and 4 test this.
- A service worker must resolve assets beneath its own `/play/` scope and never cache API traffic; Task 3 tests this.
- Legacy prune and failed activation must leave `/play/` and the previous release recoverable; Tasks 5 and 6 test this.

---

### Task 1: Production configuration and MySQL connection

**Files:**
- Create: `api/src/Config/AppConfig.php`
- Create: `api/tests/ConfigTest.php`
- Modify: `api/src/Database/ConnectionFactory.php`
- Modify: `api/tests/run.php`
- Modify: `api/.env.example`

**Interfaces:**
- Consumes: `ConnectionFactory::sqliteMemory()` and the approved `.env` key contract.
- Produces: `AppConfig::fromFile(string $path): AppConfig`, `AppConfig::pdoDsn(): string`, readonly configuration properties, and `ConnectionFactory::fromConfig(AppConfig $config): PDO`.

- [ ] **Step 1: Write failing configuration tests**

Create `config_tests()` and register it in `api/tests/run.php`. Include valid MySQL and SQLite files, missing keys, unsupported drivers, short signing keys, and literal punctuation:

```php
'production env preserves secret punctuation' => static function (): void {
    $path = write_test_env(<<<'ENV'
DB_DRIVER=mysql
DB_HOST=db.example.test
DB_NAME=blood
DB_USER=banner
DB_PASS=p@ss#word=with spaces
APP_SIGNING_KEY=0123456789abcdef0123456789abcdef
APP_ENV=production
CORS_ORIGIN=https://blood.trickypig.com
ENV);
    try {
        $config = AppConfig::fromFile($path);
        expect_same('p@ss#word=with spaces', $config->dbPassword, 'password changed');
        expect_same('mysql:host=db.example.test;dbname=blood;charset=utf8mb4', $config->pdoDsn(), 'DSN mismatch');
    } finally {
        unlink($path);
    }
},
'configuration errors redact secrets' => static function (): void {
    $secret = 'visible-nowhere-secret';
    $path = write_test_env("DB_DRIVER=oracle\nDB_PASS={$secret}\nAPP_SIGNING_KEY=short\n");
    try {
        AppConfig::fromFile($path);
        throw new RuntimeException('invalid configuration was accepted');
    } catch (RuntimeException $error) {
        expect_true(!str_contains($error->getMessage(), $secret), 'DB_PASS leaked');
    } finally {
        unlink($path);
    }
},
```

- [ ] **Step 2: Run the PHP suite and confirm failure**

Run: `php api/tests/run.php`

Expected: FAIL because `BloodAndBanners\Config\AppConfig` does not exist.

- [ ] **Step 3: Implement the configuration object and connection factory**

Parse one `KEY=value` per line by splitting on only the first `=`. Ignore blank lines and lines whose first non-whitespace character is `#`; support matching quotes around an entire value; never treat inline `#` as a comment.

```php
public static function fromFile(string $path): self
{
    if (!is_readable($path)) {
        throw new RuntimeException('Application configuration is not readable.');
    }
    $values = self::parse((string)file_get_contents($path));
    $driver = strtolower(trim($values['DB_DRIVER'] ?? ''));
    if (!in_array($driver, ['mysql', 'sqlite'], true)) {
        throw new RuntimeException('DB_DRIVER must be mysql or sqlite.');
    }
    $signingKey = $values['APP_SIGNING_KEY'] ?? '';
    if (strlen($signingKey) < 32) {
        throw new RuntimeException('APP_SIGNING_KEY must contain at least 32 bytes.');
    }
    return new self(
        dbDriver: $driver,
        dbHost: $values['DB_HOST'] ?? '',
        dbName: $values['DB_NAME'] ?? '',
        dbUser: $values['DB_USER'] ?? '',
        dbPassword: $values['DB_PASS'] ?? '',
        dbPath: $values['DB_PATH'] ?? '',
        signingKey: $signingKey,
        appEnvironment: $values['APP_ENV'] ?? 'production',
        corsOrigin: $values['CORS_ORIGIN'] ?? ''
    );
}

public function pdoDsn(): string
{
    return $this->dbDriver === 'mysql'
        ? "mysql:host={$this->dbHost};dbname={$this->dbName};charset=utf8mb4"
        : "sqlite:{$this->dbPath}";
}
```

Require `DB_HOST`, `DB_NAME`, `DB_USER`, and `DB_PASS` for MySQL and `DB_PATH` for SQLite. Add `ConnectionFactory::fromConfig()` while retaining `fromEnvironment()` for local development:

```php
public static function fromConfig(AppConfig $config): PDO
{
    $user = $config->dbDriver === 'mysql' ? $config->dbUser : null;
    $password = $config->dbDriver === 'mysql' ? $config->dbPassword : null;
    return self::configure(new PDO($config->pdoDsn(), $user, $password));
}
```

Update `.env.example` to the approved names and the example
`APP_SIGNING_KEY=replace-with-32-or-more-random-bytes`.

- [ ] **Step 4: Run the PHP suite**

Run: `php api/tests/run.php`

Expected: all configuration cases and existing tests pass with zero failures.

- [ ] **Step 5: Commit**

```powershell
git add api/src/Config/AppConfig.php api/src/Database/ConnectionFactory.php api/tests/ConfigTest.php api/tests/run.php api/.env.example
git commit -m "feat: load production API configuration"
```

### Task 2: Shared bootstrap, mounted requests, and explicit migrations

**Files:**
- Create: `api/autoload.php`
- Create: `api/src/Http/RequestFactory.php`
- Create: `api/tools/migrate.php`
- Create: `api/tests/HttpRequestFactoryTest.php`
- Modify: `api/tests/bootstrap.php`
- Modify: `api/tests/run.php`
- Modify: `api/public/index.php`
- Modify: `scripts/start-api.ps1`
- Modify: `tests/local-api-launch.ps1`

**Interfaces:**
- Consumes: Task 1 configuration, existing `Request`, `Application`, `Migrator`, and local environment variables.
- Produces: `RequestFactory::fromHttp(string $method, string $requestUri, array $headers, string $rawBody, array $query, string $mountPath = ''): Request`; `api/tools/migrate.php [--env-file PATH]`; reusable `api/autoload.php`.

- [ ] **Step 1: Write failing request-factory tests**

```php
'play mount is removed only at a boundary' => static function (): void {
    $request = RequestFactory::fromHttp('POST', '/play/api/v1/games?invite=abc', [], '{"mode":"vanillaPvp"}', ['invite' => 'abc'], '/play');
    expect_same('/api/v1/games', $request->path, 'mounted path mismatch');
    expect_same('abc', $request->query['invite'], 'query mismatch');

    $other = RequestFactory::fromHttp('GET', '/playground/api/v1/games', [], '', [], '/play');
    expect_same('/playground/api/v1/games', $other->path, 'non-boundary prefix was stripped');
},
'malformed JSON fails before dispatch' => static function (): void {
    try {
        RequestFactory::fromHttp('POST', '/play/api/v1/games', [], '{', [], '/play');
        throw new RuntimeException('malformed JSON was accepted');
    } catch (JsonException) {
    }
},
```

Register the file in `api/tests/run.php`.

- [ ] **Step 2: Run the tests and confirm failure**

Run: `php api/tests/run.php`

Expected: FAIL because `RequestFactory` is undefined.

- [ ] **Step 3: Extract autoloading and implement request construction**

Move the existing namespace autoloader into `api/autoload.php`; require it from the test bootstrap and local public entry point.

```php
$path = parse_url($requestUri, PHP_URL_PATH) ?: '/';
$mount = '/' . trim($mountPath, '/');
if ($mount !== '/' && ($path === $mount || str_starts_with($path, $mount . '/'))) {
    $path = substr($path, strlen($mount)) ?: '/';
}
$body = $rawBody === '' ? [] : json_decode($rawBody, true, flags: JSON_THROW_ON_ERROR);
return new Request($method, $path, $headers, is_array($body) ? $body : [], $query);
```

- [ ] **Step 4: Move migrations out of web requests**

Create the CLI migration entry point:

```php
require dirname(__DIR__) . '/autoload.php';
if ($argc === 3 && $argv[1] === '--env-file') {
    $connection = ConnectionFactory::fromConfig(AppConfig::fromFile($argv[2]));
} elseif ($argc === 1) {
    $connection = ConnectionFactory::fromEnvironment();
} else {
    fwrite(STDERR, "usage: php migrate.php [--env-file PATH]\n");
    exit(2);
}
(new Migrator($connection))->migrate();
fwrite(STDOUT, "Migrations complete.\n");
```

Remove migration from `api/public/index.php`, use `RequestFactory` there with no mount, and run the migration tool once from `start-api.ps1` before `php -S`. Abort startup when migration exits nonzero.

- [ ] **Step 5: Verify local migration and startup behavior**

Update `tests/local-api-launch.ps1` to select a unique temporary SQLite file through `BLOOD_BANNERS_DSN`, assert that startup creates it, and restore/delete it in `finally`.

Run:

```powershell
php api/tests/run.php
powershell -NoProfile -ExecutionPolicy Bypass -File tests/local-api-launch.ps1
```

Expected: both pass and repeated local starts do not fail migrations.

- [ ] **Step 6: Commit**

```powershell
git add api/autoload.php api/src/Http/RequestFactory.php api/tools/migrate.php api/tests/HttpRequestFactoryTest.php api/tests/bootstrap.php api/tests/run.php api/public/index.php scripts/start-api.ps1 tests/local-api-launch.ps1
git commit -m "refactor: separate API startup from migrations"
```

### Task 3: Subpath-aware PWA release build

**Files:**
- Create: `src/BloodAndBanners.Web/wwwroot/appsettings.json`
- Create: `scripts/build-game-release.ps1`
- Create: `tests/game-release-build.ps1`
- Modify: `src/BloodAndBanners.Web/wwwroot/service-worker.published.js`
- Modify: `src/BloodAndBanners.Web/wwwroot/manifest.webmanifest`

**Interfaces:**
- Consumes: Blazor publish output and relative `api/v1/...` client calls.
- Produces: `build-game-release.ps1 -Destination PATH [-PublishedWebRoot PATH]`, creating `PATH/public/play/` with a `/play/` base and no development settings.

- [ ] **Step 1: Write a failing fake-publish test**

Create a temporary fake publish tree, invoke the builder with `-PublishedWebRoot`, and assert:

```powershell
$index = Get-Content -Raw -LiteralPath (Join-Path $playRoot 'index.html')
if ($index -notmatch '<base href="/play/"') { throw 'base path is not /play/' }
if (Test-Path (Join-Path $playRoot 'appsettings.Development.json')) { throw 'development settings leaked' }
$manifest = Get-Content -Raw -LiteralPath (Join-Path $playRoot 'manifest.webmanifest') | ConvertFrom-Json
if ($manifest.scope -ne './' -or $manifest.start_url -ne './') { throw 'manifest scope mismatch' }
```

Scan staged text files for `http://localhost` and fail on any match.
Read the staged service worker and assert it derives `baseUrl` from
`self.registration.scope` and bypasses its cache for `${baseUrl.pathname}api/`.

- [ ] **Step 2: Run the release test and confirm failure**

Run: `powershell -NoProfile -ExecutionPolicy Bypass -File tests/game-release-build.ps1`

Expected: FAIL because the builder does not exist.

- [ ] **Step 3: Make service-worker behavior scope-relative**

```javascript
const baseUrl = new URL(self.registration.scope);
const manifestUrlList = self.assetsManifest.assets.map(asset => new URL(asset.url, baseUrl).href);
```

Construct cache requests with `new URL(asset.url, baseUrl)`. In `onFetch`, bypass the cache when the request pathname begins with `${baseUrl.pathname}api/`. Add `"scope": "./"` to the manifest and create production `appsettings.json`:

```json
{
  "ImageBaseUrl": "https://blood.trickypig.com"
}
```

- [ ] **Step 4: Implement the release builder**

Run `dotnet publish ... -c Release -o $publishDirectory` unless `-PublishedWebRoot` is supplied. Copy only `wwwroot` into `Destination/public/play`, remove `appsettings.Development.json` and `*.map`, and replace exactly one `<base href="/" />` with `<base href="/play/" />`; throw for zero or multiple matches.

- [ ] **Step 5: Run fake and real release checks**

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File tests/game-release-build.ps1
powershell -NoProfile -ExecutionPolicy Bypass -File scripts/build-game-release.ps1 -Destination "$env:TEMP\blood-game-release-check"
dotnet build src/BloodAndBanners.Web/BloodAndBanners.Web.csproj -c Release
```

Expected: tests pass, the real staged HTML uses `/play/`, and no development settings are staged. Remove only the named temporary check directory after inspection.

- [ ] **Step 6: Commit**

```powershell
git add src/BloodAndBanners.Web/wwwroot/appsettings.json src/BloodAndBanners.Web/wwwroot/service-worker.published.js src/BloodAndBanners.Web/wwwroot/manifest.webmanifest scripts/build-game-release.ps1 tests/game-release-build.ps1
git commit -m "feat: build the PWA for the play subpath"
```

### Task 4: Production gateway and Apache rules

**Files:**
- Create: `api/src/Http/Gateway.php`
- Create: `api/tests/GatewayTest.php`
- Create: `scripts/deployment/play/.htaccess`
- Create: `scripts/deployment/play/api/index.php`
- Modify: `api/tests/run.php`
- Modify: `scripts/build-game-release.ps1`
- Modify: `tests/game-release-build.ps1`

**Interfaces:**
- Consumes: `AppConfig`, `ConnectionFactory::fromConfig()`, `RequestFactory::fromHttp()`, and private `blood/server/api/`.
- Produces: `Gateway::failure(Throwable $error, int $status = 503, ?callable $logger = null): Response`, `Gateway::corsHeaders(AppConfig $config, string $requestOrigin): array`, `Gateway::emit(Response $response, ?AppConfig $config, string $requestOrigin): void`, and the sole public PHP entry point.

- [ ] **Step 1: Write failing gateway and route-order tests**

Test that an exception containing a fake password produces a fixed `serviceUnavailable` response and a sanitized log line containing neither the password nor the exception message. Test exact allowed CORS origin, a lookalike suffix, and an empty origin.

Extend the release test to assert `.htaccess` has API rewrite before SPA fallback, real-file and directory exclusions before fallback, `Options -Indexes`, no-cache rules for the HTML/service worker, and long-lived cache rules for fingerprinted assets.

```php
$logs = [];
$response = Gateway::failure(
    new RuntimeException('password=not-for-clients'),
    logger: static function (string $line) use (&$logs): void { $logs[] = $line; }
);
$encoded = json_encode($response->body, JSON_THROW_ON_ERROR);
expect_same(503, $response->status, 'bootstrap failure status mismatch');
expect_true(!str_contains($encoded, 'not-for-clients'), 'bootstrap response leaked a secret');
expect_true(!str_contains(implode('\n', $logs), 'not-for-clients'), 'bootstrap log leaked a secret');
```

- [ ] **Step 2: Run tests and confirm failure**

```powershell
php api/tests/run.php
powershell -NoProfile -ExecutionPolicy Bypass -File tests/game-release-build.ps1
```

Expected: FAIL because the gateway and Apache templates do not exist.

- [ ] **Step 3: Implement fixed failures and exact CORS**

`Gateway::failure()` generates a random incident ID and logs only that ID, the exception class, basename of the source file, and source line. It never logs the exception message. The optional logger makes this behavior testable; the default logger calls `error_log()`. Return only:

```php
return new Response($status, [
    'error' => [
        'code' => 'serviceUnavailable',
        'message' => 'The game service is temporarily unavailable.',
    ],
]);
```

`corsHeaders()` returns nothing unless the non-empty request origin exactly equals `AppConfig::$corsOrigin`. An exact match returns `Access-Control-Allow-Origin`, `Vary: Origin`, `Access-Control-Allow-Headers: Authorization, Content-Type, X-Client-Platform, X-Client-Version, X-Seat-Token`, and `Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS`.

- [ ] **Step 4: Implement the deployed gateway template**

```php
$bloodRoot = dirname(__DIR__, 3);
$apiRoot = $bloodRoot . '/server/api';
require $apiRoot . '/autoload.php';

try {
    $config = AppConfig::fromFile($bloodRoot . '/.env');
    $connection = ConnectionFactory::fromConfig($config);
    $application = new Application(new AuthService($connection, $config->signingKey), new GameService($connection));
    $request = RequestFactory::fromHttp(
        $_SERVER['REQUEST_METHOD'] ?? 'GET',
        $_SERVER['REQUEST_URI'] ?? '/',
        function_exists('getallheaders') ? getallheaders() : [],
        (string)file_get_contents('php://input'),
        $_GET,
        '/play'
    );
    $response = $application->handle($request);
} catch (JsonException) {
    $response = new Response(400, ['error' => ['code' => 'invalidJson', 'message' => 'The request body is not valid JSON.']]);
} catch (Throwable $error) {
    $response = Gateway::failure($error);
}
Gateway::emit($response, $config ?? null, $_SERVER['HTTP_ORIGIN'] ?? '');
```

Return 204 to `OPTIONS` only after configuration loads and the incoming origin exactly matches. `Gateway::emit()` sets JSON content type/status, applies response and CORS headers, and encodes with `JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES`.

- [ ] **Step 5: Add hardened Apache rules and package the templates**

Use `Options -Indexes`, `SetEnvIf Authorization "(.+)" HTTP_AUTHORIZATION=$1`, `LimitRequestBody 1048576`, `RewriteBase /play/`, an `^api(?:/|$)` rewrite to `/play/api/index.php`, real-file/directory checks, and a final `/play/index.html` fallback. On IONOS, the internal rewrite exposes that captured credential as `REDIRECT_HTTP_AUTHORIZATION`, so request construction must restore it when PHP did not receive a normal `Authorization` header. Do not override the PHP handler in `.htaccess`; IONOS selects the active PHP version per domain in the Hosting control panel. Add `X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`, `X-Frame-Options: SAMEORIGIN`, and `Permissions-Policy: camera=(), microphone=(), geolocation=()`. Set `Cache-Control: no-cache` on `index.html`, manifest, and service-worker files; set one-year immutable caching on fingerprinted framework assets.

Copy `.htaccess` and the gateway to `public/play/api/index.php` from the release builder.

- [ ] **Step 6: Run gateway and package tests**

```powershell
php api/tests/run.php
powershell -NoProfile -ExecutionPolicy Bypass -File tests/game-release-build.ps1
```

Expected: all pass and the public tree contains no private API source.

- [ ] **Step 7: Commit**

```powershell
git add api/src/Http/Gateway.php api/tests/GatewayTest.php api/tests/run.php scripts/deployment/play/.htaccess scripts/deployment/play/api/index.php scripts/build-game-release.ps1 tests/game-release-build.ps1
git commit -m "feat: add the production play API gateway"
```

### Task 5: Complete the allowlisted package and protect legacy publishing

**Files:**
- Create: `scripts/deployment/DeploymentHelpers.psm1`
- Create: `tests/deployment-package.ps1`
- Modify: `scripts/build-game-release.ps1`
- Modify: `scripts/deploy.ps1`

**Interfaces:**
- Consumes: Task 3 builder, Task 4 gateway assets, tracked API files, and legacy rsync construction.
- Produces: release roots `public/play/` and `server/api/`; `Get-LegacyRsyncProtection(): string[]`; `Assert-SafeDeploymentPath(string $path): void`.

- [ ] **Step 1: Write failing package-boundary tests**

Build from a fake publish tree and require private files while banning them from public:

```powershell
$requiredPrivate = @(
    'server/api/autoload.php',
    'server/api/src/Config/AppConfig.php',
    'server/api/migrations/001_initial.mysql.sql',
    'server/api/scenarios/proving-field.json',
    'server/api/tools/migrate.php'
)
foreach ($relative in $requiredPrivate) {
    if (-not (Test-Path -LiteralPath (Join-Path $release $relative))) { throw "missing $relative" }
}
$forbidden = Get-ChildItem (Join-Path $release 'public/play') -Recurse -Force |
    Where-Object { $_.Name -in @('.env', 'AppConfig.php', '001_initial.mysql.sql') -or $_.Extension -in @('.sqlite', '.map') }
if ($forbidden) { throw "private files entered public: $($forbidden.FullName -join ', ')" }
```

Assert `Get-LegacyRsyncProtection` returns `--filter=protect /play/***`. Assert safe-path validation accepts the three game targets and rejects `/`, `.`, `public`, traversal, and paths outside `trickypig/blood`.

- [ ] **Step 2: Run the package test and confirm failure**

Run: `powershell -NoProfile -ExecutionPolicy Bypass -File tests/deployment-package.ps1`

Expected: FAIL because the helper module and private package do not exist.

- [ ] **Step 3: Implement deployment safety helpers**

Normalize slash direction, reject empty/traversal paths, and allow only exact suffixes beneath `trickypig/blood`:

```powershell
function Get-LegacyRsyncProtection {
    return @('--filter=protect /play/***')
}

function Assert-SafeDeploymentPath([string]$Path) {
    $normalized = $Path.Replace('\', '/').Trim('/')
    $allowed = @('trickypig/blood/public/play', 'trickypig/blood/server/api', 'trickypig/blood/.deploy')
    if ($normalized -notin $allowed -or $normalized -match '(^|/)\.\.(/|$)') {
        throw "Unsafe deployment path '$Path'."
    }
}
```

- [ ] **Step 4: Add the private package allowlist**

Copy only `autoload.php`, `src/**/*.php`, `migrations/*.mysql.sql`, `scenarios/*.json`, and `tools/migrate.php` into `server/api/`. Exclude `api/public`, tests, `var`, SQLite migrations/databases, dotfiles, symlinks, and every path outside the public/private allowlists.

- [ ] **Step 5: Protect `/play/` in legacy prune operations**

Import the helper module in `deploy.ps1` and append the returned filter to every rsync call before source and destination. Preserve current staging and dry-run behavior.

- [ ] **Step 6: Run package and legacy dry-run checks**

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File tests/deployment-package.ps1
powershell -NoProfile -ExecutionPolicy Bypass -File scripts/deploy.ps1
```

Expected: package tests pass; legacy deploy reports a dry run and writes nothing.

- [ ] **Step 7: Commit**

```powershell
git add scripts/deployment/DeploymentHelpers.psm1 scripts/build-game-release.ps1 scripts/deploy.ps1 tests/deployment-package.ps1
git commit -m "feat: isolate the game deployment package"
```

### Task 6: Dry-run-first remote deployment and rollback

**Files:**
- Create: `scripts/deployment/activate-game-release.sh`
- Create: `scripts/deploy-game.ps1`
- Create: `tests/deploy-game-script.ps1`
- Modify: `scripts/deployment/DeploymentHelpers.psm1`

**Interfaces:**
- Consumes: `.vscode/sftp.json`, WSL SSH behavior from `deploy.ps1`, the release package, and Task 5 safety helpers.
- Produces: `deploy-game.ps1 [-Push] [-CommandPreview]`; `New-GameRemoteLayout(string $remotePublicPath, string $releaseId)`; activation actions `activate` and `rollback`.

- [ ] **Step 1: Write failing command and mutation-boundary tests**

For `/trickypig/blood/public/`, assert the root is `trickypig/blood`, stage is `trickypig/blood/.deploy/<release>`, and live targets are `public/play` and `server/api`. Reject non-public roots, invalid release IDs, whitespace, and shell metacharacters.

Use `-CommandPreview` to assert a default run contains only read-only SSH checks and `rsync --dry-run`; it must not contain `mkdir`, `mv`, `rm`, `chmod`, migration, or activation. Assert a push preview contains staging, validation, migration, activation, HTTPS verification, and rollback, but no `.env` value.

Exercise `activate-game-release.sh` inside a temporary WSL directory. Prepend a
test-only `mv` shim to `PATH` that delegates to `/bin/mv` once and fails on the
next call. Assert the activation trap restores both original live directories
and leaves the staged release available for diagnosis. Run the explicit
`rollback` action against a successful fixture and assert both previous
directories return to their live paths.

- [ ] **Step 2: Run the script test and confirm failure**

Run: `powershell -NoProfile -ExecutionPolicy Bypass -File tests/deploy-game-script.ps1`

Expected: FAIL because the deployer and remote layout do not exist.

- [ ] **Step 3: Implement activation and rollback**

The shell script accepts action, validated account root, and release ID. Use `set -eu`; never source or print `.env`. Before activation:

```sh
test "$(stat -c %a "$root/.env")" = 600
find "$stage/server/api" -type f -name '*.php' -exec php8.4-cli -l {} \;
php8.4-cli "$stage/server/api/tools/migrate.php" --env-file "$root/.env"
```

For activation, move live server/public directories to a release-specific previous directory and move both staged directories live. Install a trap before the first live move and restore both prior directories on failure. For rollback, require the exact previous directory and restore both halves. Never recursively delete account root, `public`, `server`, or `.deploy`.

- [ ] **Step 4: Implement the PowerShell deployer**

Reuse WSL key resolution, `RemoteCommand=none`, `BatchMode=yes`, and connection checks. Form the release ID from UTC and `git rev-parse --short HEAD`. Use arrays for process arguments and strict validation for every value inserted into fixed remote command templates.

Default flow: run local checks/build, verify remote root and `.env` read-only, and run one itemized rsync dry run from the release root to the existing `blood/` account root with no deletion. This comparison can describe both `public/play/` and `server/api/` even when `server/` does not exist yet. Print changes, print `Dry run only - nothing was written.`, and exit without remote directory creation.

Push flow: create the exact stage directory, upload without deletion outside it, invoke activation, then check `/play/`, the service worker, a deep path, and `/play/api/v1/compatibility` with `curl.exe --fail-with-body`. Invoke rollback if an HTTP check fails and print the retained previous-release path on success.

- [ ] **Step 5: Run preview tests and a real dry run**

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File tests/deploy-game-script.ps1
powershell -NoProfile -ExecutionPolicy Bypass -File scripts/deploy-game.ps1
```

Expected: tests pass; real dry run connects read-only, reports changes, performs no remote mutation, and exits zero.

- [ ] **Step 6: Commit**

```powershell
git add scripts/deployment/activate-game-release.sh scripts/deployment/DeploymentHelpers.psm1 scripts/deploy-game.ps1 tests/deploy-game-script.ps1
git commit -m "feat: deploy and roll back the public game"
```

### Task 7: Documentation, full verification, and first production rollout

**Files:**
- Modify: `README.md`
- Modify: `.claude/skills/publishing-the-site/SKILL.md`
- Modify: `codemagic.yaml`

**Interfaces:**
- Consumes: Tasks 1–6.
- Produces: operator documentation, Windows CI checks, and the first verified production deployment after a separate authorization gate.

- [ ] **Step 1: Update operator documentation and CI**

Document these commands and their safety behavior:

```powershell
.\scripts\deploy-game.ps1         # read-only dry run
.\scripts\deploy-game.ps1 -Push   # staged production release
```

Describe layout, `.env` key names without values, mode `600`, explicit `php8.4-cli` migrations, rollback location, and signing-key rotation impact. Update the publishing skill to distinguish legacy and game publishers and repeat the mandatory dry-run-first rule. Add the PowerShell release tests to the Windows CI workflow after .NET tests.

- [ ] **Step 2: Run complete local verification**

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File tests/local-api-launch.ps1
php api/tests/run.php
dotnet test tests/BloodAndBanners.Contracts.Tests/BloodAndBanners.Contracts.Tests.csproj
dotnet test tests/BloodAndBanners.UI.Tests/BloodAndBanners.UI.Tests.csproj
dotnet build src/BloodAndBanners.Web/BloodAndBanners.Web.csproj -c Release
powershell -NoProfile -ExecutionPolicy Bypass -File tests/game-release-build.ps1
powershell -NoProfile -ExecutionPolicy Bypass -File tests/deployment-package.ps1
powershell -NoProfile -ExecutionPolicy Bypass -File tests/deploy-game-script.ps1
git diff --check
```

Expected: all exit zero and `git diff --check` prints nothing.

- [ ] **Step 3: Commit docs and CI**

```powershell
git add README.md .claude/skills/publishing-the-site/SKILL.md codemagic.yaml
git commit -m "docs: document the public game release"
```

- [ ] **Step 4: Run and review the production dry run**

Run: `.\scripts\deploy-game.ps1`

Expected: only new `public/play/` and `server/api/` files are listed; no legacy content or secret value appears; the script says nothing was written.

- [ ] **Step 5: Stop for explicit production authorization**

Present the dry-run summary, `.env` mode `404` versus required `600`, and exact push command. Do not change permissions or run `-Push` until the user explicitly authorizes deployment.

- [ ] **Step 6: Tighten permissions and push after authorization**

After approval, run only `chmod 600 trickypig/blood/.env` through the validated SSH target, verify `stat -c %a` returns `600`, and run `.\scripts\deploy-game.ps1 -Push`.

Expected: migration, activation, and HTTPS checks pass; the script prints the previous-release path and live URL.

- [ ] **Step 7: Perform the multiplayer smoke test**

In two independent browser profiles: open `/play/`, create and join a Vanilla duel, ready both seats, draft from both players, start the match, submit one legal action each, refresh, and confirm both reconnect to the correct seat/version. Then open a legacy static page and hard-refresh `/play/` to verify both legacy content and the current service worker.

Record release commit, deployment time, compatibility response, and smoke result in the handoff. If a check fails, run the deployer's rollback and report whether additive migrations had already run.
