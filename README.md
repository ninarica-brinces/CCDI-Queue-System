# CCDI Queue System

## PostgreSQL configuration

The PHP API uses PDO with PostgreSQL. Set these environment variables in Render
under the web service's **Environment** settings, using the connection details
from the Supabase project's PostgreSQL connection page:

- `DB_HOST` (required)
- `DB_PORT` (optional; defaults to `5432`)
- `DB_NAME` (optional; defaults to `postgres`)
- `DB_USER` (required)
- `DB_PASSWORD` (required; keep this secret and do not commit it)
- `DB_SSLMODE` (optional; defaults to `require`)
- `APP_TIMEZONE` (optional; defaults to `Asia/Manila`)
- `APP_DEBUG` (optional; set to `true` temporarily to include database error
  details in API 500 responses; disable it after troubleshooting)

The Docker image installs PHP's `pdo_pgsql` and `pgsql` extensions. For local
development, set the same variables in the environment used to run PHP.

Before deploying, run [`supabase.sql`](./supabase.sql) in the Supabase SQL
Editor. It safely adds ticket fields for student details and transaction
information, plus indexes used for shared queue ordering and active-ticket
checks. API failures include a request ID that can be matched with Render logs.
Set `APP_DEBUG=true` only while troubleshooting; it exposes database error
messages in API responses and should be disabled afterward.

Both portals call the same relative `api.php` endpoint. Deploy the PHP app and
its HTML/JavaScript together as one Render web service; do not host the pages
as a separate static site with a different API URL.

## Deployment security

The current API does not authenticate callers, and the cashier passcode is
checked in browser code only. Anyone who can reach the Render URL can read queue
data and invoke ticket or reset actions. Add server-side authentication and
authorization before using this deployment with real student data.
