ALTER TABLE game_memberships ADD COLUMN ai_personality TEXT NULL;
ALTER TABLE games ADD COLUMN ai_pace TEXT NULL;
ALTER TABLE games ADD COLUMN ai_stepped_at INTEGER NULL;
