# dl-map
A php tool to generate a map (as a png file) from online tiles

## Configuration

set your config data in config.json file, using [config.json.dist](config.json.dist) as an exemple.

TIPS: you can use [geoportail](https://www.geoportail.gouv.fr/) right click to find GPX coordinates 

you should write your own settings using examples.

Settings files use an URL template with placeholders like `{z}`, `{x}`, `{y}`, `{layer}`, `{style}`, `{format}`.
For the web preview zoom range, you can set `leaflet_min_zoom`, `leaflet_max_zoom`, and `leaflet_default_zoom`.

## Run with php

You need php on you computer, with gd

```bash
php base.php
```

## Run with docker 

You need docker and docker compose on your computer.

```bash
docker-compose up --build
```

Then open `http://localhost:8080/` to select an area and generate a PNG.

Note: the CLI mode (`php base.php`) still reads `config.json`, but the web UI does not require it.

### Memory limit

If you hit `Allowed memory size ... exhausted`, increase PHP memory and/or the container memory:

```bash
# PHP only (override in compose)
PHP_MEMORY_LIMIT=2G docker-compose up --build
```

On Docker Desktop, also ensure the VM/container has enough RAM allocated (Settings → Resources).
