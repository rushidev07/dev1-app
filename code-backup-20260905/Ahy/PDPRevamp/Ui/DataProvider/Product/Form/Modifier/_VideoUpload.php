<?php

declare(strict_types=1);

namespace Ahy\PDPRevamp\Ui\DataProvider\Product\Form\Modifier;

use Magento\Framework\UrlInterface;
use Magento\Ui\DataProvider\Modifier\ModifierInterface;

/**
 * Adds a "Product Video Upload" field to the product edit page: a standard
 * Magento_Ui/js/form/element/file-uploader field - the same declarative
 * component Magento itself uses for every other admin file/image upload -
 * pointed at Controller/Adminhtml/Video/Upload.php, which saves the file to
 * pub/media/videos and returns its public URL.
 *
 * Deliberately does not try to inject anything into Magento_ProductVideo's
 * own native "Add Video" dialog (an earlier version did this via a
 * MutationObserver-based JS mixin reaching into that dialog's DOM, which
 * proved fragile and unreliable). Once uploaded here, the file's URL is
 * visible via the uploader's own file link/thumbnail - copy it into the
 * native Add Video dialog's Url field to actually attach it to the gallery.
 */
class VideoUpload implements ModifierInterface
{
    private const DATA_SCOPE_FIELDSET = 'video_upload_fieldset';
    private const DATA_SCOPE_FIELD = 'video_upload';

    private UrlInterface $urlBuilder;

    public function __construct(UrlInterface $urlBuilder)
    {
        $this->urlBuilder = $urlBuilder;
    }

    public function modifyData(array $data): array
    {
        return $data;
    }

    public function modifyMeta(array $meta): array
    {
        $meta[self::DATA_SCOPE_FIELDSET] = [
            'arguments' => [
                'data' => [
                    'config' => [
                        'label' => __('Product Video Upload'),
                        'collapsible' => true,
                        'componentType' => 'fieldset',
                        'dataScope' => self::DATA_SCOPE_FIELDSET,
                        'sortOrder' => 110,
                    ],
                ],
            ],
            'children' => [
                self::DATA_SCOPE_FIELD => [
                    'arguments' => [
                        'data' => [
                            'config' => [
                                'componentType' => 'fileUploader',
                                'formElement' => 'fileUploader',
                                'component' => 'Magento_Ui/js/form/element/file-uploader',
                                'elementTmpl' => 'ui/form/element/uploader/uploader',
                                'label' => __('Video File'),
                                'dataScope' => self::DATA_SCOPE_FIELD,
                                'sortOrder' => 10,
                                'required' => false,
                                'previewTmpl' => 'Magento_Catalog/image-preview',
                                'maxFileSize' => 209715200,
                                'allowedExtensions' => 'mp4 webm ogg',
                                'uploaderConfig' => [
                                    'url' => $this->urlBuilder->getUrl('ahy_pdprevamp/video/upload'),
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        return $meta;
    }
}
