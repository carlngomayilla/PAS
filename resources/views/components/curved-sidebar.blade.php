@php
    $user = auth()->user();
    $isGlobalReader = $user?->hasGlobalReadAccess() ?? false;

    // Main navigation sections
    $mainItems = [
        [
            'label' => 'Dashboard',
            'route' => 'dashboard',
            'icon' => 'grid',
            'active' => request()->routeIs('dashboard'),
        ],
    ];

    // Planning section
    $planningItems = [
        [
            'label' => 'PAS',
            'route' => 'workspace.pas.index',
            'icon' => 'target',
            'active' => request()->routeIs('workspace.pas.*'),
        ],
        [
            'label' => 'PAO',
            'route' => 'workspace.pao.index',
            'icon' => 'folder',
            'active' => request()->routeIs('workspace.pao.*'),
        ],
        [
            'label' => 'PTA',
            'route' => 'workspace.pta.index',
            'icon' => 'calendar',
            'active' => request()->routeIs('workspace.pta.*'),
        ],
    ];

    // Execution & Monitoring section
    $executionItems = [
        [
            'label' => 'Execution',
            'route' => 'workspace.actions.index',
            'icon' => 'bolt',
            'active' => request()->routeIs('workspace.actions.*'),
        ],
        [
            'label' => 'Pilotage',
            'route' => 'workspace.pilotage',
            'icon' => 'pulse',
            'active' => request()->routeIs('workspace.pilotage', 'workspace.reporting', 'workspace.alertes'),
        ],
    ];

    // Reference section (conditional)
    $referenceItems = [];
    if ($isGlobalReader) {
        $referenceItems[] = [
            'label' => 'Referentiel',
            'route' => 'workspace.referentiel.directions.index',
            'icon' => 'cog',
            'active' => request()->routeIs('workspace.referentiel.*', 'workspace.audit.*'),
        ];
    }

    $svgs = [
        'grid' => '<svg viewBox="0 0 24 24" class="h-5 w-5 fill-current"><path d="M4 13h7V4H4v9zm9 7h7V11h-7v9zM4 20h7v-5H4v5zm9-9h7V4h-7v7z"/></svg>',
        'home' => '<svg viewBox="0 0 24 24" class="h-5 w-5 fill-current"><path d="M12 3 3 10v11h7v-7h4v7h7V10l-9-7zm0 2.5 6.5 5V19H16v-7H8v7H5.5v-8.5L12 5.5z"/></svg>',
        'user' => '<svg viewBox="0 0 24 24" class="h-5 w-5 fill-current"><path d="M12 12a5 5 0 1 0-5-5 5 5 0 0 0 5 5zm0 2c-4.42 0-8 2.24-8 5v2h16v-2c0-2.76-3.58-5-8-5z"/></svg>',
        'target' => '<svg viewBox="0 0 24 24" class="h-5 w-5 fill-current"><path d="M12 2a10 10 0 1 0 10 10h-2a8 8 0 1 1-8-8V2zm0 4a6 6 0 1 0 6 6h-2a4 4 0 1 1-4-4V6zm0 3 6-6v4h4l-6 6V9h-4z"/></svg>',
        'folder' => '<svg viewBox="0 0 24 24" class="h-5 w-5 fill-current"><path d="M10 4H4v16h16V8h-8l-2-4zm2.5 6H6v2h6.5v-2zM6 14h12v-2H6v2z"/></svg>',
        'calendar' => '<svg viewBox="0 0 24 24" class="h-5 w-5 fill-current"><path d="M7 2h2v2h6V2h2v2h3v18H4V4h3V2zm11 8H6v10h12V10z"/></svg>',
        'bolt' => '<svg viewBox="0 0 24 24" class="h-5 w-5 fill-current"><path d="M13 2 4 14h6l-1 8 9-12h-6l1-8z"/></svg>',
        'chart' => '<svg viewBox="0 0 24 24" class="h-5 w-5 fill-current"><path d="M5 9h3v10H5V9zm5-4h3v14h-3V5zm5 7h3v7h-3v-7z"/></svg>',
        'doc' => '<svg viewBox="0 0 24 24" class="h-5 w-5 fill-current"><path d="M6 2h9l5 5v15H6V2zm8 1.5V8h4.5L14 3.5zM8 12h8v-2H8v2zm0 4h8v-2H8v2z"/></svg>',
        'pulse' => '<svg viewBox="0 0 24 24" class="h-5 w-5 fill-current"><path d="M3 13h4l2-4 4 8 2-4h6v-2h-4.8l-3.2 6.4-4-8-2 4H3v2z"/></svg>',
        'cog' => '<svg viewBox="0 0 24 24" class="h-5 w-5 fill-current"><path d="M19.14 12.94c.04-.31.06-.63.06-.94s-.02-.63-.06-.94l2.03-1.58a.5.5 0 0 0 .12-.64l-1.92-3.32a.5.5 0 0 0-.6-.22l-2.39.96a7.02 7.02 0 0 0-1.63-.94l-.36-2.54A.5.5 0 0 0 13.9 1h-3.8a.5.5 0 0 0-.49.42l-.36 2.54c-.58.23-1.12.54-1.63.94l-2.39-.96a.5.5 0 0 0-.6.22L2.71 7.48a.5.5 0 0 0 .12.64l2.03 1.58c-.04.31-.06.63-.06.94s.02.63.06.94L2.83 14.52a.5.5 0 0 0-.12.64l1.92 3.32c.13.22.39.31.6.22l2.39-.96c.5.4 1.05.71 1.63.94l.36 2.54c.05.24.25.42.49.42h3.8c.24 0 .45-.18.49-.42l.36-2.54c.58-.23 1.12-.54 1.63-.94l2.39.96c.22.09.47 0 .6-.22l1.92-3.32a.5.5 0 0 0-.12-.64l-2.03-1.58zM12 15.5A3.5 3.5 0 1 1 12 8a3.5 3.5 0 0 1 0 7.5z"/></svg>',
    ];

@endphp

<aside class="w-full md:sticky md:top-0 md:h-screen md:w-[280px] md:min-w-[280px]">
  <div class="sidebar-brand-surface flex h-full flex-col text-slate-100">
    <!-- Header -->
    <div class="border-b border-slate-800/50 px-4 py-4">
      <div class="flex items-center">
        <div class="min-w-0 flex-1">
          <x-brand.logo variant="wordmark" class="block h-auto w-full max-w-[8.5rem]" />
          <div class="mt-1 truncate text-[11px] text-slate-400">Pilotage et execution</div>
        </div>
      </div>
    </div>

    <!-- Navigation -->
    <nav class="flex-1 overflow-y-auto px-3 py-4" aria-label="Navigation laterale">
      <!-- Main Section -->
      <div class="mb-6">
        @foreach ($mainItems as $item)
          <a
            href="{{ route($item['route']) }}"
            class="flex items-center gap-3 rounded-lg px-4 py-3 text-sm font-medium transition-all duration-200 {{ $item['active'] ? 'bg-[linear-gradient(135deg,#8fc043_0%,#f0e509_24%,#f9b13c_52%,#3996d3_78%,#1c203d_100%)] text-white shadow-lg shadow-[#3996d3]/35' : 'text-slate-200 hover:text-white hover:bg-white/8' }}"
          >
            <span class="opacity-90">{!! $svgs[$item['icon']] ?? $svgs['grid'] !!}</span>
            <span>{{ $item['label'] }}</span>
          </a>
        @endforeach
      </div>

      <!-- Planning Section -->
      <div class="mb-6">
        <div class="mb-2 px-4 text-xs font-semibold uppercase tracking-wider text-slate-500">Planification</div>
        @foreach ($planningItems as $item)
          <a
            href="{{ route($item['route']) }}"
            class="flex items-center gap-3 rounded-lg px-4 py-3 text-sm font-medium transition-all duration-200 {{ $item['active'] ? 'bg-[linear-gradient(135deg,#8fc043_0%,#f0e509_24%,#f9b13c_52%,#3996d3_78%,#1c203d_100%)] text-white shadow-lg shadow-[#3996d3]/35' : 'text-slate-200 hover:text-white hover:bg-white/8' }}"
          >
            <span class="opacity-90">{!! $svgs[$item['icon']] ?? $svgs['grid'] !!}</span>
            <span>{{ $item['label'] }}</span>
          </a>
        @endforeach
      </div>

      <!-- Execution & Monitoring Section -->
      <div class="mb-6">
        <div class="mb-2 px-4 text-xs font-semibold uppercase tracking-wider text-slate-500">Execution & Pilotage</div>
        @foreach ($executionItems as $item)
          <a
            href="{{ route($item['route']) }}"
            class="flex items-center gap-3 rounded-lg px-4 py-3 text-sm font-medium transition-all duration-200 {{ $item['active'] ? 'bg-[linear-gradient(135deg,#8fc043_0%,#f0e509_24%,#f9b13c_52%,#3996d3_78%,#1c203d_100%)] text-white shadow-lg shadow-[#3996d3]/35' : 'text-slate-200 hover:text-white hover:bg-white/8' }}"
          >
            <span class="opacity-90">{!! $svgs[$item['icon']] ?? $svgs['grid'] !!}</span>
            <span>{{ $item['label'] }}</span>
          </a>
        @endforeach
      </div>

      <!-- Reference Section (conditional) -->
      @if ($isGlobalReader && count($referenceItems) > 0)
        <div class="mb-6">
          <div class="mb-2 px-4 text-xs font-semibold uppercase tracking-wider text-slate-500">Administration</div>
          @foreach ($referenceItems as $item)
            <a
              href="{{ route($item['route']) }}"
              class="flex items-center gap-3 rounded-lg px-4 py-3 text-sm font-medium transition-all duration-200 {{ $item['active'] ? 'bg-[linear-gradient(135deg,#8fc043_0%,#f0e509_24%,#f9b13c_52%,#3996d3_78%,#1c203d_100%)] text-white shadow-lg shadow-[#3996d3]/35' : 'text-slate-200 hover:text-white hover:bg-white/8' }}"
            >
              <span class="opacity-90">{!! $svgs[$item['icon']] ?? $svgs['grid'] !!}</span>
              <span>{{ $item['label'] }}</span>
            </a>
          @endforeach
        </div>
      @endif

    </nav>

    <!-- Footer -->
    <div class="border-t border-slate-800/50 px-4 py-4">
      <form method="POST" action="{{ route('logout') }}">
        @csrf
        <button type="submit" class="w-full inline-flex items-center justify-center rounded-lg px-3 py-3 text-sm font-medium bg-[linear-gradient(135deg,rgba(249,177,60,0.34)_0%,rgba(240,229,9,0.26)_100%)] text-[#f8e932] hover:brightness-105 transition-colors">
          Deconnexion
        </button>
      </form>
    </div>
  </div>
</aside>
