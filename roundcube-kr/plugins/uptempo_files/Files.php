<?php
declare(strict_types=1);

final class UptempoFiles
{
    public const LIMIT = 1000000000;
    public const WIRE_LIMIT = 20971520;
    public const CHUNK = 524288;
    public const WEEK = 7 * 86400;
    public const DATA = '/srv/tusd-data';
    private const MIN_RESERVATION = 1048576;

    private PDO $db;

    public static function checkMessageSize(Mail_mime $message): void
    {
        // txtHeaders mutates Mail_mime. The live envelope must retain Bcc recipients.
        $mime = clone $message;
        $path = tempnam(sys_get_temp_dir(), 'kr-mime-');
        if ($path === false) {
            throw new RuntimeException('MIME temporary file unavailable');
        }
        try {
            $headers = $mime->txtHeaders(['Bcc' => null], true);
            if ($mime->saveMessageBody($path) instanceof PEAR_Error) {
                throw new RuntimeException('MIME encoding failed');
            }
            clearstatcache(true, $path);
            $size = filesize($path);
            if ($size === false) {
                throw new RuntimeException('MIME size unavailable');
            }
            if (strlen($headers) + 2 + $size > self::WIRE_LIMIT) {
                throw new DomainException('인코딩된 메일이 20MiB를 초과합니다. 일반 첨부를 대용량 첨부로 옮겨주세요.');
            }
        } finally {
            unlink($path);
        }
    }

    public function __construct(string $path = '/var/roundcube/db/uptempo-files.sqlite')
    {
        $this->db = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 10,
        ]);
        $this->db->exec("CREATE TABLE IF NOT EXISTS files (
            id TEXT PRIMARY KEY, owner INTEGER NOT NULL, draft TEXT NOT NULL,
            name TEXT NOT NULL, size INTEGER NOT NULL, token TEXT NOT NULL UNIQUE,
            state TEXT NOT NULL, expires INTEGER NOT NULL, attempt TEXT, signature TEXT NOT NULL
        )");
        $this->db->exec('CREATE INDEX IF NOT EXISTS files_draft ON files(owner,draft)');
    }

    private function query(string $sql, array $args = []): PDOStatement
    {
        $q = $this->db->prepare($sql);
        $q->execute($args);
        return $q;
    }

    private function atomic(callable $fn)
    {
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $result = $fn();
            $this->db->exec('COMMIT');
            return $result;
        } catch (Throwable $e) {
            $this->db->exec('ROLLBACK');
            throw $e;
        }
    }

    public function rows(int $owner, string $draft): array
    {
        return $this->query("SELECT * FROM files WHERE owner=? AND draft=? AND state <> 'trash' ORDER BY rowid", [$owner,$draft])->fetchAll();
    }

    // tusd is only on the private Compose network. Never follow a Location supplied by a client.
    private function tus(string $method, string $id = '', array $headers = [], $stream = null, int $bytes = 0): array
    {
        if ($id !== '' && !preg_match('/^[0-9a-f]{32}$/D', $id)) {
            throw new RuntimeException('Invalid storage identifier');
        }
        $ch = curl_init('http://uploads:8080/files/' . $id);
        $reply = [];
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_NOBODY => $method === 'HEAD', CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => array_merge(['Tus-Resumable: 1.0.0', 'Expect:'], $headers),
            CURLOPT_HEADERFUNCTION => static function ($ch, $line) use (&$reply) {
                if (str_contains($line, ':')) {
                    [$key,$value] = explode(':', $line, 2);
                    $reply[strtolower(trim($key))] = trim($value);
                }
                return strlen($line);
            },
        ]);
        if ($stream !== null) {
            curl_setopt_array($ch, [CURLOPT_UPLOAD => true, CURLOPT_INFILE => $stream, CURLOPT_INFILESIZE => $bytes]);
        }
        if (curl_exec($ch) === false) {
            throw new RuntimeException('Upload service unavailable');
        }
        return [(int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE), $reply];
    }

    public function create(int $owner, string $draft, string $name, int $size, int $normal, string $signature): string
    {
        if ($name === '' || strlen($name) > 255 || preg_match('~[\p{C}/\\\\]~u', $name) !== 0 || $size <= 0 || $size > self::LIMIT) {
            throw new DomainException('파일명 또는 파일 크기를 확인하세요.');
        }
        if (!preg_match('/^[a-f0-9]{64}$/D',$signature)) {
            throw new DomainException('파일 식별 정보를 확인하세요.');
        }
        return $this->atomic(function () use ($owner,$draft,$name,$size,$normal,$signature) {
            $rows = $this->rows($owner,$draft);
            if (array_intersect(array_column($rows,'state'), ['sending','sent'])) {
                throw new DomainException('이미 발송 처리된 초안입니다.');
            }
            if (count($rows) >= 32 || $normal + $size + array_sum(array_column($rows,'size')) > self::LIMIT) {
                throw new DomainException('한 통당 파일 합계는 1GB까지이며 대용량 파일은 32개까지입니다.');
            }
            // Tiny uploads consume inodes and metadata even when their payload is a single byte.
            $charge = max($size, self::MIN_RESERVATION);
            $reserved = $this->query('SELECT COALESCE(SUM(charge),0) AS total,
                COALESCE(SUM(CASE WHEN owner=? THEN charge ELSE 0 END),0) AS personal
                FROM (SELECT owner, MAX(size,CAST(? AS INTEGER)) AS charge FROM files)',
                [$owner, self::MIN_RESERVATION])->fetch();
            if ($reserved['total'] + $charge > (int)(getenv('LARGE_FILES_TOTAL_BYTES') ?: 50000000000)
                || $reserved['personal'] + $charge > (int)(getenv('LARGE_FILES_USER_BYTES') ?: 5000000000)
                || disk_free_space(self::DATA) < $size + self::LIMIT) {
                throw new DomainException('대용량 첨부 저장 공간이 부족합니다.');
            }
            [$code,$headers] = $this->tus('POST', '', ['Upload-Length: ' . $size, 'Content-Length: 0']);
            $id = basename($headers['location'] ?? '');
            if ($code !== 201 || !preg_match('/^[a-f0-9]{32}$/D', $id)) {
                throw new RuntimeException('Upload reservation failed');
            }
            $this->query("INSERT INTO files VALUES(?,?,?,?,?,?,'uploading',?,NULL,?)",
                [$id,$owner,$draft,$name,$size,bin2hex(random_bytes(32)),time()+86400,$signature]);
            return $id;
        });
    }

    public function transfer(int $owner, string $draft, string $id, ?array $chunk, int $offset): int
    {
        $row = $this->query("SELECT * FROM files WHERE owner=? AND draft=? AND id=? AND expires>? AND state IN ('uploading','ready')",
            [$owner,$draft,$id,time()])->fetch();
        if (!$row) {
            throw new DomainException('이 파일을 업로드할 수 없습니다.');
        }
        if ($row['state'] === 'ready') {
            return (int)$row['size'];
        }
        if ($chunk !== null) {
            $bytes = (int)$chunk['size'];
            if ($chunk['error'] !== UPLOAD_ERR_OK || $bytes < 1 || $bytes > self::CHUNK || $offset < 0 || $offset + $bytes > $row['size']) {
                throw new DomainException('업로드 조각의 크기를 확인하세요.');
            }
            $fp = fopen($chunk['tmp_name'], 'rb');
            try {
                [$code,$headers] = $this->tus('PATCH',$id,['Content-Type: application/offset+octet-stream','Upload-Offset: '.$offset],$fp,$bytes);
            } finally {
                fclose($fp);
            }
            // A lost PATCH response can be retried. The server offset is authoritative.
            if (!in_array($code,[204,409],true)) {
                throw new RuntimeException('Upload write failed');
            }
        }
        if (!isset($headers['upload-offset']) || $code !== 204) {
            [$code,$headers] = $this->tus('HEAD',$id);
            if ($code !== 200 && $code !== 204) {
                throw new RuntimeException('Upload offset unavailable');
            }
        }
        if (!ctype_digit($headers['upload-offset'] ?? '')) {
            throw new RuntimeException('Invalid upload offset');
        }
        $actual = (int)$headers['upload-offset'];
        $complete = $actual === (int)$row['size'];
        if ($chunk !== null || $complete) {
            $this->query("UPDATE files SET state=?, expires=? WHERE id=? AND state='uploading'",
                [$complete ? 'ready' : 'uploading', time()+($complete ? self::WEEK : 86400),$id]);
        }
        return $actual;
    }

    public function remove(int $owner, string $draft, string $id): void
    {
        $this->query("UPDATE files SET state='trash' WHERE id=? AND owner=? AND draft=? AND state IN ('ready','uploading')",[$id,$owner,$draft]);
    }

    public function saveDraft(int $owner, string $draft): void
    {
        $this->query("UPDATE files SET expires=? WHERE owner=? AND draft=? AND state='ready' AND expires>?",
            [time()+self::WEEK,$owner,$draft,time()]);
    }

    public function reserveSend(int $owner, string $draft, array $expected, int $normal): array
    {
        return $this->atomic(function () use ($owner,$draft,$expected,$normal) {
            $rows = $this->rows($owner,$draft);
            if (array_diff($expected,array_column($rows,'id'))) {
                throw new DomainException('초안의 파일이 만료되었습니다. 목록에서 제거하고 다시 첨부하세요.');
            }
            if ($normal + array_sum(array_column($rows,'size')) > self::LIMIT) {
                throw new DomainException('파일 합계가 1GB를 초과합니다.');
            }
            foreach ($rows as $r) {
                if ($r['state'] !== 'ready' || $r['expires'] <= time()) {
                    throw new DomainException('미완료·만료 또는 발송 처리 중인 파일이 있습니다.');
                }
            }
            $attempt = bin2hex(random_bytes(16));
            $this->query("UPDATE files SET state='sending', attempt=?, expires=? WHERE owner=? AND draft=? AND state='ready'",
                [$attempt,time()+1800,$owner,$draft]);
            return [$attempt,$rows];
        });
    }

    public function finish(string $attempt, bool $sent): void
    {
        $this->query("UPDATE files SET state=?, expires=?, attempt=NULL WHERE attempt=? AND state='sending'",
            [$sent ? 'sent' : 'ready', time()+self::WEEK,$attempt]);
    }

    public function download(string $token): ?array
    {
        return $this->query("SELECT id,name,size FROM files WHERE token=? AND state='sent' AND expires>?",[$token,time()])->fetch() ?: null;
    }

    public function sent(int $owner): array
    {
        return $this->query("SELECT id,name,expires FROM files WHERE owner=? AND state='sent' AND expires>? ORDER BY expires DESC",[$owner,time()])->fetchAll();
    }

    public function revoke(int $owner, string $id): void
    {
        $this->query("UPDATE files SET state='trash' WHERE id=? AND owner=? AND state='sent'",[$id,$owner]);
    }

    public function collect(): void
    {
        $now = time();
        $this->atomic(function () use ($now) {
            $this->query("UPDATE files SET expires=?, state='ready', attempt=NULL WHERE state='sending' AND expires<=?",[$now+self::WEEK,$now]);
            $this->query("UPDATE files SET state='trash' WHERE state <> 'sending' AND expires<=?",[$now]);
        });
        foreach ($this->query("SELECT id FROM files WHERE state='trash'")->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $this->discard($id);
            $this->query("DELETE FROM files WHERE id=? AND state='trash'",[$id]);
        }
        // A crash between tus creation and SQLite commit can leave an unreferenced file.
        foreach (glob(self::DATA.'/*.info') ?: [] as $info) {
            $id = basename($info,'.info');
            if (filemtime($info) < $now-86400 && !$this->query('SELECT 1 FROM files WHERE id=?',[$id])->fetchColumn()) {
                $this->discard($id);
            }
        }
    }

    private function discard(string $id): void
    {
        [$code] = $this->tus('DELETE',$id);
        if (!in_array($code,[204,404,410],true)) {
            throw new RuntimeException('File cleanup failed');
        }
    }
}
