@extends('layouts.guest')

@section('content')
<div class="lamp-page min-h-screen flex items-center justify-center px-4 py-24 sm:py-28">
  <div class="w-full max-w-5xl grid grid-cols-1 gap-8 md:grid-cols-2 md:gap-10 items-center">

    <section class="relative select-none">
      <div class="mx-auto max-w-[27rem] text-white">
        <x-brand.logo variant="full" class="mx-auto w-full max-w-[18rem] sm:max-w-[22rem] md:max-w-[24rem] h-auto opacity-95" />
      </div>

      <div class="lamp mx-auto" id="lampScene">
        <div class="lamp-head"></div>
        <div class="lamp-neck"></div>
        <div class="lamp-base"></div>

        <div class="lamp-glow" aria-hidden="true"></div>
        <div class="lamp-cone" aria-hidden="true"></div>

        <button type="button" class="lamp-pull" id="lampToggle" aria-label="Allumer / Eteindre">
          <span class="lamp-cord"></span>
          <span class="lamp-knob"></span>
        </button>
      </div>

      <div class="mt-6 text-center">
        <div class="text-2xl font-extrabold tracking-tight text-white/90">Bienvenue dans l'espace ANBG</div>
        <p class="mt-2 text-sm text-slate-400">Tire sur la corde puis connecte-toi a ton espace de pilotage.</p>
      </div>

      <audio id="lampClick" preload="auto">
        <source src="{{ asset('sfx/click.mp3') }}" type="audio/mpeg">
      </audio>
    </section>

    <section class="lamp-card rounded-3xl border border-white/10 bg-white/5 p-6 shadow-2xl backdrop-blur-xl sm:p-8" id="loginCard">
      <div class="mb-5 sm:mb-6">
        <x-brand.logo variant="wordmark" class="w-full max-w-[11.5rem] sm:max-w-[13.5rem] h-auto" />
      </div>
      <h1 class="text-xl font-extrabold text-white">Connexion</h1>
      <p class="mt-1 text-sm text-slate-300">Accede a ton espace.</p>

      <form method="POST" action="{{ route('login') }}" class="mt-6 space-y-4">
        @csrf

        <div>
          <label class="text-xs font-semibold text-slate-300">Email ou matricule</label>
          <input
            type="text" name="email" required autofocus value="{{ old('email') }}"
            class="mt-2 w-full rounded-xl border border-white/10 bg-[linear-gradient(135deg,rgba(10,20,46,0.96)_0%,rgba(18,35,72,0.92)_100%)] px-4 py-3 text-white
                   placeholder:text-slate-400 shadow-[inset_0_1px_0_rgba(255,255,255,0.04),0_10px_24px_-24px_rgba(57,150,211,0.42)] focus:border-[#3996d3]/55 focus:outline-none focus:ring-2 focus:ring-[#3996d3]/30"
            placeholder="ex: a1-01@anbg.test ou A1-01"
          >
          @error('email') <div class="field-error">{{ $message }}</div> @enderror
        </div>

        <div>
          <label class="text-xs font-semibold text-slate-300">Mot de passe</label>
          <input
            type="password" name="password" required
            class="mt-2 w-full rounded-xl border border-white/10 bg-[linear-gradient(135deg,rgba(10,20,46,0.96)_0%,rgba(18,35,72,0.92)_100%)] px-4 py-3 text-white
                   placeholder:text-slate-400 shadow-[inset_0_1px_0_rgba(255,255,255,0.04),0_10px_24px_-24px_rgba(57,150,211,0.42)] focus:border-[#3996d3]/55 focus:outline-none focus:ring-2 focus:ring-[#3996d3]/30"
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
            <a class="text-sm text-[#3996d3] hover:text-[#f8e932]" href="{{ route('password.request') }}">
              Mot de passe oublie ?
            </a>
          @endif
        </div>

        <button
          type="submit"
          class="w-full rounded-xl py-3 font-bold text-[#1c203d]
                 bg-gradient-to-r from-[#8fc043] via-[#f8e932] to-[#f9b13c]
                 hover:brightness-105 transition"
        >
          Se connecter
        </button>
      </form>

      <div class="mt-6 text-xs text-slate-400">
        Lamp ON = ambiance chaude + focus plus premium.
      </div>
    </section>

  </div>
</div>
@endsection
