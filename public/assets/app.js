(() => {
  'use strict';

  const root = document.querySelector('#app');
  const toast = document.querySelector('#toast');
  const state = {
    loading: true, error: '', screen: 'questions', questions: [], trainings: [],
    activeCategory: 'all', selectedCategories: new Set(), search: '', filterOpen: false,
    activeQuestion: null, picked: new Set(), trainingName: '', editingTrainingId: null,
    currentTraining: null, sessionId: null, sessionIndex: 0, seconds: 0,
    stream: null, recorder: null, audioChunks: [], timer: null, hintOpen: false,
    uploadSucceeded: false, recordingStartedAt: null, sessionSegments: [], resultSession: null,
    playerOpen: false, player: null, playerModules: null, activeSegment: 0,
    analysisTimer: null,
  };

  const esc = (value) => String(value ?? '').replace(/[&<>'"]/g, (char) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;',
  })[char]);
  const time = (seconds) => {
    const totalSeconds = Math.max(0, Math.floor(Number(seconds) || 0));
    return `${String(Math.floor(totalSeconds / 60)).padStart(2, '0')}:${String(totalSeconds % 60).padStart(2, '0')}`;
  };
  const timeMs = (milliseconds) => time(Math.max(0, Math.floor(milliseconds / 1000)));
  const memoryLabel = (value) => value >= 80 ? 'Отлично' : value >= 50 ? 'Нужно повторить' : 'Слабое место';
  const memoryClass = (value) => value >= 80 ? 'high' : value >= 50 ? 'mid' : 'low';

  function recordingOffsetMs() {
    return state.recordingStartedAt === null ? state.seconds * 1000 : Math.max(0, Math.round(performance.now() - state.recordingStartedAt));
  }

  function closeCurrentSegment(status = 'completed') {
    const segment = state.sessionSegments[state.sessionSegments.length - 1];
    if (segment && segment.endedAtMs === null) {
      segment.endedAtMs = recordingOffsetMs();
      segment.status = status;
    }
  }

  function openSegment(position, startedAtMs) {
    const question = state.currentTraining.questions[position];
    state.sessionSegments.push({
      position,
      questionId: question.id,
      startedAtMs,
      endedAtMs: null,
      status: 'completed',
    });
  }

  async function api(url, options = {}) {
    const response = await fetch(url, { headers: { Accept: 'application/json', ...(options.body instanceof FormData ? {} : { 'Content-Type': 'application/json' }), ...options.headers }, ...options });
    const data = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(data.error || 'Сервер временно недоступен.');
    return data;
  }

  function showToast(message) {
    toast.textContent = message;
    toast.classList.add('show');
    window.clearTimeout(showToast.timer);
    showToast.timer = window.setTimeout(() => toast.classList.remove('show'), 3600);
  }

  async function load() {
    state.loading = true; state.error = ''; render();
    try {
      const [questionData, trainingData] = await Promise.all([api('/api/questions'), api('/api/trainings')]);
      state.questions = questionData.questions;
      state.trainings = trainingData.trainings;
      const first = state.trainings[0];
      state.trainingName = first?.name || 'PHP Backend — пробное собеседование';
      state.editingTrainingId = first?.id || null;
      state.picked = new Set((first?.questions || state.questions.slice(0, 3)).map((question) => question.id));
      const resultId = Number(new URLSearchParams(window.location.search).get('result'));
      if (Number.isInteger(resultId) && resultId > 0) {
        try {
          const resultData = await api(`/api/sessions/${resultId}`);
          const training = state.trainings.find((item) => item.id === resultData.session.trainingId);
          if (training && resultData.session.status === 'completed') {
            state.currentTraining = training;
          state.resultSession = resultData.session;
          state.uploadSucceeded = resultData.session.hasAudio;
          state.seconds = resultData.session.elapsedSeconds;
          state.playerOpen = resultData.session.hasAudio;
          state.screen = 'complete';
          } else setResultUrl();
        } catch (_) {
          setResultUrl();
        }
      }
    } catch (error) {
      state.error = error.message;
    } finally {
      state.loading = false; render();
    }
    if (state.screen === 'complete' && state.playerOpen) await initAudioPlayer();
    if (state.screen === 'complete') startAnalysisPolling();
  }

  function categories() {
    const map = new Map();
    state.questions.forEach((question) => {
      const item = map.get(question.category) || { id: question.category, name: question.categoryLabel, color: question.categoryColor, count: 0 };
      item.count += 1; map.set(question.category, item);
    });
    return [...map.values()];
  }

  function visibleQuestions() {
    const query = state.search.trim().toLocaleLowerCase('ru');
    const filters = state.selectedCategories.size ? [...state.selectedCategories] : state.activeCategory === 'all' ? [] : [state.activeCategory];
    return state.questions.filter((question) => (!filters.length || filters.includes(question.category)) && (!query || question.title.toLocaleLowerCase('ru').includes(query) || question.categoryLabel.toLocaleLowerCase('ru').includes(query)));
  }

  function render() {
    if (state.loading) {
      root.innerHTML = '<div class="boot"><span class="brand-mark"><i></i></span><p>Готовим вопросы…</p></div>';
      return;
    }
    if (state.error) {
      root.innerHTML = `<div class="boot"><div class="error-state"><h2>Не удалось загрузить приложение</h2><p>${esc(state.error)}</p><button class="primary" data-action="retry">Повторить</button></div></div>`;
      return;
    }
    if (state.screen === 'requesting') return renderPermission();
    if (state.screen === 'session') return renderSession();
    if (state.screen === 'complete') return renderComplete();

    root.innerHTML = `<div class="app-shell">${header()}<main class="workspace">${sidebar()}${state.screen === 'questions' ? renderQuestions() : state.screen === 'detail' ? renderDetail() : state.screen === 'builder' ? renderBuilder() : renderTrainings()}</main></div>`;
  }

  function header() {
    const questionActive = ['questions', 'detail'].includes(state.screen);
    const trainingActive = ['builder', 'trainings'].includes(state.screen);
    return `<header class="topbar"><button class="brand" data-action="nav" data-screen="questions" aria-label="На главную"><span class="brand-mark"><i></i></span>Готово<span>.</span></button><nav aria-label="Основная навигация"><button class="${questionActive ? 'active' : ''}" data-action="nav" data-screen="questions">База вопросов</button><button class="${trainingActive ? 'active' : ''}" data-action="nav" data-screen="trainings">Мои тренировки</button><a href="/chat">Чат с Qwen</a></nav><div class="top-actions"><button class="new-training" data-action="new-training">＋ Создать тренинг</button><button class="avatar" aria-label="Профиль">АК</button></div></header>`;
  }

  function sidebar() {
    const items = [{ id: 'all', name: 'Все вопросы', color: '#241d20', count: state.questions.length, mark: '⌘' }, ...categories().map((category) => ({ ...category, mark: category.name[0] }))];
    return `<aside class="sidebar"><div class="sidebar-heading"><p>Категории</p><span>${items.length - 1}</span></div><div class="category-list">${items.map((item) => `<button class="${state.activeCategory === item.id ? 'active' : ''}" data-action="category" data-id="${esc(item.id)}"><i style="background:${esc(item.color)}">${esc(item.mark)}</i><span>${esc(item.name)}</span><small>${item.count}</small></button>`).join('')}</div><div class="sidebar-note"><span>↗</span><p><strong>${state.questions.filter((question) => question.memory < 50).length} вопросов</strong><br>стоит повторить сегодня</p></div></aside>`;
  }

  function filterMarkup() {
    if (!state.filterOpen) return '';
    return `<div class="filter-popover"><header><strong>Выберите категории</strong><button data-action="reset-filter">Сбросить</button></header>${categories().map((category) => `<label class="filter-option"><input type="checkbox" data-change="filter-category" value="${esc(category.id)}" ${state.selectedCategories.has(category.id) ? 'checked' : ''}><i style="background:${esc(category.color)}"></i><span>${esc(category.name)}</span><small>${category.count}</small></label>`).join('')}<button class="apply-filter" data-action="apply-filter">Показать вопросы</button></div>`;
  }

  function renderQuestions() {
    const visible = visibleQuestions();
    const average = state.questions.length ? Math.round(state.questions.reduce((sum, question) => sum + question.memory, 0) / state.questions.length) : 0;
    return `<section class="content"><div class="content-head"><div><p class="breadcrumb">База знаний / Все вопросы</p><h1>Вопросы</h1><p class="subtitle">Находите слабые места и превращайте их в уверенные ответы.</p></div><div class="head-stat"><span>${average}%</span><small>среднее запоминание</small></div></div><div class="toolbar"><label class="search-field"><input id="question-search" data-input="search" value="${esc(state.search)}" placeholder="Поиск по вопросам и категориям" aria-label="Поиск">${state.search ? '<button class="clear-search" data-action="clear-search" aria-label="Очистить поиск">×</button>' : ''}<kbd>⌘ K</kbd></label><div class="filter-wrap"><button class="filter-button ${state.selectedCategories.size ? 'has-filter' : ''}" data-action="toggle-filter">Категории${state.selectedCategories.size ? `<b>${state.selectedCategories.size}</b>` : ''}</button>${filterMarkup()}</div></div>${state.selectedCategories.size ? `<div class="filter-chips">${[...state.selectedCategories].map((id) => { const category = categories().find((item) => item.id === id); return `<button class="chip" data-action="remove-filter" data-id="${esc(id)}">${esc(category?.name || id)}<span>×</span></button>`; }).join('')}</div>` : ''}<div class="list-meta"><span>Найдено: <strong>${visible.length}</strong></span><button class="text-button" data-action="sort-weak">Сначала слабые ↓</button></div><div class="question-list">${visible.length ? visible.map(questionCard).join('') : '<div class="empty"><h2>Ничего не нашли</h2><p>Измените запрос или сбросьте фильтры.</p><button class="primary" data-action="reset-all">Сбросить</button></div>'}</div></section>`;
  }

  function questionCard(question) {
    return `<article class="question-card" role="button" tabindex="0" data-action="open-question" data-id="${question.id}"><span class="question-index">${String(question.id).padStart(2, '0')}</span><div class="question-main"><div class="question-tags"><span><i style="background:${esc(question.categoryColor)}"></i>${esc(question.categoryLabel)}</span><b>${esc(question.level)}</b></div><h2>${esc(question.title)}</h2><p>Повторений: ${question.repeats}</p></div><div class="memory m-${memoryClass(question.memory)}"><div><span>${memoryLabel(question.memory)}</span><strong>${question.memory}%</strong></div><span class="meter"><i style="width:${question.memory}%"></i></span></div><button class="open-question" aria-label="Открыть вопрос">↗</button></article>`;
  }

  function renderDetail() {
    const question = state.activeQuestion || state.questions[0];
    if (!question) return '<section class="detail-screen"><div class="empty">Вопрос не найден.</div></section>';
    return `<section class="detail-screen"><button class="back-link" data-action="nav" data-screen="questions">← Все вопросы</button><div class="detail-layout"><article class="detail-card"><div class="question-tags"><span><i style="background:${esc(question.categoryColor)}"></i>${esc(question.categoryLabel)}</span><b>${esc(question.level)}</b></div><p class="detail-number">Вопрос ${String(question.id).padStart(2, '0')}</p><h1>${esc(question.title)}</h1><div class="answer-hint"><span>Как отвечать</span><p>${esc(question.hint)}</p></div><button class="primary" data-action="add-question" data-id="${question.id}">＋ Добавить в тренинг</button></article><aside class="memory-panel"><p>Качество запоминания</p><strong>${question.memory}%</strong><span class="meter"><i style="width:${question.memory}%;background:var(--amber)"></i></span><span>${memoryLabel(question.memory)}</span><hr><small>Последний ответ</small><b>${question.lastAnsweredAt ? new Date(question.lastAnsweredAt).toLocaleDateString('ru-RU') : 'Ещё не отвечали'}</b><small>Всего повторений</small><b>${question.repeats}</b></aside></div></section>`;
  }

  function renderBuilder() {
    const visible = visibleQuestions();
    const allPicked = visible.length > 0 && visible.every((question) => state.picked.has(question.id));
    const selectedQuestions = state.questions.filter((question) => state.picked.has(question.id));
    return `<section class="builder-screen"><div class="builder-head"><div><p class="breadcrumb">Конструктор тренинга</p><h1>Соберите свой маршрут</h1><p class="subtitle">Выберите вопросы, которые хотите отработать.</p></div><button class="primary" data-action="save-training" ${!state.picked.size || !state.trainingName.trim() ? 'disabled' : ''}>Сохранить тренинг →</button></div><label class="training-name"><span>Название тренинга</span><input maxlength="120" data-input="training-name" value="${esc(state.trainingName)}" placeholder="Например, Подготовка к Senior PHP"><small>${state.trainingName.length}/120</small></label><div class="builder-grid"><div><div class="builder-toolbar"><label class="search-field"><input data-input="search" value="${esc(state.search)}" placeholder="Найти вопрос" aria-label="Найти вопрос"></label><div class="quick-cats">${categories().map((category) => `<button class="${state.selectedCategories.has(category.id) ? 'active' : ''}" data-action="quick-category" data-id="${esc(category.id)}">${esc(category.name)}</button>`).join('')}</div></div><div class="select-all"><label><input type="checkbox" data-change="select-all" ${allPicked ? 'checked' : ''}> Выбрать все на экране</label><small>${visible.length} вопросов</small></div><div class="pick-list">${visible.map((question) => `<label class="pick-row ${state.picked.has(question.id) ? 'picked' : ''}"><input type="checkbox" data-change="picked" value="${question.id}" ${state.picked.has(question.id) ? 'checked' : ''}><span><small>${esc(question.categoryLabel)} · ${esc(question.level)}</small>${esc(question.title)}</span><em>${question.memory}%</em></label>`).join('')}</div></div><aside class="selection-summary"><span class="summary-kicker">Ваш тренинг</span><strong>${state.picked.size}</strong><p>вопросов выбрано</p><div><span>≈ ${state.picked.size * 3} мин</span><span>${new Set(selectedQuestions.map((question) => question.category)).size} категорий</span></div><p class="summary-note">Можно выделить все вопросы, которые видны после поиска и фильтрации.</p></aside></div></section>`;
  }

  function renderTrainings() {
    return `<section class="trainings-screen"><p class="breadcrumb">Практика</p><h1>Мои тренировки</h1><p class="subtitle">Репетируйте собеседование в условиях, близких к реальным.</p>${state.trainings.length ? state.trainings.map((training) => `<article class="training-card"><div class="training-cover"><span>${esc(training.categories[0] || 'Tech')}</span><i></i><i></i><i></i></div><div class="training-info"><span class="ready-pill">Готов к запуску</span><h2>${esc(training.name)}</h2><p>${training.questions.length} вопросов · примерно ${training.estimatedMinutes} минут · ${esc(training.categories.join(' / '))}</p><div class="card-actions"><button class="play-button" data-action="start-training" data-id="${training.id}">▶ Начать тренировку</button><button class="secondary" data-action="edit-training" data-id="${training.id}">Редактировать</button></div></div><div class="training-score"><small>Лучший результат</small><strong>${training.bestScore === null ? '—' : `${training.bestScore}%`}</strong><span>${training.bestScore === null ? 'ещё не пройден' : 'личный рекорд'}</span></div></article>`).join('') : '<div class="empty"><h2>Пока нет тренировок</h2><p>Соберите первый набор вопросов.</p><button class="primary" data-action="new-training">Создать</button></div>'}<div class="recording-note"><span>◉</span><div><strong>Перед началом</strong><p>Браузер запросит доступ только к микрофону. Запись голоса помогает оценить речь, темп и уверенность; камера и экран не используются.</p></div></div></section>`;
  }

  function renderPermission() {
    root.innerHTML = '<div class="permission-screen"><div class="permission-card"><div class="mic-orb">●</div><p class="breadcrumb">Подготовка аудио</p><h1>Разрешите микрофон</h1><p>Запрашиваем только голос. Камера, экран и видео не используются.</p></div></div>';
  }

  function renderSession() {
    const questions = state.currentTraining.questions;
    const question = questions[state.sessionIndex];
    const progress = Math.round(((state.sessionIndex + 1) / questions.length) * 100);
    root.innerHTML = `<div class="session-screen"><header><button class="brand"><span class="brand-mark"><i></i></span>Готово<span>.</span></button><div class="recording"><i></i> AUDIO <strong id="session-time">${time(state.seconds)}</strong></div><button class="exit-session" data-action="session-exit">Завершить ×</button></header><div class="session-progress"><i style="width:${progress}%"></i></div><main><div class="session-meta"><span>Вопрос ${state.sessionIndex + 1} из ${questions.length}</span><b>${progress}% пройдено</b></div><section class="session-question"><div class="audio-live"><div class="mic-orb">●</div><div class="wave" aria-hidden="true">${'<i></i>'.repeat(7)}</div><small><i></i> Микрофон включён</small></div><div class="prompt"><p>${esc(question.categoryLabel)} · ${esc(question.level)}</p><h1>${esc(question.title)}</h1><div class="speaking-tip"><span>≈</span><p><strong>Отвечайте вслух</strong><br>Говорите так, будто перед вами интервьюер. Оптимально — 2–3 минуты.</p></div></div></section>${state.hintOpen ? `<div class="session-hint">${esc(question.hint)}</div>` : ''}<div class="session-controls"><button class="hint-button" data-action="toggle-hint">${state.hintOpen ? 'Скрыть подсказку' : 'Подсказка'}</button><p>Записывается только аудио с микрофона</p><button class="next-button" data-action="session-next">${state.sessionIndex === questions.length - 1 ? 'Завершить тренинг' : 'Следующий вопрос'} →</button></div></main></div>`;
  }

  function renderComplete() {
    const total = state.currentTraining?.questions.length || 0;
    const session = state.resultSession;
    const segments = session?.segments || [];
    const canPlay = state.uploadSucceeded && session?.hasAudio;
    const player = state.playerOpen && canPlay ? `<section class="result-player" aria-label="Аудиозапись тренировки"><div class="player-heading"><div><span>Запись тренировки</span><h2>${esc(state.currentTraining?.name || 'Тренировка')}</h2></div><a class="download-audio" href="${esc(session.downloadUrl)}">↓ Скачать</a></div><div class="wave-shell loading"><div id="waveform"></div><div id="wave-timeline"></div><p class="wave-loading">Строим аудиоволну…</p></div><div class="player-controls"><button data-action="player-skip" data-seconds="-10" aria-label="Назад на 10 секунд">↶ 10</button><button class="player-main" data-action="player-toggle" aria-label="Воспроизвести"><span id="player-icon">▶</span></button><button data-action="player-skip" data-seconds="10" aria-label="Вперёд на 10 секунд">10 ↷</button><strong><span id="player-current">00:00</span> <i>/</i> <span id="player-duration">${time(session.elapsedSeconds)}</span></strong><button class="player-rate" data-action="player-rate">1×</button></div><div class="segment-list"><div class="segment-list-head"><span>Ответы по вопросам</span><small>Нажмите, чтобы воспроизвести фрагмент</small></div>${segments.map((segment) => `<button class="segment-row ${segment.position === state.activeSegment ? 'active' : ''}" data-action="play-segment" data-position="${segment.position}" data-segment-row="${segment.position}"><b>${String(segment.position + 1).padStart(2, '0')}</b><span><small>${esc(segment.categoryLabel)}</small>${esc(segment.questionTitle)}</span><time>${timeMs(segment.startedAtMs)}–${timeMs(segment.endedAtMs)}</time><i>▶</i></button>`).join('')}</div></section>` : '';
    root.innerHTML = `<div class="complete-screen"><div class="complete-card ${canPlay ? 'player-expanded' : ''}"><div class="complete-summary"><div class="trophy">✓</div><p class="completion-kicker">Тренировка завершена</p><h1>Отличная работа!</h1><p>${state.uploadSucceeded ? 'Аудиозапись ответа сохранена. Ниже уже открыт плеер, а локальный анализ появится автоматически.' : 'Тренировка сохранена, но аудиозапись не была загружена.'}</p><div class="result-stats"><div><strong>${total}</strong><span>вопросов</span></div><div><strong>${time(state.seconds)}</strong><span>затрачено</span></div><div><strong>100%</strong><span>пройдено</span></div></div></div>${player}<section id="analysis-panel" class="analysis-panel" aria-live="polite">${analysisMarkup()}</section><div class="complete-actions"><button class="primary" data-action="complete-home">К тренировкам</button><button class="secondary" data-action="again">Пройти ещё раз</button></div></div></div>`;
  }

  const criterionMeta = {
    technicalCorrectness: ['Техническая корректность', 40],
    completeness: ['Полнота', 20],
    structure: ['Структура', 15],
    clarity: ['Ясность', 10],
    pace: ['Темп', 5],
    pauses: ['Паузы', 5],
    fillerWords: ['Слова-паразиты', 5],
  };

  function analysisMarkup() {
    const analysis = state.resultSession?.analysis;
    if (!state.resultSession?.hasAudio) return '';
    if (!analysis || analysis.status === 'not_started') {
      return `<div class="analysis-empty"><span class="analysis-icon">◎</span><div><h2>Локальный разбор ответа</h2><p>GigaAM расшифрует русскую речь, затем Qwen отдельно оценит содержание и подачу.</p></div><button class="secondary" data-action="retry-analysis">Запустить анализ</button></div>`;
    }
    if (analysis.status === 'failed') {
      return `<div class="analysis-error"><span class="analysis-icon">!</span><div><h2>Анализ не завершён</h2><p>${esc(analysis.error?.message || 'Локальная модель вернула ошибку.')}</p><small>Код: ${esc(analysis.error?.code || 'unknown')}</small></div><button class="secondary" data-action="retry-analysis">Повторить анализ</button></div>`;
    }
    if (['queued', 'transcribing', 'evaluating'].includes(analysis.status)) {
      const labels = {
        queued: ['Анализ поставлен в очередь', 'Ждём свободный локальный worker.'],
        transcribing: ['GigaAM расшифровывает запись', 'Сначала получаем русский текст, слова и паузы. Модель будет выгружена из памяти перед следующим этапом.'],
        evaluating: ['Qwen оценивает ответы', 'Расшифровка готова. Qwen3.5-9B проверяет каждый ответ по семи критериям.'],
      };
      const [title, description] = labels[analysis.status];
      return `<div class="analysis-progress"><span class="analysis-spinner" aria-hidden="true"></span><div><small>Локальный AI · без внешнего API</small><h2>${title}</h2><p>${description}</p></div></div><div class="pipeline" aria-label="Этапы анализа"><span class="done">Аудио</span><i></i><span class="${analysis.status !== 'queued' ? 'done' : 'active'}">GigaAM</span><i></i><span class="${analysis.status === 'evaluating' ? 'active' : ''}">Qwen</span><i></i><span>Результат</span></div>`;
    }

    const evaluated = (state.resultSession?.segments || []).filter((segment) => segment.evaluation || segment.transcript);
    return `<div class="analysis-heading"><div><small>Локальный AI-разбор завершён</small><h2>Оценка ответов и рекомендации</h2><p>Итог рассчитан по технической корректности, полноте и качеству речи.</p></div><strong>${Number(analysis.score) || 0}<i>/100</i></strong></div><div class="evaluation-list">${evaluated.map(evaluationMarkup).join('')}</div>`;
  }

  function evaluationMarkup(segment, index) {
    const transcript = segment.transcript;
    const evaluation = segment.evaluation;
    const metrics = transcript?.metrics || {};
    const metricItems = [
      ['Темп', Number(metrics.wordsPerMinute) ? `${Math.round(metrics.wordsPerMinute)} сл/мин` : '—'],
      ['Пауз', Number.isFinite(Number(metrics.pauseCount)) ? String(metrics.pauseCount) : '—'],
      ['Длинных пауз', Number.isFinite(Number(metrics.longPauseCount)) ? String(metrics.longPauseCount) : '—'],
      ['Слов-паразитов', Number.isFinite(Number(metrics.fillerWordCount)) ? String(metrics.fillerWordCount) : '—'],
    ];
    const criteria = evaluation?.criteria || {};
    const pauses = Array.isArray(transcript?.pauses) ? transcript.pauses : [];
    const criterionRows = Object.entries(criterionMeta).map(([key, [label, maximum]]) => {
      const value = Math.max(0, Math.min(maximum, Number(criteria[key]) || 0));
      return `<div class="criterion"><span>${label}</span><i><b style="width:${Math.round(value / maximum * 100)}%"></b></i><strong>${value}/${maximum}</strong></div>`;
    }).join('');
    const list = (title, values, cssClass) => Array.isArray(values) && values.length
      ? `<div class="feedback-list ${cssClass}"><h4>${title}</h4><ul>${values.map((item) => `<li>${esc(item)}</li>`).join('')}</ul></div>` : '';

    const pauseMarkup = pauses.length ? `<div class="pause-list"><small>Паузы в записи</small><div>${pauses.map((pause) => {
      const startMs = Math.max(0, Number(pause.startMs) || 0);
      const durationMs = Math.max(0, Number(pause.durationMs) || 0);
      return `<button data-action="player-seek" data-ms="${startMs}" aria-label="Перейти к паузе ${timeMs(startMs)}">${timeMs(startMs)} <i>${(durationMs / 1000).toFixed(1)} с</i></button>`;
    }).join('')}</div></div>` : '<p class="no-pauses">Заметных пауз длиннее 0,45 секунды не найдено.</p>';

    return `<details class="evaluation-card" ${index === 0 ? 'open' : ''}><summary><span><b>${String(segment.position + 1).padStart(2, '0')}</b><i>${esc(segment.categoryLabel)}</i><strong>${esc(segment.questionTitle)}</strong></span><em>${evaluation ? `${Math.max(0, Math.min(100, Number(evaluation.totalScore) || 0))}/100` : 'Расшифровано'}</em></summary><div class="evaluation-body"><div class="transcript-block"><div class="transcript-head"><h3>Расшифровка</h3><button data-action="play-segment" data-position="${segment.position}">▶ ${timeMs(segment.startedAtMs)}–${timeMs(segment.endedAtMs)}</button></div><p>${esc(transcript?.text || 'Речь не распознана.')}</p>${pauseMarkup}<div class="speech-metrics">${metricItems.map(([label, value]) => `<span><small>${label}</small><b>${value}</b></span>`).join('')}</div></div>${evaluation ? `<div class="verdict"><h3>Вердикт</h3><p>${esc(evaluation.verdict)}</p></div><div class="criteria-grid">${criterionRows}</div><div class="feedback-grid">${list('Что получилось', evaluation.strengths, 'positive')}${list('Что улучшить', evaluation.recommendations, 'recommend')}${list('Что упущено', evaluation.missingTopics, 'missing')}</div>` : '<p class="partial-evaluation">Оценка этого ответа ещё не готова.</p>'}</div></details>`;
  }

  function stopAnalysisPolling() {
    if (state.analysisTimer) window.clearTimeout(state.analysisTimer);
    state.analysisTimer = null;
  }

  function startAnalysisPolling() {
    stopAnalysisPolling();
    const status = state.resultSession?.analysis?.status;
    if (!['queued', 'transcribing', 'evaluating'].includes(status)) return;
    state.analysisTimer = window.setTimeout(refreshAnalysis, 1800);
  }

  async function refreshAnalysis() {
    if (state.screen !== 'complete' || !state.resultSession?.id) return stopAnalysisPolling();
    try {
      const data = await api(`/api/sessions/${state.resultSession.id}`);
      if (state.screen !== 'complete') return;
      state.resultSession = data.session;
      const panel = document.querySelector('#analysis-panel');
      if (panel) panel.innerHTML = analysisMarkup();
    } catch (error) {
      showToast(error.message);
    }
    startAnalysisPolling();
  }

  async function retryAnalysis() {
    try {
      const data = await api(`/api/sessions/${state.resultSession.id}/analysis`, { method: 'POST', body: '{}' });
      state.resultSession = data.session;
      const panel = document.querySelector('#analysis-panel');
      if (panel) panel.innerHTML = analysisMarkup();
      startAnalysisPolling();
    } catch (error) {
      showToast(error.message);
    }
  }

  async function loadPlayerModules() {
    if (!state.playerModules) {
      const base = 'https://cdn.jsdelivr.net/npm/wavesurfer.js@7.12.11/dist';
      state.playerModules = Promise.all([
        import(`${base}/wavesurfer.esm.js`),
        import(`${base}/plugins/regions.esm.js`),
        import(`${base}/plugins/timeline.esm.js`),
        import(`${base}/plugins/hover.esm.js`),
      ]);
    }
    return state.playerModules;
  }

  async function initAudioPlayer() {
    const session = state.resultSession;
    if (!state.playerOpen || !session?.audioUrl || !document.querySelector('#waveform')) return;
    try {
      const [waveModule, regionsModule, timelineModule, hoverModule] = await loadPlayerModules();
      if (!state.playerOpen || !document.querySelector('#waveform')) return;
      const regions = regionsModule.default.create();
      const timeline = timelineModule.default.create({ container: '#wave-timeline', height: 22 });
      const hover = hoverModule.default.create({ lineColor: '#6e44ff', lineWidth: 1, labelBackground: '#241d20', labelColor: '#fff', labelSize: '10px' });
      const wavesurfer = waveModule.default.create({
        container: '#waveform',
        url: session.audioUrl,
        height: 116,
        waveColor: '#d8cfee',
        progressColor: '#6e44ff',
        cursorColor: '#ef745f',
        cursorWidth: 2,
        barWidth: 3,
        barGap: 2,
        barRadius: 3,
        normalize: true,
        plugins: [regions, timeline, hover],
      });
      state.player = { wavesurfer, regions, playUntil: null, rateIndex: 1 };

      wavesurfer.on('ready', (duration) => {
        document.querySelector('.wave-shell')?.classList.remove('loading');
        const durationNode = document.querySelector('#player-duration');
        if (durationNode) durationNode.textContent = time(duration);
        session.segments.forEach((segment) => regions.addRegion({
          id: `segment-${segment.position}`,
          start: segment.startedAtMs / 1000,
          end: segment.endedAtMs / 1000,
          content: String(segment.position + 1),
          color: segment.position % 2 ? 'rgba(239,116,95,.12)' : 'rgba(110,68,255,.12)',
          drag: false,
          resize: false,
        }));
      });
      wavesurfer.on('timeupdate', updatePlayerTime);
      wavesurfer.on('play', () => updatePlayIcon(true));
      wavesurfer.on('pause', () => updatePlayIcon(false));
      wavesurfer.on('finish', () => updatePlayIcon(false));
      wavesurfer.on('error', () => showToast('Не удалось открыть аудиозапись.'));
      regions.on('region-clicked', (region, event) => {
        event.stopPropagation();
        playSegment(Number(region.id.replace('segment-', '')));
      });
    } catch (error) {
      state.playerModules = null;
      document.querySelector('.wave-loading')?.replaceChildren(document.createTextNode('Не удалось загрузить проигрыватель.'));
      showToast('Не удалось загрузить WaveSurfer. Проверьте подключение к интернету.');
    }
  }

  function updatePlayIcon(playing) {
    const icon = document.querySelector('#player-icon');
    if (icon) icon.textContent = playing ? 'Ⅱ' : '▶';
    const button = icon?.closest('button');
    if (button) button.setAttribute('aria-label', playing ? 'Пауза' : 'Воспроизвести');
  }

  function updatePlayerTime(currentTime) {
    const current = document.querySelector('#player-current');
    if (current) current.textContent = time(currentTime);
    if (state.player?.playUntil !== null && currentTime >= state.player.playUntil - 0.03) {
      const playUntil = state.player.playUntil;
      state.player.playUntil = null;
      state.player.wavesurfer.pause();
      state.player.wavesurfer.setTime(playUntil);
    }
    const milliseconds = currentTime * 1000;
    const active = state.resultSession?.segments.findIndex((segment) => milliseconds >= segment.startedAtMs && milliseconds < segment.endedAtMs) ?? -1;
    if (active !== state.activeSegment) {
      state.activeSegment = active;
      document.querySelectorAll('[data-segment-row]').forEach((row) => row.classList.toggle('active', Number(row.dataset.segmentRow) === active));
    }
  }

  function playSegment(position) {
    const segment = state.resultSession?.segments.find((item) => item.position === position);
    if (!segment || !state.player) return;
    state.activeSegment = position;
    document.querySelectorAll('[data-segment-row]').forEach((row) => row.classList.toggle('active', Number(row.dataset.segmentRow) === position));
    state.player.playUntil = segment.endedAtMs / 1000;
    state.player.wavesurfer.setTime(segment.startedAtMs / 1000);
    state.player.wavesurfer.play();
  }

  function destroyPlayer() {
    state.player?.wavesurfer.destroy();
    state.player = null;
  }

  function setResultUrl(sessionId = null) {
    const url = new URL(window.location.href);
    if (sessionId === null) url.searchParams.delete('result');
    else url.searchParams.set('result', String(sessionId));
    window.history.replaceState(null, '', `${url.pathname}${url.search}${url.hash}`);
  }

  function navigate(screen) {
    stopAnalysisPolling();
    destroyPlayer();
    setResultUrl();
    state.screen = screen; state.filterOpen = false; state.search = ''; state.selectedCategories.clear();
    window.scrollTo(0, 0); render();
  }

  function editTraining(training) {
    state.editingTrainingId = training?.id || null;
    state.trainingName = training?.name || 'Новая тренировка';
    state.picked = new Set((training?.questions || []).map((question) => question.id));
    navigate('builder');
  }

  async function saveTraining() {
    const body = JSON.stringify({ name: state.trainingName.trim(), questionIds: [...state.picked] });
    try {
      const updating = state.editingTrainingId !== null;
      const data = await api(updating ? `/api/trainings/${state.editingTrainingId}` : '/api/trainings', { method: updating ? 'PUT' : 'POST', body });
      const index = state.trainings.findIndex((item) => item.id === data.training.id);
      if (index >= 0) state.trainings[index] = data.training; else state.trainings.unshift(data.training);
      state.editingTrainingId = data.training.id;
      showToast('Тренировка сохранена'); navigate('trainings');
    } catch (error) { showToast(error.message); }
  }

  async function startTraining(training) {
    destroyPlayer();
    setResultUrl();
    state.currentTraining = training; state.screen = 'requesting'; render();
    try {
      if (!navigator.mediaDevices?.getUserMedia || typeof MediaRecorder === 'undefined') throw new Error('Этот браузер не поддерживает запись аудио.');
      const stream = await navigator.mediaDevices.getUserMedia({ audio: true, video: false });
      const sessionData = await api(`/api/trainings/${training.id}/sessions`, { method: 'POST', body: '{}' });
      const preferred = ['audio/webm;codecs=opus', 'audio/ogg;codecs=opus', 'audio/mp4'].find((type) => MediaRecorder.isTypeSupported(type));
      const recorder = preferred ? new MediaRecorder(stream, { mimeType: preferred }) : new MediaRecorder(stream);
      state.stream = stream; state.recorder = recorder; state.audioChunks = []; state.sessionId = sessionData.session.id;
      state.sessionIndex = 0; state.seconds = 0; state.hintOpen = false; state.uploadSucceeded = false;
      state.resultSession = null; state.playerOpen = false; state.activeSegment = 0; state.sessionSegments = [];
      recorder.addEventListener('dataavailable', (event) => { if (event.data.size > 0) state.audioChunks.push(event.data); });
      recorder.start(1000);
      state.recordingStartedAt = performance.now();
      openSegment(0, 0);
      state.timer = window.setInterval(() => { state.seconds += 1; const node = document.querySelector('#session-time'); if (node) node.textContent = time(state.seconds); }, 1000);
      state.screen = 'session'; render();
    } catch (error) {
      stopTracks(); state.screen = 'trainings'; render();
      const denied = error?.name === 'NotAllowedError' ? 'Без доступа к микрофону запись не начнётся. Разрешите микрофон в настройках браузера.' : error.message;
      showToast(denied);
    }
  }

  function stopTracks() {
    if (state.timer) window.clearInterval(state.timer);
    state.timer = null;
    state.stream?.getTracks().forEach((track) => track.stop());
    state.stream = null;
  }

  async function stopRecorder() {
    const recorder = state.recorder;
    if (!recorder || recorder.state === 'inactive') return;
    await new Promise((resolve) => { recorder.addEventListener('stop', resolve, { once: true }); recorder.stop(); });
  }

  async function finishSession(cancelled) {
    document.querySelectorAll('.session-controls button,.exit-session').forEach((button) => { button.disabled = true; });
    try {
      const lastSegment = state.sessionSegments[state.sessionSegments.length - 1];
      const elapsedMilliseconds = Math.max(lastSegment?.endedAtMs || 0, recordingOffsetMs());
      state.seconds = Math.ceil(elapsedMilliseconds / 1000);
      await stopRecorder(); stopTracks();
      const mimeType = state.recorder?.mimeType || 'audio/webm';
      const blob = new Blob(state.audioChunks, { type: mimeType });
      if (blob.size > 0) {
        const form = new FormData(); form.append('audio', blob, 'answer.webm');
        await api(`/api/sessions/${state.sessionId}/audio`, { method: 'POST', body: form });
        state.uploadSucceeded = true;
      }
      const answeredCount = state.sessionSegments.filter((segment) => segment.status === 'completed').length;
      const completed = await api(`/api/sessions/${state.sessionId}/complete`, { method: 'POST', body: JSON.stringify({
        elapsedSeconds: state.seconds,
        answeredCount,
        cancelled,
        segments: state.sessionSegments,
      }) });
      state.resultSession = completed.session;
      const refreshed = await api('/api/trainings'); state.trainings = refreshed.trainings;
      state.screen = cancelled ? 'trainings' : 'complete';
      state.playerOpen = !cancelled && state.uploadSucceeded && completed.session.hasAudio;
      if (!cancelled) setResultUrl(completed.session.id);
      render();
      if (state.playerOpen) await initAudioPlayer();
      if (!cancelled) startAnalysisPolling();
      if (cancelled) showToast('Тренировка завершена, аудио сохранено');
    } catch (error) {
      stopTracks(); state.screen = cancelled ? 'trainings' : 'complete'; render(); showToast(error.message);
    } finally { state.recorder = null; state.audioChunks = []; state.recordingStartedAt = null; }
  }

  root.addEventListener('click', async (event) => {
    const target = event.target.closest('[data-action]');
    if (!target) return;
    const action = target.dataset.action;
    if (action === 'retry') return load();
    if (action === 'nav') return navigate(target.dataset.screen);
    if (action === 'category') { state.activeCategory = target.dataset.id; state.selectedCategories.clear(); state.screen = 'questions'; return render(); }
    if (action === 'toggle-filter') { state.filterOpen = !state.filterOpen; return render(); }
    if (action === 'apply-filter') { state.filterOpen = false; return render(); }
    if (action === 'remove-filter') { state.selectedCategories.delete(target.dataset.id); return render(); }
    if (action === 'clear-search') { state.search = ''; return render(); }
    if (action === 'reset-all') { state.search = ''; state.selectedCategories.clear(); state.activeCategory = 'all'; return render(); }
    if (action === 'sort-weak') { state.questions.sort((a, b) => a.memory - b.memory || a.id - b.id); return render(); }
    if (action === 'open-question') { state.activeQuestion = state.questions.find((question) => question.id === Number(target.dataset.id)); state.screen = 'detail'; window.scrollTo(0, 0); return render(); }
    if (action === 'add-question') { state.picked.add(Number(target.dataset.id)); state.screen = 'builder'; return render(); }
    if (action === 'new-training') return editTraining(null);
    if (action === 'quick-category') { state.selectedCategories.has(target.dataset.id) ? state.selectedCategories.delete(target.dataset.id) : state.selectedCategories.add(target.dataset.id); return render(); }
    if (action === 'save-training') return saveTraining();
    if (action === 'edit-training') return editTraining(state.trainings.find((item) => item.id === Number(target.dataset.id)));
    if (action === 'start-training') return startTraining(state.trainings.find((item) => item.id === Number(target.dataset.id)));
    if (action === 'toggle-hint') { state.hintOpen = !state.hintOpen; return render(); }
    if (action === 'session-next') {
      closeCurrentSegment('completed');
      if (state.sessionIndex < state.currentTraining.questions.length - 1) {
        const boundary = state.sessionSegments[state.sessionSegments.length - 1].endedAtMs;
        state.sessionIndex += 1; state.hintOpen = false; openSegment(state.sessionIndex, boundary); render();
      } else await finishSession(false);
      return;
    }
    if (action === 'session-exit') { closeCurrentSegment('interrupted'); return finishSession(true); }
    if (action === 'player-toggle' && state.player) {
      state.player.playUntil = null;
      return state.player.wavesurfer.playPause();
    }
    if (action === 'player-skip' && state.player) {
      state.player.playUntil = null;
      const next = Math.max(0, Math.min(state.player.wavesurfer.getDuration(), state.player.wavesurfer.getCurrentTime() + Number(target.dataset.seconds)));
      state.player.wavesurfer.setTime(next); return;
    }
    if (action === 'player-seek' && state.player) {
      state.player.playUntil = null;
      state.player.wavesurfer.setTime(Math.max(0, Number(target.dataset.ms) || 0) / 1000);
      return state.player.wavesurfer.play();
    }
    if (action === 'player-rate' && state.player) {
      const rates = [0.75, 1, 1.25, 1.5];
      state.player.rateIndex = (state.player.rateIndex + 1) % rates.length;
      state.player.wavesurfer.setPlaybackRate(rates[state.player.rateIndex]);
      target.textContent = `${rates[state.player.rateIndex]}×`; return;
    }
    if (action === 'play-segment') return playSegment(Number(target.dataset.position));
    if (action === 'retry-analysis') return retryAnalysis();
    if (action === 'complete-home') return navigate('trainings');
    if (action === 'again') return startTraining(state.currentTraining);
  });

  root.addEventListener('change', (event) => {
    const target = event.target;
    if (target.dataset.change === 'filter-category') { target.checked ? state.selectedCategories.add(target.value) : state.selectedCategories.delete(target.value); render(); }
    if (target.dataset.change === 'picked') { const id = Number(target.value); target.checked ? state.picked.add(id) : state.picked.delete(id); render(); }
    if (target.dataset.change === 'select-all') { const ids = visibleQuestions().map((question) => question.id); ids.forEach((id) => target.checked ? state.picked.add(id) : state.picked.delete(id)); render(); }
  });

  root.addEventListener('input', (event) => {
    if (event.target.dataset.input === 'search') { state.search = event.target.value; render(); document.querySelector('[data-input="search"]')?.focus(); }
    if (event.target.dataset.input === 'training-name') { state.trainingName = event.target.value; render(); const input = document.querySelector('[data-input="training-name"]'); input?.focus(); input?.setSelectionRange(state.trainingName.length, state.trainingName.length); }
  });

  document.addEventListener('keydown', (event) => {
    if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') { event.preventDefault(); document.querySelector('#question-search')?.focus(); }
    if ((event.key === 'Enter' || event.key === ' ') && event.target.matches('.question-card')) { event.preventDefault(); event.target.click(); }
  });
  window.addEventListener('beforeunload', () => { stopTracks(); stopAnalysisPolling(); });
  load();
})();
