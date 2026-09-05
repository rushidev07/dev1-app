<?php

declare(strict_types=1);

namespace Ahy\DiscountAlert\Service\Email;

/**
 * Everything needed to render and address one outgoing message. Immutable value object,
 * so the mailer needs no setter chain and no per-send state to reset.
 */
class Envelope
{
    /**
     * @param array<string, mixed> $templateVars
     * @param string[]             $to
     * @param string[]             $cc
     * @param string[]             $bcc
     * @param Attachment[]         $attachments
     */
    public function __construct(
        private readonly string $templateId,
        private readonly array $templateVars,
        private readonly array $to,
        private readonly int $storeId,
        private readonly string $senderIdentity = 'general',
        private readonly array $cc = [],
        private readonly array $bcc = [],
        private readonly array $attachments = []
    ) {}

    public function getTemplateId(): string
    {
        return $this->templateId;
    }

    /**
     * @return array<string, mixed>
     */
    public function getTemplateVars(): array
    {
        return $this->templateVars;
    }

    /**
     * @return string[]
     */
    public function getTo(): array
    {
        return $this->to;
    }

    public function getStoreId(): int
    {
        return $this->storeId;
    }

    /**
     * Sender identity from Stores > Configuration > General > Store Email Addresses.
     */
    public function getSenderIdentity(): string
    {
        return $this->senderIdentity;
    }

    /**
     * @return string[]
     */
    public function getCc(): array
    {
        return $this->cc;
    }

    /**
     * @return string[]
     */
    public function getBcc(): array
    {
        return $this->bcc;
    }

    /**
     * @return Attachment[]
     */
    public function getAttachments(): array
    {
        return $this->attachments;
    }
}
