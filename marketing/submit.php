<?php
// submit.php - Plutio form handler
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Plutio credentials
$clientId = 'XNAlsVL8Q_78AyyFAhcu8LEztPol-M';
$clientSecret = 'wCaTDL-8wPE-NMkBYngbLTvnnfkl5oaBSFC-8XAG';
$subdomain = 'netdust';

// Get form data
$name = trim($_POST['name'] ?? '');
$email = trim($_POST['email'] ?? '');
$organization = trim($_POST['organization'] ?? '');
$website = trim($_POST['website'] ?? '');
$interest = trim($_POST['interest'] ?? $_POST['tier'] ?? ''); // Handle both field names
$message = trim($_POST['message'] ?? '');

// Basic validation
if (empty($name) || empty($email)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Name and email are required']);
    exit;
}

// Get OAuth2 access token
$tokenUrl = 'https://api.plutio.com/v1.11/oauth/token';
$tokenData = [
    'grant_type' => 'client_credentials',
    'client_id' => $clientId,
    'client_secret' => $clientSecret
];

$ch = curl_init($tokenUrl);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query($tokenData),
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/x-www-form-urlencoded',
        'Business: ' . $subdomain
    ],
    CURLOPT_RETURNTRANSFER => true
]);

$tokenResponse = curl_exec($ch);
$tokenHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($tokenHttpCode !== 200) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Something went wrong. Please email me directly.']);
    exit;
}

$tokenData = json_decode($tokenResponse, true);
$accessToken = $tokenData['access_token'] ?? '';

if (empty($accessToken)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to get access token']);
    exit;
}

// Split name into first/last
$nameParts = explode(' ', $name, 2);
$firstName = $nameParts[0];
$lastName = $nameParts[1] ?? '';

// Create person in Plutio
$personData = [
    'name' => [
        'first' => $firstName,
        'last' => $lastName
    ],
    'email' => $email,
    'role' => 'client'
];

$ch = curl_init('https://api.plutio.com/v1.11/people');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($personData),
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $accessToken,
        'Content-Type: application/json',
        'Business: ' . $subdomain
    ],
    CURLOPT_RETURNTRANSFER => true
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode < 200 || $httpCode >= 300) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Something went wrong. Please email me directly.']);
    exit;
}

// Get person ID from response
$personResult = json_decode($response, true);
$personId = $personResult['_id'] ?? null;

// Build task description
$taskDescription = "New enquiry from Stride website\n\n";
$taskDescription .= "Name: $name\n";
$taskDescription .= "Email: $email\n";
if (!empty($organization)) {
    $taskDescription .= "Organization: $organization\n";
}
if (!empty($interest)) {
    $taskDescription .= "Interest: $interest\n";
}
if (!empty($message)) {
    $taskDescription .= "\nMessage:\n$message";
}

// Step 1: Create task with description
$taskTitle = "📩 $name" . (!empty($organization) ? " — $organization" : "");

$descriptionHtml = "<p><strong>New enquiry from Stride website</strong></p>";
$descriptionHtml .= "<p><strong>Name:</strong> " . htmlspecialchars($name) . "</p>";
$descriptionHtml .= "<p><strong>Email:</strong> <a href=\"mailto:" . htmlspecialchars($email) . "\">" . htmlspecialchars($email) . "</a></p>";
if (!empty($organization)) {
    $descriptionHtml .= "<p><strong>Organization:</strong> " . htmlspecialchars($organization) . "</p>";
}
if (!empty($website)) {
    $descriptionHtml .= "<p><strong>Website:</strong> <a href=\"" . htmlspecialchars($website) . "\">" . htmlspecialchars($website) . "</a></p>";
}
if (!empty($interest)) {
    $interestLabels = [
        'online' => 'Online cursussen',
        'klassikaal' => 'Klassikale opleidingen',
        'blended' => 'Blended learning',
        'trajecten' => 'Leertrajecten',
        'lti' => 'LTI / API-integratie',
        'migratie' => 'Migratie van ander platform',
        'anders' => 'Iets anders'
    ];
    $descriptionHtml .= "<p><strong>Interest:</strong> " . ($interestLabels[$interest] ?? htmlspecialchars($interest)) . "</p>";
}
if (!empty($message)) {
    $descriptionHtml .= "<p><strong>Message:</strong></p><p>" . nl2br(htmlspecialchars($message)) . "</p>";
}

$taskData = [
    'title' => $taskTitle,
    'descriptionHTML' => $descriptionHtml
];

$ch = curl_init('https://api.plutio.com/v1.11/tasks');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($taskData),
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $accessToken,
        'Content-Type: application/json',
        'Business: ' . $subdomain
    ],
    CURLOPT_RETURNTRANSFER => true
]);

$taskResponse = curl_exec($ch);
$taskHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// Step 2: Move task to project board's Backlog group
$taskResult = json_decode($taskResponse, true);
$taskId = $taskResult['_id'] ?? null;

if ($taskId) {
    $taskGroupId = 'xCfP8BndFK23LZG3L'; // Backlog group in Atelier 296 Enquiries project
    $moveData = [
        '_id' => $taskId,
        'taskGroupId' => $taskGroupId,
        'position' => 0
    ];

    $ch = curl_init('https://api.plutio.com/v1.11/tasks/move');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($moveData),
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json',
            'Business: ' . $subdomain
        ],
        CURLOPT_RETURNTRANSFER => true
    ]);

    curl_exec($ch);
    curl_close($ch);
}

echo json_encode(['success' => true, 'message' => 'Thanks! I\'ll be in touch soon.']);
