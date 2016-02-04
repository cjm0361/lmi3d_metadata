<?php

/**
 * @file
 * Contains \Drupal\lmi3d_metadata\Lmi3dMetadataScanner.
 */

namespace Drupal\lmi3d_metadata;

/**
 * A scanner to find YAML files in a given folder.
 */
class Lmi3dMetadataScanner {

  /**
   * Returns a list of file objects.
   *
   * @param string $directory
   *   Absolute path to the directory to search.
   * 
   * @param array  $ext
   *   File extensions to search.
   * 
   * @return array
   *   List of stdClass objects with name and uri properties.
   */
  public function scan($directory, $ext) {
    // Use Unix paths regardless of platform, skip dot directories, follow
    // symlinks (to allow extensions to be linked from elsewhere), and return
    // the RecursiveDirectoryIterator instance to have access to getSubPath(),
    // since SplFileInfo does not support relative paths.
    $flags = \FilesystemIterator::UNIX_PATHS;
    $flags |= \FilesystemIterator::SKIP_DOTS;
    $flags |= \FilesystemIterator::CURRENT_AS_SELF;
    $directory_iterator = new \RecursiveDirectoryIterator($directory, $flags);
    $iterator = new \RecursiveIteratorIterator($directory_iterator);

    $files = array();
    foreach ($iterator as $fileinfo) {
      /* @var \SplFileInfo $fileinfo */

      // Skip directories and non-json files.
      if ($fileinfo->isDir() || $fileinfo->getExtension() != $ext) {
        continue;
      }

      // @todo Use a typed class?
      $file = new \stdClass();
      $file->name = $fileinfo->getFilename();
      $file->uri = $fileinfo->getPathname();
      $files[$file->uri] = $file;
    }

    return $files;
  }

}
