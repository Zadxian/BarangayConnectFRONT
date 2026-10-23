<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
require_once APP_DIR . 'controllers/BaseController.php';

class PageController extends BaseController
{
    public function home()
    {
        $this->render('pages/home', ['title' => 'Home']);
    }

    public function services()
    {
        $services = $this->db()->raw(
            'SELECT name, description, fee, requirements FROM service_types WHERE is_active = 1 ORDER BY name'
        )->fetchAll(PDO::FETCH_ASSOC);
        $this->render('pages/services', ['title' => 'Services', 'services' => $services]);
    }

    public function about()
    {
        $this->render('pages/message', [
            'title' => 'About BarangayConnect',
            'message' => 'BarangayConnect provides a convenient way to request barangay documents, track applications, and submit incident reports.',
        ]);
    }

    public function login()
    {
        $this->render('pages/form', [
            'title' => 'Login',
            'intro' => 'Sign in to continue to your resident or staff account.',
            'action' => 'login',
            'google_oauth_enabled' => (string)getenv('GOOGLE_OAUTH_CLIENT_ID') !== '' && (string)getenv('GOOGLE_OAUTH_CLIENT_SECRET') !== '',
            'button' => 'Login',
            'fields' => [
                ['Email', 'email', 'email'],
                ['Password', 'password', 'password'],
            ],
            'footer' => ['No account yet?', 'Register here', 'register'],
        ]);
    }

    public function google_login()
    {
        $client_id = (string)getenv('GOOGLE_OAUTH_CLIENT_ID');
        if ($client_id === '' || (string)getenv('GOOGLE_OAUTH_CLIENT_SECRET') === '') {
            $this->finish_post('login', 'error', 'Google sign-in is not configured.');
        }
        $state = bin2hex(random_bytes(32));
        $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $_SESSION['google_oauth_state'] = $state;
        $_SESSION['google_oauth_verifier'] = $verifier;
        $_SESSION['google_oauth_started'] = time();
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $url = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
            'client_id' => $client_id,
            'redirect_uri' => site_url('auth/google/callback'),
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
            'prompt' => 'select_account',
        ]);
        header('Location: ' . $url, true, 302);
        exit;
    }

    public function google_callback()
    {
        $state = $_GET['state'] ?? '';
        $code = $_GET['code'] ?? '';
        $expected_state = $_SESSION['google_oauth_state'] ?? '';
        $verifier = $_SESSION['google_oauth_verifier'] ?? '';
        $started = (int)($_SESSION['google_oauth_started'] ?? 0);
        unset($_SESSION['google_oauth_state'], $_SESSION['google_oauth_verifier'], $_SESSION['google_oauth_started']);
        if (!is_string($state) || !is_string($code) || !is_string($expected_state) || $expected_state === '' || !hash_equals($expected_state, $state) || $started < time() - 600 || $code === '' || !is_string($verifier)) {
            $this->finish_post('login', 'error', 'Google sign-in expired or could not be verified. Please try again.');
        }

        $redirect_uri = site_url('auth/google/callback');
        $curl = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'code' => $code,
                'client_id' => (string)getenv('GOOGLE_OAUTH_CLIENT_ID'),
                'client_secret' => (string)getenv('GOOGLE_OAUTH_CLIENT_SECRET'),
                'redirect_uri' => $redirect_uri,
                'grant_type' => 'authorization_code',
                'code_verifier' => $verifier,
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 20,
        ]);
        $token_response = curl_exec($curl);
        $token_status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        $token_data = is_string($token_response) ? json_decode($token_response, true) : null;
        $id_token = is_array($token_data) ? ($token_data['id_token'] ?? '') : '';
        if ($token_status < 200 || $token_status >= 300 || !is_string($id_token) || $id_token === '') {
            error_log('Google OAuth token exchange failed. HTTP ' . $token_status);
            $this->finish_post('login', 'error', 'Google sign-in could not be completed.');
        }

        $curl = curl_init('https://oauth2.googleapis.com/tokeninfo?' . http_build_query(['id_token' => $id_token]));
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 20,
        ]);
        $profile_response = curl_exec($curl);
        $profile_status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        $profile = is_string($profile_response) ? json_decode($profile_response, true) : null;
        $verified = is_array($profile)
            && $profile_status >= 200 && $profile_status < 300
            && ($profile['aud'] ?? '') === (string)getenv('GOOGLE_OAUTH_CLIENT_ID')
            && in_array(($profile['iss'] ?? ''), ['accounts.google.com', 'https://accounts.google.com'], true)
            && filter_var($profile['email'] ?? '', FILTER_VALIDATE_EMAIL)
            && in_array(($profile['email_verified'] ?? ''), [true, 'true'], true)
            && (int)($profile['exp'] ?? 0) > time()
            && !empty($profile['sub']);
        if (!$verified) {
            error_log('Google OAuth identity verification failed.');
            $this->finish_post('login', 'error', 'Google sign-in could not verify your account.');
        }

        $google_id = (string)$profile['sub'];
        $email = strtolower((string)$profile['email']);
        $user = $this->db()->raw(
            'SELECT id, username, full_name, email, role, is_active, contact, address, sms_opt_in, google_id FROM users WHERE google_id = ? LIMIT 1',
            [$google_id]
        )->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            $user = $this->db()->raw(
                'SELECT id, username, full_name, email, role, is_active, contact, address, sms_opt_in, google_id FROM users WHERE email = ? LIMIT 1',
                [$email]
            )->fetch(PDO::FETCH_ASSOC);
            if ($user && !empty($user['google_id']) && !hash_equals((string)$user['google_id'], $google_id)) {
                $this->finish_post('login', 'error', 'This account is linked to another Google account. Contact an administrator.');
            }
            if ($user) {
                $this->db()->raw('UPDATE users SET google_id = ?, updated_at = NOW() WHERE id = ?', [$google_id, (int)$user['id']]);
                $user['google_id'] = $google_id;
            } else {
                $name = substr((string)($profile['name'] ?? $email), 0, 150);
                $base = preg_replace('/[^a-z0-9_]/', '', strtolower(strtok($email, '@')));
                $username = substr($base ?: 'resident', 0, 70) . '_' . bin2hex(random_bytes(4));
                $this->db()->raw(
                    'INSERT INTO users (username, full_name, email, password, role, is_active, google_id, auth_provider, created_at) VALUES (?, ?, ?, ?, ?, 1, ?, ?, NOW())',
                    [$username, $name, $email, password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT), 'user', $google_id, 'google']
                );
                $id = (int)$this->db()->raw('SELECT LAST_INSERT_ID()')->fetchColumn();
                $user = [
                    'id' => $id,
                    'username' => $username,
                    'full_name' => $name,
                    'email' => $email,
                    'role' => 'user',
                    'is_active' => 1,
                    'contact' => null,
                    'address' => null,
                    'sms_opt_in' => 0,
                    'google_id' => $google_id,
                ];
            }
        }
        if ((int)$user['is_active'] !== 1) {
            $this->finish_post('login', 'error', 'This account is inactive. Contact an administrator.');
        }
        session_regenerate_id(true);
        unset($user['google_id']);
        $_SESSION['user'] = $user;
        $this->audit((int)$user['id'], 'login', 'user', (int)$user['id'], 'Google OAuth');
        redirect($this->dashboard_for_role($user['role']));
        exit;
    }

    public function register()
    {
        $this->render('pages/form', [
            'title' => 'Resident Registration',
            'intro' => 'Create an account to request documents and file incident reports.',
            'action' => 'register',
            'button' => 'Create account',
            'fields' => [
                ['Full name', 'full_name', 'text'],
                ['Email', 'email', 'email'],
                ['Contact number', 'contact', 'tel'],
                ['Purok / Street', 'address', 'text'],
                ['I agree to receive request status updates by SMS', 'sms_opt_in', 'checkbox', null, false],
                ['Password (at least 12 characters)', 'password', 'password'],
            ],
            'footer' => ['Already registered?', 'Login', 'login'],
        ]);
    }

    public function setup()
    {
        $admin_exists = (int)$this->db()->raw("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn() > 0;
        $this->render('pages/setup', [
            'title' => 'Initial administrator setup',
            'admin_exists' => $admin_exists,
            'setup_enabled' => (string)getenv('ADMIN_SETUP_TOKEN') !== '',
        ]);
    }

    public function create_initial_admin()
    {
        $admin_exists = (int)$this->db()->raw("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn() > 0;
        $expected_token = (string)getenv('ADMIN_SETUP_TOKEN');
        $provided_token = $this->post_string('setup_token', 256);
        if ($admin_exists || $expected_token === '' || !hash_equals($expected_token, $provided_token)) {
            $this->finish_post('setup', 'error', 'Administrator setup is disabled or the setup token is invalid.');
        }

        $name = $this->post_string('full_name', 150);
        $email = strtolower($this->post_string('email', 255));
        $password = $_POST['password'] ?? '';
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || !is_string($password) || strlen($password) < 12 || strlen($password) > 72) {
            $this->finish_post('setup', 'error', 'Enter a name, valid email, and password between 12 and 72 characters.');
        }
        if ($this->db()->raw('SELECT id FROM users WHERE email = ? LIMIT 1', [$email])->fetch(PDO::FETCH_ASSOC)) {
            $this->finish_post('setup', 'error', 'An account with that email already exists.');
        }

        $auth_provider = 'local';
        $stored_password = password_hash($password, PASSWORD_DEFAULT);
        if ((string)getenv('FIREBASE_API_KEY') !== '') {
            $identity = $this->firebase_identity('signUp', $email, $password);
            if (!$identity || empty($identity['ok'])) {
                $this->finish_post('setup', 'error', 'Firebase could not create this account. Verify Firebase email/password sign-in is enabled.');
            }
            $auth_provider = 'firebase';
            $stored_password = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
        }
        $base = preg_replace('/[^a-z0-9_]/', '', strtolower(strtok($email, '@')));
        $username = substr($base ?: 'admin', 0, 70) . '_' . bin2hex(random_bytes(4));
        $this->db()->raw(
            'INSERT INTO users (username, full_name, email, password, role, is_active, auth_provider, created_at) VALUES (?, ?, ?, ?, ?, 1, ?, NOW())',
            [$username, $name, $email, $stored_password, 'admin', $auth_provider]
        );
        $id = (int)$this->db()->raw('SELECT LAST_INSERT_ID()')->fetchColumn();
        $this->audit($id, 'create_initial_admin', 'user', $id);
        $this->finish_post('login', 'success', 'Initial administrator created. Sign in with your new account.');
    }

    public function authenticate()
    {
        $email = strtolower($this->post_string('email', 255));
        $password = $_POST['password'] ?? '';
        $user = $this->db()->raw(
            'SELECT id, username, full_name, email, password, role, is_active, contact, address, sms_opt_in, auth_provider FROM users WHERE email = ? LIMIT 1',
            [$email]
        )->fetch(PDO::FETCH_ASSOC);
        $valid_password = false;
        if ($user && is_string($password)) {
            if (($user['auth_provider'] ?? 'local') === 'firebase') {
                $identity = $this->firebase_identity('signInWithPassword', $email, $password);
                $valid_password = is_array($identity) && !empty($identity['ok']);
            } else {
                $valid_password = password_verify($password, $user['password']);
            }
        }
        if (!$user || (int)$user['is_active'] !== 1 || !$valid_password) {
            $this->finish_post('login', 'error', 'Email or password is incorrect.');
        }

        session_regenerate_id(true);
        unset($user['password']);
        $_SESSION['user'] = $user;
        $this->db()->raw('UPDATE users SET updated_at = NOW() WHERE id = ?', [(int)$user['id']]);
        $this->db()->raw(
            'INSERT INTO audit_logs (actor_id, action, entity, entity_id, details) VALUES (?, ?, ?, ?, ?)',
            [(int)$user['id'], 'login', 'user', (int)$user['id'], 'Successful password login']
        );
        redirect($this->dashboard_for_role($user['role']));
        exit;
    }

    public function create_account()
    {
        $name = $this->post_string('full_name', 150);
        $email = strtolower($this->post_string('email', 255));
        $contact = $this->post_string('contact', 30);
        $address = $this->post_string('address', 255);
        $sms_opt_in = isset($_POST['sms_opt_in']) && $_POST['sms_opt_in'] === '1' ? 1 : 0;
        $password = $_POST['password'] ?? '';

        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || !is_string($password) || strlen($password) < 12 || strlen($password) > 72) {
            $this->finish_post('register', 'error', 'Enter your name and a valid email, and use a password from 12 to 72 characters.');
        }
        if ($this->db()->raw('SELECT id FROM users WHERE email = ? LIMIT 1', [$email])->fetch(PDO::FETCH_ASSOC)) {
            $this->finish_post('register', 'error', 'An account with that email already exists.');
        }

        $auth_provider = 'local';
        $stored_password = password_hash($password, PASSWORD_DEFAULT);
        if ((string)getenv('FIREBASE_API_KEY') !== '') {
            $identity = $this->firebase_identity('signUp', $email, $password);
            if (!$identity || empty($identity['ok'])) {
                $message = ($identity['error'] ?? '') === 'EMAIL_EXISTS'
                    ? 'That email already has a Firebase account. Contact the barangay administrator.'
                    : 'Firebase could not create your account. Please try again later.';
                $this->finish_post('register', 'error', $message);
            }
            $auth_provider = 'firebase';
            $stored_password = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
        }
        $base = preg_replace('/[^a-z0-9_]/', '', strtolower(strtok($email, '@')));
        $username = substr($base ?: 'resident', 0, 70) . '_' . bin2hex(random_bytes(4));
        $this->db()->raw(
            'INSERT INTO users (username, full_name, email, password, role, is_active, contact, address, sms_opt_in, auth_provider, created_at) VALUES (?, ?, ?, ?, ?, 1, ?, ?, ?, ?, NOW())',
            [$username, $name, $email, $stored_password, 'user', $contact, $address, $sms_opt_in, $auth_provider]
        );
        $id = (int)$this->db()->raw('SELECT LAST_INSERT_ID()')->fetchColumn();

        session_regenerate_id(true);
        $_SESSION['user'] = [
            'id' => $id,
            'username' => $username,
            'full_name' => $name,
            'email' => $email,
            'role' => 'user',
            'is_active' => 1,
            'contact' => $contact,
            'address' => $address,
            'sms_opt_in' => $sms_opt_in,
        ];
        $this->db()->raw(
            'INSERT INTO audit_logs (actor_id, action, entity, entity_id, details) VALUES (?, ?, ?, ?, ?)',
            [$id, 'register', 'user', $id, 'Resident account created']
        );
        $this->finish_post('resident', 'success', 'Your account is ready. Welcome to BarangayConnect.');
    }

    public function logout()
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            if (ini_get('session.use_cookies')) {
                $params = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
            }
            session_destroy();
        }
        redirect('');
        exit;
    }

    public function track()
    {
        $this->render('pages/form', [
            'title' => 'Track a Request or Incident',
            'intro' => 'Enter the reference number shown when you submitted your request or report.',
            'action' => 'track',
            'button' => 'Track',
            'fields' => [['Reference number', 'reference_no', 'text']],
        ]);
    }

    public function track_result()
    {
        $reference = strtoupper($this->post_string('reference_no', 32));
        $result = null;
        if (preg_match('/^[A-Z0-9-]{8,32}$/', $reference)) {
            $result = $this->db()->raw(
                "SELECT reference_no, 'Document request' AS record_type, status, created_at FROM document_requests WHERE reference_no = ?
                 UNION ALL
                 SELECT reference_no, 'Incident report' AS record_type, status, created_at FROM incidents WHERE reference_no = ?
                 LIMIT 1",
                [$reference, $reference]
            )->fetch(PDO::FETCH_ASSOC);
        }
        $this->render('pages/track_result', ['title' => 'Tracking result', 'result' => $result]);
    }

    private function dashboard_for_role($role)
    {
        if ($role === 'admin') {
            return 'admin/users';
        }
        return $role === 'moderator' ? 'staff' : 'resident';
    }
}
