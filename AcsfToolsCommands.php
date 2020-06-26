<?php

/**
 * @file
 */

namespace Drush\Commands\acsf_tools;

use Drush\Drush;
use Consolidation\SiteAlias\SiteAliasManagerAwareInterface;
use Consolidation\SiteAlias\SiteAliasManagerAwareTrait;

/**
 * A Drush commandfile.
 */
class AcsfToolsCommands extends AcsfToolsUtils implements SiteAliasManagerAwareInterface {

  use SiteAliasManagerAwareTrait;

  /**
   * List the sites of the factory.
   *
   * @command acsf-tools:list
   *
   * @bootstrap site
   * @param array $options An associative array of options whose values come
   *   from cli, aliases, config, etc.
   * @option fields
   *   The list of fields to display (comma separated list).
   * @usage drush acsf-tools-list
   *   Get all details for all the sites of the factory.
   * @usage drush acsf-tools-list --fields
   *   Get prefix for all the sites of the factory.
   * @usage drush acsf-tools-list --fields=name,domains
   *   Get prefix, name and domains for all the sites of the factory.
   *
   * @aliases sfl,acsf-tools-list
   */
  public function sitesList(array $options = ['fields' => null]) {

    // Look for list of sites and loop over it.
    if ($sites = $this->getSites()) {
      // Render the info.
      $fields = $options['fields'];
      if (isset($fields)) {
        $expected_attributes = array_flip(explode(',', $fields));
      }

      foreach ($sites as $name => $details) {
        // Get site prefix from main domain.
        $prefix = explode('.', $details['domains'][0])[0];
        $this->output()->writeln($prefix);

        // Filter attributes.
        if (isset($expected_attributes)) {
          $details = array_intersect_key($details, $expected_attributes);
        }

        // Print attributes.
        $this->recursivePrint($details, 2);
      }
    }
  }

  /**
   * List details for each site in the Factory.
   *
   * @command acsf-tools:info
   *
   * @bootstrap site
   * @usage drush acsf-tools-info
   *   Get more details for all the sites of the factory.
   *
   * @aliases sfi,acsf-tools-info
   */
  public function sitesInfo() {

    // Don't run locally.
    if (!$this->checkAcsfFunction('gardens_site_data_load_file')) {
      return FALSE;
    }

    // Look for list of sites and loop over it.
    if (($map = gardens_site_data_load_file()) && isset($map['sites'])) {
      // Acquire sites info.
      $sites = array();
      foreach ($map['sites'] as $domain => $site_details) {
        $conf = $site_details['conf'];

        // Include settings file to get DB name. To save rescources, without bootsrtapping Drupal
        $settings_inc = "/var/www/site-php/{$_ENV['AH_SITE_GROUP']}.{$_ENV['AH_SITE_ENVIRONMENT']}/D7-{$_ENV['AH_SITE_ENVIRONMENT']}-" . $conf['gardens_db_name'] . "-settings.inc";
        $file = file_get_contents($settings_inc);
        $need= "\"database\" => \"";
        $need2= "\",";
        // Find db name
        $dpos = strpos($file, $need);
        $db_name = substr($file, ($dpos + strlen($need)) );
        $upos = strpos($db_name, $need2);
        // Isolate db name
        $db_name = substr($db_name, 0, $upos );

        // Re-structure  site
        $sites[$conf['gardens_site_id']]['domains'][] = $domain;
        $sites[$conf['gardens_site_id']]['conf'] = array('db_name' => $db_name, 'gname' => $conf['gardens_db_name'], );
      }
    }
    else {
      return $this->logger()->error("\nFailed to retrieve the list of sites of the factory.");
    }

    $this->output->writeln("\nID\t\tName\t\tDB Name\t\t\t\tDomain\n");

    foreach ($sites as $key => $site) {
      $this->output->writeln("$key\t\t" . $site['conf']['gname'] . "\t\t" . $site['conf']['db_name'] . "\t\t" . $site['domains'][0]);
    }
  }

  /**
   * Runs the passed drush command against all the sites of the factory (ml stands for multiple -l option).
   *
   * @command acsf-tools:ml
   *
   * @bootstrap site
   * @params $cmd
   *   The drush command you want to run against all sites in your factory.
   * @params $command_args Optional.
   *   A quoted, space delimited set of arguments to pass to your drush command.
   * @params $command_options Optional.
   *   A quoted space delimited set of options to pass to your drush command.
   * @option profiles
   *   Target sites with specific profiles. Comma list.
   * @option delay
   *   Number of seconds to delay to run command between each site.
   * @option total-time-limit
   *   Total time limit in seconds. If this option is present, the given command will be executed multiple times within the given time limit.
   * @option use-https
   *   Use secure urls for drush commands.
   * @usage drush acsf-tools-ml st
   *   Get output of `drush status` for all the sites.
   * @usage drush acsf-tools-ml cget "'system.site' 'mail'"
   *   Get value of site_mail variable for all the sites.
   * @usage drush acsf-tools-ml upwd "'admin' 'password'"
   *   Update user password.
   * @usage drush acsf-tools-ml cget "'system.site' 'mail'" "'format=json' 'interactive-mode'"
   *   Fetch config value in JSON format.
   * @usage drush acsf-tools-ml cr --delay=10
   *   Run cache clear on all sites with delay of 10 seconds between each site.
   * @usage drush acsf-tools-ml cron --use-https=1
   *   Run cron on all sites using secure url for URI.
   * @aliases sfml,acsf-tools-ml
   */
  public function ml($cmd, $command_args = '', $command_options = '', $options = ['profiles' => '', 'delay' => 0, 'total-time-limit' => 0, 'use-https' => 0]) {
    // Look for list of sites and loop over it.
    if ($sites = $this->getSites()) {
      if (!empty($options['profiles'])) {
        $profiles = explode(',', $options['profiles']);
        unset($options['profiles']);
      }

      $i = 0;
      $delay = $options['delay'];
      $total_time_limit = $options['total-time-limit'];
      $end = time() + $total_time_limit;

      do {
        foreach ($sites as $delta => $details) {
          // Get the first custom domain if any. Otherwise use the first domain
          // which is *.acsitefactory.com. Given this is used as --uri parameter
          // by the drush command, it can have an impact on the drupal process.
          $domain = $details['domains'][1] ?? $details['domains'][0];

          if (array_key_exists('use-https', $options)) {
            if ($options['use-https']) {
              // Use secure urls in URI to ensure base_url in Drupal uses https.
              $domain = 'https://' . $domain;
            }
          }

          $site_settings_filepath = 'sites/g/files/' . $details['name'] . '/settings.php';
          if (!empty($profiles) && file_exists($site_settings_filepath)) {
            $site_settings = @file_get_contents($site_settings_filepath);
            if (preg_match("/'install_profile'] = '([a-zA-Z_]*)'/", $site_settings, $matches)) {
              if (isset($matches[1]) && !in_array($matches[1], $profiles)) {
                $this->output()->writeln("\n=> Skipping command on $domain");
                continue;
              }
            }
          }

          $options['uri'] = $domain;
          $this->output()->writeln("\n=> Running command on $domain");

          $self = $this->siteAliasManager()->getSelf();
          $command_args = [];
          // Remove empty values from array.
          $options = array_filter($options);
          $process = Drush::drush($self, $cmd, $command_args, $options);
          $exit_code = $process->run();

          if ($exit_code !== 0) {
            $this->output()
              ->writeln("\n=> The command failed to execute for the site $domain.");
            continue;
          }

          $self = $this->siteAliasManager()->getSelf();
          $command_args = [];
          // Remove empty values from array.
          $options = array_filter($options);
          $process = Drush::drush($self, $cmd, $command_args, $options);
          $exit_code = $process->run();

          if ($exit_code !== 0) {
            $this->output()
              ->writeln("\n=> The command failed to execute for the site $domain.");
            continue;
          }
          // Delay in running the command for next site.
          if ($delay > 0 && $i < (count($sites) - 1)) {
            $this->output()
              ->writeln("\n=> Sleeping for $delay seconds before running command on next site.");
            sleep($delay);
          }

          // Print the output.
          $this->output()->writeln($process->getOutput());
        }
      } while ($total_time_limit && time() < $end && !empty($sites));
    }
  }

  /**
   * Make a DB dump for each site of the factory.
   *
   * @command acsf-tools:dump
   *
   * @bootstrap site
   * @param array $options An associative array of options whose values come from cli, aliases, config, etc.
   * @option result-folder
   *   The folder in which the dumps will be written. Defaults to ~/drush-backups.
   * @option gzip
   *   Compress the dump into a zip file.
   *
   * @usage drush acsf-tools-dump
   *   Create DB dumps for the sites of the factory. Default result folder will be used.
   * @usage drush acsf-tools-dump --result-folder=/home/project/backup/20160617
   *   Create DB dumps for the sites of the factory and store them in the specified folder. If folder does not exist the command will try to create it.
   * @usage drush acsf-tools-dump --result-folder=/home/project/backup/20160617 --gzip
   *   Same as above but using options of sql-dump command.
   *
   * @aliases sfdu,acsf-tools-dump
   */
  public function dbDump(array $options = ['result-folder' => '~/drush-backups', 'gzip' => FALSE]) {

    // Ask for confirmation before running the command.
    if (!$this->promptConfirm()) {
      return;
    }

    // Identify target folder.
    $result_folder = $options['result-folder'];

    // Look for list of sites and loop over it.
    if ($sites = $this->getSites()) {
      $arguments = [];
      $command = 'sql-dump';

      $options = Drush::input()->getOptions();
      unset($options['php']);
      unset($options['php-options']);

      unset($options['result-folder']);

      foreach ($sites as $details) {
        $domain = $details['domains'][0];
        $prefix = explode('.', $domain)[0];

        // Get options passed to this drush command & append it with options
        // needed by the next command to execute.
        $options = Drush::redispatchOptions();
        unset($options['php']);
        unset($options['php-options']);
        unset($options['result-folder']);

        $current_date = date("Ymd");
        // Folder based on current date.
        $backup_result_folder = $result_folder . '/' . $current_date;
        if (!is_dir($backup_result_folder)) {
            mkdir($backup_result_folder, 0755, true);
        }
        $options['result-file'] = $backup_result_folder . '/' . $prefix . '.sql';
        $options['uri'] = $domain;

        $this->logger()->info("\n=> Running sfdu command on $domain");
        $self = $this->siteAliasManager()->getSelf();
        // Remove empty values from array.
        $options = array_filter($options);
        $process = Drush::drush($self, $command, $arguments, $options);
        $exit_code = $process->run();

        if ($exit_code !== 0) {
          // Throw an exception with details about the failed process.
          $this->output()
            ->writeln("\n=> The command failed to execute for the site $domain.");
          continue;
        }

        // Log Success Message
        $this->logger()->info("\n=> DB Dump for the site completed Successfully $domain");
      }
    }
  }

  /**
   * Make a DB dump for each site of the factory.
   *
   * @command acsf-tools:restore
   *
   * @bootstrap site
   * @param array $options An associative array of options whose values come from cli, aliases, config, etc.
   * @option source-folder
   *   The folder in which the dumps are stored. Defaults to ~/drush-backups.
   * @option gzip
   *   Restore from a zipped dump.
   *
   * @usage drush acsf-tools-restore
   *   Restore DB dumps for the sites of the factory. Default backup folder will be used.
   * @usage drush acsf-tools-restore --source-folder=/home/project/backup/20160617
   *   Restore DB dumps for factory sites that are stored in the specified folder.
   * @usage drush acsf-tools-restore --source-folder=/home/project/backup/20160617 --gzip
   *   Restore compressed DB dumps for factory sites that are stored in the specified folder.
   *
   * @aliases sfr,acsf-tools-restore
   *
   * @return bool|void
   */
  function dbRestore(array $options = ['source-folder' => '~/drush-backups', 'gzip' => FALSE]) {

    // Ask for confirmation before running the command.
    if (!$this->promptConfirm()) {
      return false;
    }

    // Identify source folder.
    $source_folder = $options['source-folder'];

    if (!is_dir($source_folder)) {
      // Source folder does not exist.
      return $this->logger()->error(dt("Source folder $source_folder does not exist."));
    }

    $gzip = $options['gzip'];

    // Look for list of sites and loop over it.
    if ($sites = $this->getSites()) {
      $arguments = [];

      foreach ($sites as $details) {
        $domain = $details['domains'][0];
        $prefix = explode('.', $domain)[0];

        $source_file = $source_folder . '/' . $prefix . '.sql';

        if ($gzip) {
          $source_file .= '.gz';
        }

        if (!file_exists($source_file)) {
          $this->logger()->error("\n => No source file $source_file for $prefix site.");
          continue;
        }

        // Temporary decompress the dump to be used with drush sql-cli.
        if ($gzip) {
          $shell_execution = Drush::shell('gunzip -k ' . $source_file);
          $exit_code = $shell_execution->run();

          if ($exit_code !== 0) {
            // Throw an exception with details about the failed process.
            $this->output()
              ->writeln("\n=> The command gunzip failed to execute for the site $domain.");
            continue;
          }

          $source_file = substr($source_file, 0, -3);
        }

        // Get options passed to this drush command & append it with options
        // needed by the next command to execute.
        $options = Drush::redispatchOptions();
        $options['uri'] = $domain;
        unset($options['php']);
        unset($options['php-options']);
        unset($options['source-folder']);
        unset($options['gzip']);
        // Command Started.
        $this->output()
          ->writeln("\n=> Restoring the Database on the Domain $domain.");

        $self = $this->siteAliasManager()->getSelf();

        // Remove empty values from array.
        $options = array_filter($options);
        $sql_connect_process = Drush::drush($self, 'sql-connect', $arguments, $options, ['output' => FALSE]);
        $exit_code_sql_connect = $sql_connect_process->run();

        if ($exit_code_sql_connect !== 0) {
          // $exit_code_sql_connect an exception with details about the failed process.
          $this->output()
            ->writeln("\n=> The sql-connect command failed to execute for the site $domain.");
          continue;
        }

        $result = json_decode($sql_connect_process->getOutput(), TRUE);

        if (!empty($result) && array_key_exists('object', $result)) {
          $sql_drop_process = Drush::drush($self, 'sql-drop', $arguments, $options);
          $sql_drop_process_exit_code = $sql_drop_process->run();

          if ($sql_drop_process_exit_code !== 0) {
            // Throw an exception with details about the failed process.
            $this->output()
              ->writeln("\n=> The sql-drop command failed to execute for the site $domain.");
            continue;
          }

          $shell_execution_process = Drush::shell($result['object'] . ' < ' . $source_file);
          $exit_code_shell = $shell_execution_process->run();

          if ($exit_code_shell !== 0) {
            // Throw an exception with details about the failed process.
            $this->output()
              ->writeln("\n=> The command failed to execute for the site $domain.");
            continue;
          }
        }

        // Remove the temporary decompressed dump
        if ($gzip) {
          $shell_execution_rm = Drush::shell('rm ' . $source_file);
          $exit_code_rm = $shell_execution_rm->run();

          if ($exit_code_rm !== 0) {
            // Throw an exception with details about the failed process.
            $this->output()
              ->writeln("\n=> The Shell rm command failed to execute for the site $domain.");
            continue;
          }
        }

        // Command Completed.
        $this->output()
          ->writeln("\n=> Dropping and restoring database on $domain Completed.");
      }
    }
  }
}
