# Blood & Banners digital game

This repository now contains the first runnable vertical slice of the server-authoritative digital game:

- `api/` — dependency-free PHP 8.1+ JSON API with SQLite/MySQL migrations.
- `src/BloodAndBanners.Contracts/` — camelCase wire contracts shared by both clients.
- `src/BloodAndBanners.UI/` — responsive Razor UI shared by web and MAUI.
- `src/BloodAndBanners.Web/` — installable Blazor WebAssembly PWA.
- `src/BloodAndBanners.Maui/` — .NET 10 MAUI Blazor Hybrid host.
- `tests/fixtures/` — golden JSON fixtures used to prevent DTO drift.

## Run locally

### Browse optional image libraries

Open `images/.temp/index.html` in a browser to browse PictoIcon 64 and Fantasy
Icons on separate, compact pages. Each gallery has a sticky black/white
background toggle; Fantasy Icons are grouped by folder. Click an icon to open
its original image.

After adding or changing files in these libraries, rebuild the galleries from
the repository root with `python scripts/build-image-galleries.py`. The image
libraries and generated pages remain in the ignored `images/.temp/` folder.

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

1. Open `http://localhost:5016/` in the first browser, choose **Vanilla duel**, select a battlefield, and choose **Open war room**.
2. Copy the invite link from the war room.
3. Open that link in a different browser, private window, or browser profile. Two tabs in the same profile share the same saved seat.
4. In the second browser, choose **Join war room**. Both browsers should show two commanders within about two seconds.
5. Choose **Ready your banner** in both browsers. The host's **Begin the draft** button becomes available when both seats are ready.
6. Start the match from the host browser. Both clients show the draft table with both initiative rolls.
7. Take turns picking. On your pick, choose any open decision: who moves first, your start position, your hero, or your army. Heroes and armies can each be claimed once.
8. When the last pick lands, the starting portals open forward, backward, then forward again. The armies fade in while the portals idle forward and backward three times, then the portals close and the first player's turn begins. Reopening a match keeps its portals inactive and does not replay the entrance.
9. On your turn, click one of your units. Gold hexes are reachable, red hexes are attackable enemies. Hover or focus a destination to inspect the exact server-projected path. Safe movement sends immediately; terrain damage, forced movement, or a forced stop requires confirmation. Click an enemy to attack. Each turn allows your hero plus three other units, or any four units if your hero has fallen.
10. The battle log in the side rail shows each roll. The match ends when one side has no units left. Choose **Return to war room** to leave.
11. Refresh either browser at any point to verify that it reconnects to the same seat and state.

This slice implements Vanilla duel rules and abilities. Co-op and Battle Royale still open their current direct scenarios, but their complete mode-specific rules are not yet playable.

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

### Battlefields, terrain, and board controls

Vanilla duel offers three battlefields before creating the war room:

- **Four Corners** — 13×11, the default terrain-heavy map with open water, ice, lava, and poison regions.
- **Proving Field** — 11×9, a compact introductory duel.
- **Home Map** — 32×30, the large-format map with safe deployments near the top-left and bottom-right.

Terrain rules attach to painted terrain, not the surrounding biome. Shallow water, snow, basalt, and darkness are neutral ground.

- **Open water** ends voluntary movement on entry, costs an extra movement point to leave, counts as one level higher when climbing out, and gives −1 Power while occupied. Flying ignores it. Lava Walker instead rolls one no-Defense damage die for each open-water hex entered; shallow water has no penalty and never triggers that weakness.
- **Ice** applies Unsteady (−1 Power and no disengagement strike) and locks movement into a straight slide. Drops roll one no-Defense die per level. Enemy collisions damage both units; a friendly collision offers only the legal **Continue**, **Stop**, and targeted **Swing** exits projected by the server.
- **Poison pits** stop voluntary movement and apply Poisoned. At the end of each owning-player turn, a Poisoned unit rolls one die: swords wound without Defense, while a shield clears poison only after leaving the pits.
- **Lava** rolls five damage dice with no Defense for every entered hex and five more at the end of each owning-player turn while the unit remains there. Lava Walker ignores both rolls. Flying may cross lava safely during voluntary movement but cannot voluntarily land on it.

Use the mouse wheel or `+`/`−` to zoom, drag empty board space to pan, and choose **Fit** to show the whole battlefield. Touch supports drag and pinch around the gesture midpoint. Keyboard users can focus hexes, preview paths, activate them with Enter or Space, and operate Fit/zoom and every terrain confirmation or ice choice as ordinary buttons. A drag or pinch never leaks through as a movement or attack command.

Hero and unit artwork loads from `ImageBaseUrl` in `wwwroot/appsettings.Development.json`. Without it, tokens fall back to initials.

### Try the abilities

1. Draft **Malachar the Voidcaller** with **Sol Invictus** in one browser and **Thorgrim Shattershield** with **Amethyst Thornguard** in the other.
2. Select the Wizard: its card lists **Fireball** as a button. Choose it, hover an enemy to see the splash hexes, then click the enemy. The log reports the strike and the scorched neighbours.
3. Select Thorgrim and use **Shield Wall** on an adjacent ally; the ally's card shows `Defense 3 → 4` and its token gets a ▲.
4. Attack the Elvish Duelist (or let it attack). If one of its dice is not a hit/block, its owner sees the **Moonlight Glare** prompt; the other browser shows "Waiting for your opponent's decision…" and, after 45 seconds, a **Keep the roll for them** button.
5. Pick **Deepstone Guard** or **Sellsword Marauders** to see **Knockback** and **Stunning Blow**: after a hit the target rolls a save, and a failed save drives it back (watch for collisions and falls) or stuns it (💫 on the token; it can't move until the end of its next turn). **Thalion Windrider**'s **Gust of Wind** blows an adjacent enemy two hexes back — hover the target to preview the path. **Malachar**'s **Teleport** is two clicks: the unit, then where it goes.
6. Draft **Thalion Windrider** or **Ignar Duskfire** to exercise **Flying** and **Lava Walker** against the painted terrain rules described above.

## Seat credentials and reconnect

Game creation and joining return a `{ game, seatToken }` session envelope. Player state, event polling, commands, match start, spectator-link management, and seat reassignment require both the bearer identity and the matching `X-Seat-Token` header. The token is never included in `GameProjection` or spectator responses.

The web client persists the session in origin-scoped browser storage so a refresh can reconnect. MAUI uses the platform secure-storage provider. If the 15-minute access token expires, the client rotates its refresh token, saves the new identity session, and retries reconnecting with the unchanged seat token. Host reassignment rotates the affected seat token immediately, invalidating the previous controller.

## Deploy the Android MAUI app

Run these PowerShell commands from the repository root. Install the .NET 10 SDK and MAUI Android tooling, including the Android SDK, platform tools, and supported JDK. The Visual Studio **.NET Multi-platform App UI development** workload provides these. Add `keytool` and `adb` to PATH, or use their full paths. The app requires Android 7.0 (API 24) or newer.

### Run on a device or emulator

Enable USB debugging and accept the debugging authorization on a physical device, or start an Android emulator. Check that `adb devices` lists the target, then deploy a debug build:

```powershell
dotnet workload install maui-android
dotnet build src/BloodAndBanners.Maui/BloodAndBanners.Maui.csproj -f net10.0-android -t:Run
```

Use `-f net10.0-android` to select Android for the MAUI build. Do not pass a global `TargetFrameworks` override: it also affects the shared libraries, which target `net10.0`. Debug and Release both use the production API by default; change the URLs described below before testing against staging.

### Build a standalone test APK

For an APK you can copy to a phone without a debugger or release keystore, build Debug for Android:

```powershell
dotnet build src/BloodAndBanners.Maui/BloodAndBanners.Maui.csproj -t:Rebuild -f net10.0-android -c Debug
```

Install `src/BloodAndBanners.Maui/bin/Debug/net10.0-android/com.trickypig.bloodandbanners.game-Signed.apk`. The SDK signs this with its debug key automatically. The project file always embeds the .NET assemblies (`EmbedAssembliesIntoApk=true`, fast deployment off) for Android builds, because a fast-deployment APK omits them and closes immediately when installed by hand. This makes `-t:Run` deploys slower but means any APK in `bin/` is standalone.

If startup fails, reproduce the crash, then capture the Android crash buffer:

```powershell
adb logcat -b crash -d > android-crash.txt
```

For managed breakpoints, select the connected phone as the Android debug target in Visual Studio and press F5.


### Publish a signed release

The package ID is `com.trickypig.bloodandbanners.game`. Before each update, increase `ApplicationVersion` (currently `1`) in `BloodAndBanners.Maui.csproj` and set the user-visible `ApplicationDisplayVersion` (currently `1.0`). Keep the package ID and signing identity consistent for updates.

Create a release keystore once, outside the repository. Replace these example paths with a private directory that already exists:

```powershell
keytool -genkeypair -v -keystore C:\AndroidSigning\blood-and-banners.keystore -alias blood-and-banners -keyalg RSA -keysize 2048 -validity 10000
```

Back up the keystore and password securely. Save the password in `C:\AndroidSigning\password.txt`, restricting access to your account. Use the same key and store password for this example. Keep these files out of source control.

Publish a signed APK for direct installation:

```powershell
dotnet publish src/BloodAndBanners.Maui/BloodAndBanners.Maui.csproj `
  -f net10.0-android -c Release `
  -p:AndroidPackageFormats=apk -p:AndroidKeyStore=true `
  -p:AndroidSigningKeyStore=C:\AndroidSigning\blood-and-banners.keystore `
  -p:AndroidSigningKeyAlias=blood-and-banners `
  -p:AndroidSigningKeyPass=file:C:\AndroidSigning\password.txt `
  -p:AndroidSigningStorePass=file:C:\AndroidSigning\password.txt
```

Find the signed APK in `src/BloodAndBanners.Maui/bin/Release/net10.0-android/publish/`. With one device connected, install it using its actual filename:

```powershell
adb install -r "src/BloodAndBanners.Maui/bin/Release/net10.0-android/publish/com.trickypig.bloodandbanners.game-Signed.apk"
```

A previously installed debug build uses a different signing key and cannot be upgraded with this release APK. Uninstall that debug build first if necessary; uninstalling removes its saved app data. Alternatively, transfer the signed APK to the device and allow installation from the app used to open it.

For Google Play, rerun the publish command with `-p:AndroidPackageFormats=aab`. Upload the signed AAB from the same publish directory to a Play Console internal testing track, configure Play App Signing, and test before promoting the release. An AAB cannot be installed with `adb install`. See [Microsoft's Android publishing guide](https://learn.microsoft.com/en-us/dotnet/maui/android/deployment/publish-cli?view=net-maui-10.0) for signing and distribution details.

After installation, load each battlefield, create or join a war room, check hero/unit artwork, and verify reconnect after restarting the app. Confirm a copied invite opens `https://blood.trickypig.com/play/?join=...`.

Invite links open in the Android app through verified App Links. `.well-known/assetlinks.json` lists the SHA-256 fingerprint of the key that signs the APK (currently the SDK debug key); add the release key's fingerprint there before shipping a release-signed build, then publish it with `scripts/deploy.ps1`. Read a fingerprint with `keytool -printcert -jarfile <apk>`.

The `quality-and-android` workflow in `codemagic.yaml` builds Release Android APK artifacts. It does not configure a private release keystore or upload to Google Play; use the signed publish procedure above for distribution.


## Build the iOS app with Codemagic

The `ios-testflight` workflow in `codemagic.yaml` follows Goldulf’s Goons: it installs .NET 10 and MAUI on a Mac, runs the shared contract/UI tests, signs an `ios-arm64` Release IPA, and uploads it to App Store Connect for internal TestFlight testing. The iOS bundle ID is `com.trickypig.bloodandbanners`; Android keeps `com.trickypig.bloodandbanners.game`.

Before the first build:

1. Register the explicit bundle ID `com.trickypig.bloodandbanners` in Apple Developer and create its app record in App Store Connect. The workflow reuses existing signing files; it does not create certificates, provisioning profiles, or the App Store Connect app record.
2. Add this repository to the same Codemagic team as Goldulf’s Goons. Give the app access to the shared **House Rules** App Store Connect integration for uploading. Under **Team settings → codemagic.yaml settings → Code signing identities**, reuse the existing Apple Distribution certificate (including its private key) and upload or fetch an **App Store** provisioning profile for `com.trickypig.bloodandbanners` linked to that certificate. If the certificate was not generated by Codemagic, upload its existing `.p12` with its password. If no profile exists yet, create one in Apple Developer using that existing certificate, then add it to Codemagic. See [Codemagic’s signing identities setup](https://docs.codemagic.io/yaml-code-signing/signing-ios/). The workflow selects stored files by distribution type and bundle ID; it no longer uses `CERTIFICATE_PRIVATE_KEY` or the `ios_signing` variable group. Keep signing keys out of the repository.
3. Select **Blood & Banners iOS TestFlight** and start a build. Pushes to `main` also trigger it once the Codemagic webhook is connected.

The workflow queries this app’s latest TestFlight build number and increments it; failed lookups stop the build rather than reuse a number. Avoid overlapping release builds for this app. `ApplicationDisplayVersion` remains the version set in the MAUI project. The workflow uses Xcode `27.0`, as required by the installed .NET iOS `27.0.10722` workload. The SDK and workload installation currently follow the .NET 10 channel, so review Xcode compatibility when those tools update. See [Codemagic’s Xcode 27.0 image](https://docs.codemagic.io/specs-macos/xcode-27-0/).

The iOS `Info.plist` declares `ITSAppUsesNonExemptEncryption=false`, so each build includes the export-compliance answer for the app’s current use of platform HTTPS and secure storage. Reassess this declaration if the app or a dependency introduces non-exempt encryption. See [Apple’s encryption declaration guidance](https://developer.apple.com/documentation/bundleresources/information-property-list/itsappusesnonexemptencryption).

Download the signed `.ipa` from Codemagic’s build artifacts. After Apple processes the upload, configure internal testers in App Store Connect. External beta review and public App Store release are not enabled by this workflow. The existing `ios-check` workflow remains a simulator compile check. A signed archive and upload require a real Codemagic Mac build; local YAML validation cannot verify Apple credentials or provisioning.

## Verify

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File tests/local-api-launch.ps1
php api/tests/run.php
dotnet test tests/BloodAndBanners.Contracts.Tests/BloodAndBanners.Contracts.Tests.csproj
dotnet test tests/BloodAndBanners.UI.Tests/BloodAndBanners.UI.Tests.csproj
dotnet build src/BloodAndBanners.Web/BloodAndBanners.Web.csproj
dotnet build src/BloodAndBanners.Maui/BloodAndBanners.Maui.csproj -f net10.0-windows10.0.19041.0
powershell -NoProfile -ExecutionPolicy Bypass -File tests/game-release-build.ps1
powershell -NoProfile -ExecutionPolicy Bypass -File tests/deployment-package.ps1
powershell -NoProfile -ExecutionPolicy Bypass -File tests/deploy-game-script.ps1
```

## Map editor

Open `/editor` locally or <https://blood.trickypig.com/play/editor> in production. The old static editor URL redirects there. Anyone can create and edit maps, import legacy offset or axial JSON, and export files without signing in. Signed-in users can also save, load, and save copies of their own private maps. A stale save offers reload or Save as instead of overwriting another tab's edits.

Saved maps use immutable scenario revisions. Editing access is owner-only; custom-game creation and public/shared map browsing are future features. Scenario JSON exports preserve objectives, placements, scoring, and extra metadata when the legacy editor format cannot represent them. The Blazor editor and game share terrain art, geometry, and elevation shading.

Publishing this change requires the normal game release (UI plus API); publishing the static redirect alone does not install the new editor. No database migration is needed.

## Production configuration

The public game is deployed at <https://blood.trickypig.com/play/> without replacing the existing static pages. The release uses two allowlisted roots beneath the account directory:

```text
trickypig/blood/
|-- .env                         # private, mode 600
|-- public/play/                 # PWA plus the sole public PHP gateway
|-- server/api/                  # private PHP source, migrations, and scenarios
`-- .deploy/                     # staged and previous releases
```

The production `.env` uses these key names: `DB_DRIVER`, `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`, `APP_SIGNING_KEY`, `APP_ENV`, and `CORS_ORIGIN`. `DB_PATH` is used only for SQLite configurations. Do not put values in the repository or the public tree. `APP_SIGNING_KEY` must be a random secret of at least 32 bytes. Rotating it immediately invalidates outstanding access tokens; refresh tokens and seat credentials are independent, so clients with a valid refresh token can obtain a newly signed access token.

The game publisher is dry-run-first:

```powershell
.\scripts\deploy-game.ps1         # local checks/build plus read-only SSH and rsync dry run
.\scripts\deploy-game.ps1 -Push   # staged migration, atomic activation, and HTTPS checks
```

Review the dry run before changing anything remotely. Before `-Push`, set only `trickypig/blood/.env` to mode `600` and verify it with `stat -c %a`. Activation syntax-checks every staged PHP file and runs migrations explicitly with `php8.4-cli server/api/tools/migrate.php --env-file .env`. It retains the prior public and private trees together at `.deploy/previous-<release-id>`; a failed HTTPS check invokes rollback automatically. Never run `-Push` without explicit production authorization.

The older static-site publisher remains separate: `scripts/deploy.ps1` publishes the root HTML, `data/`, and `images/` at <https://trickypig.com/blood/public/>. Its rsync rules protect `/play/`, including prune runs.

The MAUI host uses `https://blood.trickypig.com/play/` as its HTTP base address in `src/BloodAndBanners.Maui/MauiProgram.cs`. The shared client appends `api/v1/...`, producing `https://blood.trickypig.com/play/api/v1/...`. Keep the trailing slash and do not append `/api/` to the base address. `Components/Pages/Home.razor` uses `https://blood.trickypig.com/play/` for public invite links and `https://blood.trickypig.com/` for artwork. For staging, update these URLs together to match that deployment. Never embed database or signing credentials in either client.
