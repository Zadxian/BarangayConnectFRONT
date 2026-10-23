<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

// Role authorization is enforced by controller actions through BaseController::require_user().
$config['middlewares'] = [];
