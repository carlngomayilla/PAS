@extends('layouts.guest')

@section('content')
<div
  class="lamp-page min-h-screen flex items-center justify-center px-4 py-24 sm:py-28"
  data-force-login-visible="{{ $errors->any() || old('email') ? '1' : '0' }}"
>
  <div class="w-full max-w-5xl grid grid-cols-1 gap-8 md:grid-cols-2 md:gap-10 items-center">

    <section class="relative select-none">
      <div class="mx-auto max-w-[27rem] text-white">
        <x-brand.logo variant="full" class="mx-auto w-full max-w-[18rem] sm:max-w-[22rem] md:max-w-[24rem] h-auto opacity-95" />
      </div>

      <div class="lamp mx-auto" id="lampScene">
        <div class="lamp-head"></div>
        <div class="lamp-neck"></div>
        <div class="lamp-base"></div>

        <div class="lamp-flash" aria-hidden="true"></div>
        <div class="lamp-glow" aria-hidden="true"></div>
        <div class="lamp-cone" aria-hidden="true"></div>

        <button type="button" class="lamp-pull" id="lampToggle" aria-label="Tirer pour allumer et se connecter">
          <span class="lamp-cord"></span>
          <span class="lamp-knob"></span>
        </button>
      </div>

      <div class="mt-6 text-center">
        <div class="text-2xl font-extrabold tracking-tight text-white/90">{{ $platformSettings->get('login_welcome_title', "Bienvenue dans l'espace ANBG") }}</div>
      </div>
    </section>

    <section class="lamp-card rounded-3xl border border-white/10 bg-white/5 p-6 shadow-2xl backdrop-blur-xl sm:p-8" id="loginCard" aria-hidden="true">
      <h1 class="text-xl font-extrabold text-white">{{ $platformSettings->get('login_form_title', 'Connexion') }}</h1>
      <p class="mt-1 text-sm text-slate-300">{{ $platformSettings->get('login_form_subtitle', 'Accede a ton espace.') }}</p>

      <form method="POST" action="{{ route('login') }}" class="mt-6 space-y-4" id="lampLoginForm">
        @csrf

        <div>
          <label class="text-xs font-semibold text-slate-300">{{ $platformSettings->get('login_identifier_label', 'Email ou matricule') }}</label>
          <input
            id="lampLoginIdentifier"
            type="text" name="email" required autofocus value="{{ old('email') }}"
            class="mt-2 w-full rounded-xl border border-white/10 bg-[linear-gradient(135deg,rgba(15,23,42,0.96)_0%,rgba(22,35,56,0.92)_100%)] px-4 py-3 text-white
                   placeholder:text-slate-400 shadow-[inset_0_1px_0_rgba(255,255,255,0.04),0_10px_24px_-24px_rgba(36,59,90,0.34)] focus:border-[#516B8B]/55 focus:outline-none focus:ring-2 focus:ring-[#516B8B]/25"
            placeholder="{{ $platformSettings->get('login_identifier_placeholder', 'ex: admin@anbg.ga ou ADM-001') }}"
          >
          @error('email') <div class="field-error">{{ $message }}</div> @enderror
        </div>

        <div>
          <label class="text-xs font-semibold text-slate-300">Mot de passe</label>
          <input
            id="lampLoginPassword"
            type="password" name="password" required
            class="mt-2 w-full rounded-xl border border-white/10 bg-[linear-gradient(135deg,rgba(15,23,42,0.96)_0%,rgba(22,35,56,0.92)_100%)] px-4 py-3 text-white
                   placeholder:text-slate-400 shadow-[inset_0_1px_0_rgba(255,255,255,0.04),0_10px_24px_-24px_rgba(36,59,90,0.34)] focus:border-[#516B8B]/55 focus:outline-none focus:ring-2 focus:ring-[#516B8B]/25"
            placeholder="********"
          >
          @error('password') <div class="field-error">{{ $message }}</div> @enderror
        </div>

        <div class="flex items-center justify-between">
          <label class="flex items-center gap-2 text-sm text-slate-300">
            <input type="checkbox" name="remember" class="h-4 w-4">
            Se souvenir
          </label>
          @if (Route::has('password.request'))
            <a class="text-sm text-[#B7C4D6] hover:text-white" href="{{ route('password.request') }}">
              Mot de passe oublie ?
            </a>
          @endif
        </div>

      </form>
    </section>

  </div>
</div>
@endsection
