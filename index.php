<?php

require __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

$domain = $_ENV['DOMAIN'];
$apiBaseUrl = $_ENV['IONOS_API_URL'];
$apiToken = $_ENV['IONOS_API_TOKEN'];

$result = "";

function apiRequest($method, $url, $payload = null, $token = null) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer $token",
        "Content-Type: application/json",
        "User-Agent: DNS-URL-Shortener/1.0"
    ]);
    if ($payload !== null)
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));

    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$status, $response];
}

/* ===============================================
   FORM SUBMISSION PROCESSING
   =============================================== */
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $url = trim($_POST["url"] ?? "");

    if (empty($url)) {
        $result = ["code" => 400, "message" => "Missing URL."];
    } elseif (!filter_var($url, FILTER_VALIDATE_URL)) {
        $result = ["code" => 400, "message" => "Invalid URL format."];
    } elseif (strlen($url) > 2048) {
        $result = ["code" => 400, "message" => "URL too long (max 2048 chars)."];
    } else {

        $shortCode = substr(md5($url), 0, 6);
        $recordName = "{$shortCode}.{$domain}";

        // Check locally via DNS
        $records = dns_get_record($recordName, DNS_TXT);
        if (!empty($records) && $records[0]["txt"] === $url) {
            $result = [
                "code" => 201,
                "message" => "URL already exists.",
                "short_url" => "https://{$domain}/{$shortCode}"
            ];
        } else {
            // 1️⃣ Retrieve zones
            [$status, $response] = apiRequest("GET", "$apiBaseUrl/zones", null, $apiToken);

            if ($status === 401) {
                $result = ["code" => 401, "message" => "Invalid API token."];
            } elseif ($status !== 200) {
                $result = ["code" => 400, "message" => "Cannot retrieve zones (HTTP $status)."];
            } else {
                $zones = json_decode($response, true);

                // Find zone for this domain
                $zoneId = null;
                foreach ($zones as $zone) {
                    if ($zone["name"] === $domain) {
                        $zoneId = $zone["id"];
                        break;
                    }
                }

                if (!$zoneId) {
                    $result = ["code" => 400, "message" => "Zone not found in IONOS for domain $domain."];
                } else {
                    // 2️⃣ Create TXT record (POST)
                    $payload = [
                        [
                            "name" => $shortCode,
                            "type" => "TXT",
                            "content" => $url,
                            "ttl" => 60  // 1 minute TTL, faster propagation
                        ]
                    ];

                    [$statusPost, $responsePost] =
                        apiRequest("POST", "$apiBaseUrl/zones/$zoneId/records", $payload, $apiToken);

                    if ($statusPost === 201 || $statusPost === 200) {
                        $result = [
                            "code" => 201,
                            "message" => "✅ TXT record created successfully.",
                            "short_url" => "https://{$domain}/{$shortCode}"
                        ];
                    } else {
                        $result = [
                            "code" => 400,
                            "message" => "❌ Error creating DNS record (HTTP $statusPost)."
                        ];
                    }
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DNS URL Shortener</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f2f2f2; padding: 40px; }
        .container { max-width: 600px; background: #fff; padding: 30px; border-radius: 10px; box-shadow: 0 0 10px rgba(0,0,0,0.1); margin: auto; }
        h1 { text-align: center; color: #333; }
        input[type="url"] { width: 100%; padding: 10px; border-radius: 6px; border: 1px solid #ccc; margin-bottom: 20px; }
        button { background: #0078d7; color: white; padding: 10px 20px; border: none; border-radius: 6px; cursor: pointer; }
        button:hover { background: #005fa3; }
        .result { margin-top: 20px; padding: 10px; border-radius: 6px; }
        .success { background: #e0ffe0; border: 1px solid #00a000; }
        .error { background: #ffe0e0; border: 1px solid #a00000; }
    </style>
</head>
<body>
    <div class="container">
        <h1>DNS URL Shortener</h1>
        <form method="POST">
            <label for="url">Enter a URL to shorten:</label>
            <input type="url" name="url" id="url" placeholder="https://example.com/page" required>
            <button type="submit">Shorten</button>
        </form>

        <?php if ($result): ?>
            <div class="result <?= $result['code'] == 201 ? 'success' : 'error' ?>">
                <strong>Status <?= $result['code'] ?>:</strong>
                <p><?= htmlspecialchars($result['message']) ?></p>
                <?php if (!empty($result['short_url'])): ?>
                    <p><a href="<?= htmlspecialchars($result['short_url']) ?>" target="_blank"><?= htmlspecialchars($result['short_url']) ?></a></p>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>