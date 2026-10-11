# Blood & Banners digital game

This repository now contains the first runnable vertical slice of the server-authoritative digital game:

- `api/` — dependency-free PHP 8.1+ JSON API with SQLite/MySQL migrations.
- `src/BloodAndBanners.Contracts/` — camelCase wire contracts shared by both clients.
- `src/BloodAndBanners.UI/` — responsive Razor UI shared by web and MAUI.
- `src/BloodAndBanners.Web/` — installable Blazor WebAssembly PWA.
- `src/BloodAndBanners.Maui/` — .NET 10 MAUI Blazor Hybrid host.
- `tests/fixtures/` — golden JSON fixtures used to prevent DTO drift.

## Run locally

Start the PHP API with a development SQLite database:

```powershell
$env:BLOOD_BANNERS_SIGNING_KEY = 'local-development-signing-key-32-bytes'
php -S localhost:8080 api/router.php
```

In another terminal, start the PWA:

```powershell
dotnet run --project src/BloodAndBanners.Web/BloodAndBanners.Web.csproj
```

The development PWA reads `wwwroot/appsettings.Development.json` and calls the API at `http://localhost:8080/`. Production web hosting can omit `ApiBaseUrl` when the static client and `/api` share an origin.

## Verify

```powershell
php api/tests/run.php
dotnet test tests/BloodAndBanners.Contracts.Tests/BloodAndBanners.Contracts.Tests.csproj
dotnet test tests/BloodAndBanners.UI.Tests/BloodAndBanners.UI.Tests.csproj
dotnet build src/BloodAndBanners.Web/BloodAndBanners.Web.csproj
dotnet build src/BloodAndBanners.Maui/BloodAndBanners.Maui.csproj -f net10.0-windows10.0.19041.0
```

## Production configuration

Point Apache's `/api` alias or document root at `api/public`, enable `mod_rewrite` and `mod_headers`, and supply the four environment values shown in `api/.env.example`. The signing key must be a random secret of at least 32 bytes. MySQL uses `api/migrations/001_initial.mysql.sql`; local SQLite uses the matching SQLite migration automatically.

The MAUI host currently targets `https://api.bloodandbanners.com/` in `MauiProgram.cs`; change that URI for staging or alternate production hosting. Never embed database or signing credentials in either client.
