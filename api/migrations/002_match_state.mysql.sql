CREATE TABLE IF NOT EXISTS game_states (
    game_id CHAR(32) PRIMARY KEY,
    version BIGINT NOT NULL,
    state_json JSON NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    CONSTRAINT fk_state_game FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE
) ENGINE=InnoDB;
