<?php

namespace Grasmash\Evaluator;

use Alchemy\Zippy\Zippy;
use Doctrine\Common\Cache\FilesystemCache;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\TransferStats;
use Kevinrob\GuzzleCache\CacheMiddleware;
use Kevinrob\GuzzleCache\Storage\DoctrineCacheStorage;
use Kevinrob\GuzzleCache\Strategy\PrivateCacheStrategy;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

abstract class Priorities {
    const CRITICAL = 400;
    const MAJOR = 300;
    const NORMAL = 200;
    const MINOR = 100;
}
abstract class Categories {
    const BUG_REPORT = 1;
    const TASK = 2;
    const FEATURE_REQUEST = 3;
    const SUPPORT_REQUEST = 4;
    const PLAN = 5;
}

abstract class Statuses {
  const ACTIVE = 1;
  const FIXED = 2;
  const CLOSED_DUPLICATE = 3;
  const POSTPONED = 4;
  const CLOSED_WONT_FIX = 5;
  const CLOSED_WORKS_AS_DESIGNED = 6;
  const CLOSED_FIXED = 7;
  const NEEDS_REVIEW = 8;
  const NEEDS_WORK = 13;
  const RTBC = 14;
  const PATCH_TO_BE_PORTED = 15;
  const POSTPONED_NEED_INFO = 16;
  const CLOSED_OUTDATED = 17;
  const CLOSE_CANNOT_REPRODUCE = 18;
}
abstract class Vocabularies {
    const CORE_COMPATIBILITY = 6;
}

abstract class CoreCompatibilityTerms {
    const DRUPAL_8X = 7234;
}

class EvaluateCommand extends Command
{

    /** @var InputInterface */
    protected $input;

    /** @var OutputInterface */
    protected $output;

    /** @var Filesystem */
    protected $fs;

    /** @var string */
    protected $tmp;

    public function configure()
    {
        $this->setName('evaluate');
        $this->setDescription("Evaluate a contributed Drupal project.");
        $this->addArgument('project', InputArgument::REQUIRED, 'The machine name of the project to evaluate.');
        $this->addOption('dev-version', null, InputArgument::OPTIONAL, 'The dev version to evaluate. This is used for issue statistics.');
        $this->addOption('stable-version', null, InputArgument::OPTIONAL, 'The dev version to evaluate. This is used for code analysis.');
        $this->addUsage('acquia_connector --dev-version=8.x-1.x-dev');
        // @todo Assume major version, allow to be specified.
    }

    /**
     * @param \Symfony\Component\Console\Input\InputInterface $input
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     *
     * @return int
     * @throws \Exception
     */
    public function execute(InputInterface $input, OutputInterface $output)
    {
        // @see https://www.drupal.org/drupalorg/docs/api
        // https://www.drupal.org/api-d7/node.json?field_project_machine_name=[project-name]
        // You can pass special meta controls to your query: limit, page, sort, and direction.

        $this->input = $input;
        $this->output = $output;
        $project_name = $input->getArgument('project');
        // @todo Change this to a dynamic value from argument.
        $major_version = '8.x';
        $core_compatibility = CoreCompatibilityTerms::DRUPAL_8X;
        $this->fs = new Filesystem();
        $this->tmp = sys_get_temp_dir();

        $project = $this->getProject($project_name);
        $this->summarizeProjectMetadata($output, $project, $project_name,
            $major_version);

        $project_releases = $this->getProjectReleases($project, $core_compatibility);

        if ($input->getOption('dev-version')) {
            $dev_version = $input->getOption('dev-version');
        }
        else {
            $dev_version = $this->determineDevRelease($project_releases,
                $major_version, $project_name);
        }

        if ($input->getOption('stable-version')) {
            $recommended_version = $input->getOption('stable-version');
        }
        else {
            $recommended_version = $this->determineRecommendedRelease($project_releases, $major_version, $project_name);
        }

        // Download module.
        $project_string = $project_name . "-" . $recommended_version;
        $download_path = $this->downloadProjectFromDrupalOrg($project_string);

        // Code analysis.
        $phpstan_process = $this->startPhpStan($download_path);
        $phpcs_process = $this->startPhpCs($download_path);

        $this->summarizeIssues($output, $project, $dev_version);
        $this->summarizeReleases($output, $project_releases);

        // Calculate a "maintenance health" score based on:
        // Average time for issue response.
        // % critical vs major vs minor.
        // % bugs.
        // Average time between releases.
        // Maintenance status.
        // Development status.
        // SA Coverage
        // # of uncommitted rtbcs.

        // $project = new ContribProject($response_object);
        // https://www.drupal.org/api-d7/node.json?type=project_issue&field_project=3060&taxonomy_vocabulary_9=187541

        $output->writeln("Code Analysis for <comment>$recommended_version</comment>:");
        $this->endPhpStan($output, $phpstan_process, $project_name);
        $this->endPhpCs($output, $phpcs_process, $project_name);

        return 0;
    }

    /**
     * @param $project
     *
     * @return int
     */
    protected function countProjectIssues($project, $query = []) {
        if ($project->field_project_has_issue_queue) {
            $default_query = [
                'field_project' => $project->nid,
                'type' => 'project_issue',
                //'taxonomy_vocabulary_6' => 7234, // 8.x core compatibility.
            ];
            $query = array_merge($default_query, $query);
            $response_object = $this->requestNode($query);
            $last = $response_object->last;
            $url_parts = parse_url($last);
            $query = $url_parts['query'];
            parse_str($query, $query_parts);
            $num_pages = $query_parts['page'];

            if ($num_pages > 1) {
                $response_object = $this->requestNode([
                    'field_project' => $project->nid,
                    'type' => 'project_issue',
                    'page' => $num_pages,
                ]);
                $list_count = count($response_object->list);
                $num_issues = (($num_pages - 1) * 100) + $list_count;
            }
            else {
                $num_issues = count($response_object->list);
            }
            return $num_issues;
        }
        else {
            return 0;
        }
    }


    /**
     * @param $project
     * @param $core_compatibility
     *
     * @return array
     */
    protected function getProjectReleases($project, $core_compatibility) {
        // Maybe use a different endpoint?
        // @see https://updates.drupal.org/release-history/ctools/8.x
        if ($project->field_project_has_releases) {
            $response_object = $this->requestNode([
                'field_release_project' => $project->nid,
                'type' => 'project_release',
                'taxonomy_vocabulary_' . Vocabularies::CORE_COMPATIBILITY => $core_compatibility,
            ]);
            return $response_object->list;
        }
        else {
            return [];
        }
    }

    /**
     * @return \GuzzleHttp\Client
     */
    protected function createGuzzleClient() {
        $stack = HandlerStack::create();
        $stack->push(
            new CacheMiddleware(
                new PrivateCacheStrategy(
                    new DoctrineCacheStorage(
                        new FilesystemCache(__DIR__ . '/../cache')
                    )
                )
            ),
            'cache'
        );
        $client = new Client(['handler' => $stack]);
        return $client;
    }

    /**
     * @param $query
     *
     * @return mixed
     */
    protected function requestNode($query) {
        $client = $this->createGuzzleClient();
        $response = $client->request('GET',
            'https://www.drupal.org/api-d7/node.json', [
                'query' => $query,
                'on_stats' => function (TransferStats $stats) use (&$url) {
                    $url = $stats->getEffectiveUri();
                }
            ]);
        if ($response->getStatusCode() !== 200) {
            throw new \Exception("Request to $url failed, returned {$response->getStatusCode()} with reason: {$response->getReasonPhrase()}");
        }
        $body = $response->getBody()->getContents();
        $response_object = json_decode($body);
        return $response_object;
    }

    /**
     * @param $numerator
     * @param $denominator
     *
     * @return string
     */
    protected function formatPercentage($numerator, $denominator) {
        return number_format($numerator / $denominator * 100, 2);
    }

    protected function downloadProjectFromDrupalOrg($project_string)
    {
        $targz_filename = "$project_string.tar.gz";
        $targz_filepath = "{$this->tmp}/$targz_filename";
        $untarred_dirpath = "{$this->tmp}/$project_string";
        if (!file_exists($targz_filepath) || getenv('COMPOSERIZE_DRUPAL_NO_CACHE') == true) {
            file_put_contents(
                $targz_filepath,
                fopen(
                    "https://ftp.drupal.org/files/projects/$targz_filename",
                    'r'
                )
            );
        }
        if (!file_exists($untarred_dirpath)) {
            $this->fs->mkdir($untarred_dirpath);
            $zippy = Zippy::load();
            $archive = $zippy->open($targz_filepath);
            $archive->extract($untarred_dirpath);
        }

        return $untarred_dirpath;
    }

    /**
     * @param $download_path
     *
     * @return \Symfony\Component\Process\Process
     */
    protected function startPhpStan(
        $download_path
    ): \Symfony\Component\Process\Process {
        $command = "./vendor/bin/phpstan analyse '$download_path' --error-format=json --no-progress";
    return $this->startProcess($command);
    }

    /**
     * @param $command
     *
     * @return \Symfony\Component\Process\Process
     */
    protected function startProcess(
        $command
    ): \Symfony\Component\Process\Process {
        $root_dir = dirname(__DIR__);
        $process = new Process($command, $root_dir, null, null, 300);
        $process->start();
        if ($this->output->isVerbose()) {
            foreach ($process as $type => $data) {
                $this->output->writeln($data);
            }
        }
        return $process;
    }

    /**
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     * @param $phpstan_process
     * @param $project_name
     */
    protected function endPhpStan(
        OutputInterface $output,
        $phpstan_process,
        $project_name
    ): void {
        $phpstan_process->wait();
        if ($phpstan_process->getOutput()) {
            $phpstan_output = json_decode($phpstan_process->getOutput());
            $output->writeln("  <info>Deprecation errors</info>: {$phpstan_output->totals->errors}");
            $output->writeln("  <info>Deprecation file errors</info>: {$phpstan_output->totals->file_errors}");
        }
        else {
            $output->writeln("  <error>Failed to execute PHPStan against $project_name</error>");
            $output->write($phpstan_process->getErrorOutput());
        }
    }

    /**
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     * @param $phpcs_process
     * @param $project_name
     * @param $phpstan_process
     */
    protected function endPhpCs(
        OutputInterface $output,
        $phpcs_process,
        $project_name
    ): void {
        $phpcs_process->wait();
        if ($phpcs_process->getOutput()) {
            $phpcs_output = json_decode($phpcs_process->getOutput());
            $output->writeln("  <info>Coding standards errors</info>: {$phpcs_output->totals->errors}");
            $output->writeln("  <info>Coding standards warnings</info>: {$phpcs_output->totals->warnings}");
        }
        else {
            $output->writeln("  <error>Failed to execute PHPCS against $project_name</error>");
            $output->write($phpcs_process->getErrorOutput());
        }
    }

    /**
     * @param $download_path
     *
     * @return \Symfony\Component\Process\Process
     */
    protected function startPhpCs($download_path
    ): \Symfony\Component\Process\Process {
        $phpcs_process = $this->startProcess("./vendor/bin/phpcs '$download_path' --standard=./vendor/drupal/coder/coder_sniffer/Drupal --report=json");
        return $phpcs_process;
    }

    /**
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     * @param $project
     * @param $version
     */
    protected function summarizeIssues(
        OutputInterface $output,
        $project,
        $version
    ): void {
        // Determine version to filter on.
        // @todo Only count issues that are not closed!
        $num_issues = $this->countProjectIssues($project,
            ['field_issue_version' => $version]);
        // @todo handle 0 issues edge case.
        $output->writeln("<info>Issue statistics</info> for <comment>$version</comment>");
        $output->writeln("  <info>Total issues</info>:  " . $num_issues);
        $output->writeln('  <info>By priority</info>:');

        $num_crit_issues = $this->countProjectIssues($project, [
            'field_issue_priority' => Priorities::CRITICAL,
            'field_issue_version' => $version
        ]);
        $percent_crit = $this->formatPercentage($num_crit_issues, $num_issues);
        $output->writeln("    <info># critical</info>:  $num_crit_issues ($percent_crit%)");

        $num_major_issues = $this->countProjectIssues($project, [
            'field_issue_priority' => Priorities::MAJOR,
            'field_issue_version' => $version
        ]);
        $percent_major = $this->formatPercentage($num_major_issues,
            $num_issues);
        $output->writeln("    <info># major</info>:     $num_major_issues ($percent_major%)");

        $num_normal_issues = $this->countProjectIssues($project, [
            'field_issue_priority' => Priorities::NORMAL,
            'field_issue_version' => $version
        ]);
        $percent_normal = $this->formatPercentage($num_normal_issues,
            $num_issues);
        $output->writeln("    <info># normal</info>:    $num_normal_issues ($percent_normal%)");

        $num_minor_issues = $this->countProjectIssues($project, [
            'field_issue_priority' => Priorities::MINOR,
            'field_issue_version' => $version
        ]);
        $percent_minor = $this->formatPercentage($num_minor_issues,
            $num_issues);
        $output->writeln("    <info># minor</info>:     $num_minor_issues ($percent_minor%)");

        $output->writeln("  <info>By category</info>:");

        $num_bug_issues = $this->countProjectIssues($project, [
            'field_issue_category' => Categories::BUG_REPORT,
            'field_issue_version' => $version
        ]);
        $percent_bugs = $this->formatPercentage($num_bug_issues, $num_issues);
        $output->writeln("    <info># bug</info>:       $num_bug_issues ($percent_bugs%)");

        $num_feature_issues = $this->countProjectIssues($project, [
            'field_issue_category' => Categories::FEATURE_REQUEST,
            'field_issue_version' => $version
        ]);
        $percent_features = $this->formatPercentage($num_feature_issues,
            $num_issues);
        $output->writeln("    <info># feature</info>:   $num_feature_issues ($percent_features%)");

        $num_support_issues = $this->countProjectIssues($project, [
            'field_issue_category' => Categories::SUPPORT_REQUEST,
            'field_issue_version' => $version
        ]);
        $percent_support = $this->formatPercentage($num_support_issues,
            $num_issues);
        $output->writeln("    <info># support</info>:   $num_support_issues ($percent_support%)");

        $num_task_issues = $this->countProjectIssues($project, [
            'field_issue_category' => Categories::TASK,
            'field_issue_version' => $version
        ]);
        $percent_task = $this->formatPercentage($num_task_issues, $num_issues);
        $output->writeln("    <info># task</info>:      $num_task_issues ($percent_task%)");

        $num_plan_issues = $this->countProjectIssues($project, [
            'field_issue_category' => Categories::PLAN,
            'field_issue_version' => $version
        ]);
        $percent_plan = $this->formatPercentage($num_plan_issues, $num_issues);
        $output->writeln("    <info># plan</info>:      $num_plan_issues ($percent_plan%)");

        if ($project->field_project_has_issue_queue) {
            $query = [
                'field_project' => $project->nid,
                'type' => 'project_issue',
                'field_issue_status' => Statuses::CLOSED_FIXED,
                'sort' => 'changed',
                'direction' => 'DESC',
            ];
            $response_object = $this->requestNode($query);
            if (count($response_object->list)) {
                $latest_issue = $response_object->list[0];
                $latest_issue_date = date('r', $latest_issue->changed);
            }
            else {
                $latest_issue_date = 'never';
            }

            $output->writeln("  <info>Last \"Closed (fixed)\"</info>:  $latest_issue_date");
        }
    }

    /**
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     * @param $project_releases
     */
    protected function summarizeReleases(
        OutputInterface $output,
        $project_releases
    ): void {
        $num_releases = count($project_releases);
        $last_release = end($project_releases);
        $last_release_date = date('r', $last_release->created);

        $output->writeln("<info># releases</info>:      $num_releases");
        $output->writeln("<info>last release</info>:    $last_release_date");
    }

    /**
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     * @param $project
     * @param $project_name
     * @param $major_version
     */
    protected function summarizeProjectMetadata(
        OutputInterface $output,
        $project,
        $project_name,
        $major_version
    ): void {
        $output->writeln($project->title . ' (' . $project_name . ')');
        $output->writeln('<info>Downloads</info>:  ' . $project->field_download_count);
        $output->writeln('<info>SA Coverage</info>:  ' . $project->field_security_advisory_coverage);
        $output->writeln('<info>Starred</info>:  ' . count($project->flag_project_star_user));
        $output->writeln('<info>Usage</info>:  ' . $project->project_usage->{"$major_version"});
    }

    /**
     * @param $project_name
     *
     * @return mixed
     * @throws \Exception
     */
    protected function getProject($project_name) {
        $response_object = $this->requestNode(['field_project_machine_name' => $project_name]);
        $list_count = count($response_object->list);
        if (!$list_count) {
            throw new \Exception("No project with machine name $project_name could be found.");
        }

        $project = $response_object->list[0];
        return $project;
    }

    /**
     * @param $project_releases
     * @param $major_version
     * @param $project_name
     *
     * @throws \Exception
     * @return string
     */
    protected function determineRecommendedRelease(
        $project_releases,
        $major_version,
        $project_name) {
        // Stable releases are typically at the end of the array.
        $releases = array_reverse($project_releases);
        foreach ($releases as $project_release) {

            // If field_release_version_extra is null, then it is not a dev
            // alpha, beta, or rc release.
            if (is_null($project_release->field_release_version_extra)
                && substr($project_release->field_release_version,
                    0, 3) == $major_version) {
                $recommended_version = $project_release->field_release_version;
                return $recommended_version;
            }
        }
        if (!isset($recommended_version)) {
            throw new \Exception("Unable to determine recommended release for $project_name for Drupal major version $major_version.");
        }
    }

    /**
     * @param $project_releases
     * @param $major_version
     * @param $project_name
     *
     * @return mixed
     * @throws \Exception
     */
    protected function determineDevRelease(
        $project_releases,
        $major_version,
        $project_name
    ) {
        // We're only querying dev versions. E.g., 8.x-3-x-dev.
        foreach ($project_releases as $project_release) {
            if ($project_release->field_release_version_extra
                && substr($project_release->field_release_version,0, 3) == $major_version
                && $project_release->field_release_version_extra == 'dev') {
                $dev_version = $project_release->field_release_version;
                return $dev_version;
            }
        }
        if (!isset($dev_version)) {
            throw new \Exception("Unable to find development release for $project_name for Drupal major version $major_version.");
        }
    }

}
