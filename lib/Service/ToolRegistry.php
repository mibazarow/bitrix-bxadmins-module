<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Service;

use Mibazarow\Bxadmins\Config;
use Mibazarow\Bxadmins\Tool\AddEventHandlerTool;
use Mibazarow\Bxadmins\Tool\SetElementImagesTool;
use Mibazarow\Bxadmins\Tool\SearchImagesTool;
use Mibazarow\Bxadmins\Tool\BrowserCssTool;
use Mibazarow\Bxadmins\Tool\BrowserInspectTool;
use Mibazarow\Bxadmins\Tool\BrowserScreenshotTool;
use Mibazarow\Bxadmins\Tool\BrowserSmokeTool;
use Mibazarow\Bxadmins\Tool\CheckSeoTool;
use Mibazarow\Bxadmins\Tool\FindDuplicateProductsTool;
use Mibazarow\Bxadmins\Tool\ScanUploadsTool;
use Mibazarow\Bxadmins\Tool\SetFormResultStatusTool;
use Mibazarow\Bxadmins\Tool\BulkCheckSeoTool;
use Mibazarow\Bxadmins\Tool\BulkDeleteElementsTool;
use Mibazarow\Bxadmins\Tool\CreateProductTool;
use Mibazarow\Bxadmins\Tool\GenerateSitemapTool;
use Mibazarow\Bxadmins\Tool\LowStockTool;
use Mibazarow\Bxadmins\Tool\ReadLogTool;
use Mibazarow\Bxadmins\Tool\SalesReportTool;
use Mibazarow\Bxadmins\Tool\SetSectionSeoTool;
use Mibazarow\Bxadmins\Tool\TopProductsTool;
use Mibazarow\Bxadmins\Tool\ClearCacheTool;
use Mibazarow\Bxadmins\Tool\CreateBackupTool;
use Mibazarow\Bxadmins\Tool\CreateCouponTool;
use Mibazarow\Bxadmins\Tool\CreateOfferTool;
use Mibazarow\Bxadmins\Tool\CreateDiscountTool;
use Mibazarow\Bxadmins\Tool\CreateForumPostTool;
use Mibazarow\Bxadmins\Tool\CreateForumTopicTool;
use Mibazarow\Bxadmins\Tool\CreateGroupTool;
use Mibazarow\Bxadmins\Tool\CreateIblockTool;
use Mibazarow\Bxadmins\Tool\CreateOrderTool;
use Mibazarow\Bxadmins\Tool\CreateUserTool;
use Mibazarow\Bxadmins\Tool\DeleteIblockTool;
use Mibazarow\Bxadmins\Tool\DeleteSiteFileTool;
use Mibazarow\Bxadmins\Tool\GetModuleOptionsTool;
use Mibazarow\Bxadmins\Tool\ListMailEventsTool;
use Mibazarow\Bxadmins\Tool\ListMailTemplatesTool;
use Mibazarow\Bxadmins\Tool\SaveMailTemplateTool;
use Mibazarow\Bxadmins\Tool\SetElementPropertiesTool;
use Mibazarow\Bxadmins\Tool\SetElementSeoTool;
use Mibazarow\Bxadmins\Tool\SetModuleOptionTool;
use Mibazarow\Bxadmins\Tool\UninstallModuleTool;
use Mibazarow\Bxadmins\Tool\UpdateIblockTool;
use Mibazarow\Bxadmins\Tool\UpdateUserTool;
use Mibazarow\Bxadmins\Tool\WriteSiteFileTool;
use Mibazarow\Bxadmins\Tool\CreateIblockElementTool;
use Mibazarow\Bxadmins\Tool\CreateIblockSectionTool;
use Mibazarow\Bxadmins\Tool\DeleteForumPostTool;
use Mibazarow\Bxadmins\Tool\DeleteIblockElementTool;
use Mibazarow\Bxadmins\Tool\DeleteLocalFileTool;
use Mibazarow\Bxadmins\Tool\GetIblockElementTool;
use Mibazarow\Bxadmins\Tool\GetProductPricesTool;
use Mibazarow\Bxadmins\Tool\GetStoreStockTool;
use Mibazarow\Bxadmins\Tool\GetEntityFieldsTool;
use Mibazarow\Bxadmins\Tool\GetIblockFieldsTool;
use Mibazarow\Bxadmins\Tool\GetSiteTool;
use Mibazarow\Bxadmins\Tool\InstallModuleTool;
use Mibazarow\Bxadmins\Tool\ListAgentsTool;
use Mibazarow\Bxadmins\Tool\ListBizprocTemplatesTool;
use Mibazarow\Bxadmins\Tool\ListBlogPostsTool;
use Mibazarow\Bxadmins\Tool\ListCalendarEventsTool;
use Mibazarow\Bxadmins\Tool\ListCouponsTool;
use Mibazarow\Bxadmins\Tool\ListCurrenciesTool;
use Mibazarow\Bxadmins\Tool\ListDeliveriesTool;
use Mibazarow\Bxadmins\Tool\ListDiscountsTool;
use Mibazarow\Bxadmins\Tool\ListCatalogProductsTool;
use Mibazarow\Bxadmins\Tool\ListHighloadBlocksTool;
use Mibazarow\Bxadmins\Tool\ListComponentsTool;
use Mibazarow\Bxadmins\Tool\ListEventsTool;
use Mibazarow\Bxadmins\Tool\ListForumTopicsTool;
use Mibazarow\Bxadmins\Tool\ListGroupsTool;
use Mibazarow\Bxadmins\Tool\ListIblockElementsTool;
use Mibazarow\Bxadmins\Tool\ListIblockPropertiesTool;
use Mibazarow\Bxadmins\Tool\ListIblockSectionsTool;
use Mibazarow\Bxadmins\Tool\ListIblocksTool;
use Mibazarow\Bxadmins\Tool\ListModulesTool;
use Mibazarow\Bxadmins\Tool\ListLocationsTool;
use Mibazarow\Bxadmins\Tool\ListOffersTool;
use Mibazarow\Bxadmins\Tool\ListOrdersTool;
use Mibazarow\Bxadmins\Tool\ListPaySystemsTool;
use Mibazarow\Bxadmins\Tool\ListPriceTypesTool;
use Mibazarow\Bxadmins\Tool\ListSitesTool;
use Mibazarow\Bxadmins\Tool\ListStoresTool;
use Mibazarow\Bxadmins\Tool\ListRestAppsTool;
use Mibazarow\Bxadmins\Tool\ListSocialGroupsTool;
use Mibazarow\Bxadmins\Tool\ListSubscriptionsTool;
use Mibazarow\Bxadmins\Tool\ListTemplatesTool;
use Mibazarow\Bxadmins\Tool\ModuleInfoTool;
use Mibazarow\Bxadmins\Tool\ReadFileTool;
use Mibazarow\Bxadmins\Tool\SearchKnowledgeTool;
use Mibazarow\Bxadmins\Tool\SiteInfoTool;
use Mibazarow\Bxadmins\Tool\ToolInterface;
use Mibazarow\Bxadmins\Tool\UpdateIblockElementTool;
use Mibazarow\Bxadmins\Tool\UpdateIblockSectionTool;
use Mibazarow\Bxadmins\Tool\UpdateOfferTool;
use Mibazarow\Bxadmins\Tool\UpdateOrderStatusTool;
use Mibazarow\Bxadmins\Tool\UpdateProductPriceTool;
use Mibazarow\Bxadmins\Tool\WriteLocalFileTool;
use Mibazarow\Bxadmins\Tool\CreateAgentTool;
use Mibazarow\Bxadmins\Tool\CreateBlogPostTool;
use Mibazarow\Bxadmins\Tool\CreateCalendarEventTool;
use Mibazarow\Bxadmins\Tool\CreateIblockPropertyTool;
use Mibazarow\Bxadmins\Tool\CreateIblockTypeTool;
use Mibazarow\Bxadmins\Tool\DeleteAgentTool;
use Mibazarow\Bxadmins\Tool\DeleteCouponTool;
use Mibazarow\Bxadmins\Tool\DeleteDiscountTool;
use Mibazarow\Bxadmins\Tool\DeleteForumTopicTool;
use Mibazarow\Bxadmins\Tool\DeleteIblockSectionTool;
use Mibazarow\Bxadmins\Tool\DeleteUserTool;
use Mibazarow\Bxadmins\Tool\ListBackupsTool;
use Mibazarow\Bxadmins\Tool\ListChangesTool;
use Mibazarow\Bxadmins\Tool\ListUsersTool;
use Mibazarow\Bxadmins\Tool\RemoveEventHandlerTool;
use Mibazarow\Bxadmins\Tool\RestoreBackupTool;
use Mibazarow\Bxadmins\Tool\RestoreSiteTool;
use Mibazarow\Bxadmins\Tool\RollbackChangeTool;
use Mibazarow\Bxadmins\Tool\SetMenuTool;
use Mibazarow\Bxadmins\Tool\UpdateGroupTool;
use Mibazarow\Bxadmins\Tool\UpdateSiteTool;
use Mibazarow\Bxadmins\Tool\CreateHighloadBlockTool;
use Mibazarow\Bxadmins\Tool\CreateSiteTool;
use Mibazarow\Bxadmins\Tool\DeleteHighloadBlockTool;
use Mibazarow\Bxadmins\Tool\DeleteIblockPropertyTool;
use Mibazarow\Bxadmins\Tool\DeleteRedirectTool;
use Mibazarow\Bxadmins\Tool\GetFormResultsTool;
use Mibazarow\Bxadmins\Tool\GetMenuTool;
use Mibazarow\Bxadmins\Tool\GetRobotsTool;
use Mibazarow\Bxadmins\Tool\ListFormsTool;
use Mibazarow\Bxadmins\Tool\ListOrderStatusesTool;
use Mibazarow\Bxadmins\Tool\ListRedirectsTool;
use Mibazarow\Bxadmins\Tool\RunSqlTool;
use Mibazarow\Bxadmins\Tool\SetRedirectTool;
use Mibazarow\Bxadmins\Tool\SetRobotsTool;
use Mibazarow\Bxadmins\Tool\SetStoreStockTool;
use Mibazarow\Bxadmins\Tool\SetUserGroupsTool;
use Mibazarow\Bxadmins\Tool\SetUserPasswordTool;
use Mibazarow\Bxadmins\Tool\UpdateCatalogProductTool;
use Mibazarow\Bxadmins\Tool\UpdateDiscountTool;
use Mibazarow\Bxadmins\Tool\UpdateIblockPropertyTool;
use Mibazarow\Bxadmins\Tool\UpdateOrderTool;
use Mibazarow\Bxadmins\Tool\UploadFileTool;
use Mibazarow\Bxadmins\Tool\CreateForumTool;
use Mibazarow\Bxadmins\Tool\DeleteForumTool;
use Mibazarow\Bxadmins\Tool\ListForumsTool;
use Mibazarow\Bxadmins\Tool\ListForumPostsTool;
use Mibazarow\Bxadmins\Tool\UpdateForumTool;
use Mibazarow\Bxadmins\Tool\UpdateForumPostTool;
use Mibazarow\Bxadmins\Tool\UpdateForumTopicTool;
use Mibazarow\Bxadmins\Tool\DownloadModuleTool;
use Mibazarow\Bxadmins\Tool\ModuleCoverageTool;
use Mibazarow\Bxadmins\Tool\AddBlogCommentTool;
use Mibazarow\Bxadmins\Tool\AddSubscriberTool;
use Mibazarow\Bxadmins\Tool\DeleteBlogCommentTool;
use Mibazarow\Bxadmins\Tool\DeleteBlogPostTool;
use Mibazarow\Bxadmins\Tool\DeleteCalendarEventTool;
use Mibazarow\Bxadmins\Tool\GetTrafficSummaryTool;
use Mibazarow\Bxadmins\Tool\ListBannersTool;
use Mibazarow\Bxadmins\Tool\ListBlogCommentsTool;
use Mibazarow\Bxadmins\Tool\ListCoursesTool;
use Mibazarow\Bxadmins\Tool\ListLandingsTool;
use Mibazarow\Bxadmins\Tool\ListModuleContentTool;
use Mibazarow\Bxadmins\Tool\ListSubscribeRubricsTool;
use Mibazarow\Bxadmins\Tool\ListTicketsTool;
use Mibazarow\Bxadmins\Tool\ListVotesTool;
use Mibazarow\Bxadmins\Tool\ReindexSearchTool;
use Mibazarow\Bxadmins\Tool\UpdateBlogPostTool;
use Mibazarow\Bxadmins\Tool\UpdateCalendarEventTool;
use Mibazarow\Bxadmins\Tool\CreateScheduleTool;
use Mibazarow\Bxadmins\Tool\DeleteScheduleTool;
use Mibazarow\Bxadmins\Tool\ListSchedulesTool;
use Mibazarow\Bxadmins\Tool\SaveMemoryTool;
use Mibazarow\Bxadmins\Tool\ListMemoriesTool;
use Mibazarow\Bxadmins\Tool\ListElementsWithoutImagesTool;
use Mibazarow\Bxadmins\Tool\AnalyzeImageTool;
use Mibazarow\Bxadmins\Tool\SaveSkillTool;
use Mibazarow\Bxadmins\Tool\ListSkillsTool;
use Mibazarow\Bxadmins\Tool\SearchSkillsTool;
use Mibazarow\Bxadmins\Tool\UseSkillTool;
use Mibazarow\Bxadmins\Tool\RestoreSkillTool;
use Mibazarow\Bxadmins\Tool\RenameSkillTool;
use Mibazarow\Bxadmins\Tool\DeleteSkillTool;
use Mibazarow\Bxadmins\Tool\McpTool;
use Mibazarow\Bxadmins\Tool\TodoWriteTool;
use Mibazarow\Bxadmins\Tool\TodoReadTool;
use Mibazarow\Bxadmins\Tool\RunPhpTool;
use Mibazarow\Bxadmins\Tool\UpdateBannerTool;
use Mibazarow\Bxadmins\Tool\ForgetMemoryTool;
use Mibazarow\Bxadmins\Tool\CreateModuleTool;
use Mibazarow\Bxadmins\Tool\DeleteModuleTool;
use Mibazarow\Bxadmins\Tool\UpdateScheduleTool;
use Mibazarow\Bxadmins\Tool\BulkUpdatePricesTool;
use Mibazarow\Bxadmins\Tool\CreateUserFieldTool;
use Mibazarow\Bxadmins\Tool\DeleteMediaFileTool;
use Mibazarow\Bxadmins\Tool\GetServerStatusTool;
use Mibazarow\Bxadmins\Tool\ListMediaFilesTool;
use Mibazarow\Bxadmins\Tool\SendTestEmailTool;
use Mibazarow\Bxadmins\Tool\UpdateOrderItemsTool;
use Mibazarow\Bxadmins\Tool\BulkUpdateElementsTool;
use Mibazarow\Bxadmins\Tool\CheckLinksTool;
use Mibazarow\Bxadmins\Tool\CheckUptimeTool;
use Mibazarow\Bxadmins\Tool\CreateDeliveryTool;
use Mibazarow\Bxadmins\Tool\CreatePaySystemTool;
use Mibazarow\Bxadmins\Tool\CreateSiteTemplateTool;
use Mibazarow\Bxadmins\Tool\CreateWebFormTool;
use Mibazarow\Bxadmins\Tool\ImportCsvElementsTool;
use Mibazarow\Bxadmins\Tool\UpdateWebFormTool;
use Mibazarow\Bxadmins\Tool\UploadBackupS3Tool;

/**
 * Реестр инструментов: определения для модели и исполнение с проверкой домена прав.
 */
class ToolRegistry
{
    /** @var class-string<ToolInterface>[] */
    private const TOOLS = array(
        SiteInfoTool::class,
        ListModulesTool::class,
        ModuleInfoTool::class,
        ListSitesTool::class,
        UpdateSiteTool::class,
        GetSiteTool::class,
        ListGroupsTool::class,
        ListIblocksTool::class,
        ListMailTemplatesTool::class,
        ListMailEventsTool::class,
        GetModuleOptionsTool::class,
        GetIblockFieldsTool::class,
        ListComponentsTool::class,
        ListEventsTool::class,
        ListAgentsTool::class,
        CreateAgentTool::class,
        DeleteAgentTool::class,
        ListUsersTool::class,
        ListTemplatesTool::class,
        GetEntityFieldsTool::class,
        SearchKnowledgeTool::class,
        ListIblockSectionsTool::class,
        ListIblockElementsTool::class,
        ListIblockPropertiesTool::class,
        CreateIblockPropertyTool::class,
        GetIblockElementTool::class,
        ListHighloadBlocksTool::class,
        ReadFileTool::class,
        ListOrdersTool::class,
        ListCatalogProductsTool::class,
        ListPriceTypesTool::class,
        ListStoresTool::class,
        ListPaySystemsTool::class,
        ListDeliveriesTool::class,
        ListDiscountsTool::class,
        DeleteDiscountTool::class,
        ListCouponsTool::class,
        DeleteCouponTool::class,
        ListCurrenciesTool::class,
        ListLocationsTool::class,
        GetProductPricesTool::class,
        GetStoreStockTool::class,
        ListOffersTool::class,
        ListForumTopicsTool::class,
        DeleteForumTopicTool::class,
        ListSocialGroupsTool::class,
        ListBlogPostsTool::class,
        CreateBlogPostTool::class,
        ListCalendarEventsTool::class,
        CreateCalendarEventTool::class,
        ListBizprocTemplatesTool::class,
        ListRestAppsTool::class,
        ListSubscriptionsTool::class,
        CheckSeoTool::class,
        BrowserScreenshotTool::class,
        BrowserInspectTool::class,
        BrowserCssTool::class,
        BrowserSmokeTool::class,
        CreateBackupTool::class,
        ListBackupsTool::class,
        ListChangesTool::class,
        RollbackChangeTool::class,
        RestoreSiteTool::class,
        RestoreBackupTool::class,
        CreateIblockTool::class,
        CreateIblockTypeTool::class,
        UpdateIblockTool::class,
        DeleteIblockTool::class,
        CreateUserTool::class,
        UpdateUserTool::class,
        DeleteUserTool::class,
        CreateGroupTool::class,
        UpdateGroupTool::class,
        CreateOrderTool::class,
        CreateForumTopicTool::class,
        CreateForumPostTool::class,
        SaveMailTemplateTool::class,
        SetModuleOptionTool::class,
        InstallModuleTool::class,
        UninstallModuleTool::class,
        CreateModuleTool::class,
        DeleteModuleTool::class,
        SetElementPropertiesTool::class,
        SetElementSeoTool::class,
        WriteSiteFileTool::class,
        SetMenuTool::class,
        DeleteSiteFileTool::class,
        CreateIblockElementTool::class,
        UpdateIblockElementTool::class,
        CreateIblockSectionTool::class,
        UpdateIblockSectionTool::class,
        DeleteIblockSectionTool::class,
        DeleteIblockElementTool::class,
        DeleteLocalFileTool::class,
        UpdateOrderStatusTool::class,
        UpdateProductPriceTool::class,
        CreateDiscountTool::class,
        CreateCouponTool::class,
        CreateOfferTool::class,
        UpdateOfferTool::class,
        DeleteForumPostTool::class,
        AddEventHandlerTool::class,
        RemoveEventHandlerTool::class,
        ClearCacheTool::class,
        WriteLocalFileTool::class,
        CreateHighloadBlockTool::class,
        CreateSiteTool::class,
        DeleteHighloadBlockTool::class,
        DeleteIblockPropertyTool::class,
        DeleteRedirectTool::class,
        GetFormResultsTool::class,
        GetMenuTool::class,
        GetRobotsTool::class,
        ListFormsTool::class,
        ListOrderStatusesTool::class,
        ListRedirectsTool::class,
        RunSqlTool::class,
        SetRedirectTool::class,
        SetRobotsTool::class,
        SetStoreStockTool::class,
        SetUserGroupsTool::class,
        SetUserPasswordTool::class,
        UpdateCatalogProductTool::class,
        UpdateDiscountTool::class,
        UpdateIblockPropertyTool::class,
        UpdateOrderTool::class,
        UploadFileTool::class,
        SetElementImagesTool::class,
        SearchImagesTool::class,
        CreateForumTool::class,
        DeleteForumTool::class,
        ListForumsTool::class,
        ListForumPostsTool::class,
        UpdateForumTool::class,
        UpdateForumPostTool::class,
        UpdateForumTopicTool::class,
        DownloadModuleTool::class,
        ModuleCoverageTool::class,
        AddBlogCommentTool::class,
        AddSubscriberTool::class,
        DeleteBlogCommentTool::class,
        DeleteBlogPostTool::class,
        DeleteCalendarEventTool::class,
        GetTrafficSummaryTool::class,
        ListBannersTool::class,
        ListBlogCommentsTool::class,
        ListCoursesTool::class,
        ListLandingsTool::class,
        ListModuleContentTool::class,
        ListSubscribeRubricsTool::class,
        ListTicketsTool::class,
        ListVotesTool::class,
        ReindexSearchTool::class,
        UpdateBlogPostTool::class,
        UpdateCalendarEventTool::class,
        CreateScheduleTool::class,
        DeleteScheduleTool::class,
        ListSchedulesTool::class,
        SaveMemoryTool::class,
        ListMemoriesTool::class,
        ListElementsWithoutImagesTool::class,
        AnalyzeImageTool::class,
        SaveSkillTool::class,
        ListSkillsTool::class,
        SearchSkillsTool::class,
        UseSkillTool::class,
        RestoreSkillTool::class,
        RenameSkillTool::class,
        DeleteSkillTool::class,
BulkCheckSeoTool::class,
BulkDeleteElementsTool::class,
CreateProductTool::class,
GenerateSitemapTool::class,
LowStockTool::class,
ReadLogTool::class,
SalesReportTool::class,
SetSectionSeoTool::class,
TopProductsTool::class,
FindDuplicateProductsTool::class,
ScanUploadsTool::class,
SetFormResultStatusTool::class,
        McpTool::class,
        TodoWriteTool::class,
        TodoReadTool::class,
        RunPhpTool::class,
        UpdateBannerTool::class,
        ForgetMemoryTool::class,
        UpdateScheduleTool::class,
        BulkUpdatePricesTool::class,
        CreateUserFieldTool::class,
        DeleteMediaFileTool::class,
        GetServerStatusTool::class,
        ListMediaFilesTool::class,
        SendTestEmailTool::class,
        UpdateOrderItemsTool::class,
        BulkUpdateElementsTool::class,
        CheckLinksTool::class,
        CheckUptimeTool::class,
        CreateDeliveryTool::class,
        CreatePaySystemTool::class,
        CreateSiteTemplateTool::class,
        CreateWebFormTool::class,
        ImportCsvElementsTool::class,
        UpdateWebFormTool::class,
        UploadBackupS3Tool::class,
    );

    /** @return class-string<ToolInterface>[] все инструменты модуля */
    public static function tools(): array
    {
        return self::TOOLS;
    }

    /** Массив tools для OpenAI: только инструменты разрешённых доменов. */
    public static function definitions(): array
    {
        $definitions = array();

        foreach (self::TOOLS as $tool) {
            if (!Config::isDomainAllowed($tool::getDomain())) {
                continue;
            }

            $definition = $tool::getDefinition();
            $parameters = $definition['parameters'] ?? null;

            // Пустой properties должен уходить как {}, иначе строгие провайдеры
            // считают схему невалидной (JSON-массив вместо JSON-объекта).
            if (is_array($parameters)
                && ($parameters['type'] ?? '') === 'object'
                && isset($parameters['properties'])
                && is_array($parameters['properties'])
                && $parameters['properties'] === array()) {
                $definition['parameters']['properties'] = new \stdClass();
            }

            $definitions[] = array(
                'type' => 'function',
                'function' => $definition,
            );
        }

        // MCP-инструменты не отправляются схемами в каждом запросе: их отдаёт
        // mcp_tool по требованию (list → describe → call).

        return $definitions;
    }

    /** Исполняет инструмент; ошибки возвращаются как ['error' => ...], исключения не всплывают. */
    public static function execute(string $name, array $args, array $context = array()): array
    {
        // Инструменты MCP живут вне реестра: маршрутизируем по префиксу сервера.
        if (McpService::owns($name)) {
            if (!Config::isDomainAllowed('integrations')) {
                return array('error' => 'Инструменты MCP отключены: домен "integrations" выключен в настройках.');
            }

            return McpService::execute($name, $args);
        }

        foreach (self::TOOLS as $tool) {
            if ($tool::getName() !== $name) {
                continue;
            }

            $domain = $tool::getDomain();
            if (!Config::isDomainAllowed($domain)) {
                return array(
                    'error' => 'Tool "' . $name . '" is unavailable: domain "' . $domain . '" is disabled in settings.',
                );
            }

            $definition = $tool::getDefinition();
            $schema = is_array($definition['parameters'] ?? null) ? $definition['parameters'] : array();
            $errors = ToolSchema::validate($schema, $args);
            if ($errors !== array()) {
                return array(
                    'error' => 'Invalid arguments for "' . $name . '": ' . implode('; ', $errors) . '.',
                );
            }

            try {
                Modules::ensure();

                return $tool::execute($args, $context);
            } catch (\InvalidArgumentException $e) {
                return array('error' => $e->getMessage());
            } catch (\Throwable $e) {
                Logger::error('Tool "' . $name . '" error: ' . $e->getMessage());

                // Причина нужна и модели, и человеку: без неё агент не может
                // исправить аргументы, а пользователь — понять, что произошло.
                $reason = trim(strip_tags($e->getMessage()));

                return array(
                    'error' => 'Tool "' . $name . '" failed'
                        . ($reason !== '' ? ': ' . mb_substr($reason, 0, 300) : '.'),
                );
            }
        }

        return array('error' => 'Unknown tool: ' . $name);
    }
}
