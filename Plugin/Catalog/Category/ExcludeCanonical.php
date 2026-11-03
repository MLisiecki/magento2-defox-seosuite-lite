<?php
/**
 * @package     Defox_SEOSuite
 * @author      Marcin Lisiecki 
 * @copyright   Copyright (c) 2025 deFox
 * @license     GNU General Public License v3.0
 */

declare(strict_types=1);

namespace Defox\SEOSuite\Plugin\Catalog\Category;

use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Category\DataProvider;
use Magento\Framework\App\RequestInterface;

/**
 * Plugin to add canonical exclusion option to category form
 */
class ExcludeCanonical
{
    /**
     * @var RequestInterface
     */
    private RequestInterface $request;

    /**
     * @param RequestInterface $request
     */
    public function __construct(
        RequestInterface $request
    ) {
        $this->request = $request;
    }

    /**
     * After plugin for getMeta method
     *
     * @param DataProvider $subject
     * @param array $result
     * @return array
     */
    public function afterGetMeta(DataProvider $subject, array $result): array
    {
        // Add canonical exclusion field to SEO section
        if (isset($result['search_engine_optimization']['children'])) {
            $result['search_engine_optimization']['children']['seo_canonical_disabled'] = [
                'arguments' => [
                    'data' => [
                        'config' => [
                            'dataType' => 'boolean',
                            'formElement' => 'checkbox',
                            'componentType' => 'field',
                            'label' => __('Disable Canonical URL'),
                            'prefer' => 'toggle',
                            'valueMap' => [
                                'true' => 1,
                                'false' => 0
                            ],
                            'default' => 0,
                            'sortOrder' => 40,
                            'dataScope' => 'seo_canonical_disabled',
                        ]
                    ]
                ]
            ];
        }
        
        return $result;
    }

    /**
     * After plugin for getData method
     *
     * @param DataProvider $subject
     * @param array $result
     * @return array
     */
    public function afterGetData(DataProvider $subject, array $result): array
    {
        $categoryId = $this->request->getParam('id');
        
        if ($categoryId && isset($result[$categoryId])) {
            $category = $subject->getCurrentCategory();
            
            if ($category instanceof Category) {
                $seoCanonicalDisabled = $category->getData('seo_canonical_disabled') ?? 0;
                
                $result[$categoryId]['seo_canonical_disabled'] = $seoCanonicalDisabled;
            }
        }
        
        return $result;
    }
}
