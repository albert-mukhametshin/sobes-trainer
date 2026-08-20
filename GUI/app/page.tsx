"use client";

import { useEffect, useMemo, useState } from "react";

type Screen = "questions" | "detail" | "builder" | "trainings" | "session" | "complete";
type Question = { id: number; category: string; title: string; level: string; memory: number; repeats: number };

const categoryMeta: Record<string, { name: string; color: string; mark: string }> = {
  php: { name: "PHP", color: "#7168a8", mark: "P" },
  symfony: { name: "Symfony", color: "#171717", mark: "S" },
  mysql: { name: "MySQL", color: "#e48a28", mark: "M" },
  oop: { name: "ООП", color: "#3f8065", mark: "O" },
  architecture: { name: "Архитектура", color: "#bb564e", mark: "A" },
  testing: { name: "Тестирование", color: "#397f9c", mark: "T" },
};

const questions: Question[] = [
  { id: 1, category: "php", title: "Чем отличаются require, include и их варианты с _once?", level: "Базовый", memory: 92, repeats: 4 },
  { id: 2, category: "symfony", title: "Как устроен жизненный цикл HTTP-запроса в Symfony?", level: "Средний", memory: 68, repeats: 3 },
  { id: 3, category: "mysql", title: "Когда индекс не будет использован оптимизатором MySQL?", level: "Средний", memory: 41, repeats: 2 },
  { id: 4, category: "oop", title: "В чём разница между композицией и наследованием?", level: "Базовый", memory: 84, repeats: 5 },
  { id: 5, category: "architecture", title: "Какие границы ответственности у Repository и Service?", level: "Продвинутый", memory: 22, repeats: 1 },
  { id: 6, category: "php", title: "Что такое позднее статическое связывание?", level: "Средний", memory: 57, repeats: 2 },
  { id: 7, category: "symfony", title: "Как работает контейнер зависимостей и autowiring?", level: "Средний", memory: 73, repeats: 4 },
  { id: 8, category: "testing", title: "Что именно проверяет интеграционный тест?", level: "Базовый", memory: 35, repeats: 1 },
];

const categories = [
  { id: "all", name: "Все вопросы", count: 128, color: "#211a1d", mark: "⌘" },
  ...Object.entries(categoryMeta).map(([id, value], index) => ({ id, ...value, count: [34, 28, 22, 18, 15, 11][index] })),
];

function memoryLabel(value: number) { return value >= 80 ? "Отлично" : value >= 50 ? "Нужно повторить" : "Слабое место"; }

export default function Home() {
  const [screen, setScreen] = useState<Screen>("questions");
  const [activeCategory, setActiveCategory] = useState("all");
  const [selectedCategories, setSelectedCategories] = useState<string[]>([]);
  const [search, setSearch] = useState("");
  const [filterOpen, setFilterOpen] = useState(false);
  const [activeQuestion, setActiveQuestion] = useState(questions[2]);
  const [trainingName, setTrainingName] = useState("PHP Backend — пробное собеседование");
  const [picked, setPicked] = useState<number[]>([2, 3, 5]);
  const [sessionIndex, setSessionIndex] = useState(0);
  const [seconds, setSeconds] = useState(0);
  const [recording, setRecording] = useState(false);

  useEffect(() => {
    if (screen !== "session" || !recording) return;
    const timer = window.setInterval(() => setSeconds((value) => value + 1), 1000);
    return () => window.clearInterval(timer);
  }, [screen, recording]);

  const visible = useMemo(() => {
    const query = search.trim().toLocaleLowerCase("ru");
    const filter = selectedCategories.length ? selectedCategories : activeCategory === "all" ? [] : [activeCategory];
    return questions.filter((question) => {
      const cat = categoryMeta[question.category].name.toLocaleLowerCase("ru");
      return (!filter.length || filter.includes(question.category)) && (!query || question.title.toLocaleLowerCase("ru").includes(query) || cat.includes(query));
    });
  }, [search, selectedCategories, activeCategory]);

  const trainingQuestions = picked.length ? questions.filter((question) => picked.includes(question.id)) : questions.slice(0, 3);
  const currentSessionQuestion = trainingQuestions[sessionIndex] ?? trainingQuestions[0];
  const time = `${String(Math.floor(seconds / 60)).padStart(2, "0")}:${String(seconds % 60).padStart(2, "0")}`;

  const openQuestion = (question: Question) => { setActiveQuestion(question); setScreen("detail"); window.scrollTo(0, 0); };
  const startSession = () => { setSessionIndex(0); setSeconds(0); setRecording(true); setScreen("session"); };
  const nextQuestion = () => {
    if (sessionIndex >= trainingQuestions.length - 1) { setRecording(false); setScreen("complete"); }
    else setSessionIndex((value) => value + 1);
  };

  if (screen === "session") return <Session question={currentSessionQuestion} index={sessionIndex} total={trainingQuestions.length} time={time} onNext={nextQuestion} onExit={() => setScreen("trainings")} />;
  if (screen === "complete") return <Complete time={time} total={trainingQuestions.length} onHome={() => setScreen("trainings")} onAgain={startSession} />;

  return (
    <div className="app-shell">
      <Header screen={screen} onNavigate={setScreen} onCreate={() => setScreen("builder")} />
      <main className="workspace">
        <Sidebar active={activeCategory} onChange={(id) => { setActiveCategory(id); setSelectedCategories([]); setScreen("questions"); }} />
        {screen === "questions" && (
          <section className="content">
            <div className="content-head">
              <div><p className="breadcrumb">База знаний&nbsp; / &nbsp;Все вопросы</p><h1>Вопросы</h1><p className="subtitle">Находите слабые места и превращайте их в уверенные ответы.</p></div>
              <div className="head-stat"><span>71%</span><small>среднее запоминание</small></div>
            </div>
            <div className="toolbar">
              <label className="search-field"><i /><input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Поиск по вопросам и категориям" />{search && <button onClick={() => setSearch("")}>×</button>}<kbd>⌘ K</kbd></label>
              <div className="filter-wrap">
                <button className={`filter-button ${selectedCategories.length ? "has-filter" : ""}`} onClick={() => setFilterOpen(!filterOpen)}><i />Категории{selectedCategories.length > 0 && <b>{selectedCategories.length}</b>}<span>⌄</span></button>
                {filterOpen && <CategoryFilter selected={selectedCategories} onToggle={(id) => setSelectedCategories((items) => items.includes(id) ? items.filter((item) => item !== id) : [...items, id])} onReset={() => setSelectedCategories([])} onClose={() => setFilterOpen(false)} />}
              </div>
            </div>
            {selectedCategories.length > 0 && <div className="filter-chips">{selectedCategories.map((id) => <button key={id} onClick={() => setSelectedCategories((items) => items.filter((item) => item !== id))}>{categoryMeta[id].name}<span>×</span></button>)}</div>}
            <div className="list-meta"><span>Найдено: <strong>{visible.length}</strong></span><button>Сначала слабые ↓</button></div>
            <div className="question-list">
              {visible.map((question, index) => <QuestionCard key={question.id} question={question} index={index} onOpen={() => openQuestion(question)} />)}
              {!visible.length && <div className="empty-state"><span>?</span><h2>Ничего не нашли</h2><p>Измените запрос или сбросьте фильтры.</p><button onClick={() => { setSearch(""); setSelectedCategories([]); }}>Сбросить</button></div>}
            </div>
          </section>
        )}
        {screen === "detail" && <QuestionDetail question={activeQuestion} onBack={() => setScreen("questions")} onAdd={() => { setPicked((ids) => ids.includes(activeQuestion.id) ? ids : [...ids, activeQuestion.id]); setScreen("builder"); }} />}
        {screen === "builder" && <Builder name={trainingName} setName={setTrainingName} visible={visible} picked={picked} setPicked={setPicked} search={search} setSearch={setSearch} selectedCategories={selectedCategories} setSelectedCategories={setSelectedCategories} onReady={() => setScreen("trainings")} />}
        {screen === "trainings" && <Trainings name={trainingName} total={trainingQuestions.length} onPlay={startSession} onEdit={() => setScreen("builder")} />}
      </main>
    </div>
  );
}

function Header({ screen, onNavigate, onCreate }: { screen: Screen; onNavigate: (screen: Screen) => void; onCreate: () => void }) {
  return <header className="topbar">
    <button className="brand" onClick={() => onNavigate("questions")}><span className="brand-mark"><i /></span>Готово<span>.</span></button>
    <nav><button className={screen === "questions" || screen === "detail" ? "active" : ""} onClick={() => onNavigate("questions")}>База вопросов</button><button className={screen === "trainings" || screen === "builder" ? "active" : ""} onClick={() => onNavigate("trainings")}>Мои тренинги</button><button>Прогресс</button></nav>
    <div className="top-actions"><button className="new-training" onClick={onCreate}>＋ <span>Создать тренинг</span></button><button className="avatar">АК</button></div>
  </header>;
}

function Sidebar({ active, onChange }: { active: string; onChange: (id: string) => void }) {
  return <aside className="sidebar"><div className="sidebar-heading"><p>Категории</p><span>7</span></div><div className="category-list">{categories.map((category) => <button key={category.id} className={active === category.id ? "active" : ""} onClick={() => onChange(category.id)}><i style={{ background: category.color }}>{category.mark}</i><span>{category.name}</span><small>{category.count}</small></button>)}</div><div className="sidebar-note"><span>↗</span><p><strong>12 вопросов</strong><br />пора повторить сегодня</p></div></aside>;
}

function CategoryFilter({ selected, onToggle, onReset, onClose }: { selected: string[]; onToggle: (id: string) => void; onReset: () => void; onClose: () => void }) {
  return <div className="filter-popover"><div><strong>Выберите категории</strong><button onClick={onReset}>Сбросить</button></div>{categories.slice(1).map((category) => <label key={category.id}><input type="checkbox" checked={selected.includes(category.id)} onChange={() => onToggle(category.id)} /><b>✓</b><i style={{ background: category.color }} /><span>{category.name}</span><small>{category.count}</small></label>)}<button className="apply-filter" onClick={onClose}>Показать вопросы</button></div>;
}

function QuestionCard({ question, index, onOpen }: { question: Question; index: number; onOpen: () => void }) {
  const cat = categoryMeta[question.category];
  return <article className="question-card" style={{ "--delay": `${index * 45}ms` } as React.CSSProperties} onClick={onOpen}><span className="question-index">{String(question.id).padStart(2, "0")}</span><div className="question-main"><div className="question-tags"><span><i style={{ background: cat.color }} />{cat.name}</span><b>{question.level}</b></div><h2>{question.title}</h2><p>Повторений: {question.repeats}</p></div><div className={`memory m-${question.memory >= 80 ? "high" : question.memory >= 50 ? "mid" : "low"}`}><div><span>{memoryLabel(question.memory)}</span><strong>{question.memory}%</strong></div><i><b style={{ width: `${question.memory}%` }} /></i></div><button className="open-question">↗</button></article>;
}

function QuestionDetail({ question, onBack, onAdd }: { question: Question; onBack: () => void; onAdd: () => void }) {
  const cat = categoryMeta[question.category];
  return <section className="detail-screen"><button className="back-link" onClick={onBack}>← Все вопросы</button><div className="detail-layout"><article className="detail-card"><div className="question-tags"><span><i style={{ background: cat.color }} />{cat.name}</span><b>{question.level}</b></div><p className="detail-number">Вопрос {String(question.id).padStart(2, "0")}</p><h1>{question.title}</h1><div className="answer-hint"><span>Как отвечать</span><p>Начните с определения, затем объясните механизм на примере и обязательно назовите практические ограничения.</p></div><button className="primary" onClick={onAdd}>＋ Добавить в тренинг</button></article><aside className="memory-panel"><p>Качество запоминания</p><strong>{question.memory}%</strong><div className="big-progress"><i style={{ width: `${question.memory}%` }} /></div><span>{memoryLabel(question.memory)}</span><hr /><small>Последний ответ</small><b>2 дня назад</b><small>Всего повторений</small><b>{question.repeats}</b><button>Оценить заново</button></aside></div></section>;
}

function Builder({ name, setName, visible, picked, setPicked, search, setSearch, selectedCategories, setSelectedCategories, onReady }: { name: string; setName: (v: string) => void; visible: Question[]; picked: number[]; setPicked: React.Dispatch<React.SetStateAction<number[]>>; search: string; setSearch: (v: string) => void; selectedCategories: string[]; setSelectedCategories: React.Dispatch<React.SetStateAction<string[]>>; onReady: () => void }) {
  const allVisiblePicked = visible.length > 0 && visible.every((q) => picked.includes(q.id));
  const toggleAll = () => setPicked((ids) => allVisiblePicked ? ids.filter((id) => !visible.some((q) => q.id === id)) : Array.from(new Set([...ids, ...visible.map((q) => q.id)])));
  return <section className="builder-screen"><div className="builder-head"><div><p className="breadcrumb">Конструктор тренинга</p><h1>Соберите свой маршрут</h1><p className="subtitle">Выберите вопросы, которые хотите отработать.</p></div><button className="primary" disabled={!picked.length || !name.trim()} onClick={onReady}>Сохранить тренинг <span>→</span></button></div><label className="training-name"><span>Название тренинга</span><input value={name} onChange={(e) => setName(e.target.value)} placeholder="Например, Подготовка к Senior PHP" /><small>{name.length}/60</small></label><div className="builder-grid"><div><div className="builder-toolbar"><label className="search-field"><i /><input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Найти вопрос" /></label><div className="quick-cats">{categories.slice(1,5).map((cat) => <button className={selectedCategories.includes(cat.id) ? "active" : ""} key={cat.id} onClick={() => setSelectedCategories((ids) => ids.includes(cat.id) ? ids.filter((id) => id !== cat.id) : [...ids, cat.id])}>{cat.name}</button>)}</div></div><div className="select-all"><label><input type="checkbox" checked={allVisiblePicked} onChange={toggleAll} /> <span>Выбрать все на экране</span></label><small>{visible.length} вопросов</small></div><div className="pick-list">{visible.map((q) => <label key={q.id} className={picked.includes(q.id) ? "picked" : ""}><input type="checkbox" checked={picked.includes(q.id)} onChange={() => setPicked((ids) => ids.includes(q.id) ? ids.filter((id) => id !== q.id) : [...ids, q.id])} /><b>✓</b><span><small>{categoryMeta[q.category].name} · {q.level}</small>{q.title}</span><em>{q.memory}%</em></label>)}</div></div><aside className="selection-summary"><span className="summary-kicker">Ваш тренинг</span><strong>{picked.length}</strong><p>вопроса выбрано</p><div><span>≈ {picked.length * 3} мин</span><span>{new Set(questions.filter(q => picked.includes(q.id)).map(q => q.category)).size} категории</span></div><p className="summary-note">Можно выделить все вопросы, которые сейчас видны после поиска и фильтрации.</p></aside></div></section>;
}

function Trainings({ name, total, onPlay, onEdit }: { name: string; total: number; onPlay: () => void; onEdit: () => void }) {
  return <section className="trainings-screen"><p className="breadcrumb">Практика</p><h1>Мои тренинги</h1><p className="subtitle">Репетируйте собеседование в условиях, близких к реальным.</p><article className="training-card"><div className="training-cover"><span>PHP</span><i /><i /><i /></div><div className="training-info"><span className="ready-pill">Готов к запуску</span><h2>{name || "PHP Backend — пробное собеседование"}</h2><p>{total} вопросов · примерно {total * 3} минут · PHP / Symfony / MySQL</p><div><button className="play-button" onClick={onPlay}><span>▶</span> Начать тренировку</button><button className="secondary" onClick={onEdit}>Редактировать</button></div></div><div className="training-score"><small>Лучший результат</small><strong>—</strong><span>ещё не пройден</span></div></article><div className="recording-note"><span>●</span><div><strong>Перед началом</strong><p>Браузер запросит разрешение на камеру и демонстрацию экрана. Запись помогает оценить речь, темп и уверенность ответа.</p></div></div></section>;
}

function Session({ question, index, total, time, onNext, onExit }: { question: Question; index: number; total: number; time: string; onNext: () => void; onExit: () => void }) {
  const progress = ((index + 1) / total) * 100;
  return <div className="session-screen"><header><button className="brand session-brand"><span className="brand-mark"><i /></span>Готово<span>.</span></button><div className="recording"><i /> REC <strong>{time}</strong></div><button className="exit-session" onClick={onExit}>Завершить ×</button></header><div className="session-progress"><i style={{ width: `${progress}%` }} /></div><main><div className="session-meta"><span>Вопрос {index + 1} из {total}</span><b>{Math.round(progress)}% пройдено</b></div><section className="session-question"><div className="live-camera"><div className="camera-person"><i /><span /></div><small><i /> Камера включена</small></div><div className="prompt"><p>{categoryMeta[question.category].name} · {question.level}</p><h1>{question.title}</h1><div className="speaking-tip"><span>≈</span><p><strong>Отвечайте вслух</strong><br />Говорите так, будто перед вами интервьюер. Оптимально — 2–3 минуты.</p></div></div></section><div className="session-controls"><button className="hint-button">Подсказка</button><p>Экран и камера записываются</p><button className="next-button" onClick={onNext}>{index === total - 1 ? "Завершить тренинг" : "Следующий вопрос"} <span>→</span></button></div></main></div>;
}

function Complete({ time, total, onHome, onAgain }: { time: string; total: number; onHome: () => void; onAgain: () => void }) {
  return <div className="complete-screen"><div className="confetti">{Array.from({ length: 26 }).map((_, i) => <i key={i} style={{ "--i": i } as React.CSSProperties} />)}</div><div className="complete-card"><div className="trophy">✓</div><p className="completion-kicker">Тренинг завершён</p><h1>Отличная работа!</h1><p>Вы прошли пробное собеседование. Запись готова — пересмотрите ответы и отметьте, что стоит улучшить.</p><div className="result-stats"><div><strong>{total}/{total}</strong><span>вопросов</span></div><div><strong>{time}</strong><span>время</span></div><div><strong>100%</strong><span>пройдено</span></div></div><div className="complete-actions"><button className="primary" onClick={onHome}>К результатам →</button><button className="secondary" onClick={onAgain}>Пройти ещё раз</button></div></div></div>;
}
