<?php

namespace Marello\Bundle\PdfBundle\Provider;

use Marello\Bundle\PdfBundle\DependencyInjection\Configuration;
use Marello\Bundle\SalesBundle\Entity\SalesChannel;
use Oro\Bundle\AttachmentBundle\Entity\File;
use Oro\Bundle\AttachmentBundle\Manager\AttachmentManager;
use Oro\Bundle\AttachmentBundle\Manager\MediaCacheManager;
use Oro\Bundle\AttachmentBundle\Resizer\ImageResizer;
use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use Oro\Bundle\EntityBundle\ORM\DoctrineHelper;

class LogoProvider
{
    protected $configManager;

    protected $doctrineHelper;

    protected $attachmentManager;

    protected $mediaCacheManager;

    protected $imageResizer;

    protected $projectDir;

    public function __construct(
        ConfigManager $configManager,
        DoctrineHelper $doctrineHelper,
        AttachmentManager $attachmentManager,
        MediaCacheManager $mediaCacheManager,
        ImageResizer $imageResizer,
        $projectDir
    ) {
        $this->configManager = $configManager;
        $this->doctrineHelper = $doctrineHelper;
        $this->attachmentManager = $attachmentManager;
        $this->mediaCacheManager = $mediaCacheManager;
        $this->imageResizer = $imageResizer;
        $this->projectDir = $projectDir;
    }

    public function getInvoiceLogo(SalesChannel $salesChannel, $absolute = false)
    {
        $path = null;

        $id = $this->getInvoiceLogoId($salesChannel);
        if ($id !== null) {
            $entity = $this->getInvoiceLogoEntity($id);
            if ($entity !== null) {
                $path = $this->getInvoiceLogoAttachment($entity, $absolute);
            }
        }

        return $path;
    }

    protected function getInvoiceLogoId(SalesChannel $salesChannel)
    {
        $key = sprintf('%s.%s', Configuration::CONFIG_NAME, Configuration::CONFIG_KEY_LOGO);

        return $this->configManager->get($key, false, false, $salesChannel);
    }

    protected function getInvoiceLogoEntity($id)
    {
        return $this->doctrineHelper
            ->getEntityRepositoryForClass(File::class)
            ->find($id)
        ;
    }

    protected function getInvoiceLogoAttachment(File $entity, $absolute)
    {
        $path = $this->attachmentManager->getFilteredImageUrl($entity, 'invoice_logo');
        $absolutePath = $this->projectDir.'/public'.$path;

        if (!file_exists($absolutePath)) {
            $this->fetchImage($entity, $path);
        }

        if ($absolute) {
            return $path = $absolutePath;
        }

        return $path;
    }

    protected function fetchImage(File $entity, $path)
    {
        $resized = $this->imageResizer->resizeImage($entity, 'invoice_logo');
        $this->mediaCacheManager->store($resized->getContent(), $path);
    }
}
