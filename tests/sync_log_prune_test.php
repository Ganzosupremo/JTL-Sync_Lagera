<?php

declare(strict_types=1);

require __DIR__ . '/support/bootstrap.php';

use App\Models\SyncLog;

$db = requireTestDatabase();
$logs = new SyncLog();

$insert = $db->prepare("INSERT INTO sync_logs (created_at, level, source, message) VALUES (?, 'info', 'test', ?)");

foreach ([['-45 days', 'viejo-1'], ['-31 days', 'viejo-2'], ['-29 days', 'reciente-1'], ['-1 hour', 'reciente-2']] as [$age, $message]) {
    $createdAt = date('Y-m-d H:i:s', strtotime($age));
    $insert->bind_param('ss', $createdAt, $message);
    $insert->execute();
}

assertSame(0, $logs->prune(0), 'Con 0 dias no debe borrarse nada.');
assertSame(4, count($logs->recent()), 'Con 0 dias deben quedar todos los logs.');

// A batch size of 1 forces several DELETE rounds.
assertSame(2, $logs->prune(30, 1), 'Deben borrarse los logs con mas de 30 dias.');
assertSame(['reciente-2', 'reciente-1'], array_column($logs->recent(), 'message'), 'Los logs recientes deben conservarse.');

echo "sync_log_prune_test: OK\n";
