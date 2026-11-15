<?php

const ADMIN_MODULE_NAME = 'mibazarow.bxadmins';

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

use Bitrix\Main\Application;
use Bitrix\Main\Context;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ModuleManager;
use Mibazarow\Bxadmins\Admin\Assets;
use Mibazarow\Bxadmins\Agent\DaemonAgent;
use Mibazarow\Bxadmins\Config;
use Mibazarow\Bxadmins\Service\BackupService;
use Mibazarow\Bxadmins\Service\ChangeService;
use Mibazarow\Bxadmins\Service\IndexService;
use Mibazarow\Bxadmins\Service\Logger;

global $USER, $APPLICATION;

Loc::loadMessages(__FILE__);

if (!$USER->IsAdmin()) {
    $APPLICATION->AuthForm(Loc::getMessage('MIBAZAROW_BXADMINS_ACCESS_DENIED'));
}

if (!Loader::includeModule(ADMIN_MODULE_NAME)) {
    require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
    CAdminMessage::ShowMessage(array('MESSAGE' => Loc::getMessage('MIBAZAROW_BXADMINS_MODULE_NOT_INSTALLED'), 'TYPE' => 'ERROR'));
    require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
    return;
}

$connection = Application::getConnection();
$tableNames = array(
    'b_mibazarow_bxadmins_chat',
    'b_mibazarow_bxadmins_message',
    'b_mibazarow_bxadmins_index',
    'b_mibazarow_bxadmins_change',
    'b_mibazarow_bxadmins_task',
    'b_mibazarow_bxadmins_task_event',
    'b_mibazarow_bxadmins_llm_profile',
    'b_mibazarow_bxadmins_mcp_server',
    'b_mibazarow_bxadmins_schedule',
);

$tables = array();
foreach ($tableNames as $tableName) {
    $tables[$tableName] = $connection->isTableExists($tableName);
}

$request = Context::getCurrent()->getRequest();
$siteUrl = ($request->isHttps() ? 'https://' : 'http://') . $request->getServer()->get('SERVER_NAME') . '/';

$indexStatus = IndexService::status();
$displayOffset = $indexStatus['complete'] ? (int)$indexStatus['total'] : (int)$indexStatus['offset'];

$backupStatus = BackupService::status();
$backups = BackupService::list();
$backupKeep = Config::backupKeep();

$changes = ChangeService::recent(20);
$changeStatusLabels = array(
    ChangeService::STATUS_PENDING => Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_CHANGE_STATUS_PENDING'),
    ChangeService::STATUS_EXECUTED => Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_CHANGE_STATUS_EXECUTED'),
    ChangeService::STATUS_REJECTED => Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_CHANGE_STATUS_REJECTED'),
    ChangeService::STATUS_FAILED => Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_CHANGE_STATUS_FAILED'),
    ChangeService::STATUS_ROLLED_BACK => Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_CHANGE_STATUS_ROLLED_BACK'),
);

$formatSize = static function (int $bytes): string {
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2, ',', ' ') . ' МБ';
    }
    if ($bytes >= 1024) {
        return number_format($bytes / 1024, 1, ',', ' ') . ' КБ';
    }

    return $bytes . ' Б';
};

/**
 * Разбирает строку журнала на дату, уровень и текст.
 * Нестандартная строка возвращается целиком в поле message.
 */
$parseLogLine = static function (string $line): array {
    if (preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}) \[([a-z]+)\] (.*)$/u', $line, $match) === 1) {
        return array(
            'date' => $match[1],
            'level' => $match[2],
            'message' => $match[3],
        );
    }

    return array('date' => '', 'level' => '', 'message' => $line);
};

// Логи пишутся в файл (в БД не храним), поэтому читаем хвост журнала.
// Формат строки: «YYYY-MM-DD HH:MM:SS [level] текст {json}» — разбираем на колонки,
// иначе журнал выглядит как стена текста.
$logs = array();
foreach (Logger::tail(50) as $line) {
    $logs[] = $parseLogLine($line);
}

$llmConfigured = Config::isLlmConfigured();
$tablesMissing = count(array_filter($tables, static fn(bool $exists): bool => !$exists));
$streamOk = (bool)($tables['b_mibazarow_bxadmins_task_event'] ?? false);

// Демон выполняет задачи агента: без него чат принимает запросы, но ничего не делает.
$daemonOk = DaemonAgent::isRunning();


$APPLICATION->SetTitle(Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_TITLE'));

// AddHeadScript пропускает путь через Asset и теряет ?v= — подключаем строкой.
$APPLICATION->AddHeadString('<link rel="stylesheet" href="' . Assets::url('admin.css') . '">', true);
$APPLICATION->AddHeadString('<script src="' . Assets::url('manage.js') . '"></script>', true);

$aTabs = array(
    array('DIV' => 'tab_overview', 'TAB' => Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_TAB_OVERVIEW'), 'TITLE' => Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_TAB_OVERVIEW')),
    array('DIV' => 'tab_index', 'TAB' => Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_TAB_INDEX'), 'TITLE' => Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_INDEX_TITLE')),
    array('DIV' => 'tab_changes', 'TAB' => Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_TAB_CHANGES'), 'TITLE' => Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_CHANGES_TITLE')),
    array('DIV' => 'tab_backups', 'TAB' => Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_TAB_BACKUPS'), 'TITLE' => Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_BACKUPS_TITLE')),
    array('DIV' => 'tab_logs', 'TAB' => Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_TAB_LOGS'), 'TITLE' => Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_LOGS_TITLE')),
);

$tabControl = new CAdminTabControl('bxadminsManage', $aTabs, true, true);

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
?>
<div class="bxadmins-manage">
<?php
$tabControl->Begin();
$tabControl->BeginNextTab();
?>
<tr><td class="adm-detail-content-cell">
<div class="bxadmins-cards">
    <div class="bxadmins-card">
        <div class="bxadmins-card-value"><?php echo htmlspecialchars(ModuleManager::getVersion(ADMIN_MODULE_NAME), ENT_QUOTES, 'UTF-8'); ?></div>
        <div class="bxadmins-card-label"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_MODULE_VERSION'); ?></div>
    </div>
    <div class="bxadmins-card">
        <div class="bxadmins-card-value"><?php echo PHP_VERSION; ?></div>
        <div class="bxadmins-card-label"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_PHP_VERSION'); ?></div>
    </div>
    <div class="bxadmins-card">
        <div class="bxadmins-card-value bxadmins-card-value-sm"><?php echo htmlspecialchars($siteUrl, ENT_QUOTES, 'UTF-8'); ?></div>
        <div class="bxadmins-card-label"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_SITE_URL'); ?></div>
    </div>
    <div class="bxadmins-card">
        <div class="bxadmins-card-value<?php echo $llmConfigured ? ' bxadmins-card-value-ok' : ' bxadmins-card-value-warn'; ?>">
            <?php echo $llmConfigured
                ? Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_LLM_CONFIGURED_SHORT')
                : Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_LLM_NOT_CONFIGURED_SHORT'); ?>
        </div>
        <div class="bxadmins-card-label"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_LLM_STATUS'); ?> · <?php
            echo htmlspecialchars($llmConfigured ? Config::llmModel() : Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_LLM_NONE'), ENT_QUOTES, 'UTF-8');
        ?></div>
    </div>
    <div class="bxadmins-card">
        <div class="bxadmins-card-value<?php echo $indexStatus['complete'] ? ' bxadmins-card-value-ok' : ''; ?>">
            <?php echo $indexStatus['complete']
                ? Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_INDEX_STATE_DONE')
                : Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_INDEX_STATE_RUNNING'); ?>
        </div>
        <div class="bxadmins-card-label"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_INDEX_STATE'); ?></div>
    </div>
    <div class="bxadmins-card">
        <div class="bxadmins-card-value<?php echo $streamOk ? ' bxadmins-card-value-ok' : ' bxadmins-card-value-warn'; ?>">
            <?php echo $streamOk
                ? Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_STREAM_OK')
                : Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_STREAM_BROKEN'); ?>
        </div>
        <div class="bxadmins-card-label"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_STREAM_STATE'); ?></div>
    </div>
    <div class="bxadmins-card">
        <div class="bxadmins-card-value<?php echo $daemonOk ? ' bxadmins-card-value-ok' : ' bxadmins-card-value-warn'; ?>">
            <?php echo $daemonOk
                ? Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_DAEMON_OK')
                : Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_DAEMON_DOWN'); ?>
        </div>
        <div class="bxadmins-card-label"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_DAEMON_STATE'); ?></div>
    </div>
    <div class="bxadmins-card">
        <div class="bxadmins-card-value"><?php echo (int)$indexStatus['rows']; ?> / <?php echo (int)$indexStatus['chunks']; ?></div>
        <div class="bxadmins-card-label"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_OVERVIEW_INDEX_ROWS'); ?></div>
    </div>
    <div class="bxadmins-card">
        <div class="bxadmins-card-value"><?php echo count($backups); ?></div>
        <div class="bxadmins-card-label"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_OVERVIEW_BACKUPS'); ?></div>
    </div>
    <div class="bxadmins-card">
        <div class="bxadmins-card-value"><?php echo count($changes); ?></div>
        <div class="bxadmins-card-label"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_OVERVIEW_CHANGES'); ?></div>
    </div>
    <div class="bxadmins-card">
        <div class="bxadmins-card-value<?php echo $tablesMissing === 0 ? ' bxadmins-card-value-ok' : ' bxadmins-card-value-warn'; ?>">
            <?php echo (count($tables) - $tablesMissing); ?> / <?php echo count($tables); ?>
        </div>
        <div class="bxadmins-card-label"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_OVERVIEW_TABLES'); ?></div>
    </div>
</div>
<table class="adm-list-table">
    <thead>
    <tr class="adm-list-table-header">
        <th class="adm-list-table-cell"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_TABLE'); ?></th>
        <th class="adm-list-table-cell"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_STATUS'); ?></th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($tables as $tableName => $exists): ?>
        <tr class="adm-list-table-row">
            <td class="adm-list-table-cell"><code><?php echo $tableName; ?></code></td>
            <td class="adm-list-table-cell">
                <span class="bxadmins-table-status<?php echo $exists ? ' bxadmins-table-status-ok' : ' bxadmins-table-status-miss'; ?>">
                    <?php echo $exists
                        ? Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_TABLE_OK')
                        : Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_TABLE_MISSING'); ?>
                </span>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</td></tr>
<?php
$tabControl->BeginNextTab();
?>
<tr><td class="adm-detail-content-cell">
<div class="bxadmins-index"
     data-ajax="/local/modules/mibazarow.bxadmins/admin/ajax.php"
     data-sessid="<?php echo bitrix_sessid(); ?>"
     data-label-running="<?php echo htmlspecialchars(Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_ACTION_RUNNING'), ENT_QUOTES, 'UTF-8'); ?>"
     data-clear-confirm="<?php echo htmlspecialchars(Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_INDEX_CLEAR_CONFIRM'), ENT_QUOTES, 'UTF-8'); ?>"
     data-clear-ok="<?php echo htmlspecialchars(Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_INDEX_CLEAR_OK'), ENT_QUOTES, 'UTF-8'); ?>"
     data-batch-template="<?php echo htmlspecialchars(Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_INDEX_BATCH_RESULT'), ENT_QUOTES, 'UTF-8'); ?>"
     data-batch-done="<?php echo htmlspecialchars(Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_INDEX_DONE_NOTE'), ENT_QUOTES, 'UTF-8'); ?>"
     data-already-complete="<?php echo htmlspecialchars(Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_INDEX_ALREADY_COMPLETE'), ENT_QUOTES, 'UTF-8'); ?>"
     data-start-confirm="<?php echo htmlspecialchars(Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_INDEX_START_CONFIRM'), ENT_QUOTES, 'UTF-8'); ?>"
     data-start-ok="<?php echo htmlspecialchars(Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_INDEX_START_OK'), ENT_QUOTES, 'UTF-8'); ?>"
     data-state-done="<?php echo htmlspecialchars(Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_INDEX_STATE_DONE'), ENT_QUOTES, 'UTF-8'); ?>"
     data-state-running="<?php echo htmlspecialchars(Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_INDEX_STATE_RUNNING'), ENT_QUOTES, 'UTF-8'); ?>"
     data-started-label="<?php echo htmlspecialchars(Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_INDEX_STARTED'), ENT_QUOTES, 'UTF-8'); ?>"
     data-errors-template="<?php echo htmlspecialchars(Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_INDEX_ERRORS_NOTE'), ENT_QUOTES, 'UTF-8'); ?>"
     data-embed-ok="<?php echo htmlspecialchars(Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_EMBED_OK'), ENT_QUOTES, 'UTF-8'); ?>"
     data-embed-fail="<?php echo htmlspecialchars(Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_EMBED_FAIL'), ENT_QUOTES, 'UTF-8'); ?>"
     data-fail-template="<?php echo htmlspecialchars(Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_ACTION_FAIL'), ENT_QUOTES, 'UTF-8'); ?>"
     data-none="<?php echo htmlspecialchars(Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_INDEX_NONE'), ENT_QUOTES, 'UTF-8'); ?>">
    <div class="bxadmins-cards">
        <div class="bxadmins-card">
            <div class="bxadmins-card-value" id="bxadmins-index-state"><?php echo $indexStatus['complete']
                ? Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_INDEX_STATE_DONE')
                : Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_INDEX_STATE_RUNNING'); ?></div>
            <div class="bxadmins-card-label"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_INDEX_STATE'); ?></div>
        </div>
        <div class="bxadmins-card">
            <div class="bxadmins-card-value" id="bxadmins-index-chunks"><?php echo (int)$indexStatus['rows']; ?></div>
            <div class="bxadmins-card-label"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_INDEX_ROWS'); ?></div>
        </div>
        <div class="bxadmins-card">
            <div class="bxadmins-card-value" id="bxadmins-index-chunks-total"><?php echo (int)$indexStatus['chunks']; ?></div>
            <div class="bxadmins-card-label"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_INDEX_CHUNKS'); ?></div>
        </div>
        <div class="bxadmins-card">
            <div class="bxadmins-card-value" id="bxadmins-index-cursor"><?php echo $displayOffset; ?> / <?php echo (int)$indexStatus['total']; ?></div>
            <div class="bxadmins-card-label"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_INDEX_CURSOR'); ?></div>
        </div>
        <div class="bxadmins-card">
            <div class="bxadmins-card-value bxadmins-card-value-sm" id="bxadmins-index-lastrun"><?php echo $indexStatus['last_run'] !== ''
                ? htmlspecialchars($indexStatus['last_run'], ENT_QUOTES, 'UTF-8')
                : Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_INDEX_NONE'); ?></div>
            <div class="bxadmins-card-label"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_INDEX_LAST_RUN'); ?></div>
        </div>
        <div class="bxadmins-card">
            <div class="bxadmins-card-value bxadmins-card-value-sm" id="bxadmins-index-model"><?php echo htmlspecialchars($indexStatus['embedding_model'], ENT_QUOTES, 'UTF-8'); ?></div>
            <div class="bxadmins-card-label"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_INDEX_MODEL'); ?></div>
        </div>
    </div>

    <table class="adm-detail-content-table edit-table">
        <tbody>
        <tr>
            <td class="adm-detail-content-cell-l"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_INDEX_STARTED'); ?>:</td>
            <td class="adm-detail-content-cell-r" id="bxadmins-index-started"><?php echo $indexStatus['started_at'] !== ''
                ? htmlspecialchars($indexStatus['started_at'], ENT_QUOTES, 'UTF-8')
                : Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_INDEX_NONE'); ?></td>
        </tr>
        <tr>
            <td class="adm-detail-content-cell-l"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_INDEX_BY_TYPE'); ?>:</td>
            <td class="adm-detail-content-cell-r" id="bxadmins-index-bytype"><?php
                $parts = array();
                foreach ($indexStatus['by_type'] as $type => $count) {
                    $parts[] = $type . ': ' . (int)$count;
                }
                echo htmlspecialchars(implode(' · ', $parts), ENT_QUOTES, 'UTF-8');
            ?></td>
        </tr>
        <tr>
            <td class="adm-detail-content-cell-l"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_INDEX_LAST_ERROR'); ?>:</td>
            <td class="adm-detail-content-cell-r" id="bxadmins-index-lasterror"><?php echo $indexStatus['last_error'] !== ''
                ? htmlspecialchars($indexStatus['last_error'], ENT_QUOTES, 'UTF-8')
                : Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_INDEX_NONE'); ?></td>
        </tr>
        </tbody>
    </table>

    <div class="bxadmins-progress">
        <div class="bxadmins-progress-bar">
            <div class="bxadmins-progress-fill" id="bxadmins-index-fill" style="width: <?php
                echo $indexStatus['total'] > 0
                    ? min(100, (int)round($displayOffset / max(1, (int)$indexStatus['total']) * 100))
                    : 0;
            ?>%"></div>
        </div>
        <div class="bxadmins-progress-label" id="bxadmins-index-progress-label"><?php
            echo $indexStatus['total'] > 0
                ? $displayOffset . ' / ' . (int)$indexStatus['total']
                : Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_INDEX_CHUNKS') . ': ' . (int)$indexStatus['chunks'];
        ?></div>
    </div>

    <div class="bxadmins-actions">
        <label class="bxadmins-checkbox">
            <input type="checkbox" id="bxadmins-batch-reindex">
            <?php echo Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_INDEX_REINDEX_LABEL'); ?>
        </label>
        <button type="button" class="adm-btn adm-btn-save" id="bxadmins-btn-index-batch"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_BTN_INDEX_RUN'); ?></button>
        <button type="button" class="adm-btn" id="bxadmins-btn-index-start"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_BTN_INDEX_START'); ?></button>
        <button type="button" class="adm-btn" id="bxadmins-btn-index-clear"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_BTN_INDEX_CLEAR'); ?></button>
        <button type="button" class="adm-btn" id="bxadmins-btn-test-embed"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_BTN_TEST_EMBED'); ?></button>
    </div>
    <div class="adm-info-message" id="bxadmins-index-result" hidden></div>
</div>
</td></tr>
<?php
$tabControl->BeginNextTab();
?>
<tr><td class="adm-detail-content-cell">
<div class="bxadmins-changes"
     data-ajax="/local/modules/mibazarow.bxadmins/admin/ajax.php"
     data-sessid="<?php echo bitrix_sessid(); ?>"
     data-rollback-label="<?php echo htmlspecialchars(Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_CHANGE_ROLLBACK'), ENT_QUOTES, 'UTF-8'); ?>"
     data-rollback-confirm="<?php echo htmlspecialchars(Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_CHANGE_ROLLBACK_CONFIRM'), ENT_QUOTES, 'UTF-8'); ?>"
     data-rollback-ok="<?php echo htmlspecialchars(Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_CHANGE_ROLLBACK_OK'), ENT_QUOTES, 'UTF-8'); ?>"
     data-restore-site-label="<?php echo htmlspecialchars(Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_CHANGE_RESTORE_SITE'), ENT_QUOTES, 'UTF-8'); ?>"
     data-restore-site-confirm="<?php echo htmlspecialchars(Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_CHANGE_RESTORE_SITE_CONFIRM'), ENT_QUOTES, 'UTF-8'); ?>"
     data-restore-site-ok="<?php echo htmlspecialchars(Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_CHANGE_RESTORE_SITE_OK'), ENT_QUOTES, 'UTF-8'); ?>"
     data-fail-template="<?php echo htmlspecialchars(Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_ACTION_FAIL'), ENT_QUOTES, 'UTF-8'); ?>"
     data-none="<?php echo htmlspecialchars(Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_BACKUP_NONE'), ENT_QUOTES, 'UTF-8'); ?>"
     data-status-pending="<?php echo htmlspecialchars(Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_CHANGE_STATUS_PENDING'), ENT_QUOTES, 'UTF-8'); ?>"
     data-status-executed="<?php echo htmlspecialchars(Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_CHANGE_STATUS_EXECUTED'), ENT_QUOTES, 'UTF-8'); ?>"
     data-status-rejected="<?php echo htmlspecialchars(Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_CHANGE_STATUS_REJECTED'), ENT_QUOTES, 'UTF-8'); ?>"
     data-status-failed="<?php echo htmlspecialchars(Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_CHANGE_STATUS_FAILED'), ENT_QUOTES, 'UTF-8'); ?>"
     data-status-rolled_back="<?php echo htmlspecialchars(Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_CHANGE_STATUS_ROLLED_BACK'), ENT_QUOTES, 'UTF-8'); ?>">
    <div class="adm-info-message"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_CHANGES_NOTE'); ?></div>
    <div class="adm-info-message" id="bxadmins-change-result" hidden></div>
    <table class="adm-list-table">
        <thead>
        <tr class="adm-list-table-header">
            <th class="adm-list-table-cell"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_CHANGE_DATE'); ?></th>
            <th class="adm-list-table-cell"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_CHANGE_TOOL'); ?></th>
            <th class="adm-list-table-cell"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_CHANGE_SUMMARY'); ?></th>
            <th class="adm-list-table-cell"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_CHANGE_STATUS'); ?></th>
            <th class="adm-list-table-cell"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_CHANGE_BACKUP'); ?></th>
            <th class="adm-list-table-cell"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_CHANGE_ACTIONS'); ?></th>
        </tr>
        </thead>
        <tbody id="bxadmins-change-list">
        <?php foreach ($changes as $change): ?>
            <?php $changeStatus = (string)$change['status']; ?>
            <tr class="adm-list-table-row" data-change-id="<?php echo (int)$change['id']; ?>">
                <td class="adm-list-table-cell"><?php echo htmlspecialchars((string)$change['created_at'], ENT_QUOTES, 'UTF-8'); ?></td>
                <td class="adm-list-table-cell"><code><?php echo htmlspecialchars((string)$change['tool'], ENT_QUOTES, 'UTF-8'); ?></code></td>
                <td class="adm-list-table-cell"><?php echo htmlspecialchars((string)$change['summary'], ENT_QUOTES, 'UTF-8'); ?></td>
                <td class="adm-list-table-cell">
                    <span class="bxadmins-change-status bxadmins-change-status-<?php echo htmlspecialchars($changeStatus, ENT_QUOTES, 'UTF-8'); ?>">
                        <?php echo htmlspecialchars($changeStatusLabels[$changeStatus] ?? $changeStatus, ENT_QUOTES, 'UTF-8'); ?>
                    </span>
                </td>
                <td class="adm-list-table-cell">
                    <?php if ((string)$change['backup_file'] !== ''): ?>
                        <code><?php echo htmlspecialchars((string)$change['backup_file'], ENT_QUOTES, 'UTF-8'); ?></code>
                    <?php else: ?>
                        <?php echo Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_BACKUP_NONE'); ?>
                    <?php endif; ?>
                </td>
                <td class="adm-list-table-cell">
                    <?php if ($changeStatus === ChangeService::STATUS_EXECUTED): ?>
                        <button type="button" class="adm-btn bxadmins-change-rollback" data-change-id="<?php echo (int)$change['id']; ?>">
                            <?php echo Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_CHANGE_ROLLBACK'); ?>
                        </button>
                        <button type="button" class="adm-btn bxadmins-change-restore-site" data-change-id="<?php echo (int)$change['id']; ?>">
                            <?php echo Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_CHANGE_RESTORE_SITE'); ?>
                        </button>
                    <?php else: ?>
                        <?php echo Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_BACKUP_NONE'); ?>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <div class="adm-info-message" id="bxadmins-change-empty"<?php echo empty($changes) ? '' : ' hidden'; ?>><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_CHANGES_EMPTY'); ?></div>
</div>
</td></tr>
<?php
$tabControl->BeginNextTab();
?>
<tr><td class="adm-detail-content-cell">
<div class="bxadmins-backups"
     data-ajax="/local/modules/mibazarow.bxadmins/admin/ajax.php"
     data-sessid="<?php echo bitrix_sessid(); ?>"
     data-created-template="<?php echo htmlspecialchars(Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_BACKUP_CREATED'), ENT_QUOTES, 'UTF-8'); ?>"
     data-deleted-template="<?php echo htmlspecialchars(Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_BACKUP_DELETED'), ENT_QUOTES, 'UTF-8'); ?>"
     data-delete-confirm="<?php echo htmlspecialchars(Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_BACKUP_DELETE_CONFIRM'), ENT_QUOTES, 'UTF-8'); ?>"
     data-delete-label="<?php echo htmlspecialchars(Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_BACKUP_DELETE'), ENT_QUOTES, 'UTF-8'); ?>"
     data-fail-template="<?php echo htmlspecialchars(Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_ACTION_FAIL'), ENT_QUOTES, 'UTF-8'); ?>"
     data-none="<?php echo htmlspecialchars(Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_BACKUP_NONE'), ENT_QUOTES, 'UTF-8'); ?>">
    <div class="adm-info-message">
        <?php echo Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_BACKUPS_DIR'); ?>:
        <code><?php echo htmlspecialchars($backupStatus['dir'], ENT_QUOTES, 'UTF-8'); ?></code>
        &middot;
        <?php echo Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_BACKUPS_KEEP'); ?>:
        <strong><?php echo (int)$backupKeep; ?></strong>
        &middot;
        <?php echo Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_BACKUPS_HTTP_DENY'); ?>:
        <strong class="<?php echo $backupStatus['http_denied'] ? 'bxadmins-table-status-ok' : 'bxadmins-table-status-miss'; ?>"><?php echo $backupStatus['http_denied']
            ? Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_BACKUPS_HTTP_DENIED')
            : Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_BACKUPS_HTTP_OPEN'); ?></strong>
    </div>
    <div class="bxadmins-actions">
        <button type="button" class="adm-btn adm-btn-save" id="bxadmins-btn-backup-create"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_BTN_BACKUP_CREATE'); ?></button>
    </div>
    <div class="adm-info-message" id="bxadmins-backup-result" hidden></div>
    <table class="adm-list-table">
        <thead>
        <tr class="adm-list-table-header">
            <th class="adm-list-table-cell"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_BACKUPS_FILE'); ?></th>
            <th class="adm-list-table-cell"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_BACKUPS_SIZE'); ?></th>
            <th class="adm-list-table-cell"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_BACKUPS_DATE'); ?></th>
            <th class="adm-list-table-cell"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_BACKUPS_ACTIONS'); ?></th>
        </tr>
        </thead>
        <tbody id="bxadmins-backup-list">
        <?php foreach ($backups as $backup): ?>
            <tr class="adm-list-table-row" data-file="<?php echo htmlspecialchars($backup['file'], ENT_QUOTES, 'UTF-8'); ?>">
                <td class="adm-list-table-cell"><code><?php echo htmlspecialchars($backup['file'], ENT_QUOTES, 'UTF-8'); ?></code></td>
                <td class="adm-list-table-cell"><?php echo htmlspecialchars($formatSize($backup['size']), ENT_QUOTES, 'UTF-8'); ?></td>
                <td class="adm-list-table-cell"><?php echo date('Y-m-d H:i', $backup['mtime']); ?></td>
                <td class="adm-list-table-cell">
                    <button type="button" class="adm-btn bxadmins-backup-delete"
                            data-file="<?php echo htmlspecialchars($backup['file'], ENT_QUOTES, 'UTF-8'); ?>">
                        <?php echo Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_BACKUP_DELETE'); ?>
                    </button>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <div class="adm-info-message" id="bxadmins-backup-empty"<?php echo empty($backups) ? '' : ' hidden'; ?>><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_BACKUPS_EMPTY'); ?></div>
    <div class="bxadmins-note"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_BACKUPS_HINT'); ?></div>
</div>
</td></tr>
<?php
$tabControl->BeginNextTab();
?>
<tr><td class="adm-detail-content-cell">
<?php if (empty($logs)): ?>
    <div class="adm-info-message"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_LOGS_EMPTY'); ?></div>
<?php else: ?>
    <table class="adm-list-table">
        <thead>
        <tr class="adm-list-table-header">
            <th class="adm-list-table-cell bxadmins-log-date"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_LOG_DATE'); ?></th>
            <th class="adm-list-table-cell bxadmins-log-level"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_LOG_LEVEL'); ?></th>
            <th class="adm-list-table-cell"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_LOG_MESSAGE'); ?></th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($logs as $log): ?>
            <tr class="adm-list-table-row">
                <td class="adm-list-table-cell bxadmins-log-date"><?php echo htmlspecialchars($log['date'], ENT_QUOTES, 'UTF-8'); ?></td>
                <td class="adm-list-table-cell bxadmins-log-level">
                    <span class="bxadmins-log-badge bxadmins-log-<?php echo htmlspecialchars($log['level'] !== '' ? $log['level'] : 'info', ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($log['level'], ENT_QUOTES, 'UTF-8'); ?></span>
                </td>
                <td class="adm-list-table-cell bxadmins-log-message"><?php echo htmlspecialchars($log['message'], ENT_QUOTES, 'UTF-8'); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
</td></tr>
<?php
$tabControl->End();
?>
</div>
<?php
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
