<?php
Abstract Class SiteWideSearch {

  const TABLE_NAME = 'sitewidesearch';
  const SEARCH_RESULTS_PER_PAGE = 10;
  const FEATURED_IMAGE_SIZE = 'medium';
  const NUM_META_FIELDS = 10; // You shouldn't really need to modify this unless there's a LOT of meta fields

  /**
   * Get only the fields specified in the getAllowedJSONFields method
   * @param collection $results
   * @return array
   */
  protected static function getJSONFields($results) {

    $child_class = get_called_class();
    $json_fields = $child_class::getAllowedJSONFields();
    $array_filtered_fields = array();
    $inc = 0;
    foreach($results as $result) {
      $filtered_fields = [];

      foreach($json_fields as $field) {
        switch($field) {
          case 'permalink':
            $filtered_fields[$field] = $result->getPermalink();
            break;
          case 'search_excerpt':
            $filtered_fields[$field] = $result->getTheExcerpt();
            break;
          case 'post_date':
            if (method_exists($result, 'getHumanDate')) {
              $filtered_fields[$field] = $result->getHumanDate();
            } else {
              $filtered_fields[$field] = $result->$field;
            }
            break;
          case 'post_title':
            $filtered_fields[$field] = $result->getTheTitle();
            break;
          case 'featured_image':
            $filtered_fields[$field] = !empty($result->photo) ? app_image_path($result->photo, static::FEATURED_IMAGE_SIZE) : app_image_path($result->getPostAttachmentURL(), 'medium');
            break;
          default:
            $filtered_fields[$field] = $result->$field;
            break;
        }
      }

      $array_filtered_fields[] = $filtered_fields;
    }
    return $array_filtered_fields;
  }


  /**
   * Regenerates teh search table
   */
  public static function regenerateSearchTable($from_scratch=false) {
    if($from_scratch) {
      static::regenerateSearchTableFromScratch();
    }
    global $wpdb;
    $records = $wpdb->get_results("SELECT * FROM sitewidesearch");
    foreach($records as $r) {
      static::postModified($r->post_id, true);
    }
  }


  /**
   * WARNING - This is expensive (use a cron job after hours)
   */
  public static function regenerateSearchTableFromScratch() {
    global $wpdb;
    $post_types = static::getPostTypes();
    unset($post_types['default']);

    $post_types = join(
      ',',
      array_map(function($s) { return "'$s'"; }, $post_types)
    );

    $query = sprintf(
      "SELECT ID FROM %sposts
      WHERE post_type IN (%s)
      AND post_status = 'publish'
      ",
      $wpdb->prefix,
      $post_types
    );

    foreach($wpdb->get_results($query, OBJECT) as $post) {
      static::postModified($post->ID, true);
    }
  }


  /**
   * Installs and populates the search table
   */
  public static function installSearchTable() {
    global $wpdb;

    $table_name = static::TABLE_NAME;
    $test_string = sprintf("show tables like '%s'", $table_name);

    if($wpdb->get_var($test_string) ==  $table_name) return;

    $sql = "CREATE TABLE $table_name (
        `post_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        `post_type` text,
        `term_ids` longtext,
        `post_title` text,
        `post_content` longtext,
        `post_name` varchar(200),
        `post_date` DATETIME,
        `terms` longtext,
        `taxonomies` longtext,";

    // Generate the right number of meta fields
    foreach(range(0, static::NUM_META_FIELDS - 1) as $meta_field) {
      $sql .= "
        `meta_$meta_field` longtext,";
    }

    $sql .= "
      PRIMARY KEY (`post_id`)
    ) ENGINE=MyISAM;";

    $wpdb->query($sql);

    static::regenerateSearchTable(true);

    // Add full text indexes AFTER inserting data, because it's faster
    $full_text_fields = ['post_title', 'post_content', 'post_name', 'terms', 'taxonomies'];
    foreach(range(0, static::NUM_META_FIELDS - 1) as $meta_field) {
      $full_text_fields[] = 'meta_' . $meta_field;
    }

    $full_text_fields_alters = array_map(function($field) {
      return "ADD FULLTEXT {$field}_fulltext ($field)";
    }, $full_text_fields);

    $sql = "ALTER TABLE $table_name ";
    $sql .= implode(",\n", $full_text_fields_alters);

    $wpdb->query($sql);
  }

  public static function watchPostStatuses() {
    $child_class = get_called_class();
    $post_types = array_filter(
      array_keys($child_class::getSearchableFields())
    );

    $post_statuses = array(
      'new',
      'publish',
      'pending',
      'draft',
      'auto-draft',
      'future',
      'private',
      'inherit',
      'trash',
    );

    foreach($post_types as $post_type) {
      foreach($post_statuses as $status) {
        add_action(
          sprintf('%s_%s', $status, $post_type),
          sprintf('%s::postModified', $child_class)
        );
      }
    }
  }

  /**
   * Drop search table
   */
  public static function uninstallSearchTable() {
    global $wpdb;
    $table_name = static::TABLE_NAME;
    $sql = "DROP TABLE $table_name";

    $wpdb->query($sql);
  }

  /**
   * Replace all special characters with a space
   *
   * @param string $str
   * @return string
   */
  public static function stripSpecialCharacters($str) {
    return preg_replace('/[^\p{L}\p{N}_]+/u', ' ', $str);;
  }


  public function postModified($post_id, $force_update=false) {
    global $wpdb;
    $table_name = static::$table_name;

    // Grab the post
    if (empty($post_id)) {
      return;
    }

    $post = get_post($post_id);

    // Check if the post is included in sitewide search
    if (!array_key_exists($post->post_type, static::getSearchableFields())) {
      return;
    }

    $taco_post = \Taco\Post\Factory::create($post_id);

    // Post was deleted
    if(in_array($taco_post->post_status, array('trash', 'pending', 'private', 'draft', 'auto-draft', 'inherit'))
      && !$force_update) {
      return static::postDeleted($post_id);
    }

    $post_type_fields = static::getSearchableFields()[$taco_post->post_type];

    // Grab taxonomies and terms
    $tax_terms = wp_get_post_terms($taco_post->ID, get_taxonomies());
    $term_ids = join(', ', Collection::pluck($tax_terms, 'term_id'));
    $term_names = join(', ', Collection::pluck($tax_terms, 'name'));
    $taxonomies = join(', ', array_unique(Collection::pluck($tax_terms, 'taxonomy')));

    // Split leaders out into leaders and fellows.  This should probably be in the child class,
    // but I'm just putting it in here for now
    $post_type = $taco_post->post_type;
    if ($taco_post->post_type === 'fellow') {
      $post_type = $taco_post->getFellowType();
    }


    $data = [
        'post_id' => $taco_post->ID,
        'post_type' => $post_type,
        'post_title' => $taco_post->post_title,
        'post_name' => $taco_post->post_name,
        'post_content' => $taco_post->post_content,
        'post_date' => $taco_post->post_date,
        'terms' => $term_names,
        'term_ids' => $term_ids,
        'taxonomies' => $taxonomies,
    ];

    // Handle meta values
    if (Arr::iterable($post_type_fields['meta_fields'])) {
      // Since the key of the field is the field name, keep a count here
      $meta_index = 0;

      foreach ($post_type_fields['meta_fields'] as $meta_field => $meta_weight) {
        // Handle addmany fields
        if (strpos($meta_field, '::') !== -1) {
          $meta_field_parts = explode('::', $meta_field);

          if (Arr::iterable($taco_post->{$meta_field_parts[0]})) {
            $meta_values = array_map(function($subpost) use ($meta_field_parts) {
              return $subpost->{$meta_field_parts[1]};
            }, $taco_post->{$meta_field_parts[0]});

            $data['meta_' . $meta_index] = implode(' ', $meta_values);
          }
        } else {
          $data['meta_' . $meta_index] = $taco_post->$meta_field;
        }

        $meta_index++;
      }
    }

    // Strip special characters
    foreach ($data as $index => $datum) {
      if ($index !== 'post_type' && $index !== 'post_date') {
        $data[$index] = static::stripSpecialCharacters($datum);
      }
    }

    $row_exists = $wpdb->query(
      sprintf(
        "SELECT post_id from sitewidesearch where post_id = %d",
        $taco_post->ID
      )
    );

    if ($row_exists) {
      $wpdb->update(
        $table_name,
        $data,
        array('post_id' => $taco_post->ID)
      );
    } else {
      $wpdb->insert(
        $table_name,
        $data
      );
    }
  }


  /**
   *
   */
  public function postDeleted($id) {
    global $wpdb;

    $wpdb->delete('sitewidesearch', ['post_id' => $id]);
  }


  /**
   * Get a joined array of post types (will eventually allow for fields and points)
   * @param array $array
   * @return string
   */
  private static function getQueryFromCriteria($array) {
    $array_post_types = array();
    foreach($array as $post_type) {
      $array_post_types[] = "'$post_type'";
    }
    return join(",", $array_post_types);
  }


  /**
   * Get Taco Posts from an array of ids
   * @param array $array_ids
   * @return collection
   */
  private static function convertToTacoPosts($array_ids) {
    return \Taco\Post\Factory::createMultiple($array_ids);
  }


  /**
   * Set and get default ranking fields here
   * @return array
   */
  public function getDefaultRankingFields() {
    return array(
      'post_title'   => 5,
      'post_name' => 4,
      'post_content' => 1,
      'terms' => 3
    );
  }


  /**
   * Get an array of field => values without post_types
   * @return array
   */
  public static function getAllFields() {
    $child_class = get_called_class();
    $post_type_fields = $child_class::getSearchableFields();
    $new_array = array();
    foreach($post_type_fields as $post_type => $fields) {
      foreach($fields as $k => $v) {
        $new_array[$k] = $v;
      }
    }
    return $new_array;
  }


  /**
   * Get an associative array of key (field name) => value (points) fields
   * @return array
   */
  public function getRankingFields() {
    $default_fields = static::getDefaultRankingFields();
    $intersected = array_intersect_key(static::getAllFields(), $default_fields);
    return ($intersected + $default_fields);
  }


  /**
   * Construct a query part for post fields
   * @param string $keywords
   * @return array
   */
  private function constructPostFieldsQuery($keywords) {
    $query = array();
    $field_points = static::getRankingFields();
    foreach($field_points as $key => $points) {
      $query[] = sprintf(
        "(%d * (MATCH (%s) AGAINST ('%s' IN BOOLEAN MODE))) \r\n",
        $points,
        $key,
        $keywords
      );
    }
    return join(' + ', $query);
  }


  /**
   * Unset default fields from array of fields
   * @param array $fields
   * @return array
   */
  private static function unsetDefaults($post_type_fields) {
    $default_fields = static::getDefaultRankingFields();
    foreach($default_fields as $k => $v) {
      foreach($post_type_fields as $post_type => $fields) {
        if(!Arr::iterable($post_type_fields[$post_type])) continue;
        unset($post_type_fields[$post_type][$k]);
      }
    }
    return $post_type_fields;
  }


  /**
   * Construct query parts for postmeta
   * @param string $keywords
   * @return array
   */
  private static function constructPostMetaQuery($keywords) {
    $child_class = get_called_class();
    $post_type_fields = static::unsetDefaults($child_class::getSearchableFields());
    $query_1 = array();
    $query_2 = array();
    $inc = 0;
    global $wpdb;
    $wp_db_prefix = $wpdb->prefix;
    foreach($post_type_fields as $post_type => $fields) {
      $post_type_query = (strlen($post_type))
          ? sprintf("and post_type = '%s'", $post_type)
          : '';

      foreach($fields as $key => $points) {
        $field_key_query =  sprintf("AND pm%d.meta_key = '%s'", $inc, $key);
        if(!$post_type && !in_array($key, array_keys(static::getDefaultRankingFields()))) {
          throw new Exception("Fields defined must be a default field or belong to a custom post type.", 1);
        }
        $query_1[] = sprintf(
          "(%d * (MATCH (pm%d.meta_value) AGAINST ('%s' IN BOOLEAN MODE))) \r\n",
          $points,
          $inc,
          $keywords
        );
        $query_2[] = sprintf(
          "LEFT JOIN %spostmeta AS pm%d ON pm%d.post_id = %sposts.ID %s %s \r\n",
          $wp_db_prefix,
          $inc,
          $inc,
          $wp_db_prefix,
          $post_type_query,
          $field_key_query
        );
        $inc++;
      }
    }
    return array(
      join(' + ', $query_1),
      join($query_2)
    );
  }


  /**
   * Returns the post_types defined in getSearchableFields()
   * @return array
   */
  public static function getPostTypes() {
    $child_class = get_called_class();
    $searchable_fields = $child_class::getSearchableFields();
    unset($searchable_fields['default']);
    return array_filter(array_keys($searchable_fields));
  }

  private static function getSingleFieldQuery($keywords = '', $field, $value) {
    return sprintf(
      "+ (%d * (MATCH (%s) AGAINST ('%s' IN BOOLEAN MODE)))",
      $value,
      $field,
      $keywords
    );
  }


  /**
   * Helper function to get the query including all post point values
   *
   * @param string $keywords - string of keywords
   * @param [type] $type_values - array of type values defined in the SiteWideSearch instance
   * @param boolean $boolean - whether or not this is a boolean query
   * @return void
   */
  private static function getPostTypePointsQuery($keywords='', $type_values, $boolean = false) {
    $query = array();
    foreach($type_values as $field => $value) {
      if ($field === 'meta_fields' && Arr::iterable($value)) {
        // Keep track of which meta value we're on
        $meta_field_index = 0;
        foreach ($value as $meta_field => $meta_field_value) {
          $query[] = static::getSingleFieldQuery($keywords, 'meta_' . $meta_field_index, $meta_field_value);
          $meta_field_index++;
        }
      } else {
        $query[] = static::getSingleFieldQuery($keywords, $field, $value);
      }
    }

    // Do this so the query starts with 0 +
    $post_type_query = '(0 ' . join("\r\n ", $query) . ')';

    // Just check if the result is greater than 0 to do a boolean query
    if ($boolean) {
      $post_type_query .= ' > 0';
    }

    return $post_type_query;
  }



  /**
   * Helper function search on taxonomy term
   *
   * @param [string] $terms - string of term to search on
   * @return string
   */
  private static function getTermsQuery($terms) {
    $query = array();
    foreach($terms as $term_id) {
      $query[] = sprintf('OR locate(%d, term_ids)', $term_id);
    }
    return join("\r\n ", $query);
  }


  /**
   * Helper function to build query to grab the full text query
   *
   * @param [string] $keywords - string of keywords
   * @param boolean $boolean - whether or not to search in boolean mode.  If not specified, this searches in natrual language mode.
   * @return string
   */
  private static function getFullTextQuery($keywords, $boolean = false) {

    if(!strlen($keywords)) return '';

    $child_class = get_called_class();
    $post_type_field_values = $child_class::getSearchableFields();
    $query[] = 'CASE';
    foreach($post_type_field_values as $post_type => $post_type_values) {

      if($post_type === 'default' || !Arr::iterable($post_type_values)) {
        continue;
      }

      $query[] = sprintf('WHEN post_type=\'%s\' then', $post_type);
      $query[] = static::getPostTypePointsQuery($keywords, $post_type_values, $boolean);
    }
    $query[] = 'ELSE 0';
    $query[] = 'END as score';

    return join("\r\n ", $query);
  }

  /**
   * Helper function to build query to select results by post type
   *
   * @param [string] $post_type - the post type to search on.  Leave blank to search all post types
   * @return string
   */
  private static function getPostTypeQuery($post_type = '') {
    if (empty($post_type)) {
      $post_types = static::getPostTypes();
      $post_type_query = "post_type IN ('" . implode("','", $post_types) . "')";
    } else {
      $post_type_query = "post_type = '$post_type'";
    }

    return $post_type_query;
  }

  /**
   * Get result counts grouped by post type.  Add them up to get full results count
   *
   * @param [string] $keywords - string of keywords
   * @return array of result counds grouped by post type
   */
  public static function getResultCounts($keywords) {
    global $wpdb;

    $keywords = self::parseKeywords($keywords);

    // This query gets all post types with results and their associated count
    $query = sprintf(
      "SELECT
      post_type,
      COUNT(*) as result_count,
      %s
      FROM %s
      WHERE %s
      GROUP BY post_type, score
      HAVING score > 0
      ORDER BY score ASC",
      static::getFullTextQuery($keywords, true),
      static::TABLE_NAME,
      static::getPostTypeQuery()
    );

    $results = $wpdb->get_results($query);

    // Initialize all post counts to 0, then set any found results
    $result_counts = [];
    foreach(static::getPostTypes() as $post_type) {
      $result_counts[$post_type] = 0;
    }

    foreach ($results as $result) {
      $result_counts[$result->post_type] = (int) $result->result_count;
    }

    return $result_counts;
  }


  /**
   * Handle escaped keywords and quotes
   */
  public static function parseKeywords($keywords) {
    $keywords = stripslashes($keywords);
    $keywords = self::stripSpecialCharacters($keywords);
    if ($keywords[0] !== '"' || $keywords[strlen($keywords) - 1] !== '"') {
      $keywords = $keywords . '*';
    }

    $keywords = esc_sql($keywords);

    return $keywords;
  }

  /**
   * Specify which fields are returned by the search
   */
  public static function getAllowedJSONFields() {
    return array(
      'post_title',
      'search_excerpt',
      'post_date',
      'post_type',
      'permalink',
      'featured_image',
      'ID'
    );
  }

  /**
   * Get search results as Taco posts
   *
   * @param [string] $keywords - string of keywords
   * @param array $args - see default args for options
   * @return array of Taco Posts
   */
  public static function getSearchResults($keywords, $args = []) {
    $default_args = [
      'perpage'     => static::SEARCH_RESULTS_PER_PAGE,
      'offset'    => 0,
      'post_type' => '',
      'orderby'   => 'score',
      'order'     => 'DESC',
    ];

    $args = array_merge(
      $default_args,
      $args
    );

    global $wpdb;

    $keywords = self::parseKeywords($keywords);

    foreach ($args as $index => $arg) {
      $args[$index] = esc_sql($arg);
    }

    $query = sprintf(
      "SELECT
      post_id,
      %s
      FROM %s
      WHERE %s
      HAVING score > 0
      ORDER BY %s %s
      LIMIT %d, %d",
      static::getFullTextQuery($keywords),
      static::TABLE_NAME,
      static::getPostTypeQuery($args['post_type']),
      $args['orderby'], $args['order'],
      $args['offset'], $args['perpage']
    );

    $results = $wpdb->get_results($query);

    if(!Arr::iterable($results)) {
      return [];
    }

    $results_taco = static::convertToTacoPosts(Collection::pluck($results, 'post_id'));

    return $results_taco;
  }


  /**
   * Install search table and rewrite rules when activating plugin
   */
  public static function activatePlugin() {
    global $wp_rewrite;
    $wp_rewrite->flush_rules(true);

    static::installSearchTable();
  }

  /**
   * Uninstall search table and rewrite rules when activating plugin
   */
  public static function deactivatePlugin() {
    global $wp_rewrite;
    $wp_rewrite->flush_rules(true);

    static::uninstallSearchTable();
  }

  /**
   * Get nicely formatted search results.  Use the same results as getSearchResults
   */
  public static function getJSONSearchResults($keywords, $args = []) {
    $results = static::getSearchResults($keywords, $args);
    $result_counts = static::getResultCounts($keywords);

    return json_encode([
      'keyword' => stripslashes($keywords),
      'results' => static::getJSONFields($results),
      'result_counts' => $result_counts,
      'total_results' => array_sum($result_counts),
      'selected_post_type' => $args['post_type'],
    ]);
  }

  /**
   * Add sitewide-search-results to WordPress
   */
  public static function addRewrite() {
    add_rewrite_rule('^sitewide-search-results(.*)$', 'index.php?sitewide-search=1&$matches[1]');

    global $wp_rewrite;
    $wp_rewrite->flush_rules(true);
  }

  /**
   * This is necessary for the nice URL in WordPress
   */
  public static function addQueryVars($vars) {
    $vars[] = 'sitewide-search';
    return $vars;
  }

  /**
   * This does the actual redirect from the pretty WordPress URL and search results page
   */
  public static function catchRedirect() {
    global $wp_query;

    if (array_key_exists('sitewide-search', $wp_query->query_vars)) {
      include WP_PLUGIN_DIR . '/sitewide-search/search-results-json.php';
      exit;
    }
  }
}
