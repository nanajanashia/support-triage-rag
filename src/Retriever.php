<?php

declare(strict_types=1);

namespace Triage;

use PDO;

final class Retriever
{
    public function __construct(
        private PDO $db,
        private Embedder $embedder,
    ) {}

    /**
     * @return array<int, array{ticket: array, score: float}>
     */
    public function similar(string $text, int $k = 5): array
    {
        $query = $this->embedder->embed($text);

        $stmt = $this->db->prepare(
            'SELECT t.id, t.subject, t.body, t.category, t.root_cause, t.resolution, e.vector
             FROM ticket_embeddings e
             JOIN tickets t ON t.id = e.ticket_id
             WHERE e.model = :model'
        );
        $stmt->execute([':model' => $this->embedder->model()]);

        $scored = [];
        foreach ($stmt as $row) {
            $vector = json_decode($row['vector'], true);
            unset($row['vector']);
            $scored[] = ['ticket' => $row, 'score' => self::cosine($query, $vector)];
        }

        usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($scored, 0, $k);
    }

    /**
     * Pull a ticket number out of free text, e.g. "ticket 150", "ticket number 150",
     * "case #150", "#150". Returns null when the text has no such reference.
     */
    public static function extractTicketId(string $text): ?int
    {
        if (preg_match('/\b(?:ticket|case)\s*(?:number|no\.?|id)?\s*#?\s*(\d{1,6})\b/i', $text, $m)
            || preg_match('/#(\d{1,6})\b/', $text, $m)) {
            return (int) $m[1];
        }
        return null;
    }

    /** Exact lookup by primary key. */
    public function byId(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, subject, body, category, root_cause, resolution, created_at
             FROM tickets WHERE id = :id'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** Keyword search on subject (case-insensitive LIKE). Every word must appear. */
    public function searchSubject(string $keywords, int $limit = 10): array
    {
        $words = preg_split('/\s+/', trim($keywords), -1, PREG_SPLIT_NO_EMPTY);
        if ($words === []) {
            return [];
        }
        $where  = implode(' AND ', array_fill(0, count($words), 'subject LIKE ?'));
        $params = array_map(fn ($w) => '%' . $w . '%', $words);
        $params[] = $limit;

        $stmt = $this->db->prepare(
            "SELECT id, subject, category, root_cause FROM tickets WHERE {$where} ORDER BY id LIMIT ?"
        );
        foreach ($params as $i => $v) {
            $stmt->bindValue($i + 1, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /** @param float[] $a @param float[] $b */
    public static function cosine(array $a, array $b): float
    {
        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;
        foreach ($a as $i => $x) {
            $y = $b[$i];
            $dot   += $x * $y;
            $normA += $x * $x;
            $normB += $y * $y;
        }
        if ($normA == 0.0 || $normB == 0.0) {
            return 0.0;
        }
        return $dot / (sqrt($normA) * sqrt($normB));
    }
}