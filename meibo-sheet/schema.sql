-- Tables are created automatically by web/db.php on first start.
-- This file is only a reference (and can be used for manual setup).
CREATE TABLE IF NOT EXISTS meta (k VARCHAR(32) NOT NULL PRIMARY KEY, v BIGINT NOT NULL);
CREATE TABLE IF NOT EXISTS people (id VARCHAR(40) NOT NULL PRIMARY KEY, data JSON NOT NULL, rev INT NOT NULL DEFAULT 1);
CREATE TABLE IF NOT EXISTS staff (pos INT NOT NULL PRIMARY KEY, name VARCHAR(255) NOT NULL);
CREATE TABLE IF NOT EXISTS days (d DATE NOT NULL PRIMARY KEY, seq TINYINT NOT NULL, data JSON NOT NULL, rev INT NOT NULL DEFAULT 1);
CREATE TABLE IF NOT EXISTS attendance (d DATE NOT NULL, person_id VARCHAR(40) NOT NULL, overnight TINYINT NOT NULL DEFAULT 0, PRIMARY KEY (d, person_id));
CREATE TABLE IF NOT EXISTS log (id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY, ts VARCHAR(40), type VARCHAR(16), target VARCHAR(64), field VARCHAR(64), old_v JSON NULL, new_v JSON NULL, d VARCHAR(10));
INSERT IGNORE INTO meta (k, v) VALUES ('counter', 0);
-- Example report: days attended per person in a month
-- SELECT person_id, COUNT(*) AS days, SUM(overnight) AS nights FROM attendance WHERE d BETWEEN '2026-09-01' AND '2026-09-30' GROUP BY person_id;
