CREATE TABLE IF NOT EXISTS login_challenges (
    id TEXT PRIMARY KEY,
    email TEXT NOT NULL,
    code_hash TEXT NOT NULL,
    token_hash TEXT NOT NULL UNIQUE,
    attempts INTEGER NOT NULL DEFAULT 0,
    client_ip TEXT NOT NULL,
    expires_at TEXT NOT NULL,
    consumed_at TEXT NULL,
    created_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_login_challenges_email ON login_challenges(email, created_at);
CREATE INDEX IF NOT EXISTS idx_login_challenges_ip ON login_challenges(client_ip, created_at);

CREATE TABLE IF NOT EXISTS friendships (
    id TEXT PRIMARY KEY,
    requester_user_id TEXT NOT NULL,
    addressee_user_id TEXT NULL,
    addressee_email TEXT NOT NULL,
    status TEXT NOT NULL CHECK (status IN ('pending', 'accepted')),
    from_game INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL,
    responded_at TEXT NULL,
    UNIQUE (requester_user_id, addressee_email),
    FOREIGN KEY (requester_user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (addressee_user_id) REFERENCES users(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_friendships_addressee ON friendships(addressee_user_id);
CREATE INDEX IF NOT EXISTS idx_friendships_email ON friendships(addressee_email);
