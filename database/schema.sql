SET NAMES utf8mb4;
CREATE DATABASE IF NOT EXISTS swimpro CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE swimpro;

CREATE TABLE IF NOT EXISTS clubs (
 id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(160) NOT NULL, city VARCHAR(120) NULL,
 contact VARCHAR(160) NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS swimmers (
 id INT AUTO_INCREMENT PRIMARY KEY, license_no VARCHAR(60) NOT NULL UNIQUE,
 first_name VARCHAR(100) NOT NULL, last_name VARCHAR(100) NOT NULL,
 birth_date DATE NOT NULL, sex ENUM('F','M') NOT NULL, club_id INT NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_swimmer_club FOREIGN KEY(club_id) REFERENCES clubs(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS swimmer_club_history (
 id INT AUTO_INCREMENT PRIMARY KEY,
 swimmer_id INT NOT NULL,
 club_id INT NULL,
 joined_on DATE NOT NULL,
 left_on DATE NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_history_swimmer FOREIGN KEY(swimmer_id) REFERENCES swimmers(id) ON DELETE CASCADE,
 CONSTRAINT fk_history_club FOREIGN KEY(club_id) REFERENCES clubs(id) ON DELETE SET NULL,
 INDEX idx_history_swimmer(swimmer_id), INDEX idx_history_club(club_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS users (
 id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(120) NOT NULL, email VARCHAR(160) NOT NULL UNIQUE,
 password VARCHAR(255) NOT NULL,
 role ENUM('admin','organisateur','club','officiel','nageur','entraineur') NOT NULL DEFAULT 'club',
 club_id INT NULL, swimmer_id INT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_user_club FOREIGN KEY(club_id) REFERENCES clubs(id) ON DELETE SET NULL,
 CONSTRAINT fk_user_swimmer FOREIGN KEY(swimmer_id) REFERENCES swimmers(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS competitions (
 id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(160) NOT NULL, event_date DATE NOT NULL,
 venue VARCHAR(160) NOT NULL, lanes INT NOT NULL DEFAULT 8, deadline DATE NULL,
 status ENUM('draft','open','running','finished') NOT NULL DEFAULT 'open',
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS settings (k VARCHAR(60) PRIMARY KEY, v VARCHAR(255) NOT NULL) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS age_categories (
 id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(60) NOT NULL, min_age INT NOT NULL, max_age INT NOT NULL, sort_order INT NOT NULL DEFAULT 0
) ENGINE=InnoDB;
INSERT INTO age_categories(name,min_age,max_age,sort_order)
 SELECT * FROM (SELECT 'Poussins' n,0 a,9 b,1 o UNION ALL SELECT 'Benjamins',10,11,2 UNION ALL SELECT 'Minimes',12,13,3 UNION ALL SELECT 'Cadets',14,15,4 UNION ALL SELECT 'Juniors',16,17,5 UNION ALL SELECT 'Seniors',18,99,6) d
 WHERE NOT EXISTS(SELECT 1 FROM age_categories);

CREATE TABLE IF NOT EXISTS events (
 id INT AUTO_INCREMENT PRIMARY KEY, competition_id INT NOT NULL, name VARCHAR(160) NOT NULL,
 stroke ENUM('Nage libre','Dos','Brasse','Papillon','4 nages','Relais') NOT NULL DEFAULT 'Nage libre',
 distance INT NOT NULL, sex ENUM('F','M','Mixte') NOT NULL DEFAULT 'Mixte',
 category VARCHAR(60) NOT NULL DEFAULT 'Toutes catégories', relay TINYINT NOT NULL DEFAULT 0,
 phase ENUM('series','finale') NOT NULL DEFAULT 'series', max_entries INT NOT NULL DEFAULT 6,
 category_id INT NULL, relay_size TINYINT NOT NULL DEFAULT 4, source_event_id INT NULL,
 CONSTRAINT fk_event_comp FOREIGN KEY(competition_id) REFERENCES competitions(id) ON DELETE CASCADE,
 CONSTRAINT fk_event_category FOREIGN KEY(category_id) REFERENCES age_categories(id) ON DELETE SET NULL,
 CONSTRAINT fk_event_source FOREIGN KEY(source_event_id) REFERENCES events(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS entries (
 id INT AUTO_INCREMENT PRIMARY KEY, event_id INT NOT NULL, swimmer_id INT NOT NULL,
 seed_time DECIMAL(8,2) NULL, status ENUM('engagé','DNS','DSQ') NOT NULL DEFAULT 'engagé',
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_event_swimmer(event_id,swimmer_id),
 CONSTRAINT fk_entry_event FOREIGN KEY(event_id) REFERENCES events(id) ON DELETE CASCADE,
 CONSTRAINT fk_entry_swimmer FOREIGN KEY(swimmer_id) REFERENCES swimmers(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS relay_teams (
 id INT AUTO_INCREMENT PRIMARY KEY, event_id INT NOT NULL, club_id INT NULL, name VARCHAR(160) NOT NULL, seed_time DECIMAL(8,2) NULL,
 status ENUM('engagé','DNS','DSQ') NOT NULL DEFAULT 'engagé', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_relay_event(event_id),
 CONSTRAINT fk_relay_event FOREIGN KEY(event_id) REFERENCES events(id) ON DELETE CASCADE,
 CONSTRAINT fk_relay_club FOREIGN KEY(club_id) REFERENCES clubs(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS relay_members (
 id INT AUTO_INCREMENT PRIMARY KEY, team_id INT NOT NULL, event_id INT NOT NULL, swimmer_id INT NOT NULL, leg_no INT NOT NULL,
 UNIQUE KEY uq_relay_event_swimmer(event_id,swimmer_id), UNIQUE KEY uq_relay_team_leg(team_id,leg_no),
 CONSTRAINT fk_rm_team FOREIGN KEY(team_id) REFERENCES relay_teams(id) ON DELETE CASCADE,
 CONSTRAINT fk_rm_event FOREIGN KEY(event_id) REFERENCES events(id) ON DELETE CASCADE,
 CONSTRAINT fk_rm_swimmer FOREIGN KEY(swimmer_id) REFERENCES swimmers(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS heats (
 id INT AUTO_INCREMENT PRIMARY KEY, event_id INT NOT NULL, heat_no INT NOT NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_event_heat(event_id,heat_no),
 CONSTRAINT fk_heat_event FOREIGN KEY(event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS heat_lanes (
 id INT AUTO_INCREMENT PRIMARY KEY, heat_id INT NOT NULL, lane_no INT NOT NULL, entry_id INT NULL, team_id INT NULL,
 UNIQUE KEY uq_heat_lane(heat_id,lane_no), UNIQUE KEY uq_entry_heat(heat_id,entry_id), UNIQUE KEY uq_team_heat(heat_id,team_id),
 CONSTRAINT fk_lane_heat FOREIGN KEY(heat_id) REFERENCES heats(id) ON DELETE CASCADE,
 CONSTRAINT fk_lane_entry FOREIGN KEY(entry_id) REFERENCES entries(id) ON DELETE CASCADE,
 CONSTRAINT fk_lane_team FOREIGN KEY(team_id) REFERENCES relay_teams(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS results (
 id INT AUTO_INCREMENT PRIMARY KEY, entry_id INT NULL UNIQUE, team_id INT NULL, time_sec DECIMAL(8,2) NULL,
 status ENUM('OK','DNS','DSQ') NOT NULL DEFAULT 'OK', note VARCHAR(255) NULL,
 updated_by INT NULL, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_result_team(team_id),
 CONSTRAINT fk_result_entry FOREIGN KEY(entry_id) REFERENCES entries(id) ON DELETE CASCADE,
 CONSTRAINT fk_result_team FOREIGN KEY(team_id) REFERENCES relay_teams(id) ON DELETE CASCADE,
 CONSTRAINT fk_result_user FOREIGN KEY(updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS point_scale (place_no INT PRIMARY KEY, points INT NOT NULL);
INSERT IGNORE INTO point_scale(place_no,points) VALUES(1,20),(2,17),(3,15),(4,13),(5,11),(6,9),(7,7),(8,5);
INSERT INTO settings(k,v) VALUES('schema_version','2') ON DUPLICATE KEY UPDATE v=VALUES(v);

CREATE INDEX idx_entries_event ON entries(event_id);
CREATE INDEX idx_swimmers_club ON swimmers(club_id);
CREATE INDEX idx_results_updated ON results(updated_at);
