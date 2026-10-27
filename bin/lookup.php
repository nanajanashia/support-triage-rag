<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Triage\{Db, Embedder, Retriever};

$arg = $argv[1] ?? null;
if ($arg === null) {
    fwrite(STDERR, "Usage: php bin/lookup.php <ticket id>\n       php bin/lookup.php \"subject keywords\"\n");
    exit(1);
}

$retriever = new Retriever(Db::conn(), new Embedder());

if (ctype_digit($arg)) {
    $t = $retriever->byId((int) $arg);
    if ($t === null) {
        echo "No ticket with id #{$arg}.\n";
        exit(1);
    }
    echo "Ticket #{$t['id']}  {$t['category']} / {$t['root_cause']}  ({$t['created_at']})\n";
    echo "Subject: {$t['subject']}\n\n";
    echo "Body:\n" . wordwrap($t['body'], 90) . "\n\n";
    echo "Resolution:\n" . wordwrap($t['resolution'], 90) . "\n";
    exit(0);
}

$rows = $retriever->searchSubject($arg);
if ($rows === []) {
    echo "No subjects containing all of: {$arg}\n";
    exit(0);
}
printf("%d ticket(s) with subject containing all of: %s\n", count($rows), $arg);
foreach ($rows as $r) {
    printf("  #%-3d %-18s %-40s %s\n", $r['id'], $r['category'], $r['root_cause'], $r['subject']);
}
