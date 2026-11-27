# Meibo — магазин Umbrel

В магазине два приложения:
- **meibo-sheet** (версия 1.9.13) — лист 利用者名簿 и его администрирование.
- **meibocrm** (версия 0.3.0) — вход работников, их записи дня, печать листа в дизайне meibo.

Перед установкой замените токен `CHANGE-ME-READ-TOKEN`:
- в `meibo-sheet/docker-compose.yml` — `READ_TOKEN`;
- в `meibocrm/docker-compose.yml` — `MEIBO_TOKEN` (то же значение) и `MEIBO_URL` (адрес вашего Umbrel, например `http://192.168.1.10:1475`).

Подробности по каждому приложению — в его папке.
