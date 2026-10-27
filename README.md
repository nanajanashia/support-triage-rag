# Support ticket triage with RAG (PHP, MySQL, local models)

All ticket data in this repo is synthetic, created to explore the RAG pattern for support triage. The domain is an order and inventory management SaaS: stock figures, channel order imports, despatch, accounting sync.

A new support ticket is embedded and matched against past tickets and their resolutions in MySQL. A local LLM drafts a reply from the closest cases, citing them. A decision step then routes the ticket: `auto_reply` when a known problem matches with high confidence, `human_review` when unclear, `escalate` for billing disputes, data loss, legal threats or abusive tone. Every decision is written to a log table.

Stack: PHP 8.4, MySQL 8 in Docker, Ollama with `nomic-embed-text` for embeddings and `qwen2.5:7b` for generation. Runs fully locally, no API key.

## Architecture

```
INGEST PATH  (php bin/ingest.php, run once or when tickets.json changes)

  data/tickets.json ──▶ for each ticket:
                          1. INSERT row ──────────────────────▶ MySQL  tickets
                          2. embed(subject + body) ──▶ Ollama nomic-embed-text ──▶ 768 floats
                          3. INSERT vector ───────────────────▶ MySQL  ticket_embeddings

QUERY PATH   (php bin/triage.php "ticket text")

  new ticket text
       │
       ├─ mentions "ticket 150" / "#12"?  ──▶ exact lookup, print record, stop
       │
       ▼
  Retriever.similar()
       1. embed(new text) ──▶ Ollama ──▶ 768 floats
       2. SELECT all vectors for this embedding model ◀── MySQL
       3. cosine(new, each stored), sort, keep top 5 with their resolutions
       │
       ▼
  Llm.draftReply()
       prompt = grounding rules + 5 past cases + new ticket ──▶ qwen2.5:7b ──▶ reply citing [case #N]
       │
       ▼
  Triage.decide()
       1. hard rules (billing dispute, data loss, legal, abusive tone) ──▶ escalate, model not consulted
       2. soft rules (pricing question, several issues)                ──▶ cap at human_review
       3. top similarity < 0.60                                         ──▶ human_review, model not consulted
       4. model decides auto_reply | human_review in JSON mode with confidence and reason
          auto_reply only if confidence ≥ 0.75 and top similarity ≥ 0.70
       │
       ▼
  INSERT into triage_log, print similar cases, draft, decision
```

## Install

Requires PHP 8.3+ with pdo_mysql and curl, Composer, Docker and Homebrew.

```bash
# models
brew install ollama
brew services start ollama
ollama pull nomic-embed-text
ollama pull qwen2.5:7b

# config and database
cp .env.example .env            # then set DB_USER, DB_PASSWORD, DB_ROOT_PASSWORD
docker compose up -d
docker exec -i triage-mysql sh -c 'mysql -u$MYSQL_USER -p$MYSQL_PASSWORD $MYSQL_DATABASE' < schema.sql

# code and data
composer dump-autoload
php bin/ingest.php              # load and embed the 150 past tickets
```

Database credentials, port, Ollama URL and model names all come from `.env`, read by both `docker-compose.yml` and the PHP code. `.env` is git-ignored; `.env.example` lists the variables.

## Use

```bash
php bin/triage.php "Free to sell says 9 but we only have 3 on the shelf"   # full pipeline for one ticket
php bin/evaluate.php                                                        # run the evaluation set
php bin/lookup.php 48                                                       # exact ticket by id
php bin/lookup.php "xero invoice"                                           # subject keyword search
```

## Files

```
docker-compose.yml     MySQL 8, named volume so data survives container recreation
schema.sql             tickets, ticket_embeddings (ticket_id, model, vector JSON), triage_log
composer.json          PSR-4 autoload only, no packages
.env.example           variable names for database, Ollama URL and model names
data/tickets.json      150 synthetic past tickets: subject, body, category, root_cause, resolution
data/eval.json         20 evaluation tickets with expected category, root cause and action
src/Env.php            tiny .env loader
src/Db.php             shared PDO connection
src/Embedder.php       POST /api/embeddings on Ollama, returns float[]
src/Llm.php            POST /api/generate on Ollama; draftReply() holds the grounding prompt
src/Retriever.php      cosine similarity top-k, plus exact id and subject keyword lookups
src/Triage.php         hard rules, soft rules, thresholds, JSON-mode model decision, logging
bin/ingest.php         load tickets.json, embed, store
bin/triage.php         full pipeline for one ticket
bin/lookup.php         exact and keyword lookups without the model
bin/evaluate.php       run the eval set, print hit rates and confusion matrix
```

## The data

150 past tickets across nine categories (stock_figures, order_import, accounting_sync, despatch, user_access, purchasing, reporting_export, billing, data_loss), written to sound like small UK product businesses using a stock and order system that syncs with Shopify, WooCommerce, Xero and QuickBooks. Confusable pairs were put in on purpose, for example our own subscription invoice being wrong (billing) versus the customer's Xero invoices having wrong VAT (accounting_sync).

## Design decisions

**Human in the loop.** The model drafts, it does not send. `auto_reply` means the draft is ready for a one-click send, and is only granted when retrieval similarity and model confidence are both above threshold. The thresholds are constants at the top of `src/Triage.php`.

**Rules before the model.** Billing disputes, data loss, legal language and abusive tone are where a wrong automatic answer does real damage. A regex is more reliable than a 7B model there and costs nothing. Hard rules escalate and stop; soft rules cap the outcome at human review.

**No chunking.** A support ticket is 50 to 150 words about one problem, so one vector per ticket is the right unit. Only subject and body are embedded, because that is all a new ticket arrives with. The resolution is shown to the model after the match.

**Model stored next to every vector.** Vectors from different embedding models are not comparable. `ticket_embeddings` has a composite key on (ticket_id, model), so re-embedding with a new model is additive.

**Exact lookups before semantic search.** "What happened on ticket 150" is not a similarity problem. A regex catches ticket references and answers from the primary key.

## Scope

This is a working prototype sized for a few hundred tickets: similarity is computed in PHP over all stored vectors, and the escalation rules are plain regular expressions. Both are easy to swap for a vector index and a classifier when the ticket volume grows. `auto_reply` produces a draft for one-click send, not an automatic reply.
