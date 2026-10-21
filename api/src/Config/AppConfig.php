<?php
declare(strict_types=1);

namespace BloodAndBanners\Config;

use RuntimeException;

final class AppConfig
{
    public function __construct(
        public readonly string $dbDriver,
        public readonly string $dbHost,
        public readonly string $dbName,
        public readonly string $dbUser,
        public readonly string $dbPassword,
        public readonly string $dbPath,
        public readonly string $signingKey,
        public readonly string $appEnvironment,
        public readonly string $corsOrigin,
        public readonly string $smtpHost = '',
        public readonly int $smtpPort = 465,
        public readonly string $smtpUser = '',
        public readonly string $smtpPassword = '',
        public readonly string $mailFrom = 'noreply@trickypig.com',
        public readonly string $publicUrl = 'https://blood.trickypig.com/play',
    ) {
    }

    public static function fromFile(string $path): self
    {
        if (!is_readable($path)) {
            throw new RuntimeException('Application configuration is not readable.');
        }
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException('Application configuration could not be read.');
        }

        $values = self::parse($contents);
        $driver = strtolower(trim($values['DB_DRIVER'] ?? ''));
        if (!in_array($driver, ['mysql', 'sqlite'], true)) {
            throw new RuntimeException('DB_DRIVER must be mysql or sqlite.');
        }

        $signingKey = $values['APP_SIGNING_KEY'] ?? '';
        if (strlen($signingKey) < 32) {
            throw new RuntimeException('APP_SIGNING_KEY must contain at least 32 bytes.');
        }

        $dbHost = $values['DB_HOST'] ?? '';
        $dbName = $values['DB_NAME'] ?? '';
        $dbUser = $values['DB_USER'] ?? '';
        $dbPassword = $values['DB_PASS'] ?? '';
        $dbPath = $values['DB_PATH'] ?? '';
        if ($driver === 'mysql') {
            self::required('DB_HOST', $dbHost);
            self::required('DB_NAME', $dbName);
            self::required('DB_USER', $dbUser);
            self::required('DB_PASS', $dbPassword);
        } else {
            self::required('DB_PATH', $dbPath);
        }

        $corsOrigin = trim($values['CORS_ORIGIN'] ?? '');
        if (!self::isOrigin($corsOrigin)) {
            throw new RuntimeException('CORS_ORIGIN must be an http or https origin without a path.');
        }

        return new self(
            $driver,
            trim($dbHost),
            trim($dbName),
            trim($dbUser),
            $dbPassword,
            $dbPath,
            $signingKey,
            trim($values['APP_ENV'] ?? 'production'),
            $corsOrigin,
            trim($values['SMTP_HOST'] ?? ''),
            (int)(trim($values['SMTP_PORT'] ?? '') ?: 465),
            trim($values['SMTP_USER'] ?? ''),
            $values['SMTP_PASS'] ?? '',
            trim($values['MAIL_FROM'] ?? '') ?: 'noreply@trickypig.com',
            rtrim(trim($values['APP_PUBLIC_URL'] ?? '') ?: 'https://blood.trickypig.com/play', '/'),
        );
    }

    public function pdoDsn(): string
    {
        return $this->dbDriver === 'mysql'
            ? "mysql:host={$this->dbHost};dbname={$this->dbName};charset=utf8mb4"
            : "sqlite:{$this->dbPath}";
    }

    /** @return array<string, string> */
    private static function parse(string $contents): array
    {
        $values = [];
        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            if (trim($line) === '' || str_starts_with(ltrim($line), '#')) {
                continue;
            }
            if (!str_contains($line, '=')) {
                throw new RuntimeException('Application configuration contains an invalid line.');
            }
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            if (preg_match('/^[A-Z][A-Z0-9_]*$/', $key) !== 1) {
                throw new RuntimeException('Application configuration contains an invalid key.');
            }
            if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[-1] === $value[0]) {
                $value = substr($value, 1, -1);
            }
            $values[$key] = $value;
        }
        return $values;
    }

    private static function required(string $name, string $value): void
    {
        if ($value === '') {
            throw new RuntimeException("{$name} is required.");
        }
    }

    private static function isOrigin(string $origin): bool
    {
        $parts = parse_url($origin);
        return is_array($parts)
            && in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true)
            && ($parts['host'] ?? '') !== ''
            && !isset($parts['user'], $parts['pass'], $parts['query'], $parts['fragment'])
            && !isset($parts['path']);
    }
}
