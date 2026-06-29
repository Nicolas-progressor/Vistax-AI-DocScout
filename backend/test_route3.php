<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';

$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);

echo "Testing route: /api/documents/1/analyze\n";

try {
    $request = Illuminate\Http\Request::create('/api/documents/1/analyze', 'GET', ['preset' => 'legal_audit']);
    $response = $kernel->handle($request);
    echo "Status: " . $response->getStatusCode() . "\n";
    echo "Content: " . substr($response->getContent(), 0, 500) . "\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
}

$kernel->terminate($request, $response);
