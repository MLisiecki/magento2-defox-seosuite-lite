<?php
/**
 * @package     Defox_SEOSuite
 * @author      Marcin Lisiecki
 * @copyright   Copyright (c) 2025 deFox
 * @license     GNU General Public License v3.0
 */

declare(strict_types=1);

namespace Defox\SEOSuite\Controller\Adminhtml\Template;

use Defox\SEOSuite\Api\Data\TemplateInterface;
use Defox\SEOSuite\Api\TemplateRepositoryInterface;
use Defox\SEOSuite\Model\Template;
use Defox\SEOSuite\Model\TemplateFactory;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Framework\Exception\LocalizedException;

/**
 * Template save controller
 * 
 * Saves a meta tag template
 */
class Save extends Action implements HttpPostActionInterface
{
    /**
     * Authorization level
     */
    public const ADMIN_RESOURCE = 'Defox_SEOSuite::template_save';

    /**
     * Fields of defox_seosuite_template_form.xml (template_id is taken from the loaded model only)
     */
    private const FORM_FIELDS = [
        'name', 'type', 'entity_type', 'store_id', 'priority', 'is_active', 'conditions_serialized',
        'meta_title_template', 'meta_description_template', 'meta_keywords_template', 'meta_robots_template',
        'og_title_template', 'og_description_template', 'og_type_template', 'og_image_template',
    ];

    /**
     * @var DataPersistorInterface
     */
    protected DataPersistorInterface $dataPersistor;

    /**
     * @var TemplateFactory
     */
    protected TemplateFactory $templateFactory;

    /**
     * @var TemplateRepositoryInterface
     */
    protected TemplateRepositoryInterface $templateRepository;

    /**
     * @param Context $context
     * @param DataPersistorInterface $dataPersistor
     * @param TemplateFactory $templateFactory
     * @param TemplateRepositoryInterface $templateRepository
     */
    public function __construct(
        Context $context,
        DataPersistorInterface $dataPersistor,
        TemplateFactory $templateFactory,
        TemplateRepositoryInterface $templateRepository
    ) {
        parent::__construct($context);
        $this->dataPersistor = $dataPersistor;
        $this->templateFactory = $templateFactory;
        $this->templateRepository = $templateRepository;
    }

    /**
     * Execute action
     *
     * @return \Magento\Framework\Controller\ResultInterface
     */
    public function execute()
    {
        $resultRedirect = $this->resultRedirectFactory->create();
        $data = $this->getRequest()->getPostValue();
        
        if ($data) {
            if (isset($data['is_active'])) {
                $data['is_active'] = ($data['is_active'] === 'true' || (int)$data['is_active'] === 1) ? 
                    Template::STATUS_ENABLED : Template::STATUS_DISABLED;
            }
            
            if (empty($data['template_id'])) {
                $data['template_id'] = null;
            }

            $model = $this->templateFactory->create();

            $id = $this->getRequest()->getParam('template_id');
            if ($id) {
                try {
                    $model = $this->templateRepository->getById((int)$id);
                } catch (\Exception $e) {
                    $this->messageManager->addErrorMessage(__('This template no longer exists.'));
                    return $resultRedirect->setPath('*/*/');
                }
            }

            try {
                $model->addData($this->filterFormData($data));
                $this->templateRepository->save($model);
                $this->messageManager->addSuccessMessage(__('You saved the template.'));
                $this->dataPersistor->clear('defox_seosuite_template');

                if ($this->getRequest()->getParam('back')) {
                    return $resultRedirect->setPath('*/*/edit', ['template_id' => $model->getId()]);
                }
                return $resultRedirect->setPath('*/*/');
            } catch (LocalizedException $e) {
                $this->messageManager->addErrorMessage($e->getMessage());
            } catch (\Exception $e) {
                $this->messageManager->addExceptionMessage($e, __('Something went wrong while saving the template.'));
            }

            $this->dataPersistor->set('defox_seosuite_template', $data);
            return $resultRedirect->setPath('*/*/edit', ['template_id' => $this->getRequest()->getParam('template_id')]);
        }
        
        return $resultRedirect->setPath('*/*/');
    }

    /**
     * Keep only form fields and cast them to scalar column types
     *
     * @param array $data
     * @return array
     * @throws LocalizedException
     */
    private function filterFormData(array $data): array
    {
        $fields = array_intersect_key($data, array_flip(self::FORM_FIELDS));
        foreach ($fields as $field => $value) {
            if ($value !== null && !is_scalar($value)) {
                throw new LocalizedException(__('Invalid value for "%1".', $field));
            }
            if (in_array($field, ['store_id', 'priority', 'is_active'], true)) {
                $fields[$field] = (int)$value;
            } elseif ($value !== null) {
                $fields[$field] = (string)$value;
            }
        }
        return $fields;
    }
}
