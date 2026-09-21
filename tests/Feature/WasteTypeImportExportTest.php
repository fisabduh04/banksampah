<?php

use Tests\TestCase;

/*
 * Test ini membutuhkan Laravel TestCase karena menggunakan
 * helper HTTP seperti $this->get().
 */
uses(TestCase::class);

test('halaman aplikasi dapat diakses', function () {
    $response = $this->get('/');

    $response->assertStatus(200);
});
