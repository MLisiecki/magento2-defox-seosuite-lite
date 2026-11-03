<?php
/**
 * @package     Defox_SEOSuite
 * @author      Marcin Lisiecki
 * @copyright   Copyright (c) 2025 deFox
 * @license     GNU General Public License v3.0
 */
declare(strict_types=1);

namespace Defox\SEOSuite\Cron;

use Defox\SEOSuite\Model\Sitemap\Analytics\StatisticsManager;
use Magento\Framework\App\Config\ScopeConfigInterface;

/**
 * Daily cleanup of sitemap generation statistics past the configured retention
 */
class CleanSitemapStatistics
{
    private const XML_PATH_RETENTION_DAYS = 'defox_seosuite/sitemap/statistics_retention_days';

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param StatisticsManager $statisticsManager
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly StatisticsManager $statisticsManager
    ) {
    }

    /**
     * Delete rows older than the retention period (0 = keep forever)
     *
     * @return void
     */
    public function execute(): void
    {
        $this->statisticsManager->deleteOlderThan((int)$this->scopeConfig->getValue(self::XML_PATH_RETENTION_DAYS));
    }
}
