ALTER TABLE reading_history
    ADD COLUMN progress_percent TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER chapter_id;
