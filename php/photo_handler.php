<?php
header('Content-Type: application/json');

function writeLog(string $message): void
{
    $logDir = __DIR__ . '/../../logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0777, true);
    }
    @error_log($message . PHP_EOL, 3, $logDir . '/php.log');
}

function base64UrlEncode(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function getServiceAccountJson(): ?array
{
    $rawJson = getenv('GOOGLE_SERVICE_ACCOUNT_JSON');
    if (!empty($rawJson)) {
        $json = json_decode($rawJson, true);
        if (is_array($json) && !empty($json['private_key']) && !empty($json['client_email'])) {
            return $json;
        }
    }

    $path = getenv('GOOGLE_SERVICE_ACCOUNT_JSON_PATH') ?: getenv('GOOGLE_APPLICATION_CREDENTIALS');
    if (!empty($path) && is_file($path)) {
        $json = json_decode(file_get_contents($path), true);
        if (is_array($json) && !empty($json['private_key']) && !empty($json['client_email'])) {
            return $json;
        }
    }

    return null;
}

function buildJwt(array $account): string
{
    $header = ['alg' => 'RS256', 'typ' => 'JWT'];
    $now = time();
    $payload = [
        'iss' => $account['client_email'],
        'scope' => 'https://www.googleapis.com/auth/drive.file',
        'aud' => $account['token_uri'],
        'iat' => $now,
        'exp' => $now + 3600,
    ];

    $headerEnc = base64UrlEncode(json_encode($header, JSON_UNESCAPED_SLASHES));
    $payloadEnc = base64UrlEncode(json_encode($payload, JSON_UNESCAPED_SLASHES));
    $signingInput = $headerEnc . '.' . $payloadEnc;

    $privateKey = openssl_pkey_get_private($account['private_key']);
    if ($privateKey === false) {
        throw new RuntimeException('Invalid service account private key');
    }

    openssl_sign($signingInput, $signature, $privateKey, OPENSSL_ALGO_SHA256);
    $sig = base64UrlEncode($signature);

    return $signingInput . '.' . $sig;
}

function getAccessToken(array $account): ?string
{
    try {
        $jwt = buildJwt($account);
    } catch (Throwable $e) {
        writeLog('[drive] JWT build failed: ' . $e->getMessage());
        return null;
    }

    $postData = http_build_query([
        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
        'assertion' => $jwt,
    ]);

    $ch = curl_init($account['token_uri']);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $postData,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $httpCode >= 400) {
        writeLog('[drive] token request failed: HTTP ' . $httpCode . ' response=' . ($response ?: 'empty'));
        return null;
    }

    $decoded = json_decode($response, true);
    if (!empty($decoded['access_token'])) {
        return $decoded['access_token'];
    }

    writeLog('[drive] token response missing access_token: ' . $response);
    return null;
}

function uploadToGoogleDrive(array $account, string $binaryContent, string $fileName): ?array
{
    $folderId = getenv('GOOGLE_DRIVE_FOLDER_ID') ?: getenv('GDRIVE_FOLDER_ID');
    if (empty($folderId)) {
        writeLog('[drive] missing GOOGLE_DRIVE_FOLDER_ID');
        return null;
    }

    $token = getAccessToken($account);
    if (empty($token)) {
        return null;
    }

    $metadata = json_encode(['name' => $fileName, 'parents' => [$folderId]], JSON_UNESCAPED_SLASHES);
    $boundary = '----drive_upload_' . bin2hex(random_bytes(12));
    $body = "--{$boundary}\r\n";
    $body .= "Content-Type: application/json; charset=UTF-8\r\n\r\n";
    $body .= $metadata . "\r\n";
    $body .= "--{$boundary}\r\n";
    $body .= "Content-Type: image/png\r\n\r\n";
    $body .= $binaryContent . "\r\n";
    $body .= "--{$boundary}--\r\n";

    $ch = curl_init('https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart&fields=id,name,webViewLink');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Content-Type: multipart/related; boundary=' . $boundary,
        ],
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $httpCode >= 400) {
        writeLog('[drive] upload failed: HTTP ' . $httpCode . ' ' . ($response ?: 'curl failed'));
        return null;
    }

    $decoded = json_decode($response, true);
    if (!empty($decoded['id'])) {
        return [
            'status' => 'ok',
            'fileId' => $decoded['id'],
            'name' => $decoded['name'] ?? $fileName,
            'link' => $decoded['webViewLink'] ?? null,
        ];
    }

    writeLog('[drive] unexpected upload response: ' . $response);
    return null;
}

$data = json_decode(file_get_contents('php://input'), true);
if (!empty($data['image'])) {
    $imageData = str_replace('data:image/png;base64,', '', $data['image']);
    $imageData = str_replace('data:image/jpeg;base64,', '', $imageData);
    $imageData = str_replace(' ', '+', $imageData);
    $decoded = base64_decode($imageData, true);

    if ($decoded === false) {
        writeLog('[photo] base64 decode failed');
        echo json_encode(['status' => 'decode_failed']);
        exit;
    }

    $serviceAccount = getServiceAccountJson();
    $folderId = getenv('GOOGLE_DRIVE_FOLDER_ID') ?: getenv('GDRIVE_FOLDER_ID');

    if ($serviceAccount && !empty($folderId)) {
        $result = uploadToGoogleDrive($serviceAccount, $decoded, 'selfie-' . time() . '.png');
        if ($result !== null) {
            writeLog('[photo] uploaded to Drive: ' . json_encode($result));
            echo json_encode($result);
            exit;
        }

        writeLog('[photo] Drive upload failed; falling back to local save');
    }

    $logDir = __DIR__ . '/../../logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0777, true);
    }

    $file = $logDir . '/selfie.png';
    $written = file_put_contents($file, $decoded);
    writeLog('[photo] local write=' . ($written !== false ? 'ok' : 'failed') . ' bytes=' . ($written !== false ? $written : 0));
    echo json_encode(['status' => $written !== false ? 'ok' : 'write_failed']);
    exit;
}

writeLog('[photo] missing image payload');
echo json_encode(['status' => 'missing']);
