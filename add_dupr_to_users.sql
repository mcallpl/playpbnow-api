-- Add dupr_rating column to users table if it doesn't exist
ALTER TABLE users ADD COLUMN dupr_rating DECIMAL(3,2) DEFAULT NULL;
