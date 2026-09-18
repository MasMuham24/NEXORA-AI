<x-layouts.app title="WORKSPACE">
    <div class="h-screen flex flex-col overflow-hidden bg-ink text-bone">

        {{-- Mobile top bar --}}
        <header class="lg:hidden flex items-center justify-between h-12 px-4 border-b border-line/80 bg-ink2 relative z-30">
            <div class="flex items-center gap-3">
                <button id="drawer-open" class="font-mono text-dim hover:text-bone text-sm px-1 py-1" aria-label="Open sidebar">≡</button>
                <span class="font-display font-bold tracking-tighter text-bone">NEXORA<span class="text-accent">_</span></span>
            </div>
            <div class="flex items-center gap-2 font-mono text-[10px] tracking-widest text-dim uppercase">
                <span class="w-1.5 h-1.5 rounded-full bg-ok seg-pulse"></span>
                <span class="hidden sm:inline">ONLINE</span>
            </div>
        </header>

        <div class="flex-1 flex min-h-0">

            {{-- Sidebar / command panel --}}
            <aside id="sidebar"
                class="fixed lg:static inset-y-0 left-0 z-40 w-72 lg:w-80 shrink-0 border-r border-line/80 bg-ink2 flex flex-col -translate-x-full lg:translate-x-0 transition-transform duration-200 lg:transition-none">
                <div class="flex items-center justify-between h-12 px-4 border-b border-line/80">
                    <span class="font-mono text-[10px] tracking-[0.28em] uppercase text-dim">Workspace · 01</span>
                    <button id="drawer-close" class="lg:hidden font-mono text-dim hover:text-bone text-sm" aria-label="Close sidebar">✕</button>
                </div>

                <div class="p-3 border-b border-line/80">
                    <button id="new-chat"
                        class="w-full flex items-center justify-between bg-accent hover:bg-accenthi text-ink font-semibold text-xs tracking-[0.18em] uppercase px-3.5 py-2.5 transition-colors">
                        <span>+ New Chat</span>
                        <span class="font-mono">01</span>
                    </button>
                </div>

                <div class="p-3 border-b border-line/80">
                    <input id="conv-search" type="text" placeholder="SEARCH CONVERSATIONS"
                        class="w-full bg-ink border border-line2 px-3 py-2 font-mono text-[11px] tracking-widest uppercase text-bone placeholder:text-faint focus:outline-none focus:border-accent/60 transition-colors">
                </div>

                <div id="conv-list" class="flex-1 overflow-y-auto scrollbars px-2 py-3 space-y-4"></div>

                <div class="border-t border-line/80">
                    <div class="flex items-center justify-between pt-3 pb-1 px-4 font-mono text-[10px] tracking-[0.25em] uppercase text-faint">
                        <span>Profile</span>
                        <span class="text-dim">SSN/00</span>
                    </div>
                    <div class="px-4 py-3 flex items-center gap-3">
                        <div class="w-8 h-8 shrink-0 border border-line2 grid place-items-center font-mono text-xs text-accent">
                            {{ strtoupper(substr(auth()->user()->name, 0, 1)) }}
                        </div>
                        <div class="min-w-0">
                            <p class="text-sm text-bone truncate">{{ auth()->user()->name }}</p>
                            <p class="font-mono text-[10px] text-dim truncate">{{ auth()->user()->email }}</p>
                        </div>
                    </div>
                    <div class="flex border-t border-line/80">
                        <button id="settings-btn" class="flex-1 py-2.5 font-mono text-[10px] tracking-[0.22em] uppercase text-dim hover:text-bone hover:bg-panel transition-colors text-center">
                            Settings
                        </button>
                        <form method="POST" action="{{ url('/logout') }}" class="flex-1">
                            @csrf
                            <button type="submit" class="w-full py-2.5 font-mono text-[10px] tracking-[0.22em] uppercase text-dim hover:text-warn transition-colors text-center">
                                Logout
                            </button>
                        </form>
                    </div>
                </div>
            </aside>

            {{-- Overlay for mobile drawer --}}
            <div id="drawer-overlay" class="fixed inset-0 z-30 bg-black/50 lg:hidden hidden"></div>

            {{-- Main column --}}
            <main class="flex-1 flex flex-col min-w-0">

                {{-- Status bar --}}
                <div class="hidden lg:flex items-center justify-between h-10 px-5 border-b border-line/80 bg-ink2/60">
                    <div class="font-mono text-[10px] tracking-[0.22em] uppercase text-dim flex items-center gap-3">
                        <span class="text-accent" id="status-conv">NO CONVERSATION</span>
                        <span class="text-faint">//</span>
                        <span id="status-title" class="text-dim truncate max-w-[320px]">STANDBY</span>
                    </div>
                    <div class="flex items-center gap-5 font-mono text-[10px] tracking-[0.22em] uppercase text-dim">
                        <span class="flex items-center gap-1.5"><span class="w-1.5 h-1.5 rounded-full bg-ok seg-pulse"></span>SYSTEM ONLINE</span>
                        <span>ENGINE:<span class="text-bone ml-1">{{ strtoupper(config('ai.provider', 'pateway')) }}</span></span>
                        <span>MODEL:<span class="text-bone ml-1" id="status-model">{{ strtoupper(config('ai.providers.pateway.model', '—')) }}</span></span>
                        <button id="reload-btn" class="text-dim hover:text-bone transition-colors">RELOAD</button>
                    </div>
                </div>

                {{-- Messages area --}}
                <div id="messages-area" class="flex-1 overflow-y-auto scrollbars relative"></div>

                {{-- Composer --}}
                <div class="border-t border-line/80 bg-ink2/80 px-3 pt-3 pb-3 md:px-5 relative z-10">
                    <div class="max-w-4xl mx-auto">
                        <form id="composer" class="border border-line2 bg-ink focus-within:border-accent/60 transition-colors">
                            <textarea id="composer-input" rows="1" placeholder="MESSAGE NEXORA_AI..."
                                class="w-full bg-transparent px-4 pt-3.5 pb-1 text-sm text-bone placeholder:text-faint focus:outline-none resize-none scrollbars"></textarea>
                            <div class="flex items-center justify-between px-3 pb-2 pt-1">
                                <div class="flex items-center gap-2">
                                    <select id="model-select" class="bg-panel2 border border-line2 px-2 py-1.5 font-mono text-[10px] tracking-wider uppercase text-mute focus:outline-none focus:border-accent/60 transition-colors max-w-[180px]">
                                        <option value="" selected>DEFAULT</option>
                                        <option value="claude-haiku-4-5-20251001">CLAUDE-HAIKU-4.5</option>
                                        <option value="claude-sonnet-4-6">CLAUDE-SONNET-4.6</option>
                                        <option value="claude-opus-4-7">CLAUDE-OPUS-4.7</option>
                                        <option value="gpt-5.5">GPT-5.5</option>
                                        <option value="deepseek-v4-pro">DEEPSEEK-V4-PRO</option>
                                        <option value="glm-5.2">GLM-5.2</option>
                                    </select>
                                    <span class="hidden sm:inline font-mono text-[9px] tracking-[0.2em] text-faint uppercase">Ctrl+Enter send</span>
                                </div>
                                <button id="send-btn" type="submit"
                                    class="flex items-center gap-2 bg-accent hover:bg-accenthi disabled:opacity-40 disabled:cursor-not-allowed text-ink font-semibold text-xs tracking-[0.18em] uppercase px-4 py-2 transition-colors">
                                    <span id="send-label">Send</span>
                                </button>
                            </div>
                        </form>
                        <p id="toast" class="hidden mt-2 font-mono text-[10px] tracking-widest text-warn uppercase text-center"></p>
                    </div>
                </div>
            </main>
        </div>

        {{-- Settings panel --}}
        <div id="settings-panel" class="hidden fixed inset-0 z-50 grid place-items-center bg-black/60" role="dialog" aria-modal="true">
            <div class="w-[340px] border border-line bg-panel shadow-2xl">
                <div class="flex items-center justify-between px-4 h-9 border-b border-line font-mono text-[10px] tracking-[0.25em] uppercase text-dim">
                    <span>System Settings</span>
                    <button id="settings-close" class="text-dim hover:text-bone">✕</button>
                </div>
                <div class="p-4 space-y-3 font-mono text-[11px] tracking-wide text-mute">
                    <div class="flex justify-between"><span class="text-dim uppercase text-[10px]">System</span><span class="text-ok">ONLINE</span></div>
                    <div class="flex justify-between"><span class="text-dim uppercase text-[10px]">Engine</span><span class="text-bone uppercase">{{ strtoupper(config('ai.provider', 'pateway')) }}</span></div>
                    <div class="flex justify-between"><span class="text-dim uppercase text-[10px]">Model</span><span class="text-bone uppercase">{{ strtoupper(config('ai.providers.pateway.model', '—')) }}</span></div>
                    <div class="flex justify-between"><span class="text-dim uppercase text-[10px]">Operator</span><span class="text-bone">{{ auth()->user()->email }}</span></div>
                    <div class="flex justify-between"><span class="text-dim uppercase text-[10px]">Version</span><span class="text-bone">NEXORA-AI v1.0</span></div>
                    <p class="pt-2 border-t border-line text-[10px] text-faint leading-relaxed">
                        Creative × Industrial workspace. Streaming enabled. Model selectable per request.
                    </p>
                </div>
            </div>
        </div>
    </div>

    <script>
        window.NEXORA = {
            defaultModel: @json(config('ai.providers.pateway.model')),
            provider: @json(config('ai.provider', 'pateway')),
            user: {
                name: @json(auth()->user()->name),
                email: @json(auth()->user()->email),
            },
        };
    </script>
</x-layouts.app>