CREATE TABLE IF NOT EXISTS questions (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(100) NULL,
  body VARCHAR(1000) NOT NULL,
  status ENUM('pending', 'asked', 'dismissed') NOT NULL DEFAULT 'pending',
  visible TINYINT(1) NOT NULL DEFAULT 0,
  is_current TINYINT(1) NOT NULL DEFAULT 0,
  ip_hash CHAR(64) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  INDEX idx_status_created (status, created_at),
  INDEX idx_ip_created (ip_hash, created_at),
  INDEX idx_wall (visible, status, is_current)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS votes (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  question_id INT UNSIGNED NOT NULL,
  voter_token CHAR(64) NOT NULL,
  value TINYINT NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_vote (question_id, voter_token),
  CONSTRAINT fk_votes_question
    FOREIGN KEY (question_id) REFERENCES questions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vote_limits (
  voter_token CHAR(64) NOT NULL,
  last_vote_at DATETIME NOT NULL,
  PRIMARY KEY (voter_token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
