<?php
/**
 * @package     Defox_SEOSuite
 * @author      Marcin Lisiecki
 * @copyright   Copyright (c) 2025 deFox
 * @license     GNU General Public License v3.0
 */

declare(strict_types=1);

namespace Defox\SEOSuite\Model\StructuredData;

use Defox\SEOSuite\Helper\Config;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * The store's schema.org Organization, built once from the SEO Suite organization config (CR-38)
 *
 * Used as the Organization item, Category/WebPage "publisher", about page "about" and Product offer "seller".
 */
class OrganizationDataProvider
{
    /**
     * Allowed schema.org types for the configured organization type
     */
    private const TYPES = [
        'Organization', 'Corporation', 'LocalBusiness', 'Store',
        'OnlineStore', // pending schema.org type, may not be recognized by Google
        'Restaurant', 'Hotel', 'ProfessionalService', 'MedicalBusiness', 'AutomotiveBusiness',
        'FinancialService', 'FoodEstablishment', 'SportsActivityLocation',
    ];

    /**
     * @var Config
     */
    private Config $config;

    /**
     * @var StoreManagerInterface
     */
    private StoreManagerInterface $storeManager;

    /**
     * @param Config $config
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(Config $config, StoreManagerInterface $storeManager)
    {
        $this->config = $config;
        $this->storeManager = $storeManager;
    }

    /**
     * Organization data (without @context)
     *
     * @return array
     */
    public function getData(): array
    {
        $c = $this->config;
        $data = [
            '@type' => $this->getType(),
            'name' => trim($c->getStructuredDataOrganizationName()),
            'url' => $this->storeManager->getStore()->getBaseUrl(),
        ];

        $logo = $this->getLogoUrl();
        if ($logo !== null) {
            $data['logo'] = ['@type' => 'ImageObject', 'url' => $logo];
        }

        $address = array_filter([
            'streetAddress' => $c->getStructuredDataOrganizationStreetAddress(),
            'addressLocality' => $c->getStructuredDataOrganizationLocality(),
            'addressRegion' => $c->getStructuredDataOrganizationRegion(),
            'postalCode' => $c->getStructuredDataOrganizationPostalCode(),
            'addressCountry' => $c->getStructuredDataOrganizationCountry(),
        ], 'strlen');

        $optional = [
            'description' => $c->getStructuredDataOrganizationDescription(),
            'address' => $address ? ['@type' => 'PostalAddress'] + $address : [],
            'contactPoint' => $this->getContactPoints(),
            'sameAs' => $this->getSocialProfiles(),
            'foundingDate' => $c->getStructuredDataOrganizationFoundingDate(),
            'taxID' => $c->getStructuredDataOrganizationTaxId(),
            'vatID' => $c->getStructuredDataOrganizationVatId(),
        ];
        foreach ($optional as $key => $value) {
            if ($value !== '' && $value !== []) {
                $data[$key] = $value;
            }
        }

        return $data;
    }

    /**
     * Organization data when enabled and named, null otherwise (one guard for every Organization node)
     *
     * @return array|null
     */
    public function getNamedData(): ?array
    {
        return $this->config->isStructuredDataOrganizationEnabled()
            && trim($this->config->getStructuredDataOrganizationName()) !== ''
            ? $this->getData()
            : null;
    }

    /**
     * Configured organization type, limited to TYPES
     *
     * @return string
     */
    public function getType(): string
    {
        $type = $this->config->getStructuredDataOrganizationType();
        return in_array($type, self::TYPES, true) ? $type : 'Organization';
    }

    /**
     * Absolute logo URL (relative config values live under media/logo/), null when not configured
     *
     * @return string|null
     */
    public function getLogoUrl(): ?string
    {
        $logoPath = $this->config->getStructuredDataOrganizationLogo();
        if ($logoPath === '') {
            return null;
        }
        if (filter_var($logoPath, FILTER_VALIDATE_URL)) {
            return $logoPath;
        }

        return $this->storeManager->getStore()->getBaseUrl(UrlInterface::URL_TYPE_MEDIA)
            . 'logo/' . ltrim($logoPath, '/');
    }

    /**
     * Contact points; languages and area served belong to the customer service point
     *
     * @return array
     */
    private function getContactPoints(): array
    {
        $c = $this->config;
        $points = [
            'customer service' => [
                $c->getStructuredDataOrganizationCustomerServicePhone(),
                $c->getStructuredDataOrganizationCustomerServiceEmail(),
            ],
            'technical support' => [
                $c->getStructuredDataOrganizationTechSupportPhone(),
                $c->getStructuredDataOrganizationTechSupportEmail(),
            ],
            'sales' => [$c->getStructuredDataOrganizationSalesPhone(), $c->getStructuredDataOrganizationSalesEmail()],
        ];

        $result = [];
        foreach ($points as $type => [$phone, $email]) {
            if ($phone === '' && $email === '') {
                continue;
            }
            $point = array_filter(['telephone' => $phone, 'email' => $email], 'strlen');
            if ($type === 'customer service') {
                $point += array_filter([
                    'availableLanguage' => $this->cleanList($c->getStructuredDataOrganizationAvailableLanguages()),
                    'areaServed' => $this->cleanList($c->getStructuredDataOrganizationAreaServed()),
                ]);
            }
            $result[] = ['@type' => 'ContactPoint', 'contactType' => $type] + $point;
        }

        return $result;
    }

    /**
     * Trimmed, non-empty entries of a comma-separated config list
     *
     * @param string[] $values
     * @return string[]
     */
    private function cleanList(array $values): array
    {
        return array_values(array_filter(array_map('trim', $values), 'strlen'));
    }

    /**
     * Valid social profile URLs
     *
     * @return string[]
     */
    private function getSocialProfiles(): array
    {
        $c = $this->config;
        return array_values(array_filter([
            $c->getStructuredDataOrganizationFacebook(),
            $c->getStructuredDataOrganizationTwitter(),
            $c->getStructuredDataOrganizationInstagram(),
            $c->getStructuredDataOrganizationYoutube(),
            $c->getStructuredDataOrganizationLinkedin(),
            $c->getStructuredDataOrganizationPinterest(),
            $c->getStructuredDataOrganizationTiktok(),
        ], static fn (string $url): bool => (bool)filter_var($url, FILTER_VALIDATE_URL)));
    }
}
