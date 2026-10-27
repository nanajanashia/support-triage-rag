<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Triage\{Db, Embedder, Retriever, Llm, Triage};

/**
 * Runs the held-out eval set through the full pipeline and reports:
 *   - retrieval hit rate: expected category (and root cause, where given) appears in the top 5
 *   - triage accuracy:    decided action equals expected action
 * Nothing in eval.json is ever shown to the model; only subject and body go in.
 *
 * Usage: php bin/evaluate.php [--no-log] [--k=5] [--only=E11,E12] [--json]
 */
$opts  = getopt('', ['no-log', 'k::', 'json', 'only::']);
$k     = isset($opts['k']) ? (int) $opts['k'] : 5;
$doLog = !isset($opts['no-log']);

$eval      = json_decode(file_get_contents(__DIR__ . '/../data/eval.json'), true);
if (!empty($opts['only'])) {
    $only = array_map('trim', explode(',', $opts['only']));
    $eval = array_values(array_filter($eval, fn ($e) => in_array($e['id'], $only, true)));
}
$db        = Db::conn();
$llm       = new Llm();
$retriever = new Retriever($db, new Embedder());
$triage    = new Triage($db, $llm);

$rows        = [];
$catHits     = 0;
$rcHits      = 0;
$rcTotal     = 0;
$actionHits  = 0;
$confusion   = [];   // expected => decided => count
$start       = microtime(true);

foreach ($eval as $e) {
    $text    = "{$e['subject']}\n{$e['body']}";
    $similar = $retriever->similar($text, $k);
    $draft   = $llm->draftReply($text, $similar);
    $d       = $triage->decide($text, $similar, $draft);
    if ($doLog) {
        $triage->log("[eval {$e['id']}] {$e['subject']}", $e['body'], $similar, $draft, $d);
    }

    $topCats = array_map(fn ($r) => $r['ticket']['category'], $similar);
    $topRcs  = array_map(fn ($r) => $r['ticket']['root_cause'], $similar);

    $catHit = in_array($e['expected_category'], $topCats, true);
    $rcHit  = null;
    if ($e['expected_root_cause'] !== null) {
        $rcTotal++;
        $rcHit = in_array($e['expected_root_cause'], $topRcs, true);
        $rcHits += $rcHit ? 1 : 0;
    }
    $actHit = $d['action'] === $e['expected_action'];

    $catHits    += $catHit ? 1 : 0;
    $actionHits += $actHit ? 1 : 0;
    $confusion[$e['expected_action']][$d['action']] = ($confusion[$e['expected_action']][$d['action']] ?? 0) + 1;

    $rows[] = [
        'id'        => $e['id'],
        'top_score' => $similar[0]['score'],
        'top_cat'   => $topCats[0],
        'cat_hit'   => $catHit,
        'rc_hit'    => $rcHit,
        'expected'  => $e['expected_action'],
        'decided'   => $d['action'],
        'act_hit'   => $actHit,
        'rule'      => $d['rule_fired'],
        'conf'      => $d['confidence'],
    ];

    printf("%s  %s  cat:%s rc:%s  action:%s  %-12s -> %-12s  %s\n",
        $e['id'],
        number_format($similar[0]['score'], 2),
        $catHit ? 'Y' : 'N',
        $rcHit === null ? '-' : ($rcHit ? 'Y' : 'N'),
        $actHit ? 'Y' : 'N',
        $e['expected_action'],
        $d['action'],
        $d['rule_fired'] ?? ''
    );
}

$n = count($eval);
$elapsed = microtime(true) - $start;

echo "\n";
printf("Model: %s   Embeddings: %s   k=%d   %d tickets in %.0fs\n", $llm->model(), (new Embedder())->model(), $k, $n, $elapsed);
printf("Retrieval  category in top-%d:    %2d/%2d  (%.0f%%)\n", $k, $catHits, $n, 100 * $catHits / $n);
printf("Retrieval  root cause in top-%d:  %2d/%2d  (%.0f%%)  (tickets with an expected root cause)\n", $k, $rcHits, $rcTotal, 100 * $rcHits / max(1, $rcTotal));
printf("Triage     action correct:        %2d/%2d  (%.0f%%)\n", $actionHits, $n, 100 * $actionHits / $n);

$actions = ['auto_reply', 'human_review', 'escalate'];
echo "\nConfusion (rows = expected, columns = decided):\n";
printf("  %-14s %12s %12s %12s\n", '', ...$actions);
foreach ($actions as $exp) {
    printf("  %-14s", $exp);
    foreach ($actions as $dec) {
        printf(" %12d", $confusion[$exp][$dec] ?? 0);
    }
    echo "\n";
}

$wrong = array_filter($rows, fn ($r) => !$r['act_hit']);
if ($wrong !== []) {
    echo "\nWrong triage decisions:\n";
    foreach ($wrong as $r) {
        printf("  %s expected %s, got %s (confidence %.2f, rule %s, top score %.2f)\n",
            $r['id'], $r['expected'], $r['decided'], $r['conf'], $r['rule'] ?? 'none', $r['top_score']);
    }
}

if (isset($opts['json'])) {
    file_put_contents(__DIR__ . '/../data/eval_results.json', json_encode([
        'model' => $llm->model(), 'k' => $k, 'n' => $n,
        'category_hit_rate' => $catHits / $n, 'root_cause_hit_rate' => $rcHits / max(1, $rcTotal), 'action_accuracy' => $actionHits / $n,
        'confusion' => $confusion, 'rows' => $rows,
    ], JSON_PRETTY_PRINT));
    echo "\nWritten data/eval_results.json\n";
}
