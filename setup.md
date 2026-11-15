# Развёртывание BXADMIN-S на готовом сайте

Инструкция начинается с момента, когда сервер и сайт уже работают: настроен
веб-сервер, установлен чистый 1С-Битрикс, есть доступ в админку. Всё, что модуль
может сделать сам (таблицы, агенты, обработчики, меню, admin-обёртки), делает
установщик — руками эти вещи не настраиваются.

Область: собственный сервер или VPS с root/sudo. Shared-хостинг не
рассматривается: без cron, `proc_open`, Node и Python модуль не заработает
полноценно.

Ориентир по времени: 20–30 минут без сервиса эмбеддингов и Scrapling; с ними —
плюс 20–40 минут (скачивание модели/Chromium и первый запуск). Из этого ~10 минут
занимает индексация.

Условные обозначения:

| Плейсхолдер | Пример |
|---|---|
| `<domain>` | `bxadmin.example.ru` |
| `<site_dir>` | `/home/bitrix/ext_www/bxadmin.example.ru` |
| `<db_name>` | `bxadmin` |
| `<db_user>` | `bxadmin` |

Команды с `sudo` выполняются от пользователя с правами root; остальные — от
пользователя PHP (`bitrix`). Если sudo нет, те же действия делает владелец сервера.

---

## 0. Что должно получиться

```
интернет
   │
   ▼
nginx / apache           TLS, статика, PHP
   │
   ▼
<site_dir>               docroot сайта
   ├── bitrix/           ядро и модули вендора — только чтение
   ├── local/
   │   └── modules/mibazarow.bxadmins/    код харнеса
   └── upload/           пользовательские файлы

вне docroot:
 /home/bitrix/bxadmins-data/
     logs/              журналы модуля, демона и скрапера
     screenshots/       снимки страниц
     git/               точки отката файлов (site.git, local.git)
     chat-files/        вложения из чата (создаётся модулем)
     chrome/            headless-браузер
     scrapling/         MCP-скрапер (опционально)

внутри docroot (исключения из «bitrix только для чтения»):
 <site_dir>/bitrix/admin/                     admin-обёртки модуля
 <site_dir>/bitrix/backup/bx_admin_s_bacaps/  SQL-дампы и снапшоты файлов
 <site_dir>/upload/bxadmins/                  картинки и документы

фоновые процессы:
 Node-демон             цикл «модель → инструмент → модель» (сторожит агент)
 cron Битрикса          агенты: индексация, обслуживание, сторож демона,
                        задачи по расписанию, сторож скрапера
 сервис эмбеддингов     векторный поиск по базе знаний
```

Доставка ответов в браузер работает через встроенный SSE-поток
`admin/stream.php` и журнал событий в БД — отдельный push-server не нужен.

Модуль не зависит от конкретной связки веб-сервера: важны docroot, пользователь
PHP и доступ к `php` из cron. Эталонная установка — nginx снаружи, apache с
`mod_php` внутри; подойдёт и чистый nginx + php-fpm.

Важно: «ядро только для чтения» не означает, что в `bitrix/` вообще нельзя писать.
Модулю нужны права на `bitrix/admin` (установка обёрток) и `bitrix/backup`
(дампы). Это единственные разрешённые места внутри ядра.

---

## 1. Предпосылки

Проверьте окружение на сервере:

```bash
# PHP: версия и обязательные расширения
php -v                 # 8.1 … 8.5
php -m | grep -E 'pdo_mysql|curl|mbstring|json|xml|openssl|zip|fileinfo'
php -m | grep -iE 'imagick|gd|intl'   # не обязательны, но полезны

# PHP: опасные ограничения
php -i | grep -E 'disable_functions|file_uploads|upload_max_filesize|post_max_size|memory_limit|upload_tmp_dir|sys_temp_dir'
php -r 'var_dump(function_exists("proc_open"), function_exists("exec"), function_exists("shell_exec"));'
# все три должны быть bool(true)

# Node.js (для демона и браузера)
node -v                # рекомендуется 22 LTS
command -v node

# CLI-утилиты
git --version
diff --version
mysqldump --version
mysql --version
setsid --version
ps --version
curl --version | head -1
unzip -v | head -1
# если чего-то нет (Debian/Ubuntu; для MariaDB — mariadb-client):
# sudo apt install -y git diffutils procps util-linux unzip curl default-mysql-client

# Cron
which crontab
ls -l /etc/cron.d/bitrix-cron 2>/dev/null

# Время (критично для HMAC демона, допуск ±120 секунд)
timedatectl status
chronyc tracking 2>/dev/null || ntpq -p 2>/dev/null

# Порты: 8899/8000/3100/3101 — либо свободны, либо заняты нужным сервисом;
# наружу обычно открыты только 80/443.
ss -ltnp | grep -E ':(8899|8000|3100|3101)\b'

# Сайт и исходящая сеть
curl -s -o /dev/null -w '%{http_code}\n' https://<domain>/   # 200 / 302
curl -sS -o /dev/null -w '%{http_code}\n' https://<llm-endpoint>/models
```

Если включён firewall, наружу достаточно 80/443. Сервисные порты
8899/8000/3101 должны слушать `127.0.0.1`, а не `0.0.0.0`.

Исходящий HTTPS нужен как минимум к LLM-провайдеру. Дополнительно — к серверу
обновлений Битрикса (скачивание модулей), S3 (если настроено), Openverse/Wikimedia
(`search_images`), MCP-серверам, PyPI (Scrapling) и `storage.googleapis.com`
(Chrome for Testing). Убедитесь, что установлен пакет `ca-certificates` и системное
время корректно — иначе TLS будет падать неочевидными ошибками.

```bash
sudo apt install -y ca-certificates unzip
```

Отдельно проверьте **веб-SAPI**, а не только CLI: `disable_functions`, лимиты
загрузки и расширения у php-fpm/apache могут отличаться от `php` в консоли.
Самый простой способ — временный `phpinfo()` или раздел «PHP» в админке Битрикса.

Требования по расширениям:

- обязательны `pdo_mysql`, `curl`, `mbstring`, `json`, `xml`, `openssl`, `zip`, `fileinfo`;
- `zip` нужен для SQL-дампов и чтения `.docx`/`.xlsx`;
- `fileinfo` — для определения MIME вложений;
- `openssl` — HTTPS, S3-подпись, проверка TLS-сертификата;
- `Imagick` — желателен: ресайз картинок и рендер PDF. Для PDF дополнительно
  нужен Ghostscript и разрешающий `policy.xml`, иначе PDF-вложения не читаются;
- `gd` и `intl` модулем напрямую не используются, но обычно нужны самому Битриксу.

```bash
sudo apt install -y php-imagick imagemagick ghostscript
sudo systemctl restart php8.2-fpm   # или apache2; подставьте свою версию SAPI
```

В `disable_functions` **не должно быть** `proc_open`, `exec`, `shell_exec`.
Без них не запустится демон, не будут работать `mysqldump`, `git`, `php -l`
и часть инструментов.

Рекомендуемые лимиты PHP (для веб-SAPI):

```ini
upload_max_filesize = 32M
post_max_size = 32M
memory_limit = 256M
max_execution_time = 60
```

`upload_max_filesize`/`post_max_size` должны быть не меньше 25 МБ — это лимит
вложений в чате. `upload_tmp_dir` и `sys_temp_dir` должны быть доступны на запись
и иметь свободное место: через них идут загрузки, дампы и восстановление.
Для больших PDF и картинок `memory_limit` может потребоваться больше 256 МБ.

На стороне nginx не забудьте `client_max_body_size` (по умолчанию 1 МБ — этого
мало для вложений):

```nginx
client_max_body_size 32m;
```

Проверьте, что в Битриксе установлен модуль `main` и есть администратор с
доступом к Marketplace. Остальные модули подключаются по наличию: `iblock`,
`catalog`, `sale`, `forum`, `blog`, `calendar`, `socialnetwork`, `form`,
`highloadblock`, `subscribe`, `currency`, `bizproc`, `rest`. Если модуля
нет — соответствующие инструменты не появляются у модели, остальное работает.
Модуль `pull` и push-server больше не нужны: доставка событий встроена в модуль.

Отдельно проверьте агентов Битрикса: они должны запускаться по cron, а не по
ходам пользователей.

```bash
sudo mysql -e "SELECT VALUE FROM b_option WHERE MODULE_ID='main' AND NAME='agents_use_crontab'" <db_name>
# ожидается Y
```

Проверьте cron Битрикса (он нужен модулю):

```bash
ls /etc/cron.d/bitrix-cron 2>/dev/null || crontab -u bitrix -l
```

Если cron ещё не настроен — сделайте это по §4.4 после установки модуля.

Проверьте права пользователя БД. Ему нужны не только `SELECT/INSERT/UPDATE/DELETE`,
но и `CREATE/ALTER/DROP/INDEX` — иначе установка не создаст таблицы, а обновление
модуля не добавит новые. Для `mysqldump --routines --events` нужны права на чтение
процедур и событий; для восстановления — право импорта с `DROP`/`CREATE`.

```bash
sudo mysql -e 'SHOW GRANTS FOR <db_user>@localhost'
```

---

## 2. Копирование модуля

### 2.1. Файлы

Модуль лежит в `local/modules/mibazarow.bxadmins`. Копировать нужно **целиком**,
включая `daemon/`, `install/`, `lang/`, `admin/`, `lib/`, `views/`, `tools/`:

```bash
# с машины разработчика
rsync -av --delete \
    local/modules/mibazarow.bxadmins/ \
    <user>@<server>:<site_dir>/local/modules/mibazarow.bxadmins/
```

Если `local/` на сервере — символическая ссылка на общий каталог мультисайта,
rsync пишет по ссылке; это нормально.

### 2.2. Права

```bash
sudo chown -R bitrix:bitrix <site_dir>/local/modules/mibazarow.bxadmins
sudo find <site_dir>/local/modules/mibazarow.bxadmins -type d -exec chmod 2775 {} \;
sudo find <site_dir>/local/modules/mibazarow.bxadmins -type f -exec chmod 664 {} \;
```

Проверка целостности:

```bash
ls <site_dir>/local/modules/mibazarow.bxadmins/{install,daemon,lib,admin,lang}
```

Каталог модуля должен быть доступен пользователю PHP на запись: демон создаёт
там PID-файл `.daemon.lock`. При `chmod 2775` и владельце `bitrix` это выполняется.

В `bitrix/` руками ничего не копируется. Установщик сам положит пять тонких
admin-обёрток в `/bitrix/admin/` и удалит их при деинсталляции. Поэтому на время
установки и обновления каталог `bitrix/admin` должен быть доступен пользователю
PHP на запись.

---

## 3. Установка

### 3.1. Через админку

Админка → **Marketplace → Установленные решения** → найти **BXADMIN-S** →
«Установить».

### 3.2. Через CLI

```bash
cd <site_dir>
sudo -u bitrix php -r '
$_SERVER["DOCUMENT_ROOT"] = getcwd();
define("NO_KEEP_STATISTIC", true);
define("NOT_CHECK_PERMISSIONS", true);
require $_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/include/prolog_before.php";
require $_SERVER["DOCUMENT_ROOT"] . "/local/modules/mibazarow.bxadmins/install/index.php";
$module = new mibazarow_bxadmins();
var_dump($module->DoInstall());
'
```

Ожидаемо: `bool(true)`.

### 3.3. Что делает установщик

- создаёт 14 таблиц `b_mibazarow_bxadmins_*` — идемпотентно, `CREATE TABLE IF NOT EXISTS`;
- копирует пять admin-обёрток в `/bitrix/admin/`;
- регистрирует модуль и раздел **BXADMIN-S** в глобальном меню;
- заводит пять агентов: индексация (15 мин), обслуживание (сутки), сторож демона
  (минута), задачи по расписанию (минута), сторож MCP-скрапера (минута);
- генерирует `worker_secret` — общий секрет демона и PHP;
- создаёт таблицу владения модулями `b_mibazarow_bxadmins_custom_module`.

Для установки у пользователя БД должны быть права `CREATE`, `ALTER`, `DROP`,
`INDEX`; иначе `RunSQLBatch` вернёт ошибку и установка завершится неудачей.

Проверка, что модуль загружается:

```bash
sudo -u bitrix php -r '
$_SERVER["DOCUMENT_ROOT"] = "<site_dir>";
define("NO_KEEP_STATISTIC", true);
define("NOT_CHECK_PERMISSIONS", true);
require $_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/include/prolog_before.php";
\Bitrix\Main\Loader::includeModule("mibazarow.bxadmins");
echo count(\Mibazarow\Bxadmins\Service\ToolRegistry::definitions()), " tools\n";
'
```

Ожидаемо: все встроенные инструменты (на текущей версии — 205) плюс инструменты
MCP-серверов, если они уже подключены, и минус выключенные домены.

---

## 4. Инфраструктура модуля

### 4.1. Каталог данных вне docroot

Модуль пишет служебные данные рядом с docroot, но не внутрь него. Путь
вычисляется как «каталог выше docroot» + `/bxadmins-data`:

```bash
sudo mkdir -p /home/bitrix/bxadmins-data/{logs,screenshots,git,chrome,chat-files,scrapling}
sudo chown -R bitrix:bitrix /home/bitrix/bxadmins-data
sudo chmod 750 /home/bitrix/bxadmins-data
```

Если раскладка другая, учтите: **настраивается только `log_dir`**. Остальные пути
(`git`, `chat-files`, скриншоты) вычисляются от `dirname(dirname(docroot))`, а
`chrome` и `scrapling` задаются переменными окружения демона. На нестандартной
раскладке либо создайте каталог по вычисленному пути:

```bash
DATA="$(dirname "$(dirname "<site_dir>")")/bxadmins-data"
sudo mkdir -p "$DATA"/{logs,screenshots,git,chrome,chat-files,scrapling}
```

либо сделайте симлинк (если каталога ещё нет):

```bash
sudo ln -s /нужный/путь "$DATA"
```

Либо задать переменные в `/etc/cron.d/bitrix-cron`, потому что `DaemonAgent`
передаёт демону окружение cron:

```
SHOT_DIR=/data/bxadmins/screenshots
CHROME_DIR=/data/bxadmins/chrome
CHROME_BIN=/usr/bin/chromium
CHROME_LIBS=/data/bxadmins/chrome/libs/usr/lib/x86_64-linux-gnu
```

Каталог обязан быть **вне** корня сайта: в нём лежат снапшоты, точки отката и
файлы чата. SQL-дампы хранятся отдельно — в
`<site_dir>/bitrix/backup/bx_admin_s_bacaps` (см. §4.3).

Если в PHP задан `open_basedir`, добавьте в него `bxadmins-data`, каталог
бэкапов и `sys_temp_dir`, иначе демон, git и восстановление не смогут писать.

Оцените свободное место заранее: до пяти SQL-дампов БД, история двух git-репозиториев,
скриншоты, файлы чата (до 25 МБ × 5 на сообщение), Chrome (~300 МБ), Scrapling
(~400 МБ) и модель эмбеддингов (несколько гигабайт). На небольшом сервере это
легко даёт 10–20 ГБ сверх сайта.

### 4.2. Node.js

Нужен **Node.js 22 LTS** (проверено на 22). Формально демон запустится и на 18,
но `daemon/browser.js` использует глобальный `WebSocket`, которого нет в Node 18
и 20 — браузерные инструменты (`browser_*`) при этом не работают.

```bash
curl -fsSL https://deb.nodesource.com/setup_22.x | sudo -E bash -
sudo apt install -y nodejs
node -v
```

Если Node стоит через nvm/asdf, cron его не увидит. Сделайте симлинк:

```bash
sudo ln -s "$(which node)" /usr/local/bin/node
```

`npm` и внешние пакеты демону не нужны — только стандартная библиотека Node.

Проверьте не только shell, но и окружение, близкое к cron:

```bash
sudo -u bitrix env -i PATH=/usr/local/bin:/usr/bin:/bin sh -c 'command -v node && node -v'
```

### 4.3. Права на файлы

Пользователь PHP (в apache — `bitrix`) должен писать:

| Путь | Зачем |
|---|---|
| `<site_dir>/local/` | правка шаблонов, компонентов и модулей агента |
| `<site_dir>/` | `robots.txt`, `urlrewrite.php`, статические страницы |
| `<site_dir>/upload/bxadmins/` | загрузка картинок и документов |
| `<site_dir>/bitrix/admin/` | установка/удаление admin-обёрток |
| `<site_dir>/bitrix/backup/bx_admin_s_bacaps/` | SQL-дампы и снапшоты точек отката |
| `<site_dir>/bitrix/managed_cache/` | сброс кеша |

```bash
sudo mkdir -p <site_dir>/bitrix/backup/bx_admin_s_bacaps <site_dir>/upload/bxadmins
sudo chown -R bitrix:bitrix <site_dir>/bitrix/backup <site_dir>/upload
sudo -u bitrix test -w <site_dir>/local && echo "local: ok"
sudo -u bitrix test -w <site_dir>/bitrix/backup/bx_admin_s_bacaps && echo "backup: ok"
sudo -u bitrix test -w <site_dir>/bitrix/admin && echo "admin: ok"
sudo -u bitrix test -w <site_dir>/upload && echo "upload: ok"
```

Дампы лежат внутри docroot, а модуль кладёт рядом `.htaccess`. **Apache его
читает, чистый nginx — нет.** Для nginx обязательно закройте каталог:

```nginx
location ^~ /bitrix/backup/ {
    deny all;
    return 404;
}
```

Проверьте, что дампы не скачиваются:

```bash
curl -s -o /dev/null -w '%{http_code}\n' https://<domain>/bitrix/backup/
# ожидается 403 или 404, не 200
```

Если Apache работает с `AllowOverride None`, `.htaccess` тоже не сработает —
тогда нужен `Location`/`Directory` с `Require all denied`.

Для восстановления больших дампов поднимите `max_allowed_packet` MySQL (например,
до `64M` или `256M`) — иначе импорт может упасть на длинных `INSERT`:

```bash
mysql -e 'SHOW VARIABLES LIKE "max_allowed_packet"'
```

### 4.4. Cron Битрикса

Без него не работают агенты: ни индексация, ни сторож демона, ни задачи по
расписанию. На установке с несколькими сайтами cron **один** — из каталога
основного сайта.

`/etc/cron.d/bitrix-cron`:

```
* * * * * bitrix /usr/bin/php -f /home/bitrix/ext_www/<основной_сайт>/bitrix/modules/main/tools/cron_events.php >/dev/null 2>&1
```

```bash
sudo chown root:root /etc/cron.d/bitrix-cron
sudo chmod 644 /etc/cron.d/bitrix-cron
sudo systemctl restart cron
```

Cron запускает процессы с минимальным `PATH` (`/usr/bin:/bin`). Если Node,
`mysqldump` или `git` лежат в другом месте, используйте симлинки или абсолютные
пути; проверьте от имени `bitrix`:

```bash
sudo -u bitrix env -i PATH=/usr/local/bin:/usr/bin:/bin sh -c \
  'command -v node php mysqldump mysql git diff ps setsid'
```

Часовой пояс PHP и MySQL должен совпадать, а системное время — синхронизироваться
по NTP. HMAC-подпись внутреннего API демона отклоняет запросы при расхождении
больше 120 секунд — при сбитых часах «задачи не выполняются» без явной ошибки.

```bash
timedatectl status
php -r 'echo date_default_timezone_get(), " ", date("c"), "\n";'
mysql -e 'SELECT @@global.time_zone, @@session.time_zone, NOW()'
```

Если время не синхронизировано, поставьте chrony (или используйте
`systemd-timesyncd`):

```bash
sudo apt install -y chrony
sudo systemctl enable --now chrony
```

Проверка агентов через пару минут:

```bash
sudo mysql -e "SELECT NAME, LAST_EXEC, NEXT_EXEC FROM b_agent WHERE MODULE_ID='mibazarow.bxadmins'" <db_name>
```

Должно быть пять строк с непустым `LAST_EXEC`.

Важно для мультисайта: cron запускается из чужого docroot, поэтому адрес демона
фиксируется отдельно. Откройте админку модуля на нужном сайте — при первом
открытии в опции `daemon_site_url` запишется его домен. Проверить:

```bash
sudo mysql -e "SELECT VALUE FROM b_option WHERE MODULE_ID='mibazarow.bxadmins' AND NAME='daemon_site_url'" <db_name>
```

### 4.5. Node-демон

Запускать руками не нужно: агент `DaemonAgent` проверяет процесс раз в минуту и
поднимает его сам. Первый запуск произойдёт в течение минуты после установки.

Что проверить:

```bash
pgrep -af bxadmins-daemon.js
ss -ltnp | grep 8899
tail -20 /home/bitrix/bxadmins-data/logs/daemon.log
```

В журнале должно быть:

```
BXADMIN-S daemon started for https://<domain>
browser api listening on 127.0.0.1:8899
```

Если порт 8899 занят, демон не поднимется. Если в журнале `bad timestamp` или
`401 bad timestamp` — проверьте NTP (§4.4). Если `node not found` — Node не виден
из cron (§4.2).

Демон держит один экземпляр (PID-файл `daemon/.daemon.lock`), второй запуск
выходит сам. Порт `127.0.0.1:8899` — внутренний, наружу не открывается.

Ручной запуск для отладки:

```bash
cd <site_dir>
SECRET=$(sudo -u bitrix php -r '
$_SERVER["DOCUMENT_ROOT"] = getcwd();
define("NO_KEEP_STATISTIC", true);
define("NOT_CHECK_PERMISSIONS", true);
require $_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/include/prolog_before.php";
\Bitrix\Main\Loader::includeModule("mibazarow.bxadmins");
echo \Mibazarow\Bxadmins\Config::workerSecret();
')
sudo -u bitrix SECRET="$SECRET" SITE_URL=https://<domain> \
  setsid node local/modules/mibazarow.bxadmins/daemon/bxadmins-daemon.js \
  >> /home/bitrix/bxadmins-data/logs/daemon.log 2>&1 < /dev/null &
```

### 4.6. Headless-браузер

Нужен для скриншотов, разбора DOM/CSS и дымовых тестов. Модуль запускает
`chrome-headless-shell` по CDP — без GUI, без X11.

Скачать актуальную сборку и положить в каталог данных:

```bash
cd /home/bitrix/bxadmins-data/chrome
VERSION=156.0.7933.0   # подставьте актуальную версию из chrome-for-testing
sudo -u bitrix curl -Lo chs.zip \
  "https://storage.googleapis.com/chrome-for-testing-public/${VERSION}/linux64/chrome-headless-shell-linux64.zip"
sudo -u bitrix unzip -q chs.zip && sudo -u bitrix rm chs.zip
sudo -u bitrix ./chrome-headless-shell-linux64/chrome-headless-shell --version
```

Для ARM-сервера сборки `linux64` нет: используйте системный Chromium или
сборку под arm64, и задайте `CHROME_BIN`.

Если не хватает системных библиотек, два варианта:

```bash
# А. доустановить зависимости
sudo apt install -y libnss3 libatk-bridge2.0-0 libcups2 libdrm2 libgbm1 \
                    libasound2t64 libxkbcommon0 libxcomposite1 libxdamage1 libxrandr2

# Б. проверить, чего не хватает
ldd /home/bitrix/bxadmins-data/chrome/chrome-headless-shell-linux64/chrome-headless-shell \
  | grep 'not found'
```

```bash
# В. положить библиотеки рядом и не трогать систему
#    /home/bitrix/bxadmins-data/chrome/libs/usr/lib/x86_64-linux-gnu/...
#    демон сам добавит их в LD_LIBRARY_PATH
```

Для корректного рендера кириллицы в скриншотах поставьте шрифты:

```bash
sudo apt install -y fonts-dejavu-core fonts-liberation
```

Альтернатива — системный Chrome:

```bash
sudo apt install -y chromium
export CHROME_BIN=/usr/bin/chromium      # для демона, через окружение запуска
```

Переменные демона: `CHROME_DIR`, `CHROME_BIN`, `CHROME_LIBS`, `SHOT_DIR`,
`BROWSER_TIMEOUT_MS`.

Без браузера модуль работает, но дизайн проверяет только по DOM/CSS и честно
предупреждает об ограничении.

### 4.7. Доставка событий (SSE)

Встроена в модуль. Node-демон пишет события задачи в таблицу
`b_mibazarow_bxadmins_task_event`, а `admin/stream.php` отдаёт их браузеру через
`EventSource`. Дополнительно настраивать на сервере **ничего не нужно**: ни
модуль `pull`, ни `push-server`, ни маршрут `/rest/`, ни WebSocket, ни новые порты.

Как это работает:

- одно SSE-соединение на вкладку, поток живёт до 25 секунд и переподключается сам;
- `Last-Event-ID` не даёт потерять события при обрыве и перезагрузке;
- если поток недоступен (старый браузер, буферизующий прокси), чат автоматически
  переходит на опрос `task_active` и показывает черновик ответа;
- журнал чистит существующий `MaintenanceAgent` раз в сутки: удаляются события задач,
  завершённых более 15 минут назад, и всё старше суток.

Проверка:

```bash
# таблица журнала создана установщиком
sudo mysql -e "SHOW TABLES LIKE 'b_mibazarow_bxadmins_task_event'" <db_name>

# в браузере: открыть чат, отправить задачу, в Network должен появиться stream.php
# с типом text/event-stream; ошибок в консоли быть не должно
```

SSE занимает один PHP-воркер на открытую вкладку админки. Для одного-двух
администраторов это нормально; при жёстком лимите `pm.max_children` увеличьте
пул или закройте лишние вкладки.

Правило `/rest/` в `urlrewrite.php` больше не требуется для чата. Если оно
осталось от Pull, удалять его не обязательно: оно может быть нужно другим
REST-вызовам сайта.

### 4.8. Сервис эмбеддингов

Нужен для векторного поиска по базе знаний. **В состав модуля не входит** и
ставится отдельно: Python 3.10+, FastAPI/uvicorn, модель
`google/embeddinggemma-2`, порт `8000`, только `127.0.0.1`.

Ожидаемый контракт:

```
POST http://127.0.0.1:8000/embed
{"text": "...", "profile": "site_passage" | "site_query"}
→ {"embedding": [...], "dim": 256, "model": "..."}

GET http://127.0.0.1:8000/health
```

Сервис должен быть автозапускаемым. Пример systemd-юнита (пути подставьте свои):

```ini
# /etc/systemd/system/bxadmins-embeddings.service
[Unit]
Description=BXADMIN-S Embeddings Service
After=network.target

[Service]
Type=simple
User=root
WorkingDirectory=/opt/bxadmins-embeddings
ExecStart=/opt/bxadmins-embeddings/venv/bin/python -m uvicorn embeddings-service:app --host 127.0.0.1 --port 8000
Restart=always
RestartSec=5
Environment=EMBEDDING_MODEL=google/embeddinggemma-2
Environment=EMBEDDING_DIM=256
Environment=EMBEDDING_MAX_SEQ=512
Environment=EMBEDDING_THREADS=2
Environment=HF_HOME=/var/lib/bxadmins-embeddings

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now bxadmins-embeddings
systemctl status bxadmins-embeddings --no-pager
```

На эталонном сервере этот же сервис использует BxMCP: код лежит в
`/opt/bitrix-mcp/python`, юнит называется `bxmcp-embeddings.service`. Для модуля
это не важно — важен только контракт `/embed` и `/health` на `127.0.0.1:8000`.
Запуск от `root` не обязателен: лучше отдельный пользователь с правами только на
каталог модели и venv.

Требования к ресурсам: модель и Python-окружение занимают несколько гигабайт
диска и RAM; при первом запуске модель скачивается с Hugging Face. Если модель
доступна только после принятия лицензии, задайте `HF_TOKEN` в окружении сервиса.
Индексация идёт медленно (3–5 с на фрагмент), это нормально.

Адрес и модель меняются в настройках модуля (`embedding_endpoint`,
`embedding_model`). Если сервиса нет, поиск временно работает на MySQL
FULLTEXT — модуль предупредит, но не сломается. Для FULLTEXT нужен индекс
`FT_BXADMINS_INDEX_SEARCH`, он создаётся установщиком.

Проверка:

```bash
curl -sS http://127.0.0.1:8000/health
curl -sS -X POST http://127.0.0.1:8000/embed \
  -H 'Content-Type: application/json' \
  -d '{"text":"проверка","profile":"site_query"}'
```

Для диагностики из PHP:

```bash
sudo -u bitrix php -r '$_SERVER["DOCUMENT_ROOT"]=getcwd();
define("NO_KEEP_STATISTIC",true); define("NOT_CHECK_PERMISSIONS",true);
require "bitrix/modules/main/include/prolog_before.php";
\Bitrix\Main\Loader::includeModule("mibazarow.bxadmins");
print_r((new \Mibazarow\Bxadmins\Service\EmbeddingClient())->test());'
```

### 4.9. MCP-скрапер (опционально)

Нужен, когда обычный fetch получает 403/429/captcha или страница собирается
JavaScript. Модуль поднимает Scrapling MCP сторожем `ScraperAgent`.

Требования: Python 3.10+, пакет `python3-venv`, доступ к PyPI и к серверу
загрузки Chromium, те же системные библиотеки, что и для Chrome (§4.6).

```bash
sudo apt install -y python3 python3-venv
sudo -u bitrix mkdir -p /home/bitrix/bxadmins-data/scrapling
sudo -u bitrix python3 -m venv /home/bitrix/bxadmins-data/scrapling/.venv
sudo -u bitrix /home/bitrix/bxadmins-data/scrapling/.venv/bin/pip install --upgrade pip
sudo -u bitrix /home/bitrix/bxadmins-data/scrapling/.venv/bin/pip install "scrapling[ai]"
sudo -u bitrix /home/bitrix/bxadmins-data/scrapling/.venv/bin/scrapling install
```

Затем в админке модуля добавьте MCP-сервер:

- URL: `http://127.0.0.1:3101/mcp`
- токен: любая длинная случайная строка (уйдёт как bearer)

Перезапустите cron-агентов или подождите минуту: агент найдёт сервер по URL,
поднимет `scrapling-mcp --http` с токеном из окружения и будет проверять его
каждую минуту. Лог: `/home/bitrix/bxadmins-data/logs/scrapling-mcp.log`,
инструменты появятся в каталоге MCP (вызов — через `mcp_tool`).

Без скрапера модуль полностью работоспособен — просто не будет обхода
защищённых страниц.

Внешний **BxMCP** (Bitrix MCP) для работы модуля не требуется и в поставку не
входит. Если он используется на этом же сервере, это отдельная установка; на
работу чата без него это не влияет.

### 4.10. Автоматическая установка окружения (опционально)

> [!IMPORTANT]
> **Порядок важен: сначала установите модуль BXADMIN-S, затем запускайте
> `environment/install.sh`.** MCP-серверы BxMCP и Scrapling регистрируются
> автоматически, вручную их подключать не нужно. Если environment поставлен
> раньше модуля — после установки модуля выполните `./install.sh register`.

В модуле есть `environment/` — установщик EmbeddingGemma 2, BxMCP, Scrapling
и Chrome. Он рассчитан на уже готовый сервер: веб-сервер и чистый Битрикс с БД
есть, всё остальное скрипт проверяет и доустанавливает.

```bash
cd <site_dir>/local/modules/mibazarow.bxadmins/environment

# обычная установка: всё скачивается и ставится на этом же сервере
sudo ./install.sh

# офлайн-вариант
# sudo ./install.sh install --payload /path/to/payload.tar.gz

./install.sh status
```

`install` требует root. Без `--payload` работает online-режим: компоненты
скачиваются и ставятся на этом же сервере. `--payload`/`--offline` включают
офлайн-режим, где нужен заранее собранный bundle.

Что делает установщик:

- ставит через `apt`/`dnf` недостающие `git`, `curl`, mysql-клиент, `cron`,
  `python3`, build tools, шрифты и библиотеки Chrome;
- в online-режиме ставит Node, BxMCP, embeddings с уже входящей в `environment/`
  моделью EmbeddingGemma 2, Scrapling и Chrome; в offline — разворачивает payload;
  документация BxMCP тоже лежит в `environment/bxmcp/data/docs`;
- поднимает сервисы через systemd или `setsid`;
- автоматически регистрирует BxMCP и Scrapling как MCP-серверы модуля
  (вручную ничего подключать не нужно);
- ставит cron на переиндексацию модулей и документации BxMCP раз в три дня;
- запускает первичную индексацию в фоне.

Индекс BxMCP не поставляется: он строится на месте из
`<site_dir>/bitrix/modules` и соответствует конкретному сайту. `local/modules`
BxMCP не индексирует — только штатные модули. Папка закрыта `.htaccess`, а для
nginx добавьте правило из `environment/nginx.conf.example`.

Payload нужен только для офлайн-установки и собирается на сервере той же
ОС/архитектуры командой `environment/bundle.sh`; в Git и архив модуля бинарники
не попадают. В обычном режиме `install.sh` ставит Node, BxMCP (`npm ci`),
embeddings, модель, Scrapling и Chrome из сети. Подробности и ограничения — в
`environment/README.md`.

---

## 5. Настройка в админке

Админка → **BXADMIN-S** (раздел в левом меню).

**Настройки → Модель.**
Endpoint, API-ключ, модель. Кнопка «Загрузить модели» подтягивает список у
провайдера, «Проверить» — делает тестовый запрос. Провайдер обязан поддерживать
**tool calling** — без него харнес честно скажет, что модель не подходит.
Желательны также streaming (иначе ответ приходит целиком, без «печати») и vision
(иначе картинки не анализируются). Endpoint должен быть OpenAI-совместимым:
`/chat/completions`, `/models`.

**Настройки → Доступ.**
Галочки доменов: ядро, контент, SEO, коммерция, совместная работа,
автоматизация, маркетинг, интеграции, безопасность, события, дизайн.
Выключенный домен убирает его инструменты из выдачи модели.

Создание и удаление собственных модулей относятся к домену **Безопасность и
инфраструктура** — если он выключен, этих инструментов у модели не будет.

**Обслуживание → Индекс.**
Дождаться полного прохода. Пока индекс не готов, чат заблокирован. Индексация
идёт в фоне агентом, браузер можно закрыть. На небольшом каталоге — 5–10 минут,
на крупном — дольше; прогресс виден на вкладке.

**Сервис → Параллельных задач демона.**
Сколько задач демон выполняет параллельно (по умолчанию 10 — столько же, сколько
задач может быть у пользователя; применяется после перезапуска демона). Задачи в
разных чатах идут одновременно и не ждут друг друга, в одном чате — по очереди.
У пользователя может быть не больше 10 задач сразу; это значение фиксировано.

Каждая параллельная задача занимает PHP-воркер (запросы инструментов) и соединение
с БД. Проверьте, что `pm.max_children` PHP-FPM и MySQL `max_connections` больше
этого числа с запасом, а `request_terminate_timeout`/`fastcgi_read_timeout` не
обрывают долгие операции (дамп, индексация, большие страницы).

```bash
php-fpm -tt 2>/dev/null | grep -E 'pm.max_children|request_terminate_timeout'
mysql -e 'SHOW VARIABLES LIKE "max_connections"'
```

**Сервис → Резервные копии в S3.**
Дампы лежат на том же сервере, что и сайт. Чтобы архивы пережили потерю сервера,
укажите S3-совместимое хранилище: `backup_s3_endpoint`, `backup_s3_bucket`,
`backup_s3_key`, `backup_s3_secret`, при необходимости `backup_s3_region`.
После этого агент сможет отправлять архивы инструментом `upload_backup_s3`.
Минимальные права ключа: `s3:PutObject` на префикс `backups/*`. Нужен исходящий
HTTPS до endpoint и корректные CA-сертификаты; endpoint должен поддерживать
S3-совместимую подпись AWS4.

**Свои модули.**
Агент может создавать модули только в `/local/modules`, через `create_module`;
изменять и удалять — только те, что создал сам (реестр в
`b_mibazarow_bxadmins_custom_module`, поле `owned` в `list_modules`). Модули ядра
и вендора доступны только для чтения. Это не настройка, а жёсткое правило —
отключать его не нужно.

**Обслуживание → Обзор.**
Все карточки должны быть зелёными: модель, индекс, SSE, демон, таблицы.

Карточки «Обзора» не проверяют всё окружение. Node, Chrome, `mysqldump`, `mysql`,
`git`, `proc_open`, права, эмбеддинги и NTP проверяйте командами из §1, §4 и §6:
модуль покажет проблему только там, где инструмент реально вызывается.

---

## 6. Приёмка

```bash
cd <site_dir>

# 1. инструменты на месте
sudo -u bitrix php -r '$_SERVER["DOCUMENT_ROOT"]=getcwd();
define("NO_KEEP_STATISTIC",true); define("NOT_CHECK_PERMISSIONS",true);
require "bitrix/modules/main/include/prolog_before.php";
\Bitrix\Main\Loader::includeModule("mibazarow.bxadmins");
echo count(\Mibazarow\Bxadmins\Service\ToolRegistry::definitions()), " tools\n";'

# 2. точки отката работают
sudo -u bitrix php -r '$_SERVER["DOCUMENT_ROOT"]=getcwd();
define("NO_KEEP_STATISTIC",true); define("NOT_CHECK_PERMISSIONS",true);
require "bitrix/modules/main/include/prolog_before.php";
\Bitrix\Main\Loader::includeModule("mibazarow.bxadmins");
echo \Mibazarow\Bxadmins\Service\GitService::isAvailable()
    ? "git ok\n" : \Mibazarow\Bxadmins\Service\GitService::lastError() . "\n";'

# 3. демон жив
pgrep -f bxadmins-daemon.js

# 4. агенты идут
sudo mysql -e "SELECT NAME, LAST_EXEC FROM b_agent WHERE MODULE_ID='mibazarow.bxadmins'" <db_name>

# 5. окружение, от которого зависит работа
php -r 'echo PHP_VERSION, "\n";
var_dump(function_exists("proc_open"), function_exists("exec"), function_exists("shell_exec"));'
node -v
command -v node mysqldump mysql git diff ps setsid
timedatectl status | head -3
ss -ltnp | grep 8899

# 6. браузер (при запущенном демоне)
sudo -u bitrix php -r '$_SERVER["DOCUMENT_ROOT"]=getcwd();
define("NO_KEEP_STATISTIC",true); define("NOT_CHECK_PERMISSIONS",true);
require "bitrix/modules/main/include/prolog_before.php";
\Bitrix\Main\Loader::includeModule("mibazarow.bxadmins");
var_dump(\Mibazarow\Bxadmins\Service\BrowserService::isAvailable());'

# 7. эмбеддинги (если сервис установлен)
curl -sS http://127.0.0.1:8000/health

# 8. бэкап и восстановление
sudo -u bitrix php -r '$_SERVER["DOCUMENT_ROOT"]=getcwd();
define("NO_KEEP_STATISTIC",true); define("NOT_CHECK_PERMISSIONS",true);
require "bitrix/modules/main/include/prolog_before.php";
\Bitrix\Main\Loader::includeModule("mibazarow.bxadmins");
print_r(\Mibazarow\Bxadmins\Service\BackupService::create(false));'

# 9. дампы не отдаются по HTTP
curl -s -o /dev/null -w '%{http_code}\n' https://<domain>/bitrix/backup/
```

Финальный тест руками, в браузере: открыть **BXADMIN-S → Чат**, написать
«создай в корне файл test.html с текстом проверка», дождаться карточки,
нажать «Подтвердить», затем «Откатить». Файл должен появиться и исчезнуть.

В DevTools → Network при этом должен быть запрос `stream.php` с типом
`text/event-stream` (переподключается примерно раз в 25 секунд) и без ошибок
в консоли. Если поток не идёт, чат продолжит работать через `task_active`.

Проверка собственных модулей (по желанию): попросите агента «создай модуль
acme.testmod с классом lib/Demo.php и установи его». В карточке появится список
файлов; после подтверждения модуль будет в `/local/modules/acme.testmod`,
а `list_modules` покажет его с `origin=agent`, `owned=true`.

---

## 7. Обновление модуля

Сначала остановите фоновые процессы, иначе демон продолжит работать со старым
кодом. Перед обновлением также сделайте бэкап БД и файлов.

```bash
pkill -f bxadmins-daemon.js
pkill -f scrapling-mcp
rm -f <site_dir>/local/modules/mibazarow.bxadmins/daemon/.daemon.lock
```

Скопируйте новую версию и верните владельца файлов (rsync мог его изменить):

```bash
rsync -av --delete local/modules/mibazarow.bxadmins/ <user>@<server>:<site_dir>/local/modules/mibazarow.bxadmins/
sudo chown -R bitrix:bitrix <site_dir>/local/modules/mibazarow.bxadmins
```

Админка → **Marketplace → Установленные решения** → BXADMIN-S →
**«Переустановить»**. Установщик идемпотентен: недостающие таблицы создаются,
существующие не трогаются, агенты обновляются, обработчики перерегистрируются.
Данные не теряются.

**Важно про схему БД:** установщик выполняет `install.sql` только если не хватает
хотя бы одной таблицы, и использует `CREATE TABLE IF NOT EXISTS`. Колонки и
индексы в уже существующих таблицах он **не меняет**. Если в релизе добавлена
колонка, после «Переустановить» её не появится — нужна отдельная миграция или
ручной `ALTER TABLE` по примечаниям к релизу. Проверьте `SHOW CREATE TABLE` для
изменённых таблиц.

Либо CLI (см. §3.2) — он делает то же самое.

После обновления сбросьте кеш и перезапустите демон:

```bash
rm -f <site_dir>/local/modules/mibazarow.bxadmins/daemon/.daemon.lock
```

Агент поднимет демон и скрапер в течение минуты. Проверка:

```bash
pgrep -af bxadmins-daemon.js
ss -ltnp | grep 8899
tail -20 /home/bitrix/bxadmins-data/logs/daemon.log
```

---

## 8. Удаление

Админка → Marketplace → BXADMIN-S → «Удалить». Установщик снимает обработчики,
агентов, admin-обёртки и таблицы модуля.

Перед удалением остановите фоновые процессы, иначе они останутся работать без
модуля и будут писать ошибки в журнал:

```bash
pkill -f bxadmins-daemon.js
pkill -f scrapling-mcp
rm -f <site_dir>/local/modules/mibazarow.bxadmins/daemon/.daemon.lock
```

После удаления проверьте, что не осталось процессов:

```bash
pgrep -af 'bxadmins-daemon|scrapling-mcp' || echo "processes stopped"
```

Модули, созданные агентом в `/local/modules`, при удалении харнеса **не
удаляются** — это код сайта. Удалите их через `delete_module` до деинсталляции
или вручную после.

Данные вне docroot остаются — удалите вручную, если не нужны:

```bash
sudo rm -rf /home/bitrix/bxadmins-data
```

SQL-дампы и снапшоты файлов лежат внутри сайта и тоже не удаляются автоматически:

```bash
sudo rm -rf <site_dir>/bitrix/backup/bx_admin_s_bacaps
```

Опции модуля в `b_option` при деинсталляции не очищаются. Если нужен полный
след-в-след, удалите их отдельно:

```sql
DELETE FROM b_option WHERE MODULE_ID = 'mibazarow.bxadmins';
```

---

## 9. Чек-лист

- [ ] PHP 8.1–8.5 с `pdo_mysql`, `curl`, `mbstring`, `json`, `xml`, `openssl`, `zip`, `fileinfo`
- [ ] `proc_open`, `exec`, `shell_exec` не запрещены (проверено и для CLI, и для веб-SAPI)
- [ ] `upload_max_filesize` и `post_max_size` ≥ 25 МБ, `memory_limit` ≥ 256 МБ, `client_max_body_size` ≥ 32 МБ
- [ ] Node.js 22 (или 18+ без браузерных инструментов), виден из cron
- [ ] `mysqldump`, `mysql`, `git`, `diff`, `setsid`, `ps`, `unzip` — на месте
- [ ] NTP работает, время PHP/MySQL/системы совпадает
- [ ] Порт 8899 занят демоном и не открыт наружу; остальные сервисные порты закрыты
- [ ] Пользователь БД умеет `CREATE/ALTER/DROP/INDEX`, импорт дампа доступен
- [ ] Сайт открывается по https, admin-доступ есть
- [ ] Модуль скопирован целиком, права `bitrix:bitrix`, `bitrix/admin` доступен на запись
- [ ] Установлен, раздел BXADMIN-S виден в левом меню
- [ ] `/home/bitrix/bxadmins-data/{logs,screenshots,git,chrome,chat-files}` создан и доступен на запись
- [ ] `bitrix/backup/bx_admin_s_bacaps` создан и доступен на запись
- [ ] nginx/apache не отдаёт `/bitrix/backup/` по HTTP
- [ ] cron Битрикса идёт (`agents_use_crontab=Y`), пять агентов модуля имеют `LAST_EXEC`
- [ ] Демон запущен, порт 8899 слушается, в журнале `daemon started`
- [ ] Модель подключена, «Проверить» отвечает, модель поддерживает tool calling
- [ ] Индексация завершена
- [ ] Сервис эмбеддингов отвечает на `/health` (или осознанно работаем на FULLTEXT)
- [ ] Chrome запускается, `BrowserService::isAvailable()` = true, шрифты установлены
- [ ] `Imagick` (+ Ghostscript для PDF) установлен, если нужны картинки/PDF (опционально)
- [ ] Бэкап создаётся, восстановление доступно
- [ ] Таблица журнала `b_mibazarow_bxadmins_task_event` создана, в чате идёт `stream.php` (text/event-stream)
- [ ] Все карточки на «Обзоре» зелёные
- [ ] Сквозной тест: файл создан и откачен
- [ ] `create_module` создаёт модуль в `local/modules`, `delete_module` его удаляет
- [ ] Правка стороннего модуля отклоняется с понятной ошибкой

---

## 10. Частые проблемы

| Симптом | Причина | Что делать |
|---|---|---|
| Чата нет, раздел в меню есть | Индекс не завершён | Обслуживание → Индекс, дождаться |
| Чат принимает запрос, ответа нет | Демон не запущен | `pgrep -f bxadmins-daemon.js`, журнал `daemon.log` |
| Демон падает сразу | Нет Node или `SECRET` | `node -v`, права на `.daemon.lock` |
| «Модель не подключена» | Нет активного профиля | Настройки → Модель |
| Ответ идёт рывками раз в 2–3 с | SSE не проходит через прокси/браузер, работает резервный опрос | §4.7; проверить запрос `stream.php` в Network |
| «Не удалось создать SQL-дамп» | Нет `mysqldump` или прав на каталог | §1, §4.3 |
| Точки отката файлов не создаются | Нет `git` | `apt install git` |
| Инструменты не видны модели | Домен выключен в «Доступе» | Включить галочки |
| Скриншоты не делаются | Нет браузера | §4.6 |
| Задачи ждут друг друга | Один рабочий цикл | Сервис → «Параллельных задач демона», затем перезапуск демона |
| `upload_backup_s3` отвечает «S3 не настроен» | Не заполнены ключи | Сервис → «Резервные копии в S3» |
| Поиск работает грубо | Нет сервиса эмбеддингов | §4.8, FULLTEXT — временный режим |
| Демон работает с чужим доменом | Cron из другого docroot | §4.4, проверить `daemon_site_url` |
| `create_module`/`delete_module` отвечают «реестр владения» | Не создана таблица после обновления | Переустановить модуль (§7) |
| Правка модуля отклонена: «не создан агентом» | Модуль чужой или создан до установки реестра | Это защита; свои модули создавайте через `create_module` |
| `create_module` отвечает «ID зарезервирован» | `bitrix.*`, `main` и сам харнес запрещены | Выберите свой vendor, например `acme.forms` |
| После отката сайта пропал чат | Не должно случаться | Служебные таблицы модуля в дамп не входят; проверьте, что модуль обновлён |
| `bad timestamp` / `401` от worker.php | Сбиты часы (>120 с) | Включить NTP/chrony, проверить `timedatectl` |
| `node not found in PATH` | Node из nvm/asdf не виден cron | Симлинк в `/usr/local/bin`, проверка через `env -i PATH=...` |
| `proc_open() has been disabled` | Функция в `disable_functions` | Разрешить для CLI и веб-SAPI |
| `WebSocket is not defined` в daemon.log | Node 18/20 | Обновить до Node 22 |
| Порт 8899 занят | Другой демон/процесс | `ss -ltnp \| grep 8899`, остановить лишний |
| Chrome не стартует | Нет библиотек или `CHROME_BIN` | `ldd ... \| grep 'not found'`, §4.6 |
| В скриншотах квадраты вместо кириллицы | Нет шрифтов | `apt install fonts-dejavu-core fonts-liberation` |
| Дампы доступны по HTTP | nginx не читает `.htaccess` | Закрыть `/bitrix/backup/` в конфиге nginx |
| `mysqldump` падает на `--routines` | Нет прав на процедуры/события | Выдать права или убрать флаги (потеря routines) |
| `mysqldump: unknown option` | Клиент не совпадает с сервером (MySQL/MariaDB) | Поставить клиент той же ветки, что и сервер |
| `setsid: command not found` | Нет `util-linux` | `apt install util-linux` |
| Восстановление: `mysql не найден` | Нет клиента MySQL | `apt install default-mysql-client` (или `mariadb-client`) |
| Git-откат не работает при нестандартном docroot | Путь `bxadmins-data` вычисляется от родителя docroot | Симлинк на `/home/bitrix/bxadmins-data` |
| Эмбеддинги долго/таймаут | Мало CPU/RAM или модель не загрузилась | Проверить `/health`, логи сервиса, увеличить таймаут/ресурсы |
| Вложения >25 МБ не загружаются | Лимиты PHP | §1, `upload_max_filesize`/`post_max_size` |
| Восстановление падает на большом дампе | `max_allowed_packet` MySQL | Поднять до 64M/256M на время импорта |
| После обновления нет новой колонки | `install.sql` не меняет существующие таблицы | Применить миграцию из примечаний к релизу |
