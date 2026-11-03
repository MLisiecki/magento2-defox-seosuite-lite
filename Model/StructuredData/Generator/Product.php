<?php
/**
 * @package     Defox_SEOSuite
 * @author      Marcin Lisiecki 
 * @copyright   Copyright (c) 2025 deFox
 * @license     GNU General Public License v3.0
 */

declare(strict_types=1);

namespace Defox\SEOSuite\Model\StructuredData\Generator;

use Defox\SEOSuite\Helper\Config;
use Defox\SEOSuite\Model\Text\Cleaner;
use Defox\SEOSuite\Model\StructuredData\AbstractGenerator;
use Defox\SEOSuite\Model\StructuredData\OrganizationDataProvider;
use Defox\SEOSuite\Model\StructuredData\Mapper\AttributeMapperInterface;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Helper\Data as CatalogHelper;
use Magento\Catalog\Helper\Image as ImageHelper;
use Magento\Catalog\Model\Product as ProductModel;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Module\Manager as ModuleManager;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Review\Model\AppendSummaryData;
use Magento\Review\Model\Review as ReviewModel;
use Magento\Review\Model\ReviewFactory;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Product structured data generator
 * 
 * Generates schema.org Product structured data for catalog products.
 * Supports simple, configurable, grouped, virtual, and downloadable product types.
 */
class Product extends AbstractGenerator
{
    /**
     * Keys a mapped (text) attribute can never fill validly; other keys a mapping may fill when the generator left them empty
     */
    private const RESERVED_KEYS = [
        '@context', '@type', 'image', 'brand', 'offers', 'review', 'aggregateRating', 'additionalProperty',
    ];

    /**
     * @var ImageHelper
     */
    private ImageHelper $imageHelper;
    
    /**
     * @var StockRegistryInterface
     */
    private StockRegistryInterface $stockRegistry;
    
    /**
     * @var PriceCurrencyInterface
     */
    private PriceCurrencyInterface $priceCurrency;
    
    /**
     * @var ReviewFactory
     */
    private ReviewFactory $reviewFactory;
    
    /**
     * @var AttributeMapperInterface
     */
    private AttributeMapperInterface $attributeMapper;
    
    /**
     * @var CatalogHelper
     */
    private CatalogHelper $catalogHelper;
    
    /**
     * @var CategoryCollectionFactory
     */
    private CategoryCollectionFactory $categoryCollectionFactory;

    /**
     * @var AppendSummaryData
     */
    private AppendSummaryData $appendSummaryData;

    /**
     * @var ModuleManager
     */
    private ModuleManager $moduleManager;

    /**
     * @var TimezoneInterface
     */
    private TimezoneInterface $timezone;
    
    /**
     * Constructor
     *
     * @param Config $configHelper
     * @param StoreManagerInterface $storeManager
     * @param LoggerInterface $logger
     * @param ScopeConfigInterface $scopeConfig
     * @param Cleaner $textCleaner
     * @param OrganizationDataProvider $organizationData
     * @param ImageHelper $imageHelper
     * @param StockRegistryInterface $stockRegistry
     * @param PriceCurrencyInterface $priceCurrency
     * @param ReviewFactory $reviewFactory
     * @param AttributeMapperInterface $attributeMapper
     * @param CatalogHelper $catalogHelper
     * @param CategoryCollectionFactory $categoryCollectionFactory
     * @param ModuleManager $moduleManager
     * @param AppendSummaryData $appendSummaryData
     * @param TimezoneInterface $timezone
     */
    public function __construct(
        Config $configHelper,
        StoreManagerInterface $storeManager,
        LoggerInterface $logger,
        ScopeConfigInterface $scopeConfig,
        Cleaner $textCleaner,
        OrganizationDataProvider $organizationData,
        ImageHelper $imageHelper,
        StockRegistryInterface $stockRegistry,
        PriceCurrencyInterface $priceCurrency,
        ReviewFactory $reviewFactory,
        AttributeMapperInterface $attributeMapper,
        CatalogHelper $catalogHelper,
        CategoryCollectionFactory $categoryCollectionFactory,
        ModuleManager $moduleManager,
        AppendSummaryData $appendSummaryData,
        TimezoneInterface $timezone
    ) {
        parent::__construct($configHelper, $storeManager, $logger, $scopeConfig, $textCleaner, $organizationData);
        $this->imageHelper = $imageHelper;
        $this->stockRegistry = $stockRegistry;
        $this->priceCurrency = $priceCurrency;
        $this->reviewFactory = $reviewFactory;
        $this->attributeMapper = $attributeMapper;
        $this->catalogHelper = $catalogHelper;
        $this->categoryCollectionFactory = $categoryCollectionFactory;
        $this->moduleManager = $moduleManager;
        $this->appendSummaryData = $appendSummaryData;
        $this->timezone = $timezone;
    }
    
    /**
     * Generate structured data for product
     *
     * @param mixed $entity
     * @param array $context
     * @return array
     */
    protected function doGenerate($entity, array $context): array
    {
        /** @var ProductModel $product */
        $product = $entity;
        
        $data = [
            '@type' => 'Product',
            'name' => $product->getName(),
            'sku' => $product->getSku(),
            'url' => $this->cleanUrl($product->getProductUrl())
        ];

        $description = $this->getProductDescription($product);
        if ($description !== '') {
            $data['description'] = $description;
        }
        
        // Add images
        $images = $this->getProductImages($product);
        if (!empty($images)) {
            $data['image'] = count($images) === 1 ? $images[0] : $images;
        }
        
        // Add brand/manufacturer
        $brand = $this->getProductBrand($product);
        if ($brand) {
            $data['brand'] = [
                '@type' => 'Brand',
                'name' => $brand
            ];
        }
        
        // Add model
        $model = $this->getProductModel($product);
        if ($model) {
            $data['model'] = $model;
        }
        
        // Add identifiers (GTIN, MPN)
        $identifiers = $this->getProductIdentifiers($product);
        $data = array_merge($data, $identifiers);
        
        // Add offers
        $offers = $this->getProductOffers($product);
        if (!empty($offers)) {
            $data['offers'] = $offers;
        }
        
        // Add reviews and ratings
        $reviewData = $this->getProductReviews($product);
        if (!empty($reviewData)) {
            $data = array_merge($data, $reviewData);
        }
        
        // Add custom attributes based on mapping: only fill keys the generator did not set (no overrides)
        $custom = array_diff_key($this->getCustomAttributes($product), array_flip(self::RESERVED_KEYS));
        if (array_intersect_key($data, array_flip(['gtin8', 'gtin13', 'gtin14']))) {
            unset($custom['gtin8'], $custom['gtin13'], $custom['gtin14']); // one GTIN per product
        }
        $data += $custom;
        
        // Add additional properties (weight, dimensions, etc.)
        $additionalProperties = $this->getAdditionalProperties($product);
        if (!empty($additionalProperties)) {
            $data['additionalProperty'] = $additionalProperties;
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
        return $entity instanceof ProductInterface || $entity instanceof ProductModel;
    }
    
    /**
     * Get schema type
     *
     * @return string
     */
    public function getSchemaType(): string
    {
        return 'Product';
    }
    
    /**
     * Check if generator is enabled
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isEnabled(?int $storeId = null): bool
    {
        return $this->configHelper->isStructuredDataProductEnabled($storeId);
    }
    
    /**
     * Get product description
     *
     * @param ProductModel $product
     * @return string
     */
    private function getProductDescription(ProductModel $product): string
    {
        $description = $product->getDescription() ?: $product->getShortDescription();
        if ($description) {
            // Use the new HTML cleaning method from AbstractGenerator
            $description = $this->cleanDescription($description);
        }
        
        return $description ?: '';
    }
    
    /**
     * Get product images
     *
     * @param ProductModel $product
     * @return array
     */
    private function getProductImages(ProductModel $product): array
    {
        $images = [];
        $gallery = $product->getMediaGalleryImages();
        
        if ($gallery && $gallery->getSize() > 0) {
            foreach ($gallery as $image) {
                if ($image->getUrl()) {
                    $images[] = $image->getUrl();
                }
            }
        }
        
        // Fallback to main image
        if (empty($images)) {
            try {
                $imageUrl = $this->imageHelper->init($product, 'product_page_image_large')
                    ->setImageFile($product->getImage())
                    ->getUrl();
                if ($imageUrl) {
                    $images[] = $imageUrl;
                }
            } catch (\Exception $e) {
                $this->logger->warning('Error getting product image: ' . $e->getMessage());
            }
        }
        
        return $images;
    }
    
    /**
     * Get product brand
     *
     * @param ProductModel $product
     * @return string|null
     */
    private function getProductBrand(ProductModel $product): ?string
    {
        // Mapped brand attribute -> manufacturer -> configured default brand; first non-empty string wins
        $brandAttribute = $this->configHelper->getStructuredDataBrandAttribute();
        $candidates = [
            $brandAttribute ? $this->getAttributeValueText($product, $brandAttribute) : null,
            $this->getAttributeValueText($product, 'manufacturer'),
            $this->configHelper->getStructuredDataDefaultBrand(),
        ];

        foreach ($candidates as $brand) {
            if (is_string($brand) && trim($brand) !== '') {
                return trim($brand);
            }
        }

        return null;
    }
    
    /**
     * Get product model
     *
     * @param ProductModel $product
     * @return string|null
     */
    private function getProductModel(ProductModel $product): ?string
    {
        $modelAttribute = $this->configHelper->getStructuredDataModelAttribute();
        return $modelAttribute ? $this->getAttributeValueText($product, $modelAttribute) : null;
    }

    /**
     * Attribute value as non-empty text: option label for source (select/multiselect/boolean) attributes,
     * raw scalar for text-like ones; null when empty, multi-valued or an option id without a label
     *
     * @param ProductModel $product
     * @param string $attributeCode
     * @return string|null
     */
    private function getAttributeValueText(ProductModel $product, string $attributeCode): ?string
    {
        $value = $product->getData($attributeCode);
        if ($value === null || $value === '' || is_array($value)) {
            return null;
        }

        $attribute = $product->getResource()->getAttribute($attributeCode);
        if ($attribute && $attribute->usesSource()) {
            $value = $product->getAttributeText($attributeCode); // false/array: never fall back to the raw id
        }

        return (is_scalar($value) || $value instanceof \Stringable) && trim((string)$value) !== '' ? trim((string)$value) : null;
    }
    
    /**
     * Get product identifiers (GTIN, MPN)
     *
     * @param ProductModel $product
     * @return array
     */
    private function getProductIdentifiers(ProductModel $product): array
    {
        $identifiers = [];
        
        // GTIN mappings
        $gtinMappings = [
            'gtin8' => $this->configHelper->getStructuredDataGtin8Attribute(),
            'gtin13' => $this->configHelper->getStructuredDataGtin13Attribute(),
            'gtin14' => $this->configHelper->getStructuredDataGtin14Attribute(),
        ];
        
        foreach ($gtinMappings as $gtinType => $attribute) {
            $value = $attribute ? $this->getAttributeValueText($product, $attribute) : null;
            if ($value !== null) {
                $identifiers[$gtinType] = $value;
                break; // Only use one GTIN type
            }
        }
        
        // MPN
        $mpnAttribute = $this->configHelper->getStructuredDataMpnAttribute();
        $mpn = $mpnAttribute ? $this->getAttributeValueText($product, $mpnAttribute) : null;
        if ($mpn !== null) {
            $identifiers['mpn'] = $mpn;
        }
        
        return $identifiers;
    }
    
    /**
     * Get product offers
     *
     * @param ProductModel $product
     * @return array
     */
    private function getProductOffers(ProductModel $product): array
    {
        $typeId = $product->getTypeId();

        if (in_array($typeId, ['configurable', 'grouped', 'bundle'])) {
            return $this->getAggregateOffer($product);
        }

        return $this->getSimpleOffer($product);
    }

    /**
     * Get simple Offer for simple/virtual/downloadable products
     *
     * @param ProductModel $product
     * @return array
     */
    private function getSimpleOffer(ProductModel $product): array
    {
        $offers = [
            '@type' => 'Offer',
            'url' => $this->cleanUrl($product->getProductUrl()),
            'priceCurrency' => $this->storeManager->getStore()->getCurrentCurrencyCode(),
            'availability' => $this->getProductAvailability($product),
            'priceValidUntil' => $this->getPriceValidUntil($product)
        ];

        $price = $this->getProductPrice($product);
        if ($price === null) {
            return []; // price is required on Offer
        }
        $offers['price'] = $this->formatPrice($price);

        $condition = $this->getProductCondition($product);
        if ($condition) {
            $offers['itemCondition'] = $condition;
        }

        $seller = $this->getSellerInfo();
        if ($seller) {
            $offers['seller'] = $seller;
        }

        $validFrom = $this->getSpecialPriceValidFrom($product);
        if ($validFrom !== null) {
            $offers['validFrom'] = $validFrom;
        }

        return $this->addMerchantPolicies($offers);
    }

    /**
     * Add return policy and shipping details (when enabled and complete) to an Offer/AggregateOffer
     *
     * @param array $offers
     * @return array
     */
    private function addMerchantPolicies(array $offers): array
    {
        $returnPolicy = $this->getReturnPolicy((string)$offers['priceCurrency']);
        if ($returnPolicy !== null) {
            $offers['hasMerchantReturnPolicy'] = $returnPolicy;
        }

        $shippingDetails = $this->getShippingDetails((string)$offers['priceCurrency']);
        if ($shippingDetails !== null) {
            $offers['shippingDetails'] = $shippingDetails;
        }

        return $offers;
    }

    /**
     * Get AggregateOffer for configurable/grouped/bundle products
     *
     * @param ProductModel $product
     * @return array
     */
    private function getAggregateOffer(ProductModel $product): array
    {
        $prices = $this->getChildPrices($product);
        if (empty($prices)) {
            return []; // lowPrice is required on AggregateOffer
        }

        $offers = [
            '@type' => 'AggregateOffer',
            'url' => $this->cleanUrl($product->getProductUrl()),
            'priceCurrency' => $this->storeManager->getStore()->getCurrentCurrencyCode(),
            'availability' => $this->getProductAvailability($product),
            'offerCount' => count($prices)
        ];

        $offers['lowPrice'] = $this->formatPrice(min($prices));
        $offers['highPrice'] = $this->formatPrice(max($prices));

        $condition = $this->getProductCondition($product);
        if ($condition) {
            $offers['itemCondition'] = $condition;
        }

        $seller = $this->getSellerInfo();
        if ($seller) {
            $offers['seller'] = $seller;
        }

        return $this->addMerchantPolicies($offers);
    }

    /**
     * Get child product prices for composite products
     *
     * @param ProductModel $product
     * @return float[]
     */
    private function getChildPrices(ProductModel $product): array
    {
        $prices = [];
        $typeInstance = $product->getTypeInstance();

        try {
            if ($product->getTypeId() === 'configurable') {
                $children = $typeInstance->getUsedProducts($product);
            } elseif ($product->getTypeId() === 'grouped') {
                $children = $typeInstance->getAssociatedProducts($product);
            } else {
                // Bundle - use price model
                $priceModel = $product->getPriceModel();
                // Bundle totals already apply tax per selection: only convert currency (no second tax pass)
                $store = $this->storeManager->getStore();
                $includeTax = (int)$this->scopeConfig->getValue(
                    'tax/display/type',
                    \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
                    $store->getId()
                ) !== 1;
                [$minPrice, $maxPrice] = $priceModel->getTotalPrices($product, null, $includeTax, false);
                return array_filter([
                    (float)$this->priceCurrency->convert((float)$minPrice, $store),
                    (float)$this->priceCurrency->convert((float)$maxPrice, $store),
                ]);
            }

            foreach ($children as $child) {
                $childPrice = (float)$child->getFinalPrice();
                if ($childPrice > 0) {
                    $prices[] = $this->getPriceWithTax($product, $childPrice);
                }
            }
        } catch (\Exception $e) {
            $this->logger->warning('Error getting child prices: ' . $e->getMessage());
            $fallbackPrice = $this->getProductPrice($product);
            if ($fallbackPrice !== null) {
                $prices[] = $fallbackPrice;
            }
        }

        return $prices;
    }
    
    /**
     * Get product price (respects store tax display setting)
     *
     * @param ProductModel $product
     * @return float|null
     */
    private function getProductPrice(ProductModel $product): ?float
    {
        try {
            $price = $product->getFinalPrice();

            if ($price === null) {
                return null;
            }

            return $this->getPriceWithTax($product, (float)$price);
        } catch (\Exception $e) {
            $this->logger->warning('Error getting product price: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Get price with or without tax based on store display setting
     *
     * @param ProductModel $product
     * @param float $price
     * @return float
     */
    private function getPriceWithTax(ProductModel $product, float $price): float
    {
        $taxDisplayType = (int)$this->scopeConfig->getValue(
            'tax/display/type',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
            $this->storeManager->getStore()->getId()
        );

        // 1 = excluding tax, 2 = including tax, 3 = both (use including)
        $includeTax = $taxDisplayType !== 1;

        $result = $this->catalogHelper->getTaxPrice(
            $product,
            $price,
            $includeTax,
            null,
            null,
            null,
            $this->storeManager->getStore(),
            null,
            true
        );

        // Final/tax prices are in base currency; publish them in the currency printed next to them (CR-11)
        return (float)$this->priceCurrency->convert((float)$result, $this->storeManager->getStore());
    }
    
    /**
     * Get product availability
     *
     * @param ProductModel $product
     * @return string
     */
    private function getProductAvailability(ProductModel $product): string
    {
        try {
            $stockItem = $this->stockRegistry->getStockItem($product->getId());

            if ($stockItem->getIsInStock()) {
                return 'https://schema.org/InStock';
            }
        } catch (\Exception $e) {
            $this->logger->warning('Error getting stock status: ' . $e->getMessage());
        }

        return 'https://schema.org/OutOfStock';
    }
    
    /**
     * Get product condition
     *
     * @param ProductModel $product
     * @return string|null
     */
    private function getProductCondition(ProductModel $product): ?string
    {
        $conditionAttribute = $this->configHelper->getStructuredDataConditionAttribute();
        if ($conditionAttribute && $product->getData($conditionAttribute)) {
            $condition = $product->getAttributeText($conditionAttribute);
            if (!$condition) {
                $condition = $product->getData($conditionAttribute);
            }
            
            // Map to schema.org values
            $conditionMap = [
                'new' => 'https://schema.org/NewCondition',
                'used' => 'https://schema.org/UsedCondition',
                'refurbished' => 'https://schema.org/RefurbishedCondition',
                'damaged' => 'https://schema.org/DamagedCondition'
            ];
            
            $conditionLower = strtolower((string)$condition);
            return $conditionMap[$conditionLower] ?? null;
        }
        
        // Default to new condition
        return 'https://schema.org/NewCondition';
    }
    
    /**
     * Get price valid until date
     *
     * @param ProductModel $product
     * @return string
     */
    private function getPriceValidUntil(ProductModel $product): string
    {
        // Special price end date only while that special price exists and has not ended
        $specialToDate = (string)$product->getSpecialToDate();
        if ($specialToDate !== '' && (string)$product->getSpecialPrice() !== '') {
            try {
                $date = (new \DateTime($specialToDate))->format('Y-m-d');
                if ($date >= $this->getStoreToday()->format('Y-m-d')) {
                    return $date;
                }
            } catch (\Exception $e) {
                // fall through to default
            }
        }

        return $this->getStoreToday()->modify('+1 year')->format('Y-m-d');
    }

    /**
     * Today in the current store's timezone (special price dates are store-local days, as in
     * TimezoneInterface::isScopeDateInInterval())
     *
     * @return \DateTime
     */
    private function getStoreToday(): \DateTime
    {
        return $this->timezone->scopeDate($this->getStoreId());
    }

    /**
     * Get special price start date, only while a special price period is currently active
     *
     * @param ProductModel $product
     * @return string|null
     */
    private function getSpecialPriceValidFrom(ProductModel $product): ?string
    {
        $from = (string)$product->getSpecialFromDate();
        if ($from === '' || (string)$product->getSpecialPrice() === '') {
            return null;
        }

        try {
            // Same clock and Y-m-d granularity as getPriceValidUntil()
            $today = $this->getStoreToday()->format('Y-m-d');
            $fromDay = (new \DateTime($from))->format('Y-m-d');
            $to = (string)$product->getSpecialToDate();
            if ($fromDay > $today || ($to !== '' && (new \DateTime($to))->format('Y-m-d') < $today)) {
                return null;
            }
            return $fromDay;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Get MerchantReturnPolicy node (Google merchant listings), null when disabled or incomplete
     *
     * @param string $currency Offer priceCurrency
     * @return array|null
     */
    private function getReturnPolicy(string $currency): ?array
    {
        if (!$this->configHelper->getValue(Config::XML_PATH_RETURN_POLICY_ENABLED)) {
            return null;
        }

        $countries = $this->getCountryCodes(Config::XML_PATH_RETURN_POLICY_COUNTRY);
        $category = (string)$this->configHelper->getValue(Config::XML_PATH_RETURN_POLICY_CATEGORY);
        $categories = ['MerchantReturnFiniteReturnWindow', 'MerchantReturnNotPermitted', 'MerchantReturnUnlimitedWindow'];
        if ($countries === [] || !in_array($category, $categories, true)) {
            return null;
        }

        $policy = [
            '@type' => 'MerchantReturnPolicy',
            'applicableCountry' => count($countries) === 1 ? $countries[0] : $countries,
            'returnPolicyCategory' => 'https://schema.org/' . $category,
        ];
        if ($category === 'MerchantReturnNotPermitted') {
            return $policy;
        }

        if ($category === 'MerchantReturnFiniteReturnWindow') {
            $days = (int)$this->configHelper->getValue(Config::XML_PATH_RETURN_POLICY_DAYS);
            if ($days <= 0) {
                return null;
            }
            $policy['merchantReturnDays'] = $days;
        }

        $method = (string)$this->configHelper->getValue(Config::XML_PATH_RETURN_POLICY_METHOD);
        if (in_array($method, ['ReturnByMail', 'ReturnInStore', 'ReturnAtKiosk'], true)) {
            $policy['returnMethod'] = 'https://schema.org/' . $method;
        }
        $fees = (string)$this->configHelper->getValue(Config::XML_PATH_RETURN_POLICY_FEES);
        if ($fees === 'ReturnShippingFees') {
            // ReturnShippingFees requires returnShippingFeesAmount; without a valid amount the customer pays
            $amount = trim((string)$this->configHelper->getValue(Config::XML_PATH_RETURN_POLICY_FEES_AMOUNT));
            if ($currency !== '' && is_numeric($amount) && (float)$amount >= 0) {
                $policy['returnShippingFeesAmount'] = [
                    '@type' => 'MonetaryAmount',
                    // Entered in base currency like catalog prices; published in the offer currency (CR-11)
                    'value' => $this->formatPrice(
                        (float)$this->priceCurrency->convert((float)$amount, $this->storeManager->getStore())
                    ),
                    'currency' => $currency,
                ];
            } else {
                $fees = 'ReturnFeesCustomerResponsibility';
            }
        }
        if (in_array($fees, ['FreeReturn', 'ReturnFeesCustomerResponsibility', 'ReturnShippingFees'], true)) {
            $policy['returnFees'] = 'https://schema.org/' . $fees;
        }

        return $policy;
    }

    /**
     * Get OfferShippingDetails node (Google merchant listings), null when disabled or incomplete
     *
     * @param string $currency Offer priceCurrency
     * @return array|null
     */
    private function getShippingDetails(string $currency): ?array
    {
        if ($currency === '' || !$this->configHelper->getValue(Config::XML_PATH_SHIPPING_DETAILS_ENABLED)) {
            return null;
        }

        $rate = trim((string)$this->configHelper->getValue(Config::XML_PATH_SHIPPING_RATE));
        $countries = $this->getCountryCodes(Config::XML_PATH_SHIPPING_COUNTRY);
        if (!is_numeric($rate) || (float)$rate < 0 || $countries === []) {
            return null;
        }

        $destinations = array_map(
            static fn (string $country): array => ['@type' => 'DefinedRegion', 'addressCountry' => $country],
            $countries
        );

        $details = [
            '@type' => 'OfferShippingDetails',
            'shippingRate' => [
                '@type' => 'MonetaryAmount',
                // Entered in base currency like catalog prices; published in the offer currency (CR-11)
                'value' => $this->formatPrice(
                    (float)$this->priceCurrency->convert((float)$rate, $this->storeManager->getStore())
                ),
                'currency' => $currency,
            ],
            'shippingDestination' => count($destinations) === 1 ? $destinations[0] : $destinations,
        ];

        // deliveryTime is only recommended: added when all four day values are valid and min <= max
        $days = array_map(
            fn (string $path): string => trim((string)$this->configHelper->getValue($path)),
            [
                Config::XML_PATH_SHIPPING_HANDLING_MIN,
                Config::XML_PATH_SHIPPING_HANDLING_MAX,
                Config::XML_PATH_SHIPPING_TRANSIT_MIN,
                Config::XML_PATH_SHIPPING_TRANSIT_MAX,
            ]
        );
        if (count(array_filter($days, 'ctype_digit')) !== 4) {
            return $details;
        }
        [$handlingMin, $handlingMax, $transitMin, $transitMax] = array_map('intval', $days);
        if ($handlingMin > $handlingMax || $transitMin > $transitMax) {
            return $details;
        }

        $details['deliveryTime'] = [
            '@type' => 'ShippingDeliveryTime',
            'handlingTime' => [
                '@type' => 'QuantitativeValue',
                'minValue' => $handlingMin,
                'maxValue' => $handlingMax,
                'unitCode' => 'DAY',
            ],
            'transitTime' => [
                '@type' => 'QuantitativeValue',
                'minValue' => $transitMin,
                'maxValue' => $transitMax,
                'unitCode' => 'DAY',
            ],
        ];

        return $details;
    }

    /**
     * Parse a comma-separated ISO 3166-1 alpha-2 country list from config; invalid entries are dropped
     *
     * @param string $path
     * @return string[]
     */
    private function getCountryCodes(string $path): array
    {
        $codes = array_map(
            static fn (string $code): string => strtoupper(trim($code)),
            explode(',', (string)$this->configHelper->getValue($path))
        );

        return array_values(array_unique(array_filter(
            $codes,
            static fn (string $code): bool => preg_match('/^[A-Z]{2}$/', $code) === 1
        )));
    }
    
    /**
     * Get seller information (the store's Organization)
     *
     * @return array|null
     */
    private function getSellerInfo(): ?array
    {
        if (trim($this->configHelper->getStructuredDataOrganizationName()) === '') {
            return null;
        }

        return $this->organizationData->getData();
    }
    
    /**
     * Get product reviews and ratings
     *
     * @param ProductModel $product
     * @return array
     */
    private function getProductReviews(ProductModel $product): array
    {
        // Magento_Review is optional (composer suggest): no review data when it is disabled
        if (!$this->moduleManager->isEnabled('Magento_Review')) {
            return [];
        }

        $data = [];
        
        try {
            $storeId = (int)$this->storeManager->getStore()->getId();

            // Summary is read explicitly as scalars (head renders before the body's ReviewRenderer, CR-10)
            $this->appendSummaryData->execute($product, $storeId, ReviewModel::ENTITY_PRODUCT_CODE);
            $reviewsCount = (int)$product->getData('reviews_count');
            $ratingPercent = (float)$product->getData('rating_summary');

            if ($reviewsCount <= 0) {
                return [];
            }

            if ($ratingPercent > 0) {
                $data['aggregateRating'] = [
                    '@type' => 'AggregateRating',
                    'ratingValue' => round($ratingPercent / 20, 1),
                    'bestRating' => '5',
                    'worstRating' => '1',
                    'ratingCount' => $reviewsCount,
                    'reviewCount' => $reviewsCount
                ];
            }

            // Add individual reviews if enabled
            if ($this->configHelper->isStructuredDataReviewsEnabled()) {
                // Bounded: 5 newest approved reviews, votes loaded for those only (CR-27)
                $reviewCollection = $this->reviewFactory->create()->getCollection()
                    ->addStoreFilter($storeId)
                    ->addEntityFilter(ReviewModel::ENTITY_PRODUCT_CODE, $product->getId())
                    ->addStatusFilter(ReviewModel::STATUS_APPROVED)
                    ->setDateOrder()
                    ->setPageSize(5)
                    ->setCurPage(1)
                    ->addRateVotes();

                $reviews = [];
                foreach ($reviewCollection as $review) {
                    $authorName = $this->cleanTextField($review->getNickname());
                    if ($authorName === '') {
                        continue;
                    }
                    $reviewData = [
                        '@type' => 'Review',
                        'author' => [
                            '@type' => 'Person',
                            'name' => $authorName
                        ],
                        'datePublished' => $this->formatDate((string)$review->getCreatedAt()),
                    ];
                    $reviewBody = trim($this->cleanTextField($review->getDetail()));
                    if ($reviewBody !== '') {
                        $reviewData['reviewBody'] = $reviewBody;
                    }

                    $total = 0;
                    $count = 0;
                    foreach ($review->getRatingVotes() ?: [] as $vote) {
                        $total += (int)$vote->getValue();
                        $count++;
                    }
                    if ($count === 0) {
                        continue; // reviewRating is required on Review
                    }
                    $reviewData['reviewRating'] = [
                        '@type' => 'Rating',
                        'ratingValue' => round($total / $count, 1),
                        'bestRating' => '5',
                        'worstRating' => '1'
                    ];

                    $reviews[] = $reviewData;
                }

                if (!empty($reviews)) {
                    $data['review'] = $reviews;
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Error getting product reviews: ' . $e->getMessage());
            return [];
        }
        
        return $data;
    }
    
    /**
     * Get custom attributes based on mapping
     *
     * @param ProductModel $product
     * @return array
     */
    private function getCustomAttributes(ProductModel $product): array
    {
        $customAttributes = [];
        
        // Get attribute mappings from configuration
        $mappings = $this->attributeMapper->getMappings('product');
        
        foreach ($mappings as $schemaField => $magentoAttribute) {
            // Use AttributeMapper to get properly processed value
            $value = $this->attributeMapper->getMappedValue('product', $schemaField, $product);
            
            // Check if value is valid (not null, not empty, not an error)
            if ($value !== null && $value !== '' && !$this->isErrorValue($value)) {
                // Special handling for specific fields
                $formattedValue = $this->formatSchemaFieldValue($schemaField, $value, $product);
                
                // Only add if formatted value is not null
                if ($formattedValue !== null) {
                    $customAttributes[$schemaField] = $formattedValue;
                }
            }
        }
        
        return $customAttributes;
    }
    
    /**
     * Check if value is an error value
     *
     * @param mixed $value
     * @return bool
     */
    private function isErrorValue($value): bool
    {
        if (is_string($value) && str_starts_with($value, 'Error_')) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Format schema field value based on field type
     *
     * @param string $schemaField
     * @param mixed $value
     * @param ProductModel $product
     * @return mixed
     */
    private function formatSchemaFieldValue(string $schemaField, $value, ProductModel $product)
    {
        switch ($schemaField) {
            case 'category':
                return $this->formatProductCategories($product);
                
            case 'keywords':
                return $this->formatProductKeywords($value);

            case 'weight':
                // schema.org weight is a QuantitativeValue; product weight is in the store's weight unit
                if (!is_numeric($value) || (float)$value <= 0) {
                    return null;
                }
                return [
                    '@type' => 'QuantitativeValue',
                    'value' => (float)$value,
                    'unitCode' => $this->getWeightUnit(),
                ];

            case 'width':
            case 'height':
            case 'depth':
                return null; // ponytail: Magento has no dimension unit, a unitless QuantitativeValue is invalid
                
            case 'manufacturer':
                // Avoid duplicate manufacturer if we already have brand
                $brand = $this->getProductBrand($product);
                if ($brand && mb_strtolower($brand, 'UTF-8') === mb_strtolower((string)$value, 'UTF-8')) {
                    return null; // Skip if it's the same as brand
                }
                return $value;
                
            default:
                return $value;
        }
    }
    
    /**
     * Format product categories for schema.org
     *
     * @param ProductModel $product
     * @return string|null
     */
    private function formatProductCategories(ProductModel $product): ?string
    {
        try {
            // Get category IDs first
            $categoryIds = $product->getCategoryIds();
            if (empty($categoryIds)) {
                return null;
            }
            
            // One query for all assigned categories (CR-29)
            $collection = $this->categoryCollectionFactory->create()
                ->setStoreId($this->storeManager->getStore()->getId())
                ->addIdFilter($categoryIds)
                ->addNameToResult()
                ->addIsActiveFilter();

            $categories = [];
            foreach ($collection as $category) {
                if ($category->getName()) {
                    $categories[] = $category->getName();
                }
            }
            
            // Unordered set of assigned categories (Text, not array), so no " > " path
            return !empty($categories) ? implode(', ', $categories) : null;
        } catch (\Exception $e) {
            $this->logger->warning('Error getting product categories: ' . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Format product keywords for schema.org
     *
     * @param mixed $value
     * @return string|null
     */
    private function formatProductKeywords($value): ?string
    {
        if (!$value) {
            return null;
        }
        
        if (is_array($value)) {
            $value = implode(',', array_filter($value, 'is_scalar'));
        }
        
        if (is_string($value)) {
            // Split on list separators only (multi-word keywords such as "Red Wine" stay intact)
            $keywords = array_unique(array_filter(array_map('trim', preg_split('/[,;]/', $value)), 'strlen'));
            return !empty($keywords) ? implode(', ', $keywords) : null;
        }
        
        return null;
    }
    
    /**
     * Get additional properties (weight, dimensions, etc.)
     *
     * @param ProductModel $product
     * @return array
     */
    private function getAdditionalProperties(ProductModel $product): array
    {
        $properties = [];
        
        // Don't add weight here as it's already added as a main field in custom attributes
        // This prevents duplication in the JSON-LD output
        
        // Add other mapped properties
        $propertyMappings = $this->configHelper->getStructuredDataPropertyMappings();
        foreach ($propertyMappings as $propertyName => $attribute) {
            if ($propertyName !== '' && $product->getData($attribute)) {
                // Option attributes publish their label only (false for an unknown id: never the raw id)
                $eavAttribute = $product->getResource()->getAttribute($attribute);
                $value = $eavAttribute && $eavAttribute->usesSource()
                    ? $product->getAttributeText($attribute)
                    : $product->getData($attribute);
                // Multiselect text is an array: publish as one Text value
                $value = is_array($value)
                    ? implode(', ', array_filter(array_map('strval', $value), 'strlen'))
                    : trim((string)$value);

                if ($value !== '') {
                    $properties[] = [
                        '@type' => 'PropertyValue',
                        'name' => $propertyName,
                        'value' => $value
                    ];
                }
            }
        }
        
        return $properties;
    }
    
    /**
     * Get weight unit from configuration
     *
     * @return string
     */
    private function getWeightUnit(): string
    {
        $unit = $this->scopeConfig->getValue(
            'general/locale/weight_unit',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
            $this->storeManager->getStore()->getId()
        );
        
        // Map Magento units to schema.org units
        $unitMap = [
            'kgs' => 'KGM',  // Kilogramy
            'lbs' => 'LBR'   // Funty
        ];
        
        // Default to KGM (kilograms) for European stores
        return $unitMap[$unit] ?? 'KGM';
    }
}
