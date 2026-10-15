<?php
/**
 * php-webhook-deploy — GitHub webhook receiver that runs per-repo deploy commands.
 *
 * 1. cp config.example.php config.php (lives NEXT to public/, not inside it)
 * 2. Point your GitHub repo's webhook at https://yourdomain.com/webhook.php
 *    (Content-Type: application/json, secret = the one in config.php)
 *
 * Verifies the HMAC-SHA256 signature, matches the repo + branch, executes the
 * configured commands with a timeout, and appends a JSON line to the deploy log.
 * A GET request with ?status=1 returns the last deployments (no secret needed
 * to *read* — put this behind basic auth or a firewall if that bothers you).
 */

$configFile = __DIR__ . '/../config.php';
if (!file_exists($configFile)) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(array('ok' => false, 'error' => 'config.php missing — copy config.example.php'));
    exit;
}
$config = require $configFile;

// --- Status endpoint ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['status'])) {
    header('Content-Type: application/json');
    echo json_encode(array('ok' => true, 'deployments' => webhook_recent($config, 20)));
    exit;
}

// --- Only GitHub POSTs from here ---------------------------------------------
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$payload   = file_get_contents('php://input');
$signature = isset($_SERVER['HTTP_X_HUB_SIGNATURE_256']) ? $_SERVER['HTTP_X_HUB_SIGNATURE_256'] : '';

if ($signature === '' || !hash_equals('sha256=' . hash_hmac('sha256', $payload, $config['secret']), $signature)) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(array('ok' => false, 'error' => 'bad signature'));
    exit;
}

$event = isset($_SERVER['HTTP_X_GITHUB_EVENT']) ? $_SERVER['HTTP_X_GITHUB_EVENT'] : 'ping';
if ($event === 'ping') {
    header('Content-Type: application/json');
    echo json_encode(array('ok' => true, 'message' => 'pong — webhook configured correctly'));
    exit;
}
if ($event !== 'push') {
    http_response_code(200);
    header('Content-Type: application/json');
    echo json_encode(array('ok' => true, 'message' => 'ignored event: ' . $event));
    exit;
}

$data   = json_decode($payload, true);
$repo   = isset($data['repository']['full_name']) ? $data['repository']['full_name'] : '';
$branch = isset($data['ref']) ? preg_replace('#^refs/heads/#', '', $data['ref']) : '';

if (!isset($config['repos'][$repo])) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(array('ok' => false, 'error' => 'repo not configured'));
    exit;
}

$repoCfg = $config['repos'][$repo];
if (isset($repoCfg['branch']) && $repoCfg['branch'] !== '' && $repoCfg['branch'] !== $branch) {
    header('Content-Type: application/json');
    echo json_encode(array('ok' => true, 'message' => 'branch ' . $branch . ' not deployed (watches ' . $repoCfg['branch'] . ')'));
    exit;
}

// --- Run the deploy commands --------------------------------------------------
$results = array();
foreach ((array) $repoCfg['commands'] as $cmd) {
    $results[] = webhook_run($cmd, isset($repoCfg['timeout']) ? (int) $repoCfg['timeout'] : 120);
}

$record = array(
    'time'   => gmdate('c'),
    'repo'   => $repo,
    'branch' => $branch,
    'commit' => isset($data['after']) ? substr((string) $data['after'], 0, 7) : '',
    'ok'     => webhook_all_ok($results),
    'steps'  => $results,
);
webhook_log($config, $record);

header('Content-Type: application/json');
http_response_code($record['ok'] ? 200 : 500);
echo json_encode(array('ok' => $record['ok'], 'steps' => count($results)));

// --- Helpers ------------------------------------------------------------------

/**
 * Run one shell command with a hard timeout; never interpolates user input —
 * commands come from your own config.php only.
 */
function webhook_run($cmd, $timeout)
{
    $descriptors = array(
        0 => array('pipe', 'r'),
        1 => array('pipe', 'w'),
        2 => array('pipe', 'w'),
    );
    $start = microtime(true);
    $proc = proc_open($cmd, $descriptors, $pipes);
    if (!is_resource($proc)) {
        return array('cmd' => $cmd, 'ok' => false, 'exit' => -1, 'output' => 'proc_open failed');
    }
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $out = '';
    $exit = -1;
    while (true) {
        $status = proc_get_status($proc);
        $out .= stream_get_contents($pipes[1]);
        $out .= stream_get_contents($pipes[2]);
        if (!$status['running']) {
            $exit = $status['exitcode'];
            break;
        }
        if (microtime(true) - $start > $timeout) {
            proc_terminate($proc, 9);
            $exit = 124;
            $out .= "\n[TIMEOUT after {$timeout}s]";
            break;
        }
        usleep(100000);
    }
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);

    return array(
        'cmd'    => $cmd,
        'ok'     => $exit === 0,
        'exit'   => $exit,
        'output' => trim(substr($out, -2000)), // keep the tail; full logs go to the deploy log
    );
}

function webhook_all_ok($results)
{
    foreach ($results as $r) {
        if (!$r['ok']) {
            return false;
        }
    }
    return true;
}

function webhook_log($config, $record)
{
    $dir = rtrim($config['log_dir'], '/');
    if (!is_dir($dir)) {
        mkdir($dir, 0750, true);
    }
    file_put_contents(
        $dir . '/deployments.log',
        json_encode($record) . "\n",
        FILE_APPEND | LOCK_EX
    );
}

function webhook_recent($config, $limit)
{
    $file = rtrim($config['log_dir'], '/') . '/deployments.log';
    if (!file_exists($file)) {
        return array();
    }
    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $lines = array_slice($lines, -$limit);
    $out = array();
    foreach ($lines as $line) {
        $row = json_decode($line, true);
        if (is_array($row)) {
            unset($row['steps']); // keep the status endpoint light
            $out[] = $row;
        }
    }
    return array_reverse($out);
}
