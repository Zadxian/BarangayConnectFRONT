<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Shared parent for all page controllers.
 * Every page goes through render() so the layout and navigation stay consistent.
 */
class BaseController extends Controller
{
    public function __construct()
    {
        parent::__construct();
        if (session_status() !== PHP_SESSION_ACTIVE) {
            $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
            session_set_cookie_params([
                'lifetime' => 0,
                'path' => '/',
                'secure' => $secure,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            session_start();
        }
    }

    protected function render($view, $data = [])
    {
        $data['app_name'] = 'BarangayConnect';
        $data['current_user'] = $_SESSION['user'] ?? null;
        $data['flash'] = $_SESSION['_flash'] ?? null;
        unset($_SESSION['_flash']);
        $this->call->view($view, $data);
    }

    protected function db()
    {
        $this->call->database();
        return lava_instance()->db;
    }

    protected function require_user($roles = [])
    {
        $user = $_SESSION['user'] ?? null;
        if (!$user || empty($user['id'])) {
            $this->set_flash('error', 'Please sign in to continue.');
            redirect('login');
            exit;
        }

        $row = $this->db()->table('users')
            ->select('id, username, email, role, is_active, full_name, contact, address, sms_opt_in')
            ->where('id', (int)$user['id'])
            ->limit(1)
            ->get();
        if (!$row || (int)$row['is_active'] !== 1) {
            unset($_SESSION['user']);
            $this->set_flash('error', 'Your account is unavailable. Please contact an administrator.');
            redirect('login');
            exit;
        }

        if ($roles && !in_array($row['role'], $roles, true)) {
            http_response_code(403);
            $this->render('pages/message', [
                'title' => 'Access denied',
                'message' => 'Your account does not have permission to view this page.',
            ]);
            exit;
        }
        $_SESSION['user'] = $row;
        return $row;
    }

    protected function set_flash($type, $message)
    {
        $_SESSION['_flash'] = ['type' => $type, 'message' => $message];
    }

    protected function post_string($key, $max_length = 1000)
    {
        $value = $_POST[$key] ?? '';
        if (!is_string($value)) {
            return '';
        }
        return trim(substr($value, 0, $max_length));
    }

    protected function finish_post($uri, $type, $message)
    {
        $this->set_flash($type, $message);
        redirect($uri);
        exit;
    }

    protected function audit($actor_id, $action, $entity, $entity_id, $details = '')
    {
        $this->db()->table('audit_logs')->insert([
            'actor_id' => $actor_id,
            'action' => $action,
            'entity' => $entity,
            'entity_id' => $entity_id,
            'details' => $details,
        ]);
    }

    protected function notify_user($user_id, $message)
    {
        $this->db()->table('notifications')->insert([
            'user_id' => $user_id,
            'message' => $message,
            'channel' => 'in-app',
            'status' => 'Queued',
        ]);
        $recipient = $this->db()->table('users')
            ->select('contact, sms_opt_in')
            ->where('id', (int)$user_id)
            ->limit(1)
            ->get();
        if (!$recipient || (int)$recipient['sms_opt_in'] !== 1 || empty($recipient['contact'])) {
            return;
        }

        $api_key = (string)getenv('SEMAPHORE_API_KEY');
        $initial_status = $api_key === '' ? 'Unconfigured' : 'Queued';
        $notification_id = $this->db()->table('notifications')->insert([
            'user_id' => (int)$user_id,
            'message' => $message,
            'channel' => 'sms',
            'status' => $initial_status,
        ]);
        if ($api_key === '') {
            return;
        }

        $curl = curl_init('https://api.semaphore.co/api/v4/messages');
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'apikey' => $api_key,
                'number' => $recipient['contact'],
                'message' => $message,
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
        ]);
        $response = curl_exec($curl);
        $status_code = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($curl);
        curl_close($curl);
        $sent = $response !== false && $status_code >= 200 && $status_code < 300;
        $this->db()->table('notifications')
            ->where('id', (int)$notification_id)
            ->update([
                'status' => $sent ? 'Submitted' : 'Failed',
                'sent_at' => $sent ? date('Y-m-d H:i:s') : null,
            ]);
        if (!$sent) {
            error_log('Semaphore SMS submission failed. HTTP ' . $status_code . ($curl_error !== '' ? ': transport error' : ''));
        }
    }

    protected function firebase_identity($operation, $email, $password)
    {
        $api_key = (string)getenv('FIREBASE_API_KEY');
        if ($api_key === '') {
            return null;
        }
        if (!in_array($operation, ['signUp', 'signInWithPassword'], true)) {
            throw new InvalidArgumentException('Unsupported Firebase authentication operation.');
        }
        $curl = curl_init('https://identitytoolkit.googleapis.com/v1/accounts:' . $operation . '?key=' . rawurlencode($api_key));
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode([
                'email' => $email,
                'password' => $password,
                'returnSecureToken' => true,
            ], JSON_THROW_ON_ERROR),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 20,
        ]);
        $response = curl_exec($curl);
        $status_code = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($curl);
        curl_close($curl);
        $body = is_string($response) ? json_decode($response, true) : null;
        $provider_error = $body['error']['message'] ?? '';
        if ($response === false || $status_code < 200 || $status_code >= 300 || !is_array($body) || empty($body['localId'])) {
            $safe_error = is_string($provider_error) ? preg_replace('/[^A-Z0-9_]/', '', strtoupper($provider_error)) : '';
            error_log('Firebase authentication request failed. HTTP ' . $status_code . ($curl_error !== '' ? ': transport error' : '') . ($safe_error !== '' ? ' (' . $safe_error . ')' : ''));
            return ['ok' => false, 'error' => $safe_error];
        }
        return ['ok' => true, 'uid' => $body['localId']];
    }

    protected function upload_private_file($field, $folder, $allowed_mimes, $max_bytes = 5242880)
    {
        if (empty($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        $file = $_FILES[$field];
        if ($file['error'] !== UPLOAD_ERR_OK || $file['size'] > $max_bytes) {
            throw new RuntimeException('The upload failed or exceeds the 5 MB limit.');
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        $extensions = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'application/pdf' => 'pdf',
        ];
        if (!in_array($mime, $allowed_mimes, true) || !isset($extensions[$mime])) {
            throw new RuntimeException('Only PDF, JPEG, or PNG files are allowed.');
        }
        $directory = ROOT_DIR . 'runtime' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . $folder;
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Private upload storage could not be created.');
        }
        $filename = bin2hex(random_bytes(20)) . '.' . $extensions[$mime];
        if (!move_uploaded_file($file['tmp_name'], $directory . DIRECTORY_SEPARATOR . $filename)) {
            throw new RuntimeException('The uploaded file could not be stored.');
        }
        return $folder . '/' . $filename;
    }
}
