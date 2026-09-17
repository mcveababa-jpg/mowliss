<?php
require_once __DIR__ . '/env.php';

// Domain-ready app configuration.
// Change APP_BASE_URL to your live domain later (for example https://mowliss.example.com).
// The app will keep working locally with http://localhost/comeback and will automatically use
// the right public URLs when you deploy to a live host.

$appConfig = [
    'app_name' => 'MoWLiSS',
    'base_url' => env('APP_BASE_URL', 'http://localhost/comeback'),
    'map_tile_url' => env('MAP_TILE_URL', 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png'),
    'map_attribution' => env('MAP_ATTRIBUTION', '&copy; OpenStreetMap contributors'),
    'map_embed_base' => env('MAP_EMBED_BASE', 'https://www.openstreetmap.org/export/embed.html'),
];

function app_base_url(): string
{
    global $appConfig;
    return (string)($appConfig['base_url'] ?? 'http://localhost/comeback');
}

function map_tile_url(): string
{
    global $appConfig;
    return (string)($appConfig['map_tile_url'] ?? 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png');
}

function map_attribution(): string
{
    global $appConfig;
    return (string)($appConfig['map_attribution'] ?? '&copy; OpenStreetMap contributors');
}
