# CCDI Queue System

## PostgreSQL configuration

The PHP API uses PDO with PostgreSQL. In Render's web service **Environment**
settings, use the Supabase project's **Session pooler** connection details.
Render may not have IPv6 connectivity to Supabase's direct database endpoint;
using the session pooler avoids that network failure. Copy the pooler host,
port, and username from Supabase **Connect** (the username commonly includes
the project reference):

- `DB_HOST` (required; session pooler hostname, not `db.<project-ref>.supabase.co`)
- `DB_PORT` (optional; use the port shown by Supabase, commonly `5432` for the
  session pooler or `6543` for the transaction pooler; defaults to `5432`)
- `DB_NAME` (required; Supabase database name, commonly `postgres`)
- `DB_USER` (required; use the exact pooler username)
- `DB_PASSWORD` (required; keep this secret and do not commit it)
- `DB_SSLMODE` (optional; defaults to `require`; retain SSL for Supabase)
- `APP_TIMEZONE` (optional; defaults to `Asia/Manila`)

The Docker image installs PHP's `pdo_pgsql` and `pgsql` extensions. For local
development, set the same variables in the environment used to run PHP. The
API returns HTTP 500 for uncaught connection or query errors and logs detailed
diagnostics under the included request ID.

Before deploying, run [`supabase.sql`](./supabase.sql) in the Supabase SQL
Editor. It safely adds ticket fields for student details and transaction
information, recall tracking, the daily report archive, and indexes used for
shared queue ordering and active-ticket checks. API failures include a request
ID that can be matched with Render logs.

`index.html` redirects to the student portal in `student.html`; the cashier
terminal is in `cashier.html` and uses `app.js`. Both call the same relative
`api.php` endpoint and therefore use the same server-side PDO connection.
Deploy the PHP app and its HTML/JavaScript together as one Render web service;
do not host the pages as a separate static site with a different API URL.

Cashier credentials are validated against active records in
`public.system_users`; the cashier portal requires a database role of
`cashier`. Cashier actions require server-side authorization, same-origin
requests, a CSRF token, and sign-in. Student sign-in requires a case-insensitive,
trimmed Student ID and registered full-name match. Sessions use HttpOnly,
SameSite cookies and expire after 30 minutes of inactivity.

Queue requests and their requested payment details are stored in
`queue_tickets`; cashier completion updates that ticket's status and records
completed tuition payments in `payment_activity_records` using the ticket
number as the reference. The amount is calculated from
`student_profiles.current_balance` and the ticket's payment percentage.
Resetting the operational day archives ticket and status totals in
`daily_reports` before clearing that day's tickets.

Ticket creation requires an exact student number and full name match in
`student_profiles`; student lookup suggestions are not exposed. The API limits
each operational day to 100 issued tickets and allows up to three recalls per
serving ticket. Detailed payment fields for non-tuition services are withheld
after a ticket leaves the active queue.

## Deployment security

Serve the application only over HTTPS. The API does not enable cross-origin
access, protects cashier operations with server-side authorization, limits
student sessions to their own identity, and omits payment details for
non-tuition services. Store cashier passwords as `password_hash()` hashes in
`public.system_users`; the API retains plaintext comparison only for legacy
records during migration.
