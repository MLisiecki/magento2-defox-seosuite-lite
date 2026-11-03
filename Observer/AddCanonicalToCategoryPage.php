<?php
/**
 * @package     Defox_SEOSuite
 * @author      Marcin Lisiecki
 * @copyright   Copyright (c) 2025 deFox
 * @license     GNU General Public License v3.0
 */

declare(strict_types=1);

namespace Defox\SEOSuite\Observer;

use Defox\SEOSuite\Helper\Config;
use Defox\SEOSuite\Model\Canonical\Category as CanonicalCategory;
use Defox\SEOSuite\Model\Canonical\RobotsHandler;
use Magento\Catalog\Helper\Data as CatalogHelper;
use Magento\Catalog\Model\Category;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\View\Page\Config as PageConfig;
use Psr\Log\LoggerInterface;

/**
 * Observer that adds canonical links to category pages
 */
class AddCanonicalToCategoryPage implements ObserverInterface
{
    /**
     * @var PageConfig
     */
    private PageConfig $pageConfig;

    /**
     * @var Http
     */
    private Http $request;

    /**
     * @var CatalogHelper
     */
    private CatalogHelper $catalogHelper;
    
    /**
     * @var Config
     */
    private Config $config;
    
    /**
     * @var CanonicalCategory
     */
    private CanonicalCategory $canonicalCategory;
    
    /**
     * @var RobotsHandler
     */
    private RobotsHandler $robotsHandler;
    
    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @param PageConfig $pageConfig
     * @param Http $request
     * @param CatalogHelper $catalogHelper
     * @param Config $config
     * @param CanonicalCategory $canonicalCategory
     * @param RobotsHandler $robotsHandler
     * @param LoggerInterface $logger
     */
    public function __construct(
        PageConfig $pageConfig,
        Http $request,
        CatalogHelper $catalogHelper,
        Config $config,
        CanonicalCategory $canonicalCategory,
        RobotsHandler $robotsHandler,
        LoggerInterface $logger
    ) {
        $this->pageConfig = $pageConfig;
        $this->request = $request;
        $this->catalogHelper = $catalogHelper;
        $this->config = $config;
        $this->canonicalCategory = $canonicalCategory;
        $this->robotsHandler = $robotsHandler;
        $this->logger = $logger;
    }

    /**
     * Add canonical link to category page
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        try {
            // Check if we're on a category page
            if ($this->request->getFullActionName() !== 'catalog_category_view') {
                return;
            }
            
            // Check if the module and canonical links are enabled
            if (!$this->config->isEnabled() || !$this->config->isCategoryCanonicalEnabled()) {
                return;
            }
            
            // Check if category should have NOINDEX
            // Same decision the robots meta uses (no canonical on NOINDEX pages)
            if ($this->robotsHandler->shouldAddNoindex()) {
                return;
            }
            
            // Current category resolved by the core view controller
            $category = $this->catalogHelper->getCategory();
            
            if (!$category instanceof Category) {
                return;
            }
            
            // Get canonical URL
            $canonicalUrl = $this->canonicalCategory->getCanonicalUrl($category);
            
            if ($canonicalUrl) {
                // Add canonical link to head
                $this->pageConfig->addRemotePageAsset(
                    $canonicalUrl,
                    'canonical',
                    ['attributes' => ['rel' => 'canonical']]
                );
            }
        } catch (\Throwable $e) {
            $this->logger->error(
                'Error adding canonical link to category page: ' . $e->getMessage(),
                ['exception' => $e]
            );
        }
    }
}
