<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
require_once APP_DIR . 'controllers/BaseController.php';

class AdminController extends BaseController
{
    public function users()
    {
        $this->require_user(['admin']);
        $users = $this->db()->raw(
            'SELECT id, username, full_name, email, role, is_active, created_at FROM users ORDER BY created_at DESC LIMIT 200'
        )->fetchAll(PDO::FETCH_ASSOC);
        $this->render('pages/admin_users', ['title' => 'User Management', 'users' => $users]);
    }

    public function create_user()
    {
        $admin = $this->require_user(['admin']);
        $name = $this->post_string('full_name', 150);
        $email = strtolower($this->post_string('email', 255));
        $role = $this->post_string('role', 20);
        $password = $_POST['password'] ?? '';
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || !in_array($role, ['moderator', 'admin'], true) || !is_string($password) || strlen($password) < 12 || strlen($password) > 72) {
            $this->finish_post('admin/users', 'error', 'Provide a valid name and email, select a staff role, and use a password of 12 to 72 characters.');
        }
        if ($this->db()->raw('SELECT id FROM users WHERE email = ? LIMIT 1', [$email])->fetch(PDO::FETCH_ASSOC)) {
            $this->finish_post('admin/users', 'error', 'An account with that email already exists.');
        }
        $auth_provider = 'local';
        $stored_password = password_hash($password, PASSWORD_DEFAULT);
        if ((string)getenv('FIREBASE_API_KEY') !== '') {
            $identity = $this->firebase_identity('signUp', $email, $password);
            if (!$identity || empty($identity['ok'])) {
                $this->finish_post('admin/users', 'error', 'Firebase could not create this account. Verify Firebase email/password sign-in is enabled.');
            }
            $auth_provider = 'firebase';
            $stored_password = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
        }
        $base = preg_replace('/[^a-z0-9_]/', '', strtolower(strtok($email, '@')));
        $username = substr($base ?: 'staff', 0, 70) . '_' . bin2hex(random_bytes(4));
        $this->db()->raw(
            'INSERT INTO users (username, full_name, email, password, role, is_active, auth_provider, created_at) VALUES (?, ?, ?, ?, ?, 1, ?, NOW())',
            [$username, $name, $email, $stored_password, $role, $auth_provider]
        );
        $id = (int)$this->db()->raw('SELECT LAST_INSERT_ID()')->fetchColumn();
        $this->audit((int)$admin['id'], 'create', 'user', $id, $role);
        $this->finish_post('admin/users', 'success', 'Staff account created.');
    }

    public function update_user_status($id)
    {
        $admin = $this->require_user(['admin']);
        $active = $this->post_string('is_active', 1);
        if (!in_array($active, ['0', '1'], true) || (int)$id === (int)$admin['id']) {
            $this->finish_post('admin/users', 'error', 'Choose a valid status. You cannot deactivate your own account.');
        }
        $user = $this->db()->raw('SELECT id FROM users WHERE id = ? LIMIT 1', [(int)$id])->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            $this->finish_post('admin/users', 'error', 'Account not found.');
        }
        $this->db()->raw('UPDATE users SET is_active = ?, updated_at = NOW() WHERE id = ?', [(int)$active, (int)$id]);
        $this->audit((int)$admin['id'], $active === '1' ? 'activate' : 'deactivate', 'user', (int)$id);
        $this->finish_post('admin/users', 'success', 'Account status updated.');
    }

    public function settings()
    {
        $this->require_user(['admin']);
        $services = $this->db()->raw('SELECT id, name, description, fee, requirements, is_active FROM service_types ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
        $types = $this->db()->raw('SELECT id, name, is_active FROM incident_types ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
        $settings = $this->db()->raw('SELECT setting_key, setting_value FROM app_settings')->fetchAll(PDO::FETCH_KEY_PAIR);
        $this->render('pages/admin_settings', [
            'title' => 'System Settings',
            'services' => $services,
            'incident_types' => $types,
            'settings' => $settings,
        ]);
    }

    public function save_settings()
    {
        $admin = $this->require_user(['admin']);
        $name = $this->post_string('barangay_name', 150);
        $hours = $this->post_string('office_hours', 255);
        $contact = $this->post_string('office_contact', 60);
        foreach (['barangay_name' => $name, 'office_hours' => $hours, 'office_contact' => $contact] as $key => $value) {
            $this->db()->raw(
                'INSERT INTO app_settings (setting_key, setting_value, updated_at) VALUES (?, ?, NOW())
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()',
                [$key, $value]
            );
        }
        $this->audit((int)$admin['id'], 'update_settings', 'app_settings', null, 'Barangay contact information');
        $this->finish_post('admin/settings', 'success', 'Barangay settings saved.');
    }

    public function create_service()
    {
        $admin = $this->require_user(['admin']);
        $name = $this->post_string('name', 120);
        $description = $this->post_string('description', 2000);
        $requirements = $this->post_string('requirements', 2000);
        $fee = filter_var($_POST['fee'] ?? null, FILTER_VALIDATE_FLOAT);
        if ($name === '' || $fee === false || $fee < 0) {
            $this->finish_post('admin/settings', 'error', 'Enter a service name and a non-negative fee.');
        }
        if ($this->db()->raw('SELECT id FROM service_types WHERE name = ? LIMIT 1', [$name])->fetch(PDO::FETCH_ASSOC)) {
            $this->finish_post('admin/settings', 'error', 'A service with that name already exists.');
        }
        $this->db()->raw(
            'INSERT INTO service_types (name, description, fee, requirements, is_active) VALUES (?, ?, ?, ?, 1)',
            [$name, $description, $fee, $requirements]
        );
        $this->audit((int)$admin['id'], 'create', 'service_type', (int)$this->db()->raw('SELECT LAST_INSERT_ID()')->fetchColumn(), $name);
        $this->finish_post('admin/settings', 'success', 'Document service added.');
    }

    public function update_service($id)
    {
        $admin = $this->require_user(['admin']);
        $name = $this->post_string('name', 120);
        $description = $this->post_string('description', 2000);
        $requirements = $this->post_string('requirements', 2000);
        $fee = filter_var($_POST['fee'] ?? null, FILTER_VALIDATE_FLOAT);
        $active = $this->post_string('is_active', 1);
        if ($name === '' || $fee === false || $fee < 0 || !in_array($active, ['0', '1'], true)) {
            $this->finish_post('admin/settings', 'error', 'Provide a name, non-negative fee, and valid active setting.');
        }
        $this->db()->raw(
            'UPDATE service_types SET name = ?, description = ?, fee = ?, requirements = ?, is_active = ?, updated_at = NOW() WHERE id = ?',
            [$name, $description, $fee, $requirements, (int)$active, (int)$id]
        );
        $this->audit((int)$admin['id'], 'update', 'service_type', (int)$id, $name);
        $this->finish_post('admin/settings', 'success', 'Document service updated.');
    }

    public function create_incident_type()
    {
        $admin = $this->require_user(['admin']);
        $name = $this->post_string('name', 120);
        if ($name === '') {
            $this->finish_post('admin/settings', 'error', 'Enter an incident type.');
        }
        if ($this->db()->raw('SELECT id FROM incident_types WHERE name = ? LIMIT 1', [$name])->fetch(PDO::FETCH_ASSOC)) {
            $this->finish_post('admin/settings', 'error', 'That incident type already exists.');
        }
        $this->db()->raw('INSERT INTO incident_types (name, is_active) VALUES (?, 1)', [$name]);
        $this->audit((int)$admin['id'], 'create', 'incident_type', (int)$this->db()->raw('SELECT LAST_INSERT_ID()')->fetchColumn(), $name);
        $this->finish_post('admin/settings', 'success', 'Incident type added.');
    }
}
