<?php

namespace Ayesh\ComposerAutoloadMin;

use Composer\Autoload\AutoloadGenerator;
use Composer\Autoload\ClassMapGenerator;
use Composer\Config;
use Composer\Installer\InstallationManager;
use Composer\Package\PackageInterface;
use Composer\Repository\InstalledRepositoryInterface;
use Composer\Script\ScriptEvents;
use Composer\Util\Filesystem;

class MinAutoloadGenerator extends AutoloadGenerator {
    protected $classMapAuthoritative;
    protected $runScripts;
    protected $eventDispatcher;
    protected $devMode;
    protected $io;
    private $apcu;

  public function dump(
      Config $config,
      InstalledRepositoryInterface $localRepo,
      PackageInterface $mainPackage,
      InstallationManager $installationManager,
      $targetDir,
      $scanPsrPackages = false,
      $suffix = ''
  ) {
      if ($this->classMapAuthoritative) {
          // Force scanPsrPackages when classmap is authoritative
          $scanPsrPackages = true;
      }
      if ($this->runScripts) {
          $this->eventDispatcher->dispatchScript(ScriptEvents::PRE_AUTOLOAD_DUMP, $this->devMode, array(), array(
              'optimize' => (bool) $scanPsrPackages,
          ));
      }

      $filesystem = new Filesystem();
      $filesystem->ensureDirectoryExists($config->get('vendor-dir'));
      // Do not remove double realpath() calls.
      // Fixes failing Windows realpath() implementation.
      // See https://bugs.php.net/bug.php?id=72738
      $basePath = $filesystem->normalizePath(realpath(realpath(getcwd())));
      $vendorPath = $filesystem->normalizePath(realpath(realpath($config->get('vendor-dir'))));
      $useGlobalIncludePath = (bool) $config->get('use-include-path');
      $prependAutoloader = $config->get('prepend-autoloader') === false ? 'false' : 'true';
      $targetDir = $vendorPath.'/'.$targetDir;
      $filesystem->ensureDirectoryExists($targetDir);

      $vendorPathCode = $filesystem->findShortestPathCode(realpath($targetDir), $vendorPath, true);
      $vendorPathCode52 = str_replace('__DIR__', 'dirname(__FILE__)', $vendorPathCode);
      $vendorPathToTargetDirCode = $filesystem->findShortestPathCode($vendorPath, realpath($targetDir), true);

      $appBaseDirCode = $filesystem->findShortestPathCode($vendorPath, $basePath, true);
      $appBaseDirCode = str_replace('__DIR__', '$vendorDir', $appBaseDirCode);

      $namespacesFile = <<<EOF
<?php

// autoload_namespaces.php @generated by Composer

\$vendorDir = $vendorPathCode52;
\$baseDir = $appBaseDirCode;

return array(

EOF;

      $psr4File = <<<EOF
<?php

// autoload_psr4.php @generated by Composer

\$vendorDir = $vendorPathCode52;
\$baseDir = $appBaseDirCode;

return array(

EOF;

      // Collect information from all packages.
      $packageMap = $this->buildPackageMap($installationManager, $mainPackage, $localRepo->getCanonicalPackages());
      $autoloads = $this->parseAutoloads($packageMap, $mainPackage, $this->devMode === false);

      // Process the 'psr-0' base directories.
      foreach ($autoloads['psr-0'] as $namespace => $paths) {
          $exportedPaths = array();
          foreach ($paths as $path) {
              $exportedPaths[] = $this->getPathCode($filesystem, $basePath, $vendorPath, $path);
          }
          $exportedPrefix = var_export($namespace, true);
          $namespacesFile .= "    $exportedPrefix => ";
          $namespacesFile .= "array(".implode(', ', $exportedPaths)."),\n";
      }
      $namespacesFile .= ");\n";

      // Process the 'psr-4' base directories.
      foreach ($autoloads['psr-4'] as $namespace => $paths) {
          $exportedPaths = array();
          foreach ($paths as $path) {
              $exportedPaths[] = $this->getPathCode($filesystem, $basePath, $vendorPath, $path);
          }
          $exportedPrefix = var_export($namespace, true);
          $psr4File .= "    $exportedPrefix => ";
          $psr4File .= "array(".implode(', ', $exportedPaths)."),\n";
      }
      $psr4File .= ");\n";

      $classmapFile = <<<EOF
<?php

// autoload_classmap.php @generated by Composer

\$vendorDir = $vendorPathCode52;
\$baseDir = $appBaseDirCode;

return array(

EOF;

      // add custom psr-0 autoloading if the root package has a target dir
      $targetDirLoader = null;
      $mainAutoload = $mainPackage->getAutoload();
      if ($mainPackage->getTargetDir() && !empty($mainAutoload['psr-0'])) {
          $levels = substr_count($filesystem->normalizePath($mainPackage->getTargetDir()), '/') + 1;
          $prefixes = implode(', ', array_map(function ($prefix) {
              return var_export($prefix, true);
          }, array_keys($mainAutoload['psr-0'])));
          $baseDirFromTargetDirCode = $filesystem->findShortestPathCode($targetDir, $basePath, true);

          $targetDirLoader = <<<EOF

    public static function autoload(\$class)
    {
        \$dir = $baseDirFromTargetDirCode . '/';
        \$prefixes = array($prefixes);
        foreach (\$prefixes as \$prefix) {
            if (0 !== strpos(\$class, \$prefix)) {
                continue;
            }
            \$path = \$dir . implode('/', array_slice(explode('\\\\', \$class), $levels)).'.php';
            if (!\$path = stream_resolve_include_path(\$path)) {
                return false;
            }
            require \$path;

            return true;
        }
    }

EOF;
      }

      $blacklist = null;
      if (!empty($autoloads['exclude-from-classmap'])) {
          $blacklist = '{(' . implode('|', $autoloads['exclude-from-classmap']) . ')}';
      }

      $classMap = array();
      $ambiguousClasses = array();
      $scannedFiles = array();
      foreach ($autoloads['classmap'] as $dir) {
          $classMap = $this->addClassMapCode($filesystem, $basePath, $vendorPath, $dir, $blacklist, null, null, $classMap, $ambiguousClasses, $scannedFiles);
      }

      if ($scanPsrPackages) {
          $namespacesToScan = array();

          // Scan the PSR-0/4 directories for class files, and add them to the class map
          foreach (array('psr-4', 'psr-0') as $psrType) {
              foreach ($autoloads[$psrType] as $namespace => $paths) {
                  $namespacesToScan[$namespace][] = array('paths' => $paths, 'type' => $psrType);
              }
          }

          krsort($namespacesToScan);

          foreach ($namespacesToScan as $namespace => $groups) {
              foreach ($groups as $group) {
                  foreach ($group['paths'] as $dir) {
                      $dir = $filesystem->normalizePath($filesystem->isAbsolutePath($dir) ? $dir : $basePath.'/'.$dir);
                      if (!is_dir($dir)) {
                          continue;
                      }

                      $classMap = $this->addClassMapCode($filesystem, $basePath, $vendorPath, $dir, $blacklist, $namespace, $group['type'], $classMap, $ambiguousClasses, $scannedFiles);
                  }
              }
          }
      }

      foreach ($ambiguousClasses as $className => $ambigiousPaths) {
          $cleanPath = str_replace(array('$vendorDir . \'', '$baseDir . \'', "',\n"), array($vendorPath, $basePath, ''), $classMap[$className]);

          $this->io->writeError(
              '<warning>Warning: Ambiguous class resolution, "'.$className.'"'.
              ' was found '. (count($ambigiousPaths) + 1) .'x: in "'.$cleanPath.'" and "'. implode('", "', $ambigiousPaths) .'", the first will be used.</warning>'
          );
      }

      ksort($classMap);
      foreach ($classMap as $class => $code) {
          $classmapFile .= '    '.var_export($class, true).' => '.$code;
      }
      $classmapFile .= ");\n";

      if (!$suffix) {
          if (!$config->get('autoloader-suffix') && is_readable($vendorPath.'/autoload.php')) {
              $content = file_get_contents($vendorPath.'/autoload.php');
              if (preg_match('{ComposerAutoloaderInit([^:\s]+)::}', $content, $match)) {
                  $suffix = $match[1];
              }
          }

          if (!$suffix) {
              $suffix = $config->get('autoloader-suffix') ?: md5(uniqid('', true));
          }
      }

      $this->filePutContentsIfModified($targetDir.'/autoload_namespaces.php', $namespacesFile);
      $this->filePutContentsIfModified($targetDir.'/autoload_psr4.php', $psr4File);
      $this->filePutContentsIfModified($targetDir.'/autoload_classmap.php', $classmapFile);
      $includePathFilePath = $targetDir.'/include_paths.php';
      if ($includePathFileContents = $this->getIncludePathsFile($packageMap, $filesystem, $basePath, $vendorPath, $vendorPathCode52, $appBaseDirCode)) {
          $this->filePutContentsIfModified($includePathFilePath, $includePathFileContents);
      } elseif (file_exists($includePathFilePath)) {
          unlink($includePathFilePath);
      }
      $includeFilesFilePath = $targetDir.'/autoload_files.php';
      if ($includeFilesFileContents = $this->getIncludeFilesFile($autoloads['files'], $filesystem, $basePath, $vendorPath, $vendorPathCode52, $appBaseDirCode)) {
          $this->filePutContentsIfModified($includeFilesFilePath, $includeFilesFileContents);
      } elseif (file_exists($includeFilesFilePath)) {
          unlink($includeFilesFilePath);
      }
      $this->filePutContentsIfModified($targetDir.'/autoload_static.php', $this->getStaticFile($suffix, $targetDir, $vendorPath, $basePath, $staticPhpVersion));

      $this->safeUnlink($targetDir.'/autoload_namespaces.php');
      $this->safeUnlink($targetDir.'/autoload_psr4.php');
      $this->safeUnlink($targetDir.'/autoload_classmap.php');
      $this->safeUnlink($targetDir.'/autoload_real.php');
      $this->safeUnlink($targetDir.'/autoload_files.php');

      $initial_autoload = $this->getAutoloadFile($vendorPathToTargetDirCode, $suffix);
      $autoload_real_contents = $this->getAutoloadRealFile(true, (bool) $includePathFileContents, $targetDirLoader, (bool) $includeFilesFileContents, $vendorPathCode, $appBaseDirCode, $suffix, $useGlobalIncludePath, $prependAutoloader, $staticPhpVersion, (bool) $config->get('platform-check'));

      $this->filePutContentsIfModified($vendorPath.'/autoload.php', $initial_autoload . "\r\n" . $autoload_real_contents);

      $checkPlatform = $config->get('platform-check');
      if ($checkPlatform && method_exists($this, 'getPlatformCheck')) {
          $this->filePutContentsIfModified($targetDir.'/platform_check.php', $this->getPlatformCheck($packageMap));
      } elseif (file_exists($targetDir.'/platform_check.php')) {
          unlink($targetDir.'/platform_check.php');
      }

      $this->safeCopy(__DIR__.'/../vendor-verbatim/ClassLoader.php', $targetDir.'/ClassLoader.php');
      $this->safeCopy(__DIR__.'/../vendor-verbatim/LICENSE', $targetDir.'/LICENSE');

      if ($this->runScripts) {
          $this->eventDispatcher->dispatchScript(ScriptEvents::POST_AUTOLOAD_DUMP, $this->devMode, array(), array(
              'optimize' => (bool) $scanPsrPackages,
          ));
      }

      return count($classMap);
  }

  protected function safeUnlink(string $filename): void {
      if (file_exists($filename)) {
          unlink($filename);
      }
  }

    protected function getAutoloadFile($vendorPathToTargetDirCode, $suffix)
    {
        return <<<AUTOLOAD
<?php

// autoload.php @generated by Composer + Composer Min Autoload plugin


return ComposerAutoloaderInit$suffix::getLoader();

AUTOLOAD;
    }

    protected function getAutoloadRealFile($useClassMap, $useIncludePath, $targetDirLoader, $useIncludeFiles, $vendorPathCode, $appBaseDirCode, $suffix, $useGlobalIncludePath, $prependAutoloader, $staticPhpVersion = 70000, $checkPlatform = true)
    {
        $file = <<<HEADER

class ComposerAutoloaderInit$suffix
{
    private static \$loader;

    public static function loadClassLoader(\$class)
    {
        if ('Composer\\Autoload\\ClassLoader' === \$class) {
            require __DIR__ . '/composer/ClassLoader.php';
        }
    }

    /**
     * @return \Composer\Autoload\ClassLoader
     */
    public static function getLoader()
    {
        if (null !== self::\$loader) {
            return self::\$loader;
        }

HEADER;

        if ($checkPlatform && method_exists($this, 'getPlatformCheck')) {
            $file .= <<<'PLATFORM_CHECK'

        require __DIR__ . '/platform_check.php';


PLATFORM_CHECK;
        }

        $file .= <<<CLASSLOADER_INIT
        spl_autoload_register(array('ComposerAutoloaderInit$suffix', 'loadClassLoader'), true, $prependAutoloader);
        self::\$loader = \$loader = new \\Composer\\Autoload\\ClassLoader();
        spl_autoload_unregister(array('ComposerAutoloaderInit$suffix', 'loadClassLoader'));


CLASSLOADER_INIT;

        if ($useIncludePath) {
            $file .= <<<'INCLUDE_PATH'
        $includePaths = require __DIR__ . '/composer/include_paths.php';
        $includePaths[] = get_include_path();
        set_include_path(implode(PATH_SEPARATOR, $includePaths));


INCLUDE_PATH;
        }

        $file .= <<<STATIC_INIT
        
        require_once __DIR__ . '/composer/autoload_static.php';
        call_user_func(\Composer\Autoload\ComposerStaticInit$suffix::getInitializer(\$loader));

STATIC_INIT;

        if ($this->classMapAuthoritative) {
            $file .= <<<'CLASSMAPAUTHORITATIVE'
        $loader->setClassMapAuthoritative(true);

CLASSMAPAUTHORITATIVE;
        }

        if ($this->apcu) {
            $apcuPrefix = substr(base64_encode(md5(uniqid('', true), true)), 0, -3);
            $file .= <<<APCU
        \$loader->setApcuPrefix('$apcuPrefix');

APCU;
        }

        if ($useGlobalIncludePath) {
            $file .= <<<'INCLUDEPATH'
        $loader->setUseIncludePath(true);

INCLUDEPATH;
        }

        if ($targetDirLoader) {
            $file .= <<<REGISTER_TARGET_DIR_AUTOLOAD
        spl_autoload_register(array('ComposerAutoloaderInit$suffix', 'autoload'), true, true);


REGISTER_TARGET_DIR_AUTOLOAD;
        }

        $file .= <<<REGISTER_LOADER
        \$loader->register($prependAutoloader);


REGISTER_LOADER;

        if ($useIncludeFiles) {
            $file .= <<<INCLUDE_FILES
        \$includeFiles = Composer\Autoload\ComposerStaticInit$suffix::\$files;
        foreach (\$includeFiles as \$fileIdentifier => \$file) {
            composerRequire$suffix(\$fileIdentifier, \$file);
        }


INCLUDE_FILES;
        }

        $file .= <<<METHOD_FOOTER
        return \$loader;
    }

METHOD_FOOTER;

        $file .= $targetDirLoader;

        if ($useIncludeFiles) {
            return $file . <<<FOOTER
}

function composerRequire$suffix(\$fileIdentifier, \$file)
{
    if (empty(\$GLOBALS['__composer_autoload_files'][\$fileIdentifier])) {
        require \$file;

        \$GLOBALS['__composer_autoload_files'][\$fileIdentifier] = true;
    }
}

FOOTER;
        }

        return $file . <<<FOOTER
}

FOOTER;
    }


  /*
   * Verbatim
   */



    private function addClassMapCode($filesystem, $basePath, $vendorPath, $dir, $blacklist, $namespaceFilter, $autoloadType, array $classMap, array &$ambiguousClasses, array &$scannedFiles)
    {
        foreach ($this->generateClassMap($dir, $blacklist, $namespaceFilter, $autoloadType, true, $scannedFiles) as $class => $path) {
            $pathCode = $this->getPathCode($filesystem, $basePath, $vendorPath, $path).",\n";
            if (!isset($classMap[$class])) {
                $classMap[$class] = $pathCode;
            } elseif ($this->io && $classMap[$class] !== $pathCode && !preg_match('{/(test|fixture|example|stub)s?/}i', strtr($classMap[$class].' '.$path, '\\', '/'))) {
                $ambiguousClasses[$class][] = $path;
            }
        }

        return $classMap;
    }

    private function generateClassMap($dir, $blacklist, $namespaceFilter, $autoloadType, $showAmbiguousWarning, array &$scannedFiles)
    {
        return ClassMapGenerator::createMap($dir, $blacklist, $showAmbiguousWarning ? $this->io : null, $namespaceFilter, $autoloadType, $scannedFiles);
    }

    private function filePutContentsIfModified($path, $content)
    {
        $currentContent = @file_get_contents($path);
        if (!$currentContent || ($currentContent != $content)) {
            return file_put_contents($path, $content);
        }

        return 0;
    }

    /**
     * @inheritDoc
     */
    protected function safeCopy($source, $target)
    {
        if (!file_exists($target) || !file_exists($source) || !$this->filesAreEqual($source, $target)) {
            $source = fopen($source, 'r');
            $target = fopen($target, 'w+');

            stream_copy_to_stream($source, $target);
            fclose($source);
            fclose($target);
        }
    }

    // src/Composer/Util/Filesystem.php
    private function filesAreEqual($a, $b)
    {
        // Check if filesize is different
        if (filesize($a) !== filesize($b)) {
            return false;
        }

        // Check if content is different
        $ah = fopen($a, 'rb');
        $bh = fopen($b, 'rb');

        $result = true;
        while (!feof($ah)) {
            if (fread($ah, 8192) != fread($bh, 8192)) {
                $result = false;
                break;
            }
        }

        fclose($ah);
        fclose($bh);

        return $result;
    }
}
