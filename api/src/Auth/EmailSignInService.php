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
                // Increment in SQL and only while still open, so parallel guesses cannot lose counts or reopen a consumed challenge.
                // consumed_at is assigned first: MySQL evaluates SET left to right, so `attempts` is still the old value here.
                // The limit is an int constant inlined on purpose: a bound PDO parameter is a string and SQLite would compare it as text.
                $this->connection->prepare(
                    'UPDATE login_challenges
                     SET consumed_at = CASE WHEN attempts + 1 >= ' . self::MAX_ATTEMPTS . ' THEN :now ELSE consumed_at END, attempts = attempts + 1
                     WHERE id = :id AND consumed_at IS NULL'
                )->execute([
                    'now' => self::time($now),
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
