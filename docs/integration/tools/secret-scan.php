<?php

/**
 * Full-history secret scan.
 *
 * Reads a `git cat-file --batch` stream on stdin, fed from `git rev-list --objects --all`, so it
 * sees every blob **reachable from any ref** — the whole history, not just the working tree.
 *
 * Deliberately NOT `--batch-all-objects`. That also yields dangling objects left by aborted
 * operations, `git add` followed by a reset, and rebases: blobs no commit references, which can
 * never be pushed and which no history rewrite would remove. Reporting them produces alarms
 * nobody can action, and an alarm nobody can action is how a gate stops being read.
 *
 * Usage:
 *   git rev-list --objects --all > objects.txt
 *   awk '{print $1}' objects.txt | git cat-file --batch --buffer | php secret-scan.php <repo> objects.txt
 *
 * Path attribution comes from the same object list.
 */
$repo = $argv[1] ?? '.';
$mapFile = $argv[2] ?? null;
$allowFile = $argv[3] ?? 'docs/integration/secret-scan-allowlist.txt';

/*
 * ACCEPTED FINDINGS, REVIEWED ONCE AND RECORDED.
 *
 * A scanner with no way to accept a finding is a scanner that is permanently red, and a gate
 * that is permanently red is one everybody learns to ignore — which is worse than not having
 * it. The alternative people reach for is weakening the pattern, which blinds the scanner to
 * the real thing as well.
 *
 * So the pattern stays sharp and each accepted hit is listed by `rule|path` with a reason a
 * reviewer wrote. Adding a line is a visible diff on a file whose whole purpose is to be
 * argued with.
 */
$allowed = [];
if (is_readable($allowFile)) {
    foreach (file($allowFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#')) {
            continue;
        }
        $key = trim(explode('#', $line, 2)[0]);
        if ($key !== '') {
            $allowed[$key] = true;
        }
    }
}

$paths = [];
if ($mapFile && is_readable($mapFile)) {
    foreach (file($mapFile, FILE_IGNORE_NEW_LINES) as $line) {
        $sp = strpos($line, ' ');
        if ($sp === false) {
            continue;
        }
        $sha = substr($line, 0, $sp);
        $p = substr($line, $sp + 1);
        if ($p !== '') {
            $paths[$sha][$p] = true;
        }
    }
}

$patterns = [
    'private-key' => '/-----BEGIN (?:RSA |DSA |EC |OPENSSH |PGP )?PRIVATE KEY-----/',
    'aws-access-key-id' => '/\b(?:AKIA|ASIA)[0-9A-Z]{16}\b/',
    'google-api-key' => '/\bAIza[0-9A-Za-z_\-]{35}\b/',
    'github-token' => '/\bgh[pousr]_[A-Za-z0-9]{36}\b|\bgithub_pat_[A-Za-z0-9_]{22,}/',
    'slack-token' => '/\bxox[abprs]-[A-Za-z0-9-]{10,}/',
    'stripe-live-key' => '/\bsk_live_[A-Za-z0-9]{20,}/',
    'jwt' => '/\beyJ[A-Za-z0-9_\-]{10,}\.[A-Za-z0-9_\-]{10,}\.[A-Za-z0-9_\-]{10,}/',
    'dsn-with-password' => '/\b(?:postgres|postgresql|mysql|redis|mongodb)(?:\+srv)?:\/\/[^:\s\/@]+:[^@\s]+@/',
    'laravel-app-key' => '/base64:[A-Za-z0-9+\/]{40,}={0,2}/',
    'gcp-service-account' => '/"private_key_id"\s*:/',
    'assigned-secret' => '/(?i)\b(?:password|passwd|secret|token|api[_-]?key|access[_-]?key|private[_-]?key|client[_-]?secret)\b\s*[:=]>?\s*[\'"]([^\'"\s]{8,})[\'"]/',
];

// Values that are obviously not live credentials.
$placeholder = '/^(?:your[-_].*|change[-_]?me|example.*|placeholder.*|xxx+|\.{3,}|null|true|false|secret|password|passw0rd|s3cret|test.*|dummy.*|fake.*|sample.*|redacted.*|<.*>|\$\{.*\}|%s|.*_here|base64:.{0,10})$/i';

$findings = [];
$accepted = [];
$blobs = 0;
$scanned = 0;

$fh = fopen('php://stdin', 'rb');
while (! feof($fh)) {
    $header = fgets($fh);
    if ($header === false) {
        break;
    }
    $header = rtrim($header, "\n");
    if ($header === '') {
        continue;
    }
    $parts = explode(' ', $header);
    if (count($parts) < 3) {
        continue;
    }
    [$sha, $type, $size] = $parts;
    $size = (int) $size;

    $content = $size > 0 ? fread($fh, $size) : '';
    while (strlen($content) < $size) {
        $chunk = fread($fh, $size - strlen($content));
        if ($chunk === false || $chunk === '') {
            break;
        }
        $content .= $chunk;
    }
    fgets($fh); // trailing newline

    if ($type !== 'blob') {
        continue;
    }
    $blobs++;

    // Skip binaries and very large blobs (lockfiles, images, minified bundles).
    if ($size > 1_500_000) {
        continue;
    }
    if (strpos($content, "\0") !== false) {
        continue;
    }
    $scanned++;

    foreach ($patterns as $name => $re) {
        if (! preg_match_all($re, $content, $m, PREG_OFFSET_CAPTURE)) {
            continue;
        }
        foreach ($m[0] as $i => $hit) {
            $value = $m[1][$i][0] ?? $hit[0];
            if ($name === 'assigned-secret' && preg_match($placeholder, $value)) {
                continue;
            }
            $line = substr_count(substr($content, 0, $hit[1]), "\n") + 1;
            $where = isset($paths[$sha]) ? implode(', ', array_keys($paths[$sha])) : '(unreferenced blob)';
            $key = $name.'|'.$where.'|'.substr($hit[0], 0, 60);
            if (isset($findings[$key])) {
                continue;
            }
            if (isset($allowed[$name.'|'.$where])) {
                $accepted[$name.'|'.$where] = true;

                continue;
            }
            $findings[$key] = [
                'rule' => $name,
                'path' => $where,
                'blob' => substr($sha, 0, 10),
                'line' => $line,
                'match' => substr(preg_replace('/\s+/', ' ', $hit[0]), 0, 100),
            ];
        }
    }
}

echo "repo: $repo\n";
echo "blobs seen: $blobs   text blobs scanned: $scanned\n";
echo 'accepted (reviewed, in the allowlist): '.count($accepted)."\n";
echo 'findings: '.count($findings)."\n";
if ($findings) {
    echo str_repeat('-', 100)."\n";
    foreach ($findings as $f) {
        printf("[%s] %s:%d (blob %s)\n    %s\n", $f['rule'], $f['path'], $f['line'], $f['blob'], $f['match']);
    }
}
