# Sobes Trainer

English · [Русский](README.md)

A local AI trainer for technical interviews. Sobes Trainer records audio only, transcribes answers with GigaAM, evaluates them with a local Qwen model, and presents actionable feedback alongside question-level timestamps in the original recording.

The project is designed primarily for personal use on Apple Silicon Macs. Recordings, transcripts, and answers are not sent to external paid APIs.

## Why Sobes Trainer

- **Private by default.** Audio, transcripts, and evaluations stay on the user's computer.
- **No API fees.** Both GigaAM and Qwen run locally.
- **Practice spoken answers.** The application analyzes content as well as pauses, speaking pace, and filler words.
- **Question-level playback.** A continuous recording is split by timestamps, so every answer can be selected directly on a WaveSurfer.js waveform.
- **Actionable feedback.** Qwen returns a structured evaluation across seven criteria, strengths, missing topics, and concrete next steps.
- **Memory-conscious inference.** GigaAM runs first and is unloaded before Qwen starts, so both models are never intentionally kept in memory together.
- **Resilient local inference.** Streaming output is checked for repetition and loops, with timeouts, up to three attempts, and clear UI errors.
- **Replaceable models.** ASR and answer evaluation use adapter interfaces, allowing models to be changed without redesigning the user flow or database.
- **Built-in local chat.** Talk to Qwen using text or dictate a prompt through GigaAM.

## Features

- question catalog with categories, difficulty, and learning progress;
- training builder with saved custom sessions;
- one continuous audio recording per attempt, without camera or video access;
- timed interview flow with keyboard controls;
- an always-visible result player with waveform, playback speed, and question timestamps;
- Russian speech recognition with GigaAM-v3 `v3_e2e_rnnt`, including words, pauses, and speech metrics;
- local Qwen3.5-9B 4-bit evaluation through Ollama;
- cached transcripts and resumable analysis without rerunning ASR;
- a separate local Qwen chat with text and voice input.

## Technology stack

- Symfony 7.4, PHP 8.4, and Twig;
- PostgreSQL 17 and Doctrine ORM;
- Redis and Symfony Messenger;
- plain browser JavaScript and CSS on the same application origin;
- WaveSurfer.js 7.x;
- FastAPI, GigaAM-v3, and FFmpeg;
- Ollama and Qwen3.5-9B Q4;
- Docker Compose and Nginx.

## Requirements

- macOS on Apple Silicon;
- Docker Desktop;
- Homebrew;
- at least 16 GB of unified memory; 24 GB is recommended;
- enough disk space for Docker images, the Python environment, and model weights.

The project has been tested on a MacBook Air M4 with 24 GB of memory. GigaAM can fall back from MPS to CPU, but performance on other hardware may vary.

## Quick start

Install system dependencies and download the models:

```bash
brew install ffmpeg uv ollama
make ai-install
make ai-pull
```

Start Ollama and GigaAM in separate terminal windows:

```bash
make ai-ollama
```

```bash
make ai-asr
```

Start the application and apply database migrations:

```bash
make up
make db-migrate
```

Available endpoints:

- application: [http://localhost:8080](http://localhost:8080);
- local chat: [http://localhost:8080/chat](http://localhost:8080/chat);
- Mailpit: [http://localhost:8025](http://localhost:8025);
- health check: [http://localhost:8080/health](http://localhost:8080/health).

The first transcription downloads GigaAM weights into the user cache and therefore takes longer than subsequent runs.

## Analysis pipeline

```text
audio
  ↓
GigaAM-v3 e2e_rnnt
  ↓ transcript, words, pauses, and speech metrics
unload GigaAM
  ↓
Qwen3.5-9B Q4 through Ollama
  ↓ structured scores and recommendations
unload Qwen
```

When an interview session ends, Symfony Messenger queues the analysis. Completed transcripts and valid evaluations are reused when the configured models have not changed. A failed or interrupted analysis can therefore resume without processing the audio again.

## Voice input in chat

The user explicitly starts and stops microphone recording. The temporary audio file is passed to GigaAM and deleted after transcription, including error paths. The recognized text is placed into the composer so it can be reviewed before it is sent to Qwen.

Chat history exists only in the open browser tab and is not stored in PostgreSQL.

## Data and privacy

- Camera, screen capture, and video permissions are never requested.
- Interview recordings are stored under `var/audio`, outside the public directory.
- Audio is served only through controlled Symfony routes.
- Server filesystem paths are never exposed by the API.
- Chat voice recordings are deleted immediately after transcription.
- No external paid AI API is used.

See [docs/data-model.md](docs/data-model.md) for the database schema and storage rules.

## Development commands

```bash
make test
make lint
docker compose exec php php bin/console doctrine:schema:validate
```

Local AI setup is documented in [local-ai/README.md](local-ai/README.md). Product constraints are described in [interview-trainer-project-description.md](interview-trainer-project-description.md) (Russian).

## License

Sobes Trainer is distributed under the [GNU General Public License v3.0](LICENSE).
