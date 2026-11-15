# environment

> [!IMPORTANT]
> **Сначала установите модуль BXADMIN-S, потом запускайте `install.sh`.**
> Модуль создаёт таблицы MCP-серверов; без него регистрация не сработает.
> Если environment уже развёрнут раньше модуля — после установки модуля
> выполните `./install.sh register`.
> **BxMCP и Scrapling регистрируются автоматически.** Пользователю не нужно
> вручную вводить URL, токены или добавлять MCP-серверы в админке.

Окружение BXADMIN-S: EmbeddingGemma 2, BxMCP, Scrapling и Chrome. Ставится
на сервер рядом с модулем. По умолчанию компоненты скачиваются на целевом
сервере; офлайн-payload — опциональный режим, потому что 10–20 ГБ бинарников
не лежат в Git и привязаны к ОС/архитектуре.

Поддерживаются:

- Debian 12+ и Ubuntu 22.04+;
- RHEL-совместимые: AlmaLinux, Rocky Linux, CentOS, Oracle Linux 9+.

Для RHEL 8 и других старых систем нужен отдельный bundle с Python 3.11+, иначе
venv эмбеддингов не запустится. Для экзотических сборок автоматика не
гарантируется — адаптация вручную или платная поддержка.

## Быстрый старт

```bash
sudo ./install.sh
```

Без аргументов это то же самое, что `install`: проверить систему, поставить
недостающее, поднять сервисы. Если нужен не install, используйте `start`,
`stop`, `status`, `reindex`, `register` или `uninstall`.

## Что делает install.sh

1. Проверяет ОС, архитектуру, корень сайта и порты.
2. Ставит системные пакеты, если их нет: `git`, `curl`, `mysql`-клиент, `cron`,
   `python3`, шрифты и библиотеки Chrome. Уже установленное не трогает.
   Запуск с `sudo` нужен для systemd, cron и установки пакетов.
3. В online-режиме ставит Node, BxMCP, embeddings/venv, модель, Scrapling и Chrome
   из сети. В offline-режиме (`--payload`/`--offline`) разворачивает готовый payload.
4. Поднимает сервисы через systemd (если есть root) или через `setsid`/`nohup`.
5. Автоматически регистрирует BxMCP и Scrapling как MCP-серверы модуля:
   пользователю не нужно вводить URL/токены вручную. Если environment поставлен
   раньше самого модуля, после установки модуля выполните `./install.sh register`.
6. Ставит `/etc/cron.d/bitrix-cron`, если его ещё нет (агенты модуля).
7. Ставит cron: раз в три дня переиндексация модулей и документации BxMCP.
8. Запускает первичную индексацию в фоне.

Индекс BxMCP **не входит в поставку**. Он строится на месте из
`<site>/bitrix/modules`, поэтому соответствует конкретному сайту. `local/modules`
BxMCP не индексирует — это осознанно: MCP должен знать только штатные модули.

## Установка

Если на сервере есть интернет, payload **не нужен**. `install.sh` ставит всё
прямо на целевом сервере: системные пакеты через `apt`/`dnf`, Node 22, BxMCP
(`npm ci`), embeddings (`venv` + PyPI, модель уже в `environment/models`),
Scrapling (PyPI + браузеры) и Chrome for Testing. Отдельный сборочный сервер
не требуется.

Payload остаётся для двух случаев:

- `--payload <tar>` — офлайн-установка из заранее собранного архива;
- `--offline` — запретить скачивание, требовать payload или `--build-payload`.

`bundle.sh` — упаковщик уже установленных компонентов для офлайн-поставки, не
загрузчик. На чистом сервере его запускать бессмысленно: ему нечего паковать.

Сначала установите сам модуль (таблицы и агенты), затем — environment.
Если environment поставлен раньше, после установки модуля выполните
`./install.sh register`.

```bash
cd local/modules/mibazarow.bxadmins/environment

# online: всё скачается и поставится на этом же сервере
sudo ./install.sh

# офлайн: готовый bundle
sudo ./install.sh install --payload /path/to/bxadmins-environment.tar.gz

./install.sh status
./install.sh reindex
```

`install` обязательно запускается от root: нужны `apt`/`dnf`, systemd и cron.

Online-режим использует сеть:

- Node 22 — через NodeSource;
- BxMCP — из `environment/bxmcp` + `npm ci` (≈84 МБ зависимостей);
- embeddings — venv + CPU-torch + `requirements.txt`; модель EmbeddingGemma 2
  уже лежит в `environment/models/embeddinggemma-2`;
- Scrapling — `pip install "scrapling[ai]"` + `scrapling install`;
- Chrome — chrome-for-testing (`CHROME_VERSION`).

Документация BxMCP входит в поставку: `environment/bxmcp/data/docs`.
`BXMCP_DOCS_URL` нужен только чтобы обновить её централизованно (tar.gz);
без URL используется встроенная копия.

Полезные флаги:

```bash
--site-root DIR       # если модуль лежит не в стандартном docroot
--no-system-deps      # не вызывать apt/dnf
--no-cron             # не ставить cron
--no-register         # не регистрировать MCP-серверы
--wait-index          # дождаться первичной индексации (иначе она в фоне)
--offline             # не скачивать, требовать payload/--build-payload
--online              # принудительно online-режим (по умолчанию)
--build-payload       # собрать payload из текущих исходников без вопроса
--dev                 # разрешить dev-режим без payload (не для чистого сервера)
```

## Команды

```bash
./install.sh start
./install.sh stop
./install.sh restart
./install.sh status
./install.sh reindex
./install.sh register
./install.sh uninstall          # убрать сервисы/cron, данные оставить
./install.sh uninstall --purge  # удалить environment, данные чата не трогать
```

## Пути и порты

По умолчанию:

| Что | Где |
|---|---|
| Environment | `/home/bitrix/bxadmins-data/environment` |
| BxMCP | `environment/bxmcp` |
| Индекс BxMCP | `environment/bxmcp/data/index.db` |
| Embeddings | `environment/embeddings` |
| Модель | `environment/models/embeddinggemma-2` |
| Scrapling | `/home/bitrix/bxadmins-data/scrapling` |
| Chrome | `/home/bitrix/bxadmins-data/chrome` |
| Конфиг | `environment/environment.env` |

Порты: embeddings `8000`, BxMCP `3100`, Scrapling `3101`. Все слушают
`127.0.0.1`; наружу ничего не открывается.

## Cron

Ставится `/etc/cron.d/bxadmins-environment` (если есть root) или crontab
пользователя:

```
17 3 */3 * *  <user>  cd <site> && .../install.sh reindex
```

`reindex` дергает `/admin/reindex` (модули + компоненты), затем
`/admin/reindex-docs`. Если BxMCP ещё занят, скрипт ждёт и повторяет запрос
документации. Для агентов Битрикса нужен `agents_use_crontab=Y`; если опция
выключена, включите её в настройках главного модуля.

## Веб-доступ

Папка закрыта `.htaccess` для Apache. **Nginx `.htaccess` не читает** — добавьте
в конфиг сайта:

```nginx
location ^~ /local/modules/mibazarow.bxadmins/environment/ {
    deny all;
    return 404;
}
```

Готовый фрагмент — в `nginx.conf.example`.

## Payload

`bundle.sh` кладёт компоненты в корень архива; `install.sh` распаковывает его
сразу в `environment` и не копирует 7 ГБ BxMCP повторно.

`bundle.sh` собирает:

- `runtime/node` — Node, под который собран `better-sqlite3` BxMCP;
- `bxmcp` — сервер, `node_modules`, документация, **без** `index.db`;
- `embeddings` — скрипт, `requirements.txt`, venv;
- `models/huggingface` — кеш `google/embeddinggemma-2`;
- `scrapling/.venv` и `scrapling/browsers` (кеш Playwright);
- `chrome` — `chrome-headless-shell` и библиотеки.

Сборку нужно делать на той же ОС-семействе и архитектуре, что и цель: venv и
нативные модули Node не переносятся между разными Python/glibc/ABI. Для
Debian/Ubuntu и RHEL лучше делать отдельные bundle.

## Ограничения

- Первичная индексация BxMCP не мгновенная; чат при этом работает, просто
  `mcp1_*` появятся после готовности индекса.
- BxMCP индексирует каталоги `bitrix/modules`; если в них остались файлы
  удалённых модулей, они тоже попадут в индекс. Для строгого соответствия
  установленным модулям нужен фильтр по `b_module`/`ModuleManager`.
- `local/modules` намеренно не индексируется.
- Системные пакеты ставятся через `apt`/`dnf`. В online-режиме приложение
  (Node, BxMCP, модель, Scrapling, Chrome) скачивается; в offline — из payload.
- Chrome и Scrapling требуют системные библиотеки; на минимальных RHEL-сборках
  проверьте `ldd` и SELinux.

## Безопасность

- `.htaccess` + nginx-правило закрывают папку от веба.
- `register-mcp.php` работает только из CLI.
- Токен Scrapling генерируется случайно и хранится в `environment.env` с правами
  `600`; в process list не попадает.
- MCP-серверы и embeddings слушают только loopback.
