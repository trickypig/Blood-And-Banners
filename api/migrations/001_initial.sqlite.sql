CREATE TABLE IF NOT EXISTS users (
    id TEXT PRIMARY KEY,
    email TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    display_name TEXT NOT NULL,
    converted_guest_id TEXT NULL,
    created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS guest_identities (
    id TEXT PRIMARY KEY,
    device_id TEXT NOT NULL,
    converted_user_id TEXT NULL,
    created_at TEXT NOT NULL,
    FOREIGN KEY (converted_user_id) REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS refresh_tokens (
    id TEXT PRIMARY KEY,
    identity_kind TEXT NOT NULL CHECK (identity_kind IN ('guest', 'user')),
    identity_id TEXT NOT NULL,
    device_id TEXT NOT NULL,
    token_hash TEXT NOT NULL UNIQUE,
    expires_at TEXT NOT NULL,
    revoked_at TEXT NULL,
    created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS scenarios (
    id TEXT PRIMARY KEY,
    owner_user_id TEXT NULL,
    room_code TEXT NULL,
    name TEXT NOT NULL,
    visibility TEXT NOT NULL DEFAULT 'private',
    created_at TEXT NOT NULL,
    deleted_at TEXT NULL,
    FOREIGN KEY (owner_user_id) REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS scenario_revisions (
    id TEXT PRIMARY KEY,
    scenario_id TEXT NOT NULL,
    revision INTEGER NOT NULL,
    definition_json TEXT NOT NULL,
    created_at TEXT NOT NULL,
    UNIQUE (scenario_id, revision),
    FOREIGN KEY (scenario_id) REFERENCES scenarios(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS games (
    id TEXT PRIMARY KEY,
    mode TEXT NOT NULL,
    lifecycle TEXT NOT NULL,
    scenario_revision_id TEXT NULL,
    scenario_name TEXT NOT NULL,
    host_identity_id TEXT NOT NULL,
    version INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    completed_at TEXT NULL,
    expires_at TEXT NULL,
    FOREIGN KEY (scenario_revision_id) REFERENCES scenario_revisions(id)
);

CREATE TABLE IF NOT EXISTS game_memberships (
    id TEXT PRIMARY KEY,
    game_id TEXT NOT NULL,
    identity_id TEXT NOT NULL,
    seat_index INTEGER NOT NULL,
    role TEXT NOT NULL,
    is_host INTEGER NOT NULL DEFAULT 0,
    ready INTEGER NOT NULL DEFAULT 0,
    seat_token_hash TEXT NOT NULL,
    joined_at TEXT NOT NULL,
    UNIQUE (game_id, identity_id),
    UNIQUE (game_id, seat_index),
    FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS spectator_links (
    id TEXT PRIMARY KEY,
    game_id TEXT NOT NULL,
    token_hash TEXT NOT NULL UNIQUE,
    enabled INTEGER NOT NULL DEFAULT 1,
    created_by_identity_id TEXT NOT NULL,
    created_at TEXT NOT NULL,
    revoked_at TEXT NULL,
    FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS game_snapshots (
    game_id TEXT PRIMARY KEY,
    version INTEGER NOT NULL,
    state_json TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS game_events (
    game_id TEXT NOT NULL,
    sequence INTEGER NOT NULL,
    version INTEGER NOT NULL,
    kind TEXT NOT NULL,
    acting_identity_id TEXT NULL,
    focus_hexes_json TEXT NOT NULL,
    payload_json TEXT NOT NULL,
    created_at TEXT NOT NULL,
    PRIMARY KEY (game_id, sequence),
    FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS command_receipts (
    game_id TEXT NOT NULL,
    command_id TEXT NOT NULL,
    identity_id TEXT NOT NULL,
    response_json TEXT NOT NULL,
    created_at TEXT NOT NULL,
    PRIMARY KEY (game_id, command_id, identity_id),
    FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_game_events_poll ON game_events(game_id, sequence);
CREATE INDEX IF NOT EXISTS idx_games_history ON games(host_identity_id, updated_at);
CREATE INDEX IF NOT EXISTS idx_memberships_history ON game_memberships(identity_id, joined_at);
