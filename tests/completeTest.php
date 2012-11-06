<?php

/**
 * @group base
 */
class completeCase extends Drush_CommandTestCase {
  /**
   * Write a config file that contains our configuration file.
   */
  static function setUpBeforeClass() {
    parent::setUpBeforeClass();
    $contents = "
      <?php

      \$options['shell-aliases'] = array(
        'uninstall' => 'pm-uninstall',
      );
    ";
    file_put_contents(UNISH_SANDBOX . '/drushrc.php', trim($contents));
  }

  public function testComplete() {
    // We copy our completetest commandfile into our path.
    // We cannot use --include since complete deliberately avoids drush
    // command dispatch.
    copy(dirname(__FILE__) . '/completetest.drush.inc', UNISH_CACHE . "/completetest.drush.inc");

    $sites = $this->setUpDrupal(2);
    $env = key($sites);
    $root = $this->webroot();
    // We copy the unit test command into (only) our dev site, so we have a
    // detectable difference we can use to detect cache correctness between
    // sites.
    mkdir("$root/sites/$env/modules");
    copy(dirname(__FILE__) . '/completetestsite.drush.inc', "$root/sites/$env/modules/completetestsite.drush.inc");
    // Clear the cache, so it finds our test command.
    $this->drush('php-eval', array('drush_cache_clear_all();'), array(), '@' . $env);

    // Create a sample directory and file to test file/directory completion.
    mkdir("aardvark");
    touch('aard wolf.tar.gz');

    // Create directory for temporary debug logs.
    mkdir(UNISH_SANDBOX . '/complete-debug');

    // Test cache clearing for global cache, which should affect all
    // environments. First clear the cache:
    $this->drush('php-eval', array('drush_complete_cache_clear();'));
    // Confirm we get cache rebuilds for runs both in and out of a site
    // which is expected since these should resolve to separate cache IDs.
    $this->verifyComplete('@dev aaaaaaaard-', 'aaaaaaaard-ant', 'aaaaaaaard-zebra', FALSE);
    $this->verifyComplete('aaaaaaaard-', 'aaaaaaaard-ant', 'aaaaaaaard-wolf', FALSE);
    // Next, rerun and check results to confirm cache IDs are generated
    // correctly on our fast bootstrap when returning the cached result.
    $this->verifyComplete('@dev aaaaaaaard-', 'aaaaaaaard-ant', 'aaaaaaaard-zebra');
    $this->verifyComplete('aaaaaaaard-', 'aaaaaaaard-ant', 'aaaaaaaard-wolf');

    // Test cache clearing for a completion type, which should be effective only
    // for current environment - i.e. a specific site should not be effected.
    $this->drush('php-eval', array('drush_complete_cache_clear("command-names");'));
    $this->verifyComplete('@dev aaaaaaaard-', 'aaaaaaaard-ant', 'aaaaaaaard-zebra');
    $this->verifyComplete('aaaaaaaard-', 'aaaaaaaard-ant', 'aaaaaaaard-wolf', FALSE);

    // Test cache clearing for a command specific completion type, which should
    // be effective only for current environment. Prime caches first.
    $this->verifyComplete('@dev aaaaaaaard a', 'aardvark', 'aardwolf', FALSE);
    $this->verifyComplete('aaaaaaaard a', 'aardvark', 'aardwolf', FALSE);
    $this->drush('php-eval', array('drush_complete_cache_clear("arguments", "aaaaaaaard");'));
    // We cleared the global cache for this argument, not the site specific
    // cache should still exist.
    $this->verifyComplete('@dev aaaaaaaard a', 'aardvark', 'aardwolf');
    $this->verifyComplete('aaaaaaaard a', 'aardvark', 'aardwolf', FALSE);

    // Test overall context sensitivity - almost all of these are cache hits.
    // No context (i.e. "drush <tab>"), should list aliases and commands.
    $this->verifyComplete('""', '@dev', 'zzzzzzzzebra');
    // Site alias alone.
    $this->verifyComplete('@', '@dev', '@stage');
    // Command alone.
    $this->verifyComplete('aaaaaaaa', 'aaaaaaaard', 'aaaaaaaard-wolf');
    // Command with single result.
    $this->verifyComplete('aaaaaaaard-v', 'aaaaaaaard-vark', 'aaaaaaaard-vark');
    // Command with no results should produce no output.
    $this->verifyComplete('dont-name-a-command-like-this', '', '');
    // Commands that start the same as another command (i.e. aaaaaaaard is a
    // valid command, but we should still list aaaaaaaardwolf when completing on
    // "aaaaaaaard").
    $this->verifyComplete('@dev aaaaaaaard', 'aaaaaaaard', 'aaaaaaaard-zebra');
    // Global option alone.
    $this->verifyComplete('--n', '--no', '--nocolor');
    // Site alias + command.
    $this->verifyComplete('@dev aaaaaaaa', 'aaaaaaaard', 'aaaaaaaard-zebra');
    // Site alias + command, should allow no further site aliases or commands.
    $this->verifyComplete('@dev aaaaaaaard-wolf @', '', '', FALSE);
    $this->verifyComplete('@dev aaaaaaaard-wolf aaaaaaaa', '', '');
    // Command + command option.
    $this->verifyComplete('aaaaaaaard --', '--ears', '--nose');
    // Site alias + command + command option.
    $this->verifyComplete('@dev aaaaaaaard --', '--ears', '--nose');
    // Command + all arguments
    $this->verifyComplete('aaaaaaaard ""', 'aardvark', 'zebra');
    // Command + argument.
    $this->verifyComplete('aaaaaaaard a', 'aardvark', 'aardwolf');
    // Site alias + command + regular argument.
    // Note: this is checked implicitly by the argument cache testing above.

    if ($this->is_windows()) {
      $this->markTestSkipped('Complete tests not fully working nor needed on Windows.');
    }

    // Site alias + command + file/directory argument. This is a command
    // argument we have not used so far, so a cache miss is expected.
    $this->verifyComplete('archive-restore aard', 'aard wolf.tar.gz', 'aardvark/', FALSE);
    // Site alias + command + file/directory argument with quoting.
    $this->verifyComplete('archive-restore aard\ w', 'aard\ wolf.tar.gz', 'aard\ wolf.tar.gz');
  }

  /**
   * Helper function to call completion and make common checks.
   *
   * @param $command
   *   The command line to attempt to complete.
   * @param $first
   *   String indicating the expected first completion suggestion.
   * @param $last
   *   String indicating the expected last completion suggestion.
   * @param bool $cache_hit
   *   Optional parameter, if TRUE or omitted the debug log is checked to
   *   ensure a cache hit on the last cache debug log entry, if FALSE then a
   *   cache miss is checked for.
   */
  function verifyComplete($command, $first, $last, $cache_hit = TRUE) {
    // We capture debug output to a separate file, so we can check for cache
    // hits/misses.
    $debug_file = tempnam(UNISH_SANDBOX . '/complete-debug', 'complete-debug');
    // Commands should take the format:
    // drush --early=includes/complete.inc [--complete-debug] drush [@alias] [command]...
    $exec = sprintf('%s --early=includes/complete.inc --config=%s --complete-debug %s %s 2> %s', UNISH_DRUSH, UNISH_SANDBOX . '/drushrc.php', UNISH_DRUSH, $command, $debug_file);
    $this->execute($exec);
    $result = $this->getOutputAsList();
    $actual = reset($result);
    $this->assertEquals("$command: (f) $first", "$command: (f) $actual");
    $actual = end($result);
    $this->assertEquals("$command: (l) $last", "$command: (l) $actual");
    // If checking for HIT, we ensure no MISS exists, if checking for MISS we
    // ensure no HIT exists. However, we exclude the first cache report, since
    // it is expected that the command-names cache (loaded when matching
    // command names) may sometimes be a HIT even when we are testing for a MISS
    // in the actual cache we are loading to complete against.
    $check_not_exist = 'HIT';
    if ($cache_hit) {
      $check_not_exist = 'MISS';
    }
    $contents = file_get_contents($debug_file);
    $first_cache_pos = strpos($contents, 'Cache ') + 6;
    $this->assertFalse(strpos($contents, 'Cache ' . $check_not_exist . ' cid', $first_cache_pos));
    unlink($debug_file);
  }
}
