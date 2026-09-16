-- ============================================================================
-- EduFlex: AI-Powered Personalized Learning Companion
-- MySQL 8.0 schema
--
-- Built directly from the Data Dictionary in Chapter III (Tables 4 to 15).
-- Column names, types, lengths and nullability match the manuscript.
--
-- Several columns and tables are ADDED beyond the manuscript. They are marked
-- "ADDED" below and are listed in database/SCHEMA-NOTES.md. Update the
-- Chapter III data dictionary to match before your final defense.
--
-- Import:  phpMyAdmin > Import > choose this file > Go
-- Or CLI:  mysql -u root -p < 01_schema.sql
-- ============================================================================

DROP DATABASE IF EXISTS eduflex;
CREATE DATABASE eduflex
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
USE eduflex;


-- ---------------------------------------------------------------------------
-- TABLE 4: USER
-- ---------------------------------------------------------------------------
CREATE TABLE user (
  user_id        INT(11)      NOT NULL AUTO_INCREMENT,
  full_name      VARCHAR(150) NOT NULL,
  email          VARCHAR(255) NOT NULL,
  password_hash  VARCHAR(255) NOT NULL,
  program        VARCHAR(100) NOT NULL DEFAULT 'BS Information Technology',
  year_level     TINYINT(1)   NOT NULL DEFAULT 1,
  account_status VARCHAR(20)  NOT NULL DEFAULT 'active',
  -- ADDED: path to the learner's uploaded profile picture, relative to the
  -- project root, or NULL when they have not set one. See SCHEMA-NOTES.md 7.
  avatar_path    VARCHAR(255) NULL DEFAULT NULL,
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id),
  UNIQUE KEY uq_user_email (email),
  KEY idx_user_status (account_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ---------------------------------------------------------------------------
-- TABLE 5: SUBSCRIPTION
--
-- Kept because it is in your ERD. Note that Chapter I describes EduFlex as a
-- non-commercial prototype, so every account should be created with
-- plan_type = 'free'. Decide whether this table stays before the defense.
-- ---------------------------------------------------------------------------
CREATE TABLE subscription (
  subscription_id INT(11)     NOT NULL AUTO_INCREMENT,
  user_id         INT(11)     NOT NULL,
  plan_type       VARCHAR(20) NOT NULL DEFAULT 'free',
  status          VARCHAR(20) NOT NULL DEFAULT 'active',
  started_at      DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at      DATETIME        NULL DEFAULT NULL,
  PRIMARY KEY (subscription_id),
  UNIQUE KEY uq_subscription_user (user_id),
  CONSTRAINT fk_subscription_user FOREIGN KEY (user_id)
    REFERENCES user (user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ---------------------------------------------------------------------------
-- TABLE 6: LEARNING RESOURCE
-- ---------------------------------------------------------------------------
CREATE TABLE learning_resource (
  resource_id       INT(11)      NOT NULL AUTO_INCREMENT,
  user_id           INT(11)      NOT NULL,
  title             VARCHAR(255) NOT NULL,
  file_type         VARCHAR(50)  NOT NULL,
  storage_path      VARCHAR(500) NOT NULL,
  processing_status VARCHAR(20)  NOT NULL DEFAULT 'pending',
  -- ADDED: extraction bookkeeping. Chapter III change: add these to Table 6.
  original_name     VARCHAR(255) NOT NULL DEFAULT '',
  file_size         INT(11)      NOT NULL DEFAULT 0,
  char_count        INT(11)      NOT NULL DEFAULT 0,
  chunk_count       INT(11)      NOT NULL DEFAULT 0,
  extract_engine    VARCHAR(40)      NULL DEFAULT NULL,
  extract_message   VARCHAR(500)     NULL DEFAULT NULL,
  processed_at      DATETIME         NULL DEFAULT NULL,
  uploaded_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (resource_id),
  KEY idx_resource_user (user_id),
  KEY idx_resource_status (processing_status),
  CONSTRAINT fk_resource_user FOREIGN KEY (user_id)
    REFERENCES user (user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ---------------------------------------------------------------------------
-- TABLE 7: AI INTERACTION
-- ---------------------------------------------------------------------------
CREATE TABLE ai_interaction (
  interaction_id INT(11)  NOT NULL AUTO_INCREMENT,
  user_id        INT(11)  NOT NULL,
  resource_id    INT(11)      NULL DEFAULT NULL,
  prompt         TEXT     NOT NULL,
  response       TEXT     NOT NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (interaction_id),
  KEY idx_interaction_user (user_id),
  CONSTRAINT fk_interaction_user FOREIGN KEY (user_id)
    REFERENCES user (user_id) ON DELETE CASCADE,
  CONSTRAINT fk_interaction_resource FOREIGN KEY (resource_id)
    REFERENCES learning_resource (resource_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ---------------------------------------------------------------------------
-- TABLE 8: NOTIFICATION
-- ---------------------------------------------------------------------------
CREATE TABLE notification (
  notification_id   INT(11)      NOT NULL AUTO_INCREMENT,
  user_id           INT(11)      NOT NULL,
  notification_type VARCHAR(50)  NOT NULL,
  title             VARCHAR(255) NOT NULL,
  message           TEXT         NOT NULL,
  is_read           BOOLEAN      NOT NULL DEFAULT FALSE,
  created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (notification_id),
  KEY idx_notification_user_read (user_id, is_read),
  CONSTRAINT fk_notification_user FOREIGN KEY (user_id)
    REFERENCES user (user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ---------------------------------------------------------------------------
-- TABLE 9: SUPPORT REQUEST
-- ---------------------------------------------------------------------------
CREATE TABLE support_request (
  request_id   INT(11)      NOT NULL AUTO_INCREMENT,
  user_id      INT(11)      NOT NULL,
  request_type VARCHAR(20)  NOT NULL,
  subject      VARCHAR(255) NOT NULL,
  message      TEXT         NOT NULL,
  status       VARCHAR(20)  NOT NULL DEFAULT 'open',
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (request_id),
  KEY idx_support_user (user_id),
  KEY idx_support_status (status),
  CONSTRAINT fk_support_user FOREIGN KEY (user_id)
    REFERENCES user (user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ---------------------------------------------------------------------------
-- RESOURCE CHUNK  (ADDED — not in the Chapter III data dictionary)
--
-- Extraction turns an upload into plain text, which is then split into
-- overlapping chunks. Chunks are the unit sent to the language model: a
-- 40-page reviewer will not fit in one request, and sending the whole
-- document with every chat message would be slow and costly.
--
-- Chapter III change: add this as a new entity related to LEARNING_RESOURCE
-- (one resource has many chunks).
-- ---------------------------------------------------------------------------
CREATE TABLE resource_chunk (
  chunk_id    INT(11)  NOT NULL AUTO_INCREMENT,
  resource_id INT(11)  NOT NULL,
  chunk_index INT(11)  NOT NULL,
  content     TEXT     NOT NULL,
  word_count  INT(11)  NOT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (chunk_id),
  UNIQUE KEY uq_chunk_position (resource_id, chunk_index),
  KEY idx_chunk_resource (resource_id),
  FULLTEXT KEY ft_chunk_content (content),
  CONSTRAINT fk_chunk_resource FOREIGN KEY (resource_id)
    REFERENCES learning_resource (resource_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ---------------------------------------------------------------------------
-- TABLE 12: TOPIC PROGRESS
--
-- Declared before learning_activity because learning_activity now references
-- it (see the ADDED column note there).
--
-- ADDED: scored_items. The mastery model shows "No data" until a topic has at
-- least 5 scored items. Without this counter every screen would need a COUNT
-- join against attempt_response. Keep it in step with that table.
-- ---------------------------------------------------------------------------
CREATE TABLE topic_progress (
  topic_progress_id INT(11)      NOT NULL AUTO_INCREMENT,
  user_id           INT(11)      NOT NULL,
  resource_id       INT(11)      NOT NULL,
  topic_name        VARCHAR(255) NOT NULL,
  mastery_score     DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  weakness_priority VARCHAR(20)  NOT NULL DEFAULT 'none',
  scored_items      INT(11)      NOT NULL DEFAULT 0,   -- ADDED
  updated_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
                                 ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (topic_progress_id),
  UNIQUE KEY uq_topic_per_user_resource (user_id, resource_id, topic_name),
  KEY idx_topic_user_mastery (user_id, mastery_score),
  CONSTRAINT fk_topic_user FOREIGN KEY (user_id)
    REFERENCES user (user_id) ON DELETE CASCADE,
  CONSTRAINT fk_topic_resource FOREIGN KEY (resource_id)
    REFERENCES learning_resource (resource_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ---------------------------------------------------------------------------
-- TABLE 10: LEARNING ACTIVITY
--
-- ADDED: topic_progress_id. Your documented schema links an activity only to a
-- resource, so there is no way to tell which TOPIC an activity targets. The
-- entire adaptive loop depends on that link. Without it you cannot roll
-- attempts up into per-topic mastery.
-- ---------------------------------------------------------------------------
CREATE TABLE learning_activity (
  activity_id       INT(11)      NOT NULL AUTO_INCREMENT,
  resource_id       INT(11)      NOT NULL,
  topic_progress_id INT(11)          NULL DEFAULT NULL,   -- ADDED
  activity_type     VARCHAR(30)  NOT NULL,
  title             VARCHAR(255) NOT NULL,
  bloom_level       VARCHAR(20)  NOT NULL,
  difficulty_level  VARCHAR(20)  NOT NULL,
  generated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (activity_id),
  KEY idx_activity_resource (resource_id),
  KEY idx_activity_topic (topic_progress_id),
  CONSTRAINT fk_activity_resource FOREIGN KEY (resource_id)
    REFERENCES learning_resource (resource_id) ON DELETE CASCADE,
  CONSTRAINT fk_activity_topic FOREIGN KEY (topic_progress_id)
    REFERENCES topic_progress (topic_progress_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ---------------------------------------------------------------------------
-- TABLE 14: ACTIVITY ITEM
-- ---------------------------------------------------------------------------
CREATE TABLE activity_item (
  item_id        INT(11)     NOT NULL AUTO_INCREMENT,
  activity_id    INT(11)     NOT NULL,
  question_text  TEXT        NOT NULL,
  item_type      VARCHAR(30) NOT NULL,
  options_json   JSON            NULL DEFAULT NULL,
  correct_answer TEXT        NOT NULL,
  explanation    TEXT            NULL DEFAULT NULL,
  -- ADDED, both nullable. A practice set belongs to one topic at one Bloom
  -- level, so its items leave these NULL and inherit from the activity. A mock
  -- examination spans several topics and several levels, so ITS items carry
  -- their own. Everything that reads them uses
  -- COALESCE(item value, activity value), which is why adding these changed no
  -- existing behaviour. See SCHEMA-NOTES.md 8.
  topic_progress_id INT(11)     NULL DEFAULT NULL,
  bloom_level       VARCHAR(20) NULL DEFAULT NULL,
  PRIMARY KEY (item_id),
  KEY idx_item_activity (activity_id),
  KEY idx_item_topic (topic_progress_id),
  CONSTRAINT fk_item_activity FOREIGN KEY (activity_id)
    REFERENCES learning_activity (activity_id) ON DELETE CASCADE,
  CONSTRAINT fk_item_topic FOREIGN KEY (topic_progress_id)
    REFERENCES topic_progress (topic_progress_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ---------------------------------------------------------------------------
-- TABLE 11: ACTIVITY ATTEMPT
-- ---------------------------------------------------------------------------
CREATE TABLE activity_attempt (
  attempt_id   INT(11)      NOT NULL AUTO_INCREMENT,
  user_id      INT(11)      NOT NULL,
  activity_id  INT(11)      NOT NULL,
  score        DECIMAL(5,2)     NULL DEFAULT NULL,
  total_items  INT(11)      NOT NULL,
  started_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at DATETIME         NULL DEFAULT NULL,
  PRIMARY KEY (attempt_id),
  KEY idx_attempt_user (user_id),
  KEY idx_attempt_activity (activity_id),
  CONSTRAINT fk_attempt_user FOREIGN KEY (user_id)
    REFERENCES user (user_id) ON DELETE CASCADE,
  CONSTRAINT fk_attempt_activity FOREIGN KEY (activity_id)
    REFERENCES learning_activity (activity_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ---------------------------------------------------------------------------
-- TABLE 15: ATTEMPT RESPONSE
--
-- This table is the evidence base for the mastery model. Every row is one
-- scored item. Do not delete rows; recency weighting depends on the full
-- history being present.
-- ---------------------------------------------------------------------------
CREATE TABLE attempt_response (
  response_id INT(11)  NOT NULL AUTO_INCREMENT,
  attempt_id  INT(11)  NOT NULL,
  item_id     INT(11)  NOT NULL,
  user_answer TEXT         NULL DEFAULT NULL,
  is_correct  BOOLEAN  NOT NULL DEFAULT FALSE,
  answered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (response_id),
  KEY idx_response_attempt (attempt_id),
  KEY idx_response_item (item_id),
  CONSTRAINT fk_response_attempt FOREIGN KEY (attempt_id)
    REFERENCES activity_attempt (attempt_id) ON DELETE CASCADE,
  CONSTRAINT fk_response_item FOREIGN KEY (item_id)
    REFERENCES activity_item (item_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ---------------------------------------------------------------------------
-- TABLE 13: RECOMMENDATION
-- ---------------------------------------------------------------------------
CREATE TABLE recommendation (
  recommendation_id   INT(11)      NOT NULL AUTO_INCREMENT,
  user_id             INT(11)      NOT NULL,
  topic_progress_id   INT(11)      NOT NULL,
  recommended_level   VARCHAR(20)  NOT NULL,
  recommended_activity VARCHAR(255) NOT NULL,
  reason              TEXT         NOT NULL,
  status              VARCHAR(20)  NOT NULL DEFAULT 'new',
  created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (recommendation_id),
  KEY idx_reco_user_status (user_id, status),
  CONSTRAINT fk_reco_user FOREIGN KEY (user_id)
    REFERENCES user (user_id) ON DELETE CASCADE,
  CONSTRAINT fk_reco_topic FOREIGN KEY (topic_progress_id)
    REFERENCES topic_progress (topic_progress_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ---------------------------------------------------------------------------
-- Reference table for the Bloom weights used by the mastery model.
-- Kept in the database so the formula has one authoritative source and the
-- weights can be cited in Chapter III without hunting through code.
-- ---------------------------------------------------------------------------
CREATE TABLE bloom_level (
  bloom_level VARCHAR(20)  NOT NULL,
  sort_order  TINYINT      NOT NULL,
  weight      DECIMAL(3,1) NOT NULL,
  PRIMARY KEY (bloom_level)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO bloom_level (bloom_level, sort_order, weight) VALUES
  ('Remember',   1, 1.0),
  ('Understand', 2, 1.2),
  ('Apply',      3, 1.5),
  ('Analyze',    4, 1.8),
  ('Evaluate',   5, 2.0),
  ('Create',     6, 2.2);

-- ---------------------------------------------------------------------------
-- LOGIN ATTEMPT  (ADDED -- not in the Chapter III data dictionary)
--
-- One row per FAILED sign-in attempt. Successful logins are never recorded,
-- and a successful login deletes the rows for that email and address.
--
-- Without this table the login form accepts unlimited guesses at whatever rate
-- the network allows, which is the only thing between a weak password and an
-- account. A counter in the session would not do: an attacker simply does not
-- send the cookie.
--
-- No foreign key to `user` on purpose. Attempts against an email that does not
-- exist must be counted too, otherwise the throttle itself becomes a way to
-- discover which emails are registered.
--
-- Pruned by includes/security.php on each successful login, so it stays small
-- without a scheduled job.
-- ---------------------------------------------------------------------------
CREATE TABLE login_attempt (
  attempt_id   INT(11)      NOT NULL AUTO_INCREMENT,
  email        VARCHAR(255) NOT NULL,
  ip_address   VARCHAR(45)  NOT NULL,
  attempted_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (attempt_id),
  KEY idx_login_identity (email, ip_address, attempted_at),
  KEY idx_login_ip (ip_address, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
