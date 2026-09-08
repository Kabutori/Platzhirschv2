<?php
namespace App\Services;
use PDO;
class DatabaseCopy
{
    private function identifier(string $name): string
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/D', $name)) {
            throw new \RuntimeException('unsupported_identifier');
        }
        return '`' . $name . '`';
    }
    public function copy(PDO $source, PDO $target, string $from, string $to): array
    {
        $source->exec('USE ' . $this->identifier($from));
        $target->exec('USE ' . $this->identifier($to));
        $tables = $source->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM);
        foreach ($tables as $table) {
            if ($table[1] !== 'BASE TABLE') {
                throw new \RuntimeException('views_not_supported');
            }
        }
        if ($source->query('SHOW TRIGGERS')->fetch()) {
            throw new \RuntimeException('triggers_not_supported');
        }
        $names = array_column($tables, 0);
        sort($names);
        if (!$names) {
            throw new \RuntimeException('source_empty');
        }
        $source->exec(
            'LOCK TABLES ' . implode(', ', array_map(fn($t) => $this->identifier($t) . ' READ', $names)),
        );
        $source->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        $target->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        try {
            $target->exec('SET FOREIGN_KEY_CHECKS=0');
            $result = [];
            foreach ($names as $name) {
                $table = $this->identifier($name);
                $schema = $source->query('SHOW CREATE TABLE ' . $table);
                $definition = $schema->fetch(PDO::FETCH_NUM)[1];
                $schema->closeCursor();
                if (preg_match('/REFERENCES\s+`[^`]+`\s*\./i', $definition)) {
                    throw new \RuntimeException('cross_database_reference');
                }
                $target->exec($definition);
                $key = $source
                    ->query('SHOW INDEX FROM ' . $table . " WHERE Key_name = 'PRIMARY'")
                    ->fetchAll(PDO::FETCH_ASSOC);
                if (!$key) {
                    throw new \RuntimeException('primary_key_required');
                }
                usort($key, fn($a, $b) => $a['Seq_in_index'] <=> $b['Seq_in_index']);
                $order = implode(',', array_map(fn($k) => $this->identifier($k['Column_name']), $key));
                $rows = $source->query('SELECT * FROM ' . $table . ' ORDER BY ' . $order);
                $statement = null;
                $count = 0;
                while ($row = $rows->fetch(PDO::FETCH_ASSOC)) {
                    if (!$statement) {
                        $statement = $target->prepare(
                            'INSERT INTO ' .
                                $table .
                                ' (' .
                                implode(',', array_map(fn($c) => $this->identifier($c), array_keys($row))) .
                                ') VALUES (' .
                                implode(',', array_fill(0, count($row), '?')) .
                                ')',
                        );
                    }
                    $statement->execute(array_values($row));
                    $count++;
                }
                $left = $this->digest($source, $table, $order);
                $right = $this->digest($target, $table, $order);
                if ($left !== $right) {
                    throw new \RuntimeException('copy_verification_failed');
                }
                $result[$name] = ['rows' => $count, 'sha256' => $left];
            }
            return $result;
        } finally {
            $target->exec(
                'SET FOREIGN_KEY_CHECKS=1',
            ); /* Caller retains source read locks until placement commits. */
        }
    }
    private function digest(PDO $pdo, string $table, string $order): string
    {
        $hash = hash_init('sha256');
        $rows = $pdo->query('SELECT * FROM ' . $table . ' ORDER BY ' . $order);
        while ($row = $rows->fetch(PDO::FETCH_NUM)) {
            hash_update(
                $hash,
                json_encode(
                    array_map(fn($v) => $v === null ? null : base64_encode((string) $v), $row),
                    JSON_THROW_ON_ERROR,
                ) . "\n",
            );
        }
        return hash_final($hash);
    }
}
