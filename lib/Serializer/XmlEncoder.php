<?php

/*
 * This file is part of the PHPBench package
 *
 * (c) Daniel Leech <daniel@dantleech.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpBench\Serializer;

use PhpBench\Dom\Document;
use PhpBench\Model\Benchmark;
use PhpBench\Model\Subject;
use PhpBench\Model\Suite;
use PhpBench\Model\SuiteCollection;
use PhpBench\Model\Variant;
use PhpBench\PhpBench;
use PhpBench\Util\TimeUnit;

/**
 * Encodes the Suite object graph into an XML document.
 */
class XmlEncoder
{
    /**
     * Encode a Suite object into a XML document.
     *
     * @param SuiteCollection $suiteCollection
     *
     * @return Document
     */
    public function encode(SuiteCollection $suiteCollection)
    {
        $dom = new Document();
        $rootEl = $dom->createRoot('phpbench');
        $rootEl->setAttribute('version', PhpBench::VERSION);

        foreach ($suiteCollection->getSuites() as $suite) {
            $suiteEl = $rootEl->appendElement('suite');
            $suiteEl->setAttribute('context', $suite->getContextName());
            $suiteEl->setAttribute('date', $suite->getDate()->format('Y-m-d H:i:s'));
            $suiteEl->setAttribute('config-path', $suite->getConfigPath());

            $envEl = $suiteEl->appendElement('env');

            foreach ($suite->getEnvInformations() as $information) {
                $infoEl = $envEl->appendElement($information->getName());
                foreach ($information as $key => $value) {
                    $infoEl->setAttribute($key, $value);
                }
            }

            foreach ($suite->getBenchmarks() as $benchmark) {
                $this->processBenchmark($benchmark, $suiteEl);
            }
        }

        return $dom;
    }

    private function processBenchmark(Benchmark $benchmark, \DOMElement $suiteEl)
    {
        $benchmarkEl = $suiteEl->appendElement('benchmark');
        $benchmarkEl->setAttribute('class', $benchmark->getClass());
        foreach ($benchmark->getSubjects() as $subject) {
            $this->processSubject($subject, $benchmarkEl);
        }
    }

    private function processSubject(Subject $subject, \DOMElement $benchmarkEl)
    {
        $subjectEl = $benchmarkEl->appendElement('subject');
        $subjectEl->setAttribute('name', $subject->getName());

        foreach ($subject->getGroups() as $group) {
            $groupEl = $subjectEl->appendElement('group');
            $groupEl->setAttribute('name', $group);
        }

        foreach ($subject->getVariants() as $variant) {
            $this->processVariant($subject, $variant, $subjectEl);
        }
    }

    private function processVariant(Subject $subject, Variant $variant, \DOMElement $subjectEl)
    {
        $variantEl = $subjectEl->appendElement('variant');

        // TODO: These attributes should be on the subject, see
        // https://github.com/phpbench/phpbench/issues/307
        $variantEl->setAttribute('sleep', $subject->getSleep());
        $variantEl->setAttribute('output-time-unit', $subject->getOutputTimeUnit() ?: TimeUnit::MICROSECONDS);
        $variantEl->setAttribute('output-mode', $subject->getOutputMode() ?: TimeUnit::MODE_TIME);
        $variantEl->setAttribute('revs', $subject->getRevs());
        $variantEl->setAttribute('warmup', $subject->getWarmup());
        $variantEl->setAttribute('retry-threshold', $subject->getRetryThreshold());

        foreach ($variant->getParameterSet() as $name => $value) {
            $this->createParameter($variantEl, $name, $value);
        }

        if ($variant->hasErrorStack()) {
            $errorsEl = $variantEl->appendElement('errors');
            foreach ($variant->getErrorStack() as $error) {
                $errorEl = $errorsEl->appendElement('error', $error->getMessage());
                $errorEl->setAttribute('exception-class', $error->getClass());
                $errorEl->setAttribute('code', $error->getCode());
                $errorEl->setAttribute('file', $error->getFile());
                $errorEl->setAttribute('line', $error->getLine());
            }

            return;
        }

        $stats = $variant->getStats();
        $stats = iterator_to_array($stats);

        // ensure same order (for testing)
        ksort($stats);

        foreach ($variant as $iteration) {
            $iterationEl = $variantEl->appendElement('iteration');
            $iterationEl->setAttribute('net-time', $iteration->getTime());
            $iterationEl->setAttribute('rev-time', $iteration->getRevTime());
            $iterationEl->setAttribute('z-value', $iteration->getZValue());
            $iterationEl->setAttribute('memory', $iteration->getMemory());
            $iterationEl->setAttribute('deviation', $iteration->getDeviation());
            $iterationEl->setAttribute('rejection-count', $iteration->getRejectionCount());
        }

        $statsEl = $variantEl->appendElement('stats');
        foreach ($stats as $statName => $statValue) {
            $statsEl->setAttribute($statName, $statValue);
        }
    }

    private function createParameter($parentEl, $name, $value)
    {
        $parameterEl = $parentEl->appendElement('parameter');
        $parameterEl->setAttribute('name', $name);

        if (is_array($value)) {
            $parameterEl->setAttribute('type', 'collection');
            foreach ($value as $key => $element) {
                $this->createParameter($parameterEl, $key, $element);
            }

            return $parameterEl;
        }

        if (is_scalar($value)) {
            $parameterEl->setAttribute('value', $value);

            return $parameterEl;
        }

        throw new \InvalidArgumentException(sprintf(
            'Parameters must be either scalars or arrays, got: %s',
            is_object($value) ? get_class($value) : gettype($value)
        ));
    }
}