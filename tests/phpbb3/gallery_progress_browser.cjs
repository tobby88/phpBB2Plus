// Operate the normal file picker, submit button and network stack of an isolated Chromium.
const {spawn} = require('node:child_process');
const fs = require('node:fs/promises');
const path = require('node:path');
const [browser, profile, url, mode, photo, oversized] = process.argv.slice(2);
const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));

async function until(read, description) {
    const end = Date.now() + 18000;
    while (Date.now() < end) {
        const result = await read();
        if (result) return result;
        await sleep(50);
    }
    throw Error('Timed out waiting for ' + description);
}

async function exercise() {
    if (new URL(url).hostname !== '127.0.0.1') throw Error('A synthetic loopback board is required');
    const chrome = spawn(browser, ['--headless','--disable-background-networking','--disable-component-update','--disable-extensions',
        '--disable-sync','--no-first-run','--no-default-browser-check','--remote-debugging-port=0','--remote-debugging-address=127.0.0.1',
        '--host-resolver-rules=MAP * ~NOTFOUND, EXCLUDE 127.0.0.1, EXCLUDE localhost','--user-data-dir='+profile,'about:blank'],
        {windowsHide:true, stdio:'ignore'});
    let socket, command;
    try {
        const port = await until(async () => {
            try { return Number((await fs.readFile(path.join(profile,'DevToolsActivePort'),'utf8')).split('\n')[0]); }
            catch { return 0; }
        }, 'the local browser');
        const targets = await (await fetch('http://127.0.0.1:'+port+'/json/list')).json();
        const target = new URL(targets.find(t => t.type === 'page').webSocketDebuggerUrl);
        if (target.protocol !== 'ws:' || target.hostname !== '127.0.0.1' || target.port !== String(port)) throw Error('Unexpected local debugger address');
        socket = new WebSocket(target);
        await new Promise((resolve, reject) => { socket.onopen = resolve; socket.onerror = reject; });
        const replies = new Map(), posts = new Set(), lengths = new Map();
        let seq = 0;
        command = (method, params = {}) => new Promise((resolve,reject) => {
            const id = ++seq;
            const timer = setTimeout(() => { replies.delete(id); reject(Error('No debugger reply for '+method)); },6500);
            replies.set(id,{resolve,reject,timer});
            socket.send(JSON.stringify({id, method, params}));
        });
        socket.onclose = () => {
            for (const reply of replies.values()) { clearTimeout(reply.timer); reply.reject(Error('Local browser closed')); }
            replies.clear();
        };
        socket.onmessage = ({data}) => {
            const d = JSON.parse(data);
            if (replies.has(d.id)) {
                const p = replies.get(d.id);
                replies.delete(d.id);
                clearTimeout(p.timer);
                if (d.error) p.reject(Error(d.error.message)); else p.resolve(d.result);
            } else if (d.method === 'Network.requestWillBeSent' && d.params.request.method === 'POST' &&
                       new URL(d.params.request.url).pathname === new URL(url).pathname) {
                posts.add(d.params.requestId);
            } else if (d.method === 'Network.requestWillBeSentExtraInfo') {
                const headers = d.params.headers;
                lengths.set(d.params.requestId, Number(headers['Content-Length'] || headers['content-length']));
            }
        };
        async function find(selector) {
            try {
                const {root} = await command('DOM.getDocument');
                return (await command('DOM.querySelector',{nodeId:root.nodeId, selector})).nodeId;
            } catch (error) {
                // A regular multipart POST replaces the whole document between these two reads.
                if (/Could not find node|No node with given id/i.test(error.message)) return 0;
                throw error;
            }
        }
        async function evaluate(expression) {
            const result = await command('Runtime.evaluate',{expression,returnByValue:true});
            if (result.exceptionDetails) throw Error('Unable to inspect the local browser page');
            return result.result.value;
        }
        if (mode === 'member-tools') {
            const {cookies, agent} = JSON.parse(require('node:fs').readFileSync(0, 'utf8'));
            await command('Network.enable');
            await command('Network.setUserAgentOverride', {userAgent: agent});
            for (const [name, value] of cookies) await command('Network.setCookie', {name, value, url});
            await command('Network.setCookie', {name:'cookie_consent', value:'1', url});
            await require('./member_profile_browser.cjs')({command, evaluate, until, url});
            await require('./module_editors_browser.cjs')({command, evaluate, until, url});
            await require('./smilie_creator_browser.cjs')({command, find, evaluate, until, url});
            return;
        }
        if (mode === 'quick-reply') {
            await require('./quick_reply_browser.cjs')({command, find, evaluate, until, url});
            return;
        }
        async function fileSubmit(file, doubleClick = false) {
            const tokenNode = await until(() => find('[name="creation_time"]'), 'the form token');
            const token = (await command('DOM.getAttributes',{nodeId:tokenNode})).attributes;
            await sleep(Math.max(0, Number(token[token.indexOf('value')+1])*1000 + 1060 - Date.now()));
            await command('DOM.setFileInputFiles',{nodeId:await find('#album-files'), files:[file]});
            const nodeId = await find('#album-upload [type="submit"]');
            await command('DOM.scrollIntoViewIfNeeded',{nodeId});
            const q = (await command('DOM.getBoxModel',{nodeId})).model.content;
            const point = {x:(q[0]+q[4])/2, y:(q[1]+q[5])/2, button:'left', clickCount:1};
            for (let clickCount = 1; clickCount <= (doubleClick ? 2 : 1); clickCount++) {
                await command('Input.dispatchMouseEvent',{type:'mousePressed', ...point, clickCount});
                await command('Input.dispatchMouseEvent',{type:'mouseReleased', ...point, clickCount});
            }
        }
        await command('Page.enable');
        await command('Network.enable');
        await command('Network.setCookie',{name:'cookie_consent', value:'1', url});
        if (mode === 'native') await command('Emulation.setScriptExecutionDisabled',{value:true});
        await command('Page.navigate',{url});
        await until(() => find('#album-files'), 'the upload form');
        if (mode === 'native') {
            await fileSubmit(photo);
            await until(() => find('#page-body div.panel > .inner > p[role="status"]'), 'the normal multipart confirmation with scripts disabled');
            if (posts.size !== 1 || await find('#album-upload')) throw Error('The ordinary form was submitted more than once');
            return;
        }
        await until(() => evaluate('document.readyState === "complete"'), 'the upload script');
        await command('Network.emulateNetworkConditions',{offline:false, latency:75, downloadThroughput:-1, uploadThroughput:60000});
        // Observe only normal DOM changes. The progress values originate in native upload events.
        await evaluate('(' + function () {
            window.transferHistory = [];
            window.transferMessages = [];
            const status = document.querySelector('#album-upload-status');
            const live = status.querySelector('[role="status"]');
            new MutationObserver(changes => {
                for (const change of changes) for (const node of change.addedNodes) window.transferMessages.push(node.textContent);
            }).observe(live,{childList:true});
            function inspect() {
                const bar = document.querySelector('#album-transfer-bar');
                const row = [status.dataset.state, bar.hasAttribute('value') ? bar.value : null,
                    live.textContent, document.querySelector('#album-bytes').textContent,
                    document.querySelector('#album-speed').textContent, document.querySelector('#album-elapsed').textContent,
                    document.querySelector('#album-remaining').textContent];
                if (JSON.stringify(row) !== JSON.stringify(window.transferHistory.at(-1))) window.transferHistory.push(row);
            }
            new MutationObserver(inspect).observe(status,{subtree:true,childList:true,attributes:true});
        }.toString()+')()');
        if (mode === 'expanded') {
            await fileSubmit(oversized);
            await until(() => evaluate('document.querySelector("#album-upload-status").dataset.state === "error"'), 'a recoverable image-size error');
            if (!await evaluate('!document.querySelector("#album-upload fieldset").disabled && !document.querySelector("#album-upload").hidden')) {
                throw Error('The file picker remained locked after a rejected picture');
            }
            await evaluate('window.transferHistory = []; window.transferMessages = []');
        }
        await fileSubmit(photo, true);
        await until(() => evaluate('document.querySelector("#album-upload-status").dataset.state === "complete"'), 'gallery publication');
        const rows = await evaluate('window.transferHistory');
        const states = rows.map(r => r[0]).filter((s,i,a) => s && s !== a[i-1]);
        const values = rows.map(r => r[1]).filter(v => v !== null);
        const last = rows.at(-1);
        const messages = await evaluate('window.transferMessages');
        const sizes = last[3].split('/').map(s => Number(s.replace(/[^\d]/g,'')));
        if (states.join('/') !== 'sending/processing/complete' || rows.filter(r => r[0] === 'complete').length !== 1 ||
            messages.filter(text => text === last[2]).length !== 1 ||
            !rows.some(r => r[0] === 'sending' && r[1] > 0 && r[1] < 100 && /KiB\/s$/.test(r[4]) && /^\d+:\d\d:\d\d$/.test(r[6])) ||
            !/^\d+:\d\d:\d\d$/.test(last[5]) || last[6] !== '00:00:00' ||
            !values.some(v => v > 0 && v < 100) || values.some((v,i) => i && v < values[i-1]) || last[1] !== 100 ||
            sizes.length !== 2 || sizes[0] !== sizes[1] || sizes[1] !== lengths.get(Array.from(posts).at(-1)) ||
            posts.size !== (mode === 'expanded' ? 2 : 1)) {
            throw Error('Unexpected transfer: '+JSON.stringify({states, values, sizes, wire:lengths.get(Array.from(posts).at(-1)), posts:posts.size}));
        }
        const done = await evaluate('[document.querySelector("#album-upload").hidden, document.querySelector("#album-upload-details").hidden, document.querySelector("#album-upload-status a").hidden]');
        if (!done[0] || done[1] !== (mode === 'compact') || done[2]) throw Error('The saved transfer ignored its configured result display');
        process.stdout.write(JSON.stringify({bytes:sizes[1], intermediate:values.filter(v => v > 0 && v < 100).length}));
    } finally {
        try { if (socket && socket.readyState === WebSocket.OPEN) socket.send(JSON.stringify({id:99999,method:'Browser.close'})); } catch {}
        for (let i=0; i<80 && chrome.exitCode === null && chrome.signalCode === null; i++) await sleep(50);
        if (chrome.exitCode === null && chrome.signalCode === null) chrome.kill();
        if (socket) socket.close();
    }
}
exercise().catch(error => { console.error(error.message); process.exitCode=1; });
