<?php
/**
 * Plugin Name:       ElasticPress Natural Language Processing Tools
 * Plugin URI:        https://github.com/moraleida/elasticpress-nlp-tools
 * Description:       A toolkit for using self-hosted Natural Language Processing in WordPress
 * Version:           0.0.1
 * Requires PHP:      7.0
 * Author:            Ricardo Moraleida
 * License:           GPL v3 or later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       enlptag
 *
 * @package  elasticpress-nlp-tools
 */

namespace ENLPTools;

use \ElasticPress\Elasticsearch;
use \ElasticPress\Indexables;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

define( 'ENLPTOOLS_URL', plugin_dir_url( __FILE__ ) );
define( 'ENLPTOOLS_PATH', plugin_dir_path( __FILE__ ) );
define( 'ENLPTOOLS_VERSION', '0.0.1' );

class ENLPTools {

	public $pipeline_name = 'wordpress_nlp_ingester';
	public $pipeline_description = 'A Natural Language Processing pipeline for WordPress taxonomies';
	public $field_to_ingest = 'post_content';
	public $models_to_map = array( 'dates', 'persons', 'locations' );

	public function __construct() {
	}

	public function init() {
		// core actions
		\add_action( 'activate_plugin', array( $this, 'activate' ) );
		\add_action( 'ep_after_bulk_index', array( $this, 'get_post_entities_from_es' ), 10, 3 );

		// elasticpress filters
		\add_filter( 'ep_index_request_path', array( $this, 'append_ingester_to_index_endpoint' ) );
		\add_filter( 'ep_bulk_index_request_path', array( $this, 'append_ingester_to_index_endpoint' ) );

		// custom actions
		\add_action( 'enlptools_configure_ingester', array( $this, 'configure_ingester' ) );
		\add_action( 'enlptools_configure_mapping', array( $this, 'configure_mapping' ) );

		$this->models_to_map = \apply_filters( 'enpltools_models_to_map', $this->models_to_map );
	}

	/**
	 * Actions to run when the plugin is activated
	 */
	public function activate() {
		/**
		 * TODO: Require ElasticPress
		 * TODO: Require OpenNLP Plugin
		 * TODO: Require OpenNLP Configs
		 */

		do_action( 'enlptools_configure_ingester' );
		do_action( 'enlptools_configure_mapping' );
	}

	/**
	 * Create the pipeline ingester in the Elasticsearch cluster
	 */
	public function configure_ingester() {
		$ep = Elasticsearch::factory();

		$query = array(
			'description' => $this->pipeline_description,
			'processors'  => array(
				array(
					'opennlp' => array(
						'field' => $this->field_to_ingest,
					),
				),
			),
		);

		$args = array(
			'body'   => \wp_json_encode( $query ),
			'method' => 'PUT',
		);

		$path = '_ingest/pipeline/' . $this->pipeline_name;

		$request  = $ep->remote_request( $path, $args );
		$response = \json_decode( $request['body'] );
	}

	/**
	 * Dynamically add the NLP tag fields to the Elasticsearch mapping without reindexing
	 */
	 public function configure_mapping() {
		 $ep    = Elasticsearch::factory();
		 $index = Indexables::factory()->get( 'post' )->get_index_name();
		 $query = $this->prepare_entities_to_include_in_es_mapping();

		 $args = array(
			 'body'   => \wp_json_encode( $query ),
			 'method' => 'POST',
		 );

		 $path = $index . '/_mapping';

		 $request  = $ep->remote_request( $path, $args );
		 $response = \json_decode( $request['body'] );
	 }

	/**
	 * Map ingested entities for indexing
	 *
	 * @return mixed
	 */
	public function map_entities() {
		$entities        = array_flip( $this->models_to_map );
		$mapped_entities = array();

		foreach ( $entities as $entity => $value ) {
			$map_to = \apply_filters( 'enlptools_entity_sync_to', '', $entity );

			if ( $map_to ) {
				$mapped_entities[ 'entities.' . $entity ] = $map_to;
			}
		}

		return $mapped_entities;
	}

	/**
	 * Prepares entities for mapping in Elasticsearch
	 *
	 * @return string
	 */
	public function prepare_entities_to_include_in_es_mapping() {
		$entities  = $this->models_to_map;
		$mapping = array();

		foreach ( $entities as $entity ) {
			$mapping['properties'][ 'entities.'. $entity ] = array(
				'type' => 'text',
			);

		}

		return $mapping;
	}

	/**
	 * Prepares entities to be retrieved from Elasticsearch
	 *
	 * @return string
	 */
	public function prepare_entities_to_query() {
		$entities  = $this->map_entities();
		$ent_str[] = implode( ',', \array_keys( $entities ) );
		$ent_str[] = implode( ',', \array_values( $entities ) );

		return \implode( ',', $ent_str );
	}

	/**
	 * Retrieves a single document from Elasticsearch to save its entities to WP
	 *
	 * @param $document
	 * @param $return
	 */
	public function get_post_entities_from_es( $documents, $indexable, $return ) {
		$ep       = Elasticsearch::factory();
		$index    = Indexables::factory()->get( 'post' )->get_index_name();
		$entities = $this->prepare_entities_to_query();

		$args = array(
			'body'   => \wp_json_encode( array( 'ids' => $documents ) ),
			'method' => 'POST',
		);

		$path = $index . '/_mget?_source=' . $entities;

		$request  = $ep->remote_request( $path, $args );
		$response = \json_decode( $request['body'] );

		if ( ! empty( $response->docs ) ) {
			$this->save_entities_to_wp( $response->docs );
		}
	}

	/**
	 * Saves entities found in Elasticsearch back to WP
	 *
	 * @param $post_id
	 * @param $entities
	 */
	public function save_entities_to_wp( $docs ) {
		$entity_mapping = $this->map_entities();
		$ids = array();

		foreach ( $docs as $doc ) {
			if ( ! empty( $doc->_source->entities ) ) {
				$entities = $doc->_source->entities;
				$ids[] = $doc->_id;

				foreach ( $entity_mapping as $entity => $mapping ) {
					$map = \explode( '.', $mapping );
					$ent = \explode( '.', $entity );

					if ( 'terms' === $map[0] && ! empty( $entities->{$ent[1]} ) ) {
						\wp_set_object_terms( $doc->_id, $entities->{$ent[1]}, $map[1], true );
					}

					if ( 'meta' === $map[0] && ! empty( $entities->{$ent[1]} ) ) {
						update_post_meta( $doc->_id, \sanitize_text_field( $map[1] ), $this->sanitize_array_to_store( $entities->{$ent[1]} ) );
					}
				}
			}
		}

		\remove_action( 'ep_after_bulk_index', array( $this, 'get_post_entities_from_es' ), 10, 3 );
		$indexable = new Indexable\Post\Post();
		$indexable->bulk_index( $ids );
		\add_action( 'ep_after_bulk_index', array( $this, 'get_post_entities_from_es' ), 10, 3 );
	}

	public function sanitize_array_to_store( $array ) {
		return array_map( 'sanitize_text_field', $array );
	}

	/**
	 * Append our Custom Ingester to POST indexing
	 *
	 * @param string $path the indexing endpoint
	 *
	 * @return string
	 *
	 * @uses ep_bulk_index_request_path
	 * @uses ep_index_request_path
	 */
	public function append_ingester_to_index_endpoint( $path ) {
		return $path . '?pipeline=' . $this->pipeline_name;
	}
}

$plugin = new ENLPTools();
$plugin->init();