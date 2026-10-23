<?php

class Create_barangay_workflows
{
    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
        $this->_lava->call->database();
    }

    public function up()
    {
        $columns = $this->_lava->db->raw('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN);
        $columns = array_flip($columns);
        $user_columns = [
            'full_name' => 'ALTER TABLE users ADD full_name VARCHAR(150) NULL AFTER username',
            'contact' => 'ALTER TABLE users ADD contact VARCHAR(30) NULL',
            'address' => 'ALTER TABLE users ADD address VARCHAR(255) NULL',
            'google_id' => 'ALTER TABLE users ADD google_id VARCHAR(255) NULL',
            'sms_opt_in' => 'ALTER TABLE users ADD sms_opt_in TINYINT(1) NOT NULL DEFAULT 0',
            'auth_provider' => "ALTER TABLE users ADD auth_provider VARCHAR(20) NOT NULL DEFAULT 'local'",
        ];
        foreach ($user_columns as $column => $sql) {
            if (!isset($columns[$column])) {
                $this->_lava->db->raw($sql);
            }
        }
        $google_index = $this->_lava->db->raw("SHOW INDEX FROM users WHERE Key_name = 'users_google_id_unique'")->fetch(PDO::FETCH_ASSOC);
        if (!$google_index) {
            $this->_lava->db->raw('CREATE UNIQUE INDEX users_google_id_unique ON users (google_id)');
        }

        $statements = [
            "CREATE TABLE IF NOT EXISTS service_types (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                name VARCHAR(120) NOT NULL,
                description TEXT NULL,
                fee DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                requirements TEXT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL,
                PRIMARY KEY (id),
                UNIQUE KEY service_types_name_unique (name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS document_requests (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                reference_no VARCHAR(32) NOT NULL,
                user_id INT UNSIGNED NOT NULL,
                service_type_id INT UNSIGNED NOT NULL,
                purpose VARCHAR(500) NOT NULL,
                valid_id_path VARCHAR(255) NULL,
                status VARCHAR(30) NOT NULL DEFAULT 'Submitted',
                payment_status VARCHAR(30) NOT NULL DEFAULT 'Unpaid',
                staff_remarks TEXT NULL,
                reviewed_by INT UNSIGNED NULL,
                reviewed_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL,
                PRIMARY KEY (id),
                UNIQUE KEY document_requests_reference_unique (reference_no),
                KEY document_requests_user_idx (user_id),
                KEY document_requests_status_idx (status),
                CONSTRAINT document_requests_user_fk FOREIGN KEY (user_id) REFERENCES users(id),
                CONSTRAINT document_requests_service_fk FOREIGN KEY (service_type_id) REFERENCES service_types(id),
                CONSTRAINT document_requests_reviewer_fk FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS incident_types (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                name VARCHAR(120) NOT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                PRIMARY KEY (id),
                UNIQUE KEY incident_types_name_unique (name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS incidents (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                reference_no VARCHAR(32) NOT NULL,
                reporter_id INT UNSIGNED NOT NULL,
                incident_type_id INT UNSIGNED NOT NULL,
                incident_date DATE NOT NULL,
                location VARCHAR(255) NOT NULL,
                latitude DECIMAL(10,7) NULL,
                longitude DECIMAL(10,7) NULL,
                description TEXT NOT NULL,
                evidence_path VARCHAR(255) NULL,
                status VARCHAR(30) NOT NULL DEFAULT 'Submitted',
                assigned_to INT UNSIGNED NULL,
                staff_remarks TEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL,
                PRIMARY KEY (id),
                UNIQUE KEY incidents_reference_unique (reference_no),
                KEY incidents_reporter_idx (reporter_id),
                KEY incidents_status_idx (status),
                CONSTRAINT incidents_reporter_fk FOREIGN KEY (reporter_id) REFERENCES users(id),
                CONSTRAINT incidents_type_fk FOREIGN KEY (incident_type_id) REFERENCES incident_types(id),
                CONSTRAINT incidents_assigned_fk FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS payments (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                request_id INT UNSIGNED NOT NULL,
                user_id INT UNSIGNED NOT NULL,
                provider VARCHAR(40) NOT NULL DEFAULT 'manual',
                amount DECIMAL(10,2) NOT NULL,
                status VARCHAR(30) NOT NULL DEFAULT 'Pending',
                provider_reference VARCHAR(120) NULL,
                checkout_url VARCHAR(500) NULL,
                paid_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY payments_request_idx (request_id),
                CONSTRAINT payments_request_fk FOREIGN KEY (request_id) REFERENCES document_requests(id),
                CONSTRAINT payments_user_fk FOREIGN KEY (user_id) REFERENCES users(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS hearings (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                incident_id INT UNSIGNED NOT NULL,
                scheduled_at DATETIME NOT NULL,
                location VARCHAR(255) NOT NULL,
                notes TEXT NULL,
                outcome TEXT NULL,
                status VARCHAR(30) NOT NULL DEFAULT 'Scheduled',
                created_by INT UNSIGNED NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY hearings_scheduled_idx (scheduled_at),
                CONSTRAINT hearings_incident_fk FOREIGN KEY (incident_id) REFERENCES incidents(id),
                CONSTRAINT hearings_creator_fk FOREIGN KEY (created_by) REFERENCES users(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS notifications (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id INT UNSIGNED NOT NULL,
                message VARCHAR(500) NOT NULL,
                channel VARCHAR(30) NOT NULL DEFAULT 'in-app',
                status VARCHAR(30) NOT NULL DEFAULT 'Queued',
                sent_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY notifications_user_idx (user_id),
                CONSTRAINT notifications_user_fk FOREIGN KEY (user_id) REFERENCES users(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS audit_logs (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                actor_id INT UNSIGNED NULL,
                action VARCHAR(80) NOT NULL,
                entity VARCHAR(80) NOT NULL,
                entity_id INT UNSIGNED NULL,
                details TEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY audit_logs_created_idx (created_at),
                CONSTRAINT audit_logs_actor_fk FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS app_settings (
                setting_key VARCHAR(120) NOT NULL,
                setting_value TEXT NOT NULL,
                updated_at DATETIME NULL,
                PRIMARY KEY (setting_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ];

        foreach ($statements as $sql) {
            $this->_lava->db->raw($sql);
        }

        $services = [
            ['Barangay Clearance', 'Clearance for employment, travel, or general purposes.', 50.00, 'Valid government-issued ID; proof of residency.'],
            ['Certificate of Residency', 'Proof of current residency in the barangay.', 30.00, 'Valid government-issued ID; proof of residency.'],
            ['Certificate of Indigency', 'Certification for qualified residents requesting assistance.', 0.00, 'Valid government-issued ID; proof of residency; statement of purpose.'],
            ['Business Clearance', 'Barangay clearance for a local business.', 100.00, 'Valid ID; proof of address; business permit application.'],
        ];
        foreach ($services as $service) {
            $this->_lava->db->raw(
                'INSERT IGNORE INTO service_types (name, description, fee, requirements) VALUES (?, ?, ?, ?)',
                $service
            );
        }

        foreach (['Noise complaint', 'Theft', 'Physical altercation', 'Property dispute', 'Other'] as $name) {
            $this->_lava->db->raw('INSERT IGNORE INTO incident_types (name) VALUES (?)', [$name]);
        }
    }

    public function down()
    {
        foreach (['hearings', 'payments', 'incidents', 'notifications', 'document_requests', 'audit_logs', 'app_settings', 'incident_types', 'service_types'] as $table) {
            $this->_lava->db->raw('DROP TABLE IF EXISTS ' . $table);
        }
    }
}
