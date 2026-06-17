# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Commands

```bash
# Install dependencies
composer install

# Start local services (postgres + redis)
docker compose up -d

# Database setup
php bin/console doctrine:migrations:migrate
php bin/console doctrine:fixtures:load

# Generate JWT keypair (first-time setup)
php bin/console lexik:jwt:generate-keypair

# Start dev server
symfony server:start

# Start async worker (separate terminal)
php bin/console messenger:consume async -vv

# Run all tests
vendor/bin/phpunit
# or
composer test

# Run a single test file or filter
vendor/bin/phpunit tests/Unit/Service/AI/AITutorServiceTest.php
vendor/bin/phpunit --filter testMethodName

# Tests with HTML coverage report
vendor/bin/phpunit --coverage-html coverage/

# Static analysis (PHPStan level 8)
vendor/bin/phpstan analyse src tests --level 8
# or
composer stan

# Check code style (dry-run)
vendor/bin/php-cs-fixer fix --dry-run --diff
# or
composer lint

# Fix code style
vendor/bin/php-cs-fixer fix
```

## Architecture

### Two-Service ECS Setup

The application runs as two separate ECS Fargate services built from the same codebase:

- **App service** — nginx + php-fpm, handles HTTP requests (2–20 autoscaled tasks)
- **Worker service** — Symfony Messenger consumer (`messenger:consume async`), processes all async AI jobs (2–10 tasks)

### Async Message Pipeline (SQS FIFO)

Heavy AI operations are dispatched as Messenger messages and processed by the worker service. All message routing is in `config/packages/messenger.yaml`. Current async messages:

| Message | Purpose |
|---|---|
| `ConvertDocumentToCourseMessage` | TalentCraft 2.0 pipeline (PDF/DOCX → course) |
| `SendRetentionNudgeMessage` | Personalised at-risk learner emails via SES |
| `GenerateMicroModuleMessage` | Auto-generated micro-modules from quiz gaps |
| `IndexContentMessage` | Embed and persist content chunks for RAG |
| `GenerateVoiceoverMessage` | Voiceover script generation per lesson |

Queue is SQS FIFO with 3 retries (exponential backoff 1s→2s→4s, max 30s), dead-letter on the `failed` transport.

### RAG / Smart Search Pipeline

`SmartSearchService` uses PostgreSQL `pgvector` (not a separate vector DB) for semantic search:

1. Query → `text-embedding-3-large` embedding
2. `ContentChunkRepository::findSimilar()` — pgvector cosine similarity, top-8 chunks
3. Chunks → GPT-4o for grounded answer generation (temperature 0.2)

Content is indexed by calling `SmartSearchService::indexContent()`, which splits text into 512-word chunks with 64-word overlap and embeds each chunk individually.

### AI Provider Abstraction

All AI services depend on `App\AI\AIClientInterface` (two methods: `chat()` and `embed()`), injected via DI. The concrete adapter is selected at boot time from the `AI_PROVIDER` env var:

| `AI_PROVIDER` | Adapter | Embeddings |
|---|---|---|
| `openai` | `OpenAIAdapter` — wraps `openai-php/client` | Native |
| `ollama` | `OpenAIAdapter` — same client, custom base URL | Native (model-dependent) |
| `claude` | `AnthropicAdapter` — HTTP to `api.anthropic.com/v1/messages` | Not supported — throws |
| `gemini` | `GeminiAdapter` — OpenAI client pointed at Gemini's compatible endpoint | Native |
| `bedrock` | `BedrockAdapter` — AWS SDK Converse API; InvokeModel for Titan embeddings | Titan Embed V2 |

`AIClientFactory::create()` in `src/Factory/AIClientFactory.php` builds the adapter. The model string is set via `AI_MODEL` env var and bound globally in `config/services.yaml`. `SentimentAnalysisService` is a dependency of `AITutorService` — it runs after session completion.

> **Note:** When `AI_PROVIDER=claude`, `SmartSearchService` will throw at runtime because Anthropic has no embeddings API. Use `openai`, `gemini`, or `bedrock` when smart search is enabled.

### Feature Overview

#### AI Tutor / AI Coach (`AITutorService`)
Conversational coaching and role-play simulations for learners. Equivalent to simplelms's native AI Coach feature but provider-agnostic.

- `MODE_CHAT` — Socratic coaching, adapts to learner level
- `MODE_ROLEPLAY` — Persona-driven simulations (e.g. difficult customer, job interview); tension escalates/de-escalates based on learner responses
- Sessions persist full message history in `AITutorSession`
- On completion: `SentimentAnalysisService` scores empathy/clarity/confidence/professionalism; `generateCoachingFeedback()` produces strengths, improvement areas, and a recommended next module

#### Smart Search (`SmartSearchService`)
RAG-powered semantic search across all LMS content (PDFs, video transcripts, SCORM, policies). Returns a grounded answer with timestamped source links. Content is indexed via `indexContent()` which chunks text into 512-word windows with 64-word overlap.

#### TalentCraft 2.0 — Document to Course (`DocumentToCourseService`)
Converts PDF, DOCX, or TXT files into fully structured courses. Pipeline: S3 download → text extraction → GPT analysis of learning objectives → per-chunk module/lesson/quiz generation → `CourseBuilderService`. Each module includes HTML content, a TTS-ready voiceover script, key concepts, and a quiz with explanations.

#### Micro-Module Generator (`MicroModuleGeneratorService`)
Triggered when a learner scores below 60% on a topic across ≥2 attempts. Generates a ~30-second personalised micro-module targeting the exact misconception (not a full topic overview), tailored by `skill_level` and `learning_style` from the learner's `aiPersonalizationProfile`.

#### Predictive Retention Engine (`PredictiveRetentionService`)
Scores learners on 9 behavioural signals (login frequency, completion rate, overdue courses, quiz scores, AI tutor activity, etc.) and identifies at-risk users (score ≥ 0.65). Runs nightly via `RetentionSweepCommand` (EventBridge → ECS). At-risk users trigger `SendRetentionNudgeMessage` → SES email + in-app notification.

#### Skill-Gap Heat Map (`SkillGapMappingService`)
Extracts required skill proficiency levels from job descriptions via GPT, aggregates current employee skills, and produces a per-skill gap analysis (`low / medium / high / critical`) with training recommendations. Results cached 4 hours; force-refresh via `?refresh=true`.

### SSE Streaming

`AITutorController` streams chat responses using Server-Sent Events (no WebSocket infrastructure). The `?stream=1` query param enables streaming in the controller; SSE works natively through the ALB.

### Authentication

Stateless JWT via `lexik/jwt-authentication-bundle`. Login at `POST /api/v1/auth/login` (email + password). All `/api/v1/admin/*` routes require `ROLE_ADMIN`; all other `/api/v1/*` routes require `ROLE_USER`. Health check endpoint is public.

### Feature Flags

Four boolean env vars gate AI features at the service level:

```
FEATURE_AI_TUTOR, FEATURE_SMART_SEARCH, FEATURE_PREDICTIVE_RETENTION, FEATURE_DOCUMENT_TO_COURSE
```

These are injected as `app.feature.*` parameters (see `config/services.yaml`).

### Nightly Retention Sweep

`RetentionSweepCommand` is triggered by AWS EventBridge at 02:00 UTC as a one-off ECS task. It scores learners on 9 behavioural signals via `PredictiveRetentionService`, then dispatches `SendRetentionNudgeMessage` for at-risk users.

### Skill-Gap Heat Map Caching

`SkillGapMappingService` is expensive (cross-references job descriptions against employee skills via GPT). Results are cached for 4 hours. Force refresh via `?refresh=true` on `GET /api/v1/admin/analytics/skill-gaps`.

## Local Environment

Copy `.env` to `.env.local` and set: `DATABASE_URL`, `REDIS_URL`, `AI_PROVIDER`, `AI_MODEL`, and the API key for your chosen provider (`OPENAI_API_KEY`, `ANTHROPIC_API_KEY`, `GEMINI_API_KEY`, or AWS credentials for Bedrock). The other AWS env vars are only needed if testing AWS-backed features locally; SQS/S3/SES calls will fail gracefully in tests.

In CI, provider API keys are set to fake values — services using `AIClientInterface` must be mocked in unit tests.

## CI/CD

`.github/workflows/ci-cd.yml` runs on push to `main`/`develop`:

1. **test** — PHPStan level 8, php-cs-fixer dry-run, PHPUnit with Postgres + Redis services
2. **build** (main only) — builds two Docker images (`app` + `worker`) and pushes to ECR
3. **deploy** (main only) — runs DB migrations as a one-off ECS task, then rolling-updates both ECS services