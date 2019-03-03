<?php

namespace ErrorHeroModule\Spec\Middleware;

use ArrayObject;
use Aura\Di\Container as AuraContainer;
use Aura\Di\ContainerBuilder as AuraContainerBuilder;
use Auryn\Injector as AurynInjector;
use DI\Container as PHPDIContainer;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\PDOMySql\Driver;
use Doctrine\ORM\EntityManager;
use Elie\PHPDI\Config\ContainerWrapper as EliePHPDIv4ContainerWrapper;
use ErrorHeroModule\Handler\Logging;
use ErrorHeroModule\Middleware\Expressive;
use ErrorHeroModule\Middleware\ExpressiveFactory;
use ErrorHeroModule\Spec\Fixture\NotSupportedContainer;
use Kahlan\Plugin\Double;
use Northwoods\Container\InjectorContainer as AurynInjectorContainer;
use Pimple\Container as PimpleContainer;
use Pimple\Psr11\Container as Psr11PimpleContainer;
use RuntimeException;
use Symfony\Component\DependencyInjection\ContainerBuilder as SymfonyContainerBuilder;
use Zend\Db\Adapter\Adapter;
use Zend\DI\Config\ContainerWrapper as EliePHPDIv3ContainerWrapper;
use Zend\Expressive\Template\TemplateRendererInterface;
use Zend\ServiceManager\ServiceManager;

describe('ExpressiveFactory', function () {

    given('factory', function () {
        return new ExpressiveFactory();
    });

    given('mapCreateContainers', function () {
        $map = [
            AuraContainer::class               => (new AuraContainerBuilder())->newInstance(),
            SymfonyContainerBuilder::class     => new SymfonyContainerBuilder(),
            AurynInjectorContainer::class      => new AurynInjectorContainer(new AurynInjector()),
            Psr11PimpleContainer::class        => new Psr11PimpleContainer(new PimpleContainer()),
        ];

        $elie29zendphpdiconfigVersion = str_replace('v', '', \PackageVersions\Versions::getVersion("elie29/zend-phpdi-config"));
        $phpDI = [
            $elie29zendphpdiconfigVersion >= 4 ? EliePHPDIv4ContainerWrapper::class : EliePHPDIv3ContainerWrapper::class
                => $elie29zendphpdiconfigVersion >= 4 ? new EliePHPDIv4ContainerWrapper() : new EliePHPDIv3ContainerWrapper()
        ];

        return $phpDI + $map;
    });

    given('config', function () {

        return [

            'db' => [
                'username' => 'root',
                'password' => '',
                'driver'   => 'pdo_mysql',
                'dsn'      => 'mysql:host=localhost;dbname=errorheromodule',
                'driver_options' => [
                    \PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES \'UTF8\'',
                ],
                'adapters' => [
                    'my-adapter' => [
                        'driver' => 'pdo_mysql',
                        'dsn' => 'mysql:host=localhost;dbname=errorheromodule',
                        'username' => 'root',
                        'password' => '',
                        'driver_options' => [
                            \PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES \'UTF8\'',
                        ],
                    ],
                ],
            ],

            'error-hero-module' => [
                'enable' => true,
                'display-settings' => [

                    // excluded php errors
                    'exclude-php-errors' => [
                        \E_USER_DEPRECATED
                    ],

                    // show or not error
                    'display_errors'  => 0,

                    // if enable and display_errors = 0, the page will bring layout and view
                    'template' => [
                        'layout' => 'layout::default',
                        'view'   => 'error-hero-module::error-default'
                    ],

                ],
                'logging-settings' => [
                    'same-error-log-time-range' => 86400,
                ],
                'email-notification-settings' => [
                    // set to true to activate email notification on log error
                    'enable' => false,

                    // Zend\Mail\Message instance registered at service manager
                    'mail-message'   => 'YourMailMessageService',

                    // Zend\Mail\Transport\TransportInterface instance registered at service manager
                    'mail-transport' => 'YourMailTransportService',

                    // email sender
                    'email-from'    => 'Sender Name <sender@host.com>',

                    'email-to-send' => [
                        'developer1@foo.com',
                        'developer2@foo.com',
                    ],
                ],
            ],

            'log' => [
                'ErrorHeroModuleLogger' => [
                    'writers' => [

                        [
                            'name' => 'db',
                            'options' => [
                                'db'     => Adapter::class,
                                'table'  => 'error_log',
                                'column' => [
                                    'timestamp' => 'date',
                                    'priority'  => 'type',
                                    'message'   => 'event',
                                    'extra'     => [
                                        'url'  => 'url',
                                        'file' => 'file',
                                        'line' => 'line',
                                        'error_type' => 'error_type',
                                        'trace'      => 'trace',
                                        'request_data' => 'request_data',
                                    ],
                                ],
                            ],
                        ],

                    ],
                ],
            ],

        ];

    });

    describe('__invoke()', function () {

        it('returns Expressive Middleware instance with doctrine to zend-db conversion', function () {

            $config = $this->config;
            unset($config['db']);
            $container = Double::instance(['extends' => ServiceManager::class, 'methods' => '__construct']);
            allow($container)->toReceive('get')->with('config')
                                               ->andReturn($config);

            allow($container)->toReceive('has')->with(EntityManager::class)->andReturn(true);
            $entityManager = Double::instance(['extends' => EntityManager::class, 'methods' => '__construct']);
            $connection    = Double::instance(['extends' => Connection::class, 'methods' => '__construct']);

            $driver = Double::instance(['extends' => Driver::class, 'methods' => '__construct']);
            allow($driver)->toReceive('getName')->andReturn('pdo_mysql');

            allow($connection)->toReceive('getParams')->andReturn([]);
            allow($connection)->toReceive('getUsername')->andReturn('root');
            allow($connection)->toReceive('getPassword')->andReturn('');
            allow($connection)->toReceive('getDriver')->andReturn($driver);
            allow($connection)->toReceive('getDatabase')->andReturn('mydb');
            allow($connection)->toReceive('getHost')->andReturn('localhost');
            allow($connection)->toReceive('getPort')->andReturn('3306');

            allow($entityManager)->toReceive('getConnection')->andReturn($connection);
            allow($container)->toReceive('get')->with(EntityManager::class)->andReturn(
                $entityManager
            );

            $logging = Double::instance(['extends' => Logging::class, 'methods' => '__construct']);
            allow($container)->toReceive('get')->with(Logging::class)
                                               ->andReturn($logging);

            $renderer = Double::instance(['implements' => TemplateRendererInterface::class]);
            allow($container)->toReceive('get')->with(TemplateRendererInterface::class)
                                               ->andReturn($renderer);

            expect($container->has('ErrorHeroModuleLogger'))->toBeFalsy();
            $actual = $this->factory($container);
            expect($actual)->toBeAnInstanceOf(Expressive::class);
            expect($container->has('ErrorHeroModuleLogger'))->toBeTruthy();


        });

        it('returns Expressive Middleware instance without doctrine to zend-db conversion', function () {

            $container = Double::instance(['extends' => ServiceManager::class, 'methods' => '__construct']);
            allow($container)->toReceive('get')->with('config')
                                               ->andReturn($this->config);

            $logging = Double::instance(['extends' => Logging::class, 'methods' => '__construct']);
            allow($container)->toReceive('get')->with(Logging::class)
                                               ->andReturn($logging);

            $renderer = Double::instance(['implements' => TemplateRendererInterface::class]);
            allow($container)->toReceive('get')->with(TemplateRendererInterface::class)
                                               ->andReturn($renderer);

            $actual = $this->factory($container);
            expect($actual)->toBeAnInstanceOf(Expressive::class);

        });

        it('throws RuntimeException when using mapped containers but no "db" config', function () {

            $config = [];
            foreach ($this->mapCreateContainers as $containerClass => $container) {
                if ($container instanceof AuraContainer) {
                    $config = new ArrayObject($config);
                }
                allow($container)->toReceive('get')->with('config')
                                                ->andReturn($config);
                if ($container instanceof AuraContainer) {
                    $config = $config->getArrayCopy();
                }
                allow($container)->toReceive('has')->with(EntityManager::class)->andReturn(false);

                $logging = Double::instance(['extends' => Logging::class, 'methods' => '__construct']);
                allow($container)->toReceive('get')->with(Logging::class)
                                                ->andReturn($logging);

                $renderer = Double::instance(['implements' => TemplateRendererInterface::class]);
                allow($container)->toReceive('get')->with(TemplateRendererInterface::class)
                                                ->andReturn($renderer);

                $actual = function () use ($container) {
                    $this->factory($container);
                };
                expect($actual)->toThrow(new RuntimeException(
                    \sprintf(
                        'db config is required for build "ErrorHeroModuleLogger" service by %s Container',
                        $containerClass
                    )
                ));
            }

        });

        it('returns Expressive Middleware instance with create service first for mapped containers and config does not has "adapters" key', function () {

            $config = $this->config;
            unset($config['db']['adapters']);

            foreach ($this->mapCreateContainers as $container) {
                $config['log']['ErrorHeroModuleLogger']['writers'][0]['options']['db'] = Adapter::class;
                if ($container instanceof AuraContainer) {
                    $config = new ArrayObject($config);
                }
                allow($container)->toReceive('get')->with('config')
                                                ->andReturn($config);
                if ($container instanceof AuraContainer) {
                    $config = $config->getArrayCopy();
                }
                allow($container)->toReceive('has')->with(EntityManager::class)->andReturn(false);

                $logging = Double::instance(['extends' => Logging::class, 'methods' => '__construct']);
                allow($container)->toReceive('get')->with(Logging::class)
                                                ->andReturn($logging);

                $renderer = Double::instance(['implements' => TemplateRendererInterface::class]);
                allow($container)->toReceive('get')->with(TemplateRendererInterface::class)
                                                ->andReturn($renderer);

                expect($container->has('ErrorHeroModuleLogger'))->toBeFalsy();
                $actual = $this->factory($container);
                expect($actual)->toBeAnInstanceOf(Expressive::class);
                expect($container->has('ErrorHeroModuleLogger'))->toBeTruthy();
            }

        });

        it('returns Expressive Middleware instance with create service first for mapped containers and db name found in adapters', function () {

            foreach ($this->mapCreateContainers as $container) {
                $config = $this->config;
                $config['log']['ErrorHeroModuleLogger']['writers'][0]['options']['db'] = 'my-adapter';
                if ($container instanceof AuraContainer) {
                    $config = new ArrayObject($config);
                }
                allow($container)->toReceive('get')->with('config')
                                                ->andReturn($config);
                if ($container instanceof AuraContainer) {
                    $config = $config->getArrayCopy();
                }
                allow($container)->toReceive('has')->with(EntityManager::class)->andReturn(false);

                $logging = Double::instance(['extends' => Logging::class, 'methods' => '__construct']);
                allow($container)->toReceive('get')->with(Logging::class)
                                                ->andReturn($logging);

                $renderer = Double::instance(['implements' => TemplateRendererInterface::class]);
                allow($container)->toReceive('get')->with(TemplateRendererInterface::class)
                                                ->andReturn($renderer);

                expect($container->has('ErrorHeroModuleLogger'))->toBeFalsy();
                $actual = $this->factory($container);
                expect($actual)->toBeAnInstanceOf(Expressive::class);
                expect($container->has('ErrorHeroModuleLogger'))->toBeTruthy();
            }

        });

        it('returns Expressive Middleware instance with create services first for mapped containers and db name not found in adapters, which means use "Zend\Db\Adapter\Adapter" name', function () {

            $config = $this->config;
            foreach ($this->mapCreateContainers as $container) {
                if ($container instanceof AuraContainer) {
                    $config = new ArrayObject($config);
                }
                allow($container)->toReceive('get')->with('config')
                                                ->andReturn($config);
                if ($container instanceof AuraContainer) {
                    $config = $config->getArrayCopy();
                }
                allow($container)->toReceive('has')->with(EntityManager::class)->andReturn(false);

                $logging = Double::instance(['extends' => Logging::class, 'methods' => '__construct']);
                allow($container)->toReceive('get')->with(Logging::class)
                                                ->andReturn($logging);

                $renderer = Double::instance(['implements' => TemplateRendererInterface::class]);
                allow($container)->toReceive('get')->with(TemplateRendererInterface::class)
                                                ->andReturn($renderer);

                expect($container->has('ErrorHeroModuleLogger'))->toBeFalsy();
                $actual = $this->factory($container);
                expect($actual)->toBeAnInstanceOf(Expressive::class);
                expect($container->has('ErrorHeroModuleLogger'))->toBeTruthy();
            }

        });

        it('throws RuntimeException on not supported container', function () {

            $container = new NotSupportedContainer();
            allow($container)->toReceive('get')->with('config')
                                               ->andReturn([]);

            allow($container)->toReceive('has')->with(EntityManager::class)->andReturn(false);

            $logging = Double::instance(['extends' => Logging::class, 'methods' => '__construct']);
            allow($container)->toReceive('get')->with(Logging::class)
                                               ->andReturn($logging);

            $renderer = Double::instance(['implements' => TemplateRendererInterface::class]);
            allow($container)->toReceive('get')->with(TemplateRendererInterface::class)
                                               ->andReturn($renderer);

            $actual = function () use ($container) {
                $this->factory($container);
            };
            expect($actual)->toThrow(
                new RuntimeException(\sprintf(
                    'container "%s" is unsupported',
                    \get_class($container)
                ))
            );

        });

    });

});
