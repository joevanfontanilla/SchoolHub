<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * A system user. One role per user (docs/17).
 */
final class User
{
    public const ROLE_STUDENT     = 'Student';
    public const ROLE_TEACHER     = 'Teacher';
    public const ROLE_REGISTRAR   = 'Registrar';
    public const ROLE_CASHIER     = 'Cashier';
    public const ROLE_SUPER_ADMIN = 'Super Admin';

    public const STATUS_PENDING  = 'Pending';
    public const STATUS_ACTIVE   = 'Active';
    public const STATUS_DISABLED = 'Disabled';
    public const STATUS_LOCKED   = 'Locked';
    public const STATUS_DELETED  = 'Deleted';

    public int $id;
    public int $role_id;
    public string $username;
    public string $email;
    public string $password_hash;
    public string $first_name;
    public ?string $middle_name;
    public string $last_name;
    public ?string $suffix;
    public string $status;
    public bool $must_change_password;
    public int $failed_login_attempts;
    public ?string $locked_until;
    public ?string $last_login_at;
    public ?string $role_name;
    public ?string $deleted_at = null;
    public ?string $delete_reason = null;

    public static function fromRow(array $row): self
    {
        $user = new self();
        $user->id                    = (int) $row['id'];
        $user->role_id               = (int) $row['role_id'];
        $user->username              = (string) $row['username'];
        $user->email                 = (string) $row['email'];
        $user->password_hash         = (string) $row['password_hash'];
        $user->first_name            = (string) $row['first_name'];
        $user->middle_name           = $row['middle_name'] !== null ? (string) $row['middle_name'] : null;
        $user->last_name             = (string) $row['last_name'];
        $user->suffix                = isset($row['suffix']) && $row['suffix'] !== null && $row['suffix'] !== '' ? (string) $row['suffix'] : null;
        $user->status                = (string) $row['status'];
        $user->must_change_password  = (bool) $row['must_change_password'];
        $user->failed_login_attempts = (int) ($row['failed_login_attempts'] ?? 0);
        $user->locked_until          = $row['locked_until'] ?? null;
        $user->last_login_at         = $row['last_login_at'] ?? null;
        $user->role_name             = isset($row['role_name']) ? (string) $row['role_name'] : null;
        $user->deleted_at            = $row['deleted_at'] ?? null;
        $user->delete_reason         = $row['delete_reason'] ?? null;

        return $user;
    }

    private const SELECT = 'SELECT u.*, r.name AS role_name FROM users u JOIN roles r ON r.id = u.role_id';

    public static function find(int $id): ?self
    {
        $row = Database::selectOne(self::SELECT . ' WHERE u.id = ?', [$id]);
        return $row === null ? null : self::fromRow($row);
    }

    /** Look up by either email or username — docs/07 allows both. */
    public static function findByLogin(string $login): ?self
    {
        $row = Database::selectOne(
            self::SELECT . ' WHERE u.email = ? OR u.username = ? LIMIT 1',
            [$login, $login]
        );
        return $row === null ? null : self::fromRow($row);
    }

    public static function findByEmail(string $email): ?self
    {
        $row = Database::selectOne(self::SELECT . ' WHERE u.email = ?', [$email]);
        return $row === null ? null : self::fromRow($row);
    }

    /**
     * Accounts for the Super Admin's list (docs/04). Rows, not objects: no password hashes.
     *
     * @param array<string,string> $filters q (name, username, email), role, status
     * @return array{rows:array<int,array<string,mixed>>,total:int}
     */
    public static function adminList(array $filters, int $limit = 25, int $offset = 0): array
    {
        $where = ['1 = 1'];
        $bindings = [];
        if (($filters['q'] ?? '') !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $filters['q']) . '%';
            $where[] = "(u.username LIKE ? OR u.email LIKE ? OR CONCAT(u.first_name, ' ', u.last_name) LIKE ?)";
            array_push($bindings, $like, $like, $like);
        }
        if (($filters['role'] ?? '') !== '') {
            $where[] = 'r.name = ?';
            $bindings[] = $filters['role'];
        }
        if (in_array($filters['status'] ?? '', [self::STATUS_ACTIVE, self::STATUS_PENDING, self::STATUS_DISABLED, self::STATUS_LOCKED, self::STATUS_DELETED], true)) {
            $where[] = 'u.status = ?';
            $bindings[] = $filters['status'];
        } else {
            // Deleted accounts show only under their own filter.
            $where[] = 'u.status <> ?';
            $bindings[] = self::STATUS_DELETED;
        }
        $sqlWhere = implode(' AND ', $where);
        $limit = max(1, $limit);
        $offset = max(0, $offset);

        return [
            'rows' => Database::select(
                "SELECT u.id, u.username, u.email, u.first_name, u.middle_name, u.last_name, u.suffix, u.status, u.failed_login_attempts,
                        u.locked_until, u.last_login_at, u.created_at, u.deleted_at, u.delete_reason, r.name AS role_name
                   FROM users u JOIN roles r ON r.id = u.role_id
                  WHERE {$sqlWhere}
                  ORDER BY (u.status = 'Locked') DESC, u.last_name, u.first_name
                  LIMIT {$limit} OFFSET {$offset}",
                $bindings
            ),
            'total' => (int) Database::scalar("SELECT COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id WHERE {$sqlWhere}", $bindings),
        ];
    }

    /** @return array<string,int> status => number of accounts */
    public static function statusCounts(): array
    {
        return array_map('intval', array_column(Database::select('SELECT status, COUNT(*) AS c FROM users GROUP BY status'), 'c', 'status'));
    }

    public function fullName(): string
    {
        $middle = $this->middle_name !== null && $this->middle_name !== ''
            ? ' ' . mb_substr($this->middle_name, 0, 1) . '.'
            : '';
        $suffix = $this->suffix !== null && $this->suffix !== '' ? ' ' . $this->suffix : '';
        return $this->first_name . $middle . ' ' . $this->last_name . $suffix;
    }

    public function initials(): string
    {
        return mb_strtoupper(mb_substr($this->first_name, 0, 1) . mb_substr($this->last_name, 0, 1));
    }

    public function isSuperAdmin(): bool
    {
        return $this->role_name === self::ROLE_SUPER_ADMIN;
    }

    public function isStudent(): bool
    {
        return $this->role_name === self::ROLE_STUDENT;
    }

    public function isTeacher(): bool
    {
        return $this->role_name === self::ROLE_TEACHER;
    }

    public function isRegistrar(): bool
    {
        return $this->role_name === self::ROLE_REGISTRAR;
    }

    public function isCashier(): bool
    {
        return $this->role_name === self::ROLE_CASHIER;
    }

    /**
     * Whether THIS user's role grants a permission (Super Admin holds every one). For
     * services acting on behalf of a user; request gates use Auth::can().
     */
    public function hasPermission(string $permission): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }
        return \App\Core\Database::scalar(
            'SELECT 1 FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id WHERE rp.role_id = ? AND p.`key` = ?',
            [$this->role_id, $permission]
        ) !== null;
    }

    public function isLocked(): bool
    {
        if ($this->status === self::STATUS_LOCKED) {
            // A lockout with an elapsed expiry is no longer a lockout.
            return $this->locked_until === null || strtotime($this->locked_until) > time();
        }
        return false;
    }

    /** Whether this account may hold a session right now (docs/07). */
    public function canSignIn(): bool
    {
        if ($this->status === self::STATUS_DISABLED) {
            return false;
        }
        if ($this->isLocked()) {
            return false;
        }
        return in_array($this->status, [self::STATUS_ACTIVE, self::STATUS_PENDING], true);
    }
}
