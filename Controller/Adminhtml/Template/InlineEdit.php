<?php
/**
 * @package     Defox_SEOSuite
 * @author      Marcin Lisiecki 
 * @copyright   Copyright (c) 2025 deFox
 * @license     GNU General Public License v3.0
 */

declare(strict_types=1);

namespace Defox\SEOSuite\Controller\Adminhtml\Template;

use Defox\SEOSuite\Api\TemplateRepositoryInterface;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\LocalizedException;

/**
 * Template inline edit controller
 * 
 * Handles inline editing of meta tag templates in the grid
 */
class InlineEdit extends Action implements HttpPostActionInterface
{
    /**
     * Authorization level
     */
    public const ADMIN_RESOURCE = 'Defox_SEOSuite::template_save';

    /**
     * Columns with an inline editor in defox_seosuite_template_listing.xml
     */
    private const EDITABLE_FIELDS = ['name', 'type', 'entity_type', 'priority', 'is_active'];

    /**
     * @var JsonFactory
     */
    protected JsonFactory $jsonFactory;

    /**
     * @var TemplateRepositoryInterface
     */
    protected TemplateRepositoryInterface $templateRepository;

    /**
     * @param Context $context
     * @param JsonFactory $jsonFactory
     * @param TemplateRepositoryInterface $templateRepository
     */
    public function __construct(
        Context $context,
        JsonFactory $jsonFactory,
        TemplateRepositoryInterface $templateRepository
    ) {
        parent::__construct($context);
        $this->jsonFactory = $jsonFactory;
        $this->templateRepository = $templateRepository;
    }

    /**
     * Execute action
     *
     * @return \Magento\Framework\Controller\ResultInterface
     */
    public function execute()
    {
        $resultJson = $this->jsonFactory->create();
        $error = false;
        $messages = [];

        if ($this->getRequest()->getParam('isAjax')) {
            $postItems = $this->getRequest()->getParam('items', []);
            
            if (!is_array($postItems) || !count($postItems)) {
                $messages[] = __('Please correct the data sent.');
                $error = true;
            } else {
                foreach ($postItems as $templateId => $item) {
                    $templateId = (int)$templateId;
                    try {
                        if (!is_array($item)
                            || (isset($item['template_id']) && (int)$item['template_id'] !== $templateId)
                        ) {
                            throw new LocalizedException(__('Template ID mismatch.'));
                        }
                        $template = $this->templateRepository->getById($templateId);
                        $data = array_intersect_key($item, array_flip(self::EDITABLE_FIELDS));
                        foreach ($data as $field => $value) {
                            if (!is_scalar($value)) {
                                throw new LocalizedException(__('Invalid value for "%1".', $field));
                            }
                            $data[$field] = in_array($field, ['priority', 'is_active'], true)
                                ? (int)$value
                                : (string)$value;
                        }
                        $template->addData($data);
                        $this->templateRepository->save($template);
                    } catch (LocalizedException $e) {
                        $messages[] = $this->getErrorWithTemplateId($templateId, $e->getMessage());
                        $error = true;
                    } catch (\Exception $e) {
                        $messages[] = $this->getErrorWithTemplateId(
                            $templateId,
                            (string)__('Something went wrong while saving the template.')
                        );
                        $error = true;
                    }
                }
            }
        }

        return $resultJson->setData([
            'messages' => $messages,
            'error' => $error
        ]);
    }

    /**
     * Add template ID to error message
     *
     * @param int $templateId
     * @param string $errorText
     * @return string
     */
    protected function getErrorWithTemplateId(int $templateId, string $errorText): string
    {
        return '[Template ID: ' . $templateId . '] ' . $errorText;
    }
}
