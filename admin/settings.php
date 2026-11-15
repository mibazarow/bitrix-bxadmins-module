<?php

const ADMIN_MODULE_NAME = 'mibazarow.bxadmins';

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ModuleManager;
use Mibazarow\Bxadmins\Admin\Assets;
use Mibazarow\Bxadmins\Config;
use Mibazarow\Bxadmins\Service\LlmProfileService;
use Mibazarow\Bxadmins\Service\McpService;
use Mibazarow\Bxadmins\Service\SkillService;

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

$messages = array();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_bitrix_sessid()) {
    $values = array();
    $errors = array();

    // Подключения к провайдерам живут в отдельной таблице и правятся через AJAX.
    foreach (array('embedding_endpoint', 'embedding_model') as $name) {
        $values[$name] = trim((string)($_POST[$name] ?? ''));
    }

    $scheme = parse_url($values['embedding_endpoint'], PHP_URL_SCHEME);
    if (!in_array($scheme, array('http', 'https'), true)) {
        $errors[] = Loc::getMessage('MIBAZAROW_BXADMINS_ERR_EMBEDDING_ENDPOINT');
    }

    foreach (array('log_retention_days', 'backup_keep', 'retention_days', 'worker_concurrency') as $name) {
        $raw = trim((string)($_POST[$name] ?? ''));
        if ($raw === '' || !ctype_digit($raw)) {
            $errors[] = Loc::getMessage('MIBAZAROW_BXADMINS_ERR_' . strtoupper($name));
        } else {
            $values[$name] = (string)(int)$raw;
        }
    }

    // Политика SQL-дампов: irreversible (по умолчанию), always, off.
    $dumpPolicy = strtolower(trim((string)($_POST['sql_dump_policy'] ?? 'irreversible')));
    if (!in_array($dumpPolicy, array('irreversible', 'always', 'off'), true)) {
        $errors[] = Loc::getMessage('MIBAZAROW_BXADMINS_ERR_SQL_DUMP_POLICY');
    } else {
        $values['sql_dump_policy'] = $dumpPolicy;
    }

    // S3: пустые значения допустимы — это значит «хранилище не настроено».
    foreach (array('backup_s3_endpoint', 'backup_s3_bucket', 'backup_s3_key', 'backup_s3_region') as $name) {
        $values[$name] = trim((string)($_POST[$name] ?? ''));
    }

    // Секрет в форме не показываем: пустое поле означает «оставить прежний».
    $values['backup_s3_secret'] = trim((string)($_POST['backup_s3_secret'] ?? ''));
    if ($values['backup_s3_secret'] === '') {
        $values['backup_s3_secret'] = Config::get('backup_s3_secret');
    }

    $submittedDomains = isset($_POST['allowed_domains']) && is_array($_POST['allowed_domains'])
        ? $_POST['allowed_domains']
        : array();
    $domainValues = array();
    foreach (array_keys(Config::DOMAINS) as $domainKey) {
        $domainValues[$domainKey] = isset($submittedDomains[$domainKey])
            && (string)$submittedDomains[$domainKey] === 'Y';
    }

    if ($errors !== array()) {
        foreach ($errors as $error) {
            $messages[] = array('MESSAGE' => $error, 'TYPE' => 'ERROR');
        }
    } else {
        Config::setMany($values);
        Config::setAllowedDomains($domainValues);
        $messages[] = array('MESSAGE' => Loc::getMessage('MIBAZAROW_BXADMINS_SAVED'), 'TYPE' => 'OK');
    }
}

$APPLICATION->SetTitle(Loc::getMessage('MIBAZAROW_BXADMINS_SETTINGS_TITLE'));

// AddHeadScript пропускает путь через Asset и теряет ?v= — подключаем строкой.
$APPLICATION->AddHeadString('<link rel="stylesheet" href="' . Assets::url('admin.css') . '">', true);
$APPLICATION->AddHeadString('<script src="' . Assets::url('settings.js') . '"></script>', true);

$aTabs = array(
    array('DIV' => 'tab_llm', 'TAB' => Loc::getMessage('MIBAZAROW_BXADMINS_TAB_LLM'), 'TITLE' => Loc::getMessage('MIBAZAROW_BXADMINS_SEC_LLM')),
    array('DIV' => 'tab_embedding', 'TAB' => Loc::getMessage('MIBAZAROW_BXADMINS_TAB_EMBEDDING'), 'TITLE' => Loc::getMessage('MIBAZAROW_BXADMINS_SEC_EMBEDDING')),
    array('DIV' => 'tab_mcp', 'TAB' => Loc::getMessage('MIBAZAROW_BXADMINS_TAB_MCP'), 'TITLE' => Loc::getMessage('MIBAZAROW_BXADMINS_SEC_MCP')),
    array('DIV' => 'tab_skills', 'TAB' => Loc::getMessage('MIBAZAROW_BXADMINS_TAB_SKILLS'), 'TITLE' => Loc::getMessage('MIBAZAROW_BXADMINS_SEC_SKILLS')),
    array('DIV' => 'tab_access', 'TAB' => Loc::getMessage('MIBAZAROW_BXADMINS_TAB_ACCESS'), 'TITLE' => Loc::getMessage('MIBAZAROW_BXADMINS_SEC_DOMAINS')),
    array('DIV' => 'tab_service', 'TAB' => Loc::getMessage('MIBAZAROW_BXADMINS_TAB_SERVICE'), 'TITLE' => Loc::getMessage('MIBAZAROW_BXADMINS_SEC_SERVICE')),
);

$tabControl = new CAdminTabControl('bxadminsSettings', $aTabs, true, true);

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';

foreach ($messages as $message) {
    CAdminMessage::ShowMessage($message);
}

$llmConfigured = Config::isLlmConfigured();
$allowedDomains = Config::allowedDomains();
?>
<div class="bxadmins-summary">
    <?php echo Loc::getMessage('MIBAZAROW_BXADMINS_SUMMARY_VERSION'); ?>:
    <strong><?php echo htmlspecialchars(ModuleManager::getVersion(ADMIN_MODULE_NAME), ENT_QUOTES, 'UTF-8'); ?></strong>
    &middot;
    <?php echo Loc::getMessage('MIBAZAROW_BXADMINS_SUMMARY_LLM'); ?>:
    <?php if ($llmConfigured): ?>
        <strong><?php echo htmlspecialchars(Config::llmModel(), ENT_QUOTES, 'UTF-8'); ?></strong>
        (<?php echo htmlspecialchars(Config::llmEndpoint(), ENT_QUOTES, 'UTF-8'); ?>)
    <?php else: ?>
        <strong><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_SUMMARY_NOT_CONFIGURED'); ?></strong>
    <?php endif; ?>
    &middot;
    <?php echo Loc::getMessage('MIBAZAROW_BXADMINS_SUMMARY_EMBEDDING'); ?>:
    <strong><?php echo htmlspecialchars(Config::embeddingModel(), ENT_QUOTES, 'UTF-8'); ?></strong>
</div>
<form method="post" action="" name="bxadmins_settings_form">
    <?php echo bitrix_sessid_post(); ?>
<?php
$tabControl->Begin();
$tabControl->BeginNextTab();
$profiles = LlmProfileService::list();
$ajaxUrl = '/local/modules/mibazarow.bxadmins/admin/ajax.php';
$sessid = bitrix_sessid();
?>
<tr>
    <td class="adm-detail-content-cell" colspan="2">
        <div class="bxadmins-hints">
            <div class="bxadmins-hints-title"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_LLM_HINTS_TITLE'); ?></div>
            <div class="bxadmins-hint-text"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_LLM_HINT_ANY'); ?></div>
            <div class="bxadmins-note"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_LLM_HINT_TOOLS'); ?></div>
        </div>
    </td>
</tr>
<tr>
    <td class="adm-detail-content-cell" colspan="2">
        <div class="bxadmins-profiles"
             data-ajax="<?php echo htmlspecialchars($ajaxUrl, ENT_QUOTES, 'UTF-8'); ?>"
             data-sessid="<?php echo htmlspecialchars($sessid, ENT_QUOTES, 'UTF-8'); ?>"
             data-confirm="<?php echo htmlspecialchars(Loc::getMessage('MIBAZAROW_BXADMINS_PROFILE_DELETE_CONFIRM'), ENT_QUOTES, 'UTF-8'); ?>">
            <div class="bxadmins-profiles-title"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_PROFILES_TITLE'); ?></div>
            <div class="bxadmins-note"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_PROFILES_NOTE'); ?></div>

            <?php if ($profiles === array()): ?>
                <div class="bxadmins-empty-inline"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_PROFILES_EMPTY'); ?></div>
            <?php else: ?>
                <table class="bxadmins-profiles-table">
                    <thead>
                    <tr>
                        <th><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_PROFILE_ACTIVE'); ?></th>
                        <th><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_PROFILE_NAME'); ?></th>
                        <th><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_PROFILE_ENDPOINT'); ?></th>
                        <th><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_PROFILE_MODEL'); ?></th>
                        <th>&nbsp;</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($profiles as $profile): ?>
                        <tr class="bxadmins-profile-row<?php echo $profile['active'] ? ' bxadmins-profile-active' : ''; ?>">
                            <td>
                                <input type="radio" name="bxadmins_active_profile" value="<?php echo $profile['id']; ?>"
                                       class="bxadmins-profile-activate"
                                       <?php echo $profile['active'] ? 'checked' : ''; ?>>
                            </td>
                            <td><?php echo htmlspecialchars($profile['name'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td class="bxadmins-profile-endpoint"><?php echo htmlspecialchars($profile['endpoint'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars($profile['model'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td class="bxadmins-profile-actions">
                                <button type="button" class="adm-btn bxadmins-profile-edit"
                                        data-id="<?php echo $profile['id']; ?>"
                                        data-name="<?php echo htmlspecialchars($profile['name'], ENT_QUOTES, 'UTF-8'); ?>"
                                        data-endpoint="<?php echo htmlspecialchars($profile['endpoint'], ENT_QUOTES, 'UTF-8'); ?>"
                                        data-model="<?php echo htmlspecialchars($profile['model'], ENT_QUOTES, 'UTF-8'); ?>"
                                        data-reasoning="<?php echo htmlspecialchars($profile['reasoning'], ENT_QUOTES, 'UTF-8'); ?>"
                                        data-context="<?php echo (int)$profile['context_window']; ?>">
                                    <?php echo Loc::getMessage('MIBAZAROW_BXADMINS_PROFILE_EDIT'); ?>
                                </button>
                                <button type="button" class="adm-btn bxadmins-profile-delete"
                                        data-id="<?php echo $profile['id']; ?>">
                                    <?php echo Loc::getMessage('MIBAZAROW_BXADMINS_PROFILE_DELETE'); ?>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </td>
</tr>
<tr>
    <td class="adm-detail-content-cell" colspan="2">
        <div class="bxadmins-profile-form" id="bxadmins-profile-form">
            <div class="bxadmins-profiles-title" id="bxadmins-profile-form-title"
                 data-add-label="<?php echo htmlspecialchars(Loc::getMessage('MIBAZAROW_BXADMINS_PROFILE_ADD'), ENT_QUOTES, 'UTF-8'); ?>"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_PROFILE_ADD'); ?></div>
            <input type="hidden" id="profile_id" value="0">

            <table class="bxadmins-profile-fields">
                <tr>
                    <td class="adm-detail-content-cell-l"><label for="profile_name"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_PROFILE_NAME'); ?>:</label></td>
                    <td class="adm-detail-content-cell-r">
                        <input type="text" id="profile_name" class="adm-input bxadmins-input-wide"
                               placeholder="<?php echo htmlspecialchars(Loc::getMessage('MIBAZAROW_BXADMINS_PROFILE_NAME_PLACEHOLDER'), ENT_QUOTES, 'UTF-8'); ?>">
                    </td>
                </tr>
                <tr>
                    <td class="adm-detail-content-cell-l"><label for="llm_endpoint"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_F_LLM_ENDPOINT'); ?>:</label></td>
                    <td class="adm-detail-content-cell-r">
                        <input type="text" id="llm_endpoint" class="adm-input bxadmins-input-wide"
                               placeholder="<?php echo htmlspecialchars(Loc::getMessage('MIBAZAROW_BXADMINS_LLM_ENDPOINT_PLACEHOLDER'), ENT_QUOTES, 'UTF-8'); ?>">
                    </td>
                </tr>
                <tr>
                    <td class="adm-detail-content-cell-l"><label for="llm_api_key"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_F_LLM_API_KEY'); ?>:</label></td>
                    <td class="adm-detail-content-cell-r">
                        <input type="password" id="llm_api_key" class="adm-input bxadmins-input-wide" value="" autocomplete="new-password">
                        <div class="bxadmins-note"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_API_KEY_SAVED_HINT'); ?></div>
                    </td>
                </tr>
                <tr>
                    <td class="adm-detail-content-cell-l"><label for="llm_model"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_F_LLM_MODEL'); ?>:</label></td>
                    <td class="adm-detail-content-cell-r">
                        <div class="bxadmins-model-row">
                            <span id="bxadmins-model-field">
                                <input type="text" id="llm_model" class="adm-input bxadmins-input-wide"
                                       placeholder="<?php echo htmlspecialchars(Loc::getMessage('MIBAZAROW_BXADMINS_LLM_MODEL_PLACEHOLDER'), ENT_QUOTES, 'UTF-8'); ?>">
                            </span>
                            <button type="button" class="adm-btn" id="bxadmins-load-models"
                                    data-ajax="<?php echo htmlspecialchars($ajaxUrl, ENT_QUOTES, 'UTF-8'); ?>"
                                    data-sessid="<?php echo htmlspecialchars($sessid, ENT_QUOTES, 'UTF-8'); ?>"
                                    data-label-loading="<?php echo htmlspecialchars(Loc::getMessage('MIBAZAROW_BXADMINS_MODELS_LOADING'), ENT_QUOTES, 'UTF-8'); ?>"
                                    data-label-idle="<?php echo htmlspecialchars(Loc::getMessage('MIBAZAROW_BXADMINS_MODELS_LOAD'), ENT_QUOTES, 'UTF-8'); ?>"
                                    data-empty-label="<?php echo htmlspecialchars(Loc::getMessage('MIBAZAROW_BXADMINS_MODELS_EMPTY'), ENT_QUOTES, 'UTF-8'); ?>">
                                <?php echo Loc::getMessage('MIBAZAROW_BXADMINS_MODELS_LOAD'); ?>
                            </button>
                        </div>
                        <div class="bxadmins-note" id="bxadmins-models-note"></div>
                    </td>
                </tr>
                <tr>
                    <td class="adm-detail-content-cell-l"><label for="context_window"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_F_CONTEXT_WINDOW'); ?>:</label></td>
                    <td class="adm-detail-content-cell-r">
                        <input type="number" id="context_window" class="adm-input" min="1000" step="1000" value="240000">
                        <span class="bxadmins-note-inline"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_CONTEXT_WINDOW_UNIT'); ?></span>
                        <div class="bxadmins-note"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_CONTEXT_WINDOW_NOTE'); ?></div>
                    </td>
                </tr>
                <tr>
                    <td class="adm-detail-content-cell-l"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_F_LLM_REASONING'); ?>:</td>
                    <td class="adm-detail-content-cell-r">
                        <div class="bxadmins-reasoning-switch" id="llm_reasoning_group">
                            <button type="button" class="adm-btn" data-reasoning="none"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_REASONING_NONE'); ?></button>
                            <button type="button" class="adm-btn" data-reasoning="low"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_REASONING_LOW'); ?></button>
                            <button type="button" class="adm-btn" data-reasoning="high"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_REASONING_HIGH'); ?></button>
                        </div>
                        <input type="hidden" id="llm_reasoning" value="low">
                        <div class="bxadmins-note"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_REASONING_NOTE'); ?></div>
                    </td>
                </tr>
                <tr>
                    <td class="adm-detail-content-cell-l">&nbsp;</td>
                    <td class="adm-detail-content-cell-r">
                        <button type="button" class="adm-btn adm-btn-save" id="bxadmins-profile-save"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_PROFILE_SAVE'); ?></button>
                        <button type="button" class="adm-btn" id="bxadmins-profile-reset" hidden><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_PROFILE_RESET'); ?></button>
                        <span class="bxadmins-profile-status" id="bxadmins-profile-status"></span>
                    </td>
                </tr>
            </table>
        </div>
    </td>
</tr>
<tr>
    <td class="adm-detail-content-cell-l">&nbsp;</td>
    <td class="adm-detail-content-cell-r">
        <button type="button" class="adm-btn" id="bxadmins-test-llm"
                data-ajax="<?php echo htmlspecialchars($ajaxUrl, ENT_QUOTES, 'UTF-8'); ?>"
                data-sessid="<?php echo htmlspecialchars($sessid, ENT_QUOTES, 'UTF-8'); ?>"
                data-label-running="<?php echo htmlspecialchars(Loc::getMessage('MIBAZAROW_BXADMINS_TEST_LLM_RUNNING'), ENT_QUOTES, 'UTF-8'); ?>"
                data-ok-template="<?php echo htmlspecialchars(Loc::getMessage('MIBAZAROW_BXADMINS_TEST_LLM_OK'), ENT_QUOTES, 'UTF-8'); ?>"
                data-fail-template="<?php echo htmlspecialchars(Loc::getMessage('MIBAZAROW_BXADMINS_TEST_LLM_FAIL'), ENT_QUOTES, 'UTF-8'); ?>">
            <?php echo Loc::getMessage('MIBAZAROW_BXADMINS_TEST_LLM'); ?>
        </button>
        <div class="adm-info-message" id="bxadmins-test-llm-result" hidden></div>
        <div class="bxadmins-note"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_TEST_LLM_NOTE'); ?></div>
    </td>
</tr>
<?php
$tabControl->BeginNextTab();
?>
<tr>
    <td class="adm-detail-content-cell-l"><label for="embedding_endpoint"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_F_EMBEDDING_ENDPOINT'); ?>:</label></td>
    <td class="adm-detail-content-cell-r"><input type="text" id="embedding_endpoint" name="embedding_endpoint" class="adm-input bxadmins-input-wide" value="<?php echo htmlspecialchars(Config::embeddingEndpoint(), ENT_QUOTES, 'UTF-8'); ?>"></td>
</tr>
<tr>
    <td class="adm-detail-content-cell-l"><label for="embedding_model"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_F_EMBEDDING_MODEL'); ?>:</label></td>
    <td class="adm-detail-content-cell-r">
        <input type="text" id="embedding_model" name="embedding_model" class="adm-input bxadmins-input-wide" value="<?php echo htmlspecialchars(Config::embeddingModel(), ENT_QUOTES, 'UTF-8'); ?>">
        <div class="bxadmins-note"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_EMBEDDING_MODEL_NOTE'); ?></div>
    </td>
</tr>
<tr>
    <td class="adm-detail-content-cell-l">&nbsp;</td>
    <td class="adm-detail-content-cell-r">
        <button type="button" class="adm-btn" id="bxadmins-test-embedding"
                data-ajax="/local/modules/mibazarow.bxadmins/admin/ajax.php"
                data-sessid="<?php echo bitrix_sessid(); ?>"
                data-label-running="<?php echo htmlspecialchars(Loc::getMessage('MIBAZAROW_BXADMINS_TEST_EMBED_RUNNING'), ENT_QUOTES, 'UTF-8'); ?>"
                data-ok-template="<?php echo htmlspecialchars(Loc::getMessage('MIBAZAROW_BXADMINS_TEST_EMBED_OK'), ENT_QUOTES, 'UTF-8'); ?>"
                data-fail-template="<?php echo htmlspecialchars(Loc::getMessage('MIBAZAROW_BXADMINS_TEST_EMBED_FAIL'), ENT_QUOTES, 'UTF-8'); ?>">
            <?php echo Loc::getMessage('MIBAZAROW_BXADMINS_TEST_EMBED'); ?>
        </button>
        <div class="adm-info-message" id="bxadmins-test-embedding-result" hidden></div>
    </td>
</tr>
<tr>
    <td class="adm-detail-content-cell-l">&nbsp;</td>
    <td class="adm-detail-content-cell-r"><div class="bxadmins-note"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_TEST_LLM_NOTE'); ?></div></td>
</tr>
<?php
$tabControl->BeginNextTab();
$mcpServers = McpService::list();
$mcpCatalog = McpService::catalog(true);
$mcpToolsById = array();
foreach ($mcpCatalog as $mcpRow) {
    $mcpToolsById[$mcpRow['id']] = $mcpRow;
}
?>
<tr>
    <td class="adm-detail-content-cell" colspan="2">
        <div class="bxadmins-hints">
            <div class="bxadmins-hints-title"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_MCP_TITLE'); ?></div>
            <div><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_MCP_NOTE'); ?></div>
        </div>
    </td>
</tr>
<tr>
    <td class="adm-detail-content-cell" colspan="2">
        <div class="bxadmins-mcp"
             data-ajax="<?php echo htmlspecialchars($ajaxUrl, ENT_QUOTES, 'UTF-8'); ?>"
             data-sessid="<?php echo htmlspecialchars($sessid, ENT_QUOTES, 'UTF-8'); ?>"
             data-confirm="<?php echo htmlspecialchars(Loc::getMessage('MIBAZAROW_BXADMINS_MCP_DELETE_CONFIRM'), ENT_QUOTES, 'UTF-8'); ?>">

            <?php if ($mcpServers === array()): ?>
                <div class="bxadmins-empty-inline"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_MCP_EMPTY'); ?></div>
            <?php else: ?>
                <table class="bxadmins-profiles-table">
                    <thead>
                    <tr>
                        <th><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_MCP_ACTIVE'); ?></th>
                        <th><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_MCP_NAME'); ?></th>
                        <th><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_MCP_URL'); ?></th>
                        <th><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_MCP_TOOLS'); ?></th>
                        <th><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_MCP_STATUS'); ?></th>
                        <th>&nbsp;</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($mcpServers as $server): ?>
                        <tr class="bxadmins-mcp-row<?php echo $server['active'] ? ' bxadmins-profile-active' : ''; ?>">
                            <td>
                                <input type="checkbox" class="bxadmins-mcp-toggle" value="<?php echo $server['id']; ?>"
                                       <?php echo $server['active'] ? 'checked' : ''; ?>>
                            </td>
                            <td><?php echo htmlspecialchars($server['name'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td class="bxadmins-profile-endpoint"><?php echo htmlspecialchars($server['url'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td>
                                <?php if (!empty($mcpToolsById[$server['id']]['tools'])): ?>
                                    <details class="bxadmins-mcp-tools">
                                        <summary><?php echo (int)$server['enabled_tool_count']; ?> / <?php echo (int)$server['tool_count']; ?></summary>
                                        <?php foreach ($mcpToolsById[$server['id']]['tools'] as $mcpTool): ?>
                                            <div class="bxadmins-mcp-tool">
                                                <label class="bxadmins-checkbox">
                                                    <input type="checkbox" class="bxadmins-mcp-tool-toggle"
                                                           data-server="<?php echo $server['id']; ?>"
                                                           data-tool="<?php echo htmlspecialchars($mcpTool['name'], ENT_QUOTES, 'UTF-8'); ?>"
                                                           <?php echo empty($mcpTool['disabled']) ? 'checked' : ''; ?>>
                                                    <code><?php echo htmlspecialchars($mcpTool['name'], ENT_QUOTES, 'UTF-8'); ?></code>
                                                </label>
                                                <?php if (!empty($mcpTool['read_only'])): ?> · read-only<?php endif; ?>
                                                <?php if (!empty($mcpTool['destructive'])): ?> · destructive<?php endif; ?>
                                                <?php if ($mcpTool['description'] !== ''): ?>
                                                    <div><?php echo htmlspecialchars($mcpTool['description'], ENT_QUOTES, 'UTF-8'); ?></div>
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </details>
                                <?php else: ?>
                                    <?php echo (int)$server['tool_count']; ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($server['status'] === 'error'): ?>
                                    <span class="bxadmins-mcp-status-error" title="<?php echo htmlspecialchars($server['last_error'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <?php echo Loc::getMessage('MIBAZAROW_BXADMINS_MCP_STATUS_ERROR'); ?>
                                    </span>
                                <?php else: ?>
                                    <?php echo Loc::getMessage('MIBAZAROW_BXADMINS_MCP_STATUS_OK'); ?>
                                <?php endif; ?>
                                <?php if ($server['last_checked_at'] !== ''): ?>
                                    <div class="bxadmins-note"><?php echo htmlspecialchars($server['last_checked_at'], ENT_QUOTES, 'UTF-8'); ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="bxadmins-profile-actions">
                                <button type="button" class="adm-btn bxadmins-mcp-refresh" data-id="<?php echo $server['id']; ?>">
                                    <?php echo Loc::getMessage('MIBAZAROW_BXADMINS_MCP_REFRESH'); ?>
                                </button>
                                <button type="button" class="adm-btn bxadmins-mcp-delete" data-id="<?php echo $server['id']; ?>">
                                    <?php echo Loc::getMessage('MIBAZAROW_BXADMINS_MCP_DELETE'); ?>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <div class="bxadmins-profile-form">
                <div class="bxadmins-profiles-title"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_MCP_ADD'); ?></div>
                <table class="bxadmins-profile-fields">
                    <tr>
                        <td class="adm-detail-content-cell-l"><label for="mcp_name"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_MCP_NAME'); ?>:</label></td>
                        <td class="adm-detail-content-cell-r">
                            <input type="text" id="mcp_name" class="adm-input bxadmins-input-wide"
                                   placeholder="<?php echo htmlspecialchars(Loc::getMessage('MIBAZAROW_BXADMINS_MCP_NAME_PLACEHOLDER'), ENT_QUOTES, 'UTF-8'); ?>">
                        </td>
                    </tr>
                    <tr>
                        <td class="adm-detail-content-cell-l"><label for="mcp_url"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_MCP_URL'); ?>:</label></td>
                        <td class="adm-detail-content-cell-r">
                            <input type="text" id="mcp_url" class="adm-input bxadmins-input-wide"
                                   placeholder="https://example.com/mcp/">
                            <div class="bxadmins-note"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_MCP_URL_NOTE'); ?></div>
                        </td>
                    </tr>
                    <tr>
                        <td class="adm-detail-content-cell-l"><label for="mcp_token"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_MCP_TOKEN'); ?>:</label></td>
                        <td class="adm-detail-content-cell-r">
                            <input type="password" id="mcp_token" class="adm-input bxadmins-input-wide" autocomplete="new-password">
                            <div class="bxadmins-note"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_MCP_TOKEN_NOTE'); ?></div>
                        </td>
                    </tr>
                    <tr>
                        <td class="adm-detail-content-cell-l">&nbsp;</td>
                        <td class="adm-detail-content-cell-r">
                            <button type="button" class="adm-btn" id="bxadmins-mcp-test"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_MCP_TEST'); ?></button>
                            <button type="button" class="adm-btn adm-btn-save" id="bxadmins-mcp-save"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_MCP_SAVE'); ?></button>
                            <span class="bxadmins-profile-status" id="bxadmins-mcp-status"></span>
                        </td>
                    </tr>
                </table>
            </div>
        </div>
    </td>
</tr>
<?php
$tabControl->BeginNextTab();
$skills = SkillService::all(false, 200, true);
?>
<tr>
    <td class="adm-detail-content-cell" colspan="2">
        <div class="bxadmins-hints">
            <div class="bxadmins-hints-title"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_SKILLS_TITLE'); ?></div>
            <div class="bxadmins-note"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_SKILLS_NOTE'); ?></div>
        </div>
    </td>
</tr>
<tr>
    <td class="adm-detail-content-cell" colspan="2">
        <div class="bxadmins-skills"
             data-ajax="<?php echo htmlspecialchars($ajaxUrl, ENT_QUOTES, 'UTF-8'); ?>"
             data-sessid="<?php echo htmlspecialchars($sessid, ENT_QUOTES, 'UTF-8'); ?>"
             data-confirm="<?php echo htmlspecialchars(Loc::getMessage('MIBAZAROW_BXADMINS_SKILLS_DELETE_CONFIRM'), ENT_QUOTES, 'UTF-8'); ?>"
             data-purge-confirm="<?php echo htmlspecialchars(Loc::getMessage('MIBAZAROW_BXADMINS_SKILLS_PURGE_CONFIRM'), ENT_QUOTES, 'UTF-8'); ?>">

            <div class="bxadmins-skill-toolbar">
                <input type="text" class="adm-input bxadmins-skill-filter" id="bxadmins-skill-filter"
                       placeholder="<?php echo htmlspecialchars(Loc::getMessage('MIBAZAROW_BXADMINS_SKILLS_SEARCH'), ENT_QUOTES, 'UTF-8'); ?>">
                <label class="bxadmins-checkbox">
                    <input type="checkbox" id="bxadmins-skill-show-deleted">
                    <?php echo Loc::getMessage('MIBAZAROW_BXADMINS_SKILLS_SHOW_DELETED'); ?>
                </label>
                <label class="adm-btn bxadmins-skill-import-label">
                    <?php echo Loc::getMessage('MIBAZAROW_BXADMINS_SKILLS_IMPORT'); ?>
                    <input type="file" id="bxadmins-skill-import" accept=".md,.markdown,text/markdown,text/plain" hidden>
                </label>
            </div>

            <?php if ($skills === array()): ?>
                <div class="bxadmins-empty-inline"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_SKILLS_EMPTY'); ?></div>
            <?php else: ?>
                <table class="bxadmins-profiles-table bxadmins-skill-table">
                    <thead>
                    <tr>
                        <th><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_SKILLS_ACTIVE'); ?></th>
                        <th><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_SKILLS_NAME'); ?></th>
                        <th><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_SKILLS_DESCRIPTION'); ?></th>
                        <th><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_SKILLS_USAGE'); ?></th>
                        <th><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_SKILLS_UPDATED'); ?></th>
                        <th>&nbsp;</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($skills as $skill): ?>
                        <?php
                        $deleted = $skill['deleted_at'] !== '';
                        $search = mb_strtolower($skill['name'] . ' ' . $skill['description'] . ' '
                            . $skill['tags'] . ' ' . $skill['when_to_use']);
                        ?>
                        <tr class="bxadmins-skill-row<?php echo $skill['enabled'] ? '' : ' bxadmins-skill-disabled'; ?><?php echo $deleted ? ' bxadmins-skill-deleted' : ''; ?>"
                            data-deleted="<?php echo $deleted ? 'Y' : 'N'; ?>"
                            data-search="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>">
                            <td>
                                <input type="checkbox" class="bxadmins-skill-toggle" value="<?php echo $skill['id']; ?>"
                                       <?php echo $skill['enabled'] ? 'checked' : ''; ?>
                                       <?php echo $deleted ? 'disabled' : ''; ?>>
                            </td>
                            <td>
                                <strong><?php echo htmlspecialchars($skill['name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                <?php if ($deleted): ?>
                                    <span class="bxadmins-skill-badge"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_SKILLS_TRASH_BADGE'); ?></span>
                                <?php endif; ?>
                                <span class="bxadmins-skill-badge"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_SKILLS_PRIORITY'); ?> <?php echo (int)$skill['priority']; ?></span>
                                <?php if ($skill['tags'] !== ''): ?>
                                    <div class="bxadmins-skill-meta"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_SKILLS_TAGS'); ?>: <?php echo htmlspecialchars($skill['tags'], ENT_QUOTES, 'UTF-8'); ?></div>
                                <?php endif; ?>
                                <?php if ($skill['when_to_use'] !== ''): ?>
                                    <div class="bxadmins-skill-meta"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_SKILLS_WHEN'); ?>: <?php echo htmlspecialchars($skill['when_to_use'], ENT_QUOTES, 'UTF-8'); ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="bxadmins-skill-desc"><?php echo htmlspecialchars($skill['description'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td class="bxadmins-skill-stats">
                                <?php echo Loc::getMessage('MIBAZAROW_BXADMINS_SKILLS_SOURCE'); ?>: <?php echo htmlspecialchars($skill['source'], ENT_QUOTES, 'UTF-8'); ?><br>
                                <?php echo Loc::getMessage('MIBAZAROW_BXADMINS_SKILLS_USED'); ?>: <?php echo (int)$skill['use_count']; ?><br>
                                <?php echo Loc::getMessage('MIBAZAROW_BXADMINS_SKILLS_LAST_USED'); ?>: <?php echo htmlspecialchars($skill['last_used_at'] !== '' ? $skill['last_used_at'] : '—', ENT_QUOTES, 'UTF-8'); ?>
                            </td>
                            <td><?php echo htmlspecialchars($skill['updated_at'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td class="bxadmins-profile-actions">
                                <?php if ($deleted): ?>
                                    <button type="button" class="adm-btn bxadmins-skill-restore" data-id="<?php echo $skill['id']; ?>">
                                        <?php echo Loc::getMessage('MIBAZAROW_BXADMINS_SKILLS_RESTORE'); ?>
                                    </button>
                                    <button type="button" class="adm-btn bxadmins-skill-purge" data-id="<?php echo $skill['id']; ?>">
                                        <?php echo Loc::getMessage('MIBAZAROW_BXADMINS_SKILLS_PURGE'); ?>
                                    </button>
                                <?php else: ?>
                                    <button type="button" class="adm-btn bxadmins-skill-edit" data-id="<?php echo $skill['id']; ?>">
                                        <?php echo Loc::getMessage('MIBAZAROW_BXADMINS_SKILLS_EDIT'); ?>
                                    </button>
                                    <button type="button" class="adm-btn bxadmins-skill-export" data-id="<?php echo $skill['id']; ?>"
                                            data-name="<?php echo htmlspecialchars($skill['name'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <?php echo Loc::getMessage('MIBAZAROW_BXADMINS_SKILLS_EXPORT'); ?>
                                    </button>
                                    <button type="button" class="adm-btn bxadmins-skill-delete" data-id="<?php echo $skill['id']; ?>">
                                        <?php echo Loc::getMessage('MIBAZAROW_BXADMINS_SKILLS_DELETE'); ?>
                                    </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php if (!$deleted): ?>
                            <tr class="bxadmins-skill-editor" data-editor="<?php echo $skill['id']; ?>" hidden>
                                <td colspan="6">
                                    <div class="bxadmins-skill-form" data-id="<?php echo $skill['id']; ?>">
                                        <table class="bxadmins-skill-form-grid">
                                            <tr>
                                                <td><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_SKILLS_NAME'); ?></td>
                                                <td><input type="text" class="adm-input bxadmins-input-wide" name="name" maxlength="150" value="<?php echo htmlspecialchars($skill['name'], ENT_QUOTES, 'UTF-8'); ?>"></td>
                                                <td><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_SKILLS_PRIORITY'); ?></td>
                                                <td><input type="number" class="adm-input" name="priority" min="0" max="1000" value="<?php echo (int)$skill['priority']; ?>"></td>
                                            </tr>
                                            <tr>
                                                <td><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_SKILLS_DESCRIPTION'); ?></td>
                                                <td colspan="3"><input type="text" class="adm-input bxadmins-input-wide" name="description" maxlength="500" value="<?php echo htmlspecialchars($skill['description'], ENT_QUOTES, 'UTF-8'); ?>"></td>
                                            </tr>
                                            <tr>
                                                <td><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_SKILLS_TAGS'); ?></td>
                                                <td><input type="text" class="adm-input bxadmins-input-wide" name="tags" maxlength="500" value="<?php echo htmlspecialchars($skill['tags'], ENT_QUOTES, 'UTF-8'); ?>"></td>
                                                <td><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_SKILLS_WHEN'); ?></td>
                                                <td><input type="text" class="adm-input bxadmins-input-wide" name="when_to_use" maxlength="500" value="<?php echo htmlspecialchars($skill['when_to_use'], ENT_QUOTES, 'UTF-8'); ?>"></td>
                                            </tr>
                                            <tr>
                                                <td colspan="4">
                                                    <label class="bxadmins-checkbox">
                                                        <input type="checkbox" name="enabled" <?php echo $skill['enabled'] ? 'checked' : ''; ?>>
                                                        <?php echo Loc::getMessage('MIBAZAROW_BXADMINS_SKILLS_ACTIVE'); ?>
                                                    </label>
                                                </td>
                                            </tr>
                                            <tr>
                                                <td colspan="4">
                                                    <textarea class="adm-input bxadmins-skill-instructions-input" name="instructions" rows="10"><?php echo htmlspecialchars($skill['instructions'], ENT_QUOTES, 'UTF-8'); ?></textarea>
                                                </td>
                                            </tr>
                                            <tr>
                                                <td colspan="4">
                                                    <button type="button" class="adm-btn adm-btn-save bxadmins-skill-save"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_SKILLS_SAVE'); ?></button>
                                                    <button type="button" class="adm-btn bxadmins-skill-cancel"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_SKILLS_CANCEL'); ?></button>
                                                    <button type="button" class="adm-btn bxadmins-skill-history" data-id="<?php echo $skill['id']; ?>"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_SKILLS_HISTORY'); ?></button>
                                                </td>
                                            </tr>
                                        </table>
                                        <div class="bxadmins-skill-history-list" data-history="<?php echo $skill['id']; ?>"></div>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <div class="bxadmins-profile-status" id="bxadmins-skills-status"></div>
        </div>
    </td>
</tr>
<?php
$tabControl->BeginNextTab();
?>
<tr>
    <td class="adm-detail-content-cell" colspan="2">
        <div class="bxadmins-note bxadmins-note-block"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_DOMAINS_NOTE'); ?></div>
        <div class="bxadmins-domains-grid">
            <?php foreach (Config::DOMAINS as $domainKey => $domainLabel): ?>
                <label class="bxadmins-checkbox">
                    <input type="checkbox"
                           name="allowed_domains[<?php echo htmlspecialchars($domainKey, ENT_QUOTES, 'UTF-8'); ?>]"
                           value="Y"<?php echo !empty($allowedDomains[$domainKey]) ? ' checked' : ''; ?>>
                    <?php echo htmlspecialchars($domainLabel, ENT_QUOTES, 'UTF-8'); ?>
                </label>
            <?php endforeach; ?>
        </div>
    </td>
</tr>
<?php
$tabControl->BeginNextTab();
?>
<tr>
    <td class="adm-detail-content-cell-l"><label for="log_retention_days"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_F_LOG_RETENTION'); ?>:</label></td>
    <td class="adm-detail-content-cell-r"><input type="text" id="log_retention_days" name="log_retention_days" class="adm-input bxadmins-input-short" value="<?php echo htmlspecialchars(Config::get('log_retention_days'), ENT_QUOTES, 'UTF-8'); ?>"></td>
</tr>
<tr>
    <td class="adm-detail-content-cell-l"><label for="retention_days"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_F_RETENTION'); ?>:</label></td>
    <td class="adm-detail-content-cell-r">
        <input type="text" id="retention_days" name="retention_days" class="adm-input bxadmins-input-short" value="<?php echo htmlspecialchars(Config::get('retention_days'), ENT_QUOTES, 'UTF-8'); ?>">
        <div class="bxadmins-note"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_RETENTION_NOTE'); ?></div>
    </td>
</tr>
<tr>
    <td class="adm-detail-content-cell-l"><label for="worker_concurrency"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_F_CONCURRENCY'); ?>:</label></td>
    <td class="adm-detail-content-cell-r">
        <input type="text" id="worker_concurrency" name="worker_concurrency" class="adm-input bxadmins-input-short" value="<?php echo htmlspecialchars(Config::get('worker_concurrency'), ENT_QUOTES, 'UTF-8'); ?>">
        <div class="bxadmins-note"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_CONCURRENCY_NOTE'); ?></div>
    </td>
</tr>
<tr>
    <td class="adm-detail-content-cell-l"><label for="backup_keep"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_F_BACKUP_KEEP'); ?>:</label></td>
    <td class="adm-detail-content-cell-r"><input type="text" id="backup_keep" name="backup_keep" class="adm-input bxadmins-input-short" value="<?php echo htmlspecialchars(Config::get('backup_keep'), ENT_QUOTES, 'UTF-8'); ?>"></td>
</tr>
<tr>
    <td class="adm-detail-content-cell-l"><label for="sql_dump_policy"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_F_SQL_DUMP_POLICY'); ?>:</label></td>
    <td class="adm-detail-content-cell-r">
        <select id="sql_dump_policy" name="sql_dump_policy" class="adm-select">
            <?php foreach (array(
                'irreversible' => Loc::getMessage('MIBAZAROW_BXADMINS_SQL_DUMP_IRREVERSIBLE'),
                'always' => Loc::getMessage('MIBAZAROW_BXADMINS_SQL_DUMP_ALWAYS'),
                'off' => Loc::getMessage('MIBAZAROW_BXADMINS_SQL_DUMP_OFF'),
            ) as $policyValue => $policyLabel): ?>
                <option value="<?php echo htmlspecialchars($policyValue, ENT_QUOTES, 'UTF-8'); ?>"<?php echo Config::sqlDumpPolicy() === $policyValue ? ' selected' : ''; ?>><?php echo htmlspecialchars((string)$policyLabel, ENT_QUOTES, 'UTF-8'); ?></option>
            <?php endforeach; ?>
        </select>
        <div class="bxadmins-note"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_SQL_DUMP_NOTE'); ?></div>
    </td>
</tr>
<tr>
    <td class="adm-detail-content-cell" colspan="2">
        <div class="bxadmins-hints">
            <div class="bxadmins-hints-title"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_S3_TITLE'); ?></div>
            <div class="bxadmins-note"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_S3_NOTE'); ?></div>
        </div>
    </td>
</tr>
<?php foreach (array(
    'backup_s3_endpoint' => 'S3 endpoint',
    'backup_s3_bucket' => 'S3 bucket',
    'backup_s3_key' => 'S3 access key',
    'backup_s3_secret' => 'S3 secret key',
    'backup_s3_region' => 'S3 region',
) as $field => $label): ?>
<tr>
    <td class="adm-detail-content-cell-l"><label for="<?php echo $field; ?>"><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?>:</label></td>
    <td class="adm-detail-content-cell-r">
        <?php if ($field === 'backup_s3_secret'): ?>
            <input type="password" id="<?php echo $field; ?>" name="<?php echo $field; ?>" class="adm-input"
                   value="" autocomplete="new-password"
                   placeholder="<?php echo Config::get($field) !== '' ? 'сохранён — оставьте пустым' : ''; ?>">
            <div class="bxadmins-note">Оставьте пустым, чтобы не менять сохранённый ключ.</div>
        <?php else: ?>
            <input type="text" id="<?php echo $field; ?>" name="<?php echo $field; ?>" class="adm-input"
                   value="<?php echo htmlspecialchars(Config::get($field), ENT_QUOTES, 'UTF-8'); ?>"
                   autocomplete="off">
        <?php endif; ?>
    </td>
</tr>
<?php endforeach; ?>
<?php
$tabControl->Buttons(array('btnApply' => false, 'btnCancel' => false));
$tabControl->End();
?>
</form>
<?php
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
