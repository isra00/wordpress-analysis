<?php

/** BEGIN CONFIG */

define('MYSQL_HOST', 'localhost');
define('MYSQL_USER', 'root');
define('MYSQL_PASSWORD', 'root');
define('MYSQL_DATABASE', 'rio13');

ini_set("display_errors", true);
error_reporting(E_ALL);

/* END CONFIG */

/* Functions */

function cached_file_get_contents($file)
{
  $key = "post_analysis_$file";
  if (!$val = apc_fetch($key))
  {
    apc_add($key, $val = file_get_contents($file), 60*10); //TTL 10min
  }
  
  return $val;
}

/* Bootstrap */

mysql_connect(MYSQL_HOST, MYSQL_USER, MYSQL_PASSWORD);
mysql_select_db(MYSQL_DATABASE);

$q_siteurl = mysql_query("SELECT option_value FROM wp_options WHERE option_name = 'siteurl'");
$siteurl = mysql_fetch_row($q_siteurl);
$siteurl = $siteurl[0];

/* Begin analysis */

$sql = <<<SQL
SELECT 
  post_title, DATE(post_date) post_date, display_name, post_name, comment_count, (select max(comment_count) from wp_posts) comment_max
FROM 
  wp_posts
JOIN
  wp_users ON wp_posts.post_author = wp_users.ID
WHERE
  post_status = 'publish'
  AND post_type = 'post'
SQL;

$res = mysql_query($sql);

$posts = array();
$fb_shares_max = 0;
$plusone_max = 0;
while ($row = mysql_fetch_assoc($res))
{
  $social = array();
  $absolute_url = $siteurl . $row['post_name'];
  
  $fb_json = json_decode(cached_file_get_contents("http://graph.facebook.com/$absolute_url"));
  $social['fb_shares'] = isset($fb_json->shares) ? $fb_json->shares : 0;
  
  $plusone_html = cached_file_get_contents('https://plusone.google.com/_/+1/fastbutton?url=' . urlencode($absolute_url . '/'));
  $match = array();
  preg_match('/\<div id=\"aggregateCount\" class=\"V1\"\>([0-9]+)\<\/div\>/', str_replace("\n", '', $plusone_html), $match);
  $social['plusone'] = isset($match[1]) ? $match[1] : '?';
  
  $posts[] = array_merge($row, $social );
  
  $fb_shares_max = $social['fb_shares'] > $fb_shares_max ? $social['fb_shares'] : $fb_shares_max;
  $plusone_max = $social['plusone'] > $plusone_max ? $social['plusone'] : $plusone_max;
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
  <p>Los datos de Facebook se actualizan cada 10 minutos</p>
</header>

<table class="table table-hover">
  <thead><tr><th><span>Post</span></th><th><span>FB shares</span></th><th><span>+1</span></th><th><span>Comnts</span></th><th><span>Fecha</span></th><th><span>Autor</span></th></tr></thead>
  <tbody>
<?php foreach ($posts as $p) : ?>
    <tr>
    <td><a href="http://jmjrio2013.info/<?php echo $p['post_name'] ?>"><?php echo utf8_encode($p['post_title']) ?></a></a></td>
    <td>
      <span class="meter-value"><?php echo intval($p['fb_shares']) ?></span>
      <meter min="0" max="<?php echo $fb_shares_max ?>" value="<?php echo intval($p['fb_shares']) ?>"></meter>
    </td>
    <td>
      <span class="meter-value"><?php echo intval($p['plusone']) ?></span>
      <meter min="0" max="<?php echo $plusone_max ?>" value="<?php echo intval($p['plusone']) ?>"></meter>
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