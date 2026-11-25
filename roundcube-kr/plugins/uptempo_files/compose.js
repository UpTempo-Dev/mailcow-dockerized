/* global rcmail */
rcmail.addEventListener('init', function () {
    const host = document.getElementById('compose-attachments');
    if (!host || !rcmail.env.compose_id) return;
    const panel = document.createElement('section');
    panel.innerHTML = '<button type="button" class="btn btn-secondary">대용량 파일 첨부</button>' +
        '<input type="file" multiple hidden><p class="small"></p><ul class="list-unstyled"></ul>' +
        '<div role="status" aria-live="polite"></div>';
    host.append(panel);
    const [choose, picker, budget, list, status] = panel.children;
    let state = {files: [], normal: 0, limit: 1000000000};
    let busy = false;
    let pauseRequested = false;
    let resumeId = null;
    let loadVersion = 0;
    const offsets = new Map();
    const pause = button('업로드 일시정지', () => {
        pauseRequested = true;
        pause.disabled = true;
    });
    pause.hidden = true;
    choose.after(pause);
    const format = n => new Intl.NumberFormat('ko-KR').format(n) + ' B';
    const manage = document.createElement('details');
    manage.innerHTML = '<summary>보낸 파일 다운로드 관리</summary><ul></ul>';
    panel.append(manage);
    manage.ontoggle = async () => {
        if (!manage.open) return;
        try {
            const ul = manage.querySelector('ul');
            const {files} = await api('sent');
            ul.replaceChildren();
            for (const f of files) {
                const li = document.createElement('li');
                li.append(document.createTextNode(f.name + ' '), button('다운로드 중지',async () => {
                    if (!confirm(f.name + ' 다운로드를 중지할까요?')) return;
                    try { await api('revoke',{file_id:f.id}); li.remove(); }
                    catch (e) { status.textContent = e.message; }
                }));
                ul.append(li);
            }
            if (!files.length) ul.textContent = '유효한 링크가 없습니다.';
        } catch (e) { status.textContent = e.message; }
    };

    async function api(op, fields = {}) {
        const body = new FormData();
        body.append('_id', rcmail.env.compose_id);
        body.append('op', op);
        for (const [k,v] of Object.entries(fields)) body.append(k,v);
        const response = await fetch(rcmail.url('plugin.uptempo-files'), {
            method: 'POST', body, credentials: 'same-origin', signal: AbortSignal.timeout(60000),
            headers: {'X-Roundcube-Request': rcmail.env.request_token}
        });
        const data = await response.json();
        if (!response.ok) throw new Error(data.error || '파일 요청 실패');
        return data;
    }

    function button(title, action) {
        const node = document.createElement('button');
        node.type = 'button';
        node.className = 'btn btn-sm btn-secondary';
        node.textContent = title;
        node.disabled = busy;
        node.onclick = action;
        return node;
    }

    function render() {
        choose.disabled = busy;
        pause.hidden = !busy;
        pause.disabled = pauseRequested;
        const used = state.files.reduce((sum, f) => sum + f.size, state.normal);
        budget.textContent = '원본 합계 1GB · ' + (used > state.limit ? format(used - state.limit) + ' 초과' : '남은 용량 ' + format(state.limit - used)) + ' · 발송 후 7일';
        list.replaceChildren();
        for (const f of state.files) {
            const li = document.createElement('li');
            li.style.overflowWrap = 'anywhere';
            const progress = f.state === 'uploading' ? (offsets.has(f.id)
                ? Math.floor(offsets.get(f.id) * 100 / f.size) + '%' : '업로드 미완료') :
                ({ready:'발송 시 본문에 다운로드 링크 추가', expired:'만료됨', sending:'발송 중', sent:'발송됨'}[f.state] || '');
            li.append(document.createTextNode(f.name + ' · ' + format(f.size) + ' · ' + progress + ' '));
            if (f.state === 'uploading') li.append(button('이어올리기', () => select(f.id)));
            if (!['sending','sent'].includes(f.state)) li.append(button('제거', async () => {
                try {
                    await api('remove',{file_id:f.id});
                    rcmail.cmp_hash = null;
                    await refresh();
                } catch (e) { status.textContent = e.message; }
            }));
            list.append(li);
        }
    }

    async function refresh() {
        const version = ++loadVersion;
        const result = await api('list');
        if (version === loadVersion) { state = result; render(); }
    }

    function select(id) {
        resumeId = id;
        picker.multiple = !id;
        picker.value = '';
        picker.click();
    }

    async function signature(file) {
        // A resumed selection must match every byte, including the middle of a large file.
        const block = 4 * 1024 * 1024;
        const hashes = [];
        for (let pos = 0; pos < file.size; pos += block) {
            checkPaused();
            const bytes = await file.slice(pos,pos+block).arrayBuffer();
            hashes.push(await crypto.subtle.digest('SHA-256',bytes));
        }
        const digest = await crypto.subtle.digest('SHA-256',await new Blob(hashes).arrayBuffer());
        return Array.from(new Uint8Array(digest), b => b.toString(16).padStart(2,'0')).join('');
    }

    function checkPaused() {
        if (pauseRequested) throw new Error('업로드를 일시정지했습니다.');
    }

    async function upload(file, id) {
        if (!file.size || file.size > state.limit) throw new Error('파일 크기는 1바이트부터 1GB까지입니다.');
        status.textContent = '파일을 확인하고 있습니다.';
        const fingerprint = await signature(file);
        checkPaused();
        status.textContent = '';
        if (id) {
            const original = state.files.find(f => f.id === id);
            if (!original || original.size !== file.size || original.name !== file.name || original.signature !== fingerprint) {
                throw new Error('처음 선택했던 원본 파일을 다시 선택하세요.');
            }
        } else {
            const used = state.files.reduce((sum, f) => sum + f.size,state.normal);
            if (used + file.size > state.limit) throw new Error('파일 합계가 1GB를 초과합니다.');
            id = (await api('create',{name:file.name,size:file.size,signature:fingerprint})).id;
            rcmail.cmp_hash = null;
            await refresh();
        }
        let pos = (await api('offset',{file_id:id})).offset;
        let retries = 0;
        while (pos < file.size) {
            checkPaused();
            try {
                const next = await api('chunk',{file_id:id,offset:pos,chunk:file.slice(pos,pos+state.chunk)});
                if (next.offset <= pos) throw new Error('저장 위치를 다시 확인해야 합니다.');
                pos = next.offset;
                retries = 0;
            } catch (e) {
                checkPaused();
                if (++retries > 3) throw e;
                await new Promise(resolve => setTimeout(resolve, 1000 * retries));
                try { pos = (await api('offset',{file_id:id})).offset; } catch { /* retry this chunk */ }
            }
            offsets.set(id,pos);
            render();
        }
        await refresh();
        rcmail.cmp_hash = null;
    }

    choose.onclick = () => select(null);
    picker.onchange = async () => {
        if (busy || !picker.files.length) return;
        busy = true;
        pauseRequested = false;
        status.textContent = '';
        render();
        try {
            await refresh();
            for (const f of picker.files) await upload(f,resumeId);
        } catch (e) {
            status.textContent = e.message + ' 목록에서 이어올리거나 제거할 수 있습니다.';
        } finally {
            busy = false;
            render();
        }
    };

    rcmail.addEventListener('beforesend', () => {
        if (state.files.reduce((total, f) => total + f.size, state.normal) > state.limit) {
            rcmail.display_message('첨부 합계가 1GB를 초과합니다.', 'warning');
            return false;
        }
        if (busy || state.files.some(f => f.state !== 'ready')) {
            status.textContent = '미완료·만료 파일을 확인하세요.';
            rcmail.display_message(status.textContent,'warning');
            return false;
        }
    });
    // Native upload/remove changes the combined budget too.
    const regular = document.getElementById('attachment-list');
    let timer;
    if (regular) new MutationObserver(() => {
        clearTimeout(timer);
        timer = setTimeout(() => refresh().catch(e => { status.textContent = e.message; }),200);
    }).observe(regular,{childList:true});
    refresh().catch(e => { status.textContent = e.message; });
});
