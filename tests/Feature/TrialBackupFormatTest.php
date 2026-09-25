<?php

use App\Services\TrialBackupRestorer;
use Tests\TestCase;

uses(TestCase::class);

test('Hostinger phpMyAdmin full export contains every supported table and foreign key', function (): void {
    $backup = base_path('u846702626_banksampah (1).sql');
    if (! is_file($backup)) {
        $this->markTestSkipped('Private Hostinger backup is not available.');
    }
    $result = app(TrialBackupRestorer::class)->validateSql(file_get_contents($backup));
    expect($result['source_database'])->toBe('u846702626_banksampah');
    expect($result['tables'])->toHaveCount(34);
    expect($result['foreign_keys'])->toBe(42);
});

test('unsupported exports are rejected before opening a restore connection', function (string $sql, string $message): void {
    expect(fn () => app(TrialBackupRestorer::class)->validateSql($sql))->toThrow(RuntimeException::class, $message);
})->with([
    'mysqldump' => ["-- MySQL dump 10.13\nCREATE TABLE `x` (id int);", 'mysqldump/mariadb-dump'],
    'missing tables' => ["-- phpMyAdmin SQL Dump\n-- Database: `source`\nCREATE TABLE `x` (id int) ENGINE=InnoDB;", '34 tabel'],
    'missing source' => ["-- phpMyAdmin SQL Dump\nCREATE TABLE `x` (id int);", 'Format backup'],
    'multiple databases' => ["-- phpMyAdmin SQL Dump\n-- Database: `one`\n-- Database: `two`\n", 'Format backup'],
    'trigger' => ["-- phpMyAdmin SQL Dump\n-- Database: `source`\nCREATE TRIGGER trigger_name BEFORE DELETE ON x FOR EACH ROW SET @x=1;", 'SQL tidak didukung'],
    'view' => ["-- phpMyAdmin SQL Dump\n-- Database: `source`\nCREATE VIEW v AS SELECT 1;", 'SQL tidak didukung'],
    'routine' => ["-- phpMyAdmin SQL Dump\n-- Database: `source`\nCREATE PROCEDURE p() SELECT 1;", 'SQL tidak didukung'],
    'event' => ["-- phpMyAdmin SQL Dump\n-- Database: `source`\nCREATE EVENT e ON SCHEDULE EVERY 1 DAY DO SELECT 1;", 'SQL tidak didukung'],
    'FK disabled' => ["-- phpMyAdmin SQL Dump\n-- Database: `source`\nSET FOREIGN_KEY_CHECKS=0;", 'SQL tidak didukung'],
]);

test('executable comments cannot silently hide database objects or extra statements', function (string $sql): void {
    expect(fn () => app(TrialBackupRestorer::class)->statements($sql))->toThrow(RuntimeException::class, 'Executable SQL comment');
})->with([
    'MariaDB trigger' => '/*M!100100 CREATE TRIGGER x BEFORE DELETE ON y FOR EACH ROW SET @x=1 */;',
    'unversioned' => '/*! CREATE VIEW v AS SELECT 1 */;',
    'charset prefix' => '/*!40101 SET NAMES utf8mb4; DROP TABLE customers */;',
    'MySQL dump directive' => '/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;',
]);

test('valid export envelopes cannot smuggle ALTER options or executable INSERT values', function (string $statement, string $message): void {
    $sql = "-- phpMyAdmin SQL Dump\n-- Database: `source`\nCREATE TABLE `sample` (`id` bigint) ENGINE=InnoDB;\n".$statement;
    expect(fn () => app(TrialBackupRestorer::class)->validateSql($sql))->toThrow(RuntimeException::class, $message);
})->with([
    'engine change' => ['ALTER TABLE `sample` ENGINE=MyISAM;', 'ALTER di luar'],
    'column change' => ['ALTER TABLE `sample` ADD COLUMN hidden int;', 'ALTER di luar'],
    'subquery' => ['INSERT INTO `sample` (`id`) VALUES ((SELECT 1));', 'nilai literal'],
    'function' => ['INSERT INTO `sample` (`id`) VALUES (SLEEP(1));', 'nilai literal'],
    'upsert' => ['INSERT INTO `sample` (`id`) VALUES (1) ON DUPLICATE KEY UPDATE id=2;', 'nilai literal'],
]);
