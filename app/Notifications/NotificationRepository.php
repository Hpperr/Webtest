<?php

declare(strict_types=1);

namespace TrangThu\Notifications;

use PDO;

final class NotificationRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function create(int $userId, string $type, array $payload): void
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $notification = $this->db->prepare(
            'INSERT INTO notifications (user_id, type, payload) VALUES (:user_id, :type, :payload)'
        );
        $notification->execute(['user_id' => $userId, 'type' => $type, 'payload' => $json]);
        $notificationId = (int) $this->db->lastInsertId();

        $outbox = $this->db->prepare(
            "INSERT INTO notification_outbox (notification_id, user_id, event_type, payload, status) "
            . "VALUES (:notification_id, :user_id, :event_type, :payload, 'queued')"
        );
        $outbox->execute([
            'notification_id' => $notificationId,
            'user_id' => $userId,
            'event_type' => $type,
            'payload' => $json,
        ]);
    }

    public function createForFollowers(int $bookId, int $ownerId, string $type, array $payload): void
    {
        $statement = $this->db->prepare(
            'SELECT f.user_id FROM follows f INNER JOIN users u ON u.id = f.user_id '
            . "WHERE f.book_id = :book_id AND f.user_id <> :owner_id AND u.status = 'active'"
        );
        $statement->execute(['book_id' => $bookId, 'owner_id' => $ownerId]);
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $userId) {
            $this->create((int) $userId, $type, $payload);
        }
    }

    public function forUser(int $userId): array
    {
        $statement = $this->db->prepare(
            'SELECT id, type, payload, read_at, created_at FROM notifications '
            . 'WHERE user_id = :user_id ORDER BY created_at DESC, id DESC LIMIT 50'
        );
        $statement->execute(['user_id' => $userId]);
        $items = array_map(static function (array $item): array {
            $item['payload'] = json_decode($item['payload'], true, 512, JSON_THROW_ON_ERROR);
            return $item;
        }, $statement->fetchAll());

        $unread = $this->db->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = :user_id AND read_at IS NULL');
        $unread->execute(['user_id' => $userId]);

        return ['items' => $items, 'unread_count' => (int) $unread->fetchColumn()];
    }

    public function markRead(int $userId, int $notificationId): bool
    {
        $exists = $this->db->prepare('SELECT id FROM notifications WHERE id = :id AND user_id = :user_id LIMIT 1');
        $exists->execute(['id' => $notificationId, 'user_id' => $userId]);
        if ($exists->fetchColumn() === false) {
            return false;
        }
        $update = $this->db->prepare(
            'UPDATE notifications SET read_at = COALESCE(read_at, CURRENT_TIMESTAMP) WHERE id = :id AND user_id = :user_id'
        );
        $update->execute(['id' => $notificationId, 'user_id' => $userId]);

        return true;
    }

    public function claimNext(): ?array
    {
        $this->db->beginTransaction();
        try {
            $job = $this->db->query(
                "SELECT id, user_id, event_type, payload, attempts FROM notification_outbox "
                . "WHERE (status = 'queued' AND available_at <= CURRENT_TIMESTAMP) "
                . "OR (status = 'processing' AND updated_at < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 10 MINUTE)) "
                . 'ORDER BY id ASC LIMIT 1 FOR UPDATE'
            )->fetch();
            if (!is_array($job)) {
                $this->db->commit();
                return null;
            }
            $statement = $this->db->prepare(
                "UPDATE notification_outbox SET status = 'processing', attempts = attempts + 1 WHERE id = :id"
            );
            $statement->execute(['id' => $job['id']]);
            $this->db->commit();
            $job['attempts'] = (int) $job['attempts'] + 1;
            $job['payload'] = json_decode($job['payload'], true, 512, JSON_THROW_ON_ERROR);

            return $job;
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    public function markSent(int $id): void
    {
        $statement = $this->db->prepare(
            "UPDATE notification_outbox SET status = 'sent', sent_at = CURRENT_TIMESTAMP, last_error = NULL "
            . "WHERE id = :id AND status = 'processing'"
        );
        $statement->execute(['id' => $id]);
    }

    public function retry(int $id, int $attempts, string $error): void
    {
        $status = $attempts >= 8 ? 'failed' : 'queued';
        $delay = min(3600, 15 * (2 ** min(8, max(0, $attempts - 1))));
        $statement = $this->db->prepare(
            'UPDATE notification_outbox SET status = :status, available_at = DATE_ADD(CURRENT_TIMESTAMP, INTERVAL :delay SECOND), '
            . 'last_error = :error WHERE id = :id AND status = \'processing\''
        );
        $statement->bindValue(':status', $status);
        $statement->bindValue(':delay', $delay, PDO::PARAM_INT);
        $statement->bindValue(':error', mb_substr($error, 0, 500));
        $statement->bindValue(':id', $id, PDO::PARAM_INT);
        $statement->execute();
    }
}
