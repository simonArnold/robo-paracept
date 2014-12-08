<?php
namespace Codeception\Task;

use Codeception\TestCase;
use Robo\Task\Shared\TaskException;
use Robo\Task\Shared\TaskInterface;
use Symfony\Component\Finder\Finder;

trait SplitTestsByGroups {

    protected function taskSplitTestsByGroups($numGroups)
    {
        return new SplitTestsByGroupsTask($numGroups);
    }

    protected function taskSplitTestFilesByGroups($numGroups)
    {
        return new SplitTestFilesByGroupsTask($numGroups);
    }

    protected function taskSplitGroupsByGroups($numGroups)
    {
        return new SplitGroupsByGroupsTask($numGroups);
    }
    
}

abstract class TestsSplitter {
    use \Robo\Output;

    protected $numGroups;
    protected $testsFrom = 'tests';
    protected $saveTo = 'tests/_log/paracept_';

    public function __construct($groups)
    {
        $this->numGroups = $groups;
    }

    public function testsFrom($path)
    {
        $this->testsFrom = $path;
        return $this;
    }

    public function groupsTo($pattern)
    {
        $this->saveTo = $pattern;
        return $this;
    }

}

/**
 *
 * Loads all tests into groups and saves them to groupfile according to pattern.
 *
 * ``` php
 * <?php
 * $this->taskSplitTestsByGroups(5)
 *    ->testsFrom('tests')
 *    ->groupsTo('tests/_log/paratest_')
 *    ->run();
 * ?>
 * ```
 */
class SplitTestsByGroupsTask extends TestsSplitter implements TaskInterface
{
    public function run()
    {
        if (!class_exists('\Codeception\TestLoader')) {
            throw new TaskException($this, "This task requires Codeception to be loaded. Please require autoload.php of Codeception");
        }
        $testLoader = new \Codeception\TestLoader($this->testsFrom);
        $testLoader->loadTests();
        $tests = $testLoader->getTests();

        $i = 0;
        $groups = [];

        $this->printTaskInfo("Processing ".count($tests)." files");
        // splitting tests by groups
        foreach ($tests as $test) {
            $groups[($i % $this->numGroups) + 1][] = \Codeception\TestCase::getTestFullName($test);
            $i++;
        }

        // saving group files
        foreach ($groups as $i => $tests) {
            $filename = $this->saveTo . $i;
            $this->printTaskInfo("Writing $filename");
            file_put_contents($filename, implode("\n", $tests));
        }
    }
}

/**
 *
 * Loads all tests of specific groups into groups and saves them to groupfile according to pattern.
 *
 * ``` php
 * <?php
 * $this->taskSplitGroupsByGroups(5)
 *    ->groups($groupsToTest)
 *    ->groupsTo('tests/_log/paratest_')
 *    ->run();
 * ?>
 * ```
 */
class SplitGroupsByGroupsTask extends TestsSplitter implements TaskInterface
{
    protected $wantedGroups;

    public function groups($groups) {
        $this->wantedGroups = array_flip($groups);
        return $this;
    }

    public function run() {
        $availableGroups = $this->getTestsByGroups();

        // Output all mistyped groups
        $unknownGroups = array_diff(array_keys($this->wantedGroups), array_keys($availableGroups));
        foreach ($unknownGroups as $x) $this->printTaskInfo("Unknown group: " . $x);

        // Process known groups
        $processedGroups = array_intersect_key($availableGroups, $this->wantedGroups);
        if (count($processedGroups) == 0) {
            throw new \Exception("No valid groups provided");
        }

        $this->printTaskInfo("Processing " . count($processedGroups) . " groups.");
        $tests = array_unique(call_user_func_array('array_merge', $processedGroups));

        $i = 0;
        $groups = [];

        // splitting tests by groups
        foreach ($tests as $test) {
            $groups[($i % $this->numGroups) + 1][] = $tests[$i];
            $i++;
        }

        // saving group files
        foreach ($groups as $i => $tests) {
            $filename = $this->saveTo . $i;
            $this->printTaskInfo("Writing $filename");
            file_put_contents($filename, implode("\n", $tests));
        }
    }

    public function getGroupNames() {
        return array_keys($this->getTestsByGroups());
    }

    /**
     * Analyzes test annotations and groups the tests by their group annotation.
     * @return 2-dimensional array where key is a group name and value an array of test full names
     */
    protected function getTestsByGroups() {
        $testLoader = new \Codeception\TestLoader($this->testsFrom);
        $testLoader->loadTests();
        $tests = $testLoader->getTests();

        $groupArray = [];
        // Analyze each test case
        foreach ($tests as $test) {
            list($class, $method) = explode("::",TestCase::getTestSignature($test));

            // Create reflection method to analyze doc comment
            $annotation = new \ReflectionMethod($class, $method);
            // Iterate over each line of doc comment
            foreach(explode(PHP_EOL, $annotation) as $line) {
                // Save test into groups mentioned in annotation
                if (preg_match('@group\s(.*)$@', $line, $group)) {
                    $group = $group[1];
                    // Create key if group was not mentioned before
                    if (!array_key_exists($group, $groupArray)) {
                        $groupArray[$group] = array();
                    }
                    array_push($groupArray[$group], TestCase::getTestFullName($test));
                }
            }
        }
        return $groupArray;
    }
}

/**
 * Finds all test files and splits them by group.
 * Unlike `SplitTestsByGroupsTask` does not load them into memory and not requires Codeception to be loaded
 *
 * ``` php
 * <?php
 * $this->taskSplitTestFilesByGroups(5)
 *    ->testsFrom('tests/unit/Acme')
 *    ->codeceptionRoot('projects/tested')
 *    ->groupsTo('tests/_log/paratest_')
 *    ->run();
 * ?>
 * ```
 */
class SplitTestFilesByGroupsTask extends TestsSplitter implements TaskInterface
{
    protected $projectRoot;
    
    public function projectRoot($path)
    {
        $this->projectRoot = $path;
        return $this;
    }

    public function run()
    {
        $files = Finder::create()
            ->name("*Cept.php")
            ->name("*Cest.php")
            ->name("*Test.php")
            ->path($this->testsFrom)
            ->in($this->projectRoot ? $this->projectRoot : getcwd());

        $i = 0;
        $groups = [];

        $this->printTaskInfo("Processing ".count($files)." files");
        // splitting tests by groups
        foreach ($files as $file) {
            $groups[($i % $this->numGroups) + 1][] = $file->getRelativePathname();
            $i++;
        }

        // saving group files
        foreach ($groups as $i => $tests) {
            $filename = $this->saveTo . $i;
            $this->printTaskInfo("Writing $filename");
            file_put_contents($filename, implode("\n", $tests));
        }
    }
}
