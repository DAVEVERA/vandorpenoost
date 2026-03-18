/* ================================================
   SITE TICKER — Vanilla JS (cloned from NIEUWS OOSTENDORP)
   ================================================ */
(function () {

  const TICKER_ITEMS = [
    'Welkom op het interne portal van Oostendorp',
    'PowerPlanner beschikbaar via de Planning pagina',
    'RMS systeem bijgewerkt naar versie 2.1',
    'CO₂-doelstellingen Q1 gehaald — op koers naar −20%',
    'Klantportaal in acceptatiefase — launch verwacht Q2 2026',
    'EV Transitie Fase 2 — 35% elektrificatie in uitvoering',
    'Opening vestiging Almere gepland voor juni 2026',
    'ISO 14001 certificering — externe audit gepland september 2026',
    'Vlootuitbreiding — 24 nieuwe voertuigen verwacht Q2 2026',
    'AI-gestuurde planningsmodule in ontwikkeling voor Q3 2026'
  ];

  function getWeatherIcon(code) {
    if (code === 0) return '☀️';
    if (code <= 3) return '🌤️';
    if (code <= 48) return '🌫️';
    if (code <= 55) return '🌦️';
    if (code <= 65) return '🌧️';
    if (code <= 75) return '❄️';
    if (code <= 82) return '🚿';
    return '⛈️';
  }

  function getWeatherDesc(code) {
    const c = {
      0:'Onbewolkt',1:'Licht bewolkt',2:'Half bewolkt',3:'Bewolkt',
      45:'Mist',48:'Rijpfrost',51:'Lichte motregen',53:'Motregen',55:'Zware motregen',
      61:'Lichte regen',63:'Regen',65:'Zware regen',71:'Lichte sneeuwval',
      73:'Sneeuwval',75:'Zware sneeuwval',80:'Lichte buien',81:'Buien',82:'Zware buien',
      95:'Onweer',96:'Onweer met hagel',99:'Zwaar onweer'
    };
    return c[code] || 'Wisselend';
  }

  /* ---- Clock ---- */
  function updateClock() {
    const el = document.getElementById('tickerTime');
    if (!el) return;
    el.textContent = new Date().toLocaleTimeString('nl-NL', { hour: '2-digit', minute: '2-digit' });
  }
  setInterval(updateClock, 1000);
  updateClock();

  /* ---- Weather ---- */
  async function updateWeather() {
    try {
      const r = await fetch(
        'https://api.open-meteo.com/v1/forecast?latitude=51.65&longitude=5.61&current_weather=true&timezone=auto'
      );
      const d = await r.json();
      if (!d.current_weather) return;
      const temp = Math.round(d.current_weather.temperature);
      const code = d.current_weather.weathercode;
      const iconEl = document.getElementById('tickerWeatherIcon');
      const tempEl = document.getElementById('tickerWeatherTemp');
      const descEl = document.getElementById('tickerWeatherDesc');
      if (iconEl) iconEl.textContent = getWeatherIcon(code);
      if (tempEl) tempEl.textContent = temp + '°';
      if (descEl) descEl.textContent = getWeatherDesc(code);
    } catch (e) { /* fail silently */ }
  }
  updateWeather();
  setInterval(updateWeather, 600000);

  /* ---- Traffic (simulated, same as original) ---- */
  function updateTraffic() {
    const roads = ['A50', 'A2', 'N279', 'A59', 'A73'];
    const items = roads.filter(() => Math.random() > 0.4);
    const countEl = document.getElementById('tickerTrafficCount');
    const roadEl  = document.getElementById('tickerTrafficRoad');
    if (!countEl) return;
    if (items.length > 0) {
      countEl.textContent = items.length + ' melding' + (items.length !== 1 ? 'en' : '');
      if (roadEl) roadEl.textContent = items[0];
    } else {
      countEl.textContent = 'Geen file';
      if (roadEl) roadEl.textContent = '';
    }
  }
  updateTraffic();
  setInterval(updateTraffic, 300000);

  /* ---- Ticker scroll ---- */
  function initTickerScroll() {
    const scroll = document.getElementById('tickerScroll');
    if (!scroll) return;
    const items = TICKER_ITEMS;
    const charCount = items.reduce((s, t) => s + t.length, 0);
    const duration = Math.max(30, Math.round(charCount * 0.18));
    const html = items.map(item =>
      '<span class="ticker-scroll-item">' + item +
      '<span class="ticker-scroll-sep"> • </span></span>'
    ).join('');
    scroll.innerHTML = html + html; // duplicate for seamless loop
    scroll.style.animationDuration = duration + 's';
  }
  initTickerScroll();

  /* ---- Hero tile SPA routing ---- */
  document.querySelectorAll('.hero-tile[data-page]').forEach(function (tile) {
    tile.addEventListener('click', function (e) {
      e.preventDefault();
      var page = tile.dataset.page;
      if (page) {
        history.pushState(null, '', '#' + page);
        window.dispatchEvent(new PopStateEvent('popstate'));
      }
    });
  });

})();
