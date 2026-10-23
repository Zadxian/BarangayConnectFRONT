<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
require_once APP_DIR . 'controllers/BaseController.php';

class ResidentController extends BaseController
{
    public function dashboard()
    {
        $user = $this->require_user(['user']);
        $counts = $this->db()->raw(
            "SELECT
                SUM(status IN ('Submitted','Under Review','Processing')) AS pending_requests,
                SUM(status = 'Ready for Pickup') AS ready_requests
             FROM document_requests WHERE user_id = ?",
            [(int)$user['id']]
        )->fetch(PDO::FETCH_ASSOC);
        $incidents = $this->db()->raw('SELECT COUNT(*) FROM incidents WHERE reporter_id = ?', [(int)$user['id']])->fetchColumn();
        $notifications = $this->db()->raw(
            'SELECT message, channel, status, created_at FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 5',
            [(int)$user['id']]
        )->fetchAll(PDO::FETCH_ASSOC);
        $this->render('pages/dashboard', [
            'title' => 'Resident Dashboard',
            'cards' => [
                ['Pending requests', (string)($counts['pending_requests'] ?? 0), 'resident/requests'],
                ['Ready for pickup', (string)($counts['ready_requests'] ?? 0), 'resident/requests'],
                ['My incident reports', (string)$incidents, 'resident/incidents'],
            ],
            'notifications' => $notifications,
        ]);
    }

    public function requests()
    {
        $user = $this->require_user(['user']);
        $requests = $this->db()->raw(
            'SELECT r.id, r.reference_no, s.name AS service_name, r.status, r.payment_status, r.purpose, r.staff_remarks, r.created_at, s.fee
             FROM document_requests r JOIN service_types s ON s.id = r.service_type_id
             WHERE r.user_id = ? ORDER BY r.created_at DESC LIMIT 100',
            [(int)$user['id']]
        )->fetchAll(PDO::FETCH_ASSOC);
        $this->render('pages/requests', ['title' => 'My Document Requests', 'requests' => $requests]);
    }

    public function request_new()
    {
        $this->require_user(['user']);
        $services = $this->db()->raw('SELECT id, name FROM service_types WHERE is_active = 1 ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
        $options = array_map(function ($service) {
            return ['value' => $service['id'], 'label' => $service['name']];
        }, $services);
        $this->render('pages/form', [
            'title' => 'New Document Request',
            'intro' => 'Choose a document, provide its purpose, and upload a clear image or PDF of your valid ID.',
            'action' => 'resident/requests/new',
            'button' => 'Submit request',
            'fields' => [
                ['Document type', 'service_type_id', 'select', $options],
                ['Purpose', 'purpose', 'text'],
                ['Valid ID (PDF, JPEG, PNG; max 5 MB)', 'valid_id', 'file'],
            ],
        ]);
    }

    public function create_request()
    {
        $user = $this->require_user(['user']);
        $service_id = filter_var($_POST['service_type_id'] ?? null, FILTER_VALIDATE_INT);
        $purpose = $this->post_string('purpose', 500);
        $service = $service_id ? $this->db()->raw(
            'SELECT id, fee FROM service_types WHERE id = ? AND is_active = 1 LIMIT 1',
            [$service_id]
        )->fetch(PDO::FETCH_ASSOC) : null;
        if (!$service || $purpose === '') {
            $this->finish_post('resident/requests/new', 'error', 'Choose an available document type and enter its purpose.');
        }

        try {
            $id_path = $this->upload_private_file('valid_id', 'ids', ['application/pdf', 'image/jpeg', 'image/png']);
        } catch (RuntimeException $error) {
            $this->finish_post('resident/requests/new', 'error', $error->getMessage());
        }
        if (!$id_path) {
            $this->finish_post('resident/requests/new', 'error', 'Upload a valid ID to submit this request.');
        }

        $reference = 'BC-' . strtoupper(bin2hex(random_bytes(14)));
        $this->db()->raw(
            'INSERT INTO document_requests (reference_no, user_id, service_type_id, purpose, valid_id_path, status, payment_status) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$reference, (int)$user['id'], (int)$service['id'], $purpose, $id_path, 'Submitted', (float)$service['fee'] > 0 ? 'Unpaid' : 'Paid']
        );
        $request_id = (int)$this->db()->raw('SELECT LAST_INSERT_ID()')->fetchColumn();
        $this->notify_user((int)$user['id'], 'Your document request ' . $reference . ' was submitted.');
        $this->audit((int)$user['id'], 'create', 'document_request', $request_id, $reference);
        $this->finish_post('resident/requests', 'success', 'Request submitted. Save this tracking reference: ' . $reference);
    }

    public function create_payment($id)
    {
        $user = $this->require_user(['user']);
        $secret = (string)getenv('PAYMONGO_SECRET_KEY');
        if ($secret === '') {
            $this->finish_post('resident/requests', 'error', 'Online payment is not configured yet. Please contact the barangay office to pay in person.');
        }

        $request = $this->db()->raw(
            'SELECT r.id, r.reference_no, r.user_id, r.status, r.payment_status, s.name, s.fee
             FROM document_requests r JOIN service_types s ON s.id = r.service_type_id
             WHERE r.id = ? AND r.user_id = ? LIMIT 1',
            [(int)$id, (int)$user['id']]
        )->fetch(PDO::FETCH_ASSOC);
        if (!$request || $request['payment_status'] === 'Paid' || $request['status'] === 'Rejected' || (float)$request['fee'] <= 0) {
            $this->finish_post('resident/requests', 'error', 'This request cannot be paid online.');
        }

        $existing = $this->db()->raw(
            "SELECT checkout_url, status FROM payments WHERE request_id = ? AND user_id = ? AND provider = 'paymongo' ORDER BY id DESC LIMIT 1",
            [(int)$request['id'], (int)$user['id']]
        )->fetch(PDO::FETCH_ASSOC);
        if ($existing && $existing['status'] === 'Pending' && !empty($existing['checkout_url'])) {
            redirect($existing['checkout_url']);
            exit;
        }

        $amount = (int)round((float)$request['fee'] * 100);
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
                        'amount' => $amount,
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
            $this->finish_post('resident/requests', 'error', 'The payment provider could not create a checkout session. Please try again or pay in person.');
        }
        $checkout_host = parse_url($checkout_url, PHP_URL_HOST);
        if (parse_url($checkout_url, PHP_URL_SCHEME) !== 'https' || !in_array($checkout_host, ['checkout.paymongo.com'], true)) {
            error_log('PayMongo returned an unexpected checkout URL host.');
            $this->finish_post('resident/requests', 'error', 'The payment provider returned an invalid checkout address.');
        }

        $this->db()->raw(
            'INSERT INTO payments (request_id, user_id, provider, amount, status, provider_reference, checkout_url) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [(int)$request['id'], (int)$user['id'], 'paymongo', (float)$request['fee'], 'Pending', $session_id, $checkout_url]
        );
        redirect($checkout_url);
        exit;
    }

    public function paymongo_webhook()
    {
        $webhook_secret = (string)getenv('PAYMONGO_WEBHOOK_SECRET');
        $signature_header = (string)($_SERVER['HTTP_PAYMONGO_SIGNATURE'] ?? '');
        $signature_parts = [];
        foreach (explode(',', $signature_header) as $part) {
            $pair = explode('=', trim($part), 2);
            if (count($pair) === 2) {
                $signature_parts[$pair[0]] = $pair[1];
            }
        }
        $timestamp = $signature_parts['t'] ?? '';
        $signature = $signature_parts['te'] ?? ($signature_parts['li'] ?? '');
        $body = file_get_contents('php://input');
        if ($webhook_secret === '' || !ctype_digit($timestamp) || abs(time() - (int)$timestamp) > 300 || $signature === '') {
            http_response_code(400);
            echo 'Invalid webhook signature.';
            return;
        }
        $expected = hash_hmac('sha256', $timestamp . '.' . $body, $webhook_secret);
        if (!hash_equals($expected, $signature)) {
            http_response_code(400);
            echo 'Invalid webhook signature.';
            return;
        }

        $event = json_decode($body, true);
        if (!is_array($event)) {
            http_response_code(400);
            echo 'Invalid event payload.';
            return;
        }
        $event_type = $event['data']['attributes']['type'] ?? '';
        $session_id = $event['data']['attributes']['data']['id'] ?? '';
        if ($event_type !== 'checkout_session.payment.paid' || !is_string($session_id) || $session_id === '') {
            http_response_code(200);
            echo 'Event ignored.';
            return;
        }

        $payment = $this->db()->raw(
            "SELECT p.id, p.request_id, p.user_id, p.status, r.reference_no
             FROM payments p JOIN document_requests r ON r.id = p.request_id
             WHERE p.provider = 'paymongo' AND p.provider_reference = ? LIMIT 1",
            [$session_id]
        )->fetch(PDO::FETCH_ASSOC);
        if (!$payment) {
            http_response_code(404);
            echo 'Payment not found.';
            return;
        }
        if ($payment['status'] !== 'Paid') {
            $this->db()->raw('UPDATE payments SET status = ?, paid_at = NOW() WHERE id = ?', ['Paid', (int)$payment['id']]);
            $this->db()->raw('UPDATE document_requests SET payment_status = ?, updated_at = NOW() WHERE id = ?', ['Paid', (int)$payment['request_id']]);
            $this->notify_user((int)$payment['user_id'], 'Payment for request ' . $payment['reference_no'] . ' was confirmed.');
            $this->audit((int)$payment['user_id'], 'payment_confirmed', 'document_request', (int)$payment['request_id'], 'PayMongo');
        }
        http_response_code(200);
        echo 'Webhook processed.';
    }

    public function incidents()
    {
        $user = $this->require_user(['user']);
        $incidents = $this->db()->raw(
            'SELECT i.reference_no, t.name AS incident_type, i.incident_date, i.location, i.status, i.staff_remarks, i.created_at,
                    (SELECT MIN(h.scheduled_at) FROM hearings h WHERE h.incident_id = i.id AND h.status = ?) AS next_hearing
             FROM incidents i JOIN incident_types t ON t.id = i.incident_type_id
             WHERE i.reporter_id = ? ORDER BY i.created_at DESC LIMIT 100',
            ['Scheduled', (int)$user['id']]
        )->fetchAll(PDO::FETCH_ASSOC);
        $this->render('pages/incidents', ['title' => 'My Incident Reports', 'incidents' => $incidents]);
    }

    public function incident_new()
    {
        $this->require_user(['user']);
        $types = $this->db()->raw('SELECT id, name FROM incident_types WHERE is_active = 1 ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
        $options = array_map(function ($type) {
            return ['value' => $type['id'], 'label' => $type['name']];
        }, $types);
        $this->render('pages/incident_form', [
            'title' => 'File an Incident Report',
            'intro' => 'Provide accurate details. Reports are visible only to you and authorized barangay staff.',
            'action' => 'resident/incidents/new',
            'button' => 'Submit report',
            'types' => $options,
            'maps_api_key' => (string)getenv('GOOGLE_MAPS_API_KEY'),
        ]);
    }

    public function create_incident()
    {
        $user = $this->require_user(['user']);
        $type_id = filter_var($_POST['incident_type_id'] ?? null, FILTER_VALIDATE_INT);
        $date = $this->post_string('incident_date', 10);
        $location = $this->post_string('location', 255);
        $description = $this->post_string('description', 5000);
        $valid_date = DateTime::createFromFormat('!Y-m-d', $date);
        $type = $type_id ? $this->db()->raw('SELECT id FROM incident_types WHERE id = ? AND is_active = 1', [$type_id])->fetch(PDO::FETCH_ASSOC) : null;
        if (!$type || !$valid_date || $valid_date->format('Y-m-d') !== $date || $date > date('Y-m-d') || $location === '' || $description === '') {
            $this->finish_post('resident/incidents/new', 'error', 'Complete the incident type, valid date, location, and description.');
        }

        $latitude = $this->post_string('latitude', 20);
        $longitude = $this->post_string('longitude', 20);
        if (($latitude !== '' && (!is_numeric($latitude) || (float)$latitude < -90 || (float)$latitude > 90))
            || ($longitude !== '' && (!is_numeric($longitude) || (float)$longitude < -180 || (float)$longitude > 180))
            || (($latitude === '') !== ($longitude === ''))) {
            $this->finish_post('resident/incidents/new', 'error', 'Enter both latitude and longitude as valid coordinates.');
        }
        try {
            $evidence_path = $this->upload_private_file('evidence', 'evidence', ['application/pdf', 'image/jpeg', 'image/png']);
        } catch (RuntimeException $error) {
            $this->finish_post('resident/incidents/new', 'error', $error->getMessage());
        }

        $reference = 'BC-' . strtoupper(bin2hex(random_bytes(14)));
        $this->db()->raw(
            'INSERT INTO incidents (reference_no, reporter_id, incident_type_id, incident_date, location, latitude, longitude, description, evidence_path, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $reference,
                (int)$user['id'],
                (int)$type['id'],
                $date,
                $location,
                $latitude === '' ? null : (float)$latitude,
                $longitude === '' ? null : (float)$longitude,
                $description,
                $evidence_path,
                'Submitted',
            ]
        );
        $incident_id = (int)$this->db()->raw('SELECT LAST_INSERT_ID()')->fetchColumn();
        $this->notify_user((int)$user['id'], 'Your incident report ' . $reference . ' was submitted.');
        $this->audit((int)$user['id'], 'create', 'incident', $incident_id, $reference);
        $this->finish_post('resident/incidents', 'success', 'Incident report submitted. Save this tracking reference: ' . $reference);
    }

    public function profile()
    {
        $user = $this->require_user(['user']);
        $this->render('pages/form', [
            'title' => 'My Profile',
            'intro' => 'Update your contact information and address.',
            'action' => 'resident/profile',
            'button' => 'Save profile',
            'fields' => [
                ['Full name', 'full_name', 'text'],
                ['Contact number', 'contact', 'tel'],
                ['Purok / Street', 'address', 'text'],
                ['I agree to receive request status updates by SMS', 'sms_opt_in', 'checkbox', null, false],
            ],
            'values' => [
                'full_name' => $user['full_name'] ?? '',
                'contact' => $user['contact'] ?? '',
                'address' => $user['address'] ?? '',
                'sms_opt_in' => (int)($user['sms_opt_in'] ?? 0),
            ],
        ]);
    }

    public function update_profile()
    {
        $user = $this->require_user(['user']);
        $name = $this->post_string('full_name', 150);
        $contact = $this->post_string('contact', 30);
        $address = $this->post_string('address', 255);
        $sms_opt_in = isset($_POST['sms_opt_in']) && $_POST['sms_opt_in'] === '1' ? 1 : 0;
        if ($name === '' || $contact === '' || $address === '') {
            $this->finish_post('resident/profile', 'error', 'Name, contact number, and address are required.');
        }
        $this->db()->raw(
            'UPDATE users SET full_name = ?, contact = ?, address = ?, sms_opt_in = ?, updated_at = NOW() WHERE id = ?',
            [$name, $contact, $address, $sms_opt_in, (int)$user['id']]
        );
        $_SESSION['user']['full_name'] = $name;
        $_SESSION['user']['contact'] = $contact;
        $_SESSION['user']['address'] = $address;
        $_SESSION['user']['sms_opt_in'] = $sms_opt_in;
        $this->audit((int)$user['id'], 'update_profile', 'user', (int)$user['id']);
        $this->finish_post('resident/profile', 'success', 'Profile updated.');
    }
}
