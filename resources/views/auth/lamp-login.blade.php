@extends('layouts.guest')

@section('content')
<div class="lamp-page min-h-screen flex items-center justify-center px-4">
  <div class="w-full max-w-5xl grid grid-cols-1 md:grid-cols-2 gap-10 items-center">

    <section class="relative select-none">
      <div class="mx-auto max-w-[30rem] text-white">
        <x-brand.logo variant="full" class="mx-auto w-full max-w-[28rem] opacity-95" />
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

    <section class="lamp-card rounded-3xl border border-white/10 bg-white/5 backdrop-blur-xl shadow-2xl p-8" id="loginCard">
      <div class="mb-6">
        <x-brand.logo variant="wordmark" class="w-full max-w-[16rem] text-white/95" />
      </div>
      <h1 class="text-xl font-extrabold text-white">Connexion</h1>
      <p class="mt-1 text-sm text-slate-300">Accede a ton espace.</p>

      <form method="POST" action="{{ route('login') }}" class="mt-6 space-y-4">
        @csrf

        <div>
          <label class="text-xs font-semibold text-slate-300">Email ou matricule</label>
          <input
            type="text" name="email" required autofocus value="{{ old('email') }}"
            class="mt-2 w-full rounded-xl bg-black/25 border border-white/10 px-4 py-3 text-white
                   placeholder:text-slate-500 focus:outline-none focus:ring-2 focus:ring-sky-400/60"
            placeholder="ex: a1-01@anbg.test ou A1-01"
          >
          @error('email') <div class="mt-1 text-xs text-red-400">{{ $message }}</div> @enderror
        </div>

        <div>
          <label class="text-xs font-semibold text-slate-300">Mot de passe</label>
          <input
            type="password" name="password" required
            class="mt-2 w-full rounded-xl bg-black/25 border border-white/10 px-4 py-3 text-white
                   placeholder:text-slate-500 focus:outline-none focus:ring-2 focus:ring-sky-400/60"
            placeholder="********"
          >
          @error('password') <div class="mt-1 text-xs text-red-400">{{ $message }}</div> @enderror
        </div>

        <div class="flex items-center justify-between">
          <label class="flex items-center gap-2 text-sm text-slate-300">
            <input type="checkbox" name="remember" class="rounded border-white/20 bg-black/30">
            Se souvenir
          </label>
          @if (Route::has('password.request'))
            <a class="text-sm text-sky-300 hover:text-lime-200" href="{{ route('password.request') }}">
              Mot de passe oublie ?
            </a>
          @endif
        </div>

        <button
          type="submit"
          class="w-full rounded-xl py-3 font-bold text-slate-950
                 bg-gradient-to-r from-[#C7E54B] via-[#75BC43] to-[#34B8FF]
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
