const runs = new WeakMap();

// Source animation timing: 100 ms per frame. Frame indexes here are zero based.
export function frameAt(elapsed) {
    const tick = Math.max(0, Math.floor(elapsed / 100));
    if (tick < 48) {
        const pass = Math.floor(tick / 16);
        return { sheet: 'opening', frame: pass === 1 ? 15 - tick % 16 : tick % 16, opacity: 0, done: false };
    }
    if (tick < 138) {
        const idle = tick - 48;
        return { sheet: 'idle', frame: Math.floor(idle / 15) % 2 ? 14 - idle % 15 : idle % 15,
            opacity: Math.min(1, (elapsed - 4800) / 800), done: false };
    }
    return { sheet: 'opening', frame: tick < 154 ? 15 - (tick - 138) : 0, opacity: 1, done: tick >= 154 };
}

export function stop(board) {
    const run = runs.get(board);
    if (run) {
        run.stopped = true;
        cancelAnimationFrame(run.frame);
        runs.delete(board);
    }
}

export async function start(board, receiver, gameId) {
    stop(board);
    const run = { stopped: false, frame: 0 };
    runs.set(board, run);
    const portals = [...board.querySelectorAll('[data-portal-frames]')];
    const sheets = portals.flatMap(portal => [...portal.querySelectorAll('image')]);
    // Decode every sheet before starting so slow image loads cannot consume the opening.
    await Promise.all([...new Set(sheets.map(sheet => sheet.getAttribute('href')).filter(Boolean))].map(async url => {
        const image = new Image();
        image.src = url;
        try { await image.decode(); } catch { /* An unavailable image must not block gameplay. */ }
    }));
    if (run.stopped) return;
    const began = performance.now();
    const step = now => {
        if (run.stopped) return;
        const state = frameAt(now - began);
        for (const portal of portals) {
            portal.setAttribute('viewBox', `${state.frame * 190} 0 190 185`);
            for (const sheet of portal.querySelectorAll('image')) {
                sheet.style.display = sheet.dataset.sheet === state.sheet ? '' : 'none';
            }
        }
        for (const unit of board.querySelectorAll('.unit-token')) unit.style.opacity = state.opacity;
        if (state.done) {
            runs.delete(board);
            receiver.invokeMethodAsync('CompletePortalEntrance', gameId);
        } else {
            run.frame = requestAnimationFrame(step);
        }
    };
    run.frame = requestAnimationFrame(step);
}
