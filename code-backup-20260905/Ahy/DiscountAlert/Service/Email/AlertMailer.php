<?php

declare(strict_types=1);

namespace Ahy\DiscountAlert\Service\Email;

use Magento\Framework\App\TemplateTypesInterface;
use Magento\Framework\Mail\AddressConverter;
use Magento\Framework\Mail\EmailMessageInterfaceFactory;
use Magento\Framework\Mail\MimeInterface;
use Magento\Framework\Mail\MimeMessageInterfaceFactory;
use Magento\Framework\Mail\MimePartInterface;
use Magento\Framework\Mail\MimePartInterfaceFactory;
use Magento\Framework\Mail\Template\FactoryInterface as TemplateFactoryInterface;
use Magento\Framework\Mail\Template\SenderResolverInterface;
use Magento\Framework\Mail\TransportInterfaceFactory;

/**
 * Renders an email template and sends it, with attachments and CC/BCC support.
 *
 * Composed entirely from the framework's mail factories rather than extending
 * Magento\Framework\Mail\Template\TransportBuilder: core's builder cannot carry
 * attachments, and subclassing it means reaching into protected state that is not part
 * of any public contract. Building the message here keeps every dependency explicit and
 * the subject line intact.
 */
class AlertMailer
{
    public function __construct(
        private readonly TemplateFactoryInterface $templateFactory,
        private readonly MimePartInterfaceFactory $mimePartFactory,
        private readonly MimeMessageInterfaceFactory $mimeMessageFactory,
        private readonly EmailMessageInterfaceFactory $emailMessageFactory,
        private readonly TransportInterfaceFactory $transportFactory,
        private readonly AddressConverter $addressConverter,
        private readonly SenderResolverInterface $senderResolver
    ) {}

    /**
     * @throws \Magento\Framework\Exception\MailException
     */
    public function send(Envelope $envelope): void
    {
        $template = $this->templateFactory->get($envelope->getTemplateId())
            ->setVars($envelope->getTemplateVars())
            ->setOptions([
                'area'  => \Magento\Framework\App\Area::AREA_ADMINHTML,
                'store' => $envelope->getStoreId(),
            ]);

        $bodyPart = $this->mimePartFactory->create([
            'content' => $template->processTemplate(),
            'type'    => $template->getType() === TemplateTypesInterface::TYPE_TEXT
                ? MimeInterface::TYPE_TEXT
                : MimeInterface::TYPE_HTML,
        ]);

        $parts = \array_merge([$bodyPart], $this->buildAttachmentParts($envelope->getAttachments()));

        $sender = $this->senderResolver->resolve($envelope->getSenderIdentity(), $envelope->getStoreId());

        $messageData = [
            'body'     => $this->mimeMessageFactory->create(['parts' => $parts]),
            'subject'  => \html_entity_decode((string) $template->getSubject(), ENT_QUOTES),
            'encoding' => $bodyPart->getCharset(),
            'from'     => [$this->addressConverter->convert($sender['email'], (string) $sender['name'])],
            'to'       => $this->convertAddresses($envelope->getTo()),
        ];

        if ($envelope->getCc()) {
            $messageData['cc'] = $this->convertAddresses($envelope->getCc());
        }

        if ($envelope->getBcc()) {
            $messageData['bcc'] = $this->convertAddresses($envelope->getBcc());
        }

        $this->transportFactory
            ->create(['message' => $this->emailMessageFactory->create($messageData)])
            ->sendMessage();
    }

    /**
     * @param  Attachment[] $attachments
     * @return MimePartInterface[]
     */
    private function buildAttachmentParts(array $attachments): array
    {
        $parts = [];

        foreach ($attachments as $attachment) {
            $parts[] = $this->mimePartFactory->create([
                'content'     => $attachment->getContent(),
                'type'        => $attachment->getMimeType(),
                'fileName'    => $attachment->getFileName(),
                'disposition' => MimeInterface::DISPOSITION_ATTACHMENT,
                'encoding'    => MimeInterface::ENCODING_BASE64,
            ]);
        }

        return $parts;
    }

    /**
     * @param  string[] $emails
     * @return \Magento\Framework\Mail\Address[]
     */
    private function convertAddresses(array $emails): array
    {
        $addresses = [];

        foreach ($emails as $email) {
            $addresses[] = $this->addressConverter->convert($email);
        }

        return $addresses;
    }
}
