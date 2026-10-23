<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/** @var object $router **/

$router->get('/',         'SpaController::index');
$router->get('/services', 'SpaController::index');
$router->get('/about',    'SpaController::index');
$router->get('/login',    'SpaController::index');
$router->get('/register', 'SpaController::index');
$router->get('/setup',    'SpaController::index');
$router->get('/auth/google', 'PageController::google_login');
$router->get('/auth/google/callback', 'PageController::google_callback');
$router->get('/track',    'SpaController::index');

$router->get('/resident',                'SpaController::index');
$router->get('/resident/requests',       'SpaController::index');
$router->get('/resident/requests/new',   'SpaController::index');
$router->get('/resident/incidents',      'SpaController::index');
$router->get('/resident/incidents/new',  'SpaController::index');
$router->get('/resident/profile',        'SpaController::index');

$router->get('/staff',            'SpaController::index');
$router->get('/staff/requests',   'SpaController::index');
$router->get('/staff/incidents',  'SpaController::index');
$router->get('/staff/hearings',   'SpaController::index');
$router->get('/staff/residents',  'SpaController::index');
$router->get('/staff/reports',    'SpaController::index');
$router->get('/staff/files/{kind}/{filename}', 'StaffController::private_file');

$router->get('/admin/users',     'SpaController::index');
$router->get('/admin/settings',  'SpaController::index');

$router->get('/api/csrf', 'ApiController::csrf');
$router->get('/api/me', 'ApiController::me');
$router->get('/api/public', 'ApiController::public_data');
$router->get('/api/incident-types', 'ApiController::incident_types');
$router->get('/api/track', 'ApiController::track');
$router->get('/api/resident/dashboard', 'ApiController::resident_dashboard');
$router->get('/api/resident/requests', 'ApiController::resident_requests');
$router->get('/api/resident/incidents', 'ApiController::resident_incidents');
$router->get('/api/resident/profile', 'ApiController::resident_profile');
$router->get('/api/staff/dashboard', 'ApiController::staff_dashboard');
$router->get('/api/staff/requests', 'ApiController::staff_requests');
$router->get('/api/staff/incidents', 'ApiController::staff_incidents');
$router->get('/api/staff/hearings', 'ApiController::staff_hearings');
$router->get('/api/staff/residents', 'ApiController::staff_residents');
$router->get('/api/staff/reports', 'ApiController::staff_reports');
$router->get('/api/admin/users', 'ApiController::admin_users');
$router->get('/api/admin/settings', 'ApiController::admin_settings');

$router->post('/api/login', 'ApiController::login');
$router->post('/api/register', 'ApiController::register');
$router->post('/api/setup', 'ApiController::setup');
$router->post('/api/logout', 'ApiController::logout');
$router->post('/api/resident/requests', 'ApiController::create_request');
$router->post('/api/resident/requests/{id}/payment', 'ApiController::create_payment');
$router->post('/api/resident/incidents', 'ApiController::create_incident');
$router->post('/api/resident/profile', 'ApiController::update_profile');
$router->post('/api/staff/requests/{id}', 'ApiController::update_request');
$router->post('/api/staff/incidents/{id}', 'ApiController::update_incident');
$router->post('/api/staff/hearings', 'ApiController::create_hearing');
$router->post('/api/staff/hearings/{id}', 'ApiController::update_hearing');
$router->post('/api/admin/users', 'ApiController::create_staff_user');
$router->post('/api/admin/users/{id}/status', 'ApiController::update_user_status');
$router->post('/api/admin/settings', 'ApiController::save_settings');
$router->post('/api/admin/services', 'ApiController::save_service');
$router->post('/api/admin/services/{id}', 'ApiController::save_service');
$router->post('/api/admin/incident-types', 'ApiController::create_incident_type');

$router->post('/login', 'PageController::authenticate');
$router->post('/register', 'PageController::create_account');
$router->post('/setup', 'PageController::create_initial_admin');
$router->post('/logout', 'PageController::logout');

$router->post('/resident/requests/new', 'ResidentController::create_request');
$router->post('/resident/requests/{id}/payment', 'ResidentController::create_payment');
$router->post('/resident/incidents/new', 'ResidentController::create_incident');
$router->post('/resident/profile', 'ResidentController::update_profile');
$router->post('/track', 'PageController::track_result');

$router->post('/staff/requests/{id}', 'StaffController::update_request');
$router->post('/staff/incidents/{id}', 'StaffController::update_incident');
$router->post('/staff/hearings', 'StaffController::create_hearing');
$router->post('/staff/hearings/{id}', 'StaffController::update_hearing');
$router->post('/admin/users', 'AdminController::create_user');
$router->post('/admin/users/{id}/status', 'AdminController::update_user_status');
$router->post('/admin/settings', 'AdminController::save_settings');
$router->post('/admin/services', 'AdminController::create_service');
$router->post('/admin/services/{id}', 'AdminController::update_service');
$router->post('/admin/incident-types', 'AdminController::create_incident_type');
$router->post('/webhooks/paymongo', 'ResidentController::paymongo_webhook');

if (PHP_SAPI === 'cli') {
    $router->get('/migrate', 'MigrationController::migrate');
    $router->get('/create-migration/{migration_class}', 'MigrationController::create_migration');
    $router->get('/rollback', 'MigrationController::rollback');
    $router->get('/rollback-all', 'MigrationController::rollback_all');
    $router->get('/refresh', 'MigrationController::refresh');
    $router->get('/migration-status', 'MigrationController::status');
}
