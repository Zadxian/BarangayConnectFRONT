# BarangayConnect

BarangayConnect is a barangay e-services portal with a React/Vite interface and a PHP 8.1+ LavaLust backend. It supports resident accounts, document requests, private ID uploads, request tracking, incident reporting, staff review, hearing schedules, reports, and administrator-managed accounts and services.

## Local development (Windows / WAMP PHP + Aiven MySQL)

### 1. Prepare PHP and configure Aiven MySQL

1. In the Aiven Console, open your MySQL service and confirm it is running. Copy the host, port, database name, username, and password from its connection details. Aiven commonly uses `avnadmin` and `defaultdb`, but use the values displayed for your service.
2. Allow your current public IP in the service's allowed IP ranges. Do not expose the service to all IPs unless you have deliberately reviewed that risk.
3. Download the CA certificate from the Aiven service page. From the project root, create a local certificate folder and copy the downloaded CA file there:

   ```powershell
   New-Item -ItemType Directory -Force .\runtime\certs
   Copy-Item 'C:\path\to\downloaded\ca.pem' .\runtime\certs\ca.pem
   ```

4. From the project root, copy the example environment file:

   ```powershell
   Copy-Item .env.example .env
   ```

5. Edit `.env` and enter the Aiven connection details. Keep `DB_SSL_CA=runtime/certs/ca.pem` if you used the copy command above. Do not paste your Aiven password into chat or commit `.env`. Generate four independent random secrets for `APP_KEY`, `ADMIN_SETUP_TOKEN`, `JWT_SECRET`, and `REFRESH_TOKEN_KEY` with:

   ```powershell
   & 'C:\wamp64\bin\php\php8.4.15\php.exe' -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
   ```

   Run the command four times and use a different output for each secret. Keep `.env` private and never commit it.

### 2. Apply the database migrations to Aiven

From the project root, run:

```powershell
& 'C:\wamp64\bin\php\php8.4.15\php.exe' .\public\index.php migrate
```

This creates the existing user/authentication tables and the BarangayConnect workflow tables in the configured Aiven database. Migrations are available through the CLI only; do not expose migration actions as public web routes. Confirm the target service and database before running them.

### 3. Start the PHP backend

Run from the project root and leave the terminal open:

```powershell
& 'C:\wamp64\bin\php\php8.4.15\php.exe' -S 127.0.0.1:8000 -t public public\index.php
```

Keep this terminal open. The PHP backend and API listen at `http://127.0.0.1:8000`. PHP and Node run locally; the application database is Aiven, so WAMP's local MySQL/MariaDB service does not need to be running. If PHP is on your PATH, you can replace the absolute PHP executable path with `php`.

### 4. Start the React development server

In a second PowerShell window, from the project root:

```powershell
npm install
npm run dev
```

Open [http://127.0.0.1:5173](http://127.0.0.1:5173). Vite proxies API requests to the PHP server on port 8000.

For a production-style local build, run `npm run build` and open `http://127.0.0.1:8000`; PHP serves the compiled React assets from `public/build`.

### 5. Create accounts

1. Open [http://127.0.0.1:5173/setup](http://127.0.0.1:5173/setup) and create the initial administrator using the `ADMIN_SETUP_TOKEN` from `.env`.
2. Register residents at `/register`.
3. Sign in as the administrator and create staff accounts under **Admin → Users**.
4. Sign in to use the resident and staff areas. The initial admin setup page is disabled once an admin account exists.

## Included workflows

- React single-page interface served by Vite in development or by PHP from the production build.
- LavaLust's API library provides JSON responses for the CSRF-protected, session-authenticated API; its database query builder handles routine reads and writes through the configured connection.
- Role-checked resident, staff, and administrator endpoints; `src/api.js` is the small same-origin frontend client for those endpoints, not a separate API service or library.
- Resident registration and password login with hashed passwords and role-checked workflows.
- Resident profiles and optional SMS consent.
- Document catalog, request submission with private ID uploads, status tracking, and staff review.
- PayMongo checkout when credentials and webhook configuration are provided; in-person payment can be recorded by authorized staff.
- Incident reports with optional location coordinates and evidence, staff case management, and mediation hearing scheduling.
- In-app notifications, optional Semaphore SMS updates, reports, admin-managed user roles, document fees, incident types, and barangay information.
- CSRF-protected forms, prepared database statements, private uploads outside `public/`, and audit records for important actions.

## Collaborating with your group

- Keep the GitHub repository private while it contains application source and configuration details. Invite group members as repository collaborators and use feature branches and pull requests into `main`; see [CONTRIBUTING.md](./CONTRIBUTING.md).
- Each contributor should create their own ignored `.env` from `.env.example`. Never commit or send `.env`, database passwords, JWT secrets, CA certificates, private uploads, or real resident data.
- Use an approved development database and individual, least-privilege database credentials. Do not give teammates the Aiven administrator password or run development work against production data.
- Keep `node_modules/` and generated `public/build/` output out of commits. Commit source files, migrations, and dependency lockfiles instead.

## Optional provider configuration

Set provider credentials only in the untracked `.env` file:

- `PAYMONGO_SECRET_KEY` and `PAYMONGO_WEBHOOK_SECRET` enable online checkout. Configure the provider webhook to call `https://<your-public-host>/webhooks/paymongo` and subscribe to `checkout_session.payment.paid`. Localhost cannot receive public provider webhooks without a secure tunnel.
- `SEMAPHORE_API_KEY` enables SMS status messages for residents who opted in. Without it, app notifications remain available and SMS is marked unconfigured.
- `FIREBASE_API_KEY` enables Firebase Authentication email/password sign-up and login. Enable Email/Password in Firebase Authentication. Without the key, the app uses its local password-hash authentication.
- `GOOGLE_OAUTH_CLIENT_ID` and `GOOGLE_OAUTH_CLIENT_SECRET` enable Google sign-in. Register the exact redirect URI `<APP_URL>/auth/google/callback` in the Google OAuth client.
- `GOOGLE_MAPS_API_KEY` enables the interactive incident map and reverse-geocoded address. Restrict the browser key by referrer and enable Maps JavaScript API and Geocoding API.

Private uploads use the app's protected local `runtime/uploads` directory. Cloudinary storage is not enabled; local private storage remains the default.

## Security and deployment notes

- A database credential was previously present directly in the application's configuration. It has been removed from the code. **Revoke/rotate that database credential with its provider**, because deleting it from the working file does not invalidate a previously exposed password.
- Use HTTPS, strong secrets, backups, least-privilege database credentials, and test accounts/data before deployment.
- The local PHP development server is not production hosting. Do not deploy with debug display enabled or use sample secrets.
- Do not commit `.env`, private uploads, API credentials, resident IDs, or incident evidence.
