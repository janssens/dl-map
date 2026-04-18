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

## Users / Auth (SQLite)

The web UI now uses a SQLite database stored in `var/app.sqlite`.

- Register/login pages: `register.php`, `login.php`
- First registered user becomes `admin` if no admin exists yet
- Roles: `free`, `premium`, `admin`
- Layer configurations are stored in DB (`layers` table). On first run, existing `settings/*.json(.dist)` are imported as global layers with `public` access.
- Users can create private layers in `my_layers.php`
- Admin panel: `admin/index.php` (users + layers CRUD)

### Email confirmation & password reset

By default, emails are written to `var/mail.log` (works in Docker).

- Confirm account link: sent on registration (token in URL)
- Forgot password: `forgot_password.php` → `reset_password.php`

To try real emails, set:

```bash
DL_MAP_MAILER=mail
DL_MAP_MAIL_FROM="dl-map <noreply@example.com>"
```

### Memory limit

If you hit `Allowed memory size ... exhausted`, increase PHP memory and/or the container memory:

```bash
# PHP only (override in compose)
PHP_MEMORY_LIMIT=2G docker-compose up --build
```

On Docker Desktop, also ensure the VM/container has enough RAM allocated (Settings → Resources).
