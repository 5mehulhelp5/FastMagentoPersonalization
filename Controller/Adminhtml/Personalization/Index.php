<?php

declare(strict_types=1);

namespace ParkkTech\FastMagentoPersonalization\Controller\Adminhtml\Personalization;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\View\Result\PageFactory;

/**
 * The personalisation dashboard — A/B results, conversion and sales curves, the weight trajectory.
 */
class Index extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'ParkkTech_FastMagento::personalization';

    public function __construct(
        Context $context,
        private readonly PageFactory $resultPageFactory
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $resultPage = $this->resultPageFactory->create();
        $resultPage->setActiveMenu('ParkkTech_FastMagento::personalization');
        $resultPage->getConfig()->getTitle()->prepend(__('Personalisation'));
        return $resultPage;
    }
}
