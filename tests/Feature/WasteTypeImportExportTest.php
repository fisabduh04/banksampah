<?php

use Tests\TestCase;

uses(TestCase::class);

test('example', function (): void {
    $response = $this->get('/');

    $response->assertStatus(200);
});
