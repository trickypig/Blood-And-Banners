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
.\scripts\start-api.ps1
```

The launcher resolves the API paths from its own location, so an absolute path to the script also works from another directory. To use a different port, pass `-Port 8081`.

In another terminal, start the PWA:

```powershell
dotnet run --project src/BloodAndBanners.Web/BloodAndBanners.Web.csproj
```

The development PWA reads `wwwroot/appsettings.Development.json` and calls the API at `http://localhost:8080/`. Production web hosting can omit `ApiBaseUrl` when the static client and `/api` share an origin.

### Play a two-browser Vanilla duel

1. Open `http://localhost:5016/` in the first browser and choose **Vanilla duel**.
2. Copy the invite link from the war room.
3. Open that link in a different browser, private window, or browser profile. Two tabs in the same profile share the same saved seat.
4. In the second browser, choose **Join war room**. Both browsers should show two commanders within about two seconds.
5. Choose **Ready your banner** in both browsers. The host's **Begin the draft** button becomes available when both seats are ready.
6. Start the match from the host browser. Both clients show the draft table with both initiative rolls.
7. Take turns picking. On your pick, choose any open decision: who moves first, your start position, your hero, or your army. Heroes and armies can each be claimed once.
8. When the last pick lands, both armies are placed beside their portals on Proving Field and the first player's turn begins.
9. On your turn, click one of your units. Gold hexes are reachable, red hexes are attackable enemies. Click a hex to move, then click an enemy to attack. Each turn allows your hero plus three other units, or any four units if your hero has fallen.
10. The battle log in the side rail shows each roll. The match ends when one side has no units left. Choose **Return to war room** to leave.
11. Refresh either browser at any point to verify that it reconnects to the same seat and state.

This slice implements the core Vanilla rules only. Abilities, water, ice, poison, stun, flying, terrain objects, and the Co-op and Battle Royale modes are not yet playable.

Hero and unit artwork loads from `ImageBaseUrl` in `wwwroot/appsettings.Development.json`. Without it, tokens fall back to initials.

### Try the abilities

1. Draft **Malachar the Voidcaller** with **Sol Invictus** in one browser and **Thorgrim Shattershield** with **Amethyst Thornguard** in the other.
2. Select the Wizard: its card lists **Fireball** as a button. Choose it, hover an enemy to see the splash hexes, then click the enemy. The log reports the strike and the scorched neighbours.
3. Select Thorgrim and use **Shield Wall** on an adjacent ally; the ally's card shows `Defense 3 → 4` and its token gets a ▲.
4. Attack the Elvish Duelist (or let it attack). If one of its dice is not a hit/block, its owner sees the **Moonlight Glare** prompt; the other browser shows "Waiting for your opponent's decision…" and, after 45 seconds, a **Keep the roll for them** button.
5. Pick **Deepstone Guard** or **Sellsword Marauders** to see **Knockback** and **Stunning Blow**: after a hit the target rolls a save, and a failed save drives it back (watch for collisions and falls) or stuns it (💫 on the token; it can't move until the end of its next turn). **Thalion Windrider**'s **Gust of Wind** blows an adjacent enemy two hexes back — hover the target to preview the path. **Malachar**'s **Teleport** is two clicks: the unit, then where it goes.
6. Only **Flying** and **Lava Walker** still show "Not yet in the digital game".

## Seat credentials and reconnect

Game creation and joining return a `{ game, seatToken }` session envelope. Player state, event polling, commands, match start, spectator-link management, and seat reassignment require both the bearer identity and the matching `X-Seat-Token` header. The token is never included in `GameProjection` or spectator responses.

The web client persists the session in origin-scoped browser storage so a refresh can reconnect. MAUI uses the platform secure-storage provider. If the 15-minute access token expires, the client rotates its refresh token, saves the new identity session, and retries reconnecting with the unchanged seat token. Host reassignment rotates the affected seat token immediately, invalidating the previous controller.

## Verify

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File tests/local-api-launch.ps1
php api/tests/run.php
dotnet test tests/BloodAndBanners.Contracts.Tests/BloodAndBanners.Contracts.Tests.csproj
dotnet test tests/BloodAndBanners.UI.Tests/BloodAndBanners.UI.Tests.csproj
dotnet build src/BloodAndBanners.Web/BloodAndBanners.Web.csproj
dotnet build src/BloodAndBanners.Maui/BloodAndBanners.Maui.csproj -f net10.0-windows10.0.19041.0
```

## Production configuration

Point Apache's `/api` alias or document root at `api/public`, enable `mod_rewrite` and `mod_headers`, and supply the four environment values shown in `api/.env.example`. The signing key must be a random secret of at least 32 bytes. MySQL uses `api/migrations/001_initial.mysql.sql`; local SQLite uses the matching SQLite migration automatically.

The MAUI host currently targets `https://api.bloodandbanners.com/` in `MauiProgram.cs`; change that URI for staging or alternate production hosting. Never embed database or signing credentials in either client.
