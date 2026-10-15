<?php
declare(strict_types=1);

namespace RH;

use PDO;

/**
 * SQLite 连接与迁移调度。
 *
 * 单文件库 + WAL，家用实例（几个人、几千条菜谱）这个组合完全够，
 * 备份就等于拷一个文件。多实例并发再换 PostgreSQL。
 */
final class Db
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $dir = Config::dataDir();

        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            Http::fail('数据目录创建失败：' . $dir . '（检查容器挂载与权限）', 500);
        }

        $existed = is_file(Config::dbPath());

        try {
            $pdo = new PDO('sqlite:' . Config::dbPath(), null, null, array(
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ));
        } catch (\Throwable $e) {
            Http::fail('数据库打不开：' . $e->getMessage(), 500);
        }

        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 8000');
        $pdo->exec('PRAGMA synchronous = NORMAL');

        self::$pdo = $pdo;

        if (!$existed) {
            @chmod(Config::dbPath(), 0664);
        }

        return $pdo;
    }

    /** 连库 + 建表 + 灌初始数据，幂等 */
    public static function boot(): void
    {
        self::pdo();
        Schema::migrate();
        Schema::seed();
    }

    /**
     * 断开当前连接。
     *
     * 全项目只有「整库替换」（后台恢复备份）这一个地方用得上，别的地方没必要：
     *   - Windows 上 SQLite 锁着数据文件，连接不关掉连改名都不让；
     *   - Linux 上虽然改得了名，但 PDO 还指着旧 inode，
     *     之后的读写会落进一个已经没有路径指向它的文件里——看起来正常，实际全丢。
     *
     * 关之前先 CHECKPOINT，把 WAL 里的东西并回主库。
     */
    public static function close(): void
    {
        if (self::$pdo instanceof PDO) {
            try {
                self::$pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');
            } catch (\Throwable $e) {
                // 检查点失败不重要，目的只是让 -wal 尽量消失；连接照样要断
            }
        }

        self::$pdo = null;
    }

    public static function version(): int
    {
        return (int) self::pdo()->query('PRAGMA user_version')->fetchColumn();
    }

    public static function setVersion(int $v): void
    {
        self::pdo()->exec('PRAGMA user_version = ' . $v);
    }

    // ------------------------------------------------------------ 查询快捷方法

    /**
     * 绑定参数。
     *
     * 这一步不能省：PDO 默认把所有参数当字符串绑，
     * 而 SQLite 只在「列」上做类型亲和转换，对着 COUNT(...) / CASE...END
     * 这类**表达式**比较时，整型 2 和字符串 '2' 是不相等的。
     * 踩过一次，这里统一按 PHP 类型绑。
     */
    private static function bind(\PDOStatement $st, array $params): void
    {
        $i = 1;

        foreach ($params as $k => $v) {
            $key = is_int($k) ? $i++ : (strpos((string) $k, ':') === 0 ? (string) $k : ':' . $k);

            if ($v === null) {
                $st->bindValue($key, null, PDO::PARAM_NULL);
            } elseif (is_bool($v)) {
                $st->bindValue($key, $v ? 1 : 0, PDO::PARAM_INT);
            } elseif (is_int($v)) {
                $st->bindValue($key, $v, PDO::PARAM_INT);
            } else {
                $st->bindValue($key, (string) $v);
            }
        }
    }

    public static function all(string $sql, array $params = array()): array
    {
        $st = self::pdo()->prepare($sql);
        self::bind($st, $params);
        $st->execute();
        $rows = $st->fetchAll();

        return is_array($rows) ? $rows : array();
    }

    public static function one(string $sql, array $params = array()): ?array
    {
        $st = self::pdo()->prepare($sql);
        self::bind($st, $params);
        $st->execute();
        $row = $st->fetch();

        return is_array($row) ? $row : null;
    }

    public static function value(string $sql, array $params = array())
    {
        $st = self::pdo()->prepare($sql);
        self::bind($st, $params);
        $st->execute();
        $v = $st->fetchColumn();

        return $v === false ? null : $v;
    }

    public static function exec(string $sql, array $params = array()): int
    {
        $st = self::pdo()->prepare($sql);
        self::bind($st, $params);
        $st->execute();

        return $st->rowCount();
    }

    /** 按主键取一行 */
    public static function find(string $table, int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        return self::one('SELECT * FROM ' . self::ident($table) . ' WHERE id = ?', array($id));
    }

    /** 插入一行，返回自增 ID */
    public static function insert(string $table, array $row): int
    {
        $cols = array_keys($row);
        $ph   = array();

        foreach ($cols as $c) {
            $ph[] = ':' . $c;
        }

        $sql = 'INSERT INTO ' . self::ident($table) . ' (' . implode(',', array_map(array(self::class, 'ident'), $cols)) . ')'
            . ' VALUES (' . implode(',', $ph) . ')';

        $st = self::pdo()->prepare($sql);

        foreach ($row as $k => $v) {
            $st->bindValue(':' . $k, $v);
        }

        $st->execute();

        return (int) self::pdo()->lastInsertId();
    }

    /** 按 id 更新，返回影响行数 */
    public static function updateRow(string $table, int $id, array $row): int
    {
        if ($id <= 0 || !$row) {
            return 0;
        }

        $sets = array();

        foreach (array_keys($row) as $c) {
            $sets[] = self::ident($c) . ' = :' . $c;
        }

        $st = self::pdo()->prepare(
            'UPDATE ' . self::ident($table) . ' SET ' . implode(', ', $sets) . ' WHERE id = :__id'
        );

        foreach ($row as $k => $v) {
            $st->bindValue(':' . $k, $v);
        }

        $st->bindValue(':__id', $id, PDO::PARAM_INT);
        $st->execute();

        return $st->rowCount();
    }

    public static function delete(string $table, int $id): int
    {
        return self::exec('DELETE FROM ' . self::ident($table) . ' WHERE id = ?', array($id));
    }

    /** 简单标识符白名单：只允许字母数字下划线，防注入 */
    public static function ident(string $name): string
    {
        return preg_replace('/[^A-Za-z0-9_]/', '', $name) ?? '';
    }

    public static function tx(callable $fn)
    {
        $pdo = self::pdo();
        $own = !$pdo->inTransaction();

        if ($own) {
            $pdo->beginTransaction();
        }

        try {
            $result = $fn($pdo);

            if ($own) {
                $pdo->commit();
            }

            return $result;
        } catch (\Throwable $e) {
            if ($own && $pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }
    }
}
