<?php

declare(strict_types=1);

/**
 * A stub router for `php -S 127.0.0.1:<port>` (`DemoStreamTransportTest`): it answers a few fixed paths so the walkthrough
 * script's real stream transport can be exercised over a socket. It opens no database, boots no kernel, loads no dotenv
 * file, writes no file and reads no environment value.
 */
$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), \PHP_URL_PATH);
header('Content-Type: application/json');
switch ($path) {
    case '/redirect':
        http_response_code(302);
        header('Location: /followed');
        echo '{}';
        break;
    case '/followed':
        echo json_encode(['followed' => true]);
        break;
    case '/echo':
        echo json_encode([
            'method' => $_SERVER['REQUEST_METHOD'] ?? null,
            'authorization' => $_SERVER['HTTP_AUTHORIZATION'] ?? null,
            'content_type' => $_SERVER['CONTENT_TYPE'] ?? null,
            'body' => file_get_contents('php://input'),
        ]);
        break;
    case '/problem':
        http_response_code(409);
        header('Content-Type: application/problem+json');
        echo json_encode(['type' => 'urn:erp:problem:business-rule-violated', 'rule' => 'demo.stub_rule', 'detail' => 'a detail the script must not print']);
        break;
    default:
        http_response_code(404);
        echo '{}';
}
