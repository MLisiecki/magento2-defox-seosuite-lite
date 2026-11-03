<?php
/**
 * @package     Defox_SEOSuite
 * @author      Marcin Lisiecki
 * @copyright   Copyright (c) 2025 deFox
 * @license     GNU General Public License v3.0
 */

declare(strict_types=1);

namespace Defox\SEOSuite\Model;

use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Exception\LocalizedException;
use Psr\Log\LoggerInterface;

/**
 * Exception text safe for HTTP responses and e-mails: LocalizedException text passes through,
 * anything else (incl. FileSystemException, which carries absolute paths) becomes a generic text
 */
class SafeErrorMessage
{
    /**
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Get a message safe to show; hidden details go to the log
     *
     * @param \Throwable $e
     * @return string
     */
    public function get(\Throwable $e): string
    {
        if ($e instanceof LocalizedException && !$e instanceof FileSystemException) {
            return $e->getMessage();
        }
        $this->logger->error('Defox_SEOSuite: ' . $e->getMessage(), ['exception' => $e]);

        return (string)__('An unexpected error occurred. Please check the log for details.');
    }
}
