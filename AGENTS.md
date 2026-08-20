# Interview Trainer — project instructions

## Product source of truth

- Read `interview-trainer-project-description.md` before changing product behavior.
- Use the visual language and user flows in `GUI/` as the design reference.
- The application records audio only. Never request camera, screen-capture, or video permissions and never add video storage.
- Keep all user-facing copy in Russian unless the task explicitly asks for another language.

## Architecture

- The deployable application lives at the repository root and uses Symfony 7.4, PHP 8.4, Doctrine ORM, PostgreSQL, Redis, Twig, and plain browser JavaScript/CSS.
- `GUI/` is a reference prototype. Do not introduce a second production backend or duplicate persistence there.
- Serve the interface and `/api/*` endpoints from the same Symfony origin.
- Store durable domain data in PostgreSQL. Store uploaded audio outside the public web root and expose downloads through a controlled Symfony endpoint.
- Treat request payloads and uploaded media as untrusted input. Validate IDs, strings, MIME types, file size, and state transitions.
- Local analysis uses replaceable `AsrProviderInterface` and `AnswerEvaluatorInterface` adapters. The default stack is host-side GigaAM-v3 `v3_e2e_rnnt`, then Ollama `qwen3.5:9b-q4_K_M`.
- Never keep both AI models loaded: the ASR service must unload GigaAM before responding, and only then may the Messenger worker invoke Qwen. Unload Qwen after a session evaluation.
- Detect repeated/looping model output, allow no more than three attempts per stage, and persist a stable error code plus a Russian user-facing error after the last attempt.
- The local chat keeps its history in the browser only. Voice messages are temporary uploads: unload Qwen before GigaAM, delete the audio after transcription, and require an explicit user action before sending the recognized text to Qwen.

## Frontend conventions

- Preserve the established responsive visual style, but replace all camera/video concepts with microphone/audio states.
- Request media with `navigator.mediaDevices.getUserMedia({ audio: true, video: false })` only after an explicit user action.
- Stop every media track when a session ends, exits, fails, or the page unloads.
- Keep keyboard focus, accessible names, touch targets, empty states, loading states, and error feedback usable.
- Do not render raw API data with `innerHTML`; build DOM safely or escape values.

## Backend conventions

- Use PHP attributes for Doctrine mapping and Symfony routing.
- Keep controllers thin; put reusable domain and upload behavior in services when it grows beyond request orchestration.
- Return consistent JSON errors with an `error` field and an appropriate HTTP status.
- Migrations must be deterministic and include any seed data required for a useful first run.
- Do not expose server filesystem paths in API responses.

## Verification

- Run syntax checks for changed PHP and JavaScript files.
- Run the focused PHPUnit suite for changed backend behavior, then the full suite when practical.
- Apply database migrations before manual browser QA.
- For interface changes, verify the desktop and narrow/mobile layouts and exercise the main flow: questions → builder → saved training → audio session → completion.
- Confirm that browser permission UI requests microphone access only.

## Skills

- Use `browser:control-in-app-browser` for visual and interactive browser QA when display or behavior must be verified.
- Use `sites:sites-building` only while working directly inside the `GUI/` reference Sites project or its `.openai/hosting.json`; do not migrate the Symfony backend into Sites.
- Use `sites:sites-hosting` only when the user explicitly requests publishing the `GUI/` Sites project.
- Use `imagegen` only when the user asks for a new raster visual or the interface genuinely requires an original bitmap asset; prefer existing CSS and assets otherwise.
- Use `openai-docs` only for OpenAI/Codex product or API questions, not ordinary application development.
- Do not use `roistat-phpunit-policy`: this repository is not a Roistat repository.

## Safety and repository hygiene

- Preserve unrelated user changes and never use destructive Git commands.
- Do not commit uploaded recordings, secrets, local databases, caches, or generated build output.
- Update this file when architectural or product constraints change.
