<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
require_once APP_DIR . 'controllers/BaseController.php';

class ApiController extends BaseController
{
    private $api;

    public function __construct()
    {
        parent::__construct();
        $this->api = $this->call->library('api');
    }

    private function respond($status, $payload)
    {
        header('Cache-Control: no-store');
        $this->api->respond($payload, $status);
    }

    private function success($data = [], $message = null)
    {
        $payload = ['data' => $data];
        if ($message !== null) {
            $payload['message'] = $message;
        }
        $this->respond(200, $payload);
    }

    private function failure($status, $message)
    {
        $this->respond($status, ['error' => $message]);
    }

    private function value($key, $max = 1000)
    {
        $value = $_POST[$key] ?? '';
        return is_string($value) ? trim(substr($value, 0, $max)) : '';
    }

    private function api_user($roles = [])
    {
        $session_user = $_SESSION['user'] ?? null;
        if (!$session_user || empty($session_user['id'])) {
            $this->failure(401, 'Please sign in to continue.');
        }
        $user = $this->db()->table('users')
            ->select('id, username, email, role, is_active, full_name, contact, address, sms_opt_in')
            ->where('id', (int)$session_user['id'])
            ->limit(1)
            ->get();
        if (!$user || (int)$user['is_active'] !== 1) {
            unset($_SESSION['user']);
            $this->failure(401, 'Your account is unavailable. Please contact an administrator.');
        }
        if ($roles && !in_array($user['role'], $roles, true)) {
            $this->failure(403, 'Your account does not have permission to perform this action.');
        }
        $_SESSION['user'] = $user;
        return $user;
    }

    private function public_user($user)
    {
        unset($user['password'], $user['auth_provider'], $user['google_id']);
        return $user;
    }

    private function account_values($name, $email, $password)
    {
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || !is_string($password) || strlen($password) < 12 || strlen($password) > 72) {
            $this->failure(422, 'Enter a name, a valid email, and a password from 12 to 72 characters.');
        }
        if ($this->db()->table('users')->select('id')->where('email', $email)->limit(1)->get()) {
            $this->failure(409, 'An account with that email already exists.');
        }
    }

    public function csrf()
    {
        $this->success([
            'name' => $this->security->get_csrf_token_name(),
            'token' => $this->security->get_csrf_hash(),
        ]);
    }

    public function me()
    {
        if (empty($_SESSION['user']['id'])) {
            $this->success(['user' => null]);
        }
        $this->success(['user' => $this->public_user($this->api_user())]);
    }

    public function public_data()
    {
        $services = $this->db()->table('service_types')
            ->select('id, name, description, fee, requirements')
            ->where('is_active', 1)
            ->order_by('name', 'ASC')
            ->get_all();
        $settings = $this->db()->table('app_settings')
            ->select('setting_key, setting_value')
            ->get_all(PDO::FETCH_KEY_PAIR);
        $this->success([
            'services' => $services,
            'barangay' => [
                'name' => $settings['barangay_name'] ?? 'BarangayConnect',
                'office_hours' => $settings['office_hours'] ?? '',
                'office_contact' => $settings['office_contact'] ?? '',
            ],
        ]);
    }

    public function incident_types()
    {
        $types = $this->db()->table('incident_types')
            ->select('id, name')
            ->where('is_active', 1)
            ->order_by('name', 'ASC')
            ->get_all();
        $this->success(['types' => $types]);
    }

    public function login()
    {
        $email = strtolower($this->value('email', 255));
        $password = $_POST['password'] ?? '';
        $user = $this->db()->table('users')
            ->select('id, username, full_name, email, password, role, is_active, contact, address, sms_opt_in, auth_provider')
            ->where('email', $email)
            ->limit(1)
            ->get();
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
            $this->failure(401, 'Email or password is incorrect.');
        }
        session_regenerate_id(true);
        unset($user['password'], $user['auth_provider']);
        $_SESSION['user'] = $user;
        $this->db()->table('users')
            ->where('id', (int)$user['id'])
            ->update(['updated_at' => date('Y-m-d H:i:s')]);
        $this->audit((int)$user['id'], 'login', 'user', (int)$user['id'], 'Successful password login');
        $this->success(['user' => $user], 'Signed in successfully.');
    }

    public function register()
    {
        $name = $this->value('full_name', 150);
        $email = strtolower($this->value('email', 255));
        $contact = $this->value('contact', 30);
        $address = $this->value('address', 255);
        $password = $_POST['password'] ?? '';
        $sms_opt_in = isset($_POST['sms_opt_in']) && $_POST['sms_opt_in'] === '1' ? 1 : 0;
        $this->account_values($name, $email, $password);

        $auth_provider = 'local';
        $stored_password = password_hash($password, PASSWORD_DEFAULT);
        if ((string)getenv('FIREBASE_API_KEY') !== '') {
            $identity = $this->firebase_identity('signUp', $email, $password);
            if (!$identity || empty($identity['ok'])) {
                $this->failure(422, 'Firebase could not create the account. Verify Firebase email/password sign-in is enabled.');
            }
            $auth_provider = 'firebase';
            $stored_password = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
        }
        $base = preg_replace('/[^a-z0-9_]/', '', strtolower(strtok($email, '@')));
        $username = substr($base ?: 'resident', 0, 70) . '_' . bin2hex(random_bytes(4));
        $id = $this->db()->table('users')->insert([
            'username' => $username,
            'full_name' => $name,
            'email' => $email,
            'password' => $stored_password,
            'role' => 'user',
            'is_active' => 1,
            'contact' => $contact,
            'address' => $address,
            'sms_opt_in' => $sms_opt_in,
            'auth_provider' => $auth_provider,
        ]);
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
        $this->audit($id, 'register', 'user', $id, 'Resident account created');
        $this->success(['user' => $_SESSION['user']], 'Your account is ready.');
    }

    public function setup()
    {
        $admin_exists = $this->db()->table('users')
            ->select_count('id', 'total')
            ->where('role', 'admin')
            ->get();
        $admin_exists = (int)($admin_exists['total'] ?? 0) > 0;
        $expected_token = (string)getenv('ADMIN_SETUP_TOKEN');
        $provided_token = $this->value('setup_token', 256);
        if ($admin_exists || $expected_token === '' || !hash_equals($expected_token, $provided_token)) {
            $this->failure(403, 'Administrator setup is disabled or the setup token is invalid.');
        }
        $name = $this->value('full_name', 150);
        $email = strtolower($this->value('email', 255));
        $password = $_POST['password'] ?? '';
        $this->account_values($name, $email, $password);
        $auth_provider = 'local';
        $stored_password = password_hash($password, PASSWORD_DEFAULT);
        if ((string)getenv('FIREBASE_API_KEY') !== '') {
            $identity = $this->firebase_identity('signUp', $email, $password);
            if (!$identity || empty($identity['ok'])) {
                $this->failure(422, 'Firebase could not create the account. Verify Firebase email/password sign-in is enabled.');
            }
            $auth_provider = 'firebase';
            $stored_password = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
        }
        $base = preg_replace('/[^a-z0-9_]/', '', strtolower(strtok($email, '@')));
        $username = substr($base ?: 'admin', 0, 70) . '_' . bin2hex(random_bytes(4));
        $id = $this->db()->table('users')->insert([
            'username' => $username,
            'full_name' => $name,
            'email' => $email,
            'password' => $stored_password,
            'role' => 'admin',
            'is_active' => 1,
            'auth_provider' => $auth_provider,
        ]);
        $this->audit($id, 'create_initial_admin', 'user', $id);
        $this->success([], 'Administrator created. Sign in with the new account.');
    }

    public function logout()
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
        $this->success([], 'Signed out.');
    }

    public function track()
    {
        $reference = strtoupper(trim(substr((string)($_GET['reference_no'] ?? ''), 0, 32)));
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
        $this->success(['result' => $result]);
    }

    public function resident_dashboard()
    {
        $user = $this->api_user(['user']);
        $counts = $this->db()->raw(
            "SELECT SUM(status IN ('Submitted','Under Review','Processing')) AS pending_requests,
                    SUM(status = 'Ready for Pickup') AS ready_requests
             FROM document_requests WHERE user_id = ?",
            [(int)$user['id']]
        )->fetch(PDO::FETCH_ASSOC);
        $incidents = $this->db()->raw('SELECT COUNT(*) FROM incidents WHERE reporter_id = ?', [(int)$user['id']])->fetchColumn();
        $notifications = $this->db()->table('notifications')
            ->select('message, channel, status, created_at')
            ->where('user_id', (int)$user['id'])
            ->order_by('created_at', 'DESC')
            ->limit(5)
            ->get_all();
        $this->success([
            'pending_requests' => (int)($counts['pending_requests'] ?? 0),
            'ready_requests' => (int)($counts['ready_requests'] ?? 0),
            'incidents' => (int)$incidents,
            'notifications' => $notifications,
        ]);
    }

    public function resident_requests()
    {
        $user = $this->api_user(['user']);
        $requests = $this->db()->table('document_requests r')
            ->select('r.id, r.reference_no, s.name AS service_name, r.status, r.payment_status, r.purpose, r.staff_remarks, r.created_at, s.fee')
            ->join('service_types s', 's.id = r.service_type_id')
            ->where('r.user_id', (int)$user['id'])
            ->order_by('r.created_at', 'DESC')
            ->limit(100)
            ->get_all();
        $services = $this->db()->table('service_types')
            ->select('id, name, fee, requirements')
            ->where('is_active', 1)
            ->order_by('name', 'ASC')
            ->get_all();
        $this->success(['requests' => $requests, 'services' => $services]);
    }

    public function create_request()
    {
        $user = $this->api_user(['user']);
        $service_id = filter_var($_POST['service_type_id'] ?? null, FILTER_VALIDATE_INT);
        $purpose = $this->value('purpose', 500);
        $service = $service_id ? $this->db()->table('service_types')
            ->select('id, fee')
            ->where(['id' => $service_id, 'is_active' => 1])
            ->limit(1)
            ->get() : null;
        if (!$service || $purpose === '') {
            $this->failure(422, 'Choose an available document type and enter its purpose.');
        }
        try {
            $id_path = $this->upload_private_file('valid_id', 'ids', ['application/pdf', 'image/jpeg', 'image/png']);
        } catch (RuntimeException $error) {
            $this->failure(422, $error->getMessage());
        }
        if (!$id_path) {
            $this->failure(422, 'Upload a valid ID to submit this request.');
        }
        $reference = 'BC-' . strtoupper(bin2hex(random_bytes(14)));
        $request_id = $this->db()->table('document_requests')->insert([
            'reference_no' => $reference,
            'user_id' => (int)$user['id'],
            'service_type_id' => (int)$service['id'],
            'purpose' => $purpose,
            'valid_id_path' => $id_path,
            'status' => 'Submitted',
            'payment_status' => (float)$service['fee'] > 0 ? 'Unpaid' : 'Paid',
        ]);
        $this->notify_user((int)$user['id'], 'Your document request ' . $reference . ' was submitted.');
        $this->audit((int)$user['id'], 'create', 'document_request', $request_id, $reference);
        $this->success(['reference_no' => $reference], 'Request submitted.');
    }

    public function create_payment($id)
    {
        $user = $this->api_user(['user']);
        $secret = (string)getenv('PAYMONGO_SECRET_KEY');
        if ($secret === '') {
            $this->failure(503, 'Online payment is not configured. Please contact the barangay office to pay in person.');
        }
        $request = $this->db()->table('document_requests r')
            ->select('r.id, r.reference_no, r.status, r.payment_status, s.name, s.fee')
            ->join('service_types s', 's.id = r.service_type_id')
            ->where(['r.id' => (int)$id, 'r.user_id' => (int)$user['id']])
            ->limit(1)
            ->get();
        if (!$request || $request['payment_status'] === 'Paid' || $request['status'] === 'Rejected' || (float)$request['fee'] <= 0) {
            $this->failure(422, 'This request cannot be paid online.');
        }
        $existing = $this->db()->table('payments')
            ->select('checkout_url, status')
            ->where(['request_id' => (int)$request['id'], 'user_id' => (int)$user['id'], 'provider' => 'paymongo'])
            ->order_by('id', 'DESC')
            ->limit(1)
            ->get();
        if ($existing && $existing['status'] === 'Pending' && !empty($existing['checkout_url'])) {
            $this->success(['checkout_url' => $existing['checkout_url']]);
        }
        $success_url = rtrim((string)getenv('APP_URL'), '/') . '/resident/requests';
        if ($success_url === '/resident/requests') {
            $success_url = 'http://127.0.0.1:8000/resident/requests';
        }
        $payload = [
            'data' => [
                'attributes' => [
                    'send_email_receipt' => false,
                    'show_description' => true,
                    'show_line_items' => true,
                    'description' => 'Barangay document request ' . $request['reference_no'],
                    'line_items' => [[
                        'currency' => 'PHP',
                        'amount' => (int)round((float)$request['fee'] * 100),
                        'name' => $request['name'],
                        'quantity' => 1,
                    ]],
                    'payment_method_types' => ['gcash', 'paymaya', 'card'],
                    'success_url' => $success_url,
                    'cancel_url' => $success_url,
                    'metadata' => [
                        'request_id' => (string)$request['id'],
                        'reference_no' => $request['reference_no'],
                    ],
                ],
            ],
        ];
        $curl = curl_init('https://api.paymongo.com/v1/checkout_sessions');
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR),
            CURLOPT_HTTPHEADER => [
                'Authorization: Basic ' . base64_encode($secret . ':'),
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 20,
        ]);
        $response = curl_exec($curl);
        $status_code = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($curl);
        curl_close($curl);
        $decoded = is_string($response) ? json_decode($response, true) : null;
        $session_id = $decoded['data']['id'] ?? '';
        $checkout_url = $decoded['data']['attributes']['checkout_url'] ?? '';
        if ($response === false || $status_code < 200 || $status_code >= 300 || !is_string($session_id) || !is_string($checkout_url) || $session_id === '' || $checkout_url === '') {
            error_log('PayMongo checkout creation failed. HTTP ' . $status_code . ($curl_error !== '' ? ': transport error' : ''));
            $this->failure(502, 'The payment provider could not create a checkout session. Please try again or pay in person.');
        }
        if (parse_url($checkout_url, PHP_URL_SCHEME) !== 'https' || parse_url($checkout_url, PHP_URL_HOST) !== 'checkout.paymongo.com') {
            error_log('PayMongo returned an unexpected checkout URL host.');
            $this->failure(502, 'The payment provider returned an invalid checkout address.');
        }
        $this->db()->table('payments')->insert([
            'request_id' => (int)$request['id'],
            'user_id' => (int)$user['id'],
            'provider' => 'paymongo',
            'amount' => (float)$request['fee'],
            'status' => 'Pending',
            'provider_reference' => $session_id,
            'checkout_url' => $checkout_url,
        ]);
        $this->success(['checkout_url' => $checkout_url]);
    }

    public function resident_incidents()
    {
        $user = $this->api_user(['user']);
        $incidents = $this->db()->raw(
            'SELECT i.reference_no, t.name AS incident_type, i.incident_date, i.location, i.status, i.staff_remarks, i.created_at,
                    (SELECT MIN(h.scheduled_at) FROM hearings h WHERE h.incident_id = i.id AND h.status = ?) AS next_hearing
             FROM incidents i JOIN incident_types t ON t.id = i.incident_type_id
             WHERE i.reporter_id = ? ORDER BY i.created_at DESC LIMIT 100',
            ['Scheduled', (int)$user['id']]
        )->fetchAll(PDO::FETCH_ASSOC);
        $types = $this->db()->table('incident_types')
            ->select('id, name')
            ->where('is_active', 1)
            ->order_by('name', 'ASC')
            ->get_all();
        $this->success(['incidents' => $incidents, 'types' => $types]);
    }

    public function create_incident()
    {
        $user = $this->api_user(['user']);
        $type_id = filter_var($_POST['incident_type_id'] ?? null, FILTER_VALIDATE_INT);
        $date = $this->value('incident_date', 10);
        $location = $this->value('location', 255);
        $description = $this->value('description', 5000);
        $valid_date = DateTime::createFromFormat('!Y-m-d', $date);
        $type = $type_id ? $this->db()->table('incident_types')
            ->select('id')
            ->where(['id' => $type_id, 'is_active' => 1])
            ->get() : null;
        if (!$type || !$valid_date || $valid_date->format('Y-m-d') !== $date || $date > date('Y-m-d') || $location === '' || $description === '') {
            $this->failure(422, 'Complete the incident type, valid date, location, and description.');
        }
        $latitude = $this->value('latitude', 20);
        $longitude = $this->value('longitude', 20);
        if (($latitude !== '' && (!is_numeric($latitude) || (float)$latitude < -90 || (float)$latitude > 90))
            || ($longitude !== '' && (!is_numeric($longitude) || (float)$longitude < -180 || (float)$longitude > 180))
            || (($latitude === '') !== ($longitude === ''))) {
            $this->failure(422, 'Enter both latitude and longitude as valid coordinates.');
        }
        try {
            $evidence_path = $this->upload_private_file('evidence', 'evidence', ['application/pdf', 'image/jpeg', 'image/png']);
        } catch (RuntimeException $error) {
            $this->failure(422, $error->getMessage());
        }
        $reference = 'BC-' . strtoupper(bin2hex(random_bytes(14)));
        $id = $this->db()->table('incidents')->insert([
            'reference_no' => $reference,
            'reporter_id' => (int)$user['id'],
            'incident_type_id' => (int)$type['id'],
            'incident_date' => $date,
            'location' => $location,
            'latitude' => $latitude === '' ? null : (float)$latitude,
            'longitude' => $longitude === '' ? null : (float)$longitude,
            'description' => $description,
            'evidence_path' => $evidence_path,
            'status' => 'Submitted',
        ]);
        $this->notify_user((int)$user['id'], 'Your incident report ' . $reference . ' was submitted.');
        $this->audit((int)$user['id'], 'create', 'incident', $id, $reference);
        $this->success(['reference_no' => $reference], 'Incident report submitted.');
    }

    public function resident_profile()
    {
        $user = $this->api_user(['user']);
        $this->success(['user' => $this->public_user($user)]);
    }

    public function update_profile()
    {
        $user = $this->api_user(['user']);
        $name = $this->value('full_name', 150);
        $contact = $this->value('contact', 30);
        $address = $this->value('address', 255);
        $sms_opt_in = isset($_POST['sms_opt_in']) && $_POST['sms_opt_in'] === '1' ? 1 : 0;
        if ($name === '' || $contact === '' || $address === '') {
            $this->failure(422, 'Name, contact number, and address are required.');
        }
        $this->db()->table('users')
            ->where('id', (int)$user['id'])
            ->update([
                'full_name' => $name,
                'contact' => $contact,
                'address' => $address,
                'sms_opt_in' => $sms_opt_in,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        $_SESSION['user']['full_name'] = $name;
        $_SESSION['user']['contact'] = $contact;
        $_SESSION['user']['address'] = $address;
        $_SESSION['user']['sms_opt_in'] = $sms_opt_in;
        $this->audit((int)$user['id'], 'update_profile', 'user', (int)$user['id']);
        $this->success([], 'Profile updated.');
    }

    public function staff_dashboard()
    {
        $this->api_user(['moderator', 'admin']);
        $this->success([
            'requests' => (int)$this->db()->raw("SELECT COUNT(*) FROM document_requests WHERE status IN ('Submitted','Under Review')")->fetchColumn(),
            'incidents' => (int)$this->db()->raw("SELECT COUNT(*) FROM incidents WHERE status NOT IN ('Resolved','Dismissed')")->fetchColumn(),
            'hearings' => (int)$this->db()->raw("SELECT COUNT(*) FROM hearings WHERE status = 'Scheduled' AND scheduled_at >= NOW()")->fetchColumn(),
        ]);
    }

    public function staff_requests()
    {
        $this->api_user(['moderator', 'admin']);
        $rows = $this->db()->raw(
            'SELECT r.id, r.reference_no, r.purpose, r.status, r.payment_status, r.staff_remarks, r.created_at,
                    r.valid_id_path, u.full_name, u.email, u.contact, s.name AS service_name, s.fee
             FROM document_requests r JOIN users u ON u.id = r.user_id
             JOIN service_types s ON s.id = r.service_type_id
             ORDER BY r.created_at DESC LIMIT 200'
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['valid_id_url'] = $row['valid_id_path'] ? site_url('staff/files/' . $row['valid_id_path']) : null;
            unset($row['valid_id_path']);
        }
        unset($row);
        $this->success(['requests' => $rows]);
    }

    public function update_request($id)
    {
        $staff = $this->api_user(['moderator', 'admin']);
        $request = $this->db()->raw(
            'SELECT r.*, s.fee FROM document_requests r JOIN service_types s ON s.id = r.service_type_id WHERE r.id = ? LIMIT 1',
            [(int)$id]
        )->fetch(PDO::FETCH_ASSOC);
        if (!$request) {
            $this->failure(404, 'Request not found.');
        }
        $status = $this->value('status', 30);
        if (!in_array($status, ['Under Review', 'Approved', 'Rejected', 'Ready for Pickup', 'Released'], true)) {
            $this->failure(422, 'Choose a valid request status.');
        }
        $payment_status = $request['payment_status'];
        if ($this->value('payment_action', 10) === 'paid' && $payment_status !== 'Paid') {
            $payment_status = 'Paid';
            $this->db()->raw(
                'INSERT INTO payments (request_id, user_id, provider, amount, status, paid_at) VALUES (?, ?, ?, ?, ?, NOW())',
                [(int)$request['id'], (int)$request['user_id'], 'in-person', (float)$request['fee'], 'Paid']
            );
        }
        if (in_array($status, ['Ready for Pickup', 'Released'], true) && (float)$request['fee'] > 0 && $payment_status !== 'Paid') {
            $this->failure(422, 'Record payment before marking a fee-based request ready or released.');
        }
        $remarks = $this->value('staff_remarks', 2000);
        $this->db()->raw(
            'UPDATE document_requests SET status = ?, payment_status = ?, staff_remarks = ?, reviewed_by = ?, reviewed_at = NOW(), updated_at = NOW() WHERE id = ?',
            [$status, $payment_status, $remarks, (int)$staff['id'], (int)$request['id']]
        );
        $this->notify_user((int)$request['user_id'], 'Request ' . $request['reference_no'] . ' status updated to ' . $status . '.');
        $this->audit((int)$staff['id'], 'update_status', 'document_request', (int)$request['id'], $status);
        $this->success([], 'Request updated.');
    }

    public function staff_incidents()
    {
        $this->api_user(['moderator', 'admin']);
        $rows = $this->db()->raw(
            'SELECT i.id, i.reference_no, i.incident_date, i.location, i.latitude, i.longitude, i.description, i.status, i.staff_remarks,
                    i.evidence_path, i.created_at, t.name AS incident_type, u.full_name AS reporter, u.contact,
                    (SELECT MIN(h.scheduled_at) FROM hearings h WHERE h.incident_id = i.id AND h.status = ?) AS next_hearing
             FROM incidents i JOIN incident_types t ON t.id = i.incident_type_id
             JOIN users u ON u.id = i.reporter_id
             ORDER BY i.created_at DESC LIMIT 200',
            ['Scheduled']
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['evidence_url'] = $row['evidence_path'] ? site_url('staff/files/' . $row['evidence_path']) : null;
            unset($row['evidence_path']);
        }
        unset($row);
        $this->success(['incidents' => $rows]);
    }

    public function update_incident($id)
    {
        $staff = $this->api_user(['moderator', 'admin']);
        $incident = $this->db()->table('incidents')
            ->select('id, reference_no, reporter_id')
            ->where('id', (int)$id)
            ->limit(1)
            ->get();
        if (!$incident) {
            $this->failure(404, 'Incident report not found.');
        }
        $status = $this->value('status', 30);
        if (!in_array($status, ['Under Review', 'Hearing Scheduled', 'Resolved', 'Dismissed'], true)) {
            $this->failure(422, 'Choose a valid incident status.');
        }
        $remarks = $this->value('staff_remarks', 2000);
        $this->db()->table('incidents')
            ->where('id', (int)$incident['id'])
            ->update([
                'status' => $status,
                'staff_remarks' => $remarks,
                'assigned_to' => (int)$staff['id'],
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        $this->notify_user((int)$incident['reporter_id'], 'Incident ' . $incident['reference_no'] . ' status updated to ' . $status . '.');
        $this->audit((int)$staff['id'], 'update_status', 'incident', (int)$incident['id'], $status);
        $this->success([], 'Incident case updated.');
    }

    public function staff_hearings()
    {
        $this->api_user(['moderator', 'admin']);
        $incidents = $this->db()->raw(
            "SELECT i.id, i.reference_no, u.full_name, t.name AS incident_type
             FROM incidents i JOIN users u ON u.id = i.reporter_id
             JOIN incident_types t ON t.id = i.incident_type_id
             WHERE i.status NOT IN ('Resolved','Dismissed') ORDER BY i.created_at DESC"
        )->fetchAll(PDO::FETCH_ASSOC);
        $hearings = $this->db()->raw(
            'SELECT h.id, h.incident_id, h.scheduled_at, h.location, h.notes, h.outcome, h.status, i.reference_no, u.full_name
             FROM hearings h JOIN incidents i ON i.id = h.incident_id JOIN users u ON u.id = i.reporter_id
             ORDER BY h.scheduled_at DESC LIMIT 200'
        )->fetchAll(PDO::FETCH_ASSOC);
        $this->success(['incidents' => $incidents, 'hearings' => $hearings]);
    }

    public function create_hearing()
    {
        $staff = $this->api_user(['moderator', 'admin']);
        $incident_id = filter_var($_POST['incident_id'] ?? null, FILTER_VALIDATE_INT);
        $scheduled = $this->value('scheduled_at', 16);
        $location = $this->value('location', 255);
        $notes = $this->value('notes', 2000);
        $date = DateTime::createFromFormat('!Y-m-d\TH:i', $scheduled);
        $incident = $incident_id ? $this->db()->table('incidents')
            ->select('id, reporter_id, reference_no')
            ->where('id', $incident_id)
            ->limit(1)
            ->get() : null;
        if (!$incident || !$date || $date->format('Y-m-d\TH:i') !== $scheduled || $date <= new DateTime() || $location === '') {
            $this->failure(422, 'Choose an open incident, a future hearing time, and a location.');
        }
        $hearing_id = $this->db()->table('hearings')->insert([
            'incident_id' => (int)$incident['id'],
            'scheduled_at' => $date->format('Y-m-d H:i:s'),
            'location' => $location,
            'notes' => $notes,
            'status' => 'Scheduled',
            'created_by' => (int)$staff['id'],
        ]);
        $this->db()->table('incidents')
            ->where('id', (int)$incident['id'])
            ->update([
                'status' => 'Hearing Scheduled',
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        $this->notify_user((int)$incident['reporter_id'], 'A mediation hearing was scheduled for incident ' . $incident['reference_no'] . ' at ' . $date->format('M j, Y g:i A') . '.');
        $this->audit((int)$staff['id'], 'schedule', 'hearing', $hearing_id, $incident['reference_no']);
        $this->success([], 'Hearing scheduled.');
    }

    public function update_hearing($id)
    {
        $staff = $this->api_user(['moderator', 'admin']);
        $hearing = $this->db()->table('hearings h')
            ->select('h.id, h.incident_id, i.reporter_id, i.reference_no')
            ->join('incidents i', 'i.id = h.incident_id')
            ->where('h.id', (int)$id)
            ->limit(1)
            ->get();
        if (!$hearing) {
            $this->failure(404, 'Hearing not found.');
        }
        $status = $this->value('status', 30);
        if (!in_array($status, ['Scheduled', 'Completed', 'Cancelled'], true)) {
            $this->failure(422, 'Choose a valid hearing status.');
        }
        $outcome = $this->value('outcome', 2000);
        $this->db()->table('hearings')
            ->where('id', (int)$hearing['id'])
            ->update(['status' => $status, 'outcome' => $outcome]);
        $this->notify_user((int)$hearing['reporter_id'], 'Hearing for incident ' . $hearing['reference_no'] . ' is now ' . strtolower($status) . '.');
        $this->audit((int)$staff['id'], 'update', 'hearing', (int)$hearing['id'], $status);
        $this->success([], 'Hearing updated.');
    }

    public function staff_residents()
    {
        $this->api_user(['moderator', 'admin']);
        $query = trim(substr((string)($_GET['q'] ?? ''), 0, 100));
        $like = '%' . $query . '%';
        $rows = $this->db()->raw(
            "SELECT id, full_name, email, contact, address, is_active, created_at FROM users
             WHERE role = 'user' AND (? = '' OR full_name LIKE ? OR email LIKE ? OR contact LIKE ?)
             ORDER BY created_at DESC LIMIT 200",
            [$query, $like, $like, $like]
        )->fetchAll(PDO::FETCH_ASSOC);
        $this->success(['residents' => $rows]);
    }

    public function staff_reports()
    {
        $this->api_user(['moderator', 'admin']);
        $this->success([
            'requests' => $this->db()->raw('SELECT status, COUNT(*) AS total FROM document_requests GROUP BY status ORDER BY status')->fetchAll(PDO::FETCH_ASSOC),
            'incidents' => $this->db()->raw(
                'SELECT t.name AS incident_type, COUNT(*) AS total FROM incidents i JOIN incident_types t ON t.id = i.incident_type_id GROUP BY t.id, t.name ORDER BY t.name'
            )->fetchAll(PDO::FETCH_ASSOC),
            'monthly' => $this->db()->raw(
                "SELECT DATE_FORMAT(created_at, '%Y-%m') AS month, COUNT(*) AS total
                 FROM document_requests WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
                 GROUP BY DATE_FORMAT(created_at, '%Y-%m') ORDER BY month"
            )->fetchAll(PDO::FETCH_ASSOC),
            'payments' => $this->db()->raw(
                'SELECT provider, status, COUNT(*) AS total, SUM(amount) AS amount FROM payments GROUP BY provider, status ORDER BY provider, status'
            )->fetchAll(PDO::FETCH_ASSOC),
        ]);
    }

    public function admin_users()
    {
        $this->api_user(['admin']);
        $users = $this->db()->table('users')
            ->select('id, username, full_name, email, role, is_active, created_at')
            ->order_by('created_at', 'DESC')
            ->limit(200)
            ->get_all();
        $this->success(['users' => $users]);
    }

    public function create_staff_user()
    {
        $admin = $this->api_user(['admin']);
        $name = $this->value('full_name', 150);
        $email = strtolower($this->value('email', 255));
        $role = $this->value('role', 20);
        $password = $_POST['password'] ?? '';
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || !in_array($role, ['moderator', 'admin'], true) || !is_string($password) || strlen($password) < 12 || strlen($password) > 72) {
            $this->failure(422, 'Provide a valid name and email, select a staff role, and use a password of 12 to 72 characters.');
        }
        if ($this->db()->table('users')->select('id')->where('email', $email)->limit(1)->get()) {
            $this->failure(409, 'An account with that email already exists.');
        }
        $auth_provider = 'local';
        $stored_password = password_hash($password, PASSWORD_DEFAULT);
        if ((string)getenv('FIREBASE_API_KEY') !== '') {
            $identity = $this->firebase_identity('signUp', $email, $password);
            if (!$identity || empty($identity['ok'])) {
                $this->failure(422, 'Firebase could not create this account. Verify Firebase email/password sign-in is enabled.');
            }
            $auth_provider = 'firebase';
            $stored_password = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
        }
        $base = preg_replace('/[^a-z0-9_]/', '', strtolower(strtok($email, '@')));
        $username = substr($base ?: 'staff', 0, 70) . '_' . bin2hex(random_bytes(4));
        $id = $this->db()->table('users')->insert([
            'username' => $username,
            'full_name' => $name,
            'email' => $email,
            'password' => $stored_password,
            'role' => $role,
            'is_active' => 1,
            'auth_provider' => $auth_provider,
        ]);
        $this->audit((int)$admin['id'], 'create', 'user', $id, $role);
        $this->success([], 'Staff account created.');
    }

    public function update_user_status($id)
    {
        $admin = $this->api_user(['admin']);
        $active = $this->value('is_active', 1);
        if (!in_array($active, ['0', '1'], true) || (int)$id === (int)$admin['id']) {
            $this->failure(422, 'Choose a valid status. You cannot deactivate your own account.');
        }
        $user = $this->db()->table('users')
            ->select('id')
            ->where('id', (int)$id)
            ->limit(1)
            ->get();
        if (!$user) {
            $this->failure(404, 'Account not found.');
        }
        $this->db()->table('users')
            ->where('id', (int)$id)
            ->update(['is_active' => (int)$active, 'updated_at' => date('Y-m-d H:i:s')]);
        $this->audit((int)$admin['id'], $active === '1' ? 'activate' : 'deactivate', 'user', (int)$id);
        $this->success([], 'Account status updated.');
    }

    public function admin_settings()
    {
        $this->api_user(['admin']);
        $services = $this->db()->table('service_types')
            ->select('id, name, description, fee, requirements, is_active')
            ->order_by('name', 'ASC')
            ->get_all();
        $types = $this->db()->table('incident_types')
            ->select('id, name, is_active')
            ->order_by('name', 'ASC')
            ->get_all();
        $settings = $this->db()->table('app_settings')
            ->select('setting_key, setting_value')
            ->get_all(PDO::FETCH_KEY_PAIR);
        $this->success([
            'services' => $services,
            'incident_types' => $types,
            'settings' => [
                'barangay_name' => $settings['barangay_name'] ?? '',
                'office_hours' => $settings['office_hours'] ?? '',
                'office_contact' => $settings['office_contact'] ?? '',
            ],
        ]);
    }

    public function save_settings()
    {
        $admin = $this->api_user(['admin']);
        $values = [
            'barangay_name' => $this->value('barangay_name', 150),
            'office_hours' => $this->value('office_hours', 255),
            'office_contact' => $this->value('office_contact', 60),
        ];
        foreach ($values as $key => $value) {
            $this->db()->raw(
                'INSERT INTO app_settings (setting_key, setting_value, updated_at) VALUES (?, ?, NOW())
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()',
                [$key, $value]
            );
        }
        $this->audit((int)$admin['id'], 'update_settings', 'app_settings', null, 'Barangay contact information');
        $this->success([], 'Barangay settings saved.');
    }

    public function save_service($id = null)
    {
        $admin = $this->api_user(['admin']);
        $name = $this->value('name', 120);
        $description = $this->value('description', 2000);
        $requirements = $this->value('requirements', 2000);
        $fee = filter_var($_POST['fee'] ?? null, FILTER_VALIDATE_FLOAT);
        $active = $this->value('is_active', 1);
        if ($name === '' || $fee === false || $fee < 0 || !in_array($active, ['0', '1'], true)) {
            $this->failure(422, 'Provide a name, non-negative fee, and valid active setting.');
        }
        if ($id === null) {
            if ($this->db()->raw('SELECT id FROM service_types WHERE name = ? LIMIT 1', [$name])->fetch(PDO::FETCH_ASSOC)) {
                $this->failure(409, 'A service with that name already exists.');
            }
            $this->db()->raw(
                'INSERT INTO service_types (name, description, fee, requirements, is_active) VALUES (?, ?, ?, ?, ?)',
                [$name, $description, $fee, $requirements, (int)$active]
            );
            $id = (int)$this->db()->raw('SELECT LAST_INSERT_ID()')->fetchColumn();
            $action = 'create';
        } else {
            $exists = $this->db()->table('service_types')
                ->select('id')
                ->where('id', (int)$id)
                ->limit(1)
                ->get();
            if (!$exists) {
                $this->failure(404, 'Document service not found.');
            }
            $this->db()->raw(
                'UPDATE service_types SET name = ?, description = ?, fee = ?, requirements = ?, is_active = ?, updated_at = NOW() WHERE id = ?',
                [$name, $description, $fee, $requirements, (int)$active, (int)$id]
            );
            $action = 'update';
        }
        $this->audit((int)$admin['id'], $action, 'service_type', (int)$id, $name);
        $this->success([], 'Document service saved.');
    }

    public function create_incident_type()
    {
        $admin = $this->api_user(['admin']);
        $name = $this->value('name', 120);
        if ($name === '') {
            $this->failure(422, 'Enter an incident type.');
        }
        if ($this->db()->raw('SELECT id FROM incident_types WHERE name = ? LIMIT 1', [$name])->fetch(PDO::FETCH_ASSOC)) {
            $this->failure(409, 'That incident type already exists.');
        }
        $this->db()->raw('INSERT INTO incident_types (name, is_active) VALUES (?, 1)', [$name]);
        $id = (int)$this->db()->raw('SELECT LAST_INSERT_ID()')->fetchColumn();
        $this->audit((int)$admin['id'], 'create', 'incident_type', $id, $name);
        $this->success([], 'Incident type added.');
    }
}
