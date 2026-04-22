<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/app.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/layers.php';
require_once __DIR__ . '/lib/layout.php';

app_boot();
$user = auth_current_user();

$extraHeadHtml = '';

if (!$user){
    layout_header("Casse dalles", null);
    ?>
    <div class="card">
        <h2 style="margin-top:0;">Générer une carte PNG depuis des tuiles</h2>
        <p class="muted" style="margin-top:0.5rem;">
            Sélectionnez une zone sur la carte, choisissez un fond de carte (layer), puis lancez une génération.
            L'application télécharge les tuiles (avec cache) et assemble l'image finale, avec des exports utiles (PNG/JPG/GPX/KMZ/OMAP…).
        </p>
        <div class="row" style="margin-top:1rem;">
            <a class="btn" href="/register.php">Créer un compte</a>
            <a class="btn secondary" href="/login.php">Se connecter</a>
        </div>
        <div class="muted" style="margin-top:0.75rem;">
            Un compte est requis pour accéder à l'outil (gestion des layers, droits premium, layers privés).
        </div>
    </div>
    <?php
    layout_footer();
    exit;
}

$layers = layers_list_for_user($user);
if (count($layers) === 0){
    http_response_code(500);
    echo "No layers found";
    exit;
}

$jobLimitError = ((string)($_GET['error'] ?? '') === 'job_limit');

// Preload settings metadata for JS (zoom, tile_size).
$settingsMeta = [];
foreach ($layers as $layer){
    try {
        $data = $layer['settings'];
        $label = (string)$layer['label'];
        if (($layer['owner_user_id'] ?? null) !== null){
            $label .= ' (privé)';
        } elseif (($layer['access'] ?? '') === 'premium'){
            $label .= ' (premium)';
        } elseif (($layer['access'] ?? '') === 'admin'){
            $label .= ' (admin)';
        }
        $urlTemplate = (string)($data['url'] ?? '');
        $layerName = (string)($data['layer'] ?? '');
        $style = (string)($data['style'] ?? 'normal');
        $ext = (string)($data['file_ext'] ?? 'png');
        $format = 'image/' . $ext;
        $previewUrl = str_replace(
            ['{style}', '{layer}', '{format}', '{zoom}', '{col}', '{row}'],
            [$style, $layerName, $format, '{z}', '{x}', '{y}'],
            $urlTemplate
        );
        $hasCookieAuth = isset($data['cookies']) && is_array($data['cookies']) && count($data['cookies']) > 0;
        $useProxyForPreview = $previewUrl === '' || $hasCookieAuth;
        $settingsMeta[$layer['slug']] = [
            'id' => (string)$layer['slug'],
            'label' => $label,
            'allowed' => (bool)($layer['allowed'] ?? false),
            // `zoom` is the generation zoom (and default zoom for preview).
            'zoom' => (int)($data['zoom'] ?? 16),
            'leafletMinZoom' => (int)($data['leaflet_min_zoom'] ?? ($data['zoom'] ?? 0)),
            'leafletMaxZoom' => (int)($data['leaflet_max_zoom'] ?? ($data['zoom'] ?? 19)),
            'leafletDefaultZoom' => (int)($data['leaflet_default_zoom'] ?? ($data['zoom'] ?? 16)),
            'tileSize' => (int)(is_array($data['tile_size'] ?? null) ? ($data['tile_size'][0] ?? 256) : 256),
            'previewUrl' => $previewUrl,
            'useProxyForPreview' => $useProxyForPreview,
        ];
    } catch (Throwable $e){
        // Skip invalid settings in UI.
    }
}

$defaultSetting = null;
if (array_key_exists('opentopomap', $settingsMeta) && !empty($settingsMeta['opentopomap']['allowed'])){
    $defaultSetting = 'opentopomap';
}
if ($defaultSetting === null){
    foreach ($settingsMeta as $id => $meta){
        if (!empty($meta['allowed'])){
            $defaultSetting = (string)$id;
            break;
        }
    }
}
if ($defaultSetting === null){
    $defaultSetting = (array_key_first($settingsMeta) ?: (string)$layers[0]['slug']);
}

$extraHeadHtml = '<link rel="stylesheet" href="/assets/vendor/leaflet/leaflet.css" />';
layout_header('Générateur de carte', $user, $extraHeadHtml);
?>
<?php if ($jobLimitError): ?>
    <div class="error" style="margin-bottom: 0.75rem;">
        Vous avez atteint le nombre de génération maximal pour le compte gratuit, supprimez des résultat si vous voulez en créer de nouveau.
        <div style="margin-top:0.5rem;">
            <a class="btn secondary" href="/jobs.php">Ouvrir mes jobs</a>
        </div>
    </div>
<?php endif; ?>
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
                    <?php foreach ($layers as $layer): ?>
                        <?php if (!isset($settingsMeta[$layer['slug']])){ continue; } ?>
                        <option value="<?= htmlspecialchars((string)$layer['slug'], ENT_QUOTES) ?>" <?= (string)$layer['slug'] === $defaultSetting ? 'selected' : '' ?> <?= empty($layer['allowed']) ? 'disabled' : '' ?>>
                            <?= htmlspecialchars((string)$settingsMeta[$layer['slug']]['label'], ENT_QUOTES) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <div style="margin-top: 0.8rem;" class="row">
                    <span class="pill" id="zoom-pill"></span>
                    <span class="pill" id="tiles-pill">Rectangle: non défini</span>
                    <span class="pill" id="access-pill" style="display:none;"></span>
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

<script src="/assets/vendor/leaflet/leaflet.js"></script>
<script>
    const settingsMeta = <?php echo json_encode($settingsMeta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
    const settingSelect = document.querySelector('#setting');
    const zoomPill = document.querySelector('#zoom-pill');
    const tilesPill = document.querySelector('#tiles-pill');
    const accessPill = document.querySelector('#access-pill');
    const submitBtn = document.querySelector('#submit-btn');

    function currentSetting(){
        const id = settingSelect.value;
        return settingsMeta[id] || null;
    }

    function getCookie(name){
        const prefix = name + '=';
        const parts = (document.cookie || '').split(';');
        for (const part of parts){
            const trimmed = part.trim();
            if (trimmed.startsWith(prefix)){
                return decodeURIComponent(trimmed.substring(prefix.length));
            }
        }
        return null;
    }

    function setCookie(name, value, maxAgeSeconds){
        const safe = encodeURIComponent(String(value));
        const attrs = [
            `${name}=${safe}`,
            `Max-Age=${Math.max(0, Math.floor(maxAgeSeconds || 0))}`,
            'Path=/',
            'SameSite=Lax',
        ];
        document.cookie = attrs.join('; ');
    }

    function loadSavedMapView(){
        const raw = getCookie('dlmap_view_v1');
        if (!raw){
            return null;
        }
        try {
            const json = JSON.parse(raw);
            const lat = parseFloat(json.lat);
            const lng = parseFloat(json.lng);
            const zoom = parseInt(json.zoom, 10);
            if (!isFinite(lat) || !isFinite(lng) || !isFinite(zoom)){
                return null;
            }
            if (lat < -90 || lat > 90 || lng < -180 || lng > 180){
                return null;
            }
            return { lat, lng, zoom };
        } catch (e){
            return null;
        }
    }

    function saveMapView(map){
        const c = map.getCenter();
        const z = map.getZoom();
        const payload = JSON.stringify({ lat: c.lat, lng: c.lng, zoom: z });
        // 180 days
        setCookie('dlmap_view_v1', payload, 180 * 24 * 60 * 60);
    }

    // Default center: Mont Aiguille (France)
    const savedView = loadSavedMapView();
    const map = L.map('map').setView(
        savedView ? [savedView.lat, savedView.lng] : [44.8442, 5.5526],
        savedView ? savedView.zoom : 13
    );
    map.on('moveend', function(){
        saveMapView(map);
    });

    const settingIds = Object.keys(settingsMeta);
    const minPreviewZoom = settingIds.reduce(function(acc, id){
        return Math.min(acc, settingsMeta[id].leafletMinZoom);
    }, 19);
    const maxPreviewZoom = settingIds.reduce(function(acc, id){
        return Math.max(acc, settingsMeta[id].leafletMaxZoom);
    }, 0);
    map.setMinZoom(minPreviewZoom);
    map.setMaxZoom(maxPreviewZoom);

    const layersBySetting = {};
    const baseLayers = {};
    settingIds.forEach(function(id){
        const s = settingsMeta[id];
        const tileUrl = s.useProxyForPreview
            ? `tile.php?setting=${encodeURIComponent(s.id)}&z={z}&x={x}&y={y}`
            : s.previewUrl;
        const attribution = s.useProxyForPreview
            ? 'Tiles proxifiees par le serveur'
            : 'Tiles source directe';
        const layer = L.tileLayer(tileUrl, {
            tileSize: s.tileSize || 256,
            minZoom: s.leafletMinZoom,
            maxZoom: s.leafletMaxZoom,
            attribution: attribution,
        });
        layersBySetting[id] = layer;
        baseLayers[s.label || s.id] = layer;
    });

    function updateGenerationSettingUi(){
        const s = currentSetting();
        if (!s){
            zoomPill.textContent = 'Zoom: -';
            return;
        }
        zoomPill.textContent = `Zoom: ${s.leafletMinZoom} → ${s.leafletMaxZoom} (gen: ${s.zoom})`;
        if (!s.allowed){
            accessPill.style.display = 'inline-block';
            accessPill.textContent = 'Compte premium requis';
            submitBtn.disabled = true;
        } else {
            accessPill.style.display = 'none';
            accessPill.textContent = '';
            // Submit button is enabled only when rectangle is selected.
            if (bounds.length === 2){
                submitBtn.disabled = false;
            }
        }
    }

    const layerControl = L.control.layers(baseLayers, {}, { position: 'topright' }).addTo(map);
    const initialPreviewLayer = layersBySetting[settingSelect.value] || layersBySetting[settingIds[0]];
    if (initialPreviewLayer){
        initialPreviewLayer.addTo(map);
    }

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
    let pendingCorner = null;
    let bounds = [];

    function clearSelection(){
        if (drawnRect){ map.removeLayer(drawnRect); }
        if (firstCorner){ map.removeLayer(firstCorner); }
        if (secondCorner){ map.removeLayer(secondCorner); }
        if (pendingCorner){ map.removeLayer(pendingCorner); }
        drawnRect = null;
        firstCorner = null;
        secondCorner = null;
        pendingCorner = null;
        bounds = [];
        document.querySelector('#latTopLeft').value = '';
        document.querySelector('#lngTopLeft').value = '';
        document.querySelector('#latBottomRight').value = '';
        document.querySelector('#lngBottomRight').value = '';
        document.querySelector('#coords').textContent = '(sélectionnez un rectangle)';
        submitBtn.disabled = true;
        tilesPill.textContent = 'Rectangle: non défini';
    }

    function updateSelectionFromMarkers(){
        if (!firstCorner || !secondCorner){
            return;
        }
        const lBounds = L.latLngBounds(firstCorner.getLatLng(), secondCorner.getLatLng());
        firstCorner.setLatLng(lBounds.getNorthWest());
        secondCorner.setLatLng(lBounds.getSouthEast());
        bounds = [lBounds.getNorthWest(), lBounds.getSouthEast()];
        if (drawnRect){
            drawnRect.setBounds(lBounds);
        } else {
            drawnRect = L.rectangle(lBounds, { color: '#00ff78', weight: 1 }).addTo(map);
        }
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

        const s = currentSetting();
        submitBtn.disabled = !s || !s.allowed;
        tilesPill.textContent = 'Rectangle: OK';
    }

    function createSelectionFromCorners(a, b){
        const lBounds = L.latLngBounds(a, b);
        if (pendingCorner){
            map.removeLayer(pendingCorner);
            pendingCorner = null;
        }
        if (drawnRect){ map.removeLayer(drawnRect); }
        if (firstCorner){ map.removeLayer(firstCorner); }
        if (secondCorner){ map.removeLayer(secondCorner); }
        drawnRect = L.rectangle(lBounds, { color: '#00ff78', weight: 1 }).addTo(map);
        firstCorner = L.marker(lBounds.getNorthWest(), { draggable: true }).addTo(map);
        secondCorner = L.marker(lBounds.getSouthEast(), { draggable: true }).addTo(map);
        firstCorner.on('drag', updateSelectionFromMarkers);
        firstCorner.on('dragend', updateSelectionFromMarkers);
        secondCorner.on('drag', updateSelectionFromMarkers);
        secondCorner.on('dragend', updateSelectionFromMarkers);
        updateSelectionFromMarkers();
    }

    map.on('click', function(e) {
        if (drawnRect){
            clearSelection();
            pendingCorner = L.marker(e.latlng, { draggable: false }).addTo(map);
            tilesPill.textContent = 'Rectangle: 1 coin défini';
            return;
        }
        if (!pendingCorner){
            pendingCorner = L.marker(e.latlng, { draggable: false }).addTo(map);
            tilesPill.textContent = 'Rectangle: 1 coin défini';
            return;
        }
        createSelectionFromCorners(pendingCorner.getLatLng(), e.latlng);
    });

    settingSelect.addEventListener('change', function(){
        updateGenerationSettingUi();
        if (bounds.length === 2){
            updateForm(L.latLngBounds(bounds[0], bounds[1]));
        }
    });

    function tryRestoreFromQuery(){
        const params = new URLSearchParams(window.location.search || '');
        const requestedSetting = (params.get('settings') || '').trim();
        if (requestedSetting && settingsMeta[requestedSetting]){
            settingSelect.value = requestedSetting;
        }

        const latTopLeft = parseFloat(params.get('latTopLeft') || '');
        const lngTopLeft = parseFloat(params.get('lngTopLeft') || '');
        const latBottomRight = parseFloat(params.get('latBottomRight') || '');
        const lngBottomRight = parseFloat(params.get('lngBottomRight') || '');
        if (![latTopLeft, lngTopLeft, latBottomRight, lngBottomRight].every(Number.isFinite)){
            return;
        }
        if (latTopLeft < -90 || latTopLeft > 90 || latBottomRight < -90 || latBottomRight > 90){
            return;
        }
        if (lngTopLeft < -180 || lngTopLeft > 180 || lngBottomRight < -180 || lngBottomRight > 180){
            return;
        }

        const lBounds = L.latLngBounds([latTopLeft, lngTopLeft], [latBottomRight, lngBottomRight]);
        createSelectionFromCorners(lBounds.getNorthWest(), lBounds.getSouthEast());
        map.fitBounds(lBounds, { padding: [20, 20] });
    }

    updateGenerationSettingUi();
    tryRestoreFromQuery();
</script>
<?php layout_footer(); ?>
