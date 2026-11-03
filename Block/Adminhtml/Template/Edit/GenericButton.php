<?php
/**
 * @package     Defox_SEOSuite
 * @author      Marcin Lisiecki
 * @copyright   Copyright (c) 2025 deFox
 * @license     GNU General Public License v3.0
 */

declare(strict_types=1);

namespace Defox\SEOSuite\Block\Adminhtml\Template\Edit;

use Magento\Backend\Block\Widget\Context;

/**
 * Generic button class for template edit form
 */
class GenericButton
{
    /**
     * @var Context
     */
    protected Context $context;

    /**
     * @param Context $context
     */
    public function __construct(
        Context $context
    ) {
        $this->context = $context;
    }

    /**
     * Get template ID
     *
     * @return int|null
     */
    public function getTemplateId(): ?int
    {
        // Edit controller redirects away when the ID does not resolve, so the param is the loaded template's ID
        $templateId = (int)$this->context->getRequest()->getParam('template_id');
        return $templateId > 0 ? $templateId : null;
    }

    /**
     * Generate URL by route and parameters
     *
     * @param string $route
     * @param array $params
     * @return string
     */
    public function getUrl(string $route = '', array $params = []): string
    {
        return $this->context->getUrlBuilder()->getUrl($route, $params);
    }
}
