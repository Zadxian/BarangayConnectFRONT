<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
require_once APP_DIR . 'controllers/BaseController.php';

class StaffController extends BaseController
{
    public function dashboard()
    {
        $this->require_user(['moderator', 'admin']);
        $cards = [
            ['Requests to review', (string)$this->db()->raw("SELECT COUNT(*) FROM document_requests WHERE status IN ('Submitted','Under Review')")->fetchColumn(), 'staff/requests'],
            ['Open incident cases', (string)$this->db()->raw("SELECT COUNT(*) FROM incidents WHERE status NOT IN ('Resolved','Dismissed')")->fetchColumn(), 'staff/incidents'],
            ['Upcoming hearings', (string)$this->db()->raw("SELECT COUNT(*) FROM hearings WHERE status = 'Scheduled' AND scheduled_at >= NOW()")->fetchColumn(), 'staff/hearings'],
        ];
        $this->render('pages/dashboard', ['title' => 'Staff Dashboard', 'cards' => $cards]);
    }

    public function requests()
    {
        $this->require_user(['moderator', 'admin']);
        $requests = $this->db()->raw(
            'SELECT r.id, r.reference_no, r.purpose, r.status, r.payment_status, r.staff_remarks, r.created_at,
                    r.valid_id_path, u.full_name, u.email, u.contact, s.name AS service_name, s.fee
             FROM document_requests r
             JOIN users u ON u.id = r.user_id
             JOIN service_types s ON s.id = r.service_type_id
             ORDER BY r.created_at DESC LIMIT 200'
        )->fetchAll(PDO::FETCH_ASSOC);
        $this->render('pages/staff_requests', ['title' => 'Document Requests', 'requests' => $requests]);
    }

    public function private_file($kind, $filename)
    {
        $this->require_user(['moderator', 'admin']);
        if (!in_array($kind, ['ids', 'evidence'], true) || !preg_match('/^[a-f0-9]{40}\.(pdf|jpg|png)$/', $filename)) {
            http_response_code(404);
            echo 'File not found.';
            return;
        }
        if ($kind === 'ids') {
            $stored = $this->db()->raw(
                'SELECT valid_id_path AS path FROM document_requests WHERE valid_id_path = ? LIMIT 1',
                [$kind . '/' . $filename]
            )->fetch(PDO::FETCH_ASSOC);
        } else {
            $stored = $this->db()->raw(
                'SELECT evidence_path AS path FROM incidents WHERE evidence_path = ? LIMIT 1',
                [$kind . '/' . $filename]
            )->fetch(PDO::FETCH_ASSOC);
        }
        if (!$stored) {
            http_response_code(404);
            echo 'File not found.';
            return;
        }
        $path = ROOT_DIR . 'runtime' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . $kind . DIRECTORY_SEPARATOR . $filename;
        if (!is_file($path)) {
            http_response_code(404);
            echo 'File not found.';
            return;
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
        if (!in_array($mime, ['application/pdf', 'image/jpeg', 'image/png'], true)) {
            http_response_code(415);
            echo 'Unsupported file type.';
            return;
        }
        header('Content-Type: ' . $mime);
        header('Content-Disposition: attachment; filename="' . $kind . '-' . $filename . '"');
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;
    }

    public function update_request($id)
    {
        $staff = $this->require_user(['moderator', 'admin']);
        $request = $this->db()->raw(
            'SELECT r.*, s.fee FROM document_requests r JOIN service_types s ON s.id = r.service_type_id WHERE r.id = ? LIMIT 1',
            [(int)$id]
        )->fetch(PDO::FETCH_ASSOC);
        if (!$request) {
            $this->finish_post('staff/requests', 'error', 'Request not found.');
        }
        $status = $this->post_string('status', 30);
        $allowed = ['Under Review', 'Approved', 'Rejected', 'Ready for Pickup', 'Released'];
        if (!in_array($status, $allowed, true)) {
            $this->finish_post('staff/requests', 'error', 'Choose a valid request status.');
        }

        $payment_action = $this->post_string('payment_action', 10);
        $payment_status = $request['payment_status'];
        if ($payment_action === 'paid' && $payment_status !== 'Paid') {
            $payment_status = 'Paid';
            $this->db()->raw(
                'INSERT INTO payments (request_id, user_id, provider, amount, status, paid_at) VALUES (?, ?, ?, ?, ?, NOW())',
                [(int)$request['id'], (int)$request['user_id'], 'in-person', (float)$request['fee'], 'Paid']
            );
        }
        if (in_array($status, ['Ready for Pickup', 'Released'], true) && $payment_status !== 'Paid') {
            $this->finish_post('staff/requests', 'error', 'Record payment before marking a fee-based request ready or released.');
        }

        $remarks = $this->post_string('staff_remarks', 2000);
        $this->db()->raw(
            'UPDATE document_requests SET status = ?, payment_status = ?, staff_remarks = ?, reviewed_by = ?, reviewed_at = NOW(), updated_at = NOW() WHERE id = ?',
            [$status, $payment_status, $remarks, (int)$staff['id'], (int)$request['id']]
        );
        $this->notify_user((int)$request['user_id'], 'Request ' . $request['reference_no'] . ' status updated to ' . $status . '.');
        $this->audit((int)$staff['id'], 'update_status', 'document_request', (int)$request['id'], $status);
        $this->finish_post('staff/requests', 'success', 'Request updated.');
    }

    public function incidents()
    {
        $this->require_user(['moderator', 'admin']);
        $incidents = $this->db()->raw(
            'SELECT i.id, i.reference_no, i.incident_date, i.location, i.latitude, i.longitude, i.description, i.status, i.staff_remarks,
                    i.evidence_path, i.created_at, t.name AS incident_type, u.full_name AS reporter, u.contact,
                    (SELECT MIN(h.scheduled_at) FROM hearings h WHERE h.incident_id = i.id AND h.status = ?) AS next_hearing
             FROM incidents i JOIN incident_types t ON t.id = i.incident_type_id
             JOIN users u ON u.id = i.reporter_id
             ORDER BY i.created_at DESC LIMIT 200',
            ['Scheduled']
        )->fetchAll(PDO::FETCH_ASSOC);
        $this->render('pages/staff_incidents', ['title' => 'Incident Cases', 'incidents' => $incidents]);
    }

    public function update_incident($id)
    {
        $staff = $this->require_user(['moderator', 'admin']);
        $incident = $this->db()->raw('SELECT id, reference_no, reporter_id FROM incidents WHERE id = ? LIMIT 1', [(int)$id])->fetch(PDO::FETCH_ASSOC);
        if (!$incident) {
            $this->finish_post('staff/incidents', 'error', 'Incident report not found.');
        }
        $status = $this->post_string('status', 30);
        if (!in_array($status, ['Under Review', 'Hearing Scheduled', 'Resolved', 'Dismissed'], true)) {
            $this->finish_post('staff/incidents', 'error', 'Choose a valid incident status.');
        }
        $remarks = $this->post_string('staff_remarks', 2000);
        $this->db()->raw(
            'UPDATE incidents SET status = ?, staff_remarks = ?, assigned_to = ?, updated_at = NOW() WHERE id = ?',
            [$status, $remarks, (int)$staff['id'], (int)$incident['id']]
        );
        $this->notify_user((int)$incident['reporter_id'], 'Incident ' . $incident['reference_no'] . ' status updated to ' . $status . '.');
        $this->audit((int)$staff['id'], 'update_status', 'incident', (int)$incident['id'], $status);
        $this->finish_post('staff/incidents', 'success', 'Incident case updated.');
    }

    public function hearings()
    {
        $this->require_user(['moderator', 'admin']);
        $incidents = $this->db()->raw(
            "SELECT i.id, i.reference_no, u.full_name, t.name AS incident_type
             FROM incidents i JOIN users u ON u.id = i.reporter_id
             JOIN incident_types t ON t.id = i.incident_type_id
             WHERE i.status NOT IN ('Resolved','Dismissed') ORDER BY i.created_at DESC"
        )->fetchAll(PDO::FETCH_ASSOC);
        $hearings = $this->db()->raw(
            'SELECT h.id, h.scheduled_at, h.location, h.notes, h.outcome, h.status, i.reference_no, u.full_name
             FROM hearings h JOIN incidents i ON i.id = h.incident_id JOIN users u ON u.id = i.reporter_id
             ORDER BY h.scheduled_at DESC LIMIT 200'
        )->fetchAll(PDO::FETCH_ASSOC);
        $this->render('pages/hearings', ['title' => 'Hearing Schedule', 'incidents' => $incidents, 'hearings' => $hearings]);
    }

    public function create_hearing()
    {
        $staff = $this->require_user(['moderator', 'admin']);
        $incident_id = filter_var($_POST['incident_id'] ?? null, FILTER_VALIDATE_INT);
        $scheduled = $this->post_string('scheduled_at', 16);
        $location = $this->post_string('location', 255);
        $notes = $this->post_string('notes', 2000);
        $date = DateTime::createFromFormat('!Y-m-d\TH:i', $scheduled);
        $incident = $incident_id ? $this->db()->raw('SELECT id, reporter_id, reference_no FROM incidents WHERE id = ? LIMIT 1', [$incident_id])->fetch(PDO::FETCH_ASSOC) : null;
        if (!$incident || !$date || $date->format('Y-m-d\TH:i') !== $scheduled || $date <= new DateTime() || $location === '') {
            $this->finish_post('staff/hearings', 'error', 'Choose an open incident, a future hearing time, and a location.');
        }

        $this->db()->raw(
            'INSERT INTO hearings (incident_id, scheduled_at, location, notes, status, created_by) VALUES (?, ?, ?, ?, ?, ?)',
            [(int)$incident['id'], $date->format('Y-m-d H:i:s'), $location, $notes, 'Scheduled', (int)$staff['id']]
        );
        $hearing_id = (int)$this->db()->raw('SELECT LAST_INSERT_ID()')->fetchColumn();
        $this->db()->raw('UPDATE incidents SET status = ?, updated_at = NOW() WHERE id = ?', ['Hearing Scheduled', (int)$incident['id']]);
        $this->notify_user((int)$incident['reporter_id'], 'A mediation hearing was scheduled for incident ' . $incident['reference_no'] . ' at ' . $date->format('M j, Y g:i A') . '.');
        $this->audit((int)$staff['id'], 'schedule', 'hearing', $hearing_id, $incident['reference_no']);
        $this->finish_post('staff/hearings', 'success', 'Hearing scheduled and resident notified in the app.');
    }

    public function update_hearing($id)
    {
        $staff = $this->require_user(['moderator', 'admin']);
        $hearing = $this->db()->raw(
            'SELECT h.id, h.incident_id, i.reporter_id, i.reference_no FROM hearings h JOIN incidents i ON i.id = h.incident_id WHERE h.id = ? LIMIT 1',
            [(int)$id]
        )->fetch(PDO::FETCH_ASSOC);
        if (!$hearing) {
            $this->finish_post('staff/hearings', 'error', 'Hearing not found.');
        }
        $status = $this->post_string('status', 30);
        if (!in_array($status, ['Scheduled', 'Completed', 'Cancelled'], true)) {
            $this->finish_post('staff/hearings', 'error', 'Choose a valid hearing status.');
        }
        $outcome = $this->post_string('outcome', 2000);
        $this->db()->raw('UPDATE hearings SET status = ?, outcome = ? WHERE id = ?', [$status, $outcome, (int)$hearing['id']]);
        $this->notify_user((int)$hearing['reporter_id'], 'Hearing for incident ' . $hearing['reference_no'] . ' is now ' . strtolower($status) . '.');
        $this->audit((int)$staff['id'], 'update', 'hearing', (int)$hearing['id'], $status);
        $this->finish_post('staff/hearings', 'success', 'Hearing updated.');
    }

    public function residents()
    {
        $this->require_user(['moderator', 'admin']);
        $query = $this->post_string('q', 100);
        if ($query === '' && isset($_GET['q']) && is_string($_GET['q'])) {
            $query = trim(substr($_GET['q'], 0, 100));
        }
        $like = '%' . $query . '%';
        $residents = $this->db()->raw(
            "SELECT id, full_name, email, contact, address, is_active, created_at
             FROM users WHERE role = 'user' AND (? = '' OR full_name LIKE ? OR email LIKE ? OR contact LIKE ?)
             ORDER BY created_at DESC LIMIT 200",
            [$query, $like, $like, $like]
        )->fetchAll(PDO::FETCH_ASSOC);
        $this->render('pages/residents', ['title' => 'Residents', 'residents' => $residents, 'query' => $query]);
    }

    public function reports()
    {
        $this->require_user(['moderator', 'admin']);
        $requests = $this->db()->raw(
            'SELECT status, COUNT(*) AS total FROM document_requests GROUP BY status ORDER BY status'
        )->fetchAll(PDO::FETCH_ASSOC);
        $incidents = $this->db()->raw(
            'SELECT t.name AS incident_type, COUNT(*) AS total FROM incidents i JOIN incident_types t ON t.id = i.incident_type_id GROUP BY t.id, t.name ORDER BY t.name'
        )->fetchAll(PDO::FETCH_ASSOC);
        $monthly = $this->db()->raw(
            "SELECT DATE_FORMAT(created_at, '%Y-%m') AS month, COUNT(*) AS total
             FROM document_requests WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
             GROUP BY DATE_FORMAT(created_at, '%Y-%m') ORDER BY month"
        )->fetchAll(PDO::FETCH_ASSOC);
        $payments = $this->db()->raw(
            'SELECT provider, status, COUNT(*) AS total, SUM(amount) AS amount FROM payments GROUP BY provider, status ORDER BY provider, status'
        )->fetchAll(PDO::FETCH_ASSOC);
        $this->render('pages/reports', [
            'title' => 'Reports',
            'requests' => $requests,
            'incidents' => $incidents,
            'monthly' => $monthly,
            'payments' => $payments,
        ]);
    }
}
