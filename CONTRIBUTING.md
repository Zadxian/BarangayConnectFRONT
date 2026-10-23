# Contributing to BarangayConnect

Thanks for helping with BarangayConnect. Please coordinate with the group before making changes that affect the database schema, authentication, payments, or resident data.

## Local setup

1. Install PHP 8.1 or later with `pdo_mysql`, `openssl`, and `curl`, plus Node.js and npm.
2. Copy `.env.example` to `.env` and fill in your own local settings. Never commit `.env`, CA certificates, credentials, or resident information.
3. Install frontend dependencies with `npm install`.
4. Apply database migrations only to an approved development database:

   ```powershell
   php .\public\index.php migrate
   ```

5. Start the PHP backend and Vite frontend using the commands in the README.

Do not point local development at a production database. Use fictional demo data and test accounts. Database access should be issued individually with the least privileges needed; do not share the Aiven administrator password in chat or commit it.

## Branch and pull request workflow

1. Update your local `main` branch before starting work.
2. Create a focused branch, for example `feature/request-history` or `fix/login-validation`.
3. Keep changes small and avoid mixing unrelated cleanup with feature work.
4. Run `npm run build` for frontend changes and PHP syntax checks for changed PHP files.
5. Open a pull request to `main`. Describe the user-visible change, list the checks you ran, and include screenshots for UI changes when useful.
6. Ask another group member to review the pull request before merging.

Do not commit generated `node_modules/` or `public/build/` files. Database changes should use a new, reversible LavaLust migration instead of manual production edits.
