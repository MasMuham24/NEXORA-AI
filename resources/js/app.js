import { marked } from 'marked';
import DOMPurify from 'dompurify';

(() => {
    'use strict';

    const state = {
        conversations: [],
        current: null,
        messages: [],
        search: '',
        streaming: false,
        abortController: null,
    };

    const el = (id) => document.getElementById(id);

    const esc = (s) =>
        String(s ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');

    const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

    const fmtTime = (iso) => {
        if (!iso) return '--:--';
        const d = new Date(iso);
        if (Number.isNaN(d.getTime())) return '--:--';
        return d.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' });
    };

    async function api(path, opts = {}) {
        const headers = {
            'Accept': 'application/json',
            'X-CSRF-TOKEN': csrf(),
            ...(opts.headers || {}),
        };
        if (opts.body && typeof opts.body !== 'string') {
            headers['Content-Type'] = 'application/json';
            opts.body = JSON.stringify(opts.body);
        }
        let res;
        try {
            res = await fetch(path, { ...opts, headers, credentials: 'same-origin' });
        } catch (e) {
            throw new Error('Connection failed.');
        }
        let data = {};
        try {
            data = await res.json();
        } catch (e) {
            data = {};
        }
        return { ok: res.ok, status: res.status, data };
    }

    const toast = (msg) => {
        const t = el('toast');
        t.textContent = msg;
        t.classList.remove('hidden');
        clearTimeout(window.__toastT);
        window.__toastT = setTimeout(() => t.classList.add('hidden'), 3800);
    };

    // ---------------------------------------------------------------- markdown

    marked.setOptions({
        gfm: true,
        breaks: false,
    });

    const MD_ALLOWED_TAGS = [
        'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'ul', 'ol', 'li', 'strong', 'em', 'del', 'blockquote',
        'a', 'code', 'pre', 'table', 'thead', 'tbody', 'tr', 'th', 'td',
        'hr', 'br', 'span', 'div', 'input',
    ];
    const MD_ALLOWED_ATTR = ['href', 'title', 'class', 'target', 'rel', 'checked', 'type'];

    function renderMarkdown(md) {
        const raw = marked.parse(md || '');
        const clean = DOMPurify.sanitize(raw, {
            ALLOWED_TAGS: MD_ALLOWED_TAGS,
            ALLOWED_ATTR: MD_ALLOWED_ATTR,
            ALLOWED_URI_REGEXP: /^(?:(?:https?|mailto):|[^a-z]|[a-z+.-]+(?:[^a-z+.-:]|$))/i,
        });
        const host = document.createElement('div');
        host.className = 'md';
        host.innerHTML = clean;
        decorateCodeBlocks(host);
        return host;
    }

    function decorateCodeBlocks(host) {
        host.querySelectorAll('pre').forEach((pre) => {
            if (pre.dataset.decorated) return;
            pre.dataset.decorated = '1';
            const code = pre.querySelector('code');
            const match = code?.className.match(/language-([\w-]+)/);
            const lang = match ? match[1] : '';
            const wrap = document.createElement('div');
            wrap.className = 'code-wrap';
            const head = document.createElement('div');
            head.className = 'code-head';
            const label = document.createElement('span');
            label.className = 'code-lang';
            label.textContent = lang ? lang.toUpperCase() : 'CODE';
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'code-copy';
            btn.textContent = 'COPY';
            btn.addEventListener('click', async () => {
                const ok = await copyText(code?.textContent ?? '');
                btn.textContent = ok ? 'COPIED' : 'FAILED';
                setTimeout(() => { btn.textContent = 'COPY'; }, 1600);
            });
            head.appendChild(label);
            head.appendChild(btn);
            wrap.appendChild(head);
            pre.parentNode?.insertBefore(wrap, pre);
            wrap.appendChild(pre);
        });
    }

    async function copyText(text) {
        try {
            await navigator.clipboard.writeText(text);
            return true;
        } catch (e) {
            try {
                const ta = document.createElement('textarea');
                ta.value = text;
                ta.setAttribute('readonly', '');
                ta.style.position = 'fixed';
                ta.style.opacity = '0';
                document.body.appendChild(ta);
                ta.select();
                const ok = document.execCommand('copy');
                document.body.removeChild(ta);
                return ok;
            } catch (e2) {
                return false;
            }
        }
    }

    let streamRaf = null;
    let streamTarget = null;
    let streamText = '';
    function queueStreamRender(node, text, area) {
        streamTarget = node;
        streamText = text;
        if (streamRaf) return;
        streamRaf = requestAnimationFrame(() => {
            streamRaf = null;
            if (!streamTarget || !streamTarget.isConnected) return;
            const nearBottom = area.scrollHeight - area.scrollTop - area.clientHeight < 140;
            streamTarget.textContent = '';
            streamTarget.appendChild(renderMarkdown(streamText));
            if (nearBottom) area.scrollTop = area.scrollHeight;
        });
    }

    // ---------------------------------------------------------------- sidebar

    function dayGroup(iso) {
        const d = new Date(iso).toDateString();
        const now = new Date().toDateString();
        if (d === now) return 'TODAY';
        const y = new Date(Date.now() - 86400000).toDateString();
        return d === y ? 'YESTERDAY' : 'OLDER';
    }

    async function loadConversations() {
        const { ok, data } = await api('/conversations');
        if (!ok) {
            toast('Failed to load conversations.');
            return;
        }
        state.conversations = data.data ?? data ?? [];
        state.conversations.sort((a, b) => new Date(b.updated_at) - new Date(a.updated_at));
        renderSidebar();
    }

    function renderSidebar() {
        const list = el('conv-list');
        const q = state.search.trim().toLowerCase();
        const filtered = state.conversations.filter((c) => {
            if (!q) return true;
            return (c.title ?? '').toLowerCase().includes(q);
        });

        const groups = {};
        for (const c of filtered) {
            const g = dayGroup(c.updated_at);
            (groups[g] ||= []).push(c);
        }

        list.innerHTML = '';
        const order = ['TODAY', 'YESTERDAY', 'OLDER'];
        for (const g of order) {
            if (!groups[g] || !groups[g].length) continue;
            const block = document.createElement('div');
            const head = document.createElement('div');
            head.className = 'mb-2 px-1 font-mono text-[10px] tracking-[0.28em] uppercase text-faint flex items-center gap-2';
            head.innerHTML = `<span>${g}</span><span class="h-px flex-1 bg-line"></span><span class="text-dim">${groups[g].length}</span>`;
            block.appendChild(head);

            for (const c of groups[g]) {
                block.appendChild(conversationRow(c));
            }
            list.appendChild(block);
        }

        if (!filtered.length) {
            list.innerHTML = q
                ? '<p class="px-3 py-6 font-mono text-[10px] tracking-widest uppercase text-faint text-center">NO MATCHES</p>'
                : '<p class="px-3 py-6 font-mono text-[10px] tracking-widest uppercase text-faint text-center">WORKSPACE EMPTY</p>';
        }
    }

    function conversationRow(c) {
        const row = document.createElement('div');
        const active = state.current?.id === c.id;
        row.setAttribute('role', 'button');
        row.tabIndex = 0;
        row.className =
            'group w-full flex items-center gap-3 px-3 py-2.5 mb-1 cursor-pointer text-left border transition-colors ' +
            (active
                ? 'border-accent/50 bg-accent/[0.08]'
                : 'border-transparent hover:border-line hover:bg-panel');

        row.innerHTML = `
            <span class="w-1.5 h-1.5 shrink-0 rounded-full ${active ? 'bg-accent' : 'bg-faint'}" style="display:none"></span>
            <div class="min-w-0 flex-1">
                <p class="truncate text-[13px] ${active ? 'text-bone' : 'text-mute group-hover:text-bone'} transition-colors">${esc(c.title || 'UNTITLED')}</p>
                <p class="font-mono text-[10px] text-faint tracking-wider mt-0.5">${esc(c.messages_count ?? 0)} MSG · ${fmtTime(c.updated_at)}</p>
            </div>
            <span class="opacity-0 group-hover:opacity-100 font-mono text-[10px] tracking-widest text-faint uppercase">#${esc(String(c.id).padStart(2, '0'))}</span>
            <button type="button" data-del-conv="${esc(c.id)}" aria-label="Delete conversation"
                class="opacity-0 group-hover:opacity-100 shrink-0 font-mono text-dim hover:text-warn px-1 transition-colors">✕</button>`;

        row.addEventListener('click', (e) => {
            if (e.target.closest('[data-del-conv]')) return;
            openConversation(c.id);
        });
        row.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                if (e.target.closest('[data-del-conv]')) return;
                openConversation(c.id);
            }
        });
        return row;
    }

    // ---------------------------------------------------------------- conversation

    async function openConversation(id, { keepScroll = false } = {}) {
        const area = el('messages-area');
        let keepH = 0;
        if (keepScroll) {
            keepH = area.scrollHeight - area.scrollTop;
        }

        const { ok, data } = await api(`/conversations/${id}`);
        if (!ok) {
            toast('Conversation not found.');
            return;
        }

        state.current = data.conversation;
        state.messages = data.conversation.messages ?? [];
        renderConversationMeta();

        renderMessages();
        if (keepScroll) {
            area.scrollTop = area.scrollHeight - keepH;
        }

        syncModelSelect();
    }

    function renderConversationMeta() {
        const c = state.current;
        el('status-conv').textContent = c ? `CONVERSATION #${c.id}` : 'NO CONVERSATION';
        el('status-title').textContent = c ? (c.title || 'UNTITLED') : 'STANDBY';
    }

    function syncModelSelect() {
        const sel = el('model-select');
        let last = window.NEXORA.defaultModel;
        for (const m of [...state.messages].reverse()) {
            const model = m.metadata?.model;
            if (typeof model === 'string' && model) {
                last = model;
                break;
            }
        }
        const opts = [...sel.options].map((o) => o.value);
        if (opts.includes(last)) {
            sel.value = last;
        } else if (last !== window.NEXORA.defaultModel) {
            sel.value = '';
        } else {
            sel.value = '';
        }
    }

    // ---------------------------------------------------------------- messages

    function renderMessages(prependEmpty = true) {
        const area = el('messages-area');
        area.innerHTML = '';

        if (!state.current) {
            area.appendChild(emptyState());
            return;
        }

        if (!state.messages.length) {
            area.appendChild(conversationEmpty());
            return;
        }

        const wrap = document.createElement('div');
        wrap.className = 'max-w-4xl mx-auto px-3 md:px-6 py-8 space-y-8';
        let n = 0;
        for (const m of state.messages) {
            n += 1;
            wrap.appendChild(renderMessage(m, n));
        }
        area.appendChild(wrap);
        area.scrollTop = area.scrollHeight;
    }

    function emptyState() {
        const box = document.createElement('div');
        box.className = 'relative min-h-full grid place-items-center tech-grid padded';
        box.innerHTML = `
            <div class="absolute inset-0 tech-grid-fine opacity-50 pointer-events-none"></div>
            <div class="relative text-center select-none px-6">
                <p class="font-mono text-[10px] tracking-[0.32em] uppercase text-dim mb-8">AI WORKSPACE / STANDBY</p>
                <h1 class="font-display font-bold tracking-tighter text-bone text-[clamp(2.6rem,9vw,7rem)] leading-none">
                    NEXORA<span class="text-accent">_</span>AI
                </h1>
                <p class="font-mono text-[12px] tracking-[0.44em] uppercase text-mute mt-4">Your Intelligence Workspace</p>
                <div class="mt-12 max-w-lg mx-auto">
                    <p class="font-mono text-[10px] tracking-[0.24em] uppercase text-faint mb-4">/// SUGGESTED SIGNALS</p>
                    <div class="grid gap-2">
                        <button data-prompt="Explain the Laravel service layer pattern with a practical example."
                            class="border border-line hover:border-accent/60 text-left px-4 py-3 text-sm text-mute hover:text-bone transition-colors">
                            <span class="font-mono text-[10px] text-faint mr-2">01</span>Explain the Laravel service layer pattern
                        </button>
                        <button data-prompt="Help me design a modular AI chat backend architecture."
                            class="border border-line hover:border-accent/60 text-left px-4 py-3 text-sm text-mute hover:text-bone transition-colors">
                            <span class="font-mono text-[10px] text-faint mr-2">02</span>Design a modular AI chat backend
                        </button>
                        <button data-prompt="Write clean REST API endpoints for a conversation workspace."
                            class="border border-line hover:border-accent/60 text-left px-4 py-3 text-sm text-mute hover:text-bone transition-colors">
                            <span class="font-mono text-[10px] text-faint mr-2">03</span>Write clean REST API endpoints
                        </button>
                    </div>
                    <button id="empty-new-chat"
                        class="mt-8 inline-flex items-center gap-3 bg-accent hover:bg-accenthi text-ink font-semibold text-xs tracking-[0.2em] uppercase px-6 py-3 transition-colors">
                        <span>+ Start a new conversation</span>
                        <span class="font-mono">0</span>
                    </button>
                </div>
            </div>`;

        box.querySelectorAll('[data-prompt]').forEach((b) => {
            b.addEventListener('click', async () => {
                const text = b.dataset.prompt;
                await ensureConversation();
                await sendMessage(text);
            });
        });
        box.querySelector('#empty-new-chat').addEventListener('click', () => ensureConversation().then(() => {
            el('composer-input').focus();
        }));
        return box;
    }

    function conversationEmpty() {
        const box = document.createElement('div');
        box.className = 'min-h-full grid place-items-center tech-grid relative';
        box.innerHTML = `
            <div class="absolute inset-0 tech-grid-fine opacity-50 pointer-events-none"></div>
            <div class="relative text-center select-none px-6">
                <p class="font-mono text-[10px] tracking-[0.3em] uppercase text-dim mb-4">CONVERSATION #${esc(state.current.id)}</p>
                <h2 class="font-display font-bold text-3xl md:text-4xl tracking-tighter text-bone">EMPTY CHANNEL</h2>
                <p class="font-mono text-[10px] tracking-[0.32em] uppercase text-faint mt-3">Compose first signal below</p>
            </div>`;
        return box;
    }

    function sectionHeader(label, index, right) {
        const head = document.createElement('div');
        head.className = 'flex items-baseline justify-between gap-3 mb-3';
        head.innerHTML = `
            <div class="flex items-baseline gap-3">
                <span class="font-mono text-[11px] tracking-[0.24em] uppercase ${label === 'USER' ? 'text-accent' : 'text-ok'}">${esc(String(index).padStart(2, '0'))} / ${label}</span>
                <span class="font-mono text-[10px] tracking-[0.2em] text-faint uppercase">${esc(right || '')}</span>
            </div>`;
        return head;
    }

    function renderMessage(m, index) {
        const panel = document.createElement('article');
        panel.dataset.id = m.id;
        panel.className =
            'border bg-panel/70 fade-up ' +
            (m.role === 'user' ? 'border-line ml-0 md:ml-auto md:max-w-[85%]' : 'border-line mr-0');

        const isUser = m.role === 'user';
        const meta = m.metadata ?? {};
        const isEditable = !!meta.edited;
        const isRegen = !!meta.regenerated;

        const rightLabel = isRegen
            ? `REGEN · ${esc(meta.model || '')}`
            : isEditable
                ? `EDITED · ${esc(meta.model || '')}`
                : m.role === 'assistant'
                    ? esc(meta.model || '')
                    : '';

        const head = document.createElement('div');
        head.innerHTML = `<div class="flex items-center justify-between px-4 py-2.5 border-b border-line">
            <div class="flex items-baseline gap-3">
                <span class="font-mono text-[11px] tracking-[0.24em] uppercase ${isUser ? 'text-accent' : 'text-ok'}">${esc(String(index).padStart(2, '0'))} / ${isUser ? 'USER' : 'NEXORA AI'}</span>
                ${rightLabel ? `<span class="font-mono text-[10px] tracking-[0.16em] uppercase text-faint truncate">${rightLabel}</span>` : ''}
            </div>
            <span class="font-mono text-[10px] tracking-widest text-faint uppercase">${fmtTime(m.created_at)}</span>
        </div>`;
        panel.appendChild(head);

        const body = document.createElement('div');
        body.className = 'px-4 py-4';
        if (isUser) {
            const content = document.createElement('p');
            content.className = 'text-[14px] leading-relaxed text-bone/90 whitespace-pre-wrap break-words';
            content.textContent = m.content;
            body.appendChild(content);
        } else {
            body.appendChild(renderMarkdown(m.content));
        }
        panel.appendChild(body);

        const foot = document.createElement('div');
        foot.className = 'flex items-center gap-1 px-3 pb-3 pt-0';
        if (isUser) {
            foot.appendChild(actionBtn('EDIT', () => startEdit(m, panel)));
        } else {
            foot.appendChild(actionBtn('COPY', async () => {
                const ok = await copyText(m.content);
                toast(ok ? 'Copied to clipboard.' : 'Copy failed.');
            }));
            foot.appendChild(actionBtn('REGENERATE', async () => {
                await regenerate(m);
            }));
        }
        foot.appendChild(document.createElement('span')).className = 'flex-1';
        panel.appendChild(foot);

        return panel;
    }

    function actionBtn(label, onClick) {
        const b = document.createElement('button');
        b.type = 'button';
        b.textContent = label;
        b.className =
            'px-2.5 py-1 font-mono text-[10px] tracking-[0.2em] uppercase text-dim hover:text-bone hover:bg-panel2 border border-transparent hover:border-line transition-colors';
        b.addEventListener('click', onClick);
        return b;
    }

    // ---------------------------------------------------------------- edit

    function startEdit(m, panel) {
        if (state.streaming) {
            toast('Stop generation before editing.');
            return;
        }
        const body = panel.querySelector('.px-4.py-4');
        body.style.display = 'none';
        const area = document.createElement('div');
        area.className = 'space-y-2';
        const ta = document.createElement('textarea');
        ta.value = m.content;
        ta.rows = 3;
        ta.className =
            'w-full bg-ink2 border border-line2 px-3 py-2 text-sm text-bone focus:outline-none focus:border-accent/60 resize-none scrollbars';
        const btns = document.createElement('div');
        btns.className = 'flex gap-2 items-center';
        const save = actionBtn('SAVE', async () => {
            const text = ta.value.trim();
            if (!text) {
                toast('Message empty.');
                return;
            }
            const model = el('model-select').value;
            const { ok, data } = await api(`/conversations/${state.current.id}/messages/${m.id}`, {
                method: 'PUT',
                body: { content: text, model: model || undefined },
            });
            if (!ok) {
                toast(data.message || 'Edit failed.');
                return;
            }
            await openConversation(state.current.id, { keepScroll: true });
        });
        const cancel = actionBtn('CANCEL', () => {
            area.remove();
            body.style.display = '';
        });
        cancel.classList.add('text-faint');
        btns.appendChild(save);
        btns.appendChild(cancel);
        area.appendChild(ta);
        area.appendChild(btns);
        panel.appendChild(area);
        ta.focus();
        ta.setSelectionRange(ta.value.length, ta.value.length);
    }

    // ---------------------------------------------------------------- regenerate

    async function regenerate(m) {
        if (state.streaming) return;
        const { ok, data } = await api(`/conversations/${state.current.id}/messages/${m.id}/regenerate`, {
            method: 'POST',
        });
        if (!ok) {
            toast(data.message || 'Regeneration failed.');
            return;
        }
        await openConversation(state.current.id, { keepScroll: true });
    }

    // ---------------------------------------------------------------- new chat

    async function ensureConversation() {
        if (state.current) return state.current;
        const { ok, data } = await api('/conversations', { method: 'POST', body: {} });
        if (!ok) {
            toast('Failed to create conversation.');
            return null;
        }
        state.current = data.conversation;
        state.messages = [];
        await loadConversations();
        renderConversationMeta();
        renderMessages();
        return state.current;
    }

    async function createNewChat() {
        const convo = await ensureConversation();
        if (!convo) return;
        if (state.current && !state.messages.length) {
            el('composer-input').focus();
            return;
        }
        await openConversation(convo.id);
        el('composer-input').focus();
        closeDrawer();
    }

    async function deleteConversation(id) {
        if (state.streaming) {
            toast('Stop generation before deleting.');
            return;
        }
        const ok = window.confirm('Delete this conversation? Irreversible.');
        if (!ok) return;
        const { ok: res, data } = await api(`/conversations/${id}`, { method: 'DELETE' });
        if (!res) {
            toast(data.message || 'Delete failed.');
            return;
        }
        if (state.current?.id === id) {
            state.current = null;
            state.messages = [];
            renderConversationMeta();
            renderMessages();
        }
        await loadConversations();
    }

    // ---------------------------------------------------------------- send / streaming

    async function sendMessage(textOverride) {
        if (state.streaming) return;

        const convo = state.current;
        if (!convo) return;

        const text = (textOverride ?? el('composer-input').value).trim();
        if (!text) return;

        const area = el('messages-area');

        let userPanel = renderMessage({ id: null, role: 'user', content: text, created_at: new Date().toISOString(), metadata: {} }, (state.messages.length || 0) + 1);
        userPanel.dataset.tmp = '1';

        let genPanel = document.createElement('article');
        genPanel.className = 'border border-line bg-panel/70 fade-up';
        genPanel.innerHTML = `
            <div class="flex items-center justify-between px-4 py-2.5 border-b border-line">
                <div class="flex items-baseline gap-3">
                    <span class="font-mono text-[11px] tracking-[0.24em] uppercase text-ok">-- / NEXORA AI</span>
                    <span class="font-mono text-[10px] tracking-[0.16em] uppercase text-accent blink">GENERATING</span>
                </div>
                <span class="font-mono text-[10px] tracking-widest text-faint uppercase">STREAM</span>
            </div>
            <div class="px-4 pt-4 flex items-center gap-1.5 h-6">
                ${'<span class="w-1.5 h-3 bg-accent/80 seg-pulse"></span>'.repeat(14)}
            </div>
            <div class="px-4 pb-4 pt-2">
                <div class="md-host"></div>
            </div>`;

        const genBody = genPanel.querySelector('.md-host');

        let base = area.querySelector('.max-w-4xl');
        if (!base) {
            area.innerHTML = '';
            base = document.createElement('div');
            base.className = 'max-w-4xl mx-auto px-3 md:px-6 py-8 space-y-8';
            area.appendChild(base);
        }
        base.appendChild(userPanel);
        base.appendChild(genPanel);
        area.scrollTop = area.scrollHeight;

        el('composer-input').value = '';
        autosize();

        state.streaming = true;
        setComposerBusy(true);
        const model = el('model-select').value || null;

        const controller = new AbortController();
        state.abortController = controller;

        let full = '';
        let failed = false;

        try {
            const res = await fetch(`/conversations/${convo.id}/chat`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'text/event-stream', 'X-CSRF-TOKEN': csrf() },
                body: JSON.stringify({ content: text, stream: true, model }),
                signal: controller.signal,
            });

            if (!res.ok || !res.body) {
                const data = await res.json().catch(() => ({}));
                toast(data.message || 'AI request failed.');
                failed = true;
            } else {
                const reader = res.body.getReader();
                const dec = new TextDecoder();
                let buf = '';
                while (true) {
                    const { done, value } = await reader.read();
                    if (done) break;
                    buf += dec.decode(value, { stream: true });
                    let idx;
                    while ((idx = buf.indexOf('\n\n')) >= 0) {
                        const frame = buf.slice(0, idx);
                        buf = buf.slice(idx + 2);
                        for (const line of frame.split('\n')) {
                            const l = line.trim();
                            if (!l.startsWith('data:')) continue;
                            const d = l.slice(5).trim();
                            if (d === '[DONE]') continue;
                            try {
                                const j = JSON.parse(d);
                                if (typeof j.content === 'string') {
                                    full += j.content;
                                    queueStreamRender(genBody, full, area);
                                } else if (j.error) {
                                    toast(j.error);
                                    failed = true;
                                }
                            } catch (err) {
                                /* ignore malformed frame */
                            }
                        }
                    }
                }
            }
        } catch (err) {
            if (err.name === 'AbortError') {
                toast('Generation stopped.');
            } else {
                toast('AI request failed.');
            }
            failed = true;
        } finally {
            state.streaming = false;
            state.abortController = null;
            setComposerBusy(false);
            genPanel.classList.add('opacity-40');
        }

        await loadConversations();
        if (state.current && state.current.id === convo.id) {
            await openConversation(convo.id, { keepScroll: true });
        } else {
            renderConversationMeta();
        }
    }

    function stopStreaming() {
        if (state.abortController) {
            state.abortController.abort();
        }
    }

    function setComposerBusy(busy) {
        const btn = el('send-btn');
        el('send-label').textContent = busy ? 'STOP' : 'SEND';
        btn.disabled = false;
    }

    function autosize() {
        const ta = el('composer-input');
        ta.style.height = 'auto';
        ta.style.height = Math.min(ta.scrollHeight, 160) + 'px';
    }

    // ---------------------------------------------------------------- drawer / settings

    function closeDrawer() {
        el('sidebar').classList.add('-translate-x-full');
        el('sidebar').classList.remove('translate-x-0');
        el('drawer-overlay').classList.add('hidden');
    }

    function openDrawer() {
        el('sidebar').classList.remove('-translate-x-full');
        el('sidebar').classList.add('translate-x-0');
        el('drawer-overlay').classList.remove('hidden');
    }

    // ---------------------------------------------------------------- wire up

    function init() {
        const input = el('composer-input');
        input.addEventListener('input', autosize);
        input.addEventListener('keydown', (e) => {
            if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
                e.preventDefault();
                sendMessage();
            }
        });

        el('composer').addEventListener('submit', (e) => {
            e.preventDefault();
            if (state.streaming) {
                stopStreaming();
                return;
            }
            sendMessage();
        });

        el('new-chat').addEventListener('click', createNewChat);
        el('conv-search').addEventListener('input', (e) => {
            state.search = e.target.value;
            renderSidebar();
        });

        el('drawer-open').addEventListener('click', openDrawer);
        el('drawer-close').addEventListener('click', closeDrawer);
        el('drawer-overlay').addEventListener('click', closeDrawer);
        el('reload-btn').addEventListener('click', () => {
            loadConversations();
            if (state.current) openConversation(state.current.id);
            toast('Reloaded.');
        });

        el('settings-btn').addEventListener('click', () => el('settings-panel').classList.remove('hidden'));
        el('settings-close').addEventListener('click', () => el('settings-panel').classList.add('hidden'));

        // delete conversation via sidebar hover util: add tiny delete buttons
        // (attached during renderSidebar via delegation instead is simpler)
        el('conv-list').addEventListener('click', (e) => {
            const del = e.target.closest('[data-del-conv]');
            if (!del) return;
            e.stopPropagation();
            deleteConversation(Number(del.dataset.delConv));
        });

        el('composer-input').addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && !e.shiftKey && !e.ctrlKey && !e.metaKey) {
                e.preventDefault();
                sendMessage();
            }
        });

        loadConversations().then(() => {
            if (!state.current && state.conversations.length) {
                openConversation(state.conversations[0].id);
            } else if (!state.current) {
                renderMessages();
            }
        });
    }

    document.addEventListener('DOMContentLoaded', init);
})();