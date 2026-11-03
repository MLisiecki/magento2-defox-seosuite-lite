<?php
/**
 * @package     Defox_SEOSuite
 * @author      Marcin Lisiecki
 * @copyright   Copyright (c) 2025 deFox
 * @license     GNU General Public License v3.0
 */

declare(strict_types=1);

namespace Defox\SEOSuite\Logger;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Filesystem\DriverInterface;
use Magento\Framework\Logger\Handler\Base;
use Monolog\Level;
use Monolog\LogRecord;

/**
 * Logger handler class: INFO and above always, DEBUG only when Debug Mode is enabled
 */
class Handler extends Base
{
    public const XML_PATH_DEBUG_MODE = 'defox_seosuite/general/debug_mode';

    /**
     * Logging level
     *
     * @var int
     */
    protected $loggerType = Level::Info->value;

    /**
     * File name
     *
     * @var string
     */
    protected $fileName = '/var/log/defox_seosuite.log';

    /**
     * @param DriverInterface $filesystem
     * @param ScopeConfigInterface $scopeConfig
     * @param string|null $filePath
     * @param string|null $fileName
     */
    public function __construct(
        DriverInterface $filesystem,
        private readonly ScopeConfigInterface $scopeConfig,
        ?string $filePath = null,
        ?string $fileName = null
    ) {
        parent::__construct($filesystem, $filePath, $fileName);
    }

    /**
     * @inheritDoc
     */
    public function isHandling(LogRecord $record): bool
    {
        if ($record->level === Level::Debug) {
            // ponytail: default scope only; the handler has no store context
            return $this->scopeConfig->isSetFlag(self::XML_PATH_DEBUG_MODE);
        }

        return parent::isHandling($record);
    }
}
