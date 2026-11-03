<?php
/**
 * @package     Defox_SEOSuite
 * @author      Marcin Lisiecki 
 * @copyright   Copyright (c) 2025 deFox
 * @license     GNU General Public License v3.0
 */

declare(strict_types=1);

namespace Defox\SEOSuite\Model\StructuredData\Renderer;

use Psr\Log\LoggerInterface;

/**
 * JSON-LD renderer for structured data
 * 
 * This class is responsible for rendering structured data arrays into valid JSON-LD format.
 */
class JsonLd
{
    /**
     * JSON_HEX_TAG|JSON_HEX_AMP keep "</script>" and entities inert inside the script tag (INV-B);
     * invalid UTF-8 is substituted instead of failing the whole document (CR-19)
     */
    private const JSON_FLAGS = JSON_HEX_TAG | JSON_HEX_AMP | JSON_INVALID_UTF8_SUBSTITUTE
        | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @param LoggerInterface $logger
     */
    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Render structured data as JSON-LD script tag
     *
     * @param array $data Single structured data item or array of items
     * @return string
     */
    public function render(array $data): string
    {
        if (empty($data)) {
            return '';
        }
        
        // Check if we have a single item or multiple items
        $jsonData = $this->prepareData($data);
        
        // Encode to JSON with proper formatting
        $json = json_encode($jsonData, self::JSON_FLAGS | JSON_PRETTY_PRINT);
        
        if ($json === false) {
            $this->logger->warning('Defox_SEOSuite: JSON-LD encoding failed: ' . json_last_error_msg());
            return '';
        }
        
        // Wrap in script tag
        return sprintf(
            '<script type="application/ld+json">%s</script>',
            $json
        );
    }
    
    /**
     * Render multiple structured data items as separate JSON-LD script tags
     *
     * @param array $items Array of structured data items
     * @return string
     */
    public function renderMultiple(array $items): string
    {
        if (empty($items)) {
            return '';
        }
        
        $output = [];
        
        foreach ($items as $item) {
            if (is_array($item) && !empty($item)) {
                $rendered = $this->render($item);
                if ($rendered) {
                    $output[] = $rendered;
                }
            }
        }
        
        return implode("\n", $output);
    }
    
    /**
     * Prepare data for JSON encoding
     *
     * @param array $data
     * @return array
     */
    private function prepareData(array $data): array
    {
        // If the array has numeric keys, it's a list of items
        if (isset($data[0])) {
            // Multiple items - use @graph with @context only at top level
            if (count($data) > 1) {
                $cleanedItems = array_map(function (array $item): array {
                    unset($item['@context']);
                    return $item;
                }, $data);
                return [
                    '@context' => 'https://schema.org',
                    '@graph' => $cleanedItems
                ];
            } else {
                // Single item in array - ensure @context is first
                $singleItem = $data[0];
                return $this->ensureContextFirst($singleItem);
            }
        }
        
        // Single item - ensure @context is first
        return $this->ensureContextFirst($data);
    }
    
    /**
     * Ensure @context is the first element in the array
     *
     * @param array $data
     * @return array
     */
    private function ensureContextFirst(array $data): array
    {
        // If @context doesn't exist, add it
        if (!isset($data['@context'])) {
            $data['@context'] = 'https://schema.org';
        }
        
        // Reorder array to put @context first
        $context = $data['@context'];
        unset($data['@context']);
        
        return ['@context' => $context] + $data;
    }

}
