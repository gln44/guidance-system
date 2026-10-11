-- Guidance Counseling System - database schema
-- Import this file in phpMyAdmin (Import tab) or run:  mysql -u root -p < schema.sql

CREATE DATABASE IF NOT EXISTS guidance_db
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE guidance_db;

-- Counselor accounts. Only these users can log in.
CREATE TABLE IF NOT EXISTS counselors (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  full_name     VARCHAR(100) NOT NULL,
  username      VARCHAR(50)  NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role          ENUM('admin', 'counselor') NOT NULL DEFAULT 'counselor',
  is_active     TINYINT(1) NOT NULL DEFAULT 1,
  last_login    DATETIME NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Student records.
CREATE TABLE IF NOT EXISTS students (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  student_no       VARCHAR(30)  NOT NULL UNIQUE,
  last_name        VARCHAR(60)  NOT NULL,
  first_name       VARCHAR(60)  NOT NULL,
  middle_name      VARCHAR(60)  NULL,
  sex              VARCHAR(20)  NULL,
  birthdate        DATE         NULL,
  course           VARCHAR(100) NULL,
  year_level       VARCHAR(20)  NULL,
  section          VARCHAR(30)  NULL,
  contact_no       VARCHAR(30)  NULL,
  email            VARCHAR(100) NULL,
  address          VARCHAR(255) NULL,
  guardian_name    VARCHAR(100) NULL,
  guardian_contact VARCHAR(30)  NULL,
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_student_name (last_name, first_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per counseling session.
CREATE TABLE IF NOT EXISTS counseling_sessions (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  student_id      INT UNSIGNED NOT NULL,
  counselor_id    INT UNSIGNED NOT NULL,
  session_date    DATE NOT NULL,
  session_time    TIME NULL,
  session_type    VARCHAR(40) NOT NULL,
  referral_source VARCHAR(40) NOT NULL,
  concern         TEXT NOT NULL,
  notes           TEXT NULL,
  action_taken    TEXT NULL,
  recommendations TEXT NULL,
  status          ENUM('Open', 'Follow-up', 'Closed') NOT NULL DEFAULT 'Open',
  follow_up_date  DATE NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_session_student   FOREIGN KEY (student_id)   REFERENCES students(id)   ON DELETE RESTRICT,
  CONSTRAINT fk_session_counselor FOREIGN KEY (counselor_id) REFERENCES counselors(id) ON DELETE RESTRICT,
  INDEX idx_session_date (session_date),
  INDEX idx_session_status (status, follow_up_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sections for each grade level (set on the Sections page).
CREATE TABLE IF NOT EXISTS sections (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  grade_level VARCHAR(20) NOT NULL,
  name        VARCHAR(30) NOT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_grade_section (grade_level, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
