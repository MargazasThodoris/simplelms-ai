# TalentLMS AI Platform
### Agentic AI-Powered Learning Management System — Symfony 7.2 / PHP 8.3 on AWS

> *"The best LMS is the one that manages itself."*

---

## Architecture Overview

```
┌──────────────────────────────────────────────────────────────────┐
│                          CloudFront CDN                          │
└─────────────────────────────┬────────────────────────────────────┘
                              │
┌─────────────────────────────▼────────────────────────────────────┐
│                    Application Load Balancer                      │
│                      (HTTPS / TLS 1.3)                           │
└───────────┬──────────────────────────────────────────────────────┘
            │
┌───────────▼──────────────────────────────────────────────────────┐
│              ECS Fargate Cluster (eu-west-1, 3 AZs)              │
│                                                                   │
│  ┌─────────────────────┐    ┌──────────────────────────────────┐ │
│  │   App Service        │    │   Worker Service                 │ │
│  │  (nginx + php-fpm)   │    │  (Symfony Messenger consumer)    │ │
│  │   2–20 tasks (ASG)   │    │  2–10 tasks                      │ │
│  └─────────────────────┘    └──────────────────────────────────┘ │
└──────────────┬────────────────────────┬─────────────────────────┘
               │                        │
   ┌───────────▼───┐            ┌───────▼───────────────┐
   │  RDS Aurora   │            │  ElastiCache Redis     │
   │  PostgreSQL   │            │  (sessions, cache,     │
   │  (Multi-AZ)   │            │   rate limiting)       │
   └───────────────┘            └───────────────────────┘
               │
   ┌───────────▼──────────────────────────────────────────┐
   │  AWS Services                                         │
   │  ├── S3           (media, documents, transcripts)     │
   │  ├── SQS FIFO     (async job queue)                   │
   │  ├── SES          (transactional email)               │
   │  ├── Transcribe   (video → transcript indexing)       │
   │  ├── EventBridge  (nightly scheduled tasks)           │
   │  └── Secrets Mgr  (credentials)                       │
   └──────────────────────────────────────────────────────┘
               │
   ┌───────────▼──────────────────────────────────────────┐
   │  External APIs                                        │
   │  └── OpenAI (GPT-4o, text-embedding-3-large)         │
   └──────────────────────────────────────────────────────┘
```

---

## AI Features

### 1. 🤖 24/7 AI Tutor & Role-Play Simulator
**Endpoint:** `POST /api/v1/tutor/sessions`

Learners enter a live AI coaching session or a fully dynamic role-play simulation.
- **Chat mode** — Socratic coaching that probes understanding before giving answers.
- **Role-play mode** — Configurable AI persona (e.g. "Angry Client") reacts dynamically to the learner's communication style.
- **SSE streaming** — Real-time token-by-token response via Server-Sent Events (`?stream=1`).
- **Post-session report** — Full transcript + Sentiment Analysis score (empathy, clarity, confidence) + specific coaching tips.

```php
// Start a role-play session
POST /api/v1/tutor/sessions
{
    "mode": "roleplay",
    "course_id": "01J...",
    "persona": {
        "name": "Difficult Client",
        "scenario": "Client upset about a delayed delivery",
        "personality": "impatient, interrupts, escalates quickly"
    }
}
```

### 2. 🎯 Hyper-Personalised Micro-Modules
**Service:** `MicroModuleGeneratorService`

The system monitors quiz performance and automatically generates targeted 30-second micro-modules when a learner consistently misses questions on a specific topic. The module addresses the *exact* misconception — not a full topic overview.

### 3. 🔍 AI-Powered Smart Search (RAG)
**Endpoint:** `GET /api/v1/search?q=...`

Semantic search across *all* LMS content — PDFs, video transcripts, SCORM, policy documents — using Retrieval-Augmented Generation (RAG).

```
GET /api/v1/search?q=What+is+our+remote+work+policy+in+France
→ Direct answer + links to the exact PDF page / video timestamp
```

**Pipeline:**
1. Query → OpenAI `text-embedding-3-large` embedding
2. Vector similarity search against indexed content chunks (PostgreSQL `pgvector`)
3. Top-8 chunks → GPT-4o grounded answer generation
4. Source citations with deep links

### 4. 📄 TalentCraft 2.0 — Document to Course
**Endpoint:** `POST /api/v1/courses/generate-from-document`

Upload a 50-page PDF/DOCX and get back a full interactive course in minutes, processed asynchronously via SQS + ECS worker:
1. Text extraction (PDF/DOCX/TXT)
2. Learning objective identification
3. Module & lesson structure generation
4. Quiz question writing (MCQ, T/F, open-ended)
5. Voiceover script generation per lesson
6. Auto-indexing for Smart Search

### 5. 📊 Automated Skill-Gap Heat Map
**Endpoint:** `GET /api/v1/admin/analytics/skill-gaps`

Cross-references job descriptions against current employee skills to produce a risk-rated heat map.
- Cached 4 hours (expensive AI operation, force refresh with `?refresh=true`)
- Risk levels: `low | medium | high | critical`
- Auto-generated training recommendations for critical gaps

### 6. 🚨 Predictive Retention Engine
**Endpoint:** `GET /api/v1/admin/analytics/at-risk-learners`

Monitors 9 behavioural signals (login frequency, quiz scores, video watch rate, overdue courses, etc.) and flags at-risk learners *before* they disengage.

- **Nightly sweep** via AWS EventBridge → ECS scheduled task
- Personalised nudge dispatched via SQS → Messenger worker → SES email
- Admin dashboard shows probability scores and recommended interventions

---

## Project Structure

```
talentlms-ai/
├── src/
│   ├── Controller/
│   │   ├── AITutorController.php       # SSE streaming chat
│   │   ├── SmartSearchController.php   # RAG search
│   │   ├── CourseGenerationController.php
│   │   ├── AdminAnalyticsController.php
│   │   └── HealthController.php
│   ├── Entity/
│   │   ├── User.php                    # engagement_score, at_risk_score
│   │   ├── Course.php                  # embedding vector, source tracking
│   │   ├── AITutorSession.php          # full conversation, sentiment
│   │   └── ...
│   ├── Service/
│   │   ├── AI/
│   │   │   ├── AITutorService.php          # Core chat/roleplay engine
│   │   │   ├── DocumentToCourseService.php # TalentCraft 2.0
│   │   │   ├── SmartSearchService.php      # RAG pipeline
│   │   │   ├── MicroModuleGeneratorService.php
│   │   │   └── SentimentAnalysisService.php
│   │   ├── Analytics/
│   │   │   ├── PredictiveRetentionService.php
│   │   │   └── SkillGapMappingService.php
│   │   └── Storage/
│   │       └── S3Service.php
│   ├── Message/                        # Symfony Messenger DTOs
│   ├── MessageHandler/                 # Async job processors
│   └── Command/
│       └── RetentionSweepCommand.php
├── infrastructure/
│   ├── terraform/
│   │   ├── main.tf     # VPC, RDS, Redis, SQS, S3, ALB, CloudFront
│   │   └── ecs.tf      # ECS tasks, services, auto-scaling, EventBridge
│   └── docker/
│       ├── Dockerfile
│       └── nginx.conf
├── config/
│   ├── packages/
│   │   ├── messenger.yaml  # SQS transports
│   │   └── security.yaml   # JWT auth
│   └── services.yaml
├── tests/
│   └── Unit/Service/AI/AITutorServiceTest.php
└── .github/workflows/ci-cd.yml   # Test → Build ECR → Deploy ECS
```

---

## Local Development

```bash
# 1. Clone and install
git clone https://github.com/your-org/talentlms-ai
cd talentlms-ai
composer install

# 2. Environment
cp .env .env.local
# Edit .env.local: set DATABASE_URL, REDIS_URL, OPENAI_API_KEY

# 3. Start services
docker compose up -d   # postgres + redis

# 4. Database
php bin/console doctrine:migrations:migrate
php bin/console doctrine:fixtures:load

# 5. JWT Keys
php bin/console lexik:jwt:generate-keypair

# 6. Run
symfony server:start

# 7. Start messenger worker (in a separate terminal)
php bin/console messenger:consume async -vv
```

---

## Infrastructure Deployment

```bash
cd infrastructure/terraform

# Initialise
terraform init

# Plan
terraform plan \
  -var="app_image=123456789.dkr.ecr.eu-west-1.amazonaws.com/talentlms/app:latest" \
  -var="worker_image=123456789.dkr.ecr.eu-west-1.amazonaws.com/talentlms/worker:latest" \
  -var="db_password=$DB_PASSWORD" \
  -var="jwt_passphrase=$JWT_PASSPHRASE" \
  -var="openai_api_key=$OPENAI_API_KEY" \
  -var="certificate_arn=arn:aws:acm:..."

# Apply
terraform apply
```

**Resources provisioned:**
| Resource | Detail |
|---|---|
| VPC | 3 AZs, public + private + DB subnets |
| RDS Aurora PostgreSQL | v16, Multi-AZ, encrypted, 14-day backups |
| ElastiCache Redis | r7g.large, Multi-AZ, TLS |
| ECS Fargate | App (2–20 tasks) + Worker (2–10 tasks) |
| ALB | HTTPS, TLS 1.3, HTTP→HTTPS redirect |
| SQS FIFO | Async + Failed queues, DLQ after 3 retries |
| S3 | Media + Transcripts buckets, KMS encrypted |
| CloudFront | Media CDN, OAC signed requests |
| EventBridge | Nightly retention sweep at 02:00 UTC |
| Secrets Manager | DB password, JWT, OpenAI key |
| ECR | App + Worker repositories, vulnerability scanning |

---

## Tests

```bash
# Unit tests
vendor/bin/phpunit

# With coverage
vendor/bin/phpunit --coverage-html coverage/

# Static analysis
vendor/bin/phpstan analyse src tests --level 8

# Code style
vendor/bin/php-cs-fixer fix
```

---

## Key Design Decisions

| Decision | Rationale |
|---|---|
| **Symfony Messenger + SQS** | All heavy AI operations (document conversion, nudges) are async — API stays fast |
| **SSE for AI chat** | No WebSocket infra needed; SSE works through ALB natively |
| **RAG over fine-tuning** | Keeps company content private; no retraining cost; real-time indexing |
| **PostgreSQL pgvector** | Single DB for relational + vector data; simplifies ops vs. dedicated vector DB |
| **GPT-4o for analysis + o3-mini for bulk** | Balance quality vs. cost per use case |
| **ECS Fargate over Lambda** | Long-running Messenger workers + streaming responses need persistent containers |
| **EventBridge scheduler** | Native AWS cron for retention sweeps; no separate scheduler infra |
