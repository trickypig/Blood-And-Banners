# Canonical Map Coordinates Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make axial `q`/`r` the only persisted coordinate format and regenerate every rectangular built-in map in the same orientation.

**Architecture:** A small PHP value helper and a browser/Node JavaScript helper implement the same odd-column-offset-to-axial boundary conversion. The editor normalizes coordinates at creation/import and otherwise works only with axial data. The deterministic scenario generator authors rectangular cells by column and row, converts them once, and writes canonical scenario JSON consumed unchanged by the rules and renderer.

**Tech Stack:** PHP 8.3 and the dependency-free PHP test runner; plain browser JavaScript plus Node's built-in test/assert APIs; JSON scenario assets; .NET 10 xUnit verification.

**Spec:** `docs/superpowers/specs/2026-10-13-canonical-map-coordinates-design.md`

## Global Constraints

- Axial `q`/`r` is the only persisted tile-coordinate format going forward.
- Rectangular creation converts `(column,row)` to `q = row - floor(column / 2)`, `r = column` immediately.
- `columns` and `rows` remain dimensions only.
- Preserve explicit axial input and support legacy `col`/`row` input through one-way normalization.
- Do not edit, move, delete, or commit `data/Home_Map.json`.
- Do not reinterpret existing immutable saved scenario revisions or matches.
- Leave `BoardLayout`, `Hex`, and `HexMath` coordinate semantics unchanged.

## Review Focus

1. Odd columns must receive the half-row offset while even columns must not; conversion tests cover columns 0, 1, 2, 3, and 28.
2. A version-2 editor file must normalize to axial fields without leaving stale `col`/`row` values; the Node test checks the normalized object and exported shape.
3. Explicit `q`/`r` input must not be converted a second time; PHP and JavaScript tests both cover preservation.
4. Terrain and starts must remain on their original visual Home Map cells; the source-to-generated fidelity test compares every converted key and payload.
5. Proving Field placement tie-breaking changes with the canonical axes; exact literal placements and capacity remain tested.

---

### Task 1: Canonical PHP conversion and legacy import

**Files:**
- Create: `api/src/Scenario/AxialCoordinates.php`
- Modify: `api/src/Scenario/LegacyMapImporter.php`
- Modify: `api/tests/ScenarioTest.php`

**Interfaces:**
- Produces: `AxialCoordinates::fromOddColumnOffset(int $column, int $row): array{q:int,r:int}`.
- Consumes: legacy hexes with either complete `q`/`r` or complete `col`/`row` coordinates.

- [x] **Step 1: Write failing importer tests.** Add literal expectations showing `(0,0) -> (0,0)`, `(1,0) -> (0,1)`, `(2,1) -> (0,2)`, and explicit axial `(-3,7)` remains unchanged.
- [x] **Step 2: Run `php api/tests/run.php`.** Expected: the legacy conversion assertion fails because current code relabels `col` as `q` and `row` as `r`.
- [x] **Step 3: Implement the minimal helper and importer branch.** Preserve complete `q`/`r`; otherwise convert complete `col`/`row`; reject a hex without either complete pair as `invalidLegacyMap`.
- [x] **Step 4: Run `php api/tests/run.php`.** Expected: all PHP tests pass before scenario assets are changed.

### Task 2: Axial editor state and serialization

**Files:**
- Create: `map_coordinates.js`
- Create: `tests/map_coordinates.test.js`
- Modify: `hex_map_editor.html`

**Interfaces:**
- Produces: `MapCoordinates.fromOffset(column,row)`, `MapCoordinates.toOffset(q,r)`, `MapCoordinates.normalizeHex(hex)`, and `MapCoordinates.normalizeMap(map)` for browser and CommonJS consumers.
- The editor's `mapData.hexes` contains `q`/`r`, never `col`/`row`.

- [x] **Step 1: Write failing Node tests.** Assert literal conversion pairs, inverse conversion, preservation of explicit axial values, removal of legacy fields, version 3 normalization, and rejection of incomplete coordinate pairs.
- [x] **Step 2: Run `node --test tests/map_coordinates.test.js`.** Expected: fail because `map_coordinates.js` does not exist.
- [x] **Step 3: Implement the coordinate module.** Normalize by cloning map/hex objects so imports cannot retain legacy coordinate keys.
- [x] **Step 4: Run the Node test.** Expected: all coordinate-module tests pass.
- [x] **Step 5: Update the editor.** Load the module, bump `MAP_FORMAT_VERSION` to 3, create axial hexes, calculate centers from axial coordinates, key selection/variants by `q`/`r`, show axial coordinates, and normalize imported maps before assigning `mapData`.
- [x] **Step 6: Run the Node test again.** Expected: all tests pass.

### Task 3: Regenerate canonical built-in rectangles

**Files:**
- Modify: `api/tools/build-terrain-scenarios.php`
- Modify/generated: `api/scenarios/four-corners.json`
- Modify/generated: `api/scenarios/home-map.json`
- Modify/generated: `api/scenarios/proving-field.json`
- Modify: `api/tests/ScenarioTest.php`
- Modify: `api/tests/VanillaGeometryTest.php`

**Interfaces:**
- Consumes: `AxialCoordinates::fromOddColumnOffset` from Task 1.
- Produces: deterministic canonical axial JSON for all three rectangular Vanilla maps.

- [x] **Step 1: Write failing scenario assertions.** Verify every expected rectangular `(column,row)` maps to its canonical axial key; update Four Corners homes to `5,0` and `-1,12`; update Home Map source fidelity and homes to `2,3` and `12,28`; update Proving Field homes, representative terrain, and exact placements to their canonical coordinates.
- [x] **Step 2: Run `php api/tests/run.php`.** Expected: coordinate-shape and converted-key assertions fail against the existing scenario assets.
- [x] **Step 3: Refactor the generator.** Author Four Corners and Proving Field using `$column`/`$row`, convert each cell through the shared helper, and choose Home Map starts by their converted keys.
- [x] **Step 4: Run `php api/tools/build-terrain-scenarios.php data/Home_Map.json api/scenarios`.** Expected: the three scenario files are deterministically rewritten; the source file is untouched.
- [x] **Step 5: Run `php api/tests/run.php`.** Expected: all PHP tests pass, including connectivity and deployment capacity.
- [x] **Step 6: Run `node --test tests/map_coordinates.test.js`, `dotnet test tests/BloodAndBanners.Contracts.Tests/BloodAndBanners.Contracts.Tests.csproj --no-restore`, and `dotnet test tests/BloodAndBanners.UI.Tests/BloodAndBanners.UI.Tests.csproj --no-restore`.** Expected: every suite passes.
- [x] **Step 7: Run `git diff --check` and inspect `git status --short`.** Expected: no whitespace errors and `data/Home_Map.json` remains untracked and unchanged.

## Self-review

- Spec coverage: editor creation/import/export, API normalization, three built-ins, starts, terrain fidelity, placement, connectivity, and saved-revision compatibility are covered.
- Placeholder scan: no deferred implementation placeholders remain.
- Type consistency: PHP and JavaScript helpers both return canonical `q`/`r`; generators and tests consume those exact names.
- Review focus: each listed failure mode is assigned a literal behavior test in Tasks 1–3.
