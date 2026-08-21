CREATE TABLE IF NOT EXISTS notifications (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 property_id INT NOT NULL,
 category VARCHAR(50) NOT NULL DEFAULT 'System',
 priority ENUM('Critical','High','Medium','Low') NOT NULL DEFAULT 'Medium',
 title VARCHAR(255) NOT NULL,
 message TEXT NULL,
 module VARCHAR(80) NULL,
 reference_type VARCHAR(80) NULL,
 reference_id VARCHAR(100) NULL,
 target_role VARCHAR(50) NOT NULL DEFAULT 'All',
 target_user_id INT NULL,
 action_url VARCHAR(500) NULL,
 created_by_role VARCHAR(50) NULL,
 created_by_user_id INT NULL,
 expires_at DATETIME NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 INDEX(property_id), INDEX(target_role), INDEX(target_user_id),
 INDEX(category), INDEX(priority), INDEX(created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notification_reads (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 notification_id BIGINT UNSIGNED NOT NULL,
 user_role VARCHAR(50) NOT NULL,
 user_id INT NOT NULL,
 read_at DATETIME DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_notification_read(notification_id,user_role,user_id),
 CONSTRAINT fk_notification_read FOREIGN KEY(notification_id)
 REFERENCES notifications(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notification_archives (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 notification_id BIGINT UNSIGNED NOT NULL,
 user_role VARCHAR(50) NOT NULL,
 user_id INT NOT NULL,
 archived_at DATETIME DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_notification_archive(notification_id,user_role,user_id),
 CONSTRAINT fk_notification_archive FOREIGN KEY(notification_id)
 REFERENCES notifications(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
