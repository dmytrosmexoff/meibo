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

-- ===== History (everything that changes is kept) =====
-- people_history  : every saved version of a person (and a last copy marked deleted=1 when removed)
-- days_history    : every saved version of every day sheet (who attended, shifts, number of the sheet)
-- log             : every single change (field, old value, new value)
-- person_settings : readable list of each person's settings by date (name, kind, medicines, meals, note)
-- person_absence  : readable list of periods when a person was marked absent
-- staff_days      : readable list of who worked each day (明 / 日勤 / 夜勤, with the prefix written before the name)
-- attendance      : readable list of who attended each day (with name and kind)
--
-- Who attended on a date:            SELECT person_name, kind, overnight FROM attendance WHERE d = '2026-10-01';
-- Staff of a date:                   SELECT role, pos, prefix, name FROM staff_days WHERE d = '2026-10-01' ORDER BY role, pos;
-- All settings of every person:      SELECT * FROM person_settings ORDER BY person_id, from_date;
-- Latest 50 changes:                 SELECT id, ts, type, field, old_v, new_v FROM log ORDER BY id DESC LIMIT 50;
-- All saved versions of one day:     SELECT id, saved_at, rev, seq FROM days_history WHERE d = '2026-10-01' ORDER BY id;
-- Absence periods:                   SELECT * FROM person_absence ORDER BY person_id, from_date;
