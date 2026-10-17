<?php

it('treats a request that arrived over HTTPS through a proxy as secure', function () {
    $this->withHeaders(['X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => 'relief.example.test', 'X-Forwarded-Port' => '443'])
        ->get('/login')
        ->assertOk();

    expect(request()->isSecure())->toBeTrue()
        ->and(request()->getScheme())->toBe('https')
        ->and(url('/login'))->toStartWith('https://');
});

it('stays plain HTTP when no proxy says otherwise', function () {
    $this->get('/login')->assertOk();

    expect(request()->isSecure())->toBeFalse();
});
