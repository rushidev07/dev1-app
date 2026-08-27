<?php

declare(strict_types=1);

namespace Ahy\DiscountAlert\Service\Email;

use Magento\Framework\Mail\MimeInterface;

/**
 * A file to be attached to an outgoing message. Immutable value object.
 */
class Attachment
{
    public function __construct(
        private readonly string $content,
        private readonly string $fileName,
        private readonly string $mimeType = MimeInterface::TYPE_OCTET_STREAM
    ) {}

    public function getContent(): string
    {
        return $this->content;
    }

    public function getFileName(): string
    {
        return $this->fileName;
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }
}
