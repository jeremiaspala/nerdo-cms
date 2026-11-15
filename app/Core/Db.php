<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOStatement;

/**
 * Capa fina sobre PDO.
 *
 * Reglas que no se rompen:
 *  - EMULATE_PREPARES en false, así la base recibe sentencias preparadas de
 *    verdad y no una interpolación hecha por el driver.
 *  - Todo valor viaja como parámetro. Nunca se concatena entrada del usuario.
 *  - Los identificadores (tabla, columna, dirección de orden) jamás salen de
 *    la entrada: se validan contra lista blanca con orderBy().
 */
final class Db
{
    private PDO $pdo;

    public function __construct(string $dsn, string $user, string $pass, string $timezoneOffset = '+00:00')
    {
        $this->pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);

        // Modo estricto: que la base rechace datos truncados en vez de
        // guardarlos a medias y que falle acá y no en producción.
        $this->pdo->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION'");

        // PHP y la base tienen que coincidir en la hora. Si no, NOW() y
        // date() discrepan y se rompen tres cosas a la vez: las entradas
        // programadas, el vencimiento de sesiones y las ventanas del límite
        // de tasa. El offset se recalcula en cada conexión, así que el
        // horario de verano no lo desfasa.
        if (!preg_match('/^[+-]\d{2}:\d{2}$/', $timezoneOffset)) {
            $timezoneOffset = '+00:00';
        }
        $this->pdo->exec("SET SESSION time_zone = '{$timezoneOffset}'");
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    /**
     * Con EMULATE_PREPARES en false, MySQL recibe los parámetros con tipo.
     * Si un LIMIT viaja como string la consulta es un error de sintaxis, así
     * que acá se deduce el tipo en vez de dejarlo librado a execute().
     */
    public function run(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);

        foreach ($params as $key => $value) {
            $name = is_int($key) ? $key + 1 : ':' . ltrim((string) $key, ':');

            $type = match (true) {
                $value === null => PDO::PARAM_NULL,
                is_bool($value) => PDO::PARAM_BOOL,
                is_int($value) => PDO::PARAM_INT,
                default => PDO::PARAM_STR,
            };

            $stmt->bindValue($name, $value, $type);
        }

        $stmt->execute();

        return $stmt;
    }

    /** @return array<int,array<string,mixed>> */
    public function select(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function first(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();

        return $row === false ? null : $row;
    }

    public function value(string $sql, array $params = []): mixed
    {
        $value = $this->run($sql, $params)->fetchColumn();

        return $value === false ? null : $value;
    }

    public function exists(string $sql, array $params = []): bool
    {
        return $this->first($sql, $params) !== null;
    }

    public function insert(string $table, array $data): int
    {
        $table = $this->ident($table);
        $cols = array_keys($data);
        $placeholders = array_map(static fn (string $c): string => ':' . $c, $cols);

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', array_map([$this, 'ident'], $cols)),
            implode(', ', $placeholders)
        );

        $this->run($sql, $data);

        return (int) $this->pdo->lastInsertId();
    }

    public function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        $sets = [];
        foreach (array_keys($data) as $col) {
            $sets[] = $this->ident($col) . ' = :set_' . $col;
        }

        $params = [];
        foreach ($data as $k => $v) {
            $params['set_' . $k] = $v;
        }

        $sql = sprintf('UPDATE %s SET %s WHERE %s', $this->ident($table), implode(', ', $sets), $where);

        return $this->run($sql, $params + $whereParams)->rowCount();
    }

    public function transaction(callable $fn): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $fn($this);
            $this->pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Único camino permitido para que una preferencia del usuario influya en
     * un ORDER BY: se compara contra una lista blanca, no se escapa.
     */
    public function orderBy(?string $requested, array $allowed, string $default): string
    {
        return in_array($requested, $allowed, true) ? $requested : $default;
    }

    /** Identificadores propios del código, nunca de la entrada. */
    private function ident(string $name): string
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name)) {
            throw new \InvalidArgumentException('Identificador inválido: ' . $name);
        }

        return '`' . $name . '`';
    }
}
