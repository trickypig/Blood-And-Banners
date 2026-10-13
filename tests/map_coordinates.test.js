const test = require('node:test');
const assert = require('node:assert/strict');

const MapCoordinates = require('../map_coordinates.js');

test('odd-column offset positions convert to the canonical axial convention', () => {
    assert.deepEqual(MapCoordinates.fromOffset(0, 0), { q: 0, r: 0 });
    assert.deepEqual(MapCoordinates.fromOffset(1, 0), { q: 0, r: 1 });
    assert.deepEqual(MapCoordinates.fromOffset(2, 1), { q: 0, r: 2 });
    assert.deepEqual(MapCoordinates.fromOffset(3, 3), { q: 2, r: 3 });
    assert.deepEqual(MapCoordinates.fromOffset(28, 26), { q: 12, r: 28 });
});

test('canonical axial positions convert back to their rectangular editor cells', () => {
    assert.deepEqual(MapCoordinates.toOffset(0, 0), { column: 0, row: 0 });
    assert.deepEqual(MapCoordinates.toOffset(0, 1), { column: 1, row: 0 });
    assert.deepEqual(MapCoordinates.toOffset(0, 2), { column: 2, row: 1 });
    assert.deepEqual(MapCoordinates.toOffset(12, 28), { column: 28, row: 26 });
});

test('legacy editor maps normalize to version 3 axial data without stale offset fields', () => {
    const normalized = MapCoordinates.normalizeMap({
        version: 2,
        name: 'Legacy Map',
        columns: 2,
        rows: 1,
        hexes: [{ col: 1, row: 0, biome: 'lava', terrain: 'lava', height: 2 }]
    });

    assert.deepEqual(normalized, {
        version: 3,
        name: 'Legacy Map',
        columns: 2,
        rows: 1,
        hexes: [{ q: 0, r: 1, biome: 'lava', terrain: 'lava', height: 2 }]
    });
});

test('already-axial editor maps preserve their coordinates', () => {
    const normalized = MapCoordinates.normalizeMap({
        version: 3,
        name: 'Axial Map',
        columns: 1,
        rows: 1,
        hexes: [{ q: -3, r: 7, biome: 'plains', terrain: 'grass', height: 0 }]
    });

    assert.deepEqual(normalized.hexes[0], {
        q: -3,
        r: 7,
        biome: 'plains',
        terrain: 'grass',
        height: 0
    });
});

test('incomplete coordinate pairs are rejected', () => {
    assert.throws(() => MapCoordinates.normalizeHex({ q: 1 }), /Invalid map coordinates/);
    assert.throws(() => MapCoordinates.normalizeHex({ col: 1 }), /Invalid map coordinates/);
});
