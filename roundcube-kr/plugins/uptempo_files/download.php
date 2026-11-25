<?php
declare(strict_types=1);

header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');
header('X-Frame-Options: DENY');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // The bearer token stays in the URL fragment, outside proxy access logs and referrers.
    $nonce = base64_encode(random_bytes(18));
    header("Content-Security-Policy: default-src 'none'; script-src 'nonce-$nonce'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="ko"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">',
        '<title>대용량 파일 다운로드</title><h1>대용량 파일 다운로드</h1><form method="post"><input type="hidden" name="token">',
        '<button disabled>원본 파일 다운로드</button></form><p id="status"></p><script nonce="',$nonce,'">',
        'const t=location.hash.slice(1); if(/^[a-f0-9]{64}$/.test(t)){document.querySelector("input").value=t;',
        'document.querySelector("button").disabled=false;}else{document.querySelector("#status").textContent="메일에 있는 전체 링크를 열어주세요."}</script></html>';
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: GET, POST');
    http_response_code(405);
    exit;
}
require_once '/var/www/html/plugins/uptempo_files/Files.php';
$token = $_POST['token'] ?? '';
$row = is_string($token) && preg_match('/^[a-f0-9]{64}$/D',$token) ? (new UptempoFiles())->download($token) : null;
$fp = $row ? @fopen(UptempoFiles::DATA.'/'.$row['id'],'rb') : false;
if (!$fp || fstat($fp)['size'] !== (int)$row['size']) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('파일이 만료되었거나 다운로드할 수 없습니다.');
}
header("Content-Security-Policy: sandbox; default-src 'none'");
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="download"; filename*=UTF-8\'\''.rawurlencode($row['name']));
header('Content-Length: '.$row['size']);
header('X-Accel-Buffering: no');
set_time_limit(0);
while (ob_get_level()) ob_end_clean();
fpassthru($fp);
fclose($fp);
