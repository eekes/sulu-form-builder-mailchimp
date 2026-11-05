<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderMailchimpBundle\Tests\Unit;

use Eekes\Sulu\FormBuilderBundle\Application\Integration\IntegrationException;
use Eekes\Sulu\FormBuilderBundle\Application\Integration\IntegrationRequest;
use Eekes\Sulu\FormBuilderBundle\Domain\Entity\Form;
use Eekes\Sulu\FormBuilderBundle\Domain\Entity\Submission;
use Eekes\Sulu\FormBuilderMailchimpBundle\MailchimpClient;
use Eekes\Sulu\FormBuilderMailchimpBundle\MailchimpIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(MailchimpIntegration::class)]
#[CoversClass(MailchimpClient::class)]
final class MailchimpIntegrationTest extends TestCase
{
    /**
     * @var list<array{method: string, url: string, body: mixed, auth: mixed}>
     */
    private array $calls = [];

    public function testAVisitorIsAddedOrUpdatedInTheAudienceWithDoubleOptIn(): void
    {
        $result = $this->integration()->execute($this->request(['email' => 'Jane@Example.com', 'first_name' => 'Jane', 'last_name' => 'Doe'], ['audience' => 'list1']));

        self::assertTrue($result->succeeded);
        self::assertCount(1, $this->calls);
        self::assertSame('PUT', $this->calls[0]['method']);
        self::assertSame('https://us21.api.mailchimp.com/3.0/lists/list1/members/'.md5('jane@example.com'), $this->calls[0]['url'], 'Mailchimp identifies a member by the hash of the lowercase address.');
        self::assertSame(['email_address' => 'Jane@Example.com', 'status_if_new' => 'pending', 'merge_fields' => ['FNAME' => 'Jane', 'LNAME' => 'Doe']], $this->calls[0]['body']);
        self::assertSame('Jane@Example.com is pending in the audience.', $result->message);
    }

    public function testWithoutDoubleOptInANewMemberIsSubscribedRightAway(): void
    {
        $this->integration()->execute($this->request(['email' => 'jane@example.com'], ['audience' => 'list1', 'double_opt_in' => false]));

        self::assertIsArray($this->calls[0]['body']);
        self::assertSame('subscribed', $this->calls[0]['body']['status_if_new']);
    }

    public function testFieldsOfTheEditorsOwnBecomeMergeFields(): void
    {
        $this->integration()->execute($this->request(['email' => 'jane@example.com', 'company' => 'Acme', 'phone' => '0470', 'empty' => ''], ['audience' => 'list1']));

        self::assertIsArray($this->calls[0]['body']);
        self::assertSame(['COMPANY' => 'Acme', 'PHONE' => '0470'], $this->calls[0]['body']['merge_fields']);
    }

    public function testTagsAreAddedToTheMember(): void
    {
        $this->integration()->execute($this->request(['email' => 'jane@example.com'], ['audience' => 'list1', 'tags' => 'website, contact , ']));

        self::assertCount(2, $this->calls);
        self::assertSame('POST', $this->calls[1]['method']);
        self::assertStringEndsWith('/tags', $this->calls[1]['url']);
        self::assertSame(['tags' => [['name' => 'website', 'status' => 'active'], ['name' => 'contact', 'status' => 'active']]], $this->calls[1]['body']);
    }

    public function testTheApiKeyIsSentAsBasicAuthAndChoosesTheDataCenter(): void
    {
        $this->integration('abc123-eu7')->execute($this->request(['email' => 'jane@example.com'], ['audience' => 'list1']));

        self::assertStringStartsWith('https://eu7.api.mailchimp.com/3.0/', $this->calls[0]['url']);
        self::assertSame('Basic '.base64_encode('sulu:abc123-eu7'), $this->calls[0]['auth']);
    }

    public function testARefusalCarriesMailchimpsExplanation(): void
    {
        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessage('Mailchimp refused (400): jane@fake looks fake or invalid, please enter a real email address.');

        $this->integration(status: 400, body: '{"title":"Invalid Resource","detail":"jane@fake looks fake or invalid, please enter a real email address."}')
            ->execute($this->request(['email' => 'jane@fake'], ['audience' => 'list1']));
    }

    public function testOnlyAFailureThatMayPassLaterIsRetryable(): void
    {
        foreach ([400 => false, 404 => false, 429 => true, 500 => true, 503 => true] as $status => $retryable) {
            try {
                $this->integration(status: $status, body: '{"title":"Error"}')->execute($this->request(['email' => 'jane@example.com'], ['audience' => 'list1']));
                self::fail('A refusal has to fail.');
            } catch (IntegrationException $exception) {
                self::assertSame($retryable, $exception->retryable, 'HTTP '.$status);
            }
        }
    }

    public function testTheStatusSaysWhetherAnApiKeyIsConfigured(): void
    {
        $ready = $this->integration()->getStatus();
        self::assertTrue($ready->isReady());
        self::assertSame(['eekes_form_builder_mailchimp.status.data_center' => 'us21'], $ready->details);

        $missing = (new MailchimpIntegration(new MailchimpClient(new MockHttpClient(), null), 'MAILCHIMP_API_KEY'))->getStatus();
        self::assertFalse($missing->isReady());
        self::assertSame('eekes_form_builder_mailchimp.status.not_configured', $missing->message);
        self::assertCount(1, $missing->requirements);
        self::assertFalse($missing->requirements[0]->met);
        self::assertSame('eekes_form_builder.requirement.set_env', $missing->requirements[0]->hint);
        self::assertSame(['%env%' => 'MAILCHIMP_API_KEY'], $missing->requirements[0]->parameters, 'It names the variable the project uses.');

        $wrongFormat = $this->integration('no-data-center')->getStatus();
        self::assertFalse($wrongFormat->isReady(), 'A key without its data center cannot be used.');
        self::assertSame([true, false], array_map(static fn ($requirement): bool => $requirement->met, $wrongFormat->requirements));
        self::assertSame('eekes_form_builder_mailchimp.status.key_format_wrong', $wrongFormat->requirements[1]->hint);
        self::assertStringNotContainsString('no-data-center', json_encode($wrongFormat, \JSON_THROW_ON_ERROR), 'The key itself is never shown.');
    }

    public function testTheConnectionTestAsksWhoseKeyItIs(): void
    {
        $status = $this->integration(body: '{"account_name":"Acme","email":"info@acme.example"}')->testConnection();

        self::assertTrue($status->isReady());
        self::assertSame(['eekes_form_builder_mailchimp.status.account' => 'Acme', 'eekes_form_builder_mailchimp.status.email' => 'info@acme.example'], $status->details);
        self::assertSame('GET', $this->calls[0]['method']);
        self::assertSame('https://us21.api.mailchimp.com/3.0/?fields=account_name,email', $this->calls[0]['url']);
        self::assertFalse($this->integration()->testSendsData());

        self::assertFalse($this->integration(null)->testConnection()->isReady(), 'Without a key nothing is asked.');
        self::assertCount(1, $this->calls);

        $this->expectException(IntegrationException::class);
        $this->integration(status: 401, body: '{"title":"API Key Invalid"}')->testConnection();
    }

    public function testWithoutAnApiKeyNothingIsCalled(): void
    {
        try {
            $this->integration(null)->execute($this->request(['email' => 'jane@example.com'], ['audience' => 'list1']));
            self::fail('An unconfigured integration has to fail.');
        } catch (IntegrationException $exception) {
            self::assertStringContainsString('Mailchimp is not configured', $exception->getMessage());
        }

        self::assertSame([], $this->calls);
    }

    public function testTheAudiencesOfTheAccountAreTheChoices(): void
    {
        $options = $this->integration(body: '{"lists":[{"id":"a1","name":"Newsletter"},{"id":"b2","name":"Customers"}]}')->getOptions('subscribe', 'audience');

        self::assertSame([['value' => 'a1', 'label' => 'Newsletter'], ['value' => 'b2', 'label' => 'Customers']], $options);
        self::assertSame('https://us21.api.mailchimp.com/3.0/lists?count=1000&fields=lists.id,lists.name', $this->calls[0]['url']);
    }

    public function testTheMergeFieldsOfTheAudienceAreOfferedAsFields(): void
    {
        $body = json_encode(['merge_fields' => [
            ['tag' => 'MMERGE8', 'name' => 'Telefoon', 'type' => 'text', 'required' => false, 'display_order' => 10, 'options' => ['size' => 25]],
            ['tag' => 'FNAME', 'name' => 'Voornaam', 'type' => 'text', 'required' => false, 'display_order' => 2, 'options' => []],
            ['tag' => 'COMPANY', 'name' => 'Company', 'type' => 'text', 'required' => true, 'display_order' => 5, 'options' => []],
            ['tag' => 'MMERGE6', 'name' => 'Address', 'type' => 'address', 'required' => false, 'display_order' => 6, 'options' => ['default_country' => 16]],
            ['tag' => 'SIZE', 'name' => 'Shirt size', 'type' => 'dropdown', 'required' => false, 'display_order' => 7, 'options' => ['choices' => ['S', 'M', 'L']]],
            ['tag' => 'BIRTHDAY', 'name' => 'Birthday', 'type' => 'birthday', 'required' => false, 'display_order' => 8, 'options' => ['date_format' => 'MM/DD']],
            ['tag' => 'EMPLOYEES', 'name' => '', 'type' => 'number', 'required' => false, 'display_order' => 9, 'options' => []],
        ]], \JSON_THROW_ON_ERROR);

        $fields = $this->integration(body: $body)->getDynamicFields('subscribe', ['audience' => 'list 1']);

        self::assertSame('GET', $this->calls[0]['method']);
        self::assertStringStartsWith('https://us21.api.mailchimp.com/3.0/lists/list%201/merge-fields?count=1000&fields=', $this->calls[0]['url']);
        self::assertSame(['COMPANY', 'SIZE', 'BIRTHDAY', 'EMPLOYEES', 'MMERGE8'], array_map(static fn ($field): string => $field->key, $fields), 'In Mailchimp\'s order, without FNAME (declared) and the address (not supported).');
        self::assertSame(['Company', 'Shirt size', 'Birthday', 'EMPLOYEES', 'Telefoon'], array_map(static fn ($field): string => $field->label, $fields), 'The name the account gave it, or the tag.');
        self::assertTrue($fields[0]->required);
        self::assertSame('S, M, L', $fields[1]->help);
        self::assertSame('MM/DD', $fields[2]->help);
        self::assertSame('number', $fields[3]->type);
        self::assertNull($fields[4]->help);
    }

    public function testWithoutAnAudienceNoMergeFieldsAreAskedFor(): void
    {
        self::assertSame([], $this->integration()->getDynamicFields('subscribe', []));
        self::assertSame([], $this->integration()->getDynamicFields('subscribe', ['audience' => '']));
        self::assertSame([], $this->integration()->getDynamicFields('unknown', ['audience' => 'list1']));
        self::assertSame([], $this->calls);
    }

    public function testAMappedMergeFieldIsSentByItsTag(): void
    {
        $this->integration()->execute($this->request(['email' => 'jane@example.com', 'MMERGE8' => '0470 12 34 56'], ['audience' => 'list1']));

        self::assertIsArray($this->calls[0]['body']);
        self::assertSame(['MMERGE8' => '0470 12 34 56'], $this->calls[0]['body']['merge_fields']);
    }

    public function testTheActionNeedsAnEmailAndAnAudience(): void
    {
        $action = $this->integration()->getActions()[0];

        self::assertTrue($action->field('email')?->required);
        self::assertTrue($action->setting('audience')?->required);
        self::assertTrue($action->setting('audience')->dynamicOptions);
        self::assertTrue($action->allowCustomFields);
    }

    private function integration(?string $apiKey = 'abc123-us21', int $status = 200, string $body = '{"id":"m1","status":"pending"}'): MailchimpIntegration
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options) use ($status, $body): MockResponse {
            $headers = \is_array($options['normalized_headers'] ?? null) ? $options['normalized_headers'] : [];
            $authorization = \is_array($headers['authorization'] ?? null) ? $headers['authorization'][0] ?? null : null;

            $this->calls[] = [
                'method' => $method,
                'url' => $url,
                'body' => \is_string($options['body'] ?? null) && $options['body'] !== '' ? json_decode($options['body'], true) : null,
                'auth' => \is_string($authorization) ? substr($authorization, \strlen('Authorization: ')) : null,
            ];

            return new MockResponse($body, ['http_code' => $status]);
        });

        return new MailchimpIntegration(new MailchimpClient($client, $apiKey));
    }

    /**
     * @param array<string, mixed> $values
     * @param array<string, mixed> $settings
     */
    private function request(array $values, array $settings): IntegrationRequest
    {
        return new IntegrationRequest('subscribe', $values, $settings, new Submission(new Form('newsletter', 'Newsletter'), 'en', []), 'Newsletter');
    }
}
