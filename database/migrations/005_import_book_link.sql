ALTER TABLE import_jobs
    ADD COLUMN book_id BIGINT UNSIGNED NULL AFTER user_id,
    ADD CONSTRAINT import_jobs_book_fk FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE SET NULL;
