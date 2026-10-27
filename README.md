# Support ticket triage with RAG (PHP, MySQL, local models)

All ticket data in this repo is synthetic, created to explore the RAG pattern for support triage. The domain is an order and inventory management SaaS: stock figures, channel order imports, despatch, accounting sync.

A new support ticket is embedded and matched against past tickets and their resolutions in MySQL. The model drafts a reply from those cases, citing them. A decision step then routes the ticket: known problem with a confident match becomes a draft ready to send, anything unclear goes to a human, and anything about money, data loss, legal threats or an angry customer is escalated before a model ever sees it.

Everything runs locally. No API key, no cloud service.

## Results on the held-out set

20 held-out tickets, none of them in the retrieval set. Two consecutive runs gave identical results.

| Metric | Result |
|---|---|
| Expected category appears in top 5 retrieved | 20 / 20 |
| Expected root cause appears in top 5 (16 tickets have one) | 15 / 16 |
| Triage action correct (auto_reply / human_review / escalate) | 19 / 20 |

Confusion matrix. Rows are the expected action, columns are what the pipeline decided.

|  | auto_reply | human_review | escalate |
|---|---|---|---|
| **auto_reply** | 8 | 0 | 0 |
| **human_review** | 0 | 5 | 1 |
| **escalate** | 0 | 0 | 6 |

No ticket that needed a human or an escalation was auto-replied. The one error (E10) is a billing keyword rule firing on "the courier charged us twice", which escalated a ticket that should have gone to human review. It fails in the safe direction. See Known limitations.

Models: `nomic-embed-text` for embeddings, `qwen2.5:7b` for drafting and the triage decision. Full per-ticket output is in `data/eval_results.json`.

## Architecture

```
INGEST PATH  (php bin/ingest.php, run once or when tickets.json changes)

  data/tickets.json ──▶ for each ticket:
                          1. INSERT row ──────────────────────▶ MySQL  tickets
                          2. embed(subject + body) ──▶ Ollama nomic-embed-text ──▶ 768 floats
                          3. INSERT vector ───────────────────▶ MySQL  ticket_embeddings
                                                                        (ticket_id, model, vector JSON)

QUERY PATH   (php bin/triage.php "ticket text")

  new ticket text
       │
       ├─ mentions "ticket 150" / "#12"?  ──▶ exact lookup, print record, stop
       │
       ▼
  Retriever.similar()
       1. embed(new text) ──▶ Ollama ──▶ 768 floats
       2. SELECT all vectors for this embedding model ◀── MySQL
       3. cosine(new, each stored) ──▶ 150 scores
       4. sort, keep top 5 (with their resolutions)
       │
       ▼
  Llm.draftReply()
       prompt = grounding rules + 5 past cases + new ticket ──▶ Ollama qwen2.5:7b ──▶ reply citing [case #N]
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
  INSERT into triage_log (similar ids + scores, draft, action, confidence, reason, rule, model)
  print similar cases, draft, decision
```

## How to run

Requirements: PHP 8.3+ with pdo_mysql and curl, Composer, Docker, Homebrew (macOS) for Ollama. Tested on Apple silicon with 16 GB RAM.

```bash
# 1. Models
brew install ollama
brew services start ollama
ollama pull nomic-embed-text
ollama pull qwen2.5:7b          # or llama3.2 for a smaller, faster, less accurate option

# 2. Config and database
cp .env.example .env            # then set DB_USER, DB_PASSWORD, DB_ROOT_PASSWORD
docker compose up -d
docker exec -i triage-mysql mysql -utriage -ptriage triage < schema.sql

# 3. Code
composer dump-autoload

# 4. Load and embed the 150 past tickets (about a minute)
php bin/ingest.php

# 5. Triage a ticket
php bin/triage.php "Free to sell says 9 but we only have 3 on the shelf, we had several website orders cancelled last week"

# 6. Run the evaluation (about two minutes)
php bin/evaluate.php --json

# Extras
php bin/lookup.php 48                 # exact ticket by id
php bin/lookup.php "xero invoice"     # subject keyword search
php bin/evaluate.php --only=E10,E11   # rerun specific eval tickets
```

Database credentials, port, Ollama URL and model names all come from `.env`, which both `docker-compose.yml` and the PHP code read. `.env` is git-ignored; `.env.example` lists the variables. Change `DB_PORT` if 3306 is taken, and `LLM_MODEL` to `llama3.2` for the lighter model.

Example output:

```
Similar past tickets:
  0.771  #1   stock_figures      allocation_not_released_on_cancel        Free to sell shows 12 but we only have 4 on the shelf
  0.741  #2   stock_figures      free_to_sell_misunderstood               Shopify says sold out but we have 30 jars
  0.716  #87  purchasing         po_not_generated_from_shortfall          No purchase order suggested even though we are short
  0.677  #20  stock_figures      unit_of_measure                          Stock in cases but we sell in bottles
  0.676  #141 data_loss          bulk_delete_by_user                      300 products have disappeared

Draft reply (qwen2.5:7b):
Three cancelled orders in Shopify while the integration was disconnected caused the
allocation to stay on the products. Re-sync the cancelled orders to update free to sell
and match the shelf stock. [case #1]

Decision:
  action:      AUTO_REPLY
  confidence:  0.95
  reason:      The ticket describes a similar issue to case #1, and the draft reply
               provides a relevant fix.
  matched:     case #1
  logged as:   triage_log #69
```

## Files

```
docker-compose.yml     MySQL 8, named volume so data survives container recreation
schema.sql             tickets, ticket_embeddings (ticket_id, model, vector JSON), triage_log
composer.json          PSR-4 autoload only, no packages
.env.example           variable names for database, Ollama URL and model names
data/tickets.json      150 synthetic past tickets: subject, body, category, root_cause, resolution
data/eval.json         20 held-out tickets with expected category, root cause and action
data/eval_results.json last evaluation run
src/Env.php            tiny .env loader, no dependency
src/Db.php             shared PDO connection, settings from .env
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

150 past tickets across nine categories, written to sound like owner-managed UK product businesses (furniture, food and drink, wholesale) using a stock and order system that syncs with Shopify, WooCommerce, Magento, Xero and QuickBooks.

| Category | Tickets | Examples of root causes |
|---|---|---|
| stock_figures | 24 | allocation not released on cancel, free to sell misunderstood, channel sync interval |
| order_import | 22 | Shopify edit creates duplicate, webhook queue backlog, SKU mapping missing |
| accounting_sync | 22 | Xero tax rate mapping, invoice duplicated on resync, auth token expired |
| despatch | 18 | picked old quantity, part shipment leaves back order open |
| user_access | 16 | role permissions, password reset blocked, plan user limit |
| purchasing | 14 | no default supplier so no PO suggested, supplier lead time ignored |
| reporting_export | 14 | CSV encoding, export row limit, date range too wide |
| billing | 10 | double charge, refund request, dispute (always escalated) |
| data_loss | 10 | bulk delete, import overwrote stock (always escalated) |

Confusable pairs were put in on purpose: a Shopify order duplicated versus a Xero invoice duplicated; our subscription invoice being wrong (billing) versus the customer's own Xero invoices having wrong VAT (accounting_sync); a user-limit question that is really about the plan. The eval set includes a known stock problem written in an abusive tone and a known despatch problem with a solicitor involved, to check that rules beat similarity.

## Design decisions

**Why human in the loop.** The model drafts, it does not send. `auto_reply` means "draft is ready for a one-click send", and it is only granted when retrieval similarity and model confidence are both above threshold. Everything else is read by a person. The thresholds (0.60 to consult the model, 0.70 similarity and 0.75 confidence for auto reply) are constants at the top of `src/Triage.php` and were set by looking at score distributions on the eval set, not guessed.

**Why rules before the model.** Billing disputes, data loss claims, legal language and abusive tone are the cases where a wrong automatic answer does real damage. A regular expression spotting "solicitor" or "refund" is more reliable than a 7B model and costs nothing. Hard rules escalate and stop. Soft rules (pricing questions, multiple issues) do not escalate but cap the outcome at human review. The model only decides between auto reply and human review on what is left.

**Why no chunking.** Chunking exists for long documents that exceed the embedding window or cover several topics. A support ticket is 50 to 150 words about one problem. One vector per ticket is the right unit. I would chunk a help centre or a manual, by heading or by fixed window with overlap.

**What gets embedded.** Subject and body only. A new ticket arrives with only those, so past tickets are embedded the same way to compare like with like. The resolution is retrieved after the match and shown to the model; embedding it would make tickets cluster by solution instead of symptom. Category and root cause are excluded because they are what the evaluation tries to recover.

**Why cosine similarity.** It measures the angle between vectors and ignores magnitude, so a long rambling ticket and a two-line ticket about the same problem score as similar. Computed in PHP over all 150 vectors on every query, which takes a few milliseconds here and is the wrong choice at scale (see below).

**Exact lookups before semantic search.** A question like "what happened on ticket 150" is not a similarity problem. The pipeline detects ticket references with a regex and answers from the primary key. `bin/lookup.php` also does subject keyword search. In production the same idea extends to customer id, order number and error codes.

**Model stored next to every vector.** Vectors from different embedding models are not comparable. `ticket_embeddings` has a composite key on (ticket_id, model), and the retriever filters on model, so re-embedding with a new model is an additive migration rather than a replacement.

**Prompt iteration driven by the eval set.** The first draft prompt let the model invent steps inside Xero that appeared in no case. A very strict rewrite refused a near-exact match. The final prompt asks the model to first decide whether a case matches, then answer only from that resolution with citations. The triage prompt went through the same loop: adding one "not covered" rule made the model over-cautious and flipped two correct auto replies, until the prompt listed positive conditions for auto reply first and said customer-specific details are expected to differ. Each change was checked against all 20 eval tickets, twice.

## Known limitations

- **Keyword rules have false positives.** "The courier charged us twice" trips the billing rule. Fixing it by excluding courier names would be fitting to the test set. Fine for 150 tickets; at scale the rules would be a first-pass classifier with a confidence of its own.
- **7B model drafts need editing.** They are grounded and cite correctly, but phrasing is terse and sometimes copies the resolution's internal tone. That is acceptable for a draft a human reviews, and is why `auto_reply` means "ready to review and send", not "sent".
- **Synthetic data written by one person** shares a vocabulary with the eval set written by the same person. Real tickets are messier. The retrieval numbers would drop on real data and the rules would need tuning.
- **Small eval set.** 20 tickets is enough to catch regressions during development, not enough to quote a precision figure with confidence.

## What I would change for production

- **Vector index** instead of JSON plus PHP cosine: pgvector, MySQL 9 `VECTOR` type, or OpenSearch. The retriever interface stays the same.
- **Async queue** for embedding and generation, with the ticket system adapter (Zendesk, Jira Service Management, Freshdesk) pushing new tickets in and reading decisions back.
- **Evaluation in CI.** `bin/evaluate.php --json` already produces a machine-readable result; a pipeline step would fail the build if action accuracy or retrieval hit rate drops below a floor.
- **Prompt versioning.** Store a prompt version string alongside `model` in `triage_log`, so a change in behaviour can be traced to a prompt change.
- **Audit and feedback.** `triage_log` already records every decision with its inputs. Add a column for what the human actually did, which turns the log into training data for the thresholds and rules.
- **Hosted model option** behind the same `Llm` interface for teams that prefer a stronger model and accept the data leaving the building.
