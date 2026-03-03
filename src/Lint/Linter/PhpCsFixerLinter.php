<?php

class PhpCsFixerLinter extends \ArcanistExternalLinter
{
    /**
     * @var array
     */
    private $folderExclusions = [
        'Tests/',
        'Test/',
    ];

    /**
     * @var array
     */
    private $defaultFlags = [
        '--verbose',
        '--dry-run',
        '--diff',
        '--format=json',
        '--using-cache=no',
    ];

    /**
     * @var LinterConfiguration
     */
    private $configuration;

    /**
     * @var LintMessageBuilder
     */
    private $lintMessageBuilder;

    /**
     * @param LinterConfiguration $configuration
     */
    public function __construct(?LinterConfiguration $configuration = null)
    {
        if ($configuration === null) {
            $configuration = new LinterConfiguration();
        }

        $this->configuration = $configuration;

        $unifiedDiffFormat = false;
        if (
            version_compare($this->getVersion(), '2.8.0', '>=')
            && $this->configuration->isUnifiedDiffFormat()
        ) {
            $unifiedDiffFormat = true;
        }

        $this->lintMessageBuilder = new LintMessageBuilder($unifiedDiffFormat);

        $this->setPaths($this->configuration->getPaths());
    }

    public function getLinterName()
    {
        return 'PhpCsFixerLinter';
    }

    public function getInfoName()
    {
        return 'PHP_CS_FIXER Linter';
    }

    public function getInfoURI()
    {
        return 'https://github.com/FriendsOfPHP/PHP-CS-Fixer';
    }

    public function getInfoDescription()
    {
        return pht(
            'The PHP Coding Standards Fixer tool fixes most issues in your code' .
            'when you want to follow the PHP coding standards as defined in' .
            'the PSR-1 and PSR-2 documents and many more.'
        );
    }

    public function getLinterConfigurationName()
    {
        return 'php-cs-fixer';
    }

    public function getMandatoryFlags()
    {
        return ['fix'];
    }

    public function getInstallInstructions()
    {
        return
            'You should install "php-cs-fixer" globally, or locally.' .
            ' Please adjust "lint.php_cs_fixer.php_cs_binary" parameter in ".arcconfig" file accordingly'
        ;
    }

    public function getDefaultFlags()
    {
        return array_merge(
            $this->defaultFlags,
            [sprintf('--config=%s', $this->configuration->getPhpCsFile())]
        );
    }

    public function getLinterConfigurationOptions()
    {
        $options = [
            'fix_paths' => [
                'type' => 'optional string | list<string>',
                'help' => pht('Paths that needs to be linted'),
            ],
            'php_cs_file' => [
                'type' => 'optional string',
                'help' => pht('Path to config file'),
            ],
            'unified_diff_format' => [
                'type' => 'optional bool',
                'help' => pht('Unified diff format'),
            ],
        ];

        return $options + parent::getLinterConfigurationOptions();
    }

    public function setLinterConfigurationValue($key, $value)
    {
        switch ($key) {
            case 'fix_paths':
                $this->setPaths($this->resolveLintableFiles($value));
                $this->getEngine()->setPaths($this->getPaths());
                return;
            case 'php_cs_file':
                $this->configuration->setPhpCsFile($value);
                return;
            case 'unified_diff_format':
                $this->configuration->setUnifiedDiffFormat($value);
                $this->lintMessageBuilder = new LintMessageBuilder($value);
                return;
        }

        return parent::setLinterConfigurationValue($key, $value);
    }

    public function getDefaultBinary()
    {
        return $this->configuration->getBinaryFile();
    }

    /**
     * @return string|null
     */
    public function getVersion()
    {
        list($stdout) = execx('%C --version', $this->getExecutableCommand());

        $version = null;
        if (preg_match('#PHP CS Fixer (\d+\.\d+\.\d+\.*)#i', $stdout, $matches)) {
            $version = $matches[1];
        }

        return $version;
    }

    public function parseLinterOutput($path, $err, $stdout, $stderr)
    {
        $json = phutil_json_decode($stdout);
        $messages = [];
        foreach ($json['files'] as $fix) {
            $messages = array_merge($messages, $this->lintMessageBuilder->buildLintMessages($path, $fix));
        }

        return $messages;
    }

    public function shouldExpectCommandErrors()
    {
        return true;
    }

    protected function getPathArgumentForLinterFuture($path)
    {
        $root = $this->getEngine()->getWorkingCopy()->getProjectRoot();

        $absPath = Filesystem::resolvePath($path, $root);
        $absRoot = rtrim($root, '/') . '/';

        if (strpos($absPath, $absRoot) === 0) {
            return substr($absPath, strlen($absRoot));
        }

        return $path;
    }

    private function resolveLintableFiles(array $lintablePaths)
    {
        $paths = $this->getEngine()->getPaths();

        $properPaths = [];

        foreach ($paths as $key => $path) {
            if (!file_exists($this->getEngine()->getFilePathOnDisk($path))) {
                unset($paths[$key]);
            }

            if (
                preg_match('#' . implode('|', $this->pregQuotePaths($lintablePaths)) . '#i', $path)
                && preg_match('#\.(php)$#', $path)
                && preg_match('#' . implode('|', $this->pregQuotePaths($this->folderExclusions)) . '#i', $path) === 0
            ) {
                $properPaths[] = $path;
            }
        }
        return array_merge($this->configuration->getPaths(), $properPaths);
    }

    private function pregQuotePaths(array $paths)
    {
        foreach ($paths as $key => $path) {
            $paths[$key] = preg_quote($path, '#');
        }

        return $paths;
    }
}
