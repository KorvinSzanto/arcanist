<?php

/*
 * Copyright 2012 Facebook, Inc.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *   http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

/**
 * Sends changes from your working copy to Differential for code review.
 *
 * @task lintunit   Lint and Unit Tests
 * @task message    Commit and Update Messages
 * @task diffspec   Diff Specification
 * @task diffprop   Diff Properties
 *
 * @group workflow
 */
final class ArcanistDiffWorkflow extends ArcanistBaseWorkflow {

  private $hasWarnedExternals = false;
  private $unresolvedLint;
  private $lintExcuse;
  private $unitExcuse;
  private $testResults;
  private $diffID;
  private $revisionID;
  private $unitWorkflow;

  public function getCommandSynopses() {
    return phutil_console_format(<<<EOTEXT
      **diff** [__paths__] (svn)
      **diff** [__commit__] (git, hg)
EOTEXT
      );
  }

  public function getCommandHelp() {
    return phutil_console_format(<<<EOTEXT
          Supports: git, svn, hg
          Generate a Differential diff or revision from local changes.

          Under git, you can specify a commit (like __HEAD^^^__ or __master__)
          and Differential will generate a diff against the merge base of that
          commit and HEAD.

          Under svn, you can choose to include only some of the modified files
          in the working copy in the diff by specifying their paths. If you
          omit paths, all changes are included in the diff.
EOTEXT
      );
  }
  public function requiresWorkingCopy() {
    return !$this->isRawDiffSource();
  }

  public function requiresConduit() {
    return true;
  }

  public function requiresAuthentication() {
    return true;
  }

  public function requiresRepositoryAPI() {
    if (!$this->isRawDiffSource()) {
      return true;
    }

    if ($this->getArgument('use-commit-message')) {
      return true;
    }

    return false;
  }

  public function getDiffID() {
    return $this->diffID;
  }

  public function getArguments() {
    return array(
      'message' => array(
        'short'       => 'm',
        'param'       => 'message',
        'help' =>
          "When updating a revision, use the specified message instead of ".
          "prompting.",
      ),
      'message-file' => array(
        'short' => 'F',
        'param' => 'file',
        'paramtype' => 'file',
        'help' => 'When creating a revision, read revision information '.
                  'from this file.',
      ),
      'use-commit-message' => array(
        'supports' => array(
          'git',
          // TODO: Support mercurial.
        ),
        'short' => 'C',
        'param' => 'commit',
        'help' => 'Read revision information from a specific commit.',
        'conflicts' => array(
          'only'    => null,
          'preview' => null,
          'update'  => null,
        ),
      ),
      'edit' => array(
        'supports'    => array(
          'git',
        ),
        'nosupport'   => array(
          'svn' => 'Edit revisions via the web interface when using SVN.',
        ),
        'help' =>
          "When updating a revision under git, edit revision information ".
          "before updating.",
      ),
      'raw' => array(
        'help' =>
          "Read diff from stdin, not from the working copy. This disables ".
          "many Arcanist/Phabricator features which depend on having access ".
          "to the working copy.",
        'conflicts' => array(
          'less-context'        => null,
          'apply-patches'       => '--raw disables lint.',
          'never-apply-patches' => '--raw disables lint.',
          'advice'              => '--raw disables lint.',
          'lintall'             => '--raw disables lint.',

          'create'              => '--raw and --create both need stdin. '.
                                   'Use --raw-command.',
          'edit'                => '--raw and --edit both need stdin. '.
                                   'Use --raw-command.',
          'raw-command'         => null,
        ),
      ),
      'raw-command' => array(
        'param' => 'command',
        'help' =>
          "Generate diff by executing a specified command, not from the ".
          "working copy. This disables many Arcanist/Phabricator features ".
          "which depend on having access to the working copy.",
        'conflicts' => array(
          'less-context'        => null,
          'apply-patches'       => '--raw-command disables lint.',
          'never-apply-patches' => '--raw-command disables lint.',
          'advice'              => '--raw-command disables lint.',
          'lintall'             => '--raw-command disables lint.',
        ),
      ),
      'create' => array(
        'help' => "Always create a new revision.",
        'conflicts' => array(
          'edit'    => '--create can not be used with --edit.',
          'only'    => '--create can not be used with --only.',
          'preview' => '--create can not be used with --preview.',
          'update'  => '--create can not be used with --update.',
        ),
      ),
      'update' => array(
        'param' => 'revision_id',
        'help'  => "Always update a specific revision.",
      ),
      'nounit' => array(
        'help' =>
          "Do not run unit tests.",
      ),
      'nolint' => array(
        'help' =>
          "Do not run lint.",
        'conflicts' => array(
          'lintall'   => '--nolint suppresses lint.',
          'advice'    => '--nolint suppresses lint.',
          'apply-patches' => '--nolint suppresses lint.',
          'never-apply-patches' => '--nolint suppresses lint.',
        ),
      ),
      'only' => array(
        'help' =>
          "Only generate a diff, without running lint, unit tests, or other ".
          "auxiliary steps. See also --preview.",
        'conflicts' => array(
          'preview'   => null,
          'message'   => '--only does not affect revisions.',
          'edit'      => '--only does not affect revisions.',
          'lintall'   => '--only suppresses lint.',
          'advice'    => '--only suppresses lint.',
          'apply-patches' => '--only suppresses lint.',
          'never-apply-patches' => '--only suppresses lint.',
        ),
      ),
      'preview' => array(
        'supports'    => array(
          'git',
        ),
        'nosupport'   => array(
          'svn' => 'Revisions are never created directly when using SVN.',
        ),
        'help' =>
          "Instead of creating or updating a revision, only create a diff, ".
          "which you may later attach to a revision. This still runs lint ".
          "unit tests. See also --only.",
        'conflicts' => array(
          'only'      => null,
          'edit'      => '--preview does affect revisions.',
          'message'   => '--preview does not update any revision.',
        ),
      ),
      'encoding' => array(
        'param' => 'encoding',
        'help' =>
          "Attempt to convert non UTF-8 hunks into specified encoding.",
      ),
      'allow-untracked' => array(
        'help' =>
          "Skip checks for untracked files in the working copy.",
      ),
      'excuse' => array(
        'param' => 'excuse',
        'help' => 'Provide a prepared in advance excuse for any lints/tests'.
          ' shall they fail.',
      ),
      'less-context' => array(
        'help' =>
          "Normally, files are diffed with full context: the entire file is ".
          "sent to Differential so reviewers can 'show more' and see it. If ".
          "you are making changes to very large files with tens of thousands ".
          "of lines, this may not work well. With this flag, a diff will ".
          "be created that has only a few lines of context.",
      ),
      'lintall' => array(
        'help' =>
          "Raise all lint warnings, not just those on lines you changed.",
        'passthru' => array(
          'lint' => true,
        ),
      ),
      'advice' => array(
        'help' =>
          "Raise lint advice in addition to lint warnings and errors.",
        'passthru' => array(
          'lint' => true,
        ),
      ),
      'apply-patches' => array(
        'help' =>
          'Apply patches suggested by lint to the working copy without '.
          'prompting.',
        'conflicts' => array(
          'never-apply-patches' => true,
        ),
        'passthru' => array(
          'lint' => true,
        ),
      ),
      'never-apply-patches' => array(
        'help' => 'Never apply patches suggested by lint.',
        'conflicts' => array(
          'apply-patches' => true,
        ),
        'passthru' => array(
          'lint' => true,
        ),
      ),
      'json' => array(
        'help' =>
          'Emit machine-readable JSON. EXPERIMENTAL! Probably does not work!',
      ),
      'no-amend' => array(
        'help' => 'Never amend commits in the working copy.',
      ),
      'uncommitted' => array(
        'help' => 'Include uncommitted changes without prompting.',
        'supports' => array(
          'hg',
        ),
      ),
      '*' => 'paths',
    );
  }

  public function isRawDiffSource() {
    return $this->getArgument('raw') || $this->getArgument('raw-command');
  }

  public function run() {
    $this->runDiffSetupBasics();

    $paths = $this->generateAffectedPaths();

    // Do this before we start linting or running unit tests so we can detect
    // things like a missing test plan or invalid reviewers immediately.
    $commit_message = $this->buildCommitMessage();

    $lint_result = $this->runLint($paths);
    $unit_result = $this->runUnit($paths);

    $changes = $this->generateChanges();
    if (!$changes) {
      throw new ArcanistUsageException(
        "There are no changes to generate a diff from!");
    }

    $diff_spec = array(
      'changes'                   => mpull($changes, 'toDictionary'),
      'lintStatus'                => $this->getLintStatus($lint_result),
      'unitStatus'                => $this->getUnitStatus($unit_result),
    ) + $this->buildDiffSpecification();

    $conduit = $this->getConduit();
    $diff_info = $conduit->callMethodSynchronous(
      'differential.creatediff',
      $diff_spec);

    $this->diffID = $diff_info['diffid'];

    if ($this->unitWorkflow) {
      $this->unitWorkflow->setDifferentialDiffID($diff_info['diffid']);
    }

    $this->updateLintDiffProperty();
    $this->updateUnitDiffProperty();
    $this->updateLocalDiffProperty();

    $output_json = $this->getArgument('json');

    if ($this->shouldOnlyCreateDiff()) {
      if (!$output_json) {
        echo phutil_console_format(
          "Created a new Differential diff:\n".
          "        **Diff URI:** __%s__\n\n",
          $diff_info['uri']);
      } else {
        $human = ob_get_clean();
        echo json_encode(array(
          'diffURI' => $diff_info['uri'],
          'diffID'  => $this->getDiffID(),
          'human'   => $human,
        ))."\n";
        ob_start();
      }
    } else {

      $message = $commit_message;

      $revision = array(
        'diffid' => $this->getDiffID(),
        'fields' => $message->getFields(),
      );

      if ($message->getRevisionID()) {
        // TODO: This is silly -- we're getting a text corpus from the server
        // and then sending it right back to be parsed. This should be a
        // single call.
        $remote_corpus = $conduit->callMethodSynchronous(
          'differential.getcommitmessage',
          array(
            'revision_id' => $message->getRevisionID(),
            'edit' => true,
            'fields' => array(),
          ));

        $should_edit = $this->getArgument('edit');
        if ($should_edit) {
          $new_text = id(new PhutilInteractiveEditor($remote_corpus))
            ->setName('differential-edit-revision-info')
            ->editInteractively();
          $new_message = ArcanistDifferentialCommitMessage::newFromRawCorpus(
            $new_text);
        } else {
          $new_message = ArcanistDifferentialCommitMessage::newFromRawCorpus(
            $remote_corpus);
        }

        $new_message->pullDataFromConduit($conduit);
        $revision['fields'] = $new_message->getFields();

        $revision['id'] = $message->getRevisionID();
        $this->revisionID = $revision['id'];

        $update_message = $this->getUpdateMessage();

        $revision['message'] = $update_message;
        $future = $conduit->callMethod(
          'differential.updaterevision',
          $revision);
        $result = $future->resolve();
        echo "Updated an existing Differential revision:\n";
      } else {
        $revision['user'] = $this->getUserPHID();
        $future = $conduit->callMethod(
          'differential.createrevision',
          $revision);
        $result = $future->resolve();

        $revised_message = $conduit->callMethodSynchronous(
          'differential.getcommitmessage',
          array(
            'revision_id' => $result['revisionid'],
          ));

        if (!$this->isRawDiffSource()) {
          $repository_api = $this->getRepositoryAPI();
          if (($repository_api instanceof ArcanistGitAPI) &&
              $this->shouldAmend()) {
            echo "Updating commit message...\n";
            $repository_api->amendGitHeadCommit($revised_message);
          }
        }

        echo "Created a new Differential revision:\n";
      }

      $uri = $result['uri'];
      echo phutil_console_format(
        "        **Revision URI:** __%s__\n\n",
        $uri);
    }

    echo "Included changes:\n";
    foreach ($changes as $change) {
      echo '  '.$change->renderTextSummary()."\n";
    }

    if ($output_json) {
      ob_get_clean();
    }

    $this->removeScratchFile('create-message');

    return 0;
  }

  private function runDiffSetupBasics() {
    if ($this->requiresRepositoryAPI()) {
      $repository_api = $this->getRepositoryAPI();
      if ($this->getArgument('less-context')) {
        $repository_api->setDiffLinesOfContext(3);
      }

      if ($repository_api->supportsRelativeLocalCommits()) {

        // Parse the relative commit as soon as we can, to avoid generating
        // caches we need to drop later and expensive discovery operations
        // (particularly in Mercurial).

        $relative = $this->getArgument('paths');
        if ($relative) {
          $repository_api->parseRelativeLocalCommit($relative);
        }
      }
    }

    $output_json = $this->getArgument('json');
    if ($output_json) {
      // TODO: We should move this to a higher-level and put an indirection
      // layer between echoing stuff and stdout.
      ob_start();
    }

    if ($this->requiresWorkingCopy()) {
      try {
        $this->requireCleanWorkingCopy();
      } catch (ArcanistUncommittedChangesException $ex) {
        if ($repository_api instanceof ArcanistMercurialAPI) {

          // Some Mercurial users prefer to use it like SVN, where they don't
          // commit changes before sending them for review. This would be a
          // pretty bad workflow in Git, but Mercurial users are significantly
          // more expert at change management.

          $use_dirty_changes = false;
          if ($this->getArgument('uncommitted')) {
            // OK.
          } else {
            $ok = phutil_console_confirm(
              "You have uncommitted changes in your working copy. You can ".
              "include them in the diff, or abort and deal with them. (Use ".
              "'--uncommitted' to include them and skip this prompt.) ".
              "Do you want to include uncommitted changes in the diff?");
            if (!$ok) {
              throw $ex;
            }
          }

          $repository_api->setIncludeDirectoryStateInDiffs(true);
        }
      }
    }
  }

  protected function shouldOnlyCreateDiff() {

    if ($this->getArgument('create')) {
      return false;
    }

    if ($this->getArgument('update')) {
      return false;
    }

    if ($this->getArgument('use-commit-message')) {
      return false;
    }

    if ($this->isRawDiffSource()) {
      return true;
    }

    $repository_api = $this->getRepositoryAPI();
    if ($repository_api instanceof ArcanistSubversionAPI) {
      return true;
    }

    if ($repository_api instanceof ArcanistMercurialAPI) {
      return true;
    }

    if ($this->isHistoryImmutable()) {
      return true;
    }

    return $this->getArgument('preview') ||
           $this->getArgument('only');
  }

  private function generateAffectedPaths() {
    if ($this->isRawDiffSource()) {
      return array();
    }

    $repository_api = $this->getRepositoryAPI();
    if ($repository_api instanceof ArcanistSubversionAPI) {
      $file_list = new FileList($this->getArgument('paths', array()));
      $paths = $repository_api->getSVNStatus($externals = true);
      foreach ($paths as $path => $mask) {
        if (!$file_list->contains($repository_api->getPath($path), true)) {
          unset($paths[$path]);
        }
      }

      $warn_externals = array();
      foreach ($paths as $path => $mask) {
        $any_mod = ($mask & ArcanistRepositoryAPI::FLAG_ADDED) ||
                   ($mask & ArcanistRepositoryAPI::FLAG_MODIFIED) ||
                   ($mask & ArcanistRepositoryAPI::FLAG_DELETED);
        if ($mask & ArcanistRepositoryAPI::FLAG_EXTERNALS) {
          unset($paths[$path]);
          if ($any_mod) {
            $warn_externals[] = $path;
          }
        }
      }

      if ($warn_externals && !$this->hasWarnedExternals) {
        echo phutil_console_format(
          "The working copy includes changes to 'svn:externals' paths. These ".
          "changes will not be included in the diff because SVN can not ".
          "commit 'svn:externals' changes alongside normal changes.".
          "\n\n".
          "Modified 'svn:externals' files:".
          "\n\n".
          phutil_console_wrap(implode("\n", $warn_externals), 8));
        $prompt = "Generate a diff (with just local changes) anyway?";
        if (!phutil_console_confirm($prompt)) {
          throw new ArcanistUserAbortException();
        } else {
          $this->hasWarnedExternals = true;
        }
      }

    } else if ($repository_api->supportsRelativeLocalCommits()) {
      $paths = $repository_api->getWorkingCopyStatus();
    } else {
      throw new Exception("Unknown VCS!");
    }

    foreach ($paths as $path => $mask) {
      if ($mask & ArcanistRepositoryAPI::FLAG_UNTRACKED) {
        unset($paths[$path]);
      }
    }

    return $paths;
  }


  protected function generateChanges() {
    $parser = new ArcanistDiffParser();

    $is_raw = $this->isRawDiffSource();
    if ($is_raw) {

      if ($this->getArgument('raw')) {
        file_put_contents('php://stderr', "Reading diff from stdin...\n");
        $raw_diff = file_get_contents('php://stdin');
      } else if ($this->getArgument('raw-command')) {
        list($raw_diff) = execx($this->getArgument('raw-command'));
      } else {
        throw new Exception("Unknown raw diff source.");
      }

      $changes = $parser->parseDiff($raw_diff);
      foreach ($changes as $key => $change) {
        // Remove "message" changes, e.g. from "git show".
        if ($change->getType() == ArcanistDiffChangeType::TYPE_MESSAGE) {
          unset($changes[$key]);
        }
      }
      return $changes;
    }

    $repository_api = $this->getRepositoryAPI();

    if ($repository_api instanceof ArcanistSubversionAPI) {
      $paths = $this->generateAffectedPaths();
      $this->primeSubversionWorkingCopyData($paths);

      // Check to make sure the user is diffing from a consistent base revision.
      // This is mostly just an abuse sanity check because it's silly to do this
      // and makes the code more difficult to effectively review, but it also
      // affects patches and makes them nonportable.
      $bases = $repository_api->getSVNBaseRevisions();

      // Remove all files with baserev "0"; these files are new.
      foreach ($bases as $path => $baserev) {
        if ($bases[$path] <= 0) {
          unset($bases[$path]);
        }
      }

      if ($bases) {
        $rev = reset($bases);

        $revlist = array();
        foreach ($bases as $path => $baserev) {
          $revlist[] = "    Revision {$baserev}, {$path}";
        }
        $revlist = implode("\n", $revlist);

        foreach ($bases as $path => $baserev) {
          if ($baserev !== $rev) {
            throw new ArcanistUsageException(
              "Base revisions of changed paths are mismatched. Update all ".
              "paths to the same base revision before creating a diff: ".
              "\n\n".
              $revlist);
          }
        }

        // If you have a change which affects several files, all of which are
        // at a consistent base revision, treat that revision as the effective
        // base revision. The use case here is that you made a change to some
        // file, which updates it to HEAD, but want to be able to change it
        // again without updating the entire working copy. This is a little
        // sketchy but it arises in Facebook Ops workflows with config files and
        // doesn't have any real material tradeoffs (e.g., these patches are
        // perfectly applyable).
        $repository_api->overrideSVNBaseRevisionNumber($rev);
      }

      $changes = $parser->parseSubversionDiff(
        $repository_api,
        $paths);
    } else if ($repository_api instanceof ArcanistGitAPI) {
      $diff = $repository_api->getFullGitDiff();
      if (!strlen($diff)) {
        throw new ArcanistUsageException(
          "No changes found. (Did you specify the wrong commit range?)");
      }
      $changes = $parser->parseDiff($diff);
    } else if ($repository_api instanceof ArcanistMercurialAPI) {
      $diff = $repository_api->getFullMercurialDiff();
      if (!strlen($diff)) {
        throw new ArcanistUsageException(
          "No changes found. (Did you specify the wrong commit range?)");
      }
      $changes = $parser->parseDiff($diff);
    } else {
      throw new Exception("Repository API is not supported.");
    }

    if (count($changes) > 250) {
      $count = number_format(count($changes));
      $message =
        "This diff has a very large number of changes ({$count}). ".
        "Differential works best for changes which will receive detailed ".
        "human review, and not as well for large automated changes or ".
        "bulk checkins. Continue anyway?";
      if (!phutil_console_confirm($message)) {
        throw new ArcanistUsageException(
          "Aborted generation of gigantic diff.");
      }
    }

    $limit = 1024 * 1024 * 4;
    foreach ($changes as $change) {
      $size = 0;
      foreach ($change->getHunks() as $hunk) {
        $size += strlen($hunk->getCorpus());
      }
      if ($size > $limit) {
        $file_name = $change->getCurrentPath();
        $change_size = number_format($size);
        $byte_warning =
          "Diff for '{$file_name}' with context is {$change_size} bytes in ".
          "length. Generally, source changes should not be this large. If ".
          "this file is a huge text file, try using the '--less-context' flag.";
        if ($repository_api instanceof ArcanistSubversionAPI) {
          throw new ArcanistUsageException(
            "{$byte_warning} If the file is not a text file, mark it as ".
            "binary with:".
            "\n\n".
            "  $ svn propset svn:mime-type application/octet-stream <filename>".
            "\n");
        } else {
          $confirm =
            "{$byte_warning} If the file is not a text file, you can ".
            "mark it 'binary'. Mark this file as 'binary' and continue?";
          if (phutil_console_confirm($confirm)) {
            $change->convertToBinaryChange();
          } else {
            throw new ArcanistUsageException(
              "Aborted generation of gigantic diff.");
          }
        }
      }
    }

    $try_encoding = nonempty($this->getArgument('encoding'), null);

    $utf8_problems = array();
    foreach ($changes as $change) {
      foreach ($change->getHunks() as $hunk) {
        $corpus = $hunk->getCorpus();
        if (!phutil_is_utf8($corpus)) {

          // If this corpus is heuristically binary, don't try to convert it.
          // mb_check_encoding() and mb_convert_encoding() are both very very
          // liberal about what they're willing to process.
          $is_binary = ArcanistDiffUtils::isHeuristicBinaryFile($corpus);
          if (!$is_binary) {

            if (!$try_encoding) {
              try {
                $try_encoding = $this->getRepositoryEncoding();
              } catch (ConduitClientException $e) {
                if ($e->getErrorCode() == 'ERR-BAD-ARCANIST-PROJECT') {
                  echo phutil_console_wrap(
                    "Lookup of encoding in arcanist project failed\n".
                    $e->getMessage());
                } else {
                  throw $e;
                }
              }
            }

            if ($try_encoding) {
              // NOTE: This feature is HIGHLY EXPERIMENTAL and will cause a lot
              // of issues. Use it at your own risk.
              $corpus = mb_convert_encoding($corpus, 'UTF-8', $try_encoding);
              $name = $change->getCurrentPath();
              if (phutil_is_utf8($corpus)) {
                $this->writeStatusMessage(
                  "[Experimental] Converted a '{$name}' hunk from ".
                  "'{$try_encoding}' to UTF-8.\n");
                $hunk->setCorpus($corpus);
                continue;
              }
            }
          }
          $utf8_problems[] = $change;
          break;
        }
      }
    }

    // If there are non-binary files which aren't valid UTF-8, warn the user
    // and treat them as binary changes. See D327 for discussion of why Arcanist
    // has this behavior.
    if ($utf8_problems) {
      $learn_more =
        "You can learn more about how Phabricator handles character encodings ".
        "(and how to configure encoding settings and detect and correct ".
        "encoding problems) by reading 'User Guide: UTF-8 and Character ".
        "Encoding' in the Phabricator documentation.\n\n";
      if (count($utf8_problems) == 1) {
        $utf8_warning =
          "This diff includes a file which is not valid UTF-8 (it has invalid ".
          "byte sequences). You can either stop this workflow and fix it, or ".
          "continue. If you continue, this file will be marked as binary.\n\n".
          $learn_more.
          "    AFFECTED FILE\n";

        $confirm = "Do you want to mark this file as binary and continue?";
      } else {
        $utf8_warning =
          "This diff includes files which are not valid UTF-8 (they contain ".
          "invalid byte sequences). You can either stop this workflow and fix ".
          "these files, or continue. If you continue, these files will be ".
          "marked as binary.\n\n".
          $learn_more.
          "    AFFECTED FILES\n";

        $confirm = "Do you want to mark these files as binary and continue?";
      }

      echo phutil_console_format("**Invalid Content Encoding (Non-UTF8)**\n");
      echo phutil_console_wrap($utf8_warning);

      $file_list = mpull($utf8_problems, 'getCurrentPath');
      $file_list = '    '.implode("\n    ", $file_list);
      echo $file_list;

      if (!phutil_console_confirm($confirm, $default_no = false)) {
        throw new ArcanistUsageException("Aborted workflow to fix UTF-8.");
      } else {
        foreach ($utf8_problems as $change) {
          $change->convertToBinaryChange();
        }
      }
    }

    foreach ($changes as $change) {
      if ($change->getFileType() != ArcanistDiffChangeType::FILE_BINARY) {
        continue;
      }

      $path = $change->getCurrentPath();
      $name = basename($path);

      $old_file = $repository_api->getOriginalFileData($path);
      $old_dict = $this->uploadFile($old_file, $name, 'old binary');
      if ($old_dict['guid']) {
        $change->setMetadata('old:binary-phid', $old_dict['guid']);
      }
      $change->setMetadata('old:file:size',      $old_dict['size']);
      $change->setMetadata('old:file:mime-type', $old_dict['mime']);

      $new_file = $repository_api->getCurrentFileData($path);
      $new_dict = $this->uploadFile($new_file, $name, 'new binary');
      if ($new_dict['guid']) {
        $change->setMetadata('new:binary-phid', $new_dict['guid']);
      }
      $change->setMetadata('new:file:size',      $new_dict['size']);
      $change->setMetadata('new:file:mime-type', $new_dict['mime']);

      if (preg_match('@^image/@', $new_dict['mime'])) {
        $change->setFileType(ArcanistDiffChangeType::FILE_IMAGE);
      }
    }

    return $changes;
  }

  private function uploadFile($data, $name, $desc) {
    $result = array(
      'guid' => null,
      'mime' => null,
      'size' => null
    );

    $result['size'] = $size = strlen($data);
    if (!$size) {
      return $result;
    }

    $future = new ExecFuture('file -b --mime -');
    $future->write($data);
    list($mime_type) = $future->resolvex();

    $mime_type = trim($mime_type);
    $result['mime'] = $mime_type;

    echo "Uploading {$desc} '{$name}' ({$mime_type}, {$size} bytes)...\n";

    try {
      $guid = $this->getConduit()->callMethodSynchronous(
        'file.upload',
        array(
          'data_base64' => base64_encode($data),
          'name'        => $name,
      ));

      $result['guid'] = $guid;
    } catch (ConduitClientException $e) {
      $message = "Failed to upload {$desc} '{$name}'.  Continue?";
      if (!phutil_console_confirm($message, $default_no = false)) {
        throw new ArcanistUsageException(
          'Aborted due to file upload failure.'
        );
      }
    }
    return $result;
  }

  private function getGitParentLogInfo() {
    $info = array(
      'parent'        => null,
      'base_revision' => null,
      'base_path'     => null,
      'uuid'          => null,
    );

    $conduit = $this->getConduit();
    $repository_api = $this->getRepositoryAPI();

    $parser = new ArcanistDiffParser();
    $history_messages = $repository_api->getGitHistoryLog();
    if (!$history_messages) {
      // This can occur on the initial commit.
      return $info;
    }
    $history_messages = $parser->parseDiff($history_messages);

    foreach ($history_messages as $key => $change) {
      try {
        $message = ArcanistDifferentialCommitMessage::newFromRawCorpus(
          $change->getMetadata('message'));
        if ($message->getRevisionID() && $info['parent'] === null) {
          $info['parent'] = $message->getRevisionID();
        }
        if ($message->getGitSVNBaseRevision() &&
            $info['base_revision'] === null) {
          $info['base_revision'] = $message->getGitSVNBaseRevision();
          $info['base_path']     = $message->getGitSVNBasePath();
        }
        if ($message->getGitSVNUUID()) {
          $info['uuid'] = $message->getGitSVNUUID();
        }
        if ($info['parent'] && $info['base_revision']) {
          break;
        }
      } catch (ArcanistDifferentialCommitMessageParserException $ex) {
        // Ignore.
      }
    }

    return $info;
  }

  protected function primeSubversionWorkingCopyData($paths) {
    $repository_api = $this->getRepositoryAPI();

    $futures = array();
    $targets = array();
    foreach ($paths as $path => $mask) {
      $futures[] = $repository_api->buildDiffFuture($path);
      $targets[] = array('command' => 'diff', 'path' => $path);
      $futures[] = $repository_api->buildInfoFuture($path);
      $targets[] = array('command' => 'info', 'path' => $path);
    }

    foreach ($futures as $key => $future) {
      $target = $targets[$key];
      if ($target['command'] == 'diff') {
        $repository_api->primeSVNDiffResult(
          $target['path'],
          $future->resolve());
      } else {
        $repository_api->primeSVNInfoResult(
          $target['path'],
          $future->resolve());
      }
    }
  }

  private function shouldAmend() {
    return !$this->isHistoryImmutable() && !$this->getArgument('no-amend');
  }


/* -(  Lint and Unit Tests  )------------------------------------------------ */


  /**
   * @task lintunit
   */
  private function runLint($paths) {
    if ($this->getArgument('nolint') ||
        $this->getArgument('only') ||
        $this->isRawDiffSource()) {
      return ArcanistLintWorkflow::RESULT_SKIP;
    }

    $repository_api = $this->getRepositoryAPI();

    echo "Linting...\n";
    try {
      $argv = $this->getPassthruArgumentsAsArgv('lint');
      if ($repository_api->supportsRelativeLocalCommits()) {
        $argv[] = '--rev';
        $argv[] = $repository_api->getRelativeCommit();
      }
      $lint_workflow = $this->buildChildWorkflow('lint', $argv);

      if ($this->shouldAmend()) {
        // TODO: We should offer to create a checkpoint commit.
        $lint_workflow->setShouldAmendChanges(true);
      }

      $lint_result = $lint_workflow->run();

      $continue = false;
      switch ($lint_result) {
        case ArcanistLintWorkflow::RESULT_OKAY:
          echo phutil_console_format(
            "<bg:green>** LINT OKAY **</bg> No lint problems.\n");
          break;
        case ArcanistLintWorkflow::RESULT_WARNINGS:
          $msg = "Lint issued unresolved warnings. ";
          $msg .= $this->getArgument('excuse')
            ? "Ignore them?"
            : "Provide explanation and continue?";
          $continue = phutil_console_confirm($msg);
          if (!$continue) {
            throw new ArcanistUserAbortException();
          }
          break;
        case ArcanistLintWorkflow::RESULT_ERRORS:
          echo phutil_console_format(
            "<bg:red>** LINT ERRORS **</bg> Lint raised errors!\n");
          $msg = "Lint issued unresolved errors! ";
          $msg .= $this->getArgument('excuse')
            ? "Ignore lint errors?"
            : "Provide explanation and continue?";
          $continue = phutil_console_confirm($msg);
          if (!$continue) {
            throw new ArcanistUserAbortException();
          }
          break;
      }

      $this->unresolvedLint = $lint_workflow->getUnresolvedMessages();
      if ($continue) {
        if ($this->getArgument('excuse')) {
          $this->unitExcuse = $this->getArgument('excuse');
        } else {
          $template = "\n\n# Provide an explanation for these lint failures:\n";
          foreach ($this->unresolvedLint as $message) {
            $template = $template."# ".
              $message->getPath().":".
              $message->getLine()." ".
              $message->getCode()." :: ".
              $message->getDescription()."\n";
          }
          $this->lintExcuse = $this->getErrorExcuse($template);
        }
      }

      return $lint_result;
    } catch (ArcanistNoEngineException $ex) {
      echo "No lint engine configured for this project.\n";
    } catch (ArcanistNoEffectException $ex) {
      echo "No paths to lint.\n";
    }

    return null;
  }


  /**
   * @task lintunit
   */
  private function runUnit($paths) {
    if ($this->getArgument('nounit') ||
        $this->getArgument('only') ||
        $this->isRawDiffSource()) {
      return ArcanistUnitWorkflow::RESULT_SKIP;
    }

    $repository_api = $this->getRepositoryAPI();

    echo "Running unit tests...\n";
    try {
      $argv = $this->getPassthruArgumentsAsArgv('unit');
      if ($repository_api->supportsRelativeLocalCommits()) {
        $argv[] = '--rev';
        $argv[] = $repository_api->getRelativeCommit();
      }
      $this->unitWorkflow = $this->buildChildWorkflow('unit', $argv);
      $unit_result = $this->unitWorkflow->run();
      $explain = false;
      switch ($unit_result) {
        case ArcanistUnitWorkflow::RESULT_OKAY:
          echo phutil_console_format(
            "<bg:green>** UNIT OKAY **</bg> No unit test failures.\n");
          break;
        case ArcanistUnitWorkflow::RESULT_UNSOUND:
          $continue = phutil_console_confirm(
            "Unit test results included failures, but all failing tests ".
            "are known to be unsound. Ignore unsound test failures?");
          if (!$continue) {
            throw new ArcanistUserAbortException();
          }
          break;
        case ArcanistUnitWorkflow::RESULT_FAIL:
          echo phutil_console_format(
            "<bg:red>** UNIT ERRORS **</bg> Unit testing raised errors!\n");
          $msg = "Unit test results include failures! ";
          $msg .= $this->getArgument('excuse')
            ? "Ignore test failures?"
            : "Explain test failures and continue?";
          $continue = phutil_console_confirm($msg);
          if (!$continue) {
            throw new ArcanistUserAbortException();
          }
          $explain = true;
          break;
      }

      $this->testResults = $this->unitWorkflow->getTestResults();
      if ($explain) {
        if ($this->getArgument('excuse')) {
          $this->unitExcuse = $this->getArgument('excuse');
        } else {
          $template = "\n\n".
            "# Provide an explanation for these unit test failures:\n";
          foreach ($this->testResults as $test) {
            $testResult = $test->getResult();
            switch ($testResult) {
              case ArcanistUnitTestResult::RESULT_FAIL:
              case ArcanistUnitTestResult::RESULT_BROKEN:
                $template = $template."# ".
                  $test->getName()." :: ".
                  $test->getResult()."\n";
                break;
              default:
                break;
            }
          }
          $this->unitExcuse = $this->getErrorExcuse($template);
        }
      }

      return $unit_result;
    } catch (ArcanistNoEngineException $ex) {
      echo "No unit test engine is configured for this project.\n";
    } catch (ArcanistNoEffectException $ex) {
      echo "No tests to run.\n";
    }

    return null;
  }

  private function getErrorExcuse($template) {
    $new_template = id(new PhutilInteractiveEditor($template))
      ->setName('error-excuse')
      ->editInteractively();

    if ($new_template == $template) {
      throw new ArcanistUsageException(
        "No explanation provided.");
    }

    $template = preg_replace('/^\s*#.*$/m', '', $new_template);
    $template = rtrim($template)."\n";

    return $template;
  }


/* -(  Commit and Update Messages  )----------------------------------------- */


  /**
   * @task message
   */
  private function buildCommitMessage() {
    $is_create = $this->getArgument('create');
    $is_update = $this->getArgument('update');
    $is_raw = $this->isRawDiffSource();
    $is_message = $this->getArgument('use-commit-message');

    if ($is_message) {
      return $this->getCommitMessageFromCommit($is_message);
    }

    if (!$is_raw && !$is_create && !$is_update) {
      $repository_api = $this->getRepositoryAPI();
      $revisions = $repository_api->loadWorkingCopyDifferentialRevisions(
        $this->getConduit(),
        array(
          'authors' => array($this->getUserPHID()),
          'status'  => 'status-open',
        ));
      if (!$revisions) {
        $is_create = true;
      } else if (count($revisions) == 1) {
        $revision = head($revisions);
        $is_update = $revision['id'];
      } else {
        throw new ArcanistUsageException(
          "There are several revisions in the specified commit range:\n\n".
          $this->renderRevisionList($revisions)."\n".
          "Use '--update' to choose one, or '--create' to create a new ".
          "revision.");
      }
    }

    $message = null;
    if ($is_create) {
      $message_file = $this->getArgument('message-file');
      if ($message_file) {
        return $this->getCommitMessageFromFile($message_file);
      } else {
        return $this->getCommitMessageFromUser();
      }
    } else if ($is_update) {
      return $this->getCommitMessageFromRevision($is_update);
    } else {
      // This is --raw without enough info to create a revision, so force just
      // a diff.
      return null;
    }
  }


  /**
   * @task message
   */
  private function getCommitMessageFromCommit($rev) {
    $change = $this->getRepositoryAPI()->getCommitMessageForRevision($rev);
    $message = ArcanistDifferentialCommitMessage::newFromRawCorpus(
      $change->getMetadata('message'));
    $message->pullDataFromConduit($this->getConduit());
    $this->validateCommitMessage($message);
    return $message;
  }


  /**
   * @task message
   */
  private function getCommitMessageFromUser() {
    $conduit = $this->getConduit();

    $template = null;

    $saved = $this->readScratchFile('create-message');
    if ($saved) {
      $where = $this->getReadableScratchFilePath('create-message');

      $preview = explode("\n", $saved);
      $preview = array_shift($preview);
      $preview = trim($preview);
      $preview = phutil_utf8_shorten($preview, 64);

      if ($preview) {
        $preview = "Message begins:\n\n       {$preview}\n\n";
      } else {
        $preview = null;
      }

      echo
        "You have a saved revision message in '{$where}'.\n".
        "{$preview}".
        "You can use this message, or discard it.";

      $use = phutil_console_confirm(
        "Do you want to use this message?",
        $default_no = false);
      if ($use) {
        $template = $saved;
      } else {
        $this->removeScratchFile('create-message');
      }
    }

    $template_is_default = false;
    $notes = array();

    if (!$template) {
      list($fields, $notes) = $this->getDefaultCreateFields();
      if (!$fields) {
        $template_is_default = true;
      }

      $template = $conduit->callMethodSynchronous(
        'differential.getcommitmessage',
        array(
          'revision_id' => null,
          'edit'        => 'create',
          'fields'      => $fields,
        ));
    }

    $issues = array(
      'NEW DIFFERENTIAL REVISION',
      'Describe the changes in this new revision.',
      '',
      'arc could not identify any existing revision in your working copy.',
      'If you intended to update an existing revision, use:',
      '',
      '  $ arc diff --update <revision>',
    );
    if ($notes) {
      $issues = array_merge($issues, array(''), $notes);
    }

    $done = false;
    while (!$done) {
      $template = rtrim($template)."\n\n";
      foreach ($issues as $issue) {
        $template .= '# '.$issue."\n";
      }
      $template .= "\n";

      $new_template = id(new PhutilInteractiveEditor($template))
        ->setName('new-commit')
        ->editInteractively();

      if ($template_is_default && ($new_template == $template)) {
        throw new ArcanistUsageException(
          "Template not edited.");
      }

      $template = preg_replace('/^\s*#.*$/m', '', $new_template);
      $template = rtrim($template)."\n";
      $wrote = $this->writeScratchFile('create-message', $template);
      $where = $this->getReadableScratchFilePath('create-message');

      try {
        $message = ArcanistDifferentialCommitMessage::newFromRawCorpus(
          $template);
        $message->pullDataFromConduit($conduit);
        $this->validateCommitMessage($message);
        $done = true;
      } catch (ArcanistDifferentialCommitMessageParserException $ex) {
        echo "Commit message has errors:\n\n";
        $issues = array('Resolve these errors:');
        foreach ($ex->getParserErrors() as $error) {
          echo "      - ".$error."\n";
          $issues[] = '  - '.$error;
        }
        echo "\n";
        echo "You must resolve these errors to continue.";
        $again = phutil_console_confirm(
          "Do you want to edit the message?",
          $default_no = false);
        if ($again) {
          // Keep going.
        } else {
          $saved = null;
          if ($wrote) {
            $saved = "A copy was saved to '{$where}'.";
          }
          throw new ArcanistUsageException(
            "Message has unresolved errrors. {$saved}");
        }
      } catch (Exception $ex) {
        if ($wrote) {
          echo phutil_console_wrap("(Commit messaged saved to '{$where}'.)\n");
        }
        throw $ex;
      }
    }

    return $message;
  }


  /**
   * @task message
   */
  private function getCommitMessageFromFile($file) {
    $conduit = $this->getConduit();

    $data = Filesystem::readFile($file);
    $message = ArcanistDifferentialCommitMessage::newFromRawCorpus($data);
    $message->pullDataFromConduit($conduit);

    $this->validateCommitMessage($message);

    return $message;
  }


  /**
   * @task message
   */
  private function getCommitMessageFromRevision($revision_id) {
    $id = $this->normalizeRevisionID($revision_id);

    $revision = $this->getConduit()->callMethodSynchronous(
      'differential.query',
      array(
        'ids' => array($id),
      ));
    $revision = head($revision);

    if (!$revision) {
      throw new ArcanistUsageException(
        "Revision '{$revision_id}' does not exist!");
    }

    if ($revision['authorPHID'] != $this->getUserPHID()) {
      $rev_title = $revision['title'];
      throw new ArcanistUsageException(
        "You don't own revision D{$id} '{$rev_title}'. You can only update ".
        "revisions you own.");
    }

    $message = $this->getConduit()->callMethodSynchronous(
      'differential.getcommitmessage',
      array(
        'revision_id' => $id,
        'edit'        => false,
      ));

    $obj = ArcanistDifferentialCommitMessage::newFromRawCorpus($message);
    $obj->pullDataFromConduit($this->getConduit());

    return $obj;
  }


  /**
   * @task message
   */
  private function validateCommitMessage(
    ArcanistDifferentialCommitMessage $message) {
    $reviewers = $message->getFieldValue('reviewerPHIDs');
    if (!$reviewers) {
      $confirm = "You have not specified any reviewers. Continue anyway?";
      if (!phutil_console_confirm($confirm)) {
        throw new ArcanistUsageException('Specify reviewers and retry.');
      }
    } else if (in_array($this->getUserPHID(), $reviewers)) {
      throw new ArcanistUsageException(
        "You can not be a reviewer for your own revision.");
    }
  }


  /**
   * @task message
   */
  private function getUpdateMessage() {
    $comments = $this->getArgument('message');
    if (strlen($comments)) {
      return $comments;
    }

    if ($this->getArgument('raw')) {
      throw new ArcanistUsageException(
        "When using '--raw' to update a revision, specify an update message ".
        "with '--message'. (Normally, we'd launch an editor to ask you for a ".
        "message, but can not do that because stdin is the diff source.)");
    }

    // When updating a revision using git without specifying '--message', try
    // to prefill with the message in HEAD if it isn't a template message. The
    // idea is that if you do:
    //
    //  $ git commit -a -m 'fix some junk'
    //  $ arc diff
    //
    // ...you shouldn't have to retype the update message. Similar things apply
    // to Mercurial.

    $comments = $this->getDefaultUpdateMessage();

    $template =
      rtrim($comments).
      "\n\n".
      "# Enter a brief description of the changes included in this update.".
      "\n";

    $comments = id(new PhutilInteractiveEditor($template))
      ->setName('differential-update-comments')
      ->editInteractively();

    $comments = preg_replace('/^\s*#.*$/m', '', $comments);
    $comments = rtrim($comments);

    if (!strlen($comments)) {
      throw new ArcanistUserAbortException();
    }

    return $comments;
  }

  private function getLocalGitCommitMessages() {
    $repository_api = $this->getRepositoryAPI();
    $parser = new ArcanistDiffParser();
    $commit_messages = $repository_api->getGitCommitLog();

    if (!strlen($commit_messages)) {
      if (!$repository_api->getHasCommits()) {
        throw new ArcanistUsageException(
          "This git repository doesn't have any commits yet. You need to ".
          "commit something before you can diff against it.");
      } else {
        throw new ArcanistUsageException(
          "The commit range doesn't include any commits. (Did you diff ".
          "against the wrong commit?)");
      }
    }

    return $parser->parseDiff($commit_messages);
  }

  private function getDefaultCreateFields() {
    $empty = array(array(), array());

    if ($this->isRawDiffSource()) {
      return $empty;
    }

    $repository_api = $this->getRepositoryAPI();
    if ($repository_api instanceof ArcanistGitAPI) {
      return $this->getGitCreateFields();
    }

    // TODO: Load default fields in Mercurial.

    return $empty;
  }

  private function getGitCreateFields() {
    $conduit = $this->getConduit();
    $changes = $this->getLocalGitCommitMessages();

    $commits = array();
    foreach ($changes as $key => $change) {
      $commits[$change->getCommitHash()] = $change->getMetadata('message');
    }

    $messages = array();
    foreach ($commits as $hash => $text) {
      $messages[$hash] = ArcanistDifferentialCommitMessage::newFromRawCorpus(
        $text);
    }

    $fields = array();
    $notes = array();
    foreach ($messages as $hash => $message) {
      try {
        $message->pullDataFromConduit($conduit, $partial = true);
        $fields += $message->getFields();
      } catch (ArcanistDifferentialCommitMessageParserException $ex) {
        $fields += $message->getFields();

        $frev = substr($hash, 0, 8);
        $notes[] = "NOTE: commit {$frev} could not be completely parsed:";
        foreach ($ex->getParserErrors() as $error) {
          $notes[] = "  - {$error}";
        }
      }
    }

    return array($fields, $notes);
  }

  private function getDefaultUpdateMessage() {
    if ($this->isRawDiffSource()) {
      return null;
    }

    $repository_api = $this->getRepositoryAPI();
    if ($repository_api instanceof ArcanistGitAPI) {
      return $this->getGitUpdateMessage();
    }

    if ($repository_api instanceof ArcanistMercurialAPI) {
      return $this->getMercurialUpdateMessage();
    }

    return null;
  }

  /**
   * Retrieve the git messages between HEAD and the last update.
   *
   * @task message
   */
  private function getGitUpdateMessage() {
    $repository_api = $this->getRepositoryAPI();

    $parser = new ArcanistDiffParser();
    $commit_messages = $repository_api->getGitCommitLog();
    $commit_messages = $parser->parseDiff($commit_messages);

    if (count($commit_messages) == 1) {
      // If there's only one message, assume this is an amend-based workflow and
      // that using it to prefill doesn't make sense.
      return null;
    }

    // We have more than one message, so figure out which ones are new. We
    // do this by pulling the current diff and comparing commit hashes in the
    // working copy with attached commit hashes. It's not super important that
    // we always get this 100% right, we're just trying to do something
    // reasonable.

    $local = $this->loadActiveLocalCommitInfo();
    $hashes = ipull($local, null, 'commit');

    $usable = array();
    foreach ($commit_messages as $message) {
      $text = $message->getMetadata('message');

      $parsed = ArcanistDifferentialCommitMessage::newFromRawCorpus($text);
      if ($parsed->getRevisionID()) {
        // If this is an amended commit message with a revision ID, it's
        // certainly not new. Stop marking commits as usable and break out.
        break;
      }

      if (isset($hashes[$message->getCommitHash()])) {
        // If this commit is currently part of the diff, stop using commit
        // messages, since anything older than this isn't new.
        break;
      }

      // Otherwise, this looks new, so it's a usable commit message.
      $usable[] = $text;
    }

    if (!$usable) {
      // No new commit messages, so we don't have anywhere to start from.
      return null;
    }

    return $this->formatUsableLogs($usable);
  }

  /**
   * Retrieve the hg messages between tip and the last update.
   *
   * @task message
   */
  private function getMercurialUpdateMessage() {
    $repository_api = $this->getRepositoryAPI();

    $messages = $repository_api->getCommitMessageLog();

    $local = $this->loadActiveLocalCommitInfo();
    $hashes = ipull($local, null, 'rev');

    $usable = array();
    foreach ($messages as $rev => $message) {
      if (isset($hashes[$rev])) {
        // If this commit is currently part of the active diff on the revision,
        // stop using commit messages, since anything older than this isn't new.
        break;
      }

      // Otherwise, this looks new, so it's a usable commit message.
      $usable[] = $message;
    }

    if (!$usable) {
      // No new commit messages, so we don't have anywhere to start from.
      return null;
    }

    return $this->formatUsableLogs($usable);
  }


  /**
   * Format log messages to prefill a diff update.
   *
   * @task message
   */
  private function formatUsableLogs(array $usable) {
    // Flip messages so they'll read chronologically (oldest-first) in the
    // template, e.g.:
    //
    //   - Added foobar.
    //   - Fixed foobar bug.
    //   - Documented foobar.

    $usable = array_reverse($usable);
    $default = array();
    foreach ($usable as $message) {
      // Pick the first line out of each message.
      $text = trim($message);
      $text = head(explode("\n", $text));
      $default[] = '  - '.$text."\n";
    }

    return implode('', $default);
  }

  private function loadActiveLocalCommitInfo() {
    $current_diff = $this->getConduit()->callMethodSynchronous(
      'differential.getdiff',
      array(
        'revision_id' => $this->revisionID,
      ));

    $properties = idx($current_diff, 'properties', array());
    return idx($properties, 'local:commits', array());
  }


/* -(  Diff Specification  )------------------------------------------------- */


  /**
   * @task diffspec
   */
  private function getLintStatus($lint_result) {
    $map = array(
      ArcanistLintWorkflow::RESULT_OKAY       => 'okay',
      ArcanistLintWorkflow::RESULT_ERRORS     => 'fail',
      ArcanistLintWorkflow::RESULT_WARNINGS   => 'warn',
      ArcanistLintWorkflow::RESULT_SKIP       => 'skip',
    );
    return idx($map, $lint_result, 'none');
  }


  /**
   * @task diffspec
   */
  private function getUnitStatus($unit_result) {
    $map = array(
      ArcanistUnitWorkflow::RESULT_OKAY       => 'okay',
      ArcanistUnitWorkflow::RESULT_FAIL       => 'fail',
      ArcanistUnitWorkflow::RESULT_UNSOUND    => 'warn',
      ArcanistUnitWorkflow::RESULT_SKIP       => 'skip',
      ArcanistUnitWorkflow::RESULT_POSTPONED  => 'postponed',
    );
    return idx($map, $unit_result, 'none');
  }


  /**
   * @task diffspec
   */
  private function buildDiffSpecification() {

    $base_revision  = null;
    $base_path      = null;
    $vcs            = null;
    $repo_uuid      = null;
    $parent         = null;
    $source_path    = null;
    $branch         = null;

    if (!$this->isRawDiffSource()) {
      $repository_api = $this->getRepositoryAPI();

      $base_revision  = $repository_api->getSourceControlBaseRevision();
      $base_path      = $repository_api->getSourceControlPath();
      $vcs            = $repository_api->getSourceControlSystemName();
      $source_path    = $repository_api->getPath();
      $branch         = $repository_api->getBranchName();

      if ($repository_api instanceof ArcanistGitAPI) {
        $info = $this->getGitParentLogInfo();
        if ($info['parent']) {
          $parent = $info['parent'];
        }
        if ($info['base_revision']) {
          $base_revision = $info['base_revision'];
        }
        if ($info['base_path']) {
          $base_path = $info['base_path'];
        }
        if ($info['uuid']) {
          $repo_uuid = $info['uuid'];
        }
      } else if ($repository_api instanceof ArcanistSubversionAPI) {
        $repo_uuid = $repository_api->getRepositorySVNUUID();
      } else if ($repository_api instanceof ArcanistMercurialAPI) {
        // TODO: Provide this information.
      } else {
        throw new Exception("Unsupported repository API!");
      }
    }

    $project_id = null;
    if ($this->requiresWorkingCopy()) {
      $project_id = $this->getWorkingCopy()->getProjectID();
    }

    return array(
      'sourceMachine'             => php_uname('n'),
      'sourcePath'                => $source_path,
      'branch'                    => $branch,
      'sourceControlSystem'       => $vcs,
      'sourceControlPath'         => $base_path,
      'sourceControlBaseRevision' => $base_revision,
      'parentRevisionID'          => $parent,
      'repositoryUUID'            => $repo_uuid,
      'creationMethod'            => 'arc',
      'arcanistProject'           => $project_id,
      'authorPHID'                => $this->getUserPHID(),
    );
  }


/* -(  Diff Properties  )---------------------------------------------------- */


  /**
   * Update lint information for the diff.
   *
   * @return void
   *
   * @task diffprop
   */
  private function updateLintDiffProperty() {
    if (!$this->unresolvedLint) {
      return;
    }

    $data = array();
    foreach ($this->unresolvedLint as $message) {
      $data[] = array(
        'path'        => $message->getPath(),
        'line'        => $message->getLine(),
        'char'        => $message->getChar(),
        'code'        => $message->getCode(),
        'severity'    => $message->getSeverity(),
        'name'        => $message->getName(),
        'description' => $message->getDescription(),
      );
    }

    $this->updateDiffProperty('arc:lint', json_encode($data));
    if (strlen($this->lintExcuse)) {
      $this->updateDiffProperty('arc:lint-excuse',
        json_encode($this->lintExcuse));
    }
  }


  /**
   * Update unit test information for the diff.
   *
   * @return void
   *
   * @task diffprop
   */
  private function updateUnitDiffProperty() {
    if (!$this->testResults) {
      return;
    }

    $data = array();
    foreach ($this->testResults as $test) {
      $data[] = array(
        'name'      => $test->getName(),
        'result'    => $test->getResult(),
        'userdata'  => $test->getUserData(),
        'coverage'  => $test->getCoverage(),
      );
    }

    $this->updateDiffProperty('arc:unit', json_encode($data));
    if (strlen($this->unitExcuse)) {
      $this->updateDiffProperty('arc:unit-excuse',
        json_encode($this->unitExcuse));
    }
  }


  /**
   * Update local commit information for the diff.
   *
   * @task diffprop
   */
  private function updateLocalDiffProperty() {
    if ($this->isRawDiffSource()) {
      return;
    }

    $local_info = $this->getRepositoryAPI()->getLocalCommitInformation();
    if (!$local_info) {
      return;
    }

    $this->updateDiffProperty('local:commits', json_encode($local_info));
  }


  /**
   * Update an arbitrary diff property.
   *
   * @param string Diff property name.
   * @param string Diff property value.
   * @return void
   *
   * @task diffprop
   */
  private function updateDiffProperty($name, $data) {
    $this->getConduit()->callMethodSynchronous(
      'differential.setdiffproperty',
      array(
        'diff_id' => $this->getDiffID(),
        'name'    => $name,
        'data'    => $data,
      ));
  }

}
