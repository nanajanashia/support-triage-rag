<?php

declare(strict_types=1);

namespace Triage;

use RuntimeException;

final class Llm
{
    private string $model;
    private string $baseUrl;

    public function __construct(?string $model = null, ?string $baseUrl = null)
    {
        $this->model   = $model   ?? Env::get('LLM_MODEL', 'qwen2.5:7b');
        $this->baseUrl = $baseUrl ?? Env::get('OLLAMA_URL', 'http://localhost:11434');
    }

    public function model(): string
    {
        return $this->model;
    }

    public function generate(string $prompt, bool $json = false): string
    {
        $payload = [
            'model'   => $this->model,
            'prompt'  => $prompt,
            'stream'  => false,
            'options' => ['temperature' => 0.2],
        ];
        if ($json) {
            $payload['format'] = 'json';
        }

        $ch = curl_init($this->baseUrl . '/api/generate');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 180,
        ]);
        $raw = curl_exec($ch);
        if ($raw === false) {
            throw new RuntimeException('Ollama request failed: ' . curl_error($ch));
        }
        curl_close($ch);

        $data = json_decode($raw, true);
        if (!isset($data['response'])) {
            throw new RuntimeException('Unexpected Ollama response: ' . $raw);
        }
        return trim($data['response']);
    }

    /** @param array<int, array{ticket: array, score: float}> $similar */
    public function draftReply(string $ticketText, array $similar): string
    {
        $cases = '';
        foreach ($similar as $r) {
            $t = $r['ticket'];
            $score = number_format($r['score'], 2);
            $cases .= "Case #{$t['id']} (similarity {$score}):\n"
                    . "  Problem: {$t['subject']}. {$t['body']}\n"
                    . "  Resolution: {$t['resolution']}\n\n";
        }

        $prompt = <<<PROMPT
You are a support agent for an order and inventory management SaaS used by small product businesses in the UK.
Below are past support cases, most similar first, each with the resolution that fixed it. Then a new ticket.

Step 1. Decide whether at least one past case describes the same problem as the new ticket. Same symptoms and same product area count as the same problem; the wording does not need to match.
A case does NOT match if the new ticket names a sales channel, accounting system, courier or other integration (for example Squarespace, Amazon, eBay, Sage, Evri) that appears in none of the past cases. Fixes for one platform must not be rewritten for another.
Step 2. If yes, write the reply from that case's resolution. If no case matches, reply in two sentences: we have not seen this exact issue, and a colleague will look into it.

Rules for the reply:
- Only use causes, fixes and settings that appear in the resolutions. You may paraphrase, but do not add steps, settings or features of your own.
- Put the case number in brackets after each fact you use, like [case #67].
- British English, direct, maximum 120 words. No greeting, no sign-off, no placeholders.

PAST CASES:
{$cases}
NEW TICKET:
{$ticketText}

REPLY:
PROMPT;

        return $this->generate($prompt);
    }
}
