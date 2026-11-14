-- =====================================================================
-- task_db.sql: TaskFlow database (run in HeidiSQL: Query tab, then F9)
-- =====================================================================

CREATE DATABASE IF NOT EXISTS `task_db`;
USE task_db;

DROP TABLE IF EXISTS tasks;

CREATE TABLE tasks (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    task_name        VARCHAR(255) NOT NULL,
    task_description TEXT NOT NULL,
    status           ENUM('pending', 'completed', 'overdue') DEFAULT 'pending',
    created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    due_date         DATE NULL,
    is_important     TINYINT(1) NOT NULL DEFAULT 0     -- star (Important page)
);

-- Optional sample data
INSERT INTO tasks (task_name, task_description, status, due_date, is_important) VALUES
('Prepare presentation', 'Slides for Friday class', 'pending',   CURDATE() + INTERVAL 3 DAY, 1),
('Finish Chapter 2',     'Read and take notes',     'overdue',   CURDATE() - INTERVAL 2 DAY, 0),
('Submit lab activity',  'Upload to Google Drive',  'completed', CURDATE() - INTERVAL 1 DAY, 0);

-- ---------------------------------------------------------------------
-- CRUD reference (index.php runs these as PDO prepared statements)
-- ---------------------------------------------------------------------
-- CREATE: INSERT INTO tasks (task_name, task_description, due_date, status) VALUES (?, ?, ?, ?);
-- READ:   SELECT * FROM tasks ORDER BY id ASC;
--         SELECT * FROM tasks WHERE status = 'overdue';
-- UPDATE: UPDATE tasks SET task_name = ?, task_description = ?, due_date = ?, status = ? WHERE id = ?;
--         UPDATE tasks SET status = IF(status = 'completed', 'pending', 'completed') WHERE id = ?;
--         UPDATE tasks SET status = 'overdue' WHERE due_date < CURRENT_DATE AND status = 'pending';
-- DELETE: DELETE FROM tasks WHERE id = ?;
