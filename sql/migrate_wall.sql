-- Run once if questions already exists from v1.

ALTER TABLE questions
  ADD COLUMN visible TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN is_current TINYINT(1) NOT NULL DEFAULT 0;

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

-- Run only if votes already exists without the up/down value column.
-- One row per voter per question; flipping a vote updates value in place.
ALTER TABLE votes
  ADD COLUMN value TINYINT NOT NULL DEFAULT 1;
