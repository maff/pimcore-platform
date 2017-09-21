<?php

declare(strict_types=1);

/**
 * Pimcore
 *
 * This source file is available under two different licenses:
 * - GNU General Public License version 3 (GPLv3)
 * - Pimcore Enterprise License (PEL)
 * Full copyright and license information is available in
 * LICENSE.md which is distributed with this source code.
 *
 * @copyright  Copyright (c) Pimcore GmbH (http://www.pimcore.org)
 * @license    http://www.pimcore.org/license     GPLv3 and PEL
 */

namespace Pimcore\Install;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\DriverManager;
use Pimcore\Config;
use Pimcore\Db\Connection;
use Pimcore\Install\Profile\FileInstaller;
use Pimcore\Install\Profile\Profile;
use Pimcore\Install\Profile\ProfileLocator;
use Pimcore\Model\Tool\Setup;
use Pimcore\Tool;
use Pimcore\Tool\Requirements;
use Pimcore\Tool\Requirements\Check;
use Psr\Log\LoggerInterface;
use Symfony\Component\Filesystem\Filesystem;

class Installer
{
    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var ProfileLocator
     */
    private $profileLocator;

    /**
     * @var FileInstaller
     */
    private $fileInstaller;

    /**
     * If false, profile files won't be copied
     *
     * @var bool
     */
    private $copyProfileFiles = true;

    /**
     * @var bool
     */
    private $overwriteExistingFiles = true;

    /**
     * @var bool
     */
    private $symlink = false;

    /**
     * Predefined profile from config
     *
     * @var string
     */
    private $profile;

    /**
     * Predefined DB credentials from config
     *
     * @var array
     */
    private $dbCredentials;

    public function __construct(
        LoggerInterface $logger,
        ProfileLocator $profileLocator,
        FileInstaller $fileInstaller
    )
    {
        $this->logger         = $logger;
        $this->profileLocator = $profileLocator;
        $this->fileInstaller  = $fileInstaller;
    }

    public function setCopyProfileFiles(bool $copyProfile)
    {
        $this->copyProfileFiles = $copyProfile;
    }

    public function setProfile(string $profile = null)
    {
        $this->profile = $profile;
    }

    public function setDbCredentials(array $dbCredentials = [])
    {
        $this->dbCredentials = $dbCredentials;
    }

    public function setOverwriteExistingFiles(bool $overwriteExistingFiles)
    {
        $this->overwriteExistingFiles = $overwriteExistingFiles;
    }

    public function setSymlink(bool $symlink)
    {
        $this->symlink = $symlink;
    }

    public function needsProfile(): bool
    {
        return null === $this->profile;
    }

    public function needsDbCredentials(): bool
    {
        return empty($this->dbCredentials);
    }

    public function checkPrerequisites(): array
    {
        /** @var Check[] $checks */
        $checks = array_merge(
            Requirements::checkFilesystem(),
            Requirements::checkPhp()
        );

        $errors = [];
        foreach ($checks as $check) {
            if ($check->getState() === Check::STATE_ERROR) {
                if ($check->getLink()) {
                    if ('cli' === php_sapi_name()) {
                        $errors[] = sprintf('%s (see %s)', $check->getMessage(), $check->getLink());
                    } else {
                        $errors[] = sprintf('<a href="%s" target="_blank">%s</a>', $check->getLink(), $check->getMessage());
                    }
                } else {
                    $errors[] = $check->getMessage();
                }
            }
        }

        return $errors;
    }

    /**
     * @param array $params
     *
     * @return array Array of errors
     */
    public function install(array $params): array
    {
        $dbConfig = $this->resolveDbConfig($params);

        // try to establish a mysql connection
        try {
            $config = new Configuration();

            /** @var Connection $db */
            $db = DriverManager::getConnection($dbConfig, $config);

            // check utf-8 encoding
            $result = $db->fetchRow('SHOW VARIABLES LIKE "character\_set\_database"');
            if (!in_array($result['Value'], ['utf8mb4'])) {
                $errors[] = 'Database charset is not utf8mb4';
            }
        } catch (\Exception $e) {
            $errors[] = sprintf('Couldn\'t establish connection to MySQL: %s', $e->getMessage());
        }

        // check username & password
        $adminUser = $params['admin_username'];
        $adminPass = $params['admin_password'];

        if (strlen($adminPass) < 4 || strlen($adminUser) < 4) {
            $errors[] = 'Username and password should have at least 4 characters';
        }

        $profileId = null;
        if (null !== $this->profile) {
            $profileId = $this->profile;
        } else {
            $profileId = 'empty';
            if (isset($params['profile'])) {
                $profileId = $params['profile'];
            }
        }

        if (empty($profileId)) {
            $errors[] = sprintf('Invalid profile ID');

            return $errors;
        }

        $profile = null;

        try {
            $profile = $this->profileLocator->getProfile($profileId);
        } catch (\Exception $e) {
            $errors[] = sprintf(
                htmlentities($e->getMessage(), ENT_QUOTES, 'UTF-8')
            );
        }

        if (!empty($errors)) {
            return $errors;
        }

        try {
            return $this->runInstall(
                $profile,
                $dbConfig,
                [
                    'username' => $adminUser,
                    'password' => $adminPass
                ]
            );
        } catch (\Throwable $e) {
            $this->logger->error($e);

            return [
                $e->getMessage()
            ];
        }
    }

    public function resolveDbConfig(array $params): array
    {
        $dbConfig = [
            'driver'       => 'pdo_mysql',
            'wrapperClass' => Connection::class,
        ];

        // do not handle parameters if db credentials are set via config
        if (!empty($this->dbCredentials)) {
            return array_merge(
                $dbConfig,
                $this->dbCredentials
            );
        }

        // database configuration host/unix socket
        $dbConfig = array_merge($dbConfig, [
            'user'     => $params['mysql_username'],
            'password' => $params['mysql_password'],
            'dbname'   => $params['mysql_database'],
        ]);

        $hostSocketValue = $params['mysql_host_socket'];

        // use value as unix socket if file exists
        if (file_exists($hostSocketValue)) {
            $dbConfig['unix_socket'] = $hostSocketValue;
        } else {
            $dbConfig['host'] = $hostSocketValue;
            $dbConfig['port'] = $params['mysql_port'];
        }

        return $dbConfig;
    }

    private function runInstall(Profile $profile, array $dbConfig, array $userCredentials): array
    {
        $this->logger->info('Running installation with profile {profile}', [
            'profile' => $profile->getName()
        ]);

        $errors = [];
        if ($this->copyProfileFiles) {
            $errors = $this->fileInstaller->installFiles($profile, $this->overwriteExistingFiles, $this->symlink);
            if (count($errors) > 0) {
                return $errors;
            }
        }

        $setup = new Setup();

        $dbConfig['username'] = $dbConfig['user'];
        unset($dbConfig['user']);
        unset($dbConfig['driver']);
        unset($dbConfig['wrapperClass']);

        $setup->config([
            'database' => [
                'params' => $dbConfig
            ],
        ]);

        $kernel = new \AppKernel(Config::getEnvironment(), true);
        \Pimcore::setKernel($kernel);

        $kernel->boot();
        $setup->database();

        $errors = $this->setupProfileDatabase($setup, $profile, $userCredentials, $errors);

        Tool::clearSymfonyCache($kernel->getContainer());

        return $errors;
    }

    private function setupProfileDatabase(Setup $setup, Profile $profile, array $userCredentials, array $errors = []): array
    {
        try {
            if (empty($profile->getDbDataFiles())) {
                // empty installation
                $setup->contents($userCredentials);
            } else {
                foreach ($profile->getDbDataFiles() as $dbFile) {
                    $this->logger->info('Importing DB file {dbFile}', ['dbFile' => $dbFile]);
                    $setup->insertDump($dbFile);
                }

                $setup->createOrUpdateUser($userCredentials);
            }
        } catch (\Exception $e) {
            $this->logger->error($e);
            $errors[] = $e->getMessage();
        }

        return $errors;
    }
}
