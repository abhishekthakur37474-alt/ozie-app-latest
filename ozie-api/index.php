<?php
// ozie-api router.
// Base URL: https://demo.orderezee.com/ozie-api/v1/auth/login
// Fallback (if server blocks pretty URLs / ModSecurity): https://demo.orderezee.com/ozie-api/index.php?route=v1/auth/login
define('OZIE_API', true);
require __DIR__ . '/core/bootstrap.php';

$routes = [
    // --- auth
    'POST v1/auth/login'             => 'v1/auth/login.php',
    'POST v1/auth/google'            => 'v1/auth/google.php',
    'GET  v1/auth/me'                => 'v1/auth/me.php',
    'POST v1/auth/logout'            => 'v1/auth/logout.php',
    'POST v1/auth/register'          => 'v1/auth/register.php',
    'POST v1/auth/verify-otp'        => 'v1/auth/verify_otp.php',
    'POST v1/auth/resend-otp'        => 'v1/auth/resend_otp.php',
    'POST v1/auth/forgot-password'   => 'v1/auth/forgot_password.php',
    'POST v1/auth/reset-password'    => 'v1/auth/reset_password.php',
    'POST v1/auth/change-password'   => 'v1/auth/change_password.php',

    // --- onboarding
    'POST v1/onboarding/verify-code' => 'v1/onboarding/verify_code.php',
    'POST v1/onboarding/inquiry'     => 'v1/onboarding/inquiry.php',
    'POST v1/onboarding/check-duplicate' => 'v1/onboarding/check_duplicate.php',
    'POST v1/onboarding/submit'      => 'v1/onboarding/submit.php',

    // --- dashboard
    'GET  v1/dashboard/retailer'     => 'v1/dashboard/retailer.php',

    // --- profile
    'GET  v1/profile/get'            => 'v1/profile/get.php',
    'POST v1/profile/update'         => 'v1/profile/update.php',

    // --- retailer
    'GET  v1/retailer/products'      => 'v1/retailer/products.php',
    'POST v1/retailer/products'      => 'v1/retailer/products.php',
    'GET  v1/retailer/review-orders' => 'v1/retailer/review_orders.php',
    'POST v1/retailer/review-orders' => 'v1/retailer/review_orders.php',
    'GET  v1/retailer/orders'        => 'v1/retailer/orders.php',
];

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// 1) ?route=... (works even when pretty URLs are blocked)  2) real URL path
$route = isset($_GET['route']) && is_string($_GET['route']) ? $_GET['route'] : '';
$path  = trim($route, '/');
if ($path === '') {
    $path = request_path();
}
if ($path === 'index.php') {
    $path = '';
}

// Health check: no login, no DB. Use it to test that the API is reachable.
if ($path === 'v1/ping' && $method === 'GET') {
    ok(['time' => date('c'), 'php' => PHP_VERSION], 'pong');
}

$normalized = [];
foreach ($routes as $k => $file) {
    [$m, $p] = preg_split('/\s+/', $k, 2);
    $normalized[$p][$m] = $file;
}

if (!isset($normalized[$path])) {
    fail('Endpoint not found.', 404);
}
if (!isset($normalized[$path][$method])) {
    header('Allow: ' . implode(', ', array_keys($normalized[$path])));
    fail('Method not allowed.', 405);
}

$handler = __DIR__ . '/' . $normalized[$path][$method];
if (!is_file($handler)) {
    error_log('[ozie-api] handler missing: ' . $normalized[$path][$method]);
    fail('This endpoint is not installed on the server.', 501);
}

require $handler;