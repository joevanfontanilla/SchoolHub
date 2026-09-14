-- @up
-- One CLASS: a subject taught to a section in a term (docs/17 amended).
-- Its weekly time blocks live in class_meetings, so a subject that meets Monday
-- and Wednesday is still one class with one roster, one enlistment per student,
-- and one set of grades.
CREATE TABLE class_schedules (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    term_id         BIGINT UNSIGNED NOT NULL,
    section_id      BIGINT UNSIGNED NOT NULL,
    subject_id      BIGINT UNSIGNED NOT NULL,
    teacher_user_id BIGINT UNSIGNED NULL,
    status          VARCHAR(20) NOT NULL DEFAULT 'ACTIVE',
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_class_section_subject (section_id, subject_id),
    KEY idx_class_teacher (term_id, teacher_user_id),
    CONSTRAINT fk_class_term FOREIGN KEY (term_id) REFERENCES terms (id) ON DELETE RESTRICT,
    CONSTRAINT fk_class_section FOREIGN KEY (section_id) REFERENCES sections (id) ON DELETE RESTRICT,
    CONSTRAINT fk_class_subject FOREIGN KEY (subject_id) REFERENCES subjects (id) ON DELETE RESTRICT,
    CONSTRAINT fk_class_teacher FOREIGN KEY (teacher_user_id) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Weekly time blocks. Overlap checks (teacher, room, same section) run in
-- ClassScheduleService; a time overlap cannot be expressed as a constraint.
CREATE TABLE class_meetings (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    class_schedule_id BIGINT UNSIGNED NOT NULL,
    day_of_week       TINYINT UNSIGNED NOT NULL,
    start_time        TIME NOT NULL,
    end_time          TIME NOT NULL,
    room_id           BIGINT UNSIGNED NULL,
    created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_meeting_class (class_schedule_id),
    KEY idx_meeting_room_day (room_id, day_of_week),
    KEY idx_meeting_day (day_of_week, start_time),
    CONSTRAINT fk_meeting_class FOREIGN KEY (class_schedule_id) REFERENCES class_schedules (id) ON DELETE CASCADE,
    CONSTRAINT fk_meeting_room FOREIGN KEY (room_id) REFERENCES rooms (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A student in a class. Written only by EnlistmentService. Dropped enlistments
-- are kept (status DROPPED), never deleted.
CREATE TABLE class_enlistments (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    enrollment_id     BIGINT UNSIGNED NOT NULL,
    class_schedule_id BIGINT UNSIGNED NOT NULL,
    status            VARCHAR(20) NOT NULL DEFAULT 'ENLISTED',
    override_reason   VARCHAR(500) NULL,
    enlisted_at       DATETIME NOT NULL,
    enlisted_by       BIGINT UNSIGNED NOT NULL,
    dropped_at        DATETIME NULL,
    dropped_by        BIGINT UNSIGNED NULL,
    drop_reason       VARCHAR(500) NULL,
    created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_enlistment (enrollment_id, class_schedule_id),
    KEY idx_enlistment_class (class_schedule_id, status),
    CONSTRAINT fk_enlistment_enrollment FOREIGN KEY (enrollment_id) REFERENCES enrollments (id) ON DELETE RESTRICT,
    CONSTRAINT fk_enlistment_class FOREIGN KEY (class_schedule_id) REFERENCES class_schedules (id) ON DELETE RESTRICT,
    CONSTRAINT fk_enlistment_by FOREIGN KEY (enlisted_by) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT fk_enlistment_dropped_by FOREIGN KEY (dropped_by) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- @down
DROP TABLE IF EXISTS class_enlistments;
DROP TABLE IF EXISTS class_meetings;
DROP TABLE IF EXISTS class_schedules;
