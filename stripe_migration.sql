-- Stripe integration migration
-- Run on the PlayPBNow database (MariaDB at peoplestar.com)

ALTER TABLE users
    ADD COLUMN stripe_customer_id VARCHAR(255) DEFAULT NULL AFTER subscription_end_date,
    ADD COLUMN stripe_subscription_id VARCHAR(255) DEFAULT NULL AFTER stripe_customer_id;

CREATE INDEX idx_users_stripe_customer ON users(stripe_customer_id);
CREATE INDEX idx_users_stripe_subscription ON users(stripe_subscription_id);
