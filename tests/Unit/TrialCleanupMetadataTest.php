<?php

use App\Services\TrialCleanupService;

/** @return array<string, mixed> */
function cleanupMetadataFixture(): array
{
    return ['failed_jobs' => [
        'engine' => 'InnoDB',
        'columns' => [[
            'name' => 'failed_at', 'type' => 'timestamp', 'full_type' => 'timestamp',
            'nullable' => 'NO', 'default_value' => 'CURRENT_TIMESTAMP', 'max_length' => null,
            'numeric_precision' => null, 'numeric_scale' => null, 'datetime_precision' => '0',
            'charset' => null, 'collation' => null, 'extra' => 'DEFAULT_GENERATED', 'generation_expression' => '',
        ]],
        'indexes' => [],
        'foreign_keys' => [
            ['name' => 'first_fk', 'column_name' => 'first_id', 'parent_table' => 'users', 'parent_column' => 'id', 'delete_rule' => 'NO ACTION', 'update_rule' => 'NO ACTION'],
            ['name' => 'second_fk', 'column_name' => 'second_id', 'parent_table' => 'users', 'parent_column' => 'id', 'delete_rule' => 'CASCADE', 'update_rule' => 'NO ACTION'],
        ],
    ]];
}

test('equivalent MariaDB timestamp and every InnoDB no action rule match a MySQL manifest', function (): void {
    $mysql = cleanupMetadataFixture();
    $maria = $mysql;
    $maria['failed_jobs']['columns'][0]['default_value'] = 'current_timestamp()';
    $maria['failed_jobs']['columns'][0]['extra'] = '';
    $maria['failed_jobs']['foreign_keys'][0]['delete_rule'] = 'RESTRICT';
    $maria['failed_jobs']['foreign_keys'][0]['update_rule'] = 'RESTRICT';
    $maria['failed_jobs']['foreign_keys'][1]['update_rule'] = 'RESTRICT';
    $service = new TrialCleanupService;

    expect($service->schemaDifferences($maria, $mysql))->toBe([]);
    expect($service->schemaDifferences($mysql, $maria))->toBe([]);
    unset($mysql['failed_jobs']['engine']);
    expect($service->schemaDifferences($maria, $mysql))->toBe([]);
});

test('foreign key equivalence is never applied to an unproven or non InnoDB engine', function (?string $engine): void {
    $mysql = cleanupMetadataFixture();
    $actual = $mysql;
    $actual['failed_jobs']['engine'] = $engine;
    $mysql['failed_jobs']['engine'] = $engine;
    $actual['failed_jobs']['foreign_keys'][0]['delete_rule'] = 'RESTRICT';

    expect((new TrialCleanupService)->schemaDifferences($actual, $mysql))->toBe([
        ['path' => 'failed_jobs.foreign_keys.0.delete_rule', 'expected' => 'NO ACTION', 'actual' => 'RESTRICT'],
    ]);
})->with(['MyISAM', 'NDB', null]);

test('all meaningful foreign key differences remain visible together', function (): void {
    $mysql = cleanupMetadataFixture();
    $maria = $mysql;
    $maria['failed_jobs']['foreign_keys'][0]['delete_rule'] = 'SET NULL';
    $maria['failed_jobs']['foreign_keys'][0]['update_rule'] = 'CASCADE';
    $maria['failed_jobs']['foreign_keys'][1]['parent_column'] = 'other_id';
    $maria['failed_jobs']['foreign_keys'][1]['column_name'] = 'other_child_id';
    $maria['failed_jobs']['foreign_keys'][1]['parent_table'] = 'customers';
    $maria['failed_jobs']['foreign_keys'][1]['name'] = 'renamed_fk';

    expect(array_column((new TrialCleanupService)->schemaDifferences($maria, $mysql), 'path'))->toBe([
        'failed_jobs.foreign_keys.0.delete_rule', 'failed_jobs.foreign_keys.0.update_rule',
        'failed_jobs.foreign_keys.1.name', 'failed_jobs.foreign_keys.1.column_name',
        'failed_jobs.foreign_keys.1.parent_table', 'failed_jobs.foreign_keys.1.parent_column',
    ]);
});

test('timestamp normalization requires all other failed at column attributes to match', function (string $field, mixed $value): void {
    $mysql = cleanupMetadataFixture();
    $maria = $mysql;
    $maria['failed_jobs']['columns'][0]['default_value'] = 'current_timestamp()';
    $maria['failed_jobs']['columns'][0]['extra'] = '';
    $maria['failed_jobs']['columns'][0][$field] = $value;

    $paths = array_column((new TrialCleanupService)->schemaDifferences($maria, $mysql), 'path');

    expect($paths)->toContain('failed_jobs.columns.0.'.$field);
    expect($paths)->toContain('failed_jobs.columns.0.default_value');
})->with([
    'nullable' => ['nullable', 'YES'], 'type' => ['type', 'datetime'],
    'precision' => ['datetime_precision', '6'], 'full type' => ['full_type', 'timestamp(6)'],
    'collation' => ['collation', 'utf8mb4_bin'], 'charset' => ['charset', 'utf8mb4'],
    'numeric precision' => ['numeric_precision', '20'], 'length' => ['max_length', '20'],
    'generated expression' => ['generation_expression', 'other_column + 1'],
    'on update' => ['extra', 'on update current_timestamp()'],
    'quoted literal' => ['default_value', "'CURRENT_TIMESTAMP'"],
    'fractional default' => ['default_value', 'current_timestamp(6)'],
    'null default' => ['default_value', null],
]);

test('timestamp equivalence does not extend to another table or column', function (string $table, string $column): void {
    $definition = cleanupMetadataFixture()['failed_jobs'];
    $definition['columns'][0]['name'] = $column;
    $mysql = [$table => $definition];
    $maria = $mysql;
    $maria[$table]['columns'][0]['default_value'] = 'current_timestamp()';
    $maria[$table]['columns'][0]['extra'] = '';

    expect((new TrialCleanupService)->schemaDifferences($maria, $mysql))->toHaveCount(2);
})->with([['other_table', 'failed_at'], ['failed_jobs', 'created_at']]);

test('missing added reordered and composite foreign keys cannot pass metadata comparison', function (string $change): void {
    $mysql = cleanupMetadataFixture();
    $maria = $mysql;
    $keys = &$maria['failed_jobs']['foreign_keys'];
    match ($change) {
        'missing' => array_pop($keys),
        'added' => $keys[] = $keys[0],
        'reordered' => $keys = array_reverse($keys),
        'composite' => $keys[1]['name'] = $keys[0]['name'],
    };

    expect((new TrialCleanupService)->schemaDifferences($maria, $mysql))->not->toBeEmpty();
})->with(['missing', 'added', 'reordered', 'composite']);
