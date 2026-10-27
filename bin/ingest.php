<?php

  declare(strict_types=1);

  require __DIR__ . '/../vendor/autoload.php';

use Triage\{Db, Embedder};
  require __DIR__ . '/../src/Embedder.php';

  $tickets  = json_decode(file_get_contents(__DIR__ . '/../data/tickets.json'), true);
  $db       = Db::conn();
  $embedder = new Embedder();

  $insertTicket = $db->prepare(
      'INSERT INTO tickets (id, subject, body, category, root_cause, resolution, created_at)
       VALUES (:id, :subject, :body, :category, :root_cause, :resolution, :created_at)
       ON DUPLICATE KEY UPDATE subject = VALUES(subject), body = VALUES(body),
           category = VALUES(category), root_cause = VALUES(root_cause),
           resolution = VALUES(resolution), created_at = VALUES(created_at)'
  );

  $insertVector = $db->prepare(
      'INSERT INTO ticket_embeddings (ticket_id, model, vector)
       VALUES (:ticket_id, :model, :vector)
       ON DUPLICATE KEY UPDATE vector = VALUES(vector)'
  );

  $start = microtime(true);
  foreach ($tickets as $i => $t) {
      $insertTicket->execute([
          ':id'         => $t['id'],
          ':subject'    => $t['subject'],
          ':body'       => $t['body'],
          ':category'   => $t['category'],
          ':root_cause' => $t['root_cause'],
          ':resolution' => $t['resolution'],
          ':created_at' => $t['created_at'],
      ]);

      $text   = "Subject: {$t['subject']}\nBody: {$t['body']}";
      $vector = $embedder->embed($text);

      $insertVector->execute([
          ':ticket_id' => $t['id'],
          ':model'     => $embedder->model(),
          ':vector'    => json_encode($vector),
      ]);

      printf("[%3d/%d] #%d %s (%d dims)\n", $i + 1, count($tickets), $t['id'], $t['category'], count($vector));
  }
  printf("Done in %.1fs\n", microtime(true) - $start);