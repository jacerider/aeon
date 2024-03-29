<?php

declare(strict_types = 1);

namespace Drush\Commands\aeon;

use Consolidation\AnnotatedCommand\CommandData;
use Consolidation\AnnotatedCommand\CommandError;
use Drupal\Component\Utility\UrlHelper;
use Drush\Commands\DrushCommands;
use Robo\Contract\BuilderAwareInterface;
use Robo\State\Data as RoboStateData;
use Robo\TaskAccessor;
use Symfony\Component\Filesystem\Filesystem;

use Robo\Task\Archive\Tasks as ArchiveTaskLoader;
use Robo\Task\Filesystem\Tasks as FilesystemTaskLoader;
use Symfony\Component\Finder\Finder;

/**
 * Defines Drush commands for generating aeon subtheme.
 */
class SubThemeCommands extends DrushCommands implements BuilderAwareInterface {

  use TaskAccessor;
  use ArchiveTaskLoader;
  use FilesystemTaskLoader;

  /**
   * The aeon subtheme generator.
   *
   * @var \Drupal\aeon\SubThemeGenerator
   */
  protected $subThemeCreator;

  /**
   * The filesystem.
   *
   * @var \Symfony\Component\Filesystem\Filesystem
   */
  protected $fs;

  /**
   * {@inheritdoc}
   */
  public function __construct(?SubThemeGenerator $subThemeCreator = NULL, ?Filesystem $fs = NULL) {
    $this->subThemeCreator = $subThemeCreator ?: new SubThemeGenerator();
    $this->fs = $fs ?: new Filesystem();

    parent::__construct();
  }

  /**
   * Creates a Aeon sub-theme.
   *
   * @command aeon:create
   * @aliases aeon
   *
   * @bootstrap full
   *
   * @option string $machine-name
   *   The machine-readable name of your sub-theme. This will be auto-generated
   *   from the human-readable name if omitted.
   * @option string $description
   *   The description of your sub-theme
   * @option string $destination
   *   The destination of your sub-theme.
   * @option string $kit
   *   The name or url of the starter kit to use.
   *
   * @usage drush aeon:create 'My Theme'
   *   Creates a Aeon sub-theme called "My Theme", using the default options.
   * @usage drush aeon:create 'My Theme' --machine_name=my_theme
   *   Creates a Aeon sub-theme called "My Theme" with a specific machine name.
   *
   * @aeonArgLabel name
   * @aeonOptionMachineName machine-name
   */
  public function generateSubTheme(
    string $name,
    array $options = [
      'machine-name' => '',
      'description' => '',
      'destination' => '',
      'kit' => 'valerian',
    ]
  ) {
    $kit = $options['kit'];

    // @todo Use extension service.
    $aeonDir = \Drupal::service('extension.list.theme')->getPath('aeon');
    $srcDir = "$aeonDir/kits/{$kit}";

    // Find kit from other active themes.
    /** @var \Drupal\Core\Extension\Extension[] $themes */
    foreach (\Drupal::service('theme_handler')->listInfo() as $theme) {
      $path = "{$theme->getPath()}/kits/{$kit}";
      if ($this->fs->exists($path)) {
        $srcDir = $path;
      }
    }

    $dstDir = "{$options['destination']}/{$options['machine-name']}";

    $cb = $this->collectionBuilder();
    $cb->getState()->offsetSet('srcDir', $srcDir);

    if (UrlHelper::isValid($kit, TRUE)) {
      $kitUrl = $kit;
      $cb->addTask($this->taskTmpDir());

      $cb->addCode(function (RoboStateData $data) use ($kitUrl): int {
        $logger = $this->logger();
        $logger->debug(
          'download Aeon starter kit from <info>{kitUrl}</info>',
          [
            'kitUrl' => $kitUrl,
          ]
        );

        $fileName = $this->getFileNameFromUrl($kitUrl);
        $packDir = "{$data['path']}/pack";
        $data['packPath'] = "$packDir/$fileName";

        try {
          $this->fs->mkdir($packDir);
          $this->fs->copy($kitUrl, $data['packPath']);
        }
        catch (\Exception $e) {
          $logger->error($e->getMessage());

          return 1;
        }

        return 0;
      });

      $cb->addCode(function (RoboStateData $data): int {
        $logger = $this->logger();
        $logger->debug(
          'extract downloaded Aeon starter kit from <info>{packPath}</info> to <info>{srcDir}</info>',
          [
            'packPath' => $data['packPath'],
            'srcDir' => $data['srcDir'],
          ]
        );

        $data['srcDir'] = "{$data['path']}/kit";

        /** @var \Drupal\Core\Archiver\ArchiverManager $extractorManager */
        $extractorManager = \Drupal::service('plugin.manager.archiver');

        try {
          /** @var \Drupal\Core\Archiver\ArchiverInterface $extractorInstance */
          $extractorInstance = $extractorManager->getInstance(['filepath' => $data['packPath']]);
          $extractorInstance->extract($data['srcDir']);
        }
        catch (\Exception $e) {
          $this->logger()->error($e->getMessage());

          return 1;
        }

        $topLevelDir = $this->getTopLevelDir($data['srcDir']);
        if ($topLevelDir) {
          $data['srcDir'] = $topLevelDir;
        }

        return 0;
      });
    }

    $cb->addCode(function (RoboStateData $data) use ($dstDir): int {
      $logger = $this->logger();
      $logger->debug(
        'copy Aeon starter kit from <info>{srcDir}</info> to <info>{dstDir}</info>',
        [
          'srcDir' => $data['srcDir'],
          'dstDir' => $dstDir,
        ]
      );

      try {
        $this->fs->mirror($data['srcDir'], $dstDir);
      }
      catch (\Exception $e) {
        $this->logger()->error($e->getMessage());

        return 1;
      }

      return 0;
    });

    $cb->addCode(function () use ($name, $options, $dstDir): int {
      $logger = $this->logger();
      $logger->debug(
        'customize Aeon starter kit in <info>{dstDir}</info> directory',
        [
          'dstDir' => $dstDir,
        ]
      );

      $this
        ->subThemeCreator
        ->setDir($dstDir)
        ->setMachineName($options['machine-name'])
        ->setName($name)
        ->setDescription($options['description'])
        ->generate();

      return 0;
    });

    return $cb;
  }

  /**
   * Validate command.
   *
   * @param \Consolidation\AnnotatedCommand\CommandData $commandData
   *   The command data.
   *
   * @hook validate aeon:create
   *
   * @return \Consolidation\AnnotatedCommand\CommandError|null
   *   The command error or null.
   */
  public function onHookValidateAeonGenerateSubTheme(CommandData $commandData): ?CommandError {
    $input = $commandData->input();

    if (!$input->getOption('kit')) {
      $input->setOption('kit', 'valerian');
    }

    if (!$input->getOption('description')) {
      $input->setOption('description', $this->getDefaultDescription());
    }

    $machineName = $input->getOption('machine-name');
    if (!$machineName) {
      $machineName = $this->convertLabelToMachineName($input->getArgument('name'));
      $input->setOption('machine-name', $machineName);
    }

    $destination = $input->getOption('destination');
    if (!$destination) {
      $destination = $this->getDefaultDestination();
      $input->setOption('destination', $destination);
    }

    $dstDir = "$destination/$machineName";
    if ($this->fs->exists($dstDir) && !$this->isDirEmpty($dstDir)) {
      return new CommandError("Destination directory '$dstDir' not empty", 1);
    }

    return NULL;
  }

  /**
   * On validate arg label.
   *
   * @param \Consolidation\AnnotatedCommand\CommandData $commandData
   *   The command data.
   *
   * @hook validate @aeonArgLabel
   *
   * @return \Consolidation\AnnotatedCommand\CommandError|null
   *   The command error or null.
   */
  public function onHookValidateAeonArgLabel(CommandData $commandData): ?CommandError {
    $annotationKey = 'aeonArgLabel';
    $annotationData = $commandData->annotationData();
    if (!$annotationData->has($annotationKey)) {
      return NULL;
    }

    $commandErrors = [];
    $argNames = $this->parseMultiValueAnnotation($annotationData->get($annotationKey));
    foreach ($argNames as $argName) {
      $commandErrors[] = $this->onHookValidateAeonArgLabelSingle($commandData, $argName);
    }

    return $this->aggregateCommandErrors($commandErrors);
  }

  /**
   * On validate arg single label.
   *
   * @param \Consolidation\AnnotatedCommand\CommandData $commandData
   *   The command data.
   * @param string $argName
   *   The arg name.
   *
   * @return \Consolidation\AnnotatedCommand\CommandError|null
   *   The command error or null.
   */
  protected function onHookValidateAeonArgLabelSingle(CommandData $commandData, string $argName): ?CommandError {
    $label = $commandData->input()->getArgument($argName);
    if (strlen($label) === 0) {
      return NULL;
    }

    if (!preg_match('/^[^\t\r\n]+$/ui', $label)) {
      return new CommandError("Tabs and new line characters are not allowed in argument '$argName'.");
    }

    return NULL;
  }

  /**
   * On validate machine name.
   *
   * @param \Consolidation\AnnotatedCommand\CommandData $commandData
   *   The command data.
   *
   * @hook validate @aeonOptionMachineName
   *
   * @return \Consolidation\AnnotatedCommand\CommandError|null
   *   The command error or null.
   */
  public function onHookValidateAeonOptionMachineName(CommandData $commandData) {
    $annotationKey = 'aeonOptionMachineName';
    $annotationData = $commandData->annotationData();
    if (!$annotationData->has($annotationKey)) {
      return NULL;
    }

    $commandErrors = [];
    $optionNames = $this->parseMultiValueAnnotation($annotationData->get($annotationKey));
    foreach ($optionNames as $optionName) {
      $commandErrors[] = $this->onHookValidateAeonOptionMachineNameSingle($commandData, $optionName);
    }

    return $this->aggregateCommandErrors($commandErrors);
  }

  /**
   * On validate single machine name.
   *
   * @param \Consolidation\AnnotatedCommand\CommandData $commandData
   *   The command data.
   * @param string $optionName
   *   The option name.
   *
   * @return \Consolidation\AnnotatedCommand\CommandError|null
   *   The command error or null.
   */
  protected function onHookValidateAeonOptionMachineNameSingle(CommandData $commandData, $optionName): ?CommandError {
    $machineNames = $commandData->input()->getOption($optionName);
    if (!is_array($machineNames)) {
      $machineNames = strlen($machineNames) !== 0 ? [$machineNames] : [];
    }

    $invalidMachineNames = [];
    foreach ($machineNames as $machineName) {
      if (!preg_match('/^[a-z][a-z0-9_]*$/', $machineName)) {
        $invalidMachineNames[] = $machineName;
      }
    }

    if ($invalidMachineNames) {
      return new CommandError("Following machine-names are invalid in option '$optionName': " . implode(', ', $invalidMachineNames));
    }

    return NULL;
  }

  /**
   * Parse multi value annotation.
   *
   * @param string $value
   *   The value.
   *
   * @return array
   *   The parsed array.
   */
  protected function parseMultiValueAnnotation(string $value): array {
    return $this->explodeCommaSeparatedList($value);
  }

  /**
   * Explode comma separated list.
   *
   * @param string $items
   *   The items.
   *
   * @return array
   *   The exploded array.
   */
  protected function explodeCommaSeparatedList(string $items): array {
    return array_filter(
      preg_split('/\s*,\s*/', trim($items)),
      'mb_strlen'
    );
  }

  /**
   * Aggregate command errors.
   *
   * @param \Consolidation\AnnotatedCommand\CommandError[] $commandErrors
   *   The command errors.
   *
   * @return \Consolidation\AnnotatedCommand\CommandError|null
   *   The error or null.
   */
  protected function aggregateCommandErrors(array $commandErrors): ?CommandError {
    $errorCode = 0;
    $messages = [];
    /** @var \Consolidation\AnnotatedCommand\CommandError $commandError */
    foreach (array_filter($commandErrors) as $commandError) {
      $messages[] = $commandError->getOutputData();
      $errorCode = max($errorCode, $commandError->getExitCode());
    }

    if ($messages) {
      return new CommandError(implode(PHP_EOL, $messages), $errorCode);
    }

    return NULL;
  }

  /**
   * Convert label to machine name.
   *
   * @param string $label
   *   The label.
   *
   * @return string
   *   The machine name.
   */
  protected function convertLabelToMachineName(string $label): string {
    return mb_strtolower(preg_replace('/[^a-z0-9_]+/ui', '_', $label));
  }

  /**
   * Get the default destination.
   *
   * @return string
   *   The default destination.
   */
  protected function getDefaultDestination(): string {
    return './themes';
  }

  /**
   * Get the default description.
   */
  protected function getDefaultDescription(): string {
    return 'A theme based on Aeon.';
  }

  /**
   * Check if dir is empty.
   *
   * @return bool
   *   TRUE if directory is empty.
   */
  protected function isDirEmpty(string $dir): bool {
    return !(new \FilesystemIterator($dir))->valid();
  }

  /**
   * Get directory descendants.
   *
   * @return \Symfony\Component\Finder\Finder
   *   The finder.
   */
  protected function getDirectDescendants(string $dir): Finder {
    return (new Finder())
      ->in($dir)
      ->depth('0');
  }

  /**
   * Get file name from URL.
   *
   * @param string $url
   *   The url.
   *
   * @return string
   *   The file name.
   */
  protected function getFileNameFromUrl(string $url): string {
    return pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_BASENAME);
  }

  /**
   * Get the top level dir.
   *
   * @param string $parentDir
   *   The parent directory.
   *
   * @return string
   *   The top level directory.
   */
  protected function getTopLevelDir(string $parentDir): string {
    $directDescendants = $this->getDirectDescendants($parentDir);
    $iterator = $directDescendants->getIterator();
    $iterator->rewind();
    /** @var \Symfony\Component\Finder\SplFileInfo $firstFile */
    $firstFile = $iterator->current();
    if ($directDescendants->count() === 1 && $firstFile->isDir()) {
      return $firstFile->getPathname();
    }

    return '';
  }

}
