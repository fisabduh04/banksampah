<?php

use Tests\TestCase;

uses(TestCase::class);

test('example', function () {
    $response = $this->get('/');

    $response->assertStatus(200);
});
