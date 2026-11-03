<?php
/**
 * @package     Defox_SEOSuite
 * @author      Marcin Lisiecki 
 * @copyright   Copyright (c) 2025 deFox
 * @license     GNU General Public License v3.0
 */

declare(strict_types=1);

namespace Defox\SEOSuite\Model\StructuredData\Generator;

use Magento\Cms\Model\Page;

/**
 * Article structured data generator
 *
 * Generates schema.org Article/BlogPosting structured data for CMS pages that are articles or blog posts.
 */
class Article extends WebPage
{
    /**
     * Generate structured data for article
     *
     * @param mixed $entity
     * @param array $context
     * @return array
     */
    protected function doGenerate($entity, array $context): array
    {
        /** @var Page $page */
        $page = $entity;

        // Get base WebPage data
        $data = parent::doGenerate($entity, $context);

        // Change type to Article or BlogPosting
        $data['@type'] = $this->getArticleType($page);

        // Add headline (required for Article)
        $data['headline'] = $page->getTitle();

        // Add author
        $author = $this->getAuthor($page, $context);
        if ($author) {
            $data['author'] = $author;
        }

        // Add article body with HTML cleaning
        $data['articleBody'] = $this->cleanContent((string)$page->getContent());

        // Add word count using cleaned content
        $cleanContent = $this->cleanContent((string)$page->getContent());
        // UTF-8 aware (str_word_count is ASCII-only); "don't" / "well-known" stay one word, invalid UTF-8 -> 0
        $data['wordCount'] = (int)preg_match_all('/[\p{L}\p{M}\p{N}]+(?:[\'’-][\p{L}\p{M}\p{N}]+)*/u', $cleanContent);

        // Add images from content, with fallback to organization logo
        $images = $this->extractImagesFromContent((string)$page->getContent());
        if (!empty($images)) {
            $data['image'] = count($images) === 1 ? $images[0] : $images;
        } else {
            $logoUrl = $this->organizationData->getLogoUrl();
            if ($logoUrl !== null) {
                $data['image'] = $logoUrl;
            }
        }

        // Add keywords from meta keywords (cleaned)
        if ($page->getMetaKeywords()) {
            $data['keywords'] = $this->cleanTextField($page->getMetaKeywords());
        }

        // Add article section if available
        if (isset($context['section'])) {
            $data['articleSection'] = $context['section'];
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
        return $entity instanceof Page && $this->isArticleLikePage($entity);
    }

    /**
     * Get schema type
     *
     * @return string
     */
    public function getSchemaType(): string
    {
        return 'Article';
    }

    /**
     * Get article type (Article or BlogPosting)
     *
     * @param Page $page
     * @return string
     */
    private function getArticleType(Page $page): string
    {
        return preg_match('/(?<![\p{L}\p{N}])blog/iu', (string)$page->getIdentifier()) ? 'BlogPosting' : 'Article';
    }

    /**
     * Get author information
     *
     * @param Page $page
     * @param array $context
     * @return array|null
     */
    private function getAuthor(Page $page, array $context): ?array
    {
        // Check context for author information
        if (isset($context['author'])) {
            if (is_array($context['author'])) {
                return $context['author'];
            }
            $name = $this->cleanTextField($context['author']);
            if ($name !== '') {
                return ['@type' => 'Person', 'name' => $name];
            }
        }

        // Try to extract author from content
        $content = (string)$page->getContent();
        // "by" only as a whole word ("Ruby", "nearby" are not bylines); UTF-8 aware, invalid UTF-8 -> no match
        // ponytail: blank byline ("by <b>Jan</b>", "Autor: \r\n") falls back to the Organization, not the tag text
        if (preg_match('/(?<![\p{L}\p{N}])(?:by\s|autor:|author:)\s*([^<\n]+)/iu', $content, $matches)) {
            $name = $this->cleanTextField($matches[1]);
            if ($name !== '') {
                return ['@type' => 'Person', 'name' => $name];
            }
        }

        // Default to organization (one guard: enabled + non-empty name)
        return $this->organizationData->getNamedData();
    }

    /**
     * Extract images from content
     *
     * @param string $content
     * @return array
     */
    private function extractImagesFromContent(string $content): array
    {
        $images = [];
        $baseUrl = $this->storeManager->getStore()->getBaseUrl();
        $mediaUrl = $this->storeManager->getStore()->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_MEDIA);

        // Handle Magento media directives {{media url="..."}}
        if (preg_match_all('/\{\{media\s+url=["\']([^"\']+)["\']\s*\}\}/i', $content, $mediaMatches)) {
            foreach ($mediaMatches[1] as $mediaPath) {
                $images[] = $mediaUrl . ltrim($mediaPath, '/');
            }
        }

        // Extract img tags
        if (preg_match_all('/<img[^>]+src=[\"\']([^\"\']+)[\"\'][^>]*>/i', $content, $matches)) {
            foreach ($matches[1] as $imageUrl) {
                if (strpos($imageUrl, '{{media') !== false) {
                    continue; // Already handled above
                }
                if (str_starts_with($imageUrl, '//')) {
                    $imageUrl = (parse_url($baseUrl, PHP_URL_SCHEME) ?: 'https') . ':' . $imageUrl;
                } elseif (preg_match('#^[a-z][a-z0-9+.-]*:#i', $imageUrl)) {
                    if (!preg_match('#^https?://#i', $imageUrl)) {
                        continue; // data:, blob:, javascript: ... are not image URLs for schema.org
                    }
                } else {
                    $imageUrl = rtrim($baseUrl, '/') . '/' . ltrim($imageUrl, '/');
                }
                // FILTER_VALIDATE_URL is ASCII-only: percent-encode UTF-8 / spaces (as browsers do) so "żółw.jpg" survives
                $imageUrl = (string)preg_replace_callback('/[^\x21-\x7E]+/', fn ($m) => rawurlencode($m[0]), $imageUrl);
                if (filter_var($imageUrl, FILTER_VALIDATE_URL)) {
                    $images[] = $imageUrl;
                }
            }
        }

        return array_values(array_unique($images));
    }
}
