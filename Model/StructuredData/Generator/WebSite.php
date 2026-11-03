<?php
/**
 * @package     Defox_SEOSuite
 * @author      Marcin Lisiecki 
 * @copyright   Copyright (c) 2025 deFox
 * @license     GNU General Public License v3.0
 */

declare(strict_types=1);

namespace Defox\SEOSuite\Model\StructuredData\Generator;

use Defox\SEOSuite\Model\StructuredData\AbstractGenerator;
use Magento\Store\Model\Store;

/**
 * WebSite structured data generator
 * 
 * Generates schema.org WebSite structured data (no SearchAction: Google retired the Sitelinks Searchbox).
 */
class WebSite extends AbstractGenerator
{
    /**
     * Generate structured data for website
     *
     * @param mixed $entity
     * @param array $context
     * @return array
     */
    protected function doGenerate($entity, array $context): array
    {
        $store = $this->storeManager->getStore();
        $baseUrl = $store->getBaseUrl();
        
        $data = [
            '@type' => 'WebSite',
            'name' => $store->getName(),
            'url' => $baseUrl
        ];
        
        // Add alternative name if configured
        $alternateName = $this->configHelper->getStructuredDataWebsiteAlternateName();
        if ($alternateName) {
            $data['alternateName'] = $alternateName;
        }
        
        // Publisher: the store's Organization (one guard: enabled + non-empty name)
        $organization = $this->organizationData->getNamedData();
        if ($organization !== null) {
            $data['publisher'] = $organization;
        }
        
        return $data;
    }
    
    /**
     * Check if generator can handle entity
     *
     * @param mixed $entity
     * @return bool
     */
    public function canHandle($entity): bool
    {
        // Website data is generated for homepage or store
        return $entity instanceof Store || $entity === null;
    }
    
    /**
     * Get schema type
     *
     * @return string
     */
    public function getSchemaType(): string
    {
        return 'WebSite';
    }
    
    /**
     * Check if generator is enabled
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isEnabled(?int $storeId = null): bool
    {
        return $this->configHelper->isStructuredDataWebsiteEnabled($storeId);
    }

}
