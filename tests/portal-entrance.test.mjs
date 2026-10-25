import { test } from 'node:test';
import assert from 'node:assert/strict';
import { frameAt } from '../src/BloodAndBanners.UI/wwwroot/js/portalEntrance.js';

test('opening plays forward, backward, forward with units hidden', () => {
    for (let pass = 0; pass < 3; pass++) {
        for (let frame = 0; frame < 16; frame++) {
            const state = frameAt((pass * 16 + frame) * 100);
            assert.equal(state.sheet, 'opening');
            assert.equal(state.frame, pass === 1 ? 15 - frame : frame);
            assert.equal(state.opacity, 0);
        }
    }
});

test('units fade in while idle plays forward and backward three times', () => {
    for (let pass = 0; pass < 6; pass++) {
        for (let frame = 0; frame < 15; frame++) {
            const state = frameAt(4800 + (pass * 15 + frame) * 100);
            assert.equal(state.sheet, 'idle');
            assert.equal(state.frame, pass % 2 ? 14 - frame : frame);
        }
    }
    assert.equal(frameAt(4800).opacity, 0);
    assert.equal(frameAt(5200).opacity, 0.5);
    assert.equal(frameAt(5600).opacity, 1);
});

test('closing runs backward then holds the inactive first frame indefinitely', () => {
    for (let frame = 0; frame < 16; frame++) {
        const state = frameAt(13800 + frame * 100);
        assert.equal(state.sheet, 'opening');
        assert.equal(state.frame, 15 - frame);
        assert.equal(state.opacity, 1);
        assert.equal(state.done, false);
    }
    for (const elapsed of [15400, 16000, 999999]) {
        assert.deepEqual(frameAt(elapsed), { sheet: 'opening', frame: 0, opacity: 1, done: true });
    }
});
