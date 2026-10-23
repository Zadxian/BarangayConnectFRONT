<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
require_once APP_DIR . 'controllers/BaseController.php';

class SpaController extends BaseController
{
    public function index()
    {
        $manifest_path = ROOT_DIR . 'public' . DIRECTORY_SEPARATOR . 'build' . DIRECTORY_SEPARATOR . 'manifest.json';
        if (!is_file($manifest_path)) {
            http_response_code(503);
            echo 'React assets are not built. Run npm install and npm run build, or use the Vite development server.';
            return;
        }
        $manifest = json_decode(file_get_contents($manifest_path), true);
        if (!is_array($manifest) || empty($manifest['index.html']['file'])) {
            http_response_code(500);
            echo 'The React asset manifest is invalid.';
            return;
        }
        $entry = $manifest['index.html'];
        $this->call->view('pages/spa', ['entry' => $entry]);
    }
}
