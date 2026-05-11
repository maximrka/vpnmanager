<?php

declare(strict_types=1);

namespace App;

use PDO;

final class AuditService
{
    public function __construct(private PDO $pdo)
    {
    }

    public function log(?int $userId, string $action, string $result, array $details = []): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO audit_log(user_id, action, target_type, target_id, details_json, result, created_at, ip) VALUES (:uid, :action, :tt, :tid, :dj, :result, datetime(\'now\'), :ip)'
        );
        $stmt->execute([
            ':uid' => $userId,
            ':action' => $action,
            ':tt' => $details['target_type'] ?? null,
            ':tid' => $details['target_id'] ?? null,
            ':dj' => json_encode($details, JSON_UNESCAPED_UNICODE),
            ':result' => $result,
            ':ip' => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    }

    public function last(int $limit = 20): array
    {
        $stmt = $this->pdo->prepare('SELECT created_at, action, result, ip FROM audit_log ORDER BY id DESC LIMIT :l');
        $stmt->bindValue(':l', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
