<?php
/**
 * @package     Defox_SEOSuite
 * @author      Marcin Lisiecki 
 * @copyright   Copyright (c) 2025 deFox
 * @license     GNU General Public License v3.0
 */

declare(strict_types=1);

namespace Defox\SEOSuite\Plugin\Catalog\Product;

use Magento\Catalog\Model\Locator\LocatorInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Ui\DataProvider\Product\Form\Modifier\AbstractModifier;
use Magento\Catalog\Ui\DataProvider\Product\Form\Modifier\General;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Exception\NotFoundException;
use Magento\Framework\Stdlib\ArrayManager;

/**
 * Plugin to add canonical exclusion option to product form
 */
class ExcludeCanonical
{
    /**
     * @var ArrayManager
     */
    private ArrayManager $arrayManager;

    /**
     * Product of the edit form (the same locator core's form modifiers use)
     *
     * @var LocatorInterface
     */
    private LocatorInterface $locator;

    /**
     * @var RequestInterface
     */
    private RequestInterface $request;

    /**
     * @param ArrayManager $arrayManager
     * @param LocatorInterface $locator
     * @param RequestInterface $request
     */
    public function __construct(
        ArrayManager $arrayManager,
        LocatorInterface $locator,
        RequestInterface $request
    ) {
        $this->arrayManager = $arrayManager;
        $this->locator = $locator;
        $this->request = $request;
    }

    /**
     * After plugin for modifyMeta method
     *
     * @param General $subject
     * @param array $result
     * @return array
     */
    public function afterModifyMeta(General $subject, array $result): array
    {
        $generalMetaPath = $this->arrayManager->findPath(
            AbstractModifier::DEFAULT_GENERAL_PANEL,
            $result,
            null,
            'children'
        );
        
        if ($generalMetaPath) {
            $generalMeta = $this->arrayManager->get($generalMetaPath, $result);
            
            // Add canonical exclusion field
            $generalChildren = $this->arrayManager->get('children', $generalMeta, []);
            $generalChildren['seo_canonical_disabled'] = $this->getCanonicalFieldConfig(40);
            
            // Set modified children
            $generalMeta = $this->arrayManager->set('children', $generalMeta, $generalChildren);
            
            // Set modified general meta
            $result = $this->arrayManager->set($generalMetaPath, $result, $generalMeta);
        }
        
        return $result;
    }

    /**
     * After plugin for modifyData method
     *
     * @param General $subject
     * @param array $result
     * @return array
     */
    public function afterModifyData(General $subject, array $result): array
    {
        $productId = $this->request->getParam('id');
        
        if ($productId) {
            try {
                $product = $this->locator->getProduct();
            } catch (NotFoundException $e) {
                return $result;
            }
            
            if ($product instanceof Product) {
                $seoCanonicalDisabled = $product->getData('seo_canonical_disabled') ?? 0;
                
                $result[$productId]['product']['seo_canonical_disabled'] = $seoCanonicalDisabled;
            }
        }
        
        return $result;
    }

    /**
     * Get canonical field config
     *
     * @param int $sortOrder
     * @return array
     */
    private function getCanonicalFieldConfig(int $sortOrder): array
    {
        return [
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
                        'sortOrder' => $sortOrder,
                        'dataScope' => 'seo_canonical_disabled',
                    ]
                ]
            ]
        ];
    }
}
