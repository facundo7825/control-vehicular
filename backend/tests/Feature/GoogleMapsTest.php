<?php

use App\Mapas\GoogleMaps;
use Illuminate\Support\Facades\Http;

it('devuelve duraciones por clave usando el tráfico si está disponible', function () {
    Http::fake(['maps.googleapis.com/*' => Http::response([
        'status' => 'OK',
        'rows' => [
            ['elements' => [['status' => 'OK', 'duration' => ['value' => 300], 'duration_in_traffic' => ['value' => 420]]]],
            ['elements' => [['status' => 'ZERO_RESULTS']]],
        ],
    ])]);

    $r = (new GoogleMaps('clave'))->duracionesHacia([7 => [-34.6, -58.4], 9 => [-34.7, -58.5]], -34.65, -58.45);

    expect($r)->toBe([7 => 420, 9 => null]);
    Http::assertSent(fn ($req) => str_contains($req->url(), 'origins=-34.6%2C-58.4%7C-34.7%2C-58.5'));
});

it('devuelve nulos si Google falla', function () {
    Http::fake(['maps.googleapis.com/*' => Http::response(['status' => 'REQUEST_DENIED'])]);

    expect((new GoogleMaps('clave'))->duracionesHacia([1 => [0, 0]], 1, 1))->toBe([1 => null]);
});
