-- Migration 003: Add redirect_uri to oauth_states table
ALTER TABLE oauth_states ADD COLUMN IF NOT EXISTS redirect_uri VARCHAR(512) NULL AFTER platform;
