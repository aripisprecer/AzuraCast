<?php
return function (\Slim\Container $di, $settings) {

    $di['app_settings'] = $settings;

    // Override Slim handlers.
    $di['request'] = function (\Slim\Container $di) {
        return \App\Http\Request::createFromEnvironment($di->get('environment'));
    };

    $di['response'] = function (\Slim\Container $di) {
        $headers = new \Slim\Http\Headers(['Content-Type' => 'text/html; charset=UTF-8']);
        $response = new \App\Http\Response(200, $headers, null, $di[\App\Url::class]);

        return $response->withProtocolVersion($di->get('settings')['httpVersion']);
    };

    $di['callableResolver'] = function ($di) {
        return new \App\Mvc\Resolver($di);
    };

    $di['errorHandler'] = function ($di) {
        return $di[\App\Mvc\ErrorHandler::class];
    };

    $di['phpErrorHandler'] = function($di) {
        return $di[\App\Mvc\ErrorHandler::class];
    };

    $di['notFoundHandler'] = function ($di) {
        return function (\App\Http\Request $request, \App\Http\Response $response) use ($di) {
            /** @var \App\Mvc\View $view */
            $view = $di[\App\Mvc\View::class];

            return $view->renderToResponse($response->withStatus(404), 'system/error_pagenotfound');
        };
    };

    $di['foundHandler'] = function() {
        return new \Slim\Handlers\Strategies\RequestResponseArgs();
    };

    $di[\App\Config::class] = function () {
        return new \App\Config(APP_INCLUDE_BASE . '/config');
    };

    $di[\Doctrine\ORM\EntityManager::class] = function ($di) {
        try {
            $options = [
                'autoGenerateProxies' => !APP_IN_PRODUCTION,
                'proxyNamespace' => 'AppProxy',
                'proxyPath' => APP_INCLUDE_TEMP . '/proxies',
                'modelPath' => APP_INCLUDE_BASE . '/src/Entity',
                'conn' => [
                    'driver' => 'pdo_mysql',
                    'charset' => 'utf8mb4',
                    'defaultTableOptions' => [
                        'charset' => 'utf8mb4',
                        'collate' => 'utf8mb4_unicode_ci',
                    ],
                    'driverOptions' => [
                        \PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci',
                    ],
                    'platform' => new \Doctrine\DBAL\Platforms\MariaDb1027Platform(),
                ]
            ];

            if (APP_INSIDE_DOCKER) {
                $options['conn']['host'] = $_ENV['MYSQL_HOST'] ?? 'mariadb';
                $options['conn']['port'] = $_ENV['MYSQL_PORT'] ?? 3306;
                $options['conn']['dbname'] = $_ENV['MYSQL_DATABASE'] ?? 'azuracast';
                $options['conn']['user'] = $_ENV['MYSQL_USER'] ?? 'azuracast';
                $options['conn']['password'] = $_ENV['MYSQL_PASSWORD'] ?? 'azur4c457';
            } else {
                $options['conn']['host'] = $_ENV['db_host'] ?? 'localhost';
                $options['conn']['port'] = $_ENV['db_port'] ?? '3306';
                $options['conn']['dbname'] = $_ENV['db_name'] ?? 'azuracast';
                $options['conn']['user'] = $_ENV['db_username'] ?? 'azuracast';
                $options['conn']['password'] = $_ENV['db_password'];
            }

            \Doctrine\Common\Proxy\Autoloader::register($options['proxyPath'], $options['proxyNamespace']);

            // Fetch and store entity manager.
            $config = new \Doctrine\ORM\Configuration;

            $metadata_driver = $config->newDefaultAnnotationDriver($options['modelPath']);
            $config->setMetadataDriverImpl($metadata_driver);

            if (APP_IN_PRODUCTION) {
                /** @var \Redis $redis */
                $redis = $di[\Redis::class];
                $redis->select(2);

                $cache = new \App\Doctrine\Cache\Redis;
                $cache->setRedis($redis);
            } else {
                $cache = new \Doctrine\Common\Cache\ArrayCache;
            }

            $config->setMetadataCacheImpl($cache);
            $config->setQueryCacheImpl($cache);
            $config->setResultCacheImpl($cache);

            // Disable second-level cache for unit testing purposes, as it causes data to be out of date on pages.
            if (APP_TESTING_MODE) {
                $config->setSecondLevelCacheEnabled(false);
            }

            // Create proxy directory if it doesn't exist.
            if (!is_dir($options['proxyPath'])) {
                if (!mkdir($options['proxyPath'], 0744) && !is_dir($options['proxyPath'])) {
                    throw new \RuntimeException(sprintf('Could not create proxy directory %s', $options['proxyPath']));
                }
            }

            $config->setProxyDir($options['proxyPath']);
            $config->setProxyNamespace($options['proxyNamespace']);
            $config->setAutoGenerateProxyClasses(\Doctrine\Common\Proxy\AbstractProxyFactory::AUTOGENERATE_FILE_NOT_EXISTS);
            $config->setDefaultRepositoryClassName(\Entity\Repository\BaseRepository::class);

            if (isset($options['conn']['debug']) && $options['conn']['debug']) {
                $config->setSQLLogger(new \Doctrine\DBAL\Logging\EchoSQLLogger);
            }

            $config->addCustomNumericFunction('RAND', '\App\Doctrine\Functions\Rand');
            $config->addCustomStringFunction('FIELD', 'DoctrineExtensions\Query\Mysql\Field');
            $config->addCustomStringFunction('IF', 'DoctrineExtensions\Query\Mysql\IfElse');

            $em = \Doctrine\ORM\EntityManager::create($options['conn'], $config, new \Doctrine\Common\EventManager);

            return $em;
        } catch (\Exception $e) {
            throw new \App\Exception\Bootstrap($e->getMessage());
        }
    };

    $di[\Doctrine\DBAL\Connection::class] = function ($di) {
        /** @var \Doctrine\ORM\EntityManager $em */
        $em = $di[\Doctrine\ORM\EntityManager::class];
        return $em->getConnection();
    };

    $di[\Entity\Repository\SettingsRepository::class] = function($di) {
        /** @var \Doctrine\ORM\EntityManager $em */
        $em = $di[\Doctrine\ORM\EntityManager::class];

        return $em->getRepository(Entity\Settings::class);
    };

    $di[\App\Auth::class] = function ($di) {
        /** @var \Doctrine\ORM\EntityManager $em */
        $em = $di[\Doctrine\ORM\EntityManager::class];

        /** @var Entity\Repository\UserRepository $user_repo */
        $user_repo = $em->getRepository(Entity\User::class);

        return new \App\Auth($di[\App\Session::class], $user_repo);
    };

    $di[\AzuraCast\Acl\StationAcl::class] = function ($di) {
        /** @var \Doctrine\ORM\EntityManager $em */
        $em = $di[\Doctrine\ORM\EntityManager::class];

        /** @var Entity\Repository\RolePermissionRepository $permissions_repo */
        $permissions_repo = $em->getRepository(Entity\RolePermission::class);

        return new \AzuraCast\Acl\StationAcl($permissions_repo);
    };

    $di[\Redis::class] = $di->factory(function ($di) {
        $redis_host = (APP_INSIDE_DOCKER) ? 'redis' : 'localhost';

        $redis = new \Redis();
        $redis->connect($redis_host, 6379, 15);
        return $redis;
    });

    $di[\App\Cache::class] = function ($di) {
        /** @var \Redis $redis */
        $redis = $di[\Redis::class];
        $redis->select(0);

        return new \App\Cache($redis);
    };

    $di[\App\Url::class] = function ($di) {
        /** @var Entity\Repository\SettingsRepository $settings_repo */
        $settings_repo = $di[\Entity\Repository\SettingsRepository::class];

        $base_url = $settings_repo->getSetting('base_url', '');
        $prefer_browser_url = (bool)$settings_repo->getSetting('prefer_browser_url', 0);

        $http_host = $_SERVER['HTTP_HOST'] ?? '';
        $ignore_hosts = ['localhost', 'nginx'];

        if (!empty($http_host) && !in_array($http_host, $ignore_hosts) && ($prefer_browser_url || empty($base_url))) {
            $base_url = $http_host;
        }

        if (!empty($base_url)) {
            $always_use_ssl = (bool)$settings_repo->getSetting('always_use_ssl', 0);
            $base_url_schema = (APP_IS_SECURE || $always_use_ssl) ? 'https://' : 'http://';

            $base_url = $base_url_schema.$base_url;
        }

        return new \App\Url($di['router'], $base_url);
    };

    $di[\App\Session::class] = function ($di) {
        ini_set('session.gc_maxlifetime', 86400);
        ini_set('session.gc_probability', 1);
        ini_set('session.gc_divisor', 100);

        $redis_server = (APP_INSIDE_DOCKER) ? 'redis' : 'localhost';
        ini_set('session.save_handler', 'redis');
        ini_set('session.save_path', 'tcp://' . $redis_server . ':6379?database=1');

        return new \App\Session;
    };

    $di[\App\Csrf::class] = function ($di) {
        return new \App\Csrf($di[\App\Session::class]);
    };

    $di[\App\Flash::class] = function ($di) {
        return new \App\Flash($di[\App\Session::class]);
    };

    $di[\InfluxDB\Database::class] = function ($di) {
        $opts = [
            'host' => (APP_INSIDE_DOCKER) ? 'influxdb' : 'localhost',
            'port' => 8086,
        ];

        $influx = new \InfluxDB\Client($opts['host'], $opts['port']);

        return $influx->selectDB('stations');
    };

    $di[\Supervisor\Supervisor::class] = function ($di) {
        $guzzle_client = new \GuzzleHttp\Client();
        $client = new \fXmlRpc\Client(
            'http://' . (APP_INSIDE_DOCKER ? 'stations' : '127.0.0.1') . ':9001/RPC2',
            new \fXmlRpc\Transport\HttpAdapterTransport(
                new \Http\Message\MessageFactory\GuzzleMessageFactory(),
                new \Http\Adapter\Guzzle6\Client($guzzle_client)
            )
        );

        $connector = new \Supervisor\Connector\XmlRpc($client);
        $supervisor = new \Supervisor\Supervisor($connector);

        if (!$supervisor->isConnected()) {
            throw new \App\Exception(sprintf('Could not connect to supervisord.'));
        }

        return $supervisor;
    };

    $di[\App\Mvc\View::class] = $di->factory(function(\Slim\Container $di) {
        $view = new \App\Mvc\View(APP_INCLUDE_BASE . '/templates');
        $view->setFileExtension('phtml');

        $view->registerFunction('service', function($service) use ($di) {
            return $di->get($service);
        });

        $view->registerFunction('escapeJs', function($string) {
            return json_encode($string);
        });

        $view->registerFunction('mailto', function ($address, $link_text = null) {
            $address = substr(chunk_split(bin2hex(" $address"), 2, ";&#x"), 3, -3);
            $link_text = $link_text ?? $address;

            return '<a href="mailto:' . $address . '">' . $link_text . '</a>';
        });

        $view->registerFunction('pluralize', function ($word, $num = 0) {
            if ((int)$num === 1) {
                return $word;
            } else {
                return \Doctrine\Common\Inflector\Inflector::pluralize($word);
            }
        });

        $view->registerFunction('truncate', function ($text, $length = 80) {
            return \App\Utilities::truncate_text($text, $length);
        });

        $view->addData([
            'assets' => $di[\AzuraCast\Assets::class],
            'auth' => $di[\App\Auth::class],
            'acl' => $di[\AzuraCast\Acl\StationAcl::class],
            'url' => $di[\App\Url::class],
            'flash' => $di[\App\Flash::class],
            'customization' => $di[\AzuraCast\Customization::class],
            'app_settings' => $di['app_settings'],
        ]);

        return $view;
    });

    $di[\App\Mvc\ErrorHandler::class] = function($di) {
        return new \App\Mvc\ErrorHandler(
            $di[\App\Url::class],
            $di[\App\Session::class],
            $di[\App\Flash::class],
            $di[\App\Mvc\View::class],
            $di[\AzuraCast\Acl\StationAcl::class],
            $di[\Monolog\Logger::class]
        );
    };

    $di[\Monolog\Logger::class] = function($di) use ($settings) {
        $logger = new Monolog\Logger($settings['name']);

        if (APP_INSIDE_DOCKER) {
            $logging_level = (APP_IN_PRODUCTION) ? \Monolog\Logger::WARNING : \Monolog\Logger::DEBUG;

            $handler = new \Monolog\Handler\StreamHandler('php://stderr', $logging_level, true);
            $logger->pushHandler($handler);
        }

        $handler = new \Monolog\Handler\StreamHandler(APP_INCLUDE_TEMP.'/azuracast.log', \Monolog\Logger::WARNING, true);
        $logger->pushHandler($handler);

        return $logger;
    };

    $di[\AzuraCast\Console\Application::class] = function($di) use ($settings) {
        return \AzuraCast\Console\Application::create($di, $settings);
    };

    //
    // AzuraCast-specific dependencies
    //

    $di[\AzuraCast\ApiUtilities::class] = function($di) {
        return new \AzuraCast\ApiUtilities(
            $di[\Doctrine\ORM\EntityManager::class],
            $di[\App\Url::class]
        );
    };

    $di[\AzuraCast\Assets::class] = function ($di) {
        $libraries = require('assets.php');

        $versioned_files = [];
        $assets_file = APP_INCLUDE_STATIC . '/assets.json';
        if (file_exists($assets_file)) {
            $versioned_files = json_decode(file_get_contents($assets_file), true);
        }

        return new \AzuraCast\Assets($libraries, $versioned_files, $di[\App\Url::class]);
    };

    $di[\AzuraCast\Customization::class] = function ($di) {
        return new \AzuraCast\Customization(
            $di['app_settings'],
            $di[\Entity\Repository\SettingsRepository::class],
            $di[\App\Url::class]
        );
    };

    $di[\AzuraCast\RateLimit::class] = function($di) {
        /** @var \Redis $redis */
        $redis = $di[\Redis::class];
        $redis->select(3);

        return new \AzuraCast\RateLimit($redis);
    };

    $di[\AzuraCast\Radio\Adapters::class] = function($di) {
        return new AzuraCast\Radio\Adapters(new \Pimple\Psr11\ServiceLocator($di, [
            \AzuraCast\Radio\Backend\Liquidsoap::class,
            \AzuraCast\Radio\Backend\None::class,
            \AzuraCast\Radio\Frontend\Icecast::class,
            \AzuraCast\Radio\Frontend\Remote::class,
            \AzuraCast\Radio\Frontend\SHOUTcast::class,
        ]));
    };

    $di[\AzuraCast\Radio\Configuration::class] = function($di) {
        return new \AzuraCast\Radio\Configuration(
            $di[\Doctrine\ORM\EntityManager::class],
            $di[\AzuraCast\Radio\Adapters::class],
            $di[\Supervisor\Supervisor::class]
        );
    };

    $di[\AzuraCast\Radio\Backend\Liquidsoap::class] = $di->factory(function($di) {
        return new \AzuraCast\Radio\Backend\Liquidsoap(
            $di[\Doctrine\ORM\EntityManager::class],
            $di[\Supervisor\Supervisor::class],
            $di[\Monolog\Logger::class]
        );
    });

    $di[\AzuraCast\Radio\Backend\None::class] = $di->factory(function($di) {
        return new \AzuraCast\Radio\Backend\None(
            $di[\Doctrine\ORM\EntityManager::class],
            $di[\Supervisor\Supervisor::class],
            $di[\Monolog\Logger::class]
        );
    });

    $di[\AzuraCast\Radio\Frontend\Icecast::class] = $di->factory(function($di) {
        return new \AzuraCast\Radio\Frontend\Icecast(
            $di[\Doctrine\ORM\EntityManager::class],
            $di[\Supervisor\Supervisor::class],
            $di[\Monolog\Logger::class],
            $di[\App\Url::class]
        );
    });

    $di[\AzuraCast\Radio\Frontend\Remote::class] = $di->factory(function($di) {
        return new \AzuraCast\Radio\Frontend\Remote(
            $di[\Doctrine\ORM\EntityManager::class],
            $di[\Supervisor\Supervisor::class],
            $di[\Monolog\Logger::class],
            $di[\App\Url::class]
        );
    });

    $di[\AzuraCast\Radio\Frontend\SHOUTcast::class] = $di->factory(function($di) {
        return new \AzuraCast\Radio\Frontend\SHOUTcast(
            $di[\Doctrine\ORM\EntityManager::class],
            $di[\Supervisor\Supervisor::class],
            $di[\Monolog\Logger::class],
            $di[\App\Url::class]
        );
    });

    $di[\AzuraCast\Sync::class] = function ($di) {
        return new \AzuraCast\Sync(
            $di[\Entity\Repository\SettingsRepository::class],
            $di[\Monolog\Logger::class],
            new \Pimple\ServiceIterator($di, [
                \AzuraCast\Sync\NowPlaying::class,
            ]),
            new \Pimple\ServiceIterator($di, [
                \AzuraCast\Sync\RadioRequests::class,
            ]),
            new \Pimple\ServiceIterator($di, [
                \AzuraCast\Sync\Media::class
            ]),
            new \Pimple\ServiceIterator($di, [
                \AzuraCast\Sync\Analytics::class,
                \AzuraCast\Sync\RadioAutomation::class,
                \AzuraCast\Sync\HistoryCleanup::class,
            ])
        );
    };

    $di[\AzuraCast\Sync\Analytics::class] = function($di) {
        return new \AzuraCast\Sync\Analytics(
            $di[\Doctrine\ORM\EntityManager::class],
            $di[\InfluxDB\Database::class]
        );
    };

    $di[\AzuraCast\Sync\HistoryCleanup::class] = function($di) {
        return new \AzuraCast\Sync\HistoryCleanup(
            $di[\Doctrine\ORM\EntityManager::class]
        );
    };

    $di[\AzuraCast\Sync\Media::class] = function($di) {
        return new \AzuraCast\Sync\Media(
            $di[\Doctrine\ORM\EntityManager::class]
        );
    };

    $di[\AzuraCast\Sync\NowPlaying::class] = function($di) {
        return new \AzuraCast\Sync\NowPlaying(
            $di[\Doctrine\ORM\EntityManager::class],
            $di[\App\Url::class],
            $di[\InfluxDB\Database::class],
            $di[\App\Cache::class],
            $di[\AzuraCast\Radio\Adapters::class],
            $di[\AzuraCast\Webhook\Dispatcher::class],
            $di[\AzuraCast\ApiUtilities::class]
        );
    };

    $di[\AzuraCast\Sync\RadioAutomation::class] = function($di) {
        return new \AzuraCast\Sync\RadioAutomation(
            $di[\Doctrine\ORM\EntityManager::class],
            $di[\AzuraCast\Radio\Adapters::class]
        );
    };

    $di[\AzuraCast\Sync\RadioRequests::class] = function($di) {
        return new \AzuraCast\Sync\RadioRequests(
            $di[\Doctrine\ORM\EntityManager::class],
            $di[\AzuraCast\Radio\Adapters::class]
        );
    };

    $di[\AzuraCast\Webhook\Dispatcher::class] = function($di) {
        return new \AzuraCast\Webhook\Dispatcher(
            $di[\Monolog\Logger::class],
            new \Pimple\Psr11\ServiceLocator($di, [
                'local'     => \AzuraCast\Webhook\Connector\Local::class,
                'generic'   => \AzuraCast\Webhook\Connector\Generic::class,
                'tunein'    => \AzuraCast\Webhook\Connector\TuneIn::class,
                'discord'   => \AzuraCast\Webhook\Connector\Discord::class,
                'twitter'   => \AzuraCast\Webhook\Connector\Twitter::class,
            ])
        );
    };

    $di[\AzuraCast\Webhook\Connector\Local::class] = function($di) {
        return new \AzuraCast\Webhook\Connector\Local(
            $di[\Monolog\Logger::class],
            $di[\InfluxDB\Database::class],
            $di[\App\Cache::class],
            $di[\Entity\Repository\SettingsRepository::class]
        );
    };

    $di[\AzuraCast\Webhook\Connector\Generic::class] = function($di) {
        return new \AzuraCast\Webhook\Connector\Generic(
            $di[\Monolog\Logger::class]
        );
    };

    $di[\AzuraCast\Webhook\Connector\TuneIn::class] = function($di) {
        return new \AzuraCast\Webhook\Connector\TuneIn(
            $di[\Monolog\Logger::class]
        );
    };

    $di[\AzuraCast\Webhook\Connector\Discord::class] = function($di) {
        return new \AzuraCast\Webhook\Connector\Discord(
            $di[\Monolog\Logger::class]
        );
    };

    $di[\AzuraCast\Webhook\Connector\Twitter::class] = function($di) {
        return new \AzuraCast\Webhook\Connector\Twitter(
            $di[\Monolog\Logger::class]
        );
    };

    //
    // Middleware
    //

    $di[\App\Middleware\DebugEcho::class] = function($di) {
        return new \App\Middleware\DebugEcho($di[\Monolog\Logger::class]);
    };

    $di[\AzuraCast\Middleware\EnableView::class] = function($di) {
        return new \AzuraCast\Middleware\EnableView($di[\App\Mvc\View::class]);
    };

    $di[\AzuraCast\Middleware\EnforceSecurity::class] = function($di) {
        return new \AzuraCast\Middleware\EnforceSecurity(
            $di[\Doctrine\ORM\EntityManager::class],
            $di[\AzuraCast\Assets::class]
        );
    };

    $di[\AzuraCast\Middleware\GetCurrentUser::class] = function($di) {
        return new \AzuraCast\Middleware\GetCurrentUser(
            $di[\App\Auth::class],
            $di[\AzuraCast\Customization::class]
        );
    };

    $di[\AzuraCast\Middleware\GetStation::class] = function($di) {
        /** @var \Doctrine\ORM\EntityManager $em */
        $em = $di[\Doctrine\ORM\EntityManager::class];

        /** @var \Entity\Repository\StationRepository $station_repo */
        $station_repo = $em->getRepository(Entity\Station::class);

        return new \AzuraCast\Middleware\GetStation(
            $station_repo,
            $di[\AzuraCast\Radio\Adapters::class]
        );
    };

    $di[\AzuraCast\Middleware\Permissions::class] = function($di) {
        return new \AzuraCast\Middleware\Permissions(
            $di[\AzuraCast\Acl\StationAcl::class]
        );
    };

    $di[\AzuraCast\Middleware\RateLimit::class] = function($di) {
        return new \AzuraCast\Middleware\RateLimit(
            $di[\AzuraCast\RateLimit::class]
        );
    };

    $di[\AzuraCast\Middleware\RemoveSlashes::class] = function($di) {
        return new \AzuraCast\Middleware\RemoveSlashes();
    };

    //
    // Module-specific middleware
    //

    $di[\AzuraCast\Middleware\Module\Admin::class] = function($di) {
        /** @var \App\Config $config */
        $config = $di[\App\Config::class];

        return new \AzuraCast\Middleware\Module\Admin(
            $di[\AzuraCast\Acl\StationAcl::class],
            $config->get('admin/dashboard')
        );
    };

    $di[\AzuraCast\Middleware\Module\Api::class] = function($di) {
        /** @var \Doctrine\ORM\EntityManager $em */
        $em = $di[\Doctrine\ORM\EntityManager::class];

        /** @var Entity\Repository\ApiKeyRepository $api_repo */
        $api_repo = $em->getRepository(Entity\ApiKey::class);

        return new \AzuraCast\Middleware\Module\Api(
            $di[\App\Session::class],
            $api_repo
        );
    };

    $di[\AzuraCast\Middleware\Module\Stations::class] = function($di) {
        return new \AzuraCast\Middleware\Module\Stations;
    };

    $di[\AzuraCast\Middleware\Module\StationFiles::class] = function($di) {
        return new \AzuraCast\Middleware\Module\StationFiles();
    };

    //
    // Main Slim Application
    //

    $di['app'] = function ($di) {

        $app = new \Slim\App($di);

        // Get the current user entity object and assign it into the request if it exists.
        $app->add(\AzuraCast\Middleware\GetCurrentUser::class);

        // Check HTTPS setting and enforce Content Security Policy accordingly.
        $app->add(\AzuraCast\Middleware\EnforceSecurity::class);

        // Remove trailing slash from all URLs when routing.
        $app->add(\AzuraCast\Middleware\RemoveSlashes::class);

        // Load routes
        call_user_func(include(__DIR__.'/routes.php'), $app);

        return $app;
    };

    return $di;

};