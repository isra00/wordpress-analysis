<?php

error_reporting(E_ALL);
ini_set("display_errors", true);

/******************************************************* CONFIG *******************************************************/

define('WORDPRESS_HOME', ''); //Blog home URL, for example, http://israelviana.es/. It MUST include trailing slash.
define('TTL', 3600); //Time for cache

//Config for Google Analytics
define('GA_EMAIL', '');
define('GA_PASSWORD', '');
define('GA_PROFILE_ID', '');
define('GA_DATE_FROM', '2012-04-01'); //Starting date for GA reports in format YYYY-MM-DD. Put your first post's publish date.

//Config for Twitter
define('TWITTER_ACCESS_TOKEN', '');
define('TWITTER_ACCESS_TOKEN_SECRET', '');
define('TWITTER_CONSUMER_KEY', '');
define('TWITTER_CONSUMER_SECRET', '');

//Config for Wordpress database
define('MYSQL_HOST', 'localhost');
define('MYSQL_USER', '');
define('MYSQL_PASSWORD', '');
define('MYSQL_DATABASE', '');
define('MYSQL_PREFIX', 'wp_');

/************************ END CONFIG. DO NOT TOUCH BELOW IF YOU DON'T KNOW WHAT YOU ARE DOING. ************************/

/*
 * Load libraries
 */

require 'gapi.class.php';
$ga = new gapi(GA_EMAIL, GA_PASSWORD);

require_once('TwitterAPIExchange.php');
$twitter = new TwitterAPIExchange(array(
	'oauth_access_token'		=> TWITTER_ACCESS_TOKEN,
	'oauth_access_token_secret'	=> TWITTER_ACCESS_TOKEN_SECRET,
	'consumer_key'				=> TWITTER_CONSUMER_KEY,
	'consumer_secret'			=> TWITTER_CONSUMER_SECRET
));

/*************************************************** API FUNCTIONS ****************************************************/

/**
 * Wrapper for file_get_contents caching results in APC.
 *
 * @param string $file File path. Can use any file wrapper.
 * @return string File contents.
 */
function cached_file_get_contents($file)
{
	$key = "post_analysis_$file";
	if (!$val = apc_fetch($key))
	{
		apc_add($key, $val = file_get_contents($file), TTL);
	}
	
	return $val;
}

/**
 * Get the # of Analytics pageviews for a given URL from GA_DATE_FROM to today. Includes caching in APC.
 *
 * @param string $page Page relative URL (for example, /contact).
 * @return int Number of page views.
 */
function cached_gapi_pageviews_page($page)
{
	global $ga;
	
	$key = "post_analysis_ga_$page";
	if (!($val = apc_fetch($key)))
	{
		/**
		 * IMPORTANTE! Para que la consulta sin dimensiones funcione se ha modificado la gapi.class con el hack
		 * @url https://code.google.com/p/gapi-google-analytics-php-interface/issues/detail?id=13#c4
		 */
		$ga->requestReportData(GA_PROFILE_ID, null, array('pageviews'), null, "pagePath =~ /$page/.*", GA_DATE_FROM, date('Y-m-d'));
		$ga_results = $ga->getResults();
		$metrics = $ga_results[0]->getMetrics();
		$val = $metrics['pageviews'];
		apc_store($key, $val, TTL);
	}
	
	return $val;
}

/**
 * Get the # of tweets including a given URL. Includes caching in APC.
 *
 * @param string $absolute_url The URL to look for in Twitter
 * @return int Number of tweets that shared the URL in the last 6-9 days.
 * @see https://dev.twitter.com/docs/using-search
 */
function cached_twitter_search_links($absolute_url)
{
	global $twitter;

	$key = "post_analysis_twitter_$absolute_url";
	if (!($val = apc_fetch($key)))
	{
		$resp = json_decode($twitter->setGetfield('?q=' . urlencode($absolute_url))
						 ->buildOauth('https://api.twitter.com/1.1/search/tweets.json', 'GET')
						 ->performRequest());
		$val = count($resp->statuses);
		apc_store($key, $val, TTL);
	}
	
	return $val;
}

/******************************************************* MAIN *********************************************************/

mysql_connect(MYSQL_HOST, MYSQL_USER, MYSQL_PASSWORD);
mysql_select_db(MYSQL_DATABASE);

$prefix = MYSQL_PREFIX;
$sql = <<<SQL
SELECT 
	post_title, DATE(post_date) post_date, display_name, post_name, comment_count, (select max(comment_count) from {$prefix}posts) comment_max
FROM 
	{$prefix}posts
JOIN
	{$prefix}users ON {$prefix}posts.post_author = {$prefix}users.ID
WHERE
	post_status = 'publish'
	AND post_type = 'post'
SQL;

$res = mysql_query($sql);

$posts = array();
$fb_shares_max = 0;
$plusone_max = 0;
$pageviews_max = 0;
$twitter_max = 0;
while ($row = mysql_fetch_assoc($res))
{
	$social = array();
	$absolute_url = WORDPRESS_HOME . $row['post_name'];
	
	$fb_json = json_decode(cached_file_get_contents("http://graph.facebook.com/$absolute_url"));
	$social['fb_shares'] = isset($fb_json->shares) ? $fb_json->shares : 0;
	
	$plusone_html = cached_file_get_contents('https://plusone.google.com/_/+1/fastbutton?url=' . urlencode($absolute_url . '/'));
	$match = array();
	preg_match('/\<div id=\"aggregateCount\" class=\"V1\"\>([0-9]+)\<\/div\>/', str_replace("\n", '', $plusone_html), $match);
	$social['plusone'] = isset($match[1]) ? $match[1] : '?';
	
	$social['pageviews'] = cached_gapi_pageviews_page($row['post_name']);
	
	$social['twitter'] = cached_twitter_search_links($absolute_url);
	
	$posts[] = array_merge($row, $social );
	
	$fb_shares_max = $social['fb_shares'] > $fb_shares_max ? $social['fb_shares'] : $fb_shares_max;
	$plusone_max = $social['plusone'] > $plusone_max ? $social['plusone'] : $plusone_max;
	$pageviews_max = $social['pageviews'] > $pageviews_max ? $social['pageviews'] : $pageviews_max;
	$twitter_max = $social['twitter'] > $twitter_max ? $social['twitter'] : $twitter_max;
}

?>
<!DOCTYPE html>
<!--[if lt IE 7]>      <html class="no-js lt-ie9 lt-ie8 lt-ie7"> <![endif]-->
<!--[if IE 7]>         <html class="no-js lt-ie9 lt-ie8"> <![endif]-->
<!--[if IE 8]>         <html class="no-js lt-ie9"> <![endif]-->
<!--[if gt IE 8]><!--> <html class="no-js"> <!--<![endif]-->
<head>
  <meta charset="utf-8">
  <meta http-equiv="X-UA-Compatible" content="IE=edge,chrome=1">
  <title>Análisis de posts</title>
  <meta name="description" content="">
  <meta name="viewport" content="width=device-width">
  <link href="//netdna.bootstrapcdn.com/twitter-bootstrap/2.3.2/css/bootstrap-combined.min.css" rel="stylesheet">
  <link href="//netdna.bootstrapcdn.com/font-awesome/3.2.0/css/font-awesome.min.css" rel="stylesheet">
  <style>
    body { margin: 1em; }
    .table th { cursor: pointer; }
    .table th span { display: inline-block; padding-right: 1.25em; } /* Espacio reservado para las flechas de ordenación */
    th.headerSortDown span, th.headerSortUp span { padding-right: 0; }
    .headerSortDown span:after { content: " ▼"; }
    .headerSortUp span:after { content: " ▲"; }
    td { position: relative; }
    .meter-value { font-weight: bold; text-shadow: 1px 1px 1px white; padding-left: .2em; position: absolute; }
    meter { height: 1.3em; }
  </style>
</head>
<body>

<header class="page-header">
  <h1>Análisis de posts</h1>
  <p>Los datos se actualizan cada <?php echo TTL / 60 ?> minutos. Algunos datos de FB share en posts anteriores a abril de 2013 se pueden haber perdido debido al cambio en la página.</p>
  <p>Solo se procesan los tuits creados en los últimos 7 días (<a href="https://dev.twitter.com/docs/using-search" target="_blank">más info</a>)</p>
</header>

<table class="table table-hover">
  <thead><tr><th><span>Post</span></th><th><span>Pageviews</span></th><th><span>FB shares</span></th><th><span>+1</span></th><th><span>Twitter</span></th><th><span>Comnts</span></th><th><span>Fecha</span></th><th><span>Autor</span></th></tr></thead>
  <tbody>
<?php foreach ($posts as $p) : ?>
    <tr>
    <td><a href="http://jmjrio2013.info/<?php echo $p['post_name'] ?>"><?php echo utf8_encode($p['post_title']) ?></a></a></td>
    <td>
      <span class="meter-value"><?php echo intval($p['pageviews']) ?></span>
      <meter min="0" max="<?php echo $pageviews_max ?>" value="<?php echo intval($p['pageviews']) ?>"></meter>
    </td>
    <td>
      <span class="meter-value"><?php echo intval($p['fb_shares']) ?></span>
      <meter min="0" max="<?php echo $fb_shares_max ?>" value="<?php echo intval($p['fb_shares']) ?>"></meter>
    </td>
    <td>
      <span class="meter-value"><?php echo intval($p['plusone']) ?></span>
      <meter min="0" max="<?php echo $plusone_max ?>" value="<?php echo intval($p['plusone']) ?>"></meter>
    </td>
    <td>
      <span class="meter-value"><?php echo intval($p['twitter']) ?></span>
      <meter min="0" max="<?php echo $twitter_max ?>" value="<?php echo intval($p['twitter']) ?>"></meter>
    </td>
    <td>
      <span class="meter-value"><?php echo intval($p['comment_count']) ?></span>
      <meter min="0" max="<?php echo $p['comment_max'] ?>" value="<?php echo intval($p['comment_count']) ?>"></meter></td>
    <td><?php echo $p['post_date'] ?></td>
    <td><?php echo utf8_encode($p['display_name']) ?></td>
  </tr>
<?php endforeach ?>
  </tbody>
</table>
  
<script src="http://code.jquery.com/jquery-1.10.1.min.js"></script>
<script src="http://tablesorter.com/__jquery.tablesorter.min.js" type="text/javascript"></script>
<script type="text/javascript">
$(function() {
  $("table").tablesorter();
});
</script>

</body>
</html>
