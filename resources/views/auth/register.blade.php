<x-layouts.app title="REGISTER">
    <div class="min-h-screen flex flex-col tech-grid relative">
        <div class="pointer-events-none absolute inset-0 tech-grid-fine opacity-40"></div>

        <header class="relative z-10 flex items-center justify-between px-5 md:px-8 h-14 border-b border-line/70">
            <div class="flex items-center gap-3 font-mono text-[11px] tracking-[0.22em] text-dim uppercase">
                <span class="text-bone">NEXORA</span>
                <span class="text-faint">/</span>
                <span>AI WORKSPACE</span>
                <span class="text-faint">/</span>
                <span class="text-accent">REGISTRATION</span>
            </div>
            <div class="flex items-center gap-2 font-mono text-[11px] tracking-widest text-dim uppercase">
                <span class="w-1.5 h-1.5 rounded-full bg-ok shadow-[0_0_8px_0_rgba(143,212,92,0.8)] seg-pulse"></span>
                <span>SYSTEM ONLINE</span>
            </div>
        </header>

        <div class="relative z-10 flex-1 grid lg:grid-cols-[1fr_1.15fr] grid-rows-1">

            {{-- Form side --}}
            <div class="flex items-center justify-center px-5 py-10 md:py-14 order-2 lg:order-1 lg:border-r border-line/70">
                <div class="w-full max-w-[400px]">
                    <div class="mb-10 lg:hidden">
                        <h1 class="font-display font-bold text-5xl tracking-tighter text-bone">
                            NEXORA<span class="text-accent">_</span>
                        </h1>
                        <p class="font-mono text-[11px] tracking-[0.3em] text-dim uppercase mt-2">AI WORKSPACE · v1</p>
                    </div>

                    <div class="border border-line bg-panel/90 backdrop-blur-sm">
                        <div class="flex items-center justify-between px-4 h-9 border-b border-line font-mono text-[10px] tracking-[0.25em] uppercase text-dim">
                            <span>Register</span>
                            <span class="text-faint">SSN/02</span>
                        </div>

                        <form id="register-form" class="p-5 md:p-6 space-y-5">
                            <div class="space-y-1.5">
                                <label for="name" class="block font-mono text-[10px] tracking-[0.25em] uppercase text-dim">
                                    Operator Name
                                </label>
                                <input id="name" name="name" type="text" autocomplete="name" required
                                    class="w-full bg-ink2 border border-line2 px-3.5 py-2.5 text-sm text-bone placeholder:text-faint focus:outline-none focus:border-accent/70 focus:ring-1 focus:ring-accent/40 transition-colors">
                            </div>

                            <div class="space-y-1.5">
                                <label for="email" class="block font-mono text-[10px] tracking-[0.25em] uppercase text-dim">
                                    Email Address
                                </label>
                                <input id="email" name="email" type="email" autocomplete="email" required
                                    class="w-full bg-ink2 border border-line2 px-3.5 py-2.5 text-sm text-bone placeholder:text-faint focus:outline-none focus:border-accent/70 focus:ring-1 focus:ring-accent/40 transition-colors">
                            </div>

                            <div class="space-y-1.5">
                                <label for="password" class="block font-mono text-[10px] tracking-[0.25em] uppercase text-dim">
                                    Password
                                </label>
                                <input id="password" name="password" type="password" autocomplete="new-password" required
                                    class="w-full bg-ink2 border border-line2 px-3.5 py-2.5 text-sm text-bone placeholder:text-faint focus:outline-none focus:border-accent/70 focus:ring-1 focus:ring-accent/40 transition-colors">
                            </div>

                            <div class="space-y-1.5">
                                <label for="password_confirmation" class="block font-mono text-[10px] tracking-[0.25em] uppercase text-dim">
                                    Confirm Password
                                </label>
                                <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required
                                    class="w-full bg-ink2 border border-line2 px-3.5 py-2.5 text-sm text-bone placeholder:text-faint focus:outline-none focus:border-accent/70 focus:ring-1 focus:ring-accent/40 transition-colors">
                            </div>

                            <p id="form-error" class="hidden font-mono text-[11px] text-warn leading-relaxed"></p>

                            <button type="submit"
                                class="w-full bg-accent hover:bg-accenthi text-ink font-semibold text-sm tracking-wide py-2.5 uppercase transition-colors disabled:opacity-50">
                                Create Operator
                            </button>

                            <p class="font-mono text-[11px] tracking-widest text-dim text-center pt-1">
                                HAVE AN ACCOUNT? <a href="{{ route('login') }}" class="text-bone underline underline-offset-4 hover:text-accent transition-colors">LOGIN</a>
                            </p>
                        </form>
                    </div>

                    <p class="mt-6 font-mono text-[10px] tracking-[0.2em] uppercase text-faint text-center">
                        Identity Created Locally · No Third Party
                    </p>
                </div>
            </div>

            {{-- Editorial side --}}
            <div class="hidden lg:flex flex-col justify-between p-8 md:p-10 select-none order-1 lg:order-2">
                <div class="flex-1 flex flex-col justify-center">
                    <p class="font-mono text-[11px] tracking-[0.3em] text-dim uppercase mb-10">Workspace Enrollment · v1</p>
                    <h1 class="font-display font-bold leading-none text-[clamp(4.5rem,12vw,11rem)] tracking-tighter text-bone">
                        CREATE<span class="text-accent">.</span>
                    </h1>
                    <h2 class="font-mono text-[clamp(0.85rem,1.6vw,1.25rem)] tracking-[0.42em] text-mute uppercase mt-8">
                        Your Intelligent Workspace
                    </h2>
                    <div class="mt-16 max-w-md">
                        <p class="text-mute text-sm leading-relaxed">
                            Claim your workspace. Conversations persist, model selection stays yours, and every
                            response renders live under your control.
                        </p>
                    </div>
                </div>

                <div class="flex items-end justify-between font-mono text-[10px] tracking-[0.25em] uppercase text-faint">
                    <div>
                        <p>01 / IDENTITY</p>
                        <p class="text-dim mt-1">NAME · EMAIL · CREDENTIAL</p>
                    </div>
                    <div class="text-right">
                        <p>02 / STATUS</p>
                        <p class="text-dim mt-1">AWAITING OPERATOR</p>
                    </div>
                </div>
            </div>
        </div>

        <footer class="relative z-10 h-9 border-t border-line/70 flex items-center justify-between px-5 md:px-8 font-mono text-[10px] tracking-[0.22em] uppercase overflow-hidden text-faint">
            <span>NEXORA-AI v1.0</span>
            <span class="hidden md:inline">FILE: /access/register</span>
            <span>{{ now()->format('Y-m-d H:i') }}</span>
        </footer>
    </div>

    <script>
        const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content;
        document.getElementById('register-form').addEventListener('submit', async (e) => {
            e.preventDefault();
            const btn = e.target.querySelector('button[type=submit]');
            const err = document.getElementById('form-error');
            err.classList.add('hidden');
            btn.disabled = true;
            try {
                const res = await fetch('/register', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf() },
                    body: JSON.stringify({
                        name: document.getElementById('name').value,
                        email: document.getElementById('email').value,
                        password: document.getElementById('password').value,
                        password_confirmation: document.getElementById('password_confirmation').value,
                    }),
                });
                const data = await res.json().catch(() => ({}));
                if (res.ok) { window.location.href = '/'; return; }
                const msg = data.errors
                    ? Object.values(data.errors).flat().join(' · ')
                    : (data.message ?? 'Registration failed.');
                err.textContent = msg;
                err.classList.remove('hidden');
            } catch (ex) {
                err.textContent = 'Connection failed. Retry.';
                err.classList.remove('hidden');
            } finally {
                btn.disabled = false;
            }
        });
    </script>
</x-layouts.app>