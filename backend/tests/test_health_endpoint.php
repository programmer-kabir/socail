<?php
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/api/health';
$_SERVER['HTTP_ORIGIN'] = 'http://localhost:5173';

ob_start();
require __DIR__ . '/../public/index.php';
$output = ob_get_clean();

$data = json_decode($output, true);
if ($data && isset($data['success']) && $data['success'] === true && $data['data']['database']['status'] === 'connected') {
    echo "HEALTH_ENDPOINT_TEST_SUCCESS" . PHP_EOL;
    echo json_encode($data, JSON_PRETTY_PRINT) . PHP_EOL;
    exit(0);
} else {
    echo "HEALTH_ENDPOINT_TEST_FAILED: " . $output . PHP_EOL;
    exit(1);
}
