<?php
/**
 * @package     Defox_SEOSuite
 * @author      Marcin Lisiecki
 * @copyright   Copyright (c) 2025 deFox
 * @license     GNU General Public License v3.0
 */

declare(strict_types=1);

namespace Defox\SEOSuite\Block\Adminhtml\Template\Edit;

use Magento\Framework\View\Element\UiComponent\Control\ButtonProviderInterface;

/**
 * Save and continue edit button for template edit form
 */
class SaveAndContinueButton extends GenericButton implements ButtonProviderInterface
{
    /**
     * @inheritDoc
     */
    public function getButtonData(): array
    {
        return [
            'label' => __('Save and Continue Edit'),
            'class' => 'save',
            // ponytail: no buttonAdapter - Magento_Ui/js/form/adapter already binds #save / #save_and_continue
            // (ids come from the button keys in the form XML); binding both submitted the form twice
            'sort_order' => 40,
            'aclResource' => 'Defox_SEOSuite::template_save',
        ];
    }
}
