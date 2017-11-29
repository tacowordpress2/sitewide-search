# Taco SiteWide Search
Requires TacoWordPress 2

**Note:** This plugin supports the `getHumanDate()` field on Taco posts.  If this is defined, then the displayed date format in the search results will reflect this in each post type that has this function defined on it.  This is to facilitate cleaner handling of date formats, and also allows the date format to be different across different post types.

## General Info
This plugin allows for an quick and easy way to include a faceted sitewide search on any WordPress site using Taco.

## Plugin Configuration
### Installation
Drop this in the plugin directory, and create a `SiteWideSearch.php` in the root of the sitewide-search directory.  Use `SiteWideSearchInstance.php.sample` as a basis for this.  This is not the ideal place to put this file, but until there's a better place for it, this is where it needs to go.

### Post Type Specification and Weights
`SiteWideSearchInstance.php` only needs to implement one method, `getSearchableFields()`.  This function should return an array of arrays.  The keys of the top level array should be keyed by each post type that should be searchable.  Each one of these arrays should contain entries keyed by the field, and having the value be how much to weight the item.  Possible fiels are `post_content`, `post_title`, `meta_fields`, and `terms`.  In general, these should be fairly consistent across all content types, otherwise you'll end up with certain content types always being weighted more than other content types.

The `meta_fields` field is a special case, as it can include yet another nested array corresponding to which meta fields are included in the sitewide search.  Additionally, when dealing with AddMany fields, you can specify meta fields by separating the Add Many field name and the internal Add Many field with 2 colons.  For example, to get the `title` field within the `panel_grid_panels` Add Many field, specify the field `panel_grid_panels::title`.

In the following example, the Site Wide Search would search on the Page and Post content types, with the post title being weighted 4 times as heavily as any other field.  Additionally, the Page post type would search on the panel_grid_panels Add Many field on both the title and caption.

```
  public static function getSearchableFields() {
    return array(
      'page' => array(
        'post_content' => 1,
        'post_title' => 4,
        'terms' => 1,
        'meta_fields' => [
          'panel_grid_panels::title' => 1,
          'panel_grid_panels::caption' => 1,
        ],
      ),
      'post' => array(
        'post_title' => 4,
        'post_content' => 1,
        'terms' => 1,
      ),
    );
  }
```

See `SiteWideSearchInstance.php.sample` for a more comprehensive example.

### Modifying Data Before Return
You can modify the `getJSONFields()` function in `SiteWideSearchInstance.php` to process data before returning data to the front end.

In the following example, the post_date is unset for several post types so it will not get displayed on the front end:

```
  public static function getJSONFields($results) {
    $results = array_map(function($result) {
      switch ($result['post_type']) {
        case 'page':
        case 'board-member':
        case 'team-member':
        case 'fellow':
        case 'leader':
          $result['post_date'] = '';
          break;
      }

      return $result;
    }, parent::getJSONFields($results));

    return $results;
  }
```

### Choosing which fields to return
By default, all possible fields are returned in the JSON results.  You can override `getAllowedJSONFields()` to restrict which fields are returned

## Querying Data
The plugin ships with a convenient JSON endpoint to query data against.  To query data, simply hit the endpoint `/sitewide-search-results/` with the following query parameters:

* `sitewide-search-keyword`: **Required** - the keywords to use for the search
* `sitewide-search-posttype`: The post type to search against.  Leave blank to get all post types
* `sitewide-search-perpage`: Number of results per page.  Default is 10.  You can override the constant * `SEARCH_RESULTS_PER_PAGE` in `SiteWideSearchInstance.php` to override the default
* `sitewide-search-pagenum`: The number of the page results to display.  Default is 1.
* `sitewide-search-orderby`: What criteria to sort results by.  This can be any value that is returned by `getAllowedJSONFields()` plus `score`, which will order by relevance.  Default is `score`
* `sitewide-search-order`: Whether to sort results in ascending or descending order.  Accepts `asc` or `desc`.  Default is `desc`

The data will be returned in the following JSON format:
```
{
  "keyword": A repeat of the keyword searched on
  "results": [
    Array containing the current page of results and all fields requested in getAllowedJSONFields()
  ],
  "result_counts": [
    The number of results of each post type.  Includes post types with 0 results.
  ]
  "total_results": The total number of results
  "selected_post_type": The post type queried.  If blank, the results contain all post types.
}
```

## Alternative Querying Method
`SiteWideSearchInstance::getJSONSearchResults()` can be called directly too in case you don't want to consume the JSON API using AJAX.  In this case, simply call the method in the same way as it's called in `search-results-json.php`, but do something with the data there instead of echoing it out.

`SiteWideSearchInstance::getSearchResults()` can also be called directly with the same arguments as `SiteWideSearchInstance::getJSONSearchResults()`, but this is not recommended since you will lose a lot of the metadata that the JSON results contains.

## Data Validity
Search data is updated automatically when posts are saved.  However, if the search configuration has changed or if new data has been imported without going through the standard WordPress interface, simply deactivate and reactivate the plugin.  Please note that reactivating the plugin is a fairly expensive operation though, and should not be done at peak traffic times.