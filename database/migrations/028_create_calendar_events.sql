-- @up
-- The school academic calendar (docs/09): dated activities and no-class days, grouped by
-- semester under an academic year, shown on the public site and in the portal, and edited by
-- the registrar or the Super Admin (permission calendar.manage).
--
-- date_label is free text ("July 15-18, 2026", "Aug 17,18,20 & 22, 2026") because printed
-- calendars use irregular date expressions; sort_date is the primary date, only for ordering.
CREATE TABLE calendar_events (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    academic_year VARCHAR(20) NOT NULL,
    category      VARCHAR(50) NOT NULL,
    date_label    VARCHAR(120) NOT NULL,
    sort_date     DATE NULL,
    activity      VARCHAR(255) NOT NULL,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_calendar_year (academic_year),
    KEY idx_calendar_order (academic_year, sort_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- @down
DROP TABLE IF EXISTS calendar_events;
