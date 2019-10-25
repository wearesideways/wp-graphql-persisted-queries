<?php
/**
 * Loader
 *
 * @package wp-graphql-persisted-queries
 */

namespace WPGraphQL\Extensions\PersistedQueries;

use GraphQL\Error\UserError;
use GraphQL\Language\Parser;
use GraphQL\Server\OperationParams;
use GraphQL\Server\ServerConfig;

const VERSION = 2;

/**
 * Load or save a persisted query from a custom post type. This allows users to
 * avoid sending the query over the wire, saving bandwidth. In particular, it
 * allows for moving to GET requests, which can be cached at the edge.
 */
class Loader {
    /**
     * Error message returned when the query is not persisted or cannot be found.
     * This is important for the Apollo implementation; it looks for this exact
     * error message in the response.
     */
    private $error_message = 'PersistedQueryNotFound';

    /**
     * Namespace for WP filters.
     *
     * @var string
     */
    private $namespace = 'graphql_persisted_queries';

    private $persist_query_ids = [];

    /**
     * Filter configuration values and register the post type used to store
     * persisted queries.
     *
     * @return void
     */
    public function init() {
        add_filter( 'graphql_request_data', [ $this, 'process_request' ], 10, 1 );

        add_filter( 'graphql_process_http_request_response', [ $this, 'process_response' ], 10, 4 );

        add_filter( 'graphql_server_config', [ $this, 'graphql_server_config' ], 10, 2 );

        // Filter the HTTP status code.
        add_filter( 'graphql_response_status_code', [ $this, 'get_http_status_code' ], 10, 2 );
    }

    /**
     * Filter the HTTP status code. We should return 202 instead of 500 if
     * retrieving the persisted query fails. This prevents the Apollo client from
     * giving up on the request. It also prevents most edge caches from caching
     * this initial error response.
     *
     * @param  int   $status_code HTTP status code
     * @param  array $response    GraphQL response
     * @return int
     */
    public function get_http_status_code( $status_code, $response ) {
        if ( is_array( $response ) && isset( $response['errors'][0]['message'] ) && $this->error_message === $response['errors'][0]['message'] ) {
            return 202;
        }

        return $status_code;
    }

    /**
     * Be a little flexible in how operation name is sent.
     *
     * @param  array $request_data Request data.
     * @return string
     */
    private function get_operation_name( $request_data ) {
        foreach( [ 'operationName', 'operation_name' ] as $key ) {
            if ( ! empty( $request_data[ $key ] ) ) {
                return $request_data[ $key ];
            }
        }

        return 'UnnamedQuery';
    }

    /**
     * Attempts to load a persisted query corresponding to a query ID (hash).
     *
     * @param  string $query_id Query ID
     * @return array
     */
    private function load( $query_id ) {
        // If query has been persisted to our custom post type, return it.
        return wp_cache_get( VERSION . ':' . $query_id, $this->namespace);
    }

    /**
     * Filter request data and load the query if request provides a query ID. We
     * are following the Apollo draft spec for automatic persisted queries. See:
     *
     * https://github.com/apollographql/apollo-link-persisted-queries#automatic-persisted-queries
     *
     * @param  array $request_data Request data from WPHelper
     * @return array
     * @throws UserError           Caught and handled by WPGraphQL
     */
    public function process_request( $request_data ) {
        $has_query = ! empty( $request_data['query'] );
        $has_query_id = ! empty( $request_data['queryId'] );

        // Query IDs are case-insensitive.
        $query_id = $has_query_id ? strtolower( $request_data['queryId'] ) : null;

        // Client sends queryId but *not* query == optimistic request to use
        // persisted query.
        if ( $has_query_id && ! $has_query ) {
            $persisted = $this->load( $query_id );

            // If the query is empty, that means it has not been persisted.
            if ( empty( $persisted['query'] ) ) {
                throw new UserError( $this->error_message );
            }

            $request_data['query_validated'] = $persisted['query_validated'];
            $request_data['queryid'] = $query_id; // Tap into persistent query loader from graphql-php
        } else {
            $this->persist_query_ids[] = $query_id;
        }

        // We've got this. Call off any other persistence implementations.
        unset( $request_data['queryId'] );

        return $request_data;
    }

    public function process_response( $response, $result, $operation_name, $query) {
        if ($query_id = array_pop($this->persist_query_ids)) {
            $this->save( $query_id, $query,  empty($response->errors), $operation_name );
        }
    }

    public function graphql_server_config( ServerConfig $server, OperationParams $params) {
        $server->setPersistentQueryLoader(function (string $query_id) {
            return $this->load( $query_id )['query'] ?? null;
        });

        if ($params->getOriginalInput('query_validated')) {
            $server->setValidationRules([]);
        }
    }

    /**
     * Save (persist) a query.
     *
     * @param  string $query_id Query ID
     * @param  string $query    GraphQL query
     * @param  string $name     Operation name
     * @return void
     */
    private function save( $query_id, $query, $is_validated, $name = 'UnnamedQuery' ) {
        wp_cache_add( VERSION . ':' . $query_id, [
            'query' => Parser::parse($query),
            'query_validated'   => $is_validated,
            'name' => $name
        ], $this->namespace );
    }
}
