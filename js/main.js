/* ================================================
   VANDORPENOOST — Navigation & Interactions
   ================================================ */

(function () {

  /* ---- DOM refs ---- */
  const hamburger         = document.getElementById('hamburger');
  const sidebar           = document.getElementById('sidebar');
  const sidebarClose      = document.getElementById('sidebarClose');
  const overlay           = document.getElementById('overlay');
  const navItems          = document.querySelectorAll('.nav-item');
  const pages             = document.querySelectorAll('.page');
  const currentPageLabel  = document.getElementById('currentPageLabel');
  const headerLogo        = document.getElementById('headerLogo');

  const VALID_PAGES = ['nieuws', 'planning', 'rms', 'performance', 'roadmap', 'askai'];
  const PAGE_LABELS = {
    nieuws:      'Nieuws',
    planning:    'Planning',
    rms:         'RMS',
    performance: 'Performance',
    roadmap:     'Roadmap',
    askai:       'AskAI',
  };

  /* ================================================
     SIDEBAR
     ================================================ */
  function openSidebar() {
    sidebar.classList.add('open');
    overlay.classList.add('active');
    hamburger.classList.add('active');
    hamburger.setAttribute('aria-expanded', 'true');
    document.body.style.overflow = 'hidden';
  }

  function closeSidebar() {
    sidebar.classList.remove('open');
    overlay.classList.remove('active');
    hamburger.classList.remove('active');
    hamburger.setAttribute('aria-expanded', 'false');
    document.body.style.overflow = '';
  }

  hamburger.addEventListener('click', () =>
    sidebar.classList.contains('open') ? closeSidebar() : openSidebar()
  );
  sidebarClose.addEventListener('click', closeSidebar);
  overlay.addEventListener('click', closeSidebar);

  document.addEventListener('keydown', e => {
    if (e.key === 'Escape' && sidebar.classList.contains('open')) closeSidebar();
  });

  /* ================================================
     ROUTING
     ================================================ */
  function navigateTo(pageName) {
    if (!VALID_PAGES.includes(pageName)) pageName = 'nieuws';

    pages.forEach(p => p.classList.remove('active'));
    const target = document.getElementById('page-' + pageName);
    if (target) target.classList.add('active');

    navItems.forEach(item => {
      item.classList.toggle('active', item.dataset.page === pageName);
    });

    if (currentPageLabel) currentPageLabel.textContent = PAGE_LABELS[pageName] || pageName;

    window.scrollTo({ top: 0, behavior: 'instant' });
    closeSidebar();
  }

  navItems.forEach(item => {
    item.addEventListener('click', e => {
      e.preventDefault();
      const page = item.dataset.page;
      if (page) {
        history.pushState(null, '', '#' + page);
        navigateTo(page);
      }
    });
  });

  if (headerLogo) {
    headerLogo.addEventListener('click', e => {
      e.preventDefault();
      history.pushState(null, '', '#nieuws');
      navigateTo('nieuws');
    });
  }

  function handleHash() {
    const hash = window.location.hash.replace('#', '');
    navigateTo(VALID_PAGES.includes(hash) ? hash : 'nieuws');
  }

  window.addEventListener('popstate', handleHash);
  handleHash(); // initial load

  /* ================================================
     PLANNING — view toggle
     ================================================ */
  document.querySelectorAll('.planning-controls .btn-outline').forEach(btn => {
    btn.addEventListener('click', () => {
      document.querySelectorAll('.planning-controls .btn-outline')
        .forEach(b => b.classList.remove('active'));
      btn.classList.add('active');
    });
  });

  /* ================================================
     ASKAI CHAT
     ================================================ */
  const chatInput    = document.getElementById('chatInput');
  const chatSend     = document.getElementById('chatSend');
  const chatMessages = document.getElementById('chatMessages');

  const AI_RESPONSES = {
    actief:    'Momenteel zijn er <strong>118 voertuigen actief ingezet</strong>, een bezettingsgraad van 83%. Dit ligt boven de gestelde doelstelling van 80%.',
    kpi:       'De KPI-doelstellingen voor Q2 2026: omzet €3,1M, klanttevredenheid ≥ 8,5, vlootbezetting ≥ 80% en CO₂-reductie van 20% t.o.v. 2024.',
    levering:  'De volgende levering betreft <strong>24 nieuwe voertuigen</strong> (elektrisch & hybride) en wordt verwacht in Q2 2026, conform de vlootuitbreidingsplanning.',
    roadmap:   'De 2026-roadmap omvat vier fasen: Q1 (RMS v2.0, CO₂-rapportage), Q2 (EV-transitie, klantportaal, opening Almere), Q3 (AI-planning, ISO 14001) en Q4 (cashless, jaarreview).',
    onderhoud: 'Momenteel staan <strong>14 voertuigen in onderhoud</strong>. Dit betreft gepland onderhoud en is binnen de verwachte norm voor deze periode.',
    default: [
      'Op basis van de beschikbare gegevens is dit onderwerp momenteel in behandeling bij het operations team. U kunt de details terugvinden in het bijbehorende dashboard.',
      'Goed punt. Ik heb de relevante data geanalyseerd — voor een gedetailleerd overzicht verwijs ik u naar het Performance- of RMS-dashboard.',
      'Op dit moment beschik ik over beperkte informatie hierover. Ik adviseer contact op te nemen met de betreffende afdeling voor een volledig en actueel antwoord.',
    ],
  };

  function getResponse(q) {
    q = q.toLowerCase();
    if (q.includes('actief') || q.includes('voertuig') || q.includes('bezet')) return AI_RESPONSES.actief;
    if (q.includes('kpi') || q.includes('doelstelling') || q.includes('kwartaal')) return AI_RESPONSES.kpi;
    if (q.includes('levering') || q.includes('leveren') || q.includes('volgende')) return AI_RESPONSES.levering;
    if (q.includes('roadmap') || q.includes('samenvatting') || q.includes('planning')) return AI_RESPONSES.roadmap;
    if (q.includes('onderhoud') || q.includes('repair') || q.includes('maintenance')) return AI_RESPONSES.onderhoud;
    return AI_RESPONSES.default[Math.floor(Math.random() * AI_RESPONSES.default.length)];
  }

  function appendMessage(html, role) {
    const wrap = document.createElement('div');
    wrap.className = 'chat-message ' + role;

    const avatar = document.createElement('div');
    avatar.className = 'msg-avatar';
    avatar.innerHTML = role === 'assistant'
      ? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>'
      : '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg>';

    const content = document.createElement('div');
    content.className = 'msg-content';
    content.innerHTML = '<p>' + html + '</p>';

    wrap.appendChild(avatar);
    wrap.appendChild(content);
    chatMessages.appendChild(wrap);
    chatMessages.scrollTop = chatMessages.scrollHeight;
    return wrap;
  }

  function showTyping() {
    const wrap = document.createElement('div');
    wrap.className = 'chat-message assistant';
    wrap.id = 'typingWrap';
    wrap.innerHTML = `
      <div class="msg-avatar">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
          <path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/>
        </svg>
      </div>
      <div class="msg-content">
        <div class="typing-dots">
          <span class="typing-dot"></span>
          <span class="typing-dot"></span>
          <span class="typing-dot"></span>
        </div>
      </div>`;
    chatMessages.appendChild(wrap);
    chatMessages.scrollTop = chatMessages.scrollHeight;
    return wrap;
  }

  function sendMessage(text) {
    text = (text || '').trim();
    if (!text) return;
    if (chatInput) chatInput.value = '';

    appendMessage(text, 'user');
    const typing = showTyping();

    setTimeout(() => {
      typing.remove();
      appendMessage(getResponse(text), 'assistant');
    }, 900 + Math.random() * 600);
  }

  if (chatSend)  chatSend.addEventListener('click', () => sendMessage(chatInput.value));
  if (chatInput) chatInput.addEventListener('keydown', e => {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMessage(chatInput.value); }
  });

  /* ---- suggestion chips (global) ---- */
  window.askQuestion = function (q) {
    navigateTo('askai');
    setTimeout(() => sendMessage(q), 100);
  };

})();
