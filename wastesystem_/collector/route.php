<?php
session_start();
require_once "../config/database.php";

// Only logged-in collectors can access this page
if (
    !isset($_SESSION["user_id"]) ||
    !isset($_SESSION["role"]) ||
    $_SESSION["role"] !== "collector"
) {
    header("Location: ../index.php");
    exit;
}

$collector_id = (int) $_SESSION["user_id"];

// Get approved requests assigned to this collector
$stmt = $conn->prepare("
    SELECT
        cs.id AS schedule_id,
        cs.collection_date,
        cs.requested_time,
        cs.collection_address,
        cs.notes,
        h.id AS household_id,
        h.latitude,
        h.longitude,
        u.fullname
    FROM collection_schedules cs
    INNER JOIN households h ON h.user_id = cs.user_id
    INNER JOIN users u ON u.id = cs.user_id
    WHERE cs.collector_id = ?
      AND cs.status = 'Approved'
      AND h.latitude IS NOT NULL
      AND h.longitude IS NOT NULL
      AND h.latitude != ''
      AND h.longitude != ''
    ORDER BY cs.collection_date ASC, cs.requested_time ASC
");

if (!$stmt) {
    die("Database query failed: " . htmlspecialchars($conn->error));
}

$stmt->bind_param("i", $collector_id);
$stmt->execute();

$result = $stmt->get_result();

$requests = [];

while ($row = $result->fetch_assoc()) {
    $requests[] = $row;
}

$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>My Collection Route</title>

    <!-- Bootstrap Icons -->
    <link
        rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
    >

    <!-- Leaflet CSS -->
    <link
        rel="stylesheet"
        href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
    >

    <style>
        /* =====================================================
           COLLECTOR ROUTE - SELF-CONTAINED DESIGN
           (does not depend on Bootstrap)
           ===================================================== */

        *,
        *::before,
        *::after {
            box-sizing: border-box;
        }

        :root {
            --green-900: #123d2a;
            --green-800: #17452f;
            --green-700: #198754;
            --green-600: #157347;
            --green-100: #e5f4eb;
            --ink: #263b32;
            --muted: #7b8981;
            --line: #edf1ee;
            --bg: #f4f7f6;
        }

        html,
        body {
            margin: 0;
            padding: 0;
        }

        body {
            background: var(--bg);
            color: var(--ink);
            font-family: "Segoe UI", system-ui, -apple-system, Arial, sans-serif;
            font-size: 14px;
            line-height: 1.5;
            min-height: 100vh;
            padding: 12px 14px 30px;
        }

        /* ---------- NAVBAR ---------- */
        .app-navbar {
            position: sticky;
            top: 12px;
            z-index: 1000;
            max-width: 1200px;
            margin: 0 auto;
            overflow: hidden;
            border-radius: 20px;
            background:
                radial-gradient(circle at 92% -40%, rgba(255, 255, 255, .22), transparent 55%),
                linear-gradient(135deg, #123d2a 0%, #17623f 55%, #198754 100%);
            border: 1px solid rgba(255, 255, 255, .08);
            box-shadow: 0 6px 16px rgba(23, 69, 47, .22);
        }

        .app-navbar-inner {
            padding: 0 24px;
            height: 74px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
        }

        .app-brand {
            display: flex;
            align-items: center;
            gap: 15px;
            min-width: 0;
            color: #fff;
            text-decoration: none;
        }

        .brand-icon {
            width: 48px;
            height: 48px;
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(255, 255, 255, .18);
            border: 1px solid rgba(255, 255, 255, .22);
            border-radius: 14px;
            font-size: 24px;
            line-height: 1;
        }

        .brand-text {
            display: flex;
            align-items: center;
            gap: 12px;
            min-width: 0;
        }

        .brand-title {
            font-size: 21px;
            font-weight: 800;
            letter-spacing: .2px;
            white-space: nowrap;
        }

        .brand-sub {
            padding: 5px 13px;
            background: rgba(255, 255, 255, .16);
            border-radius: 999px;
            font-size: 11.5px;
            font-weight: 800;
            letter-spacing: .8px;
            text-transform: uppercase;
            color: #d6f0e0;
            white-space: nowrap;
        }

        .nav-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            flex-shrink: 0;
            background: #fff;
            color: var(--green-800);
            padding: 10px 20px;
            border-radius: 999px;
            font-size: 13.5px;
            font-weight: 800;
            text-decoration: none;
            box-shadow: 0 2px 8px rgba(0, 0, 0, .15);
            transition: transform .2s, box-shadow .2s;
            white-space: nowrap;
        }

        .nav-pill:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, .22);
        }

        /* ---------- PAGE ---------- */
        .page-container {
            max-width: 1200px;
            margin: 26px auto 0;
        }

        .page-title {
            margin: 0 0 4px;
            color: #183d2b;
            font-size: 26px;
            font-weight: 800;
        }

        .page-subtitle {
            margin: 0 0 22px;
            color: var(--muted);
            font-size: 13px;
        }

        .route-card {
            background: #fff;
            padding: 22px;
            border-radius: 16px;
            border: 1px solid var(--line);
            box-shadow: 0 4px 18px rgba(0, 0, 0, .05);
            margin-bottom: 22px;
        }

        .card-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 18px;
            padding-bottom: 14px;
            border-bottom: 1px solid var(--line);
        }

        .card-title {
            display: flex;
            align-items: center;
            gap: 9px;
            margin: 0;
            color: #244331;
            font-size: 16px;
            font-weight: 800;
        }

        .card-title i {
            color: var(--green-700);
        }

        .count-chip {
            background: var(--green-100);
            color: var(--green-700);
            padding: 5px 12px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 800;
        }

        /* ---------- REQUEST LIST ---------- */
        .request-list {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 16px;
        }

        .request-item {
            display: flex;
            flex-direction: column;
            border: 1px solid #e4ece7;
            border-left: 5px solid var(--green-700);
            border-radius: 12px;
            padding: 16px 18px;
            background: #fff;
            transition: transform .2s, box-shadow .2s;
        }

        .request-item:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(0, 0, 0, .07);
        }

        .request-top {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 12px;
        }

        .request-name {
            margin: 0;
            color: #183d2b;
            font-size: 16px;
            font-weight: 800;
        }

        .request-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            flex-shrink: 0;
            background: var(--green-100);
            color: var(--green-700);
            border-radius: 999px;
            padding: 4px 11px;
            font-size: 11px;
            font-weight: 800;
        }

        .request-info {
            display: flex;
            gap: 9px;
            margin: 0 0 7px;
            font-size: 13px;
            color: #52665a;
            overflow-wrap: anywhere;
        }

        .request-info i {
            color: var(--green-700);
            margin-top: 2px;
            flex-shrink: 0;
        }

        .request-info strong {
            color: var(--ink);
        }

        .request-actions {
            margin-top: auto;
            padding-top: 12px;
        }

        .empty-box {
            text-align: center;
            padding: 36px 16px;
            color: var(--muted);
        }

        .empty-box i {
            display: block;
            font-size: 38px;
            margin-bottom: 8px;
            color: #bdc9c1;
        }

        /* ---------- BUTTONS ---------- */
        .btn-green,
        .btn-light-green {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            min-height: 38px;
            padding: 8px 16px;
            border-radius: 10px;
            border: 1px solid transparent;
            font-family: inherit;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            transition: all .2s ease;
        }

        .btn-green {
            background: var(--green-700);
            color: #fff;
            box-shadow: 0 3px 10px rgba(25, 135, 84, .25);
        }

        .btn-green:hover {
            background: var(--green-600);
            transform: translateY(-1px);
        }

        .btn-light-green {
            background: #fff;
            color: var(--green-700);
            border-color: var(--green-700);
        }

        .btn-light-green:hover {
            background: var(--green-700);
            color: #fff;
        }

        .request-actions .btn-green {
            width: 100%;
        }

        /* ---------- MAP ---------- */
        #map {
            width: 100%;
            height: 500px;
            border-radius: 12px;
            border: 1px solid #dfe8e2;
            z-index: 1;
        }

        #routeStatus {
            display: flex;
            align-items: flex-start;
            gap: 9px;
            margin-top: 14px;
            padding: 11px 15px;
            background: #f4f9f6;
            border: 1px solid #dbeadf;
            border-radius: 10px;
            color: #3d5a4a;
            font-size: 13px;
            font-weight: 600;
        }

        #routeStatus::before {
            content: "ℹ";
            color: var(--green-700);
            font-weight: 800;
        }

        .map-legend {
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
            margin-bottom: 12px;
            font-size: 12px;
            color: #52665a;
            font-weight: 600;
        }

        .map-legend span {
            display: inline-flex;
            align-items: center;
            gap: 7px;
        }

        .legend-dot {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            border: 2px solid #fff;
            box-shadow: 0 0 0 1px rgba(0, 0, 0, .15);
        }

        .legend-green { background: #198754; }
        .legend-blue { background: #0d6efd; }

        /* Pickup marker */
        .pickup-marker {
            background: #198754;
            border: 2px solid white;
            border-radius: 50%;
            width: 25px;
            height: 25px;
            box-shadow: 0 2px 6px rgba(0,0,0,.3);
        }

        /* Collector GPS marker */
        .collector-location-marker {
            background: #0d6efd;
            border: 3px solid white;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,.4);
        }

        @media (max-width: 768px) {
            body {
                padding: 8px 10px 24px;
            }

            .app-navbar {
                top: 8px;
            }

            .app-navbar-inner {
                height: 64px;
                padding: 0 14px;
            }

            .brand-icon {
                width: 40px;
                height: 40px;
                font-size: 20px;
            }

            .brand-title {
                font-size: 16px;
            }

            .brand-sub {
                font-size: 10px;
                padding: 4px 10px;
            }

            .nav-pill {
                padding: 8px 14px;
                font-size: 12px;
            }

            .page-title {
                font-size: 22px;
            }

            .route-card {
                padding: 16px;
            }

            .request-list {
                grid-template-columns: 1fr;
            }

            #map {
                height: 400px;
            }
        }
    </style>
</head>

<body>

<!-- NAVBAR -->
<nav class="app-navbar">
    <div class="app-navbar-inner">

        <a class="app-brand" href="dashboard.php">
            <span class="brand-icon">♻</span>
            <span class="brand-text">
                <span class="brand-title">Waste Collection</span>
                <span class="brand-sub">Collector</span>
            </span>
        </a>


    </div>
</nav>


<main class="page-container">

    <h2 class="page-title">My Collection Route</h2>

    <p class="page-subtitle">
        View approved collection requests assigned to your account.
    </p>


    <!-- ASSIGNED COLLECTION REQUESTS -->
    <div class="route-card">

        <div class="card-head">

            <h5 class="card-title">
                <i class="bi bi-calendar-check"></i>
                Assigned Collection Requests
            </h5>

            <span class="count-chip">
                <?= count($requests); ?> request(s)
            </span>

        </div>

        <?php if (count($requests) > 0): ?>

            <div class="request-list">

            <?php foreach ($requests as $request): ?>

                <div class="request-item">

                    <div class="request-top">

                        <h6 class="request-name">
                            <?= htmlspecialchars($request["fullname"]); ?>
                        </h6>

                        <span class="request-badge">
                            <i class="bi bi-check-circle-fill"></i>
                            Approved
                        </span>

                    </div>

                    <p class="request-info">
                        <i class="bi bi-calendar3"></i>
                        <span>
                            <strong>Date:</strong>
                            <?= htmlspecialchars(date("M d, Y", strtotime($request["collection_date"]))); ?>
                        </span>
                    </p>

                    <p class="request-info">
                        <i class="bi bi-clock"></i>
                        <span>
                            <strong>Time:</strong>
                            <?= htmlspecialchars($request["requested_time"] ?? "Not specified"); ?>
                        </span>
                    </p>

                    <p class="request-info">
                        <i class="bi bi-geo-alt"></i>
                        <span>
                            <strong>Address:</strong>
                            <?= htmlspecialchars($request["collection_address"] ?? "No address"); ?>
                        </span>
                    </p>

                    <?php if (!empty($request["notes"])): ?>
                        <p class="request-info">
                            <i class="bi bi-chat-left-text"></i>
                            <span>
                                <strong>Notes:</strong>
                                <?= htmlspecialchars($request["notes"]); ?>
                            </span>
                        </p>
                    <?php endif; ?>

                    <div class="request-actions">
                        <button
                            type="button"
                            class="btn-green"
                            onclick="showRoute(
                                <?= json_encode((float) $request["latitude"]); ?>,
                                <?= json_encode((float) $request["longitude"]); ?>,
                                <?= (int) $request["schedule_id"]; ?>
                            )">

                            <i class="bi bi-sign-turn-right"></i>
                            Get Directions

                        </button>
                    </div>

                </div>

            <?php endforeach; ?>

            </div>

        <?php else: ?>

            <div class="empty-box">
                <i class="bi bi-inbox"></i>
                No approved collection requests have been assigned to you yet.
            </div>

        <?php endif; ?>

    </div>


    <!-- COLLECTION MAP -->
    <div class="route-card">

        <div class="card-head">

            <h5 class="card-title">
                <i class="bi bi-map"></i>
                Special Pickup Map
            </h5>

            <button type="button" class="btn-light-green" onclick="showAllRequests()">
                <i class="bi bi-arrows-fullscreen"></i>
                Show All
            </button>

        </div>

        <div class="map-legend">
            <span><i class="legend-dot legend-green"></i> Pickup location</span>
            <span><i class="legend-dot legend-blue"></i> Your location</span>
        </div>

        <div id="map"></div>

        <div id="routeStatus">
            Allow location access to get directions from your current position.
        </div>

    </div>

</main>


<!-- Leaflet JavaScript -->
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<script>

// ==========================================
// REQUEST DATA FROM PHP
// ==========================================

const requests = <?= json_encode(array_map(function ($request) {
    return [
        "id" => (int) $request["schedule_id"],
        "name" => $request["fullname"],
        "address" => $request["collection_address"] ?? "No address",
        "latitude" => is_numeric($request["latitude"]) ? (float) $request["latitude"] : null,
        "longitude" => is_numeric($request["longitude"]) ? (float) $request["longitude"] : null
    ];
}, $requests), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;


// ==========================================
// MAP VARIABLES
// ==========================================

let map;

let currentPosition = null;
let currentLocationMarker = null;
let gpsWatchId = null;

let markers = {};

let routePolyline = null;

let selectedScheduleId = null;


// ==========================================
// CHECK COORDINATES
// ==========================================

function hasValidCoordinates(latitude, longitude) {

    return latitude !== null &&
           longitude !== null &&
           Number.isFinite(Number(latitude)) &&
           Number.isFinite(Number(longitude)) &&
           Math.abs(Number(latitude)) <= 90 &&
           Math.abs(Number(longitude)) <= 180;

}


// ==========================================
// ESCAPE HTML
// ==========================================

function escapeHTML(value) {

    return String(value ?? "").replace(/[&<>"']/g, char => ({
        "&": "&amp;",
        "<": "&lt;",
        ">": "&gt;",
        '"': "&quot;",
        "'": "&#39;"
    })[char]);

}


// ==========================================
// POPUP CONTENT
// ==========================================

function getPopupContent(request) {

    return `
        <div style="min-width:190px;max-width:260px;padding:5px;">

            <strong style="font-size:14px;">
                ${escapeHTML(request.name)}
            </strong>

            <hr style="margin:7px 0;">

            <div style="font-size:12px;margin-bottom:5px;">
                <strong>Address:</strong>
                ${escapeHTML(request.address)}
            </div>

            <div style="font-size:12px;">
                <strong>Request ID:</strong>
                #${escapeHTML(request.id)}
            </div>

        </div>
    `;

}


// ==========================================
// INITIALIZE MAP
// ==========================================

function initMap() {

    map = L.map("map").setView([15.4802, 120.5979], 12);

    L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
        maxZoom: 19,
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
    }).addTo(map);

    createRequestMarkers();

    showAllRequests();

    startGPS();

}


// ==========================================
// CREATE REQUEST MARKERS
// ==========================================

function createRequestMarkers() {

    const pickupIcon = L.divIcon({
        className: "",
        html: '<div class="pickup-marker"></div>',
        iconSize: [25, 25],
        iconAnchor: [12, 12]
    });

    requests.forEach(request => {

        if (!hasValidCoordinates(request.latitude, request.longitude)) {
            return;
        }

        const marker = L.marker(
            [
                Number(request.latitude),
                Number(request.longitude)
            ],
            {
                icon: pickupIcon
            }
        );

        marker.bindPopup(getPopupContent(request));

        markers[request.id] = marker;

    });

}


// ==========================================
// COLLECTOR GPS TRACKING
// ==========================================

function startGPS() {

    const message = document.getElementById("routeStatus");

    if (!navigator.geolocation) {

        message.textContent =
            "Your browser does not support GPS location.";

        return;
    }

    message.textContent =
        "Waiting for your current GPS location...";

    gpsWatchId = navigator.geolocation.watchPosition(

        function(position) {

            currentPosition = {
                lat: position.coords.latitude,
                lng: position.coords.longitude
            };

            const gpsIcon = L.divIcon({
                className: "",
                html: '<div class="collector-location-marker"></div>',
                iconSize: [20, 20],
                iconAnchor: [10, 10]
            });

            if (!currentLocationMarker) {

                currentLocationMarker = L.marker(
                    [
                        currentPosition.lat,
                        currentPosition.lng
                    ],
                    {
                        icon: gpsIcon,
                        zIndexOffset: 1000
                    }
                ).addTo(map);

                currentLocationMarker.bindPopup(
                    "<strong>Your Current Location</strong>"
                );

            } else {

                currentLocationMarker.setLatLng([
                    currentPosition.lat,
                    currentPosition.lng
                ]);

            }

            if (!selectedScheduleId) {

                message.textContent =
                    "Your current GPS location is ready. Select Get Directions.";

            }

        },

        function(error) {

            let errorMessage = "Unable to get your GPS location.";

            if (error.code === 1) {
                errorMessage =
                    "Location permission was denied. Please allow location access in your browser.";
            } else if (error.code === 2) {
                errorMessage =
                    "Your current location is unavailable.";
            } else if (error.code === 3) {
                errorMessage =
                    "GPS request timed out. Please try again.";
            }

            if (!currentPosition) {
                message.textContent = errorMessage;
            }

        },

        {
            enableHighAccuracy: true,
            timeout: 15000,
            maximumAge: 5000
        }

    );

}


// ==========================================
// CLEAR PREVIOUS ROUTE
// ==========================================

function clearRoute() {

    if (routePolyline) {

        map.removeLayer(routePolyline);

        routePolyline = null;

    }

}


// ==========================================
// SHOW ALL APPROVED REQUESTS
// ==========================================

function showAllRequests() {

    selectedScheduleId = null;

    clearRoute();

    const visibleMarkers = Object.values(markers);

    if (visibleMarkers.length === 0) {

        document.getElementById("routeStatus").textContent =
            "No valid household coordinates are available.";

        return;
    }

    visibleMarkers.forEach(marker => {

        if (!map.hasLayer(marker)) {
            marker.addTo(map);
        }

    });

    const group = L.featureGroup(visibleMarkers);

    if (visibleMarkers.length === 1) {

        map.setView(group.getBounds().getCenter(), 16);

    } else {

        map.fitBounds(group.getBounds(), {
            padding: [40, 40],
            maxZoom: 16
        });

    }

    document.getElementById("routeStatus").textContent =
        `${visibleMarkers.length} approved collection request(s) displayed on the map.`;

}


// ==========================================
// GET DIRECTIONS TO SELECTED REQUEST
// ==========================================

async function showRoute(latitude, longitude, scheduleId) {

    const message = document.getElementById("routeStatus");

    latitude = Number(latitude);
    longitude = Number(longitude);
    scheduleId = Number(scheduleId);

    if (!hasValidCoordinates(latitude, longitude)) {

        message.textContent =
            "This collection request has invalid GPS coordinates.";

        return;
    }

    if (!currentPosition) {

        message.textContent =
            "Your current GPS location is not available. Please allow location access.";

        return;
    }

    selectedScheduleId = scheduleId;

    message.textContent =
        "Calculating driving route. Please wait...";

    clearRoute();

    try {

        // OSRM requires longitude first, then latitude
        const coordinates =
            `${currentPosition.lng},${currentPosition.lat};${longitude},${latitude}`;

        const osrmURL =
            "https://router.project-osrm.org/route/v1/driving/" +
            coordinates +
            "?overview=full&geometries=geojson&steps=false";

        const response = await fetch(osrmURL);

        if (!response.ok) {

            throw new Error(
                "The routing service could not be reached. Please try again."
            );

        }

        const data = await response.json();

        if (data.code !== "Ok" || !data.routes || !data.routes.length) {

            throw new Error(
                data.message || "No driving route was found."
            );

        }

        const route = data.routes[0];

        // Convert OSRM [longitude, latitude] to Leaflet [latitude, longitude]
        const routeCoordinates = route.geometry.coordinates.map(point => [
            point[1],
            point[0]
        ]);

        // Draw route
        routePolyline = L.polyline(
            routeCoordinates,
            {
                color: "#1976d2",
                weight: 6,
                opacity: 0.85
            }
        ).addTo(map);

        // Show selected pickup marker
        Object.values(markers).forEach(marker => {

            map.removeLayer(marker);

        });

        if (markers[scheduleId]) {
            markers[scheduleId].addTo(map);
        }

        // Keep collector GPS marker visible
        if (currentLocationMarker && !map.hasLayer(currentLocationMarker)) {
            currentLocationMarker.addTo(map);
        }

        // Zoom to route
        const bounds = routePolyline.getBounds();

        if (bounds.isValid()) {

            map.fitBounds(bounds, {
                padding: [40, 40],
                maxZoom: 16
            });

        }

        const distanceKm = (route.distance / 1000).toFixed(2);

        const durationMinutes = Math.round(route.duration / 60);

        message.textContent =
            `Driving route displayed for request #${scheduleId}. ` +
            `Distance: ${distanceKm} km | ` +
            `Estimated driving time: ${durationMinutes} minute(s).`;

    } catch (error) {

        console.error("OSRM routing error:", error);

        message.textContent =
            "Unable to calculate driving route: " + error.message;

    }

}


document.addEventListener("DOMContentLoaded", function() {

    initMap();

});

</script>

</body>
</html>