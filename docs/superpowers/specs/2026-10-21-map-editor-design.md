# Blazor map editor with private saved maps

## Intent and agreed behavior

Move the map editor into the existing Blazor application and let signed-in users save and load their own maps in the database. Anyone can use the editor and import/export local files without signing in. Only a map's owner can browse, load, or save it through the editor.

The owner will later need to start games on custom maps, let participants see the battlefield, and potentially share or publish maps for universal use. Those features are future work, but this implementation must preserve a suitable data model.

## Scope and implementation choices

The following are proposed engineering choices, rather than additional requirements from the user.

- Add an `/editor` route in the web Blazor app, with the editor component and supporting logic in `BloodAndBanners.UI`. Keep it reusable by MAUI, but adding native navigation is outside this change.
- Preserve the existing editor's map name, rectangular generation, clear, biome/terrain selection, elevation tools, player-start markers, selected-hex information, and zoom controls. Preserve its current editing ranges; accept valid imported elevations supported by the game without silently clamping them.
- Use the existing `BoardLayout`, `HexTile`, and `TerrainArt`. Extract shared terrain drawing and elevation shading from `GameTable` into a small component/helper used by both game and editor. Game overlays, units, targeting, and turn interaction remain in `GameTable`. Avoid rewriting game behavior to accommodate editing.
- Keep the existing visual style and responsive map/sidebar arrangement. Use SVG hex hit targets and local Blazor state. JavaScript is limited to browser facilities such as downloads and viewport interaction where needed.
- Replace the old static editor page with a link/redirect to the Blazor editor, preserving existing incoming links. Resolve the destination consistently with the site's existing game URL and app base path; do not assume both sites share an origin or authentication storage. Editing and sign-in take place on the game application's origin.

## Account behavior

Reuse `IAccountSession`, its token refresh behavior, and the existing sign-in components. Signed-out users see an optional sign-in action explaining that it enables database storage. Editing, importing, and exporting require no account or database calls.

Signed-in users also see My maps, Save, Save as, and Load. Save updates the loaded owned map; a new or imported map first creates a saved map. Save as always creates a separate map. Maps are identified by IDs, not names; duplicate names are allowed. Renaming is an ordinary save.

Keep map contents in local component state during sign-in, sign-out, network errors, and expired sessions. If authentication expires, explain that signing in is needed to save, while keeping file export available. Sign-out clears the saved-map list and association with the current database map; the map remains editable locally. Logging into a different account must never overwrite the previous owner's map.

Track unsaved changes. Before New, Clear, Import, or Load replaces/discards work, offer an in-app discard confirmation. A successful export need not clear the database-dirty flag. After a failed save, retain edits and show an inline error. No autosave, deletion, or revision-history browser is required in this change.

## Persistence and API

Reuse the existing `scenarios` and `scenario_revisions` tables and `ScenarioService`, rather than creating a separate maps table. Store a canonical `ScenarioDefinition` and private visibility. All editor endpoints require a real signed-in user; guest identities are rejected. Derive owner identity exclusively from the authenticated principal.

Proposed routes:

- `GET /api/v1/account/maps`: owned, non-deleted maps with ID, name, latest revision ID/number, and last-saved time, newest first. Exclude built-ins and other owners' maps.
- `POST /api/v1/account/maps`: validate and create a private scenario and first revision; return its identifiers and summary.
- `GET /api/v1/account/maps/{id}`: return the latest canonical definition and identifiers, only for the owner.
- `PUT /api/v1/account/maps/{id}`: append an immutable revision and update the map name, only for the owner. Require the caller's expected latest revision ID; a stale edit returns a conflict without discarding local edits. Offer reload or Save as after a conflict.

Return the same not-found response for missing and other-owned map IDs. Do not expose the existing unrestricted internal `getRevision` helper as a public editor route. Apply ownership and concurrency checks transactionally; concurrent saves must not allocate duplicate revision numbers or silently overwrite each other. Add a database constraint/migration if necessary for that guarantee, with both SQLite and MySQL support.

Server validation must cover bounded request/map sizes, supported schema, nonempty bounded names, integer dimensions/coordinates/elevations, unique coordinates, valid terrain/biome combinations, and supported player markers. Share established validation rules where possible. Do not require a finished playable map to save: an unfinished map may have no player starts. Use explicit validation errors, not PHP coercion of malformed data.

Games already reference immutable scenario revisions. Future custom-game creation can authorize the host's chosen map and pin a revision. Participants then see the game's board projection under game permissions, without needing access to the editor API. Sharing/publication will need explicit new permissions and discovery routes; private map IDs must not implicitly grant access.

## File compatibility

Continue importing the legacy offset-coordinate JSON and version-3 axial JSON accepted by the current editor. Normalize once to the canonical model; use the existing coordinate conventions and `LegacyMapImporter` as the compatibility reference. Also accept canonical `ScenarioDefinition` JSON so database maps can round-trip without losing scenario metadata.

Retain legacy-compatible version-3 editor export for ordinary maps, preserving height, starts, and existing tile fields. For canonical imports carrying scenario-only metadata, offer a canonical JSON export that preserves it; do not silently discard placements, objectives, scoring, or compatibility settings. Preserve unsupported-but-valid scenario fields in local state even when there are no editing controls for them. Reject malformed files with an inline explanation while retaining the current map.

## Verification and delivery

API tests cover unauthenticated/guest rejection, isolation between two owners across every route, list/load/create/update, immutable revisions, stale/concurrent saves, malformed input, and saving incomplete maps. UI/model tests cover anonymous editing and file operations, account-dependent controls, sign-in/session failure preserving edits, Save versus Save as, dirty-state confirmation, file compatibility, and shared shading/geometry behavior. Run relevant existing game rendering and account tests to catch regressions from extraction.

Manually verify the editor's terrain/elevation/player tools, raised-tile hit testing, zoom, file download/upload, account save/load, and narrow-screen layout. Update release packaging only as needed so the route and browser helpers ship with the app. Deployment is a separate action; do not publish as part of implementation.

## Deferred

Starting games on custom maps, public/shared browsing, universal-map promotion, collaborative editing, autosave, map deletion, and a revision-history interface are not part of this implementation.
