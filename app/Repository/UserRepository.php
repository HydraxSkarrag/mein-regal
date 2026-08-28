<?php
declare(strict_types=1);

namespace App\Repository;

use PDO;

final class UserRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function findByEmail(string $email): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM users WHERE email = ?');
        $statement->execute([mb_strtolower(trim($email))]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    public function findById(int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM users WHERE id = ?');
        $statement->execute([$id]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    public function create(string $email, string $password, string $displayName, string $locale = 'de', bool $isAdmin = true): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO users (email, password_hash, display_name, locale, is_admin) VALUES (?, ?, ?, ?, ?)'
        );
        $statement->execute([
            mb_strtolower(trim($email)),
            password_hash($password, PASSWORD_DEFAULT),
            trim($displayName),
            $locale,
            $isAdmin ? 1 : 0,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function updatePassword(int $userId, string $password): void
    {
        $statement = $this->pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $statement->execute([password_hash($password, PASSWORD_DEFAULT), $userId]);
    }

    public function updateLocale(int $userId, string $locale): void
    {
        $statement = $this->pdo->prepare('UPDATE users SET locale = ? WHERE id = ?');
        $statement->execute([$locale, $userId]);
    }

    public function count(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    }
}
