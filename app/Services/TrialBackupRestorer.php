<?php

namespace App\Services;

use PDO;
use RuntimeException;

class TrialBackupRestorer
{
    /** @return array{source_sha256: string, source_database: string, target_database: string, tables: int, foreign_keys: int} */
    public function restore(string $path, string $sha256, string $target, string $server): array
    {
        if (! preg_match('/\Abanksampah_cleanup_testing_[a-z0-9_]{1,35}\z/', $target)) {
            throw new RuntimeException('Restore hanya ke database banksampah_cleanup_testing_* baru.');
        }
        if (! is_file($path) || ! hash_equals($sha256, hash_file('sha256', $path))) {
            throw new RuntimeException('Hash backup tidak cocok.');
        }
        $validated = $this->validateSql(file_get_contents($path));
        $source = $validated['source_database'];
        $statements = $validated['statements'];
        $tables = $validated['tables'];
        $foreignKeys = $validated['foreign_keys'];
        $pdo = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', config('database.connections.mysql.username'), config('database.connections.mysql.password'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::MYSQL_ATTR_MULTI_STATEMENTS => false]);
        if ($pdo->query('SELECT @@hostname')->fetchColumn() !== $server || $server === '') {
            throw new RuntimeException('Server lokal restore berbeda dari server yang diharapkan.');
        }
        $pdo->exec('CREATE DATABASE `'.$target.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $pdo->exec('USE `'.$target.'`');
        $pdo->exec("SET time_zone = '+00:00'");
        if ((int) $pdo->query('SELECT @@foreign_key_checks')->fetchColumn() !== 1) {
            throw new RuntimeException('Foreign key harus aktif selama restore.');
        }
        foreach ($statements as $statement) {
            $pdo->exec($statement);
        }
        $actualTables = (int) $pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()')->fetchColumn();
        $actualKeys = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.table_constraints WHERE constraint_schema = DATABASE() AND constraint_type = 'FOREIGN KEY'")->fetchColumn();
        if ($actualTables !== count($tables) || $actualKeys !== $foreignKeys) {
            throw new RuntimeException('Restore tidak lengkap. Salinan tidak boleh dipakai untuk simulasi.');
        }

        return ['source_sha256' => $sha256, 'source_database' => $source, 'target_database' => $target, 'tables' => $actualTables, 'foreign_keys' => $actualKeys];
    }

    /** Validate the complete supported phpMyAdmin export before creating any database.
     * @return array{source_database: string, statements: list<string>, tables: list<string>, foreign_keys: int}
     */
    public function validateSql(string $sql): array
    {
        if (! str_starts_with($sql, '-- phpMyAdmin SQL Dump') || preg_match_all('/^-- Database: `([a-zA-Z0-9_]+)`/m', $sql, $sources) !== 1) {
            throw new RuntimeException('Format backup tidak didukung: gunakan ekspor SQL lengkap phpMyAdmin. mysqldump/mariadb-dump belum didukung; tidak ada restore yang dimulai.');
        }
        $statements = $this->statements($sql);
        $tables = [];
        $foreignKeys = 0;
        foreach ($statements as $statement) {
            if (preg_match('/\ACREATE TABLE `([a-z_]+)` \(/', $statement, $match)) {
                if (! preg_match('/\) ENGINE=InnoDB\b/', $statement) || preg_match('/\b(?:SELECT|DATA DIRECTORY|INDEX DIRECTORY|TABLESPACE|PARTITION)\b/i', $statement)) {
                    throw new RuntimeException('Definisi CREATE TABLE di luar format InnoDB yang didukung.');
                }
                $tables[] = $match[1];
            } elseif (preg_match('/\A(?:INSERT INTO|ALTER TABLE) `([a-z_]+)`\s/', $statement, $match)) {
                if (! in_array($match[1], $tables, true)) {
                    throw new RuntimeException('Data/ALTER merujuk tabel yang belum didefinisikan.');
                }
                if (str_starts_with($statement, 'INSERT INTO')) {
                    $value = "(?:NULL|-?[0-9]+(?:\\.[0-9]+)?|0x[0-9a-fA-F]+|'(?:[^'\\\\]++|\\\\.|'')*+')";
                    $tuple = '\(\s*'.$value.'(?:,\s*'.$value.')*\s*\)';
                    if (! preg_match('/\AINSERT INTO `[a-z_]+` \(`[a-z_]+`(?:,\s*`[a-z_]+`)*\) VALUES\s*'.$tuple.'(?:,\s*'.$tuple.')*\z/s', $statement)) {
                        throw new RuntimeException('Hanya complete INSERT dengan nilai literal phpMyAdmin yang didukung.');
                    }
                } else {
                    $columns = '\(`[a-z_]+`(?:\([0-9]+\))?(?:,\s*`[a-z_]+`(?:\([0-9]+\))?)*\)';
                    $action = '(?:CASCADE|SET NULL|RESTRICT|NO ACTION)';
                    $add = 'ADD (?:(?:PRIMARY KEY|(?:UNIQUE )?KEY `[a-z_]+`) '.$columns.'|CONSTRAINT `[a-z_]+` FOREIGN KEY '.$columns.' REFERENCES `[a-z_]+` '.$columns.'(?: ON DELETE '.$action.')?(?: ON UPDATE '.$action.')?)';
                    $modify = 'MODIFY `id` (?:bigint|int)(?:\([0-9]+\))? UNSIGNED NOT NULL AUTO_INCREMENT(?:, AUTO_INCREMENT=[0-9]+)?';
                    if (! preg_match('/\AALTER TABLE `[a-z_]+`\s+(?:'.$add.'(?:,\s*'.$add.')*|'.$modify.')\z/s', $statement)) {
                        throw new RuntimeException('ALTER di luar indeks, foreign key, dan auto-increment phpMyAdmin; restore belum dimulai.');
                    }
                }
            } elseif (! in_array($statement, ['SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO"', 'SET time_zone = "+00:00"', 'START TRANSACTION', 'COMMIT'], true)) {
                throw new RuntimeException('SQL tidak didukung (termasuk view/trigger/routine/event); restore belum dimulai.');
            }
            if (! str_starts_with($statement, 'INSERT INTO') && preg_match('/\b(?:FOREIGN_KEY_CHECKS|TRUNCATE|DROP|RENAME|EXCHANGE|IMPORT|DISCARD|DISABLE|USE)\b/i', $statement)) {
                throw new RuntimeException('Instruksi restore tidak diperbolehkan; restore belum dimulai.');
            }
            if (! str_starts_with($statement, 'INSERT INTO')) {
                $foreignKeys += preg_match_all('/FOREIGN KEY \(/', $statement);
            }
        }
        $expected = [...TrialCleanupService::TRANSACTIONS, ...TrialCleanupService::PRESERVE];
        sort($expected);
        $sorted = $tables;
        sort($sorted);
        if ($sorted !== $expected || $foreignKeys !== 42) {
            throw new RuntimeException('Format snapshot aplikasi membutuhkan 34 tabel lengkap dan 42 FK; struktur berubah/tidak lengkap harus direview sebelum restore.');
        }

        return ['source_database' => $sources[1][0], 'statements' => $statements, 'tables' => $tables, 'foreign_keys' => $foreignKeys];
    }

    /** @return list<string> */
    public function statements(string $sql): array
    {
        $result = [];
        $buffer = '';
        $quote = null;
        $length = strlen($sql);
        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $next = $sql[$i + 1] ?? '';
            if ($quote !== null) {
                $buffer .= $char;
                if ($char === '\\' && $quote !== '`' && $next !== '') {
                    $buffer .= $next;
                    $i++;
                } elseif ($char === $quote) {
                    if ($next === $quote) {
                        $buffer .= $next;
                        $i++;
                    } else {
                        $quote = null;
                    }
                }

                continue;
            }
            if (in_array($char, ["'", '"', '`'], true)) {
                $quote = $char;
                $buffer .= $char;
            } elseif (($char === '-' && $next === '-' && ctype_space($sql[$i + 2] ?? ' ')) || $char === '#') {
                while ($i < $length && $sql[$i] !== "\n") {
                    $i++;
                }
                $buffer .= ' ';
            } elseif ($char === '/' && $next === '*') {
                $end = strpos($sql, '*/', $i + 2);
                if ($end === false) {
                    throw new RuntimeException('Komentar SQL tidak lengkap.');
                }
                $comment = substr($sql, $i, $end + 2 - $i);
                if (str_starts_with($comment, '/*!') || str_starts_with($comment, '/*M!')) {
                    if (! preg_match('/\A\/\*!40101 SET (?:@OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT|@OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS|@OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION|NAMES utf8mb4|CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT|CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS|COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION) \*\/\z/', $comment)) {
                        throw new RuntimeException('Executable SQL comment tidak didukung; ekspor phpMyAdmin lengkap diperlukan, restore belum dimulai.');
                    }
                }
                $i = $end + 1;
                $buffer .= ' ';
            } elseif ($char === ';') {
                if (trim($buffer) !== '') {
                    $result[] = trim($buffer);
                }
                $buffer = '';
            } else {
                $buffer .= $char;
            }
        }
        if ($quote !== null || trim($buffer) !== '') {
            throw new RuntimeException('SQL tidak lengkap atau tidak diakhiri titik koma.');
        }

        return $result;
    }
}
