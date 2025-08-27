-- Initialize Bazar Marketplace Database
-- This script will be executed when MySQL container starts for the first time

-- Grant privileges to bazar user
GRANT ALL PRIVILEGES ON bazar_marketplace.* TO 'bazar'@'%';
FLUSH PRIVILEGES;

-- Source the main schema file (this will be mounted from the project root)
-- The main schema is in database_schema.sql at the project root