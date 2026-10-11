CREATE TABLE IF NOT EXISTS game_states (
    game_id TEXT PRIMARY KEY,
    version INTEGER NOT NULL,
    state_json TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE
);
