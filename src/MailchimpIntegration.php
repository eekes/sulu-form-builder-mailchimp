<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderMailchimpBundle;

use Eekes\Sulu\FormBuilderBundle\Application\Integration\AsFormIntegration;
use Eekes\Sulu\FormBuilderBundle\Application\Integration\DynamicFieldsIntegrationInterface;
use Eekes\Sulu\FormBuilderBundle\Application\Integration\IntegrationAction;
use Eekes\Sulu\FormBuilderBundle\Application\Integration\IntegrationException;
use Eekes\Sulu\FormBuilderBundle\Application\Integration\IntegrationRequest;
use Eekes\Sulu\FormBuilderBundle\Application\Integration\IntegrationRequirement;
use Eekes\Sulu\FormBuilderBundle\Application\Integration\IntegrationResult;
use Eekes\Sulu\FormBuilderBundle\Application\Integration\IntegrationStatus;
use Eekes\Sulu\FormBuilderBundle\Application\Integration\Setting;
use Eekes\Sulu\FormBuilderBundle\Application\Integration\StatusAwareIntegrationInterface;
use Eekes\Sulu\FormBuilderBundle\Application\Integration\TargetField;
use Eekes\Sulu\FormBuilderBundle\Application\Integration\TestableIntegrationInterface;

/**
 * Subscribes the visitor to a Mailchimp audience.
 *
 * The member is created or updated in one call (Mailchimp's "add or update"), so a visitor who
 * subscribes twice is not an error. With double opt-in a new member gets Mailchimp's
 * confirmation e-mail first; an existing member keeps their status either way, so nobody who
 * unsubscribed is silently subscribed again.
 *
 * The merge fields of the chosen audience are offered in the flow modal, so editors map MMERGE7
 * or COMPANY without typing the tag. Fields of the editor's own are sent as merge fields too, by
 * their name in upper case: a field "company" fills the merge tag COMPANY.
 */
#[AsFormIntegration(key: 'mailchimp', title: 'eekes_form_builder_mailchimp.title', icon: 'su-envelope')]
final readonly class MailchimpIntegration implements StatusAwareIntegrationInterface, TestableIntegrationInterface, DynamicFieldsIntegrationInterface
{
    public const string ACTION_SUBSCRIBE = 'subscribe';

    private const array MERGE_FIELDS = ['first_name' => 'FNAME', 'last_name' => 'LNAME', 'phone' => 'PHONE'];

    /**
     * Merge field types that take more than one answer: an address is an object of street, city,
     * zip and country.
     */
    private const array UNSUPPORTED_TYPES = ['address'];

    /**
     * @param null|string $apiKeyEnv the environment variable the API key comes from
     */
    public function __construct(
        private MailchimpClient $client,
        private ?string $apiKeyEnv = null,
    ) {
    }

    public function getActions(): array
    {
        return [
            new IntegrationAction(
                key: self::ACTION_SUBSCRIBE,
                title: 'eekes_form_builder_mailchimp.subscribe',
                fields: [
                    new TargetField('email', 'eekes_form_builder_mailchimp.email', required: true, type: TargetField::TYPE_EMAIL),
                    new TargetField('first_name', 'eekes_form_builder_mailchimp.first_name'),
                    new TargetField('last_name', 'eekes_form_builder_mailchimp.last_name'),
                    new TargetField('phone', 'eekes_form_builder_mailchimp.phone', type: TargetField::TYPE_PHONE),
                ],
                settings: [
                    new Setting('audience', 'eekes_form_builder_mailchimp.audience', Setting::TYPE_SELECT, required: true, dynamicOptions: true),
                    new Setting('double_opt_in', 'eekes_form_builder_mailchimp.double_opt_in', Setting::TYPE_CHECKBOX, help: 'eekes_form_builder_mailchimp.double_opt_in_help', default: true),
                    new Setting('tags', 'eekes_form_builder_mailchimp.tags', help: 'eekes_form_builder_mailchimp.tags_help'),
                ],
                allowCustomFields: true,
                description: 'eekes_form_builder_mailchimp.subscribe_description',
            ),
        ];
    }

    public function getOptions(string $action, string $setting): array
    {
        $response = $this->client->request('GET', 'lists?count=1000&fields=lists.id,lists.name');
        $options = [];

        foreach (\is_array($response['lists'] ?? null) ? $response['lists'] : [] as $list) {
            if (\is_array($list) && \is_string($list['id'] ?? null) && \is_string($list['name'] ?? null)) {
                $options[] = ['value' => $list['id'], 'label' => $list['name']];
            }
        }

        return $options;
    }

    /**
     * The merge fields of the audience, in the order Mailchimp shows them, without the ones the
     * action declares already (FNAME, LNAME, PHONE). Labels and hints are Mailchimp's own: the
     * name the account gave the field, its choices or its date format.
     */
    public function getDynamicFields(string $action, array $settings): array
    {
        $audience = \is_string($settings['audience'] ?? null) ? $settings['audience'] : '';

        if ($action !== self::ACTION_SUBSCRIBE || $audience === '') {
            return [];
        }

        $response = $this->client->request('GET', \sprintf(
            'lists/%s/merge-fields?count=1000&fields=merge_fields.tag,merge_fields.name,merge_fields.type,merge_fields.required,merge_fields.display_order,merge_fields.options',
            rawurlencode($audience),
        ));
        $mergeFields = array_filter(
            \is_array($response['merge_fields'] ?? null) ? $response['merge_fields'] : [],
            static fn (mixed $field): bool => \is_array($field) && \is_string($field['tag'] ?? null) && \is_string($field['type'] ?? null),
        );
        usort($mergeFields, static fn (array $a, array $b): int => self::int($a['display_order'] ?? null) <=> self::int($b['display_order'] ?? null));
        $fields = [];

        foreach ($mergeFields as $field) {
            $tag = $field['tag'];
            $type = $field['type'];

            if (\in_array($tag, self::MERGE_FIELDS, true) || \in_array($type, self::UNSUPPORTED_TYPES, true)) {
                continue;
            }

            $options = \is_array($field['options'] ?? null) ? $field['options'] : [];
            $fields[] = new TargetField(
                $tag,
                \is_string($field['name'] ?? null) && $field['name'] !== '' ? $field['name'] : $tag,
                ($field['required'] ?? false) === true,
                match ($type) {
                    'number' => TargetField::TYPE_NUMBER,
                    'phone' => TargetField::TYPE_PHONE,
                    default => TargetField::TYPE_TEXT,
                },
                self::hint($type, $options),
            );
        }

        return $fields;
    }

    /**
     * What a value has to look like, in Mailchimp's own terms so it needs no translation: the
     * choices of a dropdown or radio field, the format of a date or birthday.
     *
     * @param array<mixed> $options
     */
    private static function hint(string $type, array $options): ?string
    {
        if (\in_array($type, ['dropdown', 'radio'], true) && \is_array($options['choices'] ?? null)) {
            $choices = array_filter($options['choices'], 'is_string');

            return $choices !== [] ? implode(', ', $choices) : null;
        }

        if (\in_array($type, ['date', 'birthday'], true) && \is_string($options['date_format'] ?? null)) {
            return $options['date_format'];
        }

        return null;
    }

    private static function int(mixed $value): int
    {
        return \is_int($value) ? $value : \PHP_INT_MAX;
    }

    public function execute(IntegrationRequest $request): IntegrationResult
    {
        $email = $request->string('email') ?? throw new IntegrationException('There is no e-mail address to subscribe.');
        $audience = $request->stringSetting('audience') ?? throw new IntegrationException('No audience is selected.');
        $memberPath = \sprintf('lists/%s/members/%s', rawurlencode($audience), md5(mb_strtolower($email)));

        $member = [
            'email_address' => $email,
            'status_if_new' => $request->boolSetting('double_opt_in', true) ? 'pending' : 'subscribed',
        ];

        $mergeFields = $this->mergeFields($request);

        if ($mergeFields !== []) {
            $member['merge_fields'] = $mergeFields;
        }

        $response = $this->client->request('PUT', $memberPath, $member);
        $tags = $this->tags($request);

        if ($tags !== []) {
            $this->client->request('POST', $memberPath.'/tags', [
                'tags' => array_map(static fn (string $tag): array => ['name' => $tag, 'status' => 'active'], $tags),
            ]);
        }

        $status = \is_string($response['status'] ?? null) ? $response['status'] : 'unknown';

        return IntegrationResult::success(
            \sprintf('%s is %s in the audience.', $email, $status),
            ['member' => $member, 'tags' => $tags],
            ['id' => $response['id'] ?? null, 'status' => $status],
        );
    }

    /**
     * @return array<string, string>
     */
    private function mergeFields(IntegrationRequest $request): array
    {
        $fields = [];

        foreach ($request->values as $key => $value) {
            if ($key === 'email') {
                continue;
            }

            $text = $request->string($key);

            if ($text !== null) {
                $fields[self::MERGE_FIELDS[$key] ?? mb_strtoupper($key)] = $text;
            }
        }

        return $fields;
    }

    /**
     * @return list<string>
     */
    private function tags(IntegrationRequest $request): array
    {
        $tags = array_map('trim', explode(',', $request->stringSetting('tags') ?? ''));

        return array_values(array_filter($tags, static fn (string $tag): bool => $tag !== ''));
    }

    /**
     * Only what is configured: the key is checked against Mailchimp when a flow is set up and
     * the audiences are loaded.
     */
    public function getStatus(): IntegrationStatus
    {
        $dataCenter = $this->client->dataCenter();
        $hasKey = $this->client->hasApiKey();
        $requirements = [
            IntegrationRequirement::config('eekes_form_builder_mailchimp.status.api_key', $hasKey ? '***' : null, $this->apiKeyEnv, 'eekes_sulu_form_builder_mailchimp.api_key'),
        ];

        if ($hasKey) {
            $requirements[] = new IntegrationRequirement(
                'eekes_form_builder_mailchimp.status.key_format',
                $dataCenter !== null,
                $dataCenter !== null ? 'eekes_form_builder_mailchimp.status.key_format_ok' : 'eekes_form_builder_mailchimp.status.key_format_wrong',
                ['%data_center%' => $dataCenter ?? ''],
            );
        }

        return IntegrationStatus::fromRequirements(
            $requirements,
            'eekes_form_builder_mailchimp.status.configured',
            'eekes_form_builder_mailchimp.status.not_configured',
            $hasKey ? [] : ['eekes_form_builder_mailchimp.status.step_create_key'],
            $dataCenter !== null ? ['eekes_form_builder_mailchimp.status.data_center' => $dataCenter] : [],
        );
    }

    /**
     * Asks Mailchimp whose key it is, so a key of the wrong account is noticed too.
     */
    public function testConnection(): IntegrationStatus
    {
        $status = $this->getStatus();

        if (!$status->isReady()) {
            return $status;
        }

        $account = $this->client->request('GET', '?fields=account_name,email');
        $name = \is_string($account['account_name'] ?? null) ? $account['account_name'] : '?';

        return IntegrationStatus::ready('eekes_form_builder_mailchimp.status.test_passed', array_filter([
            'eekes_form_builder_mailchimp.status.account' => $name,
            'eekes_form_builder_mailchimp.status.email' => \is_string($account['email'] ?? null) ? $account['email'] : '',
        ]));
    }

    public function testSendsData(): bool
    {
        return false;
    }
}
