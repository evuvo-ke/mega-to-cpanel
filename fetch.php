<?php
/**
 * fetch.php - Server-side downloader for cPanel / shared hosting.
 *
 * Pulls a file from a normal URL (Cloudflare R2, CDN, direct link...) or from a
 * mega.nz file link straight into a folder on your hosting account.
 * Mega files are decrypted on the fly. Nothing passes through your desktop.
 *
 * SETUP
 *   1. Set $PASSWORD below.
 *   2. Upload to public_html and open https://yourdomain.com/fetch.php
 *   3. DELETE this file when you're done with it.
 *
 * Requires PHP 7+ with the cURL and OpenSSL extensions (standard on cPanel).
 */

// ------------------------------- CONFIG -------------------------------
$PASSWORD    = 'change-this-password';   // REQUIRED: change this
$DEST_DIR    = __DIR__ . '/data';        // use __DIR__ . '/shared' if you prefer
$JOBS_DIR    = __DIR__ . '/.fetch_jobs'; // progress files (auto-created, blocked from web)
$BLOCKED_EXT = ['php','phtml','php3','php4','php5','php7','php8','phar','pht','htaccess','cgi','pl','py','sh'];
// ----------------------------------------------------------------------

@set_time_limit(0);
@ini_set('memory_limit', '256M');
@ini_set('display_errors', '0');
@ini_set('zlib.output_compression', '0');
ignore_user_abort(true);

foreach ([$DEST_DIR, $JOBS_DIR] as $d) {
    if (!is_dir($d)) @mkdir($d, 0755, true);
}

$denyPhp = "Options -Indexes\n<FilesMatch \"\\.(php|phtml|phar|pht|php[3-8])$\">\n"
         . "  <IfModule mod_authz_core.c>\n    Require all denied\n  </IfModule>\n"
         . "  <IfModule !mod_authz_core.c>\n    Deny from all\n  </IfModule>\n</FilesMatch>\n";
$denyAll = "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n"
         . "<IfModule !mod_authz_core.c>\n  Deny from all\n</IfModule>\n";
$ht = $DEST_DIR . '/.htaccess';
if (!file_exists($ht) || strpos((string)@file_get_contents($ht), 'php_flag') !== false) {
    @file_put_contents($ht, $denyPhp);
}
if (!file_exists($JOBS_DIR . '/.htaccess')) {
    @file_put_contents($JOBS_DIR . '/.htaccess', $denyAll);
}

/* ============================== helpers ============================== */

class FetchError extends Exception {}

function out(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data);
    exit;
}

function safe_name(string $name): string {
    $name = basename(rawurldecode($name));
    $name = preg_replace('/[^\p{L}\p{N}._ -]+/u', '_', $name);
    $name = trim($name, '. _');
    return $name !== '' ? $name : 'download_' . date('Ymd_His');
}

function is_public_host(?string $host): bool {
    if (!$host) return false;
    $ips = gethostbynamel($host);
    if (!$ips) return false;
    foreach ($ips as $ip) {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }
    }
    return true;
}

function b64url_decode(string $s): string {
    $s = strtr($s, '-_', '+/');
    $s .= str_repeat('=', (4 - strlen($s) % 4) % 4);
    return (string)base64_decode($s, true);
}

/** Collects size + filename from response headers (resets on each redirect hop). */
function header_collector(array &$h): callable {
    return function ($ch, $line) use (&$h) {
        if (stripos($line, 'HTTP/') === 0) {
            $h = ['size' => 0, 'name' => null];
        } elseif (stripos($line, 'content-length:') === 0) {
            $h['size'] = (int)trim(substr($line, 15));
        } elseif (stripos($line, 'content-disposition:') === 0
            && preg_match('/filename\*?=(?:UTF-8\'\')?"?([^";\r\n]+)"?/i', $line, $m)) {
            $h['name'] = $m[1];
        }
        return strlen($line);
    };
}

/* ============================ Mega support ============================ */

function parse_mega(string $url): ?array {
    if (preg_match('~mega\.nz/(?:file/|embed/|#!)([\w-]{8})[#!]([\w-]{43})~', $url, $m)) {
        return [$m[1], $m[2]];
    }
    return null;
}

function is_mega_folder(string $url): bool {
    return (bool)preg_match('~mega\.nz/(?:folder/|#F!)~', $url);
}

function mega_error(int $code): string {
    $map = [
        -3  => 'Mega is busy right now, try again in a moment.',
        -4  => 'Mega rate limit hit, wait a few minutes and try again.',
        -8  => 'This Mega link has expired.',
        -9  => 'File not found. The link may have been removed or is incorrect.',
        -14 => 'The decryption key in the link is invalid.',
        -16 => 'This Mega file has been blocked (terms-of-service violation).',
        -17 => 'Mega transfer quota exceeded for this server. Try again later.',
        -18 => 'This file is temporarily unavailable on Mega.',
    ];
    return $map[$code] ?? "Mega returned error code $code.";
}

function mega_api(array $req): array {
    $ch = curl_init('https://g.api.mega.co.nz/cs?id=' . random_int(1, 999999999));
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode([$req]),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; ServerFetch/2.0)',
    ]);
    $body = curl_exec($ch);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($body === false) throw new FetchError('Could not reach Mega: ' . $err);

    $data = json_decode($body, true);
    if (is_int($data)) throw new FetchError(mega_error($data));
    $r = is_array($data) ? ($data[0] ?? null) : null;
    if (is_int($r)) throw new FetchError(mega_error($r));
    if (!is_array($r)) throw new FetchError('Unexpected response from Mega.');
    return $r;
}

function mega_file(string $handle, string $keyB64): array {
    $raw = b64url_decode($keyB64);
    if (strlen($raw) !== 32) throw new FetchError('The key in that Mega link looks incomplete.');

    $k     = array_values(unpack('N8', $raw));
    $key   = pack('N4', $k[0] ^ $k[4], $k[1] ^ $k[5], $k[2] ^ $k[6], $k[3] ^ $k[7]);
    $nonce = pack('N2', $k[4], $k[5]);

    $r = mega_api(['a' => 'g', 'g' => 1, 'ssl' => 1, 'p' => $handle]);
    if (empty($r['g']) || empty($r['at'])) throw new FetchError('Mega did not return a download address.');

    $attr = openssl_decrypt(b64url_decode($r['at']), 'aes-128-cbc', $key,
        OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, str_repeat("\0", 16));
    $name = null;
    if ($attr !== false && strncmp($attr, 'MEGA{', 5) === 0) {
        $j = json_decode(rtrim(substr($attr, 4), "\0"), true);
        $name = $j['n'] ?? null;
    }
    if ($name === null) throw new FetchError('Could not decrypt the file details. Is the link key correct?');

    return ['name' => $name, 'size' => (int)($r['s'] ?? 0), 'url' => $r['g'], 'key' => $key, 'nonce' => $nonce];
}

/** Streaming AES-128-CTR decryptor (Mega's file cipher). */
class CtrDecryptor {
    private $key, $nonce, $buf = '', $block = 0;

    public function __construct(string $key, string $nonce) {
        $this->key = $key;
        $this->nonce = $nonce;
    }

    private function dec(string $chunk): string {
        $iv  = $this->nonce . pack('J', $this->block);
        $out = openssl_decrypt($chunk, 'aes-128-ctr', $this->key, OPENSSL_RAW_DATA, $iv);
        if ($out === false) throw new FetchError('Decryption failed.');
        $this->block += intdiv(strlen($chunk) + 15, 16);
        return $out;
    }

    public function update(string $data): string {
        $this->buf .= $data;
        if (strlen($this->buf) < 262144) return '';          // batch up ~256 KB
        $n   = intdiv(strlen($this->buf), 16) * 16;          // whole AES blocks only
        $out = $this->dec(substr($this->buf, 0, $n));
        $this->buf = (string)substr($this->buf, $n);
        return $out;
    }

    public function finish(): string {
        if ($this->buf === '') return '';
        $out = $this->dec($this->buf);
        $this->buf = '';
        return $out;
    }
}

/* ============================ job tracking ============================ */

class Job {
    public $file, $cancelFile;
    private $state = [];

    public function __construct(string $dir, string $id) {
        $this->file = "$dir/$id.json";
        $this->cancelFile = "$dir/$id.cancel";
    }
    public function set(array $f): void {
        $this->state = array_merge($this->state, $f, ['updated' => time()]);
        $tmp = $this->file . '.tmp';
        file_put_contents($tmp, json_encode($this->state), LOCK_EX);
        @rename($tmp, $this->file);
    }
    public function cancelled(): bool { return file_exists($this->cancelFile); }
}

function respond_and_continue(array $payload): void {
    ignore_user_abort(true);
    $body = json_encode($payload);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    if (function_exists('litespeed_finish_request') || function_exists('fastcgi_finish_request')) {
        echo $body;
        function_exists('litespeed_finish_request') ? litespeed_finish_request() : fastcgi_finish_request();
        return;
    }
    while (ob_get_level()) ob_end_clean();
    header('Connection: close');
    header('Content-Length: ' . strlen($body));
    echo $body;
    flush();
}

/* ============================ the download ============================ */

function run_download(string $src, string $custom, Job $job): void {
    global $DEST_DIR, $BLOCKED_EXT;

    $finished = false;
    $tmp = $DEST_DIR . '/.part_' . bin2hex(random_bytes(6));

    // If PHP is killed mid-download (time limit etc.) make sure the UI hears about it.
    register_shutdown_function(function () use (&$finished, $job, $tmp) {
        if (!$finished) {
            @unlink($tmp);
            $job->set(['state' => 'error', 'message' =>
                'The server stopped the job (likely its PHP time limit). Try again, or ask your host to raise max_execution_time.']);
        }
    });

    try {
        $url = $src; $name = null; $total = 0; $dec = null;

        if (is_mega_folder($src)) {
            throw new FetchError('Mega folder links are not supported. Open the folder and copy the link of a single file.');
        }
        if ($mega = parse_mega($src)) {
            $job->set(['state' => 'preparing', 'message' => 'Contacting Mega...']);
            $m = mega_file($mega[0], $mega[1]);
            $url = $m['url']; $name = $m['name']; $total = $m['size'];
            $dec = new CtrDecryptor($m['key'], $m['nonce']);
        } elseif (!is_public_host(parse_url($src, PHP_URL_HOST))) {
            throw new FetchError('That host could not be resolved or points to a private address.');
        }

        $free = @disk_free_space($DEST_DIR);
        if ($total > 0 && $free !== false && $total > $free) {
            throw new FetchError('Not enough free disk space for this file.');
        }

        $fp = fopen($tmp, 'wb');
        if (!$fp) throw new FetchError('Cannot write to the download folder. Check its permissions.');

        $h = ['size' => 0, 'name' => null];
        $received = 0; $lastTick = microtime(true); $lastBytes = 0; $speed = 0.0;
        $cancelled = false; $writeFailed = false;
        $job->set(['state' => 'downloading', 'name' => $name, 'total' => $total, 'done' => 0, 'speed' => 0, 'message' => '']);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION  => true,
            CURLOPT_MAXREDIRS       => 5,
            CURLOPT_PROTOCOLS       => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT  => 30,
            CURLOPT_TIMEOUT         => 0,
            CURLOPT_LOW_SPEED_LIMIT => 1,
            CURLOPT_LOW_SPEED_TIME  => 120,           // abort if stalled for 2 min
            CURLOPT_FAILONERROR     => true,
            CURLOPT_USERAGENT       => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124 Safari/537.36',
            CURLOPT_HEADERFUNCTION  => header_collector($h),
            CURLOPT_WRITEFUNCTION   => function ($ch, $data) use (
                &$received, &$lastTick, &$lastBytes, &$speed, &$cancelled, &$writeFailed, &$h, $fp, $dec, $job, $total, $name
            ) {
                $len = strlen($data);
                $received += $len;
                $chunk = $dec ? $dec->update($data) : $data;
                if ($chunk !== '' && fwrite($fp, $chunk) !== strlen($chunk)) {
                    $writeFailed = true;
                    return 0;
                }
                $now = microtime(true);
                if ($now - $lastTick >= 0.6) {
                    $inst  = ($received - $lastBytes) / max(0.001, $now - $lastTick);
                    $speed = $speed > 0 ? $speed * 0.6 + $inst * 0.4 : $inst;
                    $lastTick = $now; $lastBytes = $received;
                    if ($job->cancelled()) { $cancelled = true; return 0; }
                    $job->set(['done' => $received, 'speed' => (int)$speed,
                               'total' => $total ?: $h['size'], 'name' => $name ?: $h['name']]);
                }
                return $len;
            },
        ]);

        $ok   = curl_exec($ch);
        $err  = curl_error($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $eff  = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);

        if ($ok && $dec) fwrite($fp, $dec->finish());
        fclose($fp);

        if ($cancelled) {
            @unlink($tmp); @unlink($job->cancelFile);
            $job->set(['state' => 'cancelled', 'message' => 'Download cancelled.']);
            $finished = true;
            return;
        }
        if ($writeFailed) throw new FetchError('Writing to disk failed. The disk or your hosting quota may be full.');
        if (!$ok) {
            if ($http === 509) throw new FetchError('Mega bandwidth quota exceeded for this server. Try again later.');
            throw new FetchError('Download failed: ' . ($err ?: 'unknown error') . ($http ? " (HTTP $http)" : ''));
        }
        if ($dec && $total > 0 && $received !== $total) {
            throw new FetchError("Incomplete download ($received of $total bytes).");
        }

        // Choose the final filename
        $final = $custom !== '' ? $custom : ($name ?: ($h['name'] ?: basename((string)parse_url($eff, PHP_URL_PATH))));
        $final = safe_name($final);
        if (in_array(strtolower(pathinfo($final, PATHINFO_EXTENSION)), $BLOCKED_EXT, true)) $final .= '.txt';

        $target = "$DEST_DIR/$final";
        if (file_exists($target)) {
            $i = pathinfo($final);
            $target = "$DEST_DIR/" . $i['filename'] . '_' . date('His') . (isset($i['extension']) ? '.' . $i['extension'] : '');
        }
        if (!rename($tmp, $target)) throw new FetchError('Downloaded, but could not move the file into place.');

        $size = filesize($target);
        $rel  = (dirname($DEST_DIR) === __DIR__) ? rawurlencode(basename($DEST_DIR)) . '/' . rawurlencode(basename($target)) : null;
        $job->set(['state' => 'done', 'file' => basename($target), 'done' => $size, 'total' => $size, 'speed' => 0, 'url' => $rel]);
        $finished = true;
    } catch (Throwable $e) {
        @unlink($tmp);
        $job->set(['state' => 'error', 'message' => $e instanceof FetchError ? $e->getMessage() : 'Unexpected error: ' . $e->getMessage()]);
        $finished = true;
    }
}

/* ============================== JSON API ============================== */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $in = json_decode((string)file_get_contents('php://input'), true);
    $in = is_array($in) ? $in : [];

    if ($PASSWORD === 'change-this-password') out(['error' => 'Open fetch.php and set $PASSWORD first.'], 403);
    if (!hash_equals($PASSWORD, (string)($in['password'] ?? ''))) { usleep(600000); out(['error' => 'Wrong password'], 401); }

    $action = (string)($in['action'] ?? '');
    $jobId  = (string)($in['job'] ?? '');
    $needJob = in_array($action, ['start', 'status', 'cancel'], true);
    if ($needJob && !preg_match('/^[a-f0-9]{16}$/', $jobId)) out(['error' => 'Bad job id'], 400);

    switch ($action) {
        case 'auth':
            out(['ok' => true]);

        case 'info': {
            $url = trim((string)($in['url'] ?? ''));
            if (is_mega_folder($url)) out(['error' => 'Mega folder links are not supported, use a single-file link.']);
            if ($mega = parse_mega($url)) {
                try {
                    $m = mega_file($mega[0], $mega[1]);
                    out(['kind' => 'mega', 'name' => $m['name'], 'size' => $m['size']]);
                } catch (FetchError $e) { out(['error' => $e->getMessage()]); }
            }
            if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url)) out(['error' => 'Not a valid http(s) URL.']);
            if (!is_public_host(parse_url($url, PHP_URL_HOST))) out(['error' => 'Host could not be resolved.']);
            $h = ['size' => 0, 'name' => null];
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_NOBODY => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_TIMEOUT => 15,
                CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; ServerFetch/2.0)',
                CURLOPT_HEADERFUNCTION => header_collector($h),
            ]);
            curl_exec($ch);
            $eff = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
            curl_close($ch);
            $name = $h['name'] ?: basename((string)parse_url($eff, PHP_URL_PATH));
            out(['kind' => 'url', 'name' => $name ? safe_name($name) : null, 'size' => $h['size']]);
        }

        case 'start': {
            $src = trim((string)($in['url'] ?? ''));
            if (!filter_var($src, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $src)) out(['error' => 'Please enter a valid http(s) URL.'], 400);
            // tidy old job files
            foreach (glob("$JOBS_DIR/*.{json,cancel}", GLOB_BRACE) ?: [] as $f) {
                if (filemtime($f) < time() - 86400) @unlink($f);
            }
            $job = new Job($JOBS_DIR, $jobId);
            @unlink($job->cancelFile);
            $job->set(['state' => 'queued', 'started' => time(), 'done' => 0, 'total' => 0, 'speed' => 0]);
            respond_and_continue(['ok' => true]);
            run_download($src, trim((string)($in['name'] ?? '')), $job);
            exit;
        }

        case 'status': {
            $f = "$JOBS_DIR/$jobId.json";
            $s = is_file($f) ? json_decode((string)file_get_contents($f), true) : null;
            if (!is_array($s)) out(['state' => 'error', 'message' => 'Job not found.', 'now' => time(), 'updated' => time()]);
            $s['now'] = time();
            out($s);
        }

        case 'cancel':
            @touch("$JOBS_DIR/$jobId.cancel");
            out(['ok' => true]);

        case 'list': {
            $files = [];
            $canLink = dirname($DEST_DIR) === __DIR__;
            foreach (scandir($DEST_DIR) ?: [] as $n) {
                if ($n[0] === '.' || !is_file("$DEST_DIR/$n")) continue;
                $files[] = ['name' => $n, 'size' => filesize("$DEST_DIR/$n"), 'time' => filemtime("$DEST_DIR/$n"),
                            'url' => $canLink ? rawurlencode(basename($DEST_DIR)) . '/' . rawurlencode($n) : null];
            }
            usort($files, function ($a, $b) { return $b['time'] <=> $a['time']; });
            $free = @disk_free_space($DEST_DIR);
            out(['files' => $files, 'free' => $free === false ? null : $free, 'dir' => basename($DEST_DIR)]);
        }

        case 'delete': {
            $n = basename((string)($in['name'] ?? ''));
            $p = "$DEST_DIR/$n";
            if ($n === '' || $n[0] === '.' || !is_file($p)) out(['error' => 'File not found.'], 404);
            out(@unlink($p) ? ['ok' => true] : ['error' => 'Could not delete that file.']);
        }

        default:
            out(['error' => 'Unknown action'], 400);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Server Fetch</title>
<style>
  :root{
    --bg:#f3f5fb; --card:#ffffff; --text:#1c2333; --muted:#6b7590; --line:#e4e8f2;
    --accent:#5b6cff; --accent2:#8a5bff; --ok:#12a26a; --err:#e0464b; --warn:#d98a0b;
    --shadow:0 10px 30px rgba(35,45,90,.08); --radius:16px;
  }
  @media (prefers-color-scheme: dark){
    :root{ --bg:#0e1220; --card:#171c2e; --text:#e8ecf7; --muted:#8f99b8; --line:#262d45;
           --shadow:0 10px 30px rgba(0,0,0,.35); }
  }
  *{box-sizing:border-box}
  html,body{margin:0}
  body{font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;background:var(--bg);color:var(--text);
       min-height:100vh;padding:32px 16px 64px;line-height:1.5}
  .wrap{max-width:720px;margin:0 auto}
  header{display:flex;align-items:center;gap:14px;margin-bottom:24px}
  .logo{width:46px;height:46px;border-radius:14px;display:grid;place-items:center;color:#fff;
        background:linear-gradient(135deg,var(--accent),var(--accent2));box-shadow:0 8px 20px rgba(91,108,255,.35)}
  h1{font-size:1.4rem;margin:0}
  header p{margin:0;color:var(--muted);font-size:.9rem}
  .card{background:var(--card);border:1px solid var(--line);border-radius:var(--radius);box-shadow:var(--shadow);
        padding:22px;margin-bottom:18px}
  .card h2{font-size:1rem;margin:0 0 14px;display:flex;justify-content:space-between;align-items:center;gap:10px}
  label{display:block;font-size:.8rem;font-weight:600;color:var(--muted);margin:14px 0 6px;letter-spacing:.02em}
  input[type=text],input[type=password],input[type=url]{
    width:100%;padding:12px 14px;font-size:.95rem;color:var(--text);background:transparent;
    border:1.5px solid var(--line);border-radius:12px;outline:none;transition:.15s}
  input:focus{border-color:var(--accent);box-shadow:0 0 0 4px rgba(91,108,255,.15)}
  .btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:12px 22px;border:0;border-radius:12px;
       font-size:.95rem;font-weight:600;cursor:pointer;color:#fff;transition:.15s;text-decoration:none;
       background:linear-gradient(135deg,var(--accent),var(--accent2))}
  .btn:hover{transform:translateY(-1px);box-shadow:0 8px 18px rgba(91,108,255,.35)}
  .btn:disabled{opacity:.55;cursor:not-allowed;transform:none;box-shadow:none}
  .btn.ghost{background:transparent;color:var(--text);border:1.5px solid var(--line)}
  .btn.ghost:hover{box-shadow:none;border-color:var(--accent)}
  .btn.small{padding:6px 12px;font-size:.8rem;border-radius:9px}
  .btn.danger{color:var(--err)}
  .btn.block{width:100%;margin-top:20px}
  .hidden{display:none!important}
  .chip{display:flex;gap:10px;align-items:center;margin-top:10px;padding:10px 14px;border-radius:12px;
        background:rgba(91,108,255,.08);font-size:.88rem;word-break:break-all}
  .chip.bad{background:rgba(224,70,75,.1);color:var(--err)}
  .tag{font-size:.7rem;font-weight:700;padding:2px 8px;border-radius:99px;background:var(--accent);color:#fff;white-space:nowrap}
  .banner{padding:12px 16px;border-radius:12px;background:rgba(217,138,11,.12);color:var(--warn);font-size:.88rem;margin-bottom:18px}
  .bar{height:14px;border-radius:99px;background:var(--line);overflow:hidden;margin:14px 0 10px}
  .bar>div{height:100%;width:0;border-radius:99px;transition:width .5s ease;
           background:linear-gradient(90deg,var(--accent),var(--accent2))}
  .bar.busy>div{width:35%!important;animation:slide 1.2s ease-in-out infinite}
  .bar.done>div{background:var(--ok)}
  @keyframes slide{0%{margin-left:-35%}100%{margin-left:100%}}
  .stats{display:flex;flex-wrap:wrap;gap:6px 18px;color:var(--muted);font-size:.85rem}
  .stats b{color:var(--text);font-weight:600}
  .title{font-weight:600;word-break:break-all}
  .msg{margin-top:12px;font-size:.9rem}
  .msg.err{color:var(--err)} .msg.ok{color:var(--ok)} .msg.warn{color:var(--warn)}
  .row{display:flex;align-items:center;gap:10px;padding:12px 0;border-top:1px solid var(--line)}
  .row:first-child{border-top:0}
  .row .meta{flex:1;min-width:0}
  .row .n{font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
  .row .s{color:var(--muted);font-size:.8rem}
  .acts{display:flex;gap:6px;flex-shrink:0;flex-wrap:wrap;justify-content:flex-end}
  .empty{color:var(--muted);text-align:center;padding:14px 0;font-size:.9rem}
  .toast{position:fixed;left:50%;bottom:28px;transform:translate(-50%,20px);background:var(--text);color:var(--bg);
         padding:10px 18px;border-radius:12px;font-size:.9rem;opacity:0;pointer-events:none;transition:.25s;z-index:10}
  .toast.show{opacity:1;transform:translate(-50%,0)}
  .spin{width:16px;height:16px;border:2px solid rgba(255,255,255,.4);border-top-color:#fff;border-radius:50%;animation:r .7s linear infinite}
  @keyframes r{to{transform:rotate(360deg)}}
  small.hint{color:var(--muted);display:block;margin-top:8px;font-size:.8rem}
  @media (max-width:520px){ .row{flex-direction:column;align-items:stretch} .acts{justify-content:flex-start} }
</style>
</head>
<body>
<div class="wrap">
  <header>
    <div class="logo">
      <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v12"/><path d="m7 10 5 5 5-5"/><path d="M4 19h16"/></svg>
    </div>
    <div>
      <h1>Server Fetch</h1>
      <p>Download a link straight to your hosting. Mega supported.</p>
    </div>
  </header>

  <?php if ($PASSWORD === 'change-this-password'): ?>
    <div class="banner">Set <code>$PASSWORD</code> at the top of <code>fetch.php</code> before using this tool.</div>
  <?php endif; ?>

  <!-- Unlock -->
  <section class="card" id="lock">
    <h2>Unlock</h2>
    <form id="lockForm">
      <label for="pw">Password</label>
      <input type="password" id="pw" autocomplete="current-password" autofocus required>
      <button class="btn block" type="submit">Unlock</button>
      <div class="msg err hidden" id="lockErr"></div>
    </form>
  </section>

  <!-- App -->
  <div id="app" class="hidden">
    <section class="card">
      <h2>New download</h2>
      <label for="url">File URL (Mega or any direct link)</label>
      <input type="url" id="url" placeholder="https://mega.nz/file/...  or  https://...">
      <div id="chip" class="chip hidden"></div>

      <label for="fname">Save as <span style="font-weight:400">(optional)</span></label>
      <input type="text" id="fname" placeholder="Leave blank to use the original name">

      <button class="btn block" id="go">Download to server</button>
      <small class="hint">Files are saved on your server and are never sent to this computer.</small>
    </section>

    <section class="card hidden" id="progress">
      <h2><span id="pTitle" class="title">Starting...</span> <button class="btn ghost small danger" id="cancel">Cancel</button></h2>
      <div class="bar busy" id="bar"><div></div></div>
      <div class="stats">
        <span><b id="pPct">0%</b></span>
        <span><b id="pDone">0 B</b> of <b id="pTotal">?</b></span>
        <span id="pSpeedWrap"><b id="pSpeed">0 B/s</b></span>
        <span id="pEtaWrap">ETA <b id="pEta">-</b></span>
      </div>
      <div id="pMsg" class="msg hidden"></div>
    </section>

    <section class="card">
      <h2>
        <span>Files in <code id="dirName">data</code></span>
        <span style="display:flex;gap:10px;align-items:center">
          <small id="free" style="color:var(--muted);font-weight:400"></small>
          <button class="btn ghost small" id="refresh">Refresh</button>
        </span>
      </h2>
      <div id="files"><div class="empty">Loading...</div></div>
    </section>
  </div>
</div>
<div class="toast" id="toast"></div>

<script>
(() => {
  const $ = s => document.querySelector(s);
  const sleep = ms => new Promise(r => setTimeout(r, ms));
  const state = { pw: sessionStorage.getItem('fx_pw') || '', job: null };

  const fmtBytes = b => {
    if (!b && b !== 0) return '?';
    const u = ['B','KB','MB','GB','TB']; let i = 0;
    while (b >= 1024 && i < 4) { b /= 1024; i++; }
    return (i ? b.toFixed(b < 10 ? 2 : 1) : b) + ' ' + u[i];
  };
  const fmtTime = s => {
    if (!isFinite(s) || s <= 0) return '-';
    s = Math.round(s);
    const h = Math.floor(s / 3600), m = Math.floor(s % 3600 / 60), x = s % 60;
    return (h ? h + 'h ' : '') + (h || m ? m + 'm ' : '') + x + 's';
  };
  const esc = s => String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const toast = t => {
    const el = $('#toast'); el.textContent = t; el.classList.add('show');
    clearTimeout(toast.t); toast.t = setTimeout(() => el.classList.remove('show'), 2400);
  };

  async function api(action, data = {}) {
    const res = await fetch(location.pathname, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action, password: state.pw, ...data })
    });
    let j = {};
    try { j = await res.json(); } catch (e) { throw new Error('Unexpected response from server.'); }
    if (res.status === 401) { lock(); throw new Error('auth'); }
    if (!res.ok) throw new Error(j.error || 'Request failed');
    return j;
  }

  /* ---------- lock / unlock ---------- */
  function lock() {
    sessionStorage.removeItem('fx_pw'); state.pw = '';
    $('#app').classList.add('hidden'); $('#lock').classList.remove('hidden');
  }
  async function unlock(pw, silent) {
    state.pw = pw;
    try {
      await api('auth');
      sessionStorage.setItem('fx_pw', pw);
      $('#lockErr').classList.add('hidden');
      $('#lock').classList.add('hidden'); $('#app').classList.remove('hidden');
      loadFiles();
    } catch (e) {
      if (silent) return;
      $('#lockErr').textContent = e.message === 'auth' ? 'Wrong password.' : e.message;
      $('#lockErr').classList.remove('hidden');
    }
  }
  $('#lockForm').addEventListener('submit', e => { e.preventDefault(); unlock($('#pw').value, false); });
  if (state.pw) unlock(state.pw, true);

  /* ---------- link preview ---------- */
  let infoTimer, infoSeq = 0;
  function showChip(html, bad) {
    const c = $('#chip'); c.innerHTML = html; c.classList.toggle('bad', !!bad); c.classList.remove('hidden');
  }

  $('#url').addEventListener('input', () => {
    clearTimeout(infoTimer);
    const v = $('#url').value.trim();
    if (!/^https?:\/\//i.test(v)) { $('#chip').classList.add('hidden'); return; }
    infoTimer = setTimeout(async () => {
      const seq = ++infoSeq;
      showChip('Checking link...');
      try {
        const j = await api('info', { url: v });
        if (seq !== infoSeq) return;
        if (j.error) return showChip(esc(j.error), true);
        showChip(
          (j.kind === 'mega' ? '<span class="tag">MEGA</span>' : '<span class="tag">URL</span>') +
          '<span><b>' + esc(j.name || 'Unknown name') + '</b>' + (j.size ? ' &middot; ' + fmtBytes(j.size) : '') + '</span>'
        );
      } catch (e) { if (e.message !== 'auth') showChip(esc(e.message), true); }
    }, 600);
  });

  /* ---------- start / progress ---------- */
  const randId = () => Array.from(crypto.getRandomValues(new Uint8Array(8)), b => b.toString(16).padStart(2, '0')).join('');

  $('#go').addEventListener('click', async () => {
    const url = $('#url').value.trim();
    if (!/^https?:\/\//i.test(url)) { toast('Paste a valid link first'); return; }
    const job = randId(); state.job = job;
    setBusy(true); showProgress({ state: 'queued' });
    try {
      await api('start', { url, name: $('#fname').value.trim(), job });
      poll(job);
    } catch (e) {
      if (e.message !== 'auth') showProgress({ state: 'error', message: e.message });
      setBusy(false); state.job = null;
    }
  });

  $('#cancel').addEventListener('click', async () => {
    if (!state.job) return;
    $('#cancel').disabled = true;
    try { await api('cancel', { job: state.job }); } catch (e) {}
  });

  function setBusy(b) {
    const g = $('#go'); g.disabled = b;
    g.innerHTML = b ? '<span class="spin"></span> Working...' : 'Download to server';
  }

  async function poll(job) {
    while (state.job === job) {
      let s;
      try { s = await api('status', { job }); }
      catch (e) { if (e.message === 'auth') return; await sleep(2500); continue; }
      showProgress(s);
      if (['done', 'error', 'cancelled'].includes(s.state)) {
        state.job = null; setBusy(false);
        if (s.state === 'done') {
          toast('Saved: ' + s.file);
          $('#url').value = ''; $('#fname').value = ''; $('#chip').classList.add('hidden');
        }
        loadFiles();
        return;
      }
      await sleep(900);
    }
  }

  function showProgress(s) {
    $('#progress').classList.remove('hidden');
    const bar = $('#bar'), msg = $('#pMsg');
    const total = s.total || 0, done = s.done || 0;
    const pct = total ? Math.min(100, done / total * 100) : 0;
    const running = ['queued', 'preparing', 'downloading'].includes(s.state);

    $('#pTitle').textContent = s.file || s.name || (s.state === 'preparing' ? 'Preparing...' : 'Starting...');
    $('#cancel').classList.toggle('hidden', !running);
    if (running) $('#cancel').disabled = false;

    bar.classList.toggle('busy', running && !total);
    bar.classList.toggle('done', s.state === 'done');
    bar.firstElementChild.style.width = (s.state === 'done' ? 100 : pct) + '%';

    $('#pPct').textContent = total ? pct.toFixed(1) + '%' : (s.state === 'done' ? '100%' : '...');
    $('#pDone').textContent = fmtBytes(done);
    $('#pTotal').textContent = total ? fmtBytes(total) : '?';
    $('#pSpeed').textContent = fmtBytes(s.speed || 0) + '/s';
    $('#pSpeedWrap').classList.toggle('hidden', !running);
    $('#pEtaWrap').classList.toggle('hidden', !(running && total && s.speed));
    if (total && s.speed) $('#pEta').textContent = fmtTime((total - done) / s.speed);

    msg.className = 'msg hidden'; msg.textContent = '';
    const stalled = s.state === 'downloading' && s.now && s.updated && (s.now - s.updated) > 60;
    if (s.state === 'error') { msg.className = 'msg err'; msg.textContent = s.message || 'Failed.'; }
    else if (s.state === 'cancelled') { msg.className = 'msg warn'; msg.textContent = s.message || 'Cancelled.'; }
    else if (s.state === 'done') { msg.className = 'msg ok'; msg.textContent = 'Done. Saved to ' + ($('#dirName').textContent || 'data') + '/.'; }
    else if (stalled) { msg.className = 'msg warn'; msg.textContent = 'No progress for over a minute. The server may have stopped the job.'; }
    else if (s.state === 'preparing' && s.message) { msg.className = 'msg'; msg.textContent = s.message; }
  }

  /* ---------- file list ---------- */
  async function loadFiles() {
    try {
      const j = await api('list');
      $('#dirName').textContent = j.dir;
      $('#free').textContent = j.free != null ? fmtBytes(j.free) + ' free' : '';
      const box = $('#files'); box.innerHTML = '';
      if (!j.files.length) { box.innerHTML = '<div class="empty">Nothing here yet.</div>'; return; }
      j.files.forEach(f => {
        const row = document.createElement('div'); row.className = 'row';
        const when = new Date(f.time * 1000).toLocaleString();
        row.innerHTML = '<div class="meta"><div class="n" title="' + esc(f.name) + '">' + esc(f.name) +
          '</div><div class="s">' + fmtBytes(f.size) + ' &middot; ' + esc(when) + '</div></div><div class="acts"></div>';
        const acts = row.querySelector('.acts');
        if (f.url) {
          const abs = new URL(f.url, location.href).href;
          const open = document.createElement('a'); open.href = abs; open.target = '_blank'; open.rel = 'noopener';
          open.className = 'btn ghost small'; open.textContent = 'Open'; acts.appendChild(open);
          const copy = document.createElement('button'); copy.className = 'btn ghost small'; copy.textContent = 'Copy link';
          copy.onclick = () => navigator.clipboard.writeText(abs).then(() => toast('Link copied')); acts.appendChild(copy);
        }
        const del = document.createElement('button'); del.className = 'btn ghost small danger'; del.textContent = 'Delete';
        del.onclick = async () => {
          if (!confirm('Delete ' + f.name + '?')) return;
          try { await api('delete', { name: f.name }); toast('Deleted'); loadFiles(); } catch (e) { toast(e.message); }
        };
        acts.appendChild(del);
        box.appendChild(row);
      });
    } catch (e) { if (e.message !== 'auth') $('#files').innerHTML = '<div class="empty">' + esc(e.message) + '</div>'; }
  }
  $('#refresh').addEventListener('click', loadFiles);
})();
</script>
</body>
</html>
