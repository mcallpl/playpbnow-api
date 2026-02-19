-- Drop the UNIQUE constraint on cell_phone
-- This constraint prevents merging players who have different phone numbers.
-- Run this in phpMyAdmin on the playpbnow database.

-- First check what the index is called:
-- SHOW INDEX FROM players WHERE Column_name = 'cell_phone' AND Non_unique = 0;

-- Drop it (the index name is typically 'cell_phone' or 'unique_cell_phone'):
ALTER TABLE players DROP INDEX cell_phone;
