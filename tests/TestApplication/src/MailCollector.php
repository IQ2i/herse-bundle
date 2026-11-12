<?php

declare(strict_types=1);

/*
 * This file is part of the Herse Bundle.
 *
 * (c) Loïc Sapone <loic@sapone.fr>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace IQ2i\HerseBundle\Tests\TestApplication;

use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mime\RawMessage;

/**
 * Keeps the emails handed to the transport: the message logger of the framework is a private service.
 */
final class MailCollector
{
    /** @var list<RawMessage> */
    private array $messages = [];

    public function onMessage(MessageEvent $event): void
    {
        $this->messages[] = $event->getMessage();
    }

    /**
     * @return list<RawMessage>
     */
    public function messages(): array
    {
        return $this->messages;
    }

    public function reset(): void
    {
        $this->messages = [];
    }
}
