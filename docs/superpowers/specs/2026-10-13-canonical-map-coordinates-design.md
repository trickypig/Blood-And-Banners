# Canonical map coordinates — design

Date: 2026-10-13
Status: approved in conversation

## Goal

Use axial `q`/`r` coordinates as the only persisted tile-coordinate format in the map editor, scenario importer, built-in scenarios, renderer, and rules engine.

## Coordinate convention

The existing rules engine and `BoardLayout` convention remains authoritative:

- axial `r` runs horizontally across a flat-top board;
- axial `q` runs diagonally down the board;
- neighboring hexes use the six axial deltas already defined by `Hex` and `HexMath`.

A rectangular editor canvas is created from odd-column offset positions, but those positions are converted immediately and are never stored:

```text
r = column
q = row - floor(column / 2)
```

The reverse calculation is used only when a rectangular row is needed for display or bounds:

```text
column = r
row = q + floor(r / 2)
```

`columns` and `rows` remain map dimensions. They are not an alternate tile-coordinate system.

## Editor behavior

- New maps store every hex as `q`/`r` from creation onward.
- Drawing, hit testing, selection, terrain variants, and the position inspector read `q`/`r`.
- Version 3 exports contain `q`/`r` and no `col`/`row` fields.
- Existing version 1 and 2 editor files with `col`/`row` remain importable. Import converts them immediately, removes the legacy fields, and subsequent export writes version 3 axial data.
- Files already containing `q`/`r` retain those coordinates.

## API and scenarios

- `LegacyMapImporter` preserves explicit axial `q`/`r` input and converts legacy `col`/`row` input with the approved formula.
- A shared PHP coordinate helper owns that conversion for the importer and scenario generator.
- Four Corners, Proving Field, and Home Map are generated from rectangular authoring positions and persisted in canonical axial coordinates.
- Terrain, elevation, blockers, objects, portals, and starts remain attached to their original visual cells.
- Home Map source `data/Home_Map.json` remains unchanged and untracked.
- Existing saved scenario revisions and matches are not rewritten or reinterpreted.

## Compatibility and validation

- Legacy input support is a one-way boundary adapter, not a second output format.
- Every rectangular built-in must contain exactly the canonical axial key produced for each `(column,row)` within its declared dimensions.
- Deployment capacity, map connectivity, terrain fidelity, and deterministic Proving Field placement remain covered by tests after conversion.

## Superseded decisions

This design supersedes the coordinate statements in the 2026-10-12 terrain-and-maps design that directly relabeled `col` as `q` and `row` as `r`, and that described Proving Field as unchanged.
