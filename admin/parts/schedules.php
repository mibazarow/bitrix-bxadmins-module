<?php

/**
 * Блок «Задачи по расписанию». Используется страницей «Задачи».
 *
 * Ожидает переменные: $schedules (список задач), $chatUrl (ссылка на чат),
 * $ajaxUrl, $sessid. Логику кнопок включает manage.js (initSchedules).
 */

declare(strict_types=1);

use Bitrix\Main\Localization\Loc;

// Тексты блока лежат в языковом файле страницы «Управление»: грузим его здесь,
// чтобы блок одинаково работал и на своей странице, и внутри вкладки.
Loc::loadMessages(__DIR__ . '/../manage.php');

/** @var array $schedules */
/** @var string $chatUrl */
/** @var string $ajaxUrl */
/** @var string $sessid */
?>
<div class="bxadmins-schedules"
     data-ajax="<?php echo htmlspecialchars($ajaxUrl, ENT_QUOTES, 'UTF-8'); ?>"
     data-sessid="<?php echo htmlspecialchars($sessid, ENT_QUOTES, 'UTF-8'); ?>"
     data-chat-url="<?php echo htmlspecialchars($chatUrl, ENT_QUOTES, 'UTF-8'); ?>"
     data-delete-confirm="<?php echo htmlspecialchars(Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_SCHEDULE_DELETE_CONFIRM'), ENT_QUOTES, 'UTF-8'); ?>"
     data-fail-template="<?php echo htmlspecialchars(Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_ACTION_FAIL'), ENT_QUOTES, 'UTF-8'); ?>"
     data-none="<?php echo htmlspecialchars(Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_SCHEDULE_NONE'), ENT_QUOTES, 'UTF-8'); ?>">

    <div class="bxadmins-sched-intro">
        <div class="bxadmins-sched-intro-icon" aria-hidden="true">⏱</div>
        <div class="bxadmins-sched-intro-body">
            <div class="bxadmins-sched-intro-title"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_SCHEDULE_INTRO_TITLE'); ?></div>
            <div class="bxadmins-sched-intro-text"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_SCHEDULE_INTRO_TEXT'); ?></div>
        </div>
        <a class="adm-btn adm-btn-save" href="<?php echo htmlspecialchars($chatUrl, ENT_QUOTES, 'UTF-8'); ?>"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_SCHEDULE_INTRO_BTN'); ?></a>
    </div>

    <?php /* hidden не работает: у .adm-info-message свой display — скрываем классом. */ ?>
    <div class="adm-info-message bxadmins-hidden" id="bxadmins-sched-result"></div>

    <div class="bxadmins-sched-list" id="bxadmins-sched-list">
        <?php foreach ($schedules as $schedule): ?>
            <div class="bxadmins-sched<?php echo $schedule['active'] ? '' : ' bxadmins-sched-off'; ?>"
                 data-id="<?php echo (int)$schedule['id']; ?>">
                <div class="bxadmins-sched-main">
                    <div class="bxadmins-sched-head">
                        <span class="bxadmins-sched-title"><?php echo htmlspecialchars($schedule['title'], ENT_QUOTES, 'UTF-8'); ?></span>
                        <span class="bxadmins-sched-badge"><?php echo htmlspecialchars($schedule['interval_text'], ENT_QUOTES, 'UTF-8'); ?></span>
                    </div>
                    <div class="bxadmins-sched-desc"><?php echo htmlspecialchars(mb_substr($schedule['prompt'], 0, 220), ENT_QUOTES, 'UTF-8'); ?></div>
                    <div class="bxadmins-sched-meta">
                        <?php echo Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_SCHEDULE_NEXT'); ?>:
                        <b><?php echo htmlspecialchars($schedule['next_run'], ENT_QUOTES, 'UTF-8'); ?></b>
                        &middot; <?php echo Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_SCHEDULE_RUNS'); ?>:
                        <b><?php echo (int)$schedule['runs']; ?></b>
                        <?php if ($schedule['last_status'] !== ''): ?>
                            &middot; <?php echo Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_SCHEDULE_LAST'); ?>:
                            <b class="bxadmins-sched-status-<?php echo htmlspecialchars($schedule['last_status'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($schedule['last_status'], ENT_QUOTES, 'UTF-8'); ?></b>
                            <span class="bxadmins-sched-when">(<?php echo htmlspecialchars($schedule['last_run'], ENT_QUOTES, 'UTF-8'); ?>)</span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="bxadmins-sched-actions">
                    <?php /* Кнопка, а не checkbox: админка Битрикса подменяет input[type=checkbox] своей галкой. */ ?>
                    <button type="button"
                            class="bxadmins-switch<?php echo $schedule['active'] ? ' bxadmins-switch-on' : ''; ?>"
                            role="switch"
                            aria-checked="<?php echo $schedule['active'] ? 'true' : 'false'; ?>"
                            data-active="<?php echo $schedule['active'] ? 'Y' : 'N'; ?>"
                            title="<?php echo Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_SCHEDULE_TOGGLE'); ?>">
                        <span class="bxadmins-switch-track"><span class="bxadmins-switch-knob"></span></span>
                    </button>
                    <a class="adm-btn bxadmins-sched-edit"
                       href="<?php echo htmlspecialchars($chatUrl . '&schedule=' . (int)$schedule['id'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_SCHEDULE_EDIT'); ?></a>
                    <button type="button" class="adm-btn bxadmins-sched-delete"
                            data-id="<?php echo (int)$schedule['id']; ?>"
                            data-title="<?php echo htmlspecialchars($schedule['title'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_SCHEDULE_DELETE'); ?></button>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="adm-info-message<?php echo $schedules === array() ? '' : ' bxadmins-hidden'; ?>" id="bxadmins-sched-empty"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_SCHEDULE_EMPTY'); ?></div>

    <div class="bxadmins-note"><?php echo Loc::getMessage('MIBAZAROW_BXADMINS_MANAGE_SCHEDULE_NOTE'); ?></div>
</div>
