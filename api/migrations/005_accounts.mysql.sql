CREATE TABLE IF NOT EXISTS login_challenges (
    id CHAR(32) PRIMARY KEY,
    email VARCHAR(320) NOT NULL,
    code_hash CHAR(64) NOT NULL,
    token_hash CHAR(64) NOT NULL UNIQUE,
    attempts INT NOT NULL DEFAULT 0,
    client_ip VARCHAR(64) NOT NULL,
    expires_at DATETIME(6) NOT NULL,
    consumed_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL,
    INDEX idx_login_challenges_email (email, created_at),
    INDEX idx_login_challenges_ip (client_ip, created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS friendships (
    id CHAR(32) PRIMARY KEY,
    requester_user_id CHAR(32) NOT NULL,
    addressee_user_id CHAR(32) NULL,
    addressee_email VARCHAR(320) NOT NULL,
    status VARCHAR(16) NOT NULL,
    from_game TINYINT NOT NULL DEFAULT 0,
    created_at DATETIME(6) NOT NULL,
    responded_at DATETIME(6) NULL,
    UNIQUE KEY uq_friendships_pair (requester_user_id, addressee_email),
    INDEX idx_friendships_addressee (addressee_user_id),
    INDEX idx_friendships_email (addressee_email),
    CONSTRAINT fk_friendships_requester FOREIGN KEY (requester_user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_friendships_addressee FOREIGN KEY (addressee_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
