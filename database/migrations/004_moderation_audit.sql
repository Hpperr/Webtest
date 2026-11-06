CREATE TABLE moderation_actions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    admin_id BIGINT UNSIGNED NOT NULL,
    target_type ENUM('book', 'report', 'comment', 'user') NOT NULL,
    target_id BIGINT UNSIGNED NOT NULL,
    action VARCHAR(32) NOT NULL,
    details JSON NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT moderation_actions_admin_fk FOREIGN KEY (admin_id) REFERENCES users(id),
    INDEX moderation_actions_target_idx (target_type, target_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
