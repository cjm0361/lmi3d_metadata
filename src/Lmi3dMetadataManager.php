<?php

/**
 * @file
 * Contains \Drupal\lmi3d_metadata\Lmi3dMetadataManager.
 */

namespace Drupal\lmi3d_metadata;

use Drupal\default_content\DefaultContentManager;

/**
 * A service for handling import of default content.
 * @todo throw useful exceptions
 */
class Lmi3dMetadataManager extends DefaultContentManager {

  /**
   * {@inheritdoc}
   */
  public function importContent($module) {
    $created = array();
    $folder = drupal_get_path('module', $module) . "/lmi3d";

    if (file_exists($folder)) {
      $file_map = array();
      foreach ($this->entityManager->getDefinitions() as $entity_type_id => $entity_type) {
        $reflection = new \ReflectionClass($entity_type->getClass());
        // We are only interested in importing content entities.
        if ($reflection->implementsInterface('\Drupal\Core\Config\Entity\ConfigEntityInterface')) {
          continue;
        }
        if (!file_exists($folder . '/' . $entity_type_id)) {
          continue;
        }
        $files = ($this->scanner()->scan($folder . '/' . $entity_type_id, 'yml') + $this->scanner()->scan($folder . '/' . $entity_type_id, 'json'));

        // Default content uses drupal.org as domain.
        // @todo Make this use a uri like default-content:.
        $this->linkManager->setLinkDomain(static::LINK_DOMAIN);
        // Parse all of the files and sort them in order of dependency.
        foreach ($files as $file) {
          $contents = $this->parseFile($file);

          // Decode the file contents.
          $decoded = $this->serializer->decode($contents, 'hal_json');

          // Get the link to this entity.
          $self = $decoded['_links']['self']['href'];

          // Throw an exception when this URL already exists.
          if (isset($file_map[$self])) {
            $args = array(
              '@href' => $self,
              '@first' => $file_map[$self]->uri,
              '@second' => $file->uri,
            );
            // Reset link domain.
            $this->linkManager->setLinkDomain(FALSE);
            throw new \Exception(SafeMarkup::format('Default content with href @href exists twice: @first @second', $args));
          }

          // Store the entity type with the file.
          $file->entity_type_id = $entity_type_id;
          // Store the file in the file map.
          $file_map[$self] = $file;
          // Create a vertex for the graph.
          $vertex = $this->getVertex($self);
          $this->graph[$vertex->link]['edges'] = [];
          if (empty($decoded['_embedded'])) {
            // No dependencies to resolve.
            continue;
          }
          // Here we need to resolve our dependencies;
          foreach ($decoded['_embedded'] as $embedded) {
            foreach ($embedded as $item) {
              $edge = $this->getVertex($item['_links']['self']['href']);
              $this->graph[$vertex->link]['edges'][$edge->link] = TRUE;
            }
          }
        }
      }

      // @todo what if no dependencies?
      $sorted = $this->sortTree($this->graph);

      foreach ($sorted as $link => $details) {
        if (!empty($file_map[$link])) {
          $file = $file_map[$link];
          $entity_type_id = $file->entity_type_id;
          $resource = $this->resourcePluginManager->getInstance(array('id' => 'entity:' . $entity_type_id));
          $definition = $resource->getPluginDefinition();
          $contents = $this->parseFile($file);
          $class = $definition['serialization_class'];
          $entity = $this->serializer->deserialize($contents, $class, 'hal_json', array('request_method' => 'POST'));
          if (!($exists = \Drupal::entityManager()->loadEntityByUuid($entity_type_id, $entity->uuid(), TRUE))) {
            $entity->enforceIsNew(TRUE);
            $entity->save();
            $created[] = $entity;
          } else {
            $entity = $exists;
          }

          // Save pathalias
          if (($uris = parse_url($link)) && ($entity_path = $entity->urlInfo()->toString())) {
            $exists_pathalias = \Drupal::service('path.alias_manager')->getAliasByPath($entity_path, $entity->language()->getId());
            if (($exists_pathalias == $entity_path) && ($exists_pathalias != $uris['path'])) {
              \Drupal::service('path.alias_storage')->save($entity_path, $uris['path'], $entity->language()->getId());
            }
          }
        }
      }
    }
    // Reset the tree.
    $this->resetTree();
    // Reset link domain.
    $this->linkManager->setLinkDomain(FALSE);
    return $created;
  }

  /**
   * Utility to get a default content scanner
   *
   * @return \Drupal\lmi3d_metadata\Lmi3dMetadataScanner
   *   A system listing implementation.
   */
  protected function scanner() {
    if ($this->scanner) {
      return $this->scanner;
    }
    return new Lmi3dMetadataScanner();
  }
}
