<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>ViLoCare | @yield('title', 'Dashboard')</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('images/favicon-32.png') }}">
    <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('images/favicon-16.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('images/apple-touch-icon.png') }}">
    <link rel="preload" href="{{ asset('fonts/manrope/manrope-latin.woff2') }}" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="{{ asset('css/fonts.css') }}">
    <link rel="stylesheet" href="{{ asset('css/vilocare.css') }}?v={{ file_exists(public_path('css/vilocare.css')) ? filemtime(public_path('css/vilocare.css')) : '1.1' }}">
    <link rel="stylesheet" href="{{ asset('css/theme.css') }}?v={{ file_exists(public_path('css/theme.css')) ? filemtime(public_path('css/theme.css')) : '1.1' }}">
    @stack('styles')
    <link rel="stylesheet" href="{{ asset('css/responsive.css') }}?v={{ file_exists(public_path('css/responsive.css')) ? filemtime(public_path('css/responsive.css')) : '1.0' }}">
</head>
<body>
<div class="app-shell">
    <!-- Main Left Sidebar -->
    <aside class="app-sidebar" id="app-sidebar">
        <div class="sidebar-head">
            <div class="sidebar-logo-wrap">
                <div class="sidebar-logo">
                    <img src="{{ asset('images/vilocarelogo.png') }}" alt="ViLoCare" />
                </div>
                <div class="sidebar-brand">
                    <p class="sidebar-brand-sub">HIV Viral Load Platform</p>
                </div>
            </div>
        </div>

        <nav class="sidebar-menu">
            <div class="sidebar-section">Overview</div>
            
            <a href="{{ route('dashboard') }}" class="sidebar-link {{ request()->routeIs('dashboard') ? 'is-active' : '' }}">
                <span class="sidebar-icon">DB</span>
                <span>Dashboard</span>
            </a>

            <a href="{{ route('profile.show') }}" class="sidebar-link {{ request()->routeIs('profile.*') ? 'is-active' : '' }}">
                <span class="sidebar-icon">PR</span>
                <span>My Profile</span>
            </a>

            <div class="sidebar-section">Clinical</div>

            @if(auth()->user()->hasRole(\App\Enums\UserRole::ADMINISTRATOR, \App\Enums\UserRole::CLINICIAN, \App\Enums\UserRole::DATA_CLERK))
            <a href="{{ route('patients.index') }}" class="sidebar-link {{ request()->routeIs('patients.*') ? 'is-active' : '' }}">
                <span class="sidebar-icon">PT</span>
                <span>Patients</span>
            </a>
            @endif

            @if(auth()->user()->hasRole(\App\Enums\UserRole::ADMINISTRATOR, \App\Enums\UserRole::CLINICIAN, \App\Enums\UserRole::LAB_TECHNICIAN))
            <a href="{{ route('viral-load.index') }}" class="sidebar-link {{ request()->routeIs('viral-load.*') ? 'is-active' : '' }}">
                <span class="sidebar-icon">VL</span>
                <span>Viral Load</span>
            </a>
            @endif

            @if(auth()->user()->hasRole(\App\Enums\UserRole::ADMINISTRATOR, \App\Enums\UserRole::CLINICIAN, \App\Enums\UserRole::LAB_TECHNICIAN, \App\Enums\UserRole::DATA_CLERK))
            <a href="{{ route('samples.index') }}" class="sidebar-link {{ request()->routeIs('samples.*') ? 'is-active' : '' }}">
                <span class="sidebar-icon">SM</span>
                <span>Samples</span>
            </a>
            @endif

            @if(auth()->user()->hasRole(\App\Enums\UserRole::ADMINISTRATOR, \App\Enums\UserRole::CLINICIAN))
            <a href="{{ route('eac.index') }}" class="sidebar-link {{ request()->routeIs('eac.*') ? 'is-active' : '' }}">
                <span class="sidebar-icon">EA</span>
                <span>EAC Sessions</span>
            </a>
            @endif

            @if(auth()->user()->hasRole(\App\Enums\UserRole::ADMINISTRATOR, \App\Enums\UserRole::CLINICIAN, \App\Enums\UserRole::DATA_CLERK))
            <a href="{{ route('appointments.index') }}" class="sidebar-link {{ request()->routeIs('appointments.*') ? 'is-active' : '' }}">
                <span class="sidebar-icon">AM</span>
                <span>Appointments</span>
            </a>

            <a href="{{ route('notifications.index') }}" class="sidebar-link {{ request()->routeIs('notifications.*') ? 'is-active' : '' }}">
                <span class="sidebar-icon">NT</span>
                <span>SMS Notifications</span>
            </a>
            @endif

            <div class="sidebar-section">Insights & Admin</div>

            <a href="{{ route('reports.index') }}" class="sidebar-link {{ request()->routeIs('reports.*') ? 'is-active' : '' }}">
                <span class="sidebar-icon">RP</span>
                <span>Reports & Analytics</span>
            </a>

            @if(auth()->user()->hasRole(\App\Enums\UserRole::ADMINISTRATOR, \App\Enums\UserRole::CLINICIAN))
            <a href="{{ route('insights.qa.index') }}" class="sidebar-link {{ request()->routeIs('insights.*') ? 'is-active' : '' }}">
                <span class="sidebar-icon">AI</span>
                <span>AI Clinical Insights</span>
            </a>
            @endif

            @if(auth()->user()->hasRole(\App\Enums\UserRole::ADMINISTRATOR, \App\Enums\UserRole::CLINICIAN))
            <a href="{{ route('users.index') }}" class="sidebar-link {{ request()->routeIs('users.*') ? 'is-active' : '' }}">
                <span class="sidebar-icon">US</span>
                <span>Staff Management</span>
            </a>
            @endif

            @if(auth()->user()->isAdmin())
            <a href="{{ route('audit-logs.index') }}" class="sidebar-link {{ request()->routeIs('audit-logs.*') ? 'is-active' : '' }}">
                <span class="sidebar-icon">AL</span>
                <span>Audit Logs</span>
            </a>
            @endif

            @if(auth()->user()->isAdmin())
            <a href="{{ route('settings.sync.edit') }}" class="sidebar-link {{ request()->routeIs('settings.sync.*') ? 'is-active' : '' }}">
                <span class="sidebar-icon">SC</span>
                <span>Sync Connection</span>
            </a>
            <a href="{{ route('settings.integrations.edit') }}" class="sidebar-link {{ request()->routeIs('settings.integrations.*') ? 'is-active' : '' }}">
                <span class="sidebar-icon">IN</span>
                <span>Integrations</span>
            </a>
            @endif

            <a href="{{ route('sync.index') }}" class="sidebar-link {{ request()->routeIs('sync.*') ? 'is-active' : '' }}">
                <span class="sidebar-icon">SY</span>
                <span>Sync Center</span>
            </a>
        </nav>

        <div class="sidebar-footer">
            <div style="display: flex; align-items: center; gap: 0.65rem;">
                <div style="width: 32px; height: 32px; border-radius: 50%; background: rgba(255, 255, 255, 0.2); display: grid; place-items: center; font-weight: 800; font-size: 0.78rem; flex-shrink: 0; overflow: hidden;">
                    @if(auth()->user()->photo_url)
                        <img src="{{ auth()->user()->photo_url }}" alt="{{ auth()->user()->name }}" style="width: 100%; height: 100%; object-fit: cover;">
                    @else
                        {{ strtoupper(substr(auth()->user()->name, 0, 2)) }}
                    @endif
                </div>
                <div style="min-width: 0; flex: 1;">
                    <div style="font-weight: 700; color: #ffffff; text-overflow: ellipsis; overflow: hidden; white-space: nowrap;">{{ auth()->user()->name }}</div>
                    <div style="font-size: 0.7rem; color: rgba(235, 255, 252, 0.75);">{{ auth()->user()->role->label() }}</div>
                </div>
            </div>

            {{-- Phones/tablets: the top bar hides the profile chip and logout, so keep them reachable here --}}
            <form action="{{ route('logout') }}" method="POST" class="drawer-only drawer-logout">
                @csrf
                <button type="submit" class="btn-logout">Logout</button>
            </form>
        </div>
    </aside>

    <!-- Mobile Sidebar Backdrop -->
    <div class="sidebar-backdrop" id="sidebar-backdrop" onclick="toggleSidebar(false)"></div>

    <!-- Main Content Shell -->
    <div class="app-main">
        <header class="app-topbar">
            <div class="topbar-left">
                <button type="button" class="sidebar-toggle" id="sidebar-toggle" onclick="toggleSidebar()" aria-label="Toggle Menu" aria-controls="app-sidebar" aria-expanded="false">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
                </button>
                <h1 class="topbar-title">@yield('title', 'Dashboard')</h1>
            </div>

            <div class="topbar-user">
                @php($syncSummary = app(\App\Services\SyncService::class)->statusSummary())
                <a href="{{ route('sync.index') }}" class="sync-indicator sync-{{ $syncSummary['state'] }}" id="global-sync-status" title="{{ $syncSummary['title'] }}" style="font-size: 0.78rem; display: flex; align-items: center; gap: 5px; text-decoration: none;">
                    <span class="sync-dot"></span>
                    <span id="sync-status-text" style="color: #475569; font-weight: 600;">{{ $syncSummary['label'] }}</span>
                </a>

                <a href="{{ route('profile.show') }}" class="user-chip">
                    <span class="user-dot"></span>
                    <span>{{ auth()->user()->name }}</span>
                </a>

                <form action="{{ route('logout') }}" method="POST" style="display: inline;">
                    @csrf
                    <button type="submit" class="btn-logout">
                        Logout
                    </button>
                </form>
            </div>
        </header>

        <main class="app-content">
            @include('partials.app-update')

            @if(session('success'))
                <div class="alert alert-success">
                    <div>{{ session('success') }}</div>
                </div>
            @endif

            @if(session('warning'))
                <div class="alert alert-warning" style="padding: 0.75rem 1rem; margin-bottom: 1rem; border-radius: 12px; border: 1px solid #fde68a; background: #fffbeb; color: #92400e;">
                    <div>{{ session('warning') }}</div>
                </div>
            @endif

            @if(session('error'))
                <div class="alert alert-danger">
                    <div>{{ session('error') }}</div>
                </div>
            @endif

            @if($errors->any())
                <div class="alert alert-danger">
                    <div>
                        <strong>Please resolve the following errors:</strong>
                        <ul style="margin-top: 4px; margin-left: 18px;">
                            @foreach($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            @endif

            @yield('content')
        </main>
    </div>
</div>

<script>
    function toggleSidebar(open) {
        if (typeof open === 'boolean') {
            document.body.classList.toggle('sidebar-open', open);
        } else {
            document.body.classList.toggle('sidebar-open');
        }
        const toggleBtn = document.getElementById('sidebar-toggle');
        if (toggleBtn) {
            toggleBtn.setAttribute('aria-expanded', document.body.classList.contains('sidebar-open') ? 'true' : 'false');
        }
    }

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && document.body.classList.contains('sidebar-open')) {
            toggleSidebar(false);
        }
    });

    // Leaving the mobile breakpoint (e.g. rotating a tablet) must not leave the drawer state stuck open
    window.addEventListener('resize', function() {
        if (window.innerWidth > 991 && document.body.classList.contains('sidebar-open')) {
            toggleSidebar(false);
        }
    });

    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('.sidebar-link').forEach(function(item) {
            item.addEventListener('click', function() {
                if (window.innerWidth <= 991) {
                    toggleSidebar(false);
                }
            });
        });

        // Instant native date confirmation helper
        const monthNames = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        function updateDatePreview(input) {
            let container = input.closest('.filter-field') || input.closest('.form-group') || input.parentElement;
            if (!container) return;
            let hint = container.querySelector('.date-preview-hint');
            const val = input.value;
            if (!val) {
                if (hint) hint.remove();
                return;
            }
            const parts = val.split('-');
            if (parts.length === 3) {
                const y = parseInt(parts[0], 10);
                const m = parseInt(parts[1], 10);
                const d = parseInt(parts[2], 10);
                if (!isNaN(y) && !isNaN(m) && !isNaN(d) && m >= 1 && m <= 12) {
                    if (!hint) {
                        hint = document.createElement('div');
                        hint.className = 'date-preview-hint';
                        hint.style.cssText = 'font-size: 0.65rem; color: #0d9488; font-weight: 700; margin-top: 2px;';
                        input.insertAdjacentElement('afterend', hint);
                    }
                    hint.innerHTML = `Selected: ${d} ${monthNames[m - 1]} ${y}`;
                    return;
                }
            }
            if (hint) hint.remove();
        }

        document.querySelectorAll('input[type="date"]').forEach(function(input) {
            updateDatePreview(input);
            input.addEventListener('input', function() { updateDatePreview(this); });
            input.addEventListener('change', function() { updateDatePreview(this); });
        });
    });
</script>
<script src="{{ asset('js/vilocare-sync.js') }}"></script>
@stack('scripts')
</body>
</html>
