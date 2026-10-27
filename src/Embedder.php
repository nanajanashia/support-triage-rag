<?php

declare(strict_types=1);

namespace Triage;

use RuntimeException;

final class Embedder
{
    private string $model;
    private string $baseUrl;

    public function __construct(?string $model = null, ?string $baseUrl = null)
    {
        $this->model   = $model   ?? Env::get('EMBED_MODEL', 'nomic-embed-text');
        $this->baseUrl = $baseUrl ?? Env::get('OLLAMA_URL', 'http://localhost:11434');
    }

    public function model(): string
    {
        return $this->model;
    }

    /** @return float[] */
    public function embed(string $text): array
    {
        $payload = json_encode(['model' => $this->model, 'prompt' => $text]);

        $ch = curl_init($this->baseUrl . '/api/embeddings');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
        ]);
        $raw = curl_exec($ch);
        if ($raw === false) {
            throw new RuntimeException('Ollama request failed: ' . curl_error($ch));
        }
        curl_close($ch);

        $data = json_decode($raw, true);
        if (!isset($data['embedding']) || !is_array($data['embedding'])) {
            throw new RuntimeException('Unexpected Ollama response: ' . $raw);
        }
        return $data['embedding'];
    }
}