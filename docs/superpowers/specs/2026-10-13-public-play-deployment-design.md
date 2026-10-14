# Public `/play/` deployment — design

Date: 2026-10-13
Status: approved in conversation

## Goal

Publish the server-authoritative Blood & Banners web game at
`https://blood.trickypig.com/play/` while preserving every existing static
page and asset at its current URL. The game is publicly accessible from its
first release and uses the IONOS MySQL database being provisioned for it.

Success means the Blazor PWA, PHP API, database migrations, secrets, routing,
deployment, rollback, and production verification form one repeatable release
process without exposing private server files or disturbing the legacy site.

## Constraints and established environment

- `blood.trickypig.com` already resolves to the IONOS host and has working
  HTTPS.
- The account-relative web root is `trickypig/blood/public/`.
- The existing root pages, `data/`, and `images/` remain in place.
- The existing static-site publisher remains responsible only for those
  legacy assets. Its prune mode must protect `play/` from deletion.
- IONOS provides PHP 8.4 with PDO, `pdo_mysql`, OpenSSL, Sodium, JSON, and the
  other extensions used by the API. Production commands must name
  `php8.4-cli` explicitly because the unversioned `php` command is PHP 4.4.
- The solution stays dependency-free on the PHP side.
- The MAUI clients and their production endpoint are outside this deployment's
  scope.

## Server layout and trust boundary

Only the compiled PWA and a minimal PHP gateway are web-accessible. PHP source,
scenario definitions, migrations, and configuration remain outside the web
root.

```text
blood/
├── .env                         private configuration, mode 600
├── server/
│   └── api/                     PHP source, scenarios, migrations, tools
└── public/
    ├── index.html               existing static site
    ├── ...                      existing pages, data, and images
    └── play/
        ├── index.html           published Blazor PWA
        ├── _framework/ ...      published client assets
        ├── .htaccess            PWA fallback, API routing, hardening
        └── api/
            └── index.php        only public PHP entry point
```

The game deployment owns only `blood/server/api/` and
`blood/public/play/`. It must not write any other public path. The legacy
deployment must use an rsync protection rule for `play/`, including when
`-Prune` is requested.

## Client hosting under `/play/`

The production client uses `/play/` consistently as its base path:

- the document `<base>` is `/play/`;
- the web app manifest uses a `/play/` start URL and scope;
- the service worker registers within `/play/` and resolves its asset manifest
  and offline cache entries from that prefix;
- framework, stylesheet, icon, and navigation URLs remain underneath
  `/play/`;
- the default Blazor host base address is the API base address, so existing
  relative `api/v1/...` client calls resolve to `/play/api/v1/...`;
- game artwork continues to use the existing `/images/` tree at the origin
  root.

Apache serves existing files directly. Requests beneath `/play/` that do not
name a real file or directory fall back to `/play/index.html`, allowing invite
links, client routes, and browser refreshes to work. API paths take precedence
over the PWA fallback.

## API gateway and request flow

Production traffic follows this path:

```text
browser
  -> https://blood.trickypig.com/play/
  -> /play/api/v1/...
  -> Apache rewrite
  -> public/play/api/index.php
  -> private server/api code
  -> IONOS MySQL
```

The gateway loads the private bootstrap and normalizes the public deployment
prefix before constructing the existing request object. For example,
`/play/api/v1/games` is presented to the application as `/api/v1/games`.
This preserves the existing API contract and tests.

The browser client and API share an origin. No cross-origin workaround is
needed for bearer tokens, seat tokens, polling, or invite links. If an Origin
header is handled, it is allowed only when it exactly equals the configured
`CORS_ORIGIN`; incoming origins are never reflected blindly.

## Configuration

`blood/.env` is a persistent, manually managed server file. Deployment never
uploads, overwrites, downloads, or prints it. Before launch its mode is changed
from the observed `404` to `600` so only the account owner can read and write
it.

The production bootstrap reads and validates these values:

- `DB_DRIVER=mysql`
- `DB_HOST`
- `DB_NAME`
- `DB_USER`
- `DB_PASS`
- `APP_SIGNING_KEY`, containing at least 32 bytes of random secret material
- `APP_ENV=production`
- `CORS_ORIGIN`, containing the exact allowed HTTPS origin

`DB_PATH` remains available for local SQLite configuration and is ignored when
`DB_DRIVER` is `mysql`. The loader constructs a PDO MySQL DSN with `utf8mb4`,
enables exception mode, and never includes credential values in errors or
logs. A missing, unreadable, malformed, or incomplete configuration fails
closed.

`APP_SIGNING_KEY` remains stable across deployments. Rotating it is a separate
operational action because rotation immediately invalidates issued access
tokens.

## Migrations

Schema migration is a deployment step, not a web-request side effect. A
dedicated CLI entry point loads the same private configuration and runs with
`php8.4-cli` before new code becomes live. The web gateway does not invoke the
migrator.

The initial MySQL migrations are additive and repeatable. If migration fails,
deployment stops before switching the live release. Future destructive or
irreversible migrations require a separate database backup and rollback plan
before they can be included in a release.

## Deployment workflow

`scripts/deploy-game.ps1` follows the repository's established safety model:
it is a dry run unless `-Push` is explicitly supplied. It performs these
stages:

1. Run the PHP tests, .NET tests, and Blazor release build locally.
2. Assemble an allowlisted staging tree containing only the published PWA,
   public gateway and Apache rules, private API source, scenarios, migrations,
   and required production tools.
3. Assert that no `.env`, local SQLite database, test fixture, development
   setting, source map, or unrelated repository file entered the package.
4. Show the file-level remote changes during a dry run.
5. Upload a pushed release into a temporary server directory rather than the
   live paths.
6. Verify all PHP files with `php8.4-cli -l`, required configuration key
   presence without values, and `.env` mode `600`.
7. Run the MySQL migration command from the staged release.
8. Move the staged backend and PWA into their live locations, preserving the
   previous release for code rollback.
9. Run post-deployment HTTP checks. If the switch or HTTP checks fail, restore
   the previous code release and report that database migrations may already
   have been applied.

The switch may create a very brief maintenance interval; zero-downtime release
coordination is not required for the first launch. Because initial migrations
are additive, the previous code remains compatible with the migrated schema.

## Failure handling and security

The public gateway catches configuration, connection, request-decoding, and
unexpected bootstrap failures. It returns a structured generic JSON error and
an appropriate HTTP status without PHP diagnostics, paths, SQL, or secrets.
Detailed failures go only to the server error log. Existing domain and game
errors retain their current structured API responses.

The `/play/` Apache configuration:

- disables directory listing;
- routes API requests before the PWA fallback;
- applies a conservative request-body size limit;
- prevents PHP diagnostic display;
- gives `index.html`, the manifest, and service-worker files revalidation or
  no-cache behavior;
- permits long-lived caching for fingerprinted framework and static assets;
- adds suitable content-type, framing, referrer, and permissions headers.

The public package contains no private PHP source or configuration. The API
continues using parameterized PDO queries, password hashing, HMAC-signed access
tokens, opaque refresh tokens, and hashed seat and spectator tokens.

Initial abuse protection relies on IONOS platform limits. Application-level
rate limiting, account email verification, password recovery, and automated
data-retention jobs are outside this deployment scope.

## Verification

Automated verification covers:

- all existing PHP and .NET tests;
- `.env` parsing, required-value validation, MySQL DSN construction, and
  redaction of secrets;
- normalization of `/play/api/v1/...` to `/api/v1/...`;
- the release build's base URL, manifest scope, and service-worker base;
- the allowlisted public/private staging layout and exclusion of secret or
  development files;
- PHP 8.4 linting of every deployed PHP file;
- dry-run behavior, legacy-path protection, failed validation, and code
  rollback in the deployment script.

Post-deployment checks verify:

- representative legacy static pages still return successfully;
- `/play/` and its framework assets load from the correct prefix;
- a deep client URL and browser refresh return the PWA;
- `/play/api/v1/compatibility` returns the expected JSON response;
- service-worker replacement does not strand a client between releases.

The launch smoke test uses two independent browser sessions to create and join
a game, begin play, submit actions, refresh, and reconnect. This final test is
manual because it exercises real browser storage, service-worker state, and
multiplayer timing without adding disposable production accounts to an
automated probe.

## Acceptance criteria

- Existing static URLs and content remain available after deployment.
- The game is publicly playable at `https://blood.trickypig.com/play/`.
- Direct navigation and refresh work anywhere within the PWA.
- The client communicates with the production API at `/play/api/v1/...`.
- The API uses the configured IONOS MySQL database and stable signing key.
- No secret or private server source is web-accessible or included in the
  public staging tree.
- Deployment is dry-run by default, validates before switching, retains a code
  rollback target, and cannot prune `/play/` through the legacy publisher.
- A two-browser multiplayer match can start, progress, refresh, and reconnect
  successfully in production.
