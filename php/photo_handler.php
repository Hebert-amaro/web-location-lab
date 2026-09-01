<?php
header('Content-Type: application/json');
$data = json_decode(file_get_contents('php://input'), true);
if (!empty($data['image'])) {
    $logDir = __DIR__ . '/../../logs';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0777, true);
    }

    $imageData = str_replace('data:image/png;base64,', '', $data['image']);
    $imageData = str_replace('data:image/jpeg;base64,', '', $imageData);
    $imageData = str_replace(' ', '+', $imageData);
    $decoded = base64_decode($imageData, true);
    if ($decoded === false) {
        error_log("[photo_handler] base64 decode failed\n", 3, $logDir . '/php.log');
        echo json_encode(['status' => 'decode_failed']);
        exit;
    }

    $file = $logDir . '/selfie.png';
    $written = file_put_contents($file, $decoded);
    error_log("[photo_handler] wrote=" . ($written !== false ? $written : 'false') . " bytes to $file\n", 3, $logDir . '/php.log');
    echo json_encode(['status' => $written !== false ? 'ok' : 'write_failed']);
} else {
    error_log("[photo_handler] missing image payload\n", 3, __DIR__ . '/../../logs/php.log');
    echo json_encode(['status' => 'missing']);
}
