ALTER TABLE chapters
    ADD COLUMN moderation_status ENUM('approved', 'pending', 'rejected') NOT NULL DEFAULT 'approved'
        AFTER visibility,
    ADD INDEX chapters_moderation_queue_idx (moderation_status, created_at);

ALTER TABLE moderation_actions
    MODIFY target_type ENUM('book', 'chapter', 'report', 'comment', 'user') NOT NULL;
