<?php
// Plain PHP front controller (src/-style products): the route in three lines.
require_once __DIR__ . '/../lib/company-lifecycle/autoload.php';

if ($method === 'POST' && $path === 'internal/manage/company-lifecycle') {
    [$status, $json] = Aicountly\Lifecycle\Kit::fromEnv('sales', manifest: __DIR__ . '/../config/lifecycle-manifest.php')
        ->receiver()->handle((string) file_get_contents('php://input'), function_exists('getallheaders') ? (getallheaders() ?: $_SERVER) : $_SERVER);
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($json);
    exit;
}

// The write gate, after the company-access check:
//   $refusal = $kit->guard(['POST /api/reports/query', '* /api/exports/**'])->check($cmpId, $method, '/api/' . $path);
//   if ($refusal !== null) { http_response_code($refusal[0]); header('Content-Type: application/json'); echo json_encode($refusal[1]); exit; }
