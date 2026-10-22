# Private Saved Maps Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the static editor with a Blazor editor that works anonymously and lets signed-in owners save and load private database maps.

**Architecture:** Reuse scenario/revision storage and account sessions. Keep map editing and file conversion in a local document model, put authenticated map operations behind a dedicated API client, and share terrain rendering with the game without moving game interaction into the editor.

**Tech Stack:** .NET 10, Blazor WebAssembly/Razor class library, SVG, minimal browser JavaScript, PHP/PDO, SQLite/MySQL, xUnit/bUnit, existing PHP test runner.

**Spec:** `docs/superpowers/specs/2026-10-21-map-editor-design.md`

## Global Constraints

- Anyone can use the editor and import/export local files without signing in.
- Only a map's owner can browse, load, or save it through the editor.
- Store a canonical `ScenarioDefinition` and private visibility.
- Games already reference immutable scenario revisions.
- Do not require a finished playable map to save: an unfinished map may have no player starts.
- Keep map contents in local component state during sign-in, sign-out, network errors, and expired sessions.
- Do not silently discard placements, objectives, scoring, or compatibility settings.
- Preserve current editor tools and ranges; future custom-game creation, sharing, publication, deletion, and autosave are deferred.
- Do not publish as part of implementation.
- Existing `(scenario_id, revision)` uniqueness is present in both initial schemas; no new table is needed.
- Git writes were denied in this workspace. Implementation must preserve working-tree changes; commits are contingent on available permissions.

## Review Focus

1. Account changes while a save/load is in flight must not attach another owner's saved-map identity to the current document (Task 5).
2. Two editor tabs saving the same revision must produce one accepted update and one conflict, never a silent overwrite (Task 1).
3. A canonical import followed by a terrain edit/export must retain scenario-only metadata and untouched tile properties (Task 3).
4. Moving SVG markup to a child component must preserve CSS isolation, raised-tile click targets, and game highlight layering (Task 4).
5. `/play/editor` deep links and the old static URL must reach the editor with the app's image/API paths and sign-in context (Task 6).

## Task 1: Owner-scoped map persistence and HTTP endpoints

**Files:**
- Modify `api/src/Scenario/ScenarioService.php` for owner reads and concurrency-safe revision writes.
- Create `api/src/Scenario/MapValidator.php` and `api/src/Scenario/MapException.php` for editor validation and typed HTTP failures.
- Modify `api/src/Http/Application.php`, `api/public/index.php`, and `scripts/deployment/play/api/index.php` to inject the scenario service and expose editor routes.
- Create `api/tests/MapEditorTest.php`; update `api/tests/AccountTestSupport.php` and `api/tests/run.php`.

**Interfaces:**
- `ScenarioService::listOwned(string $ownerUserId): array` returns `{maps: [summary...]}`.
- `ScenarioService::loadOwned(string $scenarioId, string $ownerUserId): array` returns a saved-map document.
- `ScenarioService::saveOwned(string $scenarioId, string $ownerUserId, string $expectedRevisionId, array $definition): array` returns the new saved-map document. Keep internal existing callers of `addRevision` working; they must share the serialization lock where needed.
- HTTP: `GET/POST /api/v1/account/maps`, `GET/PUT /api/v1/account/maps/{id}`. POST body `{definition}`; PUT body `{definition, expectedRevisionId}`.
- Summary `{scenarioId, name, revisionId, revision, updatedAt}`; saved-map document `{summary, definition}`; list wrapper `{maps}`. Use UTC ISO 8601 `updatedAt` derived from the latest revision timestamp, sorted descending then ID.
- Error codes: `accountRequired` (403), existing unauthorized response (401), `mapNotFound` (404), `mapConflict` (409), `invalidMap` (400), `mapTooLarge` (413).

- [ ] Add failing route tests using two `sign_in` accounts: creation returns revision 1; only owner lists/loads/updates; other owner and nonexistent IDs have identical 404 responses; guests and missing bearer tokens cannot use any route. Duplicate names create separate IDs; no starts is valid. Read back the first revision after an update to assert immutability.
- [ ] Add failing validation cases for numeric strings, duplicate coordinates, invalid biome/terrain, invalid starts, missing definition, blank names, oversized data, and invalid modes. Proposed defensive bounds: name 1–120 characters, map JSON at most 2 MiB, dimensions 1–100, 1–10,000 hexes, integer axial coordinates within ±10,000, integer elevation within ±1,000, player starts null or 1–4. Existing UI generation remains 5–30 by 5–30 and elevation editing 0–10. Permit sparse/imported boards and repeated start markers, matching existing editor behavior.
- [ ] Run `php api/tests/run.php`; confirm the new tests fail because routes/services are missing.
- [ ] Implement validation before writes. Use the actual terrain catalog from `TerrainArt` and current editor normalization as compatibility references. Preserve all valid scenario metadata; reject malformed known fields instead of coercing them. Apply strict editor validation at the new public entry points without breaking internal built-in scenario creation.
- [ ] Add routes after principal extraction, always using `requireUser`, never a body-supplied owner. Extend existing typed error handling. Wire the service in local and release entry points and test contexts.
- [ ] Serialize writes on the parent scenario: MySQL `SELECT ... FOR UPDATE` with a current/locking latest-revision read; SQLite acquire the write lock before the ownership/latest-revision read. Compare `expectedRevisionId` within the transaction, append revision, update name, and commit. Handle busy/constraint races with a bounded retry followed by a typed conflict; never expose raw SQL errors. POST validates then reuses creation behavior.
- [ ] Add a file-backed SQLite two-connection test exercising two writes from one expected revision; assert exactly one new revision and a conflict for the loser. Run the full PHP suite and lint changed PHP files. MySQL locking syntax must be checked against the deployed driver/version when available; report any verification limit.

## Task 2: Saved-map contracts and authenticated client

**Files:**
- Create `src/BloodAndBanners.Contracts/MapContracts.cs`.
- Create `src/BloodAndBanners.UI/Services/IMapApiClient.cs` and `MapApiClient.cs`.
- Modify web `Program.cs` and MAUI `MauiProgram.cs` for DI registration.
- Create `tests/BloodAndBanners.UI.Tests/MapApiClientTests.cs`, `tests/BloodAndBanners.Contracts.Tests/MapContractTests.cs`, and `tests/fixtures/saved-map.json`.

**Interfaces:**
- Records: `SavedMapSummary(string ScenarioId, string Name, string RevisionId, int Revision, string UpdatedAt)`, `SavedMap(SavedMapSummary Summary, ScenarioDefinition Definition)`, `SavedMaps(IReadOnlyList<SavedMapSummary> Maps)`.
- `IMapApiClient.ListAsync(AuthSession, CancellationToken = default): Task<SavedMaps>`.
- `LoadAsync(AuthSession, string scenarioId, CancellationToken = default): Task<SavedMap>`.
- `CreateAsync(AuthSession, ScenarioDefinition definition, CancellationToken = default): Task<SavedMap>`.
- `SaveAsync(AuthSession, string scenarioId, string expectedRevisionId, ScenarioDefinition definition, CancellationToken = default): Task<SavedMap>`.
- UI callers use `IAccountSession.CallAsync` for refresh/retry; map client uses `GameApiClient.ReadAsync<T>` and `ContractJson.Options` like `AccountApiClient`.

- [ ] Add failing fixture round-trip and fake-HTTP-handler tests asserting endpoint/method, bearer header, POST/PUT shapes, escaped IDs, and preservation of `mapConflict` errors.
- [ ] Run `dotnet test tests/BloodAndBanners.UI.Tests --filter FullyQualifiedName~MapApiClientTests` and the contract test project; confirm missing contracts/client failures.
- [ ] Implement contracts, client, and both DI registrations; rerun both test sets and require zero failures.

## Task 3: Local editor model and compatible file conversion

**Files:**
- Create `src/BloodAndBanners.UI/Services/MapEditorDocument.cs` and `MapFileCodec.cs`.
- Add catalog accessors to `src/BloodAndBanners.UI/Services/TerrainArt.cs` for biome/terrain tools rather than duplicating lists in the editor.
- Create `tests/BloodAndBanners.UI.Tests/MapEditorDocumentTests.cs` and `MapFileCodecTests.cs`.

**Interfaces:**
- `MapEditorDocument` owns canonical definition, dirty state, selected coordinates, and an optional saved-map association including owner ID and expected revision. Expose `Definition`, `IsDirty`, `SelectedHex`, `SavedSummary`, `SavedOwnerId`, and `HasScenarioMetadata`.
- Methods: `New(string name, int columns, int rows)`, `Import(string json)`, `Load(SavedMap map, string ownerId)`, `Rename(string name)`, `Paint(int q, int r, string biome, string terrain)`, `AdjustHeight(int q, int r, int delta)`, `ResetHeight(int q, int r)`, `SetPlayerStart(int q, int r, int? player)`, `Clear()`, `DetachSavedMap()`, `MarkSaved(SavedMap map, string ownerId)`.
- `MapFileCodec.Import(string json): ScenarioDefinition`; `ExportLegacy(ScenarioDefinition): string`; `ExportCanonical(ScenarioDefinition): string`. Reject legacy export when it would lose scenario metadata; the UI offers canonical export instead.
- Coordinate conversion: offset `(column,row)` becomes `(q: row - floor(column/2), r: column)`. Legacy `height` becomes canonical `elevation`. Generate canonical fields/default scoring matching `LegacyMapImporter`. Preserve tile portal/spawn/piece/object/fountain/sightBlocker fields. Preserve unknown JSON properties through extension-data DTOs or an explicit raw-data merge; document which approach is chosen before coding it.

- [ ] Write failing tests for new-map dimensions/default terrain; paint/height/start tools; no-op edits staying clean; dirty rename; clear preserving dimensions; import detaching identity; failed import leaving the old document intact.
- [ ] Add offset and version-3 fixtures asserting `(-3,7)` axial coordinates stay unchanged, `height` survives round-trip, and stale offset fields are omitted. Add canonical fixture with placements/objectives/scoring/custom tile fields; edit one hex, export/import, assert untouched metadata remains equal. Reject malformed coordinate pairs and unsupported schemas.
- [ ] Run the two filtered test classes and confirm failures; implement model and codec with bounded file parsing and deterministic validation. Rerun requiring zero failures.

## Task 4: Shared SVG terrain drawing

**Files:**
- Create `src/BloodAndBanners.UI/Components/HexTerrain.razor` and its scoped CSS, and `TerrainPatterns.razor` for image definitions.
- Modify `src/BloodAndBanners.UI/Components/GameTable.razor` and `.razor.css`.
- Add shared shade/pattern helpers to `TerrainArt.cs` as needed.
- Create `tests/BloodAndBanners.UI.Tests/HexTerrainTests.cs`; extend existing game rendering tests.

**Interfaces:**
- `HexTerrain` parameters `HexTile Tile`, `string PatternId`; renders sides, side edges, textured top, and elevation shade only. Parent retains outline, elevation label, units, and all interaction handlers.
- `TerrainPatterns` parameters `IReadOnlyList<HexProjection> Hexes`, `string? ImageBaseUrl`, `string IdPrefix`; uses deterministic IDs and distinct biome/terrain/variant combinations.
- Shared `TerrainArt.ElevationShade(int elevation): double`: positive `min(8,elevation)*.035`; nonpositive `min(.6,-(double)elevation*.08)`. Keep game's existing IDs or update every reference consistently.

- [ ] Test shade values for -10, -1, 0, 1, 4, 8, 10; assert side paths appear only for raised tiles, correct texture variants/fallbacks, and 0.28 opacity at +8. Add a game test that checks reachable/selected outlines still render after the terrain child.
- [ ] Run filtered tests to observe failures; extract only terrain markup/helpers. Move styles to the owning component or use deliberate `::deep` selectors so scoped CSS applies. Keep parent `<g>` handlers and outline selectors intact.
- [ ] Run `dotnet test tests/BloodAndBanners.UI.Tests`; require no new failures. Visually check raised tiles and target highlights during Task 6.

## Task 5: Blazor editor and account map controls

**Files:**
- Create `src/BloodAndBanners.UI/Components/MapEditor.razor`, `MapEditor.razor.cs`, `.razor.css`, and `SavedMapsPanel.razor` (with its CSS if needed).
- Create `src/BloodAndBanners.UI/wwwroot/js/mapEditorFiles.js` for downloads only.
- Create `tests/BloodAndBanners.UI.Tests/MapEditorTests.cs` and `SavedMapsPanelTests.cs`; add map API fake next to existing `AccountFakes.cs`.

**Interfaces:**
- `MapEditor` parameter `string? ImageBaseUrl`; injects `IAccountSession`, `IMapApiClient`, and `IJSRuntime`. Owns one `MapEditorDocument`, subscribes/unsubscribes account changes, and provides anonymous editor tools plus `AccountChip`.
- `SavedMapsPanel` takes the list/busy/error state and emits selected IDs; map operations remain in the parent so document/account state has one owner.
- JS module exports `download(filename, contents)` using a JSON Blob and a revoked object URL; use Blazor `InputFile` for bounded local imports.
- Use existing `BoardLayout` and shared terrain components inside an SVG. Put accessible pointer/keyboard actions on each hex group. Zoom uses an SVG viewBox/container, keeping elevation sides part of the same click target.

- [ ] Add failing bUnit tests: anonymous users can paint/import/export without map API calls; signed-in controls list and load only owned API results; Save creates a new map then updates its expected revision; Save as creates a new ID; failed save/conflict preserves dirty edits and exposes reload/Save as actions.
- [ ] Test dirty confirmations for New/Clear/Import/Load with cancel preserving the document. Parse/validate an import before replacing the current map. Disable conflicting operations while busy or take a snapshot so an awaited save never marks later edits clean.
- [ ] Add controlled-task tests: sign out/switch accounts during list/load/save, then finish the old task; ignore stale results and clear saved identity/list without clearing local contents. A token refresh for the same identity must not detach the document. Failed refresh leaves export available.
- [ ] Run filtered editor tests to confirm failures. Implement the editor and controls using the established style; retain the 15×10 initial map, 5–30 generated dimensions, elevation 0–10 tools, biome terrain cycling, starts 1–4/remove, selected-hex display, and zoom controls. Preserve imported valid elevations until explicitly edited.
- [ ] Keep all input and current document state alive while the existing sign-in panel opens/closes. On successful save, update only the saved snapshot/association belonging to the initiating owner. Export offers legacy JSON for ordinary maps and canonical JSON for scenario metadata.
- [ ] Rerun editor/model/API tests and then the complete UI suite; require zero failures. Check pointer selection on raised side faces and keyboard activation manually in Task 6.

## Task 6: Web routing, old URL, packaging, and end-to-end verification

**Files:**
- Create `src/BloodAndBanners.Web/Pages/Editor.razor` (`@page "/editor"`) using the existing configuration for image paths.
- Modify `src/BloodAndBanners.Web/Pages/Home.razor` to expose a map-editor link outside active game controls.
- Replace `hex_map_editor.html` with a lightweight redirect and visible fallback link to `https://blood.trickypig.com/play/editor`; keep changes to other static navigation links unnecessary.
- Modify deployment packaging/tests only where needed for the new route/assets; review `scripts/build-game-release.ps1`, `scripts/deployment/play/`, `tests/game-release-build.ps1`, and `tests/deployment-package.ps1`.
- Update `README.md` with editor access and private-save behavior.

**Interfaces:**
- Public production destination `/play/editor`; local route `/editor` honors `Navigation.BaseUri`.
- Existing magic-link email URLs return to the home page. Editor sign-in uses the code path in place; do not navigate away with an unsaved document. Token-link handling continues to use existing home behavior.

- [ ] Add/update release tests asserting the route is served by SPA fallback, shared JS module is packaged, and legacy static URL points to the production editor. Verify no account/private data is embedded in static assets.
- [ ] Implement page/link/redirect and documentation. Build `dotnet build src/BloodAndBanners.Web` and run `php api/tests/run.php`, `dotnet test tests/BloodAndBanners.Contracts.Tests`, `dotnet test tests/BloodAndBanners.UI.Tests`, `node --test tests/map_coordinates.test.js`, and relevant existing release-script tests. Run `git diff --check`.
- [ ] Use browser testing if available: anonymous new/paint/raise/lower/reset/starts/zoom, offset import and JSON download; sign in by local mail code, save/load and Save as; second owner cannot load the first owner's ID; edit conflict from two tabs; narrow viewport and game terrain/highlight regression. Follow the browser skill before browser tool use. If unavailable, state that visual/browser verification remains unperformed.
- [ ] Review the final changes against every spec section, request an independent code review according to the applicable review skill, fix material findings, rerun affected checks, and report test results and any environment limitations. Do not deploy.

## Plan self-review

Spec coverage: anonymous/account behavior (Tasks 3/5), owner authorization and immutable storage (1/2), compatibility (3), rendering consolidation (4), web migration and packaging (6), future sharing/game boundary (1, with no new public route). Review-focus cases are each assigned explicit verification. No product dependencies need to be installed; the existing test stacks suffice. Persistence and rendering can be tested independently, but the editor integration depends on their exact contracts, so native execution is recommended.
