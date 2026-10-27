# Support ticket triage with RAG

All ticket data in this repo is synthetic, created to explore the RAG pattern for support triage.

A new support ticket is embedded and matched against past tickets and their resolutions stored in MySQL. A local LLM drafts a reply from the closest cases, citing them. A decision step then routes the ticket: `auto_reply` when a known problem matches with high confidence, `human_review` when unclear, `escalate` for billing disputes, data loss, legal threats or abusive tone. Every decision is written to a log table.

Stack: PHP 8.4, MySQL 8 in Docker, Ollama with `nomic-embed-text` for embeddings and `qwen2.5:7b` for generation. Runs fully locally, no API key.

## Install

Requires PHP 8.3+, Composer, Docker and Homebrew.

```bash
# models
brew install ollama
brew services start ollama
ollama pull nomic-embed-text
ollama pull qwen2.5:7b

# config and database
cp .env.example .env  
# set DB_USER, DB_PASSWORD, DB_ROOT_PASSWORD     
docker compose up -d
docker exec -i triage-mysql sh -c 'mysql -u$MYSQL_USER -p$MYSQL_PASSWORD $MYSQL_DATABASE' < schema.sql

# code and data
composer dump-autoload
php bin/ingest.php
```

## Use

```bash
# run the pipeline for one ticket
php bin/triage.php "Free to sell says 9 but we only have 3 on the shelf"  
# run the evaluation set 
php bin/evaluate.php   
 # show a past ticket by id                                                     
php bin/lookup.php 48                                                      
```
