<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderMailchimpBundle;

use Eekes\Sulu\FormBuilderBundle\Application\Integration\IntegrationConfig;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * Adds "Mailchimp" to the integrations of eekes/sulu-form-builder-bundle. Enable it there:
 *
 * ```yaml
 * eekes_sulu_form_builder:
 *     integrations:
 *         enabled: ['mailchimp']
 *
 * eekes_sulu_form_builder_mailchimp:
 *     api_key: '%env(MAILCHIMP_API_KEY)%'
 * ```
 */
final class EekesSuluFormBuilderMailchimpBundle extends AbstractBundle
{
    protected string $extensionAlias = 'eekes_sulu_form_builder_mailchimp';

    public function getPath(): string
    {
        return \dirname(__DIR__);
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('api_key')
                    ->defaultNull()
                    ->info('An API key of the account, e.g. %env(MAILCHIMP_API_KEY)%. Its suffix (-us21) tells which data center to call.')
                ->end()
                ->floatNode('timeout')->defaultValue(10.0)->end()
                ->scalarNode('http_client')->defaultValue('http_client')->cannotBeEmpty()->end()
            ->end();
    }

    /**
     * @param array{api_key: null|string, timeout: float, http_client: string} $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $services = $container->services();

        $services->set(MailchimpClient::class)
            ->args([service($config['http_client']), $config['api_key'], $config['timeout']]);

        // Autoconfigured, so the AsFormIntegration attribute registers it with the form builder.
        $services->set(MailchimpIntegration::class)
            // Which environment variable the key comes from, so the admin can name it.
            ->args([service(MailchimpClient::class), IntegrationConfig::envName($builder, $config['api_key'])])
            ->autoconfigure();
    }
}
