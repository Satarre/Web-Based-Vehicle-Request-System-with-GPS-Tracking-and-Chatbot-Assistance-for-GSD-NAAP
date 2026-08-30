<?php
declare(strict_types=1);
/**
 * api/locations.php — location autocomplete proxy (Photon/Komoot OSM geocoder).
 * GET ?q=search+term&lat=14.5&lon=121.0
 * No API key required. Proxies to photon.komoot.io and normalises the response.
 */
require_once dirname(__DIR__) . '/config/config.php';

header('Content-Type: application/json');

$q   = trim((string) ($_GET['q'] ?? ''));
$lat = isset($_GET['lat']) ? (float) $_GET['lat'] : null;
$lon = isset($_GET['lon']) ? (float) $_GET['lon'] : null;

if (mb_strlen($q) < 2) {
    echo json_encode([]);
    exit;
}

/* Build Photon URL — Philippines only, language English, limit 20 (we filter+sort client-side). */
$params = [
    'q'     => $q,
    'lang'  => 'en',
    'limit' => '20',
];
if ($lat !== null && $lon !== null) {
    $params['lat'] = number_format($lat, 6, '.', '');
    $params['lon'] = number_format($lon, 6, '.', '');
}
$photonUrl = 'https://photon.komoot.io/api/?' . http_build_query($params);

/* Fetch with a short timeout so slow geocoder responses don't block the UI. */
$ctx = stream_context_create([
    'http' => [
        'timeout' => 5,
        'user_agent' => 'NAAP-VTS/1.0 (autocomplete)',
    ],
]);
$json = @file_get_contents($photonUrl, false, $ctx);
if ($json === false) {
    http_response_code(502);
    echo json_encode(['error' => 'Geocoder service unavailable.']);
    exit;
}

$raw = json_decode($json, true);
if (!is_array($raw) || !isset($raw['features'])) {
    echo json_encode([]);
    exit;
}

$results = [];
foreach ($raw['features'] as $f) {
    $props = $f['features'][0]['properties'] ?? $f['properties'] ?? null;
    if (!$props) continue;

    $coords = $f['geometry']['coordinates'] ?? $f['features'][0]['geometry']['coordinates'] ?? null;
    if (!is_array($coords) || count($coords) < 2) continue;

    $name = $props['name'] ?? $props['city'] ?? '';
    if ($name === '') continue;

    /* Philippines-only filter: accept if country is PH or not specified (local bias helps). */
    $country = strtolower(trim((string) ($props['country'] ?? '')));
    $countryCode = strtolower(trim((string) ($props['countrycode'] ?? '')));
    if ($country !== '' && $country !== 'philippines' && $countryCode !== 'ph') {
        continue;
    }

    /* Build a readable address from available properties. */
    $parts = array_filter([
        $props['street'] ?? null,
        $props['city'] ?? null,
        $props['state'] ?? null,
        $props['country'] ?? null,
    ]);
    $address = implode(', ', $parts);
    if ($address === '') $address = $props['osm_value'] ?? '';

    $latVal = (float) $coords[1];
    $lonVal = (float) $coords[0];

    /* Haversine distance for sorting. */
    $dist = 99999;
    if ($lat !== null && $lon !== null) {
        $radLat1 = deg2rad($lat);
        $radLat2 = deg2rad($latVal);
        $dLat = deg2rad($latVal - $lat);
        $dLon = deg2rad($lonVal - $lon);
        $a = sin($dLat / 2) ** 2 + cos($radLat1) * cos($radLat2) * sin($dLon / 2) ** 2;
        $dist = 6371 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    $results[] = [
        'name'       => $name,
        'address'    => $address,
        'latitude'   => $latVal,
        'longitude'  => $lonVal,
        'place_id'   => $props['osm_id'] ?? null,
        'type'       => $props['osm_key'] ?? null,
        'label'      => $props['label'] ?? $name,
        '_dist'      => $dist,
    ];
}

/* Sort nearest-first. */
usort($results, fn($a, $b) => $a['_dist'] <=> $b['_dist']);

/* Deduplicate by name (keep nearest — results already sorted by distance). */
$seen = [];
$deduped = [];
foreach ($results as $r) {
    $key = strtolower($r['name'] ?? '');
    if ($key !== '' && isset($seen[$key])) continue;
    if ($key !== '') $seen[$key] = true;
    $deduped[] = $r;
}
$results = $deduped;

/* Limit to 8 after filtering. */
$results = array_slice($results, 0, 8);

echo json_encode($results);
