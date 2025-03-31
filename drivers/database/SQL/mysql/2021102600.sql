-- changing table format and dropping foreign keys is needed for some versions of MySQL
ALTER TABLE `tasklists` DROP FOREIGN KEY `fk_tasklists_user_id`;
ALTER TABLE `tasks` DROP FOREIGN KEY`fk_tasks_tasklist_id`;

ALTER TABLE `tasklists` ROW_FORMAT=DYNAMIC;
ALTER TABLE `tasks` ROW_FORMAT=DYNAMIC;

ALTER TABLE `tasklists` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `tasks` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

ALTER TABLE `tasklists` ADD CONSTRAINT `fk_tasklist_user_id` FOREIGN KEY (`user_id`)
    REFERENCES `users`(`user_id`) ON DELETE CASCADE ON UPDATE CASCADE;
ALTER TABLE `tasks` ADD  CONSTRAINT `fk_tasks_tasklist_id` FOREIGN KEY (`tasklist_id`)
    REFERENCES `tasklists`(`tasklist_id`) ON DELETE CASCADE ON UPDATE CASCADE;
