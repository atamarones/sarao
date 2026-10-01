// El Sarao Pub · interacciones del sitio.
// Todo lo animado falla hacia visible: los estados ocultos solo existen si este archivo y GSAP cargan.
(() => {
  'use strict';

  const $ = (s, el = document) => el.querySelector(s);
  const $$ = (s, el = document) => [...el.querySelectorAll(s)];
  const root = document.documentElement;
  const reduce = matchMedia('(prefers-reduced-motion: reduce)').matches;
  const desktop = matchMedia('(min-width: 980px) and (pointer: fine)');
  const hasGsap = typeof window.gsap !== 'undefined' && typeof window.ScrollTrigger !== 'undefined';
  const motion = hasGsap && !reduce;

  // ---------- Desplazamiento suave + revelados ----------
  let lenis = null;
  if (motion) {
    gsap.registerPlugin(ScrollTrigger);
    root.classList.add('js-motion');
    if (desktop.matches && typeof window.Lenis === 'function') {
      lenis = new Lenis({ lerp: 0.1 });
      lenis.on('scroll', ScrollTrigger.update);
      gsap.ticker.add((t) => lenis.raf(t * 1000));
      gsap.ticker.lagSmoothing(0);
    }
    ScrollTrigger.batch('[data-reveal]', {
      start: 'top 88%',
      once: true,
      onEnter: (els) => gsap.to(els, { opacity: 1, y: 0, duration: 1.1, ease: 'expo.out', stagger: 0.09, overwrite: true }),
    });
    // Red de seguridad: si algo queda fuera del rango de los disparadores, se muestra igual.
    window.addEventListener('load', () => setTimeout(() => ScrollTrigger.refresh(), 300));
  }

  // Enlaces internos con desplazamiento suave
  $$('a[href^="#"]').forEach((a) => a.addEventListener('click', (e) => {
    const id = a.getAttribute('href');
    const target = id.length > 1 && document.querySelector(id);
    if (!target) return;
    e.preventDefault();
    closeMenu();
    if (lenis) lenis.scrollTo(target, { offset: -90, duration: 1.4 });
    else target.scrollIntoView({ behavior: reduce ? 'auto' : 'smooth' });
  }));

  // ---------- Navegación ----------
  const nav = $('[data-nav]');
  const dock = $('.dock');
  const hero = $('.hero');
  let lastY = 0;
  const onScroll = () => {
    const y = window.scrollY;
    nav.classList.toggle('is-scrolled', y > 40);
    nav.classList.toggle('is-hidden', y > 600 && y > lastY && !menuOpen());
    dock?.classList.toggle('is-visible', y > hero.offsetHeight * 0.6);
    lastY = y;
  };
  window.addEventListener('scroll', onScroll, { passive: true });
  onScroll();

  const burger = $('[data-burger]');
  const menu = $('[data-menu]');
  const menuOpen = () => burger.getAttribute('aria-expanded') === 'true';
  function closeMenu() {
    if (!menuOpen()) return;
    burger.setAttribute('aria-expanded', 'false');
    menu.hidden = true;
    document.body.style.overflow = '';
    lenis?.start();
  }
  burger.addEventListener('click', () => {
    if (menuOpen()) return closeMenu();
    burger.setAttribute('aria-expanded', 'true');
    menu.hidden = false;
    document.body.style.overflow = 'hidden';
    lenis?.stop();
    if (motion) gsap.from($$('a', menu), { y: 40, opacity: 0, duration: 0.7, ease: 'expo.out', stagger: 0.05 });
  });
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && menuOpen()) { closeMenu(); burger.focus(); } });

  // ---------- Hero: entrada, neón y diapositivas ----------
  const neon = $('.hero-title em');
  if (motion) {
    const lines = $$('.hero-title .line');
    gsap.set(lines, { yPercent: 105, opacity: 0 });
    gsap.timeline({ delay: 0.15 })
      .to(lines, { yPercent: 0, opacity: 1, duration: 1.2, ease: 'expo.out', stagger: 0.12 })
      .add(() => neon?.classList.add('flicker'), '-=0.6')
      .from($$('[data-hero-in]:not(.hero-title)'), { y: 30, opacity: 0, duration: 1, ease: 'expo.out', stagger: 0.1 }, '-=0.9');
    // Paralaje suave del hero al salir
    gsap.to('.hero-slides', { yPercent: 12, ease: 'none', scrollTrigger: { trigger: '.hero', start: 'top top', end: 'bottom top', scrub: true } });
  }

  const slides = $$('.hero-slide');
  const num = $('[data-slide-num]');
  const bar = $('[data-slide-bar]');
  const SLIDE_MS = 6500;
  let slide = 0;
  let slideTimer = null;
  const showSlide = (i) => {
    slides[slide].classList.remove('is-active');
    slide = (i + slides.length) % slides.length;
    slides[slide].classList.add('is-active');
    if (num) num.textContent = String(slide + 1).padStart(2, '0');
    if (bar && !reduce) {
      bar.style.transition = 'none';
      bar.style.transform = 'scaleY(0)';
      requestAnimationFrame(() => requestAnimationFrame(() => {
        bar.style.transition = `transform ${SLIDE_MS}ms linear`;
        bar.style.transform = 'scaleY(1)';
      }));
    }
  };
  const startSlides = () => {
    if (slides.length < 2 || reduce) return;
    clearInterval(slideTimer);
    showSlide(slide);
    slideTimer = setInterval(() => showSlide(slide + 1), SLIDE_MS);
  };
  document.addEventListener('visibilitychange', () => (document.hidden ? clearInterval(slideTimer) : startSlides()));
  startSlides();

  // ---------- Letra del karaoke con la bolita ----------
  const lyric = $('[data-lyric]');
  if (lyric) {
    const lineEl = $('.lyric-line', lyric);
    const ball = $('.lyric-ball', lyric);
    const lines = JSON.parse(lineEl.dataset.lines || '[]');
    let li = 0;
    const render = (text) => {
      lineEl.replaceChildren(...text.split(/(\s+)/).filter(Boolean).map((t) => {
        if (/^\s+$/.test(t)) return document.createTextNode(t);
        const s = document.createElement('span');
        s.className = 'w';
        s.textContent = t;
        return s;
      }));
      return $$('.w', lineEl);
    };
    const sing = () => {
      const words = render(lines[li]);
      if (reduce) { words.forEach((w) => w.classList.add('is-sung')); return; }
      ball.style.opacity = '1';
      let i = 0;
      const step = () => {
        if (i >= words.length) {
          ball.style.opacity = '0';
          setTimeout(() => { li = (li + 1) % lines.length; sing(); }, 1900);
          return;
        }
        const w = words[i];
        const x = w.offsetLeft + w.offsetWidth / 2 - 6;
        const y = w.offsetTop - 14;
        ball.animate([
          { transform: ball.style.transform || `translate(${x}px, ${y}px)` },
          { transform: `translate(${x}px, ${y - 22}px)`, offset: 0.45 },
          { transform: `translate(${x}px, ${y}px)` },
        ], { duration: 340, easing: 'ease-in-out', fill: 'forwards' });
        ball.style.transform = `translate(${x}px, ${y}px)`;
        setTimeout(() => w.classList.add('is-sung'), 170);
        i++;
        setTimeout(step, 300 + Math.min(260, w.textContent.length * 26));
      };
      setTimeout(step, 500);
    };
    if (lines.length) setTimeout(sing, motion ? 1600 : 0);
  }

  // ---------- Rocola: ¿qué cantas hoy? ----------
  const SONGS = {
    'Despecho': [['Amor eterno', 'Rocío Dúrcal'], ['El Rey', 'Vicente Fernández'], ['Hasta que te conocí', 'Juan Gabriel'], ['La media vuelta', 'Luis Miguel'], ['Ahora te puedes marchar', 'Luis Miguel'], ['Tu falta de querer', 'Mon Laferte'], ['Si nos dejan', 'José Alfredo Jiménez']],
    'Rock en español': [['De música ligera', 'Soda Stereo'], ['Lamento boliviano', 'Enanitos Verdes'], ['Rayando el sol', 'Maná'], ['Clavado en un bar', 'Maná'], ['La flaca', 'Jarabe de Palo'], ['Matador', 'Los Fabulosos Cadillacs'], ['Tren al sur', 'Los Prisioneros'], ['Florecita rockera', 'Aterciopelados'], ['Afuera', 'Caifanes']],
    'Salsa': [['Pedro Navaja', 'Rubén Blades'], ['Cali pachanguero', 'Grupo Niche'], ['Vivir mi vida', 'Marc Anthony'], ['Rebelión', 'Joe Arroyo'], ['Idilio', 'Willie Colón'], ['Aguanile', 'Héctor Lavoe y Willie Colón'], ['Valió la pena', 'Marc Anthony']],
    'Vallenato y tropical': [['La gota fría', 'Carlos Vives'], ['Fruta fresca', 'Carlos Vives'], ['La bicicleta', 'Carlos Vives y Shakira'], ['Robarte un beso', 'Carlos Vives y Sebastián Yatra'], ['Los caminos de la vida', 'Los Diablitos'], ['El santo cachón', 'Los Embajadores Vallenatos']],
    'Merengue': [['Suavemente', 'Elvis Crespo'], ['La bilirrubina', 'Juan Luis Guerra'], ['Burbujas de amor', 'Juan Luis Guerra'], ['El costo de la vida', 'Juan Luis Guerra']],
    'Pop latino': [['Ojos así', 'Shakira'], ['La tortura', 'Shakira y Alejandro Sanz'], ['Corazón espinado', 'Santana y Maná'], ['La camisa negra', 'Juanes'], ['A Dios le pido', 'Juanes'], ['Mientes', 'Camila'], ['Me gustas tú', 'Manu Chao'], ['Bonito', 'Jarabe de Palo']],
    'Urbano': [['Gasolina', 'Daddy Yankee'], ['Despacito', 'Luis Fonsi y Daddy Yankee'], ['Tusa', 'Karol G y Nicki Minaj'], ['Provenza', 'Karol G'], ['Felices los 4', 'Maluma'], ['Mi gente', 'J Balvin y Willy William'], ['Bichota', 'Karol G'], ['Hawái', 'Maluma']],
    'En inglés': [['Bohemian Rhapsody', 'Queen'], ["Don't Stop Believin'", 'Journey'], ["Livin' on a Prayer", 'Bon Jovi'], ['I Will Survive', 'Gloria Gaynor'], ["Sweet Child O' Mine", "Guns N' Roses"], ['Total Eclipse of the Heart', 'Bonnie Tyler'], ['Dancing Queen', 'ABBA'], ['Mr. Brightside', 'The Killers'], ['Shallow', 'Lady Gaga y Bradley Cooper']],
  };
  const genreBox = $('[data-genres]');
  const strip = $('[data-strip]');
  const reel = $('[data-reel]');
  const spinBtn = $('[data-spin]');
  if (genreBox && strip) {
    let genre = 'Todos';
    const names = ['Todos', ...Object.keys(SONGS)];
    genreBox.replaceChildren(...names.map((n) => {
      const b = document.createElement('button');
      b.type = 'button';
      b.className = 'chip';
      b.textContent = n;
      b.setAttribute('aria-pressed', String(n === genre));
      b.addEventListener('click', () => {
        genre = n;
        $$('.chip', genreBox).forEach((c) => c.setAttribute('aria-pressed', String(c === b)));
      });
      return b;
    }));
    const pool = () => (genre === 'Todos' ? Object.values(SONGS).flat() : SONGS[genre]);
    const item = ([song, artist]) => {
      const d = document.createElement('div');
      d.className = 'reel-item';
      const a = document.createElement('p'); a.className = 'reel-song'; a.textContent = song;
      const b = document.createElement('p'); b.className = 'reel-artist'; b.textContent = artist;
      d.append(a, b);
      return d;
    };
    let spinning = false;
    let last = null;
    spinBtn.addEventListener('click', () => {
      if (spinning) return;
      const list = pool();
      let pick;
      do { pick = list[Math.floor(Math.random() * list.length)]; } while (list.length > 1 && pick === last);
      last = pick;
      const filler = Array.from({ length: reduce ? 0 : 16 }, () => list[Math.floor(Math.random() * list.length)]);
      const current = strip.firstElementChild;
      strip.replaceChildren(current, ...filler.map(item), item(pick));
      reel.classList.remove('is-landed');
      reel.setAttribute('aria-busy', 'true');
      spinning = true;
      spinBtn.disabled = true;
      const h = strip.firstElementChild.offsetHeight;
      const distance = h * (strip.children.length - 1);
      const done = () => {
        strip.replaceChildren(strip.lastElementChild);
        strip.style.transform = '';
        reel.classList.add('is-landed');
        reel.setAttribute('aria-busy', 'false');
        spinning = false;
        spinBtn.disabled = false;
      };
      if (reduce) return done();
      const anim = strip.animate([{ transform: 'translateY(0)' }, { transform: `translateY(-${distance}px)` }], { duration: 2600, easing: 'cubic-bezier(.12,.8,.18,1)', fill: 'forwards' });
      anim.onfinish = () => { anim.cancel(); done(); };
    });
  }

  // ---------- Prueba tu voz: medidor tipo aplausómetro ----------
  const canvas = $('[data-viz]');
  if (canvas) {
    const ctx = canvas.getContext('2d');
    const micBtn = $('[data-mic]');
    const micLabel = $('[data-mic-label]');
    const scoreEl = $('[data-score]');
    const label = $('[data-voice-label]');
    const BARS = 40;
    let stream = null;
    let audioCtx = null;
    let analyser = null;
    let data = null;
    let raf = 0;
    let visible = false;
    let peak = 0;
    let shown = 0;
    let t = 0;
    const messages = [
      [0, 'Canta, grita o aplaude: medimos tu potencia como un aplausómetro.'],
      [8, '¿Eso fue un susurro? Dale con ganas.'],
      [30, 'Calentando motores…'],
      [55, '¡Eso! Ya suenas a coro de estadio.'],
      [75, '¡Aplausómetro al rojo vivo!'],
      [92, '¡Ovación de pie! El escenario te espera.'],
    ];
    const grad = () => {
      const g = ctx.createLinearGradient(0, canvas.height, 0, 0);
      g.addColorStop(0, '#FF2D3D');
      g.addColorStop(0.6, '#FF7A1A');
      g.addColorStop(1, '#F8B800');
      return g;
    };
    const fill = grad();
    const draw = () => {
      const W = canvas.width;
      const H = canvas.height;
      ctx.clearRect(0, 0, W, H);
      const gap = 6;
      const bw = (W - gap * (BARS - 1)) / BARS;
      let level = 0;
      if (analyser) {
        analyser.getByteFrequencyData(data);
        let sum = 0;
        for (let i = 0; i < data.length; i++) sum += data[i];
        level = Math.min(1, (sum / data.length) / 110);
      }
      t += 0.04;
      for (let i = 0; i < BARS; i++) {
        let v;
        if (analyser) {
          const idx = Math.floor(Math.pow(i / BARS, 1.6) * (data.length * 0.7));
          v = data[idx] / 255;
        } else {
          v = 0.12 + 0.1 * Math.sin(t * 2 + i * 0.45) + 0.06 * Math.sin(t * 3.3 + i * 1.3);
        }
        const bh = Math.max(4, v * (H - 10));
        ctx.fillStyle = fill;
        ctx.globalAlpha = analyser ? 1 : 0.45;
        ctx.beginPath();
        ctx.roundRect(i * (bw + gap), H - bh, bw, bh, 4);
        ctx.fill();
      }
      ctx.globalAlpha = 1;
      if (analyser) {
        const score = Math.round(level * 100);
        peak = Math.max(peak * 0.995, score);
        shown += (peak - shown) * 0.15;
        scoreEl.textContent = String(Math.round(shown));
        const msg = [...messages].reverse().find(([min]) => shown >= min);
        if (msg && label.textContent !== msg[1]) label.textContent = msg[1];
      }
      if (visible || analyser) raf = requestAnimationFrame(draw);
    };
    const start = () => { cancelAnimationFrame(raf); raf = requestAnimationFrame(draw); };
    new IntersectionObserver(([en]) => {
      visible = en.isIntersecting;
      if (visible && !reduce) start();
    }).observe(canvas);
    if (reduce) draw();

    const stop = () => {
      stream?.getTracks().forEach((tr) => tr.stop());
      audioCtx?.close();
      stream = audioCtx = analyser = null;
      micBtn.setAttribute('aria-pressed', 'false');
      micLabel.textContent = 'Encender micrófono';
      label.textContent = shown > 0 ? `Tu marca: ${Math.round(shown)}/100. ¿La superas en el escenario?` : messages[0][1];
      if (!visible) cancelAnimationFrame(raf);
    };
    micBtn.addEventListener('click', async () => {
      if (stream) return stop();
      if (!navigator.mediaDevices?.getUserMedia) {
        label.textContent = 'Tu navegador no permite usar el micrófono aquí.';
        return;
      }
      try {
        stream = await navigator.mediaDevices.getUserMedia({ audio: { echoCancellation: false, noiseSuppression: false, autoGainControl: false } });
        audioCtx = new (window.AudioContext || window.webkitAudioContext)();
        analyser = audioCtx.createAnalyser();
        analyser.fftSize = 256;
        analyser.smoothingTimeConstant = 0.75;
        data = new Uint8Array(analyser.frequencyBinCount);
        audioCtx.createMediaStreamSource(stream).connect(analyser);
        peak = shown = 0;
        micBtn.setAttribute('aria-pressed', 'true');
        micLabel.textContent = 'Apagar micrófono';
        label.textContent = '¡Te escuchamos! Canta el coro con toda.';
        start();
      } catch {
        stream = null;
        label.textContent = 'No pudimos usar el micrófono. Revisa el permiso en tu navegador e inténtalo de nuevo.';
      }
    });
    window.addEventListener('pagehide', () => stream && stop());
  }

  // ---------- Galería horizontal anclada (escritorio) ----------
  const gallery = $('[data-gallery]');
  if (gallery && motion && desktop.matches) {
    const track = $('[data-track]', gallery);
    gallery.classList.add('is-pinned');
    const dist = () => Math.max(0, track.scrollWidth - window.innerWidth + 80);
    gsap.to(track, {
      x: () => -dist(),
      ease: 'none',
      scrollTrigger: { trigger: gallery, start: 'top top', end: () => '+=' + dist(), pin: '.gallery-pin', scrub: 0.6, invalidateOnRefresh: true },
    });
    $$('.shot img', gallery).forEach((img) => {
      gsap.fromTo(img, { scale: 1.15 }, { scale: 1, ease: 'none', scrollTrigger: { trigger: gallery, start: 'top top', end: () => '+=' + dist(), scrub: true } });
    });
  }
})();
