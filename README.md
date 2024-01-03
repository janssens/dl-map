# dl-map
A php tool to generate a map (as a png file) from online tiles

## Configuration

set your config data in config.json file, using [config.json.dist](config.json.dist) as an exemple.

TIPS: you can use [geoportail](https://www.geoportail.gouv.fr/) right click to find GPX coordinates 

you should write your own settings using examples.

## Run with php

You need php on you computer, with gd

```bash
php base.php
```

## Run with docker 

You need docker and docker compose on your computer.

```bash
docker compose up
```