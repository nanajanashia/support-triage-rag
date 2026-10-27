<?php

declare(strict_types=1);

namespace Triage;

use PDO;

/**
 * Decides what happens to a ticket: auto_reply | human_review | escalate.
 *
 * Order of precedence:
 *   1. Hard rules  -> escalate, model is not consulted.
 *   2. Soft rules  -> cap the outcome at human_review.
 *   3. Retrieval signal: weak top score -> human_review without asking the model.
 *   4. Model decision in JSON mode, then thresholds decide whether auto_reply is allowed.
 */
final class Triage
{
    public const MIN_SCORE_TO_ASK_MODEL = 0.60;
    public const MIN_SCORE_FOR_AUTO     = 0.70;
    public const MIN_CONFIDENCE_FOR_AUTO = 0.75;

    /** Any match -> escalate. Keyed by rule name. */
    private const HARD_RULES = [
        'billing_dispute' => '/\b(refund|chargeback|charge ?back|charged (us )?twice|double (payment|charge)|taken .{0,20}twice|overcharged|disput(e|ing)|not paying|won\'t be paying|direct debit|cancel (our|the|my) (subscription|account)|price (increase|went up))\b/i',
        'data_loss'       => '/\b(vanished|disappeared|wiped|corrupt(ed|ion)?|rolled back|gone back in time|reset to zero|all (our |the )?(data|orders|products|stock|history) (is |are |has |have )?(gone|missing|lost|deleted)|(every|all|half( of)?|hundreds of) .{0,40}\b(gone|zero|deleted|missing|lost)\b|lost all)\b/i',
        'legal'           => '/\b(solicitor|lawyer|legal action|legal team|court|ico\b|gdpr|trading standards|formal complaint|treat this as formal|subject access request|breach of contract)\b/i',
        'abusive_tone'    => '/\b(useless|pathetic|joke|disgrace(ful)?|ridiculous|incompetent|shambles|idiots?|fuck|shit|crap|or we\'re gone|we are gone|we\'re leaving|taking our business elsewhere|everyone .{0,30}(know|hear) why|screaming)\b/i',
    ];

    /** Any match -> never better than human_review. */
    private const SOFT_RULES = [
        'plan_or_pricing_question' => '/\b(plan|pricing|subscription|upgrade|downgrade|bigger plan|pay for)\b/i',
        'multiple_issues'          => '/\b(separately|also|secondly|and another thing|two (issues|problems)|both)\b/i',
    ];

    public function __construct(
        private PDO $db,
        private Llm $llm,
    ) {}

    /**
     * @param array<int, array{ticket: array, score: float}> $similar
     * @return array{action:string, confidence:float, reason:string, rule_fired:?string, matched_case_id:?int}
     */
    public function decide(string $ticketText, array $similar, string $draft): array
    {
        foreach (self::HARD_RULES as $name => $pattern) {
            if (preg_match($pattern, $ticketText, $m)) {
                return [
                    'action'          => 'escalate',
                    'confidence'      => 1.0,
                    'reason'          => "Hard rule '{$name}' matched \"{$m[0]}\". Escalated without consulting the model.",
                    'rule_fired'      => $name,
                    'matched_case_id' => null,
                ];
            }
        }

        $cap = null;
        foreach (self::SOFT_RULES as $name => $pattern) {
            if (preg_match($pattern, $ticketText)) {
                $cap = $name;
                break;
            }
        }

        $topScore = $similar[0]['score'] ?? 0.0;
        if ($topScore < self::MIN_SCORE_TO_ASK_MODEL) {
            return [
                'action'          => 'human_review',
                'confidence'      => round($topScore, 2),
                'reason'          => sprintf('Best similarity %.2f is below %.2f; no comparable past case.', $topScore, self::MIN_SCORE_TO_ASK_MODEL),
                'rule_fired'      => 'low_similarity',
                'matched_case_id' => null,
            ];
        }

        $model = $this->askModel($ticketText, $similar, $draft);

        $action     = $model['action'];
        $confidence = $model['confidence'];
        $reason     = $model['reason'];

        if ($action === 'auto_reply') {
            if ($confidence < self::MIN_CONFIDENCE_FOR_AUTO) {
                $action = 'human_review';
                $reason .= sprintf(' Downgraded: confidence %.2f below %.2f.', $confidence, self::MIN_CONFIDENCE_FOR_AUTO);
            } elseif ($topScore < self::MIN_SCORE_FOR_AUTO) {
                $action = 'human_review';
                $reason .= sprintf(' Downgraded: top similarity %.2f below %.2f.', $topScore, self::MIN_SCORE_FOR_AUTO);
            }
        }

        if ($cap !== null && $action === 'auto_reply') {
            $action = 'human_review';
            $reason .= " Capped by soft rule '{$cap}'.";
        }

        return [
            'action'          => $action,
            'confidence'      => $confidence,
            'reason'          => $reason,
            'rule_fired'      => $cap,
            'matched_case_id' => $model['matched_case_id'],
        ];
    }

    /** @param array<int, array{ticket: array, score: float}> $similar */
    private function askModel(string $ticketText, array $similar, string $draft): array
    {
        $cases = '';
        foreach ($similar as $r) {
            $t = $r['ticket'];
            $cases .= sprintf("- case #%d (similarity %.2f, %s / %s): %s\n    Resolution: %s\n",
                $t['id'], $r['score'], $t['category'], $t['root_cause'], $t['subject'], $t['resolution']);
        }

        $prompt = <<<PROMPT
You are the triage step of a support system for an order and inventory management SaaS.
A retrieval step found the most similar past cases, and a draft reply was written from them. Decide what happens next.

Choose "auto_reply" when ALL of these hold:
  a) the best matching case describes the same symptom in the same product area as the ticket;
  b) the draft gives that case's fix and cites it;
  c) the ticket is about one problem.
Details specific to this customer (names, quantities, dates, which member of staff left, which day it started) are expected to differ from the past case and are NOT a reason to withhold auto_reply.

Choose "human_review" when ANY of these hold:
  - the ticket is too vague to identify a symptom;
  - it asks for a new feature or a report that does not exist;
  - it raises two unrelated problems;
  - it names a brand (sales channel, accounting system, courier, platform) that appears in none of the matched cases;
  - the draft says the issue has not been seen before.

Choose "escalate" only for data loss, billing disputes, legal threats or abusive tone. Rules already check this, so it is rare here.

NEW TICKET:
{$ticketText}

MOST SIMILAR PAST CASES:
{$cases}
DRAFT REPLY PRODUCED FROM THOSE CASES:
{$draft}

Respond with JSON only, exactly these keys:
{"action": "auto_reply" | "human_review" | "escalate", "confidence": number between 0 and 1, "reason": one sentence, "matched_case_id": integer case number or null}
PROMPT;

        $raw  = $this->llm->generate($prompt, json: true);
        $data = json_decode($raw, true);

        $action = $data['action'] ?? 'human_review';
        if (!in_array($action, ['auto_reply', 'human_review', 'escalate'], true)) {
            $action = 'human_review';
        }

        return [
            'action'          => $action,
            'confidence'      => max(0.0, min(1.0, (float) ($data['confidence'] ?? 0.0))),
            'reason'          => trim((string) ($data['reason'] ?? 'Model returned no reason.')),
            'matched_case_id' => isset($data['matched_case_id']) && is_numeric($data['matched_case_id']) ? (int) $data['matched_case_id'] : null,
        ];
    }

    /** @param array<int, array{ticket: array, score: float}> $similar */
    public function log(string $subject, string $body, array $similar, string $draft, array $decision): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO triage_log (subject, body, similar_ids, draft, action, confidence, reason, rule_fired, model)
             VALUES (:subject, :body, :similar_ids, :draft, :action, :confidence, :reason, :rule_fired, :model)'
        );
        $stmt->execute([
            ':subject'     => mb_substr($subject, 0, 255),
            ':body'        => $body,
            ':similar_ids' => json_encode(array_map(fn ($r) => ['id' => $r['ticket']['id'], 'score' => round($r['score'], 3)], $similar)),
            ':draft'       => $draft,
            ':action'      => $decision['action'],
            ':confidence'  => $decision['confidence'],
            ':reason'      => $decision['reason'],
            ':rule_fired'  => $decision['rule_fired'],
            ':model'       => $this->llm->model(),
        ]);
        return (int) $this->db->lastInsertId();
    }
}
