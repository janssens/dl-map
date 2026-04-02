<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/settings.php';

$settingsList = list_settings();
if (count($settingsList) === 0){
    http_response_code(500);
    echo "No settings found in ./settings";
    exit;
}

// Preload settings metadata for JS (zoom, tile_size).
$settingsMeta = [];
foreach ($settingsList as $s){
    try {
        $data = load_settings($s['id']);
        $settingsMeta[$s['id']] = [
            'id' => $s['id'],
            'label' => $s['label'],
            // `zoom` is the generation zoom (and default zoom for preview).
            'zoom' => (int)($data['zoom'] ?? 16),
            'leafletMinZoom' => (int)($data['leaflet_min_zoom'] ?? ($data['zoom'] ?? 0)),
            'leafletMaxZoom' => (int)($data['leaflet_max_zoom'] ?? ($data['zoom'] ?? 19)),
            'leafletDefaultZoom' => (int)($data['leaflet_default_zoom'] ?? ($data['zoom'] ?? 16)),
            'tileSize' => (int)(is_array($data['tile_size'] ?? null) ? ($data['tile_size'][0] ?? 256) : 256),
        ];
    } catch (Throwable $e){
        // Skip invalid settings in UI.
    }
}

$defaultSetting = array_key_exists('opentopomap', $settingsMeta)
    ? 'opentopomap'
    : (array_key_first($settingsMeta) ?: $settingsList[0]['id']);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Générateur de carte</title>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.7.1/dist/leaflet.css" />
    <link rel="stylesheet" href="assets/app.css" />
</head>
<body>
<h2>Générer une carte PNG depuis des tuiles</h2>
<div class="muted">Astuce: cliquez 2 fois pour définir le rectangle (NW puis SE). Un 3e clic réinitialise.</div>
<div class="layout" style="margin-top: 0.75rem;">
    <div class="map-col">
        <div class="card">
            <div id="map"></div>
        </div>
    </div>
    <div class="side-col">
        <div class="card">
            <form method="post" action="generate.php" id="gen-form">
                <label for="setting">Fond de carte / settings</label>
                <select name="settings" id="setting">
                    <?php foreach ($settingsList as $s): ?>
                        <?php if (!isset($settingsMeta[$s['id']])){ continue; } ?>
                        <option value="<?= htmlspecialchars($s['id'], ENT_QUOTES) ?>" <?= $s['id'] === $defaultSetting ? 'selected' : '' ?>>
                            <?= htmlspecialchars($s['label'], ENT_QUOTES) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <div style="margin-top: 0.8rem;" class="row">
                    <span class="pill" id="zoom-pill"></span>
                    <span class="pill" id="tiles-pill">Rectangle: non défini</span>
                </div>

                <div style="margin-top: 0.8rem;">
                    <div class="muted">Coordonnées utilisées pour la génération:</div>
                    <div class="coords" id="coords">(sélectionnez un rectangle)</div>
                </div>

                <input type="hidden" name="latTopLeft" id="latTopLeft" value="">
                <input type="hidden" name="lngTopLeft" id="lngTopLeft" value="">
                <input type="hidden" name="latBottomRight" id="latBottomRight" value="">
                <input type="hidden" name="lngBottomRight" id="lngBottomRight" value="">

                <div style="margin-top: 1rem;">
                    <button type="submit" id="submit-btn" disabled>Générer l'image</button>
                </div>
                <div class="muted" style="margin-top: 0.6rem;">
                    La génération télécharge les tuiles (avec cache) puis assemble l'image finale.
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://unpkg.com/leaflet@1.7.1/dist/leaflet.js"></script>
<script>
    const settingsMeta = <?php echo json_encode($settingsMeta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
    const settingSelect = document.querySelector('#setting');
    const zoomPill = document.querySelector('#zoom-pill');
    const tilesPill = document.querySelector('#tiles-pill');

    function currentSetting(){
        const id = settingSelect.value;
        return settingsMeta[id] || null;
    }

    // Default center: Mont Aiguille (France)
    const map = L.map('map').setView([44.8442, 5.5526], 13);

    const layersBySetting = {};
    const baseLayers = {};
    Object.keys(settingsMeta).forEach(function(id){
        const s = settingsMeta[id];
        const layer = L.tileLayer(`tile.php?setting=${encodeURIComponent(s.id)}&z={z}&x={x}&y={y}`, {
            tileSize: s.tileSize || 256,
            minZoom: s.leafletMinZoom,
            maxZoom: s.leafletMaxZoom,
            attribution: 'Tiles proxifiées par le serveur',
        });
        layersBySetting[id] = layer;
        baseLayers[s.label || s.id] = layer;
    });

    let activeSettingId = null;
    function applySetting(settingId, opts){
        opts = opts || {};
        const s = settingsMeta[settingId];
        if (!s){
            return;
        }

        if (activeSettingId && layersBySetting[activeSettingId] && map.hasLayer(layersBySetting[activeSettingId])){
            map.removeLayer(layersBySetting[activeSettingId]);
        }
        activeSettingId = settingId;
        layersBySetting[settingId].addTo(map);

        zoomPill.textContent = `Zoom: ${s.leafletMinZoom} → ${s.leafletMaxZoom} (gen: ${s.zoom})`;
        map.setMinZoom(s.leafletMinZoom);
        map.setMaxZoom(s.leafletMaxZoom);

        const currentZoom = map.getZoom();
        const nextZoom = Math.min(
            s.leafletMaxZoom,
            Math.max(s.leafletMinZoom, isFinite(currentZoom) ? currentZoom : s.leafletDefaultZoom)
        );

        if (opts.forceZoom || currentZoom < s.leafletMinZoom || currentZoom > s.leafletMaxZoom){
            map.setZoom(nextZoom);
        }
    }

    const layerControl = L.control.layers(baseLayers, {}, { position: 'topright' }).addTo(map);

    // Simple place search using Nominatim (OpenStreetMap)
    let searchMarker = null;
    function createGeocoderControl(){
        const control = L.control({ position: 'topleft' });
        control.onAdd = function(){
            const container = L.DomUtil.create('div', 'leaflet-control geocoder');
            container.innerHTML = `
                <div class="geocoder-row">
                    <input id="geocoder-input" type="text" placeholder="Rechercher un lieu…" autocomplete="off" />
                    <button id="geocoder-go" type="button">Go</button>
                </div>
                <div id="geocoder-results" class="geocoder-results"></div>
            `;
            L.DomEvent.disableClickPropagation(container);
            L.DomEvent.disableScrollPropagation(container);
            return container;
        };
        return control;
    }

    function setGeocodeResults(results){
        const resultsEl = document.querySelector('#geocoder-results');
        resultsEl.innerHTML = '';
        if (!results || results.length === 0){
            resultsEl.style.display = 'none';
            return;
        }
        resultsEl.style.display = 'block';
        results.forEach(function(item){
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.textContent = item.display_name;
            btn.addEventListener('click', function(){
                const lat = parseFloat(item.lat);
                const lon = parseFloat(item.lon);
                if (!isFinite(lat) || !isFinite(lon)){
                    return;
                }
                if (searchMarker){
                    map.removeLayer(searchMarker);
                }
                searchMarker = L.marker([lat, lon]).addTo(map);

                if (item.boundingbox && item.boundingbox.length === 4){
                    const south = parseFloat(item.boundingbox[0]);
                    const north = parseFloat(item.boundingbox[1]);
                    const west = parseFloat(item.boundingbox[2]);
                    const east = parseFloat(item.boundingbox[3]);
                    if ([south, north, west, east].every(isFinite)){
                        map.fitBounds([[south, west], [north, east]], { padding: [20, 20] });
                    }else{
                        map.setView([lat, lon], map.getZoom());
                    }
                }else{
                    map.setView([lat, lon], map.getZoom());
                }
                setGeocodeResults([]);
            });
            resultsEl.appendChild(btn);
        });
    }

    async function geocode(query){
        query = (query || '').trim();
        if (!query){
            setGeocodeResults([]);
            return;
        }
        try {
            const url = 'https://nominatim.openstreetmap.org/search?format=jsonv2&limit=6&q=' + encodeURIComponent(query);
            const res = await fetch(url, { headers: { 'Accept': 'application/json' } });
            if (!res.ok){
                setGeocodeResults([]);
                return;
            }
            const json = await res.json();
            setGeocodeResults(json);
        } catch (e){
            setGeocodeResults([]);
        }
    }

    createGeocoderControl().addTo(map);
    document.addEventListener('click', function(e){
        const resultsEl = document.querySelector('#geocoder-results');
        if (!resultsEl){
            return;
        }
        const controlEl = resultsEl.closest('.geocoder');
        if (controlEl && !controlEl.contains(e.target)){
            setGeocodeResults([]);
        }
    });
    document.addEventListener('click', function(e){
        if (e.target && e.target.id === 'geocoder-go'){
            geocode(document.querySelector('#geocoder-input').value);
        }
    });
    document.addEventListener('keydown', function(e){
        const input = document.querySelector('#geocoder-input');
        if (!input || document.activeElement !== input){
            return;
        }
        if (e.key === 'Enter'){
            e.preventDefault();
            geocode(input.value);
        }
        if (e.key === 'Escape'){
            setGeocodeResults([]);
            input.blur();
        }
    });

    let drawnRect = null;
    let firstCorner = null;
    let secondCorner = null;
    let bounds = [];

    function updateSelectionFromMarkers(){
        if (!firstCorner || !secondCorner){
            return;
        }
        const a = firstCorner.getLatLng();
        const b = secondCorner.getLatLng();
        const lBounds = L.latLngBounds(a, b);

        // Keep marker semantics stable: firstCorner = NW, secondCorner = SE.
        firstCorner.setLatLng(lBounds.getNorthWest());
        secondCorner.setLatLng(lBounds.getSouthEast());

        if (drawnRect){
            drawnRect.setBounds(lBounds);
        } else {
            drawnRect = L.rectangle(lBounds, {color: '#00ff78', weight: 1}).addTo(map);
        }

        // Keep `bounds` consistent with the rectangle corners.
        bounds = [lBounds.getNorthWest(), lBounds.getSouthEast()];
        updateForm(lBounds);
    }

    function updateForm(lBounds){
        const nw = lBounds.getNorthWest();
        const se = lBounds.getSouthEast();

        document.querySelector('#latTopLeft').value = nw.lat;
        document.querySelector('#lngTopLeft').value = nw.lng;
        document.querySelector('#latBottomRight').value = se.lat;
        document.querySelector('#lngBottomRight').value = se.lng;

        document.querySelector('#coords').textContent = JSON.stringify({
            latTopLeft: nw.lat,
            lngTopLeft: nw.lng,
            latBottomRight: se.lat,
            lngBottomRight: se.lng,
            settings: settingSelect.value
        }, null, 2);

        document.querySelector('#submit-btn').disabled = false;
        tilesPill.textContent = 'Rectangle: OK';
    }

    map.on('click', function(e) {
        if (drawnRect) {
            map.removeLayer(drawnRect);
            map.removeLayer(firstCorner);
            map.removeLayer(secondCorner);
            bounds = [];
            drawnRect = null;
            firstCorner = null;
            secondCorner = null;
            document.querySelector('#coords').textContent = '(sélectionnez un rectangle)';
            document.querySelector('#submit-btn').disabled = true;
            tilesPill.textContent = 'Rectangle: non défini';
        }

        bounds.push(e.latlng);
        if (bounds.length === 2){
            const lBounds = L.latLngBounds(bounds[0], bounds[1]);
            drawnRect = L.rectangle(lBounds, {color: '#00ff78', weight: 1}).addTo(map);
            if (firstCorner){ map.removeLayer(firstCorner); }
            firstCorner = L.marker(lBounds.getNorthWest(), { draggable: true }).addTo(map);
            secondCorner = L.marker(lBounds.getSouthEast(), { draggable: true }).addTo(map);
            firstCorner.on('drag', updateSelectionFromMarkers);
            firstCorner.on('dragend', updateSelectionFromMarkers);
            secondCorner.on('drag', updateSelectionFromMarkers);
            secondCorner.on('dragend', updateSelectionFromMarkers);
            updateForm(lBounds);
        }

        if (bounds.length === 1){
            firstCorner = L.marker(bounds[0], { draggable: true }).addTo(map);
        }
    });

    settingSelect.addEventListener('change', function(){
        applySetting(settingSelect.value, { forceZoom: false });
        if (bounds.length === 2){
            updateForm(L.latLngBounds(bounds[0], bounds[1]));
        }
    });

    map.on('baselayerchange', function(e){
        // Sync dropdown with layer control selection.
        const nextId = Object.keys(layersBySetting).find(id => layersBySetting[id] === e.layer);
        if (!nextId){
            return;
        }
        if (settingSelect.value !== nextId){
            settingSelect.value = nextId;
        }
        applySetting(nextId, { forceZoom: false });
        if (bounds.length === 2){
            updateForm(L.latLngBounds(bounds[0], bounds[1]));
        }
    });

    applySetting(settingSelect.value, { forceZoom: true });
</script>
</body>
</html>
