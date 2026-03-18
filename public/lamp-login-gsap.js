(function () {
  if (window.__lampGsapInit) return;
  window.__lampGsapInit = true;

  const gsapRef = window.gsap;
  if (!gsapRef) return;

  const btn = document.getElementById('lampToggle');
  const clickAudio = document.getElementById('lampClick');
  const glow = document.querySelector('.lamp-glow');
  const cone = document.querySelector('.lamp-cone');
  const card = document.getElementById('loginCard');
  const page = document.querySelector('.lamp-page');
  const pull = document.querySelector('.lamp-pull');

  if (!btn || !glow || !cone || !card || !page || !pull) return;

  const saved = localStorage.getItem('lamp') || 'off';
  let isOn = saved === 'on';

  gsapRef.set(glow, { opacity: isOn ? 1 : 0, scale: isOn ? 1.02 : 0.98 });
  gsapRef.set(cone, { opacity: isOn ? 1 : 0, y: isOn ? 0 : -8 });
  gsapRef.set(card, {
    y: isOn ? -4 : 0,
    boxShadow: isOn ? '0 30px 90px rgba(0,0,0,.45)' : '0 18px 60px rgba(0,0,0,.35)',
    borderColor: isOn ? 'rgba(255,214,102,.22)' : 'rgba(255,255,255,.10)',
  });

  function animateBackground(on) {
    gsapRef.to(page, {
      duration: 0.6,
      ease: 'power3.out',
      filter: on ? 'saturate(1.08) contrast(1.06)' : 'saturate(1) contrast(1)',
    });
  }
  animateBackground(isOn);

  function playClick() {
    if (!clickAudio) return;
    try {
      clickAudio.currentTime = 0;
      clickAudio.volume = 0.7;
      clickAudio.play();
    } catch (_) {}
  }

  function toggleLamp() {
    isOn = !isOn;
    localStorage.setItem('lamp', isOn ? 'on' : 'off');

    const tl = gsapRef.timeline();
    tl.to(pull, { y: 8, duration: 0.12, ease: 'power2.out' })
      .to(pull, { y: 0, duration: 0.22, ease: 'power2.out' });

    playClick();
    animateBackground(isOn);

    gsapRef.to(glow, {
      duration: 0.5,
      ease: 'power3.out',
      opacity: isOn ? 1 : 0,
      scale: isOn ? 1.03 : 0.98,
    });

    gsapRef.to(cone, {
      duration: 0.55,
      ease: 'power3.out',
      opacity: isOn ? 1 : 0,
      y: isOn ? 0 : -10,
    });

    gsapRef.to(card, {
      duration: 0.55,
      ease: 'power3.out',
      y: isOn ? -4 : 0,
      borderColor: isOn ? 'rgba(255,214,102,.22)' : 'rgba(255,255,255,.10)',
      boxShadow: isOn ? '0 30px 90px rgba(0,0,0,.45)' : '0 18px 60px rgba(0,0,0,.35)',
    });
  }

  btn.addEventListener('click', toggleLamp);
})();
