// Run against tests/compose.test.yaml only. Requires playwright-core and a local Chromium.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const crypto = require('node:crypto');
const {chromium} = require('playwright-core');
const origin = 'http://127.0.0.1:19847';
const title = 'Local attachment test ' + Date.now();
const bytes = process.env.TEST_ONE_GB === '1' ? 1000000000 : 2000000;

async function login(page,user,password) {
    await page.goto(origin);
    await page.locator('input[name=_user]').fill(user + '@example.test');
    await page.locator('input[name=_pass]').fill(password);
    await page.locator('button[type=submit]').click();
    await page.waitForFunction(() => window.rcmail?.env.task === 'mail');
    await page.waitForLoadState('networkidle');
}

async function api(page,op,fields = {}) {
    return page.evaluate(async ({op,fields}) => {
        const body = new FormData();
        for (const [key,value] of Object.entries({...fields,op,_id:rcmail.env.compose_id})) body.append(key,value);
        const r = await fetch(rcmail.url('plugin.uptempo-files'),{method:'POST',body,headers:{'X-Roundcube-Request':rcmail.env.request_token}});
        return {code:r.status,data:await r.json()};
    },{op,fields});
}

async function openCompose(page) {
    await page.goto(origin+'/?_task=mail&_action=compose');
    await page.getByRole('button',{name:'대용량 파일 첨부',exact:true}).waitFor();
    await page.waitForFunction(() => rcmail.editor);
    await page.waitForLoadState('networkidle');
}

async function run() {
    const dir = fs.mkdtempSync(path.join(os.tmpdir(),'uptempo-files-'));
    const original = path.join(dir,'한글 자료.bin');
    const browser = await chromium.launch({headless:true,executablePath:process.env.CHROME_PATH});
    try {
        const page = await browser.newPage();
        page.on('pageerror', e => console.error('Browser:', e.message));
        page.on('response', async res => {
            if (res.url().includes('uptempo-files') && res.status() >= 400) {
                console.log('Attachment API',res.status(),await res.text().catch(() => ''));
            }
        });
        page.on('dialog', dialog => dialog.accept());
        await login(page,'test','local-test');
        await openCompose(page);
        const missingTokenStatus = await page.evaluate(async () => {
            const body = new URLSearchParams({op:'list',_id:rcmail.env.compose_id});
            return (await fetch(rcmail.url('plugin.uptempo-files'),{method:'POST',body})).status;
        });
        assert.equal(missingTokenStatus,403,'authenticated attachment API requires a CSRF token');
        console.log('PASS compose button and existing mailbox login');
        const hasRegular = bytes < 1000000000;
        if (hasRegular) {
            const chooser = page.waitForEvent('filechooser');
            await page.locator('#compose-attachments button.attach').click();
            await (await chooser).setFiles({name:'ordinary.txt',mimeType:'text/plain',buffer:Buffer.from('small')});
            await page.locator('#attachment-list .attachment-name').filter({hasText:'ordinary.txt'}).waitFor();
            assert.equal((await api(page,'list')).data.normal,5);
        }
        const fd = fs.openSync(original,'w');
        fs.ftruncateSync(fd,bytes);
        fs.writeSync(fd,crypto.randomBytes(128),0,128,bytes-128);
        fs.closeSync(fd);

        // Lose a PATCH response after the upstream accepted the bytes. Resuming must not duplicate bytes.
        let lost = false;
        await page.route('**/*',async route => {
            const req = route.request();
            if (!lost && req.url().includes('uptempo-files') && req.postDataBuffer()?.subarray(0,1100).includes(Buffer.from('\r\nchunk\r\n'))) {
                lost = true;
                await route.fetch();
                if (hasRegular) await page.getByRole('button',{name:'업로드 일시정지'}).click();
                return route.abort();
            }
            return route.continue();
        });
        await page.locator('#compose-attachments section input[type=file]').setInputFiles(original);
        if (hasRegular) {
            await page.getByText('업로드를 일시정지했습니다.', {exact:false}).waitFor();
            const chooseAgain = page.waitForEvent('filechooser');
            await page.getByRole('button',{name:'이어올리기'}).click();
            await (await chooseAgain).setFiles(original);
        }
        await page.waitForFunction(() => {
            const panel = document.querySelector('#compose-attachments section');
            const error = panel.querySelector('[role=status]').textContent;
            if (error.includes('제거할 수 있습니다.') && !error.includes('일시정지')) throw new Error(error);
            return panel.querySelector('ul')?.textContent.includes('발송 시 본문');
        },null,{timeout:1200000});
        assert(lost);
        const items = (await api(page,'list')).data.files;
        assert.equal(items.length,1);
        const token = new URL(items[0].url).hash.slice(1);
        const download = () => fetch(origin+'/large-download.php',{method:'POST',body:new URLSearchParams({token})});
        assert.equal((await download()).status,404);
        const reject = await api(page,'create',{name:'limit.dat',size:String(1000000001-bytes),signature:'a'.repeat(64)});
        assert.equal(reject.code,422);
        console.log('PASS upload, resume, combined limit, pre-send download denied:',bytes);

        await page.locator('input[name=_subject]').fill(title);
        await page.evaluate(() => {
            rcmail.editor.set_content('첨부한 원본 파일을 확인하세요.');
            document.querySelector('[name=_to]').value='reader@example.test';
            rcmail.command('savedraft',{});
        });
        await page.waitForFunction(() => !!rcmail.env.draft_id);
        const draftId = await page.evaluate(() => rcmail.env.draft_id);
        await page.goto(origin+'/?_task=mail&_action=compose&_draft_uid='+draftId+'&_mbox=Drafts');
        await page.getByRole('button',{name:'대용량 파일 첨부',exact:true}).waitFor();
        const restored = await api(page,'list');
        assert.equal(restored.data.files[0]?.id,items[0].id, 'saved draft restores upload');
        assert.equal(new URL(restored.data.files[0].url).hash.slice(1),token);
        await page.evaluate(() => {
            document.querySelector('[name=_to]').value='test@example.test';
            document.querySelector('[name=_bcc]').value='reader@example.test';
            rcmail.command('send',{});
        });
        let response;
        for (let i=0;i<40;i++) {
            response = await download();
            if (response.ok) break;
            await new Promise(r => setTimeout(r,250));
        }
        assert.equal(response.status,200,'SMTP acceptance activates link');
        const hashed = crypto.createHash('sha256');
        let received = 0;
        for await (const chunk of response.body) { hashed.update(chunk); received += chunk.length; }
        assert.equal(received,bytes);
        const source = crypto.createHash('sha256');
        for await (const chunk of fs.createReadStream(original)) source.update(chunk);
        assert.equal(hashed.digest('hex'),source.digest('hex'));
        console.log('PASS saved draft, SMTP activation, anonymous download SHA-256:',received);
        const reader = await browser.newPage();
        await login(reader,'reader','local-reader');
        const receivedMessage = reader.getByText(title,{exact:true}).first();
        await receivedMessage.waitFor();
        const uid = await receivedMessage.evaluate(el => el.closest('tr').uid);
        await reader.goto(origin+'/?_task=mail&_action=show&_mbox=INBOX&_uid='+encodeURIComponent(uid));
        const deliveredLink = reader.locator('a[href*="large-download.php"]').first();
        const href = await deliveredLink.getAttribute('href');
        assert.equal(new URL(href, origin).hash.slice(1),token);
        console.log('PASS blind-copy recipient received the working link');
        if (hasRegular) {
            await reader.getByText('ordinary.txt',{exact:true}).first().waitFor();
            const guest = await browser.newPage({acceptDownloads:true});
            await guest.goto(new URL(href,origin).href);
            const downloading = guest.waitForEvent('download');
            await guest.getByRole('button',{name:'원본 파일 다운로드'}).click();
            const downloaded = await downloading;
            assert.equal(downloaded.suggestedFilename(),'한글 자료.bin');
            assert.equal(await downloaded.failure(),null);
            await guest.close();
            console.log('PASS ordinary MIME attachment alongside anonymous browser download');
        }
        await openCompose(reader);
        await api(reader,'revoke',{file_id:items[0].id});
        let stillActive = await download();
        assert.equal(stillActive.status,200);
        await stillActive.body.cancel();
        await openCompose(page);
        await api(page,'revoke',{file_id:items[0].id});
        assert.equal((await download()).status,404);
        console.log('PASS owner-only revocation');
    } finally {
        await browser.close();
        fs.rmSync(original,{force:true});
        fs.rmdirSync(dir);
    }
}
run().catch(e => { console.error(e); process.exitCode=1; });
