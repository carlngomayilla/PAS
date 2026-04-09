import { gsap } from 'gsap';

(function () {
  if (typeof window !== 'undefined' && window.__lampGsapInit) return;
  if (typeof window !== 'undefined') window.__lampGsapInit = true;

  const btn = document.getElementById('lampToggle');
  const clickAudio = document.getElementById('lampClick');
  const scene = document.getElementById('lampScene');
  const flash = document.querySelector('.lamp-flash');
  const glow = document.querySelector('.lamp-glow');
  const cone = document.querySelector('.lamp-cone');
  const card = document.getElementById('loginCard');
  const page = document.querySelector('.lamp-page');
  const pull = document.querySelector('.lamp-pull');
  const form = document.getElementById('lampLoginForm');
  const identifierInput = document.getElementById('lampLoginIdentifier');
  const passwordInput = document.getElementById('lampLoginPassword');

  if (!btn || !scene || !flash || !glow || !cone || !card || !page || !pull || !form || !identifierInput || !passwordInput) {
    return;
  }

  const cord = pull.querySelector('.lamp-cord');
  const knob = pull.querySelector('.lamp-knob');
  const cardRevealTargets = card.querySelectorAll('h1, p, form > *, .mt-6');
  let pointerX = window.innerWidth / 2;
  let pointerY = window.innerHeight / 2;
  let rafId = null;
  let isSubmitting = false;

  const forceVisible = page.dataset.forceLoginVisible === '1';
  let isOn = forceVisible;

  function syncPageState(on) {
    page.classList.toggle('lamp-is-on', on);
    card.setAttribute('aria-hidden', on ? 'false' : 'true');
  }

  function syncPullState() {
    if (isSubmitting) {
      btn.setAttribute('aria-label', 'Connexion en cours');
      return;
    }

    btn.setAttribute('aria-label', isOn ? 'Tirer pour se connecter' : 'Tirer pour allumer et se connecter');
  }

  syncPageState(isOn);
  syncPullState();

  gsap.set(scene, {
    transformOrigin: '50% 18%',
    rotate: isOn ? 0 : -1.4,
  });
  gsap.set(flash, {
    autoAlpha: 0,
    scale: 0.82,
  });
  gsap.set(glow, { opacity: isOn ? 1 : 0, scale: isOn ? 1.02 : 0.98 });
  gsap.set(cone, { opacity: isOn ? 1 : 0, y: isOn ? 0 : -8 });
  gsap.set(card, {
    autoAlpha: isOn ? 1 : 0,
    x: isOn ? 0 : 44,
    scale: isOn ? 1 : 0.96,
    y: isOn ? -4 : 18,
    boxShadow: isOn ? '0 30px 90px rgba(0,0,0,.45)' : '0 18px 60px rgba(0,0,0,.35)',
    borderColor: isOn ? 'rgba(255,214,102,.22)' : 'rgba(255,255,255,.10)',
  });
  gsap.set(cardRevealTargets, {
    autoAlpha: isOn ? 1 : 0,
    y: isOn ? 0 : 18,
  });
  if (cord) {
    gsap.set(cord, { transformOrigin: 'top center', scaleY: isOn ? 1 : 0.96 });
  }
  if (knob) {
    gsap.set(knob, { scale: 1, boxShadow: '0 10px 30px rgba(0, 0, 0, .4)' });
  }

  function animateBackground(on) {
    gsap.to(page, {
      duration: 0.6,
      ease: 'power3.out',
      filter: on ? 'saturate(1.08) contrast(1.06)' : 'saturate(1) contrast(1)',
    });
  }

  animateBackground(isOn);

  function updatePointerGlow() {
    rafId = null;
    if (!isOn) return;

    const rect = scene.getBoundingClientRect();
    const relativeX = Math.min(Math.max(pointerX - rect.left, 0), rect.width || 1);
    const relativeY = Math.min(Math.max(pointerY - rect.top, 0), rect.height || 1);
    const percentX = rect.width ? (relativeX / rect.width) * 100 : 50;
    const percentY = rect.height ? (relativeY / rect.height) * 100 : 32;

    gsap.to(glow, {
      duration: 0.35,
      ease: 'power2.out',
      background: `radial-gradient(circle at ${percentX}% ${Math.max(0, percentY - 20)}%, rgba(255, 225, 150, .76), rgba(255, 212, 108, .22) 40%, rgba(255, 212, 108, 0) 70%)`,
      overwrite: 'auto',
    });

    gsap.to(card, {
      duration: 0.35,
      ease: 'power2.out',
      boxShadow: `${Math.round((percentX - 50) * 0.22)}px 34px 96px rgba(0,0,0,.46), 0 0 0 1px rgba(255,214,102,.08)`,
      overwrite: 'auto',
    });
  }

  function queuePointerGlow(event) {
    pointerX = event.clientX;
    pointerY = event.clientY;

    if (rafId !== null) return;
    rafId = window.requestAnimationFrame(updatePointerGlow);
  }

  function centerPointerGlow() {
    const rect = scene.getBoundingClientRect();
    pointerX = rect.left + (rect.width / 2);
    pointerY = rect.top + (rect.height * 0.46);

    if (rafId !== null) {
      window.cancelAnimationFrame(rafId);
      rafId = null;
    }

    rafId = window.requestAnimationFrame(updatePointerGlow);
  }

  function playClick() {
    if (!clickAudio) return;
    try {
      clickAudio.currentTime = 0;
      clickAudio.volume = 0.7;
      clickAudio.play();
    } catch (_) {}
  }

  function playPullSound(turningOn) {
    const AudioContextClass = window.AudioContext || window.webkitAudioContext;
    if (!AudioContextClass) return;

    try {
      const audioContext = new AudioContextClass();
      const now = audioContext.currentTime;

      const createTone = (type, frequency, start, duration, gainFrom, gainTo) => {
        const oscillator = audioContext.createOscillator();
        const gainNode = audioContext.createGain();

        oscillator.type = type;
        oscillator.frequency.setValueAtTime(frequency, start);
        gainNode.gain.setValueAtTime(gainFrom, start);
        gainNode.gain.exponentialRampToValueAtTime(gainTo, start + duration);

        oscillator.connect(gainNode);
        gainNode.connect(audioContext.destination);
        oscillator.start(start);
        oscillator.stop(start + duration);
      };

      createTone('triangle', turningOn ? 680 : 260, now, 0.08, 0.0001, 0.028);
      createTone('sine', turningOn ? 940 : 180, now + 0.02, 0.12, 0.02, 0.0001);

      window.setTimeout(() => {
        if (audioContext.state !== 'closed') {
          audioContext.close().catch(() => {});
        }
      }, 260);
    } catch (_) {}
  }

  function animatePull() {
    const tl = gsap.timeline();
    tl.to(scene, { rotate: 3.6, duration: 0.14, ease: 'power2.out' }, 0)
      .to(pull, { y: 18, duration: 0.14, ease: 'power2.out' }, 0)
      .to(cord, { scaleY: 1.16, duration: 0.14, ease: 'power2.out' }, 0)
      .to(knob, { scale: 1.14, duration: 0.14, ease: 'power2.out' }, 0)
      .to(scene, { rotate: -2.4, duration: 0.16, ease: 'power2.out' })
      .to(pull, { y: -6, duration: 0.16, ease: 'power2.out' }, '<')
      .to(cord, { scaleY: 0.98, duration: 0.16, ease: 'power2.out' }, '<')
      .to(knob, { scale: 0.98, duration: 0.16, ease: 'power2.out' }, '<')
      .to(scene, { rotate: 1.1, duration: 0.24, ease: 'elastic.out(1, 0.45)' })
      .to(pull, { y: 0, duration: 0.24, ease: 'elastic.out(1, 0.45)' }, '<')
      .to(cord, { scaleY: 1, duration: 0.24, ease: 'elastic.out(1, 0.45)' }, '<')
      .to(knob, {
        scale: 1,
        duration: 0.24,
        ease: 'elastic.out(1, 0.45)',
        boxShadow: '0 12px 34px rgba(255, 214, 102, .28)',
      }, '<')
      .to(scene, { rotate: 0.35, duration: 0.32, ease: 'power2.out' });
  }

  function activateLamp() {
    isOn = true;
    syncPageState(true);
    syncPullState();
    animatePull();
    playClick();
    playPullSound(true);
    animateBackground(true);

    gsap.fromTo(flash, {
      autoAlpha: 0,
      scale: 0.8,
    }, {
      duration: 0.22,
      ease: 'power2.out',
      autoAlpha: 0.95,
      scale: 1.18,
      onComplete: () => {
        gsap.to(flash, {
          duration: 0.28,
          ease: 'power2.in',
          autoAlpha: 0,
          scale: 1.3,
        });
      },
    });

    gsap.fromTo([glow, cone], {
      opacity: 0.72,
    }, {
      duration: 0.16,
      opacity: 1,
      repeat: 3,
      yoyo: true,
      ease: 'power1.inOut',
      overwrite: 'auto',
    });

    gsap.to(glow, {
      duration: 0.5,
      ease: 'power3.out',
      opacity: 1,
      scale: 1.03,
      background: 'radial-gradient(circle at 50% 0%, rgba(255, 236, 170, .88), rgba(255, 220, 130, .30) 38%, rgba(255, 220, 130, 0) 70%)',
    });

    gsap.to(glow, {
      duration: 0.9,
      delay: 0.42,
      ease: 'power2.out',
      background: 'radial-gradient(circle at 50% 0%, rgba(255, 221, 145, .78), rgba(255, 212, 108, .24) 40%, rgba(255, 212, 108, 0) 70%)',
      overwrite: 'auto',
    });

    gsap.to(cone, {
      duration: 0.55,
      ease: 'power3.out',
      opacity: 1,
      y: 0,
      background: 'conic-gradient(from 180deg at 50% 0%, rgba(255, 214, 102, 0) 0deg, rgba(255, 231, 166, .22) 35deg, rgba(255, 236, 182, .44) 55deg, rgba(255, 225, 144, .24) 75deg, rgba(255, 214, 102, 0) 110deg)',
    });

    gsap.to(cone, {
      duration: 0.9,
      delay: 0.44,
      ease: 'power2.out',
      background: 'conic-gradient(from 180deg at 50% 0%, rgba(255, 214, 102, 0) 0deg, rgba(255, 219, 126, .18) 35deg, rgba(255, 215, 116, .34) 55deg, rgba(255, 216, 118, .18) 75deg, rgba(255, 214, 102, 0) 110deg)',
      overwrite: 'auto',
    });

    gsap.to(card, {
      duration: 0.7,
      ease: 'power3.out',
      autoAlpha: 1,
      x: 0,
      scale: 1,
      y: -4,
      borderColor: 'rgba(255,214,102,.22)',
      boxShadow: '0 30px 90px rgba(0,0,0,.45)',
    });

    gsap.to(cardRevealTargets, {
      duration: 0.52,
      ease: 'power3.out',
      autoAlpha: 1,
      y: 0,
      stagger: 0.055,
      delay: 0.12,
      overwrite: 'auto',
    });

    centerPointerGlow();
  }

  function loginReady() {
    return identifierInput.value.trim() !== '' && passwordInput.value.trim() !== '';
  }

  function focusFirstMissingField() {
    const target = identifierInput.value.trim() === ''
      ? identifierInput
      : passwordInput.value.trim() === ''
        ? passwordInput
        : null;

    if (!target) return;

    target.focus();
    if (typeof target.select === 'function') {
      target.select();
    }
  }

  function submitThroughCord() {
    if (isSubmitting) return;

    isSubmitting = true;
    btn.disabled = true;
    pull.classList.add('lamp-pull-submitting');
    syncPullState();

    window.setTimeout(() => {
      if (typeof form.requestSubmit === 'function') {
        form.requestSubmit();
        return;
      }

      form.submit();
    }, 420);
  }

  function handlePull(event) {
    event.preventDefault();

    if (isSubmitting) return;

    activateLamp();

    if (loginReady()) {
      submitThroughCord();
      return;
    }

    focusFirstMissingField();
  }

  form.addEventListener('submit', () => {
    isSubmitting = true;
    btn.disabled = true;
    pull.classList.add('lamp-pull-submitting');
    syncPullState();
  });

  btn.addEventListener('click', handlePull);
  scene.addEventListener('pointermove', queuePointerGlow);
  scene.addEventListener('pointerenter', queuePointerGlow);
})();
