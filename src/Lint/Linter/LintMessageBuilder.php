<?php

use ptlis\DiffParser\Line;
use ptlis\DiffParser\Parser;

class LintMessageBuilder
{
    const CHANGE_NOTATION_REGEX = '#^(%s)(?:\s|[a-zA-Z]|$)#';

    private $unifiedDiffFormat;

    public function __construct($unifiedDiffFormat = true)
    {
        $this->unifiedDiffFormat = $unifiedDiffFormat;
    }

    /**
     * @param string $path
     * @param array $fixData
     * @param callable|null $ruleDiffProvider function(string $ruleName): string
     * @param callable|null $ruleDescriptionProvider function(string $ruleName): string
     * @return \ArcanistLintMessage[]
     */
    public function buildLintMessages(
        $path,
        array $fixData,
        ?callable $ruleDiffProvider = null,
        ?callable $ruleDescriptionProvider = null
    ) {
        if (!$this->unifiedDiffFormat) {
            return $this->guessMessages($path, $fixData);
        }
        return $this->doBuildLintMessages($path, $fixData, $ruleDiffProvider, $ruleDescriptionProvider);
    }

    private function doBuildLintMessages($path, array $fixData, ?callable $ruleDiffProvider, ?callable $ruleDescriptionProvider)
    {
        $changeSet = (new Parser())->parseLines(explode("\n", $fixData['diff']));
        $ruleBlocks = $this->collectRuleBlocks($fixData['appliedFixers'], $ruleDiffProvider);

        /** @var \ArcanistLintMessage[] $messages */
        $messages = [];

        foreach ($changeSet->getFiles() as $file) {
            foreach ($file->getHunks() as $hunk) {
                $minusLines = [];
                $plusLines = [];
                $firstChangedLine = null;
                $currentLine = $hunk->getOriginalStart();

                foreach ($hunk->getLines() as $line) {
                    $op = $line->getOperation();
                    $isRemoved = ($op === 2 || $op === '-' || $op === 'removed');
                    $isAdded = ($op === 1 || $op === '+' || $op === 'added');

                    if ($isRemoved) {
                        if ($firstChangedLine === null) $firstChangedLine = $currentLine;
                        $minusLines[] = "-" . $line->getContent();
                        $currentLine++;
                    } elseif ($isAdded) {
                        if ($firstChangedLine === null) $firstChangedLine = $currentLine;
                        $plusLines[] = "+" . $line->getContent();
                    } else {
                        $currentLine++;
                    }
                }

                if (!empty($minusLines) || !empty($plusLines)) {
                    $hunkStart = $firstChangedLine ?: $hunk->getOriginalStart();
                    $hunkEnd = empty($minusLines) ? $hunkStart : $hunkStart + count($minusLines) - 1;

                    $matchedRuleBlocks = $this->matchRuleBlocksToRange($ruleBlocks, $hunkStart, $hunkEnd);

                    if (!empty($matchedRuleBlocks)) {
                        foreach ($matchedRuleBlocks as $fixer => $block) {
                            $description = $ruleDescriptionProvider !== null ? $ruleDescriptionProvider($fixer) : '';
                            $messages[] = $this->buildMessage(
                                $path,
                                $block['start'],
                                $fixer,
                                $block['minus'],
                                $block['plus'],
                                $description
                            );
                        }
                    } else {
                        $ruleName = $this->getTrimmedAppliedFixers($fixData['appliedFixers']);
                        $messages[] = $this->buildMessage($path, $hunkStart, $ruleName, $minusLines, $plusLines, '');
                    }
                }
            }
        }

        return $messages;
    }

    /**
     * @param string $path
     * @param int $line
     * @param string $ruleName
     * @param string[] $minusLines
     * @param string[] $plusLines
     * @param string $ruleDescription
     * @return \ArcanistLintMessage
     */
    private function buildMessage($path, $line, $ruleName, array $minusLines, array $plusLines, $ruleDescription)
    {
        if (count($minusLines) > 5) {
            $minusLines = array_slice($minusLines, 0, 3);
        }

        $message = new \ArcanistLintMessage();
        $message->setPath($path);
        $message->setLine($line);
        $message->setChar(1);
        $message->setCode('CS.FIX');
        $message->setSeverity(\ArcanistLintSeverity::SEVERITY_WARNING);
        $message->setName($ruleName !== '' ? $ruleName : 'PHP-CS-Fixer');

        $header = $ruleDescription !== ''
            ? $ruleDescription
            : "Suggested changes ($ruleName):";

        $description = "$header\n\n```\n";
        if (!empty($minusLines)) $description .= implode("\n", $minusLines) . "\n";
        if (!empty($plusLines)) $description .= implode("\n", $plusLines) . "\n";
        $description .= "```\n";

        $message->setDescription($description);

        return $message;
    }

    /**
     * Runs the fixer for each individually applied rule and records which original line ranges (with the
     * matching diff lines) it touches, so a specific hunk can later be attributed to the rule(s) that
     * actually changed it, each with its own diff.
     *
     * @param string[] $appliedFixers
     * @param callable|null $ruleDiffProvider
     * @return array<string, array<array{start: int, end: int, minus: string[], plus: string[]}>>
     */
    private function collectRuleBlocks(array $appliedFixers, ?callable $ruleDiffProvider)
    {
        if ($ruleDiffProvider === null) {
            return [];
        }

        $ruleBlocks = [];
        foreach ($appliedFixers as $fixer) {
            $ruleBlocks[$fixer] = $this->extractChangedBlocks($ruleDiffProvider($fixer));
        }

        return $ruleBlocks;
    }

    /**
     * @param array<string, array<array{start: int, end: int, minus: string[], plus: string[]}>> $ruleBlocks
     * @param int $hunkStart
     * @param int $hunkEnd
     * @return array<string, array{start: int, end: int, minus: string[], plus: string[]}>
     */
    private function matchRuleBlocksToRange(array $ruleBlocks, $hunkStart, $hunkEnd)
    {
        $matched = [];
        foreach ($ruleBlocks as $fixer => $blocks) {
            foreach ($blocks as $block) {
                if ($hunkStart <= $block['end'] && $block['start'] <= $hunkEnd) {
                    $matched[$fixer] = $block;
                    break;
                }
            }
        }

        return $matched;
    }

    /**
     * Parses a single-rule diff and returns the original-file line ranges it touched, along with the
     * diff lines for each range.
     *
     * @param string $diff
     * @return array<array{start: int, end: int, minus: string[], plus: string[]}>
     */
    private function extractChangedBlocks($diff)
    {
        if (trim((string) $diff) === '') {
            return [];
        }

        $blocks = [];
        $changeSet = (new Parser())->parseLines(explode("\n", $diff));

        foreach ($changeSet->getFiles() as $file) {
            foreach ($file->getHunks() as $hunk) {
                $currentLine = $hunk->getOriginalStart();
                $firstChangedLine = null;
                $minusLines = [];
                $plusLines = [];

                foreach ($hunk->getLines() as $line) {
                    $op = $line->getOperation();
                    $isRemoved = ($op === 2 || $op === '-' || $op === 'removed');
                    $isAdded = ($op === 1 || $op === '+' || $op === 'added');

                    if ($isRemoved) {
                        if ($firstChangedLine === null) $firstChangedLine = $currentLine;
                        $minusLines[] = "-" . $line->getContent();
                        $currentLine++;
                    } elseif ($isAdded) {
                        if ($firstChangedLine === null) $firstChangedLine = $currentLine;
                        $plusLines[] = "+" . $line->getContent();
                    } else {
                        if ($firstChangedLine !== null) {
                            $blocks[] = [
                                'start' => $firstChangedLine,
                                'end' => empty($minusLines) ? $firstChangedLine : $firstChangedLine + count($minusLines) - 1,
                                'minus' => $minusLines,
                                'plus' => $plusLines,
                            ];
                            $firstChangedLine = null;
                            $minusLines = [];
                            $plusLines = [];
                        }
                        $currentLine++;
                    }
                }

                if ($firstChangedLine !== null) {
                    $blocks[] = [
                        'start' => $firstChangedLine,
                        'end' => empty($minusLines) ? $firstChangedLine : $firstChangedLine + count($minusLines) - 1,
                        'minus' => $minusLines,
                        'plus' => $plusLines,
                    ];
                }
            }
        }

        return $blocks;
    }

    /**
     * @param string $path
     * @param array $fixData
     * @return \ArcanistLintMessage[]
     */
    private function guessMessages($path, array $fixData)
    {
        $diffParts = $this->extractDiffParts($fixData['diff']);
        $rows = array_map('trim', file($path));

        $messages = [];
        for ($i = 0; $i < count($rows); $i++) {
            foreach ($diffParts as $diffPart) {
                if (isset($diffPart['informational'])) {
                    $matchedInformational = 0;
                    foreach ($diffPart['informational'] as $key => $item) {
                        if (!isset($rows[$i + $key]) || $rows[$i + $key] !== $item) {
                            break 2;
                        }
                        $matchedInformational++;
                    }
                    if ($matchedInformational === count($diffPart['informational'])) {
                        $i += $matchedInformational;
                        if (isset($diffPart['removals'])) {
                            $matchedRemovals = 0;
                            foreach ($diffPart['removals'] as $key => $removal) {
                                $realLine = $this->removeChangeNotationChar($removal, '-');
                                if (!isset($rows[$i + $key]) || $rows[$i + $key] !== $realLine) {
                                    break 2;
                                }
                                $matchedRemovals++;
                            }
                            if ($matchedRemovals === count($diffPart['removals'])) {
                                $messages[] = $this->createLintMessage($path, $diffPart, $i + 1, $fixData);
                                $i += $matchedRemovals - 1;
                                array_shift($diffParts);
                                break 1;
                            }
                        } elseif (isset($diffPart['additions'])) {
                            $messages[] = $this->createLintMessage($path, $diffPart, $i + 1, $fixData);
                            $i--;
                            array_shift($diffParts);
                            break 1;
                        }
                    }
                } elseif (isset($diffPart['removals'])) {
                    $matchedRemovals = 0;
                    foreach ($diffPart['removals'] as $key => $removal) {
                        $realLine = $this->removeChangeNotationChar($removal, '-');
                        if (!isset($rows[$i + $key]) || $rows[$i + $key] !== $realLine) {
                            break 2;
                        }
                        $matchedRemovals++;
                    }
                    if ($matchedRemovals === count($diffPart['removals'])) {
                        $messages[] = $this->createLintMessage($path, $diffPart, $i + 1, $fixData);
                        $i += $matchedRemovals - 1;
                        array_shift($diffParts);
                        break 1;
                    }
                }
            }
        }

        if (count($diffParts) > 0) {
            $message = $this->getPartialLintMessage($path, null, $fixData['appliedFixers']);
            $message->setDescription(sprintf(
                "Lint engine was unable to extract exact line number\n"
                . "Please consider applying these changes:\n```%s```",
                $fixData['diff']
            ));

            $messages[] = $message;
        }

        return $messages;
    }

    /**
     * @param string $string
     * @param string $char
     * @return string
     */
    private function removeChangeNotationChar($string, $char)
    {
        return trim(preg_replace(
            sprintf(self::CHANGE_NOTATION_REGEX, preg_quote($char, '#')),
            '',
            $string
        ));
    }

    /**
     * @param string $string
     * @param string $char
     * @return bool
     */
    private function isChangeNotationChar($string, $char)
    {
        return preg_match(
                sprintf(self::CHANGE_NOTATION_REGEX, preg_quote($char, '#')), $string
            ) === 1;
    }

    /**
     * @param string $diff
     * @return array
     */
    private function extractDiffParts($diff)
    {
        $diffParts = [];
        $parts = explode('@@ @@', $diff);
        array_shift($parts);
        $parts = array_values($parts);
        foreach ($parts as $key => $part) {
            $parts[$key] = array_map('trim', explode("\n", trim($part)));
        }

        $parts = $this->splitCombinedDiffs($parts);

        foreach ($parts as $key => $lines) {
            foreach ($lines as $line) {
                if ($this->isChangeNotationChar($line, '-')) {
                    $diffParts[$key]['removals'][] = $line;
                } elseif ($this->isChangeNotationChar($line, '+')) {
                    $diffParts[$key]['additions'][] = $line;
                } else {
                    $diffParts[$key]['informational'][] = $line;
                }
            }
        }

        $diffParts = array_filter($diffParts, function ($item) {
            if (
                isset($item['informational'])
                && (!isset($item['removals']) && !isset($item['additions']))
            ) {
                return false;
            }
            return true;
        });

        return $diffParts;
    }

    private function splitCombinedDiffs(array $parts)
    {
        foreach ($parts as $key => $lines) {
            $removals = 0;
            $lastRemovalNo = 0;
            $additions = 0;
            $lastAdditionNo = 0;
            foreach ($lines as $no => $line) {
                if ($this->isChangeNotationChar($line, '-')) {
                    $removals++;
                    $lastRemovalNo = $no + 1;
                } elseif ($this->isChangeNotationChar($line, '+')) {
                    $additions++;
                    $lastAdditionNo = $no + 1;
                } else {
                    if ($additions !== 0) {
                        $this->spliceLines($lines, $parts, $key, $lastAdditionNo);
                        return $this->splitCombinedDiffs($parts);
                    }
                    if ($removals !== 0) {
                        $this->spliceLines($lines, $parts, $key, $lastRemovalNo);
                        return $this->splitCombinedDiffs($parts);
                    }
                }
            }
        }

        return $parts;
    }

    /**
     * @param array $lines
     * @param array $parts
     * @param int $index
     * @param int $position
     */
    private function spliceLines(array $lines, array &$parts, $index, $position)
    {
        $part1 = array_slice($lines, 0, $position);
        $part2 = array_slice($lines, $position);
        array_splice($parts, $index,1, [$part1, $part2]);
    }

    /**
     * @param $path
     * @param array $diffPart
     * @param int $line
     * @param array $fixData
     * @return ArcanistLintMessage
     */
    private function createLintMessage($path, array $diffPart, $line, array $fixData)
    {
        $message = $this->getPartialLintMessage($path, $line, $fixData['appliedFixers']);

        $description = [
            "Please consider applying these changes:\n```",
            "--- Original",
            "+++ New",
            "@@ @@"
        ];
        if (isset($diffPart['removals'])) {
            $removals = array_map(
                function ($item) { return '- ' . trim(ltrim($item, '-')); },
                $diffPart['removals']
            );
            $description = array_merge($description, $removals);
        }
        if (isset($diffPart['additions'])) {
            $additions = array_map(
                function ($item) { return '+ ' . trim(ltrim($item, '+')); },
                $diffPart['additions']
            );
            $description = array_merge($description, $additions);
        }
        $description[] = '```';

        $message->setDescription(implode("\n", $description));

        return $message;
    }

    /**
     * @param string $path
     * @param int|null $line
     * @param array $appliedFixers
     * @return ArcanistLintMessage
     */
    private function getPartialLintMessage($path, $line, array $appliedFixers)
    {
        $name = $this->getTrimmedAppliedFixers($appliedFixers);

        $message = new \ArcanistLintMessage();
        $message->setName($name);
        $message->setPath($path);
        $message->setCode('PHP_CS_FIXER');
        $message->setLine($line);
        $message->setSeverity(\ArcanistLintSeverity::SEVERITY_WARNING);

        return $message;
    }

    private function getTrimmedAppliedFixers(array $appliedFixers)
    {
        $fixers = implode(', ', $appliedFixers);
        if (strlen($fixers) > 255) {
            $fixers = substr($fixers, 0, 250) . '...';
        }

        return $fixers;
    }
}
