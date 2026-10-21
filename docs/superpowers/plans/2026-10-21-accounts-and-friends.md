# Accounts and Friends Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Optional passwordless email sign-in (magic link + 6-digit code), friends (by email or from a shared game), and a signed-in start page listing your unfinished games (Resume) and friends' games (Join/Watch), while guest code/QR play stays unchanged.

**Architecture:** PHP API gains `EmailSignInService` (challenges + mail), `FriendService`, `AccountService`, and a vendored PHPMailer behind a `Mailer` interface. A signed-in user's `users.id` becomes their `identity_id` in games. The Blazor client gains `AccountApiClient`, `JsonAccountStore`, and `AccountSession`, which owns token refresh for both the account and any game seat held by the same identity. `GameSessionCoordinator` takes an optional `IAccountSession`. New UI components (`AccountChip`, `SignInPanel`, `AccountHome`, `AccountGamesList`, `FriendsPanel`, `AddFriendButton`) resolve `IAccountSession` optionally, so existing screens and tests without it keep working.

**Tech Stack:** PHP 8.1+ (no Composer; custom autoloader; tests via `php api/tests/run.php`), SQLite (dev/tests) + MySQL (prod), PHPMailer 6.9.3 vendored, .NET 10 Blazor (WASM + MAUI Hybrid), xUnit + bUnit.

**Spec:** `docs/superpowers/specs/2026-10-21-accounts-and-friends-design.md`

## Global Constraints

- Sign-in link format: `{APP_PUBLIC_URL}/?signin={token}`; `APP_PUBLIC_URL` default `https://blood.trickypig.com/play`.
- Code: 6 digits; challenge lifetime 15 minutes; 5 wrong attempts kill a challenge.
- Rate limits: 3 challenges per email per 15 minutes; 20 per IP per hour → `429 tooManyRequests`.
- `auth/email/start` responds `202` for any valid address (account or not, mail failure or not).
- SMTP: `smtp.ionos.com`, port `465`, `PHPMailer::ENCRYPTION_SMTPS`, from `noreply@trickypig.com`, 10 s timeout. The SMTP password is never committed; it lives only in the server `.env`.
- PHPMailer vendored at `api/src/Vendor/PHPMailer/` (v6.9.3, LGPL-2.1 LICENSE kept alongside).
- Empty `SMTP_HOST` → `LogMailer` writing to `api/var/mail.log`.
- Display names: whitespace collapsed, control/format chars stripped, trimmed, 1–24 characters (same rules as commander names).
- Emails are always compared normalized (`strtolower(trim())`); emails are never shown to anyone but their owner and the person who typed them.
- Error codes: `invalidEmail` 400, `invalidCode` 400, `deviceIdRequired` 400, `invalidDisplayName` 400, `cannotFriendSelf` 400, `cannotFriendSeat` 400, `accountRequired` 403, `requestNotFound` 404, `friendNotFound` 404, `alreadyFriends` 409, `alreadyRequested` 409, `tooManyRequests` 429.
- Storage key for the account on the client: `bloodAndBanners.account`.
- Start page polls account games + friends every 10 s while shown.
- Signed-out start page must render exactly as today.
- PHP: no `mbstring` (not installed locally) — use `preg_*` with `/u`.
- Commits end with:
  ```
  Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
  Claude-Session: https://claude.ai/code/session_018GfkGVBukKcMgz2Sf5qwks
  ```

## Review Focus

1. **Signed-in user opens their own game's invite/QR again** — expect their existing seat back (fresh seat token), never a 500 from the `UNIQUE(game_id, identity_id)` constraint. Test in Task 3.
2. **Email case/whitespace** — `"  Bob@Example.COM "` signs into the same account as `bob@example.com`, and a friend request typed with different case matches that account. Tests in Tasks 2 and 4.
3. **Refresh-token rotation shared between the account store and the game store** — refreshing via either path must leave both holding the new token, and two concurrent 401s must refresh once, not sign the user out. Tests in Task 8.
4. **Guest carry-over when the account already holds a seat in the same game** — verify must succeed (guest seat left as-is), not 500. Test in Task 2.
5. **Resume after being kicked from a lobby** — `404` is shown as "You no longer hold a seat in that game." and the start page stays usable. Test in Task 11.

---

## File Structure

**API (new)**
- `api/src/Vendor/PHPMailer/{PHPMailer,SMTP,Exception}.php`, `LICENSE` — vendored library.
- `api/src/Mail/Mailer.php` — interface.
- `api/src/Mail/LogMailer.php` — dev/test file mailer.
- `api/src/Mail/SmtpMailer.php` — PHPMailer wrapper.
- `api/src/Mail/MailerFactory.php` — picks a mailer from `AppConfig`.
- `api/src/Account/AccountException.php` — HTTP-status-carrying error.
- `api/src/Auth/EmailSignInService.php` — challenges, rate limits, verify, guest carry-over.
- `api/src/Account/FriendService.php` — friend requests and lists.
- `api/src/Account/AccountService.php` — profile + start-page game feed.
- `api/migrations/005_accounts.{sqlite,mysql}.sql`.
- `api/tests/MailTest.php`, `AccountTestSupport.php`, `EmailSignInTest.php`, `AccountTest.php`, `FriendTest.php`, `AccountGamesTest.php`.

**API (modified)**
- `api/autoload.php` — PHPMailer namespace.
- `api/src/Config/AppConfig.php` — mail keys.
- `api/src/Http/Request.php`, `RequestFactory.php` — `clientIp`.
- `api/src/Http/Application.php` — routes, `AccountException` mapping, new constructor params.
- `api/src/Auth/AuthService.php` — public `issuePair`, `logout`.
- `api/src/Game/GameService.php` — `resumeSeat`, member re-join, commander-name preset, `seatLimit`, projection flags, conflict-safe `transferIdentity`.
- `api/public/index.php`, `scripts/deployment/play/api/index.php` — wiring.
- `api/tests/run.php` — register new test files.

**Client (new)**
- `src/BloodAndBanners.Contracts/AccountContracts.cs`.
- `src/BloodAndBanners.UI/Services/IAccountApiClient.cs`, `AccountApiClient.cs`, `AccountStore.cs`, `IAccountSession.cs`, `AccountSession.cs`.
- `src/BloodAndBanners.UI/Components/AccountChip.razor(.css)`, `SignInPanel.razor(.css)`, `AccountHome.razor(.css)`, `AccountGamesList.razor(.css)`, `FriendsPanel.razor(.css)`, `AddFriendButton.razor(.css)`.
- `tests/fixtures/account-games.json`, `friends-list.json`, `auth-session.json`.
- `tests/BloodAndBanners.Contracts.Tests/AccountContractTests.cs`.
- `tests/BloodAndBanners.UI.Tests/AccountFakes.cs`, `AccountApiClientTests.cs`, `AccountSessionTests.cs`, `SignInPanelTests.cs`, `AccountHomeTests.cs`, `AddFriendButtonTests.cs`.

**Client (modified)**
- `src/BloodAndBanners.Contracts/AuthSession.cs`, `GameProjection.cs` (`MembershipProjection`).
- `src/BloodAndBanners.UI/Services/GameApiClient.cs` (helpers `internal`), `GameSessionCoordinator.cs`, `IGameSessionCoordinator.cs`, `InviteLinks.cs`.
- `src/BloodAndBanners.UI/Components/GameExperience.razor`, `QuickStart.razor`, `WarRoomLobby.razor`, `MatchMenu.razor`, `GameTable.razor`, `DraftTable.razor`.
- `src/BloodAndBanners.Web/Program.cs`, `Pages/Home.razor`; `src/BloodAndBanners.Maui/MauiProgram.cs`, `Components/Pages/Home.razor`.
- `tests/BloodAndBanners.UI.Tests/GameSessionCoordinatorTests.cs`, `GameExperienceTests.cs`, `WarRoomLobbyTests.cs`, `MatchMenuTests.cs`.
- `README.md`.

## Commands

- PHP tests: `php api/tests/run.php` (prints `PASS`/`FAIL` lines and a summary; exit 0 on success). To focus, pipe through `grep -E "FAIL|tests,"`.
- .NET tests: `dotnet test tests/BloodAndBanners.UI.Tests` and `dotnet test tests/BloodAndBanners.Contracts.Tests` (use `--filter "FullyQualifiedName~ClassName"` to focus).

---

### Task 1: Mail infrastructure (PHPMailer vendored, Mailer, config)

**Files:**
- Create: `api/src/Vendor/PHPMailer/PHPMailer.php`, `SMTP.php`, `Exception.php`, `LICENSE`
- Create: `api/src/Mail/Mailer.php`, `LogMailer.php`, `SmtpMailer.php`, `MailerFactory.php`
- Modify: `api/autoload.php`, `api/src/Config/AppConfig.php`
- Test: `api/tests/MailTest.php`, `api/tests/run.php`

**Interfaces:**
- Produces: `interface Mailer { send(string $to, string $subject, string $text, string $html): void }`; `LogMailer(string $path)`; `SmtpMailer(string $host, int $port, string $user, string $password, string $from)` with `message(...): PHPMailer`; `MailerFactory::fromConfig(AppConfig $config, string $logPath): Mailer`; `AppConfig` props `smtpHost`, `smtpPort`, `smtpUser`, `smtpPassword`, `mailFrom`, `publicUrl`.

- [ ] **Step 1: Vendor PHPMailer 6.9.3**

```bash
mkdir -p api/src/Vendor/PHPMailer
for f in PHPMailer SMTP Exception; do
  curl -sSfL "https://raw.githubusercontent.com/PHPMailer/PHPMailer/v6.9.3/src/$f.php" -o "api/src/Vendor/PHPMailer/$f.php"
done
curl -sSfL "https://raw.githubusercontent.com/PHPMailer/PHPMailer/v6.9.3/LICENSE" -o api/src/Vendor/PHPMailer/LICENSE
head -20 api/src/Vendor/PHPMailer/PHPMailer.php   # expect "namespace PHPMailer\PHPMailer;" and VERSION 6.9.3
grep -n "const VERSION" api/src/Vendor/PHPMailer/PHPMailer.php
```

Read through the three files before committing (they are third-party code that will be deployed).

- [ ] **Step 2: Map the PHPMailer namespace in `api/autoload.php`** (append after the existing registration)

```php
spl_autoload_register(static function (string $class): void {
    $prefix = 'PHPMailer\\PHPMailer\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $path = __DIR__ . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Vendor' . DIRECTORY_SEPARATOR
        . 'PHPMailer' . DIRECTORY_SEPARATOR . substr($class, strlen($prefix)) . '.php';
    if (is_file($path)) {
        require $path;
    }
});
```

- [ ] **Step 3: Write the failing tests** — `api/tests/MailTest.php`

```php
<?php
declare(strict_types=1);

use BloodAndBanners\Config\AppConfig;
use BloodAndBanners\Mail\LogMailer;
use BloodAndBanners\Mail\MailerFactory;
use BloodAndBanners\Mail\SmtpMailer;
use PHPMailer\PHPMailer\PHPMailer;

function mail_tests(): array
{
    $minimalEnv = "DB_DRIVER=sqlite\nDB_PATH=/tmp/x.sqlite\nAPP_SIGNING_KEY=0123456789abcdef0123456789abcdef\nCORS_ORIGIN=https://blood.trickypig.com\n";

    return [
        'log mailer appends each message to its file' => static function (): void {
            $path = tempnam(sys_get_temp_dir(), 'mail');
            try {
                $mailer = new LogMailer($path);
                $mailer->send('a@example.com', 'First', 'one', '<p>one</p>');
                $mailer->send('b@example.com', 'Second', 'two', '<p>two</p>');
                $log = (string)file_get_contents($path);
                expect_true(str_contains($log, 'To: a@example.com') && str_contains($log, 'Subject: Second'), 'both messages should be logged');
                expect_true(str_contains($log, "two"), 'text body should be logged');
            } finally {
                unlink($path);
            }
        },

        'smtp mailer configures implicit TLS on port 465' => static function (): void {
            $mailer = new SmtpMailer('smtp.ionos.com', 465, 'noreply@trickypig.com', 'secret', 'noreply@trickypig.com');
            $message = $mailer->message('player@example.com', 'Code', 'text body', '<p>html</p>');
            expect_same('smtp.ionos.com', $message->Host, 'host');
            expect_same(465, $message->Port, 'port');
            expect_same(PHPMailer::ENCRYPTION_SMTPS, $message->SMTPSecure, 'implicit TLS');
            expect_same(true, $message->SMTPAuth, 'auth');
            expect_same('noreply@trickypig.com', $message->From, 'from');
            expect_same('text body', $message->AltBody, 'plain-text alternative');
            expect_same(10, $message->Timeout, 'timeout');
        },

        'config reads mail settings with defaults' => static function () use ($minimalEnv): void {
            $path = write_test_env($minimalEnv);
            try {
                $config = AppConfig::fromFile($path);
                expect_same('', $config->smtpHost, 'no SMTP host by default');
                expect_same(465, $config->smtpPort, 'default port');
                expect_same('noreply@trickypig.com', $config->mailFrom, 'default sender');
                expect_same('https://blood.trickypig.com/play', $config->publicUrl, 'default public URL');
            } finally {
                unlink($path);
            }
            $path = write_test_env($minimalEnv . "SMTP_HOST=smtp.ionos.com\nSMTP_USER=noreply@trickypig.com\nSMTP_PASS=\"p@ss word\"\nAPP_PUBLIC_URL=http://localhost:5016\n");
            try {
                $config = AppConfig::fromFile($path);
                expect_same('smtp.ionos.com', $config->smtpHost, 'host read');
                expect_same('p@ss word', $config->smtpPassword, 'password read literally');
                expect_same('http://localhost:5016', $config->publicUrl, 'public URL read');
            } finally {
                unlink($path);
            }
        },

        'mailer factory picks log mailer without an SMTP host' => static function () use ($minimalEnv): void {
            $path = write_test_env($minimalEnv);
            try {
                expect_true(MailerFactory::fromConfig(AppConfig::fromFile($path), '/tmp/mail.log') instanceof LogMailer, 'log mailer expected');
            } finally {
                unlink($path);
            }
            $path = write_test_env($minimalEnv . "SMTP_HOST=smtp.ionos.com\nSMTP_USER=u\nSMTP_PASS=p\n");
            try {
                expect_true(MailerFactory::fromConfig(AppConfig::fromFile($path), '/tmp/mail.log') instanceof SmtpMailer, 'SMTP mailer expected');
            } finally {
                unlink($path);
            }
        },
    ];
}
```

Register in `api/tests/run.php`: add `require __DIR__ . '/MailTest.php';` after the `ConfigTest.php` require and add `mail_tests()` to the `array_merge(...)` right after `config_tests()`. (`write_test_env` already exists in `ConfigTest.php`.)

- [ ] **Step 4: Run to verify failure**

Run: `php api/tests/run.php 2>&1 | grep -E "FAIL|tests,"`
Expected: fatal error / FAIL — `BloodAndBanners\Mail\LogMailer` not found.

- [ ] **Step 5: Implement**

`api/src/Mail/Mailer.php`:
```php
<?php
declare(strict_types=1);

namespace BloodAndBanners\Mail;

interface Mailer
{
    /** Sends one message; throws when it could not be handed to the mail server. */
    public function send(string $to, string $subject, string $text, string $html): void;
}
```

`api/src/Mail/LogMailer.php`:
```php
<?php
declare(strict_types=1);

namespace BloodAndBanners\Mail;

use RuntimeException;

/** Development mailer: appends messages to a file so sign-in codes can be read without SMTP. */
final class LogMailer implements Mailer
{
    public function __construct(private readonly string $path)
    {
    }

    public function send(string $to, string $subject, string $text, string $html): void
    {
        $entry = sprintf("[%s] To: %s\nSubject: %s\n\n%s\n\n", gmdate('Y-m-d H:i:s'), $to, $subject, $text);
        if (file_put_contents($this->path, $entry, FILE_APPEND | LOCK_EX) === false) {
            throw new RuntimeException('The mail log could not be written.');
        }
    }
}
```

`api/src/Mail/SmtpMailer.php`:
```php
<?php
declare(strict_types=1);

namespace BloodAndBanners\Mail;

use PHPMailer\PHPMailer\PHPMailer;

final class SmtpMailer implements Mailer
{
    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $user,
        private readonly string $password,
        private readonly string $from,
    ) {
    }

    public function send(string $to, string $subject, string $text, string $html): void
    {
        $this->message($to, $subject, $text, $html)->send();
    }

    /** Builds the configured message without sending it, so settings can be tested offline. */
    public function message(string $to, string $subject, string $text, string $html): PHPMailer
    {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $this->host;
        $mail->Port = $this->port;
        $mail->SMTPAuth = true;
        $mail->Username = $this->user;
        $mail->Password = $this->password;
        $mail->SMTPSecure = $this->port === 465 ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Timeout = 10;
        $mail->CharSet = PHPMailer::CHARSET_UTF8;
        $mail->setFrom($this->from, 'Blood & Banners');
        $mail->addAddress($to);
        $mail->Subject = $subject;
        $mail->isHTML(true);
        $mail->Body = $html;
        $mail->AltBody = $text;
        return $mail;
    }
}
```

`api/src/Mail/MailerFactory.php`:
```php
<?php
declare(strict_types=1);

namespace BloodAndBanners\Mail;

use BloodAndBanners\Config\AppConfig;

final class MailerFactory
{
    public static function fromConfig(AppConfig $config, string $logPath): Mailer
    {
        return $config->smtpHost === ''
            ? new LogMailer($logPath)
            : new SmtpMailer($config->smtpHost, $config->smtpPort, $config->smtpUser, $config->smtpPassword, $config->mailFrom);
    }
}
```

`api/src/Config/AppConfig.php` — append constructor params (with defaults, after `$corsOrigin`):
```php
        public readonly string $smtpHost = '',
        public readonly int $smtpPort = 465,
        public readonly string $smtpUser = '',
        public readonly string $smtpPassword = '',
        public readonly string $mailFrom = 'noreply@trickypig.com',
        public readonly string $publicUrl = 'https://blood.trickypig.com/play',
```
and in `fromFile`, extend the `return new self(...)` call:
```php
            $corsOrigin,
            trim($values['SMTP_HOST'] ?? ''),
            (int)(trim($values['SMTP_PORT'] ?? '') ?: 465),
            trim($values['SMTP_USER'] ?? ''),
            $values['SMTP_PASS'] ?? '',
            trim($values['MAIL_FROM'] ?? '') ?: 'noreply@trickypig.com',
            rtrim(trim($values['APP_PUBLIC_URL'] ?? '') ?: 'https://blood.trickypig.com/play', '/'),
        );
```

- [ ] **Step 6: Run tests**

Run: `php api/tests/run.php 2>&1 | grep -E "FAIL|tests,"`
Expected: `N tests, 0 failures`.

- [ ] **Step 7: Commit**

```bash
git add api/autoload.php api/src/Vendor api/src/Mail api/src/Config/AppConfig.php api/tests/MailTest.php api/tests/run.php
git commit -m "Add mailer abstraction with vendored PHPMailer"
```

---

### Task 2: Email sign-in (migration, service, routes)

**Files:**
- Create: `api/migrations/005_accounts.sqlite.sql`, `api/migrations/005_accounts.mysql.sql`
- Create: `api/src/Account/AccountException.php`, `api/src/Auth/EmailSignInService.php`
- Modify: `api/src/Auth/AuthService.php`, `api/src/Http/Request.php`, `api/src/Http/RequestFactory.php`, `api/src/Http/Application.php`, `api/src/Game/GameService.php` (`transferIdentity`)
- Test: `api/tests/AccountTestSupport.php`, `api/tests/EmailSignInTest.php`, `api/tests/run.php`

**Interfaces:**
- Consumes: `Mailer` (Task 1).
- Produces:
  - `AccountException(int $status, string $errorCode, string $message)` with public readonly `status`, `errorCode`.
  - `EmailSignInService(PDO, AuthService, Mailer, string $publicUrl, ?Closure $clock = null, ?Closure $logger = null)`:
    - `static normalizeEmail(string): string` (throws `invalidEmail`).
    - `start(string $email, string $clientIp): void`.
    - `verifyCode(string $email, string $code, string $deviceId, ?string $guestId, ?callable $transfer): array`.
    - `verifyToken(string $token, string $deviceId, ?string $guestId, ?callable $transfer): array`.
    - The `verify*` methods return `{identityId, kind:'user', accessToken, refreshToken, displayName}`.
  - `AuthService::issuePair(string $kind, string $identityId, string $deviceId): array` (now public); `AuthService::logout(string $refreshToken): void`.
  - `Request->clientIp`.
  - `Application(AuthService, GameService, ?EmailSignInService $signIn = null, ?AccountService $accounts = null, ?FriendService $friends = null)`. The last two are added in Tasks 3/4; the param order is fixed now.
  - Test helpers `account_context(?Closure $clock = null): array{app: Application, db: PDO, mailer: MemoryMailer}`, `sign_in(array $ctx, string $email, string $device = 'device-1', array $headers = []): array`, `mailed_code(MemoryMailer): string`, `mailed_token(MemoryMailer): string`, `bearer(array $session): array`.

- [ ] **Step 1: Migration files**

`api/migrations/005_accounts.sqlite.sql`:
```sql
CREATE TABLE IF NOT EXISTS login_challenges (
    id TEXT PRIMARY KEY,
    email TEXT NOT NULL,
    code_hash TEXT NOT NULL,
    token_hash TEXT NOT NULL UNIQUE,
    attempts INTEGER NOT NULL DEFAULT 0,
    client_ip TEXT NOT NULL,
    expires_at TEXT NOT NULL,
    consumed_at TEXT NULL,
    created_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_login_challenges_email ON login_challenges(email, created_at);
CREATE INDEX IF NOT EXISTS idx_login_challenges_ip ON login_challenges(client_ip, created_at);

CREATE TABLE IF NOT EXISTS friendships (
    id TEXT PRIMARY KEY,
    requester_user_id TEXT NOT NULL,
    addressee_user_id TEXT NULL,
    addressee_email TEXT NOT NULL,
    status TEXT NOT NULL CHECK (status IN ('pending', 'accepted')),
    from_game INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL,
    responded_at TEXT NULL,
    UNIQUE (requester_user_id, addressee_email),
    FOREIGN KEY (requester_user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (addressee_user_id) REFERENCES users(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_friendships_addressee ON friendships(addressee_user_id);
CREATE INDEX IF NOT EXISTS idx_friendships_email ON friendships(addressee_email);
```

`api/migrations/005_accounts.mysql.sql`:
```sql
CREATE TABLE IF NOT EXISTS login_challenges (
    id CHAR(32) PRIMARY KEY,
    email VARCHAR(320) NOT NULL,
    code_hash CHAR(64) NOT NULL,
    token_hash CHAR(64) NOT NULL UNIQUE,
    attempts INT NOT NULL DEFAULT 0,
    client_ip VARCHAR(64) NOT NULL,
    expires_at DATETIME(6) NOT NULL,
    consumed_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL,
    INDEX idx_login_challenges_email (email, created_at),
    INDEX idx_login_challenges_ip (client_ip, created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS friendships (
    id CHAR(32) PRIMARY KEY,
    requester_user_id CHAR(32) NOT NULL,
    addressee_user_id CHAR(32) NULL,
    addressee_email VARCHAR(320) NOT NULL,
    status VARCHAR(16) NOT NULL,
    from_game TINYINT NOT NULL DEFAULT 0,
    created_at DATETIME(6) NOT NULL,
    responded_at DATETIME(6) NULL,
    UNIQUE KEY uq_friendships_pair (requester_user_id, addressee_email),
    INDEX idx_friendships_addressee (addressee_user_id),
    INDEX idx_friendships_email (addressee_email),
    CONSTRAINT fk_friendships_requester FOREIGN KEY (requester_user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_friendships_addressee FOREIGN KEY (addressee_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
```

- [ ] **Step 2: Test support** — `api/tests/AccountTestSupport.php`

```php
<?php
declare(strict_types=1);

use BloodAndBanners\Auth\AuthService;
use BloodAndBanners\Auth\EmailSignInService;
use BloodAndBanners\Database\ConnectionFactory;
use BloodAndBanners\Database\Migrator;
use BloodAndBanners\Game\GameService;
use BloodAndBanners\Http\Application;
use BloodAndBanners\Http\Request;
use BloodAndBanners\Mail\Mailer;

final class MemoryMailer implements Mailer
{
    /** @var list<array{to: string, subject: string, text: string, html: string}> */
    public array $sent = [];
    public bool $fail = false;

    public function send(string $to, string $subject, string $text, string $html): void
    {
        if ($this->fail) {
            throw new RuntimeException('SMTP unavailable');
        }
        $this->sent[] = ['to' => $to, 'subject' => $subject, 'text' => $text, 'html' => $html];
    }
}

/** @return array{app: Application, db: PDO, mailer: MemoryMailer} */
function account_context(?Closure $clock = null): array
{
    $db = ConnectionFactory::sqliteMemory();
    (new Migrator($db))->migrate();
    $auth = new AuthService($db, 'test-signing-key-with-at-least-32-bytes');
    $mailer = new MemoryMailer();
    $signIn = new EmailSignInService($db, $auth, $mailer, 'https://blood.test/play', $clock, static fn(string $line): bool => true);
    return ['app' => new Application($auth, new GameService($db), $signIn), 'db' => $db, 'mailer' => $mailer];
}

function mailed_code(MemoryMailer $mailer): string
{
    $last = $mailer->sent[array_key_last($mailer->sent)];
    preg_match('/\b(\d{6})\b/', $last['subject'], $match) === 1 || throw new RuntimeException('no code mailed');
    return $match[1];
}

function mailed_token(MemoryMailer $mailer): string
{
    $last = $mailer->sent[array_key_last($mailer->sent)];
    preg_match('/[?&]signin=([A-Za-z0-9_-]+)/', $last['text'], $match) === 1 || throw new RuntimeException('no link mailed');
    return $match[1];
}

function bearer(array $session): array
{
    return ['authorization' => 'Bearer ' . $session['accessToken']];
}

/** Runs start + verify by code and returns the verify response body. */
function sign_in(array $ctx, string $email, string $device = 'device-1', array $headers = []): array
{
    $start = $ctx['app']->handle(new Request('POST', '/api/v1/auth/email/start', body: ['email' => $email]));
    expect_same(202, $start->status, 'start should accept');
    $verify = $ctx['app']->handle(new Request('POST', '/api/v1/auth/email/verify', $headers, [
        'email' => $email, 'code' => mailed_code($ctx['mailer']), 'deviceId' => $device,
    ]));
    expect_same(200, $verify->status, 'verify should succeed: ' . json_encode($verify->body));
    return $verify->body;
}
```

- [ ] **Step 3: Write the failing tests** — `api/tests/EmailSignInTest.php`

```php
<?php
declare(strict_types=1);

use BloodAndBanners\Http\Request;

function email_sign_in_tests(): array
{
    return [
        'start mails a six digit code and a sign-in link' => static function (): void {
            $ctx = account_context();
            $response = $ctx['app']->handle(new Request('POST', '/api/v1/auth/email/start', body: ['email' => 'Hero@Example.com']));
            expect_same(202, $response->status, 'start accepted');
            expect_same('hero@example.com', $ctx['mailer']->sent[0]['to'], 'normalized recipient');
            expect_true(preg_match('/^\d{6}$/', mailed_code($ctx['mailer'])) === 1, 'six digit code');
            expect_true(str_contains($ctx['mailer']->sent[0]['text'], 'https://blood.test/play/?signin='), 'link uses public URL');
        },

        'start rejects an invalid address' => static function (): void {
            $ctx = account_context();
            $response = $ctx['app']->handle(new Request('POST', '/api/v1/auth/email/start', body: ['email' => 'not-an-email']));
            expect_same(400, $response->status, 'invalid email is 400');
            expect_same('invalidEmail', $response->body['error']['code'], 'code');
        },

        'start limits challenges per email and per IP' => static function (): void {
            $ctx = account_context();
            for ($i = 0; $i < 3; $i++) {
                expect_same(202, $ctx['app']->handle(new Request('POST', '/api/v1/auth/email/start', body: ['email' => 'a@example.com']))->status, "start {$i}");
            }
            $limited = $ctx['app']->handle(new Request('POST', '/api/v1/auth/email/start', body: ['email' => 'A@example.com ']));
            expect_same(429, $limited->status, 'fourth in 15 minutes is limited');
            expect_same('tooManyRequests', $limited->body['error']['code'], 'code');

            $ctx = account_context();
            for ($i = 0; $i < 20; $i++) {
                $ctx['app']->handle(new Request('POST', '/api/v1/auth/email/start', body: ['email' => "p{$i}@example.com"], clientIp: '10.0.0.1'));
            }
            $limited = $ctx['app']->handle(new Request('POST', '/api/v1/auth/email/start', body: ['email' => 'p99@example.com'], clientIp: '10.0.0.1'));
            expect_same(429, $limited->status, 'twenty-first per IP per hour is limited');
        },

        'failed mail still answers 202 and does not count toward the limit' => static function (): void {
            $ctx = account_context();
            $ctx['mailer']->fail = true;
            for ($i = 0; $i < 4; $i++) {
                expect_same(202, $ctx['app']->handle(new Request('POST', '/api/v1/auth/email/start', body: ['email' => 'a@example.com']))->status, "failed start {$i}");
            }
            $ctx['mailer']->fail = false;
            expect_same(202, $ctx['app']->handle(new Request('POST', '/api/v1/auth/email/start', body: ['email' => 'a@example.com']))->status, 'not limited');
        },

        'verify by code creates the account once and reuses it, ignoring email case' => static function (): void {
            $ctx = account_context();
            $first = sign_in($ctx, 'bob@example.com');
            expect_same('user', $first['kind'], 'user session');
            expect_same('', $first['displayName'], 'new account has no name yet');
            $second = sign_in($ctx, '  Bob@Example.COM ');
            expect_same($first['identityId'], $second['identityId'], 'same account');
            expect_same(1, (int)$ctx['db']->query('SELECT COUNT(*) FROM users')->fetchColumn(), 'one user row');
        },

        'verify by link token signs in and cannot be reused' => static function (): void {
            $ctx = account_context();
            $ctx['app']->handle(new Request('POST', '/api/v1/auth/email/start', body: ['email' => 'link@example.com']));
            $token = mailed_token($ctx['mailer']);
            $ok = $ctx['app']->handle(new Request('POST', '/api/v1/auth/email/verify', body: ['token' => $token, 'deviceId' => 'd']));
            expect_same(200, $ok->status, 'link verifies');
            $again = $ctx['app']->handle(new Request('POST', '/api/v1/auth/email/verify', body: ['token' => $token, 'deviceId' => 'd']));
            expect_same(400, $again->status, 'consumed link rejected');
            expect_same('invalidCode', $again->body['error']['code'], 'code');
        },

        'wrong codes lock the challenge after five attempts' => static function (): void {
            $ctx = account_context();
            $ctx['app']->handle(new Request('POST', '/api/v1/auth/email/start', body: ['email' => 'x@example.com']));
            $code = mailed_code($ctx['mailer']);
            $wrong = $code === '000000' ? '111111' : '000000';
            for ($i = 0; $i < 5; $i++) {
                $bad = $ctx['app']->handle(new Request('POST', '/api/v1/auth/email/verify', body: ['email' => 'x@example.com', 'code' => $wrong, 'deviceId' => 'd']));
                expect_same(400, $bad->status, "wrong attempt {$i}");
            }
            $late = $ctx['app']->handle(new Request('POST', '/api/v1/auth/email/verify', body: ['email' => 'x@example.com', 'code' => $code, 'deviceId' => 'd']));
            expect_same(400, $late->status, 'right code after lock is rejected');
        },

        'expired challenges are rejected' => static function (): void {
            $now = 1_800_000_000;
            $ctx = account_context(static function () use (&$now): int { return $now; });
            $ctx['app']->handle(new Request('POST', '/api/v1/auth/email/start', body: ['email' => 'late@example.com']));
            $code = mailed_code($ctx['mailer']);
            $now += 15 * 60 + 1;
            $late = $ctx['app']->handle(new Request('POST', '/api/v1/auth/email/verify', body: ['email' => 'late@example.com', 'code' => $code, 'deviceId' => 'd']));
            expect_same(400, $late->status, 'expired code rejected');
        },

        'verify requires a device id' => static function (): void {
            $ctx = account_context();
            $ctx['app']->handle(new Request('POST', '/api/v1/auth/email/start', body: ['email' => 'd@example.com']));
            $response = $ctx['app']->handle(new Request('POST', '/api/v1/auth/email/verify', body: ['email' => 'd@example.com', 'code' => mailed_code($ctx['mailer'])]));
            expect_same(400, $response->status, 'missing device');
            expect_same('deviceIdRequired', $response->body['error']['code'], 'code');
        },

        'verify with a guest token moves the guest seat to the account' => static function (): void {
            $ctx = account_context();
            $guest = $ctx['app']->handle(new Request('POST', '/api/v1/auth/guest', body: ['deviceId' => 'g']))->body;
            $created = $ctx['app']->handle(new Request('POST', '/api/v1/games', bearer($guest), ['mode' => 'vanillaPvp', 'scenarioName' => 'Four Corners']))->body;
            $user = sign_in($ctx, 'carry@example.com', 'device-1', bearer($guest));
            $state = $ctx['app']->handle(new Request('GET', "/api/v1/games/{$created['game']['gameId']}", bearer($user) + ['x-seat-token' => $created['seatToken']]));
            expect_same(200, $state->status, 'account now holds the guest seat with the same seat token');
            expect_true($state->body['viewer']['isHost'], 'still host');
        },

        'guest carry-over skips games the account already sits in' => static function (): void {
            $ctx = account_context();
            $user = sign_in($ctx, 'twice@example.com');
            $created = $ctx['app']->handle(new Request('POST', '/api/v1/games', bearer($user), ['mode' => 'vanillaPvp', 'scenarioName' => 'Four Corners']))->body;
            $guest = $ctx['app']->handle(new Request('POST', '/api/v1/auth/guest', body: ['deviceId' => 'g']))->body;
            $ctx['app']->handle(new Request('POST', "/api/v1/games/{$created['game']['gameId']}/join", bearer($guest)));
            $again = sign_in($ctx, 'twice@example.com', 'device-2', bearer($guest));
            expect_same($user['identityId'], $again['identityId'], 'same account, no 500');
        },

        'logout revokes the refresh token' => static function (): void {
            $ctx = account_context();
            $user = sign_in($ctx, 'out@example.com');
            expect_same(204, $ctx['app']->handle(new Request('POST', '/api/v1/auth/logout', body: ['refreshToken' => $user['refreshToken']]))->status, 'logout');
            $refresh = $ctx['app']->handle(new Request('POST', '/api/v1/auth/refresh', body: ['refreshToken' => $user['refreshToken'], 'deviceId' => 'device-1']));
            expect_same(401, $refresh->status, 'revoked token cannot refresh');
        },
    ];
}
```

Register both files in `api/tests/run.php`: `require __DIR__ . '/AccountTestSupport.php';` and `require __DIR__ . '/EmailSignInTest.php';` after `LobbyLeaveTest.php`, and add `email_sign_in_tests()` to the merge.

- [ ] **Step 4: Run to verify failure**

Run: `php api/tests/run.php 2>&1 | grep -E "FAIL|tests,|Fatal"`
Expected: fatal error — `EmailSignInService` not found.

- [ ] **Step 5: Implement**

`api/src/Account/AccountException.php`:
```php
<?php
declare(strict_types=1);

namespace BloodAndBanners\Account;

use RuntimeException;

/** An account or friends error that maps directly to an HTTP status and stable error code. */
final class AccountException extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }
}
```

`api/src/Http/Request.php` — add a final constructor param `public readonly string $clientIp = '',`.
`api/src/Http/RequestFactory.php` — in the `new Request(...)` call at the end of `fromHttp`, add `clientIp: (string)($server['REMOTE_ADDR'] ?? '')`.

`api/src/Auth/AuthService.php`:
- change `private function issuePair` to `public function issuePair`;
- add:
```php
    public function logout(string $refreshToken): void
    {
        $this->connection->prepare('UPDATE refresh_tokens SET revoked_at = :revoked WHERE token_hash = :hash AND revoked_at IS NULL')
            ->execute(['revoked' => self::now(), 'hash' => hash('sha256', $refreshToken)]);
    }
```

`api/src/Game/GameService.php` — make `transferIdentity` skip games the target already sits in (Review Focus 4). Replace the first `UPDATE game_memberships` statement with:
```php
            $this->connection->prepare(
                'UPDATE game_memberships SET identity_id = :to
                 WHERE identity_id = :from
                   AND game_id NOT IN (SELECT game_id FROM (SELECT game_id FROM game_memberships WHERE identity_id = :to2) AS seated)'
            )->execute(['to' => $toIdentityId, 'from' => $fromIdentityId, 'to2' => $toIdentityId]);
```
(The derived table is required by MySQL, which refuses to select from the table being updated.)

`api/src/Auth/EmailSignInService.php`:
```php
<?php
declare(strict_types=1);

namespace BloodAndBanners\Auth;

use BloodAndBanners\Account\AccountException;
use BloodAndBanners\Mail\Mailer;
use Closure;
use PDO;
use Throwable;

/** Passwordless sign-in: emails a 6-digit code plus a link token, then trades either for a user session. */
final class EmailSignInService
{
    private const CHALLENGE_SECONDS = 900;
    private const MAX_ATTEMPTS = 5;
    private const EMAIL_LIMIT = 3;
    private const EMAIL_WINDOW = 900;
    private const IP_LIMIT = 20;
    private const IP_WINDOW = 3600;

    private readonly Closure $clock;
    private readonly Closure $logger;

    public function __construct(
        private readonly PDO $connection,
        private readonly AuthService $auth,
        private readonly Mailer $mailer,
        private readonly string $publicUrl,
        ?Closure $clock = null,
        ?Closure $logger = null,
    ) {
        $this->clock = $clock ?? static fn(): int => time();
        $this->logger = $logger ?? static fn(string $line): bool => error_log($line);
    }

    public static function normalizeEmail(string $email): string
    {
        $normalized = strtolower(trim($email));
        if (strlen($normalized) > 254 || filter_var($normalized, FILTER_VALIDATE_EMAIL) === false) {
            throw new AccountException(400, 'invalidEmail', 'Enter a valid email address.');
        }
        return $normalized;
    }

    public function start(string $email, string $clientIp): void
    {
        $email = self::normalizeEmail($email);
        $now = ($this->clock)();
        if ($this->recent('email', $email, $now - self::EMAIL_WINDOW) >= self::EMAIL_LIMIT
            || $this->recent('client_ip', $clientIp, $now - self::IP_WINDOW) >= self::IP_LIMIT) {
            throw new AccountException(429, 'tooManyRequests', 'Too many sign-in emails. Wait a few minutes and try again.');
        }

        $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $token = TokenCodec::randomOpaqueToken();
        $id = bin2hex(random_bytes(16));
        $this->connection->prepare(
            'INSERT INTO login_challenges (id, email, code_hash, token_hash, attempts, client_ip, expires_at, consumed_at, created_at)
             VALUES (:id, :email, :code, :token, 0, :ip, :expires, NULL, :created)'
        )->execute([
            'id' => $id,
            'email' => $email,
            'code' => hash('sha256', $code),
            'token' => hash('sha256', $token),
            'ip' => $clientIp,
            'expires' => self::time($now + self::CHALLENGE_SECONDS),
            'created' => self::time($now),
        ]);

        $link = rtrim($this->publicUrl, '/') . '/?signin=' . rawurlencode($token);
        $text = "Your Blood & Banners sign-in code is {$code}.\n\nOr open this link on the device you want to sign in on:\n{$link}\n\nThe code and link expire in 15 minutes. If you did not ask to sign in, ignore this email.";
        $html = '<p>Your Blood &amp; Banners sign-in code is <strong style="font-size:1.4em;letter-spacing:.15em">' . $code . '</strong>.</p>'
            . '<p><a href="' . htmlspecialchars($link, ENT_QUOTES) . '">Sign in to Blood &amp; Banners</a></p>'
            . '<p>The code and link expire in 15 minutes. If you did not ask to sign in, ignore this email.</p>';
        try {
            $this->mailer->send($email, "Your Blood & Banners sign-in code: {$code}", $text, $html);
        } catch (Throwable $error) {
            // A failed send is not counted toward the rate limit, and the caller still sees 202.
            $this->connection->prepare('DELETE FROM login_challenges WHERE id = :id')->execute(['id' => $id]);
            ($this->logger)('Sign-in mail failed: ' . $error->getMessage());
        }
    }

    public function verifyCode(string $email, string $code, string $deviceId, ?string $guestId, ?callable $transfer): array
    {
        $email = self::normalizeEmail($email);
        self::requireDevice($deviceId);
        $now = ($this->clock)();
        $this->connection->beginTransaction();
        try {
            $statement = $this->connection->prepare(
                'SELECT * FROM login_challenges WHERE email = :email AND consumed_at IS NULL AND expires_at > :now ORDER BY created_at DESC'
            );
            $statement->execute(['email' => $email, 'now' => self::time($now)]);
            $challenges = $statement->fetchAll();
            $hash = hash('sha256', trim($code));
            foreach ($challenges as $challenge) {
                if (hash_equals((string)$challenge['code_hash'], $hash)) {
                    $result = $this->complete($challenge, $deviceId, $guestId, $transfer, $now);
                    $this->connection->commit();
                    return $result;
                }
            }
            foreach ($challenges as $challenge) {
                $attempts = (int)$challenge['attempts'] + 1;
                $this->connection->prepare('UPDATE login_challenges SET attempts = :attempts, consumed_at = :consumed WHERE id = :id')
                    ->execute([
                        'attempts' => $attempts,
                        'consumed' => $attempts >= self::MAX_ATTEMPTS ? self::time($now) : null,
                        'id' => $challenge['id'],
                    ]);
            }
            $this->connection->commit();
            throw self::invalid();
        } catch (Throwable $error) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
            throw $error;
        }
    }

    public function verifyToken(string $token, string $deviceId, ?string $guestId, ?callable $transfer): array
    {
        self::requireDevice($deviceId);
        $now = ($this->clock)();
        $this->connection->beginTransaction();
        try {
            $statement = $this->connection->prepare(
                'SELECT * FROM login_challenges WHERE token_hash = :hash AND consumed_at IS NULL AND expires_at > :now'
            );
            $statement->execute(['hash' => hash('sha256', trim($token)), 'now' => self::time($now)]);
            $challenge = $statement->fetch();
            if (!$challenge) {
                throw self::invalid();
            }
            $result = $this->complete($challenge, $deviceId, $guestId, $transfer, $now);
            $this->connection->commit();
            return $result;
        } catch (Throwable $error) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
            throw $error;
        }
    }

    private function complete(array $challenge, string $deviceId, ?string $guestId, ?callable $transfer, int $now): array
    {
        $consume = $this->connection->prepare('UPDATE login_challenges SET consumed_at = :now WHERE id = :id AND consumed_at IS NULL');
        $consume->execute(['now' => self::time($now), 'id' => $challenge['id']]);
        if ($consume->rowCount() !== 1) {
            throw self::invalid();
        }

        $email = (string)$challenge['email'];
        $statement = $this->connection->prepare('SELECT id, display_name FROM users WHERE email = :email');
        $statement->execute(['email' => $email]);
        $user = $statement->fetch();
        if ($user) {
            $userId = (string)$user['id'];
            $displayName = (string)$user['display_name'];
        } else {
            $userId = bin2hex(random_bytes(16));
            $displayName = '';
            // Email-only accounts store an empty password hash, which password_verify never matches.
            $this->connection->prepare(
                "INSERT INTO users (id, email, password_hash, display_name, converted_guest_id, created_at)
                 VALUES (:id, :email, '', '', NULL, :created)"
            )->execute(['id' => $userId, 'email' => $email, 'created' => self::time($now)]);
        }

        $this->connection->prepare(
            'UPDATE friendships SET addressee_user_id = :user WHERE addressee_email = :email AND addressee_user_id IS NULL'
        )->execute(['user' => $userId, 'email' => $email]);

        if ($guestId !== null && $transfer !== null) {
            $guest = $this->connection->prepare('SELECT converted_user_id FROM guest_identities WHERE id = :id');
            $guest->execute(['id' => $guestId]);
            $row = $guest->fetch();
            if ($row && $row['converted_user_id'] === null) {
                $transfer($guestId, $userId);
                $this->connection->prepare('UPDATE guest_identities SET converted_user_id = :user WHERE id = :guest')
                    ->execute(['user' => $userId, 'guest' => $guestId]);
            }
        }

        return $this->auth->issuePair('user', $userId, $deviceId) + ['displayName' => $displayName];
    }

    private function recent(string $column, string $value, int $since): int
    {
        $column = $column === 'email' ? 'email' : 'client_ip';
        $statement = $this->connection->prepare("SELECT COUNT(*) FROM login_challenges WHERE {$column} = :value AND created_at >= :since");
        $statement->execute(['value' => $value, 'since' => self::time($since)]);
        return (int)$statement->fetchColumn();
    }

    private static function requireDevice(string $deviceId): void
    {
        if (trim($deviceId) === '') {
            throw new AccountException(400, 'deviceIdRequired', 'A device id is required.');
        }
    }

    private static function invalid(): AccountException
    {
        return new AccountException(400, 'invalidCode', 'That code is wrong or has expired. Request a new one.');
    }

    private static function time(int $timestamp): string
    {
        return gmdate('Y-m-d H:i:s', $timestamp);
    }
}
```

`api/src/Http/Application.php`:
- add `use BloodAndBanners\Account\AccountException; use BloodAndBanners\Account\AccountService; use BloodAndBanners\Account\FriendService; use BloodAndBanners\Auth\EmailSignInService;`
- constructor:
```php
    public function __construct(
        private readonly AuthService $auth,
        private readonly GameService $games,
        private readonly ?EmailSignInService $signIn = null,
        private readonly ?AccountService $accounts = null,
        private readonly ?FriendService $friends = null,
    ) {
    }
```
  (`AccountService`/`FriendService` classes are created in Tasks 3/4; PHP only resolves type names when a value is passed, so `null` defaults are fine meanwhile.)
- add routes right after the `/api/v1/auth/login` block (before `$principal = ...`):
```php
            if ($request->method === 'POST' && $request->path === '/api/v1/auth/email/start') {
                $this->service($this->signIn)->start((string)($request->body['email'] ?? ''), $request->clientIp);
                return new Response(202, ['sent' => true]);
            }
            if ($request->method === 'POST' && $request->path === '/api/v1/auth/email/verify') {
                $signIn = $this->service($this->signIn);
                $deviceId = (string)($request->body['deviceId'] ?? '');
                $guestId = $this->optionalGuestId($request);
                $transfer = fn(string $from, string $to) => $this->games->transferIdentity($from, $to);
                $token = (string)($request->body['token'] ?? '');
                return new Response(200, $token !== ''
                    ? $signIn->verifyToken($token, $deviceId, $guestId, $transfer)
                    : $signIn->verifyCode((string)($request->body['email'] ?? ''), (string)($request->body['code'] ?? ''), $deviceId, $guestId, $transfer));
            }
            if ($request->method === 'POST' && $request->path === '/api/v1/auth/logout') {
                $this->auth->logout((string)($request->body['refreshToken'] ?? ''));
                return new Response(204, []);
            }
```
- add a catch before `catch (DomainException $error)`:
```php
        } catch (AccountException $error) {
            return $this->error($error->status, $error->errorCode, $error->getMessage());
```
- add helpers:
```php
    /** A guest bearer token on sign-in means "bring my current seat along"; anything else is ignored. */
    private function optionalGuestId(Request $request): ?string
    {
        $token = $request->bearerToken();
        if ($token === null) {
            return null;
        }
        try {
            $principal = $this->auth->authenticate($token);
        } catch (DomainException) {
            return null;
        }
        return ($principal['kind'] ?? '') === 'guest' ? (string)$principal['identityId'] : null;
    }

    /**
     * @template T of object
     * @param T|null $service
     * @return T
     */
    private function service(?object $service): object
    {
        return $service ?? throw new \RuntimeException('Account services are not configured.');
    }

    private function requireUser(array $principal): string
    {
        if (($principal['kind'] ?? '') !== 'user') {
            throw new AccountException(403, 'accountRequired', 'Sign in to use this.');
        }
        return (string)$principal['identityId'];
    }
```

- [ ] **Step 6: Run tests**

Run: `php api/tests/run.php 2>&1 | grep -E "FAIL|tests,"`
Expected: `0 failures` (including all pre-existing tests).

- [ ] **Step 7: Commit**

```bash
git add api/migrations/005_accounts.* api/src/Account/AccountException.php api/src/Auth api/src/Http api/src/Game/GameService.php api/tests/AccountTestSupport.php api/tests/EmailSignInTest.php api/tests/run.php
git commit -m "Add passwordless email sign-in with code and link"
```

---

### Task 3: Account profile, seat resume, member re-join, commander-name preset

**Files:**
- Create: `api/src/Account/AccountService.php`
- Modify: `api/src/Game/GameService.php`, `api/src/Http/Application.php`, `api/tests/AccountTestSupport.php`
- Test: `api/tests/AccountTest.php`, `api/tests/run.php`

**Interfaces:**
- Consumes: `AccountException`, `account_context`, `sign_in`, `bearer` (Task 2).
- Produces:
  - `AccountService(PDO)` with `profile(string $userId): array{userId, email, displayName}` and `updateDisplayName(string $userId, string $name): array` (the profile).
  - `AccountService::normalizeName(string): ?string` (static).
  - `GameService::resumeSeat(string $gameId, string $identityId): array` (a projection plus `seatToken`).
  - `GameService::seatLimit(string $mode): int` (static).
  - Routes: `GET`/`PUT /api/v1/account`, `POST /api/v1/games/{id}/resume`.

- [ ] **Step 1: Update `account_context`** in `api/tests/AccountTestSupport.php` to pass the service:

```php
    return ['app' => new Application($auth, new GameService($db), $signIn, new AccountService($db)), 'db' => $db, 'mailer' => $mailer];
```
with `use BloodAndBanners\Account\AccountService;` at the top. Add helper:

```php
function create_game(array $ctx, array $session, string $mode = 'vanillaPvp', string $scenario = 'Four Corners'): array
{
    $created = $ctx['app']->handle(new Request('POST', '/api/v1/games', bearer($session), ['mode' => $mode, 'scenarioName' => $scenario]));
    expect_same(201, $created->status, 'game created');
    return $created->body;
}

function set_display_name(array $ctx, array $session, string $name): void
{
    $response = $ctx['app']->handle(new Request('PUT', '/api/v1/account', bearer($session), ['displayName' => $name]));
    expect_same(200, $response->status, 'name set');
}
```

- [ ] **Step 2: Write the failing tests** — `api/tests/AccountTest.php`

```php
<?php
declare(strict_types=1);

use BloodAndBanners\Http\Request;

function account_tests(): array
{
    return [
        'account profile starts unnamed and accepts a display name' => static function (): void {
            $ctx = account_context();
            $user = sign_in($ctx, 'me@example.com');
            $profile = $ctx['app']->handle(new Request('GET', '/api/v1/account', bearer($user)));
            expect_same(['userId' => $user['identityId'], 'email' => 'me@example.com', 'displayName' => ''], $profile->body, 'profile');
            $updated = $ctx['app']->handle(new Request('PUT', '/api/v1/account', bearer($user), ['displayName' => "  Lady \t Ash  "]));
            expect_same('Lady Ash', $updated->body['displayName'], 'whitespace collapsed');
            expect_same('Lady Ash', sign_in($ctx, 'me@example.com')['displayName'], 'name returned on next sign-in');
        },

        'display names must be 1 to 24 characters' => static function (): void {
            $ctx = account_context();
            $user = sign_in($ctx, 'me@example.com');
            foreach (['   ', str_repeat('x', 25)] as $bad) {
                $response = $ctx['app']->handle(new Request('PUT', '/api/v1/account', bearer($user), ['displayName' => $bad]));
                expect_same(400, $response->status, 'rejected');
                expect_same('invalidDisplayName', $response->body['error']['code'], 'code');
            }
        },

        'guests cannot use account routes' => static function (): void {
            $ctx = account_context();
            $guest = $ctx['app']->handle(new Request('POST', '/api/v1/auth/guest', body: ['deviceId' => 'g']))->body;
            $response = $ctx['app']->handle(new Request('GET', '/api/v1/account', bearer($guest)));
            expect_same(403, $response->status, 'forbidden');
            expect_same('accountRequired', $response->body['error']['code'], 'code');
        },

        'resume rotates the seat token' => static function (): void {
            $ctx = account_context();
            $user = sign_in($ctx, 'r@example.com');
            $created = create_game($ctx, $user);
            $gameId = $created['game']['gameId'];
            $resumed = $ctx['app']->handle(new Request('POST', "/api/v1/games/{$gameId}/resume", bearer($user)));
            expect_same(200, $resumed->status, 'resume ok');
            expect_true($resumed->body['seatToken'] !== $created['seatToken'], 'new token');
            $old = $ctx['app']->handle(new Request('GET', "/api/v1/games/{$gameId}", bearer($user) + ['x-seat-token' => $created['seatToken']]));
            expect_same(403, $old->status, 'old token rejected');
            $new = $ctx['app']->handle(new Request('GET', "/api/v1/games/{$gameId}", bearer($user) + ['x-seat-token' => $resumed->body['seatToken']]));
            expect_same(200, $new->status, 'new token accepted');
        },

        'resume by a non-member is not found' => static function (): void {
            $ctx = account_context();
            $host = sign_in($ctx, 'host@example.com');
            $other = sign_in($ctx, 'other@example.com');
            $gameId = create_game($ctx, $host)['game']['gameId'];
            $response = $ctx['app']->handle(new Request('POST', "/api/v1/games/{$gameId}/resume", bearer($other)));
            expect_same(404, $response->status, 'not a member');
        },

        'joining a game you already sit in returns your seat' => static function (): void {
            $ctx = account_context();
            $user = sign_in($ctx, 'again@example.com');
            $gameId = create_game($ctx, $user)['game']['gameId'];
            $joined = $ctx['app']->handle(new Request('POST', "/api/v1/games/{$gameId}/join", bearer($user)));
            expect_same(200, $joined->status, 'no 500 from duplicate membership');
            expect_same(1, count($joined->body['game']['memberships']), 'still one seat');
        },

        'signed-in seats start with the display name as commander name' => static function (): void {
            $ctx = account_context();
            $host = sign_in($ctx, 'a@example.com');
            set_display_name($ctx, $host, 'Ash');
            $twin = sign_in($ctx, 'b@example.com');
            set_display_name($ctx, $twin, 'ash');
            $gameId = create_game($ctx, $host)['game']['gameId'];
            $joined = $ctx['app']->handle(new Request('POST', "/api/v1/games/{$gameId}/join", bearer($twin)))->body;
            $names = array_column($joined['game']['memberships'], 'name');
            expect_same(['Ash', null], $names, 'host named; clashing name left empty');
        },
    ];
}
```

Register `AccountTest.php` and `account_tests()` in `run.php`.

- [ ] **Step 3: Run to verify failure**

Run: `php api/tests/run.php 2>&1 | grep -E "FAIL|tests,|Fatal"`
Expected: fatal — `AccountService` not found.

- [ ] **Step 4: Implement**

`api/src/Account/AccountService.php`:
```php
<?php
declare(strict_types=1);

namespace BloodAndBanners\Account;

use PDO;

final class AccountService
{
    public function __construct(private readonly PDO $connection)
    {
    }

    /** Same rules as commander names: collapsed whitespace, no control or format characters, 1–24 characters. */
    public static function normalizeName(string $raw): ?string
    {
        $collapsed = (string)preg_replace('/\s+/u', ' ', $raw);
        $name = trim((string)preg_replace('/[\p{Cc}\p{Cf}]/u', '', $collapsed));
        $length = preg_match_all('/./su', $name);
        return $name === '' || $length > 24 ? null : $name;
    }

    public function profile(string $userId): array
    {
        $statement = $this->connection->prepare('SELECT id, email, display_name FROM users WHERE id = :id');
        $statement->execute(['id' => $userId]);
        $user = $statement->fetch();
        if (!$user) {
            throw new AccountException(403, 'accountRequired', 'Sign in to use this.');
        }
        return ['userId' => $user['id'], 'email' => $user['email'], 'displayName' => (string)$user['display_name']];
    }

    public function updateDisplayName(string $userId, string $name): array
    {
        $normalized = self::normalizeName($name)
            ?? throw new AccountException(400, 'invalidDisplayName', 'Names must be 1 to 24 characters.');
        $this->connection->prepare('UPDATE users SET display_name = :name WHERE id = :id')
            ->execute(['name' => $normalized, 'id' => $userId]);
        return $this->profile($userId);
    }
}
```

`api/src/Game/GameService.php`:
- add:
```php
    public static function seatLimit(string $mode): int
    {
        return $mode === 'vanillaPvp' ? 2 : 5;
    }

    /** Gives a signed-in member their seat on this device: a fresh seat token replaces the old one everywhere. */
    public function resumeSeat(string $gameId, string $identityId): array
    {
        $this->gameRow($gameId);
        $seatToken = self::token();
        $statement = $this->connection->prepare(
            'UPDATE game_memberships SET seat_token_hash = :hash WHERE game_id = :game AND identity_id = :identity'
        );
        $statement->execute(['hash' => hash('sha256', $seatToken), 'game' => $gameId, 'identity' => $identityId]);
        if ($statement->rowCount() !== 1) {
            throw new GameServiceException('gameNotFound', 'You do not hold a seat in that game.');
        }
        return $this->projection($gameId, $identityId) + ['seatToken' => $seatToken];
    }

    /** A signed-in commander's seat starts with their display name, unless another seat already uses it. */
    private function presetCommanderName(string $gameId, string $identityId): void
    {
        $statement = $this->connection->prepare("SELECT display_name FROM users WHERE id = :id AND display_name <> ''");
        $statement->execute(['id' => $identityId]);
        $name = $statement->fetchColumn();
        if (!is_string($name)) {
            return;
        }
        foreach ($this->memberships($gameId) as $seat) {
            if ($seat['commander_name'] !== null && preg_match('/^' . preg_quote((string)$seat['commander_name'], '/') . '$/iu', $name) === 1) {
                return;
            }
        }
        $this->connection->prepare(
            'UPDATE game_memberships SET commander_name = :name WHERE game_id = :game AND identity_id = :identity AND commander_name IS NULL'
        )->execute(['name' => $name, 'game' => $gameId, 'identity' => $identityId]);
    }
```
- in `createGame`, call `$this->presetCommanderName($gameId, $hostIdentityId);` right after `insertMembership(...)`.
- in `joinGame`, replace `$maximum = $game['mode'] === 'vanillaPvp' ? 2 : 5;` with `$maximum = self::seatLimit($game['mode']);`; call `$this->presetCommanderName($gameId, $identityId);` right after `insertMembership(...)`; and at the very top of `joinGame` (before `beginTransaction`) add:
```php
        if ((int)$this->scalar('SELECT COUNT(*) FROM game_memberships WHERE game_id = :game AND identity_id = :identity', ['game' => $gameId, 'identity' => $identityId]) > 0) {
            return $this->resumeSeat($gameId, $identityId);
        }
```
  (Check `scalar`'s signature in GameService; it is used the same way in `joinGame`.)

`api/src/Http/Application.php` — add after `$principal = $this->principal($request);`:
```php
            if ($request->path === '/api/v1/account' && in_array($request->method, ['GET', 'PUT'], true)) {
                $userId = $this->requireUser($principal);
                $accounts = $this->service($this->accounts);
                return new Response(200, $request->method === 'GET'
                    ? $accounts->profile($userId)
                    : $accounts->updateDisplayName($userId, (string)($request->body['displayName'] ?? '')));
            }
            if (preg_match('#^/api/v1/games/([^/]+)/resume$#', $request->path, $matches) === 1 && $request->method === 'POST') {
                return new Response(200, $this->seatSession($this->games->resumeSeat($matches[1], $this->requireUser($principal))));
            }
```

- [ ] **Step 5: Run tests**

Run: `php api/tests/run.php 2>&1 | grep -E "FAIL|tests,"`
Expected: `0 failures`.

- [ ] **Step 6: Commit**

```bash
git add api/src/Account/AccountService.php api/src/Game/GameService.php api/src/Http/Application.php api/tests/AccountTest.php api/tests/AccountTestSupport.php api/tests/run.php
git commit -m "Add account profile, seat resume and display-name seats"
```

---

### Task 4: Friends service and routes

**Files:**
- Create: `api/src/Account/FriendService.php`
- Modify: `api/src/Http/Application.php`, `api/tests/AccountTestSupport.php`
- Test: `api/tests/FriendTest.php`, `api/tests/run.php`

**Interfaces:**
- Consumes: `EmailSignInService::normalizeEmail`, `AccountException`, test helpers.
- Produces:
  - `FriendService(PDO)` with:
    - `list(string $me): array{friends: list<{userId, displayName}>, incoming: list<{requestId, displayName}>, outgoing: list<{requestId, label}>}`.
    - `requestByEmail(string $me, string $email): array` and `requestFromSeat(string $me, string $gameId, string $seatId): array`.
    - `accept(string $me, string $requestId): array`, `decline(string $me, string $requestId): array` and `remove(string $me, string $otherUserId): array`.
    - `friendIds(string $me): list<string>` and `requestedIds(string $me): list<string>`.
    - All mutating methods return `list($me)`.
  - Routes: `GET /api/v1/friends`; `POST /api/v1/friends/requests`; `POST /api/v1/friends/requests/{id}/accept`; `POST /api/v1/friends/requests/{id}/decline`; `DELETE /api/v1/friends/{userId}`.

- [ ] **Step 1: Update `account_context`** to construct `$friends = new FriendService($db);` and pass `new Application($auth, new GameService($db), $signIn, new AccountService($db), $friends)` (add `use BloodAndBanners\Account\FriendService;`). Add helper:

```php
function befriend(array $ctx, array $a, array $b, string $bEmail): void
{
    $ctx['app']->handle(new Request('POST', '/api/v1/friends/requests', bearer($a), ['email' => $bEmail]));
    $incoming = $ctx['app']->handle(new Request('GET', '/api/v1/friends', bearer($b)))->body['incoming'];
    $ctx['app']->handle(new Request('POST', "/api/v1/friends/requests/{$incoming[0]['requestId']}/accept", bearer($b)));
}
```

- [ ] **Step 2: Write the failing tests** — `api/tests/FriendTest.php`

```php
<?php
declare(strict_types=1);

use BloodAndBanners\Http\Request;

function friend_tests(): array
{
    $friends = static fn(array $ctx, array $session): array =>
        $ctx['app']->handle(new Request('GET', '/api/v1/friends', bearer($session)))->body;
    $request = static fn(array $ctx, array $session, array $body) =>
        $ctx['app']->handle(new Request('POST', '/api/v1/friends/requests', bearer($session), $body));

    return [
        'request by email appears as incoming and accept makes friends' => static function () use ($friends, $request): void {
            $ctx = account_context();
            $a = sign_in($ctx, 'a@example.com');
            set_display_name($ctx, $a, 'Ash');
            $b = sign_in($ctx, 'b@example.com');
            set_display_name($ctx, $b, 'Bryn');
            $sent = $request($ctx, $a, ['email' => ' B@Example.com ']);
            expect_same(200, $sent->status, 'request ok');
            expect_same('b@example.com', $sent->body['outgoing'][0]['label'], 'typed email shown to requester');
            $incoming = $friends($ctx, $b)['incoming'];
            expect_same('Ash', $incoming[0]['displayName'], 'incoming shows requester name');
            $accepted = $ctx['app']->handle(new Request('POST', "/api/v1/friends/requests/{$incoming[0]['requestId']}/accept", bearer($b)));
            expect_same([['userId' => $a['identityId'], 'displayName' => 'Ash']], $accepted->body['friends'], 'b sees a');
            expect_same([['userId' => $b['identityId'], 'displayName' => 'Bryn']], $friends($ctx, $a)['friends'], 'a sees b');
        },

        'request to an unknown email waits for that account' => static function () use ($friends, $request): void {
            $ctx = account_context();
            $a = sign_in($ctx, 'a@example.com');
            $request($ctx, $a, ['email' => 'later@example.com']);
            expect_same(0, count($ctx['mailer']->sent) - 1, 'no invitation email sent');
            $later = sign_in($ctx, 'Later@example.com');
            expect_same(1, count($friends($ctx, $later)['incoming']), 'claimed on first sign-in');
        },

        'mutual requests accept automatically' => static function () use ($friends, $request): void {
            $ctx = account_context();
            $a = sign_in($ctx, 'a@example.com');
            $b = sign_in($ctx, 'b@example.com');
            $request($ctx, $a, ['email' => 'b@example.com']);
            $back = $request($ctx, $b, ['email' => 'a@example.com']);
            expect_same(200, $back->status, 'friend back ok');
            expect_same(1, count($back->body['friends']), 'now friends');
            expect_same([], $back->body['incoming'], 'no leftover request');
        },

        'self and duplicate requests are rejected' => static function () use ($request): void {
            $ctx = account_context();
            $a = sign_in($ctx, 'a@example.com');
            expect_same('cannotFriendSelf', $request($ctx, $a, ['email' => 'A@example.com'])->body['error']['code'], 'self');
            $request($ctx, $a, ['email' => 'b@example.com']);
            $dup = $request($ctx, $a, ['email' => 'b@example.com']);
            expect_same(409, $dup->status, 'duplicate');
            expect_same('alreadyRequested', $dup->body['error']['code'], 'code');
        },

        'already friends is a conflict' => static function () use ($request): void {
            $ctx = account_context();
            $a = sign_in($ctx, 'a@example.com');
            $b = sign_in($ctx, 'b@example.com');
            befriend($ctx, $a, $b, 'b@example.com');
            expect_same('alreadyFriends', $request($ctx, $b, ['email' => 'a@example.com'])->body['error']['code'], 'reverse');
            expect_same('alreadyFriends', $request($ctx, $a, ['email' => 'b@example.com'])->body['error']['code'], 'same direction');
        },

        'only the addressee can accept; either side can decline' => static function () use ($friends, $request): void {
            $ctx = account_context();
            $a = sign_in($ctx, 'a@example.com');
            $b = sign_in($ctx, 'b@example.com');
            $id = $request($ctx, $a, ['email' => 'b@example.com'])->body['outgoing'][0]['requestId'];
            expect_same(404, $ctx['app']->handle(new Request('POST', "/api/v1/friends/requests/{$id}/accept", bearer($a)))->status, 'requester cannot accept');
            $cancel = $ctx['app']->handle(new Request('POST', "/api/v1/friends/requests/{$id}/decline", bearer($a)));
            expect_same([], $cancel->body['outgoing'], 'requester cancelled');
            expect_same([], $friends($ctx, $b)['incoming'], 'gone for addressee');
        },

        'unfriend removes both directions' => static function () use ($friends): void {
            $ctx = account_context();
            $a = sign_in($ctx, 'a@example.com');
            $b = sign_in($ctx, 'b@example.com');
            befriend($ctx, $a, $b, 'b@example.com');
            $removed = $ctx['app']->handle(new Request('DELETE', "/api/v1/friends/{$a['identityId']}", bearer($b)));
            expect_same([], $removed->body['friends'], 'b no longer has a');
            expect_same([], $friends($ctx, $a)['friends'], 'a no longer has b');
            expect_same(404, $ctx['app']->handle(new Request('DELETE', "/api/v1/friends/{$a['identityId']}", bearer($b)))->status, 'second removal 404');
        },

        'request from a shared game seat hides the email' => static function () use ($request): void {
            $ctx = account_context();
            $a = sign_in($ctx, 'a@example.com');
            $b = sign_in($ctx, 'b@example.com');
            set_display_name($ctx, $b, 'Bryn');
            $gameId = create_game($ctx, $a)['game']['gameId'];
            $joined = $ctx['app']->handle(new Request('POST', "/api/v1/games/{$gameId}/join", bearer($b)))->body;
            $bSeat = $joined['game']['viewer']['seatId'];
            $sent = $request($ctx, $a, ['gameId' => $gameId, 'seatId' => $bSeat]);
            expect_same(200, $sent->status, 'seat request ok');
            expect_same('Bryn', $sent->body['outgoing'][0]['label'], 'label is the name, not the email');
        },

        'seat requests need a shared game and an account seat' => static function () use ($request): void {
            $ctx = account_context();
            $a = sign_in($ctx, 'a@example.com');
            $outsider = sign_in($ctx, 'o@example.com');
            $created = create_game($ctx, $a);
            $gameId = $created['game']['gameId'];
            $guest = $ctx['app']->handle(new Request('POST', '/api/v1/auth/guest', body: ['deviceId' => 'g']))->body;
            $guestSeat = $ctx['app']->handle(new Request('POST', "/api/v1/games/{$gameId}/join", bearer($guest)))->body['game']['viewer']['seatId'];
            expect_same('cannotFriendSeat', $request($ctx, $a, ['gameId' => $gameId, 'seatId' => $guestSeat])->body['error']['code'], 'guest seat');
            $hostSeat = $created['game']['viewer']['seatId'];
            expect_same('cannotFriendSeat', $request($ctx, $outsider, ['gameId' => $gameId, 'seatId' => $hostSeat])->body['error']['code'], 'not in game');
        },
    ];
}
```

Register `FriendTest.php` / `friend_tests()` in `run.php`.

- [ ] **Step 3: Run to verify failure** — `php api/tests/run.php 2>&1 | grep -E "FAIL|tests,|Fatal"` → fatal, `FriendService` not found.

- [ ] **Step 4: Implement** `api/src/Account/FriendService.php`

```php
<?php
declare(strict_types=1);

namespace BloodAndBanners\Account;

use BloodAndBanners\Auth\EmailSignInService;
use PDO;
use Throwable;

/** Friend requests by email or from a shared game; a pair is friends once both sides agree. */
final class FriendService
{
    private const UNNAMED = 'Unnamed commander';

    public function __construct(private readonly PDO $connection)
    {
    }

    public function list(string $me): array
    {
        $friends = $this->connection->prepare(
            "SELECT u.id, u.display_name FROM friendships f
             INNER JOIN users u ON u.id = CASE WHEN f.requester_user_id = :me1 THEN f.addressee_user_id ELSE f.requester_user_id END
             WHERE f.status = 'accepted' AND (f.requester_user_id = :me2 OR f.addressee_user_id = :me3)
             ORDER BY u.display_name, u.id"
        );
        $friends->execute(['me1' => $me, 'me2' => $me, 'me3' => $me]);

        $incoming = $this->connection->prepare(
            "SELECT f.id, u.display_name FROM friendships f INNER JOIN users u ON u.id = f.requester_user_id
             WHERE f.status = 'pending' AND f.addressee_user_id = :me ORDER BY f.created_at, f.id"
        );
        $incoming->execute(['me' => $me]);

        $outgoing = $this->connection->prepare(
            "SELECT f.id, f.addressee_email, f.from_game, u.display_name FROM friendships f
             LEFT JOIN users u ON u.id = f.addressee_user_id
             WHERE f.status = 'pending' AND f.requester_user_id = :me ORDER BY f.created_at, f.id"
        );
        $outgoing->execute(['me' => $me]);

        return [
            'friends' => array_map(static fn(array $row): array => [
                'userId' => $row['id'],
                'displayName' => self::name($row['display_name']),
            ], $friends->fetchAll()),
            'incoming' => array_map(static fn(array $row): array => [
                'requestId' => $row['id'],
                'displayName' => self::name($row['display_name']),
            ], $incoming->fetchAll()),
            'outgoing' => array_map(static fn(array $row): array => [
                'requestId' => $row['id'],
                // A request made from a game never reveals the other player's email address.
                'label' => (int)$row['from_game'] === 1 ? self::name($row['display_name']) : $row['addressee_email'],
            ], $outgoing->fetchAll()),
        ];
    }

    public function requestByEmail(string $me, string $email): array
    {
        $email = EmailSignInService::normalizeEmail($email);
        $statement = $this->connection->prepare('SELECT id FROM users WHERE email = :email');
        $statement->execute(['email' => $email]);
        $target = $statement->fetchColumn();
        return $this->request($me, is_string($target) ? $target : null, $email, false);
    }

    public function requestFromSeat(string $me, string $gameId, string $seatId): array
    {
        $mine = $this->connection->prepare('SELECT COUNT(*) FROM game_memberships WHERE game_id = :game AND identity_id = :me');
        $mine->execute(['game' => $gameId, 'me' => $me]);
        $seat = $this->connection->prepare(
            'SELECT u.id, u.email FROM game_memberships m INNER JOIN users u ON u.id = m.identity_id WHERE m.game_id = :game AND m.id = :seat'
        );
        $seat->execute(['game' => $gameId, 'seat' => $seatId]);
        $target = $seat->fetch();
        if ((int)$mine->fetchColumn() === 0 || !$target) {
            throw new AccountException(400, 'cannotFriendSeat', 'That commander cannot be friended.');
        }
        return $this->request($me, (string)$target['id'], (string)$target['email'], true);
    }

    public function accept(string $me, string $requestId): array
    {
        $statement = $this->connection->prepare(
            "UPDATE friendships SET status = 'accepted', responded_at = :now WHERE id = :id AND addressee_user_id = :me AND status = 'pending'"
        );
        $statement->execute(['now' => self::now(), 'id' => $requestId, 'me' => $me]);
        if ($statement->rowCount() !== 1) {
            throw new AccountException(404, 'requestNotFound', 'That friend request no longer exists.');
        }
        return $this->list($me);
    }

    public function decline(string $me, string $requestId): array
    {
        $statement = $this->connection->prepare(
            "DELETE FROM friendships WHERE id = :id AND status = 'pending' AND (addressee_user_id = :me1 OR requester_user_id = :me2)"
        );
        $statement->execute(['id' => $requestId, 'me1' => $me, 'me2' => $me]);
        if ($statement->rowCount() !== 1) {
            throw new AccountException(404, 'requestNotFound', 'That friend request no longer exists.');
        }
        return $this->list($me);
    }

    public function remove(string $me, string $otherUserId): array
    {
        $statement = $this->connection->prepare(
            "DELETE FROM friendships WHERE status = 'accepted'
             AND ((requester_user_id = :me1 AND addressee_user_id = :other1) OR (requester_user_id = :other2 AND addressee_user_id = :me2))"
        );
        $statement->execute(['me1' => $me, 'other1' => $otherUserId, 'other2' => $otherUserId, 'me2' => $me]);
        if ($statement->rowCount() === 0) {
            throw new AccountException(404, 'friendNotFound', 'You are not friends with that commander.');
        }
        return $this->list($me);
    }

    /** @return list<string> */
    public function friendIds(string $me): array
    {
        $statement = $this->connection->prepare(
            "SELECT CASE WHEN requester_user_id = :me1 THEN addressee_user_id ELSE requester_user_id END
             FROM friendships WHERE status = 'accepted' AND (requester_user_id = :me2 OR addressee_user_id = :me3)"
        );
        $statement->execute(['me1' => $me, 'me2' => $me, 'me3' => $me]);
        return array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return list<string> Accounts this user has a pending request out to. */
    public function requestedIds(string $me): array
    {
        $statement = $this->connection->prepare(
            "SELECT addressee_user_id FROM friendships WHERE status = 'pending' AND requester_user_id = :me AND addressee_user_id IS NOT NULL"
        );
        $statement->execute(['me' => $me]);
        return array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    private function request(string $me, ?string $targetId, string $email, bool $fromGame): array
    {
        $mine = $this->connection->prepare('SELECT email FROM users WHERE id = :me');
        $mine->execute(['me' => $me]);
        if ($targetId === $me || $mine->fetchColumn() === $email) {
            throw new AccountException(400, 'cannotFriendSelf', 'You cannot send a friend request to yourself.');
        }

        $this->connection->beginTransaction();
        try {
            if ($targetId !== null) {
                $reverse = $this->connection->prepare(
                    'SELECT id, status FROM friendships WHERE requester_user_id = :target AND addressee_user_id = :me'
                );
                $reverse->execute(['target' => $targetId, 'me' => $me]);
                $row = $reverse->fetch();
                if ($row && $row['status'] === 'accepted') {
                    throw new AccountException(409, 'alreadyFriends', 'You are already friends.');
                }
                if ($row) {
                    // They already asked: asking back is the same as accepting.
                    $this->connection->prepare("UPDATE friendships SET status = 'accepted', responded_at = :now WHERE id = :id")
                        ->execute(['now' => self::now(), 'id' => $row['id']]);
                    $this->connection->commit();
                    return $this->list($me);
                }
            }

            $existing = $this->connection->prepare('SELECT status FROM friendships WHERE requester_user_id = :me AND addressee_email = :email');
            $existing->execute(['me' => $me, 'email' => $email]);
            $status = $existing->fetchColumn();
            if ($status === 'accepted') {
                throw new AccountException(409, 'alreadyFriends', 'You are already friends.');
            }
            if ($status === 'pending') {
                throw new AccountException(409, 'alreadyRequested', 'You already sent that request.');
            }

            $this->connection->prepare(
                "INSERT INTO friendships (id, requester_user_id, addressee_user_id, addressee_email, status, from_game, created_at, responded_at)
                 VALUES (:id, :me, :target, :email, 'pending', :fromGame, :now, NULL)"
            )->execute([
                'id' => bin2hex(random_bytes(16)),
                'me' => $me,
                'target' => $targetId,
                'email' => $email,
                'fromGame' => $fromGame ? 1 : 0,
                'now' => self::now(),
            ]);
            $this->connection->commit();
            return $this->list($me);
        } catch (Throwable $error) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
            throw $error;
        }
    }

    private static function name(?string $displayName): string
    {
        return $displayName === null || $displayName === '' ? self::UNNAMED : $displayName;
    }

    private static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}
```

Routes in `Application.php` (after the account routes):
```php
            if (str_starts_with($request->path, '/api/v1/friends')) {
                $me = $this->requireUser($principal);
                $friends = $this->service($this->friends);
                if ($request->method === 'GET' && $request->path === '/api/v1/friends') {
                    return new Response(200, $friends->list($me));
                }
                if ($request->method === 'POST' && $request->path === '/api/v1/friends/requests') {
                    $gameId = (string)($request->body['gameId'] ?? '');
                    return new Response(200, $gameId !== ''
                        ? $friends->requestFromSeat($me, $gameId, (string)($request->body['seatId'] ?? ''))
                        : $friends->requestByEmail($me, (string)($request->body['email'] ?? '')));
                }
                if ($request->method === 'POST' && preg_match('#^/api/v1/friends/requests/([^/]+)/(accept|decline)$#', $request->path, $matches) === 1) {
                    return new Response(200, $matches[2] === 'accept' ? $friends->accept($me, $matches[1]) : $friends->decline($me, $matches[1]));
                }
                if ($request->method === 'DELETE' && preg_match('#^/api/v1/friends/([^/]+)$#', $request->path, $matches) === 1) {
                    return new Response(200, $friends->remove($me, $matches[1]));
                }
            }
```

- [ ] **Step 5: Run tests** — `php api/tests/run.php 2>&1 | grep -E "FAIL|tests,"` → `0 failures`.

- [ ] **Step 6: Commit**

```bash
git add api/src/Account/FriendService.php api/src/Http/Application.php api/tests/FriendTest.php api/tests/AccountTestSupport.php api/tests/run.php
git commit -m "Add friend requests by email and from shared games"
```

---

### Task 5: Start-page game feed and seat friend flags

**Files:**
- Modify: `api/src/Account/AccountService.php`, `api/src/Game/GameService.php`, `api/src/Http/Application.php`, `api/tests/AccountTestSupport.php`
- Test: `api/tests/AccountGamesTest.php`, `api/tests/run.php`

**Interfaces:**
- Consumes: `FriendService::friendIds`, `requestedIds`; `GameService::seatLimit`.
- Produces:
  - `AccountService(PDO, FriendService)` with `games(string $me): array{yours: list<Row>, friends: list<Row>}`.
  - Each Row is `{gameId, mode, scenarioName, lifecycle, seats: list<{seatIndex, name, isFriend}>, canJoin, updatedAt}`.
  - Route `GET /api/v1/account/games`.
  - Each `memberships[]` entry in a game projection gains `isAccount`, `isFriend` and `friendRequested` (bools).

- [ ] **Step 1: Update `account_context`**: `new AccountService($db, $friends)` (construct `$friends` before it).

- [ ] **Step 2: Write the failing tests** — `api/tests/AccountGamesTest.php`

```php
<?php
declare(strict_types=1);

use BloodAndBanners\Http\Request;

function account_games_tests(): array
{
    $games = static fn(array $ctx, array $session): array =>
        $ctx['app']->handle(new Request('GET', '/api/v1/account/games', bearer($session)))->body;

    return [
        'your unfinished games are listed newest first' => static function () use ($games): void {
            $ctx = account_context();
            $me = sign_in($ctx, 'me@example.com');
            $first = create_game($ctx, $me)['game']['gameId'];
            $second = create_game($ctx, $me, 'coop', 'Void Reaches')['game']['gameId'];
            $ctx['db']->prepare("UPDATE games SET updated_at = '2000-01-01 00:00:00' WHERE id = :id")->execute(['id' => $first]);
            $ctx['db']->prepare("UPDATE games SET lifecycle = 'completed' WHERE id = :id")->execute(['id' => $second]);
            $third = create_game($ctx, $me)['game']['gameId'];
            $yours = array_column($games($ctx, $me)['yours'], 'gameId');
            expect_same([$third, $first], $yours, 'completed hidden, newest first');
        },

        'friends games exclude your own seats, pending friends and finished games' => static function () use ($games): void {
            $ctx = account_context();
            $me = sign_in($ctx, 'me@example.com');
            $friend = sign_in($ctx, 'f@example.com');
            set_display_name($ctx, $friend, 'Fen');
            $stranger = sign_in($ctx, 's@example.com');
            befriend($ctx, $me, $friend, 'f@example.com');
            $ctx['app']->handle(new Request('POST', '/api/v1/friends/requests', bearer($me), ['email' => 's@example.com']));

            $open = create_game($ctx, $friend)['game']['gameId'];
            $shared = create_game($ctx, $friend)['game']['gameId'];
            $ctx['app']->handle(new Request('POST', "/api/v1/games/{$shared}/join", bearer($me)));
            $done = create_game($ctx, $friend)['game']['gameId'];
            $ctx['db']->prepare("UPDATE games SET lifecycle = 'completed' WHERE id = :id")->execute(['id' => $done]);
            create_game($ctx, $stranger);

            $rows = $games($ctx, $me)['friends'];
            expect_same([$open], array_column($rows, 'gameId'), 'only the open friend game');
            expect_same(true, $rows[0]['canJoin'], 'lobby with a free seat');
            expect_same([['seatIndex' => 1, 'name' => 'Fen', 'isFriend' => true]], $rows[0]['seats'], 'seats');
        },

        'full or started friend games cannot be joined' => static function () use ($games): void {
            $ctx = account_context();
            $me = sign_in($ctx, 'me@example.com');
            $friend = sign_in($ctx, 'f@example.com');
            befriend($ctx, $me, $friend, 'f@example.com');
            $full = create_game($ctx, $friend)['game']['gameId'];
            $guest = $ctx['app']->handle(new Request('POST', '/api/v1/auth/guest', body: ['deviceId' => 'g']))->body;
            $ctx['app']->handle(new Request('POST', "/api/v1/games/{$full}/join", bearer($guest)));
            expect_same(false, $games($ctx, $me)['friends'][0]['canJoin'], 'full duel');
        },

        'projection marks account seats, friends and pending requests' => static function (): void {
            $ctx = account_context();
            $me = sign_in($ctx, 'me@example.com');
            $friend = sign_in($ctx, 'f@example.com');
            $other = sign_in($ctx, 'o@example.com');
            befriend($ctx, $me, $friend, 'f@example.com');
            $ctx['app']->handle(new Request('POST', '/api/v1/friends/requests', bearer($me), ['email' => 'o@example.com']));
            $created = create_game($ctx, $me, 'coop', 'Void Reaches');
            $gameId = $created['game']['gameId'];
            $ctx['app']->handle(new Request('POST', "/api/v1/games/{$gameId}/join", bearer($friend)));
            $ctx['app']->handle(new Request('POST', "/api/v1/games/{$gameId}/join", bearer($other)));
            $guest = $ctx['app']->handle(new Request('POST', '/api/v1/auth/guest', body: ['deviceId' => 'g']))->body;
            $ctx['app']->handle(new Request('POST', "/api/v1/games/{$gameId}/join", bearer($guest)));
            $view = $ctx['app']->handle(new Request('GET', "/api/v1/games/{$gameId}", bearer($me) + ['x-seat-token' => $created['seatToken']]))->body;
            $flags = array_map(static fn(array $m): array => [$m['isAccount'], $m['isFriend'], $m['friendRequested']], $view['memberships']);
            expect_same([[true, false, false], [true, true, false], [true, false, true], [false, false, false]], $flags, 'me, friend, requested, guest');
        },
    ];
}
```

Register in `run.php`.

- [ ] **Step 3: Run to verify failure** — expect FAIL (route 404 / missing `isAccount` key).

- [ ] **Step 4: Implement**

`AccountService`: constructor `public function __construct(private readonly PDO $connection, private readonly FriendService $friends)`, plus:
```php
    private const OPEN_LIFECYCLES = "('lobby', 'setup', 'draft', 'active')";
    private const FEED_LIMIT = 20;

    public function games(string $me): array
    {
        $friendIds = $this->friends->friendIds($me);
        $friendSet = array_fill_keys($friendIds, true);

        $yours = $this->connection->prepare(
            'SELECT g.id, g.mode, g.lifecycle, g.scenario_name, g.updated_at FROM games g
             INNER JOIN game_memberships m ON m.game_id = g.id
             WHERE m.identity_id = :me AND g.lifecycle IN ' . self::OPEN_LIFECYCLES . '
             ORDER BY g.updated_at DESC LIMIT ' . self::FEED_LIMIT
        );
        $yours->execute(['me' => $me]);

        $friendRows = [];
        if ($friendIds !== []) {
            $placeholders = implode(', ', array_fill(0, count($friendIds), '?'));
            $statement = $this->connection->prepare(
                'SELECT g.id, g.mode, g.lifecycle, g.scenario_name, g.updated_at FROM games g
                 WHERE g.lifecycle IN ' . self::OPEN_LIFECYCLES . "
                   AND g.id IN (SELECT game_id FROM game_memberships WHERE identity_id IN ({$placeholders}))
                   AND g.id NOT IN (SELECT game_id FROM game_memberships WHERE identity_id = ?)
                 ORDER BY g.updated_at DESC LIMIT " . self::FEED_LIMIT
            );
            $statement->execute([...$friendIds, $me]);
            $friendRows = $statement->fetchAll();
        }

        return [
            'yours' => array_map(fn(array $game): array => $this->row($game, $friendSet), $yours->fetchAll()),
            'friends' => array_map(fn(array $game): array => $this->row($game, $friendSet), $friendRows),
        ];
    }

    private function row(array $game, array $friendSet): array
    {
        $statement = $this->connection->prepare(
            'SELECT seat_index, commander_name, identity_id FROM game_memberships WHERE game_id = :game ORDER BY seat_index'
        );
        $statement->execute(['game' => $game['id']]);
        $seats = $statement->fetchAll();
        return [
            'gameId' => $game['id'],
            'mode' => $game['mode'],
            'scenarioName' => $game['scenario_name'],
            'lifecycle' => $game['lifecycle'],
            'seats' => array_map(static fn(array $seat): array => [
                'seatIndex' => (int)$seat['seat_index'],
                'name' => $seat['commander_name'] === null ? null : (string)$seat['commander_name'],
                'isFriend' => isset($friendSet[$seat['identity_id']]),
            ], $seats),
            'canJoin' => $game['lifecycle'] === 'lobby' && count($seats) < GameService::seatLimit((string)$game['mode']),
            'updatedAt' => (string)$game['updated_at'],
        ];
    }
```
(add `use BloodAndBanners\Game\GameService;`).

`GameService::projection` — before the `return [...]`, compute flags:
```php
        $accountFlags = $this->accountFlags($memberships, $identityId);
```
and in the `'memberships' => array_map(...)` closure, change to `static fn(array $seat): array => [...existing keys..., ] + $accountFlags[$seat['identity_id']]` — make it a non-static closure using `use ($accountFlags)`. Add:
```php
    /** @return array<string, array{isAccount: bool, isFriend: bool, friendRequested: bool}> keyed by identity id. */
    private function accountFlags(array $memberships, string $viewerId): array
    {
        $ids = array_values(array_unique(array_map(static fn(array $seat): string => (string)$seat['identity_id'], $memberships)));
        $accounts = [];
        if ($ids !== []) {
            $placeholders = implode(', ', array_fill(0, count($ids), '?'));
            $statement = $this->connection->prepare("SELECT id FROM users WHERE id IN ({$placeholders})");
            $statement->execute($ids);
            $accounts = array_fill_keys(array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN)), true);
        }
        $viewerIsAccount = isset($accounts[$viewerId]);
        $friendService = new FriendService($this->connection);
        $friends = $viewerIsAccount ? array_fill_keys($friendService->friendIds($viewerId), true) : [];
        $requested = $viewerIsAccount ? array_fill_keys($friendService->requestedIds($viewerId), true) : [];
        $flags = [];
        foreach ($ids as $id) {
            $flags[$id] = [
                'isAccount' => isset($accounts[$id]),
                'isFriend' => isset($friends[$id]),
                'friendRequested' => isset($requested[$id]),
            ];
        }
        return $flags;
    }
```
(add `use BloodAndBanners\Account\FriendService;`; confirm `memberships()` selects `identity_id` — it does.)

`Application.php` route (after the account routes):
```php
            if ($request->method === 'GET' && $request->path === '/api/v1/account/games') {
                return new Response(200, $this->service($this->accounts)->games($this->requireUser($principal)));
            }
```

- [ ] **Step 5: Run tests** — `php api/tests/run.php 2>&1 | grep -E "FAIL|tests,"` → `0 failures`.

- [ ] **Step 6: Commit**

```bash
git add api/src api/tests
git commit -m "Add account game feed and seat friend flags"
```

---

### Task 6: Wire the API entrypoints

**Files:**
- Modify: `api/public/index.php`, `scripts/deployment/play/api/index.php`, `api/.gitignore` or root `.gitignore` (ignore `api/var/mail.log`)
- Test: manual smoke via `scripts/start-api.ps1`

**Interfaces:**
- Consumes: everything from Tasks 1–5.

- [ ] **Step 1: Dev entrypoint** — in `api/public/index.php` replace the `$application = ...` line with:

```php
$auth = new AuthService($connection, $signingKey);
$friends = new FriendService($connection);
$publicUrl = getenv('BLOOD_BANNERS_PUBLIC_URL') ?: 'http://localhost:5016';
$signIn = new EmailSignInService($connection, $auth, new LogMailer(dirname(__DIR__) . '/var/mail.log'), $publicUrl);
$application = new Application($auth, new GameService($connection), $signIn, new AccountService($connection, $friends), $friends);
```
and add `use` lines for `AccountService`, `FriendService`, `EmailSignInService`, `LogMailer`.

- [ ] **Step 2: Production entrypoint** — in `scripts/deployment/play/api/index.php` replace the `$application = new Application(...)` block with:

```php
        $auth = new AuthService($connection, $config->signingKey);
        $friends = new FriendService($connection);
        $signIn = new EmailSignInService(
            $connection,
            $auth,
            MailerFactory::fromConfig($config, $bloodRoot . '/server/mail.log'),
            $config->publicUrl,
        );
        $application = new Application(
            $auth,
            new GameService($connection),
            $signIn,
            new AccountService($connection, $friends),
            $friends,
        );
```
with `use` lines for `AccountService`, `FriendService`, `EmailSignInService`, `MailerFactory`.

- [ ] **Step 3: Ignore the dev mail log** — check `git check-ignore -v api/var/mail.log`; if not ignored, add `api/var/mail.log` to the root `.gitignore`.

- [ ] **Step 4: Smoke test**

```powershell
$env:BLOOD_BANNERS_SIGNING_KEY = 'local-development-signing-key-32-bytes'
.\scripts\start-api.ps1    # run in background
```
```bash
curl -s -X POST localhost:8080/api/v1/auth/email/start -H 'content-type: application/json' -d '{"email":"me@example.com"}'
tail -8 api/var/mail.log      # shows the code and http://localhost:5016/?signin=… link
curl -s -X POST localhost:8080/api/v1/auth/email/verify -H 'content-type: application/json' -d '{"email":"me@example.com","code":"<code>","deviceId":"cli"}'
```
Expected: `{"sent":true}`, then a session JSON with `"kind":"user"`. Stop the server. Also run `php -l scripts/deployment/play/api/index.php`.

- [ ] **Step 5: Run the full PHP suite and the release-build test**

Run: `php api/tests/run.php 2>&1 | grep -E "FAIL|tests,"` → `0 failures`.
Run: `pwsh -File tests/game-release-build.ps1` (or `powershell -File`) → PASS, and confirm the staged release contains `api/src/Vendor/PHPMailer/PHPMailer.php` and `src/Mail/*.php` (the test prints the staging path; `Get-ChildItem -Recurse` it).

- [ ] **Step 6: Commit**

```bash
git add api/public/index.php scripts/deployment/play/api/index.php .gitignore
git commit -m "Wire account services into API entrypoints"
```

---

### Task 7: Contracts and fixtures

**Files:**
- Create: `src/BloodAndBanners.Contracts/AccountContracts.cs`
- Modify: `src/BloodAndBanners.Contracts/AuthSession.cs`, `src/BloodAndBanners.Contracts/GameProjection.cs` (`MembershipProjection`)
- Create: `tests/fixtures/auth-session.json`, `tests/fixtures/account-games.json`, `tests/fixtures/friends-list.json`
- Test: `tests/BloodAndBanners.Contracts.Tests/AccountContractTests.cs`

**Interfaces:**
- Produces (namespace `BloodAndBanners.Contracts`):
  - `record AccountProfile(string UserId, string Email, string DisplayName)`.
  - `record FriendSummary(string UserId, string DisplayName)`.
  - `record IncomingFriendRequest(string RequestId, string DisplayName)`.
  - `record OutgoingFriendRequest(string RequestId, string Label)`.
  - `record FriendsList(IReadOnlyList<FriendSummary> Friends, IReadOnlyList<IncomingFriendRequest> Incoming, IReadOnlyList<OutgoingFriendRequest> Outgoing)`.
  - `record AccountGameSeat(int SeatIndex, string? Name, bool IsFriend)`.
  - `record AccountGameRow(string GameId, GameMode Mode, string ScenarioName, GameLifecycle Lifecycle, IReadOnlyList<AccountGameSeat> Seats, bool CanJoin, string UpdatedAt)`.
  - `record AccountGames(IReadOnlyList<AccountGameRow> Yours, IReadOnlyList<AccountGameRow> Friends)`.
  - `AuthSession.DisplayName` (`string?`, init).
  - `MembershipProjection.IsAccount`, `IsFriend` and `FriendRequested` (`bool`, init).

- [ ] **Step 1: Fixtures** (shapes copied from the API in Tasks 2–5)

`tests/fixtures/auth-session.json`:
```json
{"identityId":"user-1","kind":"user","accessToken":"access","refreshToken":"refresh","displayName":"Ash"}
```
`tests/fixtures/account-games.json`:
```json
{"yours":[{"gameId":"g1","mode":"vanillaPvp","scenarioName":"Four Corners","lifecycle":"active","seats":[{"seatIndex":1,"name":"Ash","isFriend":false},{"seatIndex":2,"name":null,"isFriend":true}],"canJoin":false,"updatedAt":"2026-10-21 10:00:00"}],
 "friends":[{"gameId":"g2","mode":"coop","scenarioName":"Void Reaches","lifecycle":"lobby","seats":[{"seatIndex":1,"name":"Fen","isFriend":true}],"canJoin":true,"updatedAt":"2026-10-21 09:00:00"}]}
```
`tests/fixtures/friends-list.json`:
```json
{"friends":[{"userId":"u2","displayName":"Fen"}],"incoming":[{"requestId":"r1","displayName":"Bryn"}],"outgoing":[{"requestId":"r2","label":"later@example.com"}]}
```

- [ ] **Step 2: Write the failing test** — `tests/BloodAndBanners.Contracts.Tests/AccountContractTests.cs`

```csharp
using System.Text.Json;
using BloodAndBanners.Contracts;

namespace BloodAndBanners.Contracts.Tests;

public sealed class AccountContractTests
{
    [Fact]
    public void Auth_session_carries_the_optional_display_name()
    {
        var session = Read<AuthSession>("auth-session.json");
        Assert.Equal("user", session.Kind);
        Assert.Equal("Ash", session.DisplayName);
        Assert.Null(JsonSerializer.Deserialize<AuthSession>(
            """{"identityId":"g","kind":"guest","accessToken":"a","refreshToken":"r"}""", ContractJson.Options)!.DisplayName);
    }

    [Fact]
    public void Account_games_fixture_reads_modes_lifecycles_and_seats()
    {
        var games = Read<AccountGames>("account-games.json");
        var mine = Assert.Single(games.Yours);
        Assert.Equal(GameMode.VanillaPvp, mine.Mode);
        Assert.Equal(GameLifecycle.Active, mine.Lifecycle);
        Assert.Null(mine.Seats[1].Name);
        Assert.True(mine.Seats[1].IsFriend);
        var theirs = Assert.Single(games.Friends);
        Assert.Equal(GameMode.Coop, theirs.Mode);
        Assert.True(theirs.CanJoin);
    }

    [Fact]
    public void Friends_list_fixture_reads_all_three_lists()
    {
        var list = Read<FriendsList>("friends-list.json");
        Assert.Equal("Fen", Assert.Single(list.Friends).DisplayName);
        Assert.Equal("r1", Assert.Single(list.Incoming).RequestId);
        Assert.Equal("later@example.com", Assert.Single(list.Outgoing).Label);
    }

    [Fact]
    public void Membership_friend_flags_default_to_false()
    {
        var seat = JsonSerializer.Deserialize<MembershipProjection>(
            """{"seatId":"s","seatIndex":1,"ready":false,"isHost":true,"isAccount":true,"friendRequested":true}""", ContractJson.Options)!;
        Assert.True(seat.IsAccount);
        Assert.False(seat.IsFriend);
        Assert.True(seat.FriendRequested);
    }

    private static T Read<T>(string name) =>
        JsonSerializer.Deserialize<T>(File.ReadAllText(Path.Combine(AppContext.BaseDirectory, "fixtures", name)), ContractJson.Options)!;
}
```

- [ ] **Step 3: Run to verify failure** — `dotnet test tests/BloodAndBanners.Contracts.Tests --filter "FullyQualifiedName~AccountContractTests"` → build errors (`AccountGames` not found).

- [ ] **Step 4: Implement**

`src/BloodAndBanners.Contracts/AccountContracts.cs`:
```csharp
namespace BloodAndBanners.Contracts;

public sealed record AccountProfile(string UserId, string Email, string DisplayName);

public sealed record FriendSummary(string UserId, string DisplayName);

public sealed record IncomingFriendRequest(string RequestId, string DisplayName);

/// <summary>A request this account sent; the label is the typed email, or the player's name when sent from a game.</summary>
public sealed record OutgoingFriendRequest(string RequestId, string Label);

public sealed record FriendsList(
    IReadOnlyList<FriendSummary> Friends,
    IReadOnlyList<IncomingFriendRequest> Incoming,
    IReadOnlyList<OutgoingFriendRequest> Outgoing);

public sealed record AccountGameSeat(int SeatIndex, string? Name, bool IsFriend);

public sealed record AccountGameRow(
    string GameId,
    GameMode Mode,
    string ScenarioName,
    GameLifecycle Lifecycle,
    IReadOnlyList<AccountGameSeat> Seats,
    bool CanJoin,
    string UpdatedAt);

public sealed record AccountGames(IReadOnlyList<AccountGameRow> Yours, IReadOnlyList<AccountGameRow> Friends);
```

`AuthSession.cs` — give the record a body:
```csharp
public sealed record AuthSession(
    string IdentityId,
    string Kind,
    string AccessToken,
    string RefreshToken)
{
    /// <summary>Set for signed-in accounts; empty until the player picks a name.</summary>
    public string? DisplayName { get; init; }
}
```

`MembershipProjection` — add inside its body:
```csharp
    public bool IsAccount { get; init; }

    /// <summary>True when the viewing account is already friends with this seat's account.</summary>
    public bool IsFriend { get; init; }

    /// <summary>True when the viewing account has a pending friend request out to this seat's account.</summary>
    public bool FriendRequested { get; init; }
```

- [ ] **Step 5: Run tests** — `dotnet test tests/BloodAndBanners.Contracts.Tests` → all pass.

- [ ] **Step 6: Commit**

```bash
git add src/BloodAndBanners.Contracts tests/fixtures tests/BloodAndBanners.Contracts.Tests/AccountContractTests.cs
git commit -m "Add account and friends contracts"
```

---

### Task 8: Client account services (API client, store, session)

**Files:**
- Create: `src/BloodAndBanners.UI/Services/IAccountApiClient.cs`, `AccountApiClient.cs`, `AccountStore.cs`, `IAccountSession.cs`, `AccountSession.cs`
- Modify: `src/BloodAndBanners.UI/Services/GameApiClient.cs` (make `ReadAsync`/`ErrorAsync` `internal static`)
- Test: `tests/BloodAndBanners.UI.Tests/AccountApiClientTests.cs`, `tests/BloodAndBanners.UI.Tests/AccountSessionTests.cs`, `tests/BloodAndBanners.UI.Tests/AccountFakes.cs`

**Interfaces:**
- Consumes: contracts (Task 7); `IGameApiClient.RefreshAsync`, `IGameSessionStore`, `IKeyValueStore`, `GameApiException`.
- Produces:
  - `IAccountApiClient` (signatures below).
  - `record StoredAccount(string DeviceId, AuthSession Identity, string DisplayName)`.
  - `IAccountStore` / `JsonAccountStore(IKeyValueStore)`.
  - `IAccountSession` (members below) with implementation `AccountSession(IAccountApiClient, IGameApiClient, IAccountStore, IGameSessionStore)`.
  - Test fakes `FakeAccountApiClient` and `FakeAccountSession` (internal, in `AccountFakes.cs`, reused by Tasks 9–12).

- [ ] **Step 1: Interfaces**

`src/BloodAndBanners.UI/Services/IAccountApiClient.cs`:
```csharp
using BloodAndBanners.Contracts;

namespace BloodAndBanners.UI.Services;

public interface IAccountApiClient
{
    Task StartEmailSignInAsync(string email, CancellationToken cancellationToken = default);
    /// <summary>Trades an emailed code for a session; a guest identity brings its current seat along.</summary>
    Task<AuthSession> VerifyEmailCodeAsync(string email, string code, string deviceId, AuthSession? guest, CancellationToken cancellationToken = default);
    Task<AuthSession> VerifyEmailLinkAsync(string token, string deviceId, AuthSession? guest, CancellationToken cancellationToken = default);
    Task LogoutAsync(string refreshToken, CancellationToken cancellationToken = default);
    Task<AccountProfile> UpdateDisplayNameAsync(AuthSession session, string displayName, CancellationToken cancellationToken = default);
    Task<AccountGames> GetGamesAsync(AuthSession session, CancellationToken cancellationToken = default);
    Task<GameSeatSession> ResumeGameAsync(AuthSession session, string gameId, CancellationToken cancellationToken = default);
    Task<FriendsList> GetFriendsAsync(AuthSession session, CancellationToken cancellationToken = default);
    Task<FriendsList> RequestFriendAsync(AuthSession session, string email, CancellationToken cancellationToken = default);
    Task<FriendsList> RequestFriendFromSeatAsync(AuthSession session, string gameId, string seatId, CancellationToken cancellationToken = default);
    Task<FriendsList> AcceptFriendAsync(AuthSession session, string requestId, CancellationToken cancellationToken = default);
    Task<FriendsList> DeclineFriendAsync(AuthSession session, string requestId, CancellationToken cancellationToken = default);
    Task<FriendsList> RemoveFriendAsync(AuthSession session, string userId, CancellationToken cancellationToken = default);
}
```

`src/BloodAndBanners.UI/Services/IAccountSession.cs`:
```csharp
using BloodAndBanners.Contracts;

namespace BloodAndBanners.UI.Services;

public sealed record StoredAccount(string DeviceId, AuthSession Identity, string DisplayName);

/// <summary>The signed-in account on this device; owns token refresh for the account and any seat it holds.</summary>
public interface IAccountSession
{
    StoredAccount? Current { get; }
    IAccountApiClient Api { get; }
    event Action? Changed;
    Task<StoredAccount?> LoadAsync();
    Task StartSignInAsync(string email, CancellationToken cancellationToken = default);
    Task<StoredAccount> VerifyCodeAsync(string email, string code, string deviceId, CancellationToken cancellationToken = default);
    Task<StoredAccount> VerifyLinkAsync(string token, string deviceId, CancellationToken cancellationToken = default);
    Task<StoredAccount> SetDisplayNameAsync(string displayName, CancellationToken cancellationToken = default);
    Task SignOutAsync();
    /// <summary>Refreshes once even when several callers saw the same expired token.</summary>
    Task<AuthSession> RefreshAsync(string staleAccessToken, CancellationToken cancellationToken = default);
    /// <summary>Runs an account call, refreshing and retrying once on 401.</summary>
    Task<T> CallAsync<T>(Func<AuthSession, Task<T>> call, CancellationToken cancellationToken = default);
}
```

- [ ] **Step 2: Write the failing API-client tests** — `tests/BloodAndBanners.UI.Tests/AccountApiClientTests.cs`

```csharp
using System.Net;
using System.Text;
using BloodAndBanners.Contracts;
using BloodAndBanners.UI.Services;

namespace BloodAndBanners.UI.Tests;

public sealed class AccountApiClientTests
{
    private static readonly AuthSession User = new("u1", "user", "access-1", "refresh-1");

    [Fact]
    public async Task Verify_by_code_posts_email_code_device_and_guest_bearer()
    {
        var handler = new RecordingHandler(HttpStatusCode.OK, """{"identityId":"u1","kind":"user","accessToken":"a","refreshToken":"r","displayName":""}""");
        var client = new AccountApiClient(new HttpClient(handler) { BaseAddress = new Uri("https://api.test/") });

        var session = await client.VerifyEmailCodeAsync("me@example.com", "123456", "web-1", new AuthSession("g1", "guest", "guest-access", "gr"));

        Assert.Equal("user", session.Kind);
        Assert.Equal("", session.DisplayName);
        Assert.Equal("https://api.test/api/v1/auth/email/verify", handler.Uri);
        Assert.Equal("Bearer guest-access", handler.Authorization);
        Assert.Contains("\"code\":\"123456\"", handler.Body);
        Assert.Contains("\"deviceId\":\"web-1\"", handler.Body);
    }

    [Fact]
    public async Task Verify_by_link_without_guest_sends_no_bearer()
    {
        var handler = new RecordingHandler(HttpStatusCode.OK, """{"identityId":"u1","kind":"user","accessToken":"a","refreshToken":"r"}""");
        var client = new AccountApiClient(new HttpClient(handler) { BaseAddress = new Uri("https://api.test/") });

        await client.VerifyEmailLinkAsync("tok", "web-1", guest: null);

        Assert.Null(handler.Authorization);
        Assert.Contains("\"token\":\"tok\"", handler.Body);
    }

    [Fact]
    public async Task Errors_surface_the_server_code()
    {
        var handler = new RecordingHandler(HttpStatusCode.TooManyRequests, """{"error":{"code":"tooManyRequests","message":"Slow down"}}""");
        var client = new AccountApiClient(new HttpClient(handler) { BaseAddress = new Uri("https://api.test/") });

        var error = await Assert.ThrowsAsync<GameApiException>(() => client.StartEmailSignInAsync("me@example.com"));

        Assert.Equal("tooManyRequests", error.Code);
    }

    [Theory]
    [InlineData("GET", "api/v1/friends")]
    [InlineData("POST", "api/v1/friends/requests/r1/accept")]
    [InlineData("POST", "api/v1/friends/requests/r1/decline")]
    [InlineData("DELETE", "api/v1/friends/u2")]
    public async Task Friend_calls_use_the_documented_routes(string method, string path)
    {
        var handler = new RecordingHandler(HttpStatusCode.OK, """{"friends":[],"incoming":[],"outgoing":[]}""");
        var client = new AccountApiClient(new HttpClient(handler) { BaseAddress = new Uri("https://api.test/") });

        _ = path switch
        {
            "api/v1/friends" => await client.GetFriendsAsync(User),
            "api/v1/friends/requests/r1/accept" => await client.AcceptFriendAsync(User, "r1"),
            "api/v1/friends/requests/r1/decline" => await client.DeclineFriendAsync(User, "r1"),
            _ => await client.RemoveFriendAsync(User, "u2"),
        };

        Assert.Equal(method, handler.Method);
        Assert.Equal($"https://api.test/{path}", handler.Uri);
        Assert.Equal("Bearer access-1", handler.Authorization);
    }

    [Fact]
    public async Task Seat_friend_request_sends_game_and_seat()
    {
        var handler = new RecordingHandler(HttpStatusCode.OK, """{"friends":[],"incoming":[],"outgoing":[]}""");
        var client = new AccountApiClient(new HttpClient(handler) { BaseAddress = new Uri("https://api.test/") });

        await client.RequestFriendFromSeatAsync(User, "g1", "seat-2");

        Assert.Contains("\"gameId\":\"g1\"", handler.Body);
        Assert.Contains("\"seatId\":\"seat-2\"", handler.Body);
    }

    private sealed class RecordingHandler(HttpStatusCode status, string body) : HttpMessageHandler
    {
        public string? Method { get; private set; }
        public string? Uri { get; private set; }
        public string? Authorization { get; private set; }
        public string Body { get; private set; } = string.Empty;

        protected override async Task<HttpResponseMessage> SendAsync(HttpRequestMessage request, CancellationToken cancellationToken)
        {
            Method = request.Method.Method;
            Uri = request.RequestUri?.ToString();
            Authorization = request.Headers.Authorization?.ToString();
            Body = request.Content is null ? string.Empty : await request.Content.ReadAsStringAsync(cancellationToken);
            return new HttpResponseMessage(status) { Content = new StringContent(body, Encoding.UTF8, "application/json") };
        }
    }
}
```

- [ ] **Step 3: Shared fakes** — `tests/BloodAndBanners.UI.Tests/AccountFakes.cs`

```csharp
using System.Net;
using BloodAndBanners.Contracts;
using BloodAndBanners.UI.Services;

namespace BloodAndBanners.UI.Tests;

internal sealed class FakeAccountApiClient : IAccountApiClient
{
    public AuthSession Verified { get; set; } = new("u1", "user", "access-1", "refresh-1") { DisplayName = "" };
    public Exception? VerifyFailure { get; set; }
    public Exception? StartFailure { get; set; }
    public AccountGames Games { get; set; } = new([], []);
    public FriendsList Friends { get; set; } = new([], [], []);
    public GameSeatSession? Resumed { get; set; }
    public Exception? ResumeFailure { get; set; }
    public Exception? FriendFailure { get; set; }
    public List<string> Calls { get; } = [];
    public AuthSession? VerifyGuest { get; private set; }
    public List<AuthSession> SessionsUsed { get; } = [];
    /// <summary>Access tokens the fake rejects with 401, to exercise refresh.</summary>
    public HashSet<string> ExpiredTokens { get; } = [];

    public Task StartEmailSignInAsync(string email, CancellationToken cancellationToken = default)
    {
        Calls.Add($"start:{email}");
        return StartFailure is null ? Task.CompletedTask : Task.FromException(StartFailure);
    }

    public Task<AuthSession> VerifyEmailCodeAsync(string email, string code, string deviceId, AuthSession? guest, CancellationToken cancellationToken = default)
    {
        Calls.Add($"code:{email}:{code}");
        VerifyGuest = guest;
        return VerifyFailure is null ? Task.FromResult(Verified) : Task.FromException<AuthSession>(VerifyFailure);
    }

    public Task<AuthSession> VerifyEmailLinkAsync(string token, string deviceId, AuthSession? guest, CancellationToken cancellationToken = default)
    {
        Calls.Add($"link:{token}");
        VerifyGuest = guest;
        return VerifyFailure is null ? Task.FromResult(Verified) : Task.FromException<AuthSession>(VerifyFailure);
    }

    public Task LogoutAsync(string refreshToken, CancellationToken cancellationToken = default)
    {
        Calls.Add($"logout:{refreshToken}");
        return Task.CompletedTask;
    }

    public Task<AccountProfile> UpdateDisplayNameAsync(AuthSession session, string displayName, CancellationToken cancellationToken = default) =>
        Use(session, $"name:{displayName}", () => new AccountProfile(session.IdentityId, "me@example.com", displayName.Trim()));

    public Task<AccountGames> GetGamesAsync(AuthSession session, CancellationToken cancellationToken = default) =>
        Use(session, "games", () => Games);

    public Task<GameSeatSession> ResumeGameAsync(AuthSession session, string gameId, CancellationToken cancellationToken = default) =>
        ResumeFailure is not null
            ? Task.FromException<GameSeatSession>(ResumeFailure)
            : Use(session, $"resume:{gameId}", () => Resumed ?? throw new InvalidOperationException("Resume not configured."));

    public Task<FriendsList> GetFriendsAsync(AuthSession session, CancellationToken cancellationToken = default) => Friend(session, "friends");
    public Task<FriendsList> RequestFriendAsync(AuthSession session, string email, CancellationToken cancellationToken = default) => Friend(session, $"request:{email}");
    public Task<FriendsList> RequestFriendFromSeatAsync(AuthSession session, string gameId, string seatId, CancellationToken cancellationToken = default) => Friend(session, $"seat:{gameId}:{seatId}");
    public Task<FriendsList> AcceptFriendAsync(AuthSession session, string requestId, CancellationToken cancellationToken = default) => Friend(session, $"accept:{requestId}");
    public Task<FriendsList> DeclineFriendAsync(AuthSession session, string requestId, CancellationToken cancellationToken = default) => Friend(session, $"decline:{requestId}");
    public Task<FriendsList> RemoveFriendAsync(AuthSession session, string userId, CancellationToken cancellationToken = default) => Friend(session, $"remove:{userId}");

    private Task<FriendsList> Friend(AuthSession session, string call) =>
        FriendFailure is not null && call != "friends" ? Task.FromException<FriendsList>(FriendFailure) : Use(session, call, () => Friends);

    private Task<T> Use<T>(AuthSession session, string call, Func<T> result)
    {
        SessionsUsed.Add(session);
        if (ExpiredTokens.Contains(session.AccessToken))
        {
            return Task.FromException<T>(new GameApiException(HttpStatusCode.Unauthorized, "expiredAccessToken", "expired"));
        }
        Calls.Add(call);
        return Task.FromResult(result());
    }
}

/// <summary>An in-memory signed-in (or signed-out) account for component tests.</summary>
internal sealed class FakeAccountSession(StoredAccount? current = null) : IAccountSession
{
    public StoredAccount? Current { get; private set; } = current;
    public FakeAccountApiClient FakeApi { get; } = new();
    public IAccountApiClient Api => FakeApi;
    public event Action? Changed;
    public bool SignedOut { get; private set; }
    public Exception? StartFailure { get; set; }
    public Exception? VerifyFailure { get; set; }
    public string NameAfterVerify { get; set; } = "";
    public List<string> Calls { get; } = [];

    public static StoredAccount Account(string name = "Ash") =>
        new("web-1", new AuthSession("u1", "user", "access-1", "refresh-1") { DisplayName = name }, name);

    public Task<StoredAccount?> LoadAsync() => Task.FromResult(Current);

    public Task StartSignInAsync(string email, CancellationToken cancellationToken = default)
    {
        Calls.Add($"start:{email}");
        return StartFailure is null ? Task.CompletedTask : Task.FromException(StartFailure);
    }

    public Task<StoredAccount> VerifyCodeAsync(string email, string code, string deviceId, CancellationToken cancellationToken = default) =>
        Verify($"code:{email}:{code}");

    public Task<StoredAccount> VerifyLinkAsync(string token, string deviceId, CancellationToken cancellationToken = default) =>
        Verify($"link:{token}");

    public Task<StoredAccount> SetDisplayNameAsync(string displayName, CancellationToken cancellationToken = default)
    {
        Calls.Add($"name:{displayName}");
        Current = Account(displayName.Trim());
        Changed?.Invoke();
        return Task.FromResult(Current);
    }

    public Task SignOutAsync()
    {
        SignedOut = true;
        Current = null;
        Changed?.Invoke();
        return Task.CompletedTask;
    }

    public Task<AuthSession> RefreshAsync(string staleAccessToken, CancellationToken cancellationToken = default) =>
        Task.FromResult(Current!.Identity);

    public Task<T> CallAsync<T>(Func<AuthSession, Task<T>> call, CancellationToken cancellationToken = default) =>
        call(Current?.Identity ?? throw new InvalidOperationException("Not signed in."));

    public void Raise() => Changed?.Invoke();

    private Task<StoredAccount> Verify(string call)
    {
        Calls.Add(call);
        if (VerifyFailure is not null)
        {
            return Task.FromException<StoredAccount>(VerifyFailure);
        }
        Current = Account(NameAfterVerify);
        Changed?.Invoke();
        return Task.FromResult(Current);
    }
}
```

- [ ] **Step 4: Write the failing session tests** — `tests/BloodAndBanners.UI.Tests/AccountSessionTests.cs`

```csharp
using System.Net;
using BloodAndBanners.Contracts;
using BloodAndBanners.UI.Services;

namespace BloodAndBanners.UI.Tests;

public sealed class AccountSessionTests
{
    private sealed class MemoryAccountStore : IAccountStore
    {
        public StoredAccount? Current { get; set; }
        public ValueTask<StoredAccount?> LoadAsync() => ValueTask.FromResult(Current);
        public ValueTask SaveAsync(StoredAccount account) { Current = account; return ValueTask.CompletedTask; }
        public ValueTask ClearAsync() { Current = null; return ValueTask.CompletedTask; }
    }

    private sealed class MemoryGames : IGameSessionStore
    {
        public StoredGameSession? Current { get; set; }
        public ValueTask<StoredGameSession?> LoadAsync() => ValueTask.FromResult(Current);
        public ValueTask SaveAsync(StoredGameSession session) { Current = session; return ValueTask.CompletedTask; }
        public ValueTask ClearAsync() { Current = null; return ValueTask.CompletedTask; }
    }

    /// <summary>Only RefreshAsync is used by AccountSession.</summary>
    private sealed class RefreshingGameApi : IGameApiClient
    {
        public int Refreshes { get; private set; }
        public bool Revoked { get; set; }
        public TaskCompletionSource? Gate { get; set; }

        public async Task<AuthSession> RefreshAsync(string refreshToken, string deviceId, CancellationToken cancellationToken = default)
        {
            Refreshes++;
            if (Gate is not null) await Gate.Task;
            if (Revoked) throw new GameApiException(HttpStatusCode.Unauthorized, "invalidRefreshToken", "revoked");
            return new AuthSession("u1", "user", $"access-{Refreshes + 1}", $"refresh-{Refreshes + 1}");
        }

        public Task<AuthSession> CreateGuestAsync(string deviceId, CancellationToken cancellationToken = default) => throw new NotSupportedException();
        public Task<BoardProjection> GetScenarioBoardAsync(string scenarioName, CancellationToken cancellationToken = default) => throw new NotSupportedException();
        public Task<GameSeatSession> CreateGameAsync(AuthSession session, GameMode mode, string scenarioName, CancellationToken cancellationToken = default) => throw new NotSupportedException();
        public Task<GameSeatSession> JoinGameAsync(AuthSession session, string gameId, CancellationToken cancellationToken = default) => throw new NotSupportedException();
        public Task<GameProjection> ReconnectAsync(AuthSession session, string gameId, string seatToken, CancellationToken cancellationToken = default) => throw new NotSupportedException();
        public Task<GameProjection> SubmitCommandAsync(AuthSession session, string gameId, string seatToken, CommandEnvelope command, CancellationToken cancellationToken = default) => throw new NotSupportedException();
        public Task<GameProjection> StartGameAsync(AuthSession session, string gameId, string seatToken, CancellationToken cancellationToken = default) => throw new NotSupportedException();
        public Task LeaveGameAsync(AuthSession session, string gameId, string seatToken, CancellationToken cancellationToken = default) => throw new NotSupportedException();
        public Task<GameProjection> RemoveSeatAsync(AuthSession session, string gameId, string hostSeatToken, string seatId, CancellationToken cancellationToken = default) => throw new NotSupportedException();
        public Task<SeatReassignmentResult> ReassignSeatAsync(AuthSession session, string gameId, string hostSeatToken, string seatId, string replacementIdentityId, CancellationToken cancellationToken = default) => throw new NotSupportedException();
        public Task<EventPage> GetEventsAsync(AuthSession session, string gameId, string seatToken, long afterSequence, CancellationToken cancellationToken = default) => throw new NotSupportedException();
        public Task<GameProjection> WatchGameAsync(string gameId, CancellationToken cancellationToken = default) => throw new NotSupportedException();
        public Task<EventPage> GetWatchedEventsAsync(string gameId, long afterSequence, CancellationToken cancellationToken = default) => throw new NotSupportedException();
    }

    private static readonly AuthSession Guest = new("g1", "guest", "guest-access", "guest-refresh");

    [Fact]
    public async Task Verifying_saves_the_account_and_moves_a_guest_seat_to_it()
    {
        var api = new FakeAccountApiClient();
        var store = new MemoryAccountStore();
        var games = new MemoryGames { Current = new StoredGameSession("web-old", Guest, "game-1", "seat-secret") };
        var session = new AccountSession(api, new RefreshingGameApi(), store, games);

        var account = await session.VerifyCodeAsync("me@example.com", "123456", "web-1");

        Assert.Same(Guest, api.VerifyGuest);
        Assert.Equal("web-1", account.DeviceId);
        Assert.Equal(account, store.Current);
        Assert.Equal("u1", games.Current!.Identity.IdentityId);
        Assert.Equal("seat-secret", games.Current.SeatToken);
    }

    [Fact]
    public async Task A_401_refreshes_once_and_retries_and_updates_both_stores()
    {
        var api = new FakeAccountApiClient();
        api.ExpiredTokens.Add("access-1");
        var identity = new AuthSession("u1", "user", "access-1", "refresh-1");
        var store = new MemoryAccountStore { Current = new StoredAccount("web-1", identity, "Ash") };
        var games = new MemoryGames { Current = new StoredGameSession("web-1", identity, "game-1", "seat") };
        var gameApi = new RefreshingGameApi();
        var session = new AccountSession(api, gameApi, store, games);
        await session.LoadAsync();

        await session.CallAsync(i => api.GetGamesAsync(i));

        Assert.Equal(1, gameApi.Refreshes);
        Assert.Equal("access-2", store.Current!.Identity.AccessToken);
        Assert.Equal("Ash", store.Current.DisplayName);
        Assert.Equal("refresh-2", games.Current!.Identity.RefreshToken);
    }

    [Fact]
    public async Task Concurrent_refreshes_for_the_same_stale_token_hit_the_server_once()
    {
        var identity = new AuthSession("u1", "user", "access-1", "refresh-1");
        var store = new MemoryAccountStore { Current = new StoredAccount("web-1", identity, "Ash") };
        var gameApi = new RefreshingGameApi { Gate = new TaskCompletionSource() };
        var session = new AccountSession(new FakeAccountApiClient(), gameApi, store, new MemoryGames());
        await session.LoadAsync();

        var first = session.RefreshAsync("access-1");
        var second = session.RefreshAsync("access-1");
        gameApi.Gate.SetResult();
        var results = await Task.WhenAll(first, second);

        Assert.Equal(1, gameApi.Refreshes);
        Assert.Equal(results[0], results[1]);
    }

    [Fact]
    public async Task A_revoked_refresh_token_signs_out()
    {
        var identity = new AuthSession("u1", "user", "access-1", "refresh-1");
        var store = new MemoryAccountStore { Current = new StoredAccount("web-1", identity, "Ash") };
        var session = new AccountSession(new FakeAccountApiClient(), new RefreshingGameApi { Revoked = true }, store, new MemoryGames());
        await session.LoadAsync();
        var changed = 0;
        session.Changed += () => changed++;

        await Assert.ThrowsAsync<GameApiException>(() => session.RefreshAsync("access-1"));

        Assert.Null(session.Current);
        Assert.Null(store.Current);
        Assert.Equal(1, changed);
    }

    [Fact]
    public async Task Sign_out_revokes_and_forgets_but_keeps_the_game_seat()
    {
        var api = new FakeAccountApiClient();
        var identity = new AuthSession("u1", "user", "access-1", "refresh-1");
        var store = new MemoryAccountStore { Current = new StoredAccount("web-1", identity, "Ash") };
        var games = new MemoryGames { Current = new StoredGameSession("web-1", identity, "game-1", "seat") };
        var session = new AccountSession(api, new RefreshingGameApi(), store, games);
        await session.LoadAsync();

        await session.SignOutAsync();

        Assert.Contains("logout:refresh-1", api.Calls);
        Assert.Null(store.Current);
        Assert.NotNull(games.Current);
    }

    [Fact]
    public async Task Json_store_round_trips_under_the_account_key()
    {
        var values = new Dictionary<string, string>();
        var kv = new DelegatingKeyValueStore(
            key => ValueTask.FromResult(values.GetValueOrDefault(key)),
            (key, value) => { values[key] = value; return ValueTask.CompletedTask; },
            key => { values.Remove(key); return ValueTask.CompletedTask; });
        var store = new JsonAccountStore(kv);
        var account = new StoredAccount("web-1", new AuthSession("u1", "user", "a", "r") { DisplayName = "Ash" }, "Ash");

        await store.SaveAsync(account);

        Assert.True(values.ContainsKey("bloodAndBanners.account"));
        Assert.Equal(account, await store.LoadAsync());
    }
}
```

- [ ] **Step 5: Run to verify failure** — `dotnet test tests/BloodAndBanners.UI.Tests --filter "FullyQualifiedName~Account"` → build errors (`AccountApiClient`, `AccountSession`, `JsonAccountStore` missing).

- [ ] **Step 6: Implement**

In `GameApiClient.cs` change `private static async Task<T> ReadAsync<T>` and `private static async Task<GameApiException> ErrorAsync` to `internal static`.

`src/BloodAndBanners.UI/Services/AccountApiClient.cs`:
```csharp
using System.Net.Http.Headers;
using System.Net.Http.Json;
using BloodAndBanners.Contracts;

namespace BloodAndBanners.UI.Services;

public sealed class AccountApiClient(HttpClient httpClient) : IAccountApiClient
{
    public async Task StartEmailSignInAsync(string email, CancellationToken cancellationToken = default)
    {
        using var response = await SendAsync(HttpMethod.Post, "api/v1/auth/email/start", null, new { email }, cancellationToken);
        await EnsureAsync(response, cancellationToken);
    }

    public Task<AuthSession> VerifyEmailCodeAsync(string email, string code, string deviceId, AuthSession? guest, CancellationToken cancellationToken = default) =>
        ReadAsync<AuthSession>(HttpMethod.Post, "api/v1/auth/email/verify", guest, new { email, code, deviceId }, cancellationToken);

    public Task<AuthSession> VerifyEmailLinkAsync(string token, string deviceId, AuthSession? guest, CancellationToken cancellationToken = default) =>
        ReadAsync<AuthSession>(HttpMethod.Post, "api/v1/auth/email/verify", guest, new { token, deviceId }, cancellationToken);

    public async Task LogoutAsync(string refreshToken, CancellationToken cancellationToken = default)
    {
        using var response = await SendAsync(HttpMethod.Post, "api/v1/auth/logout", null, new { refreshToken }, cancellationToken);
        await EnsureAsync(response, cancellationToken);
    }

    public Task<AccountProfile> UpdateDisplayNameAsync(AuthSession session, string displayName, CancellationToken cancellationToken = default) =>
        ReadAsync<AccountProfile>(HttpMethod.Put, "api/v1/account", session, new { displayName }, cancellationToken);

    public Task<AccountGames> GetGamesAsync(AuthSession session, CancellationToken cancellationToken = default) =>
        ReadAsync<AccountGames>(HttpMethod.Get, "api/v1/account/games", session, null, cancellationToken);

    public Task<GameSeatSession> ResumeGameAsync(AuthSession session, string gameId, CancellationToken cancellationToken = default) =>
        ReadAsync<GameSeatSession>(HttpMethod.Post, $"api/v1/games/{Uri.EscapeDataString(gameId)}/resume", session, null, cancellationToken);

    public Task<FriendsList> GetFriendsAsync(AuthSession session, CancellationToken cancellationToken = default) =>
        ReadAsync<FriendsList>(HttpMethod.Get, "api/v1/friends", session, null, cancellationToken);

    public Task<FriendsList> RequestFriendAsync(AuthSession session, string email, CancellationToken cancellationToken = default) =>
        ReadAsync<FriendsList>(HttpMethod.Post, "api/v1/friends/requests", session, new { email }, cancellationToken);

    public Task<FriendsList> RequestFriendFromSeatAsync(AuthSession session, string gameId, string seatId, CancellationToken cancellationToken = default) =>
        ReadAsync<FriendsList>(HttpMethod.Post, "api/v1/friends/requests", session, new { gameId, seatId }, cancellationToken);

    public Task<FriendsList> AcceptFriendAsync(AuthSession session, string requestId, CancellationToken cancellationToken = default) =>
        ReadAsync<FriendsList>(HttpMethod.Post, $"api/v1/friends/requests/{Uri.EscapeDataString(requestId)}/accept", session, null, cancellationToken);

    public Task<FriendsList> DeclineFriendAsync(AuthSession session, string requestId, CancellationToken cancellationToken = default) =>
        ReadAsync<FriendsList>(HttpMethod.Post, $"api/v1/friends/requests/{Uri.EscapeDataString(requestId)}/decline", session, null, cancellationToken);

    public Task<FriendsList> RemoveFriendAsync(AuthSession session, string userId, CancellationToken cancellationToken = default) =>
        ReadAsync<FriendsList>(HttpMethod.Delete, $"api/v1/friends/{Uri.EscapeDataString(userId)}", session, null, cancellationToken);

    private async Task<T> ReadAsync<T>(HttpMethod method, string uri, AuthSession? session, object? body, CancellationToken cancellationToken)
    {
        using var response = await SendAsync(method, uri, session, body, cancellationToken);
        return await GameApiClient.ReadAsync<T>(response, cancellationToken);
    }

    private Task<HttpResponseMessage> SendAsync(HttpMethod method, string uri, AuthSession? session, object? body, CancellationToken cancellationToken)
    {
        var request = new HttpRequestMessage(method, uri);
        if (body is not null)
        {
            request.Content = JsonContent.Create(body, body.GetType(), options: ContractJson.Options);
        }
        if (session is not null)
        {
            request.Headers.Authorization = new AuthenticationHeaderValue("Bearer", session.AccessToken);
        }
        return httpClient.SendAsync(request, cancellationToken);
    }

    private static async Task EnsureAsync(HttpResponseMessage response, CancellationToken cancellationToken)
    {
        if (!response.IsSuccessStatusCode)
        {
            throw await GameApiClient.ErrorAsync(response, cancellationToken);
        }
    }
}
```

`src/BloodAndBanners.UI/Services/AccountStore.cs`:
```csharp
using System.Text.Json;
using BloodAndBanners.Contracts;

namespace BloodAndBanners.UI.Services;

public interface IAccountStore
{
    ValueTask<StoredAccount?> LoadAsync();
    ValueTask SaveAsync(StoredAccount account);
    ValueTask ClearAsync();
}

public sealed class JsonAccountStore(IKeyValueStore keyValueStore) : IAccountStore
{
    private const string StorageKey = "bloodAndBanners.account";

    public async ValueTask<StoredAccount?> LoadAsync()
    {
        var json = await keyValueStore.GetAsync(StorageKey);
        return string.IsNullOrWhiteSpace(json)
            ? null
            : JsonSerializer.Deserialize<StoredAccount>(json, ContractJson.Options);
    }

    public ValueTask SaveAsync(StoredAccount account) =>
        keyValueStore.SetAsync(StorageKey, JsonSerializer.Serialize(account, ContractJson.Options));

    public ValueTask ClearAsync() => keyValueStore.RemoveAsync(StorageKey);
}
```

`src/BloodAndBanners.UI/Services/AccountSession.cs`:
```csharp
using System.Net;
using BloodAndBanners.Contracts;

namespace BloodAndBanners.UI.Services;

public sealed class AccountSession(
    IAccountApiClient accountApi,
    IGameApiClient gameApi,
    IAccountStore store,
    IGameSessionStore games) : IAccountSession
{
    private readonly SemaphoreSlim _refreshGate = new(1, 1);
    private bool _loaded;

    public StoredAccount? Current { get; private set; }
    public IAccountApiClient Api => accountApi;
    public event Action? Changed;

    public async Task<StoredAccount?> LoadAsync()
    {
        if (!_loaded)
        {
            Current = await store.LoadAsync();
            _loaded = true;
        }
        return Current;
    }

    public Task StartSignInAsync(string email, CancellationToken cancellationToken = default) =>
        accountApi.StartEmailSignInAsync(email, cancellationToken);

    public Task<StoredAccount> VerifyCodeAsync(string email, string code, string deviceId, CancellationToken cancellationToken = default) =>
        CompleteAsync(deviceId, guest => accountApi.VerifyEmailCodeAsync(email, code, deviceId, guest, cancellationToken));

    public Task<StoredAccount> VerifyLinkAsync(string token, string deviceId, CancellationToken cancellationToken = default) =>
        CompleteAsync(deviceId, guest => accountApi.VerifyEmailLinkAsync(token, deviceId, guest, cancellationToken));

    public async Task<StoredAccount> SetDisplayNameAsync(string displayName, CancellationToken cancellationToken = default)
    {
        var profile = await CallAsync(identity => accountApi.UpdateDisplayNameAsync(identity, displayName, cancellationToken), cancellationToken);
        var current = Current ?? throw new InvalidOperationException("Not signed in.");
        var updated = current with { DisplayName = profile.DisplayName, Identity = current.Identity with { DisplayName = profile.DisplayName } };
        await SaveAsync(updated);
        return updated;
    }

    public async Task SignOutAsync()
    {
        if (Current is { } current)
        {
            try
            {
                await accountApi.LogoutAsync(current.Identity.RefreshToken);
            }
            catch (HttpRequestException)
            {
                // Signing out locally still works when the server cannot be told.
            }
        }
        await ForgetAsync();
    }

    public async Task<AuthSession> RefreshAsync(string staleAccessToken, CancellationToken cancellationToken = default)
    {
        await _refreshGate.WaitAsync(cancellationToken);
        try
        {
            var current = Current ?? throw new InvalidOperationException("Not signed in.");
            if (current.Identity.AccessToken != staleAccessToken)
            {
                return current.Identity;
            }
            AuthSession refreshed;
            try
            {
                refreshed = await gameApi.RefreshAsync(current.Identity.RefreshToken, current.DeviceId, cancellationToken);
            }
            catch (HttpRequestException error) when (error.StatusCode == HttpStatusCode.Unauthorized)
            {
                await ForgetAsync();
                throw;
            }
            var updated = current with { Identity = refreshed with { DisplayName = current.DisplayName } };
            await SaveAsync(updated);
            // A seat held by this account carries the same tokens; keep it in step so neither copy goes stale.
            if (await games.LoadAsync() is { } game && game.Identity.IdentityId == refreshed.IdentityId)
            {
                await games.SaveAsync(game with { Identity = updated.Identity });
            }
            return updated.Identity;
        }
        finally
        {
            _refreshGate.Release();
        }
    }

    public async Task<T> CallAsync<T>(Func<AuthSession, Task<T>> call, CancellationToken cancellationToken = default)
    {
        var identity = (await LoadAsync() ?? throw new InvalidOperationException("Not signed in.")).Identity;
        try
        {
            return await call(identity);
        }
        catch (HttpRequestException error) when (error.StatusCode == HttpStatusCode.Unauthorized)
        {
            return await call(await RefreshAsync(identity.AccessToken, cancellationToken));
        }
    }

    private async Task<StoredAccount> CompleteAsync(string deviceId, Func<AuthSession?, Task<AuthSession>> verify)
    {
        var game = await games.LoadAsync();
        var guest = game?.Identity.Kind == "guest" ? game.Identity : null;
        var identity = await verify(guest);
        var account = new StoredAccount(deviceId, identity, identity.DisplayName ?? string.Empty);
        await SaveAsync(account);
        if (guest is not null && game is not null)
        {
            // The server moved the guest's seat to the account; the seat token is unchanged.
            await games.SaveAsync(game with { DeviceId = deviceId, Identity = identity });
        }
        return account;
    }

    private async Task SaveAsync(StoredAccount account)
    {
        await store.SaveAsync(account);
        Current = account;
        _loaded = true;
        Changed?.Invoke();
    }

    private async Task ForgetAsync()
    {
        await store.ClearAsync();
        Current = null;
        _loaded = true;
        Changed?.Invoke();
    }
}
```

The `A_revoked_refresh_token_signs_out` test expects exactly one `Changed`; only `ForgetAsync` raises it on that path.

- [ ] **Step 7: Run tests** — `dotnet test tests/BloodAndBanners.UI.Tests --filter "FullyQualifiedName~Account"` → pass.

- [ ] **Step 8: Commit**

```bash
git add src/BloodAndBanners.UI/Services tests/BloodAndBanners.UI.Tests/AccountApiClientTests.cs tests/BloodAndBanners.UI.Tests/AccountSessionTests.cs tests/BloodAndBanners.UI.Tests/AccountFakes.cs
git commit -m "Add client account API, store and session"
```

---

### Task 9: Coordinator plays as the account and resumes seats

**Files:**
- Modify: `src/BloodAndBanners.UI/Services/GameSessionCoordinator.cs`, `IGameSessionCoordinator.cs`
- Modify: `tests/BloodAndBanners.UI.Tests/GameSessionCoordinatorTests.cs`, `GameExperienceTests.cs` (fake gains the new method)

**Interfaces:**
- Consumes: `IAccountSession` (Task 8), `FakeAccountSession`.
- Produces:
  - `GameSessionCoordinator(IGameApiClient api, IGameSessionStore store, IAccountSession? account = null)`.
  - `IGameSessionCoordinator.ResumeAccountGameAsync(string gameId, CancellationToken cancellationToken = default): Task<GameProjection>`.

- [ ] **Step 1: Write the failing tests** — append to `GameSessionCoordinatorTests` (uses the file's `FakeGameApiClient`, `MemoryGameSessionStore`, `Projection(...)` helpers):

```csharp
    [Fact]
    public async Task Signed_in_players_create_games_as_their_account_without_a_guest()
    {
        var account = new FakeAccountSession(FakeAccountSession.Account());
        var api = new FakeGameApiClient { Created = new GameSeatSession(Projection("game-1"), "seat-secret") };
        var store = new MemoryGameSessionStore();
        var coordinator = new GameSessionCoordinator(api, store, account);

        await coordinator.CreateGuestGameAsync("web-new", GameMode.VanillaPvp, "Four Corners");

        Assert.Equal("u1", store.Current!.Identity.IdentityId);
        Assert.Equal("web-1", store.Current.DeviceId);
    }

    [Fact]
    public async Task Signed_out_players_still_get_a_guest()
    {
        var guest = new AuthSession("guest-1", "guest", "a", "r");
        var api = new FakeGameApiClient { Guest = guest, Joined = new GameSeatSession(Projection("game-1"), "seat") };
        var store = new MemoryGameSessionStore();
        var coordinator = new GameSessionCoordinator(api, store, new FakeAccountSession());

        await coordinator.JoinGuestGameAsync("web-1", "game-1");

        Assert.Equal(guest, store.Current!.Identity);
    }

    [Fact]
    public async Task Resuming_saves_the_fresh_seat_token()
    {
        var account = new FakeAccountSession(FakeAccountSession.Account());
        account.FakeApi.Resumed = new GameSeatSession(Projection("game-9"), "fresh-seat");
        var store = new MemoryGameSessionStore();
        var coordinator = new GameSessionCoordinator(new FakeGameApiClient(), store, account);

        var projection = await coordinator.ResumeAccountGameAsync("game-9");

        Assert.Equal("game-9", projection.GameId);
        Assert.Equal(new StoredGameSession("web-1", account.Current!.Identity, "game-9", "fresh-seat"), store.Current);
    }

    [Fact]
    public async Task Reconnect_uses_the_accounts_newer_tokens_for_its_own_seat()
    {
        var stale = new AuthSession("u1", "user", "old-access", "old-refresh");
        var account = new FakeAccountSession(FakeAccountSession.Account());
        var api = new FakeGameApiClient { Reconnected = Projection("game-1") };
        var store = new MemoryGameSessionStore { Current = new StoredGameSession("web-1", stale, "game-1", "seat") };
        var coordinator = new GameSessionCoordinator(api, store, account);

        await coordinator.ReconnectAsync();

        Assert.Equal("access-1", api.ReconnectRequest!.Value.Identity.AccessToken);
    }
```

Add to `GameExperienceTests.FakeSessionCoordinator`:
```csharp
        public GameProjection? Resumed { get; init; }
        public Exception? ResumeFailure { get; init; }
        public string? ResumeRequest { get; private set; }

        public Task<GameProjection> ResumeAccountGameAsync(string gameId, CancellationToken cancellationToken = default)
        {
            ResumeRequest = gameId;
            return ResumeFailure is not null
                ? Task.FromException<GameProjection>(ResumeFailure)
                : Task.FromResult(Resumed ?? throw new InvalidOperationException("Resume not configured."));
        }
```

- [ ] **Step 2: Run to verify failure** — `dotnet test tests/BloodAndBanners.UI.Tests --filter "FullyQualifiedName~GameSessionCoordinatorTests"` → build errors.

- [ ] **Step 3: Implement**

`IGameSessionCoordinator`: add
```csharp
    /// <summary>Takes up the signed-in account's seat in a game on this device.</summary>
    Task<GameProjection> ResumeAccountGameAsync(string gameId, CancellationToken cancellationToken = default);
```

`GameSessionCoordinator`:
- primary constructor becomes `(IGameApiClient api, IGameSessionStore store, IAccountSession? account = null)`.
- replace the bodies of `CreateGuestGameAsync` and `JoinGuestGameAsync`, and add the new members:
```csharp
    public Task<GameProjection> CreateGuestGameAsync(
        string deviceId,
        GameMode mode,
        string scenarioName,
        CancellationToken cancellationToken = default) =>
        OpenSeatAsync(deviceId, identity => api.CreateGameAsync(identity, mode, scenarioName, cancellationToken), cancellationToken);

    public Task<GameProjection> JoinGuestGameAsync(
        string deviceId,
        string gameId,
        CancellationToken cancellationToken = default)
    {
        var normalizedGameId = gameId.Trim();
        if (normalizedGameId.Length == 0)
        {
            throw new ArgumentException("A game code is required.", nameof(gameId));
        }
        return OpenSeatAsync(deviceId, identity => api.JoinGameAsync(identity, normalizedGameId, cancellationToken), cancellationToken);
    }

    public async Task<GameProjection> ResumeAccountGameAsync(string gameId, CancellationToken cancellationToken = default)
    {
        var signedIn = account is null ? null : await account.LoadAsync();
        if (signedIn is null)
        {
            throw new InvalidOperationException("Sign in to resume a game.");
        }
        return await OpenSeatAsync(signedIn.DeviceId, identity => account!.Api.ResumeGameAsync(identity, gameId.Trim(), cancellationToken), cancellationToken);
    }

    /// <summary>Opens a seat as the signed-in account when there is one, otherwise as a fresh guest, and remembers it.</summary>
    private async Task<GameProjection> OpenSeatAsync(
        string deviceId,
        Func<AuthSession, Task<GameSeatSession>> open,
        CancellationToken cancellationToken)
    {
        _watchedGameId = null;
        var signedIn = account is null ? null : await account.LoadAsync();
        AuthSession identity;
        GameSeatSession seat;
        if (signedIn is null)
        {
            identity = await api.CreateGuestAsync(deviceId, cancellationToken);
            seat = await open(identity);
        }
        else
        {
            AuthSession? used = null;
            seat = await account!.CallAsync(async current =>
            {
                used = current;
                return await open(current);
            }, cancellationToken);
            identity = used!;
            deviceId = signedIn.DeviceId;
        }
        await store.SaveAsync(new StoredGameSession(deviceId, identity, seat.Game.GameId, seat.SeatToken));
        return seat.Game;
    }

    /// <summary>A seat held by the signed-in account always uses the account's newest tokens.</summary>
    private async ValueTask<StoredGameSession?> LoadSessionAsync()
    {
        var session = await store.LoadAsync();
        if (session is null || account is null || session.Identity.Kind != "user")
        {
            return session;
        }
        var signedIn = await account.LoadAsync();
        return signedIn is not null && signedIn.Identity.IdentityId == session.Identity.IdentityId
            ? session with { Identity = signedIn.Identity }
            : session;
    }

    private async Task<AuthSession> RefreshSessionAsync(StoredGameSession session, CancellationToken cancellationToken)
    {
        if (account?.Current is { } signedIn && signedIn.Identity.IdentityId == session.Identity.IdentityId)
        {
            // The account refresh also rewrites the stored seat.
            return await account.RefreshAsync(session.Identity.AccessToken, cancellationToken);
        }
        var refreshed = await api.RefreshAsync(session.Identity.RefreshToken, session.DeviceId, cancellationToken);
        await store.SaveAsync(session with { Identity = refreshed });
        return refreshed;
    }
```
- In `ReconnectAsync`, `LeaveSeatAsync`, and `RequiredSessionAsync`, replace `store.LoadAsync()` with `LoadSessionAsync()`.
- In `ReconnectAsync`'s `catch (... Unauthorized)` block, replace the refresh+save lines with `var refreshedIdentity = await RefreshSessionAsync(session, cancellationToken);` and reconnect with `refreshedIdentity`.
- In `LeaveSeatAsync`'s inner catch, replace `var refreshed = await api.RefreshAsync(...)` with `var refreshed = await RefreshSessionAsync(session, cancellationToken);`.

- [ ] **Step 4: Run tests** — `dotnet test tests/BloodAndBanners.UI.Tests` → all pass (existing coordinator tests still construct with two args).

- [ ] **Step 5: Commit**

```bash
git add src/BloodAndBanners.UI/Services tests/BloodAndBanners.UI.Tests
git commit -m "Play and resume games as the signed-in account"
```

---

### Task 10: Sign-in UI, sign-in links, and DI registration

**Files:**
- Create: `src/BloodAndBanners.UI/Components/SignInPanel.razor`, `SignInPanel.razor.css`, `AccountChip.razor`, `AccountChip.razor.css`
- Modify: `src/BloodAndBanners.UI/Services/InviteLinks.cs`, `src/BloodAndBanners.UI/Components/QuickStart.razor`, `GameExperience.razor`
- Modify: `src/BloodAndBanners.Web/Program.cs`, `src/BloodAndBanners.Web/Pages/Home.razor`, `src/BloodAndBanners.Maui/MauiProgram.cs`, `src/BloodAndBanners.Maui/Components/Pages/Home.razor`
- Test: `tests/BloodAndBanners.UI.Tests/SignInPanelTests.cs`, additions to `GameExperienceTests.cs`

**Interfaces:**
- Consumes: `IAccountSession`, `FakeAccountSession`.
- Produces:
  - `InviteLinks.SignInTokenFromUri(string uri): string?`.
  - `SignInPanel` (params `string DeviceId`, `bool StartAtName`, `EventCallback OnDone`, `EventCallback OnCancel`).
  - `AccountChip` (param `string ClientKind`).
  - `QuickStart` new params `string ClientKind`, `string? StatusMessage`, `EventCallback<string> OnResume`.
  - `GameExperience` new params `string? InitialSignInToken`, `EventCallback OnSignInLinkHandled`.
  - Components resolve `IAccountSession` optionally through `IServiceProvider` and render nothing account-related without it.

- [ ] **Step 1: Write the failing tests** — `tests/BloodAndBanners.UI.Tests/SignInPanelTests.cs`

```csharp
using System.Net;
using BloodAndBanners.UI.Components;
using BloodAndBanners.UI.Services;
using Bunit;
using Microsoft.Extensions.DependencyInjection;

namespace BloodAndBanners.UI.Tests;

public sealed class SignInPanelTests : BunitContext
{
    private FakeAccountSession Register(StoredAccount? current = null)
    {
        var session = new FakeAccountSession(current);
        Services.AddSingleton<IAccountSession>(session);
        return session;
    }

    [Fact]
    public void Email_then_code_then_name_signs_in()
    {
        var session = Register();
        var done = false;
        var cut = Render<SignInPanel>(p => p.Add(c => c.DeviceId, "web-1").Add(c => c.OnDone, () => done = true));

        cut.Find("input[data-signin=email]").Input("me@example.com");
        cut.Find("form[data-signin=email-form]").Submit();
        cut.WaitForAssertion(() => Assert.NotNull(cut.Find("input[data-signin=code]")));
        Assert.Contains("me@example.com", cut.Markup);

        cut.Find("input[data-signin=code]").Input("123456");
        cut.Find("form[data-signin=code-form]").Submit();
        cut.WaitForAssertion(() => Assert.NotNull(cut.Find("input[data-signin=name]")));

        cut.Find("input[data-signin=name]").Input("Ash");
        cut.Find("form[data-signin=name-form]").Submit();

        cut.WaitForAssertion(() => Assert.True(done));
        Assert.Equal(["start:me@example.com", "code:me@example.com:123456", "name:Ash"], session.Calls);
    }

    [Fact]
    public void A_named_account_skips_the_name_step()
    {
        var session = Register();
        session.NameAfterVerify = "Ash";
        var done = false;
        var cut = Render<SignInPanel>(p => p.Add(c => c.DeviceId, "web-1").Add(c => c.OnDone, () => done = true));

        cut.Find("input[data-signin=email]").Input("me@example.com");
        cut.Find("form[data-signin=email-form]").Submit();
        cut.WaitForAssertion(() => cut.Find("input[data-signin=code]").Input("123456"));
        cut.Find("form[data-signin=code-form]").Submit();

        cut.WaitForAssertion(() => Assert.True(done));
    }

    [Theory]
    [InlineData("invalidCode", "That code is wrong or has expired")]
    [InlineData("tooManyRequests", "Too many sign-in emails")]
    public void Server_errors_show_inline(string code, string text)
    {
        var session = Register();
        var error = new GameApiException(HttpStatusCode.BadRequest, code, "server text");
        if (code == "tooManyRequests") session.StartFailure = error; else session.VerifyFailure = error;
        var cut = Render<SignInPanel>(p => p.Add(c => c.DeviceId, "web-1"));

        cut.Find("input[data-signin=email]").Input("me@example.com");
        cut.Find("form[data-signin=email-form]").Submit();
        if (code == "invalidCode")
        {
            cut.WaitForAssertion(() => cut.Find("input[data-signin=code]").Input("000000"));
            cut.Find("form[data-signin=code-form]").Submit();
        }

        cut.WaitForAssertion(() => Assert.Contains(text, cut.Find("[role=alert]").TextContent));
    }

    [Fact]
    public void Resend_is_disabled_right_after_sending()
    {
        Register();
        var cut = Render<SignInPanel>(p => p.Add(c => c.DeviceId, "web-1"));
        cut.Find("input[data-signin=email]").Input("me@example.com");
        cut.Find("form[data-signin=email-form]").Submit();

        cut.WaitForAssertion(() => Assert.True(cut.Find("button[data-signin=resend]").HasAttribute("disabled")));
    }

    [Fact]
    public void Account_chip_renders_nothing_without_an_account_service()
    {
        var cut = Render<AccountChip>(p => p.Add(c => c.ClientKind, "web"));
        Assert.Empty(cut.FindAll("[data-account]"));
    }

    [Fact]
    public void Account_chip_shows_the_name_and_signs_out()
    {
        var session = Register(FakeAccountSession.Account("Ash"));
        var cut = Render<AccountChip>(p => p.Add(c => c.ClientKind, "web"));

        Assert.Contains("Ash", cut.Find("[data-account=chip]").TextContent);
        cut.Find("button[data-account=sign-out]").Click();

        cut.WaitForAssertion(() => Assert.NotNull(cut.Find("button[data-account=sign-in]")));
        Assert.True(session.SignedOut);
    }

    [Fact]
    public void Account_chip_asks_an_unnamed_account_for_a_name()
    {
        Register(FakeAccountSession.Account(""));
        var cut = Render<AccountChip>(p => p.Add(c => c.ClientKind, "web"));

        Assert.NotNull(cut.Find("input[data-signin=name]"));
    }
}
```

Add to `GameExperienceTests`:
```csharp
    [Fact]
    public void A_sign_in_link_is_verified_on_load_and_reported()
    {
        var sessions = new FakeSessionCoordinator();
        var account = new FakeAccountSession { NameAfterVerify = "Ash" };
        Services.AddSingleton<IGameSessionCoordinator>(sessions);
        Services.AddSingleton<IAccountSession>(account);
        JSInterop.Mode = JSRuntimeMode.Loose;
        var handled = false;

        var cut = Render<GameExperience>(p => p
            .Add(c => c.ClientKind, "web")
            .Add(c => c.InitialSignInToken, "tok-1")
            .Add(c => c.OnSignInLinkHandled, () => handled = true));

        cut.WaitForAssertion(() => Assert.Contains("Signed in as Ash", cut.Markup));
        Assert.Contains("link:tok-1", account.Calls);
        Assert.True(handled);
    }

    [Fact]
    public void An_expired_sign_in_link_explains_what_to_do()
    {
        Services.AddSingleton<IGameSessionCoordinator>(new FakeSessionCoordinator());
        Services.AddSingleton<IAccountSession>(new FakeAccountSession
        {
            VerifyFailure = new GameApiException(System.Net.HttpStatusCode.BadRequest, "invalidCode", "x")
        });
        JSInterop.Mode = JSRuntimeMode.Loose;

        var cut = Render<GameExperience>(p => p.Add(c => c.InitialSignInToken, "old"));

        cut.WaitForAssertion(() => Assert.Contains("That sign-in link has expired", cut.Markup));
    }
```

- [ ] **Step 2: Run to verify failure** — `dotnet test tests/BloodAndBanners.UI.Tests --filter "FullyQualifiedName~SignInPanelTests|FullyQualifiedName~GameExperienceTests"` → build errors.

- [ ] **Step 3: Implement**

`InviteLinks.cs` — add `public static string? SignInTokenFromUri(string uri) => QueryValue(uri, "signin");`.

`SignInPanel.razor`:
```razor
@namespace BloodAndBanners.UI.Components
@using BloodAndBanners.UI.Services
@implements IDisposable
@inject IAccountSession Account

<section class="sign-in" aria-labelledby="sign-in-title" data-signin="panel">
    <h2 id="sign-in-title">@(_step == Step.Name ? "Name your commander" : "Sign in")</h2>
    @if (_step == Step.Email)
    {
        <form data-signin="email-form" @onsubmit="SendCode" @onsubmit:preventDefault>
            <label for="signin-email">Email address</label>
            <input id="signin-email" data-signin="email" type="email" autocomplete="email"
                   @bind="_email" @bind:event="oninput" disabled="@_busy" />
            <div class="sign-in__actions">
                <button type="submit" disabled="@(_busy || string.IsNullOrWhiteSpace(_email))">Send code</button>
                <button type="button" class="ghost" @onclick="OnCancel">Not now</button>
            </div>
        </form>
    }
    else if (_step == Step.Code)
    {
        <form data-signin="code-form" @onsubmit="VerifyCode" @onsubmit:preventDefault>
            <p>We sent a 6-digit code to <strong>@_email</strong>. You can also open the link in that email.</p>
            <label for="signin-code">Code</label>
            <input id="signin-code" data-signin="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6"
                   @bind="_code" @bind:event="oninput" disabled="@_busy" />
            <div class="sign-in__actions">
                <button type="submit" disabled="@(_busy || _code.Trim().Length != 6)">Sign in</button>
                <button type="button" class="ghost" data-signin="resend" disabled="@(_busy || _resendLocked)" @onclick="SendCode">Resend</button>
                <button type="button" class="ghost" data-signin="change-email" @onclick="ChangeEmail">Use a different email</button>
            </div>
        </form>
    }
    else
    {
        <form data-signin="name-form" @onsubmit="SaveName" @onsubmit:preventDefault>
            <p>Choose the name other commanders will see.</p>
            <label for="signin-name">Name</label>
            <input id="signin-name" data-signin="name" maxlength="24" @bind="_name" @bind:event="oninput" disabled="@_busy" />
            <div class="sign-in__actions">
                <button type="submit" disabled="@(_busy || string.IsNullOrWhiteSpace(_name))">Save name</button>
            </div>
        </form>
    }
    @if (_error is not null)
    {
        <p class="sign-in__error" role="alert">@_error</p>
    }
</section>

@code {
    private enum Step { Email, Code, Name }

    [Parameter, EditorRequired]
    public string DeviceId { get; set; } = string.Empty;

    /// <summary>Skips straight to naming, for an account that is signed in but unnamed.</summary>
    [Parameter]
    public bool StartAtName { get; set; }

    [Parameter]
    public EventCallback OnDone { get; set; }

    [Parameter]
    public EventCallback OnCancel { get; set; }

    private Step _step = Step.Email;
    private string _email = string.Empty;
    private string _code = string.Empty;
    private string _name = string.Empty;
    private bool _busy;
    private bool _resendLocked;
    private string? _error;
    private CancellationTokenSource? _resendTimer;

    protected override void OnInitialized()
    {
        if (StartAtName)
        {
            _step = Step.Name;
        }
    }

    private Task SendCode() => Run(async () =>
    {
        await Account.StartSignInAsync(_email.Trim());
        _step = Step.Code;
        LockResend();
    });

    private Task VerifyCode() => Run(async () =>
    {
        var account = await Account.VerifyCodeAsync(_email.Trim(), _code.Trim(), DeviceId);
        if (string.IsNullOrEmpty(account.DisplayName))
        {
            _step = Step.Name;
            return;
        }
        await OnDone.InvokeAsync();
    });

    private Task SaveName() => Run(async () =>
    {
        await Account.SetDisplayNameAsync(_name.Trim());
        await OnDone.InvokeAsync();
    });

    private void ChangeEmail()
    {
        _step = Step.Email;
        _code = string.Empty;
        _error = null;
    }

    private void LockResend()
    {
        _resendTimer?.Cancel();
        _resendTimer = new CancellationTokenSource();
        _resendLocked = true;
        var token = _resendTimer.Token;
        _ = Task.Delay(TimeSpan.FromSeconds(30), token).ContinueWith(_ => InvokeAsync(() =>
        {
            _resendLocked = false;
            StateHasChanged();
        }), TaskContinuationOptions.OnlyOnRanToCompletion);
    }

    private async Task Run(Func<Task> action)
    {
        _busy = true;
        _error = null;
        try
        {
            await action();
        }
        catch (GameApiException error)
        {
            _error = error.Code switch
            {
                "invalidCode" => "That code is wrong or has expired. Check the email or request a new code.",
                "tooManyRequests" => "Too many sign-in emails. Wait a few minutes and try again.",
                "invalidEmail" => "Enter a valid email address.",
                "invalidDisplayName" => "Names must be 1 to 24 characters.",
                _ => "Sign-in failed. Check your connection and try again.",
            };
        }
        catch (HttpRequestException)
        {
            _error = "Sign-in failed. Check your connection and try again.";
        }
        finally
        {
            _busy = false;
        }
    }

    public void Dispose() => _resendTimer?.Cancel();
}
```

`SignInPanel.razor.css`: read `QuickStart.razor.css` first and match it. The panel uses the same card surface as `.join-room`. `.sign-in__actions` is a wrapping flex row with `gap: .5rem`. `.ghost` buttons copy the existing `.watch` button style. `.sign-in__error` uses QuickStart's error colour. Inputs are `width: 100%` so the panel works on phones.

`AccountChip.razor`:
```razor
@namespace BloodAndBanners.UI.Components
@using BloodAndBanners.UI.Services
@implements IDisposable
@inject IServiceProvider ServiceProvider

@if (Account is not null)
{
    <div class="account-chip">
        @if (Account.Current is { } signedIn)
        {
            @if (string.IsNullOrEmpty(signedIn.DisplayName))
            {
                <SignInPanel DeviceId="@signedIn.DeviceId" StartAtName="true" />
            }
            else
            {
                <span data-account="chip">Signed in as <strong>@signedIn.DisplayName</strong></span>
                <button type="button" class="ghost" data-account="sign-out" @onclick="SignOut">Sign out</button>
            }
        }
        else if (_signInDeviceId is not null)
        {
            <SignInPanel DeviceId="@_signInDeviceId" OnDone="() => _signInDeviceId = null" OnCancel="() => _signInDeviceId = null" />
        }
        else
        {
            <button type="button" class="ghost" data-account="sign-in" @onclick="BeginSignIn">Sign in</button>
            <span class="account-chip__hint">to add friends and resume games on any device</span>
        }
    </div>
}

@code {
    [Parameter]
    public string ClientKind { get; set; } = "client";

    /// <summary>Minted once when the panel opens, so re-renders keep the same device id.</summary>
    private string? _signInDeviceId;

    // Optional so screens and tests without account services still work.
    private IAccountSession? Account => ServiceProvider.GetService(typeof(IAccountSession)) as IAccountSession;

    protected override async Task OnInitializedAsync()
    {
        if (Account is { } account)
        {
            account.Changed += OnChanged;
            await account.LoadAsync();
        }
    }

    private void BeginSignIn() => _signInDeviceId = $"{ClientKind}-{Guid.NewGuid():N}";

    private void OnChanged() => InvokeAsync(StateHasChanged);

    private Task SignOut() => Account?.SignOutAsync() ?? Task.CompletedTask;

    public void Dispose()
    {
        if (Account is { } account)
        {
            account.Changed -= OnChanged;
        }
    }
}
```

`AccountChip.razor.css`: a small row at the top of the intro copy: `display:flex; flex-wrap:wrap; align-items:center; gap:.5rem; font-size:.9rem;`.

`QuickStart.razor`:
- add params:
```csharp
    [Parameter]
    public string ClientKind { get; set; } = "client";

    [Parameter]
    public string? StatusMessage { get; set; }

    /// <summary>Raised with a game id when a signed-in player resumes one of their games.</summary>
    [Parameter]
    public EventCallback<string> OnResume { get; set; }
```
- render `<AccountChip ClientKind="@ClientKind" />` as the first child of `<div class="intro-copy">`.
- render `@if (StatusMessage is not null) { <p class="status" role="status">@StatusMessage</p> }` just above the mode list, styled like the existing error line but neutral.

`GameExperience.razor`:
- add `@inject IServiceProvider ServiceProvider` and `private IAccountSession? Account => ServiceProvider.GetService(typeof(IAccountSession)) as IAccountSession;`
- params:
```csharp
    /// <summary>A token from an emailed sign-in link; verified once on load.</summary>
    [Parameter]
    public string? InitialSignInToken { get; set; }

    /// <summary>Raised after the sign-in link was used, so the host page can drop it from its address.</summary>
    [Parameter]
    public EventCallback OnSignInLinkHandled { get; set; }
```
- pass `ClientKind="@ClientKind"`, `StatusMessage="@_status"` and `OnResume="ResumeGame"` to `<QuickStart>`. Task 11 fills in `ResumeGame`; for now add the stub `private Task ResumeGame(string gameId) => Task.CompletedTask;`.
- at the start of `OnInitializedAsync` (before `_isBusy = true;`):
```csharp
        if (!string.IsNullOrWhiteSpace(InitialSignInToken) && Account is { } account)
        {
            try
            {
                var signedIn = await account.VerifyLinkAsync(InitialSignInToken!, DeviceId(), _pollCancellation.Token);
                _status = string.IsNullOrEmpty(signedIn.DisplayName)
                    ? "You are signed in. Choose your name above."
                    : $"Signed in as {signedIn.DisplayName}.";
            }
            catch (Exception) when (!_pollCancellation.IsCancellationRequested)
            {
                _error = "That sign-in link has expired or was already used. Request a new code.";
            }
            await OnSignInLinkHandled.InvokeAsync();
        }
```
  Check that nothing later in `OnInitializedAsync` clears `_status`/`_error` when no saved seat exists. If something does, move this block after the reconnect.

DI — in `src/BloodAndBanners.Web/Program.cs`, after the `IGameApiClient` line:
```csharp
builder.Services.AddScoped<IAccountApiClient, AccountApiClient>();
builder.Services.AddScoped<IAccountStore, JsonAccountStore>();
builder.Services.AddScoped<IAccountSession, AccountSession>();
```
In `src/BloodAndBanners.Maui/MauiProgram.cs`, register the same three with `AddSingleton`, using full type names as that file does.

`src/BloodAndBanners.Web/Pages/Home.razor`:
- add `InitialSignInToken="@InviteLinks.SignInTokenFromUri(Navigation.Uri)"` and `OnSignInLinkHandled="ClearLink"`.
- rename `ClearWatchLink` to `ClearLink`. Update its comment to: "Drop ?watch= or ?signin= from the address so a reload does not repeat it." Update `OnStoppedWatching` to use `ClearLink`.

`src/BloodAndBanners.Maui/Components/Pages/Home.razor`:
- add a field `private string? _signInToken;`.
- set it in `ReadLink` with `_signInToken = InviteLinks.SignInTokenFromUri(uri);`.
- pass `InitialSignInToken="@_signInToken" OnSignInLinkHandled="() => _signInToken = null"`.

- [ ] **Step 4: Run tests and builds**

- `dotnet test tests/BloodAndBanners.UI.Tests` → all pass.
- `dotnet build src/BloodAndBanners.Web` → succeeds.
- Build the MAUI host for Android. Check the target framework in `BloodAndBanners.Maui.csproj` and pass it with `-f` → succeeds.

- [ ] **Step 5: Commit**

```bash
git add src tests/BloodAndBanners.UI.Tests
git commit -m "Add email sign-in panel, account bar and sign-in links"
```

---

### Task 11: Start page — your games, friends' games, friends panel

**Files:**
- Create: `src/BloodAndBanners.UI/Components/AccountHome.razor(.css)`, `AccountGamesList.razor(.css)`, `FriendsPanel.razor(.css)`
- Modify: `src/BloodAndBanners.UI/Components/QuickStart.razor`, `GameExperience.razor`
- Test: `tests/BloodAndBanners.UI.Tests/AccountHomeTests.cs`, additions to `GameExperienceTests.cs`

**Interfaces:**
- Consumes: `IAccountSession` (`CallAsync`, `Api.*`), `AccountGames`, `FriendsList`; `QuickStart.OnJoin`/`OnWatch`/`OnResume`.
- Produces:
  - `AccountHome` with params `bool IsBusy`, `EventCallback<string> OnResume`, `OnJoin`, `OnWatch` and `TimeSpan PollInterval` (default 10 s).
  - `AccountGamesList` with params `string Title`, `IReadOnlyList<AccountGameRow> Rows`, `bool Mine`, `bool IsBusy`, `string EmptyText` and the callbacks.
  - `FriendsPanel` with params `FriendsList Friends` and `EventCallback<FriendsList> OnChanged`.
  - `GameExperience.ResumeGame(string)`.

- [ ] **Step 1: Write the failing tests** — `tests/BloodAndBanners.UI.Tests/AccountHomeTests.cs`

```csharp
using System.Net;
using BloodAndBanners.Contracts;
using BloodAndBanners.UI.Components;
using BloodAndBanners.UI.Services;
using Bunit;
using Microsoft.Extensions.DependencyInjection;

namespace BloodAndBanners.UI.Tests;

public sealed class AccountHomeTests : BunitContext
{
    private static AccountGameRow Row(string id, GameLifecycle lifecycle, bool canJoin, params string?[] names) =>
        new(id, GameMode.VanillaPvp, "Four Corners", lifecycle,
            names.Select((name, i) => new AccountGameSeat(i + 1, name, false)).ToList(), canJoin, "2026-10-21 10:00:00");

    private FakeAccountSession SignedIn()
    {
        var session = new FakeAccountSession(FakeAccountSession.Account());
        Services.AddSingleton<IAccountSession>(session);
        return session;
    }

    [Fact]
    public void Nothing_renders_without_account_services()
    {
        var cut = Render<AccountHome>();
        Assert.Empty(cut.FindAll("[data-account-home]"));
    }

    [Fact]
    public void Nothing_renders_when_signed_out()
    {
        Services.AddSingleton<IAccountSession>(new FakeAccountSession());
        var cut = Render<AccountHome>();
        Assert.Empty(cut.FindAll("[data-account-home]"));
    }

    [Fact]
    public void Your_games_offer_resume_and_friends_games_offer_join_or_watch()
    {
        var session = SignedIn();
        session.FakeApi.Games = new AccountGames(
            [Row("mine", GameLifecycle.Active, false, "Ash", null)],
            [Row("open", GameLifecycle.Lobby, true, "Fen"), Row("busy", GameLifecycle.Active, false, "Fen", "Bryn")]);
        string? resumed = null, joined = null, watched = null;

        var cut = Render<AccountHome>(p => p
            .Add(c => c.OnResume, id => resumed = id)
            .Add(c => c.OnJoin, id => joined = id)
            .Add(c => c.OnWatch, id => watched = id));

        cut.WaitForAssertion(() => Assert.NotNull(cut.Find("[data-game=mine] button[data-action=resume-game]")));
        Assert.Contains("Commander 2", cut.Find("[data-game=mine]").TextContent);
        cut.Find("[data-game=mine] button[data-action=resume-game]").Click();
        cut.Find("[data-game=open] button[data-action=join-friend-game]").Click();
        cut.Find("[data-game=busy] button[data-action=watch-friend-game]").Click();

        Assert.Equal(("mine", "open", "busy"), (resumed, joined, watched));
    }

    [Fact]
    public void Empty_lists_explain_themselves()
    {
        SignedIn();
        var cut = Render<AccountHome>();
        cut.WaitForAssertion(() => Assert.Contains("No unfinished games", cut.Markup));
        Assert.Contains("Add a friend to see their games here", cut.Markup);
    }

    [Fact]
    public void Incoming_requests_show_a_badge_and_can_be_accepted()
    {
        var session = SignedIn();
        session.FakeApi.Friends = new FriendsList([], [new IncomingFriendRequest("r1", "Bryn")], []);
        var cut = Render<AccountHome>();

        cut.WaitForAssertion(() => Assert.Equal("1", cut.Find("[data-friends=badge]").TextContent.Trim()));
        session.FakeApi.Friends = new FriendsList([new FriendSummary("u2", "Bryn")], [], []);
        cut.Find("button[data-friend-accept=r1]").Click();

        cut.WaitForAssertion(() => Assert.Contains("accept:r1", session.FakeApi.Calls));
        cut.WaitForAssertion(() => Assert.Empty(cut.FindAll("[data-friends=badge]")));
        Assert.Contains("Bryn", cut.Find("[data-friends=list]").TextContent);
    }

    [Fact]
    public void Adding_a_friend_by_email_reports_conflicts_kindly()
    {
        var session = SignedIn();
        session.FakeApi.FriendFailure = new GameApiException(HttpStatusCode.Conflict, "alreadyRequested", "x");
        var cut = Render<AccountHome>();

        cut.WaitForAssertion(() => cut.Find("input[data-friends=email]").Input("b@example.com"));
        cut.Find("form[data-friends=add]").Submit();

        cut.WaitForAssertion(() => Assert.Contains("already sent", cut.Find("[data-friends=error]").TextContent));
    }

    [Fact]
    public void Removing_a_friend_needs_a_second_click()
    {
        var session = SignedIn();
        session.FakeApi.Friends = new FriendsList([new FriendSummary("u2", "Fen")], [], []);
        var cut = Render<AccountHome>();

        cut.WaitForAssertion(() => cut.Find("button[data-friend-remove=u2]").Click());
        Assert.DoesNotContain("remove:u2", session.FakeApi.Calls);
        cut.Find("button[data-friend-confirm-remove=u2]").Click();

        cut.WaitForAssertion(() => Assert.Contains("remove:u2", session.FakeApi.Calls));
    }

    [Fact]
    public void Lists_refresh_on_the_poll_interval()
    {
        var session = SignedIn();
        var cut = Render<AccountHome>(p => p.Add(c => c.PollInterval, TimeSpan.FromMilliseconds(30)));

        cut.WaitForAssertion(() => Assert.True(session.FakeApi.Calls.Count(c => c == "games") >= 2), TimeSpan.FromSeconds(2));
    }
}
```

Add to `GameExperienceTests` (Review Focus 5):
```csharp
    [Fact]
    public void Resuming_a_game_you_were_removed_from_explains_it()
    {
        var sessions = new FakeSessionCoordinator
        {
            ResumeFailure = new GameApiException(System.Net.HttpStatusCode.NotFound, "gameNotFound", "gone")
        };
        var account = new FakeAccountSession(FakeAccountSession.Account());
        account.FakeApi.Games = new AccountGames(
            [new AccountGameRow("kicked", GameMode.VanillaPvp, "Four Corners", GameLifecycle.Lobby, [], false, "")], []);
        Services.AddSingleton<IGameSessionCoordinator>(sessions);
        Services.AddSingleton<IAccountSession>(account);
        JSInterop.Mode = JSRuntimeMode.Loose;
        var cut = Render<GameExperience>();

        cut.WaitForAssertion(() => cut.Find("[data-game=kicked] button[data-action=resume-game]").Click());

        cut.WaitForAssertion(() => Assert.Contains("You no longer hold a seat in that game.", cut.Markup));
        Assert.Equal("kicked", sessions.ResumeRequest);
        Assert.NotNull(cut.Find("button[data-mode=vanillaPvp]"));
    }
```

- [ ] **Step 2: Run to verify failure** — `dotnet test tests/BloodAndBanners.UI.Tests --filter "FullyQualifiedName~AccountHomeTests"` → build errors.

- [ ] **Step 3: Implement**

`AccountGamesList.razor`:
```razor
@namespace BloodAndBanners.UI.Components
@using BloodAndBanners.Contracts

<section class="account-games" aria-label="@Title">
    <h3>@Title</h3>
    @if (Rows.Count == 0)
    {
        <p class="account-games__empty">@EmptyText</p>
    }
    else
    {
        <ul>
            @foreach (var row in Rows)
            {
                <li data-game="@row.GameId">
                    <div class="account-games__what">
                        <strong>@row.ScenarioName</strong>
                        <span>@ModeLabel(row.Mode)</span>
                        <span class="account-games__badge">@LifecycleLabel(row.Lifecycle)</span>
                    </div>
                    <div class="account-games__who">
                        @string.Join(" · ", row.Seats.Select(seat => string.IsNullOrEmpty(seat.Name) ? $"Commander {seat.SeatIndex}" : seat.Name))
                    </div>
                    @if (Mine)
                    {
                        <button type="button" data-action="resume-game" disabled="@IsBusy" @onclick="() => OnResume.InvokeAsync(row.GameId)">Resume</button>
                    }
                    else if (row.CanJoin)
                    {
                        <button type="button" data-action="join-friend-game" disabled="@IsBusy" @onclick="() => OnJoin.InvokeAsync(row.GameId)">Join</button>
                    }
                    else
                    {
                        <button type="button" class="ghost" data-action="watch-friend-game" disabled="@IsBusy" @onclick="() => OnWatch.InvokeAsync(row.GameId)">Watch</button>
                    }
                </li>
            }
        </ul>
    }
</section>

@code {
    [Parameter, EditorRequired] public string Title { get; set; } = string.Empty;
    [Parameter, EditorRequired] public IReadOnlyList<AccountGameRow> Rows { get; set; } = [];
    [Parameter] public bool Mine { get; set; }
    [Parameter] public bool IsBusy { get; set; }
    [Parameter] public string EmptyText { get; set; } = string.Empty;
    [Parameter] public EventCallback<string> OnResume { get; set; }
    [Parameter] public EventCallback<string> OnJoin { get; set; }
    [Parameter] public EventCallback<string> OnWatch { get; set; }

    private static string ModeLabel(GameMode mode) => mode switch
    {
        GameMode.VanillaPvp => "Vanilla duel",
        GameMode.Coop => "Void incursion",
        _ => "Battle royale",
    };

    private static string LifecycleLabel(GameLifecycle lifecycle) => lifecycle switch
    {
        GameLifecycle.Lobby => "War room",
        GameLifecycle.Draft => "Drafting",
        GameLifecycle.Setup => "Setting up",
        _ => "In battle",
    };
}
```
`GameTable.razor` already has a `ModeLabel`. If its strings match these, move it into a shared static class under `Services` and use it in both places instead of duplicating it.

`FriendsPanel.razor`:
```razor
@namespace BloodAndBanners.UI.Components
@using BloodAndBanners.Contracts
@using BloodAndBanners.UI.Services
@inject IAccountSession Account

<section class="friends-panel" aria-labelledby="friends-title">
    <h3 id="friends-title">
        Friends
        @if (Friends.Incoming.Count > 0)
        {
            <span class="friends-panel__badge" data-friends="badge" aria-label="@($"{Friends.Incoming.Count} friend requests")">@Friends.Incoming.Count</span>
        }
    </h3>

    <form data-friends="add" @onsubmit="Add" @onsubmit:preventDefault>
        <label for="friend-email">Add a friend by email</label>
        <div>
            <input id="friend-email" data-friends="email" type="email" @bind="_email" @bind:event="oninput" disabled="@_busy" />
            <button type="submit" disabled="@(_busy || string.IsNullOrWhiteSpace(_email))">Send request</button>
        </div>
    </form>
    @if (_error is not null)
    {
        <p class="friends-panel__error" role="alert" data-friends="error">@_error</p>
    }

    @if (Friends.Incoming.Count > 0)
    {
        <ul class="friends-panel__incoming">
            @foreach (var request in Friends.Incoming)
            {
                <li>
                    <span><strong>@request.DisplayName</strong> wants to be friends</span>
                    <button type="button" data-friend-accept="@request.RequestId" disabled="@_busy" @onclick="() => Change(i => Account.Api.AcceptFriendAsync(i, request.RequestId))">Accept</button>
                    <button type="button" class="ghost" data-friend-decline="@request.RequestId" disabled="@_busy" @onclick="() => Change(i => Account.Api.DeclineFriendAsync(i, request.RequestId))">Decline</button>
                </li>
            }
        </ul>
    }

    <ul class="friends-panel__list" data-friends="list">
        @foreach (var friend in Friends.Friends)
        {
            <li>
                <span>@friend.DisplayName</span>
                @if (_confirmRemove == friend.UserId)
                {
                    <button type="button" class="danger" data-friend-confirm-remove="@friend.UserId" disabled="@_busy" @onclick="() => Change(i => Account.Api.RemoveFriendAsync(i, friend.UserId))">Confirm remove</button>
                    <button type="button" class="ghost" @onclick="() => _confirmRemove = null">Keep</button>
                }
                else
                {
                    <button type="button" class="ghost" data-friend-remove="@friend.UserId" @onclick="() => _confirmRemove = friend.UserId">Remove</button>
                }
            </li>
        }
    </ul>

    @if (Friends.Outgoing.Count > 0)
    {
        <ul class="friends-panel__outgoing">
            @foreach (var request in Friends.Outgoing)
            {
                <li>
                    <span>Waiting for <strong>@request.Label</strong></span>
                    <button type="button" class="ghost" data-friend-cancel="@request.RequestId" disabled="@_busy" @onclick="() => Change(i => Account.Api.DeclineFriendAsync(i, request.RequestId))">Cancel</button>
                </li>
            }
        </ul>
    }
</section>

@code {
    [Parameter, EditorRequired] public FriendsList Friends { get; set; } = new([], [], []);
    [Parameter] public EventCallback<FriendsList> OnChanged { get; set; }

    private string _email = string.Empty;
    private string? _confirmRemove;
    private string? _error;
    private bool _busy;

    private async Task Add()
    {
        var email = _email.Trim();
        if (await Change(i => Account.Api.RequestFriendAsync(i, email)))
        {
            _email = string.Empty;
        }
    }

    private async Task<bool> Change(Func<AuthSession, Task<FriendsList>> call)
    {
        _busy = true;
        _error = null;
        try
        {
            var updated = await Account.CallAsync(call);
            _confirmRemove = null;
            await OnChanged.InvokeAsync(updated);
            return true;
        }
        catch (GameApiException error)
        {
            _error = error.Code switch
            {
                "alreadyFriends" => "You're already friends.",
                "alreadyRequested" => "You already sent that request.",
                "cannotFriendSelf" => "That's your own address.",
                "invalidEmail" => "Enter a valid email address.",
                "requestNotFound" or "friendNotFound" => "That request has changed. The list will refresh shortly.",
                _ => "That didn't work. Check your connection and try again.",
            };
            return false;
        }
        catch (HttpRequestException)
        {
            _error = "That didn't work. Check your connection and try again.";
            return false;
        }
        finally
        {
            _busy = false;
        }
    }
}
```

`AccountHome.razor`:
```razor
@namespace BloodAndBanners.UI.Components
@using BloodAndBanners.Contracts
@using BloodAndBanners.UI.Services
@implements IDisposable
@inject IServiceProvider ServiceProvider

@if (Account?.Current is not null)
{
    <section class="account-home" data-account-home>
        <AccountGamesList Title="Your games" Rows="_games.Yours" Mine="true" IsBusy="IsBusy"
                          EmptyText="No unfinished games." OnResume="OnResume" />
        <AccountGamesList Title="Friends' games" Rows="_games.Friends" IsBusy="IsBusy"
                          EmptyText="@(_friends.Friends.Count == 0 ? "Add a friend to see their games here." : "None of your friends are in a game right now.")"
                          OnJoin="OnJoin" OnWatch="OnWatch" />
        <FriendsPanel Friends="_friends" OnChanged="FriendsChanged" />
    </section>
}

@code {
    [Parameter] public bool IsBusy { get; set; }
    [Parameter] public EventCallback<string> OnResume { get; set; }
    [Parameter] public EventCallback<string> OnJoin { get; set; }
    [Parameter] public EventCallback<string> OnWatch { get; set; }
    [Parameter] public TimeSpan PollInterval { get; set; } = TimeSpan.FromSeconds(10);

    private readonly CancellationTokenSource _stop = new();
    private AccountGames _games = new([], []);
    private FriendsList _friends = new([], [], []);

    // Optional so the start page and tests without account services still work.
    private IAccountSession? Account => ServiceProvider.GetService(typeof(IAccountSession)) as IAccountSession;

    protected override async Task OnInitializedAsync()
    {
        if (Account is not { } account)
        {
            return;
        }
        account.Changed += OnAccountChanged;
        await account.LoadAsync();
        await RefreshAsync();
        _ = PollAsync(_stop.Token);
    }

    private async Task PollAsync(CancellationToken token)
    {
        using var timer = new PeriodicTimer(PollInterval);
        try
        {
            while (await timer.WaitForNextTickAsync(token))
            {
                await InvokeAsync(async () =>
                {
                    await RefreshAsync();
                    StateHasChanged();
                });
            }
        }
        catch (OperationCanceledException)
        {
        }
    }

    private async Task RefreshAsync()
    {
        if (Account is not { Current: not null } account)
        {
            _games = new([], []);
            _friends = new([], [], []);
            return;
        }
        try
        {
            _games = await account.CallAsync(identity => account.Api.GetGamesAsync(identity, _stop.Token), _stop.Token);
            _friends = await account.CallAsync(identity => account.Api.GetFriendsAsync(identity, _stop.Token), _stop.Token);
        }
        catch (Exception) when (!_stop.IsCancellationRequested)
        {
            // Keep the last lists; the next poll tries again.
        }
    }

    private void FriendsChanged(FriendsList updated) => _friends = updated;

    private void OnAccountChanged() => InvokeAsync(async () =>
    {
        await RefreshAsync();
        StateHasChanged();
    });

    public void Dispose()
    {
        _stop.Cancel();
        if (Account is { } account)
        {
            account.Changed -= OnAccountChanged;
        }
    }
}
```

CSS (`AccountHome.razor.css`, `AccountGamesList.razor.css`, `FriendsPanel.razor.css`): read `QuickStart.razor.css` and match its card surfaces, spacing and button styles.
- `.account-home` is a grid: one column on phones, `grid-template-columns: 1fr 1fr` above 900px. The friends panel spans both columns.
- Each row is a wrapping flex with the button pushed right.
- `.friends-panel__badge` is a small round pill in the gold accent.
- Memory notes that Void Lord buttons render white, so check button contrast by eye.

`QuickStart.razor` — inside the `@if (!_choosingBattlefield)` branch, after the `mode-list` div, add:
```razor
        <AccountHome IsBusy="IsBusy" OnResume="OnResume" OnJoin="OnJoin" OnWatch="OnWatch" />
```

`GameExperience.razor` — replace the `ResumeGame` stub:
```csharp
    private Task ResumeGame(string gameId) => RunCommand(async () =>
    {
        _generation++;
        _watchOffer = null;
        try
        {
            ApplyProjection(await Sessions.ResumeAccountGameAsync(gameId, _pollCancellation.Token));
        }
        catch (GameApiException error) when (error.StatusCode == HttpStatusCode.NotFound)
        {
            _error = "You no longer hold a seat in that game.";
            return;
        }
        _status = null;
        StartPolling();
    }, "That game could not be resumed. Check your connection and try again.");
```
`JoinGame` sets `_error` inside the action the same way, so this pattern works. Still, read `RunCommand` first to confirm it doesn't clear `_error` after the action returns.

- [ ] **Step 4: Run tests** — `dotnet test tests/BloodAndBanners.UI.Tests` → all pass.

- [ ] **Step 5: Commit**

```bash
git add src/BloodAndBanners.UI tests/BloodAndBanners.UI.Tests
git commit -m "Show your games, friends' games and friends on the start page"
```

---

### Task 12: Add friend from the lobby and the match menu

**Files:**
- Create: `src/BloodAndBanners.UI/Components/AddFriendButton.razor`, `AddFriendButton.razor.css`
- Modify: `src/BloodAndBanners.UI/Components/WarRoomLobby.razor`, `MatchMenu.razor`, `MatchMenu.razor.css`, `GameTable.razor`, `DraftTable.razor`
- Test: `tests/BloodAndBanners.UI.Tests/AddFriendButtonTests.cs`, additions to `MatchMenuTests.cs` and `WarRoomLobbyTests.cs`

**Interfaces:**
- Consumes: `MembershipProjection.IsAccount`, `IsFriend` and `FriendRequested`; `IAccountSession`.
- Produces:
  - `AddFriendButton` with params `string GameId`, `MembershipProjection Seat` and `string? ViewerSeatId`.
  - `MatchMenu` new params `IReadOnlyList<MembershipProjection> Members` (default `[]`) and `string? ViewerSeatId`.

- [ ] **Step 1: Write the failing tests** — `tests/BloodAndBanners.UI.Tests/AddFriendButtonTests.cs`

```csharp
using System.Net;
using BloodAndBanners.Contracts;
using BloodAndBanners.UI.Components;
using BloodAndBanners.UI.Services;
using Bunit;
using Microsoft.Extensions.DependencyInjection;

namespace BloodAndBanners.UI.Tests;

public sealed class AddFriendButtonTests : BunitContext
{
    private static MembershipProjection Seat(bool account = true, bool friend = false, bool requested = false) =>
        new("seat-2", 2, false, false) { Name = "Bryn", IsAccount = account, IsFriend = friend, FriendRequested = requested };

    private IRenderedComponent<AddFriendButton> RenderFor(MembershipProjection seat, string viewer = "seat-1") =>
        Render<AddFriendButton>(p => p.Add(c => c.GameId, "g1").Add(c => c.Seat, seat).Add(c => c.ViewerSeatId, viewer));

    [Fact]
    public void Hidden_without_a_signed_in_account()
    {
        Services.AddSingleton<IAccountSession>(new FakeAccountSession());
        Assert.Empty(RenderFor(Seat()).FindAll("[data-add-friend]"));
    }

    [Theory]
    [InlineData(false, false, false, "seat-1", null)]
    [InlineData(true, false, false, "seat-2", null)]
    [InlineData(true, true, false, "seat-1", "Friend")]
    [InlineData(true, false, true, "seat-1", "Requested")]
    public void Shows_the_right_state(bool account, bool friend, bool requested, string viewer, string? label)
    {
        Services.AddSingleton<IAccountSession>(new FakeAccountSession(FakeAccountSession.Account()));
        var cut = RenderFor(Seat(account, friend, requested), viewer);
        if (label is null)
        {
            Assert.Empty(cut.FindAll("[data-add-friend]"));
        }
        else
        {
            Assert.Equal(label, cut.Find("[data-add-friend]").TextContent.Trim());
        }
    }

    [Fact]
    public void Clicking_sends_a_seat_request_and_shows_requested()
    {
        var session = new FakeAccountSession(FakeAccountSession.Account());
        Services.AddSingleton<IAccountSession>(session);
        var cut = RenderFor(Seat());

        cut.Find("button[data-add-friend]").Click();

        cut.WaitForAssertion(() => Assert.Equal("Requested", cut.Find("[data-add-friend]").TextContent.Trim()));
        Assert.Contains("seat:g1:seat-2", session.FakeApi.Calls);
    }

    [Fact]
    public void An_already_requested_conflict_still_shows_requested()
    {
        var session = new FakeAccountSession(FakeAccountSession.Account());
        session.FakeApi.FriendFailure = new GameApiException(HttpStatusCode.Conflict, "alreadyRequested", "x");
        Services.AddSingleton<IAccountSession>(session);
        var cut = RenderFor(Seat());

        cut.Find("button[data-add-friend]").Click();

        cut.WaitForAssertion(() => Assert.Equal("Requested", cut.Find("[data-add-friend]").TextContent.Trim()));
    }
}
```

Add to `MatchMenuTests` (also add `using BloodAndBanners.Contracts; using BloodAndBanners.UI.Services; using Microsoft.Extensions.DependencyInjection;`):
```csharp
    [Fact]
    public void Signed_in_players_see_the_commanders_with_add_friend()
    {
        JSInterop.Mode = JSRuntimeMode.Loose;
        Services.AddSingleton<IAccountSession>(new FakeAccountSession(FakeAccountSession.Account()));
        var cut = Render<MatchMenu>(p => p
            .Add(m => m.GameId, "g1")
            .Add(m => m.ViewerSeatId, "seat-1")
            .Add(m => m.Members, new List<MembershipProjection>
            {
                new("seat-1", 1, true, true) { Name = "Ash", IsAccount = true },
                new("seat-2", 2, true, false) { Name = "Bryn", IsAccount = true },
            }));

        cut.Find("[data-menu=toggle]").Click();

        Assert.Contains("Bryn", cut.Find("[data-menu=commanders]").TextContent);
        Assert.Single(cut.FindAll("button[data-add-friend]"));
    }

    [Fact]
    public void Guests_see_no_commanders_section()
    {
        JSInterop.Mode = JSRuntimeMode.Loose;
        var cut = RenderMenu();
        cut.Find("[data-menu=toggle]").Click();
        Assert.Empty(cut.FindAll("[data-menu=commanders]"));
    }
```

Add to `WarRoomLobbyTests`, using the file's existing `CoopLobby` helper (add an optional `bool secondIsAccount = false` parameter that sets `IsAccount` on `seat-2`):
```csharp
    [Fact]
    public void Signed_in_viewers_can_friend_account_seats_in_the_war_room()
    {
        Services.AddSingleton<IAccountSession>(new FakeAccountSession(FakeAccountSession.Account()));
        var cut = Render<WarRoomLobby>(parameters => parameters
            .Add(c => c.Projection, CoopLobby("seat-1", true, null, secondIsAccount: true, "setReady")));

        Assert.Single(cut.FindAll("[data-seat='2'] button[data-add-friend]"));
        Assert.Empty(cut.FindAll("[data-seat='1'] [data-add-friend]"));
    }
```
`CoopLobby` uses `params string[] actions`, so put the new optional parameter before `params` and update existing callers if needed. Also copy any other parameters the existing tests pass to `WarRoomLobby` that are `EditorRequired`.

- [ ] **Step 2: Run to verify failure** — `dotnet test tests/BloodAndBanners.UI.Tests --filter "FullyQualifiedName~AddFriendButtonTests|FullyQualifiedName~MatchMenuTests|FullyQualifiedName~WarRoomLobbyTests"` → build errors.

- [ ] **Step 3: Implement**

`AddFriendButton.razor`:
```razor
@namespace BloodAndBanners.UI.Components
@using BloodAndBanners.Contracts
@using BloodAndBanners.UI.Services
@inject IServiceProvider ServiceProvider

@if (Visible)
{
    @if (Seat.IsFriend)
    {
        <span class="add-friend add-friend--done" data-add-friend>Friend</span>
    }
    else if (Seat.FriendRequested || _sent)
    {
        <span class="add-friend add-friend--done" data-add-friend>Requested</span>
    }
    else
    {
        <button type="button" class="add-friend" data-add-friend disabled="@_busy" @onclick="Send">Add friend</button>
    }
}

@code {
    [Parameter, EditorRequired] public string GameId { get; set; } = string.Empty;
    [Parameter, EditorRequired] public MembershipProjection Seat { get; set; } = default!;
    [Parameter] public string? ViewerSeatId { get; set; }

    private bool _sent;
    private bool _busy;

    // Optional so screens and tests without account services still work.
    private IAccountSession? Account => ServiceProvider.GetService(typeof(IAccountSession)) as IAccountSession;

    private bool Visible => Account?.Current is not null && Seat.IsAccount && Seat.SeatId != ViewerSeatId;

    private async Task Send()
    {
        if (Account is not { } account)
        {
            return;
        }
        _busy = true;
        try
        {
            await account.CallAsync(identity => account.Api.RequestFriendFromSeatAsync(identity, GameId, Seat.SeatId));
            _sent = true;
        }
        catch (GameApiException error) when (error.Code is "alreadyRequested" or "alreadyFriends")
        {
            _sent = true;
        }
        catch (HttpRequestException)
        {
            // The button stays; the player can try again.
        }
        finally
        {
            _busy = false;
        }
    }
}
```
`AddFriendButton.razor.css`: a compact pill button (`font-size: .8rem; padding: .15rem .6rem;`). The `--done` state is muted text, not a button.

`WarRoomLobby.razor` — inside the occupied-seat branch, right after the `<strong>...name...</strong>` line, add:
```razor
                            <AddFriendButton GameId="@Projection.GameId" Seat="seat" ViewerSeatId="@Projection.Viewer.SeatId" />
```

`MatchMenu.razor`:
- add `@using BloodAndBanners.Contracts` and `@inject IServiceProvider ServiceProvider`.
- params:
```csharp
    [Parameter]
    public IReadOnlyList<MembershipProjection> Members { get; set; } = [];

    [Parameter]
    public string? ViewerSeatId { get; set; }

    private bool SignedIn => (ServiceProvider.GetService(typeof(IAccountSession)) as IAccountSession)?.Current is not null;
```
- inside the panel, right after the details block:
```razor
            @if (SignedIn && Members.Any(member => member.IsAccount && member.SeatId != ViewerSeatId))
            {
                <div class="match-menu-commanders" role="none" data-menu="commanders">
                    @foreach (var member in Members.Where(member => member.SeatId != ViewerSeatId))
                    {
                        <div class="match-menu-commander">
                            <span>@(string.IsNullOrEmpty(member.Name) ? $"Commander {member.SeatIndex}" : member.Name)</span>
                            <AddFriendButton GameId="@GameId" Seat="member" ViewerSeatId="@ViewerSeatId" />
                        </div>
                    }
                </div>
            }
```
  Memory says all seat labels go through `SeatNames.Display`. Read `Services/SeatNames.cs`; if it can label a membership without a full projection, use it here instead of the inline fallback.
- `MatchMenu.razor.css`: `.match-menu-commander { display:flex; justify-content:space-between; align-items:center; gap:.5rem; }`.

`GameTable.razor` and `DraftTable.razor` — add to their `<MatchMenu ...>`:
```razor
               Members="@Projection.Memberships"
               ViewerSeatId="@Projection.Viewer.SeatId"
```

- [ ] **Step 4: Run tests** — `dotnet test tests/BloodAndBanners.UI.Tests` → all pass.

- [ ] **Step 5: Commit**

```bash
git add src/BloodAndBanners.UI tests/BloodAndBanners.UI.Tests
git commit -m "Add friend buttons to the lobby and match menu"
```

---

### Task 13: Docs and end-to-end check

**Files:**
- Modify: `README.md`

- [ ] **Step 1: README** — add a section after "Play a two-browser Vanilla duel":

```markdown
### Sign in and friends (optional)

Signing in is optional; guests can keep playing with game codes and QR invites. To try accounts locally:

1. Start the API and PWA as above. Choose **Sign in** on the start page and enter any email address.
2. Without `SMTP_HOST`, the API writes sign-in emails to `api/var/mail.log`. Copy the 6-digit code from there (or open the `?signin=` link).
3. Pick a display name. It becomes your commander name in new games.
4. In a second browser profile, sign in with another address.
5. Add each other by email from the **Friends** panel, or choose **Add friend** next to a signed-in commander in the war room or the ☰ menu. The other player accepts, or sends a request back.
6. The start page lists **Your games**, which you can **Resume** on any device, and **Friends' games**. **Join** a friend's open war room; otherwise **Watch** the match.

Production mail goes through IONOS SMTP. Add these keys to the server `.env` (never commit the password):

    SMTP_HOST=smtp.ionos.com
    SMTP_PORT=465
    SMTP_USER=noreply@trickypig.com
    SMTP_PASS=…
    MAIL_FROM=noreply@trickypig.com
    APP_PUBLIC_URL=https://blood.trickypig.com/play
```

- [ ] **Step 2: Full test suites**

- `php api/tests/run.php 2>&1 | grep -E "FAIL|tests,"` → `0 failures`.
- `dotnet test tests/BloodAndBanners.Contracts.Tests` and `dotnet test tests/BloodAndBanners.UI.Tests` → all pass. A queued `AttackPopup` test is known to be flaky on main (memory); if it fails, re-run it on its own.

- [ ] **Step 3: Two-browser smoke test** (use the `run` skill or start both servers by hand; mind the dev-server port notes in memory `map-preview-slice`)

1. In browser A, sign in as `a@example.com` (code from `api/var/mail.log`) and choose the name "Ash". Open a Vanilla war room.
2. In browser B (another profile), sign in as `b@example.com` and choose "Bryn". In Friends, add `a@example.com`.
3. In A, leave the war room for the start page. The Friends badge shows 1; accept the request. Resume the war room from **Your games**.
4. In B, **Friends' games** now lists A's room with **Join**. Join it.
5. Both players ready up and start, then draft a few picks. In A, open the ☰ menu: Bryn shows "Friend".
6. In A, choose **Leave** to return to the start page. **Your games** lists the match, and **Resume** brings it back.
7. Paste the `?signin=` link from the log into a fresh private window. It signs in, and the token disappears from the address bar.
8. Sign out in B. The start page returns to the plain guest layout, and joining by code still works.

Record any visual issues for the user. Only say the UI was checked by eye if it actually was.

- [ ] **Step 4: Commit**

```bash
git add README.md
git commit -m "Document sign-in, friends and mail settings"
```
