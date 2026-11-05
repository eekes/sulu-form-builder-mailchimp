<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderMailchimpBundle;

use Eekes\Sulu\FormBuilderBundle\Application\Integration\IntegrationException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The few calls of the Mailchimp Marketing API v3 the integration needs.
 *
 * Errors become IntegrationExceptions with Mailchimp's own explanation (its "detail"), which is
 * what ends up in the run log of a submission. The API key never does.
 */
final readonly class MailchimpClient
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private ?string $apiKey,
        private float $timeout = 10.0,
    ) {
    }

    /**
     * The data center the API key belongs to ("us21" from "abc123-us21"), or null when there is
     * no key or it does not look like one.
     */
    /**
     * Only whether there is a key: the key itself never leaves this class.
     */
    public function hasApiKey(): bool
    {
        return $this->apiKey !== null && $this->apiKey !== '';
    }

    public function dataCenter(): ?string
    {
        return preg_match('/-([a-z]+\d+)$/', $this->apiKey ?? '', $match) === 1 ? $match[1] : null;
    }

    /**
     * @param null|array<string, mixed> $body
     *
     * @return array<mixed>
     */
    public function request(string $method, string $path, ?array $body = null): array
    {
        $apiKey = $this->apiKey ?? '';

        if (preg_match('/-([a-z]+\d+)$/', $apiKey, $match) !== 1) {
            throw new IntegrationException('Mailchimp is not configured: set eekes_sulu_form_builder_mailchimp.api_key to an API key like "abc123-us21".', $body);
        }

        $options = ['auth_basic' => ['sulu', $apiKey], 'timeout' => $this->timeout];

        if ($body !== null) {
            $options['json'] = $body;
        }

        try {
            $response = $this->httpClient->request($method, \sprintf('https://%s.api.mailchimp.com/3.0/%s', $match[1], ltrim($path, '/')), $options);
            $status = $response->getStatusCode();
            $content = $response->getContent(false);
        } catch (ExceptionInterface $exception) {
            throw new IntegrationException('Mailchimp could not be reached: '.$exception->getMessage(), $body, null, $exception, retryable: true);
        }

        $data = $content === '' ? [] : json_decode($content, true);
        $data = \is_array($data) ? $data : ['body' => mb_substr($content, 0, 2000)];

        if ($status >= 300) {
            $detail = \is_string($data['detail'] ?? null) ? $data['detail'] : (\is_string($data['title'] ?? null) ? $data['title'] : 'HTTP '.$status);

            // A server error or "too many requests" may pass later; anything else is a refusal.
            throw new IntegrationException(\sprintf('Mailchimp refused (%d): %s', $status, $detail), $body, $data, retryable: $status >= 500 || $status === 429);
        }

        return $data;
    }
}
