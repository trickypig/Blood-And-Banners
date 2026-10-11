CREATE TABLE IF NOT EXISTS users (
    id CHAR(32) PRIMARY KEY,
    email VARCHAR(320) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    display_name VARCHAR(100) NOT NULL,
    converted_guest_id CHAR(32) NULL,
    created_at DATETIME(6) NOT NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS guest_identities (
    id CHAR(32) PRIMARY KEY,
    device_id VARCHAR(255) NOT NULL,
    converted_user_id CHAR(32) NULL,
    created_at DATETIME(6) NOT NULL,
    CONSTRAINT fk_guest_user FOREIGN KEY (converted_user_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS refresh_tokens (
    id CHAR(32) PRIMARY KEY,
    identity_kind ENUM('guest', 'user') NOT NULL,
    identity_id CHAR(32) NOT NULL,
    device_id VARCHAR(255) NOT NULL,
    token_hash CHAR(64) NOT NULL UNIQUE,
    expires_at DATETIME(6) NOT NULL,
    revoked_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS scenarios (
    id CHAR(32) PRIMARY KEY,
    owner_user_id CHAR(32) NULL,
    room_code VARCHAR(32) NULL,
    name VARCHAR(160) NOT NULL,
    visibility VARCHAR(24) NOT NULL DEFAULT 'private',
    created_at DATETIME(6) NOT NULL,
    deleted_at DATETIME(6) NULL,
    CONSTRAINT fk_scenario_owner FOREIGN KEY (owner_user_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS scenario_revisions (
    id CHAR(32) PRIMARY KEY,
    scenario_id CHAR(32) NOT NULL,
    revision INT NOT NULL,
    definition_json JSON NOT NULL,
    created_at DATETIME(6) NOT NULL,
    UNIQUE KEY uq_scenario_revision (scenario_id, revision),
    CONSTRAINT fk_revision_scenario FOREIGN KEY (scenario_id) REFERENCES scenarios(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS games (
    id CHAR(32) PRIMARY KEY,
    mode VARCHAR(32) NOT NULL,
    lifecycle VARCHAR(32) NOT NULL,
    scenario_revision_id CHAR(32) NULL,
    scenario_name VARCHAR(160) NOT NULL,
    host_identity_id CHAR(32) NOT NULL,
    version BIGINT NOT NULL DEFAULT 1,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    completed_at DATETIME(6) NULL,
    expires_at DATETIME(6) NULL,
    KEY idx_games_history (host_identity_id, updated_at),
    CONSTRAINT fk_game_revision FOREIGN KEY (scenario_revision_id) REFERENCES scenario_revisions(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS game_memberships (
    id CHAR(32) PRIMARY KEY,
    game_id CHAR(32) NOT NULL,
    identity_id CHAR(32) NOT NULL,
    seat_index INT NOT NULL,
    role VARCHAR(32) NOT NULL,
    is_host TINYINT(1) NOT NULL DEFAULT 0,
    ready TINYINT(1) NOT NULL DEFAULT 0,
    seat_token_hash CHAR(64) NOT NULL,
    joined_at DATETIME(6) NOT NULL,
    UNIQUE KEY uq_game_identity (game_id, identity_id),
    UNIQUE KEY uq_game_seat (game_id, seat_index),
    KEY idx_memberships_history (identity_id, joined_at),
    CONSTRAINT fk_membership_game FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS spectator_links (
    id CHAR(32) PRIMARY KEY,
    game_id CHAR(32) NOT NULL,
    token_hash CHAR(64) NOT NULL UNIQUE,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    created_by_identity_id CHAR(32) NOT NULL,
    created_at DATETIME(6) NOT NULL,
    revoked_at DATETIME(6) NULL,
    CONSTRAINT fk_spectator_game FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS game_snapshots (
    game_id CHAR(32) PRIMARY KEY,
    version BIGINT NOT NULL,
    state_json JSON NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    CONSTRAINT fk_snapshot_game FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS game_events (
    game_id CHAR(32) NOT NULL,
    sequence BIGINT NOT NULL,
    version BIGINT NOT NULL,
    kind VARCHAR(80) NOT NULL,
    acting_identity_id CHAR(32) NULL,
    focus_hexes_json JSON NOT NULL,
    payload_json JSON NOT NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (game_id, sequence),
    CONSTRAINT fk_event_game FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS command_receipts (
    game_id CHAR(32) NOT NULL,
    command_id VARCHAR(80) NOT NULL,
    identity_id CHAR(32) NOT NULL,
    response_json JSON NOT NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (game_id, command_id, identity_id),
    CONSTRAINT fk_receipt_game FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE
) ENGINE=InnoDB;
