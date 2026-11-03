<?php
/**
 * @package     Defox_SEOSuite
 * @author      Marcin Lisiecki
 * @copyright   Copyright (c) 2025 deFox
 * @license     GNU General Public License v3.0
 */
declare(strict_types=1);

namespace Defox\SEOSuite\Model\Sitemap;

/**
 * Interface for sitemap generators
 * 
 * Handles the generation of sitemap files in various formats
 */
interface SitemapGeneratorInterface
{
    /**
     * Generate sitemap
     *
     * @param int $storeId
     * @param array $options Additional options for generation
     * @return array Absolute paths of the published files (index first when there is one)
     * @throws \Exception
     */
    public function generate(int $storeId, array $options = []): array;

    /**
     * Statistics of the last generate() call: store_id, files[name,size,urls], total_urls, errors, duration
     *
     * @return array
     */
    public function getGenerationStats(): array;

    /**
     * Get sitemap URL
     *
     * @param int $storeId
     * @param string $fileName
     * @return string
     */
    public function getSitemapUrl(int $storeId, string $fileName): string;

    /**
     * Validate sitemap file
     *
     * @param string $filePath
     * @return array Validation results ['valid' => bool, 'errors' => array]
     */
    public function validateSitemap(string $filePath): array;
}
