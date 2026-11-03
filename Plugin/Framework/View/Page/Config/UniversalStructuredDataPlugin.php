<?php
/**
 * @package     Defox_SEOSuite
 * @author      Marcin Lisiecki 
 * @copyright   Copyright (c) 2025 deFox
 * @license     GNU General Public License v3.0
 */

declare(strict_types=1);

namespace Defox\SEOSuite\Plugin\Framework\View\Page\Config;

use Defox\SEOSuite\Helper\StructuredDataManager;
use Magento\Framework\View\Page\Config\Renderer;
use Psr\Log\LoggerInterface;

/**
 * Adds the page's JSON-LD to the rendered head content
 *
 * ponytail: the only place JSON-LD is emitted. Response post-processors such as Blueskytechco_OptimizeSpeed
 * rewrite only <script> / type="text/javascript" tags, so a type="application/ld+json" tag passes through
 * unchanged and needs no plugin on them (CR-33).
 */
class UniversalStructuredDataPlugin
{
    /**
     * @var StructuredDataManager
     */
    private StructuredDataManager $structuredDataManager;
    
    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * Constructor
     *
     * @param StructuredDataManager $structuredDataManager
     * @param LoggerInterface $logger
     */
    public function __construct(
        StructuredDataManager $structuredDataManager,
        LoggerInterface $logger
    ) {
        $this->structuredDataManager = $structuredDataManager;
        $this->logger = $logger;
    }

    /**
     * Add structured data JSON-LD after head content is rendered
     *
     * @param Renderer $subject
     * @param string $result
     * @return string
     */
    public function afterRenderHeadContent(Renderer $subject, string $result): string
    {
        try {
            $jsonLd = $this->structuredDataManager->getJsonLd();
            if ($jsonLd !== '') {
                $result .= "\n" . $jsonLd;
            }
        } catch (\Throwable $e) {
            $this->logger->error(
                'SEOSuite: Error adding structured data to head: ' . $e->getMessage(),
                ['exception' => $e]
            );
        }

        return $result;
    }
}
