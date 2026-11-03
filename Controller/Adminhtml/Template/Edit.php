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
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\View\Result\PageFactory;

/**
 * Template edit controller
 * 
 * Displays the edit form for a meta tag template
 */
class Edit extends Action
{
    /**
     * Authorization level
     */
    public const ADMIN_RESOURCE = 'Defox_SEOSuite::template';

    /**
     * @var PageFactory
     */
    protected PageFactory $resultPageFactory;

    /**
     * @var TemplateRepositoryInterface
     */
    protected TemplateRepositoryInterface $templateRepository;

    /**
     * @param Context $context
     * @param PageFactory $resultPageFactory
     * @param TemplateRepositoryInterface $templateRepository
     */
    public function __construct(
        Context $context,
        PageFactory $resultPageFactory,
        TemplateRepositoryInterface $templateRepository
    ) {
        parent::__construct($context);
        $this->resultPageFactory = $resultPageFactory;
        $this->templateRepository = $templateRepository;
    }

    /**
     * Execute action
     *
     * @return \Magento\Framework\Controller\ResultInterface
     */
    public function execute()
    {
        $id = $this->getRequest()->getParam('template_id');
        if ($id) {
            try {
                $this->templateRepository->getById((int)$id);
            } catch (NoSuchEntityException $e) {
                $this->messageManager->addErrorMessage(__('This template no longer exists.'));
                $resultRedirect = $this->resultRedirectFactory->create();
                return $resultRedirect->setPath('*/*/');
            }
        }

        $resultPage = $this->resultPageFactory->create();
        $resultPage->setActiveMenu('Defox_SEOSuite::template');
        $resultPage->addBreadcrumb(__('SEO Suite'), __('SEO Suite'));
        $resultPage->addBreadcrumb(__('Meta Tag Templates'), __('Meta Tag Templates'));

        $title = $id ? __('Edit Meta Tag Template') : __('New Meta Tag Template');
        $resultPage->addBreadcrumb($title, $title);
        $resultPage->getConfig()->getTitle()->prepend($title);

        return $resultPage;
    }
}
