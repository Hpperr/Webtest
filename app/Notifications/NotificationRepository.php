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
}
