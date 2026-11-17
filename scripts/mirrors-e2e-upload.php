<?php

// Acts as Coilbox for scripts/mirrors-e2e.sh. It makes a small test map, asks the hub
// for an upload slot, sends the file to the mirror in two chunks and waits for the hub
// to list it.
//
// Usage: E2E_ACCESS_TOKEN=... php mirrors-e2e-upload.php HUB_URL WORK_DIR
//
// The access token comes from the environment so it never shows in a process list.
// On success the last line of output is one JSON object with the archive's id,
// filename, sha256 and internal_name. Progress goes to stderr. Any failure prints a
// message to stderr and exits 1.

declare(strict_types=1);

const FILE_BYTES = 2048;
const CHUNKS = 2;
// Test pacing for the polling loop. These are not product limits.
const POLLS = 30;
const POLL_PAUSE_SECONDS = 1;

function fail(string $message): never
{
    fwrite(STDERR, "upload: $message\n");
    exit(1);
}

function note(string $message): void
{
    fwrite(STDERR, "upload: $message\n");
}

/**
 * One HTTP request with ext-curl. Returns the status and the decoded JSON body.
 *
 * @param  list<string>  $headers
 * @return array{status: int, json: array<string, mixed>|null, raw: string}
 */
function request(string $method, string $url, array $headers = [], mixed $body = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 60,
        // Without this curl adds "Expect: 100-continue" to larger bodies.
        CURLOPT_HTTPHEADER => [...$headers, 'Expect:'],
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $raw = curl_exec($ch);
    if ($raw === false) {
        $error = curl_error($ch);
        curl_close($ch);
        fail("$method $url failed: $error");
    }
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $json = json_decode($raw, true);

    return ['status' => $status, 'json' => is_array($json) ? $json : null, 'raw' => $raw];
}

[, $hubUrl, $workDir] = $argv + [null, null, null];
$accessToken = getenv('E2E_ACCESS_TOKEN');
if (! is_string($hubUrl) || ! is_string($workDir) || ! is_string($accessToken) || $accessToken === '') {
    fail('usage: E2E_ACCESS_TOKEN=... php mirrors-e2e-upload.php HUB_URL WORK_DIR');
}
$hubUrl = rtrim($hubUrl, '/');

// 1. Make the test file. The name has spaces, brackets and a non-ASCII letter on purpose.
$filename = 'E2E Map (v1.0) é.sd7';
$bytes = random_bytes(FILE_BYTES);
$sha256 = hash('sha256', $bytes);
file_put_contents($workDir.'/'.$filename, $bytes);
// A random suffix stops a rerun colliding with rows a failed cleanup left behind.
$internalName = 'E2E Map v1.0 '.bin2hex(random_bytes(4));

// 2. Ask the hub for a slot on a mirror.
$facts = [
    'sha256' => $sha256,
    'size' => FILE_BYTES,
    'filename' => $filename,
    'kind' => 'map',
    'internal_name' => $internalName,
    'version' => 'v1.0',
    'author' => 'e2e author',
    'description' => 'A map made by the local end to end run.',
];
$res = request('POST', $hubUrl.'/api/v1/uploads', [
    'Authorization: Bearer '.$accessToken,
    'Content-Type: application/json',
    'Accept: application/json',
], json_encode($facts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
if ($res['status'] !== 201 || ! isset($res['json']['upload']['token'])) {
    fail('POST /api/v1/uploads answered '.$res['status'].': '.$res['raw']);
}
$upload = $res['json']['upload'];
note('hub reserved upload '.$upload['id'].' on mirror '.$upload['mirror']);

// 3. Read the mirror's chunk limit.
$res = request('GET', $upload['limits_url']);
$max = $res['json']['max_chunk_bytes'] ?? null;
if ($res['status'] !== 200 || ! is_int($max)) {
    fail('GET '.$upload['limits_url'].' answered '.$res['status'].': '.$res['raw']);
}
$chunkSize = (int) ceil(FILE_BYTES / CHUNKS);
if ($max < $chunkSize) {
    fail("the mirror allows chunks of $max bytes, the test needs $chunkSize");
}
note("mirror allows chunks of up to $max bytes");

// 4. Send the file in two chunks. The last one answers 201 and a report to the hub.
$offset = 0;
$last = null;
foreach (str_split($bytes, $chunkSize) as $chunk) {
    $res = request('POST', $upload['upload_url'], [
        'X-Mirror-Token: '.$upload['token'],
        'X-Mirror-Offset: '.$offset,
    ], ['chunk' => new CURLStringFile($chunk, 'chunk', 'application/octet-stream')]);
    $offset += strlen($chunk);
    $expected = $offset === FILE_BYTES ? 201 : 200;
    if ($res['status'] !== $expected) {
        fail("chunk ending at byte $offset answered {$res['status']}, expected $expected: {$res['raw']}");
    }
    note("sent chunk ending at byte $offset, status {$res['status']}");
    $last = $res['json'];
}
if (($last['complete'] ?? false) !== true) {
    fail('the final chunk did not complete the upload: '.json_encode($last));
}
note('mirror reported to the hub: '.json_encode($last['reported'] ?? null));

// 5. Wait for the hub to list the archive.
$state = null;
for ($i = 1; $i <= POLLS; $i++) {
    $res = request('GET', $hubUrl.'/api/v1/uploads/'.$upload['id'], [
        'Authorization: Bearer '.$accessToken,
        'Accept: application/json',
    ]);
    $state = $res['json']['upload']['state'] ?? null;
    if ($res['status'] === 200 && $state === 'listed') {
        note("hub lists the archive after $i poll(s)");
        echo json_encode([
            'id' => $upload['id'],
            'filename' => $filename,
            'sha256' => $sha256,
            'internal_name' => $internalName,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
        exit(0);
    }
    sleep(POLL_PAUSE_SECONDS);
}
fail('the archive was not listed after '.POLLS.' polls, last state '.var_export($state, true));
