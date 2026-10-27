<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Triage\{Db, Embedder, Retriever, Llm, Triage};

$text = $argv[1] ?? null;
if ($text === null) {
    fwrite(STDERR, "Usage: php bin/triage.php \"ticket text\"\n");
    exit(1);
}

$retriever = new Retriever(Db::conn(), new Embedder());

// Exact reference beats semantic search: "ticket 150", "case #12" -> show that record and stop.
if (($id = Retriever::extractTicketId($text)) !== null) {
    $t = $retriever->byId($id);
    if ($t === null) {
        echo "No ticket with id #{$id}.\n";
        exit(1);
    }
    echo "Exact match: ticket #{$t['id']} ({$t['category']} / {$t['root_cause']}, {$t['created_at']})\n";
    echo "Subject:    {$t['subject']}\n\n";
    echo "Body:\n" . wordwrap($t['body'], 90) . "\n\n";
    echo "Resolution:\n" . wordwrap($t['resolution'], 90) . "\n";
    exit(0);
}

$results = $retriever->similar($text, 5);

echo "Similar past tickets:\n";
foreach ($results as $r) {
    $t = $r['ticket'];
    printf("  %.3f  #%-3d %-18s %-40s %s\n", $r['score'], $t['id'], $t['category'], $t['root_cause'], $t['subject']);
}

$llm   = new Llm();
$draft = $llm->draftReply($text, $results);

echo "\nDraft reply ({$llm->model()}):\n";
echo wordwrap($draft, 90) . "\n";

$triage   = new Triage(Db::conn(), $llm);
$decision = $triage->decide($text, $results, $draft);

// Subject = first line or first 80 chars; the CLI takes one string, a real adapter would pass both.
$subject = strtok($text, "\n") ?: $text;
$logId   = $triage->log(mb_substr($subject, 0, 80), $text, $results, $draft, $decision);

echo "\nDecision:\n";
printf("  action:      %s\n", strtoupper($decision['action']));
printf("  confidence:  %.2f\n", $decision['confidence']);
printf("  reason:      %s\n", wordwrap($decision['reason'], 76, "\n               "));
if ($decision['rule_fired'] !== null) {
    printf("  rule:        %s\n", $decision['rule_fired']);
}
if ($decision['matched_case_id'] !== null) {
    printf("  matched:     case #%d\n", $decision['matched_case_id']);
}
printf("  logged as:   triage_log #%d\n", $logId);
