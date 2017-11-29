<?php
header('Content-Type: application/json');

require_once __DIR__ . '/SiteWideSearchInstance.php';

$keyword = !empty($_GET['sitewide-search-keyword']) ? $_GET['sitewide-search-keyword'] : '';
$post_type  = !empty($_GET['sitewide-search-posttype']) ? $_GET['sitewide-search-posttype'] : '';
$per_page = !empty($_GET['sitewide-search-perpage']) ? $_GET['sitewide-search-perpage'] : SiteWideSearchInstance::SEARCH_RESULTS_PER_PAGE;
$current_page = !empty($_GET['sitewide-search-pagenum']) ? (int) $_GET['sitewide-search-pagenum'] : 1;
$order_by = !empty($_GET['sitewide-search-orderby']) ? $_GET['sitewide-search-orderby'] : 'score';
$order = !empty($_GET['sitewide-search-order']) ? $_GET['sitewide-search-order'] : 'desc';

$args = [
  'post_type' => $post_type,
  'perpage'    => $per_page,
  'offset'    => ($current_page - 1) * SEARCH_ITEMS_PER_PAGE,
  'order'     => $order,
  'orderby'   => $order_by,
];


echo SiteWideSearchInstance::getJSONSearchResults($keyword, $args);