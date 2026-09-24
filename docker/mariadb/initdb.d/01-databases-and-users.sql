-- Runs once, on first start of an empty data volume (docker-entrypoint-initdb.d).
-- Local-only credentials; they are not secrets.
--
-- Two users model the production split from plan section 25.5:
--   axispay_app      : runtime user. In production it gets DML only
--                      (SELECT, INSERT, UPDATE, DELETE) and no DDL.
--   axispay_migrator : deploy-time user that runs migrations (DDL).
-- Locally both users get full rights on the two databases so that the test
-- suite (which migrates) can run as either user.

CREATE DATABASE IF NOT EXISTS axispay
  CHARACTER SET utf8mb4 COLLATE utf8mb4_uca1400_ai_ci;

CREATE DATABASE IF NOT EXISTS axispay_testing
  CHARACTER SET utf8mb4 COLLATE utf8mb4_uca1400_ai_ci;

CREATE USER IF NOT EXISTS 'axispay_app'@'%' IDENTIFIED BY 'axispay_app';
CREATE USER IF NOT EXISTS 'axispay_migrator'@'%' IDENTIFIED BY 'axispay_migrator';

GRANT ALL PRIVILEGES ON axispay.* TO 'axispay_app'@'%';
GRANT ALL PRIVILEGES ON axispay_testing.* TO 'axispay_app'@'%';
GRANT ALL PRIVILEGES ON axispay.* TO 'axispay_migrator'@'%';
GRANT ALL PRIVILEGES ON axispay_testing.* TO 'axispay_migrator'@'%';

-- Production reference (do not run locally):
--   GRANT SELECT, INSERT, UPDATE, DELETE ON axispay.* TO 'axispay_app'@'<app-host>';
--   GRANT ALL PRIVILEGES ON axispay.* TO 'axispay_migrator'@'<deploy-host>';

FLUSH PRIVILEGES;
