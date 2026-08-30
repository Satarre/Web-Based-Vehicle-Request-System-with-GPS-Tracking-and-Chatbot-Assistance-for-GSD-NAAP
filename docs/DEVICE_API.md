# DEVICE_API.md — GPS Device Ingestion Contract (ESP32 / tracker vendors)

Audience: firmware/vendor integrating a dedicated in-vehicle GPS unit (NOT driver phones).

## Endpoint

```
POST http://<server>/NAAP_MN_VTS_Capstone/api/gps/locations.php
Content-Type: application/json          (or application/x-www-form-urlencoded)
Authorization: Bearer <API_TOKEN>
```

- `API_TOKEN`: 64-char hex issued ONCE at registration (mis/fleet.php → Register Device). Store it in device NVS; only its SHA-256 is kept server-side. Losing it = re-register the device.
- HTTPS is **mandatory in production**. The capstone LAN runs plain HTTP by explicit setting `GPS_ALLOW_INSECURE_HTTP=true` — never ship that to prod.

## Payload

```json
{
  "latitude": 14.5126,
  "longitude": 121.0182,
  "speed_kph": 42.5,
  "heading": 180.0,
  "altitude_m": 25.0,
  "accuracy_m": 3.2,
  "ignition": 1,
  "recorded_at": "2026-08-26 07:00:00"
}
```

| Field | Required | Notes |
|---|---|---|
| `latitude` (alias `lat`) | yes | number, −90…90 |
| `longitude` (aliases `lng`,`lon`) | yes | number, −180…180 |
| `speed_kph` (`speed`) | no | clamped 0…300 |
| `heading` (`course`) | no | degrees 0…360 |
| `altitude_m` (`altitude`) | no | −500…10000 m |
| `accuracy_m` (`accuracy`) | no | 0…10000 m |
| `ignition` | no | 0/1 |
| `recorded_at` (`timestamp`) | no | `Y-m-d H:i:s`, ISO-8601, or epoch seconds. Default: server now. **Future timestamps are clamped to now** — keep device RTC synced anyway. |

Unknown extra fields are ignored. Send one fix per POST; batch by retrying queued fixes individually (backdated fixes are always persisted, oldest first).

## Responses

Devices should treat everything ≥200 as success and never parse bodies.

| Code | Meaning |
|---|---|
| **204** | accepted (stored **or** throttled — both fine, just send the next fix later) |
| 400 | invalid payload (bad coords/types) or device not paired to a vehicle |
| 401 | missing/unknown/inactive bearer token |
| 500 | storage failure — retry with backoff |

## Behavior notes

- **Throttle:** fixes newer than the last stored one and < `GPS_MIN_INTERVAL_SEC` (20 s default) apart are dropped server-side; the heartbeat still updates. Recommended device interval: 30–60 s.
- **Geofence:** server computes inside/outside vs campus center+radius and logs transitions; no client-side geofence logic needed.
- **Offline buffering:** queue fixes locally and replay with original `recorded_at` values — replays are persisted, history is never silently lost.
- A fix is attached to the vehicle's active trip automatically; telemetry outside trips is still stored as breadcrumb history.

## Smoke test (curl)

```bash
curl -i -X POST http://localhost/NAAP_MN_VTS_Capstone/api/gps/locations.php \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"latitude":14.5126,"longitude":121.0182,"speed_kph":35}'
# expect: HTTP/1.1 204 No Content
```

Verify visibility: mis/fleet.php heartbeat columns, tracking.php live map, ticket.php GPS route once the trip is IN_PROGRESS.
