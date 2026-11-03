<?php
/**
 * @package     Defox_SEOSuite
 * @author      Marcin Lisiecki
 * @copyright   Copyright (c) 2025 deFox
 * @license     GNU General Public License v3.0
 */

declare(strict_types=1);

namespace Defox\SEOSuite\Helper;


use Defox\SEOSuite\Model\Config\Source\ProductCanonicalType;
use Defox\SEOSuite\Model\Config\Source\PaginationCanonicalType;
use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\UrlInterface;
use Magento\MediaStorage\Model\File\Validator\AvailablePath;
use Magento\Sitemap\Model\SitemapConfigReaderInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Config helper class for SEO Suite
 */
class Config extends AbstractHelper
{
    /**
     * @var DirectoryList
     */
    private DirectoryList $directoryList;

    /**
     * @var StoreManagerInterface
     */
    private StoreManagerInterface $storeManager;

    /**
     * @var AvailablePath
     */
    private AvailablePath $pathValidator;

    /**
     * @var SitemapConfigReaderInterface
     */
    private SitemapConfigReaderInterface $sitemapConfigReader;

    /**
     * Constructor
     *
     * @param Context $context
     * @param DirectoryList $directoryList
     * @param StoreManagerInterface $storeManager
     * @param AvailablePath $pathValidator
     * @param SitemapConfigReaderInterface $sitemapConfigReader
     */
    public function __construct(
        Context $context,
        DirectoryList $directoryList,
        StoreManagerInterface $storeManager,
        AvailablePath $pathValidator,
        SitemapConfigReaderInterface $sitemapConfigReader
    ) {
        parent::__construct($context);
        $this->directoryList = $directoryList;
        $this->storeManager = $storeManager;
        $this->pathValidator = $pathValidator;
        $this->sitemapConfigReader = $sitemapConfigReader;
    }
    /**
     * Module enable/disable config path
     */
    public const XML_PATH_ENABLED = 'defox_seosuite/general/enabled';
    
    /**
     * Meta tags config paths
     */
    public const XML_PATH_META_TAGS_ENABLED = 'defox_seosuite/meta_tags/enabled';
    public const XML_PATH_META_TAGS_OVERRIDE_EXISTING = 'defox_seosuite/meta_tags/override_existing';
    public const XML_PATH_META_TAGS_OVERRIDE_ONLY_EMPTY = 'defox_seosuite/meta_tags/override_only_empty';
    public const XML_PATH_META_TAGS_OVERRIDE_TITLE = 'defox_seosuite/meta_tags/override_title';
    public const XML_PATH_META_TAGS_OVERRIDE_DESCRIPTION = 'defox_seosuite/meta_tags/override_description';
    public const XML_PATH_META_TAGS_OVERRIDE_KEYWORDS = 'defox_seosuite/meta_tags/override_keywords';
    /**
     * Config path constants
     */
    public const XML_PATH_CANONICAL_ENABLED = 'defox_seosuite/canonical/enabled';
    public const XML_PATH_PRODUCT_CANONICAL_ENABLED = 'defox_seosuite/canonical/product_enabled';
    public const XML_PATH_PRODUCT_CANONICAL_URL_TYPE = 'defox_seosuite/canonical/product_url_type';
    public const XML_PATH_CATEGORY_CANONICAL_ENABLED = 'defox_seosuite/canonical/category_enabled';
    public const XML_PATH_CATEGORY_PAGINATION_CANONICAL = 'defox_seosuite/canonical/category_pagination';
    public const XML_PATH_CATEGORY_PAGINATION_TYPE = 'defox_seosuite/canonical/category_pagination_type';
    public const XML_PATH_CATEGORY_FILTERS_CANONICAL = 'defox_seosuite/canonical/category_filters';
    public const XML_PATH_EXCLUDE_PATTERNS = 'defox_seosuite/canonical/exclude_patterns';
    public const XML_PATH_EXCLUDE_INVISIBLE_PRODUCTS = 'defox_seosuite/canonical/exclude_invisible_products';
    public const XML_PATH_EXCLUDE_ANCHOR_CATEGORIES = 'defox_seosuite/canonical/exclude_anchor_categories';
    public const XML_PATH_EXCLUDED_PRODUCT_TYPES = 'defox_seosuite/canonical/excluded_product_types';
    public const XML_PATH_EXCLUDED_CMS_PAGES = 'defox_seosuite/canonical/excluded_cms_pages';

    // ponytail: renamed from friendly_urls/enabled so stale pre-U6 rows (saved =1 while the feature was inert) don't switch it on
    public const XML_PATH_FRIENDLY_URLS_ENABLED = 'defox_seosuite/friendly_urls/active';
    public const XML_PATH_FRIENDLY_URL_PATTERN = 'defox_seosuite/friendly_urls/url_pattern';
    public const XML_PATH_VALUE_SEPARATOR = 'defox_seosuite/friendly_urls/value_separator';

    // Structured Data paths
    public const XML_PATH_STRUCTURED_DATA_ENABLED = 'defox_seosuite/structured_data/enabled';
    public const XML_PATH_STRUCTURED_DATA_PRODUCT_ENABLED = 'defox_seosuite/structured_data/product_enabled';
    public const XML_PATH_STRUCTURED_DATA_CATEGORY_ENABLED = 'defox_seosuite/structured_data/category_enabled';
    public const XML_PATH_STRUCTURED_DATA_ORGANIZATION_ENABLED = 'defox_seosuite/structured_data/organization_enabled';
    public const XML_PATH_STRUCTURED_DATA_WEBSITE_ENABLED = 'defox_seosuite/structured_data/website_enabled';
    public const XML_PATH_STRUCTURED_DATA_REVIEWS_ENABLED = 'defox_seosuite/structured_data/reviews_enabled';
    public const XML_PATH_STRUCTURED_DATA_CATEGORY_PRODUCT_LIST_ENABLED = 'defox_seosuite/structured_data/category_product_list_enabled';
    public const XML_PATH_STRUCTURED_DATA_CATEGORY_PRODUCT_LIMIT = 'defox_seosuite/structured_data/category_product_limit';

    // Organization data paths
    public const XML_PATH_STRUCTURED_DATA_ORGANIZATION_NAME = 'defox_seosuite/structured_data_organization/name';
    public const XML_PATH_STRUCTURED_DATA_ORGANIZATION_TYPE = 'defox_seosuite/structured_data_organization/type';
    public const XML_PATH_STRUCTURED_DATA_ORGANIZATION_LOGO = 'defox_seosuite/structured_data_organization/logo';
    public const XML_PATH_STRUCTURED_DATA_ORGANIZATION_DESCRIPTION = 'defox_seosuite/structured_data_organization/description';
    public const XML_PATH_STRUCTURED_DATA_ORGANIZATION_FOUNDING_DATE = 'defox_seosuite/structured_data_organization/founding_date';
    public const XML_PATH_STRUCTURED_DATA_ORGANIZATION_TAX_ID = 'defox_seosuite/structured_data_organization/tax_id';
    public const XML_PATH_STRUCTURED_DATA_ORGANIZATION_VAT_ID = 'defox_seosuite/structured_data_organization/vat_id';

    // Organization address paths
    public const XML_PATH_STRUCTURED_DATA_ORGANIZATION_STREET_ADDRESS = 'defox_seosuite/structured_data_organization/street_address';
    public const XML_PATH_STRUCTURED_DATA_ORGANIZATION_LOCALITY = 'defox_seosuite/structured_data_organization/locality';
    public const XML_PATH_STRUCTURED_DATA_ORGANIZATION_REGION = 'defox_seosuite/structured_data_organization/region';
    public const XML_PATH_STRUCTURED_DATA_ORGANIZATION_POSTAL_CODE = 'defox_seosuite/structured_data_organization/postal_code';
    public const XML_PATH_STRUCTURED_DATA_ORGANIZATION_COUNTRY = 'defox_seosuite/structured_data_organization/country';

    // Organization contact paths
    public const XML_PATH_STRUCTURED_DATA_ORGANIZATION_CUSTOMER_SERVICE_PHONE = 'defox_seosuite/structured_data_organization/customer_service_phone';
    public const XML_PATH_STRUCTURED_DATA_ORGANIZATION_CUSTOMER_SERVICE_EMAIL = 'defox_seosuite/structured_data_organization/customer_service_email';
    public const XML_PATH_STRUCTURED_DATA_ORGANIZATION_TECH_SUPPORT_PHONE = 'defox_seosuite/structured_data_organization/tech_support_phone';
    public const XML_PATH_STRUCTURED_DATA_ORGANIZATION_TECH_SUPPORT_EMAIL = 'defox_seosuite/structured_data_organization/tech_support_email';
    public const XML_PATH_STRUCTURED_DATA_ORGANIZATION_SALES_PHONE = 'defox_seosuite/structured_data_organization/sales_phone';
    public const XML_PATH_STRUCTURED_DATA_ORGANIZATION_SALES_EMAIL = 'defox_seosuite/structured_data_organization/sales_email';
    public const XML_PATH_STRUCTURED_DATA_ORGANIZATION_AVAILABLE_LANGUAGES = 'defox_seosuite/structured_data_organization/available_languages';
    public const XML_PATH_STRUCTURED_DATA_ORGANIZATION_AREA_SERVED = 'defox_seosuite/structured_data_organization/area_served';

    // Organization social profiles paths
    public const XML_PATH_STRUCTURED_DATA_ORGANIZATION_FACEBOOK = 'defox_seosuite/structured_data_organization/facebook';
    public const XML_PATH_STRUCTURED_DATA_ORGANIZATION_TWITTER = 'defox_seosuite/structured_data_organization/twitter';
    public const XML_PATH_STRUCTURED_DATA_ORGANIZATION_INSTAGRAM = 'defox_seosuite/structured_data_organization/instagram';
    public const XML_PATH_STRUCTURED_DATA_ORGANIZATION_YOUTUBE = 'defox_seosuite/structured_data_organization/youtube';
    public const XML_PATH_STRUCTURED_DATA_ORGANIZATION_LINKEDIN = 'defox_seosuite/structured_data_organization/linkedin';
    public const XML_PATH_STRUCTURED_DATA_ORGANIZATION_PINTEREST = 'defox_seosuite/structured_data_organization/pinterest';
    public const XML_PATH_STRUCTURED_DATA_ORGANIZATION_TIKTOK = 'defox_seosuite/structured_data_organization/tiktok';

    // Website data paths
    public const XML_PATH_STRUCTURED_DATA_WEBSITE_ALTERNATE_NAME = 'defox_seosuite/structured_data_website/alternate_name';

    // Product attribute mappings
    public const XML_PATH_STRUCTURED_DATA_BRAND_ATTRIBUTE = 'defox_seosuite/structured_data_product/brand_attribute';
    public const XML_PATH_STRUCTURED_DATA_MODEL_ATTRIBUTE = 'defox_seosuite/structured_data_product/model_attribute';
    public const XML_PATH_STRUCTURED_DATA_GTIN8_ATTRIBUTE = 'defox_seosuite/structured_data_product/gtin8_attribute';
    public const XML_PATH_STRUCTURED_DATA_GTIN13_ATTRIBUTE = 'defox_seosuite/structured_data_product/gtin13_attribute';
    public const XML_PATH_STRUCTURED_DATA_GTIN14_ATTRIBUTE = 'defox_seosuite/structured_data_product/gtin14_attribute';
    public const XML_PATH_STRUCTURED_DATA_MPN_ATTRIBUTE = 'defox_seosuite/structured_data_product/mpn_attribute';

    public const XML_PATH_STRUCTURED_DATA_CONDITION_ATTRIBUTE = 'defox_seosuite/structured_data_product/condition_attribute';
    public const XML_PATH_STRUCTURED_DATA_PROPERTY_MAPPINGS = 'defox_seosuite/structured_data_product/property_mappings';
    public const XML_PATH_STRUCTURED_DATA_ATTRIBUTE_MAPPINGS = 'defox_seosuite/structured_data_product/attribute_mappings';
    public const XML_PATH_STRUCTURED_DATA_DEFAULT_BRAND = 'defox_seosuite/structured_data_product/default_brand';

    // Offer return policy / shipping details (Google merchant listings)
    public const XML_PATH_RETURN_POLICY_ENABLED = 'defox_seosuite/structured_data_return_policy/enabled';
    public const XML_PATH_RETURN_POLICY_COUNTRY = 'defox_seosuite/structured_data_return_policy/applicable_country';
    public const XML_PATH_RETURN_POLICY_CATEGORY = 'defox_seosuite/structured_data_return_policy/category';
    public const XML_PATH_RETURN_POLICY_DAYS = 'defox_seosuite/structured_data_return_policy/days';
    public const XML_PATH_RETURN_POLICY_METHOD = 'defox_seosuite/structured_data_return_policy/method';
    public const XML_PATH_RETURN_POLICY_FEES = 'defox_seosuite/structured_data_return_policy/fees';
    public const XML_PATH_RETURN_POLICY_FEES_AMOUNT = 'defox_seosuite/structured_data_return_policy/fees_amount';
    public const XML_PATH_SHIPPING_DETAILS_ENABLED = 'defox_seosuite/structured_data_shipping/enabled';
    public const XML_PATH_SHIPPING_RATE = 'defox_seosuite/structured_data_shipping/rate';
    public const XML_PATH_SHIPPING_COUNTRY = 'defox_seosuite/structured_data_shipping/destination_country';
    public const XML_PATH_SHIPPING_HANDLING_MIN = 'defox_seosuite/structured_data_shipping/handling_min_days';
    public const XML_PATH_SHIPPING_HANDLING_MAX = 'defox_seosuite/structured_data_shipping/handling_max_days';
    public const XML_PATH_SHIPPING_TRANSIT_MIN = 'defox_seosuite/structured_data_shipping/transit_min_days';
    public const XML_PATH_SHIPPING_TRANSIT_MAX = 'defox_seosuite/structured_data_shipping/transit_max_days';

    // Sitemap configuration paths
    public const XML_PATH_SITEMAP_ENABLED = 'defox_seosuite/sitemap/enabled';
    public const XML_PATH_SITEMAP_PATH = 'defox_seosuite/sitemap/path';
    public const DEFAULT_SITEMAP_PATH = 'media/maps/';
    public const XML_PATH_SITEMAP_ENABLE_COMPRESSION = 'defox_seosuite/sitemap/enable_compression';
    public const XML_PATH_SITEMAP_GENERATION_CRON_EXPR = 'defox_seosuite/sitemap/generation_cron_expr';
    public const XML_PATH_SITEMAP_ENABLE_HREFLANG = 'defox_seosuite/sitemap/enable_hreflang';
    public const XML_PATH_SITEMAP_PRODUCT_INCLUDE_IMAGES = 'defox_seosuite/sitemap/product/include_images';


    /**
     * Default values
     */
    public const DEFAULT_VALUE_SEPARATOR = '-';


    /**
     * Check if SEO Suite module is enabled
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Check if meta tags are enabled
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isMetaTagsEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_META_TAGS_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Check if meta tags should override existing values
     *
     * @param int|null $storeId
     * @return bool
     */
    public function shouldOverrideExistingMetaTags(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_META_TAGS_OVERRIDE_EXISTING,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Check if meta tags should override only empty values
     *
     * @param int|null $storeId
     * @return bool
     */
    public function shouldOverrideOnlyEmptyMetaTags(?int $storeId = null): bool
    {
        if (!$this->shouldOverrideExistingMetaTags($storeId)) {
            return true; // Jeśli override jest wyłączony, zawsze nadpisuj tylko puste
        }
        
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_META_TAGS_OVERRIDE_ONLY_EMPTY,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Check if meta title should be overridden
     *
     * @param int|null $storeId
     * @return bool
     */
    public function shouldOverrideMetaTitle(?int $storeId = null): bool
    {
        if (!$this->shouldOverrideExistingMetaTags($storeId)) {
            return false;
        }
        
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_META_TAGS_OVERRIDE_TITLE,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Check if meta description should be overridden
     *
     * @param int|null $storeId
     * @return bool
     */
    public function shouldOverrideMetaDescription(?int $storeId = null): bool
    {
        if (!$this->shouldOverrideExistingMetaTags($storeId)) {
            return false;
        }
        
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_META_TAGS_OVERRIDE_DESCRIPTION,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Check if meta keywords should be overridden
     *
     * @param int|null $storeId
     * @return bool
     */
    public function shouldOverrideMetaKeywords(?int $storeId = null): bool
    {
        if (!$this->shouldOverrideExistingMetaTags($storeId)) {
            return false;
        }
        
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_META_TAGS_OVERRIDE_KEYWORDS,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Check if structured data is enabled
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isStructuredDataEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_STRUCTURED_DATA_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Check if product structured data is enabled
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isStructuredDataProductEnabled(?int $storeId = null): bool
    {
        if (!$this->isStructuredDataEnabled($storeId)) {
            return false;
        }

        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_STRUCTURED_DATA_PRODUCT_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Check if category structured data is enabled
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isStructuredDataCategoryEnabled(?int $storeId = null): bool
    {
        if (!$this->isStructuredDataEnabled($storeId)) {
            return false;
        }

        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_STRUCTURED_DATA_CATEGORY_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Check if organization structured data is enabled
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isStructuredDataOrganizationEnabled(?int $storeId = null): bool
    {
        if (!$this->isStructuredDataEnabled($storeId)) {
            return false;
        }

        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_STRUCTURED_DATA_ORGANIZATION_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Check if website structured data is enabled
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isStructuredDataWebsiteEnabled(?int $storeId = null): bool
    {
        if (!$this->isStructuredDataEnabled($storeId)) {
            return false;
        }

        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_STRUCTURED_DATA_WEBSITE_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Check if reviews in structured data are enabled
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isStructuredDataReviewsEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_STRUCTURED_DATA_REVIEWS_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Check if category product list in structured data is enabled
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isStructuredDataCategoryProductListEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_STRUCTURED_DATA_CATEGORY_PRODUCT_LIST_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Get category product limit for structured data
     *
     * @param int|null $storeId
     * @return int
     */
    public function getStructuredDataCategoryProductLimit(?int $storeId = null): int
    {
        return (int)$this->scopeConfig->getValue(
            self::XML_PATH_STRUCTURED_DATA_CATEGORY_PRODUCT_LIMIT,
            ScopeInterface::SCOPE_STORE,
            $storeId
        ) ?: 20;
    }

    /**
     * Get organization name
     *
     * @param int|null $storeId
     * @return string
     */
    public function getStructuredDataOrganizationName(?int $storeId = null): string
    {
        return (string)$this->scopeConfig->getValue(
            self::XML_PATH_STRUCTURED_DATA_ORGANIZATION_NAME,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Get organization type
     *
     * @param int|null $storeId
     * @return string
     */
    public function getStructuredDataOrganizationType(?int $storeId = null): string
    {
        return (string)$this->scopeConfig->getValue(
            self::XML_PATH_STRUCTURED_DATA_ORGANIZATION_TYPE,
            ScopeInterface::SCOPE_STORE,
            $storeId
        ) ?: 'Organization';
    }

    /**
     * Get organization logo
     *
     * @param int|null $storeId
     * @return string
     */
    public function getStructuredDataOrganizationLogo(?int $storeId = null): string
    {
        return (string)$this->scopeConfig->getValue(
            self::XML_PATH_STRUCTURED_DATA_ORGANIZATION_LOGO,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Get organization description
     *
     * @param int|null $storeId
     * @return string
     */
    public function getStructuredDataOrganizationDescription(?int $storeId = null): string
    {
        return (string)$this->scopeConfig->getValue(
            self::XML_PATH_STRUCTURED_DATA_ORGANIZATION_DESCRIPTION,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Get organization founding date
     *
     * @param int|null $storeId
     * @return string
     */
    public function getStructuredDataOrganizationFoundingDate(?int $storeId = null): string
    {
        return (string)$this->scopeConfig->getValue(
            self::XML_PATH_STRUCTURED_DATA_ORGANIZATION_FOUNDING_DATE,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Get organization tax ID
     *
     * @param int|null $storeId
     * @return string
     */
    public function getStructuredDataOrganizationTaxId(?int $storeId = null): string
    {
        return (string)$this->scopeConfig->getValue(
            self::XML_PATH_STRUCTURED_DATA_ORGANIZATION_TAX_ID,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Get organization VAT ID
     *
     * @param int|null $storeId
     * @return string
     */
    public function getStructuredDataOrganizationVatId(?int $storeId = null): string
    {
        return (string)$this->scopeConfig->getValue(
            self::XML_PATH_STRUCTURED_DATA_ORGANIZATION_VAT_ID,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Get organization street address
     *
     * @param int|null $storeId
     * @return string
     */
    public function getStructuredDataOrganizationStreetAddress(?int $storeId = null): string
    {
        return (string)$this->scopeConfig->getValue(
            self::XML_PATH_STRUCTURED_DATA_ORGANIZATION_STREET_ADDRESS,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Get organization locality
     *
     * @param int|null $storeId
     * @return string
     */
    public function getStructuredDataOrganizationLocality(?int $storeId = null): string
    {
        return (string)$this->scopeConfig->getValue(
            self::XML_PATH_STRUCTURED_DATA_ORGANIZATION_LOCALITY,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Get organization region
     *
     * @param int|null $storeId
     * @return string
     */
    public function getStructuredDataOrganizationRegion(?int $storeId = null): string
    {
        return (string)$this->scopeConfig->getValue(
            self::XML_PATH_STRUCTURED_DATA_ORGANIZATION_REGION,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Get organization postal code
     *
     * @param int|null $storeId
     * @return string
     */
    public function getStructuredDataOrganizationPostalCode(?int $storeId = null): string
    {
        return (string)$this->scopeConfig->getValue(
            self::XML_PATH_STRUCTURED_DATA_ORGANIZATION_POSTAL_CODE,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Get organization country
     *
     * @param int|null $storeId
     * @return string
     */
    public function getStructuredDataOrganizationCountry(?int $storeId = null): string
    {
        return (string)$this->scopeConfig->getValue(
            self::XML_PATH_STRUCTURED_DATA_ORGANIZATION_COUNTRY,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Get organization customer service phone
     *
     * @param int|null $storeId
     * @return string
     */
    public function getStructuredDataOrganizationCustomerServicePhone(?int $storeId = null): string
    {
        return (string)$this->scopeConfig->getValue(
            self::XML_PATH_STRUCTURED_DATA_ORGANIZATION_CUSTOMER_SERVICE_PHONE,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Get organization customer service email
     *
     * @param int|null $storeId
     * @return string
     */
    public function getStructuredDataOrganizationCustomerServiceEmail(?int $storeId = null): string
    {
        return (string)$this->scopeConfig->getValue(
            self::XML_PATH_STRUCTURED_DATA_ORGANIZATION_CUSTOMER_SERVICE_EMAIL,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Get organization tech support phone
     *
     * @param int|null $storeId
     * @return string
     */
    public function getStructuredDataOrganizationTechSupportPhone(?int $storeId = null): string
    {
        return (string)$this->scopeConfig->getValue(
            self::XML_PATH_STRUCTURED_DATA_ORGANIZATION_TECH_SUPPORT_PHONE,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Get organization tech support email
     *
     * @param int|null $storeId
     * @return string
     */
    public function getStructuredDataOrganizationTechSupportEmail(?int $storeId = null): string
    {
        return (string)$this->scopeConfig->getValue(
            self::XML_PATH_STRUCTURED_DATA_ORGANIZATION_TECH_SUPPORT_EMAIL,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Get organization sales phone
     *
     * @param int|null $storeId
     * @return string
     */
    public function getStructuredDataOrganizationSalesPhone(?int $storeId = null): string
    {
        return (string)$this->scopeConfig->getValue(
            self::XML_PATH_STRUCTURED_DATA_ORGANIZATION_SALES_PHONE,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Get organization sales email
     *
     * @param int|null $storeId
     * @return string
     */
    public function getStructuredDataOrganizationSalesEmail(?int $storeId = null): string
    {
        return (string)$this->scopeConfig->getValue(
            self::XML_PATH_STRUCTURED_DATA_ORGANIZATION_SALES_EMAIL,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Get organization available languages
     *
     * @param int|null $storeId
     * @return array
     */
    public function getStructuredDataOrganizationAvailableLanguages(?int $storeId = null): array
    {
        $languages = $this->scopeConfig->getValue(
            self::XML_PATH_STRUCTURED_DATA_ORGANIZATION_AVAILABLE_LANGUAGES,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        return $languages ? explode(',', (string)$languages) : [];
    }

    /**
     * Get organization area served
     *
     * @param int|null $storeId
     * @return array
     */
    public function getStructuredDataOrganizationAreaServed(?int $storeId = null): array
    {
        $areas = $this->scopeConfig->getValue(
            self::XML_PATH_STRUCTURED_DATA_ORGANIZATION_AREA_SERVED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        return $areas ? explode(',', (string)$areas) : [];
    }

    /**
     * Get organization Facebook URL
     *
     * @param int|null $storeId
     * @return string
     */
    public function getStructuredDataOrganizationFacebook(?int $storeId = null): string
    {
        return (string)$this->scopeConfig->getValue(
            self::XML_PATH_STRUCTURED_DATA_ORGANIZATION_FACEBOOK,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Get organization Twitter URL
     *
     * @param int|null $storeId
     * @return string
     */
    public function getStructuredDataOrganizationTwitter(?int $storeId = null): string
    {
        return (string)$this->scopeConfig->getValue(
            self::XML_PATH_STRUCTURED_DATA_ORGANIZATION_TWITTER,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Get organization Instagram URL
     *
     * @param int|null $storeId
     * @return string
     */
    public function getStructuredDataOrganizationInstagram(?int $storeId = null): string
    {
        return (string)$this->scopeConfig->getValue(
            self::XML_PATH_STRUCTURED_DATA_ORGANIZATION_INSTAGRAM,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Get organization YouTube URL
     *
     * @param int|null $storeId
     * @return string
     */
    public function getStructuredDataOrganizationYoutube(?int $storeId = null): string
    {
        return (string)$this->scopeConfig->getValue(
            self::XML_PATH_STRUCTURED_DATA_ORGANIZATION_YOUTUBE,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Get organization LinkedIn URL
     *
     * @param int|null $storeId
     * @return string
     */
    public function getStructuredDataOrganizationLinkedin(?int $storeId = null): string
    {
        return (string)$this->scopeConfig->getValue(
            self::XML_PATH_STRUCTURED_DATA_ORGANIZATION_LINKEDIN,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Get organization Pinterest URL
     *
     * @param int|null $storeId
     * @return string
     */
    public function getStructuredDataOrganizationPinterest(?int $storeId = null): string
    {
        return (string)$this->scopeConfig->getValue(
            self::XML_PATH_STRUCTURED_DATA_ORGANIZATION_PINTEREST,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Get organization TikTok URL
     *
     * @param int|null $storeId
     * @return string
     */
    public function getStructuredDataOrganizationTiktok(?int $storeId = null): string
    {
        return (string)$this->scopeConfig->getValue(
            self::XML_PATH_STRUCTURED_DATA_ORGANIZATION_TIKTOK,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Get website alternate name
     *
     * @param int|null $storeId
     * @return string
     */
    public function getStructuredDataWebsiteAlternateName(?int $storeId = null): string
    {
        return (string)$this->scopeConfig->getValue(
            self::XML_PATH_STRUCTURED_DATA_WEBSITE_ALTERNATE_NAME,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Get brand attribute for structured data
     *
     * @param int|null $storeId
     * @return string
     */
    public function getStructuredDataBrandAttribute(?int $storeId = null): string
    {
        return (string)$this->scopeConfig->getValue(
            self::XML_PATH_STRUCTURED_DATA_BRAND_ATTRIBUTE,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Get default brand name used when the product has no brand/manufacturer value
     *
     * @param int|null $storeId
     * @return string
     */
    public function getStructuredDataDefaultBrand(?int $storeId = null): string
    {
        return trim((string)$this->scopeConfig->getValue(
            self::XML_PATH_STRUCTURED_DATA_DEFAULT_BRAND,
            ScopeInterface::SCOPE_STORE,
            $storeId
        ));
    }

    /**
     * Get model attribute for structured data
     *
     * @param int|null $storeId
     * @return string
     */
    public function getStructuredDataModelAttribute(?int $storeId = null): string
    {
        return (string)$this->scopeConfig->getValue(
            self::XML_PATH_STRUCTURED_DATA_MODEL_ATTRIBUTE,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Get GTIN8 attribute for structured data
     *
     * @param int|null $storeId
     * @return string
     */
    public function getStructuredDataGtin8Attribute(?int $storeId = null): string
    {
        return (string)$this->scopeConfig->getValue(
            self::XML_PATH_STRUCTURED_DATA_GTIN8_ATTRIBUTE,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Get GTIN13 attribute for structured data
     *
     * @param int|null $storeId
     * @return string
     */
    public function getStructuredDataGtin13Attribute(?int $storeId = null): string
    {
        return (string)$this->scopeConfig->getValue(
            self::XML_PATH_STRUCTURED_DATA_GTIN13_ATTRIBUTE,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Get GTIN14 attribute for structured data
     *
     * @param int|null $storeId
     * @return string
     */
    public function getStructuredDataGtin14Attribute(?int $storeId = null): string
    {
        return (string)$this->scopeConfig->getValue(
            self::XML_PATH_STRUCTURED_DATA_GTIN14_ATTRIBUTE,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Get MPN attribute for structured data
     *
     * @param int|null $storeId
     * @return string
     */
    public function getStructuredDataMpnAttribute(?int $storeId = null): string
    {
        return (string)$this->scopeConfig->getValue(
            self::XML_PATH_STRUCTURED_DATA_MPN_ATTRIBUTE,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Get condition attribute for structured data
     *
     * @param int|null $storeId
     * @return string
     */
    public function getStructuredDataConditionAttribute(?int $storeId = null): string
    {
        return (string)$this->scopeConfig->getValue(
            self::XML_PATH_STRUCTURED_DATA_CONDITION_ATTRIBUTE,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Get property mappings for structured data
     *
     * @param int|null $storeId
     * @return array
     */
    public function getStructuredDataPropertyMappings(?int $storeId = null): array
    {
        $mappings = $this->scopeConfig->getValue(
            self::XML_PATH_STRUCTURED_DATA_PROPERTY_MAPPINGS,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        if (empty($mappings)) {
            return [];
        }

        $result = [];
        $lines = explode(PHP_EOL, (string)$mappings);

        foreach ($lines as $line) {
            $parts = explode('=', trim($line));
            if (count($parts) === 2) {
                $result[trim($parts[0])] = trim($parts[1]);
            }
        }

        return $result;
    }

    /**
     * Get attribute mappings for structured data
     *
     * @param string $entityType
     * @param int|null $storeId
     * @return string
     */
    public function getStructuredDataAttributeMappings(string $entityType, ?int $storeId = null): string
    {
        $path = self::XML_PATH_STRUCTURED_DATA_ATTRIBUTE_MAPPINGS . '_' . $entityType;

        return (string)$this->scopeConfig->getValue(
            $path,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Check if canonical URLs are enabled
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isCanonicalEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_CANONICAL_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Check if product canonical URLs are enabled
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isProductCanonicalEnabled(?int $storeId = null): bool
    {
        if (!$this->isCanonicalEnabled($storeId)) {
            return false;
        }
        
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_PRODUCT_CANONICAL_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Get product canonical URL type
     *
     * @param int|null $storeId
     * @return string
     */
    public function getProductCanonicalUrlType(?int $storeId = null): string
    {
        return (string)$this->scopeConfig->getValue(
            self::XML_PATH_PRODUCT_CANONICAL_URL_TYPE,
            ScopeInterface::SCOPE_STORE,
            $storeId
        ) ?: ProductCanonicalType::TYPE_PRODUCT_URL;
    }

    /**
     * Check if category canonical URLs are enabled
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isCategoryCanonicalEnabled(?int $storeId = null): bool
    {
        if (!$this->isCanonicalEnabled($storeId)) {
            return false;
        }
        
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_CATEGORY_CANONICAL_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Check if canonical URLs should be used for category pagination
     *
     * @param int|null $storeId
     * @return bool
     */
    public function shouldUseCanonicalForCategoryPagination(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_CATEGORY_PAGINATION_CANONICAL,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Get category pagination canonical type
     *
     * @param int|null $storeId
     * @return string
     */
    public function getCategoryPaginationCanonicalType(?int $storeId = null): string
    {
        return (string)$this->scopeConfig->getValue(
            self::XML_PATH_CATEGORY_PAGINATION_TYPE,
            ScopeInterface::SCOPE_STORE,
            $storeId
        ) ?: PaginationCanonicalType::TYPE_FIRST_PAGE;
    }

    /**
     * Check if canonical URLs should be used for category filters
     *
     * @param int|null $storeId
     * @return bool
     */
    public function shouldUseCanonicalForCategoryFilters(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_CATEGORY_FILTERS_CANONICAL,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Get excluded URL patterns
     *
     * @param int|null $storeId
     * @return string
     */
    public function getExcludedUrlPatterns(?int $storeId = null): string
    {
        return (string)$this->scopeConfig->getValue(
            self::XML_PATH_EXCLUDE_PATTERNS,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Check if invisible products should be excluded
     *
     * @param int|null $storeId
     * @return bool
     */
    public function shouldExcludeInvisibleProducts(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_EXCLUDE_INVISIBLE_PRODUCTS,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Check if anchor categories should be excluded
     *
     * @param int|null $storeId
     * @return bool
     */
    public function shouldExcludeAnchorCategories(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_EXCLUDE_ANCHOR_CATEGORIES,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Get excluded product types
     *
     * @param int|null $storeId
     * @return array
     */
    public function getExcludedProductTypes(?int $storeId = null): array
    {
        $types = $this->scopeConfig->getValue(
            self::XML_PATH_EXCLUDED_PRODUCT_TYPES,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        
        if (empty($types)) {
            return [];
        }
        
        return explode(',', (string)$types);
    }

    /**
     * Get excluded CMS pages
     *
     * @param int|null $storeId
     * @return array
     */
    public function getExcludedCmsPages(?int $storeId = null): array
    {
        $pages = $this->scopeConfig->getValue(
            self::XML_PATH_EXCLUDED_CMS_PAGES,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        
        if (empty($pages)) {
            return [];
        }
        
        return explode(',', (string)$pages);
    }



    /**
     * Check if friendly URLs are enabled
     *
     * @param int|null $storeId
     * @return bool
     */
    public function areFriendlyUrlsEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_FRIENDLY_URLS_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Get friendly URL pattern
     *
     * @param int|null $storeId
     * @return string
     */
    public function getFriendlyUrlPattern(?int $storeId = null): string
    {
        return (string)$this->scopeConfig->getValue(
            self::XML_PATH_FRIENDLY_URL_PATTERN,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Get value separator
     *
     * @param int|null $storeId
     * @return string
     */
    public function getValueSeparator(?int $storeId = null): string
    {
        $separator = $this->scopeConfig->getValue(
            self::XML_PATH_VALUE_SEPARATOR,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        
        // Must not occur in attribute codes ([A-Za-z0-9_]) and must be path-safe, otherwise segments cannot be parsed back.
        return in_array($separator, ['-', '~'], true) ? $separator : self::DEFAULT_VALUE_SEPARATOR;
    }

    /**
     * Get configuration value by path
     *
     * @param string $path
     * @param int|null $storeId
     * @return string|null
     */
    public function getValue(string $path, ?int $storeId = null): ?string
    {
        // Add defox_seosuite prefix if not present
        if (strpos($path, 'defox_seosuite/') !== 0) {
            $path = 'defox_seosuite/' . $path;
        }
        
        return $this->scopeConfig->getValue(
            $path,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Check if sitemap is enabled
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isSitemapEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_SITEMAP_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Store locale code (general/locale/code), e.g. "en_GB"
     *
     * @param int $storeId
     * @return string
     */
    public function getLocaleCode(int $storeId): string
    {
        return (string)$this->scopeConfig->getValue('general/locale/code', ScopeInterface::SCOPE_STORE, $storeId);
    }

    /**
     * Identifier of a core utility CMS page for a store, without the "|<page id>" suffix core stores
     * when the identifier repeats across stores
     *
     * @param string $page cms_home_page | cms_no_route | cms_no_cookies (under web/default/)
     * @param int $storeId
     * @return string
     */
    public function getCmsUtilityPageIdentifier(string $page, int $storeId): string
    {
        $value = (string)$this->scopeConfig->getValue('web/default/' . $page, ScopeInterface::SCOPE_STORE, $storeId);
        return explode('|', $value, 2)[0];
    }



    /**
     * Normalise a sitemap directory to "dir/sub/" relative to pub/ ("" = pub/ itself) and check it against
     * the allow/deny lists core Magento_Sitemap uses (sitemap/file/valid_paths + general/file/public_files_valid_paths).
     * Shared by the config backend model (on save) and every reader (stored values may predate the backend model).
     *
     * @param string $path
     * @return string
     * @throws LocalizedException absolute path, "..", or a path core does not allow
     */
    public function normalizeSitemapPath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));

        // Leading "/" means pub/-relative (old generator and core Magento_Sitemap convention); drive letters are rejected
        if (preg_match('#^[a-z]:#i', $path)) {
            throw new LocalizedException(
                __('Sitemap Path must be relative to the pub/ directory, e.g. "media/maps/".')
            );
        }

        $path = trim($path, '/');
        $path = $path === '' ? '' : $path . '/';

        // AvailablePath also rejects "../" (LFI); the file name only has to satisfy the "*.xml" mask
        $this->pathValidator->setPaths($this->sitemapConfigReader->getValidPaths());
        if (!$this->pathValidator->isValid('/' . $path . 'sitemap.xml')) {
            throw new LocalizedException(
                __('Sitemap Path "%1" is not allowed: %2', $path, implode(' ', $this->pathValidator->getMessages()))
            );
        }

        return $path;
    }

    /**
     * Sitemap directory of a store, relative to pub/, validated ("" or "dir/sub/")
     *
     * @param int $storeId
     * @return string
     * @throws LocalizedException
     */
    public function getSitemapPath(int $storeId): string
    {
        $path = $this->scopeConfig->getValue(self::XML_PATH_SITEMAP_PATH, ScopeInterface::SCOPE_STORE, $storeId);

        return $this->normalizeSitemapPath((string)($path ?? self::DEFAULT_SITEMAP_PATH));
    }

    /**
     * Absolute sitemap directory of a store (with trailing slash)
     *
     * @param int $storeId
     * @return string
     * @throws LocalizedException
     */
    public function getFullSitemapPath(int $storeId): string
    {
        return rtrim($this->directoryList->getPath(DirectoryList::PUB), '/') . '/' . $this->getSitemapPath($storeId);
    }

    /**
     * Public URL of a sitemap file: store web base (never the link base, which may carry the store code) + path
     *
     * @param int $storeId
     * @param string $fileName
     * @return string
     * @throws LocalizedException
     */
    public function getSitemapUrl(int $storeId, string $fileName): string
    {
        // ponytail: assumes the web base URL maps to pub/ (standard 2.4 docroot); core's doc-root sniffing not copied
        $store = $this->storeManager->getStore($storeId);
        $baseUrl = rtrim((string)$store->getBaseUrl(UrlInterface::URL_TYPE_WEB, $store->isUrlSecure()), '/');

        return $baseUrl . '/' . $this->getSitemapPath($storeId) . $fileName;
    }

    /**
     * The only place sitemap file names are built: "<store code>_sitemap<suffix>.xml[.gz]"
     *
     * @param int $storeId
     * @param string $suffix "" (main/first file), "_index" or "_<N>"
     * @param bool $compressed
     * @return string
     */
    public function getSitemapFileName(int $storeId, string $suffix = '', bool $compressed = false): string
    {
        return $this->storeManager->getStore($storeId)->getCode() . '_sitemap' . $suffix . '.xml'
            . ($compressed ? '.gz' : '');
    }

    /**
     * Whether a base name is one of this store's sitemap files (the inverse of getSitemapFileName())
     *
     * @param int $storeId
     * @param string $fileName
     * @return bool
     */
    public function isSitemapFileOfStore(int $storeId, string $fileName): bool
    {
        $code = preg_quote((string)$this->storeManager->getStore($storeId)->getCode(), '/');

        return (bool)preg_match('/^' . $code . '_sitemap(_index|_\d+)?\.xml(\.gz)?$/', $fileName);
    }

    /**
     * Name of the store's published entry file (index first, then the single sitemap), null if none exists
     *
     * @param int $storeId
     * @return string|null
     * @throws LocalizedException
     */
    public function getPublishedSitemapFileName(int $storeId): ?string
    {
        $dir = $this->getFullSitemapPath($storeId);
        foreach ([['_index', true], ['_index', false], ['', true], ['', false]] as [$suffix, $compressed]) {
            $fileName = $this->getSitemapFileName($storeId, $suffix, $compressed);
            if (is_file($dir . $fileName)) {
                return $fileName;
            }
        }

        return null;
    }

    /**
     * Get sitemap generation cron expression
     *
     * @param int|null $storeId
     * @return string
     */
    public function getSitemapGenerationCronExpr(?int $storeId = null): string
    {
        $cronExpr = $this->scopeConfig->getValue(
            self::XML_PATH_SITEMAP_GENERATION_CRON_EXPR,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        
        // Fallback do domyślnej wartości jeśli nie skonfigurowano
        return $cronExpr ? (string)$cronExpr : '0 2 * * *'; // Domyślnie o 2:00 rano
    }
}