(() => {
  'use strict';

  const messagesNode = document.querySelector('#chat-messages');
  const form = document.querySelector('#chat-form');
  const input = document.querySelector('#chat-input');
  const sendButton = document.querySelector('#send-button');
  const voiceButton = document.querySelector('#voice-button');
  const voiceTime = document.querySelector('#voice-time');
  const statusNode = document.querySelector('#chat-status');
  const newChatButton = document.querySelector('#new-chat');
  const history = [];
  let recorder = null;
  let stream = null;
  let chunks = [];
  let recordingStartedAt = 0;
  let recordingTimer = null;

  function setStatus(message, busy = false) {
    statusNode.textContent = message;
    statusNode.classList.toggle('busy', busy);
  }

  function addMessage(role, content, error = false) {
    const article = document.createElement('article');
    article.className = `chat-message ${role}${error ? ' error' : ''}`;
    const avatar = document.createElement('span');
    avatar.className = 'message-avatar';
    avatar.textContent = role === 'user' ? 'В' : error ? '!' : 'Q';
    const body = document.createElement('div');
    const meta = document.createElement('small');
    meta.textContent = role === 'user' ? 'Вы' : error ? 'Ошибка' : 'Qwen · локально';
    const text = document.createElement('p');
    text.textContent = content;
    body.append(meta, text);
    article.append(avatar, body);
    messagesNode.append(article);
    messagesNode.scrollTop = messagesNode.scrollHeight;
  }

  async function request(url, options) {
    const response = await fetch(url, options);
    const data = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(data.error || 'Локальный AI-сервис недоступен.');
    return data;
  }

  async function sendMessage() {
    const content = input.value.trim();
    if (!content) return;
    history.push({ role: 'user', content });
    addMessage('user', content);
    input.value = '';
    input.disabled = true;
    sendButton.disabled = true;
    voiceButton.disabled = true;
    setStatus('Qwen готовит ответ…', true);
    try {
      const data = await request('/api/chat/messages', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({ messages: history.slice(-20) }),
      });
      history.push({ role: 'assistant', content: data.answer });
      addMessage('assistant', data.answer);
      setStatus(`Ответ получен с попытки ${data.attempts}.`);
    } catch (error) {
      history.pop();
      addMessage('assistant', error.message, true);
      input.value = content;
      setStatus('Не удалось получить ответ. Сообщение можно отправить повторно.');
    } finally {
      input.disabled = false;
      sendButton.disabled = false;
      voiceButton.disabled = false;
      input.focus();
    }
  }

  function stopTracks() {
    if (stream) stream.getTracks().forEach((track) => track.stop());
    stream = null;
  }

  function updateVoiceTimer() {
    const seconds = Math.floor((performance.now() - recordingStartedAt) / 1000);
    voiceTime.textContent = `${String(Math.floor(seconds / 60)).padStart(2, '0')}:${String(seconds % 60).padStart(2, '0')}`;
    if (seconds >= 180 && recorder?.state === 'recording') recorder.stop();
  }

  async function transcribe(blob, durationMs) {
    const data = new FormData();
    data.append('audio', blob, 'voice-message.webm');
    data.append('durationMs', String(durationMs));
    input.disabled = true;
    sendButton.disabled = true;
    voiceButton.disabled = true;
    setStatus('GigaAM расшифровывает голосовое сообщение…', true);
    try {
      const result = await request('/api/chat/transcribe', { method: 'POST', headers: { Accept: 'application/json' }, body: data });
      input.value = result.text;
      setStatus(result.text ? 'Расшифровка готова — проверьте текст и отправьте.' : 'Речь не распознана. Попробуйте записать ещё раз.');
    } catch (error) {
      setStatus(error.message);
    } finally {
      input.disabled = false;
      sendButton.disabled = false;
      voiceButton.disabled = false;
      input.focus();
    }
  }

  async function toggleVoice() {
    if (recorder?.state === 'recording') {
      recorder.stop();
      return;
    }
    try {
      stream = await navigator.mediaDevices.getUserMedia({ audio: true, video: false });
      chunks = [];
      const mimeType = ['audio/webm;codecs=opus', 'audio/webm', 'audio/ogg;codecs=opus'].find((type) => MediaRecorder.isTypeSupported(type));
      recorder = new MediaRecorder(stream, mimeType ? { mimeType } : undefined);
      recorder.addEventListener('dataavailable', (event) => { if (event.data.size) chunks.push(event.data); });
      recorder.addEventListener('stop', async () => {
        const durationMs = Math.max(250, Math.round(performance.now() - recordingStartedAt));
        window.clearInterval(recordingTimer);
        voiceButton.classList.remove('recording');
        voiceButton.setAttribute('aria-pressed', 'false');
        voiceButton.innerHTML = '<span>●</span> Голосовой ввод';
        voiceTime.hidden = true;
        stopTracks();
        const blob = new Blob(chunks, { type: recorder.mimeType || 'audio/webm' });
        recorder = null;
        if (blob.size) await transcribe(blob, durationMs);
      }, { once: true });
      recorder.start(250);
      recordingStartedAt = performance.now();
      voiceButton.classList.add('recording');
      voiceButton.setAttribute('aria-pressed', 'true');
      voiceButton.innerHTML = '<span>●</span> Остановить запись';
      voiceTime.hidden = false;
      updateVoiceTimer();
      recordingTimer = window.setInterval(updateVoiceTimer, 500);
      setStatus('Идёт запись. Нажмите кнопку ещё раз, чтобы завершить.');
    } catch (error) {
      stopTracks();
      setStatus(error.name === 'NotAllowedError' ? 'Разрешите доступ к микрофону в браузере.' : 'Не удалось начать запись с микрофона.');
    }
  }

  form.addEventListener('submit', (event) => { event.preventDefault(); sendMessage(); });
  input.addEventListener('keydown', (event) => {
    if (event.key === 'Enter' && !event.shiftKey) { event.preventDefault(); form.requestSubmit(); }
  });
  voiceButton.addEventListener('click', toggleVoice);
  newChatButton.addEventListener('click', () => {
    history.length = 0;
    messagesNode.querySelectorAll('.chat-message:not(:first-child)').forEach((node) => node.remove());
    input.value = '';
    setStatus('Новый диалог начат.');
    input.focus();
  });
  window.addEventListener('beforeunload', () => { if (recorder?.state === 'recording') recorder.stop(); stopTracks(); });
})();
