<?php
declare(strict_types=1);

// Run only inside the disposable Compose test stack.
if (PHP_SAPI !== 'cli' || getenv('UPTEMPO_FILES_LOCAL_TEST') !== '1') {
    exit(1);
}
require '/var/www/html/plugins/uptempo_files/Files.php';
require '/var/www/html/vendor/autoload.php';

function check(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function denied(callable $operation): void {
    try {
        $operation();
    } catch (DomainException $e) {
        return;
    }
    throw new RuntimeException('Expected the operation to be denied');
}

$store = new UptempoFiles();
$db = new PDO('sqlite:/var/roundcube/db/uptempo-files.sqlite');
$draft = bin2hex(random_bytes(16));
$owner = random_int(10000000, 99999999);
$path = tempnam(sys_get_temp_dir(), 'kr-upload-test-');
file_put_contents($path, 'ok');
$chunk = ['tmp_name'=>$path, 'size'=>2, 'error'=>UPLOAD_ERR_OK];
$signature = hash('sha256','ok');
try {
    $id = $store->create($owner,$draft,'보관.txt',2,0,$signature);
    putenv('LARGE_FILES_USER_BYTES=1048576');
    try {
        denied(fn() => $store->create($owner,$draft.'other','small.txt',1,0,$signature));
    } finally {
        putenv('LARGE_FILES_USER_BYTES');
    }
    $token = $store->rows($owner,$draft)[0]['token'];
    denied(fn() => $store->transfer($owner+1,$draft,$id,$chunk,0));
    denied(fn() => $store->reserveSend($owner,$draft,[$id],0));
    check($store->transfer($owner,$draft,$id,$chunk,0) === 2, 'upload completion');
    denied(fn() => $store->reserveSend($owner,$draft,[$id],UptempoFiles::LIMIT-1));
    [$attempt] = $store->reserveSend($owner,$draft,[$id],0);
    denied(fn() => $store->reserveSend($owner,$draft,[$id],0));
    $store->finish($attempt,false);
    check($store->download($token) === null, 'SMTP failure keeps file private');
    [$attempt] = $store->reserveSend($owner,$draft,[$id],0);
    $store->finish($attempt,true);
    check($store->download($token)['size'] === 2, 'accepted send');
    $rows = $store->sent($owner);
    check(abs($rows[0]['expires'] - (time()+UptempoFiles::WEEK)) < 5,'seven-day lifetime');

    $partial = $store->create($owner,$draft.'2','partial.bin',5,0,$signature);
    check($store->transfer($owner,$draft.'2',$partial,$chunk,0) === 2, 'partial upload');
    check(abs($store->rows($owner,$draft.'2')[0]['expires'] - (time()+86400)) < 5,'24-hour partial lifetime');
    $db->prepare('UPDATE files SET expires=? WHERE owner=?')->execute([time()-1,$owner]);
    check($store->download($token) === null, 'expiry denies download before cleanup');
    denied(fn() => $store->transfer($owner,$draft.'2',$partial,$chunk,2));
    $store->collect();
    foreach ([$id,$partial] as $file) {
        clearstatcache();
        check(!file_exists(UptempoFiles::DATA.'/'.$file) && !file_exists(UptempoFiles::DATA.'/'.$file.'.info'), 'cleanup removed bytes and tus metadata');
    }
    check(!$store->rows($owner,$draft) && !$store->rows($owner,$draft.'2'), 'metadata cleanup');
    foreach ([14, 16] as $mib) {
        $fp = fopen($path, 'wb');
        ftruncate($fp, $mib * 1024 * 1024);
        fclose($fp);
        $mime = new Mail_mime(['delay_file_io' => true, 'eol' => "\r\n"]);
        $mime->setTXTBody('MIME test');
        $mime->headers(['Bcc' => 'reader@example.test']);
        $mime->addAttachment($path, 'application/octet-stream', 'native.bin');
        if ($mib === 14) {
            UptempoFiles::checkMessageSize($mime);
            check($mime->headers()['Bcc'] === 'reader@example.test', 'size check preserves Bcc');
        } else {
            denied(fn() => UptempoFiles::checkMessageSize($mime));
        }
    }
    echo "PASS encoded MIME below/above 20 MiB and preservation of Bcc\n";
    echo "PASS mixed-size budget, cross-account isolation, failed/duplicate send, expiry and automatic physical removal\n";
} finally {
    unlink($path);
}
