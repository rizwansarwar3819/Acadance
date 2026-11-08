-- ============================================================
-- Database: acadance_db
-- ============================================================
CREATE DATABASE IF NOT EXISTS acadance_db
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE acadance_db;

-- ============================================================
-- Users: registered accounts
-- ============================================================
CREATE TABLE IF NOT EXISTS users (
    user_id       BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(120)  NOT NULL,
    email         VARCHAR(190)  NOT NULL UNIQUE,
    password_hash VARCHAR(255)  NOT NULL,
    role          ENUM('student', 'admin') NOT NULL DEFAULT 'student',
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE = InnoDB;

-- ============================================================
-- Courses: each user owns multiple courses
-- ============================================================
CREATE TABLE IF NOT EXISTS courses (
    course_id   BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     BIGINT UNSIGNED NOT NULL,
    course_name VARCHAR(160) NOT NULL,
    course_code VARCHAR(40)  NULL,
    description TEXT         NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_courses_user
        FOREIGN KEY (user_id) REFERENCES users (user_id)
        ON DELETE CASCADE,

    INDEX (user_id)
) ENGINE = InnoDB;

-- ============================================================
-- Assignments: each course has multiple assignments
-- ============================================================
CREATE TABLE IF NOT EXISTS assignments (
    assignment_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    course_id     BIGINT UNSIGNED NOT NULL,
    title         VARCHAR(200) NOT NULL,
    description   TEXT         NULL,
    deadline      DATETIME     NOT NULL,
    status        ENUM('pending', 'in_progress', 'completed')
                  NOT NULL DEFAULT 'pending',
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_assign_course
        FOREIGN KEY (course_id) REFERENCES courses (course_id)
        ON DELETE CASCADE,

    INDEX (course_id),
    INDEX (deadline)
) ENGINE = InnoDB;

-- ============================================================
-- Tasks: each assignment has multiple tasks (with subtask support)
-- ============================================================
CREATE TABLE IF NOT EXISTS tasks (
    task_id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    assignment_id    BIGINT UNSIGNED NOT NULL,
    parent_task_id   BIGINT UNSIGNED NULL,          -- self-referencing: subtasks
    title            VARCHAR(200) NOT NULL,
    description      TEXT         NULL,
    due_at           DATETIME     NULL,
    estimated_hours  DECIMAL(7,2) NOT NULL DEFAULT 0,
    priority         ENUM('low', 'medium', 'high') NOT NULL DEFAULT 'medium',
    status           ENUM('pending', 'in_progress', 'completed')
                     NOT NULL DEFAULT 'pending',
    completed_at     DATETIME NULL,
    created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_tasks_assignment
        FOREIGN KEY (assignment_id) REFERENCES assignments (assignment_id)
        ON DELETE CASCADE,

    CONSTRAINT fk_tasks_parent
        FOREIGN KEY (parent_task_id) REFERENCES tasks (task_id)
        ON DELETE SET NULL,                          -- parent delete hua to subtask orphan nahi hota

    INDEX (assignment_id),
    INDEX (parent_task_id),
    INDEX (due_at)
) ENGINE = InnoDB;

-- ============================================================
-- Task Dependencies: task depends on its prerequisite task
-- ============================================================
CREATE TABLE IF NOT EXISTS task_dependencies (
    task_id              BIGINT UNSIGNED NOT NULL,   -- the dependent task
    prerequisite_task_id BIGINT UNSIGNED NOT NULL,   -- must finish first
    created_at           TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (task_id, prerequisite_task_id),

    CONSTRAINT fk_dep_task
        FOREIGN KEY (task_id) REFERENCES tasks (task_id)
        ON DELETE CASCADE,

    CONSTRAINT fk_dep_pre
        FOREIGN KEY (prerequisite_task_id) REFERENCES tasks (task_id)
        ON DELETE CASCADE
) ENGINE = InnoDB;

-- ============================================================
-- Task Logs: audit trail of task activity
-- ============================================================
CREATE TABLE IF NOT EXISTS task_logs (
    log_id     BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    task_id    BIGINT UNSIGNED NOT NULL,
    action     VARCHAR(80) NOT NULL,
    details    TEXT NULL,
    logged_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_logs_task
        FOREIGN KEY (task_id) REFERENCES tasks (task_id)
        ON DELETE CASCADE,

    INDEX (task_id),
    INDEX (logged_at)
) ENGINE = InnoDB;

-- ============================================================
-- Notifications: overdue reminders etc. per user
-- ============================================================
CREATE TABLE IF NOT EXISTS notifications (
    notification_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         BIGINT UNSIGNED NOT NULL,
    task_id         BIGINT UNSIGNED NULL,
    message         VARCHAR(500) NOT NULL,
    is_read         TINYINT(1) NOT NULL DEFAULT 0,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_notif_user
        FOREIGN KEY (user_id) REFERENCES users (user_id)
        ON DELETE CASCADE,

    CONSTRAINT fk_notif_task
        FOREIGN KEY (task_id) REFERENCES tasks (task_id)
        ON DELETE CASCADE,

    -- Unique key prevents duplicate reminders for the same task+message
    UNIQUE KEY uq_user_task_message (user_id, task_id, message(190)),

    INDEX (user_id, is_read, created_at)
) ENGINE = InnoDB;