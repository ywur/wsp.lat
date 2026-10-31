<?php
// bridge.php - Purity Bridge Backend (PHP Version)
// Upload to https://wsp.lat/bridge.php

header("Content-Type: text/plain");
header("Access-Control-Allow-Origin: *");

// --- CONFIG ---
$SECRET = "super_secret_token_change_me"; // Change this!
$QUEUE_FILE = __DIR__ . "/queue.json";
$LOG_FILE = __DIR__ . "/log.json";

// Load state
function loadState($file) {
    if (!file_exists($file)) return [];
    $data = file_get_contents($file);
    return json_decode($data, true) ?: [];
}

// Save state
function saveState($file, $data) {
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));
}

// Auth check
$token = $_GET['token'] ?? '';
if ($token !== $SECRET) {
    http_response_code(401);
    die("unauthorized");
}

$action = $_GET['action'] ?? '';
$name = $_GET['name'] ?? '';

// --- LISTEN (Roblox polls this) ---
if ($action === 'listen') {
    $queue = loadState($QUEUE_FILE);
    
    // If there's a script for this user, pop it
    if (!empty($queue[$name])) {
        $src = array_shift($queue[$name]);
        saveState($QUEUE_FILE, $queue);
        die($src);
    }
    
    // Otherwise wait up to 15s (so Roblox doesn't timeout)
    $start = time();
    while (time() - $start < 15) {
        usleep(500000); // 0.5s
        $queue = loadState($QUEUE_FILE);
        if (!empty($queue[$name])) {
            $src = array_shift($queue[$name]);
            saveState($QUEUE_FILE, $queue);
            die($src);
        }
    }
    
    die("WAIT");
}

// --- RESULT (Roblox reports back) ---
if ($action === 'result') {
    $body = file_get_contents("php://input");
    $data = json_decode($body, true) ?: [];
    
    $logs = loadState($LOG_FILE);
    array_unshift($logs, [
        "id" => $data['id'] ?? 'UNKNOWN',
        "ok" => $data['ok'] ?? false,
        "out" => substr($data['out'] ?? '', 0, 500),
        "time" => date("H:i:s")
    ]);
    $logs = array_slice($logs, 0, 30); // Keep last 30
    saveState($LOG_FILE, $logs);
    
    die("ok");
}

// --- PUSH (You send scripts from the web panel) ---
if ($action === 'push') {
    $body = json_decode(file_get_contents("php://input"), true) ?: [];
    $target = $body['target'] ?? 'ALL';
    $src = $body['src'] ?? '';
    
    if (!$src) die("empty src");
    
    $queue = loadState($QUEUE_FILE);
    
    if ($target === 'ALL') {
        // Push to every connected user
        $users = array_keys($queue);
        foreach ($users as $u) {
            $queue[$u][] = $src;
        }
    } else {
        // Push to specific user
        if (!isset($queue[$target])) $queue[$target] = [];
        $queue[$target][] = $src;
    }
    
    saveState($QUEUE_FILE, $queue);
    die("queued");
}

// --- CLIENTS (List who's connected) ---
if ($action === 'clients') {
    $queue = loadState($QUEUE_FILE);
    die(json_encode(array_keys($queue)));
}

// --- PING ---
if ($action === 'ping') {
    die("pong");
}

http_response_code(404);
die("not found");
?>
