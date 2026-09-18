# NEXORA-AI

> AI Workspace for modern digital professionals.

NEXORA-AI is a modern AI workspace built with **Laravel 13**, designed for AI conversations with a scalable backend architecture and multi-provider support.

## Features

* 🔐 Authentication
* 💬 Conversation & message management
* 🤖 AI chat with PatewayAI
* 🔄 Regenerate responses
* ✏️ Edit & resend messages
* ⚡ Real-time streaming via SSE
* 🎯 AI model selection
* 🔒 User & conversation ownership
* 🧪 Automated testing

## Tech Stack

* **Laravel 13**
* **PHP 8.3+**
* **SQLite**
* **Blade**
* **Tailwind CSS**
* **Vanilla JavaScript**
* **PatewayAI**

## Architecture

```text
Controller
    ↓
AIService
    ↓
AIProviderInterface
    ↓
PatewayProvider
    ↓
PatewayAI
```

Built with a **Service Layer + Provider Abstraction** architecture to keep the AI system scalable and easy to extend.

## Testing

```text
25 tests passed
85 assertions
```

Run:

```bash
php artisan test
```

## Roadmap

**v1 — AI Chat Core** ✅

**v2 — File & Knowledge**

* File upload
* Document processing
* Knowledge Base
* RAG

**v3+ — AI Tools**

* Web research
* Image generation
* Data analysis
* Workspace & collaboration

---

<p align="center">
  <strong>NEXORA-AI</strong><br>
  Creative Intelligence. Engineered for Work.
</p>
