-- Operator approved and applied October 17, 2026. One-time provisioning record;
-- do not rerun against the existing role or rotate credentials by replaying it.
-- Connect as administrator to the verified containing database: postgres.
-- Schema/table ownership remains admin. Set the login password privately with
-- psql's \password command or the approved local provisioning step; never store
-- a password in this SQL file or in the cloud backup folder.
CREATE ROLE mcp_trading_runtime LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOREPLICATION NOBYPASSRLS;
GRANT CONNECT ON DATABASE postgres TO mcp_trading_runtime;
GRANT USAGE ON SCHEMA "mcp_trading" TO mcp_trading_runtime;
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA "mcp_trading" TO mcp_trading_runtime;
GRANT USAGE, SELECT, UPDATE ON ALL SEQUENCES IN SCHEMA "mcp_trading" TO mcp_trading_runtime;
ALTER DEFAULT PRIVILEGES FOR ROLE admin IN SCHEMA "mcp_trading"
    GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO mcp_trading_runtime;
ALTER DEFAULT PRIVILEGES FOR ROLE admin IN SCHEMA "mcp_trading"
    GRANT USAGE, SELECT, UPDATE ON SEQUENCES TO mcp_trading_runtime;
