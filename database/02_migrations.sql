-- ============================================================================
-- EduFlex: schema migrations
--
-- Brings an EXISTING eduflex database up to date WITHOUT destroying anything.
--
-- 01_schema.sql begins with DROP DATABASE. That is correct for a first install
-- and for the browser tests, and catastrophic for a database you have been
-- using: every account, upload, topic and mastery score goes with it. This file
-- exists so a schema change never costs you your data again.
--
-- Import:  phpMyAdmin > eduflex > Import > choose this file > Go
-- Or CLI:  mysql -u root eduflex < database/02_migrations.sql
-- Or:      php tools/migrate.php        (reports what it changed)
--
-- Safe to run more than once. Every statement uses IF NOT EXISTS, so running it
-- against an already-current database changes nothing and reports nothing.
-- MariaDB, which is what XAMPP ships, supports IF NOT EXISTS on ALTER TABLE.
-- MySQL 8 does not; on MySQL use tools/migrate.php, which checks first.
--
-- When you add a column or a table:
--   1. add it to 01_schema.sql, so a fresh install gets it
--   2. add the matching ALTER here, so an existing database gets it too
--   3. add it to database/SCHEMA-NOTES.md with the reason
-- All three, or a teammate's database and yours quietly disagree.
-- ============================================================================

USE eduflex;


-- ---------------------------------------------------------------------------
-- 2026-09-14  Login rate limiting.
--
-- One row per FAILED sign-in attempt. Without it the login form accepts
-- unlimited password guesses. See SCHEMA-NOTES.md section 6.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS login_attempt (
  attempt_id   INT(11)      NOT NULL AUTO_INCREMENT,
  email        VARCHAR(255) NOT NULL,
  ip_address   VARCHAR(45)  NOT NULL,
  attempted_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (attempt_id),
  KEY idx_login_identity (email, ip_address, attempted_at),
  KEY idx_login_ip (ip_address, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ---------------------------------------------------------------------------
-- 2026-09-15  Profile pictures.
--
-- Path to the learner's uploaded picture, relative to the project root, or
-- NULL when they have not set one. See SCHEMA-NOTES.md section 7.
-- ---------------------------------------------------------------------------
ALTER TABLE user
  ADD COLUMN IF NOT EXISTS avatar_path VARCHAR(255) NULL DEFAULT NULL
  AFTER account_status;


-- ---------------------------------------------------------------------------
-- 2026-09-16  Mock examinations.
--
-- An examination spans several topics and several Bloom levels, and until now
-- an item's topic and level came only from its activity. These two nullable
-- columns let an item carry its own. A practice set leaves them NULL and
-- behaves exactly as before. See SCHEMA-NOTES.md section 8.
-- ---------------------------------------------------------------------------
ALTER TABLE activity_item
  ADD COLUMN IF NOT EXISTS topic_progress_id INT(11) NULL DEFAULT NULL
  AFTER explanation;

ALTER TABLE activity_item
  ADD COLUMN IF NOT EXISTS bloom_level VARCHAR(20) NULL DEFAULT NULL
  AFTER topic_progress_id;
