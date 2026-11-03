<?php
/**
 * @package     Defox_SEOSuite
 * @author      Marcin Lisiecki
 * @copyright   Copyright (c) 2025 deFox
 * @license     GNU General Public License v3.0
 */

declare(strict_types=1);

namespace Defox\SEOSuite\Ui\Component\Listing;

use Magento\Framework\AuthorizationInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentInterface;

/**
 * Template grid mass actions filtered by ACL (same pattern as Magento_InventoryAdminUi)
 */
class MassAction extends \Magento\Ui\Component\MassAction
{
    /**
     * Action type => required ACL resource
     */
    private const ACTION_RESOURCES = [
        'delete' => 'Defox_SEOSuite::template_delete',
        'enable' => 'Defox_SEOSuite::template_save',
        'disable' => 'Defox_SEOSuite::template_save',
    ];

    /**
     * @param AuthorizationInterface $authorization
     * @param ContextInterface $context
     * @param UiComponentInterface[] $components
     * @param array $data
     */
    public function __construct(
        private readonly AuthorizationInterface $authorization,
        ContextInterface $context,
        array $components = [],
        array $data = []
    ) {
        parent::__construct($context, $components, $data);
    }

    /**
     * @inheritdoc
     */
    public function prepare(): void
    {
        foreach ($this->getChildComponents() as $actionComponent) {
            $config = $actionComponent->getConfiguration();
            $resource = self::ACTION_RESOURCES[$config['type'] ?? ''] ?? null;
            $config['actionDisable'] = $resource !== null && !$this->authorization->isAllowed($resource);
            $actionComponent->setData('config', $config);
        }

        parent::prepare();
    }
}
