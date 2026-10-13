(function (root, factory) {
    const api = factory();
    if (typeof module === 'object' && module.exports) {
        module.exports = api;
    }
    if (root) {
        root.MapCoordinates = api;
    }
}(typeof globalThis !== 'undefined' ? globalThis : this, function () {
    'use strict';

    const FORMAT_VERSION = 3;

    function assertInteger(value) {
        if (!Number.isInteger(value)) {
            throw new Error('Invalid map coordinates');
        }
    }

    function fromOffset(column, row) {
        assertInteger(column);
        assertInteger(row);
        return { q: row - Math.floor(column / 2), r: column };
    }

    function toOffset(q, r) {
        assertInteger(q);
        assertInteger(r);
        return { column: r, row: q + Math.floor(r / 2) };
    }

    function normalizeHex(hex) {
        const { q, r, col, row, ...properties } = hex;
        const hasQ = Number.isInteger(q);
        const hasR = Number.isInteger(r);
        if (hasQ || hasR) {
            if (!hasQ || !hasR) {
                throw new Error('Invalid map coordinates');
            }
            return { q, r, ...properties };
        }

        const hasColumn = Number.isInteger(col);
        const hasRow = Number.isInteger(row);
        if (!hasColumn || !hasRow) {
            throw new Error('Invalid map coordinates');
        }
        return { ...fromOffset(col, row), ...properties };
    }

    function normalizeMap(map) {
        if (!map || !Array.isArray(map.hexes)) {
            throw new Error('Invalid map format');
        }
        return {
            ...map,
            version: FORMAT_VERSION,
            hexes: map.hexes.map(normalizeHex)
        };
    }

    return { FORMAT_VERSION, fromOffset, toOffset, normalizeHex, normalizeMap };
}));
