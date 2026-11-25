<?php
declare(strict_types=1);

require_once __DIR__ . '/Files.php';

class uptempo_files extends rcube_plugin
{
    public $task = 'mail';
    private ?UptempoFiles $files = null;
    private ?string $attempt = null;
    private const HEADER = 'X-Uptempo-File-Draft';

    public function init()
    {
        $rc = rcmail::get_instance();
        $this->register_action('plugin.uptempo-files', [$this,'api']);
        if ($rc->action === 'compose') {
            $this->include_script('compose.js');
            $this->add_hook('storage_init', static function ($p) {
                $p['fetch_headers'] = trim(($p['fetch_headers'] ?? '') . ' ' . self::HEADER);
                return $p;
            });
            $this->add_hook('message_compose_body', [$this,'restore']);
        }
        $this->add_hook('message_ready', [$this,'draftHeader']);
        $this->add_hook('message_draftsaved', [$this,'draftSaved']);
        $this->add_hook('message_before_send', [$this,'beforeSend']);
        $this->add_hook('message_sent', fn($p) => $this->finish($p,true));
        $this->add_hook('message_send_error', fn($p) => $this->finish($p,false));
    }

    private function store(): UptempoFiles
    {
        return $this->files ??= new UptempoFiles();
    }

    private function owner(): int
    {
        return (int)rcmail::get_instance()->user->ID;
    }

    private function &compose(): array
    {
        $id = rcube_utils::get_input_string('_id', rcube_utils::INPUT_GPC);
        if (!$this->owner() || !$id || !isset($_SESSION['compose_data_'.$id]['id'])) {
            throw new DomainException('메일 작성 화면을 다시 열어주세요.');
        }
        $c = &$_SESSION['compose_data_'.$id];
        // Concurrent requests for a fresh compose must agree even before the session is saved.
        $c['uptempo_group'] ??= substr(hash('sha256', session_id().'|'.$id), 0, 32);
        $c['uptempo_expected'] ??= [];
        return $c;
    }

    private function normalBytes(array $c): int
    {
        return (int)array_sum(array_column(rcmail::get_instance()->list_uploaded_files($c['id']), 'size'));
    }

    public function restore($p)
    {
        if ($p['mode'] === 'draft' && !empty($p['message'])) {
            $c = &$this->compose();
            $header = trim((string)$p['message']->get_header(self::HEADER,true));
            if (empty($c['uptempo_restored']) && preg_match('/^[a-f0-9]{32}(?:\s+[a-f0-9]{32}){1,32}$/D',$header)) {
                $ids = preg_split('/\s+/', $header);
                $c['uptempo_group'] = array_shift($ids);
                $c['uptempo_expected'] = $ids;
                $c['uptempo_restored'] = true;
            }
        }
        return $p;
    }

    public function api()
    {
        $rc = rcmail::get_instance();
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        try {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !$this->owner() || !$rc->check_request()
                || !hash_equals($rc->get_request_token(), (string)($_SERVER['HTTP_X_ROUNDCUBE_REQUEST'] ?? ''))) {
                http_response_code(403);
                throw new DomainException('다시 로그인해 주세요.');
            }
            $c = &$this->compose();
            $owner = $this->owner();
            $draft = $c['uptempo_group'];
            $id = (string)($_POST['file_id'] ?? '');
            $store = $this->store();
            switch ($_POST['op'] ?? '') {
                case 'list':
                    $items = [];
                    foreach ($store->rows($owner,$draft) as $r) {
                        $items[$r['id']] = [
                            'id' => $r['id'], 'name' => $r['name'], 'size' => (int)$r['size'],
                            'signature' => $r['signature'], 'url' => $this->downloadUrl($r),
                            'state' => $r['expires'] <= time() ? 'expired' : $r['state'],
                        ];
                    }
                    foreach ($c['uptempo_expected'] as $key) {
                        $items[$key] ??= ['id'=>$key,'name'=>'만료된 첨부','size'=>0,'state'=>'expired'];
                    }
                    $result = [
                        'files' => array_values($items), 'normal' => $this->normalBytes($c),
                        'limit' => UptempoFiles::LIMIT, 'chunk' => UptempoFiles::CHUNK,
                    ];
                    break;
                case 'create':
                    if (getenv('UPTEMPO_LARGE_UPLOADS') === '0') {
                        throw new DomainException('새 대용량 첨부 접수가 일시 중단되었습니다.');
                    }
                    $id = $store->create(
                        $owner, $draft, trim((string)($_POST['name'] ?? '')), (int)($_POST['size'] ?? 0),
                        $this->normalBytes($c), (string)($_POST['signature'] ?? '')
                    );
                    $c['uptempo_expected'][] = $id;
                    $result = ['id'=>$id];
                    break;
                case 'offset':
                case 'chunk':
                    if ($_POST['op'] === 'chunk' && !isset($_FILES['chunk'])) {
                        throw new DomainException('업로드 조각이 없습니다.');
                    }
                    $result = ['offset'=>$store->transfer($owner,$draft,$id,$_FILES['chunk'] ?? null,(int)($_POST['offset'] ?? 0))];
                    break;
                case 'remove':
                    $store->remove($owner,$draft,$id);
                    $c['uptempo_expected'] = array_values(array_diff($c['uptempo_expected'],[$id]));
                    $result = ['ok'=>true];
                    break;
                case 'sent':
                    $result = ['files'=>$store->sent($owner)];
                    break;
                case 'revoke':
                    $store->revoke($owner,$id);
                    $result = ['ok'=>true];
                    break;
                default:
                    throw new DomainException('알 수 없는 요청입니다.');
            }
            echo json_encode($result, JSON_THROW_ON_ERROR);
        } catch (DomainException $e) {
            if (http_response_code() < 400) {
                http_response_code(422);
            }
            echo json_encode(['error'=>$e->getMessage()]);
        } catch (Throwable $e) {
            error_log('Uptempo attachment request: '.$e->getMessage());
            http_response_code(503);
            echo json_encode(['error'=>'파일 저장소에 연결할 수 없습니다. 잠시 후 다시 시도하세요.']);
        }
        exit;
    }

    public function draftHeader($p)
    {
        if (!empty($_POST['_draft'])) {
            $c = &$this->compose();
            $ids = array_unique(array_merge($c['uptempo_expected'],array_column($this->store()->rows($this->owner(),$c['uptempo_group']),'id')));
            if ($ids) {
                $p['message']->headers([self::HEADER => $c['uptempo_group'].' '.implode(' ', $ids)]);
            }
        }
        return $p;
    }

    public function draftSaved($p)
    {
        $c = &$this->compose();
        $this->store()->saveDraft($this->owner(),$c['uptempo_group']);
        return $p;
    }

    private function downloadUrl(array $file): string
    {
        $base = rtrim((string)rcmail::get_instance()->config->get('uptempo_download_origin','https://mail.cloudfan.shop'),'/');
        return $base.'/large-download.php#'.$file['token'];
    }

    private function appendLinks($message, array $files): void
    {
        $text = '';
        $html = '';
        foreach ($files as $file) {
            $url = $this->downloadUrl($file);
            $label = $file['name'].' ('.number_format($file['size']).' B · 발송 후 7일간 다운로드)';
            if (!str_contains((string)$message->getTXTBody(),$url)) {
                $text .= "\r\n".$label."\r\n".$url."\r\n";
            }
            if (!str_contains((string)$message->getHTMLBody(),$url)) {
                $html .= '<p><a href="'.$url.'">'.htmlspecialchars($label,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'</a></p>';
            }
        }
        $message->setTXTBody($message->getTXTBody()."\r\n".$text);
        if ($body = $message->getHTMLBody()) {
            $pos = strripos($body,'</body>');
            $message->setHTMLBody($pos === false ? $body.$html : substr_replace($body,$html,$pos,0));
        }
    }

    public function beforeSend($p)
    {
        if (rcmail::get_instance()->action !== 'send') {
            return $p; // E.g. a read receipt, outside the compose workflow.
        }
        try {
            $c = &$this->compose();
            $normal = $this->normalBytes($c);
            if ($normal >= UptempoFiles::WIRE_LIMIT) {
                throw new DomainException('일반 첨부를 대용량 첨부로 옮겨주세요.');
            }
            [$this->attempt,$files] = $this->store()->reserveSend($this->owner(),$c['uptempo_group'],$c['uptempo_expected'],$normal);
            if ($files) {
                $this->appendLinks($p['message'],$files);
            }
            UptempoFiles::checkMessageSize($p['message']);
        } catch (Throwable $e) {
            $this->finish([], false);
            if (!$e instanceof DomainException) {
                error_log('Uptempo send: '.$e->getMessage());
            }
            $p['abort'] = true;
            $p['result'] = false;
            $p['error'] = $e instanceof DomainException ? $e->getMessage() : '첨부파일 상태를 확인하고 다시 보내주세요.';
        }
        return $p;
    }

    private function finish($p, bool $success)
    {
        if ($this->attempt !== null) {
            $this->store()->finish($this->attempt,$success);
            $this->attempt = null;
        }
        return $p;
    }
}
