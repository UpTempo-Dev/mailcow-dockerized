# 한국 웹메일 대용량 첨부

Roundcube 작성 화면의 **대용량 파일 첨부**를 누르면 파일이 한국 서버의 Docker 볼륨에 저장된다. 발송할 때 플러그인이 메일 본문에 파일명, 크기, 다운로드 링크를 넣는다. Mailcow와 Brevo에는 본문과 일반 첨부만 전송된다. 수신자는 링크를 열고 **원본 파일 다운로드**를 누른다.

대용량 원본은 SMTP 첨부 검사 경로를 거치지 않는다. 원본에 대한 악성코드 검사가 필요한 환경에서는 다운로드를 허용하기 전에 파일 저장소에서 별도로 검사해야 한다.

| 항목 | 적용 기준 |
|---|---|
| 한 통의 첨부 원본 합계 | 일반 첨부와 대용량 파일 합계 1,000,000,000바이트. 대용량 파일 최대 32개 |
| 실제 메일 크기 | 헤더, 본문, 링크, 일반 첨부를 MIME 인코딩한 결과 최대 20,971,520바이트. 일반 첨부에는 기존 PHP·프록시의 요청당 업로드 한도도 적용 |
| 다운로드 | 링크를 가진 사람이 이용할 수 있다. Postfix 접수부터 7일간 유효하며, 보낸 사람이 작성 화면의 **보낸 파일 다운로드 관리**에서 즉시 회수할 수 있다. 링크를 전달받은 사람에게도 같은 권한이 생긴다. |
| 미발송 파일 | 부분 업로드는 마지막 조각 저장 뒤 24시간, 완료된 파일은 업로드 완료 또는 마지막 초안 저장 뒤 7일. 같은 초안에서 같은 원본을 선택하면 이어올릴 수 있다. |
| 정리 | 만료 시점부터 새 다운로드를 거부한다. 정리 서비스가 매시간 만료·제거·회수된 원본과 메타데이터를 삭제한다. |
| 저장 공간 | 예약 크기로 사용자당 5GB, 전체 50GB. 파일 수와 메타데이터 사용량을 제한하기 위해 파일마다 최소 1MiB를 예약한다. 정리 대기 중인 파일도 포함한다. |

## 한국 서버 배포

회사 포크의 배포 브랜치에 변경이 병합되고 배포가 승인된 뒤 한국 서버에서 실행한다.

```bash
cd ~/product/mailcow-dockerized
git status --short --branch
git pull --ff-only origin master
cd roundcube-kr
docker compose config -q && docker compose pull && docker compose up -d
docker compose ps
docker compose logs --tail=20 uploads upload-cleanup
```

서비스 표에서 `webmail`, `uploads`, `upload-cleanup`이 실행 중인지 확인한다. 웹 진입점은 기존 `10.77.0.16:18081`이다. 브라우저가 같은 웹메일 주소로 파일을 512KiB씩 올리고, Roundcube가 로그인 및 작성 권한을 검사한 뒤 내부망의 tusd에 보낸다. tusd의 8080 포트는 호스트에 공개하지 않는다. GCP 웹 VM의 기존 웹메일 프록시가 작성 요청과 `/large-download.php`를 모두 같은 웹메일로 전달해야 한다. 업로드 요청 한도에는 512KiB 조각과 multipart 헤더가 들어갈 여유가 필요하다.

배포 후 내부 테스트 계정에서 일반 첨부 하나와 대용량 파일 하나를 함께 보내고, 외부 수신자가 받은 메일의 링크에서 원본을 내려받아 SHA-256을 대조한다. 이어서 **보낸 파일 다운로드 관리 → 다운로드 중지**를 누르고 같은 링크로 새 다운로드가 차단되는지 확인한다. 서버 저장량은 `docker compose exec uploads du -sh /srv/tusd-data`로 확인할 수 있다.

새 파일 접수만 잠시 중단하려면 이 디렉터리의 `.env`에 `UPTEMPO_LARGE_UPLOADS=0`을 추가하고 `docker compose up -d webmail`로 적용한다. 이미 작성 중인 파일의 이어올리기·발송, 기존 링크의 다운로드와 정리는 계속된다. 재개할 때 값을 `1`로 바꾸고 같은 명령을 실행한다. `upload_data`에는 원본이, `preferences`에는 링크 상태와 소유자가 있으므로 재배포·복구 시 두 볼륨을 함께 보존한다.

## 로컬 검증

테스트 구성은 별도 프로젝트명, 로컬 포트 19847, 테스트 메일함 두 개를 사용한다. 아래 명령은 `roundcube-kr`에서 실행한다. 브라우저 검증에는 Node.js와 `playwright-core`, 로컬 Chromium이 필요하다.

```bash
docker compose -p uptempo-files-local-test -f compose.yaml -f tests/compose.test.yaml up -d
docker compose -p uptempo-files-local-test -f compose.yaml -f tests/compose.test.yaml exec -T -u www-data webmail php /opt/uptempo-tests/lifecycle.php
CHROME_PATH=/usr/bin/chromium TEST_ONE_GB=1 node tests/acceptance.cjs
docker compose -p uptempo-files-local-test -f compose.yaml -f tests/compose.test.yaml down
```

브라우저 테스트는 실제 초안 저장·복원, 응답이 끊긴 업로드 재개, 발송 전 다운로드 차단, SMTP 접수 및 수신, 원본 해시 일치, 소유자 회수를 검사한다. 빠르게 확인하려면 `TEST_ONE_GB`를 생략해 2MB 파일을 사용한다.
