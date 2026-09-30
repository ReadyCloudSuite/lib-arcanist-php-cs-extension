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
     * @var array<string, string>
     */
    private $ruleDescriptions = [];

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
        $ruleDiffProvider = function ($ruleName) use ($path) {
            return $this->getSingleRuleDiff($path, $ruleName);
        };
        $ruleDescriptionProvider = function ($ruleName) {
            return $this->getRuleDescription($ruleName);
        };
        foreach ($json['files'] as $fix) {
            $messages = array_merge(
                $messages,
                $this->lintMessageBuilder->buildLintMessages($path, $fix, $ruleDiffProvider, $ruleDescriptionProvider)
            );
        }

        return $messages;
    }

    /**
     * Fetches the short human-readable summary of a rule via `php-cs-fixer describe`, caching it since
     * the same rule is described identically regardless of which file/hunk it's attributed to.
     *
     * @param string $ruleName
     * @return string
     */
    private function getRuleDescription($ruleName)
    {
        if (array_key_exists($ruleName, $this->ruleDescriptions)) {
            return $this->ruleDescriptions[$ruleName];
        }

        $future = new ExecFuture('%C describe %s', $this->getExecutableCommand(), $ruleName);
        list(, $stdout) = $future->resolve();

        $description = '';
        if (preg_match('/^Description of the `.*?` rule\.\n\n(.*?)\n\n/ms', $stdout, $matches)) {
            $description = trim(preg_replace('/\s+/', ' ', $matches[1]));
        }

        return $this->ruleDescriptions[$ruleName] = $description;
    }

    /**
     * Runs the fixer against a single file with a single rule enabled, so the resulting diff
     * can be used to figure out which lines a specific rule actually touches.
     *
     * php-cs-fixer refuses to accept `--config` and `--rules` together, so the only way to keep
     * the project's config (risky-allowed, per-rule options, finder) while isolating one rule is
     * to generate a throwaway config file that loads the real one and narrows down its rules.
     *
     * @param string $path
     * @param string $ruleName
     * @return string
     */
    private function getSingleRuleDiff($path, $ruleName)
    {
        $absolutePath = $this->getEngine()->getFilePathOnDisk($path);
        $configAbsPath = Filesystem::resolvePath(
            $this->configuration->getPhpCsFile(),
            $this->getEngine()->getWorkingCopy()->getProjectRoot()
        );

        $tmpConfigPath = tempnam(sys_get_temp_dir(), 'phpcsfixer_rule_') . '.php';
        file_put_contents($tmpConfigPath, sprintf(
            '<?php $config = require %s; $rules = $config->getRules(); $name = %s; ' .
            '$config->setRules([$name => $rules[$name] ?? true]); return $config;',
            var_export($configAbsPath, true),
            var_export($ruleName, true)
        ));

        try {
            $future = new ExecFuture(
                '%C fix --dry-run --diff --using-cache=no --config=%s %s',
                $this->getExecutableCommand(),
                $tmpConfigPath,
                $absolutePath
            );
            list(, $stdout) = $future->resolve();

            return $stdout;
        } finally {
            Filesystem::remove($tmpConfigPath);
        }
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
