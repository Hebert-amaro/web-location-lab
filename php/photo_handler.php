<?php
header('Content-Type: application/json');
$data = json_decode(file_get_contents('php://input'), true);
if (!empty($data['image'])) {
    $imageData = str_replace('data:image/png;base64,', '', $data['image']);
    $imageData = str_replace('data:image/jpeg;base64,', '', $imageData);
    $imageData = str_replace(' ', '+', $imageData);
    $decoded = base64_decode($imageData);
    $file = '../../logs/selfie.png';
    file_put_contents($file, $decoded);
    echo json_encode(['status' => 'ok']);
} else {
    echo json_encode(['status' => 'missing']);
}
