<?php

const ADMIN_MODULE_NAME = 'mibazarow.bxadmins';

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Mibazarow\Bxadmins\Admin\Assets;
use Mibazarow\Bxadmins\Config;
use Mibazarow\Bxadmins\Service\IndexService;

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

$APPLICATION->AddHeadString('<link rel="stylesheet" href="' . Assets::url('admin.css') . '">', true);

if (!IndexService::isComplete()) {
    // Онбординг: пока полная индексация не завершена, харнес заблокирован (ТЗ, раздел 14).
    $APPLICATION->AddHeadString('<script src="' . Assets::url('onboarding.js') . '"></script>', true);
    $indexStatus = IndexService::status();
    $percent = $indexStatus['progress_percent'];

    $APPLICATION->SetTitle(Loc::getMessage('MIBAZAROW_BXADMINS_CHAT_LOCK_TITLE'));
    require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
    ?>
    <div class="bxadmins-lock"
         data-ajax="/local/modules/mibazarow.bxadmins/admin/ajax.php"
         data-sessid="<?php echo bitrix_sessid(); ?>"
         data-percent="<?php echo $percent === null ? '' : (int)$percent; ?>"
         data-total="<?php echo (int)$indexStatus['total']; ?>"
         data-offset="<?php echo (int)$indexStatus['offset']; ?>">
        <div class="bxadmins-lock-card">
            <div class="bxadmins-spinner" aria-hidden="true"></div>
            <h1 class="bxadmins-lock-title"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_CHAT_LOCK_TITLE'); ?></h1>
            <p class="bxadmins-lock-text"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_CHAT_LOCK_WHY'); ?></p>
            <div class="bxadmins-progress">
                <div class="bxadmins-progress-bar">
                    <div class="bxadmins-progress-fill<?php echo $percent === null ? ' bxadmins-progress-indeterminate' : ''; ?>"
                         id="bxadmins-lock-fill"
                         style="width: <?php echo $percent === null ? 100 : $percent; ?>%"></div>
                </div>
                <div class="bxadmins-progress-label" id="bxadmins-lock-label"><?php
                    echo $percent === null
                        ? Loc::getMessage('MIBAZAROW_BXADMINS_CHAT_LOCK_PROGRESS_STARTING')
                        : (int)$indexStatus['offset'] . ' / ' . (int)$indexStatus['total'] . ' (' . $percent . '%)';
                ?></div>
            </div>
            <p class="bxadmins-lock-note"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_CHAT_LOCK_TIME'); ?></p>
            <p class="bxadmins-lock-note"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_CHAT_LOCK_BG'); ?></p>
        </div>
    </div>
    <?php
    require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
    return;
}

$settingsUrl = '/bitrix/admin/mibazarow_bxadmins_settings.php?lang=' . LANGUAGE_ID;

if (!Config::isLlmConfigured()) {
    // Индексация уже позади, но модель не подключена: без неё харнес не работает.
    $APPLICATION->SetTitle(Loc::getMessage('MIBAZAROW_BXADMINS_CHAT_SETUP_TITLE'));
    require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
    ?>
    <div class="bxadmins-lock bxadmins-setup">
        <div class="bxadmins-lock-card bxadmins-setup-card">
            <div class="bxadmins-lock-eyebrow"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_CHAT_SETUP_STEP'); ?></div>
            <h1 class="bxadmins-lock-title"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_CHAT_SETUP_TITLE'); ?></h1>
            <p class="bxadmins-lock-text"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_CHAT_SETUP_WHY'); ?></p>

            <div class="bxadmins-setup-options">
                <div class="bxadmins-setup-option">
                    <div class="bxadmins-setup-option-title"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_CHAT_SETUP_OPT_LOCAL'); ?></div>
                    <div class="bxadmins-setup-option-text"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_CHAT_SETUP_OPT_LOCAL_TEXT'); ?></div>
                </div>
                <div class="bxadmins-setup-option">
                    <div class="bxadmins-setup-option-title"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_CHAT_SETUP_OPT_OWN'); ?></div>
                    <div class="bxadmins-setup-option-text"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_CHAT_SETUP_OPT_OWN_TEXT'); ?></div>
                </div>
                <div class="bxadmins-setup-option">
                    <div class="bxadmins-setup-option-title"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_CHAT_SETUP_OPT_CLOUD'); ?></div>
                    <div class="bxadmins-setup-option-text"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_CHAT_SETUP_OPT_CLOUD_TEXT'); ?></div>
                </div>
            </div>

            <p class="bxadmins-lock-note"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_CHAT_SETUP_TOOLS'); ?></p>

            <div class="bxadmins-setup-actions">
                <a class="adm-btn adm-btn-save" href="<?php echo htmlspecialchars($settingsUrl, ENT_QUOTES, 'UTF-8'); ?>">
                    <?php echo Loc::getMessage('MIBAZAROW_BXADMINS_CHAT_SETUP_BUTTON'); ?>
                </a>
            </div>

            <p class="bxadmins-lock-note"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_CHAT_SETUP_AFTER'); ?></p>
        </div>
    </div>
    <?php
    require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
    return;
}

// Прогресс и ответы приходят через SSE-поток admin/stream.php.
// Рендер Markdown подключается до чата: chat.js использует BXAdminMarkdown.
$APPLICATION->AddHeadString('<script src="' . Assets::url('markdown.js') . '"></script>', true);
$APPLICATION->AddHeadString('<script src="' . Assets::url('chat.js') . '"></script>', true);

$suggestions = array(
    Loc::getMessage('MIBAZAROW_BXADMINS_CHAT_SUGGEST_1'),
    Loc::getMessage('MIBAZAROW_BXADMINS_CHAT_SUGGEST_2'),
    Loc::getMessage('MIBAZAROW_BXADMINS_CHAT_SUGGEST_3'),
);

$APPLICATION->SetTitle(Loc::getMessage('MIBAZAROW_BXADMINS_CHAT_TITLE'));

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
?>
<div class="adm-detail-content-wrap bxadmins-chat"
     data-ajax="/local/modules/mibazarow.bxadmins/admin/ajax.php"
     data-stream-url="/local/modules/mibazarow.bxadmins/admin/stream.php"
     data-sessid="<?php echo bitrix_sessid(); ?>"
     data-suggestions="<?php echo htmlspecialchars(implode('|', $suggestions), ENT_QUOTES, 'UTF-8'); ?>"
     data-role-agent="<?php echo htmlspecialchars(Loc::getMessage('MIBAZAROW_BXADMINS_CHAT_ROLE_AGENT'), ENT_QUOTES, 'UTF-8'); ?>"
     data-role-you="<?php echo htmlspecialchars(Loc::getMessage('MIBAZAROW_BXADMINS_CHAT_ROLE_YOU'), ENT_QUOTES, 'UTF-8'); ?>"
     data-empty-title="<?php echo htmlspecialchars(Loc::getMessage('MIBAZAROW_BXADMINS_CHAT_EMPTY_TITLE'), ENT_QUOTES, 'UTF-8'); ?>"
     data-empty-chat="<?php echo htmlspecialchars(Loc::getMessage('MIBAZAROW_BXADMINS_CHAT_EMPTY_CHAT'), ENT_QUOTES, 'UTF-8'); ?>"
     data-empty-new="<?php echo htmlspecialchars(Loc::getMessage('MIBAZAROW_BXADMINS_CHAT_EMPTY_NEW'), ENT_QUOTES, 'UTF-8'); ?>"
     data-rename-prompt="<?php echo htmlspecialchars(Loc::getMessage('MIBAZAROW_BXADMINS_CHAT_RENAME_PROMPT'), ENT_QUOTES, 'UTF-8'); ?>"
     data-delete-confirm="<?php echo htmlspecialchars(Loc::getMessage('MIBAZAROW_BXADMINS_CHAT_DELETE_CONFIRM'), ENT_QUOTES, 'UTF-8'); ?>"
     data-delete-schedule-confirm="<?php echo htmlspecialchars(Loc::getMessage('MIBAZAROW_BXADMINS_CHAT_DELETE_SCHEDULE_CONFIRM'), ENT_QUOTES, 'UTF-8'); ?>"
     data-label-rename="<?php echo htmlspecialchars(Loc::getMessage('MIBAZAROW_BXADMINS_CHAT_ACTION_RENAME'), ENT_QUOTES, 'UTF-8'); ?>"
     data-label-delete="<?php echo htmlspecialchars(Loc::getMessage('MIBAZAROW_BXADMINS_CHAT_ACTION_DELETE'), ENT_QUOTES, 'UTF-8'); ?>"
     data-label-pin="<?php echo htmlspecialchars(Loc::getMessage('MIBAZAROW_BXADMINS_CHAT_ACTION_PIN'), ENT_QUOTES, 'UTF-8'); ?>"
     data-label-unpin="<?php echo htmlspecialchars(Loc::getMessage('MIBAZAROW_BXADMINS_CHAT_ACTION_UNPIN'), ENT_QUOTES, 'UTF-8'); ?>"
     data-provider-down="<?php echo htmlspecialchars(Loc::getMessage('MIBAZAROW_BXADMINS_CHAT_PROVIDER_DOWN'), ENT_QUOTES, 'UTF-8'); ?>"
     data-provider-none="<?php echo htmlspecialchars(Loc::getMessage('MIBAZAROW_BXADMINS_CHAT_PROVIDER_NONE'), ENT_QUOTES, 'UTF-8'); ?>"
     data-provider-switch="<?php echo htmlspecialchars(Loc::getMessage('MIBAZAROW_BXADMINS_CHAT_PROVIDER_SWITCH'), ENT_QUOTES, 'UTF-8'); ?>"
     data-provider-switched="<?php echo htmlspecialchars(Loc::getMessage('MIBAZAROW_BXADMINS_CHAT_PROVIDER_SWITCHED'), ENT_QUOTES, 'UTF-8'); ?>"
     data-label-thinking="<?php echo htmlspecialchars(Loc::getMessage('MIBAZAROW_BXADMINS_CHAT_THINKING'), ENT_QUOTES, 'UTF-8'); ?>"
     data-label-request="<?php echo htmlspecialchars(Loc::getMessage('MIBAZAROW_BXADMINS_CHAT_STEPS_REQUEST'), ENT_QUOTES, 'UTF-8'); ?>"
     data-label-answer="<?php echo htmlspecialchars(Loc::getMessage('MIBAZAROW_BXADMINS_CHAT_STEPS_ANSWER'), ENT_QUOTES, 'UTF-8'); ?>"
     data-label-queued="<?php echo htmlspecialchars(Loc::getMessage('MIBAZAROW_BXADMINS_CHAT_QUEUED'), ENT_QUOTES, 'UTF-8'); ?>"
     data-label-queue-remove="<?php echo htmlspecialchars(Loc::getMessage('MIBAZAROW_BXADMINS_CHAT_QUEUE_REMOVE'), ENT_QUOTES, 'UTF-8'); ?>"
     data-upload-url="/local/modules/mibazarow.bxadmins/admin/upload.php"
     data-file-url="/local/modules/mibazarow.bxadmins/admin/attachment.php"
     data-label-uploading="<?php echo htmlspecialchars(Loc::getMessage('MIBAZAROW_BXADMINS_CHAT_ATTACH_UPLOADING'), ENT_QUOTES, 'UTF-8'); ?>"
     data-label-remove-file="<?php echo htmlspecialchars(Loc::getMessage('MIBAZAROW_BXADMINS_CHAT_ATTACH_REMOVE'), ENT_QUOTES, 'UTF-8'); ?>">
    <div class="bxadmins-chat-panel">
        <aside class="bxadmins-chat-sidebar">
            <button type="button" class="adm-btn adm-btn-save bxadmins-new-chat"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_CHAT_NEW'); ?></button>
            <div class="bxadmins-empty bxadmins-chat-list-empty"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_CHAT_EMPTY_LIST'); ?></div>
            <ul class="bxadmins-chat-list"></ul>
        </aside>

        <section class="bxadmins-chat-main">
            <div class="bxadmins-chat-messages">
                <div class="bxadmins-empty bxadmins-chat-empty">
                    <div class="bxadmins-empty-mark" aria-hidden="true">BX</div>
                    <div class="bxadmins-empty-title"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_CHAT_EMPTY_TITLE'); ?></div>
                    <div class="bxadmins-empty-text"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_CHAT_EMPTY_TEXT'); ?></div>
                    <div class="bxadmins-suggestions">
                        <?php foreach ($suggestions as $suggestion): ?>
                            <button type="button" class="bxadmins-suggestion"
                                    data-text="<?php echo htmlspecialchars($suggestion, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($suggestion, ENT_QUOTES, 'UTF-8'); ?></button>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <div class="bxadmins-chat-progress" hidden>
                <details class="bxadmins-steps">
                    <summary class="bxadmins-steps-summary">
                        <span class="bxadmins-spinner bxadmins-spinner-sm" aria-hidden="true"></span>
                        <span class="bxadmins-steps-current"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_CHAT_THINKING'); ?></span>
                        <span class="bxadmins-steps-more"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_CHAT_STEPS_MORE'); ?></span>
                    </summary>
                    <ol class="bxadmins-steps-list"></ol>
                </details>
                <details class="bxadmins-reasoning" hidden>
                    <summary class="bxadmins-reasoning-summary"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_CHAT_REASONING'); ?></summary>
                    <pre class="bxadmins-reasoning-text"></pre>
                </details>
            </div>

            <div class="bxadmins-chat-composer">
                <div class="bxadmins-attach">
                    <button type="button" class="adm-btn bxadmins-attach-btn" title="<?php echo Loc::getMessage('MIBAZAROW_BXADMINS_CHAT_ATTACH_HINT'); ?>">
                        <?php echo Loc::getMessage('MIBAZAROW_BXADMINS_CHAT_ATTACH'); ?>
                    </button>
                    <input type="file" class="bxadmins-attach-input" multiple hidden
                           accept="image/*,.pdf,.doc,.docx,.xls,.xlsx,.txt,.csv,.md,.json,.zip">
                    <div class="bxadmins-attach-list" hidden></div>
                </div>
                <textarea rows="3" placeholder="<?php echo Loc::getMessage('MIBAZAROW_BXADMINS_CHAT_INPUT_PLACEHOLDER'); ?>"></textarea>
                <div class="bxadmins-composer-actions">
                    <button type="button" class="adm-btn adm-btn-save bxadmins-send"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_CHAT_SEND'); ?></button>
                    <button type="button" class="adm-btn bxadmins-stop" hidden title="<?php echo Loc::getMessage('MIBAZAROW_BXADMINS_CHAT_STOP_HINT'); ?>"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_CHAT_STOP'); ?></button>
                    <label class="bxadmins-bypass" title="<?php echo Loc::getMessage('MIBAZAROW_BXADMINS_CHAT_BYPASS_HINT'); ?>">
                        <input type="checkbox" class="bxadmins-bypass-input">
                        <span class="bxadmins-bypass-switch" aria-hidden="true"></span>
                        <span class="bxadmins-bypass-text"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_CHAT_BYPASS'); ?></span>
                    </label>
                </div>
                <div class="bxadmins-bypass-warn" hidden><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_CHAT_BYPASS_WARN'); ?></div>
                <div class="bxadmins-note"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_CHAT_LLM_NOTE'); ?></div>
            </div>
        </section>
    </div>
</div>
<?php
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
